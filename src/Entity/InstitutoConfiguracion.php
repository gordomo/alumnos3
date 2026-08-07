<?php

namespace App\Entity;

use App\Repository\InstitutoConfiguracionRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;
use App\Entity\DescuentoPromocional;

/**
 * @ORM\Entity(repositoryClass=InstitutoConfiguracionRepository::class)
 */
class InstitutoConfiguracion
{
    /**
     * @ORM\Id
     * @ORM\GeneratedValue
     * @ORM\Column(type="integer")
     */
    private $id;

    /**
     * @ORM\OneToOne(targetEntity=Instituto::class, inversedBy="configuracion")
     * @ORM\JoinColumn(nullable=false)
     */
    private $instituto;

    /**
     * @ORM\Column(type="decimal", precision=5, scale=2, nullable=true)
     * @Assert\Range(
     *      min = 0,
     *      max = 100,
     *      notInRangeMessage = "El porcentaje de descuento debe estar entre {{ min }} y {{ max }}"
     * )
     */
    private $descuentoEfectivo;

    /**
     * @ORM\Column(type="decimal", precision=5, scale=2, nullable=true)
     * @Assert\Range(
     *      min = 0,
     *      max = 100,
     *      notInRangeMessage = "El porcentaje de descuento debe estar entre {{ min }} y {{ max }}"
     * )
     */
    private $descuentoHermanos;
    
    /**
     * @ORM\Column(type="boolean")
     */
    private $deshabilitarDescuentosEnDeuda = false;
    
    /**
     * @ORM\Column(type="string", length=50, options={"default": "interes_primero"})
     */
    private $ordenCalculoInteresesDescuentos = 'interes_primero';
    
    /**
     * @ORM\OneToMany(targetEntity=Vencimiento::class, mappedBy="configuracion", orphanRemoval=true, cascade={"persist"})
     * @ORM\OrderBy({"orden" = "ASC"})
     */
    private $vencimientos;
    
    /**
     * @ORM\OneToMany(targetEntity=DescuentoPromocional::class, mappedBy="configuracion", orphanRemoval=true, cascade={"persist", "remove"})
     */
    private $descuentosPromocionales;
    
    /**
     * @ORM\Column(type="boolean", options={"default": false})
     */
    private $enviarFacturasRecibos = false;
    
    /**
     * @ORM\Column(type="boolean", options={"default": false})
     */
    private $enviarRecordatoriosDeudas = false;
    
    /**
     * @ORM\Column(type="boolean", options={"default": false})
     */
    private $enviarRecordatorioEnDiaVencimiento = false;
    
    /**
     * @ORM\Column(type="text", nullable=true)
     */
    private $textoPersonalizadoEmail;

    /**
     * A quién se le manda cada notificación del alumno: 'alumno', 'tutor' o 'ambos'.
     *
     * El default es 'alumno' porque es lo que hacía el sistema antes de que esto fuera
     * configurable, así que los institutos existentes no cambian de comportamiento.
     *
     * @ORM\Column(type="string", length=20, options={"default": "alumno"})
     */
    private $notificarA = 'alumno';

    /**
     * Días del mes en los que se manda el recordatorio de deuda, separados por coma
     * (ej: "20,28"). Vacío significa que esta regla no se aplica.
     *
     * @ORM\Column(type="string", length=60, nullable=true)
     */
    private $recordatorioDiasMes;

    /**
     * Cada cuántos días se repite el recordatorio de una misma cuota impaga.
     * Null o 0 apaga la repetición. Antes estaba fijo en 3 dentro del servicio.
     *
     * @ORM\Column(type="integer", nullable=true, options={"default": 3})
     */
    private $recordatorioCadaDias = 3;

    /**
     * Mínimo de cuotas vencidas que tiene que tener el alumno para que se le mande el
     * recordatorio. Con 1 se avisa desde la primera, que es lo que se pidió.
     *
     * @ORM\Column(type="integer", options={"default": 1})
     */
    private $recordatorioMinCuotasVencidas = 1;

    /**
     * Zona horaria del instituto para fechas y "hoy" (ej: America/Argentina/Buenos_Aires).
     * Si es null, se usa APP_TIMEZONE o la del servidor.
     *
     * @ORM\Column(type="string", length=50, nullable=true)
     */
    private $timezone;

