<?php

namespace App\Entity;

use App\Repository\TareaRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Una tarea pedida a un curso.
 *
 * Se modela igual que una evaluación: pertenece a un curso, tiene fecha y período, y la
 * entrega de cada alumno se guarda aparte. Con eso la libreta puede informar los dos números
 * que necesita por período: cuántas se pidieron y cuántas entregó cada alumno.
 *
 * @ORM\Entity(repositoryClass=TareaRepository::class)
 * @ORM\Table(name="tarea", indexes={
 *     @ORM\Index(name="idx_tarea_curso_fecha", columns={"curso_id", "fecha"}),
 *     @ORM\Index(name="idx_tarea_instituto", columns={"instituto_id"})
 * })
 */
class Tarea
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
     * @Assert\NotBlank(message="El título de la tarea es obligatorio")
     * @Assert\Length(max=150)
     */
    private $titulo;

    /**
     * Cuándo se pidió. Es la fecha que la ubica en un período.
     *
     * @ORM\Column(type="date")
     * @Assert\NotNull(message="La fecha de la tarea es obligatoria")
     */
    private $fecha;

    /**
     * Para cuándo hay que entregarla. Opcional: no todas las tareas tienen plazo.
     *
     * @ORM\Column(type="date", nullable=true)
     */
    private $fechaEntrega;

    /**
     * Período al que pertenece, con el mismo criterio que la evaluación: explícito, propuesto
     * por la fecha al crearla, y editable.
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
     * @ORM\OneToMany(targetEntity=TareaEntrega::class, mappedBy="tarea", cascade={"persist", "remove"})
     */
    private $entregas;

    public function __construct()
    {
        $this->entregas = new ArrayCollection();
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

    public function getTitulo(): ?string
    {
        return $this->titulo;
    }

    public function setTitulo(string $titulo): self
    {
        $this->titulo = $titulo;
        return $this;
    }

    public function getFecha(): ?\DateTimeInterface
    {
        return $this->fecha;
    }

    public function setFecha(?\DateTimeInterface $fecha): self
    {
        $this->fecha = $fecha;
        return $this;
    }

    public function getFechaEntrega(): ?\DateTimeInterface
    {
        return $this->fechaEntrega;
    }

    public function setFechaEntrega(?\DateTimeInterface $fechaEntrega): self
    {
        $this->fechaEntrega = $fechaEntrega;
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
     * @return Collection<int, TareaEntrega>
     */
    public function getEntregas(): Collection
    {
        return $this->entregas;
    }

    public function addEntrega(TareaEntrega $entrega): self
    {
        if (!$this->entregas->contains($entrega)) {
            $this->entregas[] = $entrega;
            $entrega->setTarea($this);
        }

        return $this;
    }

    public function removeEntrega(TareaEntrega $entrega): self
    {
        $this->entregas->removeElement($entrega);
        return $this;
    }

    /**
     * Cuántos alumnos la entregaron.
     */
    public function contarEntregadas(): int
    {
        $entregadas = 0;
        foreach ($this->entregas as $entrega) {
            if ($entrega->isEntregada()) {
                $entregadas++;
            }
        }

        return $entregadas;
    }

    public function __toString(): string
    {
        return (string) $this->titulo;
    }
}
