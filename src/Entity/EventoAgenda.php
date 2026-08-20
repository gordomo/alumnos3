<?php

namespace App\Entity;

use App\Repository\EventoAgendaRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Un evento que el instituto carga a mano en la agenda.
 *
 * Todo lo demás que se ve en la agenda es información derivada: las clases salen de los
 * horarios del curso, los exámenes de las evaluaciones, las entregas de las tareas y los
 * vencimientos de la configuración. Esto es lo único que se escribe: el feriado, el acto de fin
 * de año, la reunión de padres, la mesa de examen.
 *
 * @ORM\Entity(repositoryClass=EventoAgendaRepository::class)
 * @ORM\Table(name="evento_agenda", indexes={
 *     @ORM\Index(name="idx_evento_agenda_rango", columns={"instituto_id", "fecha_inicio"})
 * })
 */
class EventoAgenda
{
    public const TIPO_FERIADO = 'feriado';
    public const TIPO_ACTO = 'acto';
    public const TIPO_REUNION = 'reunion';
    public const TIPO_MESA_EXAMEN = 'mesa_examen';
    public const TIPO_OTRO = 'otro';

    /**
     * Etiqueta, ícono y color de cada tipo. El color se usa tal cual en el calendario, así que
     * la lista es cerrada a propósito: no se interpola nada que venga del usuario.
     */
    public const TIPOS = [
        self::TIPO_FERIADO => ['etiqueta' => 'Feriado o receso', 'icono' => 'bi-calendar-x', 'color' => '#6c757d'],
        self::TIPO_ACTO => ['etiqueta' => 'Acto o evento', 'icono' => 'bi-balloon', 'color' => '#20c997'],
        self::TIPO_REUNION => ['etiqueta' => 'Reunión', 'icono' => 'bi-people', 'color' => '#0dcaf0'],
        self::TIPO_MESA_EXAMEN => ['etiqueta' => 'Mesa de examen', 'icono' => 'bi-journal-check', 'color' => '#6f42c1'],
        self::TIPO_OTRO => ['etiqueta' => 'Otro', 'icono' => 'bi-pin-angle', 'color' => '#adb5bd'],
    ];

    public const VISIBILIDAD_TODOS = 'todos';
    public const VISIBILIDAD_PROFESORES = 'profesores';
    public const VISIBILIDAD_ALUMNOS = 'alumnos';
    public const VISIBILIDAD_SOLO_ADMIN = 'solo_admin';

