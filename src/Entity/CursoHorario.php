<?php

namespace App\Entity;

use App\Repository\CursoHorarioRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Un horario concreto de un curso para un día de la semana (ej: Martes 18:00-19:00).
 * Un curso puede tener varios CursoHorario (ej: Martes 18-19 y Jueves 18:30-19:30).
 *
 * @ORM\Entity(repositoryClass=CursoHorarioRepository::class)
 * @ORM\Table(name="curso_horario")
 */
class CursoHorario
{
    /**
     * @ORM\Id
     * @ORM\GeneratedValue
     * @ORM\Column(type="integer")
     */
    private ?int $id = null;

    /**
     * @ORM\ManyToOne(targetEntity=Curso::class, inversedBy="horarios")
     * @ORM\JoinColumn(nullable=false, onDelete="CASCADE")
     */
    private ?Curso $curso = null;

    /**
     * Día de la semana: Lunes, Martes, Miercoles, Jueves, Viernes, Sabado, Domingo
     * @ORM\Column(type="string", length=20)
     */
    private ?string $dia = null;

    /**
     * @ORM\Column(type="time")
     */
    private ?\DateTimeInterface $horarioInicio = null;

    /**
     * @ORM\Column(type="time")
     */
    private ?\DateTimeInterface $horarioFin = null;

    /**
     * Duración en horas (decimal, ej: 1.5). Se calcula desde inicio/fin.
     * @ORM\Column(type="float", nullable=true)
     */
    private ?float $duracion = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCurso(): ?Curso
    {
        return $this->curso;
    }

    public function setCurso(?Curso $curso): static
    {
        $this->curso = $curso;
        return $this;
    }

    public function getDia(): ?string
    {
        return $this->dia;
    }

    public function setDia(string $dia): static
    {
        $this->dia = $dia;
        return $this;
    }

    public function getHorarioInicio(): ?\DateTimeInterface
    {
        return $this->horarioInicio;
    }

    public function setHorarioInicio(\DateTimeInterface $horarioInicio): static
    {
        $this->horarioInicio = $horarioInicio;
        $this->calcularDuracion();
        return $this;
    }

    public function getHorarioFin(): ?\DateTimeInterface
    {
        return $this->horarioFin;
    }

    public function setHorarioFin(\DateTimeInterface $horarioFin): static
    {
        $this->horarioFin = $horarioFin;
        $this->calcularDuracion();
        return $this;
    }

    public function getDuracion(): ?float
    {
        if ($this->duracion === null && $this->horarioInicio && $this->horarioFin) {
            $this->calcularDuracion();
        }
        return $this->duracion;
    }

    public function setDuracion(?float $duracion): static
    {
        $this->duracion = $duracion;
        return $this;
    }

    private function calcularDuracion(): void
    {
        if (!$this->horarioInicio || !$this->horarioFin) {
            return;
        }
        $inicio = $this->horarioInicio instanceof \DateTime ? $this->horarioInicio : new \DateTime($this->horarioInicio->format('H:i'));
        $fin = $this->horarioFin instanceof \DateTime ? $this->horarioFin : new \DateTime($this->horarioFin->format('H:i'));
        $diff = $fin->diff($inicio);
        $this->duracion = $diff->h + $diff->i / 60.0 + $diff->s / 3600.0;
    }
}
