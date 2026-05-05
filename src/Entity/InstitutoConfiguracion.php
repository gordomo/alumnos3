<?php

namespace App\Entity;

use App\Repository\InstitutoConfiguracionRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;
use App\Entity\DescuentoPromocional;

/**
 * @ORM\Entity(repositoryClass=InstitutoConfiguracionRepository::class)
 */
class InstitutoConfiguracion
{
    /**
     * @ORM\Id
     * @ORM\GeneratedValue
     * @ORM\Column(type="integer")
     */
    private $id;

    /**
     * @ORM\OneToOne(targetEntity=Instituto::class, inversedBy="configuracion")
     * @ORM\JoinColumn(nullable=false)
     */
    private $instituto;

    /**
     * @ORM\Column(type="decimal", precision=5, scale=2, nullable=true)
     * @Assert\Range(
     *      min = 0,
     *      max = 100,
     *      notInRangeMessage = "El porcentaje de descuento debe estar entre {{ min }} y {{ max }}"
     * )
     */
    private $descuentoEfectivo;

    /**
     * @ORM\Column(type="decimal", precision=5, scale=2, nullable=true)
     * @Assert\Range(
     *      min = 0,
     *      max = 100,
     *      notInRangeMessage = "El porcentaje de descuento debe estar entre {{ min }} y {{ max }}"
     * )
     */
    private $descuentoHermanos;
    
    /**
     * @ORM\Column(type="boolean")
     */
    private $deshabilitarDescuentosEnDeuda = false;
    
    /**
     * @ORM\Column(type="string", length=50, options={"default": "interes_primero"})
     */
    private $ordenCalculoInteresesDescuentos = 'interes_primero';
    
    /**
     * @ORM\OneToMany(targetEntity=Vencimiento::class, mappedBy="configuracion", orphanRemoval=true, cascade={"persist"})
     * @ORM\OrderBy({"orden" = "ASC"})
     */
    private $vencimientos;
    
    /**
     * @ORM\OneToMany(targetEntity=DescuentoPromocional::class, mappedBy="configuracion", orphanRemoval=true, cascade={"persist", "remove"})
     */
    private $descuentosPromocionales;
    
    /**
     * @ORM\Column(type="boolean", options={"default": false})
     */
    private $enviarFacturasRecibos = false;
    
    /**
     * @ORM\Column(type="boolean", options={"default": false})
     */
    private $enviarRecordatoriosDeudas = false;
    
    /**
     * @ORM\Column(type="boolean", options={"default": false})
     */
    private $enviarRecordatorioEnDiaVencimiento = false;
    
    /**
     * @ORM\Column(type="text", nullable=true)
     */
    private $textoPersonalizadoEmail;

    /**
     * Zona horaria del instituto para fechas y "hoy" (ej: America/Argentina/Buenos_Aires).
     * Si es null, se usa APP_TIMEZONE o la del servidor.
     *
     * @ORM\Column(type="string", length=50, nullable=true)
     */
    private $timezone;

    /**
     * Formato de fecha para mostrar en la app (ej: d/m/Y, m/d/Y, Y-m-d).
     * Si es null, se usa d/m/Y.
     *
     * @ORM\Column(type="string", length=20, nullable=true)
     */
    private $dateFormat;

    /**
     * Porcentaje mínimo de asistencia requerido para aprobar un curso.
     * Si es null, no se verifica asistencia al finalizar el curso.
     *
     * @ORM\Column(type="decimal", precision=5, scale=2, nullable=true)
     * @Assert\Range(
     *      min = 0,
     *      max = 100,
     *      notInRangeMessage = "El porcentaje debe estar entre {{ min }} y {{ max }}"
     * )
     */
    private $porcentajeAsistenciaAprobacion;

    /**
     * Si es true, el alumno debe tener todas las cuotas del curso pagadas para aprobar.
     *
     * @ORM\Column(type="boolean", options={"default": false})
     */
    private $requierePagoTotalParaAprobar = false;

    /**
     * Si es true, se cobrará una cuota de inscripción anual a todos los alumnos.
     *
     * @ORM\Column(type="boolean", options={"default": false})
     */
    private $cobrarCuotaInscripcionAnual = false;

    /**
     * Monto de la cuota de inscripción anual.
     *
     * @ORM\Column(type="decimal", precision=10, scale=2, nullable=true)
     */
    private $montoCuotaInscripcionAnual;

    /**
     * Mes en que se cobra la cuota de inscripción anual (1-12).
     *
     * @ORM\Column(type="integer", nullable=true)
     */
    private $mesCobroCuotaInscripcionAnual;

