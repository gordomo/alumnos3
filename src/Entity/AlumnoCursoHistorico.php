<?php

namespace App\Entity;

use App\Repository\AlumnoCursoHistoricoRepository;
use Doctrine\ORM\Mapping as ORM;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;

/**
 * @ORM\Entity(repositoryClass=AlumnoCursoHistoricoRepository::class)
 */
class AlumnoCursoHistorico
{
    /**
     * @ORM\Id
     * @ORM\GeneratedValue
     * @ORM\Column(type="integer")
     */
    private $id;

    /**
     * @ORM\ManyToOne(targetEntity=Alumno::class, inversedBy="cursosHistoricos")
     * @ORM\JoinColumn(nullable=false)
     */
    private $alumno;

    /**
     * @ORM\ManyToOne(targetEntity=Curso::class)
     * @ORM\JoinColumn(nullable=false)
     */
    private $curso;

    /**
     * @ORM\Column(type="date")
     */
    private $fechaAlta;

    /**
     * @ORM\Column(type="date", nullable=true)
     */
    private $fechaBaja;

    /**
     * @ORM\Column(type="boolean")
     */
    private $activo = true;

    /**
     * @ORM\OneToMany(targetEntity=AlumnosPagos::class, mappedBy="cursoHistorico")
     */
    private $pagos;

    /**
     * Snapshot del curso al momento de la inscripción
     * Estos campos preservan la información original aunque el curso cambie después
     */
    
    /**
     * @ORM\Column(type="string", length=255, nullable=true)
     */
    private $nombreCurso;

    /**
     * @ORM\Column(type="decimal", precision=10, scale=2, nullable=true)
     */
    private $precioMensual;

    /**
     * @ORM\Column(type="date", nullable=true)
     */
    private $fechaInicio;

    /**
     * @ORM\Column(type="date", nullable=true)
     */
    private $fechaFin;

    /**
     * @ORM\Column(type="boolean", options={"default": false})
     */
    private $comenzarDeudaProximoMes = false;

    /**
     * Modo de generación de deuda:
     * - 'inscripcion': desde el mes de inscripción (default)
     * - 'proximo_mes': desde el mes siguiente a la inscripción
     * - 'inicio_curso': desde el inicio del curso
     * 
     * @ORM\Column(type="string", length=20, options={"default": "inscripcion"})
     */
    private $modoGeneracionDeuda = 'inscripcion';

    public function __construct()
    {
        $this->pagos = new ArrayCollection();
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

    public function getFechaAlta(): ?\DateTimeInterface
    {
        return $this->fechaAlta;
    }

    public function setFechaAlta(\DateTimeInterface $fechaAlta): self
    {
        $this->fechaAlta = $fechaAlta;
        return $this;
    }

    public function getFechaBaja(): ?\DateTimeInterface
    {
        return $this->fechaBaja;
    }

    public function setFechaBaja(?\DateTimeInterface $fechaBaja): self
    {
        $this->fechaBaja = $fechaBaja;
        return $this;
    }

    public function isActivo(): bool
    {
        return $this->activo;
    }

    public function setActivo(bool $activo): self
    {
        $this->activo = $activo;
        return $this;
    }

    /**
     * @return Collection|AlumnosPagos[]
     */
    public function getPagos(): Collection
    {
        return $this->pagos;
    }

    public function addPago(AlumnosPagos $pago): self
    {
        if (!$this->pagos->contains($pago)) {
            $this->pagos[] = $pago;
            $pago->setCursoHistorico($this);
        }
        return $this;
    }

    public function removePago(AlumnosPagos $pago): self
    {
        if ($this->pagos->removeElement($pago)) {
            if ($pago->getCursoHistorico() === $this) {
                $pago->setCursoHistorico(null);
            }
        }
        return $this;
    }

    /**
     * Obtiene la fecha de inicio del período académico
     * Prioriza el snapshot, si no existe usa la fecha del curso
     */
    public function getFechaInicioPeriodo(): ?\DateTimeInterface
    {
        return $this->fechaInicio ?? $this->curso->getFechaInicio();
    }

    /**
     * Obtiene la fecha de fin del período académico
     * Prioriza el snapshot, si no existe usa la fecha del curso
     */
    public function getFechaFinPeriodo(): ?\DateTimeInterface
    {
        return $this->fechaFin ?? $this->curso->getFechaFin();
    }

    public function getNombreCurso(): ?string
    {
        return $this->nombreCurso ?? $this->curso->getNombre();
    }

    public function setNombreCurso(?string $nombreCurso): self
    {
        $this->nombreCurso = $nombreCurso;
        return $this;
    }

    public function getPrecioMensual(): ?float
    {
        return $this->precioMensual ?? $this->curso->getPrecio();
    }

    public function setPrecioMensual(?float $precioMensual): self
    {
        $this->precioMensual = $precioMensual;
        return $this;
    }

    public function getFechaInicio(): ?\DateTimeInterface
    {
        return $this->fechaInicio;
    }

    public function setFechaInicio(?\DateTimeInterface $fechaInicio): self
    {
        $this->fechaInicio = $fechaInicio;
        return $this;
    }

    public function getFechaFin(): ?\DateTimeInterface
    {
        return $this->fechaFin;
    }

    public function setFechaFin(?\DateTimeInterface $fechaFin): self
    {
        $this->fechaFin = $fechaFin;
        return $this;
    }

    public function getComenzarDeudaProximoMes(): bool
    {
        return $this->comenzarDeudaProximoMes;
    }

    public function setComenzarDeudaProximoMes(bool $comenzarDeudaProximoMes): self
    {
        $this->comenzarDeudaProximoMes = $comenzarDeudaProximoMes;
        return $this;
    }

    public function getModoGeneracionDeuda(): string
    {
        return $this->modoGeneracionDeuda ?? 'inscripcion';
    }

    public function setModoGeneracionDeuda(string $modoGeneracionDeuda): self
    {
        $this->modoGeneracionDeuda = $modoGeneracionDeuda;
        return $this;
    }
} 