    /**
     * Formato de fecha para mostrar en la app (ej: d/m/Y, m/d/Y, Y-m-d).
     * Si es null, se usa d/m/Y.
     *
     * @ORM\Column(type="string", length=20, nullable=true)
     */
    private $dateFormat;

    /**
     * Porcentaje mínimo de asistencia requerido para aprobar un curso.
     * Si es null, no se verifica asistencia al finalizar el curso.
     *
     * @ORM\Column(type="decimal", precision=5, scale=2, nullable=true)
     * @Assert\Range(
     *      min = 0,
     *      max = 100,
     *      notInRangeMessage = "El porcentaje debe estar entre {{ min }} y {{ max }}"
     * )
     */
    private $porcentajeAsistenciaAprobacion;

    /**
     * Si es true, el alumno debe tener todas las cuotas del curso pagadas para aprobar.
     *
     * @ORM\Column(type="boolean", options={"default": false})
     */
    private $requierePagoTotalParaAprobar = false;

    /**
     * Modo de calificación del instituto:
     * - 'ninguno'    : el instituto no usa calificaciones (default, retrocompatible)
     * - 'numerico'   : nota numérica entre notaMinima y notaMaxima
     * - 'conceptual' : uno de los ConceptoCalificacion configurados
     * - 'ambos'      : nota numérica y concepto en la misma calificación
     *
     * Es un enum en vez de un booleano aparte para que no exista el estado inconsistente
     * "no usa notas pero tiene modo numérico".
     *
     * @ORM\Column(type="string", length=20, options={"default": "ninguno"})
     */
    private $modoCalificacion = self::MODO_NINGUNO;

    /**
     * @ORM\Column(type="decimal", precision=6, scale=2, nullable=true)
     */
    private $notaMinima;

    /**
     * @ORM\Column(type="decimal", precision=6, scale=2, nullable=true)
     */
    private $notaMaxima;

    /**
     * Nota mínima para aprobar, en escala numérica.
     *
     * @ORM\Column(type="decimal", precision=6, scale=2, nullable=true)
     */
    private $notaAprobacion;

    /**
     * Si es true, las calificaciones se suman como tercer criterio al cerrar un curso.
     * Apagado por default: un instituto puede usar notas de forma informativa.
     *
     * @ORM\Column(type="boolean", options={"default": false})
     */
    private $notasInfluyenAprobacion = false;

    /**
     * Cómo se decide si las notas aprueban:
     * - 'promedio' : el promedio ponderado alcanza notaAprobacion (default)
     * - 'todas'    : todas las evaluaciones que cuentan deben estar aprobadas
     *
     * @ORM\Column(type="string", length=20, options={"default": "promedio"})
     */
    private $criterioAprobacionNotas = self::CRITERIO_PROMEDIO;

    /**
     * @ORM\OneToMany(targetEntity=ConceptoCalificacion::class, mappedBy="configuracion", cascade={"persist"})
     * @ORM\OrderBy({"orden" = "ASC"})
     */
    private $conceptosCalificacion;

    /**
     * Trimestres, bimestres o mesas de examen del instituto. Vacío significa que el
     * instituto no divide el año, y todo funciona como antes de que esto existiera.
     *
     * @ORM\OneToMany(targetEntity=PeriodoAcademico::class, mappedBy="configuracion", cascade={"persist"})
     * @ORM\OrderBy({"orden" = "ASC"})
     */
    private $periodosAcademicos;

    /**
     * Ejes sobre los que se califica: Writing, Listening, Speaking. Vacío significa que el
     * instituto no separa por áreas y la libreta lista las evaluaciones sueltas.
     *
     * @ORM\OneToMany(targetEntity=AreaEvaluacion::class, mappedBy="configuracion", cascade={"persist"})
     * @ORM\OrderBy({"orden" = "ASC"})
     */
    private $areasEvaluacion;

    /**
     * Color principal de la libreta, en hexadecimal (#rrggbb). Null usa el color por default.
     *
     * @ORM\Column(type="string", length=7, nullable=true)
     */
    private $colorPrimario;

    /**
     * Color secundario de la libreta, para los fondos suaves.
     *
     * @ORM\Column(type="string", length=7, nullable=true)
     */
    private $colorSecundario;

    /**
     * Si es true, se cobrará una cuota de inscripción anual a todos los alumnos.
     *
     * @ORM\Column(type="boolean", options={"default": false})
     */
    private $cobrarCuotaInscripcionAnual = false;

