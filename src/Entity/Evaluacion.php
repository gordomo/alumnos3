<?php

namespace App\Entity;

use App\Repository\EvaluacionRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Una instancia de evaluación de un curso: un trabajo práctico, un parcial, una entrega.
 *
 * El profesor crea las que necesite y carga una calificación por alumno en cada una.
 *
 * Guarda un snapshot de la escala vigente al crearse (tipo, mínimo, máximo, aprobación).
 * Es el mismo idioma que AlumnoCursoHistorico con precioMensual: los getters hacen
 * fallback a la configuración del instituto si el snapshot está en null. Sin esto, cambiar
 * la escala del instituto reinterpretaría notas ya cargadas.
 *
 * @ORM\Entity(repositoryClass=EvaluacionRepository::class)
 * @ORM\Table(name="evaluacion", indexes={
 *     @ORM\Index(name="idx_evaluacion_curso_fecha", columns={"curso_id", "fecha"}),
 *     @ORM\Index(name="idx_evaluacion_instituto", columns={"instituto_id"})
 * })
 */
class Evaluacion
{
    /**
     * @ORM\Id
     * @ORM\GeneratedValue
     * @ORM\Column(type="integer")
     */
    private $id;

    /**
     * @ORM\ManyToOne(targetEntity=Instituto::class)
     * @ORM\JoinColumn(nullable=false)
     */
    private $instituto;

    /**
     * @ORM\ManyToOne(targetEntity=Curso::class)
     * @ORM\JoinColumn(nullable=false)
     */
    private $curso;

    /**
     * @ORM\Column(type="string", length=150)
     * @Assert\NotBlank(message="El nombre de la evaluación no puede estar vacío")
     */
    private $nombre;

    /**
     * @ORM\Column(type="date")
     * @Assert\NotNull(message="La fecha de la evaluación es obligatoria")
     */
    private $fecha;

    /**
     * Período al que pertenece la evaluación: un trimestre, una mesa de examen.
     *
     * Explícito y no derivado de la fecha, porque los períodos de examen se solapan con los
     * de cursada (una mesa de julio cae dentro del segundo trimestre) y la fecha sola no
     * alcanza para desambiguar. El formulario lo propone según la fecha y el profesor puede
     * cambiarlo.
     *
     * Null cuando el instituto no usa períodos, o cuando la fecha no cae en ninguno.
     *
     * @ORM\ManyToOne(targetEntity=PeriodoAcademico::class)
     * @ORM\JoinColumn(nullable=true, onDelete="SET NULL")
     */
    private $periodo;

    /**
     * @ORM\Column(type="text", nullable=true)
     */
    private $descripcion;

    /**
     * Peso en el promedio. Con el default 1.00 el promedio ponderado equivale al simple.
     *
     * @ORM\Column(type="decimal", precision=5, scale=2, options={"default": "1.00"})
     * @Assert\Positive(message="El peso debe ser mayor que cero")
     */
    private $peso = '1.00';

    /**
     * Permite tener evaluaciones diagnósticas o de práctica sin que afecten el promedio.
     *
     * @ORM\Column(type="boolean", options={"default": true})
     */
    private $cuentaParaPromedio = true;

    /**
     * @ORM\Column(type="string", length=20, nullable=true)
     */
    private $tipoEscala;

    /**
     * @ORM\Column(type="decimal", precision=6, scale=2, nullable=true)
     */
    private $notaMinima;

    /**
     * @ORM\Column(type="decimal", precision=6, scale=2, nullable=true)
     */
    private $notaMaxima;

    /**
     * @ORM\Column(type="decimal", precision=6, scale=2, nullable=true)
     */
    private $notaAprobacion;

    /**
     * @ORM\ManyToOne(targetEntity=User::class)
     * @ORM\JoinColumn(nullable=true, onDelete="SET NULL")
     */
    private $creadoPor;

    /**
     * @ORM\Column(type="datetime")
     */
    private $createdAt;

    /**
     * @ORM\Column(type="datetime", nullable=true)
     */
    private $updatedAt;

    /**
     * @ORM\OneToMany(targetEntity=Calificacion::class, mappedBy="evaluacion", orphanRemoval=true)
     */
    private $calificaciones;

