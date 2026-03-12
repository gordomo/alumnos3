<?php

namespace App\Command;

use App\Entity\DeudaAlumno;
use App\Repository\DeudaAlumnoRepository;
use App\Service\InstitutoTimezoneService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

class ActualizarInteresesCommand extends Command
{
    protected static $defaultName = 'app:actualizar-intereses';
    protected static $defaultDescription = 'Actualiza los intereses de las deudas vencidas según la configuración del instituto';

    private EntityManagerInterface $entityManager;
    private DeudaAlumnoRepository $deudaRepository;
    private InstitutoTimezoneService $timezoneService;

    public function __construct(
        EntityManagerInterface $entityManager,
        DeudaAlumnoRepository $deudaRepository,
        InstitutoTimezoneService $timezoneService
    ) {
        parent::__construct();
        $this->entityManager = $entityManager;
        $this->deudaRepository = $deudaRepository;
        $this->timezoneService = $timezoneService;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        
        $io->title('Actualizando intereses de deudas vencidas');

        // Obtener todas las deudas pendientes (no completamente pagadas)
        $todasDeudas = $this->deudaRepository->createQueryBuilder('d')
            ->leftJoin('d.aplicaciones', 'pa')
            ->groupBy('d.id')
            ->having('COALESCE(SUM(pa.montoAplicado), 0) < d.monto + COALESCE(d.interes, 0)')
            ->getQuery()
            ->getResult();

        $deudasActualizadas = 0;
        $deudasSinCambios = 0;

        foreach ($todasDeudas as $deuda) {
            $instituto = $deuda->getInstituto();
            $fechaActual = $this->timezoneService->getNowForInstituto($instituto);
            
            $mesDeuda = $deuda->getMes();
            $anoDeuda = $deuda->getAno();
            $mesActual = (int)$fechaActual->format('n');
            $anoActual = (int)$fechaActual->format('Y');
            $diaActual = (int)$fechaActual->format('d');

            // Obtener vencimientos del instituto ordenados
            $vencimientos = $instituto->getVencimientos()->toArray();
            usort($vencimientos, function($a, $b) {
                return $a->getDiaVencimiento() <=> $b->getDiaVencimiento();
            });

            if (empty($vencimientos)) {
                continue;
            }

            $primerVencimiento = $vencimientos[0];
            $primerDiaVencimiento = $primerVencimiento->getDiaVencimiento();

            // Determinar si la deuda está vencida
            $estaVencida = false;
            $porcentajeInteres = 0;

            // Deudas de meses anteriores siempre están vencidas
            if ($anoDeuda < $anoActual || ($anoDeuda == $anoActual && $mesDeuda < $mesActual)) {
                $estaVencida = true;
                // Aplicar el máximo interés configurado
                foreach ($vencimientos as $vencimiento) {
                    if ($vencimiento->getPorcentajeInteres() > $porcentajeInteres) {
                        $porcentajeInteres = $vencimiento->getPorcentajeInteres();
                    }
                }
            }
            // Deuda del mes actual: verificar si ya pasó el día de vencimiento
            elseif ($anoDeuda == $anoActual && $mesDeuda == $mesActual) {
                if ($diaActual >= $primerDiaVencimiento) {
                    $estaVencida = true;
                    // Determinar el escalón de interés según el día actual
                    // Aplicar el interés del último vencimiento que ya pasó
                    foreach ($vencimientos as $vencimiento) {
                        $diaVenc = $vencimiento->getDiaVencimiento();
                        if ($diaActual >= $diaVenc) {
                            $porcentajeInteres = $vencimiento->getPorcentajeInteres();
                            $io->writeln("  Mes actual: día $diaActual >= día vencimiento $diaVenc -> aplicando {$porcentajeInteres}%");
                        } else {
                            $io->writeln("  Mes actual: día $diaActual < día vencimiento $diaVenc -> no aplicar este escalón");
                            // No seguir buscando si el día actual no llegó a este vencimiento
                            break;
                        }
                    }
                }
            }
            // Deudas futuras: NO aplicar interés
            else {
                // Asegurarse de que las deudas futuras no tengan interés
                if ($deuda->getInteres() > 0) {
                    $deuda->setInteres(0);
                    $deudasActualizadas++;
                } else {
                    $deudasSinCambios++;
                }
                continue;
            }

            // Calcular y actualizar el interés si la deuda está vencida
            if ($estaVencida && $porcentajeInteres > 0) {
                $montoBase = $deuda->getMonto();
                $interesCalculado = $montoBase * ($porcentajeInteres / 100);
                
                // Solo actualizar si el interés cambió
                if ($deuda->getInteres() !== $interesCalculado) {
                    $deuda->setInteres($interesCalculado);
                    $deudasActualizadas++;
                } else {
                    $deudasSinCambios++;
                }
            } elseif ($estaVencida && $porcentajeInteres == 0) {
                // Deuda vencida pero sin interés configurado
                if ($deuda->getInteres() > 0) {
                    $deuda->setInteres(0);
                    $deudasActualizadas++;
                } else {
                    $deudasSinCambios++;
                }
            } else {
                $deudasSinCambios++;
            }
        }

        if ($deudasActualizadas > 0) {
            $this->entityManager->flush();
            $io->success("Se actualizaron $deudasActualizadas deudas con intereses.");
        } else {
            $io->info("No hay deudas que requieran actualización de intereses.");
        }

        $io->note("Deudas procesadas: " . count($todasDeudas) . " | Actualizadas: $deudasActualizadas | Sin cambios: $deudasSinCambios");

        return Command::SUCCESS;
    }
}