    /**
     * Monto de la cuota de inscripción anual.
     *
     * @ORM\Column(type="decimal", precision=10, scale=2, nullable=true)
     */
    private $montoCuotaInscripcionAnual;

    /**
     * Mes en que se cobra la cuota de inscripción anual (1-12).
     *
     * @ORM\Column(type="integer", nullable=true)
     */
    private $mesCobroCuotaInscripcionAnual;

    public const MODO_NINGUNO = 'ninguno';
    public const MODO_NUMERICO = 'numerico';
    public const MODO_CONCEPTUAL = 'conceptual';
    public const MODO_AMBOS = 'ambos';

    public const CRITERIO_PROMEDIO = 'promedio';
    public const CRITERIO_TODAS = 'todas';

    public function __construct()
    {
        $this->vencimientos = new ArrayCollection();
        $this->descuentosPromocionales = new ArrayCollection();
        $this->conceptosCalificacion = new ArrayCollection();
        $this->periodosAcademicos = new ArrayCollection();
        $this->areasEvaluacion = new ArrayCollection();
    }

    public function getModoCalificacion(): string
    {
        return $this->modoCalificacion ?: self::MODO_NINGUNO;
    }

    public function setModoCalificacion(string $modoCalificacion): self
    {
        $validos = [self::MODO_NINGUNO, self::MODO_NUMERICO, self::MODO_CONCEPTUAL, self::MODO_AMBOS];
        $this->modoCalificacion = in_array($modoCalificacion, $validos, true)
            ? $modoCalificacion
            : self::MODO_NINGUNO;

        return $this;
    }

    /**
     * ¿El instituto califica? Con 'ninguno' toda la feature queda invisible.
     */
    public function usaCalificaciones(): bool
    {
        return $this->getModoCalificacion() !== self::MODO_NINGUNO;
    }

    public function usaNotaNumerica(): bool
    {
        return in_array($this->getModoCalificacion(), [self::MODO_NUMERICO, self::MODO_AMBOS], true);
    }

    public function usaConcepto(): bool
    {
        return in_array($this->getModoCalificacion(), [self::MODO_CONCEPTUAL, self::MODO_AMBOS], true);
    }

    public function getNotaMinima(): ?float
    {
        return $this->notaMinima === null ? null : (float) $this->notaMinima;
    }

    public function setNotaMinima(?float $notaMinima): self
    {
        $this->notaMinima = $notaMinima;
        return $this;
    }

    public function getNotaMaxima(): ?float
    {
        return $this->notaMaxima === null ? null : (float) $this->notaMaxima;
    }

    public function setNotaMaxima(?float $notaMaxima): self
    {
        $this->notaMaxima = $notaMaxima;
        return $this;
    }

    public function getNotaAprobacion(): ?float
    {
        return $this->notaAprobacion === null ? null : (float) $this->notaAprobacion;
    }

    public function setNotaAprobacion(?float $notaAprobacion): self
    {
        $this->notaAprobacion = $notaAprobacion;
        return $this;
    }

    public function isNotasInfluyenAprobacion(): bool
    {
        return (bool) $this->notasInfluyenAprobacion;
    }

    public function getNotasInfluyenAprobacion(): bool
    {
        return (bool) $this->notasInfluyenAprobacion;
    }

    public function setNotasInfluyenAprobacion(bool $notasInfluyenAprobacion): self
    {
        $this->notasInfluyenAprobacion = $notasInfluyenAprobacion;
        return $this;
    }

    public function getCriterioAprobacionNotas(): string
    {
        return $this->criterioAprobacionNotas ?: self::CRITERIO_PROMEDIO;
    }

    public function setCriterioAprobacionNotas(string $criterio): self
    {
        $this->criterioAprobacionNotas = in_array($criterio, [self::CRITERIO_PROMEDIO, self::CRITERIO_TODAS], true)
            ? $criterio
            : self::CRITERIO_PROMEDIO;

        return $this;
    }

    /**
     * @return Collection<int, ConceptoCalificacion>
     */
    public function getConceptosCalificacion(): Collection
    {
        return $this->conceptosCalificacion;
    }

    /**
     * Solo los conceptos vigentes, para ofrecer en los formularios de carga.
     *
     * @return ConceptoCalificacion[]
     */
    public function getConceptosCalificacionActivos(): array
    {
        $activos = [];
        foreach ($this->conceptosCalificacion as $concepto) {
            if ($concepto->isActivo()) {
                $activos[] = $concepto;
            }
        }

        return $activos;
    }

