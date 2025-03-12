<?php

namespace App\Controller;

use App\Repository\AlumnosPagosRepository;
use App\Repository\AlumnoRepository;
use App\Repository\CursoRepository;
use DateTime;
use Knp\Component\Pager\PaginatorInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class DashboardController extends AbstractController
{
    /**
     * @Route("/", name="dashboard_index", methods={"GET"})
     */
    public function index(
        Request $request, 
        AlumnosPagosRepository $alumnosPagosRepository, 
        AlumnoRepository $alumnoRepository, 
        CursoRepository $cursoRepository, 
        PaginatorInterface $paginator
    ): Response {
        $busqueda = $request->get('busqueda', '');
        $action = $request->get('action', 'home');
        $firstDay = new DateTime();
        $desde = $request->get('desde', $firstDay->format('Y-01-01'));
        $lastDay = new DateTime();
        $hasta = $request->get('hasta', $lastDay->format('Y-12-31'));
        $max = $request->get('registros', 10);

        // Obtener el instituto del usuario actual
        $instituto = $this->getUser()->getInstituto();

        // Obtener QueryBuilder para alumnos del instituto del usuario actual
        $alumnosQuery = $alumnoRepository->createQueryBuilder('a')
            ->andWhere('a.activo = :activo')
            ->andWhere('a.instituto = :instituto')
            ->setParameter('activo', 1)
            ->setParameter('instituto', $instituto);
        
        if ($busqueda) {
            $alumnosQuery->andWhere('a.apellido LIKE :busqueda OR a.nombre LIKE :busqueda')
                         ->setParameter('busqueda', '%' . $busqueda . '%');
        }

        $paginationAlumnos = $paginator->paginate(
            $alumnosQuery, 
            $request->query->getInt('page', 1), 
            20
        );

        $deudores = [];
        foreach ($paginationAlumnos as $alumno) {
            if ($alumno->getDebeMes()) {
                $ultimoPago = $alumno->getUltimoPago();
                $ultimoPagoFecha = $ultimoPago ? $ultimoPago->getFecha()->format('d/m/Y') : 'Sin pagos';
                $deudores[] = [
                    'alumno' => $alumno,
                    'ultimo_pago' => $ultimoPagoFecha
                ];
            }
        }

        // Paginación para los "Últimos Pagos" del instituto
        $alumnosIdsParaPagos = array_map(fn($alumno) => $alumno->getId(), iterator_to_array($paginationAlumnos));

        $alumnosPagosQuery = $alumnosPagosRepository->createQueryBuilder('ap')
            ->where('ap.alumno IN(:ids)')
            ->setParameter('ids', $alumnosIdsParaPagos)
            ->andWhere('ap.fecha BETWEEN :desde AND :hasta')
            ->setParameter('desde', $desde)
            ->setParameter('hasta', $hasta)
            ->orderBy('ap.fecha', 'DESC');

        $alumnosPagosPagination = $paginator->paginate(
            $alumnosPagosQuery,
            $request->query->getInt('page', 1),
            $max
        );

        // Paginación para cursos del instituto
        $cursosQuery = $cursoRepository->createQueryBuilder('c')
            ->join('c.alumnos', 'a')
            ->where('a.instituto = :instituto')
            ->andWhere('a.activo = 1')
            ->setParameter('instituto', $instituto);

        if ($busqueda) {
            $cursosQuery->andWhere('a.apellido LIKE :busqueda OR a.nombre LIKE :busqueda')
                        ->setParameter('busqueda', '%' . $busqueda . '%');
        }

        $cursosPagination = $paginator->paginate(
            $cursosQuery, 
            $request->query->getInt('page', 1), 
            20
        );

        // Obtener estadísticas para gráficos específicas al instituto
        // Esto puede necesitar actualización según tu implementación actual
        $pagaronATiempo = $alumnosPagosRepository->findPagosAtiempo($instituto);
        $pagaronFueraDeTiempo = $alumnosPagosRepository->findPagosFueraDeTiempo($instituto);
        $deudoresCount = $paginationAlumnos->getTotalItemCount();
        $inactivos = $alumnoRepository->createQueryBuilder('a')
            ->where('a.activo = 0')
            ->andWhere('a.instituto = :instituto')
            ->setParameter('instituto', $instituto)
            ->getQuery()->getResult();

        $totalPagos = $alumnosPagosQuery->select('SUM(ap.monto) as total')->getQuery()->getSingleScalarResult();

        return $this->render('dashboard/index.html.twig', [
            'alumnos_pagos' => $alumnosPagosPagination,
            'totalPagos' => $totalPagos,
            'paginationAlumnos' => $paginationAlumnos,
            'instituto' => $instituto,
            'busqueda' => $busqueda,
            'action' => $action,
            'deudores' => $deudores, 
            'cursos' => $cursosPagination, 
            'desde' => (new DateTime($desde))->format('d-m-Y'),
            'hasta' => (new DateTime($hasta))->format('d-m-Y'),
            'max' => $max,
            'atiempo' => count($pagaronATiempo),
            'fueraDeTiempo' => count($pagaronFueraDeTiempo),
            'activos' => $alumnoRepository->count(['activo' => 1, 'instituto' => $instituto]), 
            'totalDeudores' => $deudoresCount,
            'inactivos' => count($inactivos) 
        ]);
    }
}