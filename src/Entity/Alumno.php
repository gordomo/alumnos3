<?php

namespace App\Entity;

use App\Repository\AlumnoRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * @ORM\Entity(repositoryClass=AlumnoRepository::class)
 */
class Alumno
{
    /**
     * @ORM\Id
     * @ORM\GeneratedValue
     * @ORM\Column(type="integer")
     */
    private $id;

    /**
     * @ORM\Column(type="text", nullable=true)
     */
    private $telefono_fijo;

    /**
     * @ORM\Column(type="text")
     */
    private $nombre;

    /**
     * @ORM\Column(type="text")
     */
    private $apellido;

    /**
     * @ORM\Column(type="date")
     */
    private $f_nac;

    /**
     * @ORM\Column(type="text")
     */
    private $email;

    /**
     * @ORM\Column(type="text", nullable=true)
     */
    private $l_nac;

    /**
     * @ORM\Column(type="text")
     */
    private $dni;

    /**
     * @ORM\Column(type="text", nullable=true)
     */
    private $celular;

    /**
     * @ORM\Column(type="text", nullable=true)
     */
    private $contacto_emergencia;

    /**
     * @ORM\Column(type="text", nullable=true)
     */
    private $n_tutor;

    /**
     * @ORM\Column(type="text", nullable=true)
     */
    private $t_tutor;

    /**
     * @ORM\Column(type="text", nullable=true)
     */
    private $corre_tutor;

    /**
     * @ORM\Column(type="text", nullable=true)
     */
    private $dni_tutor;

    /**
     * @ORM\Column(type="text", nullable=true)
     */
    private $escuela;

    /**
     * @ORM\Column(type="text", nullable=true)
     */
    private $extras;

    /**
     * @ORM\Column(type="text", nullable=true)
     */
    private $g_sanguineo;

    /**
     * @ORM\Column(type="text", nullable=true)
     */
    private $enfermedad;

    /**
     * @ORM\Column(type="text", nullable=true)
     */
    private $alergico;

    /**
     * @ORM\Column(type="text", nullable=true)
     */
    private $medicacion;

    /**
     * @ORM\ManyToMany(targetEntity=Curso::class, inversedBy="profesores")
     */
    private $curso;

    /**
     * @ORM\Column(type="text", nullable=true)
     */
    private $como_conociste;

    /**
     * @ORM\Column(type="json", nullable=true)
     */
    private $hermanos = [];

    public function __construct()
    {
        $this->curso = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTelefonoFijo(): ?string
    {
        return $this->telefono_fijo;
    }

    public function setTelefonoFijo(?string $telefono_fijo): self
    {
        $this->telefono_fijo = $telefono_fijo;

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

    public function getApellido(): ?string
    {
        return $this->apellido;
    }

    public function setApellido(string $apellido): self
    {
        $this->apellido = $apellido;

        return $this;
    }

    public function getFNac(): ?\DateTimeInterface
    {
        return $this->f_nac;
    }

    public function setFNac(\DateTimeInterface $f_nac): self
    {
        $this->f_nac = $f_nac;

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

    public function getLNac(): ?string
    {
        return $this->l_nac;
    }

    public function setLNac(?string $l_nac): self
    {
        $this->l_nac = $l_nac;

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

    public function getCelular(): ?string
    {
        return $this->celular;
    }

    public function setCelular(?string $celular): self
    {
        $this->celular = $celular;

        return $this;
    }

    public function getContactoEmergencia(): ?string
    {
        return $this->contacto_emergencia;
    }

    public function setContactoEmergencia(?string $contacto_emergencia): self
    {
        $this->contacto_emergencia = $contacto_emergencia;

        return $this;
    }

    public function getNTutor(): ?string
    {
        return $this->n_tutor;
    }

    public function setNTutor(?string $n_tutor): self
    {
        $this->n_tutor = $n_tutor;

        return $this;
    }

    public function getTTutor(): ?string
    {
        return $this->t_tutor;
    }

    public function setTTutor(?string $t_tutor): self
    {
        $this->t_tutor = $t_tutor;

        return $this;
    }

    public function getCorreTutor(): ?string
    {
        return $this->corre_tutor;
    }

    public function setCorreTutor(?string $corre_tutor): self
    {
        $this->corre_tutor = $corre_tutor;

        return $this;
    }

    public function getDniTutor(): ?string
    {
        return $this->dni_tutor;
    }

    public function setDniTutor(?string $dni_tutor): self
    {
        $this->dni_tutor = $dni_tutor;

        return $this;
    }

    public function getEscuela(): ?string
    {
        return $this->escuela;
    }

    public function setEscuela(?string $escuela): self
    {
        $this->escuela = $escuela;

        return $this;
    }

    public function getExtras(): ?string
    {
        return $this->extras;
    }

    public function setExtras(?string $extras): self
    {
        $this->extras = $extras;

        return $this;
    }

    public function getGSanguineo(): ?string
    {
        return $this->g_sanguineo;
    }

    public function setGSanguineo(?string $g_sanguineo): self
    {
        $this->g_sanguineo = $g_sanguineo;

        return $this;
    }

    public function getEnfermedad(): ?string
    {
        return $this->enfermedad;
    }

    public function setEnfermedad(?string $enfermedad): self
    {
        $this->enfermedad = $enfermedad;

        return $this;
    }

    public function getAlergico(): ?string
    {
        return $this->alergico;
    }

    public function setAlergico(?string $alergico): self
    {
        $this->alergico = $alergico;

        return $this;
    }

    public function getMedicacion(): ?string
    {
        return $this->medicacion;
    }

    public function setMedicacion(?string $medicacion): self
    {
        $this->medicacion = $medicacion;

        return $this;
    }

    /**
     * @return Collection<int, Curso>
     */
    public function getCurso(): Collection
    {
        return $this->curso;
    }

    public function addCurso(Curso $curso): self
    {
        if (!$this->curso->contains($curso)) {
            $this->curso[] = $curso;
        }

        return $this;
    }

    public function removeCurso(Curso $curso): self
    {
        $this->curso->removeElement($curso);

        return $this;
    }

    public function getComoConociste(): ?string
    {
        return $this->como_conociste;
    }

    public function setComoConociste(?string $como_conociste): self
    {
        $this->como_conociste = $como_conociste;

        return $this;
    }

    public function setHermanos(array $hermanos): self {
        $this->hermanos = $hermanos;
        return $this;
    }

    public function getHermanos(): ?array{
        return $this->hermanos ?? [];
    }

    public function getNombreApellido(): ?string
    {
        return $this->getNombre() . ' ' . $this->getApellido();
    }
}
