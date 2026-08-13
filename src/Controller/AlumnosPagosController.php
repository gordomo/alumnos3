<?php

namespace App\Controller;

use App\Entity\AlumnosPagos;
use App\Entity\Alumno;
use App\Entity\Curso;
use App\Entity\DeudaAlumno;
use App\Entity\MetodoPago;
use App\Form\AlumnosPagosType;
use App\Repository\AlumnosPagosRepository;
use App\Repository\VencimientoRepository;
use App\Repository\AlumnoRepository;
use App\Repository\DescuentoPromocionalRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use App\Repository\CursoRepository;
use Symfony\Component\HttpFoundation\JsonResponse;
use App\Service\HistorialCursosService;
use Knp\Component\Pager\PaginatorInterface;
use App\Service\NotificationService;
use App\Service\TokenService;
use App\Service\PagoService;
use App\Service\InstitutoTimezoneService;
/**
 * @Route("/alumnos/pagos")
 */
class AlumnosPagosController extends AbstractController
{
    private $entityManager;
    private $historialCursosService;
    private $notificationService;
    private $tokenService;
    private $pagoService;
    private InstitutoTimezoneService $institutoTimezoneService;
    private $deudaCalculator;

    public function __construct(
        EntityManagerInterface $entityManager,
        HistorialCursosService $historialCursosService,
        NotificationService $notificationService,
        TokenService $tokenService,
        PagoService $pagoService,
        InstitutoTimezoneService $institutoTimezoneService,
        \App\Service\DeudaCalculatorService $deudaCalculator
    ) {
        $this->entityManager = $entityManager;
        $this->deudaCalculator = $deudaCalculator;
        $this->historialCursosService = $historialCursosService;
        $this->notificationService = $notificationService;
        $this->tokenService = $tokenService;
        $this->pagoService = $pagoService;
        $this->institutoTimezoneService = $institutoTimezoneService;
    }

    /**
     * Primer día del mes de $fecha, a medianoche UTC, como \DateTime **mutable**.
     *
     * Mutable porque las fechas del instituto son DateTimeImmutable: ahí
     * modify()/setTime() devuelven una instancia nueva en lugar de mutar, así que
     * `clone $fecha; $fecha->modify(...)` es un no-op silencioso y los bucles
     * `while (...) { $fecha->modify('+1 month'); }` no terminan nunca.
     *
     * UTC para que todos los límites de mes se comparen sobre la misma base, igual que
     * InstitutoTimezoneService::normalizeDateOnly(), que es como se guardan las fechas
     * sin hora (fechaAlta, fechaInicio/FinPeriodo). Se toma el mes civil de $fecha, así
     * que una fecha en la zona del instituto conserva su mes.
     */
    private static function primerDiaDelMes(\DateTimeInterface $fecha): \DateTime
    {
        return new \DateTime($fecha->format('Y-m-01') . ' 00:00:00', new \DateTimeZone('UTC'));
    }

    /**
     * @Route("/", name="app_alumnos_pagos_index", methods={"GET"})
     */
    public function index(
        Request $request,
        AlumnosPagosRepository $alumnosPagosRepository,
        CursoRepository $cursoRepository,
        AlumnoRepository $alumnoRepository,
        PaginatorInterface $paginator
    ): Response {
        $busqueda = $request->get('busqueda', '');
        $cursoSelected = $request->get('curso', '');
        $metodoSelected = $request->get('metodoPago', '');
        $fechaDesde = $request->get('fechaDesde', '');
        $fechaHasta = $request->get('fechaHasta', '');
        $fechaFiltroProvista = $request->query->has('fechaDesde') || $request->query->has('fechaHasta');
        $sort = $request->get('sort', 'fecha');
        $order = $request->get('order', 'desc');

        $instituto = $this->getUser()->getInstituto();
        $nowInstituto = $this->institutoTimezoneService->getNowForInstituto($instituto);
        // Por defecto: año completo si no hay fechas en la petición (en zona horaria del instituto)
        if (empty($fechaDesde) && empty($fechaHasta)) {
            $anio = $nowInstituto->format('Y');
            $fechaDesde = $anio . '-01-01';
            $fechaHasta = $anio . '-12-31';
        }
        
        // Obtener el ID del alumno del formulario (prioridad) o de la URL
        // Priorizar el parámetro del formulario sobre el de la URL
        $alumnoSelected = $request->query->get('alumnoId', '');
        $alumnoIdFromUrl = $request->query->get('alumno', '');
        
        // Si 'alumnoId' viene del formulario (aunque esté vacío), usarlo
        // Si 'alumnoId' está presente en la query (incluso vacío), significa que el usuario seleccionó algo en el formulario
        if ($request->query->has('alumnoId')) {
            // El usuario seleccionó algo en el formulario, usar ese valor (puede ser vacío para "todos")
            $alumnoId = $alumnoSelected;
        } elseif ($alumnoIdFromUrl) {
            // No hay 'alumnoId' en el formulario, pero hay 'alumno' en la URL (navegación directa)
            $alumnoId = $alumnoIdFromUrl;
            $alumnoSelected = $alumnoIdFromUrl;
        } else {
            // No hay ningún parámetro de alumno
            $alumnoId = '';
            $alumnoSelected = '';
        }
        
        // Determinar qué tab mostrar basado en el parámetro de la URL
        // Si estamos paginando deudas (pageDeudas presente) o el tab es 'deudas', mostrar tab de deudas
        $activeTab = $request->get('tab', '');
        if ($request->query->has('pageDeudas') && $activeTab !== 'deudas') {
            $activeTab = 'deudas';
        } elseif ($activeTab === '') {
            $activeTab = 'pagos';
        }
        $alumno = null;
        $mesesAdeudados = [];
        $nombreAlumno = '';

        // Obtener el instituto del usuario actual
        $instituto = $this->getUser()->getInstituto();

        if ($alumnoId) {
            //$pagos = $alumnosPagosRepository->findByAlumno($alumnoId);
            // Cargar alumno con relaciones necesarias
            $alumno = $alumnoRepository->findWithDeudasAndAplicaciones($alumnoId);
            $nombreAlumno = $alumno->getNombre() . ' ' . $alumno->getApellido();
            $deudasParaPago = $this->getDeudasPendientesOnDemand($alumno, $nowInstituto);
            $mesesAdeudados = [];
            $nombresMeses = [
                1 => 'Enero', 2 => 'Febrero', 3 => 'Marzo', 4 => 'Abril',
                5 => 'Mayo', 6 => 'Junio', 7 => 'Julio', 8 => 'Agosto',
                9 => 'Septiembre', 10 => 'Octubre', 11 => 'Noviembre', 12 => 'Diciembre'
            ];
            
            foreach ($deudasParaPago as $deuda) {
                $curso = $deuda['curso'];
                $mesesAdeudados[] = [
                    'mes' => $deuda['mes'],
                    'ano' => $deuda['ano'],
                    'nombre' => $nombresMeses[$deuda['mes']] . ' ' . $deuda['ano'],
                    'curso' => $curso->getNombre(),
                    'curso_obj' => $curso,
                    'monto' => $deuda['monto']
                ];
            }
        } else {
            //$pagos = $alumnosPagosRepository->findByInstituto($instituto);
            $nombreAlumno = $instituto->getNombre();
        }

        // Crear QueryBuilder
        $qb = $alumnosPagosRepository->createQueryBuilder('p')
            ->leftJoin('p.alumno', 'a')
            ->leftJoin('p.curso', 'c')
            ->andWhere('a.instituto = :instituto')
            ->setParameter('instituto', $instituto);

        // Aplicar filtros
        if ($busqueda) {
            $qb->andWhere('a.nombre LIKE :busqueda OR a.apellido LIKE :busqueda')
               ->setParameter('busqueda', '%' . $busqueda . '%');
        }
        
        // Aplicar filtro de alumno (puede venir como 'alumno' o 'alumnoId' en la URL)
        if ($alumnoSelected) {
            $qb->andWhere('a.id = :alumnoSeleccionado')
               ->setParameter('alumnoSeleccionado', $alumnoSelected);
        }

        if ($cursoSelected) {
            $qb->andWhere('c.id = :curso')
               ->setParameter('curso', $cursoSelected);
        }

        if ($metodoSelected) {
            $qb->andWhere('p.metodoPago = :metodo')
               ->setParameter('metodo', $metodoSelected);
        }

        if ($fechaDesde) {
            // Parsear fecha usando el formato del instituto
            $dateFormat = $this->institutoTimezoneService->getDateFormatForInstituto($instituto);
            $fechaDesdeParsed = $this->institutoTimezoneService->parseDateString($fechaDesde, $dateFormat);
            if ($fechaDesdeParsed) {
                $qb->andWhere('p.fecha >= :fechaDesde')
                   ->setParameter('fechaDesde', $fechaDesdeParsed);
            }
        }

        if ($fechaHasta) {
            // Parsear fecha usando el formato del instituto
            $dateFormat = $this->institutoTimezoneService->getDateFormatForInstituto($instituto);
            // Fin del día: p.fecha es datetime, así que con la fecha a medianoche el <=
            // dejaría afuera todos los pagos cargados ese mismo día.
            $fechaHastaParsed = $this->institutoTimezoneService->parseDateStringEndOfDay($fechaHasta, $dateFormat);
            if ($fechaHastaParsed) {
                $qb->andWhere('p.fecha <= :fechaHasta')
                   ->setParameter('fechaHasta', $fechaHastaParsed);
            }
        }

        // Aplicar ordenamiento
        switch ($sort) {
            case 'fecha':
                $qb->orderBy('p.fecha', $order);
                break;
            case 'alumno':
                $qb->orderBy('a.apellido', $order)
                   ->addOrderBy('a.nombre', $order);
                break;
            case 'curso':
                $qb->orderBy('c.nombre', $order);
                break;
            case 'monto':
                $qb->orderBy('p.monto', $order);
                break;
            case 'metodoPago':
                $qb->orderBy('p.metodoPago', $order);
                break;
            case 'mes':
                $qb->orderBy('p.mes', $order);
                break;
            case 'ano':
                $qb->orderBy('p.ano', $order);
                break;
            default:
                $qb->orderBy('p.fecha', 'desc');
        }

        // Obtener todos los cursos para el filtro
        $cursos = $cursoRepository->findBy(['instituto' => $instituto]);

        // Obtener métodos de pago del instituto
        $metodosPagoEntities = $this->entityManager->getRepository(MetodoPago::class)
            ->findBy(['instituto' => $instituto, 'activo' => true], ['orden' => 'ASC']);
        
        $metodosPago = array_map(function($metodo) {
            return $metodo->getNombre();
        }, $metodosPagoEntities);

        // Paginar resultados
        $pagination = $paginator->paginate(
            $qb->getQuery(),
            $request->query->getInt('page', 1),
            20
        );

        // Calcular estadísticas por período
        // OJO: getNowForInstituto() devuelve DateTimeImmutable, así que modify()/setTime()
        // devuelven una instancia nueva y hay que reasignar el resultado.
        $fechaActual = $this->institutoTimezoneService->getNowForInstituto($instituto);
        $hoy = $fechaActual->setTime(0, 0, 0);

        $inicioSemana = $fechaActual->modify('monday this week')->setTime(0, 0, 0);

        $inicioMes = $fechaActual->modify('first day of this month')->setTime(0, 0, 0);

        $inicioAno = $fechaActual->modify('first day of january')->setTime(0, 0, 0);

        // Estadísticas de pagos - función helper
        $getEstadisticas = function($fechaDesde) use ($alumnosPagosRepository, $instituto) {
            $qb = $alumnosPagosRepository->createQueryBuilder('p')
                ->leftJoin('p.alumno', 'a')
                ->select('COALESCE(SUM(p.monto), 0) as total, COUNT(p.id) as cantidad')
                ->andWhere('a.instituto = :instituto')
                ->andWhere('p.fecha >= :fechaDesde')
                ->setParameter('instituto', $instituto)
                ->setParameter('fechaDesde', $fechaDesde);
            return $qb->getQuery()->getSingleResult();
        };

        // Calcular estadísticas
        $resultadoHoy = $getEstadisticas($hoy);
        $totalHoy = $resultadoHoy['total'] ?? 0;
        $cantidadHoy = $resultadoHoy['cantidad'] ?? 0;

        $resultadoSemana = $getEstadisticas($inicioSemana);
        $totalSemana = $resultadoSemana['total'] ?? 0;
        $cantidadSemana = $resultadoSemana['cantidad'] ?? 0;

        $resultadoMes = $getEstadisticas($inicioMes);
        $totalMes = $resultadoMes['total'] ?? 0;
        $cantidadMes = $resultadoMes['cantidad'] ?? 0;

        $resultadoAno = $getEstadisticas($inicioAno);
        $totalAno = $resultadoAno['total'] ?? 0;
        $cantidadAno = $resultadoAno['cantidad'] ?? 0;

        // Obtener deudas pendientes con filtros
        $estadoDeuda = $request->get('estadoDeuda', '');
        $deudaRepository = $this->entityManager->getRepository(\App\Entity\DeudaAlumno::class);
        $qbDeudas = $deudaRepository->createQueryBuilder('d')
            ->leftJoin('d.alumno', 'a')
            ->leftJoin('d.curso', 'c')
            ->leftJoin('d.aplicaciones', 'pa')
            ->andWhere('a.instituto = :instituto')
            // Sin filtro por alumno activo: al alumno que se fue debiendo hay que poder verle
            // y cobrarle la deuda. Antes desaparecía de esta pantalla al darlo de baja.
            ->setParameter('instituto', $instituto);

        // Mostrar todas las deudas con saldo pendiente (incluye futuras).
        // Esto permite gestionarlas/cancelarlas desde esta pantalla.
        
        // Aplicar filtros de búsqueda
        if ($busqueda) {
            $qbDeudas->andWhere('a.nombre LIKE :busqueda OR a.apellido LIKE :busqueda')
                     ->setParameter('busqueda', '%' . $busqueda . '%');
        }
        
        // Aplicar filtro de alumno para deudas (puede venir como 'alumno' o 'alumnoId' en la URL)
        if ($alumnoSelected) {
            $qbDeudas->andWhere('a.id = :alumnoSeleccionado')
                     ->setParameter('alumnoSeleccionado', $alumnoSelected);
        }
        
        if ($cursoSelected) {
            $qbDeudas->andWhere('c.id = :cursoDeuda')
                     ->setParameter('cursoDeuda', $cursoSelected);
        }

        // Filtro por período: deudas cuyo mes/año caen dentro del rango Desde-Hasta
        if ($fechaFiltroProvista && $fechaDesde && $fechaHasta) {
            try {
                $dateFormat = $this->institutoTimezoneService->getDateFormatForInstituto($instituto);
                $fechaDesdeObj = $this->institutoTimezoneService->parseDateString($fechaDesde, $dateFormat);
                $fechaHastaObj = $this->institutoTimezoneService->parseDateString($fechaHasta, $dateFormat);
                
                if (!$fechaDesdeObj || !$fechaHastaObj) {
                    throw new \Exception('Formato de fecha inválido');
                }
                
                $mesDesde = (int) $fechaDesdeObj->format('n');
                $anoDesde = (int) $fechaDesdeObj->format('Y');
                $mesHasta = (int) $fechaHastaObj->format('n');
                $anoHasta = (int) $fechaHastaObj->format('Y');
                $qbDeudas->andWhere(
                    '((d.ano > :anoDesde) OR (d.ano = :anoDesde AND d.mes >= :mesDesde)) AND ' .
                    '((d.ano < :anoHasta) OR (d.ano = :anoHasta AND d.mes <= :mesHasta))'
                )
                ->setParameter('anoDesde', $anoDesde)
                ->setParameter('mesDesde', $mesDesde)
                ->setParameter('anoHasta', $anoHasta)
                ->setParameter('mesHasta', $mesHasta);
            } catch (\Exception $e) {
                // Si hay error al parsear, no aplicar filtro
            }
        }
        
        // Ordenar por año y mes (más antiguas primero)
        $qbDeudas->orderBy('d.ano', 'ASC')
                 ->addOrderBy('d.mes', 'ASC')
                 ->addOrderBy('a.apellido', 'ASC')
                 ->addOrderBy('a.nombre', 'ASC');
        
        // Obtener todas las deudas y filtrarlas en memoria usando los métodos de la entidad
        $todasLasDeudas = $qbDeudas->getQuery()->getResult();
        
        // Filtrar por estado usando los métodos calculados de la entidad
        $deudasFiltradas = array_filter($todasLasDeudas, function($deuda) use ($estadoDeuda) {
            $montoPendiente = $deuda->getMontoPendiente();
            $montoPagado = $deuda->getMontoPagado();
            
            if ($estadoDeuda === 'pendientes') {
                // Solo deudas completamente pendientes (sin pagos aplicados)
                return $montoPagado == 0 && $montoPendiente > 0.01;
            } else {
                // Todas las deudas con monto pendiente (por defecto)
                return $montoPendiente > 0.01;
            }
        });
        
        // Reindexar el array después de filtrar
        $deudasFiltradas = array_values($deudasFiltradas);
        
        // Obtener el número de página para deudas (usar 'page' como nombre estándar)
        $pageDeudas = $request->query->getInt('pageDeudas', 1);
        
        // Usar el paginador de KNP directamente con el array
        // Pasamos el número de página directamente, igual que en DashboardController
        $deudasPendientes = $paginator->paginate(
            $deudasFiltradas,
            $pageDeudas,
            20
        );
        
        // Todos los alumnos del instituto para el filtro, activos o no: los inactivos pueden
        // tener deuda pendiente y hay que poder filtrar por ellos.
        $alumnos = $alumnoRepository->createQueryBuilder('a')
            ->where('a.instituto = :instituto')
            ->setParameter('instituto', $instituto)
            ->orderBy('a.apellido', 'ASC')
            ->addOrderBy('a.nombre', 'ASC')
            ->getQuery()
            ->getResult();

        // Totales de los montos mostrados en la tabla (página actual)
        $totalMontoPagos = 0;
        foreach ($pagination as $pago) {
            $totalMontoPagos += (float) $pago->getMonto();
        }
        $totalMontoDeudas = 0;
        foreach ($deudasPendientes as $deuda) {
            $totalMontoDeudas += $deuda->getMontoTotal();
        }

        return $this->render('alumnos_pagos/index.html.twig', [
            'pagos' => $pagination,
            'alumno' => $alumno,
            'nombreAlumno' => $nombreAlumno,
            'cursos' => $cursos,
            'cursoSelected' => $cursoSelected,
            'alumnos' => $alumnos,
            'alumnoSelected' => $alumnoSelected,
            'metodosPago' => $metodosPago,
            'metodoSelected' => $metodoSelected,
            'fechaDesde' => $fechaDesde,
            'fechaHasta' => $fechaHasta,
            'busqueda' => $busqueda,
            'sort' => $sort,
            'order' => $order,
            'alumnoId' => $alumnoId,
            'activeTab' => $activeTab,
            'total' => $pagination->getTotalItemCount(),
            'estadisticas' => [
                'hoy' => ['total' => $totalHoy, 'cantidad' => $cantidadHoy],
                'semana' => ['total' => $totalSemana, 'cantidad' => $cantidadSemana],
                'mes' => ['total' => $totalMes, 'cantidad' => $cantidadMes],
                'ano' => ['total' => $totalAno, 'cantidad' => $cantidadAno],
            ],
            'deudasPendientes' => $deudasPendientes,
            'estadoDeuda' => $estadoDeuda,
            'totalMontoPagos' => $totalMontoPagos,
            'totalMontoDeudas' => $totalMontoDeudas
        ]);
    }

