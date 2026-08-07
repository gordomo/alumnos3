<?php

namespace App\Service;

use App\Entity\AlumnoCursoHistorico;
use App\Entity\AreaEvaluacion;
use App\Entity\Calificacion;
use App\Entity\PeriodoAcademico;
use App\Repository\AreaEvaluacionRepository;
use App\Repository\AsistenciaAlumnosRepository;
use App\Repository\CalificacionRepository;
use App\Repository\PeriodoAcademicoRepository;

/**
 * Arma la libreta de un alumno en un curso: la grilla de áreas por período.
 *
 * Nada de esto se carga a mano. Cada celda es el promedio de las evaluaciones de esa área en
 * ese período, y se calcula con el mismo PromedioCalificacionService que usa el resto del
 * sistema, así que la libreta no puede decir algo distinto del boletín.
 *
 * Un instituto sin áreas o sin períodos igual tiene libreta: la parte que falte queda vacía y
 * la pantalla lista las evaluaciones sueltas.
 */
class LibretaService
{
    public function __construct(
        private CalificacionRepository $calificacionRepository,
        private AreaEvaluacionRepository $areaRepository,
        private PeriodoAcademicoRepository $periodoRepository,
        private AsistenciaAlumnosRepository $asistenciaRepository,
        private PromedioCalificacionService $promedioService,
        private InstitutoTimezoneService $institutoTimezoneService
    ) {
    }

    /**
     * @return array{
     *     alumno: \App\Entity\Alumno,
     *     curso: \App\Entity\Curso,
     *     historico: AlumnoCursoHistorico,
     *     ano: int,
     *     areas: AreaEvaluacion[],
     *     periodos: PeriodoAcademico[],
     *     periodosCursada: PeriodoAcademico[],
     *     periodosExamen: PeriodoAcademico[],
     *     grilla: array,
     *     sinArea: Calificacion[],
     *     asistencia: array,
     *     resumen: array,
     *     usaGrilla: bool
     * }
     */
    public function construir(AlumnoCursoHistorico $historico): array
    {
        $alumno = $historico->getAlumno();
        $curso = $historico->getCurso();
        $instituto = $alumno->getInstituto();

        $areas = $this->areaRepository->findByInstituto($instituto);
        $periodos = $this->periodoRepository->findByInstituto($instituto);

        $calificaciones = $this->calificacionRepository->findByHistorico($historico);

        // Índice calificación por área y período, para llenar la grilla sin recorrer la lista
        // completa en cada celda.
        $porAreaYPeriodo = [];
        $sinArea = [];
        foreach ($calificaciones as $calificacion) {
            $evaluacion = $calificacion->getEvaluacion();
            if (!$evaluacion) {
                continue;
            }

            $area = $evaluacion->getArea();
            $periodo = $evaluacion->getPeriodo();

            // Sin área no hay fila donde ponerla: se listan aparte, pero siguen contando para
            // el promedio general del curso.
            if (!$area) {
                $sinArea[] = $calificacion;
                continue;
            }

            $clavePeriodo = $periodo ? $periodo->getId() : 0;
            $porAreaYPeriodo[$area->getId()][$clavePeriodo][] = $calificacion;
        }

        $criterio = $this->criterioDelInstituto($historico);

        $grilla = [];
        foreach ($areas as $area) {
            $fila = ['area' => $area, 'celdas' => [], 'resumenArea' => null];

            $deLaArea = [];
            foreach ($periodos as $periodo) {
                $delPeriodo = $porAreaYPeriodo[$area->getId()][$periodo->getId()] ?? [];
                $deLaArea = array_merge($deLaArea, $delPeriodo);

                $fila['celdas'][$periodo->getId()] = $delPeriodo
                    ? $this->promedioService->resumir($delPeriodo, $criterio)
                    : null;
            }

            // Evaluaciones del área que no cayeron en ningún período.
            $sueltas = $porAreaYPeriodo[$area->getId()][0] ?? [];
            $deLaArea = array_merge($deLaArea, $sueltas);
            $fila['sinPeriodo'] = $sueltas ? $this->promedioService->resumir($sueltas, $criterio) : null;

            $fila['resumenArea'] = $deLaArea ? $this->promedioService->resumir($deLaArea, $criterio) : null;
            $grilla[] = $fila;
        }

        return [
            'alumno' => $alumno,
            'curso' => $curso,
            'historico' => $historico,
            'ano' => $this->anoDeLaLibreta($historico),
            'areas' => $areas,
            'periodos' => $periodos,
            'periodosCursada' => array_values(array_filter($periodos, fn(PeriodoAcademico $p) => !$p->esExamen())),
            'periodosExamen' => array_values(array_filter($periodos, fn(PeriodoAcademico $p) => $p->esExamen())),
            'grilla' => $grilla,
            'sinArea' => $sinArea,
            'asistencia' => $this->asistenciaPorPeriodo($historico, $periodos),
            // El resumen general sale de todas las calificaciones, con área o sin ella, así
            // que coincide con el del boletín y con el del cierre de curso.
            'resumen' => $this->promedioService->resumir($calificaciones, $criterio),
            'usaGrilla' => $areas !== [] && $periodos !== [],
        ];
    }

