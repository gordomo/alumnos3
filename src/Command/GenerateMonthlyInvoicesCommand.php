<?php

namespace App\Command;

use App\Service\BillingService;
use App\Repository\BillingInvoiceRepository;
use App\Repository\InstitutoRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:generate-monthly-invoices',
    description: 'Genera facturas mensuales para todos los institutos basadas en alumnos activos',
)]
class GenerateMonthlyInvoicesCommand extends Command
{
    private BillingService $billingService;
    private BillingInvoiceRepository $billingRepository;
    private InstitutoRepository $institutoRepository;

    public function __construct(
        BillingService $billingService,
        BillingInvoiceRepository $billingRepository,
        InstitutoRepository $institutoRepository
    ) {
        parent::__construct();
        $this->billingService = $billingService;
        $this->billingRepository = $billingRepository;
        $this->institutoRepository = $institutoRepository;
    }

    protected function configure(): void
    {
        $this
            ->addOption('year', 'y', InputOption::VALUE_OPTIONAL, 'Año para generar facturas (por defecto: año actual)')
            ->addOption('month', 'm', InputOption::VALUE_OPTIONAL, 'Mes para generar facturas (por defecto: mes anterior)')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Ejecutar sin crear facturas (solo mostrar información)')
            ->setHelp('Este comando genera facturas mensuales para todos los institutos del sistema basadas en la cantidad de alumnos activos.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        // Determinar el período
        $now = new \DateTime();
        $year = $input->getOption('year') ?? $now->format('Y');
        $month = $input->getOption('month') ?? $now->modify('-1 month')->format('m');

        $isDryRun = $input->getOption('dry-run');

        $io->title("Generando Facturas Mensuales - {$month}/{$year}");

        if ($isDryRun) {
            $io->warning('Ejecutando en modo DRY-RUN - No se crearán facturas');
        }

        try {
            // Obtener todos los institutos
            $institutos = $this->institutoRepository->findAll();

            $generatedCount = 0;
            $skippedCount = 0;
            $errors = [];

            foreach ($institutos as $instituto) {
                try {
                    $activeStudents = $this->billingService->getActiveStudentsCount($instituto);
                    $estimatedCost = $this->billingService->getEstimatedNextMonthCost($instituto);

                    // Los que no corresponde facturar se saltean acá y se cuentan como omitidos.
                    // Si se dejaran para que el servicio los rechace, aparecerían en rojo como
                    // errores y en una corrida mensual eso hace ruido.
                    $motivoOmision = null;
                    if ($instituto->isSuscripcionExenta()) {
                        $motivoOmision = 'está exento de facturación';
                    } elseif ($activeStudents === 0 && !$instituto->getMinimoMensual()) {
                        $motivoOmision = 'no tiene alumnos activos';
                    }

                    if ($motivoOmision) {
                        $io->text(sprintf('Salteando %s - %s', $instituto->getNombre(), $motivoOmision));
                        $skippedCount++;
                        continue;
                    }

                    if ($isDryRun) {
                        $io->text(sprintf(
                            'Instituto: %s - Alumnos activos: %d - Costo estimado: $%s',
                            $instituto->getNombre(),
                            $activeStudents,
                            number_format($estimatedCost, 2)
                        ));
                        continue;
                    }

                    // Verificar si ya existe factura para este período
                    $existingInvoice = $this->billingRepository->findByInstitutoAndPeriod($instituto, (int)$year, (int)$month);

                    if ($existingInvoice) {
                        $io->text(sprintf('Saltando %s - Ya existe factura para %s/%s', $instituto->getNombre(), $month, $year));
                        $skippedCount++;
                        continue;
                    }

                    $invoice = $this->billingService->generateMonthlyInvoice($instituto, (int)$year, (int)$month);

                    $io->success(sprintf(
                        'Factura generada para %s: %d alumnos, total $%.2f',
                        $instituto->getNombre(),
                        $invoice->getActiveStudentsCount(),
                        $invoice->getTotalAmount()
                    ));

                    $generatedCount++;

                } catch (\Exception $e) {
                    $errors[] = sprintf('Error con %s: %s', $instituto->getNombre(), $e->getMessage());
                    $io->error($errors[count($errors) - 1]);
                }
            }

            $io->newLine();
            $io->section('Resumen');

            if ($isDryRun) {
                $io->info('Simulación completada - No se crearon facturas');
            } else {
                $io->success("Facturas generadas: {$generatedCount}");
                if ($skippedCount > 0) {
                    $io->warning("Facturas omitidas (ya existían, exentos o sin alumnos): {$skippedCount}");
                }
                if (count($errors) > 0) {
                    $io->error("Errores encontrados: " . count($errors));
                }
            }

            return Command::SUCCESS;

        } catch (\Exception $e) {
            $io->error('Error general: ' . $e->getMessage());
            return Command::FAILURE;
        }
    }
}