    public function __construct()
    {
        $this->calificaciones = new ArrayCollection();
        $this->createdAt = new \DateTime();
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

    public function getCurso(): ?Curso
    {
        return $this->curso;
    }

    public function setCurso(?Curso $curso): self
    {
        $this->curso = $curso;
        return $this;
    }

    public function getNombre(): ?string
    {
        return $this->nombre;
    }

    public function setNombre(string $nombre): self
    {
        $this->nombre = $nombre;
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

    public function getPeriodo(): ?PeriodoAcademico
    {
        return $this->periodo;
    }

    public function setPeriodo(?PeriodoAcademico $periodo): self
    {
        $this->periodo = $periodo;
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

    public function getPeso(): float
    {
        return (float) $this->peso;
    }

    public function setPeso(float $peso): self
    {
        $this->peso = (string) $peso;
        return $this;
    }

    public function isCuentaParaPromedio(): bool
    {
        return (bool) $this->cuentaParaPromedio;
    }

    public function getCuentaParaPromedio(): bool
    {
        return (bool) $this->cuentaParaPromedio;
    }

    public function setCuentaParaPromedio(bool $cuentaParaPromedio): self
    {
        $this->cuentaParaPromedio = $cuentaParaPromedio;
        return $this;
    }

    public function setTipoEscala(?string $tipoEscala): self
    {
        $this->tipoEscala = $tipoEscala;
        return $this;
    }

    public function setNotaMinima(?float $notaMinima): self
    {
        $this->notaMinima = $notaMinima;
        return $this;
    }

    public function setNotaMaxima(?float $notaMaxima): self
    {
        $this->notaMaxima = $notaMaxima;
        return $this;
    }

    public function setNotaAprobacion(?float $notaAprobacion): self
    {
        $this->notaAprobacion = $notaAprobacion;
        return $this;
    }

    /**
     * Snapshot del tipo de escala, o el de la configuración del instituto si no hay.
     */
    public function getTipoEscala(): ?string
    {
        if ($this->tipoEscala !== null) {
            return $this->tipoEscala;
        }

        $config = $this->instituto ? $this->instituto->getConfiguracion() : null;

        return $config ? $config->getModoCalificacion() : null;
    }

    public function getNotaMinima(): ?float
    {
        if ($this->notaMinima !== null) {
            return (float) $this->notaMinima;
        }

        $config = $this->instituto ? $this->instituto->getConfiguracion() : null;

        return $config ? $config->getNotaMinima() : null;
    }

    public function getNotaMaxima(): ?float
    {
        if ($this->notaMaxima !== null) {
            return (float) $this->notaMaxima;
        }

        $config = $this->instituto ? $this->instituto->getConfiguracion() : null;

        return $config ? $config->getNotaMaxima() : null;
    }

    public function getNotaAprobacion(): ?float
    {
        if ($this->notaAprobacion !== null) {
            return (float) $this->notaAprobacion;
        }

        $config = $this->instituto ? $this->instituto->getConfiguracion() : null;

        return $config ? $config->getNotaAprobacion() : null;
    }

    public function getCreadoPor(): ?User
    {
        return $this->creadoPor;
    }

    public function setCreadoPor(?User $creadoPor): self
    {
        $this->creadoPor = $creadoPor;
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

    public function setUpdatedAt(?\DateTimeInterface $updatedAt): self
    {
        $this->updatedAt = $updatedAt;
        return $this;
    }

    /**
     * @return Collection<int, Calificacion>
     */
    public function getCalificaciones(): Collection
    {
        return $this->calificaciones;
    }

    public function addCalificacion(Calificacion $calificacion): self
    {
        if (!$this->calificaciones->contains($calificacion)) {
            $this->calificaciones[] = $calificacion;
            $calificacion->setEvaluacion($this);
        }

        return $this;
    }

    public function removeCalificacion(Calificacion $calificacion): self
    {
        if ($this->calificaciones->removeElement($calificacion)) {
            if ($calificacion->getEvaluacion() === $this) {
                $calificacion->setEvaluacion(null);
            }
        }

        return $this;
    }
}
