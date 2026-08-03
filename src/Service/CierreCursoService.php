<?php

namespace App\Service;

use App\Entity\Curso;
use App\Repository\AlumnoCursoHistoricoRepository;
use App\Repository\AsistenciaAlumnosRepository;
use App\Repository\DeudaAlumnoRepository;
use App\Repository\EvaluacionRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Decide, al cerrar un curso, si cada alumno queda "finalizado" o "no finalizado".
 *
 * Esta lógica estaba duplicada literalmente entre CursoController::previewCierreCurso()
 * y CursoController::cerrarCurso(), con el riesgo de que la vista previa mostrara una
 * cosa y el cierre hiciera otra. Vive acá para tener una sola fuente de verdad, y porque
 * es donde se va a sumar el criterio de calificaciones.
 *
 * Los criterios de aprobación se configuran por instituto en InstitutoConfiguracion:
 * porcentaje mínimo de asistencia y/o pago total del curso. Si el instituto no configuró
 * ninguno, todos los alumnos quedan finalizados.
 */
class CierreCursoService
{
    public function __construct(
        private AlumnoCursoHistoricoRepository $historicoRepository,
        private AsistenciaAlumnosRepository $asistenciaRepository,
        private DeudaAlumnoRepository $deudaAlumnoRepository,
        private InstitutoTimezoneService $institutoTimezoneService,
        private EntityManagerInterface $entityManager,
        private PromedioCalificacionService $promedioService,
        private EvaluacionRepository $evaluacionRepository
    ) {
    }

    /**
     * Criterios de aprobación vigentes para el instituto del curso.
     *
     * @return array{porcentajeRequerido: float|null, requierePagoTotal: bool, requiereNotas: bool}
     */
    public function getCriterios(Curso $curso): array
    {
        $config = $curso->getInstituto()->getConfiguracion();

        return [
            'porcentajeRequerido' => $config ? $config->getPorcentajeAsistenciaAprobacion() : null,
            'requierePagoTotal' => $config ? $config->getRequierePagoTotalParaAprobar() : false,
            'requiereNotas' => $this->criterioNotasActivo($curso),
        ];
    }

    /**
     * ¿El criterio de calificaciones está activo para este curso?
     *
     * Tres condiciones, todas necesarias, de modo que la feature nazca apagada:
     *  1. el instituto usa calificaciones (modo distinto de 'ninguno');
     *  2. el administrador prendió explícitamente que las notas influyan;
     *  3. el curso tiene al menos una evaluación que cuente para el promedio.
     *
     * Si alguna falla, las notas no pueden desaprobar a nadie y el cierre se comporta
     * exactamente como antes de que existiera esta feature.
     */
    public function criterioNotasActivo(Curso $curso): bool
    {
        $config = $curso->getInstituto() ? $curso->getInstituto()->getConfiguracion() : null;

        if (!$config || !$config->usaCalificaciones() || !$config->isNotasInfluyenAprobacion()) {
            return false;
        }

        return $this->evaluacionRepository->contarQueCuentanParaPromedio($curso) > 0;
    }

    /**
     * Calcula, sin persistir nada, el estado que le quedaría a cada alumno del curso.
     *
     * Las claves de cada fila son las que consume templates/curso/cerrar.html.twig.
     *
     * @return array<int, array<string, mixed>>
     */
    public function calcularPreview(Curso $curso): array
    {
        [
            'porcentajeRequerido' => $porcentajeRequerido,
            'requierePagoTotal' => $requierePagoTotal,
            'requiereNotas' => $requiereNotas,
        ] = $this->getCriterios($curso);

        $asistenciaPorAlumno = $this->getAsistenciaPorAlumno($curso);
        // Una sola query de notas para todo el curso, cero por alumno.
        $resumenNotas = $requiereNotas ? $this->promedioService->calcularParaCurso($curso) : [];
        // Idem con las deudas pendientes: antes se consultaba por alumno dentro del loop.
        $deudasPendientesPorAlumno = $requierePagoTotal
            ? $this->deudaAlumnoRepository->findPendientesByCursoAgrupadasPorAlumno($curso)
            : [];
        $preview = [];

        foreach ($this->historicoRepository->findByCurso($curso) as $historico) {
            // Las bajas administrativas ya tienen su estado decidido, no se recalculan.
            if ($historico->getMotivoBaja() === 'baja_administrativa') {
                continue;
            }

            $alumno = $historico->getAlumno();
            $datos = $asistenciaPorAlumno[$alumno->getId()] ?? ['total' => 0, 'presentes' => 0];
            $porcentaje = $datos['total'] > 0
                ? round(($datos['presentes'] / $datos['total']) * 100, 1)
                : 0;

            $tienePagoCompleto = true;
            if ($requierePagoTotal) {
                $tienePagoCompleto = empty($deudasPendientesPorAlumno[$alumno->getId()] ?? []);
            }

            $resumen = $requiereNotas
                ? ($resumenNotas[$historico->getId()] ?? $this->promedioService->resumenVacio())
                : null;

            $preview[] = [
                'historico' => $historico,
                'alumno' => $alumno,
                'clasesTotales' => $datos['total'],
                'clasesPresentes' => $datos['presentes'],
                'porcentajeAsistencia' => $porcentaje,
                'estadoPrediccion' => $this->decidirEstado(
                    $porcentajeRequerido,
                    $porcentaje,
                    $requierePagoTotal,
                    $tienePagoCompleto,
                    $resumen ? $resumen['resultado'] : null
                ),
                'tienePagoCompleto' => $tienePagoCompleto,
                'resultadoNotas' => $resumen ? $resumen['resultado'] : null,
                'promedioNotas' => $resumen ? $resumen['promedio'] : null,
            ];
        }

        return $preview;
    }

