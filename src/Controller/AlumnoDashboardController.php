<?php

namespace App\Controller;

use App\Repository\CursoRepository;
use App\Repository\AlumnoRepository;
use App\Repository\AsistenciaAlumnosRepository;
use App\Repository\AlumnosPagosRepository;
use App\Repository\AlumnoCursoHistoricoRepository;
use App\Repository\CalificacionRepository;
use App\Repository\TareaEntregaRepository;
use App\Repository\TareaRepository;
use App\Service\EscalaCalificacionService;
use App\Service\InstitutoTimezoneService;
use App\Service\PromedioCalificacionService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * @Route("/alumno")
 */
#[IsGranted('ROLE_ALUMNO')]
class AlumnoDashboardController extends AbstractController
{
    /**
     * @Route("/dashboard", name="app_alumno_dashboard")
     */
    public function index(
        CursoRepository $cursoRepository,
        AsistenciaAlumnosRepository $asistenciaAlumnosRepository,
        AlumnosPagosRepository $alumnosPagosRepository,
        EscalaCalificacionService $escalaService
    ): Response {
        $user = $this->getUser();
        if (!$user || !$user->getAlumno()) {
            $this->addFlash('danger', 'No se encontró información del alumno asociada a tu usuario.');
            return $this->redirectToRoute('app_logout');
        }
        
        $alumno = $user->getAlumno();
        
        // Obtener los cursos del alumno
        $cursos = $alumno->getCurso();
        
        // Obtener asistencias recientes (últimos 30 días)
        $fechaDesde = new \DateTime('-30 days');
        $asistenciasRecientes = $asistenciaAlumnosRepository->createQueryBuilder('a')
            ->where('a.alumno = :alumno')
            ->andWhere('a.fecha >= :fechaDesde')
            ->setParameter('alumno', $alumno)
            ->setParameter('fechaDesde', $fechaDesde)
            ->orderBy('a.fecha', 'DESC')
            ->setMaxResults(10)
            ->getQuery()
            ->getResult();
        
        // Obtener pagos recientes (últimos 30 días)
        $pagosRecientes = $alumnosPagosRepository->createQueryBuilder('p')
            ->where('p.alumno = :alumno')
            ->andWhere('p.fecha >= :fechaDesde')
            ->setParameter('alumno', $alumno)
            ->setParameter('fechaDesde', $fechaDesde)
            ->orderBy('p.fecha', 'DESC')
            ->setMaxResults(10)
            ->getQuery()
            ->getResult();
        
        // Calcular estadísticas básicas
        $totalCursos = $cursos->count();
        $totalAsistencias = count($asistenciasRecientes);
        $totalPagos = count($pagosRecientes);
        
        // Obtener deudas pendientes
        $deudasPendientes = [];
        foreach ($alumno->getDeudas() as $deuda) {
            if (!$deuda->isPagado()) {
                $deudasPendientes[] = $deuda;
            }
        }

        return $this->render('alumno_dashboard/index.html.twig', [
            'alumno' => $alumno,
            'cursos' => $cursos,
            'asistenciasRecientes' => $asistenciasRecientes,
            'pagosRecientes' => $pagosRecientes,
            'deudasPendientes' => $deudasPendientes,
            'totalCursos' => $totalCursos,
            'totalAsistencias' => $totalAsistencias,
            'totalPagos' => $totalPagos,
            'usaCalificaciones' => $escalaService->usaCalificaciones($alumno->getInstituto()),
        ]);
    }

