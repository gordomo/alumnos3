<?php

namespace App\Entity;

use App\Repository\SaldoFavorAplicacionRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * @ORM\Entity(repositoryClass=SaldoFavorAplicacionRepository::class)
 * @ORM\Table(name="saldo_favor_aplicacion")
 */
class SaldoFavorAplicacion
{
    /**
     * @ORM\Id
     * @ORM\GeneratedValue
     * @ORM\Column(type="integer")
     */
    private $id;

    /**
     * @ORM\ManyToOne(targetEntity=SaldoFavor::class, inversedBy="aplicaciones")
     * @ORM\JoinColumn(nullable=false)
     */
    private $saldoFavor;

    /**
     * @ORM\ManyToOne(targetEntity=DeudaAlumno::class, inversedBy="creditoAplicaciones")
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

    public function getSaldoFavor(): ?SaldoFavor
    {
        return $this->saldoFavor;
    }

    public function setSaldoFavor(?SaldoFavor $saldoFavor): self
    {
        $this->saldoFavor = $saldoFavor;
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

    public function getMontoAplicado(): ?string
    {
        return $this->montoAplicado;
    }

    public function setMontoAplicado(string $montoAplicado): self
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
