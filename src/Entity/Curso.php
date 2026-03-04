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

    /**
     * @ORM\ManyToMany(targetEntity=Profesor::class, inversedBy="cursos")
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

    /**
     * Horarios por día (ej: Martes 18-19, Jueves 18:30-19:30). Si tiene elementos, se usan estos;
     * si no, se usan los campos legacy dias, horarioInicio, horarioFin.
     * @ORM\OneToMany(targetEntity=CursoHorario::class, mappedBy="curso", cascade={"persist", "remove"}, orphanRemoval=true)
     * @ORM\OrderBy({"dia"="ASC", "horarioInicio"="ASC"})
     */
    private $horarios;

    /**
     * @ORM\Column(type="text")
     */
    private $precio;

    public function __construct()
    {
        $this->profesores = new ArrayCollection();
        $this->alumnos = new ArrayCollection();
        $this->alumnosPagos = new ArrayCollection();
        $this->horarios = new ArrayCollection();
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

    public function getInstituto(): ?Instituto
    {
        return $this->instituto;
    }

    public function setInstituto(Instituto $instituto): self
    {
        $this->instituto = $instituto;

        return $this;
    }

    /**
     * Días en los que se dicta el curso. Si tiene horarios definidos, devuelve los días de esos horarios; si no, el array legacy.
     */
    public function getDias(): ?array
    {
        if ($this->horarios->count() > 0) {
            $dias = [];
            foreach ($this->horarios as $h) {
                if ($h->getDia() !== null && !in_array($h->getDia(), $dias, true)) {
                    $dias[] = $h->getDia();
                }
            }
            sort($dias);
            return $dias;
        }
        return $this->dias;
    }

    public function setDias(array $dias): self
    {
        $this->dias = $dias;

        return $this;
    }

    /**
     * Duración en horas (decimal). Si tiene horarios, devuelve la duración del primero; si no, el valor legacy.
     */
    public function getDuracion()
    {
        if ($this->horarios->count() > 0) {
            $first = $this->horarios->first();
            return $first instanceof CursoHorario ? $first->getDuracion() : $this->duracion;
        }
        return $this->duracion;
    }

    /**
     * Duración del curso para un día dado (por si tiene distintos horarios por día).
     */
    public function getDuracionParaDia(string $dia): ?float
    {
        foreach ($this->horarios as $h) {
            if ($h->getDia() === $dia) {
                return $h->getDuracion();
            }
        }
        return $this->getDuracion();
    }

    /**
     * @return Collection<int, CursoHorario>
     */
    public function getHorarios(): Collection
    {
        return $this->horarios;
    }

    public function addHorario(CursoHorario $horario): self
    {
        if (!$this->horarios->contains($horario)) {
            $this->horarios[] = $horario;
            $horario->setCurso($this);
        }
        return $this;
    }

    public function removeHorario(CursoHorario $horario): self
    {
        if ($this->horarios->removeElement($horario)) {
            if ($horario->getCurso() === $this) {
                $horario->setCurso(null);
            }
        }
        return $this;
    }

    /**
     * @param mixed $duracion
     */
    public function setDuracion($duracion): void
    {
        $this->duracion = $duracion;
    }

    /**
     * @return Collection|Profesor[]
     */
    public function getProfesores(): Collection
    {
        return $this->profesores;
    }

    public function addProfesor(Profesor $profesor): self
    {
        if (!$this->profesores->contains($profesor)) {
            $this->profesores[] = $profesor;
        }
        return $this;
    }

    public function removeProfesor(Profesor $profesor): self
    {
        $this->profesores->removeElement($profesor);
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
     * Horario de inicio. Si tiene horarios definidos, devuelve el del primero; si no, el valor legacy.
     */
    public function getHorarioInicio()
    {
        if ($this->horarios->count() > 0) {
            $first = $this->horarios->first();
            return $first instanceof CursoHorario ? $first->getHorarioInicio() : $this->horarioInicio;
        }
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
     * Horario de fin. Si tiene horarios definidos, devuelve el del primero; si no, el valor legacy.
     */
    public function getHorarioFin()
    {
        if ($this->horarios->count() > 0) {
            $first = $this->horarios->first();
            return $first instanceof CursoHorario ? $first->getHorarioFin() : $this->horarioFin;
        }
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
     * Sincroniza los campos legacy (dias, horarioInicio, horarioFin, duracion) desde la colección horarios.
     * Útil para compatibilidad con código que sigue usando getDias()/getHorarioInicio() y para persistir en columnas existentes.
     */
    public function syncLegacyFromHorarios(): void
    {
        if ($this->horarios->count() === 0) {
            return;
        }
        $dias = [];
        $primero = null;
        $duracionTotal = 0.0;
        foreach ($this->horarios as $h) {
            if ($h->getDia() !== null && $h->getDia() !== '') {
                $dias[] = $h->getDia();
            }
            if ($primero === null) {
                $primero = $h;
            }
            $duracionTotal += $h->getDuracion() ?? 0;
        }
        $this->dias = array_unique($dias);
        if ($primero) {
            $this->horarioInicio = $primero->getHorarioInicio();
            $this->horarioFin = $primero->getHorarioFin();
        }
        $this->duracion = $duracionTotal ?: ($primero ? $primero->getDuracion() : 0);
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

    public function getDisabled(): ?bool
    {
        return $this->disabled;
    }

    public function setDisabled(bool $disabled): self   
    {
        $this->disabled = $disabled;
        return $this;
    }
}