    /**
     * Verifica los meses adeudados para un alumno y curso específico
     */
    private function verificarMesesAdeudadosPorCurso(Alumno $alumno, Curso $curso, int $ano): array
    {
        return $this->historialCursosService->verificarMesesAdeudadosPorCurso($alumno, $curso);
    }

    /**
     * Devuelve deudas pendientes calculadas on-demand hasta la fecha actual del instituto.
     */
    private function getDeudasPendientesOnDemand(Alumno $alumno, \DateTimeInterface $fechaActual): array
    {
        $mesActual = (int)$fechaActual->format('n');
        $anoActual = (int)$fechaActual->format('Y');
        $deudasCalculadas = $this->deudaCalculator->calcularDeudasAlumno($alumno);

        return array_values(array_filter($deudasCalculadas, function(array $deuda) use ($mesActual, $anoActual) {
            return ($deuda['ano'] < $anoActual)
                || ($deuda['ano'] == $anoActual && $deuda['mes'] <= $mesActual);
        }));
    }

    /**
     * Crea un mapa curso_mes_ano => deuda calculada on-demand para búsquedas rápidas.
     */
    private function getMapaDeudasOnDemand(Alumno $alumno): array
    {
        $mapa = [];
        foreach ($this->deudaCalculator->calcularDeudasAlumno($alumno) as $deuda) {
            $key = $deuda['curso']->getId() . '_' . $deuda['mes'] . '_' . $deuda['ano'];
            $mapa[$key] = $deuda;
        }

        return $mapa;
    }

    /**
     * Determina si el alumno tiene deudas vencidas usando cálculo on-demand.
     */
    private function tieneDeudasVencidasOnDemand(Alumno $alumno, \DateTimeInterface $fechaActual): bool
    {
        if (!$alumno->getActivo()) {
            return false;
        }

        $mesActual = (int)$fechaActual->format('n');
        $anoActual = (int)$fechaActual->format('Y');
        $diaActual = (int)$fechaActual->format('d');
        $primerDiaVencimiento = $alumno->getPrimerDiaVencimiento($alumno->getInstituto()->getId());

        foreach ($this->getDeudasPendientesOnDemand($alumno, $fechaActual) as $deuda) {
            if ($deuda['ano'] < $anoActual || ($deuda['ano'] == $anoActual && $deuda['mes'] < $mesActual)) {
                return true;
            }

            if ($deuda['ano'] == $anoActual && $deuda['mes'] == $mesActual && $diaActual >= $primerDiaVencimiento) {
                return true;
            }
        }

        return false;
    }

    /**
     * Obtiene precio mensual histórico del alumno para un curso en un mes dado.
     */
    private function getPrecioMensualHistoricoParaMes(Alumno $alumno, Curso $curso, int $mes, int $ano): float
    {
        $fechaMes = new \DateTimeImmutable(sprintf('%04d-%02d-01', $ano, $mes));

        foreach ($alumno->getCursosHistoricos() as $historico) {
            if ($historico->getCurso()->getId() !== $curso->getId()) {
                continue;
            }

            $fechaAlta = $historico->getFechaAlta();
            $fechaBaja = $historico->getFechaBaja();

            if ($fechaAlta) {
                $inicioAlta = (new \DateTimeImmutable($fechaAlta->format('Y-m-01')));
                if ($fechaMes < $inicioAlta) {
                    continue;
                }
            }

            if ($fechaBaja) {
                $finBaja = (new \DateTimeImmutable($fechaBaja->format('Y-m-01')));
                if ($fechaMes > $finBaja) {
                    continue;
                }
            }

            return (float)($historico->getPrecioMensual() ?? $curso->getPrecio());
        }

        return (float)$curso->getPrecio();
    }

