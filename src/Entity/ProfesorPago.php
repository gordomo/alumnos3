<?php

namespace App\Entity;

use App\Repository\ProfesorPagoRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * @ORM\Entity(repositoryClass=ProfesorPagoRepository::class)
 */
class ProfesorPago
{
    /**
     * @ORM\Id
     * @ORM\GeneratedValue
     * @ORM\Column(type="integer")
     */
    private $id;

    /**
     * @ORM\ManyToOne(targetEntity=Profesor::class)
     * @ORM\JoinColumn(nullable=false)
     */
    private $profesor;

    /**
     * @ORM\ManyToOne(targetEntity=Curso::class)
     * @ORM\JoinColumn(nullable=true)
     */
    private $curso;

    /**
     * @ORM\Column(type="integer")
     * @Assert\NotBlank
     * @Assert\Range(min=1, max=12)
     */
    private $mes;

    /**
     * @ORM\Column(type="integer")
     * @Assert\NotBlank
     */
    private $ano;

    /**
     * @ORM\Column(type="decimal", precision=10, scale=2)
     * @Assert\NotBlank
     * @Assert\Positive
     */
    private $monto;

    /**
     * @ORM\Column(type="date")
     * @Assert\NotBlank
     */
    private $fechaPago;

    /**
     * @ORM\Column(type="string", length=50)
     * @Assert\NotBlank
     */
    private $metodoPago;

    /**
     * @ORM\Column(type="text", nullable=true)
     */
    private $observacion;

    /**
     * Detalle del cálculo (JSON con información de cómo se calculó el monto)
     * @ORM\Column(type="text", nullable=true)
     */
    private $detalleCalculo;

    /**
     * @ORM\Column(type="datetime")
     */
    private $fechaCreacion;

    public function __construct()
    {
        $this->fechaCreacion = new \DateTime();
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

    public function getCurso(): ?Curso
    {
        return $this->curso;
    }

    public function setCurso(?Curso $curso): self
    {
        $this->curso = $curso;
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
        return $this->monto ? (float)$this->monto : null;
    }

    public function setMonto(float $monto): self
    {
        $this->monto = $monto;
        return $this;
    }

    public function getFechaPago(): ?\DateTimeInterface
    {
        return $this->fechaPago;
    }

    public function setFechaPago(\DateTimeInterface $fechaPago): self
    {
        $this->fechaPago = $fechaPago;
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

    public function getObservacion(): ?string
    {
        return $this->observacion;
    }

    public function setObservacion(?string $observacion): self
    {
        $this->observacion = $observacion;
        return $this;
    }

    public function getDetalleCalculo(): ?string
    {
        return $this->detalleCalculo;
    }

    public function setDetalleCalculo(?string $detalleCalculo): self
    {
        $this->detalleCalculo = $detalleCalculo;
        return $this;
    }

    public function getFechaCreacion(): ?\DateTimeInterface
    {
        return $this->fechaCreacion;
    }

    public function setFechaCreacion(\DateTimeInterface $fechaCreacion): self
    {
        $this->fechaCreacion = $fechaCreacion;
        return $this;
    }
}
