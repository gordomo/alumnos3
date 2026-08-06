<?php

namespace App\Entity;

use App\Repository\AreaEvaluacionRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Eje sobre el que se califica: Writing, Listening, Speaking, Técnica, Ritmo.
 *
 * Es lo que en la libreta ocupa las filas: cada área tiene una nota por período, y esa nota
 * sale del promedio de las evaluaciones de esa área en ese período. O sea que el profesor
 * sigue cargando evaluaciones como siempre y la libreta se deriva; nadie llena un segundo
 * documento.
 *
 * Se define por instituto y no por curso: es la lista de ejes con la que trabaja el
 * instituto entero. Un instituto sin áreas cargadas funciona igual que antes.
 *
 * Sigue el patrón de PeriodoAcademico y ConceptoCalificacion.
 *
 * @ORM\Entity(repositoryClass=AreaEvaluacionRepository::class)
 * @ORM\Table(name="area_evaluacion")
 */
class AreaEvaluacion
{
    /**
     * @ORM\Id
     * @ORM\GeneratedValue
     * @ORM\Column(type="integer")
     */
    private $id;

    /**
     * @ORM\ManyToOne(targetEntity=InstitutoConfiguracion::class, inversedBy="areasEvaluacion")
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
     * @Assert\NotBlank(message="El nombre del área es obligatorio")
     * @Assert\Length(max=100)
     */
    private $nombre;

    /**
     * Nombre corto para la libreta, donde el espacio de la fila es acotado.
     *
     * @ORM\Column(type="string", length=20, nullable=true)
     * @Assert\Length(max=20)
     */
    private $abreviatura;

    /**
     * Icono de Bootstrap Icons para la libreta, opcional (ej: "bi-pencil", "bi-ear").
     *
     * La libreta de referencia usa un ícono por fila, así que se guarda acá en lugar de
     * mapear nombres a íconos con una tabla fija que solo serviría para un instituto.
     *
     * @ORM\Column(type="string", length=40, nullable=true)
     */
    private $icono;

    /**
     * @ORM\Column(type="integer")
     */
    private $orden = 1;

    /**
     * Baja lógica: un área con evaluaciones no se borra, se desactiva.
     *
     * @ORM\Column(type="boolean", options={"default": true})
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
        $this->abreviatura = $abreviatura !== null && trim($abreviatura) !== '' ? trim($abreviatura) : null;
        return $this;
    }

    public function getEtiquetaCorta(): string
    {
        return $this->abreviatura ?: (string) $this->nombre;
    }

    public function getIcono(): ?string
    {
        return $this->icono;
    }

    public function setIcono(?string $icono): self
    {
        $icono = $icono !== null ? trim($icono) : null;
        // Solo se aceptan nombres de Bootstrap Icons: van directo a un atributo class, así
        // que cualquier otra cosa sería inyectar clases arbitrarias en el HTML.
        $this->icono = $icono !== null && preg_match('/^bi-[a-z0-9-]{1,36}$/', $icono) === 1 ? $icono : null;

        return $this;
    }

    public function getOrden(): int
    {
        return (int) $this->orden;
    }

    public function setOrden(int $orden): self
    {
        $this->orden = $orden;
        return $this;
    }

    public function isActivo(): bool
    {
        return (bool) $this->activo;
    }

    public function setActivo(bool $activo): self
    {
        $this->activo = $activo;
        return $this;
    }

    public function __toString(): string
    {
        return (string) $this->nombre;
    }
}