    /**
     * Calcula el monto sugerido para un pago
     * @param string|null $metodoPago Si se pasa, el descuento por efectivo solo se aplica cuando es 'efectivo'
     */
    private function calcularMonto(Alumno $alumno, Curso $curso, $vencimientos, array $mesesAdeudados, array $descuentosPromocionalesSeleccionados = [], ?string $metodoPago = null): array
    {
        // Obtener fecha actual en la zona horaria del instituto
        $instituto = $alumno->getInstituto();
        $fechaActual = $this->institutoTimezoneService->getNowForInstituto($instituto);
        $mesActual = (int)$fechaActual->format('n');
        $anoActual = (int)$fechaActual->format('Y');
        $diaActual = (int)$fechaActual->format('d');

        // Calcular el monto base sumando los montos de cada mes adeudado
        // Esto respeta el precio histórico de cada deuda (importante para cursos con cambios de precio)
        $montoBase = 0;
        foreach ($mesesAdeudados as $mesData) {
            // Si el mes tiene un monto específico (deuda existente), usarlo
            // Si no, usar el precio actual del curso (para meses futuros sin deuda creada aún)
            if (isset($mesData['monto']) && $mesData['monto'] > 0) {
                $montoBase += $mesData['monto'];
            } else {
                $montoBase += $curso->getPrecio();
            }
        }
        $montoFinal = $montoBase;
        $porcentajeInteres = 0;
        $motivoInteres = "Sin interés aplicado";
        $descuentosAplicados = [];
        $porcentajeDescuentoTotal = 0;

        // Ordenar vencimientos por día
        $vencimientosOrdenados = [];
        foreach ($vencimientos as $vencimiento) {
            $vencimientosOrdenados[] = $vencimiento;
        }
        
        usort($vencimientosOrdenados, function($a, $b) {
            return $a->getDiaVencimiento() <=> $b->getDiaVencimiento();
        });

        // Determinar si hay meses estrictamente anteriores (no incluye el mes actual)
        $tieneMesesAnteriores = false;
        foreach ($mesesAdeudados as $mesData) {
            $mes = is_array($mesData) ? ($mesData['mes'] ?? null) : ($mesData->mes ?? null);
            $ano = is_array($mesData) ? ($mesData['ano'] ?? null) : ($mesData->ano ?? null);
            if ($mes !== null && $ano !== null && ($ano < $anoActual || ($ano == $anoActual && $mes < $mesActual))) {
                $tieneMesesAnteriores = true;
                break;
            }
        }

        // Solo aplicar interés máximo cuando hay meses anteriores vencidos (ej: pagando enero en febrero)
        // Para el mes actual vencido (ej: febrero pagado el día 13, entre día 10 y 20), usar el escalón correspondiente
        if ($tieneMesesAnteriores) {
            // Para deudas de meses anteriores, aplicar el máximo interés configurado
            $maxInteres = 0;
            foreach ($vencimientosOrdenados as $vencimiento) {
                if ($vencimiento->getPorcentajeInteres() > $maxInteres) {
                    $maxInteres = $vencimiento->getPorcentajeInteres();
                }
            }
            $porcentajeInteres = $maxInteres;
            $motivoInteres = "Máximo interés aplicado por deudas anteriores (" . count($mesesAdeudados) . " meses)";
        } else {
            // Mes actual o solo meses futuros: determinar escalón según el día de pago
            $vencimientoAplicado = null;
            foreach ($vencimientosOrdenados as $vencimiento) {
                if ($diaActual >= $vencimiento->getDiaVencimiento()) {
                    $vencimientoAplicado = $vencimiento;
                } else {
                    break;
                }
            }
            if ($vencimientoAplicado) {
                $porcentajeInteres = $vencimientoAplicado->getPorcentajeInteres();
                $motivoInteres = "Interés del " . $porcentajeInteres . "% por pago en/después del día " . $vencimientoAplicado->getDiaVencimiento();
            }
        }

        // Obtener la configuración del instituto
        $instituto = $alumno->getInstituto();
        $configuracion = $instituto->getConfiguracion();
        $ordenCalculo = $configuracion ? $configuracion->getOrdenCalculoInteresesDescuentos() : 'interes_primero';
        
        // Verificar si podemos aplicar descuentos
        $puedeRecibirDescuentos = true;
        
        // Si está configurado para deshabilitar descuentos generales en deuda
        if ($configuracion && $configuracion->getDeshabilitarDescuentosEnDeuda()) {
            // Verificar si el alumno tiene deudas vencidas
            if ($this->tieneDeudasVencidasOnDemand($alumno, $fechaActual)) {
                $puedeRecibirDescuentos = false;
                $descuentosAplicados[] = "No se aplican descuentos generales (efectivo/hermanos) porque el alumno tiene deudas vencidas";
            }
        }
        
        // Calcular porcentajes de descuento general (efectivo y hermanos)
        if ($puedeRecibirDescuentos && $configuracion) {
            // Aplicar descuento por pago en efectivo solo si el método de pago es efectivo (o no se especificó, p. ej. render inicial)
            $aplicarDescuentoEfectivo = ($metodoPago === null || strtolower($metodoPago) === 'efectivo');
            if ($aplicarDescuentoEfectivo && $configuracion->getDescuentoEfectivo() && $configuracion->getDescuentoEfectivo() > 0) {
                $porcentajeDescuentoEfectivo = (float)$configuracion->getDescuentoEfectivo();
                $porcentajeDescuentoTotal += $porcentajeDescuentoEfectivo;
                $descuentosAplicados[] = "Descuento del " . $porcentajeDescuentoEfectivo . "% por pago en efectivo";
            }
            
            // Aplicar descuento por hermanos si corresponde
            if ($configuracion->getDescuentoHermanos() && $configuracion->getDescuentoHermanos() > 0) {
                // Verificar si el alumno tiene hermanos en el instituto
                $hermanos = $alumno->getHermanos();
                if (!empty($hermanos)) {
                    $porcentajeDescuentoHermanos = (float)$configuracion->getDescuentoHermanos();
                    $porcentajeDescuentoTotal += $porcentajeDescuentoHermanos;
                    $descuentosAplicados[] = "Descuento del " . $porcentajeDescuentoHermanos . "% por tener " . count($hermanos) . " hermano(s) en el instituto";
                }
            }
        }

        // Descuentos promocionales: siempre se aplican, independientemente de si el alumno tiene deudas
        foreach ($descuentosPromocionalesSeleccionados as $descuentoPromocional) {
            $porcentajeDescuentoPromocional = (float)$descuentoPromocional->getPorcentaje();
            $porcentajeDescuentoTotal += $porcentajeDescuentoPromocional;
            $descuentosAplicados[] = "Descuento promocional: " . $descuentoPromocional->getNombre() . " (" . $porcentajeDescuentoPromocional . "%)";
        }
        
        // Aplicar intereses y descuentos según el orden configurado
        switch ($ordenCalculo) {
            case 'descuento_primero':
                // Descuentos primero, luego interés
                if ($porcentajeDescuentoTotal > 0) {
                    $montoFinal = $montoBase * (1 - ($porcentajeDescuentoTotal / 100));
                }
                if ($porcentajeInteres > 0) {
                    $montoFinal = $montoFinal * (1 + ($porcentajeInteres / 100));
                }
                break;
                
            case 'neto':
                // Ambos sobre base, diferencia neta
                $montoInteres = $porcentajeInteres > 0 ? $montoBase * ($porcentajeInteres / 100) : 0;
                $montoDescuento = $porcentajeDescuentoTotal > 0 ? $montoBase * ($porcentajeDescuentoTotal / 100) : 0;
                $montoFinal = $montoBase + $montoInteres - $montoDescuento;
                break;
                
            case 'descuentos_solo_base':
                // Descuentos sobre base, interés sobre base original
                if ($porcentajeDescuentoTotal > 0) {
                    $montoFinal = $montoBase * (1 - ($porcentajeDescuentoTotal / 100));
                }
                if ($porcentajeInteres > 0) {
                    $montoFinal = $montoFinal + ($montoBase * ($porcentajeInteres / 100));
                }
                break;
                
            case 'interes_primero':
            default:
                // Interés primero, luego descuentos (comportamiento actual)
                if ($porcentajeInteres > 0) {
                    $montoFinal = $montoBase * (1 + ($porcentajeInteres / 100));
                }
            if ($porcentajeDescuentoTotal > 0) {
                $montoFinal = $montoFinal * (1 - ($porcentajeDescuentoTotal / 100));
            }
                break;
        }
        
        return [
            'monto' => $montoFinal,
            'montoBase' => $montoBase,
            'porcentajeInteres' => $porcentajeInteres,
            'motivoInteres' => $motivoInteres,
            'mesesAdeudados' => array_values($mesesAdeudados),
            'descuentosAplicados' => $descuentosAplicados,
            'porcentajeDescuentoTotal' => $porcentajeDescuentoTotal,
            'puedeRecibirDescuentos' => $puedeRecibirDescuentos
        ];
    }

