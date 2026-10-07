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
     * @ORM\OneToMany(targetEntity=MetodoPago::class, mappedBy="instituto", cascade={"persist", "remove"}, orphanRemoval=true)
     * @ORM\OrderBy({"orden" = "ASC"})
     */
    private $metodosPago;


    // Métodos de inicialización y getters/setters

    /**
     * Precio por alumno de este instituto. Null usa el precio global de BillingConfig.
     *
     * Existe para poder acordar un precio distinto con un instituto sin tocar el de los demás.
     *
     * @ORM\Column(type="decimal", precision=10, scale=2, nullable=true)
     */
    private $precioPorAlumno;

    /**
     * Mínimo mensual: si el cálculo por alumnos da menos que esto, se cobra esto.
     *
     * Null es sin mínimo.
     *
     * @ORM\Column(type="decimal", precision=10, scale=2, nullable=true)
     */
    private $minimoMensual;

    /**
     * Un instituto exento no se factura ni se bloquea nunca.
     *
     * Sirve para el instituto de demostración, para una prueba gratuita o para un acuerdo
     * especial, sin tener que andar cancelando facturas a mano cada mes.
     *
     * @ORM\Column(type="boolean", options={"default": false})
     */
    private $suscripcionExenta = false;

    /**
     * Cuándo empezó a usar el sistema.
     *
     * Es lo que define cuál es su primer mes, que no se factura. Los institutos anteriores a
     * este campo quedan con la fecha de su primera factura, que es lo más cercano que tenemos.
     *
     * @ORM\Column(type="date", nullable=true)
     */
    private $fechaAlta;

    /**
     * Un instituto dado de baja no entra más al sistema y no se le factura, pero conserva todos
     * sus datos.
     *
     * Es lo que corresponde cuando un cliente se va: borrarle todo deja al instituto sin
     * historial y a nosotros sin poder contestar cuánto nos pagó. El borrado definitivo existe
     * aparte, para la data de prueba.
     *
     * @ORM\Column(type="boolean", options={"default": true})
     */
    private $activo = true;

    /**
     * @ORM\Column(type="date", nullable=true)
     */
    private $fechaBaja;

    /**
     * @ORM\Column(type="text", nullable=true)
     */
    private $motivoBaja;

    public function __construct()
    {
        $this->usuarios = new ArrayCollection();
        $this->vencimientos = new ArrayCollection();
        $this->cursos = new ArrayCollection();
        $this->profesores = new ArrayCollection();
        $this->alumnos = new ArrayCollection();
        $this->admins = new ArrayCollection();
        $this->metodosPago = new ArrayCollection();
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

    /**
     * @return Collection|MetodoPago[]
     */
    public function getMetodosPago(): Collection
    {
        return $this->metodosPago;
    }

    public function addMetodoPago(MetodoPago $metodoPago): self
    {
        if (!$this->metodosPago->contains($metodoPago)) {
            $this->metodosPago[] = $metodoPago;
            $metodoPago->setInstituto($this);
        }
        return $this;
    }

    public function removeMetodoPago(MetodoPago $metodoPago): self
    {
        if ($this->metodosPago->removeElement($metodoPago)) {
            if ($metodoPago->getInstituto() === $this) {
                $metodoPago->setInstituto(null);
            }
        }
        return $this;
    }

    public function getPrecioPorAlumno(): ?float
    {
        return $this->precioPorAlumno === null ? null : (float) $this->precioPorAlumno;
    }

    public function setPrecioPorAlumno($precio): self
    {
        $this->precioPorAlumno = ($precio === null || $precio === '') ? null : $precio;
        return $this;
    }

    public function getMinimoMensual(): ?float
    {
        return $this->minimoMensual === null ? null : (float) $this->minimoMensual;
    }

    public function setMinimoMensual($minimo): self
    {
        $this->minimoMensual = ($minimo === null || $minimo === '') ? null : $minimo;
        return $this;
    }

    public function isSuscripcionExenta(): bool
    {
        return (bool) $this->suscripcionExenta;
    }

    public function setSuscripcionExenta(bool $exenta): self
    {
        $this->suscripcionExenta = $exenta;
        return $this;
    }

    public function getFechaAlta(): ?\DateTimeInterface
    {
        return $this->fechaAlta;
    }

    public function setFechaAlta(?\DateTimeInterface $fecha): self
    {
        $this->fechaAlta = $fecha;
        return $this;
    }

    /**
     * El primer mes de uso no se factura. Devuelve true si el período (año, mes) cae dentro de
     * esa cortesía.
     */
    public function isActivo(): bool
    {
        return (bool) $this->activo;
    }

    public function getFechaBaja(): ?\DateTimeInterface
    {
        return $this->fechaBaja;
    }

    public function getMotivoBaja(): ?string
    {
        return $this->motivoBaja;
    }

    /**
     * Da de baja al instituto. La fecha y el motivo quedan para saber qué pasó.
     */
    public function darDeBaja(?string $motivo = null): self
    {
        $this->activo = false;
        $this->fechaBaja = new \DateTime();
        $this->motivoBaja = $motivo !== null && trim($motivo) !== '' ? trim($motivo) : null;

        return $this;
    }

    public function reactivar(): self
    {
        $this->activo = true;
        $this->fechaBaja = null;
        $this->motivoBaja = null;

        return $this;
    }

    /**
     * Si el período (año, mes) cae dentro del primer mes sin cargo.
     *
     * El mes del alta no se factura. Y si el instituto entró el día del vencimiento o después,
     * tampoco el siguiente: de un alta el 28 quedarían tres días de cortesía, que no es el mes
     * que le prometimos. El corte es el mismo día de vencimiento configurado, así que las dos
     * fechas se mueven juntas.
     *
     * Con vencimiento el 10: alta el 9 → solo ese mes. Alta el 10 → ese mes y el siguiente.
     */
    public function enMesDeCortesia(int $ano, int $mes, int $diaCorte = 10): bool
    {
        if (!$this->fechaAlta) {
            return false;
        }

        if ((int) $this->fechaAlta->format('Y') === $ano
            && (int) $this->fechaAlta->format('n') === $mes) {
            return true;
        }

        if ((int) $this->fechaAlta->format('j') < $diaCorte) {
            return false;
        }

        $siguiente = (new \DateTime($this->fechaAlta->format('Y-m-01')))->modify('+1 month');

        return $ano === (int) $siguiente->format('Y') && $mes === (int) $siguiente->format('n');
    }
}
