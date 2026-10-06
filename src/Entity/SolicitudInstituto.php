<?php

namespace App\Entity;

use App\Repository\SolicitudInstitutoRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Un instituto que pidió usar el sistema y todavía no es un instituto.
 *
 * Vive aparte de Instituto a propósito: un candidato no es un inquilino. Si se creara el
 * Instituto al recibir el formulario, la facturación, Cobranzas y los conteos de alumnos
 * quedarían contaminados con gente que nunca entró al sistema.
 *
 * El circuito es: alguien completa el formulario público → queda PENDIENTE → el super admin la
 * confirma y recién ahí se crea el Instituto y se manda la invitación, o la descarta.
 *
 * @ORM\Entity(repositoryClass=SolicitudInstitutoRepository::class)
 * @ORM\Table(name="solicitud_instituto")
 */
class SolicitudInstituto
{
    public const PENDIENTE = 'pendiente';
    public const CONFIRMADA = 'confirmada';
    public const DESCARTADA = 'descartada';

    /** Rangos en vez de un número exacto: nadie sabe cuántos alumnos tiene, y un desplegable
     *  se completa más rápido que un input. */
    public const RANGOS = [
        '1-20' => 'Hasta 20 alumn@s',
        '21-50' => 'Entre 21 y 50',
        '51-100' => 'Entre 51 y 100',
        '100+' => 'Más de 100',
    ];

    /**
     * @ORM\Id
     * @ORM\GeneratedValue
     * @ORM\Column(type="integer")
     */
    private ?int $id = null;

    /**
     * @ORM\Column(type="string", length=255)
     * @Assert\NotBlank(message="Decinos el nombre del instituto.")
     * @Assert\Length(max=255)
     */
    private string $nombreInstituto = '';

    /**
     * @ORM\Column(type="string", length=255)
     * @Assert\NotBlank(message="Decinos con quién hablamos.")
     * @Assert\Length(max=255)
     */
    private string $nombreContacto = '';

    /**
     * @ORM\Column(type="string", length=180)
     * @Assert\NotBlank(message="Necesitamos un email para contestarte.")
     * @Assert\Email(message="Ese email no parece válido.")
     */
    private string $email = '';

    /**
     * @ORM\Column(type="string", length=50, nullable=true)
     */
    private ?string $telefono = null;

    /**
     * @ORM\Column(type="string", length=20, nullable=true)
     */
    private ?string $alumnosEstimados = null;

    /**
     * @ORM\Column(type="text", nullable=true)
     */
    private ?string $mensaje = null;

    /**
     * @ORM\Column(type="string", length=20)
     */
    private string $estado = self::PENDIENTE;

    /**
     * Por qué se descartó. Se guarda para no perder el historial de lo que se decidió.
     *
     * @ORM\Column(type="text", nullable=true)
     */
    private ?string $motivoDescarte = null;

    /**
     * Notas internas: nunca se le muestran al candidato.
     *
     * @ORM\Column(type="text", nullable=true)
     */
    private ?string $notas = null;

    /**
     * @ORM\Column(type="datetime")
     */
    private \DateTimeInterface $fechaSolicitud;

    /**
     * @ORM\Column(type="datetime", nullable=true)
     */
    private ?\DateTimeInterface $fechaResolucion = null;

    /**
     * El instituto que se creó al confirmarla, si se confirmó.
     *
     * @ORM\ManyToOne(targetEntity=Instituto::class)
     * @ORM\JoinColumn(nullable=true, onDelete="SET NULL")
     */
    private ?Instituto $instituto = null;

    /**
     * IP desde la que se envió, para poder reconocer una tanda de spam.
     *
     * @ORM\Column(type="string", length=45, nullable=true)
     */
    private ?string $ip = null;

    public function __construct()
    {
        $this->fechaSolicitud = new \DateTime();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getNombreInstituto(): string
    {
        return $this->nombreInstituto;
    }

    public function setNombreInstituto(string $nombre): self
    {
        $this->nombreInstituto = trim($nombre);

        return $this;
    }

    public function getNombreContacto(): string
    {
        return $this->nombreContacto;
    }

    public function setNombreContacto(string $nombre): self
    {
        $this->nombreContacto = trim($nombre);

        return $this;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function setEmail(string $email): self
    {
        $this->email = strtolower(trim($email));

        return $this;
    }

    public function getTelefono(): ?string
    {
        return $this->telefono;
    }

    public function setTelefono(?string $telefono): self
    {
        $this->telefono = $telefono !== null && trim($telefono) !== '' ? trim($telefono) : null;

        return $this;
    }

    public function getAlumnosEstimados(): ?string
    {
        return $this->alumnosEstimados;
    }

    public function setAlumnosEstimados(?string $rango): self
    {
        $this->alumnosEstimados = isset(self::RANGOS[$rango]) ? $rango : null;

        return $this;
    }

    public function getAlumnosEstimadosEtiqueta(): ?string
    {
        return self::RANGOS[$this->alumnosEstimados] ?? null;
    }

    public function getMensaje(): ?string
    {
        return $this->mensaje;
    }

    public function setMensaje(?string $mensaje): self
    {
        $this->mensaje = $mensaje !== null && trim($mensaje) !== '' ? trim($mensaje) : null;

        return $this;
    }

    public function getEstado(): string
    {
        return $this->estado;
    }

    public function setEstado(string $estado): self
    {
        if (in_array($estado, [self::PENDIENTE, self::CONFIRMADA, self::DESCARTADA], true)) {
            $this->estado = $estado;
        }

        return $this;
    }

    public function estaPendiente(): bool
    {
        return $this->estado === self::PENDIENTE;
    }

    public function getMotivoDescarte(): ?string
    {
        return $this->motivoDescarte;
    }

    public function setMotivoDescarte(?string $motivo): self
    {
        $this->motivoDescarte = $motivo !== null && trim($motivo) !== '' ? trim($motivo) : null;

        return $this;
    }

    public function getNotas(): ?string
    {
        return $this->notas;
    }

    public function setNotas(?string $notas): self
    {
        $this->notas = $notas !== null && trim($notas) !== '' ? trim($notas) : null;

        return $this;
    }

    public function getFechaSolicitud(): \DateTimeInterface
    {
        return $this->fechaSolicitud;
    }

    public function getFechaResolucion(): ?\DateTimeInterface
    {
        return $this->fechaResolucion;
    }

    public function setFechaResolucion(?\DateTimeInterface $fecha): self
    {
        $this->fechaResolucion = $fecha;

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

    public function getIp(): ?string
    {
        return $this->ip;
    }

    public function setIp(?string $ip): self
    {
        $this->ip = $ip;

        return $this;
    }
}