    public function addConceptoCalificacion(ConceptoCalificacion $concepto): self
    {
        if (!$this->conceptosCalificacion->contains($concepto)) {
            $this->conceptosCalificacion[] = $concepto;
            $concepto->setConfiguracion($this);
        }

        return $this;
    }

    public function removeConceptoCalificacion(ConceptoCalificacion $concepto): self
    {
        $this->conceptosCalificacion->removeElement($concepto);

        return $this;
    }

    /**
     * @return Collection<int, PeriodoAcademico>
     */
    public function getPeriodosAcademicos(): Collection
    {
        return $this->periodosAcademicos;
    }

    /**
     * Solo los períodos vigentes, para ofrecer en los formularios.
     *
     * @return PeriodoAcademico[]
     */
    public function getPeriodosAcademicosActivos(): array
    {
        $activos = [];
        foreach ($this->periodosAcademicos as $periodo) {
            if ($periodo->isActivo()) {
                $activos[] = $periodo;
            }
        }

        return $activos;
    }

    public function usaPeriodos(): bool
    {
        return count($this->getPeriodosAcademicosActivos()) > 0;
    }

    public function addPeriodoAcademico(PeriodoAcademico $periodo): self
    {
        if (!$this->periodosAcademicos->contains($periodo)) {
            $this->periodosAcademicos[] = $periodo;
            $periodo->setConfiguracion($this);
        }

        return $this;
    }

    public function removePeriodoAcademico(PeriodoAcademico $periodo): self
    {
        $this->periodosAcademicos->removeElement($periodo);

        return $this;
    }

    /**
     * @return Collection<int, AreaEvaluacion>
     */
    public function getAreasEvaluacion(): Collection
    {
        return $this->areasEvaluacion;
    }

    /**
     * @return AreaEvaluacion[]
     */
    public function getAreasEvaluacionActivas(): array
    {
        $activas = [];
        foreach ($this->areasEvaluacion as $area) {
            if ($area->isActivo()) {
                $activas[] = $area;
            }
        }

        return $activas;
    }

    public function usaAreas(): bool
    {
        return count($this->getAreasEvaluacionActivas()) > 0;
    }

    public function addAreaEvaluacion(AreaEvaluacion $area): self
    {
        if (!$this->areasEvaluacion->contains($area)) {
            $this->areasEvaluacion[] = $area;
            $area->setConfiguracion($this);
        }

        return $this;
    }

    public function removeAreaEvaluacion(AreaEvaluacion $area): self
    {
        $this->areasEvaluacion->removeElement($area);

        return $this;
    }

    public function getColorPrimario(): ?string
    {
        return $this->colorPrimario;
    }

    public function setColorPrimario(?string $color): self
    {
        $this->colorPrimario = self::normalizarColor($color);
        return $this;
    }

    public function getColorSecundario(): ?string
    {
        return $this->colorSecundario;
    }

    public function setColorSecundario(?string $color): self
    {
        $this->colorSecundario = self::normalizarColor($color);
        return $this;
    }

    /**
     * Deja el color en #rrggbb minúsculas, o null si no es un hexadecimal válido.
     *
     * Se valida acá y no en el template porque estos valores se interpolan dentro de un
     * atributo style: cualquier otra cosa sería inyectar CSS arbitrario en la página.
     */
    private static function normalizarColor(?string $color): ?string
    {
        if ($color === null) {
            return null;
        }

        $color = trim($color);
        if ($color === '') {
            return null;
        }

        if ($color[0] !== '#') {
            $color = '#' . $color;
        }

        // Se acepta la forma corta #abc y se expande, para no rechazar algo válido en CSS.
        if (preg_match('/^#([0-9a-fA-F]{3})$/', $color, $m) === 1) {
            $color = '#' . $m[1][0] . $m[1][0] . $m[1][1] . $m[1][1] . $m[1][2] . $m[1][2];
        }

        return preg_match('/^#[0-9a-fA-F]{6}$/', $color) === 1 ? strtolower($color) : null;
    }

