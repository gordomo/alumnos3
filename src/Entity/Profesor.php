<?php

namespace App\Entity;

use App\Repository\ProfesorRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * @ORM\Entity(repositoryClass=ProfesorRepository::class)
 */
class Profesor
{
    /**
     * @ORM\Id
     * @ORM\GeneratedValue
     * @ORM\Column(type="integer")
     */
    private $id;

    /**
     * @ORM\Column(type="text")
     */
    private $nombre;

    /**
     * @ORM\Column(type="text")
     */
    private $apellido;

    /**
     * @ORM\Column(type="string", length=8, unique=true)
     * @Assert\NotBlank(message="El DNI es obligatorio")
     * @Assert\Length(
     *     max=8,
     *     maxMessage="El DNI no puede tener más de {{ limit }} caracteres. Por favor, ingrese un DNI válido."
     * )
     */
    private $dni;

    /**
      * @ORM\Column(type="string", length=191, unique=true)
     */
    private $email;

    /**
     * @ORM\Column(type="text", nullable=true)
     */
    private $precioHora;

    /**
     * @ORM\Column(type="text", nullable=true)
     */
    private $viatico;

    /**
     * Tipo de pago: 'por_hora', 'fijo_mensual', 'porcentaje', 'combinado'
     * @ORM\Column(type="string", length=50, nullable=true)
     */
    private $tipoPago;

    /**
     * Monto fijo mensual (cuando tipoPago es 'fijo_mensual' o 'combinado')
     * @ORM\Column(type="decimal", precision=10, scale=2, nullable=true)
     */
    private $montoFijoMensual;

    /**
     * Porcentaje sobre el total del curso (cuando tipoPago es 'porcentaje' o 'combinado')
     * @ORM\Column(type="decimal", precision=5, scale=2, nullable=true)
     */
    private $porcentajeCurso;

    /**
     * @return mixed
     */
    public function getViatico()
    {
        return $this->viatico;
    }

    /**
     * @param mixed $viatico
     */
    public function setViatico($viatico): void
    {
        $this->viatico = $viatico;
    }

    /**
     * @ORM\Column(type="text", nullable=true)
     */
    private $tel;

    /**
     * @ORM\ManyToMany(targetEntity=Curso::class, mappedBy="profesores")
     */
    private $cursos;

    /**
     * @ORM\OneToMany(targetEntity=AsistenciaProfesores::class, mappedBy="profesor", orphanRemoval=true)
     */
    private $asistenciaProfesores;

    /**
     * @ORM\ManyToOne(targetEntity=Instituto::class, inversedBy="profesores")
     * @ORM\JoinColumn(nullable=false)
     */
    private $instituto;

    /**
     * @ORM\OneToOne(targetEntity=User::class, inversedBy="profesor")
     * @ORM\JoinColumn(name="user_id", referencedColumnName="id", nullable=true)
     */
    private $user;

    public function __construct()
    {
        $this->cursos = new ArrayCollection();
        $this->asistenciaProfesores = new ArrayCollection();
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

    public function getUser(): ?User
    {
        return $this->user;
    }

    public function setUser(?User $user): self
    {
        $this->user = $user;
        return $this;
    }
   

    public function getId(): ?int
    {
        return $this->id;
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

    public function getApellido(): ?string
    {
        return $this->apellido;
    }

    public function setApellido(string $apellido): self
    {
        $this->apellido = $apellido;

        return $this;
    }

    public function getDni(): ?string
    {
        return $this->dni;
    }

    public function setDni(string $dni): self
    {
        $this->dni = $dni;

        return $this;
    }

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function setEmail(string $email): self
    {
        $this->email = $email;

        return $this;
    }

    public function getTel(): ?string
    {
        return $this->tel;
    }

    public function setTel(?string $tel): self
    {
        $this->tel = $tel;

        return $this;
    }

    /**
     * @return Collection<int, Curso>
     */
    public function getCursos(): Collection
    {
        return $this->cursos;
    }

    public function addCurso(Curso $curso): self
    {
        if (!$this->cursos->contains($curso)) {
            $this->cursos[] = $curso;
        }

        return $this;
    }

    public function removeCurso(Curso $curso): self
    {
        $this->cursos->removeElement($curso);
        return $this;
    }

    /**
     * @return Collection<int, AsistenciaProfesores>
     */
    public function getAsistenciaProfesores(): Collection
    {
        return $this->asistenciaProfesores;
    }

    public function addAsistenciaProfesore(AsistenciaProfesores $asistenciaProfesore): self
    {
        if (!$this->asistenciaProfesores->contains($asistenciaProfesore)) {
            $this->asistenciaProfesores[] = $asistenciaProfesore;
            $asistenciaProfesore->setProfesor($this);
        }

        return $this;
    }

    public function removeAsistenciaProfesore(AsistenciaProfesores $asistenciaProfesore): self
    {
        if ($this->asistenciaProfesores->removeElement($asistenciaProfesore)) {
            // set the owning side to null (unless already changed)
            if ($asistenciaProfesore->getProfesor() === $this) {
                $asistenciaProfesore->setProfesor(null);
            }
        }

        return $this;
    }

    /**
     * @return mixed
     */
    public function getPrecioHora()
    {
        return $this->precioHora ? $this->precioHora : "0" ;
    }

    /**
     * @param mixed $precioHora
     */
    public function setPrecioHora($precioHora): void
    {
        $this->precioHora = $precioHora;
    }

    public function getTipoPago(): ?string
    {
        return $this->tipoPago ?? 'por_hora';
    }

    public function setTipoPago(?string $tipoPago): self
    {
        $this->tipoPago = $tipoPago;
        return $this;
    }

    public function getMontoFijoMensual(): ?float
    {
        return $this->montoFijoMensual ? (float)$this->montoFijoMensual : null;
    }

    public function setMontoFijoMensual(?float $montoFijoMensual): self
    {
        $this->montoFijoMensual = $montoFijoMensual;
        return $this;
    }

    public function getPorcentajeCurso(): ?float
    {
        return $this->porcentajeCurso ? (float)$this->porcentajeCurso : null;
    }

    public function setPorcentajeCurso(?float $porcentajeCurso): self
    {
        $this->porcentajeCurso = $porcentajeCurso;
        return $this;
    }
}
