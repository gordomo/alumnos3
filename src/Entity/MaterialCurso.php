<?php

namespace App\Entity;

use App\Repository\MaterialCursoRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Un material de consulta de un curso: un apunte, un video, una planilla.
 *
 * Se guarda el enlace y no el archivo a propósito: casi todos los institutos ya tienen el
 * material en Drive, en YouTube o en un sitio, y subir archivos trae límites de tamaño,
 * almacenamiento, permisos y backups. Con el enlace se resuelve lo mismo sin nada de eso.
 *
 * @ORM\Entity(repositoryClass=MaterialCursoRepository::class)
 * @ORM\Table(name="material_curso")
 */
class MaterialCurso
{
    public const TIPO_APUNTE = 'apunte';
    public const TIPO_VIDEO = 'video';
    public const TIPO_ENLACE = 'enlace';
    public const TIPO_OTRO = 'otro';

    public const TIPOS = [
        self::TIPO_APUNTE => ['etiqueta' => 'Apunte o documento', 'icono' => 'bi-file-earmark-text'],
        self::TIPO_VIDEO => ['etiqueta' => 'Video', 'icono' => 'bi-play-btn'],
        self::TIPO_ENLACE => ['etiqueta' => 'Sitio o enlace', 'icono' => 'bi-link-45deg'],
        self::TIPO_OTRO => ['etiqueta' => 'Otro', 'icono' => 'bi-paperclip'],
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
     * @ORM\ManyToOne(targetEntity=Curso::class)
     * @ORM\JoinColumn(nullable=false, onDelete="CASCADE")
     */
    private $curso;

    /**
     * @ORM\Column(type="string", length=150)
     * @Assert\NotBlank(message="El título es obligatorio")
     */
    private $titulo;

    /**
     * @ORM\Column(type="string", length=500)
     * @Assert\NotBlank(message="El enlace es obligatorio")
     * @Assert\Url(message="El enlace tiene que ser una dirección web válida", protocols={"http", "https"})
     */
    private $url;

    /**
     * @ORM\Column(type="string", length=20, options={"default": "enlace"})
     */
    private $tipo = self::TIPO_ENLACE;

    /**
     * @ORM\Column(type="text", nullable=true)
     */
    private $descripcion;

    /**
     * Un material apagado deja de verse para los alumn@s pero no se pierde.
     *
     * @ORM\Column(type="boolean", options={"default": true})
     */
    private $visible = true;

    /**
     * @ORM\ManyToOne(targetEntity=User::class)
     * @ORM\JoinColumn(nullable=true, onDelete="SET NULL")
     */
    private $cargadoPor;

    /**
     * @ORM\Column(type="datetime")
     */
    private $createdAt;

    public function __construct()
    {
        $this->createdAt = new \DateTime();
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

    public function getCurso(): ?Curso
    {
        return $this->curso;
    }

    public function setCurso(?Curso $curso): self
    {
        $this->curso = $curso;
        return $this;
    }

    public function getTitulo(): ?string
    {
        return $this->titulo;
    }

    public function setTitulo(?string $titulo): self
    {
        $this->titulo = $titulo;
        return $this;
    }

    public function getUrl(): ?string
    {
        return $this->url;
    }

    public function setUrl(?string $url): self
    {
        $this->url = $url;
        return $this;
    }

    /**
     * El enlace, solo si es http o https.
     *
     * Se usa al imprimirlo en un href: sin este filtro, un "javascript:..." guardado a mano
     * quedaría clickeable para los alumn@s. La validación de la entidad ya lo rechaza al
     * guardar, pero esto protege también a lo que haya entrado por otro camino.
     */
    public function getUrlSegura(): ?string
    {
        if (!$this->url) {
            return null;
        }

        $esquema = strtolower((string) parse_url($this->url, PHP_URL_SCHEME));

        return in_array($esquema, ['http', 'https'], true) ? $this->url : null;
    }

    public function getTipo(): string
    {
        return $this->tipo ?: self::TIPO_ENLACE;
    }

    public function setTipo(?string $tipo): self
    {
        $this->tipo = array_key_exists((string) $tipo, self::TIPOS) ? $tipo : self::TIPO_ENLACE;
        return $this;
    }

    public function getTipoEtiqueta(): string
    {
        return self::TIPOS[$this->getTipo()]['etiqueta'] ?? $this->getTipo();
    }

    public function getTipoIcono(): string
    {
        return self::TIPOS[$this->getTipo()]['icono'] ?? 'bi-paperclip';
    }

    public function getDescripcion(): ?string
    {
        return $this->descripcion;
    }

    public function setDescripcion(?string $descripcion): self
    {
        $this->descripcion = $descripcion;
        return $this;
    }

    public function isVisible(): bool
    {
        return (bool) $this->visible;
    }

    public function setVisible(bool $visible): self
    {
        $this->visible = $visible;
        return $this;
    }

    public function getCargadoPor(): ?User
    {
        return $this->cargadoPor;
    }

    public function setCargadoPor(?User $cargadoPor): self
    {
        $this->cargadoPor = $cargadoPor;
        return $this;
    }

    public function getCreatedAt(): ?\DateTimeInterface
    {
        return $this->createdAt;
    }
}
