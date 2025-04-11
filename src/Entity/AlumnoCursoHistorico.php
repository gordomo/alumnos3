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
     * Obtiene la fecha de inicio del período académico (usa la fecha del curso)
     */
    public function getFechaInicioPeriodo(): ?\DateTimeInterface
    {
        return $this->curso->getFechaInicio();
    }

    /**
     * Obtiene la fecha de fin del período académico (usa la fecha del curso)
     */
    public function getFechaFinPeriodo(): ?\DateTimeInterface
    {
        return $this->curso->getFechaFin();
    }
} 