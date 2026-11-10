<?php
namespace App\Entity;
use Doctrine\ORM\Mapping as ORM;
/** @ORM\Entity @ORM\Table(indexes={@ORM\Index(name="idx_entrante_solicitud_fecha", columns={"solicitud_id","fecha"})}) */
class MensajeEntranteSolicitud
{
    /** @ORM\Id @ORM\GeneratedValue @ORM\Column(type="integer") */
    private $id;
    /** @ORM\ManyToOne(targetEntity=SolicitudPaginaPublica::class) @ORM\JoinColumn(nullable=false, onDelete="CASCADE") */
    private $solicitud;
    /** @ORM\Column(type="string", length=180) */
    private $remitente;
    /** @ORM\Column(type="string", length=255) */
    private $asunto;
    /** @ORM\Column(type="text") */
    private $mensaje;
    /** @ORM\Column(type="datetime_immutable") */
    private $fecha;
    /** @ORM\Column(type="string", length=64, unique=true) */
    private $huella;
    /** @ORM\Column(type="string", length=68) */
    private $archivoOriginal;
    /** @ORM\Column(type="json") */
    private $adjuntos=[];
    public function __construct(SolicitudPaginaPublica $s, string $remitente, string $asunto, string $mensaje, string $huella, string $archivo, array $adjuntos)
    {
        $this->solicitud=$s;$this->remitente=$remitente;$this->asunto=$asunto;$this->mensaje=$mensaje;$this->huella=$huella;$this->archivoOriginal=$archivo;$this->adjuntos=$adjuntos;$this->fecha=new \DateTimeImmutable();
    }
    public function getId(): ?int { return $this->id; }
    public function getSolicitud(): SolicitudPaginaPublica { return $this->solicitud; }
    public function getRemitente(): string { return $this->remitente; }
    public function getAsunto(): string { return $this->asunto; }
    public function getMensaje(): string { return $this->mensaje; }
    public function getFecha(): \DateTimeImmutable { return $this->fecha; }
    public function getArchivoOriginal(): string { return $this->archivoOriginal; }
    public function getAdjuntos(): array { return $this->adjuntos; }
}
