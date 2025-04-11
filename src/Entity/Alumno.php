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

    /**
     * @ORM\OneToMany(targetEntity=DeudaAlumno::class, mappedBy="alumno", orphanRemoval=true)
     */
    private $deudas;

    /**
     * Variable para cachear el resultado de debeMes
     * @var array
     */
    private $cacheDebeMes = [];

    /**
     * Variable estática para cachear los días de vencimiento por instituto
     * @var array
     */
    private static $cacheDiasVencimiento = [];

    public function __construct()
    {
        $this->curso = new ArrayCollection();
        $this->pagos = new ArrayCollection();
        $this->cursosHistoricos = new ArrayCollection();
        $this->asistencias = new ArrayCollection();
        $this->deudas = new ArrayCollection();
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
            // Limpiar caché de debeMes al añadir un pago
            $this->limpiarCacheDebeMes();
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
            // Limpiar caché de debeMes al eliminar un pago
            $this->limpiarCacheDebeMes();
        }

        return $this;
    }

    /**
     * Verifica si el alumno tiene deudas pendientes hasta la fecha especificada
     * Método optimizado que utiliza la entidad DeudaAlumno
     */
    public function tieneDeudas(int $mes = null, int $ano = null): bool
    {
        if (!$this->activo) {
            return false;
        }

        // Si no se especifican mes y año, usar la fecha actual
        if ($mes === null || $ano === null) {
            $fecha = new \DateTime();
            $mes = (int)$fecha->format('n');
            $ano = (int)$fecha->format('Y');
        }

        // Filtrar deudas no pagadas hasta la fecha especificada
        foreach ($this->deudas as $deuda) {
            if (!$deuda->isPagado()) {
                // Comprobar si la deuda es anterior o igual a la fecha especificada
                if ($deuda->getAno() < $ano || 
                    ($deuda->getAno() == $ano && $deuda->getMes() <= $mes)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Verifica si el alumno debe algún mes hasta la fecha de consulta
     * Para cada curso histórico, debe haber un pago correspondiente por cada mes
     * entre la fecha de inicio y la fecha de fin (o la fecha de consulta, lo que ocurra primero)
     * 
     * @deprecated Use tieneDeudas() instead
     */
    public function debeMes(int $mes, int $ano, int $dia): bool
    {
        // Redirigir al nuevo método para mantener compatibilidad
        return $this->tieneDeudas($mes, $ano);
    }

    /**
     * Limpia la caché de verificación de deuda
     * Llamar a este método después de añadir pagos o modificar cursos
     */
    public function limpiarCacheDebeMes(): void
    {
        $this->cacheDebeMes = [];
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
            // Limpiar caché de debeMes al añadir un curso histórico
            $this->limpiarCacheDebeMes();
        }
        return $this;
    }

    public function removeCursoHistorico(AlumnoCursoHistorico $cursoHistorico): self
    {
        if ($this->cursosHistoricos->removeElement($cursoHistorico)) {
            if ($cursoHistorico->getAlumno() === $this) {
                $cursoHistorico->setAlumno(null);
            }
            // Limpiar caché de debeMes al eliminar un curso histórico  
            $this->limpiarCacheDebeMes();
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

    /**
     * @return Collection<int, DeudaAlumno>
     */
    public function getDeudas(): Collection
    {
        return $this->deudas;
    }

    public function addDeuda(DeudaAlumno $deuda): self
    {
        if (!$this->deudas->contains($deuda)) {
            $this->deudas[] = $deuda;
            $deuda->setAlumno($this);
        }

        return $this;
    }

    public function removeDeuda(DeudaAlumno $deuda): self
    {
        if ($this->deudas->removeElement($deuda)) {
            // set the owning side to null (unless already changed)
            if ($deuda->getAlumno() === $this) {
                $deuda->setAlumno(null);
            }
        }

        return $this;
    }

    /**
     * Verifica si el alumno tiene deudas vencidas según los vencimientos del instituto
     * Una deuda se considera vencida cuando la fecha actual supera la fecha de vencimiento del mes
     */
    public function tieneDeudasVencidas(): bool
    {
        if (!$this->activo) {
            return false;
        }

        // Obtener la fecha actual
        $fechaActual = new \DateTime();
        $diaActual = (int)$fechaActual->format('d');
        $mesActual = (int)$fechaActual->format('n');
        $anoActual = (int)$fechaActual->format('Y');

        // Obtener el día de vencimiento cacheado por instituto
        $institutoId = $this->instituto->getId();
        $primerDiaVencimiento = $this->getPrimerDiaVencimiento($institutoId);

        // Verificar deudas de meses anteriores (siempre están vencidas)
        for ($m = 1; $m < $mesActual; $m++) {
            if ($this->tieneDeudas($m, $anoActual)) {
                return true;
            }
        }
        
        // Verificar deudas de años anteriores (siempre están vencidas)
        for ($a = $anoActual - 1; $a >= $anoActual - 2; $a--) {
            for ($m = 1; $m <= 12; $m++) {
                if ($this->tieneDeudas($m, $a)) {
                    return true;
                }
            }
        }
        
        // Verificar si la deuda del mes actual está vencida
        if ($diaActual >= $primerDiaVencimiento && $this->tieneDeudas($mesActual, $anoActual)) {
            return true;
        }
        
        return false;
    }

    /**
     * Obtiene el primer día de vencimiento configurado para el instituto
     * Utiliza caché estática para evitar consultas repetidas
     */
    private function getPrimerDiaVencimiento(int $institutoId): int
    {
        // Si ya tenemos el valor en caché, lo devolvemos
        if (isset(self::$cacheDiasVencimiento[$institutoId])) {
            return self::$cacheDiasVencimiento[$institutoId];
        }

        // Obtener vencimientos ordenados del instituto
        $vencimientos = $this->instituto->getVencimientos()->toArray();
        
        // Si no hay vencimientos configurados, usar día 5 como referencia por defecto
        if (empty($vencimientos)) {
            $diaVencimiento = 5; // Día predeterminado de vencimiento
        } else {
            // Ordenar vencimientos por orden
            usort($vencimientos, function($a, $b) {
                return $a->getOrden() <=> $b->getOrden();
            });
            
            // Obtener el primer vencimiento (el que tiene el menor día del mes)
            $primerVencimiento = reset($vencimientos);
            $diaVencimiento = $primerVencimiento->getDiaVencimiento();
        }
        
        // Almacenar en caché para futuras consultas
        self::$cacheDiasVencimiento[$institutoId] = $diaVencimiento;
        
        return $diaVencimiento;
    }

    /**
     * Obtiene las deudas vencidas y la del mes actual si corresponde
     * @return array Array de deudas que deben mostrarse para pago
     */
    public function getDeudasParaPago(): array
    {
        if (!$this->activo) {
            return [];
        }

        $deudasParaMostrar = [];
        $fechaActual = new \DateTime();
        $mesActual = (int)$fechaActual->format('n');
        $anoActual = (int)$fechaActual->format('Y');
        $diaActual = (int)$fechaActual->format('d');
        
        // Obtener el día de vencimiento cacheado
        $institutoId = $this->instituto->getId();
        $primerDiaVencimiento = $this->getPrimerDiaVencimiento($institutoId);
        
        // Filtrar deudas que deben mostrarse
        foreach ($this->deudas as $deuda) {
            if (!$deuda->isPagado()) {
                $mesDeuda = $deuda->getMes();
                $anoDeuda = $deuda->getAno();
                
                // Incluir deudas de meses pasados (siempre vencidas)
                if ($anoDeuda < $anoActual || ($anoDeuda == $anoActual && $mesDeuda < $mesActual)) {
                    $deudasParaMostrar[] = $deuda;
                }
                // Incluir deuda del mes actual solo si ya venció
                elseif ($anoDeuda == $anoActual && $mesDeuda == $mesActual && $diaActual > $primerDiaVencimiento) {
                    $deudasParaMostrar[] = $deuda;
                }
                // No incluir deudas de meses futuros
            }
        }
        
        return $deudasParaMostrar;
    }
}
