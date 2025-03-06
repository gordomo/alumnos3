<?php

namespace App\Controller;

use App\Repository\AlumnoRepository;
use App\Repository\AlumnosPagosRepository;
use App\Repository\CursoRepository;
use Knp\Component\Pager\PaginatorInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class DashboardController extends AbstractController
{
    /**
     * @Route("/dashboard", name="dashboard_index", methods={"GET"})
     */
    public function index(Request $request, AlumnosPagosRepository $alumnosPagosRepository, AlumnoRepository $alumnoRepository, CursoRepository $cursoRepository, PaginatorInterface $paginator): Response
    {
        $busqueda = $request->get('busqueda', '');
        $action = $request->get('action', '');
        $firstDay = new \DateTime();
        $desde = $request->get('desde', $firstDay->format('Y-01-01'));
        $lastDay = new \DateTime();
        $hasta = $request->get('hasta', $lastDay->format('Y-12-31'));
        $max = $request->get('registros', 10);

        // Obtener QueryBuilder para alumnos
        $alumnosQuery = $alumnoRepository->createQueryBuilder('a')
            ->andWhere('a.activo = :activo')
            ->setParameter('activo', 1);
        
        if ($busqueda) {
            $alumnosQuery->andWhere('a.apellido LIKE :busqueda OR a.nombre LIKE :busqueda')
                ->setParameter('busqueda', '%' . $busqueda . '%');
        }

        // Paginar para la pestaña "Deudores"
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

        // Paginación para los "Últimos Pagos"
        $alumnosParaPagosQuery = $alumnoRepository->createQueryBuilder('a')
            ->select('a.id')
            ->where('a.activo = :activo')
            ->setParameter('activo', 1);

        if ($busqueda) {
            $alumnosParaPagosQuery->andWhere('a.apellido LIKE :busqueda OR a.nombre LIKE :busqueda')
                ->setParameter('busqueda', '%' . $busqueda . '%');
        }

        $alumnosIdsParaPagos = array_map(fn($alumno) => $alumno['id'], $alumnosParaPagosQuery->getQuery()->getArrayResult());

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

        // Paginación para cursos
        $cursosQuery = $cursoRepository->createQueryBuilder('c')
            ->join('c.alumnos', 'a');

        if ($busqueda) {
            $cursosQuery->andWhere('a.apellido LIKE :busqueda OR a.nombre LIKE :busqueda')
                        ->setParameter('busqueda', '%' . $busqueda . '%');
        } else {
            $cursosQuery->where('a IN(:ids)')
                        ->setParameter('ids', $alumnosIdsParaPagos);
        }

        $cursosPagination = $paginator->paginate(
            $cursosQuery, 
            $request->query->getInt('page', 1), 
            20
        );

        // Obtener estadísticas para gráficos
        $pagaronATiempo = $alumnosPagosRepository->findPagosAtiempo();
        $pagaronFueraDeTiempo = $alumnosPagosRepository->findPagosFueraDeTiempo();
        $deudoresCount = $paginationAlumnos->getTotalItemCount();
        $inactivos = $alumnoRepository->findBy(['activo' => 0]);
        return $this->render('dashboard/index.html.twig', [
            'alumnos_pagos' => $alumnosPagosPagination,
            'paginationAlumnos' => $paginationAlumnos,
            'busqueda' => $busqueda,
            'action' => $action,
            'deudores' => $deudores, 
            'cursos' => $cursosPagination, 
            'desde' => (new \DateTime($desde))->format('d-m-Y'),
            'hasta' => (new \DateTime($hasta))->format('d-m-Y'),
            'max' => $max,
            'atiempo' => count($pagaronATiempo),
            'fueraDeTiempo' => count($pagaronFueraDeTiempo),
            'activos' => $alumnoRepository->count(['activo' => 1]), 
            'totalDeudores' => $deudoresCount,
            'inactivos' => count($inactivos) 
        ]);
    }
}