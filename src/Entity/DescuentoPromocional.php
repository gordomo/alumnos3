<?php

namespace App\Entity;

use App\Repository\DescuentoPromocionalRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * @ORM\Entity(repositoryClass=DescuentoPromocionalRepository::class)
 */
class DescuentoPromocional
{
    /**
     * @ORM\Id
     * @ORM\GeneratedValue
     * @ORM\Column(type="integer")
     */
    private $id;

    /**
     * @ORM\ManyToOne(targetEntity=InstitutoConfiguracion::class, inversedBy="descuentosPromocionales")
     * @ORM\JoinColumn(nullable=false)
     */
    private $configuracion;

    /**
     * @ORM\Column(type="string", length=255)
     * @Assert\NotBlank(message="El nombre del descuento es obligatorio")
     * @Assert\Length(
     *      max=255,
     *      maxMessage="El nombre no puede tener más de {{ limit }} caracteres"
     * )
     */
    private $nombre;

    /**
     * @ORM\Column(type="decimal", precision=5, scale=2)
     * @Assert\NotBlank(message="El porcentaje de descuento es obligatorio")
     * @Assert\Range(
     *      min=0,
     *      max=100,
     *      notInRangeMessage="El porcentaje de descuento debe estar entre {{ min }} y {{ max }}"
     * )
     */
    private $porcentaje;

    /**
     * @ORM\Column(type="boolean")
     */
    private $activo = true;

    public function getId(): ?int
    {
        return $this->id;
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

    public function getNombre(): ?string
    {
        return $this->nombre;
    }

    public function setNombre(string $nombre): self
    {
        $this->nombre = $nombre;
        return $this;
    }

    public function getPorcentaje(): ?float
    {
        return $this->porcentaje;
    }

    public function setPorcentaje(float $porcentaje): self
    {
        $this->porcentaje = $porcentaje;
        return $this;
    }

    public function getActivo(): ?bool
    {
        return $this->activo;
    }

    public function setActivo(bool $activo): self
    {
        $this->activo = $activo;
        return $this;
    }
}

