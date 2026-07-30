<?php

namespace App\Controller;

use App\Repository\AlumnosPagosRepository;
use App\Repository\AlumnoRepository;
use App\Repository\CursoRepository;
use App\Service\InstitutoTimezoneService;
use DateTime;
use Knp\Component\Pager\PaginatorInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Doctrine\ORM\EntityManagerInterface;
use App\Entity\Alumno;
use App\Service\HistorialCursosService;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_ADMIN_INSTITUTO')]
class DashboardController extends AbstractController
{
    private $historialCursosService;
    private $deudaCalculator;
    private $timezoneService;

    public function __construct(
        HistorialCursosService $historialCursosService,
        \App\Service\DeudaCalculatorService $deudaCalculator,
        InstitutoTimezoneService $timezoneService
    ) {
        $this->historialCursosService = $historialCursosService;
        $this->deudaCalculator = $deudaCalculator;
        $this->timezoneService = $timezoneService;
    }

    /**
     * @Route("/instituto", name="dashboard_index", methods={"GET"})
     */
    public function index(
        Request $request, 
        AlumnoRepository $alumnoRepository, 
        PaginatorInterface $paginator,
        EntityManagerInterface $entityManager
    ): Response {
        $busqueda = $request->get('busqueda', '');
        $order = $request->get('order', 'asc');
        $sort = $request->get('sort', 'alumnoNombre');

        // Obtener el instituto del usuario actual
        $user = $this->getUser();
        if (!$user || !$user->getInstituto()) {
            $this->addFlash('danger', 'No tienes un instituto asignado.');
            return $this->redirectToRoute('app_login');
        }
        $instituto = $user->getInstituto();

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

        // Usar el nuevo servicio para calcular estadísticas de deudas.
        // OJO: no se le pasan $alumnos, que puede venir filtrado por búsqueda; las
        // tarjetas deben seguir reflejando a todos los alumnos activos del instituto.
        // Se reutiliza su desglose más abajo (superset de $alumnos): antes se recorrían
        // todos los alumnos acá y otra vez en el foreach siguiente, calculando dos
        // veces exactamente las mismas deudas.
        $estadisticas = $this->deudaCalculator->getEstadisticasDeudas($instituto);
        $deudasPorAlumno = $estadisticas['deudasPorAlumno'];
        $totalDeudores = $estadisticas['totalDeudores'];
        $montoTotalAdeudado = $estadisticas['montoTotalAdeudado'];
        $montoAdeudadoMensual = $estadisticas['montoAdeudadoMensual'];
        
        // Filtrar solo los deudores para la tabla
        $deudores = [];
        $deudasPorCurso = [];
        $totalAlumnos = count($alumnos);

        foreach ($alumnos as $alumno) {
            $deudasAlumno = $deudasPorAlumno[$alumno->getId()] ?? [];

            if (!empty($deudasAlumno)) {
                $cantidadMeses = count($deudasAlumno);
                $deudores[] = [
                    'alumno' => $alumno,
                    'mesesAdeudados' => $deudasAlumno,
                    'motivo' => 'Tiene ' . $cantidadMeses . ' mes(es) adeudado(s)',
                    'ultimoPago' => $alumno->getUltimoPago()
                ];

                // Calcular estadísticas por curso
                foreach ($deudasAlumno as $deuda) {
                    $curso = $deuda['curso'];
                    $montoDeuda = $deuda['monto'] + $deuda['interes'];
                    
                    // Agrupar deudas por curso
                    if (!isset($deudasPorCurso[$curso->getId()])) {
                        $deudasPorCurso[$curso->getId()] = [
                            'nombre' => $curso->getNombre(),
                            'deudores' => 0,
                            'monto' => 0,
                            'porcentaje' => 0
                        ];
                    }
                    $deudasPorCurso[$curso->getId()]['monto'] += $montoDeuda;
                }
            }
        }

        // Calcular estadísticas adicionales
        $porcentajeDeudores = $totalAlumnos > 0 ? ($totalDeudores / $totalAlumnos) * 100 : 0;
        $montoPromedioAdeudado = $totalDeudores > 0 ? $montoTotalAdeudado / $totalDeudores : 0;
        
        // Calcular deudores críticos (con más de 2 meses adeudados)
        $deudoresCriticos = 0;
        $totalMesesAdeudados = 0;
        foreach ($deudores as $deudor) {
            $mesesAdeudados = count($deudor['mesesAdeudados']);
            $totalMesesAdeudados += $mesesAdeudados;
            if ($mesesAdeudados > 2) {
                $deudoresCriticos++;
            }
        }
        $promedioMesesAdeudados = $totalDeudores > 0 ? $totalMesesAdeudados / $totalDeudores : 0;

        // Calcular montos cobrados (mensual y anual) usando la zona horaria del instituto
        $fechaActual = $this->timezoneService->getNowForInstituto($instituto);
        $mesActual = (int)$fechaActual->format('n');
        $anoActual = (int)$fechaActual->format('Y');

        // Calcular inicio y fin del mes actual en la zona horaria del instituto
        $timezone = new \DateTimeZone($this->timezoneService->getTimezoneForInstituto($instituto));
        $inicioMes = new \DateTime('first day of this month 00:00:00', $timezone);
        $finMes = new \DateTime('last day of this month 23:59:59', $timezone);

        // Calcular inicio y fin del año actual en la zona horaria del instituto
        $inicioAno = new \DateTime('first day of January ' . $anoActual . ' 00:00:00', $timezone);
        $finAno = new \DateTime('last day of December ' . $anoActual . ' 23:59:59', $timezone);

        // Monto cobrado en el mes actual
        $montoCobradoMensual = $entityManager->createQueryBuilder()
            ->select('COALESCE(SUM(p.monto), 0)')
            ->from('App\Entity\AlumnosPagos', 'p')
            ->join('p.alumno', 'a')
            ->where('a.instituto = :instituto')
            ->andWhere('p.fecha >= :inicioMes')
            ->andWhere('p.fecha <= :finMes')
            ->setParameter('instituto', $instituto)
            ->setParameter('inicioMes', $inicioMes)
            ->setParameter('finMes', $finMes)
            ->getQuery()
            ->getSingleScalarResult();

        // Monto cobrado en el año actual
        $montoCobradoAnual = $entityManager->createQueryBuilder()
            ->select('COALESCE(SUM(p.monto), 0)')
            ->from('App\Entity\AlumnosPagos', 'p')
            ->join('p.alumno', 'a')
            ->where('a.instituto = :instituto')
            ->andWhere('p.fecha >= :inicioAno')
            ->andWhere('p.fecha <= :finAno')
            ->setParameter('instituto', $instituto)
            ->setParameter('inicioAno', $inicioAno)
            ->setParameter('finAno', $finAno)
            ->getQuery()
            ->getSingleScalarResult();

        // Calcular deudores únicos por curso
        foreach ($deudores as $deudor) {
            $cursosUnicos = [];
            foreach ($deudor['mesesAdeudados'] as $deuda) {
                $cursoId = $deuda['curso']->getId();
                if (!in_array($cursoId, $cursosUnicos)) {
                    $cursosUnicos[] = $cursoId;
                    if (isset($deudasPorCurso[$cursoId])) {
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
            'montoAdeudadoMensual' => $montoAdeudadoMensual,
            'montoCobradoMensual' => $montoCobradoMensual,
            'montoCobradoAnual' => $montoCobradoAnual,
            'promedioMesesAdeudados' => $promedioMesesAdeudados,
            'porcentajeDeudores' => $porcentajeDeudores,
            'montoPromedioAdeudado' => $montoPromedioAdeudado,
            'deudasPorCurso' => $deudasPorCurso,
            'fechaActualInstituto' => $fechaActual,
            'mesActual' => $mesActual,
            'anoActual' => $anoActual
        ]);
    }
}