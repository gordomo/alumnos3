<?php

namespace App\Entity;

use App\Repository\ProfesorCursoPagoRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Cómo se le paga a un profesor en un curso puntual.
 *
 * Existe porque la modalidad no es la misma en todos los cursos: el mismo profesor puede
 * cobrar por hora en uno y un porcentaje de la recaudación en otro. La configuración que está
 * en Profesor sigue siendo la que se aplica cuando el curso no tiene regla propia, así que un
 * instituto que no use esto no cambia en nada.
 *
 * @ORM\Entity(repositoryClass=ProfesorCursoPagoRepository::class)
 * @ORM\Table(name="profesor_curso_pago", uniqueConstraints={
 *     @ORM\UniqueConstraint(name="uniq_profesor_curso_pago", columns={"profesor_id", "curso_id"})
 * })
 */
class ProfesorCursoPago
{
    public const MODALIDAD_POR_HORA = 'por_hora';
    public const MODALIDAD_PORCENTAJE = 'porcentaje';
    public const MODALIDAD_FIJO_MENSUAL = 'fijo_mensual';

    public const MODALIDADES = [
        self::MODALIDAD_POR_HORA => 'Por hora',
        self::MODALIDAD_PORCENTAJE => 'Porcentaje del curso',
        self::MODALIDAD_FIJO_MENSUAL => 'Fijo mensual por el curso',
    ];

    /**
     * @ORM\Id
     * @ORM\GeneratedValue
     * @ORM\Column(type="integer")
     */
    private $id;

    /**
     * @ORM\ManyToOne(targetEntity=Instituto::class)
     * @ORM\JoinColumn(nullable=false)
     */
    private $instituto;

    /**
     * @ORM\ManyToOne(targetEntity=Profesor::class)
     * @ORM\JoinColumn(nullable=false, onDelete="CASCADE")
     */
    private $profesor;

    /**
     * @ORM\ManyToOne(targetEntity=Curso::class)
     * @ORM\JoinColumn(nullable=false, onDelete="CASCADE")
     */
    private $curso;

    /**
     * @ORM\Column(type="string", length=30)
     * @Assert\Choice(choices={"por_hora", "porcentaje", "fijo_mensual"}, message="Modalidad de pago no válida")
     */
    private $modalidad = self::MODALIDAD_POR_HORA;

    /**
     * Valor hora de este curso. Si queda en null se usa el del profesor.
     *
     * @ORM\Column(type="decimal", precision=10, scale=2, nullable=true)
     * @Assert\PositiveOrZero(message="El valor hora no puede ser negativo")
     */
    private $precioHora;

    /**
     * Viático por clase dictada en este curso. Null usa el del profesor.
     *
     * @ORM\Column(type="decimal", precision=10, scale=2, nullable=true)
     * @Assert\PositiveOrZero(message="El viático no puede ser negativo")
     */
    private $viatico;

    /**
     * @ORM\Column(type="decimal", precision=5, scale=2, nullable=true)
     * @Assert\Range(min=0, max=100, notInRangeMessage="El porcentaje debe estar entre {{ min }} y {{ max }}")
     */
    private $porcentaje;

    /**
     * @ORM\Column(type="decimal", precision=10, scale=2, nullable=true)
     * @Assert\PositiveOrZero(message="El monto fijo no puede ser negativo")
     */
    private $montoFijo;

    /**
     * Una regla apagada no se borra: queda el registro de lo que se acordó y el cálculo vuelve
     * a la configuración del profesor.
     *
     * @ORM\Column(type="boolean", options={"default": true})
     */
    private $activo = true;

    /**
     * @ORM\Column(type="datetime")
     */
    private $updatedAt;

    public function __construct()
    {
        $this->updatedAt = new \DateTime();
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

    public function getModalidad(): string
    {
        return $this->modalidad;
    }

    public function setModalidad(string $modalidad): self
    {
        $this->modalidad = $modalidad;
        return $this;
    }

    public function getModalidadEtiqueta(): string
    {
        return self::MODALIDADES[$this->modalidad] ?? $this->modalidad;
    }

    public function getPrecioHora(): ?float
    {
        return $this->precioHora === null ? null : (float) $this->precioHora;
    }

    public function setPrecioHora($precioHora): self
    {
        $this->precioHora = $precioHora;
        return $this;
    }

    public function getViatico(): ?float
    {
        return $this->viatico === null ? null : (float) $this->viatico;
    }

    public function setViatico($viatico): self
    {
        $this->viatico = $viatico;
        return $this;
    }

    public function getPorcentaje(): ?float
    {
        return $this->porcentaje === null ? null : (float) $this->porcentaje;
    }

    public function setPorcentaje($porcentaje): self
    {
        $this->porcentaje = $porcentaje;
        return $this;
    }

    public function getMontoFijo(): ?float
    {
        return $this->montoFijo === null ? null : (float) $this->montoFijo;
    }

    public function setMontoFijo($montoFijo): self
    {
        $this->montoFijo = $montoFijo;
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

    public function getUpdatedAt(): ?\DateTimeInterface
    {
        return $this->updatedAt;
    }

    public function setUpdatedAt(?\DateTimeInterface $updatedAt): self
    {
        $this->updatedAt = $updatedAt;
        return $this;
    }

    /**
     * Si la regla tiene cargado lo que su modalidad necesita para poder calcular.
     *
     * Una regla en porcentaje sin porcentaje, o en fijo sin monto, no sirve: el cálculo la
     * ignora y usa la configuración del profesor, y la pantalla lo avisa.
     */
    public function estaCompleta(): bool
    {
        switch ($this->modalidad) {
            case self::MODALIDAD_PORCENTAJE:
                return $this->porcentaje !== null;
            case self::MODALIDAD_FIJO_MENSUAL:
                return $this->montoFijo !== null;
            case self::MODALIDAD_POR_HORA:
                // El valor hora puede venir del profesor, así que la regla puede estar vacía.
                return true;
        }

        return false;
    }
}
