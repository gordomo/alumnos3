<?php

namespace App\Controller;

use App\Repository\CursoRepository;
use App\Repository\AlumnoRepository;
use App\Repository\AsistenciaAlumnosRepository;
use App\Repository\AlumnosPagosRepository;
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
        AlumnosPagosRepository $alumnosPagosRepository
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
        ]);
    }
}

