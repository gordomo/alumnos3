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

class SincronizarMetodosPagoDesdePagosCommand extends Command
{
    protected static $defaultName = 'app:sincronizar-metodos-pago';
    protected static $defaultDescription = 'Sincroniza métodos de pago por instituto a partir de pagos históricos y garantiza Efectivo';

    private EntityManagerInterface $entityManager;

    public function __construct(EntityManagerInterface $entityManager)
    {
        parent::__construct();
        $this->entityManager = $entityManager;
    }

    protected function configure(): void
    {
        $this
            ->addOption('instituto-id', 'i', InputOption::VALUE_OPTIONAL, 'ID de instituto a procesar')
            ->addOption('dry-run', 'd', InputOption::VALUE_NONE, 'Simula cambios sin guardar');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $institutoId = $input->getOption('instituto-id');
        $dryRun = (bool) $input->getOption('dry-run');

        $institutos = [];
        if ($institutoId) {
            $instituto = $this->entityManager->getRepository(Instituto::class)->find($institutoId);
            if (!$instituto) {
                $io->error(sprintf('No se encontró el instituto con ID %s', $institutoId));
                return Command::FAILURE;
            }
            $institutos[] = $instituto;
        } else {
            $institutos = $this->entityManager->getRepository(Instituto::class)->findAll();
        }

        $io->title('Sincronización de métodos de pago');
        if ($dryRun) {
            $io->warning('Modo dry-run activo: no se guardarán cambios.');
        }

        $totalCreados = 0;
        $totalReactivados = 0;

        foreach ($institutos as $instituto) {
            $resultado = $this->sincronizarInstituto($instituto, $dryRun, $io);
            $totalCreados += $resultado['creados'];
            $totalReactivados += $resultado['reactivados'];
        }

        if (!$dryRun) {
            $this->entityManager->flush();
        }

        $io->success(sprintf(
            'Proceso finalizado. Creados: %d, Reactivados: %d',
            $totalCreados,
            $totalReactivados
        ));

        return Command::SUCCESS;
    }

    /**
     * @return array{creados:int,reactivados:int}
     */
    private function sincronizarInstituto(Instituto $instituto, bool $dryRun, SymfonyStyle $io): array
    {
        $creados = 0;
        $reactivados = 0;

        $metodosExistentes = $this->entityManager->getRepository(MetodoPago::class)
            ->findBy(['instituto' => $instituto], ['orden' => 'ASC', 'id' => 'ASC']);

        $mapaExistentes = [];
        $maxOrden = 0;
        foreach ($metodosExistentes as $metodo) {
            $normalizado = $this->normalizarNombre($metodo->getNombre());
            if ($normalizado !== '') {
                $mapaExistentes[$normalizado] = $metodo;
            }
            $maxOrden = max($maxOrden, (int) $metodo->getOrden());
        }

        // Efectivo debe existir siempre y estar activo.
        $claveEfectivo = $this->normalizarNombre('Efectivo');
        if (isset($mapaExistentes[$claveEfectivo])) {
            $metodoEfectivo = $mapaExistentes[$claveEfectivo];
            if (!$metodoEfectivo->getActivo()) {
                $metodoEfectivo->setActivo(true);
                $reactivados++;
                $io->text(sprintf('[Instituto %d] Reactivado método: %s', $instituto->getId(), $metodoEfectivo->getNombre()));
            }
        } else {
            $maxOrden++;
            $metodo = new MetodoPago();
            $metodo->setInstituto($instituto);
            $metodo->setNombre('Efectivo');
            $metodo->setActivo(true);
            $metodo->setOrden($maxOrden);
            if (!$dryRun) {
                $this->entityManager->persist($metodo);
            }
            $mapaExistentes[$claveEfectivo] = $metodo;
            $creados++;
            $io->text(sprintf('[Instituto %d] Creado método obligatorio: Efectivo', $instituto->getId()));
        }

        $metodosUsados = $this->entityManager->createQueryBuilder()
            ->select('DISTINCT p.metodoPago AS metodoPago')
            ->from(AlumnosPagos::class, 'p')
            ->join('p.alumno', 'a')
            ->where('a.instituto = :instituto')
            ->andWhere('p.metodoPago IS NOT NULL')
            ->andWhere('TRIM(p.metodoPago) <> :vacio')
            ->setParameter('instituto', $instituto)
            ->setParameter('vacio', '')
            ->getQuery()
            ->getArrayResult();

        foreach ($metodosUsados as $fila) {
            $nombreOriginal = trim((string) ($fila['metodoPago'] ?? ''));
            $clave = $this->normalizarNombre($nombreOriginal);
            if ($clave === '') {
                continue;
            }

            if (isset($mapaExistentes[$clave])) {
                $metodo = $mapaExistentes[$clave];
                if (!$metodo->getActivo()) {
                    $metodo->setActivo(true);
                    $reactivados++;
                    $io->text(sprintf('[Instituto %d] Reactivado método usado en pagos: %s', $instituto->getId(), $metodo->getNombre()));
                }
                continue;
            }

            $maxOrden++;
            $nombreNormalizado = $this->normalizarParaMostrar($nombreOriginal);
            $nuevo = new MetodoPago();
            $nuevo->setInstituto($instituto);
            $nuevo->setNombre($nombreNormalizado);
            $nuevo->setActivo(true);
            $nuevo->setOrden($maxOrden);

            if (!$dryRun) {
                $this->entityManager->persist($nuevo);
            }

            $mapaExistentes[$clave] = $nuevo;
            $creados++;
            $io->text(sprintf('[Instituto %d] Creado método desde histórico: %s', $instituto->getId(), $nombreNormalizado));
        }

        return [
            'creados' => $creados,
            'reactivados' => $reactivados,
        ];
    }

    private function normalizarNombre(string $nombre): string
    {
        $nombre = trim($nombre);
        if ($nombre === '') {
            return '';
        }

        $nombre = str_replace(['_', '-'], ' ', $nombre);
        $nombre = preg_replace('/\s+/', ' ', $nombre);
        return mb_strtolower((string) $nombre, 'UTF-8');
    }

    private function normalizarParaMostrar(string $nombre): string
    {
        $nombre = str_replace(['_', '-'], ' ', $nombre);
        $nombre = trim((string) preg_replace('/\s+/', ' ', $nombre));
        if ($nombre === '') {
            return $nombre;
        }

        return mb_convert_case($nombre, MB_CASE_TITLE, 'UTF-8');
    }
}
