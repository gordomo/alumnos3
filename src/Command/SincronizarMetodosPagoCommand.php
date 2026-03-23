<?php

namespace App\Command;

use App\Entity\AlumnosPagos;
use App\Entity\Instituto;
use App\Entity\MetodoPago;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

class SincronizarMetodosPagoCommand extends Command
{
    protected static $defaultName = 'app:sincronizar-metodos-pago';
    protected static $defaultDescription = 'Sincroniza metodos de pago por instituto a partir del historico de pagos y asegura que exista Efectivo.';

    private EntityManagerInterface $entityManager;

    public function __construct(EntityManagerInterface $entityManager)
    {
        parent::__construct();
        $this->entityManager = $entityManager;
    }

    protected function configure(): void
    {
        $this
            ->addOption(
                'instituto-id',
                'i',
                InputOption::VALUE_OPTIONAL,
                'ID de instituto para procesar uno solo'
            )
            ->addOption(
                'dry-run',
                'd',
                InputOption::VALUE_NONE,
                'Simula cambios sin guardar'
            )
            ->addOption(
                'no-normalizar-pagos',
                null,
                InputOption::VALUE_NONE,
                'No reescribe valores historicos en alumnos_pagos.metodo_pago'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $institutoId = $input->getOption('instituto-id');
        $dryRun = (bool) $input->getOption('dry-run');
        $normalizarPagos = !$input->getOption('no-normalizar-pagos');

        $institutos = [];
        if ($institutoId) {
            $instituto = $this->entityManager->getRepository(Instituto::class)->find((int) $institutoId);
            if (!$instituto) {
                $io->error(sprintf('No se encontro el instituto con id %s.', $institutoId));
                return Command::FAILURE;
            }
            $institutos = [$instituto];
        } else {
            $institutos = $this->entityManager->getRepository(Instituto::class)->findAll();
        }

        if (empty($institutos)) {
            $io->warning('No hay institutos para procesar.');
            return Command::SUCCESS;
        }

        if ($dryRun) {
            $io->warning('Modo dry-run habilitado: no se persistiran cambios.');
        }

        $resumen = [
            'institutos' => 0,
            'metodosCreados' => 0,
            'metodosReactivados' => 0,
            'pagosNormalizados' => 0,
        ];

        foreach ($institutos as $instituto) {
            $resultado = $this->sincronizarInstituto($instituto, $normalizarPagos, $dryRun, $io);
            $resumen['institutos']++;
            $resumen['metodosCreados'] += $resultado['metodosCreados'];
            $resumen['metodosReactivados'] += $resultado['metodosReactivados'];
            $resumen['pagosNormalizados'] += $resultado['pagosNormalizados'];

            if (!$dryRun) {
                $this->entityManager->flush();
                $this->entityManager->clear();
            }
        }

        $io->success('Sincronizacion finalizada.');
        $io->table(['Metricas', 'Valor'], [
            ['Institutos procesados', (string) $resumen['institutos']],
            ['Metodos creados', (string) $resumen['metodosCreados']],
            ['Metodos reactivados', (string) $resumen['metodosReactivados']],
            ['Pagos normalizados', (string) $resumen['pagosNormalizados']],
        ]);

        return Command::SUCCESS;
    }

    private function sincronizarInstituto(Instituto $instituto, bool $normalizarPagos, bool $dryRun, SymfonyStyle $io): array
    {
        $io->section(sprintf('Instituto #%d - %s', $instituto->getId(), $instituto->getNombre()));

        $metodosCreados = 0;
        $metodosReactivados = 0;
        $pagosNormalizados = 0;

        $metodosExistentes = $this->entityManager->getRepository(MetodoPago::class)
            ->findBy(['instituto' => $instituto], ['orden' => 'ASC']);

        $maxOrden = 0;
        $indexPorClave = [];
        foreach ($metodosExistentes as $metodoExistente) {
            $maxOrden = max($maxOrden, (int) $metodoExistente->getOrden());
            $indexPorClave[$this->normalizarClave($metodoExistente->getNombre())] = $metodoExistente;
        }

        // Efectivo debe existir siempre.
        $claveEfectivo = $this->normalizarClave('Efectivo');
        if (!isset($indexPorClave[$claveEfectivo])) {
            $maxOrden++;
            $nuevo = new MetodoPago();
            $nuevo->setInstituto($instituto);
            $nuevo->setNombre('Efectivo');
            $nuevo->setActivo(true);
            $nuevo->setOrden($maxOrden);
            $this->entityManager->persist($nuevo);
            $indexPorClave[$claveEfectivo] = $nuevo;
            $metodosCreados++;
            $io->text('  - Creado metodo faltante: Efectivo');
        } elseif (!$indexPorClave[$claveEfectivo]->getActivo()) {
            $indexPorClave[$claveEfectivo]->setActivo(true);
            $metodosReactivados++;
            $io->text('  - Reactivado metodo: Efectivo');
        }

        $metodosEnPagos = $this->obtenerMetodosHistoricos($instituto);

        foreach ($metodosEnPagos as $metodoHistorico) {
            $claveHistorica = $this->normalizarClave($metodoHistorico);
            if ($claveHistorica === '') {
                continue;
            }

            $metodoCanonico = $this->normalizarNombreMetodo($metodoHistorico);
            $claveCanonica = $this->normalizarClave($metodoCanonico);

            $metodoObjetivo = $indexPorClave[$claveHistorica] ?? $indexPorClave[$claveCanonica] ?? null;

            if (!$metodoObjetivo) {
                $maxOrden++;
                $metodoObjetivo = new MetodoPago();
                $metodoObjetivo->setInstituto($instituto);
                $metodoObjetivo->setNombre($metodoCanonico);
                $metodoObjetivo->setActivo(true);
                $metodoObjetivo->setOrden($maxOrden);
                $this->entityManager->persist($metodoObjetivo);
                $indexPorClave[$claveCanonica] = $metodoObjetivo;
                $metodosCreados++;
                $io->text(sprintf('  - Creado metodo desde historico: %s (origen: %s)', $metodoCanonico, $metodoHistorico));
            } elseif (!$metodoObjetivo->getActivo()) {
                $metodoObjetivo->setActivo(true);
                $metodosReactivados++;
                $io->text(sprintf('  - Reactivado metodo: %s', $metodoObjetivo->getNombre()));
            }

            if ($normalizarPagos && $metodoHistorico !== $metodoObjetivo->getNombre()) {
                $afectados = $this->normalizarPagos($instituto, $metodoHistorico, $metodoObjetivo->getNombre(), $dryRun);
                $pagosNormalizados += $afectados;
                if ($afectados > 0) {
                    $io->text(sprintf('  - Pagos normalizados: "%s" -> "%s" (%d)', $metodoHistorico, $metodoObjetivo->getNombre(), $afectados));
                }
            }
        }

        return [
            'metodosCreados' => $metodosCreados,
            'metodosReactivados' => $metodosReactivados,
            'pagosNormalizados' => $pagosNormalizados,
        ];
    }

    /**
     * @return string[]
     */
    private function obtenerMetodosHistoricos(Instituto $instituto): array
    {
        $filas = $this->entityManager->createQueryBuilder()
            ->select('DISTINCT p.metodoPago AS metodo')
            ->from(AlumnosPagos::class, 'p')
            ->join('p.alumno', 'a')
            ->where('a.instituto = :instituto')
            ->andWhere('p.metodoPago IS NOT NULL')
            ->andWhere('p.metodoPago <> :vacio')
            ->setParameter('instituto', $instituto)
            ->setParameter('vacio', '')
            ->getQuery()
            ->getArrayResult();

        return array_values(array_filter(array_map(static function (array $fila): string {
            return trim((string) ($fila['metodo'] ?? ''));
        }, $filas)));
    }

    private function normalizarPagos(Instituto $instituto, string $origen, string $destino, bool $dryRun): int
    {
        $pagos = $this->entityManager->createQueryBuilder()
            ->select('p')
            ->from(AlumnosPagos::class, 'p')
            ->join('p.alumno', 'a')
            ->where('a.instituto = :instituto')
            ->andWhere('p.metodoPago = :metodo')
            ->setParameter('instituto', $instituto)
            ->setParameter('metodo', $origen)
            ->getQuery()
            ->getResult();

        $cantidad = count($pagos);

        if ($dryRun) {
            return $cantidad;
        }

        foreach ($pagos as $pago) {
            $pago->setMetodoPago($destino);
        }

        return $cantidad;
    }

    private function normalizarClave(string $valor): string
    {
        $valor = trim($valor);
        if ($valor === '') {
            return '';
        }

        $valor = str_replace(['_', '-'], ' ', $valor);
        $valor = mb_strtolower($valor);
        $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $valor);
        if ($ascii !== false) {
            $valor = $ascii;
        }

        $valor = preg_replace('/[^a-z0-9\s]/', '', $valor) ?? '';
        $valor = preg_replace('/\s+/', ' ', $valor) ?? '';

        return trim($valor);
    }

    private function normalizarNombreMetodo(string $valor): string
    {
        $clave = $this->normalizarClave($valor);

        $mapa = [
            'efectivo' => 'Efectivo',
            'transferencia' => 'Transferencia',
            'transferencia bancaria' => 'Transferencia',
            'tarjeta de credito' => 'Tarjeta de Credito',
            'credito' => 'Tarjeta de Credito',
            'tarjeta credito' => 'Tarjeta de Credito',
            'tarjeta de debito' => 'Tarjeta de Debito',
            'debito' => 'Tarjeta de Debito',
            'tarjeta debito' => 'Tarjeta de Debito',
        ];

        if (isset($mapa[$clave])) {
            return $mapa[$clave];
        }

        if ($clave === '') {
            return 'Metodo sin nombre';
        }

        return ucwords($clave);
    }
}
