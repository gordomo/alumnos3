<?php

namespace App\Entity;

use App\Repository\DeudaAlumnoRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * @ORM\Entity(repositoryClass=DeudaAlumnoRepository::class)
 * @ORM\Table(name="deuda_alumno")
 */
class DeudaAlumno
{
    /**
     * @ORM\Id
     * @ORM\GeneratedValue
     * @ORM\Column(type="integer")
     */
    private $id;

    /**
     * @ORM\ManyToOne(targetEntity=Alumno::class, inversedBy="deudas")
     * @ORM\JoinColumn(nullable=false)
     */
    private $alumno;

    /**
     * @ORM\ManyToOne(targetEntity=Curso::class)
     * @ORM\JoinColumn(nullable=true)
     */
    private $curso;

    /**
     * @ORM\ManyToOne(targetEntity=AlumnoCursoHistorico::class)
     */
    private $cursoHistorico;

    /**
     * @ORM\Column(type="integer")
     */
    private $mes;

    /**
     * @ORM\Column(type="integer")
     */
    private $ano;

    /**
     * @ORM\OneToMany(targetEntity=PagoAplicacion::class, mappedBy="deuda", cascade={"persist", "remove"}, fetch="EAGER")
     */
    private $aplicaciones;

    /**
     * @ORM\Column(type="datetime")
     */
    private $fechaCreacion;


    /**
     * @ORM\Column(type="float")
     */
    private $monto;

    /**
     * @ORM\Column(type="float", nullable=true)
     */
    private $interes = 0;

    /**
     * @ORM\ManyToOne(targetEntity=Instituto::class)
     * @ORM\JoinColumn(nullable=false)
     */
    private $instituto;

    /**
     * @ORM\OneToMany(targetEntity=SaldoFavorAplicacion::class, mappedBy="deuda", cascade={"persist", "remove"}, fetch="EAGER")
     */
    private $creditoAplicaciones;

    /**
     * Si es true, esta deuda representa una cuota de inscripción anual en lugar de una cuota mensual de curso.
     *
     * @ORM\Column(type="boolean", options={"default": false})
     */
    private $esCuotaInscripcionAnual = false;

