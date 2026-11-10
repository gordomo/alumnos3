<?php
namespace App\Entity;

use App\Repository\SolicitudPaginaPublicaRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * @ORM\Entity(repositoryClass=SolicitudPaginaPublicaRepository::class)
 * @ORM\Table(indexes={@ORM\Index(name="idx_solicitud_publica_bandeja", columns={"instituto_id","estado","fecha"})})
 */
class SolicitudPaginaPublica
{
    /** @ORM\Id @ORM\GeneratedValue @ORM\Column(type="integer") */
    private $id;
    /** @ORM\ManyToOne(targetEntity=Instituto::class) @ORM\JoinColumn(nullable=false, onDelete="CASCADE") */
    private $instituto;
    /** @ORM\ManyToOne(targetEntity=Curso::class) @ORM\JoinColumn(nullable=true, onDelete="SET NULL") */
    private $curso;
    /** @ORM\Column(type="string", length=20) */
    private $tipo = 'contacto';
    /** @ORM\Column(type="string", length=20) */
    private $estado = 'pendiente';
    /** @ORM\Column(type="string", length=100) @Assert\NotBlank(message="Ingresá tu nombre.") @Assert\Length(max=100) */
    private $nombre = '';
    /** @ORM\Column(type="string", length=100) @Assert\Length(max=100) */
    private $apellido = '';
    /** @ORM\Column(type="string", length=180) @Assert\NotBlank(message="Ingresá tu email.") @Assert\Email(message="Ingresá un email válido.") @Assert\Length(max=180) */
    private $email = '';
    /** @ORM\Column(type="string", length=50, nullable=true) @Assert\Length(max=50) */
    private $telefono;
    /** @ORM\Column(type="text", nullable=true) @Assert\Length(max=3000) */
    private $mensaje;
    /** @ORM\Column(type="datetime_immutable") */
    private $fecha;
    /** @ORM\Column(type="datetime_immutable", nullable=true) */
    private $fechaResolucion;
    /** @ORM\Column(type="string", length=64, unique=true) */
    private $tokenHash;
    /** Una sola preinscripción activa por institución, curso y correo. @ORM\Column(type="string", length=64, nullable=true, unique=true) */
    private $claveActiva;
    /** @ORM\ManyToOne(targetEntity=Alumno::class) @ORM\JoinColumn(nullable=true, onDelete="SET NULL") */
    private $alumno;

    public function __construct() { $this->fecha = new \DateTimeImmutable(); }
    public function getId(): ?int { return $this->id; }
    public function getInstituto(): ?Instituto { return $this->instituto; }
    public function setInstituto(Instituto $i): self { $this->instituto=$i; return $this; }
    public function getCurso(): ?Curso { return $this->curso; }
    public function setCurso(?Curso $c): self { $this->curso=$c; return $this; }
    public function getTipo(): string { return $this->tipo; }
    public function setTipo(string $t): self { $this->tipo=$t; return $this; }
    public function getEstado(): string { return $this->estado; }
    public function getNombre(): string { return $this->nombre; }
    public function setNombre(string $s): self { $this->nombre=trim($s); return $this; }
    public function getApellido(): string { return $this->apellido; }
    public function setApellido(string $s): self { $this->apellido=trim($s); return $this; }
    public function getEmail(): string { return $this->email; }
    public function setEmail(string $s): self { $this->email=mb_strtolower(trim($s)); return $this; }
    public function getTelefono(): ?string { return $this->telefono; }
    public function setTelefono(?string $s): self { $this->telefono=$s === null ? null : trim($s); return $this; }
    public function getMensaje(): ?string { return $this->mensaje; }
    public function setMensaje(?string $s): self { $this->mensaje=$s === null ? null : trim($s); return $this; }
    public function getFecha(): \DateTimeImmutable { return $this->fecha; }
    public function getFechaResolucion(): ?\DateTimeImmutable { return $this->fechaResolucion; }
    public function getTokenHash(): ?string { return $this->tokenHash; }
    public function setTokenHash(string $s): self { $this->tokenHash=$s; return $this; }
    public function getClaveActiva(): ?string { return $this->claveActiva; }
    public function setClaveActiva(?string $s): self { $this->claveActiva=$s; return $this; }
    public function getAlumno(): ?Alumno { return $this->alumno; }
    public function resolver(string $estado): bool
    {
        $permitidos = $this->tipo === 'preinscripcion' ? ['confirmada','descartada'] : ['atendida','descartada'];
        if ($this->estado !== 'pendiente' || !in_array($estado,$permitidos,true)) { return false; }
        $this->estado=$estado; $this->fechaResolucion=new \DateTimeImmutable();
        if ($estado === 'descartada') { $this->claveActiva=null; }
        return true;
    }
    public function completarConAlumno(Alumno $alumno): void
    {
        if ($this->tipo !== 'preinscripcion' || $this->estado !== 'confirmada' || $alumno->getInstituto()?->getId() !== $this->instituto->getId()) { throw new \LogicException('La preinscripción no corresponde a este alumno.'); }
        if (!$this->curso || !$alumno->getCurso()->contains($this->curso)) { throw new \LogicException('Debe incluir el curso de la preinscripción.'); }
        $this->alumno=$alumno; $this->estado='inscrita'; $this->claveActiva=null; $this->fechaResolucion=new \DateTimeImmutable();
    }
}
