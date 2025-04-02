<?php

namespace App\Entity;

use App\Repository\InstitutoRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * @ORM\Entity(repositoryClass=InstitutoRepository::class)
 */
class Instituto
{
    /**
     * @ORM\Id
     * @ORM\GeneratedValue
     * @ORM\Column(type="integer")
     */
    private $id;

    /**
     * @ORM\Column(type="string", length=255)
     */
    private $nombre;

    /**
     * @ORM\OneToMany(targetEntity=User::class, mappedBy="instituto")
     */
    private $usuarios;

    /**
     * @ORM\Column(type="string", length=255, nullable=true)
     */
    private $logo;
    
    /**
     * @ORM\Column(type="string", length=255, nullable=true)
     */
    private $email;
    
    /**
     * @ORM\Column(type="string", length=255, nullable=true)
     */
    
     private $dir;
    /**
     * @ORM\Column(type="integer", length=15, nullable=true)
     */
    private $tel;
    
    /**
     * @ORM\OneToMany(targetEntity=Vencimiento::class, mappedBy="instituto", orphanRemoval=true)
     */
    private $vencimientos;

    // Métodos de inicialización y getters/setters

    public function __construct()
    {
        $this->usuarios = new ArrayCollection();
        $this->vencimientos = new ArrayCollection();
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

    public function getUsuarios(): Collection
    {
        return $this->usuarios;
    }

    public function getLogo(): ?string
    {
        return $this->logo;
    }

    public function setLogo(?string $logo): self
    {
        $this->logo = $logo;

        return $this;
    }

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function setEmail(?string $email): self
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

    public function getDir(): ?string
    {
        return $this->dir;
    }

    public function setDir(?string $dir): self
    {
        $this->dir = $dir;

        return $this;
    }

    /**
     * @return Collection<int, Vencimiento>
     */
    public function getVencimientos(): Collection
    {
        return $this->vencimientos ?? new ArrayCollection();
    }

    public function addVencimiento(Vencimiento $vencimiento): self
    {
        if (!$this->vencimientos->contains($vencimiento)) {
            $this->vencimientos[] = $vencimiento;
            $vencimiento->setInstituto($this);
        }
        return $this;
    }

    public function removeVencimiento(Vencimiento $vencimiento): self
    {
        if ($this->vencimientos->removeElement($vencimiento)) {
            // set the owning side to null (unless already changed)
            if ($vencimiento->getInstituto() === $this) {
                $vencimiento->setInstituto(null);
            }
        }
        return $this;
    }
}