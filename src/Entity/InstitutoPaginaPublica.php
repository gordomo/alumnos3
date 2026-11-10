<?php

namespace App\Entity;

use App\Repository\InstitutoPaginaPublicaRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/** @ORM\Entity(repositoryClass=InstitutoPaginaPublicaRepository::class) */
class InstitutoPaginaPublica
{
    /** @ORM\Id @ORM\GeneratedValue @ORM\Column(type="integer") */
    private $id;
    /** @ORM\OneToOne(targetEntity=Instituto::class) @ORM\JoinColumn(nullable=false, onDelete="CASCADE") */
    private $instituto;
    /** @ORM\Column(type="string", length=90, unique=true) */
    private $slug;
    /** @ORM\Column(type="boolean", options={"default": false}) */
    private $publicada = false;
    /** @ORM\Column(type="boolean", options={"default": true}) */
    private $mostrarPrecios = true;
    /** @ORM\Column(type="string", length=7) @Assert\Regex(pattern="/^#[0-9a-fA-F]{6}$/", message="Elegí un color hexadecimal válido.") */
    private $colorPrincipal = '#1b4f9c';
    /** @ORM\Column(type="string", length=7) @Assert\Regex(pattern="/^#[0-9a-fA-F]{6}$/", message="Elegí un color hexadecimal válido.") */
    private $colorAcento = '#0f766e';
    /** @ORM\Column(type="text", nullable=true) @Assert\Length(max=600, maxMessage="La presentación admite hasta 600 caracteres.") */
    private $presentacion;

    public function getId(): ?int { return $this->id; }
    public function getInstituto(): ?Instituto { return $this->instituto; }
    public function setInstituto(Instituto $instituto): self { $this->instituto = $instituto; return $this; }
    public function getSlug(): ?string { return $this->slug; }
    public function setSlug(string $slug): self { $this->slug = $slug; return $this; }
    public function isPublicada(): bool { return (bool) $this->publicada; }
    public function setPublicada(bool $publicada): self { $this->publicada = $publicada; return $this; }
    public function isMostrarPrecios(): bool { return (bool) $this->mostrarPrecios; }
    public function setMostrarPrecios(bool $mostrar): self { $this->mostrarPrecios = $mostrar; return $this; }
    public function getColorPrincipal(): string { return $this->colorPrincipal; }
    public function setColorPrincipal(string $color): self { $this->colorPrincipal = $color; return $this; }
    public function getColorAcento(): string { return $this->colorAcento; }
    public function setColorAcento(string $color): self { $this->colorAcento = $color; return $this; }
    public function getPresentacion(): ?string { return $this->presentacion; }
    public function setPresentacion(?string $texto): self { $this->presentacion = $texto === null ? null : trim($texto); return $this; }
}
