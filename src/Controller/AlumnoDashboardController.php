<?php

namespace App\Controller;

use App\Repository\CursoRepository;
use App\Repository\AlumnoRepository;
use App\Repository\AsistenciaAlumnosRepository;
use App\Repository\AlumnosPagosRepository;
use App\Repository\CalificacionRepository;
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
}