    /**
     * @Route("/new", name="app_alumnos_pagos_new", methods={"GET", "POST"})
     */
    public function new(Request $request, AlumnoRepository $alumnoRepository, DescuentoPromocionalRepository $descuentoPromocionalRepository): Response
    {
        $alumnoId = $request->query->get('id');
        if ($alumnoId) {
            // Cargar alumno con relaciones necesarias; las deudas se calculan on-demand
            $alumno = $alumnoRepository->findWithDeudasAndAplicaciones($alumnoId);
        } else {
            // Sin id en URL: no preseleccionar alumno; el usuario debe seleccionar manualmente
            $alumno = null;
        }
        $instituto = $this->getUser()->getInstituto();
        $alumnosPago = new AlumnosPagos();
        $alumnosPago->setAlumno($alumno);
        
        // Usar fecha civil del instituto, sin hora, para evitar corrimientos de día.
        $alumnosPago->setFecha($this->institutoTimezoneService->getCurrentDateForInstituto($instituto));
        $alumnosPago->setMetodoPago('efectivo');

        $mesesAdeudados = [];
        $ordenCalculo = 'interes_primero';

        if ($alumno) {
            // Obtener deudas pendientes calculadas on-demand
            $fechaActual = $this->institutoTimezoneService->getNowForInstituto($instituto);
            $deudasParaPago = $this->getDeudasPendientesOnDemand($alumno, $fechaActual);
            $mapaDeudasOnDemand = $this->getMapaDeudasOnDemand($alumno);
        $mesesAdeudados = [];
        $nombresMeses = [
            1 => 'Enero', 2 => 'Febrero', 3 => 'Marzo', 4 => 'Abril',
            5 => 'Mayo', 6 => 'Junio', 7 => 'Julio', 8 => 'Agosto',
            9 => 'Septiembre', 10 => 'Octubre', 11 => 'Noviembre', 12 => 'Diciembre'
        ];
        $mesActual = (int)$fechaActual->format('n');
        $anoActual = (int)$fechaActual->format('Y');
        $diaActual = (int)$fechaActual->format('d');
        $primerDiaVencimiento = $alumno->getPrimerDiaVencimiento($alumno->getInstituto()->getId());
        
        foreach ($deudasParaPago as $deuda) {
            $curso = $deuda['curso'];
            $esMesFuturo = ($deuda['ano'] > $anoActual)
                || ($deuda['ano'] == $anoActual && $deuda['mes'] > $mesActual);
            
            // Determinar si el mes está en mora según vencimientos del instituto
            $enMora = false;
            if (!$esMesFuturo) {
                if ($deuda['ano'] < $anoActual || ($deuda['ano'] == $anoActual && $deuda['mes'] < $mesActual)) {
                    $enMora = true; // Mes anterior: siempre en mora
                } elseif ($deuda['ano'] == $anoActual && $deuda['mes'] == $mesActual && $diaActual >= $primerDiaVencimiento) {
                    $enMora = true; // Mes actual pasado el vencimiento
                }
            }
            
            $mesesAdeudados[] = [
                'mes' => $deuda['mes'],
                'ano' => $deuda['ano'],
                'nombre' => $nombresMeses[$deuda['mes']] . ' ' . $deuda['ano'],
                'curso' => $curso->getNombre(),
                'curso_obj' => $curso,
                'monto' => $deuda['monto'],
                'montoConInteres' => $deuda['monto'] + $deuda['interes'],
                'esPendiente' => !$esMesFuturo,
                'esAdelantado' => $esMesFuturo,
                'enMora' => $enMora
            ];
        }
        
        // Agregar meses futuros para pagos adelantados
        // Se calculan por curso activo: desde el primer mes pendiente hasta la fecha fin del curso
        // (o hasta 12 meses adelante si el curso no tiene fecha fin definida)
        // Esto asegura que se muestren todos los meses, incluso los intermedios que no tienen deuda pendiente

        // Obtener meses ya pagados consultando directamente AlumnosPagos (on-demand)
        $qbPagados = $this->entityManager->createQueryBuilder();
        $qbPagados->select('IDENTITY(p.curso) AS cursoId, p.mes, p.ano, SUM(p.monto) AS totalPagado')
            ->from(\App\Entity\AlumnosPagos::class, 'p')
            ->where('p.alumno = :alumno')
            ->setParameter('alumno', $alumno)
            ->groupBy('p.curso, p.mes, p.ano');
        $pagosData = $qbPagados->getQuery()->getResult();
        
        // Construir mapa de pagos por curso/mes/año y mapa de precios mensuales por curso
        $mapaPagosPorCurso = [];
        foreach ($pagosData as $row) {
            $key = $row['cursoId'] . '_' . $row['mes'] . '_' . $row['ano'];
            $mapaPagosPorCurso[$key] = (float) $row['totalPagado'];
        }
        
        // Un mes se considera pagado si existe cualquier pago registrado,
        // independientemente del monto (descuentos pueden hacer que totalPagado < precio del curso).
        // Consistente con DeudaCalculatorService::existePagoParaMes().
        $mesesPagados = [];
        foreach ($mapaPagosPorCurso as $key => $totalPagado) {
            $mesesPagados[$key] = true;
        }

        // Obtener cursos activos del alumno para generar meses futuros
        $cursosHistoricos = $alumno->getCursosHistoricos();
        foreach ($cursosHistoricos as $historico) {
            if (!$historico->isActivo()) {
                continue;
            }
            
            $curso = $historico->getCurso();
            $fechaFinCurso = $historico->getFechaFinPeriodo();
            $fechaInicioCurso = $historico->getFechaInicioPeriodo();
            $precioMensualHistorico = (float)($historico->getPrecioMensual() ?? $curso->getPrecio());
            
            // Encontrar el primer mes pendiente para este curso
            $primerMesPendiente = null;
            foreach ($mesesAdeudados as $mesData) {
                if ($mesData['curso_obj']->getId() === $curso->getId()) {
                    // Medianoche UTC, igual que primerDiaDelMes(): createFromFormat('Y-m-d')
                    // dejaba la hora actual y desvirtuaba las comparaciones contra los
                    // primeros días de mes.
                    $fechaMes = new \DateTime(
                        sprintf('%04d-%02d-01 00:00:00', $mesData['ano'], $mesData['mes']),
                        new \DateTimeZone('UTC')
                    );
                    if ($primerMesPendiente === null || $fechaMes < $primerMesPendiente) {
                        $primerMesPendiente = $fechaMes;
                    }
                }
            }
            
            // Determinar desde dónde empezar a generar meses
            // No generar meses anteriores a la fecha de alta del alumno en el curso
            $fechaAltaHistorico = $historico->getFechaAlta();
            if (!$fechaAltaHistorico) {
                // Si no hay fecha de alta, usar la fecha de inicio del curso o la fecha actual
                $fechaAltaHistorico = $fechaInicioCurso ?: $fechaActual;
            }
            
            // Determinar el mes mínimo según la configuración de generación de deudas
            $modoGeneracionDeuda = $historico->getModoGeneracionDeuda();
            // IMPORTANTE: usar siempre \DateTime mutable construido a partir del primer día del mes.
            // $fechaAltaHistorico y $fechaActual pueden ser DateTimeImmutable, y en ese caso
            // modify()/setTime() no mutan el objeto y las fechas quedaban en "ahora".
            $fechaMinimaInicio = self::primerDiaDelMes($fechaAltaHistorico);

            if ($modoGeneracionDeuda === 'proximo_mes') {
                // Si está configurado para empezar desde el próximo mes, agregar 1 mes
                $fechaMinimaInicio->modify('+1 month');
            }
            // Si es 'inscripcion' (default), usar el mes de inscripción tal cual

            // Si hay meses pendientes, empezar desde el primero
            // Si no hay meses pendientes, empezar desde el mes siguiente al actual o desde el inicio del curso
            $fechaInicio = self::primerDiaDelMes($fechaActual);
            $fechaInicio->modify('+1 month'); // Por defecto, desde el mes siguiente al actual

            if ($primerMesPendiente) {
                // Si hay meses pendientes, empezar desde el primero para llenar todos los huecos
                // PERO nunca antes de la fecha de alta del alumno en el curso
                if ($primerMesPendiente < $fechaInicio) {
                    $fechaInicio = clone $primerMesPendiente;
                }
                if ($fechaInicio < $fechaMinimaInicio) {
                    $fechaInicio = clone $fechaMinimaInicio;
                }
            } elseif ($fechaInicioCurso) {
                // Si no hay meses pendientes pero hay fecha inicio del curso, empezar desde ahí
                $fechaInicioCursoPrimerDia = self::primerDiaDelMes($fechaInicioCurso);
                if ($fechaInicioCursoPrimerDia < $fechaInicio) {
                    $fechaInicio = $fechaInicioCursoPrimerDia;
                }
                // Pero nunca antes de la fecha de alta
                if ($fechaInicio < $fechaMinimaInicio) {
                    $fechaInicio = clone $fechaMinimaInicio;
                }
            }
            
            // Determinar hasta dónde generar meses futuros
            // Si el curso tiene fecha fin, usar esa fecha (permitir pagar todo el curso)
            // Si no tiene fecha fin, usar 12 meses adelante como límite
            if ($fechaFinCurso) {
                // Si el curso tiene fecha fin, permitir pagar hasta el fin del curso completo
                $fechaFin = self::primerDiaDelMes($fechaFinCurso);
            } else {
                // Si no tiene fecha fin, limitar a 12 meses adelante
                $fechaFin = self::primerDiaDelMes($fechaActual);
                $fechaFin->modify('+12 months');
            }

            // Debe ser mutable: el bucle avanza con $fechaVerificacion->modify('+1 month').
            $fechaVerificacion = self::primerDiaDelMes($fechaInicio);

            while ($fechaVerificacion <= $fechaFin) {
                $mesVerificar = (int)$fechaVerificacion->format('n');
                $anoVerificar = (int)$fechaVerificacion->format('Y');
                
                // Si el curso tiene fecha fin y ya pasó, no agregar más meses
                if ($fechaFinCurso && $fechaVerificacion > $fechaFinCurso) {
                    break;
                }
                
                // Verificar si este mes ya está en la lista (como deuda pendiente)
                $yaEnLista = false;
                foreach ($mesesAdeudados as $mesData) {
                    if ($mesData['curso_obj']->getId() === $curso->getId() &&
                        $mesData['mes'] == $mesVerificar &&
                        $mesData['ano'] == $anoVerificar) {
                        $yaEnLista = true;
                        break;
                    }
                }
                
                // Si no está en la lista, verificar si existe una deuda para este mes
                if (!$yaEnLista) {
                    // No generar meses anteriores a la fecha de alta del alumno en el curso
                    if ($fechaVerificacion < $fechaMinimaInicio) {
                        $fechaVerificacion->modify('+1 month');
                        continue;
                    }

                    // Un mes es "adelantado" si es posterior al mes actual del instituto
                    $esMesFuturo = ($anoVerificar > $anoActual)
                        || ($anoVerificar == $anoActual && $mesVerificar > $mesActual);

                    $keyDeuda = $curso->getId() . '_' . $mesVerificar . '_' . $anoVerificar;
                    $deudaCalculada = $mapaDeudasOnDemand[$keyDeuda] ?? null;

                    if ($deudaCalculada) {
                        // Deuda on-demand: pendiente si es el mes actual o anterior, adelantado si es futuro
                        $montoMostrar = $esMesFuturo
                            ? $precioMensualHistorico
                            : ($deudaCalculada['monto'] + $deudaCalculada['interes']);
                        $enMoraCalc = false;
                        if (!$esMesFuturo) {
                            if ($anoVerificar < $anoActual || ($anoVerificar == $anoActual && $mesVerificar < $mesActual)) {
                                $enMoraCalc = true;
                            } elseif ($anoVerificar == $anoActual && $mesVerificar == $mesActual && $diaActual >= $primerDiaVencimiento) {
                                $enMoraCalc = true;
                            }
                        }
                        $mesesAdeudados[] = [
                            'mes' => $mesVerificar,
                            'ano' => $anoVerificar,
                            'nombre' => $nombresMeses[$mesVerificar] . ' ' . $anoVerificar,
                            'curso' => $curso->getNombre(),
                            'curso_obj' => $curso,
                            'monto' => $montoMostrar,
                            'esPendiente' => !$esMesFuturo,
                            'esAdelantado' => $esMesFuturo,
                            'enMora' => $enMoraCalc
                        ];
                    } else {
                        // Sin deuda calculada: verificar si ya está pagado antes de agregar como adelantado
                        $keyPagado = $curso->getId() . '_' . $mesVerificar . '_' . $anoVerificar;
                        if (isset($mesesPagados[$keyPagado])) {
                            $fechaVerificacion->modify('+1 month');
                            continue;
                        }
                        $mesesAdeudados[] = [
                            'mes' => $mesVerificar,
                            'ano' => $anoVerificar,
                            'nombre' => $nombresMeses[$mesVerificar] . ' ' . $anoVerificar,
                            'curso' => $curso->getNombre(),
                            'curso_obj' => $curso,
                            'monto' => $precioMensualHistorico,
                            'esPendiente' => false,
                            'esAdelantado' => true,
                            'enMora' => false
                        ];
                    }
                }
                
                $fechaVerificacion->modify('+1 month');
            }
        }
        
        // Ordenar los meses por fecha para que aparezcan en orden cronológico
        usort($mesesAdeudados, function($a, $b) {
            $fechaA = new \DateTime($a['ano'] . '-' . str_pad($a['mes'], 2, '0', STR_PAD_LEFT) . '-01');
            $fechaB = new \DateTime($b['ano'] . '-' . str_pad($b['mes'], 2, '0', STR_PAD_LEFT) . '-01');
            return $fechaA <=> $fechaB;
        });
        
        // Estado por curso. Nota: $mesesAdeudados ya excluye los meses pagados, así que
        // "tiene entradas" equivale a "tiene meses sin pagar".
        //
        // - cursosVencidos:          cuántas cuotas ya pasaron su vencimiento.
        // - cursosPagoCompleto:      al día, es decir sin cuotas vencidas. La del mes en
        //                            curso todavía dentro del plazo no cuenta como deuda,
        //                            igual criterio que la pantalla de Gestión de Deudas.
        // - cursosPorVencer:         cuántas cuotas están pendientes pero aún en plazo.
        // - cursosTotalmentePagados: sin ningún mes sin pagar, ni vencido ni futuro.
        $cursosPagoCompleto = [];
        $cursosTotalmentePagados = [];
        $cursosVencidos = [];
        $cursosPorVencer = [];
        foreach ($cursosHistoricos as $historico) {
            if (!$historico->isActivo()) {
                continue;
            }
            $cursoId = $historico->getCurso()->getId();
            $vencidas = 0;
            $porVencer = 0;
            $tieneMesAdeudadoTotal = false;

            foreach ($mesesAdeudados as $mesData) {
                if ($mesData['curso_obj']->getId() !== $cursoId) {
                    continue;
                }

                $tieneMesAdeudadoTotal = true;

                if ($mesData['enMora'] ?? false) {
                    $vencidas++;
                } elseif ($mesData['esPendiente'] ?? false) {
                    // Del mes en curso y todavía dentro del plazo de vencimiento.
                    $porVencer++;
                }
            }

            // Los tres estados son mutuamente excluyentes. Antes un curso pagado completo
            // entraba también en $cursosPagoCompleto, y la caja "Cursos al Día" se dibujaba
            // vacía: el if que la muestra miraba si la lista tenía algo, pero el for de
            // adentro descartaba justamente a los que ya estaban pagados completos.
            if ($vencidas > 0) {
                $cursosVencidos[$cursoId] = $vencidas;
            } elseif ($tieneMesAdeudadoTotal) {
                // Al día: nada vencido, pero le quedan meses por pagar.
                $cursosPagoCompleto[$cursoId] = true;
                if ($porVencer > 0) {
                    $cursosPorVencer[$cursoId] = $porVencer;
                }
            } else {
                $cursosTotalmentePagados[$cursoId] = true;
            }
        }

        // Obtener el curso seleccionado si existe
        $cursoSeleccionado = null;
        if ($request->query->has('curso')) {
            $cursoId = $request->query->get('curso');
            $cursoSeleccionado = $this->entityManager->getRepository(Curso::class)->find($cursoId);
        }

        // Obtener los vencimientos
        $vencimientos = $alumno->getInstituto()->getVencimientos();
        
        // Obtener los descuentos promocionales activos
        $configuracion = $alumno->getInstituto()->getConfiguracion();
        $descuentosPromocionales = $descuentoPromocionalRepository->findActivosByConfiguracion($configuracion);

        // Si hay un curso seleccionado explícitamente en la URL, establecerlo
        if ($cursoSeleccionado) {
            $alumnosPago->setCurso($cursoSeleccionado);
        }

        // Obtener descuentos promocionales seleccionados del request
        $descuentosPromocionalesSeleccionados = [];
        if ($request->query->has('descuentos_promocionales')) {
            $descuentosIds = $request->query->get('descuentos_promocionales');
            if (is_array($descuentosIds)) {
                foreach ($descuentosIds as $id) {
                    $descuento = $descuentoPromocionalRepository->find($id);
                    if ($descuento && $descuento->getActivo()) {
                        $descuentosPromocionalesSeleccionados[] = $descuento;
                    }
                }
            }
        }   

        // Calcular el monto sugerido solo si hay un curso seleccionado explícitamente
        // Si no hay curso seleccionado, el cálculo se hará dinámicamente vía AJAX cuando el usuario seleccione meses
        $calculoMonto = null;
        if ($cursoSeleccionado) {
            // Usar solo los meses que realmente se muestran en el formulario (ya filtrados)
            $mesesAdeudadosCurso = [];
            foreach ($mesesAdeudados as $mesData) {
                if ($mesData['curso_obj']->getId() === $cursoSeleccionado->getId()) {
                    $mesesAdeudadosCurso[] = $mesData;
                }
            }
            $calculoMonto = $this->calcularMonto($alumno, $cursoSeleccionado, $vencimientos, $mesesAdeudadosCurso, $descuentosPromocionalesSeleccionados);
            $alumnosPago->setMonto($calculoMonto['monto']);
        }

        $cursosHistoricos = $alumno->getCursosHistoricos();
        // Indexado por id del curso, no acumulado: un alumno dado de baja y reinscripto
        // tiene dos históricos del mismo curso, y con una lista plana el panel de estado
        // recorría los dos e imprimía el curso repetido, con el mismo número de cuotas
        // ("Robotica - 3 cuotas vencidas" dos veces).
        $cursos = [];
        foreach ($cursosHistoricos as $cursoHistorico) {
            $curso = $cursoHistorico->getCurso();
            if ($curso === null) {
                continue;
            }
            $cursos[$curso->getId()] = $curso;
        }
        } else {
            // Sin alumno: valores por defecto para el formulario
            $instituto = $this->getUser()->getInstituto();
            $vencimientos = $instituto->getVencimientos();
            $configuracion = $instituto->getConfiguracion();
            $descuentosPromocionales = $descuentoPromocionalRepository->findActivosByConfiguracion($configuracion);
            $descuentosPromocionalesSeleccionados = [];
            $cursoSeleccionado = null;
            $calculoMonto = null;
            $cursos = [];
            $ordenCalculo = $configuracion ? $configuracion->getOrdenCalculoInteresesDescuentos() : 'interes_primero';
        }

        // Crear el formulario
        $alumnos = $alumnoRepository->findBy(['instituto' => $instituto]);
        $currentYear = $this->institutoTimezoneService->getNowForInstituto($instituto)->format('Y');

        $form = $this->createForm(AlumnosPagosType::class, $alumnosPago, [
            'alumnos' => $alumnos,
            'cursos' => array_values($cursos),
            'vencimientos' => $vencimientos,
            'current_year' => $currentYear,
            'instituto' => $instituto,
        ]);

        $form->handleRequest($request);

        if ($form->isSubmitted() && $alumnosPago->getFecha() !== null) {
            $alumnosPago->setFecha($this->institutoTimezoneService->normalizeDateOnly($alumnosPago->getFecha()));
        }

        if ($form->isSubmitted()) {
            // Verificar si está en modo pago múltiple
            $modoPagoMultiple = $request->request->get('modo_pago_multiple') === '1';
            $mesesAdeudadosSeleccionados = $request->request->get('meses_adeudados', []);
            $mesesSeleccionadosForm = $request->request->get('alumnos_pagos')['mes'] ?? [];
            
            // Priorizar checkboxes de meses_adeudados sobre el select múltiple
            // Si hay meses seleccionados en los checkboxes, usar esos (ya tienen formato mes_ano_cursoId)
            if (!empty($mesesAdeudadosSeleccionados) && is_array($mesesAdeudadosSeleccionados)) {
                $modoPagoMultiple = true;
                // Los valores ya están en el formato correcto: mes_ano_cursoId
            }
            // Si no hay checkboxes pero hay meses en el select múltiple, convertir esos
            elseif (!empty($mesesSeleccionadosForm) && is_array($mesesSeleccionadosForm)) {
                $modoPagoMultiple = true;
                // Convertir meses del formulario a formato meses_adeudados
                $mesesAdeudadosSeleccionados = [];
                $anoSeleccionado = $request->request->get('alumnos_pagos')['ano'] ?? $this->institutoTimezoneService->getNowForInstituto($instituto)->format('Y');
                $cursoSeleccionadoId = $request->request->get('alumnos_pagos')['curso'] ?? null;
                
                // Si no hay curso en el formulario, intentar obtenerlo del primer mes adeudado
                if (!$cursoSeleccionadoId && !empty($mesesAdeudados)) {
                    $primerMes = $mesesAdeudados[0];
                    $cursoSeleccionadoId = $primerMes['curso_obj']->getId();
                }
                
                foreach ($mesesSeleccionadosForm as $mes) {
                    if ($cursoSeleccionadoId) {
                        $mesesAdeudadosSeleccionados[] = $mes . '_' . $anoSeleccionado . '_' . $cursoSeleccionadoId;
                    }
                }
            }
            
            // Si está en modo múltiple, saltar la validación del formulario normal
            if ($modoPagoMultiple && !empty($mesesAdeudadosSeleccionados)) {
                // Procesar múltiples pagos
                try {
                    $descuentosPromocionalesIds = $request->request->get('descuentos_promocionales', []);
                    if (!is_array($descuentosPromocionalesIds)) {
                        $descuentosPromocionalesIds = [];
                    }
                    
                    // Obtener descuentos promocionales seleccionados
                    $descuentosPromocionalesSeleccionados = [];
                    foreach ($descuentosPromocionalesIds as $id) {
                        $descuento = $descuentoPromocionalRepository->find($id);
                        if ($descuento && $descuento->getActivo()) {
                            $descuentosPromocionalesSeleccionados[] = $descuento;
                        }
                    }
                    
                    // Calcular el monto total separando meses vencidos de no vencidos
                    // El interés solo se aplica sobre meses vencidos
                    $fechaActual = $this->institutoTimezoneService->getNowForInstituto($instituto);
                    $mesActual = (int)$fechaActual->format('n');
                    $anoActual = (int)$fechaActual->format('Y');
                    $diaActual = (int)$fechaActual->format('d');
                    
                    // Obtener el día de vencimiento para determinar si el mes actual ya venció
                    $institutoId = $alumno->getInstituto()->getId();
                    $primerDiaVencimiento = $alumno->getPrimerDiaVencimiento($institutoId);
                    $mapaDeudasOnDemand = $this->getMapaDeudasOnDemand($alumno);
                    
                    $montoTotalBaseVencidos = 0;
                    $montoTotalBaseNoVencidos = 0;
                    $mesesInfo = [];
                    $maxInteres = 0;
                    $motivoInteres = "Sin interés aplicado";
                    
                    // Separar meses vencidos de no vencidos y calcular montos
                    foreach ($mesesAdeudadosSeleccionados as $mesAdeudado) {
                        list($mes, $ano, $cursoId) = explode('_', $mesAdeudado);
                        $mes = (int)$mes;
                        $ano = (int)$ano;
                        $curso = $this->entityManager->getRepository(Curso::class)->find($cursoId);
                        
                        if (!$curso) {
                            continue;
                        }
                        
                        $keyDeuda = $cursoId . '_' . $mes . '_' . $ano;
                        $deudaCalculada = $mapaDeudasOnDemand[$keyDeuda] ?? null;

                        // Usar monto calculado on-demand o precio histórico para meses adelantados
                        $montoBaseCurso = $deudaCalculada
                            ? (float)$deudaCalculada['monto']
                            : $this->getPrecioMensualHistoricoParaMes($alumno, $curso, $mes, $ano);
                        
                        // Determinar si el mes está vencido
                        $esVencido = false;
                        if ($ano < $anoActual || ($ano == $anoActual && $mes < $mesActual)) {
                            // Mes pasado, siempre vencido
                            $esVencido = true;
                        } elseif ($ano == $anoActual && $mes == $mesActual && $diaActual > $primerDiaVencimiento) {
                            // Mes actual pero ya venció
                            $esVencido = true;
                        }
                        
                        if ($esVencido) {
                            $montoTotalBaseVencidos += $montoBaseCurso;
                        } else {
                            $montoTotalBaseNoVencidos += $montoBaseCurso;
                        }
                        
                        $mesesInfo[] = [
                            'mes' => $mes,
                            'ano' => $ano,
                            'curso' => $curso,
                            'montoBase' => $montoBaseCurso,
                            'esVencido' => $esVencido
                        ];
                    }
                    
                    // Ahora calcular interés basándose en los meses vencidos seleccionados
                    // Agrupar meses vencidos por curso
                    $mesesVencidosPorCurso = [];
                    foreach ($mesesInfo as $mesInfo) {
                        if ($mesInfo['esVencido']) {
                            $cursoId = $mesInfo['curso']->getId();
                            if (!isset($mesesVencidosPorCurso[$cursoId])) {
                                $mesesVencidosPorCurso[$cursoId] = [
                                    'curso' => $mesInfo['curso'],
                                    'meses' => []
                                ];
                            }
                            $mesesVencidosPorCurso[$cursoId]['meses'][] = [
                                'mes' => $mesInfo['mes'],
                                'ano' => $mesInfo['ano'],
                                'curso' => $mesInfo['curso']->getNombre(),
                                'curso_obj' => $mesInfo['curso']
                            ];
                        }
                    }
                    
                    // Calcular interés para cada curso que tiene meses vencidos
                    foreach ($mesesVencidosPorCurso as $cursoId => $datosCurso) {
                        if (!empty($datosCurso['meses'])) {
                            // El método calcularMonto espera un array de meses adeudados
                            // Si hay meses vencidos seleccionados, debe aplicar el máximo interés
                            $calculoTemporal = $this->calcularMonto($alumno, $datosCurso['curso'], $vencimientos, $datosCurso['meses'], []);
                            if ($calculoTemporal['porcentajeInteres'] > $maxInteres) {
                                $maxInteres = $calculoTemporal['porcentajeInteres'];
                                $motivoInteres = $calculoTemporal['motivoInteres'];
                            }
                        }
                    }
                    
                    // Si hay meses vencidos pero no se calculó interés, forzar el cálculo del máximo interés
                    if ($montoTotalBaseVencidos > 0 && $maxInteres == 0) {
                        // Obtener el máximo interés configurado
                        $vencimientosOrdenados = [];
                        foreach ($vencimientos as $vencimiento) {
                            $vencimientosOrdenados[] = $vencimiento;
                        }
                        usort($vencimientosOrdenados, function($a, $b) {
                            return $a->getDiaVencimiento() <=> $b->getDiaVencimiento();
                        });
                        
                        foreach ($vencimientosOrdenados as $vencimiento) {
                            if ($vencimiento->getPorcentajeInteres() > $maxInteres) {
                                $maxInteres = $vencimiento->getPorcentajeInteres();
                            }
                        }
                        
                        if ($maxInteres > 0) {
                            $motivoInteres = "Máximo interés aplicado por meses vencidos seleccionados";
                        }
                    }
                    
                    $montoTotalBase = $montoTotalBaseVencidos + $montoTotalBaseNoVencidos;
                    $porcentajeInteres = $maxInteres;
                    
                    // Obtener configuración del orden de cálculo
                    $configuracion = $alumno->getInstituto()->getConfiguracion();
                    $ordenCalculo = $configuracion ? $configuracion->getOrdenCalculoInteresesDescuentos() : 'interes_primero';
                    
                    // Calcular porcentajes de descuento promocional
                    $porcentajeDescuentoTotal = 0;
                    $descuentosAplicados = [];
                    foreach ($descuentosPromocionalesSeleccionados as $descuentoPromocional) {
                        $porcentajeDescuentoPromocional = (float)$descuentoPromocional->getPorcentaje();
                        $porcentajeDescuentoTotal += $porcentajeDescuentoPromocional;
                        $descuentosAplicados[] = "Descuento promocional: " . $descuentoPromocional->getNombre() . " (" . $porcentajeDescuentoPromocional . "%)";
                    }
                    
                    // Aplicar intereses solo sobre meses vencidos, luego aplicar descuentos sobre el total
                    // El interés solo se aplica sobre $montoTotalBaseVencidos
                    $montoVencidosConInteres = $montoTotalBaseVencidos;
                    if ($porcentajeInteres > 0 && $montoTotalBaseVencidos > 0) {
                        $montoVencidosConInteres = $montoTotalBaseVencidos * (1 + ($porcentajeInteres / 100));
                    }
                    
                    // Monto total antes de descuentos = meses vencidos con interés + meses no vencidos sin interés
                    $montoTotalConInteres = $montoVencidosConInteres + $montoTotalBaseNoVencidos;
                    
                    // Aplicar descuentos sobre el total según el orden configurado
                    switch ($ordenCalculo) {
                        case 'descuento_primero':
                            // Descuentos primero sobre el total, luego interés (ya aplicado solo a vencidos)
                            $montoTotalFinal = $montoTotalBase;
                            if ($porcentajeDescuentoTotal > 0) {
                                $montoTotalFinal = $montoTotalBase * (1 - ($porcentajeDescuentoTotal / 100));
                            }
                            // Aplicar interés solo sobre la parte vencida después del descuento
                            if ($porcentajeInteres > 0 && $montoTotalBaseVencidos > 0) {
                                $montoVencidosConDescuento = $montoTotalBaseVencidos * (1 - ($porcentajeDescuentoTotal / 100));
                                $montoVencidosConInteresYDescuento = $montoVencidosConDescuento * (1 + ($porcentajeInteres / 100));
                                $montoTotalFinal = $montoVencidosConInteresYDescuento + ($montoTotalBaseNoVencidos * (1 - ($porcentajeDescuentoTotal / 100)));
                            }
                            break;
                            
                        case 'neto':
                            // Ambos sobre base, diferencia neta
                            $montoInteres = $porcentajeInteres > 0 ? $montoTotalBaseVencidos * ($porcentajeInteres / 100) : 0;
                            $montoDescuento = $porcentajeDescuentoTotal > 0 ? $montoTotalBase * ($porcentajeDescuentoTotal / 100) : 0;
                            $montoTotalFinal = $montoTotalBase + $montoInteres - $montoDescuento;
                            break;
                            
                        case 'descuentos_solo_base':
                            // Descuentos sobre base total, interés solo sobre base vencida original
                            $montoTotalFinal = $montoTotalBase;
                            if ($porcentajeDescuentoTotal > 0) {
                                $montoTotalFinal = $montoTotalBase * (1 - ($porcentajeDescuentoTotal / 100));
                            }
                            if ($porcentajeInteres > 0 && $montoTotalBaseVencidos > 0) {
                                $montoTotalFinal = $montoTotalFinal + ($montoTotalBaseVencidos * ($porcentajeInteres / 100));
                            }
                            break;
                            
                        case 'interes_primero':
                        default:
                            // Interés primero solo sobre vencidos, luego descuentos sobre el total
                            $montoTotalFinal = $montoTotalConInteres;
                            if ($porcentajeDescuentoTotal > 0) {
                                $montoTotalFinal = $montoTotalConInteres * (1 - ($porcentajeDescuentoTotal / 100));
                            }
                            break;
                    }
                    
                    // Validar que no se paguen meses futuros sin pagar los anteriores
                    $fechaActual = $this->institutoTimezoneService->getNowForInstituto($instituto);
                    $mesActual = (int)$fechaActual->format('n');
                    $anoActual = (int)$fechaActual->format('Y');
                    
                    // Agrupar meses por curso para validar consecutividad
                    $mesesPorCurso = [];
                    foreach ($mesesInfo as $mesInfo) {
                        $cursoId = $mesInfo['curso']->getId();
                        if (!isset($mesesPorCurso[$cursoId])) {
                            $mesesPorCurso[$cursoId] = [];
                        }
                        $mesesPorCurso[$cursoId][] = $mesInfo;
                    }
                    
                    // Validar cada curso
                    foreach ($mesesPorCurso as $cursoId => $mesesCurso) {
                        // Ordenar meses por fecha
                        usort($mesesCurso, function($a, $b) {
                            $fechaA = new \DateTime($a['ano'] . '-' . str_pad($a['mes'], 2, '0', STR_PAD_LEFT) . '-01');
                            $fechaB = new \DateTime($b['ano'] . '-' . str_pad($b['mes'], 2, '0', STR_PAD_LEFT) . '-01');
                            return $fechaA <=> $fechaB;
                        });
                        
                        // Verificar que no haya "huecos" en los meses seleccionados
                        $fechaPrimerMes = new \DateTime($mesesCurso[0]['ano'] . '-' . str_pad($mesesCurso[0]['mes'], 2, '0', STR_PAD_LEFT) . '-01');
                        $fechaUltimoMes = new \DateTime($mesesCurso[count($mesesCurso) - 1]['ano'] . '-' . str_pad($mesesCurso[count($mesesCurso) - 1]['mes'], 2, '0', STR_PAD_LEFT) . '-01');
                        $fechaActualMes = new \DateTime($anoActual . '-' . str_pad($mesActual, 2, '0', STR_PAD_LEFT) . '-01');
                        
                        // Si el último mes seleccionado es futuro, verificar que todos los meses anteriores estén seleccionados o pagados
                        if ($fechaUltimoMes > $fechaActualMes) {
                            $fechaVerificacion = clone $fechaActualMes;
                            $fechaVerificacion->modify('+1 month'); // Empezar desde el mes siguiente al actual
                            
                            while ($fechaVerificacion < $fechaUltimoMes) {
                                $mesVerificar = (int)$fechaVerificacion->format('n');
                                $anoVerificar = (int)$fechaVerificacion->format('Y');
                                
                                // Verificar si este mes está seleccionado
                                $mesSeleccionado = false;
                                foreach ($mesesCurso as $mesInfo) {
                                    if ($mesInfo['mes'] == $mesVerificar && $mesInfo['ano'] == $anoVerificar) {
                                        $mesSeleccionado = true;
                                        break;
                                    }
                                }
                                
                                // Si no está seleccionado, verificar si existe deuda pendiente on-demand
                                if (!$mesSeleccionado) {
                                    $cursoRef = $mesesCurso[0]['curso'];
                                    $keyDeudaAnterior = $cursoRef->getId() . '_' . $mesVerificar . '_' . $anoVerificar;
                                    $deudaAnterior = $mapaDeudasOnDemand[$keyDeudaAnterior] ?? null;

                                    if ($deudaAnterior && ((float)$deudaAnterior['monto'] + (float)$deudaAnterior['interes']) > 0.01) {
                                        $nombreMes = ['', 'Enero', 'Febrero', 'Marzo', 'Abril', 'Mayo', 'Junio', 
                                                     'Julio', 'Agosto', 'Septiembre', 'Octubre', 'Noviembre', 'Diciembre'][$mesVerificar];
                                        $this->addFlash('danger', "No puede pagar meses futuros sin pagar primero los meses anteriores pendientes. El mes de {$nombreMes} {$anoVerificar} está pendiente y debe ser pagado o seleccionado antes de pagar meses futuros.");
                                        return $this->redirectToRoute('app_alumnos_pagos_new', [
                                            'alumno' => $alumno->getId(),
                                            'curso' => $cursoSeleccionado ? $cursoSeleccionado->getId() : null
                                        ]);
                                    }
                                }
                                
                                $fechaVerificacion->modify('+1 month');
                            }
                        }
                    }
                    
                    // Distribuir el monto total proporcionalmente entre los meses
                    // Usar el monto ingresado por el usuario (Precio a Cobrar), no el calculado
                    $montoUsuario = $alumnosPago->getMonto() > 0 ? $alumnosPago->getMonto() : $montoTotalFinal;
                    $pagosCreados = 0;
                    $errores = [];

                    foreach ($mesesInfo as $mesInfo) {
                        // Calcular monto proporcional para este mes
                        $proporcion = $mesInfo['montoBase'] / $montoTotalBase;
                        $montoMes = $montoUsuario * $proporcion;
                        
                        // Crear nuevo pago
                        $nuevoPago = new AlumnosPagos();
                        $nuevoPago->setAlumno($alumno);
                        $nuevoPago->setCurso($mesInfo['curso']);
                        $nuevoPago->setFecha($alumnosPago->getFecha());
                        $nuevoPago->setMes($mesInfo['mes']);
                        $nuevoPago->setAno($mesInfo['ano']);
                        $nuevoPago->setMonto($montoMes);
                        $nuevoPago->setMetodoPago($alumnosPago->getMetodoPago());
                        $nuevoPago->setObservacion($alumnosPago->getObservacion());
                        
                        // Registrar el pago usando PagoService (on-demand: busca o crea la deuda)
                        try {
                            $resultado = $this->pagoService->registrarPago($nuevoPago, null, true);
                            $pagoRegistrado = $resultado['pago'];
                            $pagosCreados++;
                            
                            // Enviar email con el recibo si está configurado
                            try {
                                $this->notificationService->enviarReciboPago($pagoRegistrado, null, false, $this->getUser());
                            } catch (\Exception $emailError) {
                                // No interrumpir el flujo si falla el envío del email
                            }
                            
                            // Con la política actual: si se paga un monto menor al debido, la deuda se cancela igual
                            // (PagoService ajusta el total de la deuda al monto pagado). No hay pagos parciales.
                        } catch (\Exception $e) {
                            $errores[] = "Error al registrar pago para {$mesInfo['mes']}/{$mesInfo['ano']}: " . $e->getMessage();
                        }
                    }
                    
                    if ($pagosCreados > 0) {
                        $this->addFlash('success', "Se crearon $pagosCreados pago(s) correctamente.");
                        if (!empty($errores)) {
                            $this->addFlash('warning', 'Algunos pagos no pudieron ser procesados: ' . implode(', ', $errores));
                        }
                        return $this->redirectToRoute('app_alumnos_pagos_index', ['alumno' => $alumno->getId()]);
                    } else {
                        $this->addFlash('danger', 'No se pudo crear ningún pago. ' . implode(', ', $errores));
                    }
                } catch (\Exception $e) {
                    $this->addFlash('danger', 'Ocurrió un error al crear los pagos: ' . $e->getMessage());
                }
            } elseif ($form->isValid() || ($modoPagoMultiple && empty($mesesAdeudadosSeleccionados))) {
                // Procesar pago único (lógica existente)
                // Si está en modo múltiple pero no hay meses seleccionados, mostrar error
                if ($modoPagoMultiple && empty($mesesAdeudadosSeleccionados)) {
                    $this->addFlash('danger', 'Debe seleccionar al menos un mes para pagar.');
                } else {
                    try {
                        // Obtener mes y año del formulario (puede venir como array o valor único)
                        $mesForm = $request->request->get('alumnos_pagos')['mes'] ?? null;
                        $anoForm = $request->request->get('alumnos_pagos')['ano'] ?? null;
                        
                        // Si mes viene como array, tomar el primero (modo único)
                        if (is_array($mesForm) && !empty($mesForm)) {
                            $alumnosPago->setMes((int)$mesForm[0]);
                        } elseif ($mesForm) {
                            $alumnosPago->setMes((int)$mesForm);
                        } else {
                            // Si no viene mes del formulario, extraerlo de la fecha
                            if ($alumnosPago->getFecha()) {
                                $alumnosPago->setMes((int)$alumnosPago->getFecha()->format('n'));
                            } else {
                                throw new \InvalidArgumentException('El mes es obligatorio. Debe seleccionar un mes o proporcionar una fecha válida.');
                            }
                        }
                        
                        if ($anoForm) {
                            $alumnosPago->setAno((int)$anoForm);
                        } else {
                            // Si no viene año del formulario, extraerlo de la fecha
                            if ($alumnosPago->getFecha()) {
                                $alumnosPago->setAno((int)$alumnosPago->getFecha()->format('Y'));
                            } else {
                                throw new \InvalidArgumentException('El año es obligatorio. Debe seleccionar un año o proporcionar una fecha válida.');
                            }
                        }
                        
                        // Validar que mes y año estén establecidos antes de continuar
                        if ($alumnosPago->getMes() === null || $alumnosPago->getAno() === null) {
                            throw new \InvalidArgumentException('El mes y el año son obligatorios para registrar un pago.');
                        }
                        
                        // Obtener descuentos promocionales seleccionados del formulario
                        $descuentosPromocionalesIds = $request->request->get('descuentos_promocionales', []);
                        if (!is_array($descuentosPromocionalesIds)) {
                            $descuentosPromocionalesIds = [];
                        }
                        
                        // No sobrescribir el monto: usar el valor ingresado por el usuario en "Precio a Cobrar"
                        // ($alumnosPago ya tiene el monto del formulario vía handleRequest)

                    // Verificar si ya existe un pago para este alumno, curso, mes y año
                    $pagoExistente = $this->entityManager->getRepository(AlumnosPagos::class)->findOneBy([
                        'alumno' => $alumnosPago->getAlumno(),
                        'curso' => $alumnosPago->getCurso(),
                        'mes' => $alumnosPago->getMes(),
                        'ano' => $alumnosPago->getAno()
                    ]);

                    if ($pagoExistente) {
                        $this->addFlash('danger', 'Ya existe un pago registrado para este alumno en este curso para el mes y año seleccionados.');
                        return $this->redirectToRoute('app_alumnos_pagos_new', [
                            'id' => $alumno->getId(),
                            'curso' => $alumnosPago->getCurso()->getId()
                        ]);
                    }

                    // Verificar tokens antes de registrar el pago
                    $instituto = $alumno->getInstituto();
                    if (!$this->tokenService->hasEnoughTokens($instituto, 'pago.create')) {
                        $this->addFlash('danger', 'No tienes suficientes tokens para registrar un pago. Balance actual: ' . $this->tokenService->getBalance($instituto)->getBalance());
                        return $this->redirectToRoute('app_alumnos_pagos_new', [
                            'id' => $alumno->getId(),
                            'curso' => $alumnosPago->getCurso()->getId()
                        ]);
                    }

                    // Registrar el pago usando PagoService (on-demand: busca o crea la deuda)
                    $resultado = $this->pagoService->registrarPago($alumnosPago, null, true);
                    $alumnosPago = $resultado['pago'];
                    
                    // Consumir tokens después de guardar exitosamente
                    $this->tokenService->consumeTokens(
                        $instituto,
                        'pago.create',
                        $this->getUser(),
                        'Registrar pago: ' . $alumno->getNombreApellido() . ' - ' . $alumnosPago->getCurso()->getNombre() . ' (' . $alumnosPago->getMes() . '/' . $alumnosPago->getAno() . ')',
                        'AlumnosPagos',
                        $alumnosPago->getId()
                    );
                    
                    // Enviar email con el recibo si está configurado
                    try {
                        $enviado = $this->notificationService->enviarReciboPago($alumnosPago, null, false, $this->getUser());
                        if ($enviado) {
                            $this->addFlash('info', 'Se envió el recibo por email a ' . $alumno->getEmail());
                        }
                    } catch (\Exception $e) {
                        // No interrumpir el flujo si falla el envío del email
                        $this->addFlash('warning', 'El pago se registró pero no se pudo enviar el email: ' . $e->getMessage());
                    }
                    
                    $this->addFlash('success', 'Pago creado correctamente.');
                    return $this->redirectToRoute('app_alumnos_pagos_index', ['alumno' => $alumno->getId()]);
                } catch (\Exception $e) {
                        $this->addFlash('danger', 'Ocurrió un error al crear el pago: ' . $e->getMessage());
                    }
                }
            } else {
                foreach ($form->getErrors(true) as $error) {
                    $this->addFlash('danger', $error->getMessage());
                }
            }
        }

        // Obtener configuración del orden de cálculo (ordenCalculo ya fue asignado en el if/else de alumno)
        if ($alumno) {
            $configuracion = $alumno->getInstituto()->getConfiguracion();
            $ordenCalculo = $configuracion ? $configuracion->getOrdenCalculoInteresesDescuentos() : 'interes_primero';
        }

        return $this->render('alumnos_pagos/new.html.twig', [
            'form' => $form->createView(),
            'alumno' => $alumno,
            'cursos' => array_values($cursos),
            'curso' => $cursoSeleccionado,
            'vencimientos' => $vencimientos,
            'is_general' => false,
            'mesesAdeudados' => $mesesAdeudados,
            'motivoInteres' => isset($calculoMonto) ? $calculoMonto['motivoInteres'] : null,
            'porcentajeInteres' => isset($calculoMonto) ? $calculoMonto['porcentajeInteres'] : 0,
            'descuentosAplicados' => isset($calculoMonto) ? $calculoMonto['descuentosAplicados'] : [],
            'porcentajeDescuentoTotal' => isset($calculoMonto) ? $calculoMonto['porcentajeDescuentoTotal'] : 0,
            'descuentosPromocionales' => $descuentosPromocionales,
            'descuentosPromocionalesSeleccionados' => $descuentosPromocionalesSeleccionados,
            'calculoMonto' => $calculoMonto,
            'ordenCalculo' => $ordenCalculo,
            'fechaActualInstituto' => $this->institutoTimezoneService->getNowForInstituto($instituto)->format('Y-m-d'),
            'puedeRecibirDescuentos' => isset($calculoMonto) ? $calculoMonto['puedeRecibirDescuentos'] : true,
            'cursosPagoCompleto' => $cursosPagoCompleto ?? [],
            'cursosTotalmentePagados' => $cursosTotalmentePagados ?? [],
            'cursosVencidos' => $cursosVencidos ?? [],
            'cursosPorVencer' => $cursosPorVencer ?? [],
        ]);
    }

