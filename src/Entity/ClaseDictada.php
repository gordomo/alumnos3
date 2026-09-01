<?php

namespace App\Entity;

use App\Repository\ClaseDictadaRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Lo que se dio en una clase: el "contenido dictado".
 *
 * Es una fila por curso y por fecha, no por alumno. Lo más parecido que había era el campo
 * observaciones de la asistencia, pero ese es por alumno y por fecha, así que servía para "Juan
 * se portó mal" y no para "hoy vimos los acordes de séptima".
 *
 * Lo carga el profesor y lo ven el alumn@ y su tutor: es lo que le permite al que faltó saber qué
 * se perdió.
 *
 * @ORM\Entity(repositoryClass=ClaseDictadaRepository::class)
 * @ORM\Table(name="clase_dictada", uniqueConstraints={
 *     @ORM\UniqueConstraint(name="uniq_clase_dictada_curso_fecha", columns={"curso_id", "fecha"})
 * })
 */
class ClaseDictada
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
     * @ORM\JoinColumn(nullable=false, onDelete="CASCADE")
     */
    private $curso;

    /**
     * @ORM\Column(type="date")
     * @Assert\NotNull(message="La fecha de la clase es obligatoria")
     */
    private $fecha;

    /**
     * @ORM\Column(type="string", length=200)
     * @Assert\NotBlank(message="Poné qué se dio en la clase")
     * @Assert\Length(max=200, maxMessage="El tema no puede tener más de {{ limit }} caracteres")
     */
    private $tema;

    /**
     * @ORM\Column(type="text", nullable=true)
     */
    private $detalle;

    /**
     * @ORM\ManyToOne(targetEntity=User::class)
     * @ORM\JoinColumn(nullable=true, onDelete="SET NULL")
     */
    private $cargadoPor;

    /**
     * @ORM\Column(type="datetime")
     */
    private $createdAt;

    public function __construct()
    {
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

    public function getFecha(): ?\DateTimeInterface
    {
        return $this->fecha;
    }

    public function setFecha(?\DateTimeInterface $fecha): self
    {
        $this->fecha = $fecha;
        return $this;
    }

    public function getTema(): ?string
    {
        return $this->tema;
    }

    public function setTema(?string $tema): self
    {
        $this->tema = $tema;
        return $this;
    }

    public function getDetalle(): ?string
    {
        return $this->detalle;
    }

    public function setDetalle(?string $detalle): self
    {
        $this->detalle = $detalle;
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

    public function getCreatedAt(): ?\DateTimeInterface
    {
        return $this->createdAt;
    }
}
