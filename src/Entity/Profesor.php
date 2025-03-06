<?php

namespace App\Entity;

use App\Repository\ProfesorRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * @ORM\Entity(repositoryClass=ProfesorRepository::class)
 */
class Profesor
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
     * @ORM\Column(type="text")
     */
    private $apellido;

    /**
     * @ORM\Column(type="text")
     */
    private $dni;

    /**
     * @ORM\Column(type="text")
     */
    private $email;

    /**
     * @ORM\Column(type="text", nullable=true)
     */
    private $precioHora;

    /**
     * @ORM\Column(type="text", nullable=true)
     */
    private $viatico;

    /**
     * @return mixed
     */
    public function getViatico()
    {
        return $this->viatico;
    }

    /**
     * @param mixed $viatico
     */
    public function setViatico($viatico): void
    {
        $this->viatico = $viatico;
    }

    /**
     * @ORM\Column(type="text", nullable=true)
     */
    private $tel;

    /**
     * @ORM\ManyToMany(targetEntity=Curso::class, inversedBy="profesores")
     */
    private $curso;

    /**
     * @ORM\OneToMany(targetEntity=AsistenciaProfesores::class, mappedBy="profesor", orphanRemoval=true)
     */
    private $asistenciaProfesores;

    public function __construct()
    {
        $this->curso = new ArrayCollection();
        $this->asistenciaProfesores = new ArrayCollection();
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

    public function getApellido(): ?string
    {
        return $this->apellido;
    }

    public function setApellido(string $apellido): self
    {
        $this->apellido = $apellido;

        return $this;
    }

    public function getDni(): ?string
    {
        return $this->dni;
    }

    public function setDni(string $dni): self
    {
        $this->dni = $dni;

        return $this;
    }

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function setEmail(string $email): self
    {
        $this->email = $email;

        return $this;
    }

    public function getTel(): ?string
    {
        return $this->tel;
    }

    public function setTel(?string $tel): self
    {
        $this->tel = $tel;

        return $this;
    }

    /**
     * @return Collection<int, Curso>
     */
    public function getCurso(): Collection
    {
        return $this->curso;
    }

    public function addCurso(Curso $curso): self
    {
        if (!$this->curso->contains($curso)) {
            $this->curso[] = $curso;
        }

        return $this;
    }

    public function removeCurso(Curso $curso): self
    {
        $this->curso->removeElement($curso);

        return $this;
    }

    /**
     * @return Collection<int, AsistenciaProfesores>
     */
    public function getAsistenciaProfesores(): Collection
    {
        return $this->asistenciaProfesores;
    }

    public function addAsistenciaProfesore(AsistenciaProfesores $asistenciaProfesore): self
    {
        if (!$this->asistenciaProfesores->contains($asistenciaProfesore)) {
            $this->asistenciaProfesores[] = $asistenciaProfesore;
            $asistenciaProfesore->setProfesor($this);
        }

        return $this;
    }

    public function removeAsistenciaProfesore(AsistenciaProfesores $asistenciaProfesore): self
    {
        if ($this->asistenciaProfesores->removeElement($asistenciaProfesore)) {
            // set the owning side to null (unless already changed)
            if ($asistenciaProfesore->getProfesor() === $this) {
                $asistenciaProfesore->setProfesor(null);
            }
        }

        return $this;
    }

    /**
     * @return mixed
     */
    public function getPrecioHora()
    {
        return $this->precioHora ? $this->precioHora : "0" ;
    }

    /**
     * @param mixed $precioHora
     */
    public function setPrecioHora($precioHora): void
    {
        $this->precioHora = $precioHora;
    }
}