    /**
     * @Route("/calcular-monto", name="app_alumnos_pagos_calcular_monto", methods={"POST"})
     */
    public function calcularMontoAjax(
        Request $request,
        AlumnoRepository $alumnoRepository,
        DescuentoPromocionalRepository $descuentoPromocionalRepository
    ): JsonResponse {
        try {
            $alumnoId = $request->request->get('alumno_id');
            $cursoId = $request->request->get('curso_id');
            $mesesSeleccionados = $request->request->get('meses_seleccionados', []); // Array de meses en formato "mes_ano_cursoId"
            $descuentosPromocionalesIds = $request->request->get('descuentos_promocionales', []);
            
            if (!$alumnoId) {
                return new JsonResponse(['error' => 'Falta el ID del alumno'], 400);
            }
            
            // Cargar alumno con sus deudas y aplicaciones (eager loading) para evitar lazy loading
            $alumno = $alumnoRepository->findWithDeudasAndAplicaciones($alumnoId);
            if (!$alumno) {
                return new JsonResponse(['error' => 'Alumno no encontrado'], 404);
            }
            
            // Verificar que el usuario tiene acceso al instituto
            $institutoUsuario = $this->getUser()->getInstituto();
            if ($alumno->getInstituto() !== $institutoUsuario) {
                return new JsonResponse(['error' => 'No tiene acceso a este instituto'], 403);
            }
            
            // Obtener descuentos promocionales seleccionados
            $descuentosPromocionalesSeleccionados = [];
            if (is_array($descuentosPromocionalesIds)) {
                foreach ($descuentosPromocionalesIds as $id) {
                    $descuento = $descuentoPromocionalRepository->find($id);
                    if ($descuento && $descuento->getActivo() && $descuento->getConfiguracion()->getInstituto() === $alumno->getInstituto()) {
                        $descuentosPromocionalesSeleccionados[] = $descuento;
                    }
                }
            }
            
            $instituto = $alumno->getInstituto();
            $vencimientos = $instituto->getVencimientos()->toArray();
        
        // Si hay múltiples meses seleccionados, calcular el total
        if (!empty($mesesSeleccionados) && is_array($mesesSeleccionados)) {
            // Agrupar meses por curso
            $mesesPorCurso = [];
            foreach ($mesesSeleccionados as $mesData) {
                list($mes, $ano, $cursoId) = explode('_', $mesData);
                if (!isset($mesesPorCurso[$cursoId])) {
                    $mesesPorCurso[$cursoId] = [];
                }
                $mesesPorCurso[$cursoId][] = ['mes' => $mes, 'ano' => $ano];
            }
            
            // Mapa de deudas calculadas on-demand para acceso rápido
            $mapaDeudas = $this->getMapaDeudasOnDemand($alumno);
            
            // Sumar montos por mes seleccionado usando cálculo on-demand
            $montoTotalConInteres = 0;
            $montoTotalBase = 0;
            
            foreach ($mesesPorCurso as $cursoId => $meses) {
                $curso = $this->entityManager->getRepository(Curso::class)->find($cursoId);
                if (!$curso) {
                    continue;
                }
                
                foreach ($meses as $mesData) {
                    $mes = (int)$mesData['mes'];
                    $ano = (int)$mesData['ano'];
                    $key = $cursoId . '_' . $mes . '_' . $ano;
                    $deudaCalculada = $mapaDeudas[$key] ?? null;
                    
                    if ($deudaCalculada) {
                        $montoTotalConInteres += $deudaCalculada['monto'] + $deudaCalculada['interes'];
                        $montoTotalBase += $deudaCalculada['monto'];
                    } else {
                        $precioHistorico = $this->getPrecioMensualHistoricoParaMes($alumno, $curso, $mes, $ano);
                        $montoTotalConInteres += $precioHistorico;
                        $montoTotalBase += $precioHistorico;
                    }
                }
            }
            
            // Calcular el detalle de intereses aplicados
            $porcentajeInteres = 0;
            $motivoInteres = "";
            $interesesDetalle = [];
            $totalInteres = 0;
            
            foreach ($mesesPorCurso as $cursoId => $meses) {
                foreach ($meses as $mesData) {
                    $mes = (int)$mesData['mes'];
                    $ano = (int)$mesData['ano'];
                    $key = $cursoId . '_' . $mes . '_' . $ano;
                    $deudaCalculada = $mapaDeudas[$key] ?? null;
                    
                    if ($deudaCalculada && $deudaCalculada['interes'] > 0) {
                        $nombresMeses = ['', 'Enero', 'Febrero', 'Marzo', 'Abril', 'Mayo', 'Junio', 'Julio', 'Agosto', 'Septiembre', 'Octubre', 'Noviembre', 'Diciembre'];
                        $porcentajeInteresDeuda = ($deudaCalculada['monto'] > 0)
                            ? round(($deudaCalculada['interes'] / $deudaCalculada['monto']) * 100, 2)
                            : 0;
                        $interesesDetalle[] = $porcentajeInteresDeuda . "% por vencimiento " . $nombresMeses[$mes] . " " . $ano;
                        $totalInteres += $deudaCalculada['interes'];
                    }
                }
            }
            
            if (!empty($interesesDetalle)) {
                $motivoInteres = implode(" + ", $interesesDetalle);
                // Calcular el porcentaje promedio de interés sobre el total base
                if ($montoTotalBase > 0) {
                    $porcentajeInteres = round(($totalInteres / $montoTotalBase) * 100, 2);
                }
            } else {
                $motivoInteres = "Sin interés aplicado";
            }
            
            // Obtener configuración del orden de cálculo
            $configuracion = $alumno->getInstituto()->getConfiguracion();
            $ordenCalculo = $configuracion ? $configuracion->getOrdenCalculoInteresesDescuentos() : 'interes_primero';
            
            // Calcular porcentajes de descuento: efectivo, hermanos (según config) + promocionales seleccionados
            $porcentajeDescuentoTotal = 0;
            $descuentosAplicados = [];
            $puedeRecibirDescuentos = true;
            
            if ($configuracion && $configuracion->getDeshabilitarDescuentosEnDeuda() && $this->tieneDeudasVencidasOnDemand($alumno, $this->institutoTimezoneService->getNowForInstituto($instituto))) {
                $puedeRecibirDescuentos = false;
                $descuentosAplicados[] = "No se aplican descuentos generales (efectivo/hermanos) porque el alumno tiene deudas vencidas";
            }
            $metodoPago = $request->request->get('metodo_pago');
            $aplicarDescuentoEfectivo = ($metodoPago === null || strtolower($metodoPago) === 'efectivo');
            if ($puedeRecibirDescuentos && $configuracion) {
                if ($aplicarDescuentoEfectivo && $configuracion->getDescuentoEfectivo() && $configuracion->getDescuentoEfectivo() > 0) {
                    $porcentajeEfectivo = (float)$configuracion->getDescuentoEfectivo();
                    $porcentajeDescuentoTotal += $porcentajeEfectivo;
                    $descuentosAplicados[] = "Descuento del " . $porcentajeEfectivo . "% por pago en efectivo";
                }
                if ($configuracion->getDescuentoHermanos() && $configuracion->getDescuentoHermanos() > 0) {
                    $hermanos = $alumno->getHermanos();
                    if (!empty($hermanos)) {
                        $porcentajeHermanos = (float)$configuracion->getDescuentoHermanos();
                        $porcentajeDescuentoTotal += $porcentajeHermanos;
                        $descuentosAplicados[] = "Descuento del " . $porcentajeHermanos . "% por tener " . count($hermanos) . " hermano(s) en el instituto";
                    }
                }
            }
            
            // Descuentos promocionales: siempre se aplican, independientemente de si el alumno tiene deudas
            foreach ($descuentosPromocionalesSeleccionados as $descuentoPromocional) {
                $porcentajeDescuentoPromocional = (float)$descuentoPromocional->getPorcentaje();
                $porcentajeDescuentoTotal += $porcentajeDescuentoPromocional;
                $descuentosAplicados[] = "Descuento promocional: " . $descuentoPromocional->getNombre() . " (" . $porcentajeDescuentoPromocional . "%)";
            }
            
            // Aplicar descuentos sobre el monto total con interés ya incluido
            $montoTotalFinal = $montoTotalConInteres;
            if ($porcentajeDescuentoTotal > 0) {
                $montoTotalFinal = $montoTotalConInteres * (1 - ($porcentajeDescuentoTotal / 100));
            }
            
            return new JsonResponse([
                'monto' => $montoTotalFinal,
                'montoBase' => $montoTotalBase,
                'montoConInteres' => $montoTotalConInteres,
                'porcentajeInteres' => $porcentajeInteres,
                'motivoInteres' => $motivoInteres,
                'descuentosAplicados' => $descuentosAplicados,
                'porcentajeDescuentoTotal' => $porcentajeDescuentoTotal,
                'ordenCalculo' => $ordenCalculo,
                'ordenCalculoTexto' => 'Suma de montos individuales con descuentos aplicados',
                'ordenCalculoDescripcion' => 'Cada deuda ya tiene su interés calculado. Los descuentos se aplican sobre el total.',
                'puedeRecibirDescuentos' => $puedeRecibirDescuentos
            ]);
        }
        
        // Lógica original para un solo curso
        if (!$cursoId) {
            return new JsonResponse(['error' => 'Falta el ID del curso'], 400);
        }
        
        $curso = $this->entityManager->getRepository(Curso::class)->find($cursoId);
        if (!$curso) {
            return new JsonResponse(['error' => 'Curso no encontrado'], 404);
        }
        
        // Verificar que el alumno y curso pertenecen al mismo instituto
        if ($alumno->getInstituto() !== $curso->getInstituto()) {
            return new JsonResponse(['error' => 'El alumno y curso no pertenecen al mismo instituto'], 400);
        }
        
        // Calcular el monto (pasamos metodo_pago para aplicar descuento efectivo solo si corresponde)
        $mesesAdeudadosCurso = $this->historialCursosService->verificarMesesAdeudadosPorCurso($alumno, $curso);
        $metodoPago = $request->request->get('metodo_pago');
        $calculoMonto = $this->calcularMonto($alumno, $curso, $vencimientos, $mesesAdeudadosCurso, $descuentosPromocionalesSeleccionados, $metodoPago);
        
        return new JsonResponse([
            'monto' => $calculoMonto['monto'],
            'montoBase' => $calculoMonto['montoBase'],
            'porcentajeInteres' => $calculoMonto['porcentajeInteres'],
            'motivoInteres' => $calculoMonto['motivoInteres'],
            'descuentosAplicados' => $calculoMonto['descuentosAplicados'],
            'porcentajeDescuentoTotal' => $calculoMonto['porcentajeDescuentoTotal']
        ]);
        } catch (\Exception $e) {
            error_log('ERROR en calcularMontoAjax: ' . $e->getMessage());
            error_log('Stack trace: ' . $e->getTraceAsString());
            error_log('File: ' . $e->getFile() . ' Line: ' . $e->getLine());
            return new JsonResponse([
                'error' => 'Error al calcular el monto: ' . $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ], 500);
        }
    }

