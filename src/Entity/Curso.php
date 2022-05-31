<?php

namespace App\Entity;

use App\Repository\CursoRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
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
     * @ORM\Column(type="integer")
     */
    private $duracion;

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

    public function __construct()
    {
        $this->profesores = new ArrayCollection();
        $this->alumnos = new ArrayCollection();
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
}