    /**
     * Cierra el curso: fija el estado de cada alumno y marca el curso como cerrado.
     *
     * Usa calcularPreview() para que lo que se persiste sea exactamente lo que se mostró.
     *
     * @return array{finalizados: int, noFinalizados: int}
     */
    public function cerrar(Curso $curso): array
    {
        $instituto = $curso->getInstituto();
        $fechaActual = $this->institutoTimezoneService->getCurrentDateForInstituto($instituto);

        $finalizados = 0;
        $noFinalizados = 0;

        foreach ($this->calcularPreview($curso) as $fila) {
            $historico = $fila['historico'];
            $historico->setMotivoBaja($fila['estadoPrediccion']);
            $historico->setActivo(false);
            $historico->setFechaBaja($fechaActual);

            // Snapshot del resultado de notas, para que quede constancia de con qué se
            // decidió aunque después se agreguen o cambien evaluaciones.
            $historico->setResultadoNotas($fila['resultadoNotas']);
            $historico->setPromedioNotas($fila['promedioNotas']);

            if ($fila['estadoPrediccion'] === 'finalizado') {
                $finalizados++;
            } else {
                $noFinalizados++;
            }
        }

        $curso->setCerrado(true);
        $curso->setFechaCierre($fechaActual);

        $this->entityManager->flush();

        return ['finalizados' => $finalizados, 'noFinalizados' => $noFinalizados];
    }

    /**
     * Estado resultante de un alumno según los criterios activos.
     *
     * Si un criterio no está configurado, no puede hacer fallar la aprobación. Con ningún
     * criterio configurado, todos quedan finalizados.
     */
    private function decidirEstado(
        ?float $porcentajeRequerido,
        float $porcentajeAsistencia,
        bool $requierePagoTotal,
        bool $tienePagoCompleto,
        ?string $resultadoNotas = null
    ): string {
        $fallaAsistencia = ($porcentajeRequerido !== null && $porcentajeAsistencia < $porcentajeRequerido);
        $fallaPago = ($requierePagoTotal && !$tienePagoCompleto);

        // 'sin_datos' NO desaprueba: un alumno sin notas cargadas no puede quedar
        // desaprobado por un criterio que no se le pudo aplicar. Con $resultadoNotas en
        // null el criterio está inactivo y esta línea no cambia nada, así que el árbol de
        // decisión queda idéntico al que había antes de que existieran las notas.
        $fallaNotas = ($resultadoNotas === PromedioCalificacionService::RESULTADO_DESAPROBADO);

        return ($fallaAsistencia || $fallaPago || $fallaNotas) ? 'no_finalizado' : 'finalizado';
    }

    /**
     * Clases totales y presentes por alumno, en el período del curso.
     *
     * @return array<int, array{total: int, presentes: int}>
     */
    private function getAsistenciaPorAlumno(Curso $curso): array
    {
        $fechaInicio = $curso->getFechaInicio();
        $fechaFin = $curso->getFechaFin()
            ?? $this->institutoTimezoneService->getCurrentDateForInstituto($curso->getInstituto());

        $porAlumno = [];
        foreach ($this->asistenciaRepository->findByDateRange($curso, $fechaInicio, $fechaFin) as $asistencia) {
            $alumnoId = $asistencia->getAlumno()->getId();
            if (!isset($porAlumno[$alumnoId])) {
                $porAlumno[$alumnoId] = ['total' => 0, 'presentes' => 0];
            }
            $porAlumno[$alumnoId]['total']++;
            if ($asistencia->getPresente()) {
                $porAlumno[$alumnoId]['presentes']++;
            }
        }

        return $porAlumno;
    }
}