    /**
     * @Route("/calcular-descuentos", name="app_alumnos_pagos_calcular_descuentos", methods={"POST"})
     */
    public function calcularDescuentos(
        Request $request,
        AlumnoRepository $alumnoRepository,
        DescuentoPromocionalRepository $descuentoPromocionalRepository
    ): JsonResponse {
        try {
            $alumnoId = $request->request->get('alumno_id');
            $montoTotal = (float)$request->request->get('monto_total', 0);
            $montoBase = (float)$request->request->get('monto_base', 0);
            $descuentosPromocionalesIds = $request->request->get('descuentos_promocionales', []);
            
            if (!$alumnoId) {
                return new JsonResponse(['error' => 'Falta el ID del alumno'], 400);
            }
            
            $alumno = $alumnoRepository->find($alumnoId);
            if (!$alumno) {
                return new JsonResponse(['error' => 'Alumno no encontrado'], 404);
            }
            
            // Verificar que el usuario tiene acceso al instituto
            $institutoUsuario = $this->getUser()->getInstituto();
            if ($alumno->getInstituto() !== $institutoUsuario) {
                return new JsonResponse(['error' => 'No tiene acceso a este instituto'], 403);
            }
            
            // Obtener descuentos promocionales seleccionados
            $descuentosPromocionalesSeleccionados = [];
            if (is_array($descuentosPromocionalesIds)) {
                foreach ($descuentosPromocionalesIds as $id) {
                    $descuento = $descuentoPromocionalRepository->find($id);
                    if ($descuento && $descuento->getActivo() && $descuento->getConfiguracion()->getInstituto() === $alumno->getInstituto()) {
                        $descuentosPromocionalesSeleccionados[] = $descuento;
                    }
                }
            }
            
            $configuracion = $alumno->getInstituto()->getConfiguracion();
            
            // Calcular porcentajes de descuento
            $porcentajeDescuentoTotal = 0;
            $descuentosAplicados = [];
            $puedeRecibirDescuentos = true;
            
            if ($configuracion && $configuracion->getDeshabilitarDescuentosEnDeuda() && $this->tieneDeudasVencidasOnDemand($alumno, $this->institutoTimezoneService->getNowForInstituto($alumno->getInstituto()))) {
                $puedeRecibirDescuentos = false;
                $descuentosAplicados[] = "No se aplican descuentos generales (efectivo/hermanos) porque el alumno tiene deudas vencidas";
            }
            
            $metodoPagoNombre = $request->request->get('metodo_pago');
            $aplicarDescuentoEfectivo = ($metodoPagoNombre === null || strtolower($metodoPagoNombre) === 'efectivo');
            
            if ($puedeRecibirDescuentos && $configuracion) {
                if ($aplicarDescuentoEfectivo && $configuracion->getDescuentoEfectivo() && $configuracion->getDescuentoEfectivo() > 0) {
                    $porcentajeEfectivo = (float)$configuracion->getDescuentoEfectivo();
                    $porcentajeDescuentoTotal += $porcentajeEfectivo;
                    $descuentosAplicados[] = "Descuento del " . $porcentajeEfectivo . "% por pago en efectivo";
                }
                if ($configuracion->getDescuentoHermanos() && $configuracion->getDescuentoHermanos() > 0) {
                    $hermanos = $alumno->getHermanos();
                    if (!empty($hermanos)) {
                        $porcentajeHermanos = (float)$configuracion->getDescuentoHermanos();
                        $porcentajeDescuentoTotal += $porcentajeHermanos;
                        $descuentosAplicados[] = "Descuento del " . $porcentajeHermanos . "% por tener " . count($hermanos) . " hermano(s) en el instituto";
                    }
                }
            }

            // Descuentos promocionales: siempre se aplican, independientemente de si el alumno tiene deudas
            foreach ($descuentosPromocionalesSeleccionados as $descuentoPromocional) {
                $porcentajeDescuentoPromocional = (float)$descuentoPromocional->getPorcentaje();
                $porcentajeDescuentoTotal += $porcentajeDescuentoPromocional;
                $descuentosAplicados[] = "Descuento promocional: " . $descuentoPromocional->getNombre() . " (" . $porcentajeDescuentoPromocional . "%)";
            }
            
            // Aplicar descuentos sobre el monto total
            $montoFinal = $montoTotal;
            if ($porcentajeDescuentoTotal > 0) {
                $montoFinal = $montoTotal * (1 - ($porcentajeDescuentoTotal / 100));
            }
            
            return new JsonResponse([
                'monto' => $montoFinal,
                'montoBase' => $montoBase,
                'montoConInteres' => $montoTotal,
                'descuentosAplicados' => $descuentosAplicados,
                'porcentajeDescuentoTotal' => $porcentajeDescuentoTotal,
                'puedeRecibirDescuentos' => $puedeRecibirDescuentos
            ]);
        } catch (\Exception $e) {
            error_log('ERROR en calcularDescuentos: ' . $e->getMessage());
            return new JsonResponse([
                'error' => 'Error al calcular descuentos: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * @Route("/{id}", name="app_alumnos_pagos_show", methods={"GET"})
     */
    public function show(AlumnosPagos $pago): Response
    {
        $instituto = $this->getUser()->getInstituto();
        if ($pago->getAlumno()->getInstituto() !== $instituto) {
            $this->addFlash('danger', 'No tiene acceso a este pago.');
            return $this->redirectToRoute('app_alumnos_pagos_index');
        }
        
        return $this->render('alumnos_pagos/show.html.twig', [
            'alumnos_pago' => $pago,
            'instituto' => $instituto
        ]);
    }

    /**
     * @Route("/{id}/edit", name="app_alumnos_pagos_edit", methods={"GET", "POST"})
     */
    public function edit(Request $request, AlumnosPagos $pago, VencimientoRepository $vencimientoRepository, CursoRepository $cursoRepository, AlumnoRepository $alumnoRepository): Response
    {
        $instituto = $this->getUser()->getInstituto();
        if ($pago->getAlumno()->getInstituto() !== $instituto) {
            $this->addFlash('danger', 'No tiene acceso a este pago.');
            return $this->redirectToRoute('app_alumnos_pagos_index');
        }

        $alumno = $pago->getAlumno();
        $montoOriginal = (float) $pago->getMonto();

        $cursos = $cursoRepository->findBy(['instituto' => $instituto]);

        // Obtener los vencimientos del instituto
        $vencimientos = $instituto->getVencimientos();

        // Crear el formulario
        $alumnos = $instituto->getAlumnos();
        $currentYear = $this->institutoTimezoneService->getNowForInstituto($instituto)->format('Y');
        $form = $this->createForm(AlumnosPagosType::class, $pago, [
            'alumnos' => $alumnos,
            'cursos' => array_values($cursos),
            'vencimientos' => $vencimientos,
            'modo_edicion' => true,
            'bloquear_monto_en_edicion' => false,
            'mes_multiple' => false,
            'current_year' => $currentYear,
            'instituto' => $instituto,
        ]);
        // mes tiene mapped => false; cargar valor de la entidad para mostrarlo (solo lectura)
        $form->get('mes')->setData($pago->getMes());

        $form->handleRequest($request);

        if ($form->isSubmitted()) {
            if ($form->isValid()) {
                // Verificar tokens antes de editar
                if (!$this->tokenService->hasEnoughTokens($instituto, 'pago.edit')) {
                    $this->addFlash('danger', 'No tienes suficientes tokens para editar un pago. Balance actual: ' . $this->tokenService->getBalance($instituto)->getBalance());
                    return $this->renderForm('alumnos_pagos/edit.html.twig', [
                        'pago' => $pago,
                        'form' => $form,
                    ]);
                }

                try {
                    // En edición solo se actualizan monto, observación y método de pago.
                    // Alumno, curso, mes y año no se modifican (si se equivocó, debe borrar y crear de nuevo).
                    $montoActualizado = (float) $pago->getMonto();
                    $montoModificado = abs($montoActualizado - $montoOriginal) > 0.00001;
                    $deudasIdsAReaplicar = [];

                    if ($montoModificado) {
                        foreach ($pago->getAplicaciones()->toArray() as $aplicacion) {
                            $deudasIdsAReaplicar[] = $aplicacion->getDeuda()->getId();
                            $pago->removeAplicacion($aplicacion);
                            $this->entityManager->remove($aplicacion);
                        }
                        $pago->setMontoRestante($montoActualizado);
                    }

                    $this->entityManager->persist($pago);
                    $this->entityManager->flush();

                    if ($montoModificado && !empty($deudasIdsAReaplicar)) {
                        $this->pagoService->aplicarPagoRestante(
                            $pago,
                            array_values(array_unique($deudasIdsAReaplicar))
                        );
                    }

                    // Consumir tokens después de guardar exitosamente
                    $this->tokenService->consumeTokens(
                        $instituto,
                        'pago.edit',
                        $this->getUser(),
                        'Editar pago: ' . $pago->getAlumno()->getNombreApellido() . ' - ' . ($pago->getCurso() ? $pago->getCurso()->getNombre() : 'Cuota de inscripcion') . ' (' . $pago->getMes() . '/' . $pago->getAno() . ')',
                        'AlumnosPagos',
                        $pago->getId()
                    );
                    
                    $this->addFlash('success', 'Pago actualizado correctamente.');
                    return $this->redirectToRoute('app_alumnos_pagos_index', ['alumno' => $pago->getAlumno()->getId()]);
                } catch (\Exception $e) {
                    $this->addFlash('danger', 'Ocurrió un error al actualizar el pago.');
                }
            } else {
                foreach ($form->getErrors(true) as $error) {
                    $this->addFlash('danger', $error->getMessage());
                }
            }
        }

        return $this->render('alumnos_pagos/edit.html.twig', [
            'form' => $form->createView(),
            'alumno' => $alumno,
            'cursos' => array_values($cursos),
            'curso' => $pago->getCurso(),
            'pagoId' => $pago->getId(),
            'vencimientos' => $vencimientos,
            'is_general' => false
        ]);
    }

    /**
     * @Route("/{id}/delete", name="app_alumnos_pagos_delete", methods={"POST"})
     */
    public function delete(Request $request, AlumnosPagos $pago): Response
    {
        $institutoUsuario = $this->getUser()->getInstituto();
        if ($pago->getAlumno()->getInstituto() !== $institutoUsuario) {
            $this->addFlash('danger', 'No tiene acceso a este pago.');
            return $this->redirectToRoute('app_alumnos_pagos_index');
        }

        $instituto = $pago->getAlumno()->getInstituto();
        
        // Verificar tokens antes de eliminar
        if (!$this->tokenService->hasEnoughTokens($instituto, 'pago.delete')) {
            $alumnoId = $pago->getAlumno()->getId();
            $this->addFlash('danger', 'No tienes suficientes tokens para eliminar un pago. Balance actual: ' . $this->tokenService->getBalance($instituto)->getBalance());
            return $this->redirectToRoute('app_alumnos_pagos_index', ['alumno' => $alumnoId]);
        }
        
        if ($this->isCsrfTokenValid('delete'.$pago->getId(), $request->request->get('_token'))) {
            $alumnoId = $pago->getAlumno()->getId();
            $this->entityManager->remove($pago);
            $this->entityManager->flush();
            
            // Consumir tokens después de eliminar exitosamente
            $this->tokenService->consumeTokens(
                $instituto,
                'pago.delete',
                $this->getUser(),
                'Eliminar pago: ' . $pago->getAlumno()->getNombreApellido() . ' - ' . ($pago->getCurso() ? $pago->getCurso()->getNombre() : 'Cuota de inscripcion') . ' (' . $pago->getMes() . '/' . $pago->getAno() . ')',
                'AlumnosPagos',
                $pago->getId()
            );
            
            $this->addFlash('success', 'Pago eliminado correctamente.');
        }

        return $this->redirectToRoute('app_alumnos_pagos_index', ['alumno' => $alumnoId]);
    }

    /**
     * @Route("/verificar-meses-adeudados/{id}", name="app_alumnos_pagos_verificar_meses_adeudados", methods={"GET"})
     */
    public function verificarMesesAdeudados(Alumno $alumno, PagosService $pagosService): JsonResponse
    {
        $mesesAdeudados = $pagosService->verificarMesesAdeudados($alumno);
        return $this->json(['mesesAdeudados' => $mesesAdeudados]);
    }

    /**
     * @Route("/registrar-por-deuda/{id}", name="app_alumnos_pagos_por_deuda", methods={"GET", "POST"})
     */
    public function registrarPagoPorDeuda(
        Request $request, 
        \App\Entity\DeudaAlumno $deuda, 
        ValidatorInterface $validator
    ): Response {
        // Verificar que la deuda tenga monto pendiente
        if ($deuda->getMontoPendiente() <= 0) {
            $this->addFlash('warning', 'Esta deuda ya está completamente pagada.');
            return $this->redirectToRoute('app_alumnos_pagos_index', ['alumno' => $deuda->getAlumno()->getId()]);
        }
        
        // Verificar que el usuario tenga acceso a esta deuda
        $instituto = $this->getUser()->getInstituto();
        if ($deuda->getInstituto() !== $instituto) {
            $this->addFlash('danger', 'No tiene acceso a esta deuda.');
            return $this->redirectToRoute('app_alumnos_pagos_index');
        }
        
        // Crear un nuevo pago con los datos de la deuda
        if ($request->isMethod('POST')) {
            // Obtener datos del formulario
            $monto = $request->request->get('monto');
            $metodoPago = $request->request->get('metodoPago');
            $observacion = $request->request->get('observacion');
            $fechaPagoStr = $request->request->get('fechaPago');
            
            $montoPendiente = $deuda->getMontoPendiente();
            
            if (!$monto || $monto <= 0) {
                $this->addFlash('danger', 'El monto debe ser mayor a $0.00.');
                return $this->redirectToRoute('app_alumnos_pagos_por_deuda', ['id' => $deuda->getId()]);
            }
            
            if ($monto > $montoPendiente) {
                $this->addFlash('danger', sprintf(
                    'El monto no puede ser mayor al pendiente ($%s).', 
                    number_format($montoPendiente, 2, ',', '.')
                ));
                return $this->redirectToRoute('app_alumnos_pagos_por_deuda', ['id' => $deuda->getId()]);
            }
            
            if (!$metodoPago) {
                $this->addFlash('danger', 'El método de pago es obligatorio.');
                return $this->redirectToRoute('app_alumnos_pagos_por_deuda', ['id' => $deuda->getId()]);
            }
            
            // Verificar tokens antes de registrar el pago
            $instituto = $deuda->getAlumno()->getInstituto();
            if (!$this->tokenService->hasEnoughTokens($instituto, 'pago.create')) {
                $this->addFlash('danger', 'No tienes suficientes tokens para registrar un pago.');
                return $this->redirectToRoute('app_alumnos_pagos_por_deuda', ['id' => $deuda->getId()]);
            }
            
            try {
                // Parsear la fecha del formulario o usar la fecha actual del instituto
                if ($fechaPagoStr) {
                    try {
                        $fechaPago = $this->institutoTimezoneService->normalizeDateOnly(new \DateTime($fechaPagoStr));
                    } catch (\Exception $e) {
                        $fechaPago = $this->institutoTimezoneService->getCurrentDateForInstituto($instituto);
                    }
                } else {
                    $fechaPago = $this->institutoTimezoneService->getCurrentDateForInstituto($instituto);
                }
                
                // Crear el pago
                $pago = new AlumnosPagos();
                $pago->setAlumno($deuda->getAlumno());
                $pago->setCurso($deuda->getCurso());
                $pago->setMes($deuda->getMes());
                $pago->setAno($deuda->getAno());
                $pago->setMonto((float)$monto);
                $pago->setFecha($fechaPago);
                $pago->setMetodoPago($metodoPago);
                $pago->setObservacion($observacion);
                
                // Validar el pago antes de registrar
                $errores = $validator->validate($pago);
                if (count($errores) > 0) {
                    $this->addFlash('danger', 'Error al validar el pago: ' . $errores[0]->getMessage());
                    return $this->redirectToRoute('app_alumnos_pagos_por_deuda', ['id' => $deuda->getId()]);
                }
                
                // Registrar el pago usando PagoService (aplicará automáticamente a la deuda)
                $resultado = $this->pagoService->registrarPago($pago, [$deuda->getId()], false);
                $pago = $resultado['pago'];
                
                // Consumir tokens después de guardar exitosamente
                $this->tokenService->consumeTokens(
                    $instituto,
                    'pago.create',
                    $this->getUser(),
                    'Registrar pago: ' . $deuda->getAlumno()->getNombreApellido() . ' - ' . ($deuda->getCurso() ? $deuda->getCurso()->getNombre() : 'Cuota de inscripcion') . ' (' . $deuda->getPeriodo() . ')',
                    'AlumnosPagos',
                    $pago->getId()
                );
                
                // El mensaje se arma primero: antes el bloque del email le hacía .= cuando
                // todavía no existía, y el warning de PHP se atajaba como si el envío hubiera
                // fallado. O sea que el recibo salía bien y la pantalla decía lo contrario.
                $mensaje = sprintf(
                    'Pago de $%s registrado correctamente para %s %s, %s.',
                    number_format($monto, 2, ',', '.'),
                    $deuda->getAlumno()->getNombre(),
                    $deuda->getAlumno()->getApellido(),
                    // La cuota de inscripción anual no tiene curso, y getPeriodo() ya devuelve
                    // "Inscripción Anual <año>" en ese caso.
                    $deuda->getCurso()
                        ? sprintf('curso %s, periodo %s', $deuda->getCurso()->getNombre(), $deuda->getPeriodo())
                        : $deuda->getPeriodo()
                );
                
                if ($resultado['montoRestante'] > 0) {
                    $mensaje .= sprintf(' Saldo restante del pago: $%s', number_format($resultado['montoRestante'], 2, ',', '.'));
                    
                    // Refrescar la deuda para obtener el nuevo monto pendiente
                    $this->entityManager->refresh($deuda);
                    $nuevoMontoPendiente = $deuda->getMontoPendiente();
                    
                    if ($nuevoMontoPendiente > 0) {
                        $mensaje .= sprintf(' | Deuda pendiente: $%s', number_format($nuevoMontoPendiente, 2, ',', '.'));
                    } else {
                        $mensaje .= ' | La deuda quedó completamente pagada.';
                    }
                } else {
                    $mensaje .= ' La deuda quedó completamente pagada.';
                }
                
                try {
                    if ($this->notificationService->enviarReciboPago($pago, null, false, $this->getUser())) {
                        $mensaje .= ' Se envió el recibo por email.';
                    }
                } catch (\Throwable $e) {
                    // Que falle el email no invalida el cobro, que ya está registrado.
                    $this->addFlash('warning', 'El pago se registró pero no se pudo enviar el email: ' . $e->getMessage());
                }

                $this->addFlash('success', $mensaje);
                
                return $this->redirectToRoute('app_alumnos_pagos_index', ['alumno' => $deuda->getAlumno()->getId()]);
            } catch (\Exception $e) {
                $this->addFlash('danger', 'Error al registrar el pago: ' . $e->getMessage());
            }
        }
        
        // Obtener los métodos de pago configurados para este instituto
        $metodosPagoEntities = $this->entityManager->getRepository(MetodoPago::class)
            ->findBy(['instituto' => $instituto, 'activo' => true], ['orden' => 'ASC']);

        $metodosPago = array_map(function (MetodoPago $metodo) {
            return $metodo->getNombre();
        }, $metodosPagoEntities);

        // Fallback defensivo si no hubiera configuración
        if (empty($metodosPago)) {
            $metodosPago = ['Efectivo'];
        }
        
        // Calcular el monto total (incluyendo intereses si aplica)
        $montoTotal = $deuda->getMontoTotal();
        $montoPendiente = $deuda->getMontoPendiente();
        $montoPagado = $deuda->getMontoPagado();
        
        // Renderizar formulario
        return $this->render('alumnos_pagos/registrar_por_deuda.html.twig', [
            'deuda' => $deuda,
            'montoTotal' => $montoTotal,
            'montoPendiente' => $montoPendiente,
            'montoPagado' => $montoPagado,
            'metodosPago' => $metodosPago,
            'fechaActualInstituto' => $this->institutoTimezoneService->getNowForInstituto($instituto)->format('Y-m-d')
        ]);
    }
}