    public function __construct()
    {
        $this->vencimientos = new ArrayCollection();
        $this->descuentosPromocionales = new ArrayCollection();
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

    public function getDescuentoEfectivo(): ?float
    {
        return $this->descuentoEfectivo;
    }

    public function setDescuentoEfectivo(?float $descuentoEfectivo): self
    {
        $this->descuentoEfectivo = $descuentoEfectivo;
        return $this;
    }

    public function getDescuentoHermanos(): ?float
    {
        return $this->descuentoHermanos;
    }

    public function setDescuentoHermanos(?float $descuentoHermanos): self
    {
        $this->descuentoHermanos = $descuentoHermanos;
        return $this;
    }

    public function getDeshabilitarDescuentosEnDeuda(): bool
    {
        return $this->deshabilitarDescuentosEnDeuda;
    }

    public function setDeshabilitarDescuentosEnDeuda(bool $deshabilitarDescuentosEnDeuda): self
    {
        $this->deshabilitarDescuentosEnDeuda = $deshabilitarDescuentosEnDeuda;
        return $this;
    }
    
    public function getOrdenCalculoInteresesDescuentos(): string
    {
        return $this->ordenCalculoInteresesDescuentos ?? 'interes_primero';
    }
    
    public function setOrdenCalculoInteresesDescuentos(string $ordenCalculoInteresesDescuentos): self
    {
        $this->ordenCalculoInteresesDescuentos = $ordenCalculoInteresesDescuentos;
        return $this;
    }
    
    /**
     * @return Collection<int, Vencimiento>
     */
    public function getVencimientos(): Collection
    {
        return $this->vencimientos;
    }

    public function addVencimiento(Vencimiento $vencimiento): self
    {
        if (!$this->vencimientos->contains($vencimiento)) {
            $this->vencimientos[] = $vencimiento;
            $vencimiento->setConfiguracion($this);
        }
        return $this;
    }

    public function removeVencimiento(Vencimiento $vencimiento): self
    {
        if ($this->vencimientos->removeElement($vencimiento)) {
            // set the owning side to null (unless already changed)
            if ($vencimiento->getConfiguracion() === $this) {
                $vencimiento->setConfiguracion(null);
            }
        }
        return $this;
    }

    /**
     * @return Collection<int, DescuentoPromocional>
     */
    public function getDescuentosPromocionales(): Collection
    {
        return $this->descuentosPromocionales;
    }

    public function addDescuentosPromocionale(DescuentoPromocional $descuentosPromocionale): self
    {
        if (!$this->descuentosPromocionales->contains($descuentosPromocionale)) {
            $this->descuentosPromocionales[] = $descuentosPromocionale;
            $descuentosPromocionale->setConfiguracion($this);
        }

        return $this;
    }

    public function removeDescuentosPromocionale(DescuentoPromocional $descuentosPromocionale): self
    {
        if ($this->descuentosPromocionales->removeElement($descuentosPromocionale)) {
            // set the owning side to null (unless already changed)
            if ($descuentosPromocional->getConfiguracion() === $this) {
                $descuentosPromocional->setConfiguracion(null);
            }
        }

        return $this;
    }
    
    public function getEnviarFacturasRecibos(): bool
    {
        return $this->enviarFacturasRecibos;
    }
    
    public function setEnviarFacturasRecibos(bool $enviarFacturasRecibos): self
    {
        $this->enviarFacturasRecibos = $enviarFacturasRecibos;
        return $this;
    }
    
    public function getEnviarRecordatoriosDeudas(): bool
    {
        return $this->enviarRecordatoriosDeudas;
    }
    
    public function setEnviarRecordatoriosDeudas(bool $enviarRecordatoriosDeudas): self
    {
        $this->enviarRecordatoriosDeudas = $enviarRecordatoriosDeudas;
        return $this;
    }
    
    public function getEnviarRecordatorioEnDiaVencimiento(): bool
    {
        return $this->enviarRecordatorioEnDiaVencimiento;
    }
    
    public function setEnviarRecordatorioEnDiaVencimiento(bool $enviarRecordatorioEnDiaVencimiento): self
    {
        $this->enviarRecordatorioEnDiaVencimiento = $enviarRecordatorioEnDiaVencimiento;
        return $this;
    }
    
    public function getTextoPersonalizadoEmail(): ?string
    {
        return $this->textoPersonalizadoEmail;
    }
    
    public function setTextoPersonalizadoEmail(?string $textoPersonalizadoEmail): self
    {
        $this->textoPersonalizadoEmail = $textoPersonalizadoEmail;
        return $this;
    }

    public function getTimezone(): ?string
    {
        return $this->timezone;
    }

    public function setTimezone(?string $timezone): self
    {
        $this->timezone = $timezone;
        return $this;
    }

    public function getDateFormat(): ?string
    {
        return $this->dateFormat;
    }

    public function setDateFormat(?string $dateFormat): self
    {
        $this->dateFormat = $dateFormat;
        return $this;
    }

    public function getPorcentajeAsistenciaAprobacion(): ?float
    {
        return $this->porcentajeAsistenciaAprobacion;
    }

    public function setPorcentajeAsistenciaAprobacion(?float $porcentajeAsistenciaAprobacion): self
    {
        $this->porcentajeAsistenciaAprobacion = $porcentajeAsistenciaAprobacion;
        return $this;
    }

    public function getRequierePagoTotalParaAprobar(): bool
    {
        return $this->requierePagoTotalParaAprobar;
    }

    public function setRequierePagoTotalParaAprobar(bool $requierePagoTotalParaAprobar): self
    {
        $this->requierePagoTotalParaAprobar = $requierePagoTotalParaAprobar;
        return $this;
    }

    public function getCobrarCuotaInscripcionAnual(): bool
    {
        return $this->cobrarCuotaInscripcionAnual;
    }

    public function setCobrarCuotaInscripcionAnual(bool $cobrarCuotaInscripcionAnual): self
    {
        $this->cobrarCuotaInscripcionAnual = $cobrarCuotaInscripcionAnual;
        return $this;
    }

    public function getMontoCuotaInscripcionAnual(): ?float
    {
        return $this->montoCuotaInscripcionAnual;
    }

    public function setMontoCuotaInscripcionAnual(?float $montoCuotaInscripcionAnual): self
    {
        $this->montoCuotaInscripcionAnual = $montoCuotaInscripcionAnual;
        return $this;
    }

    public function getMesCobroCuotaInscripcionAnual(): ?int
    {
        return $this->mesCobroCuotaInscripcionAnual;
    }

    public function setMesCobroCuotaInscripcionAnual(?int $mesCobroCuotaInscripcionAnual): self
    {
        $this->mesCobroCuotaInscripcionAnual = $mesCobroCuotaInscripcionAnual;
        return $this;
    }
} 