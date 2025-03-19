<?php

namespace App\Entity;

use App\Repository\CursoRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\Common\Collections\Criteria;
use Doctrine\ORM\Mapping as ORM;

/**
 * @ORM\Entity(repositoryClass=CursoRepository::class)
 */
class Curso
{
    /**
     * @ORM\Id
     * @ORM\GeneratedValue
     * @ORM\Column(type="integer")
     */
    private $id;

    /**
     * @ORM\Column(type="text")
     */
    private $nombre;

    /**
     * @ORM\Column(type="json")
     */
    private $dias = [];

    /**
     * @ORM\Column(type="time")
     */
    private $horarioInicio;

    /**
     * @ORM\Column(type="time")
     */
    private $horarioFin;

    /**
     * @ORM\Column(type="date")
     */
    private $fechaInicio;

    /**
     * @ORM\Column(type="date")
     */
    private $fechaFin;

    /**
     * @ORM\Column(type="float")
     */
    private $duracion;

    /**
     * @ORM\Column(type="boolean")
     */
    private $disabled = false;

    /**
     * @ORM\ManyToOne(targetEntity=Instituto::class, inversedBy="cursos")
     * @ORM\JoinColumn(nullable=false)
     */
    private $instituto;

    public function getInstituto(): ?Instituto
    {
        return $this->instituto;
    }

    public function setInstituto(?Instituto $instituto): self
    {
        $this->instituto = $instituto;
        return $this;
    }


    /**
     * @return mixed
     */
    public function getDisabled()
    {
        return $this->disabled;
    }

    /**
     * @param mixed $disabled
     */
    public function setDisabled($disabled): void
    {
        $this->disabled = $disabled;
    }

    /**
     * @return mixed
     */
    public function getDuracion()
    {
        return $this->duracion;
    }

    /**
     * @param mixed $duracion
     */
    public function setDuracion($duracion): void
    {
        $this->duracion = $duracion;
    }

    /**
     * @ORM\Column(type="text")
     */
    private $precio;

    /**
     * @ORM\ManyToMany(targetEntity=Profesor::class, mappedBy="curso")
     */
    private $profesores;

    /**
     * @ORM\ManyToMany(targetEntity=Alumno::class, mappedBy="curso")
     */
    private $alumnos;

    /**
     * @ORM\OneToMany(targetEntity=AlumnosPagos::class, mappedBy="curso")
     */
    private $alumnosPagos;

    public function __construct()
    {
        $this->profesores = new ArrayCollection();
        $this->alumnos = new ArrayCollection();
        $this->alumnosPagos = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
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

    public function getPrecio(): ?string
    {
        return $this->precio;
    }

    public function setPrecio(string $precio): self
    {
        $this->precio = $precio;

        return $this;
    }

    public function getDias(): ?array
    {
        return $this->dias;
    }

    public function setDias(array $dias): self
    {
        $this->dias = $dias;

        return $this;
    }

    /**
     * @return Collection<int, Profesor>
     */
    public function getProfesores(): Collection
    {
        return $this->profesores;
    }

    public function addProfesore(Profesor $profesore): self
    {
        if (!$this->profesores->contains($profesore)) {
            $this->profesores[] = $profesore;
            $profesore->addCurso($this);
        }

        return $this;
    }

    public function removeProfesore(Profesor $profesore): self
    {
        if ($this->profesores->removeElement($profesore)) {
            $profesore->removeCurso($this);
        }

        return $this;
    }

    /**
     * @return Collection<int, Profesor>
     */
    public function getAlumnos(): Collection
    {
        return $this->alumnos;
    }

    public function getAlumnosActivos() {
        //$criteria = Criteria::create()->where(Criteria::expr()->in("activo", 1));
        $criteria = Criteria::create()->where(Criteria::expr()->eq("activo", true));

        return $this->getAlumnos()->matching($criteria);
    }

    public function addAlumno(Profesor $alumno): self
    {
        if (!$this->alumnos->contains($alumno)) {
            $this->alumnos[] = $alumno;
            $alumno->addCurso($this);
        }

        return $this;
    }

    public function removeAlumno(Profesor $alumno): self
    {
        if ($this->alumnos->removeElement($alumno)) {
            $alumno->removeCurso($this);
        }

        return $this;
    }

    /**
     * @return Collection<int, AlumnosPagos>
     */
    public function getAlumnosPagos(): Collection
    {
        return $this->alumnosPagos;
    }

    public function addAlumnosPago(AlumnosPagos $alumnosPago): self
    {
        if (!$this->alumnosPagos->contains($alumnosPago)) {
            $this->alumnosPagos[] = $alumnosPago;
            $alumnosPago->setCurso($this);
        }

        return $this;
    }

    public function removeAlumnosPago(AlumnosPagos $alumnosPago): self
    {
        if ($this->alumnosPagos->removeElement($alumnosPago)) {
            // set the owning side to null (unless already changed)
            if ($alumnosPago->getCurso() === $this) {
                $alumnosPago->setCurso(null);
            }
        }

        return $this;
    }

    /**
     * @return mixed
     */
    public function getHorarioInicio()
    {
        return $this->horarioInicio;
    }

    /**
     * @param mixed $horarioInicio
     */
    public function setHorarioInicio($horarioInicio): void
    {
        $this->horarioInicio = $horarioInicio;
        $this->calcularDuracion();
    }

    /**
     * @return mixed
     */
    public function getHorarioFin()
    {
        return $this->horarioFin;
    }

    /**
     * @param mixed $horarioFin
     */
    public function setHorarioFin($horarioFin): void
    {
        $this->horarioFin = $horarioFin;
        $this->calcularDuracion();
    }

    /**
     * Calcula la duración del curso basada en el horario de inicio y fin
     */
    public function calcularDuracion(): void
    {
        if ($this->horarioInicio && $this->horarioFin) {
            // Convertir strings a DateTime si es necesario
            $inicio = is_string($this->horarioInicio) ? new \DateTime($this->horarioInicio) : $this->horarioInicio;
            $fin = is_string($this->horarioFin) ? new \DateTime($this->horarioFin) : $this->horarioFin;
            
            $diferencia = $fin->diff($inicio);
            $horas = $diferencia->h;
            $minutos = $diferencia->i;
            
            // Convertir a formato decimal (ej: 1:30 -> 1.5)
            $this->duracion = $horas + ($minutos / 60);
        }
    }

    public function getFechaInicio(): ?\DateTimeInterface
    {
        return $this->fechaInicio;
    }

    public function setFechaInicio(\DateTimeInterface $fechaInicio): self
    {
        $this->fechaInicio = $fechaInicio;
        return $this;
    }

    public function getFechaFin(): ?\DateTimeInterface
    {
        return $this->fechaFin;
    }

    public function setFechaFin(\DateTimeInterface $fechaFin): self
    {
        $this->fechaFin = $fechaFin;
        return $this;
    }
}
