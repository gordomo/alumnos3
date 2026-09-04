<?php

namespace App\Entity;

use App\Repository\BillingInvoiceRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * @ORM\Entity(repositoryClass=BillingInvoiceRepository::class)
 * @ORM\Table(indexes={
 *     @ORM\Index(name="idx_billing_period", columns={"instituto_id", "period_year", "period_month"}),
 *     @ORM\Index(name="idx_billing_status", columns={"status"}),
 *     @ORM\Index(name="idx_created_at", columns={"created_at"})
 * })
 */
class BillingInvoice
{
    /**
     * @ORM\Id
     * @ORM\GeneratedValue
     * @ORM\Column(type="integer")
     */
    private ?int $id = null;

    /**
     * @ORM\ManyToOne(targetEntity=Instituto::class)
     * @ORM\JoinColumn(nullable=false)
     */
    private ?Instituto $instituto = null;

    /**
     * @ORM\Column(type="integer")
     */
    private int $periodYear; // Año del período facturado

    /**
     * @ORM\Column(type="integer")
     */
    private int $periodMonth; // Mes del período facturado

    /**
     * @ORM\Column(type="integer")
     */
    private int $activeStudentsCount; // Número de alumnos activos durante el período

    /**
     * @ORM\Column(type="decimal", precision=10, scale=2)
     */
    private float $pricePerStudent; // Precio por alumno en este período

    /**
     * @ORM\Column(type="decimal", precision=10, scale=2)
     */
    private float $totalAmount; // Monto total de la factura

    /**
     * @ORM\Column(type="string", length=20)
     */
    private string $status; // pending, pending_approval, paid, cancelled, rejected

    /**
     * @ORM\Column(type="datetime", nullable=true)
     */
    private ?\DateTimeInterface $paidAt = null;

    /**
     * Cuándo vence esta factura.
     *
     * Es la pieza que faltaba: sin fecha de vencimiento no se puede avisar "regularizá antes
     * de tal día" ni calcular el atraso para el bloqueo. Se calcula al emitirla con el día
     * configurado en BillingConfig.
     *
     * @ORM\Column(type="date", nullable=true)
     */
    private ?\DateTimeInterface $dueDate = null;

    /**
     * Con qué dijo el instituto que pagó: transferencia, efectivo o mercadopago.
     *
     * @ORM\Column(type="string", length=20, nullable=true)
     */
    private ?string $paymentMethod = null;

    /**
     * Referencia del pago en Mercado Pago, para poder rastrearlo desde el panel de MP.
     *
     * @ORM\Column(type="string", length=100, nullable=true)
     */
    private ?string $mpPaymentId = null;

    /**
     * @ORM\Column(type="datetime", nullable=true)
     */
    private ?\DateTimeInterface $paymentRequestedAt = null;

    /**
     * @ORM\Column(type="string", length=255, nullable=true)
     */
    private ?string $paymentProofPath = null;

    /**
     * @ORM\Column(type="text", nullable=true)
     */
    private ?string $rejectionReason = null;

    /**
     * @ORM\Column(type="datetime", nullable=true)
     */
    private ?\DateTimeInterface $rejectedAt = null;

    /**
     * @ORM\ManyToOne(targetEntity=User::class)
     * @ORM\JoinColumn(nullable=true)
     */
    private ?User $approvedBy = null;

    /**
     * @ORM\Column(type="datetime", nullable=true)
     */
    private ?\DateTimeInterface $approvedAt = null;

    /**
     * @ORM\Column(type="text", nullable=true)
     */
    private ?string $notes = null;

    /**
     * @ORM\Column(type="datetime")
     */
    private ?\DateTimeInterface $createdAt = null;

    /**
     * @ORM\Column(type="datetime")
     */
    private ?\DateTimeInterface $updatedAt = null;

