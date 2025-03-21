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
use Doctrine\ORM\EntityManagerInterface;
use App\Entity\Alumno;

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
        PaginatorInterface $paginator,
        EntityManagerInterface $entityManager
    ): Response {
        $busqueda = $request->get('busqueda', '');
        $action = $request->get('action', 'home');
        $firstDay = new DateTime();
        $desde = $request->get('desde', $firstDay->format('Y-01-01'));
        $lastDay = new DateTime();
        $hasta = $request->get('hasta', $lastDay->format('Y-12-31'));
        $max = $request->get('registros', 10);
        $order = $request->get('order', 'desc');
        $sort = $request->get('sort', 'fecha');

        if($action == 'home') {
            $sort = $request->get('sort', 'alumnoNombre');
            $order = $request->get('order', 'asc');
        }

        // Mapeo de columnas para ordenamiento
        $sortColumns = [
            'fecha' => ['prefix' => 'ap.', 'field' => 'fecha'],
            'monto' => ['prefix' => 'ap.', 'field' => 'monto'],
            'metodoPago' => ['prefix' => 'ap.', 'field' => 'metodoPago'],
            'mes' => ['prefix' => 'ap.', 'field' => 'mes'],
            'ano' => ['prefix' => 'ap.', 'field' => 'ano'],
            'alumno' => ['prefix' => 'a.', 'field' => 'apellido']
        ];

        // Obtener el instituto del usuario actual
        $instituto = $this->getUser()->getInstituto();

        // Obtener QueryBuilder para alumnos del instituto del usuario actual
        $alumnosQuery = $alumnoRepository->createQueryBuilder('a')
            ->andWhere('a.activo = :activo')
            ->andWhere('a.instituto = :instituto')
            ->setParameter('activo', 1)
            ->setParameter('instituto', $instituto);

        // Obtener el primer día del mes actual
        $primerDiaMesActual = new DateTime('first day of this month');
        $ultimoDiaMesActual = new DateTime('last day of this month');
        
        // Verificar si estamos en el día 20 o posterior
        $hoy = new DateTime();
        $diaActual = (int)$hoy->format('d');
        
        if ($diaActual >= 20) {
            // Subconsulta para obtener los meses con pagos
            $subQuery = $alumnoRepository->createQueryBuilder('a2')
                ->select('IDENTITY(p2.alumno) as alumno_id, p2.mes, p2.ano')
                ->join('a2.pagos', 'p2')
                ->where('p2.alumno = a')
                ->getDQL();

            // Query principal modificada para encontrar deudores
            $alumnosQuery
                ->andWhere('NOT EXISTS (
                    SELECT 1 FROM App\Entity\AlumnosPagos p
                    JOIN p.alumno pa
                    WHERE p.alumno = a
                    AND pa.instituto = :instituto
                    AND p.mes = :mesActual
                    AND p.ano = :anoActual
                )')
                ->orWhere('EXISTS (
                    SELECT 1 FROM App\Entity\AlumnosPagos p1
                    JOIN p1.alumno pa1
                    WHERE p1.alumno = a
                    AND pa1.instituto = :instituto
                    AND p1.fecha < :primerDiaMesActual
                    AND NOT EXISTS (
                        SELECT 1 FROM App\Entity\AlumnosPagos p2
                        JOIN p2.alumno pa2
                        WHERE p2.alumno = a
                        AND pa2.instituto = :instituto
                        AND p2.fecha > p1.fecha
                        AND p2.fecha < :primerDiaMesActual
                    )
                )')
                ->setParameter('mesActual', (int)$hoy->format('m'))
                ->setParameter('anoActual', (int)$hoy->format('Y'))
                ->setParameter('primerDiaMesActual', $primerDiaMesActual);
        }
        
        if ($busqueda) {
            $alumnosQuery->andWhere('a.apellido LIKE :busqueda OR a.nombre LIKE :busqueda')
                         ->setParameter('busqueda', '%' . $busqueda . '%');
        }

        if ($sort === 'alumnoNombre') {
            $alumnosQuery->orderBy('a.apellido', $order);
        } elseif ($sort === 'alumnosPagos') {
            // Subconsulta para obtener la fecha del último pago
            $alumnosQuery
                ->leftJoin('a.pagos', 'ultimo_pago')
                ->addSelect('a', 'MAX(ultimo_pago.fecha) as ultimo_pago_fecha')
                ->groupBy('a.id')
                ->orderBy('ultimo_pago_fecha', $order);
        }

        // Obtener el total de deudores antes de la paginación
        $totalDeudores = count($alumnosQuery->getQuery()->getResult());

        $paginationAlumnos = $paginator->paginate(
            $alumnosQuery, 
            $request->query->getInt('page', 1), 
            20
        );

        $deudores = [];
        foreach ($paginationAlumnos as $alumno) {
            if (is_array($alumno)) {
                $ultimoPago = $alumno['ultimo_pago_fecha'];
                $alumno = $alumno[0];
                $ultimoPagoFecha = $ultimoPago ? (new DateTime($ultimoPago))->format('d/m/Y') : 'Sin pagos';
            } else {
                $ultimoPago = $alumno->getUltimoPago();
                $ultimoPagoFecha = $ultimoPago ? $ultimoPago->getFecha()->format('d/m/Y') : 'Sin pagos';
            }
            $deudores[] = [
                'alumno' => $alumno,
                'ultimo_pago' => $ultimoPagoFecha
            ];
        }

        // Paginación para los "Últimos Pagos" del instituto
        $alumnosPagosQuery = $alumnosPagosRepository->createQueryBuilder('ap')
            ->join('ap.alumno', 'a')
            ->where('a.instituto = :instituto')
            ->andWhere('ap.fecha BETWEEN :desde AND :hasta')
            ->setParameter('instituto', $instituto)
            ->setParameter('desde', $desde)
            ->setParameter('hasta', $hasta);

        // Aplicar ordenamiento según la columna seleccionada
        if (isset($sortColumns[$sort])) {
            $column = $sortColumns[$sort];
            $orderBy = $column['prefix'] . $column['field'];
            $alumnosPagosQuery->orderBy($orderBy, $order);
            
            // Si es ordenamiento por alumno, agregar ordenamiento secundario por nombre
            if ($sort === 'alumno') {
                $alumnosPagosQuery->addOrderBy('a.nombre', $order);
            }
        } else {
            $alumnosPagosQuery->orderBy('ap.fecha', $order);
        }

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

        if ($sort === 'nombreCurso') {
            $cursosQuery->orderBy('c.nombre', $order);
        }

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
        $inactivos = $alumnoRepository->createQueryBuilder('a')
            ->where('a.activo = 0')
            ->andWhere('a.instituto = :instituto')
            ->setParameter('instituto', $instituto)
            ->getQuery()->getResult();

        // Create a separate query for total calculation
        $totalPagos = $alumnosPagosRepository->createQueryBuilder('ap')
            ->join('ap.alumno', 'a')
            ->where('a.instituto = :instituto')
            ->andWhere('ap.fecha BETWEEN :desde AND :hasta')
            ->setParameter('instituto', $instituto)
            ->setParameter('desde', $desde)
            ->setParameter('hasta', $hasta)
            ->select('SUM(ap.monto) as total');

        $totalPagos = $totalPagos->getQuery()->getSingleScalarResult() ?? 0;

        $mesActual = (int)date('m');
        $añoActual = (int)date('Y');

        // Crear array de deudores con información detallada
        $deudoresDetalles = [];
        foreach ($paginationAlumnos as $alumno) {
            $mesesAdeudados = [];
            $motivo = '';
            
            // Verificar deuda del mes actual
            $tienePagoMesActual = false;
            foreach ($alumno->getPagos() as $pago) {
                if ($pago->getMes() == $mesActual && $pago->getAno() == $añoActual) {
                    $tienePagoMesActual = true;
                    break;
                }
            }
            
            if (!$tienePagoMesActual) {
                $mesesAdeudados[] = [
                    'mes' => $mesActual,
                    'año' => $añoActual
                ];
                $motivo = 'Sin pago en el mes actual';
            }
            
            // Verificar deuda del mes anterior
            $mesAnterior = $mesActual - 1;
            $añoAnterior = $añoActual;
            if ($mesAnterior < 1) {
                $mesAnterior = 12;
                $añoAnterior--;
            }
            
            $tienePagoMesAnterior = false;
            foreach ($alumno->getPagos() as $pago) {
                if ($pago->getMes() == $mesAnterior && $pago->getAno() == $añoAnterior) {
                    $tienePagoMesAnterior = true;
                    break;
                }
            }
            
            if (!$tienePagoMesAnterior) {
                $mesesAdeudados[] = [
                    'mes' => $mesAnterior,
                    'año' => $añoAnterior
                ];
                if (empty($motivo)) {
                    $motivo = 'Sin pago en el mes anterior';
                }
            }

            // Solo agregar al alumno si tiene meses adeudados
            if (!empty($mesesAdeudados)) {
                $deudoresDetalles[] = [
                    'alumno' => $alumno,
                    'mesesAdeudados' => $mesesAdeudados,
                    'motivo' => $motivo,
                    'ultimoPago' => $alumno->getUltimoPago()
                ];
            }
        }

        $totalDeudoresDetalles = count($deudoresDetalles);

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
            'order' => $order,
            'sort' => $sort,
            'atiempo' => count($pagaronATiempo),
            'fueraDeTiempo' => count($pagaronFueraDeTiempo),
            'activos' => $alumnoRepository->count(['activo' => 1, 'instituto' => $instituto]), 
            'totalDeudores' => $totalDeudoresDetalles,
            'inactivos' => count($inactivos),
            'deudoresDetalles' => $deudoresDetalles,
            'totalDeudoresDetalles' => $totalDeudoresDetalles,
            'mesActual' => $mesActual,
            'añoActual' => $añoActual
        ]);
    }
}