<?php

namespace App\Entity;

use App\Repository\SaldoFavorRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * @ORM\Entity(repositoryClass=SaldoFavorRepository::class)
 * @ORM\Table(name="saldo_favor")
 */
class SaldoFavor
{
    public const TIPO_NOTA_CREDITO = 'nota_credito';
    public const TIPO_SOBREPAGO = 'sobrepago';
    public const TIPO_CANCELACION_DEUDA = 'cancelacion_deuda';

    /**
     * @ORM\Id
     * @ORM\GeneratedValue
     * @ORM\Column(type="integer")
     */
    private $id;

    /**
     * @ORM\ManyToOne(targetEntity=Alumno::class)
     * @ORM\JoinColumn(nullable=false)
     */
    private $alumno;

    /**
     * @ORM\ManyToOne(targetEntity=Instituto::class)
     * @ORM\JoinColumn(nullable=false)
     */
    private $instituto;

    /**
     * @ORM\Column(type="decimal", precision=10, scale=2)
     */
    private $monto;

    /**
     * @ORM\Column(type="decimal", precision=10, scale=2)
     */
    private $montoDisponible;

    /**
     * @ORM\Column(type="string", length=50)
     */
    private $tipo;

    /**
     * @ORM\Column(type="text", nullable=true)
     */
    private $descripcion;

    /**
     * @ORM\Column(type="datetime")
     */
    private $fecha;

    /**
     * @ORM\ManyToOne(targetEntity=AlumnosPagos::class)
     * @ORM\JoinColumn(nullable=true)
     */
    private $pagoOrigen;

    /**
     * @ORM\ManyToOne(targetEntity=Curso::class)
     * @ORM\JoinColumn(nullable=true)
     */
    private $curso;

    /**
     * @ORM\OneToMany(targetEntity=SaldoFavorAplicacion::class, mappedBy="saldoFavor", cascade={"persist", "remove"}, fetch="EAGER")
     */
    private $aplicaciones;

    public function __construct()
    {
        $this->fecha = new \DateTime();
        $this->aplicaciones = new ArrayCollection();
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

    public function getInstituto(): ?Instituto
    {
        return $this->instituto;
    }

    public function setInstituto(?Instituto $instituto): self
    {
        $this->instituto = $instituto;
        return $this;
    }

    public function getMonto(): ?string
    {
        return $this->monto;
    }

    public function setMonto(string $monto): self
    {
        $this->monto = $monto;
        return $this;
    }

    public function getMontoDisponible(): ?string
    {
        return $this->montoDisponible;
    }

    public function setMontoDisponible(string $montoDisponible): self
    {
        $this->montoDisponible = $montoDisponible;
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

    public function getDescripcion(): ?string
    {
        return $this->descripcion;
    }

    public function setDescripcion(?string $descripcion): self
    {
        $this->descripcion = $descripcion;
        return $this;
    }

    public function getFecha(): ?\DateTimeInterface
    {
        return $this->fecha;
    }

    public function setFecha(\DateTimeInterface $fecha): self
    {
        $this->fecha = $fecha;
        return $this;
    }

    public function getPagoOrigen(): ?AlumnosPagos
    {
        return $this->pagoOrigen;
    }

    public function setPagoOrigen(?AlumnosPagos $pagoOrigen): self
    {
        $this->pagoOrigen = $pagoOrigen;
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

    /**
     * @return Collection<int, SaldoFavorAplicacion>
     */
    public function getAplicaciones(): Collection
    {
        return $this->aplicaciones;
    }

    public function addAplicacion(SaldoFavorAplicacion $aplicacion): self
    {
        if (!$this->aplicaciones->contains($aplicacion)) {
            $this->aplicaciones[] = $aplicacion;
            $aplicacion->setSaldoFavor($this);
        }
        return $this;
    }

    public function removeAplicacion(SaldoFavorAplicacion $aplicacion): self
    {
        if ($this->aplicaciones->removeElement($aplicacion)) {
            if ($aplicacion->getSaldoFavor() === $this) {
                $aplicacion->setSaldoFavor(null);
            }
        }
        return $this;
    }

    public function getMontoAplicado(): float
    {
        $total = 0;
        foreach ($this->aplicaciones as $aplicacion) {
            $total += (float) $aplicacion->getMontoAplicado();
        }
        return $total;
    }

    public function tieneSaldoDisponible(): bool
    {
        return (float) $this->montoDisponible > 0;
    }

    public function getTipoLabel(): string
    {
        $labels = [
            self::TIPO_NOTA_CREDITO => 'Nota de Crédito',
            self::TIPO_SOBREPAGO => 'Sobrepago',
            self::TIPO_CANCELACION_DEUDA => 'Cancelación de Deuda',
        ];
        return $labels[$this->tipo] ?? $this->tipo;
    }
}