    public function __construct()
    {
        $this->createdAt = new \DateTime();
        $this->updatedAt = new \DateTime();
        $this->status = 'pending';
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getInstituto(): ?Instituto
    {
        return $this->instituto;
    }

    public function setInstituto(?Instituto $instituto): self
    {
        $this->instituto = $instituto;
        return $this;
    }

    public function getPeriodYear(): int
    {
        return $this->periodYear;
    }

    public function setPeriodYear(int $periodYear): self
    {
        $this->periodYear = $periodYear;
        return $this;
    }

    public function getPeriodMonth(): int
    {
        return $this->periodMonth;
    }

    public function setPeriodMonth(int $periodMonth): self
    {
        $this->periodMonth = $periodMonth;
        return $this;
    }

    public function getPeriodDate(): \DateTime
    {
        return new \DateTime($this->periodYear . '-' . $this->periodMonth . '-01');
    }

    public function getActiveStudentsCount(): int
    {
        return $this->activeStudentsCount;
    }

    public function setActiveStudentsCount(int $activeStudentsCount): self
    {
        $this->activeStudentsCount = $activeStudentsCount;
        return $this;
    }

    public function getPricePerStudent(): float
    {
        return $this->pricePerStudent;
    }

    public function setPricePerStudent(float $pricePerStudent): self
    {
        $this->pricePerStudent = $pricePerStudent;
        return $this;
    }

    public function getTotalAmount(): float
    {
        return $this->totalAmount;
    }

    public function setTotalAmount(float $totalAmount): self
    {
        $this->totalAmount = $totalAmount;
        return $this;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): self
    {
        $this->status = $status;
        $this->updatedAt = new \DateTime();
        return $this;
    }

    public function getPaidAt(): ?\DateTimeInterface
    {
        return $this->paidAt;
    }

    public function setPaidAt(?\DateTimeInterface $paidAt): self
    {
        $this->paidAt = $paidAt;
        if ($paidAt) {
            $this->setStatus('paid');
        }
        return $this;
    }

    public function getNotes(): ?string
    {
        return $this->notes;
    }

    public function setNotes(?string $notes): self
    {
        $this->notes = $notes;
        return $this;
    }

    public function getCreatedAt(): ?\DateTimeInterface
    {
        return $this->createdAt;
    }

    public function setCreatedAt(\DateTimeInterface $createdAt): self
    {
        $this->createdAt = $createdAt;
        return $this;
    }

    public function getUpdatedAt(): ?\DateTimeInterface
    {
        return $this->updatedAt;
    }

    public function setUpdatedAt(\DateTimeInterface $updatedAt): self
    {
        $this->updatedAt = $updatedAt;
        return $this;
    }

    /**
     * Calcula el monto total basado en cantidad de alumnos y precio por alumno
     */
    public function calculateTotal(): self
    {
        $this->totalAmount = $this->activeStudentsCount * $this->pricePerStudent;
        return $this;
    }

    /**
     * Obtiene el período formateado (Ej: "Enero 2024")
     */
    public function getFormattedPeriod(): string
    {
        $date = $this->getPeriodDate();
        $meses = [
            1 => 'Enero', 2 => 'Febrero', 3 => 'Marzo', 4 => 'Abril',
            5 => 'Mayo', 6 => 'Junio', 7 => 'Julio', 8 => 'Agosto',
            9 => 'Septiembre', 10 => 'Octubre', 11 => 'Noviembre', 12 => 'Diciembre'
        ];
        return $meses[$this->periodMonth] . ' ' . $this->periodYear;
    }

    /**
     * Verifica si la factura está pendiente
     */
    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    /**
     * Verifica si la factura está pendiente de aprobación
     */
    public function isPendingApproval(): bool
    {
        return $this->status === 'pending_approval';
    }

    /**
     * Verifica si la factura está pagada
     */
    public function isPaid(): bool
    {
        return $this->status === 'paid';
    }

    /**
     * Verifica si la factura está cancelada
     */
    public function isCancelled(): bool
    {
        return $this->status === 'cancelled';
    }

    /**
     * Verifica si la factura fue rechazada
     */
    public function isRejected(): bool
    {
        return $this->status === 'rejected';
    }

    public function getPaymentRequestedAt(): ?\DateTimeInterface
    {
        return $this->paymentRequestedAt;
    }

    public function setPaymentRequestedAt(?\DateTimeInterface $paymentRequestedAt): self
    {
        $this->paymentRequestedAt = $paymentRequestedAt;
        return $this;
    }

    public function getPaymentProofPath(): ?string
    {
        return $this->paymentProofPath;
    }

    public function setPaymentProofPath(?string $paymentProofPath): self
    {
        $this->paymentProofPath = $paymentProofPath;
        return $this;
    }

    public function getRejectionReason(): ?string
    {
        return $this->rejectionReason;
    }

    public function setRejectionReason(?string $rejectionReason): self
    {
        $this->rejectionReason = $rejectionReason;
        return $this;
    }

    public function getRejectedAt(): ?\DateTimeInterface
    {
        return $this->rejectedAt;
    }

    public function setRejectedAt(?\DateTimeInterface $rejectedAt): self
    {
        $this->rejectedAt = $rejectedAt;
        return $this;
    }

    public function getApprovedBy(): ?User
    {
        return $this->approvedBy;
    }

    public function setApprovedBy(?User $approvedBy): self
    {
        $this->approvedBy = $approvedBy;
        return $this;
    }

    public function getApprovedAt(): ?\DateTimeInterface
    {
        return $this->approvedAt;
    }

    public function setApprovedAt(?\DateTimeInterface $approvedAt): self
    {
        $this->approvedAt = $approvedAt;
        return $this;
    }

    public function getDueDate(): ?\DateTimeInterface
    {
        return $this->dueDate;
    }

    public function setDueDate(?\DateTimeInterface $dueDate): self
    {
        $this->dueDate = $dueDate;
        return $this;
    }

    public function getPaymentMethod(): ?string
    {
        return $this->paymentMethod;
    }

    public function setPaymentMethod(?string $metodo): self
    {
        $this->paymentMethod = in_array($metodo, ['transferencia', 'efectivo', 'mercadopago'], true)
            ? $metodo
            : null;

        return $this;
    }

    public function getPaymentMethodEtiqueta(): ?string
    {
        return [
            'transferencia' => 'Transferencia',
            'efectivo' => 'Efectivo',
            'mercadopago' => 'Mercado Pago',
        ][$this->paymentMethod] ?? null;
    }

    public function getMpPaymentId(): ?string
    {
        return $this->mpPaymentId;
    }

    public function setMpPaymentId(?string $id): self
    {
        $this->mpPaymentId = $id;
        return $this;
    }

    /**
     * Días de atraso, contados desde el vencimiento. 0 si todavía no venció o no tiene fecha.
     */
    public function getDiasDeAtraso(?\DateTimeInterface $hoy = null): int
    {
        $hoy = $hoy ?: new \DateTime('today');

        if (!$this->dueDate || $this->isPaid() || $this->isCancelled()) {
            return 0;
        }

        // Se compara por día y no por hora: con la hora incluida, una factura vencida ayer daba
        // 0 días de atraso porque no habían pasado 24 horas todavía, y el estado quedaba mal.
        $vence = new \DateTime($this->dueDate->format('Y-m-d'));
        $ahora = new \DateTime($hoy->format('Y-m-d'));

        if ($ahora <= $vence) {
            return 0;
        }

        return (int) $vence->diff($ahora)->days;
    }

    /**
     * Cuántos días faltan para el vencimiento. Negativo si ya venció.
     */
    public function getDiasParaVencer(?\DateTimeInterface $hoy = null): ?int
    {
        $hoy = $hoy ?: new \DateTime('today');

        if (!$this->dueDate) {
            return null;
        }

        $vence = new \DateTime($this->dueDate->format('Y-m-d'));
        $ahora = new \DateTime($hoy->format('Y-m-d'));
        $diff = (int) $ahora->diff($vence)->days;

        return $vence >= $ahora ? $diff : -$diff;
    }

    /**
     * Si el instituto ya hizo algo con esta factura y está esperando que la revisemos.
     */
    public function esperandoRevision(): bool
    {
        return $this->isPendingApproval();
    }
}