    public const VISIBILIDADES = [
        self::VISIBILIDAD_TODOS => 'Todos (profesores y alumn@s)',
        self::VISIBILIDAD_PROFESORES => 'Solo profesores',
        self::VISIBILIDAD_ALUMNOS => 'Solo alumn@s',
        self::VISIBILIDAD_SOLO_ADMIN => 'Solo administración',
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
     * Un evento de un curso puntual, como la mesa de examen de esa materia. Null es del
     * instituto entero, que es el caso del feriado.
     *
     * @ORM\ManyToOne(targetEntity=Curso::class)
     * @ORM\JoinColumn(nullable=true, onDelete="SET NULL")
     */
    private $curso;

    /**
     * @ORM\Column(type="string", length=150)
     * @Assert\NotBlank(message="El título es obligatorio")
     * @Assert\Length(max=150, maxMessage="El título no puede tener más de {{ limit }} caracteres")
     */
    private $titulo;

    /**
     * @ORM\Column(type="text", nullable=true)
     */
    private $descripcion;

    /**
     * @ORM\Column(type="string", length=30)
     * @Assert\Choice(choices={"feriado", "acto", "reunion", "mesa_examen", "otro"}, message="Tipo de evento no válido")
     */
    private $tipo = self::TIPO_OTRO;

    /**
     * @ORM\Column(type="datetime")
     * @Assert\NotNull(message="La fecha es obligatoria")
     */
    private $fechaInicio;

    /**
     * Null es un evento de un solo día. Con fecha de fin cubre un rango, como el receso.
     *
     * @ORM\Column(type="datetime", nullable=true)
     */
    private $fechaFin;

    /**
     * @ORM\Column(type="boolean", options={"default": true})
     */
    private $todoElDia = true;

    /**
     * @ORM\Column(type="string", length=20, options={"default": "todos"})
     */
    private $visibilidad = self::VISIBILIDAD_TODOS;

    /**
     * @ORM\ManyToOne(targetEntity=User::class)
     * @ORM\JoinColumn(nullable=true, onDelete="SET NULL")
     */
    private $creadoPor;

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

    public function getDescripcion(): ?string
    {
        return $this->descripcion;
    }

    public function setDescripcion(?string $descripcion): self
    {
        $this->descripcion = $descripcion;
        return $this;
    }

    public function getTipo(): string
    {
        return $this->tipo;
    }

    public function setTipo(string $tipo): self
    {
        $this->tipo = array_key_exists($tipo, self::TIPOS) ? $tipo : self::TIPO_OTRO;
        return $this;
    }

    public function getTipoEtiqueta(): string
    {
        return self::TIPOS[$this->tipo]['etiqueta'] ?? $this->tipo;
    }

    public function getTipoIcono(): string
    {
        return self::TIPOS[$this->tipo]['icono'] ?? 'bi-pin-angle';
    }

    public function getTipoColor(): string
    {
        return self::TIPOS[$this->tipo]['color'] ?? '#adb5bd';
    }

    public function esFeriado(): bool
    {
        return $this->tipo === self::TIPO_FERIADO;
    }

    public function getFechaInicio(): ?\DateTimeInterface
    {
        return $this->fechaInicio;
    }

    public function setFechaInicio(?\DateTimeInterface $fechaInicio): self
    {
        $this->fechaInicio = $fechaInicio;
        return $this;
    }

    public function getFechaFin(): ?\DateTimeInterface
    {
        return $this->fechaFin;
    }

    public function setFechaFin(?\DateTimeInterface $fechaFin): self
    {
        $this->fechaFin = $fechaFin;
        return $this;
    }

    /**
     * Hasta cuándo dura, para comparar rangos sin repetir el null en cada llamador.
     */
    public function getFechaFinEfectiva(): ?\DateTimeInterface
    {
        return $this->fechaFin ?: $this->fechaInicio;
    }

    public function isTodoElDia(): bool
    {
        return (bool) $this->todoElDia;
    }

    public function setTodoElDia(bool $todoElDia): self
    {
        $this->todoElDia = $todoElDia;
        return $this;
    }

    public function getVisibilidad(): string
    {
        return $this->visibilidad ?: self::VISIBILIDAD_TODOS;
    }

    public function setVisibilidad(?string $visibilidad): self
    {
        $this->visibilidad = array_key_exists((string) $visibilidad, self::VISIBILIDADES)
            ? $visibilidad
            : self::VISIBILIDAD_TODOS;

        return $this;
    }

    public function getVisibilidadEtiqueta(): string
    {
        return self::VISIBILIDADES[$this->getVisibilidad()] ?? $this->getVisibilidad();
    }

    /**
     * Si este evento se le muestra a un profesor.
     */
    public function visibleParaProfesor(): bool
    {
        return in_array($this->getVisibilidad(), [self::VISIBILIDAD_TODOS, self::VISIBILIDAD_PROFESORES], true);
    }

    /**
     * Si este evento se le muestra a un alumno.
     */
    public function visibleParaAlumno(): bool
    {
        return in_array($this->getVisibilidad(), [self::VISIBILIDAD_TODOS, self::VISIBILIDAD_ALUMNOS], true);
    }

    public function getCreadoPor(): ?User
    {
        return $this->creadoPor;
    }

    public function setCreadoPor(?User $creadoPor): self
    {
        $this->creadoPor = $creadoPor;
        return $this;
    }

    public function getCreatedAt(): ?\DateTimeInterface
    {
        return $this->createdAt;
    }

    public function __toString(): string
    {
        return (string) $this->titulo;
    }
}
