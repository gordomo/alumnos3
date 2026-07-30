<?php

namespace App\Service;

use App\Entity\Curso;
use App\Repository\AlumnoCursoHistoricoRepository;
use App\Repository\AsistenciaAlumnosRepository;
use App\Repository\DeudaAlumnoRepository;
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
        private EntityManagerInterface $entityManager
    ) {
    }

    /**
     * Criterios de aprobación vigentes para el instituto del curso.
     *
     * @return array{porcentajeRequerido: float|null, requierePagoTotal: bool}
     */
    public function getCriterios(Curso $curso): array
    {
        $config = $curso->getInstituto()->getConfiguracion();

        return [
            'porcentajeRequerido' => $config ? $config->getPorcentajeAsistenciaAprobacion() : null,
            'requierePagoTotal' => $config ? $config->getRequierePagoTotalParaAprobar() : false,
        ];
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
        ['porcentajeRequerido' => $porcentajeRequerido, 'requierePagoTotal' => $requierePagoTotal]
            = $this->getCriterios($curso);

        $asistenciaPorAlumno = $this->getAsistenciaPorAlumno($curso);
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

            // TODO(N+1): esta consulta se hace por alumno. Se mantiene tal cual para que
            // esta extracción no cambie el comportamiento; optimizarla es un cambio aparte.
            $tienePagoCompleto = true;
            if ($requierePagoTotal) {
                $tienePagoCompleto = empty(
                    $this->deudaAlumnoRepository->findDeudaByAlumnoAndCurso($alumno, $curso)
                );
            }

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
                    $tienePagoCompleto
                ),
                'tienePagoCompleto' => $tienePagoCompleto,
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
        bool $tienePagoCompleto
    ): string {
        $fallaAsistencia = ($porcentajeRequerido !== null && $porcentajeAsistencia < $porcentajeRequerido);
        $fallaPago = ($requierePagoTotal && !$tienePagoCompleto);

        return ($fallaAsistencia || $fallaPago) ? 'no_finalizado' : 'finalizado';
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
