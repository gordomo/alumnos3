<?php

namespace App\Entity;

use App\Repository\AsistenciaProfesoresRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * @ORM\Entity(repositoryClass=AsistenciaProfesoresRepository::class)
 */
class AsistenciaProfesores
{
    /**
     * @ORM\Id
     * @ORM\GeneratedValue
     * @ORM\Column(type="integer")
     */
    private $id;

    /**
     * @ORM\ManyToOne(targetEntity=Profesor::class, inversedBy="asistenciaProfesores")
     * @ORM\JoinColumn(nullable=true)
     */
    private $profesor;

    /**
     * @ORM\Column(type="date")
     */
    private $fecha;

    /**
     * @ORM\Column(type="integer", nullable=true)
     */
    private $profesorRemplazante;

    /**
     * @ORM\Column(type="integer")
     */
    private $curso;

    /**
     * @ORM\Column(type="boolean")
     * @ORM\JoinColumn(nullable=false)
     */
    private $presente;

    /**
     * @return mixed
     */
    public function getCurso()
    {
        return $this->curso;
    }

    /**
     * @param mixed $curso
     */
    public function setCurso($curso): void
    {
        $this->curso = $curso;
    }

    /**
     * @return mixed
     */
    public function getProfesorRemplazante()
    {
        return $this->profesorRemplazante;
    }

    /**
     * @param mixed $profesorRRemplazante
     */
    public function setProfesorRemplazante($profesorRemplazante): void
    {
        $this->profesorRemplazante = $profesorRemplazante;
    }

    /**
     * @return mixed
     */
    public function getPresente()
    {
        return $this->presente;
    }

    /**
     * @param mixed $presente
     */
    public function setPresente($presente): void
    {
        $this->presente = $presente;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getProfesor(): ?Profesor
    {
        return $this->profesor;
    }

    public function setProfesor(?Profesor $profesor): self
    {
        $this->profesor = $profesor;

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
}