    /**
     * Presentes y ausentes de cada período, y el porcentaje de asistencia.
     *
     * @param PeriodoAcademico[] $periodos
     * @return array<int, array{presentes: int, ausentes: int, total: int, porcentaje: float|null}>
     */
    private function asistenciaPorPeriodo(AlumnoCursoHistorico $historico, array $periodos): array
    {
        $alumno = $historico->getAlumno();
        $curso = $historico->getCurso();

        if (!$periodos || !$curso) {
            return [];
        }

        [$desde, $hasta] = $this->rangoDelHistorico($historico);
        $porMes = $this->asistenciaRepository->contarPorMes($alumno, $curso, $desde, $hasta);

        $resultado = [];
        foreach ($periodos as $periodo) {
            $presentes = 0;
            $ausentes = 0;

            foreach ($porMes as $mes => $conteo) {
                if ($periodo->contieneMes((int) $mes)) {
                    $presentes += $conteo['presentes'];
                    $ausentes += $conteo['ausentes'];
                }
            }

            $total = $presentes + $ausentes;
            $resultado[$periodo->getId()] = [
                'presentes' => $presentes,
                'ausentes' => $ausentes,
                'total' => $total,
                'porcentaje' => $total > 0 ? round(($presentes / $total) * 100, 1) : null,
            ];
        }

        return $resultado;
    }

    /**
     * Desde cuándo y hasta cuándo cuenta la asistencia de esta inscripción.
     *
     * @return array{0: \DateTimeInterface, 1: \DateTimeInterface}
     */
    private function rangoDelHistorico(AlumnoCursoHistorico $historico): array
    {
        $instituto = $historico->getAlumno()->getInstituto();

        $desde = $historico->getFechaAlta()
            ?: ($historico->getFechaInicioPeriodo() ?: new \DateTime('2000-01-01'));

        // Si el alumno sigue inscripto, hasta hoy. Si se dio de baja, hasta la baja.
        $hasta = $historico->getFechaBaja()
            ?: $this->institutoTimezoneService->getNowForInstituto($instituto);

        return [$desde, $hasta];
    }

    /**
     * El año que encabeza la libreta: el del inicio del curso según el snapshot de la
     * inscripción, con la fecha de alta como respaldo.
     */
    private function anoDeLaLibreta(AlumnoCursoHistorico $historico): int
    {
        $fecha = $historico->getFechaInicioPeriodo() ?: $historico->getFechaAlta();

        if ($fecha) {
            return (int) $fecha->format('Y');
        }

        return (int) $this->institutoTimezoneService
            ->getNowForInstituto($historico->getAlumno()->getInstituto())
            ->format('Y');
    }

    private function criterioDelInstituto(AlumnoCursoHistorico $historico): string
    {
        $curso = $historico->getCurso();
        $instituto = $curso ? $curso->getInstituto() : null;
        $configuracion = $instituto ? $instituto->getConfiguracion() : null;

        return $configuracion
            ? $configuracion->getCriterioAprobacionNotas()
            : \App\Entity\InstitutoConfiguracion::CRITERIO_PROMEDIO;
    }
}
