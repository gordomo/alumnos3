<?php

namespace App\Entity;

use App\Repository\InstitutoRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * @ORM\Entity(repositoryClass=InstitutoRepository::class)
 */
class Instituto
{
    /**
     * @ORM\Id
     * @ORM\GeneratedValue
     * @ORM\Column(type="integer")
     */
    private $id;

    /**
     * @ORM\Column(type="string", length=255)
     */
    private $nombre;

    /**
     * @ORM\OneToMany(targetEntity=User::class, mappedBy="instituto")
     */
    private $usuarios;

    /**
     * @ORM\Column(type="string", length=255, nullable=true)
     */
    private $logo;
    
    /**
     * @ORM\Column(type="string", length=255, nullable=true)
     */
    private $email;
    
    /**
     * @ORM\Column(type="string", length=255, nullable=true)
     */
    
     private $dir;
    /**
     * @ORM\Column(type="string", length=25, nullable=true)
     */
    private $tel;
    
    /**
     * @ORM\OneToMany(targetEntity=Vencimiento::class, mappedBy="instituto", orphanRemoval=true)
     */
    private $vencimientos;

    /**
     * @ORM\OneToMany(targetEntity=Curso::class, mappedBy="instituto")
     */
    private $cursos;

    /**
     * @ORM\OneToMany(targetEntity=Profesor::class, mappedBy="instituto")
     */
    private $profesores;

    /**
     * @ORM\OneToMany(targetEntity=Alumno::class, mappedBy="instituto")
     */
    private $alumnos;

    /**
     * @ORM\OneToOne(targetEntity=InstitutoConfiguracion::class, mappedBy="instituto", cascade={"persist", "remove"})
     */
    private $configuracion;

    /**
     * @ORM\OneToMany(targetEntity=InstitutoAdmin::class, mappedBy="instituto", cascade={"persist", "remove"})
     */
    private $admins;

    /**
     * @ORM\OneToOne(targetEntity=TokenBalance::class, mappedBy="instituto", cascade={"persist", "remove"})
     */
    private $tokenBalance;

    // Métodos de inicialización y getters/setters

    public function __construct()
    {
        $this->usuarios = new ArrayCollection();
        $this->vencimientos = new ArrayCollection();
        $this->cursos = new ArrayCollection();
        $this->profesores = new ArrayCollection();
        $this->alumnos = new ArrayCollection();
        $this->admins = new ArrayCollection();
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

    public function getUsuarios(): Collection
    {
        return $this->usuarios;
    }

    public function getLogo(): ?string
    {
        return $this->logo;
    }

    public function setLogo(?string $logo): self
    {
        $this->logo = $logo;

        return $this;
    }

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function setEmail(?string $email): self
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

    public function getDir(): ?string
    {
        return $this->dir;
    }

    public function setDir(?string $dir): self
    {
        $this->dir = $dir;

        return $this;
    }

    /**
     * @return Collection<int, Vencimiento>
     */
    public function getVencimientos(): Collection
    {
        return $this->vencimientos ?? new ArrayCollection();
    }

    public function addVencimiento(Vencimiento $vencimiento): self
    {
        if (!$this->vencimientos->contains($vencimiento)) {
            $this->vencimientos[] = $vencimiento;
            $vencimiento->setInstituto($this);
        }
        return $this;
    }

    public function removeVencimiento(Vencimiento $vencimiento): self
    {
        if ($this->vencimientos->removeElement($vencimiento)) {
            // set the owning side to null (unless already changed)
            if ($vencimiento->getInstituto() === $this) {
                $vencimiento->setInstituto(null);
            }
        }
        return $this;
    }

    /**
     * @return Collection|Curso[]
     */
    public function getCursos(): Collection
    {
        return $this->cursos;
    }

    public function addCurso(Curso $curso): self
    {
        if (!$this->cursos->contains($curso)) {
            $this->cursos[] = $curso;
            $curso->setInstituto($this);
        }
        return $this;
    }

    public function removeCurso(Curso $curso): self
    {
        if ($this->cursos->removeElement($curso)) {
            if ($curso->getInstituto() === $this) {
                $curso->setInstituto(null);
            }
        }
        return $this;
    }

    /**
     * @return Collection|Profesor[]
     */
    public function getProfesores(): Collection
    {
        return $this->profesores;
    }

    public function addProfesor(Profesor $profesor): self
    {
        if (!$this->profesores->contains($profesor)) {
            $this->profesores[] = $profesor;
            $profesor->setInstituto($this);
        }
        return $this;
    }

    public function removeProfesor(Profesor $profesor): self
    {
        if ($this->profesores->removeElement($profesor)) {
            if ($profesor->getInstituto() === $this) {
                $profesor->setInstituto(null);
            }
        }
        return $this;
    }

    /**
     * @return Collection|Alumno[]
     */
    public function getAlumnos(): Collection
    {
        return $this->alumnos;
    }

    public function addAlumno(Alumno $alumno): self
    {
        if (!$this->alumnos->contains($alumno)) {
            $this->alumnos[] = $alumno;
            $alumno->setInstituto($this);
        }
        return $this;
    }

    public function removeAlumno(Alumno $alumno): self
    {
        if ($this->alumnos->removeElement($alumno)) {
            if ($alumno->getInstituto() === $this) {
                $alumno->setInstituto(null);
            }
        }
        return $this;
    }

    public function getConfiguracion(): ?InstitutoConfiguracion
    {
        return $this->configuracion;
    }

    public function setConfiguracion(?InstitutoConfiguracion $configuracion): self
    {
        // unset the owning side of the relation if necessary
        if ($configuracion === null && $this->configuracion !== null) {
            $this->configuracion->setInstituto(null);
        }

        // set the owning side of the relation if necessary
        if ($configuracion !== null && $configuracion->getInstituto() !== $this) {
            $configuracion->setInstituto($this);
        }

        $this->configuracion = $configuracion;

        return $this;
    }

    /**
     * @return Collection<int, InstitutoAdmin>
     */
    public function getAdmins(): Collection
    {
        return $this->admins;
    }

    public function addAdmin(InstitutoAdmin $admin): self
    {
        if (!$this->admins->contains($admin)) {
            $this->admins[] = $admin;
            $admin->setInstituto($this);
        }
        return $this;
    }

    public function removeAdmin(InstitutoAdmin $admin): self
    {
        if ($this->admins->removeElement($admin)) {
            if ($admin->getInstituto() === $this) {
                $admin->setInstituto(null);
            }
        }
        return $this;
    }

    public function getTokenBalance(): ?\App\Entity\TokenBalance
    {
        return $this->tokenBalance;
    }

    public function setTokenBalance(?\App\Entity\TokenBalance $tokenBalance): self
    {
        $this->tokenBalance = $tokenBalance;
        return $this;
    }
}