<?php

namespace App\Entity;

use App\Repository\InstitutoConfiguracionRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * @ORM\Entity(repositoryClass=InstitutoConfiguracionRepository::class)
 */
class InstitutoConfiguracion
{
    /**
     * @ORM\Id
     * @ORM\GeneratedValue
     * @ORM\Column(type="integer")
     */
    private $id;

    /**
     * @ORM\OneToOne(targetEntity=Instituto::class, inversedBy="configuracion")
     * @ORM\JoinColumn(nullable=false)
     */
    private $instituto;

    /**
     * @ORM\Column(type="decimal", precision=5, scale=2, nullable=true)
     * @Assert\Range(
     *      min = 0,
     *      max = 100,
     *      notInRangeMessage = "El porcentaje de descuento debe estar entre {{ min }} y {{ max }}"
     * )
     */
    private $descuentoEfectivo;

    /**
     * @ORM\Column(type="decimal", precision=5, scale=2, nullable=true)
     * @Assert\Range(
     *      min = 0,
     *      max = 100,
     *      notInRangeMessage = "El porcentaje de descuento debe estar entre {{ min }} y {{ max }}"
     * )
     */
    private $descuentoHermanos;
    
    /**
     * @ORM\Column(type="boolean")
     */
    private $deshabilitarDescuentosEnDeuda = false;
    
    /**
     * @ORM\OneToMany(targetEntity=Vencimiento::class, mappedBy="configuracion", orphanRemoval=true, cascade={"persist"})
     * @ORM\OrderBy({"orden" = "ASC"})
     */
    private $vencimientos;
    
    public function __construct()
    {
        $this->vencimientos = new ArrayCollection();
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

    public function getDescuentoEfectivo(): ?float
    {
        return $this->descuentoEfectivo;
    }

    public function setDescuentoEfectivo(?float $descuentoEfectivo): self
    {
        $this->descuentoEfectivo = $descuentoEfectivo;
        return $this;
    }

    public function getDescuentoHermanos(): ?float
    {
        return $this->descuentoHermanos;
    }

    public function setDescuentoHermanos(?float $descuentoHermanos): self
    {
        $this->descuentoHermanos = $descuentoHermanos;
        return $this;
    }

    public function getDeshabilitarDescuentosEnDeuda(): bool
    {
        return $this->deshabilitarDescuentosEnDeuda;
    }

    public function setDeshabilitarDescuentosEnDeuda(bool $deshabilitarDescuentosEnDeuda): self
    {
        $this->deshabilitarDescuentosEnDeuda = $deshabilitarDescuentosEnDeuda;
        return $this;
    }
    
    /**
     * @return Collection<int, Vencimiento>
     */
    public function getVencimientos(): Collection
    {
        return $this->vencimientos;
    }

    public function addVencimiento(Vencimiento $vencimiento): self
    {
        if (!$this->vencimientos->contains($vencimiento)) {
            $this->vencimientos[] = $vencimiento;
            $vencimiento->setConfiguracion($this);
        }
        return $this;
    }

    public function removeVencimiento(Vencimiento $vencimiento): self
    {
        if ($this->vencimientos->removeElement($vencimiento)) {
            // set the owning side to null (unless already changed)
            if ($vencimiento->getConfiguracion() === $this) {
                $vencimiento->setConfiguracion(null);
            }
        }
        return $this;
    }
} 