<?php

namespace App\Service;

use App\Entity\Instituto;
use App\Entity\BillingInvoice;
use App\Repository\BillingInvoiceRepository;
use App\Repository\BillingConfigRepository;
use App\Repository\AlumnoRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

class BillingService
{
    private EntityManagerInterface $entityManager;
    private BillingInvoiceRepository $billingRepository;
    private BillingConfigRepository $billingConfigRepository;
    private AlumnoRepository $alumnoRepository;
    private LoggerInterface $logger;

    // Precio por defecto si no hay configuración en BD
    private const DEFAULT_PRICE_PER_STUDENT_MONTHLY = 50.0;

    public function __construct(
        EntityManagerInterface $entityManager,
        BillingInvoiceRepository $billingRepository,
        BillingConfigRepository $billingConfigRepository,
        AlumnoRepository $alumnoRepository,
        LoggerInterface $logger
    ) {
        $this->entityManager = $entityManager;
        $this->billingRepository = $billingRepository;
        $this->billingConfigRepository = $billingConfigRepository;
        $this->alumnoRepository = $alumnoRepository;
        $this->logger = $logger;
    }

    /**
     * Obtiene el precio por alumno por mes desde la configuración de BD
     * Si no existe configuración, retorna el precio por defecto
     */
    public function getPricePerStudentMonthly(): float
    {
        $config = $this->billingConfigRepository->getPriceConfig();
        
        if ($config) {
            return (float) $config->getPricePerStudentMonthly();
        }
        
        // Si no hay configuración, retornar precio por defecto
        return self::DEFAULT_PRICE_PER_STUDENT_MONTHLY;
    }

    /**
     * Calcula el número de alumnos activos en un instituto
     */
    public function getActiveStudentsCount(Instituto $instituto): int
    {
        return $this->alumnoRepository->countByInstitutoAndStatus($instituto, true);
    }

    /**
     * Genera la factura mensual para un instituto
     */
    public function generateMonthlyInvoice(Instituto $instituto, int $year, int $month): BillingInvoice
    {
        // Verificar si ya existe una factura para este período
        $existingInvoice = $this->billingRepository->findByInstitutoAndPeriod($instituto, $year, $month);

        if ($existingInvoice) {
            throw new \RuntimeException("Ya existe una factura para el período {$month}/{$year} del instituto {$instituto->getNombre()}");
        }

        // Un instituto exento no se factura: es el de demostración, una prueba gratuita o un
        // acuerdo especial. Antes había que cancelarle la factura a mano cada mes.
        if ($instituto->isSuscripcionExenta()) {
            throw new \RuntimeException("El instituto {$instituto->getNombre()} está exento de facturación");
        }

        $activeStudents = $this->getActiveStudentsCount($instituto);

        // Sin alumnos activos no hay nada que cobrar. Antes se emitían facturas en $0 que
        // ensuciaban el listado y encima podían llegar a bloquear al instituto por no pagarlas.
        if ($activeStudents === 0 && !$instituto->getMinimoMensual()) {
            throw new \RuntimeException("El instituto {$instituto->getNombre()} no tiene alumnos activos en {$month}/{$year}");
        }

        // El precio propio del instituto si tiene, y si no el global.
        $pricePerStudent = $instituto->getPrecioPorAlumno() ?? $this->getPricePerStudentMonthly();

        $invoice = new BillingInvoice();
        $invoice->setInstituto($instituto);
        $invoice->setPeriodYear($year);
        $invoice->setPeriodMonth($month);
        $invoice->setActiveStudentsCount($activeStudents);
        $invoice->setPricePerStudent($pricePerStudent);
        $invoice->calculateTotal();

        // Mínimo mensual: si el cálculo por alumnos queda por debajo, se cobra el mínimo.
        $minimo = $instituto->getMinimoMensual();
        if ($minimo !== null && $invoice->getTotalAmount() < $minimo) {
            $invoice->setTotalAmount($minimo);
        }

        // El vencimiento, que es lo que después permite avisar y calcular el atraso. La factura
        // es del mes ya cerrado, así que vence en el mes siguiente al del período.
        $config = $this->billingConfigRepository->getOrCreatePriceConfig();
        $invoice->setDueDate(new \DateTime(sprintf(
            '%d-%02d-%02d',
            $month === 12 ? $year + 1 : $year,
            $month === 12 ? 1 : $month + 1,
            $config->getDiaVencimiento()
        )));

        $this->entityManager->persist($invoice);
        $this->entityManager->flush();

        $this->logger->info("Factura mensual generada para {$instituto->getNombre()}: {$activeStudents} alumnos, total: ${$invoice->getTotalAmount()}");

        return $invoice;
    }

    /**
     * Genera facturas mensuales para todos los institutos
     */
    public function generateAllMonthlyInvoices(int $year, int $month): array
    {
        $institutos = $this->entityManager->getRepository(Instituto::class)->findAll();
        $generatedInvoices = [];

        foreach ($institutos as $instituto) {
            try {
                $invoice = $this->generateMonthlyInvoice($instituto, $year, $month);
                $generatedInvoices[] = $invoice;
            } catch (\Exception $e) {
                $this->logger->error("Error generando factura para {$instituto->getNombre()}: {$e->getMessage()}");
            }
        }

        return $generatedInvoices;
    }

    /**
     * Marca una factura como pagada
     */
    public function markInvoiceAsPaid(BillingInvoice $invoice): void
    {
        $invoice->setPaidAt(new \DateTime());
        $this->entityManager->flush();

        $this->logger->info("Factura {$invoice->getId()} marcada como pagada");
    }

    /**
     * Obtiene facturas pendientes por instituto
     */
    public function getPendingInvoices(Instituto $instituto): array
    {
        return $this->billingRepository->findPendingByInstituto($instituto);
    }

    /**
     * Obtiene el total pendiente de pago por instituto
     */
    public function getTotalPendingAmount(Instituto $instituto): float
    {
        $pendingInvoices = $this->getPendingInvoices($instituto);
        $total = 0;

        foreach ($pendingInvoices as $invoice) {
            $total += $invoice->getTotalAmount();
        }

        return $total;
    }

    /**
     * Obtiene el historial de facturación de un instituto
     */
    public function getBillingHistory(Instituto $instituto, int $limit = 12): array
    {
        return $this->billingRepository->getBillingHistory($instituto, $limit);
    }

    /**
     * Obtiene estadísticas de facturación por instituto
     */
    public function getInstitutoBillingStats(Instituto $instituto): array
    {
        return $this->billingRepository->getInstitutoBillingStats($instituto);
    }

    /**
     * Verifica si un instituto tiene facturas pendientes
     */
    public function hasPendingInvoices(Instituto $instituto): bool
    {
        $pendingInvoices = $this->getPendingInvoices($instituto);
        return count($pendingInvoices) > 0;
    }

    /**
     * Obtiene todas las facturas pendientes del sistema (para super admin)
     */
    public function getAllPendingInvoices(): array
    {
        return $this->billingRepository->findAllPending();
    }

    /**
     * Calcula el costo estimado para el próximo mes
     */
    public function getEstimatedNextMonthCost(Instituto $instituto): float
    {
        $activeStudents = $this->getActiveStudentsCount($instituto);
        return $activeStudents * $this->getPricePerStudentMonthly();
    }
}
