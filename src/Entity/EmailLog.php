<?php

namespace App\Entity;

use App\Repository\EmailLogRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * @ORM\Entity(repositoryClass=EmailLogRepository::class)
 * @ORM\Table(name="email_log")
 */
class EmailLog
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
     * @ORM\ManyToOne(targetEntity=Alumno::class)
     * @ORM\JoinColumn(nullable=true)
     */
    private ?Alumno $alumno = null;

    /**
     * Tipo de email: recibo, recordatorio
     * @ORM\Column(type="string", length=50)
     */
    private ?string $tipo = null;

    /**
     * @ORM\Column(type="string", length=255)
     */
    private ?string $destinatario = null;

    /**
     * @ORM\Column(type="string", length=255)
     */
    private ?string $asunto = null;

    /**
     * @ORM\Column(type="datetime")
     */
    private ?\DateTimeInterface $fechaEnvio = null;

    /**
     * Estado: enviado, fallido
     * @ORM\Column(type="string", length=20)
     */
    private ?string $estado = null;

    /**
     * @ORM\Column(type="text", nullable=true)
     */
    private ?string $errorMensaje = null;

    /**
     * Referencia al pago (si es un recibo)
     * @ORM\ManyToOne(targetEntity=AlumnosPagos::class)
     * @ORM\JoinColumn(nullable=true, onDelete="SET NULL")
     */
    private ?AlumnosPagos $pago = null;

    /**
     * Referencia a la deuda (si es un recordatorio)
     * @ORM\ManyToOne(targetEntity=DeudaAlumno::class)
     * @ORM\JoinColumn(nullable=true, onDelete="SET NULL")
     */
    private ?DeudaAlumno $deuda = null;

    /**
     * Usuario que solicitó el envío (null si fue automático)
     * @ORM\ManyToOne(targetEntity=User::class)
     * @ORM\JoinColumn(nullable=true, onDelete="SET NULL")
     */
    private ?User $solicitadoPor = null;

    /**
     * @ORM\Column(type="boolean", options={"default": false})
     */
    private bool $esAutomatico = false;

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

    public function getAlumno(): ?Alumno
    {
        return $this->alumno;
    }

    public function setAlumno(?Alumno $alumno): self
    {
        $this->alumno = $alumno;
        return $this;
    }

    public function getTipo(): ?string
    {
        return $this->tipo;
    }

    public function setTipo(string $tipo): self
    {
        $this->tipo = $tipo;
        return $this;
    }

    public function getDestinatario(): ?string
    {
        return $this->destinatario;
    }

    public function setDestinatario(string $destinatario): self
    {
        $this->destinatario = $destinatario;
        return $this;
    }

    public function getAsunto(): ?string
    {
        return $this->asunto;
    }

    public function setAsunto(string $asunto): self
    {
        $this->asunto = $asunto;
        return $this;
    }

    public function getFechaEnvio(): ?\DateTimeInterface
    {
        return $this->fechaEnvio;
    }

    public function setFechaEnvio(\DateTimeInterface $fechaEnvio): self
    {
        $this->fechaEnvio = $fechaEnvio;
        return $this;
    }

    public function getEstado(): ?string
    {
        return $this->estado;
    }

    public function setEstado(string $estado): self
    {
        $this->estado = $estado;
        return $this;
    }

    public function getErrorMensaje(): ?string
    {
        return $this->errorMensaje;
    }

    public function setErrorMensaje(?string $errorMensaje): self
    {
        $this->errorMensaje = $errorMensaje;
        return $this;
    }

    public function getPago(): ?AlumnosPagos
    {
        return $this->pago;
    }

    public function setPago(?AlumnosPagos $pago): self
    {
        $this->pago = $pago;
        return $this;
    }

    public function getDeuda(): ?DeudaAlumno
    {
        return $this->deuda;
    }

    public function setDeuda(?DeudaAlumno $deuda): self
    {
        $this->deuda = $deuda;
        return $this;
    }

    public function getSolicitadoPor(): ?User
    {
        return $this->solicitadoPor;
    }

    public function setSolicitadoPor(?User $solicitadoPor): self
    {
        $this->solicitadoPor = $solicitadoPor;
        return $this;
    }

    public function getEsAutomatico(): bool
    {
        return $this->esAutomatico;
    }

    public function setEsAutomatico(bool $esAutomatico): self
    {
        $this->esAutomatico = $esAutomatico;
        return $this;
    }
}
