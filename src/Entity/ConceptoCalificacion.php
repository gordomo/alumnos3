<?php

namespace App\Entity;

use App\Repository\ConceptoCalificacionRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Un valor de la escala conceptual de calificación de un instituto.
 *
 * Por ejemplo: Excelente, Muy bueno, Bueno, Regular, Insuficiente. Cada instituto define
 * su propia lista ordenada e indica cuáles aprueban. Sigue el mismo patrón que
 * Vencimiento: cuelga de la configuración del instituto y además tiene instituto propio.
 *
 * @ORM\Entity(repositoryClass=ConceptoCalificacionRepository::class)
 * @ORM\Table(name="concepto_calificacion")
 */
class ConceptoCalificacion
{
    /**
     * @ORM\Id
     * @ORM\GeneratedValue
     * @ORM\Column(type="integer")
     */
    private $id;

    /**
     * @ORM\ManyToOne(targetEntity=InstitutoConfiguracion::class, inversedBy="conceptosCalificacion")
     * @ORM\JoinColumn(nullable=false)
     */
    private $configuracion;

    /**
     * @ORM\ManyToOne(targetEntity=Instituto::class)
     * @ORM\JoinColumn(nullable=false)
     */
    private $instituto;

    /**
     * @ORM\Column(type="string", length=100)
     * @Assert\NotBlank(message="El nombre del concepto no puede estar vacío")
     * @Assert\Length(max=100, maxMessage="El nombre no puede superar los {{ limit }} caracteres")
     */
    private $nombre;

    /**
     * @ORM\Column(type="string", length=10, nullable=true)
     */
    private $abreviatura;

    /**
     * Posición en la escala. Menor orden = mejor calificación.
     *
     * @ORM\Column(type="integer")
     */
    private $orden = 1;

    /**
     * @ORM\Column(type="boolean")
     */
    private $aprueba = true;

    /**
     * Equivalencia numérica opcional, para poder promediar una escala conceptual.
     *
     * @ORM\Column(type="decimal", precision=6, scale=2, nullable=true)
     */
    private $equivalenteNumerico;

    /**
     * Se desactiva en lugar de borrarse: puede haber calificaciones apuntando acá.
     *
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

    public function getInstituto(): ?Instituto
    {
        return $this->instituto;
    }

    public function setInstituto(?Instituto $instituto): self
    {
        $this->instituto = $instituto;
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

    public function getAbreviatura(): ?string
    {
        return $this->abreviatura;
    }

    public function setAbreviatura(?string $abreviatura): self
    {
        $this->abreviatura = $abreviatura;
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

    public function isAprueba(): bool
    {
        return (bool) $this->aprueba;
    }

    public function getAprueba(): bool
    {
        return (bool) $this->aprueba;
    }

    public function setAprueba(bool $aprueba): self
    {
        $this->aprueba = $aprueba;
        return $this;
    }

    /**
     * Doctrine devuelve decimal como string, así que se castea acá.
     */
    public function getEquivalenteNumerico(): ?float
    {
        return $this->equivalenteNumerico === null ? null : (float) $this->equivalenteNumerico;
    }

    public function setEquivalenteNumerico(?float $equivalenteNumerico): self
    {
        $this->equivalenteNumerico = $equivalenteNumerico;
        return $this;
    }

    public function isActivo(): bool
    {
        return (bool) $this->activo;
    }

    public function getActivo(): bool
    {
        return (bool) $this->activo;
    }

    public function setActivo(bool $activo): self
    {
        $this->activo = $activo;
        return $this;
    }

    /**
     * Etiqueta para mostrar: usa la abreviatura si existe.
     */
    public function getEtiqueta(): string
    {
        return $this->abreviatura ?: (string) $this->nombre;
    }

    public function __toString(): string
    {
        return (string) $this->nombre;
    }
}
