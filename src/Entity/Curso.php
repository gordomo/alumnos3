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
     * @ORM\Column(type="float")
     */
    private $duracion;

    /**
     * @ORM\Column(type="boolean")
     */
    private $disabled = false;

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
}
