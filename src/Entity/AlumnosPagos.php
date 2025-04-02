<?php

namespace App\Entity;

use App\Repository\AlumnosPagosRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * @ORM\Entity(repositoryClass=AlumnosPagosRepository::class)
 */
class AlumnosPagos
{
    /**
     * @ORM\Id
     * @ORM\GeneratedValue
     * @ORM\Column(type="integer")
     */
    private $id;

    /**
     * @ORM\ManyToOne(targetEntity=Alumno::class, inversedBy="pagos")
     * @ORM\JoinColumn(nullable=false)
     */
    private $alumno;

    /**
     * @ORM\Column(type="datetime")
     */
    private $fecha;

    /**
     * @ORM\Column(type="integer")
     * @Assert\Range(
     *      min = 1,
     *      max = 12,
     *      notInRangeMessage = "El mes debe estar entre {{ min }} y {{ max }}"
     * )
     */
    private $mes;

    /**
     * @ORM\Column(type="integer")
     */
    private $ano;

    /**
     * @ORM\Column(type="decimal", precision=10, scale=2)
     * @Assert\Positive(message="El monto debe ser mayor a 0")
     */
    private $monto;

    /**
     * @ORM\Column(type="text", nullable=true)
     */
    private $observacion;

    /**
     * @ORM\ManyToOne(targetEntity=Curso::class, inversedBy="alumnosPagos")
     */
    private $curso;

    /**
     * @ORM\Column(type="string", length=20)
     */
    private $metodoPago;

    /**
     * @ORM\ManyToOne(targetEntity=AlumnoCursoHistorico::class, inversedBy="pagos")
     * @ORM\JoinColumn(nullable=false)
     */
    private $cursoHistorico;

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

    public function getFecha(): ?\DateTimeInterface
    {
        return $this->fecha;
    }

    public function setFecha(\DateTimeInterface $fecha): self
    {
        $this->fecha = $fecha;
        return $this;
    }

    public function getMes(): ?int
    {
        return $this->mes;
    }

    public function setMes(int $mes): self
    {
        $this->mes = $mes;
        return $this;
    }

    public function getAno(): ?int
    {
        return $this->ano;
    }

    public function setAno(int $ano): self
    {
        $this->ano = $ano;
        return $this;
    }

    public function getMonto(): ?float
    {
        return $this->monto;
    }

    public function setMonto(float $monto): self
    {
        $this->monto = $monto;
        return $this;
    }

    public function getObservacion(): ?string
    {
        return $this->observacion;
    }

    public function setObservacion(?string $observacion): self
    {
        $this->observacion = $observacion;
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

    public function getMetodoPago(): ?string
    {
        return $this->metodoPago;
    }

    public function setMetodoPago(string $metodoPago): self
    {
        $this->metodoPago = $metodoPago;
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
}