    public function __construct()
    {
        $this->fechaCreacion = new \DateTime();
        $this->aplicaciones = new ArrayCollection();
        $this->creditoAplicaciones = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
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

    public function getCurso(): ?Curso
    {
        return $this->curso;
    }

    public function setCurso(?Curso $curso): self
    {
        $this->curso = $curso;

        return $this;
    }

    public function getCursoHistorico(): ?AlumnoCursoHistorico
    {
        return $this->cursoHistorico;
    }

    public function setCursoHistorico(?AlumnoCursoHistorico $cursoHistorico): self
    {
        $this->cursoHistorico = $cursoHistorico;

        return $this;
    }

    public function getMes(): ?int
    {
        return $this->mes;
    }

    public function setMes(int $mes): self
    {
        $this->mes = $mes;

        return $this;
    }

    public function getAno(): ?int
    {
        return $this->ano;
    }

    public function setAno(int $ano): self
    {
        $this->ano = $ano;

        return $this;
    }

    public function getPeriodo(): string
    {
        // Si es una cuota de inscripción anual, mostrar solo el año
        if ($this->esCuotaInscripcionAnual) {
            return 'Inscripción Anual ' . $this->ano;
        }
        
        $nombresMeses = [
            1 => 'Enero', 2 => 'Febrero', 3 => 'Marzo', 4 => 'Abril',
            5 => 'Mayo', 6 => 'Junio', 7 => 'Julio', 8 => 'Agosto',
            9 => 'Septiembre', 10 => 'Octubre', 11 => 'Noviembre', 12 => 'Diciembre'
        ];
        
        return $nombresMeses[$this->mes] . ' ' . $this->ano;
    }

    /**
     * @return Collection<int, PagoAplicacion>
     */
    public function getAplicaciones(): Collection
    {
        return $this->aplicaciones;
    }

    public function addAplicacion(PagoAplicacion $aplicacion): self
    {
        if (!$this->aplicaciones->contains($aplicacion)) {
            $this->aplicaciones[] = $aplicacion;
            $aplicacion->setDeuda($this);
        }

        return $this;
    }

    public function removeAplicacion(PagoAplicacion $aplicacion): self
    {
        if ($this->aplicaciones->removeElement($aplicacion)) {
            if ($aplicacion->getDeuda() === $this) {
                $aplicacion->setDeuda(null);
            }
        }

        return $this;
    }

    /**
     * Calcula el monto total pagado sumando todas las aplicaciones
     */
    public function getMontoPagado(): float
    {
        $total = 0;
        foreach ($this->aplicaciones as $aplicacion) {
            $total += $aplicacion->getMontoAplicado();
        }
        return $total;
    }

    /**
     * Calcula el monto pagado con créditos/saldos a favor
     */
    public function getMontoPagadoPorCreditos(): float
    {
        $total = 0;
        foreach ($this->creditoAplicaciones as $aplicacion) {
            $total += (float) $aplicacion->getMontoAplicado();
        }
        return $total;
    }

    /**
     * Calcula el monto pendiente (monto total - monto pagado - créditos aplicados)
     */
    public function getMontoPendiente(): float
    {
        return max(0, $this->getMontoTotal() - $this->getMontoPagado() - $this->getMontoPagadoPorCreditos());
    }

    /**
     * @return Collection<int, SaldoFavorAplicacion>
     */
    public function getCreditoAplicaciones(): Collection
    {
        return $this->creditoAplicaciones;
    }

    public function addCreditoAplicacion(SaldoFavorAplicacion $aplicacion): self
    {
        if (!$this->creditoAplicaciones->contains($aplicacion)) {
            $this->creditoAplicaciones[] = $aplicacion;
            $aplicacion->setDeuda($this);
        }
        return $this;
    }

    public function removeCreditoAplicacion(SaldoFavorAplicacion $aplicacion): self
    {
        if ($this->creditoAplicaciones->removeElement($aplicacion)) {
            if ($aplicacion->getDeuda() === $this) {
                $aplicacion->setDeuda(null);
            }
        }
        return $this;
    }

    /**
     * Verifica si la deuda está completamente pagada
     * Una deuda está pagada si tiene al menos un pago aplicado (no hay pagos parciales)
     */
    public function isPagado(): bool
    {
        return !$this->aplicaciones->isEmpty();
    }

    /**
     * Verifica si la deuda está parcialmente pagada.
     *
     * @deprecated No usar: contradice la política del sistema. PagoService, al aplicar
     * un pago menor al pendiente, baja el monto de la deuda a lo efectivamente pagado
     * y la cancela (ver PagoService::aplicarPagoADeuda(), $cerrarDeudaSiMontoMenor, que
     * todos los llamadores pasan en true). Por eso no quedan deudas parcialmente
     * pagadas y este método siempre devuelve false después de aplicar un pago.
     * Sin usos en el código.
     */
    public function isParcialmentePagado(): bool
    {
        $montoPagado = $this->getMontoPagado();
        return $montoPagado > 0 && $montoPagado < $this->getMontoTotal();
    }

    /**
     * Obtiene la fecha del primer pago aplicado
     */
    public function getFechaPago(): ?\DateTimeInterface
    {
        if ($this->aplicaciones->isEmpty()) {
            return null;
        }
        
        $fechaMasAntigua = null;
        foreach ($this->aplicaciones as $aplicacion) {
            $fechaAplicacion = $aplicacion->getFechaAplicacion();
            if ($fechaMasAntigua === null || $fechaAplicacion < $fechaMasAntigua) {
                $fechaMasAntigua = $fechaAplicacion;
            }
        }
        
        return $fechaMasAntigua;
    }

    public function getFechaCreacion(): ?\DateTimeInterface
    {
        return $this->fechaCreacion;
    }

    public function setFechaCreacion(\DateTimeInterface $fechaCreacion): self
    {
        $this->fechaCreacion = $fechaCreacion;

        return $this;
    }


    public function getMonto(): ?float
    {
        return $this->monto;
    }

    public function setMonto(float $monto): self
    {
        $this->monto = $monto;

        return $this;
    }

    public function getInteres(): ?float
    {
        return $this->interes;
    }

    public function setInteres(?float $interes): self
    {
        $this->interes = $interes;

        return $this;
    }

    public function getMontoTotal(): float
    {
        return $this->monto + $this->interes;
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

    public function getEsCuotaInscripcionAnual(): bool
    {
        return $this->esCuotaInscripcionAnual;
    }

    public function setEsCuotaInscripcionAnual(bool $esCuotaInscripcionAnual): self
    {
        $this->esCuotaInscripcionAnual = $esCuotaInscripcionAnual;

        return $this;
    }
}
