<?php

namespace App\Entity;

use App\Repository\PagoAplicacionRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * @ORM\Entity(repositoryClass=PagoAplicacionRepository::class)
 * @ORM\Table(name="pago_aplicacion")
 */
class PagoAplicacion
{
    /**
     * @ORM\Id
     * @ORM\GeneratedValue
     * @ORM\Column(type="integer")
     */
    private $id;

    /**
     * @ORM\ManyToOne(targetEntity=AlumnosPagos::class, inversedBy="aplicaciones")
     * @ORM\JoinColumn(nullable=false)
     */
    private $pago;

    /**
     * @ORM\ManyToOne(targetEntity=DeudaAlumno::class, inversedBy="aplicaciones")
     * @ORM\JoinColumn(nullable=false)
     */
    private $deuda;

    /**
     * @ORM\Column(type="decimal", precision=10, scale=2)
     */
    private $montoAplicado;

    /**
     * @ORM\Column(type="datetime")
     */
    private $fechaAplicacion;

    public function __construct()
    {
        $this->fechaAplicacion = new \DateTime();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getPago(): ?AlumnosPagos
    {
        return $this->pago;
    }

    public function setPago(?AlumnosPagos $pago): self
    {
        $this->pago = $pago;
        return $this;
    }

    public function getDeuda(): ?DeudaAlumno
    {
        return $this->deuda;
    }

    public function setDeuda(?DeudaAlumno $deuda): self
    {
        $this->deuda = $deuda;
        return $this;
    }

    public function getMontoAplicado(): ?float
    {
        return $this->montoAplicado;
    }

    public function setMontoAplicado(float $montoAplicado): self
    {
        $this->montoAplicado = $montoAplicado;
        return $this;
    }

    public function getFechaAplicacion(): ?\DateTimeInterface
    {
        return $this->fechaAplicacion;
    }

    public function setFechaAplicacion(\DateTimeInterface $fechaAplicacion): self
    {
        $this->fechaAplicacion = $fechaAplicacion;
        return $this;
    }
}