    public function getId(): ?int
    {
        return $this->id;
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

    public function getDescuentoEfectivo(): ?float
    {
        return $this->descuentoEfectivo;
    }

    public function setDescuentoEfectivo(?float $descuentoEfectivo): self
    {
        $this->descuentoEfectivo = $descuentoEfectivo;
        return $this;
    }

    public function getDescuentoHermanos(): ?float
    {
        return $this->descuentoHermanos;
    }

    public function setDescuentoHermanos(?float $descuentoHermanos): self
    {
        $this->descuentoHermanos = $descuentoHermanos;
        return $this;
    }

    public function getDeshabilitarDescuentosEnDeuda(): bool
    {
        return $this->deshabilitarDescuentosEnDeuda;
    }

    public function setDeshabilitarDescuentosEnDeuda(bool $deshabilitarDescuentosEnDeuda): self
    {
        $this->deshabilitarDescuentosEnDeuda = $deshabilitarDescuentosEnDeuda;
        return $this;
    }
    
    public function getOrdenCalculoInteresesDescuentos(): string
    {
        return $this->ordenCalculoInteresesDescuentos ?? 'interes_primero';
    }
    
    public function setOrdenCalculoInteresesDescuentos(string $ordenCalculoInteresesDescuentos): self
    {
        $this->ordenCalculoInteresesDescuentos = $ordenCalculoInteresesDescuentos;
        return $this;
    }
    
    /**
     * @return Collection<int, Vencimiento>
     */
    public function getVencimientos(): Collection
    {
        return $this->vencimientos;
    }

    public function addVencimiento(Vencimiento $vencimiento): self
    {
        if (!$this->vencimientos->contains($vencimiento)) {
            $this->vencimientos[] = $vencimiento;
            $vencimiento->setConfiguracion($this);
        }
        return $this;
    }

    public function removeVencimiento(Vencimiento $vencimiento): self
    {
        if ($this->vencimientos->removeElement($vencimiento)) {
            // set the owning side to null (unless already changed)
            if ($vencimiento->getConfiguracion() === $this) {
                $vencimiento->setConfiguracion(null);
            }
        }
        return $this;
    }

    /**
     * @return Collection<int, DescuentoPromocional>
     */
    public function getDescuentosPromocionales(): Collection
    {
        return $this->descuentosPromocionales;
    }

    public function addDescuentosPromocionale(DescuentoPromocional $descuentosPromocionale): self
    {
        if (!$this->descuentosPromocionales->contains($descuentosPromocionale)) {
            $this->descuentosPromocionales[] = $descuentosPromocionale;
            $descuentosPromocionale->setConfiguracion($this);
        }

        return $this;
    }

    public function removeDescuentosPromocionale(DescuentoPromocional $descuentosPromocionale): self
    {
        if ($this->descuentosPromocionales->removeElement($descuentosPromocionale)) {
            // set the owning side to null (unless already changed)
            if ($descuentosPromocional->getConfiguracion() === $this) {
                $descuentosPromocional->setConfiguracion(null);
            }
        }

        return $this;
    }
    
    public function getEnviarFacturasRecibos(): bool
    {
        return $this->enviarFacturasRecibos;
    }
    
    public function setEnviarFacturasRecibos(bool $enviarFacturasRecibos): self
    {
        $this->enviarFacturasRecibos = $enviarFacturasRecibos;
        return $this;
    }
    
    public function getEnviarRecordatoriosDeudas(): bool
    {
        return $this->enviarRecordatoriosDeudas;
    }
    
    public function setEnviarRecordatoriosDeudas(bool $enviarRecordatoriosDeudas): self
    {
        $this->enviarRecordatoriosDeudas = $enviarRecordatoriosDeudas;
        return $this;
    }
    
    public function getEnviarRecordatorioEnDiaVencimiento(): bool
    {
        return $this->enviarRecordatorioEnDiaVencimiento;
    }
    
    public function setEnviarRecordatorioEnDiaVencimiento(bool $enviarRecordatorioEnDiaVencimiento): self
    {
        $this->enviarRecordatorioEnDiaVencimiento = $enviarRecordatorioEnDiaVencimiento;
        return $this;
    }
    
    public function getTextoPersonalizadoEmail(): ?string
    {
        return $this->textoPersonalizadoEmail;
    }
    
    public function setTextoPersonalizadoEmail(?string $textoPersonalizadoEmail): self
    {
        $this->textoPersonalizadoEmail = $textoPersonalizadoEmail;
        return $this;
    }

    public function getNotificarA(): string
    {
        return $this->notificarA ?: 'alumno';
    }

    public function setNotificarA(?string $notificarA): self
    {
        // Cualquier valor que no sea uno de los tres cae en 'alumno', que es el default
        // histórico: es preferible avisarle a alguien que no avisarle a nadie.
        $this->notificarA = in_array($notificarA, ['alumno', 'tutor', 'ambos'], true)
            ? $notificarA
            : 'alumno';

        return $this;
    }

    public function getRecordatorioDiasMes(): ?string
    {
        return $this->recordatorioDiasMes;
    }

    public function setRecordatorioDiasMes(?string $recordatorioDiasMes): self
    {
        $this->recordatorioDiasMes = $recordatorioDiasMes;
        return $this;
    }

    /**
     * Los días del mes ya normalizados: sin repetidos, ordenados y dentro de 1..31.
     *
     * @return int[]
     */
    public function getRecordatorioDiasMesArray(): array
    {
        if (!$this->recordatorioDiasMes) {
            return [];
        }

        $dias = [];
        foreach (explode(',', $this->recordatorioDiasMes) as $parte) {
            $dia = (int) trim($parte);
            if ($dia >= 1 && $dia <= 31 && !in_array($dia, $dias, true)) {
                $dias[] = $dia;
            }
        }
        sort($dias);

        return $dias;
    }

    public function getRecordatorioCadaDias(): ?int
    {
        return $this->recordatorioCadaDias;
    }

    public function setRecordatorioCadaDias(?int $recordatorioCadaDias): self
    {
        $this->recordatorioCadaDias = $recordatorioCadaDias !== null && $recordatorioCadaDias > 0
            ? $recordatorioCadaDias
            : null;

        return $this;
    }

    public function getRecordatorioMinCuotasVencidas(): int
    {
        return max(1, (int) $this->recordatorioMinCuotasVencidas);
    }

    public function setRecordatorioMinCuotasVencidas(?int $minimo): self
    {
        $this->recordatorioMinCuotasVencidas = $minimo !== null && $minimo > 0 ? $minimo : 1;
        return $this;
    }

    public function getTimezone(): ?string
    {
        return $this->timezone;
    }

    public function setTimezone(?string $timezone): self
    {
        $this->timezone = $timezone;
        return $this;
    }

    public function getDateFormat(): ?string
    {
        return $this->dateFormat;
    }

    public function setDateFormat(?string $dateFormat): self
    {
        $this->dateFormat = $dateFormat;
        return $this;
    }

    public function getPorcentajeAsistenciaAprobacion(): ?float
    {
        return $this->porcentajeAsistenciaAprobacion;
    }

    public function setPorcentajeAsistenciaAprobacion(?float $porcentajeAsistenciaAprobacion): self
    {
        $this->porcentajeAsistenciaAprobacion = $porcentajeAsistenciaAprobacion;
        return $this;
    }

    public function getRequierePagoTotalParaAprobar(): bool
    {
        return $this->requierePagoTotalParaAprobar;
    }

    public function setRequierePagoTotalParaAprobar(bool $requierePagoTotalParaAprobar): self
    {
        $this->requierePagoTotalParaAprobar = $requierePagoTotalParaAprobar;
        return $this;
    }

    public function getCobrarCuotaInscripcionAnual(): bool
    {
        return $this->cobrarCuotaInscripcionAnual;
    }

    public function setCobrarCuotaInscripcionAnual(bool $cobrarCuotaInscripcionAnual): self
    {
        $this->cobrarCuotaInscripcionAnual = $cobrarCuotaInscripcionAnual;
        return $this;
    }

    public function getMontoCuotaInscripcionAnual(): ?float
    {
        return $this->montoCuotaInscripcionAnual;
    }

    public function setMontoCuotaInscripcionAnual(?float $montoCuotaInscripcionAnual): self
    {
        $this->montoCuotaInscripcionAnual = $montoCuotaInscripcionAnual;
        return $this;
    }

    public function getMesCobroCuotaInscripcionAnual(): ?int
    {
        return $this->mesCobroCuotaInscripcionAnual;
    }

    public function setMesCobroCuotaInscripcionAnual(?int $mesCobroCuotaInscripcionAnual): self
    {
        $this->mesCobroCuotaInscripcionAnual = $mesCobroCuotaInscripcionAnual;
        return $this;
    }
} 