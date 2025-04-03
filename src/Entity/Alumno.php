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
     * @ORM\Column(type="date", nullable=true)
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
     * @ORM\Column(type="string", length=10, unique=true)
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
     * @ORM\ManyToMany(targetEntity=Curso::class, inversedBy="alumnos")
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

    /**
     * @ORM\Column(type="boolean", nullable=true)
     */
    private $activo;

    /**
     * @ORM\OneToMany(targetEntity=AlumnosPagos::class, mappedBy="alumno", orphanRemoval=true)
     */
    private $pagos;

    /**
     * @ORM\ManyToOne(targetEntity=Instituto::class, inversedBy="alumnos")
     * @ORM\JoinColumn(nullable=false)
     */
    private $instituto;

    /**
     * @ORM\OneToMany(targetEntity=AlumnoCursoHistorico::class, mappedBy="alumno", orphanRemoval=true)
     */
    private $cursosHistoricos;

    /**
     * @ORM\OneToMany(targetEntity=AsistenciaAlumnos::class, mappedBy="alumno")
     */
    private $asistencias;

    public function __construct()
    {
        $this->curso = new ArrayCollection();
        $this->pagos = new ArrayCollection();
        $this->cursosHistoricos = new ArrayCollection();
        $this->asistencias = new ArrayCollection();
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

    public function setInstituto(?Instituto $instituto): self {
        $this->instituto = $instituto;
        return $this;
    }

    public function getInstituto(): ?Instituto{
        return $this->instituto ?? [];
    }

    public function getNombreApellido(): ?string
    {
        return $this->getNombre() . ' ' . $this->getApellido();
    }

    /**
     * @return mixed
     */
    public function getActivo()
    {
        return $this->activo;
    }

    /**
     * @param mixed $activo
     */
    public function setActivo($activo): void
    {
        $this->activo = $activo;
    }

    /**
     * @return Collection<int, AlumnosPagos>
     */
    public function getPagos(): Collection
    {
        return $this->pagos;
    }

    public function addPago(AlumnosPagos $pago): self
    {
        if (!$this->pagos->contains($pago)) {
            $this->pagos[] = $pago;
            $pago->setAlumno($this);
        }

        return $this;
    }

    public function removePago(AlumnosPagos $pago): self
    {
        if ($this->pagos->removeElement($pago)) {
            // set the owning side to null (unless already changed)
            if ($pago->getAlumno() === $this) {
                $pago->setAlumno(null);
            }
        }

        return $this;
    }

    /**
     * Verifica si el alumno debe el mes actual
     */
    public function debeMes(int $mes, int $ano, int $dia): bool
    {
        // Si el alumno no tiene cursos, no debe
        if ($this->curso->isEmpty()) {
            return false;
        }

        // Obtener el historial de cursos activo para el mes actual
        $historicoActivo = null;
        foreach ($this->cursosHistoricos as $historico) {
            $fechaInicio = $historico->getFechaInicio();
            $fechaFin = $historico->getFechaFin();
            
            // Verificar si el mes actual está dentro del período del curso
            if ($fechaInicio->format('Y-m') <= "$ano-$mes" && 
                ($fechaFin === null || $fechaFin->format('Y-m') >= "$ano-$mes")) {
                $historicoActivo = $historico;
                break;
            }
        }

        // Si no hay historial activo para el mes actual, debe
        if (!$historicoActivo) {
            return true;
        }

        // Verificar si el mes está en los meses pagados
        $mesActual = "$ano-$mes";
        return !in_array($mesActual, $historicoActivo->getMesesPagados());
    }

    /**
     * Obtiene el porcentaje de interés aplicable según el día de pago
     */
    public function getPorcentajeInteresAplicable(): float
    {
        $vencimientos = $this->instituto->getVencimientos()->toArray();
        usort($vencimientos, function($a, $b) {
            return $a->getOrden() <=> $b->getOrden();
        });

        // Si no hay vencimientos configurados, no hay interés
        if (empty($vencimientos)) {
            return 0;
        }

        $hoy = new \DateTime();
        $diaActual = (int)$hoy->format('d');

        // Encontrar el vencimiento aplicable
        foreach ($vencimientos as $vencimiento) {
            if ($diaActual > $vencimiento->getDiaVencimiento()) {
                return $vencimiento->getPorcentajeInteres();
            }
        }

        // Si no se encontró ningún vencimiento aplicable (es decir, el día actual es menor o igual al primer vencimiento)
        return 0;
    }

    public function getUltimoPago(): ?AlumnosPagos
    {
        $pagos = $this->getPagos();
        
        if ($pagos->isEmpty()) {
            return null; // Si no hay pagos, retorna null
        }

        // Ordenar pagos por fecha descendente para obtener el último
        $pagosArray = $pagos->getValues();
        
        usort($pagosArray, function (AlumnosPagos $a, AlumnosPagos $b) {
            return $b->getFecha() <=> $a->getFecha(); // Orden descendente
        });

        return $pagosArray[0]; // Retorna el primer elemento (último pago por fecha)
    }

    /**
     * @return Collection<int, AlumnoCursoHistorico>
     */
    public function getCursosHistoricos(): Collection
    {
        return $this->cursosHistoricos;
    }

    public function addCursoHistorico(AlumnoCursoHistorico $cursoHistorico): self
    {
        if (!$this->cursosHistoricos->contains($cursoHistorico)) {
            $this->cursosHistoricos[] = $cursoHistorico;
            $cursoHistorico->setAlumno($this);
        }
        return $this;
    }

    public function removeCursoHistorico(AlumnoCursoHistorico $cursoHistorico): self
    {
        if ($this->cursosHistoricos->removeElement($cursoHistorico)) {
            if ($cursoHistorico->getAlumno() === $this) {
                $cursoHistorico->setAlumno(null);
            }
        }
        return $this;
    }

    /**
     * @return Collection<int, AsistenciaAlumnos>
     */
    public function getAsistencias(): Collection
    {
        return $this->asistencias;
    }

    public function addAsistencia(AsistenciaAlumnos $asistencia): self
    {
        if (!$this->asistencias->contains($asistencia)) {
            $this->asistencias[] = $asistencia;
            $asistencia->setAlumno($this);
        }

        return $this;
    }

    public function removeAsistencia(AsistenciaAlumnos $asistencia): self
    {
        if ($this->asistencias->removeElement($asistencia)) {
            // set the owning side to null (unless already changed)
            if ($asistencia->getAlumno() === $this) {
                $asistencia->setAlumno(null);
            }
        }

        return $this;
    }
}
