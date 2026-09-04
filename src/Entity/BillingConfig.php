<?php

namespace App\Entity;

use App\Repository\BillingConfigRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * @ORM\Entity(repositoryClass=BillingConfigRepository::class)
 * @ORM\Table(name="billing_config")
 */
class BillingConfig
{
    /**
     * @ORM\Id
     * @ORM\GeneratedValue
     * @ORM\Column(type="integer")
     */
    private ?int $id = null;

    /**
     * @ORM\Column(type="string", length=100, unique=true)
     */
    private string $configKey = 'price_per_student_monthly';

    /**
     * @ORM\Column(type="decimal", precision=10, scale=2)
     */
    private float $pricePerStudentMonthly;

    /**
     * @ORM\Column(type="text", nullable=true)
     */
    private ?string $description = null;

    /**
     * Día del mes en que vence la factura del período anterior.
     *
     * La factura se emite el día 1 con el mes ya cerrado, igual que el instituto le cobra a las
     * familias, y vence este día.
     *
     * @ORM\Column(type="integer", options={"default": 10})
     */
    private int $diaVencimiento = 10;

    /**
     * Días después del vencimiento antes de pasar el instituto a solo lectura.
     *
     * @ORM\Column(type="integer", options={"default": 5})
     */
    private int $diasGracia = 5;

    /**
     * Días después del vencimiento antes de bloquear el panel del instituto.
     *
     * @ORM\Column(type="integer", options={"default": 15})
     */
    private int $diasHastaBloqueo = 15;

    /**
     * Días antes del vencimiento en que se empieza a avisar.
     *
     * @ORM\Column(type="integer", options={"default": 3})
     */
    private int $diasAvisoPrevio = 3;

    /**
     * A dónde transferir: CBU, alias y titular, tal como se le muestran al instituto.
     *
     * Sin esto el instituto veía "subí un comprobante" y no tenía a dónde transferir.
     *
     * @ORM\Column(type="text", nullable=true)
     */
    private ?string $datosTransferencia = null;

    /**
     * Credenciales de Mercado Pago. Se guardan acá y no en el .env para que el super admin las
     * pueda cambiar sin tocar el servidor.
     *
     * @ORM\Column(type="string", length=255, nullable=true)
     */
    private ?string $mpAccessToken = null;

    /**
     * @ORM\Column(type="string", length=255, nullable=true)
     */
    private ?string $mpPublicKey = null;

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
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getConfigKey(): string
    {
        return $this->configKey;
    }

    public function setConfigKey(string $configKey): self
    {
        $this->configKey = $configKey;
        return $this;
    }

    public function getPricePerStudentMonthly(): float
    {
        return $this->pricePerStudentMonthly;
    }

    public function setPricePerStudentMonthly(float $pricePerStudentMonthly): self
    {
        $this->pricePerStudentMonthly = $pricePerStudentMonthly;
        $this->updatedAt = new \DateTime();
        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): self
    {
        $this->description = $description;
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

    public function getDiaVencimiento(): int
    {
        // Entre 1 y 28 para que exista en todos los meses.
        return max(1, min(28, $this->diaVencimiento ?: 10));
    }

    public function setDiaVencimiento(int $dia): self
    {
        $this->diaVencimiento = max(1, min(28, $dia));
        return $this;
    }

    public function getDiasGracia(): int
    {
        return max(0, $this->diasGracia);
    }

    public function setDiasGracia(int $dias): self
    {
        $this->diasGracia = max(0, $dias);
        return $this;
    }

    public function getDiasHastaBloqueo(): int
    {
        // Nunca antes de la gracia: si no, se bloquearía sin pasar por solo lectura.
        return max($this->getDiasGracia(), $this->diasHastaBloqueo);
    }

    public function setDiasHastaBloqueo(int $dias): self
    {
        $this->diasHastaBloqueo = max(0, $dias);
        return $this;
    }

    public function getDiasAvisoPrevio(): int
    {
        return max(0, $this->diasAvisoPrevio);
    }

    public function setDiasAvisoPrevio(int $dias): self
    {
        $this->diasAvisoPrevio = max(0, $dias);
        return $this;
    }

    public function getDatosTransferencia(): ?string
    {
        return $this->datosTransferencia;
    }

    public function setDatosTransferencia(?string $datos): self
    {
        $this->datosTransferencia = $datos;
        return $this;
    }

    public function getMpAccessToken(): ?string
    {
        return $this->mpAccessToken;
    }

    public function setMpAccessToken(?string $token): self
    {
        $this->mpAccessToken = $token;
        return $this;
    }

    public function getMpPublicKey(): ?string
    {
        return $this->mpPublicKey;
    }

    public function setMpPublicKey(?string $key): self
    {
        $this->mpPublicKey = $key;
        return $this;
    }

    public function mercadoPagoConfigurado(): bool
    {
        return !empty($this->mpAccessToken);
    }
}
