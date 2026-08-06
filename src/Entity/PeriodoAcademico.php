<?php

namespace App\Entity;

use App\Repository\PeriodoAcademicoRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Período académico del instituto: un trimestre, un bimestre, una mesa de examen.
 *
 * Se define por rango de meses y no por fechas con año, así se configura una sola vez y
 * sirve todos los años. Un instituto sin períodos cargados funciona igual que antes: las
 * evaluaciones no pertenecen a ninguno y el boletín queda de una sola columna.
 *
 * Sigue el patrón de ConceptoCalificacion y Vencimiento: cuelga de la configuración y
 * además guarda el instituto, para poder filtrar sin pasar por la configuración.
 *
 * @ORM\Entity(repositoryClass=PeriodoAcademicoRepository::class)
 * @ORM\Table(name="periodo_academico")
 */
class PeriodoAcademico
{
    /**
     * Período de cursada normal: un trimestre, un bimestre.
     */
    public const TIPO_CURSADA = 'cursada';

    /**
     * Instancia de examen: mesa de mitad de año, final. Se lista aparte de los de cursada
     * porque en el boletín ocupan su propia columna.
     */
    public const TIPO_EXAMEN = 'examen';

    /**
     * @ORM\Id
     * @ORM\GeneratedValue
     * @ORM\Column(type="integer")
     */
    private $id;

    /**
     * @ORM\ManyToOne(targetEntity=InstitutoConfiguracion::class, inversedBy="periodosAcademicos")
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
     * @Assert\NotBlank(message="El nombre del período es obligatorio")
     * @Assert\Length(max=100)
     */
    private $nombre;

    /**
     * Nombre corto para los encabezados de la libreta, donde no entra el nombre completo.
     *
     * @ORM\Column(type="string", length=20, nullable=true)
     * @Assert\Length(max=20)
     */
    private $abreviatura;

    /**
     * @ORM\Column(type="string", length=20, options={"default": "cursada"})
     */
    private $tipo = self::TIPO_CURSADA;

    /**
     * Primer mes del período, 1 a 12.
     *
     * @ORM\Column(type="integer")
     * @Assert\Range(min=1, max=12, notInRangeMessage="El mes debe estar entre {{ min }} y {{ max }}")
     */
    private $mesInicio = 1;

    /**
     * Último mes del período, 1 a 12, inclusive.
     *
     * Si es menor que mesInicio, el período cruza el fin de año (por ejemplo noviembre a
     * febrero), y contiene() lo resuelve.
     *
     * @ORM\Column(type="integer")
     * @Assert\Range(min=1, max=12, notInRangeMessage="El mes debe estar entre {{ min }} y {{ max }}")
     */
    private $mesFin = 12;

    /**
     * @ORM\Column(type="integer")
     */
    private $orden = 1;

    /**
     * Baja lógica: un período que ya tiene evaluaciones no se borra, se desactiva.
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

    /**
     * La abreviatura si tiene, y si no el nombre completo.
     */
    public function getEtiquetaCorta(): string
    {
        return $this->abreviatura ?: (string) $this->nombre;
    }

    public function getTipo(): string
    {
        return $this->tipo ?: self::TIPO_CURSADA;
    }

    public function setTipo(?string $tipo): self
    {
        $this->tipo = in_array($tipo, [self::TIPO_CURSADA, self::TIPO_EXAMEN], true)
            ? $tipo
            : self::TIPO_CURSADA;

        return $this;
    }

    public function esExamen(): bool
    {
        return $this->getTipo() === self::TIPO_EXAMEN;
    }

    public function getMesInicio(): int
    {
        return (int) $this->mesInicio;
    }

    public function setMesInicio(int $mesInicio): self
    {
        $this->mesInicio = max(1, min(12, $mesInicio));
        return $this;
    }

    public function getMesFin(): int
    {
        return (int) $this->mesFin;
    }

    public function setMesFin(int $mesFin): self
    {
        $this->mesFin = max(1, min(12, $mesFin));
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

    /**
     * Si el mes dado cae dentro del período.
     *
     * Resuelve el caso del período que cruza el fin de año: con mesInicio 11 y mesFin 2,
     * noviembre, diciembre, enero y febrero están adentro.
     */
    public function contieneMes(int $mes): bool
    {
        $inicio = $this->getMesInicio();
        $fin = $this->getMesFin();

        if ($inicio <= $fin) {
            return $mes >= $inicio && $mes <= $fin;
        }

        return $mes >= $inicio || $mes <= $fin;
    }

    /**
     * Rango en texto para mostrar en la configuración: "Marzo a Mayo".
     */
    public function getRangoTexto(): string
    {
        $meses = [
            1 => 'Enero', 2 => 'Febrero', 3 => 'Marzo', 4 => 'Abril',
            5 => 'Mayo', 6 => 'Junio', 7 => 'Julio', 8 => 'Agosto',
            9 => 'Septiembre', 10 => 'Octubre', 11 => 'Noviembre', 12 => 'Diciembre',
        ];

        $inicio = $meses[$this->getMesInicio()] ?? $this->getMesInicio();
        $fin = $meses[$this->getMesFin()] ?? $this->getMesFin();

        return $inicio === $fin ? (string) $inicio : $inicio . ' a ' . $fin;
    }

    public function __toString(): string
    {
        return (string) $this->nombre;
    }
}
