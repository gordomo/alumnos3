<?php
namespace App\Entity;
use Doctrine\ORM\Mapping as ORM;

/**
 * @ORM\Entity
 * @ORM\Table(indexes={@ORM\Index(name="idx_respuesta_solicitud_fecha", columns={"solicitud_id","fecha"})})
 */
class RespuestaSolicitudPublica
{
    /** @ORM\Id @ORM\GeneratedValue @ORM\Column(type="integer") */
    private $id;
    /** @ORM\ManyToOne(targetEntity=SolicitudPaginaPublica::class) @ORM\JoinColumn(nullable=false, onDelete="CASCADE") */
    private $solicitud;
    /** @ORM\ManyToOne(targetEntity=User::class) @ORM\JoinColumn(nullable=true, onDelete="SET NULL") */
    private $autor;
    /** @ORM\Column(type="string", length=180) */
    private $destinatario;
    /** @ORM\Column(type="string", length=180) */
    private $asunto;
    /** @ORM\Column(type="text") */
    private $mensaje;
    /** @ORM\Column(type="string", length=20) */
    private $estado='enviando';
    /** @ORM\Column(type="datetime_immutable") */
    private $fecha;
    /** @ORM\Column(type="string", length=64, unique=true) */
    private $claveEnvio;
    /** @ORM\Column(type="string", length=180, nullable=true, unique=true) */
    private $correoMessageId;
    public function getCorreoMessageId(): ?string { return $this->correoMessageId; }
    public function prepararCorreo(): void { if (!$this->correoMessageId) { $this->correoMessageId='tb-'.bin2hex(random_bytes(16)).'@teambuilder.com.ar'; } }
    public function __construct(SolicitudPaginaPublica $solicitud, User $autor, string $asunto, string $mensaje, string $clave)
    {
        $this->solicitud=$solicitud;$this->autor=$autor;$this->destinatario=$solicitud->getEmail();$this->asunto=$asunto;$this->mensaje=$mensaje;$this->claveEnvio=$clave;$this->fecha=new \DateTimeImmutable();
    }
    public function getId(): ?int { return $this->id; }
    public function getSolicitud(): SolicitudPaginaPublica { return $this->solicitud; }
    public function getAutor(): ?User { return $this->autor; }
    public function getDestinatario(): string { return $this->destinatario; }
    public function getAsunto(): string { return $this->asunto; }
    public function getMensaje(): string { return $this->mensaje; }
    public function getEstado(): string { return $this->estado; }
    public function getFecha(): \DateTimeImmutable { return $this->fecha; }
    public function marcarEnviada(): void { $this->estado='enviada'; }
    public function marcarFallida(): void { $this->estado='fallida'; }
}
