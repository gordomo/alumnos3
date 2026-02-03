<?php

namespace App\Command;

use App\Entity\DeudaAlumno;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Console\Question\ConfirmationQuestion;

/**
 * Comando para limpiar deudas futuras que no deberían existir
 * Las deudas solo deben generarse hasta el mes actual, no meses futuros
 */
class LimpiarDeudasFuturasCommand extends Command
{
    protected static $defaultName = 'app:limpiar-deudas-futuras';
    protected static $defaultDescription = 'Elimina las deudas de meses futuros que no deberían existir';

    private EntityManagerInterface $entityManager;

    public function __construct(EntityManagerInterface $entityManager)
    {
        parent::__construct();
        $this->entityManager = $entityManager;
    }

    protected function configure(): void
    {
        $this
            ->setDescription(self::$defaultDescription)
            ->setHelp('Este comando elimina las deudas generadas para meses futuros que aún no han vencido. Solo mantiene deudas de meses pasados y el mes actual.')
            ->addOption(
                'dry-run',
                'd',
                InputOption::VALUE_NONE,
                'Mostrar qué se eliminaría sin hacer cambios reales'
            )
            ->addOption(
                'force',
                'f',
                InputOption::VALUE_NONE,
                'Forzar la eliminación sin confirmación'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = $input->getOption('dry-run');
        $force = $input->getOption('force');

        $fechaActual = new \DateTime();
        $mesActual = (int)$fechaActual->format('n');
        $anoActual = (int)$fechaActual->format('Y');

        if ($dryRun) {
            $io->warning('Ejecutando en modo DRY-RUN - No se harán cambios reales');
        }

        $io->title('Limpieza de Deudas Futuras');
        $io->info("Fecha actual: " . $fechaActual->format('Y-m-d'));
        $io->info("Mes/Año actual: $mesActual/$anoActual");

        // Buscar todas las deudas futuras (no pagadas)
        $qb = $this->entityManager->createQueryBuilder();
        $qb->select('d')
           ->from(DeudaAlumno::class, 'd')
           ->where('d.pagado = :pagado')
           ->andWhere(
                $qb->expr()->orX(
                    // Años posteriores al actual
                    $qb->expr()->gt('d.ano', ':anoActual'),
                    // Mismo año, meses posteriores al actual
                    $qb->expr()->andX(
                        $qb->expr()->eq('d.ano', ':anoActual'),
                        $qb->expr()->gt('d.mes', ':mesActual')
                    )
                )
            )
           ->setParameter('pagado', false)
           ->setParameter('anoActual', $anoActual)
           ->setParameter('mesActual', $mesActual)
           ->orderBy('d.ano', 'ASC')
           ->addOrderBy('d.mes', 'ASC');

        $deudasFuturas = $qb->getQuery()->getResult();
        $totalDeudas = count($deudasFuturas);

        if ($totalDeudas === 0) {
            $io->success('No se encontraron deudas futuras para eliminar.');
            return Command::SUCCESS;
        }

        // Agrupar por mes/año para mostrar resumen
        $resumenPorMes = [];
        $montoTotal = 0;
        
        foreach ($deudasFuturas as $deuda) {
            $key = $deuda->getAno() . '-' . str_pad($deuda->getMes(), 2, '0', STR_PAD_LEFT);
            
            if (!isset($resumenPorMes[$key])) {
                $resumenPorMes[$key] = [
                    'mes' => $deuda->getMes(),
                    'ano' => $deuda->getAno(),
                    'cantidad' => 0,
                    'monto' => 0
                ];
            }
            
            $resumenPorMes[$key]['cantidad']++;
            $resumenPorMes[$key]['monto'] += $deuda->getMonto();
            $montoTotal += $deuda->getMonto();
        }

        // Mostrar resumen
        $io->section('Deudas futuras encontradas:');
        
        $rows = [];
        foreach ($resumenPorMes as $mes => $datos) {
            $rows[] = [
                sprintf('%02d/%d', $datos['mes'], $datos['ano']),
                $datos['cantidad'],
                '$' . number_format($datos['monto'], 2)
            ];
        }
        
        $io->table(
            ['Mes/Año', 'Cantidad', 'Monto Total'],
            $rows
        );
        
        $io->text("Total de deudas futuras: <info>$totalDeudas</info>");
        $io->text("Monto total: <info>$" . number_format($montoTotal, 2) . "</info>");

        // Si es dry-run, solo mostrar y salir
        if ($dryRun) {
            $io->note('Modo DRY-RUN: No se eliminará nada. Use sin -d para aplicar cambios.');
            return Command::SUCCESS;
        }

        // Pedir confirmación si no se usó --force
        if (!$force) {
            $io->warning("¡ATENCIÓN! Estás a punto de eliminar $totalDeudas deudas futuras.");
            $helper = $this->getHelper('question');
            $question = new ConfirmationQuestion('¿Deseas continuar? (s/N) ', false);
            
            if (!$helper->ask($input, $output, $question)) {
                $io->info('Operación cancelada por el usuario.');
                return Command::SUCCESS;
            }
        }

        // Proceder con la eliminación
        $io->section('Eliminando deudas futuras...');
        $io->progressStart($totalDeudas);

        $eliminadas = 0;
        foreach ($deudasFuturas as $deuda) {
            try {
                $this->entityManager->remove($deuda);
                $eliminadas++;
                
                // Flush cada 100 deudas para evitar problemas de memoria
                if ($eliminadas % 100 === 0) {
                    $this->entityManager->flush();
                }
                
                $io->progressAdvance();
            } catch (\Exception $e) {
                $io->error('Error al eliminar deuda ID ' . $deuda->getId() . ': ' . $e->getMessage());
            }
        }

        // Flush final
        $this->entityManager->flush();
        $io->progressFinish();

        $io->success("Se eliminaron exitosamente $eliminadas deudas futuras.");
        
        return Command::SUCCESS;
    }
}
