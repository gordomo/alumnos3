<?php

namespace App\Entity;

use App\Repository\CalificacionRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * La nota de un alumno en una evaluación.
 *
 * Cuelga de AlumnoCursoHistorico y no de Alumno porque la nota pertenece a una
 * inscripción concreta, no a la persona: es la misma entidad sobre la que opera el cierre
 * de curso y donde vive el estado de aprobación (motivoBaja). Un alumno reinscripto no
 * arrastra las notas del período anterior.
 *
 * valorNumerico y concepto son ambos nullable y NO son mutuamente excluyentes: con la
 * escala en modo 'ambos' una nota es "8 (Muy bueno)" y se llenan los dos. Se usan dos
 * columnas tipadas en lugar de un valor polimórfico para poder promediar en SQL.
 * Fila ausente = sin nota, igual que en asistencias.
 *
 * @ORM\Entity(repositoryClass=CalificacionRepository::class)
 * @ORM\Table(
 *     name="calificacion",
 *     uniqueConstraints={
 *         @ORM\UniqueConstraint(name="uniq_calificacion_eval_hist", columns={"evaluacion_id", "alumno_curso_historico_id"})
 *     },
 *     indexes={
 *         @ORM\Index(name="idx_calificacion_hist", columns={"alumno_curso_historico_id"}),
 *         @ORM\Index(name="idx_calificacion_instituto", columns={"instituto_id"})
 *     }
 * )
 */
class Calificacion
{
    /**
     * @ORM\Id
     * @ORM\GeneratedValue
     * @ORM\Column(type="integer")
     */
    private $id;

    /**
     * @ORM\ManyToOne(targetEntity=Instituto::class)
     * @ORM\JoinColumn(nullable=false)
     */
    private $instituto;

    /**
     * @ORM\ManyToOne(targetEntity=Evaluacion::class, inversedBy="calificaciones")
     * @ORM\JoinColumn(nullable=false)
     */
    private $evaluacion;

    /**
     * @ORM\ManyToOne(targetEntity=AlumnoCursoHistorico::class, inversedBy="calificaciones")
     * @ORM\JoinColumn(name="alumno_curso_historico_id", nullable=false)
     */
    private $cursoHistorico;

    /**
     * @ORM\Column(type="decimal", precision=6, scale=2, nullable=true)
     */
    private $valorNumerico;

    /**
     * @ORM\ManyToOne(targetEntity=ConceptoCalificacion::class)
     * @ORM\JoinColumn(nullable=true)
     */
    private $concepto;

    /**
     * El alumno no rindió. Se excluye del promedio en lugar de contar como cero.
     *
     * @ORM\Column(type="boolean", options={"default": false})
     */
    private $ausente = false;

    /**
     * @ORM\Column(type="text", nullable=true)
     */
    private $observaciones;

    /**
     * @ORM\ManyToOne(targetEntity=User::class)
     * @ORM\JoinColumn(nullable=true, onDelete="SET NULL")
     */
    private $cargadoPor;

    /**
     * @ORM\Column(type="datetime")
     */
    private $createdAt;

    /**
     * @ORM\Column(type="datetime", nullable=true)
     */
    private $updatedAt;

    public function __construct()
    {
        $this->createdAt = new \DateTime();
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

    public function getEvaluacion(): ?Evaluacion
    {
        return $this->evaluacion;
    }

    public function setEvaluacion(?Evaluacion $evaluacion): self
    {
        $this->evaluacion = $evaluacion;
        return $this;
    }

    public function getCursoHistorico(): ?AlumnoCursoHistorico
    {
        return $this->cursoHistorico;
    }

    public function setCursoHistorico(?AlumnoCursoHistorico $cursoHistorico): self
    {
        $this->cursoHistorico = $cursoHistorico;
        return $this;
    }

    /**
     * Atajo de lectura: el alumno se alcanza a través del histórico.
     */
    public function getAlumno(): ?Alumno
    {
        return $this->cursoHistorico ? $this->cursoHistorico->getAlumno() : null;
    }

    /**
     * Doctrine devuelve decimal como string, así que se castea acá.
     */
    public function getValorNumerico(): ?float
    {
        return $this->valorNumerico === null ? null : (float) $this->valorNumerico;
    }

    public function setValorNumerico(?float $valorNumerico): self
    {
        $this->valorNumerico = $valorNumerico;
        return $this;
    }

    public function getConcepto(): ?ConceptoCalificacion
    {
        return $this->concepto;
    }

    public function setConcepto(?ConceptoCalificacion $concepto): self
    {
        $this->concepto = $concepto;
        return $this;
    }

    public function isAusente(): bool
    {
        return (bool) $this->ausente;
    }

    public function getAusente(): bool
    {
        return (bool) $this->ausente;
    }

    public function setAusente(bool $ausente): self
    {
        $this->ausente = $ausente;
        return $this;
    }

    public function getObservaciones(): ?string
    {
        return $this->observaciones;
    }

    public function setObservaciones(?string $observaciones): self
    {
        $this->observaciones = $observaciones;
        return $this;
    }

    public function getCargadoPor(): ?User
    {
        return $this->cargadoPor;
    }

    public function setCargadoPor(?User $cargadoPor): self
    {
        $this->cargadoPor = $cargadoPor;
        return $this;
    }

    public function getCreatedAt(): ?\DateTimeInterface
    {
        return $this->createdAt;
    }

    public function setCreatedAt(\DateTimeInterface $createdAt): self
    {
        $this->createdAt = $createdAt;
        return $this;
    }

    public function getUpdatedAt(): ?\DateTimeInterface
    {
        return $this->updatedAt;
    }

    public function setUpdatedAt(?\DateTimeInterface $updatedAt): self
    {
        $this->updatedAt = $updatedAt;
        return $this;
    }

    /**
     * ¿Tiene algún valor cargado? Una fila sin nada es equivalente a no tener nota.
     */
    public function tieneValor(): bool
    {
        return $this->valorNumerico !== null || $this->concepto !== null || $this->isAusente();
    }

    /**
     * Valor numérico para promediar: el propio, o la equivalencia del concepto.
     */
    public function getValorParaPromedio(): ?float
    {
        if ($this->isAusente()) {
            return null;
        }

        if ($this->valorNumerico !== null) {
            return (float) $this->valorNumerico;
        }

        return $this->concepto ? $this->concepto->getEquivalenteNumerico() : null;
    }

    /**
     * Texto para mostrar la nota tal como se cargó.
     */
    public function getEtiqueta(): string
    {
        if ($this->isAusente()) {
            return 'Ausente';
        }

        $partes = [];
        if ($this->valorNumerico !== null) {
            $partes[] = rtrim(rtrim(number_format((float) $this->valorNumerico, 2, ',', '.'), '0'), ',');
        }
        if ($this->concepto) {
            $partes[] = $this->concepto->getNombre();
        }

        return $partes ? implode(' — ', $partes) : '-';
    }
}
