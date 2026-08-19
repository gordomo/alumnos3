<?php

namespace App\Entity;

use App\Repository\TareaEntregaRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Si un alumno entregó una tarea.
 *
 * Cuelga de la inscripción y no del alumno, con el mismo criterio que Calificacion: la entrega
 * pertenece a una cursada concreta, y el cierre de curso opera sobre inscripciones.
 *
 * Fila ausente significa "no entregada". Solo se guarda fila para el alumno que el profesor
 * tocó, igual que en la grilla de notas.
 *
 * @ORM\Entity(repositoryClass=TareaEntregaRepository::class)
 * @ORM\Table(name="tarea_entrega", indexes={
 *     @ORM\Index(name="idx_entrega_hist", columns={"alumno_curso_historico_id"}),
 *     @ORM\Index(name="idx_entrega_instituto", columns={"instituto_id"})
 * }, uniqueConstraints={
 *     @ORM\UniqueConstraint(name="uniq_entrega_tarea_hist", columns={"tarea_id", "alumno_curso_historico_id"})
 * })
 */
class TareaEntrega
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
     * @ORM\ManyToOne(targetEntity=Tarea::class, inversedBy="entregas")
     * @ORM\JoinColumn(nullable=false)
     */
    private $tarea;

    /**
     * @ORM\ManyToOne(targetEntity=AlumnoCursoHistorico::class)
     * @ORM\JoinColumn(name="alumno_curso_historico_id", nullable=false)
     */
    private $cursoHistorico;

    /**
     * @ORM\Column(type="boolean", options={"default": false})
     */
    private $entregada = false;

    /**
     * @ORM\Column(type="text", nullable=true)
     */
    private $observaciones;

    /**
     * @ORM\ManyToOne(targetEntity=User::class)
     * @ORM\JoinColumn(nullable=true, onDelete="SET NULL")
     */
    private $cargadoPor;

    /**
     * @ORM\Column(type="datetime")
     */
    private $updatedAt;

    public function __construct()
    {
        $this->updatedAt = new \DateTime();
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

    public function getTarea(): ?Tarea
    {
        return $this->tarea;
    }

    public function setTarea(?Tarea $tarea): self
    {
        $this->tarea = $tarea;
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

    public function isEntregada(): bool
    {
        return (bool) $this->entregada;
    }

    public function setEntregada(bool $entregada): self
    {
        $this->entregada = $entregada;
        return $this;
    }

    public function getObservaciones(): ?string
    {
        return $this->observaciones;
    }

    public function setObservaciones(?string $observaciones): self
    {
        $this->observaciones = $observaciones !== null && trim($observaciones) !== '' ? trim($observaciones) : null;
        return $this;
    }

    public function getCargadoPor(): ?User
    {
        return $this->cargadoPor;
    }

    public function setCargadoPor(?User $cargadoPor): self
    {
        $this->cargadoPor = $cargadoPor;
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
     * Si esta fila aporta algo. Una fila sin entrega y sin observación no tiene sentido
     * guardarla: la ausencia ya significa "no entregada".
     */
    public function tieneContenido(): bool
    {
        return $this->isEntregada() || $this->observaciones !== null;
    }
}
