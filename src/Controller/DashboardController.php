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
use App\Service\HistorialCursosService;

class DashboardController extends AbstractController
{
    private $historialCursosService;

    public function __construct(HistorialCursosService $historialCursosService)
    {
        $this->historialCursosService = $historialCursosService;
    }

    /**
     * @Route("/instituto", name="dashboard_index", methods={"GET"})
     */
    public function index(
        Request $request, 
        AlumnoRepository $alumnoRepository, 
        PaginatorInterface $paginator
    ): Response {
        $busqueda = $request->get('busqueda', '');
        $order = $request->get('order', 'asc');
        $sort = $request->get('sort', 'alumnoNombre');

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

        // Ordenamiento por defecto para alumnos
        $alumnosQuery->orderBy('a.apellido', 'asc')
                    ->addOrderBy('a.nombre', 'asc');

        // Obtener todos los alumnos
        $alumnos = $alumnosQuery->getQuery()->getResult();

        // Filtrar solo los deudores y calcular estadísticas
        $deudores = [];
        $deudoresCriticos = 0;
        $montoTotalAdeudado = 0;
        $totalMesesAdeudados = 0;
        $deudasPorCurso = [];
        $totalAlumnos = count($alumnos);

        foreach ($alumnos as $alumno) {
            $mesesAdeudados = $this->historialCursosService->verificarMesesAdeudados($alumno);
            
            if (!empty($mesesAdeudados)) {
                $cantidadMeses = count($mesesAdeudados);
                $deudores[] = [
                    'alumno' => $alumno,
                    'mesesAdeudados' => $mesesAdeudados,
                    'motivo' => 'Tiene ' . $cantidadMeses . ' mes(es) adeudado(s)',
                    'ultimoPago' => $alumno->getUltimoPago()
                ];

                // Calcular estadísticas
                if ($cantidadMeses > 3) {
                    $deudoresCriticos++;
                }

                // Calcular monto adeudado y estadísticas por curso
                foreach ($mesesAdeudados as $mes) {
                    if (isset($mes['curso_obj'])) {
                        $curso = $mes['curso_obj'];
                        $montoTotalAdeudado += $curso->getPrecio();
                        
                        // Agrupar deudas por curso
                        if (!isset($deudasPorCurso[$curso->getId()])) {
                            $deudasPorCurso[$curso->getId()] = [
                                'nombre' => $curso->getNombre(),
                                'deudores' => 0,
                                'monto' => 0,
                                'porcentaje' => 0
                            ];
                        }
                        $deudasPorCurso[$curso->getId()]['monto'] += $curso->getPrecio();
                    }
                }

                $totalMesesAdeudados += $cantidadMeses;
            }
        }

        // Calcular estadísticas adicionales
        $totalDeudores = count($deudores);
        $porcentajeDeudores = $totalAlumnos > 0 ? ($totalDeudores / $totalAlumnos) * 100 : 0;
        $montoPromedioAdeudado = $totalDeudores > 0 ? $montoTotalAdeudado / $totalDeudores : 0;
        $promedioMesesAdeudados = $totalDeudores > 0 ? $totalMesesAdeudados / $totalDeudores : 0;

        // Calcular deudores únicos por curso
        foreach ($deudores as $deudor) {
            $cursosUnicos = [];
            foreach ($deudor['mesesAdeudados'] as $mes) {
                if (isset($mes['curso_obj'])) {
                    $cursoId = $mes['curso_obj']->getId();
                    if (!in_array($cursoId, $cursosUnicos)) {
                        $cursosUnicos[] = $cursoId;
                        $deudasPorCurso[$cursoId]['deudores']++;
                    }
                }
            }
        }

        // Calcular porcentajes por curso
        foreach ($deudasPorCurso as &$curso) {
            $curso['porcentaje'] = ($curso['monto'] / $montoTotalAdeudado) * 100;
        }

        // Ordenar cursos por monto de deuda
        uasort($deudasPorCurso, function($a, $b) {
            return $b['monto'] <=> $a['monto'];
        });

        // Ordenar deudores según los parámetros
        usort($deudores, function($a, $b) use ($sort, $order) {
            $comparison = 0;
            
            switch ($sort) {
                case 'alumnoNombre':
                    $comparison = strcmp($a['alumno']->getApellido(), $b['alumno']->getApellido());
                    if ($comparison === 0) {
                        $comparison = strcmp($a['alumno']->getNombre(), $b['alumno']->getNombre());
                    }
                    break;
                case 'ultimoPago':
                    $fechaA = $a['ultimoPago'] ? $a['ultimoPago']->getFecha() : new \DateTime('1970-01-01');
                    $fechaB = $b['ultimoPago'] ? $b['ultimoPago']->getFecha() : new \DateTime('1970-01-01');
                    $comparison = $fechaA <=> $fechaB;
                    break;
                case 'mesesAdeudados':
                    $comparison = count($a['mesesAdeudados']) <=> count($b['mesesAdeudados']);
                    break;
                default:
                    $comparison = strcmp($a['alumno']->getApellido(), $b['alumno']->getApellido());
            }
            
            return $order === 'asc' ? $comparison : -$comparison;
        });

        // Paginar los resultados
        $pagination = $paginator->paginate(
            $deudores,
            $request->query->getInt('page', 1),
            20
        );

        return $this->render('dashboard/index.html.twig', [
            'pagination' => $pagination,
            'instituto' => $instituto,
            'busqueda' => $busqueda,
            'order' => $order,
            'sort' => $sort,
            'totalDeudores' => $totalDeudores,
            'deudoresCriticos' => $deudoresCriticos,
            'montoTotalAdeudado' => $montoTotalAdeudado,
            'promedioMesesAdeudados' => $promedioMesesAdeudados,
            'porcentajeDeudores' => $porcentajeDeudores,
            'montoPromedioAdeudado' => $montoPromedioAdeudado,
            'deudasPorCurso' => $deudasPorCurso
        ]);
    }
}