    /**
     * Notas del alumno, agrupadas por curso.
     *
     * La ruta cae bajo ^/alumno, que en access_control ya exige ROLE_ALUMNO, y el alumno
     * se resuelve desde el usuario logueado: no hay ningún id en la URL que pueda
     * manipularse para ver las notas de otro.
     *
     * @Route("/notas", name="app_alumno_notas")
     */
    public function notas(
        CalificacionRepository $calificacionRepository,
        EscalaCalificacionService $escalaService,
        PromedioCalificacionService $promedioService,
        InstitutoTimezoneService $institutoTimezoneService
    ): Response {
        $user = $this->getUser();
        if (!$user || !$user->getAlumno()) {
            $this->addFlash('danger', 'No se encontró información del alumno asociada a tu usuario.');
            return $this->redirectToRoute('app_logout');
        }

        $alumno = $user->getAlumno();
        $instituto = $alumno->getInstituto();

        if (!$escalaService->usaCalificaciones($instituto)) {
            return $this->render('alumno_dashboard/notas.html.twig', [
                'alumno' => $alumno,
                'usaCalificaciones' => false,
                'cursos' => [],
                'date_format' => $institutoTimezoneService->getDateFormatForInstituto($instituto),
            ]);
        }

        // Una sola consulta con los joins ya hechos; el agrupado se arma en memoria.
        $porCurso = [];
        foreach ($calificacionRepository->findByAlumno($alumno) as $calificacion) {
            $historico = $calificacion->getCursoHistorico();
            $curso = $calificacion->getEvaluacion()->getCurso();
            $cursoId = $curso->getId();

            if (!isset($porCurso[$cursoId])) {
                $porCurso[$cursoId] = [
                    'curso' => $curso,
                    'historico' => $historico,
                    'calificaciones' => [],
                ];
            }

            $porCurso[$cursoId]['calificaciones'][] = $calificacion;
        }

        // El resumen se calcula sobre las notas ya cargadas, sin volver a la base.
        foreach ($porCurso as $cursoId => $datos) {
            $porCurso[$cursoId]['resumen'] = $promedioService->resumir(
                $datos['calificaciones'],
                $instituto->getConfiguracion()
                    ? $instituto->getConfiguracion()->getCriterioAprobacionNotas()
                    : 'promedio'
            );
        }

        return $this->render('alumno_dashboard/notas.html.twig', [
            'alumno' => $alumno,
            'usaCalificaciones' => true,
            'cursos' => array_values($porCurso),
            'date_format' => $institutoTimezoneService->getDateFormatForInstituto($instituto),
        ]);
    }

    /**
     * Tareas del alumno, agrupadas por curso, de solo lectura.
     *
     * Igual que las notas: el alumno se resuelve desde el usuario logueado, así que no hay
     * ningún id manipulable en la URL. Se recorren todas sus inscripciones, incluidas las
     * cerradas, porque las tareas de un curso que ya terminó siguen siendo parte de su
     * historia.
     *
     * Se agrupa por curso y no por inscripción: las tareas se le piden al curso, así que si el
     * alumno se reinscribió al mismo curso la lista sería la misma dos veces. Las entregas de
     * todas sus inscripciones se unifican.
     *
     * @Route("/tareas", name="app_alumno_tareas")
     */
    public function tareas(
        AlumnoCursoHistoricoRepository $historicoRepository,
        TareaRepository $tareaRepository,
        TareaEntregaRepository $entregaRepository,
        InstitutoTimezoneService $institutoTimezoneService
    ): Response {
        $user = $this->getUser();
        if (!$user || !$user->getAlumno()) {
            $this->addFlash('danger', 'No se encontró información del alumno asociada a tu usuario.');
            return $this->redirectToRoute('app_logout');
        }

        $alumno = $user->getAlumno();

        $cursos = [];
        foreach ($historicoRepository->findByAlumno($alumno) as $historico) {
            $curso = $historico->getCurso();
            if (!$curso) {
                continue;
            }

            $cursoId = $curso->getId();
            if (!isset($cursos[$cursoId])) {
                $tareas = $tareaRepository->findParaAlumno($curso);
                if (!$tareas) {
                    continue;
                }

                $cursos[$cursoId] = [
                    'curso' => $curso,
                    'tareas' => $tareas,
                    // Estado de cada tarea indexado por id, que es lo que lee el template.
                    'entregas' => [],
                    'entregadas' => 0,
                    'pedidas' => count($tareas),
                ];
            }

            foreach ($entregaRepository->findByHistorico($historico) as $entrega) {
                $tarea = $entrega->getTarea();
                if (!$tarea) {
                    continue;
                }

                $cursos[$cursoId]['entregas'][$tarea->getId()] = $entrega;
            }
        }

        // El total se cuenta al final, ya unificadas las entregas de todas las inscripciones.
        foreach ($cursos as $cursoId => $datos) {
            $entregadas = 0;
            foreach ($datos['entregas'] as $entrega) {
                if ($entrega->isEntregada()) {
                    $entregadas++;
                }
            }

            $cursos[$cursoId]['entregadas'] = $entregadas;
            $cursos[$cursoId]['porcentaje'] = $datos['pedidas'] > 0
                ? round(($entregadas / $datos['pedidas']) * 100, 1)
                : null;
        }

        return $this->render('alumno_dashboard/tareas.html.twig', [
            'alumno' => $alumno,
            'cursos' => array_values($cursos),
            'date_format' => $institutoTimezoneService->getDateFormatForInstituto($alumno->getInstituto()),
        ]);
    }
}
