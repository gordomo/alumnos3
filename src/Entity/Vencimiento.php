<?php

namespace App\Entity;

use App\Repository\VencimientoRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * @ORM\Entity(repositoryClass=VencimientoRepository::class)
 */
class Vencimiento
{
    /**
     * @ORM\Id
     * @ORM\GeneratedValue
     * @ORM\Column(type="integer")
     */
    private $id;

    /**
     * @ORM\Column(type="integer")
     * @Assert\Range(
     *      min = 1,
     *      max = 31,
     *      notInRangeMessage = "El día de vencimiento debe estar entre {{ min }} y {{ max }}"
     * )
     */
    private $diaVencimiento;

    /**
     * @ORM\Column(type="float")
     * @Assert\Range(
     *      min = 0,
     *      max = 100,
     *      notInRangeMessage = "El porcentaje de interés debe estar entre {{ min }} y {{ max }}"
     * )
     */
    private $porcentajeInteres;

    /**
     * @ORM\Column(type="integer")
     * @Assert\Positive(message="El orden debe ser un número positivo")
     */
    private $orden;

    /**
     * @ORM\ManyToOne(targetEntity=Instituto::class, inversedBy="vencimientos")
     * @ORM\JoinColumn(nullable=false)
     */
    private $instituto;

    /**
     * @ORM\ManyToOne(targetEntity=InstitutoConfiguracion::class, inversedBy="vencimientos")
     */
    private $configuracion;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getDiaVencimiento(): ?int
    {
        return $this->diaVencimiento;
    }

    public function setDiaVencimiento(int $diaVencimiento): self
    {
        $this->diaVencimiento = $diaVencimiento;
        return $this;
    }

    public function getPorcentajeInteres(): ?float
    {
        return $this->porcentajeInteres;
    }

    public function setPorcentajeInteres(float $porcentajeInteres): self
    {
        $this->porcentajeInteres = $porcentajeInteres;
        return $this;
    }

    public function getOrden(): ?int
    {
        return $this->orden;
    }

    public function setOrden(int $orden): self
    {
        $this->orden = $orden;
        return $this;
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

    public function getConfiguracion(): ?InstitutoConfiguracion
    {
        return $this->configuracion;
    }

    public function setConfiguracion(?InstitutoConfiguracion $configuracion): self
    {
        $this->configuracion = $configuracion;
        return $this;
    }
} 