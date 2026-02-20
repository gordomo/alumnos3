<?php

namespace App\Controller;

use App\Entity\AlumnosPagos;
use App\Entity\Alumno;
use App\Entity\Curso;
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
use App\Service\DeudaService;
use App\Service\NotificationService;
use App\Service\TokenService;
use App\Service\PagoService;
/**
 * @Route("/alumnos/pagos")
 */
class AlumnosPagosController extends AbstractController
{
    private $entityManager;
    private $historialCursosService;
    private $deudaService;
    private $notificationService;
    private $tokenService;
    private $pagoService;

    public function __construct(
        EntityManagerInterface $entityManager, 
        HistorialCursosService $historialCursosService,
        DeudaService $deudaService,
        NotificationService $notificationService,
        TokenService $tokenService,
        PagoService $pagoService
    ) {
        $this->entityManager = $entityManager;
        $this->historialCursosService = $historialCursosService;
        $this->deudaService = $deudaService;
        $this->notificationService = $notificationService;
        $this->tokenService = $tokenService;
        $this->pagoService = $pagoService;
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
        $sort = $request->get('sort', 'fecha');
        $order = $request->get('order', 'desc');

        // Por defecto: año completo si no hay fechas en la petición
        if (empty($fechaDesde) && empty($fechaHasta)) {
            $fechaActual = new \DateTime();
            $fechaDesde = $fechaActual->format('Y') . '-01-01';
            $fechaHasta = $fechaActual->format('Y') . '-12-31';
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
            $alumno = $alumnoRepository->find($alumnoId);
            $nombreAlumno = $alumno->getNombre() . ' ' . $alumno->getApellido();
            // Refrescar la entidad del alumno para asegurar que la relación de deudas esté actualizada
            $this->entityManager->refresh($alumno);
            // Usar el nuevo método getDeudasParaPago() en lugar de verificarMesesAdeudados()
            $deudasParaPago = $alumno->getDeudasParaPago();
            $mesesAdeudados = [];
            $nombresMeses = [
                1 => 'Enero', 2 => 'Febrero', 3 => 'Marzo', 4 => 'Abril',
                5 => 'Mayo', 6 => 'Junio', 7 => 'Julio', 8 => 'Agosto',
                9 => 'Septiembre', 10 => 'Octubre', 11 => 'Noviembre', 12 => 'Diciembre'
            ];
            
            foreach ($deudasParaPago as $deuda) {
                $curso = $deuda->getCurso();
                $mesesAdeudados[] = [
                    'mes' => $deuda->getMes(),
                    'ano' => $deuda->getAno(),
                    'nombre' => $nombresMeses[$deuda->getMes()] . ' ' . $deuda->getAno(),
                    'curso' => $curso->getNombre(),
                    'curso_obj' => $curso,
                    'monto' => $deuda->getMonto()
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
            $qb->andWhere('p.fecha >= :fechaDesde')
               ->setParameter('fechaDesde', new \DateTime($fechaDesde));
        }

        if ($fechaHasta) {
            $qb->andWhere('p.fecha <= :fechaHasta')
               ->setParameter('fechaHasta', new \DateTime($fechaHasta));
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

        // Obtener métodos de pago únicos
        $metodosPago = $alumnosPagosRepository->createQueryBuilder('p')
            ->select('DISTINCT p.metodoPago')
            ->andWhere('p.alumno IN (SELECT a2 FROM App\Entity\Alumno a2 WHERE a2.instituto = :instituto)')
            ->setParameter('instituto', $instituto)
            ->getQuery()
            ->getSingleColumnResult();

        // Paginar resultados
        $pagination = $paginator->paginate(
            $qb->getQuery(),
            $request->query->getInt('page', 1),
            20
        );

        // Calcular estadísticas por período
        $fechaActual = new \DateTime();
        $hoy = clone $fechaActual;
        $hoy->setTime(0, 0, 0);
        
        $inicioSemana = clone $fechaActual;
        $inicioSemana->modify('monday this week')->setTime(0, 0, 0);
        
        $inicioMes = clone $fechaActual;
        $inicioMes->modify('first day of this month')->setTime(0, 0, 0);
        
        $inicioAno = clone $fechaActual;
        $inicioAno->modify('first day of january')->setTime(0, 0, 0);

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
            ->andWhere('a.activo = :activo')
            ->setParameter('instituto', $instituto)
            ->setParameter('activo', true);

        // Mostrar solo deudas del mes actual o anteriores (ocultar deudas futuras)
        $fechaReferencia = new \DateTime();
        $mesActualDeuda = (int) $fechaReferencia->format('n');
        $anoActualDeuda = (int) $fechaReferencia->format('Y');
        $qbDeudas
            ->andWhere('(d.ano < :anoActualDeuda OR (d.ano = :anoActualDeuda AND d.mes <= :mesActualDeuda))')
            ->setParameter('anoActualDeuda', $anoActualDeuda)
            ->setParameter('mesActualDeuda', $mesActualDeuda);
        
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
        if ($fechaDesde && $fechaHasta) {
            try {
                $fechaDesdeObj = new \DateTime($fechaDesde);
                $fechaHastaObj = new \DateTime($fechaHasta);
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
        
        // Obtener todos los alumnos activos del instituto para el filtro
        $alumnos = $alumnoRepository->createQueryBuilder('a')
            ->where('a.instituto = :instituto')
            ->andWhere('a.activo = :activo')
            ->setParameter('instituto', $instituto)
            ->setParameter('activo', true)
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
     * Calcula el monto sugerido para un pago
     * @param string|null $metodoPago Si se pasa, el descuento por efectivo solo se aplica cuando es 'efectivo'
     */
    private function calcularMonto(Alumno $alumno, Curso $curso, $vencimientos, array $mesesAdeudados, array $descuentosPromocionalesSeleccionados = [], ?string $metodoPago = null): array
    {
        // Obtener fecha actual
        $fechaActual = new \DateTime();
        $mesActual = (int)$fechaActual->format('n');
        $anoActual = (int)$fechaActual->format('Y');
        $diaActual = (int)$fechaActual->format('d');

        $montoBase = $curso->getPrecio();
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
                if ($diaActual > $vencimiento->getDiaVencimiento()) {
                    $vencimientoAplicado = $vencimiento;
                } else {
                    break;
                }
            }
            if ($vencimientoAplicado) {
                $porcentajeInteres = $vencimientoAplicado->getPorcentajeInteres();
                $motivoInteres = "Interés del " . $porcentajeInteres . "% por pago después del día " . $vencimientoAplicado->getDiaVencimiento();
            }
        }

        // Obtener la configuración del instituto
        $instituto = $alumno->getInstituto();
        $configuracion = $instituto->getConfiguracion();
        $ordenCalculo = $configuracion ? $configuracion->getOrdenCalculoInteresesDescuentos() : 'interes_primero';
        
        // Verificar si podemos aplicar descuentos
        $puedeRecibirDescuentos = true;
        
        // Si está configurado para deshabilitar descuentos en deuda
        if ($configuracion && $configuracion->getDeshabilitarDescuentosEnDeuda()) {
            // Verificar si el alumno tiene deudas vencidas
            if ($alumno->tieneDeudasVencidas()) {
                $puedeRecibirDescuentos = false;
                $descuentosAplicados[] = "No se aplican descuentos porque el alumno tiene deudas vencidas";
            }
        }
        
        // Calcular porcentajes de descuento (sin aplicar aún)
        if ($puedeRecibirDescuentos && $configuracion) {
            // Aplicar descuento por pago en efectivo solo si el método de pago es efectivo (o no se especificó, p. ej. render inicial)
            $aplicarDescuentoEfectivo = ($metodoPago === null || $metodoPago === 'efectivo');
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
            
            // Aplicar descuentos promocionales seleccionados
            foreach ($descuentosPromocionalesSeleccionados as $descuentoPromocional) {
                $porcentajeDescuentoPromocional = (float)$descuentoPromocional->getPorcentaje();
                $porcentajeDescuentoTotal += $porcentajeDescuentoPromocional;
                $descuentosAplicados[] = "Descuento promocional: " . $descuentoPromocional->getNombre() . " (" . $porcentajeDescuentoPromocional . "%)";
            }
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
            'porcentajeDescuentoTotal' => $porcentajeDescuentoTotal
        ];
    }

    /**
     * @Route("/new", name="app_alumnos_pagos_new", methods={"GET", "POST"})
     */
    public function new(Request $request, AlumnoRepository $alumnoRepository, DescuentoPromocionalRepository $descuentoPromocionalRepository): Response
    {
        $alumnoId = $request->query->get('id');
        if ($alumnoId) {
            $alumno = $alumnoRepository->find($alumnoId);
        } else {
            // Sin id en URL: no preseleccionar alumno; el usuario debe seleccionar manualmente
            $alumno = null;
        }
        $alumnosPago = new AlumnosPagos();
        $alumnosPago->setAlumno($alumno);
        $alumnosPago->setFecha(new \DateTime());
        $alumnosPago->setMetodoPago('Efectivo');

        $mesesAdeudados = [];
        $ordenCalculo = 'interes_primero';

        if ($alumno) {
        // Refrescar la entidad del alumno para asegurar que la relación de deudas esté actualizada
        $this->entityManager->refresh($alumno);

        // Obtener los meses adeudados para el componente
        $deudasParaPago = $alumno->getDeudasParaPago();
        $mesesAdeudados = [];
        $nombresMeses = [
            1 => 'Enero', 2 => 'Febrero', 3 => 'Marzo', 4 => 'Abril',
            5 => 'Mayo', 6 => 'Junio', 7 => 'Julio', 8 => 'Agosto',
            9 => 'Septiembre', 10 => 'Octubre', 11 => 'Noviembre', 12 => 'Diciembre'
        ];
        
        foreach ($deudasParaPago as $deuda) {
            $curso = $deuda->getCurso();
            $mesesAdeudados[] = [
                'mes' => $deuda->getMes(),
                'ano' => $deuda->getAno(),
                'nombre' => $nombresMeses[$deuda->getMes()] . ' ' . $deuda->getAno(),
                'curso' => $curso->getNombre(),
                'curso_obj' => $curso,
                'monto' => $deuda->getMontoTotal(),
                'montoPendiente' => $deuda->getMontoPendiente(),
                'montoPagado' => $deuda->getMontoPagado(),
                'esPendiente' => true
            ];
        }
        
        // Agregar meses futuros para pagos adelantados
        // Se calculan por curso activo: desde el primer mes pendiente hasta la fecha fin del curso
        // (o hasta 12 meses adelante si el curso no tiene fecha fin definida)
        // Esto asegura que se muestren todos los meses, incluso los intermedios que no tienen deuda pendiente
        $fechaActual = new \DateTime();
        $mesActual = (int)$fechaActual->format('n');
        $anoActual = (int)$fechaActual->format('Y');
        
        // Obtener cursos activos del alumno para generar meses futuros
        $cursosHistoricos = $alumno->getCursosHistoricos();
        foreach ($cursosHistoricos as $historico) {
            if (!$historico->isActivo()) {
                continue;
            }
            
            $curso = $historico->getCurso();
            $fechaFinCurso = $curso->getFechaFin();
            $fechaInicioCurso = $curso->getFechaInicio();
            
            // Encontrar el primer mes pendiente para este curso
            $primerMesPendiente = null;
            foreach ($mesesAdeudados as $mesData) {
                if ($mesData['curso_obj']->getId() === $curso->getId()) {
                    $fechaMes = new \DateTime($mesData['ano'] . '-' . str_pad($mesData['mes'], 2, '0', STR_PAD_LEFT) . '-01');
                    if ($primerMesPendiente === null || $fechaMes < $primerMesPendiente) {
                        $primerMesPendiente = $fechaMes;
                    }
                }
            }
            
            // Determinar desde dónde empezar a generar meses
            // Si hay meses pendientes, empezar desde el primero
            // Si no hay meses pendientes, empezar desde el mes siguiente al actual o desde el inicio del curso
            $fechaInicio = clone $fechaActual;
            $fechaInicio->modify('first day of this month');
            $fechaInicio->modify('+1 month'); // Por defecto, desde el mes siguiente al actual
            
            if ($primerMesPendiente) {
                // Si hay meses pendientes, empezar desde el primero para llenar todos los huecos
                if ($primerMesPendiente < $fechaInicio) {
                    $fechaInicio = clone $primerMesPendiente;
                }
            } elseif ($fechaInicioCurso) {
                // Si no hay meses pendientes pero hay fecha inicio del curso, empezar desde ahí
                $fechaInicioCursoPrimerDia = clone $fechaInicioCurso;
                $fechaInicioCursoPrimerDia->modify('first day of this month');
                if ($fechaInicioCursoPrimerDia < $fechaInicio) {
                    $fechaInicio = $fechaInicioCursoPrimerDia;
                }
            }
            
            // Determinar hasta dónde generar meses futuros
            // Si el curso tiene fecha fin, usar esa fecha (o 12 meses adelante, lo que sea menor)
            // Si no tiene fecha fin, usar 12 meses adelante
            $fechaFin = clone $fechaActual;
            $fechaFin->modify('first day of this month');
            $fechaFin->modify('+13 months'); // Por defecto, hasta 12 meses adelante desde el mes actual
            
            if ($fechaFinCurso) {
                // Si el curso tiene fecha fin, usar la menor entre fecha fin del curso y 12 meses adelante
                $fechaFinCursoPrimerDia = clone $fechaFinCurso;
                $fechaFinCursoPrimerDia->modify('first day of this month');
                if ($fechaFinCursoPrimerDia < $fechaFin) {
                    $fechaFin = $fechaFinCursoPrimerDia;
                }
            }
            
            $fechaVerificacion = clone $fechaInicio;
            
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
                    // Verificar si existe una deuda para este mes (puede estar pagada o no haber vencido aún)
                    $deudaExistente = $this->entityManager->getRepository(\App\Entity\DeudaAlumno::class)
                        ->findOneBy([
                            'alumno' => $alumno,
                            'curso' => $curso,
                            'mes' => $mesVerificar,
                            'ano' => $anoVerificar
                        ]);
                    
                    // Si existe deuda con monto pendiente, agregarla como pendiente (aunque no haya vencido aún)
                    if ($deudaExistente && $deudaExistente->getMontoPendiente() > 0.01) {
                        $mesesAdeudados[] = [
                            'mes' => $mesVerificar,
                            'ano' => $anoVerificar,
                            'nombre' => $nombresMeses[$mesVerificar] . ' ' . $anoVerificar,
                            'curso' => $curso->getNombre(),
                            'curso_obj' => $curso,
                            'monto' => $deudaExistente->getMontoTotal(),
                            'montoPendiente' => $deudaExistente->getMontoPendiente(),
                            'montoPagado' => $deudaExistente->getMontoPagado(),
                            'esPendiente' => true,
                            'esAdelantado' => false
                        ];
                    }
                    // Si no existe deuda o está pagada, agregarla como mes adelantado
                    elseif (!$deudaExistente || $deudaExistente->getMontoPendiente() <= 0.01) {
                        $mesesAdeudados[] = [
                            'mes' => $mesVerificar,
                            'ano' => $anoVerificar,
                            'nombre' => $nombresMeses[$mesVerificar] . ' ' . $anoVerificar,
                            'curso' => $curso->getNombre(),
                            'curso_obj' => $curso,
                            'monto' => $curso->getPrecio(),
                            'montoPendiente' => $curso->getPrecio(),
                            'montoPagado' => $deudaExistente ? $deudaExistente->getMontoPagado() : 0,
                            'esPendiente' => false,
                            'esAdelantado' => true
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

        // Si hay meses adeudados y no hay curso seleccionado, establecer valores por defecto
        if (!empty($mesesAdeudados) && !$cursoSeleccionado) {
            $primerMesAdeudado = $mesesAdeudados[0];
            $alumnosPago->setMes($primerMesAdeudado['mes']);
            $alumnosPago->setAno($primerMesAdeudado['ano']);
            
            $alumnosPago->setCurso($primerMesAdeudado['curso_obj']);
            $cursoSeleccionado = $primerMesAdeudado['curso_obj'];
        } elseif ($cursoSeleccionado) {
            // Si hay un curso seleccionado, establecerlo
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

        // Calcular el monto sugerido si hay un curso seleccionado
        $calculoMonto = null;
        if ($cursoSeleccionado) {
            $mesesAdeudadosCurso = $this->historialCursosService->verificarMesesAdeudadosPorCurso($alumno, $cursoSeleccionado);
            $calculoMonto = $this->calcularMonto($alumno, $cursoSeleccionado, $vencimientos, $mesesAdeudadosCurso, $descuentosPromocionalesSeleccionados);
            $alumnosPago->setMonto($calculoMonto['monto']);
        }

        $cursosHistoricos = $alumno->getCursosHistoricos();
        $cursos = [];
        foreach ($cursosHistoricos as $cursoHistorico) {
            $cursos[] = $cursoHistorico->getCurso();
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
        $alumnos = $alumnoRepository->findBy(['instituto' => $this->getUser()->getInstituto()]);
        
        $form = $this->createForm(AlumnosPagosType::class, $alumnosPago, [
            'alumnos' => $alumnos,
            'cursos' => array_values($cursos),
            'vencimientos' => $vencimientos
        ]);

        $form->handleRequest($request);

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
                $anoSeleccionado = $request->request->get('alumnos_pagos')['ano'] ?? date('Y');
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
                    $fechaActual = new \DateTime();
                    $mesActual = (int)$fechaActual->format('n');
                    $anoActual = (int)$fechaActual->format('Y');
                    $diaActual = (int)$fechaActual->format('d');
                    
                    // Obtener el día de vencimiento para determinar si el mes actual ya venció
                    $institutoId = $alumno->getInstituto()->getId();
                    $primerDiaVencimiento = $alumno->getPrimerDiaVencimiento($institutoId);
                    
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
                        
                        $montoBaseCurso = $curso->getPrecio();
                        
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
                    $fechaActual = new \DateTime();
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
                                
                                // Si no está seleccionado, verificar si está pagado
                                if (!$mesSeleccionado) {
                                    $deudaAnterior = $this->entityManager->getRepository(\App\Entity\DeudaAlumno::class)
                                        ->findOneBy([
                                            'alumno' => $alumno,
                                            'curso' => $mesesCurso[0]['curso'],
                                            'mes' => $mesVerificar,
                                            'ano' => $anoVerificar
                                        ]);
                                    
                                    // Si existe deuda anterior con monto pendiente, rechazar el pago
                                    if ($deudaAnterior && $deudaAnterior->getMontoPendiente() > 0.01) {
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
                        // Buscar la deuda correspondiente a este mes/año/curso
                        $deuda = $this->entityManager->getRepository(\App\Entity\DeudaAlumno::class)
                            ->findOneBy([
                                'alumno' => $alumno,
                                'curso' => $mesInfo['curso'],
                                'mes' => $mesInfo['mes'],
                                'ano' => $mesInfo['ano']
                            ]);

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
                        
                        // Registrar el pago usando PagoService
                        // Si existe la deuda, aplicarla específicamente; si no, permitir adelantado
                        try {
                            $deudasIds = $deuda ? [$deuda->getId()] : null;
                            $resultado = $this->pagoService->registrarPago($nuevoPago, $deudasIds, true);
                            $pagosCreados++;
                            
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

                    // Registrar el pago usando PagoService (monto menor al debido cancela la deuda igual; soporta adelantados)
                    // Buscar deuda específica para este mes/año/curso
                    $deudaEspecifica = $this->entityManager->getRepository(\App\Entity\DeudaAlumno::class)
                        ->findOneBy([
                            'alumno' => $alumnosPago->getAlumno(),
                            'curso' => $alumnosPago->getCurso(),
                            'mes' => $alumnosPago->getMes(),
                            'ano' => $alumnosPago->getAno()
                        ]);
                    
                    $deudasIds = null;
                    if ($deudaEspecifica) {
                        $deudasIds = [$deudaEspecifica->getId()];
                    }
                    
                    // Permitir pagos adelantados (máximo 12 meses)
                    $resultado = $this->pagoService->registrarPago($alumnosPago, $deudasIds, true);
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
                        $this->notificationService->enviarReciboPago($alumnosPago);
                    } catch (\Exception $e) {
                        // No interrumpir el flujo si falla el envío del email
                        // Se puede loggear el error si es necesario
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
            'ordenCalculo' => $ordenCalculo
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
            
            $vencimientos = $alumno->getInstituto()->getVencimientos()->toArray();
        
        // Si hay múltiples meses seleccionados, calcular el total
        if (!empty($mesesSeleccionados) && is_array($mesesSeleccionados)) {
            $montoTotalBase = 0;
            $montoTotalConInteres = 0;
            $porcentajeInteres = 0;
            $motivoInteres = "Sin interés aplicado";
            $descuentosAplicados = [];
            $porcentajeDescuentoTotal = 0;
            
            // Agrupar meses por curso para calcular intereses correctamente
            $mesesPorCurso = [];
            foreach ($mesesSeleccionados as $mesData) {
                list($mes, $ano, $cursoId) = explode('_', $mesData);
                if (!isset($mesesPorCurso[$cursoId])) {
                    $mesesPorCurso[$cursoId] = [];
                }
                $mesesPorCurso[$cursoId][] = ['mes' => $mes, 'ano' => $ano];
            }
            
            // Separar meses vencidos de no vencidos para aplicar interés solo sobre vencidos
            $fechaActual = new \DateTime();
            $mesActual = (int)$fechaActual->format('n');
            $anoActual = (int)$fechaActual->format('Y');
            $diaActual = (int)$fechaActual->format('d');
            
            // Obtener el día de vencimiento para determinar si el mes actual ya venció
            $institutoId = $alumno->getInstituto()->getId();
            try {
                $primerDiaVencimiento = $alumno->getPrimerDiaVencimiento($institutoId);
            } catch (\Exception $e) {
                error_log('ERROR obteniendo primerDiaVencimiento: ' . $e->getMessage());
                $primerDiaVencimiento = 5; // Valor por defecto
            }
            
            $montoTotalBaseVencidos = 0;
            $montoTotalBaseNoVencidos = 0;
            $maxInteres = 0;
            $motivoInteres = "Sin interés aplicado";
            
            // Primero, separar meses vencidos de no vencidos y calcular montos base
            foreach ($mesesPorCurso as $cursoId => $meses) {
                $curso = $this->entityManager->getRepository(Curso::class)->find($cursoId);
                if (!$curso) continue;
                
                $montoBaseCurso = $curso->getPrecio();
                
                // Separar meses vencidos de no vencidos
                foreach ($meses as $mesData) {
                    $mes = (int)$mesData['mes'];
                    $ano = (int)$mesData['ano'];
                    
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
                }
            }
            
            // Ahora calcular interés basándose en los meses vencidos seleccionados
            // Agrupar meses vencidos por curso
            $mesesVencidosPorCurso = [];
            foreach ($mesesPorCurso as $cursoId => $meses) {
                $curso = $this->entityManager->getRepository(Curso::class)->find($cursoId);
                if (!$curso) continue;
                
                $mesesVencidosCurso = [];
                foreach ($meses as $mesData) {
                    $mes = (int)$mesData['mes'];
                    $ano = (int)$mesData['ano'];
                    
                    // Determinar si el mes está vencido (misma lógica que arriba)
                    $esVencido = false;
                    if ($ano < $anoActual || ($ano == $anoActual && $mes < $mesActual)) {
                        $esVencido = true;
                    } elseif ($ano == $anoActual && $mes == $mesActual && $diaActual > $primerDiaVencimiento) {
                        $esVencido = true;
                    }
                    
                    if ($esVencido) {
                        $mesesVencidosCurso[] = [
                            'mes' => $mes,
                            'ano' => $ano,
                            'curso' => $curso->getNombre(),
                            'curso_obj' => $curso
                        ];
                    }
                }
                
                if (!empty($mesesVencidosCurso)) {
                    if (!isset($mesesVencidosPorCurso[$cursoId])) {
                        $mesesVencidosPorCurso[$cursoId] = [
                            'curso' => $curso,
                            'meses' => []
                        ];
                    }
                    $mesesVencidosPorCurso[$cursoId]['meses'] = $mesesVencidosCurso;
                }
            }
            
            // Calcular interés para cada curso que tiene meses vencidos
            foreach ($mesesVencidosPorCurso as $cursoId => $datosCurso) {
                if (!empty($datosCurso['meses'])) {
                    try {
                        $calculoTemporal = $this->calcularMonto($alumno, $datosCurso['curso'], $vencimientos, $datosCurso['meses'], []);
                        if ($calculoTemporal['porcentajeInteres'] > $maxInteres) {
                            $maxInteres = $calculoTemporal['porcentajeInteres'];
                            $motivoInteres = $calculoTemporal['motivoInteres'];
                        }
                    } catch (\Exception $e) {
                        error_log('ERROR calcularMonto en calcularMontoAjax: ' . $e->getMessage());
                        error_log('Stack trace: ' . $e->getTraceAsString());
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
            
            // Calcular porcentajes de descuento: efectivo, hermanos (según config) + promocionales seleccionados
            $porcentajeDescuentoTotal = 0;
            $descuentosAplicados = [];
            $puedeRecibirDescuentos = true;
            if ($configuracion && $configuracion->getDeshabilitarDescuentosEnDeuda() && $alumno->tieneDeudasVencidas()) {
                $puedeRecibirDescuentos = false;
                $descuentosAplicados[] = "No se aplican descuentos porque el alumno tiene deudas vencidas";
            }
            $metodoPago = $request->request->get('metodo_pago');
            $aplicarDescuentoEfectivo = ($metodoPago === null || $metodoPago === 'efectivo');
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
            
            // Obtener texto descriptivo del orden de cálculo
            $ordenTexto = [
                'interes_primero' => 'Interés primero, luego descuentos',
                'descuento_primero' => 'Descuentos primero, luego interés',
                'neto' => 'Neto (diferencia entre intereses y descuentos)',
                'descuentos_solo_base' => 'Descuentos sobre base, interés sobre base original'
            ];
            $ordenDescripcion = [
                'interes_primero' => 'Se aplica el interés sobre el monto base, luego los descuentos sobre el resultado.',
                'descuento_primero' => 'Se aplican los descuentos sobre el monto base, luego el interés sobre el resultado.',
                'neto' => 'Ambos se calculan sobre la base y se hace la diferencia.',
                'descuentos_solo_base' => 'Los descuentos se aplican sobre la base, el interés se suma sobre la base original.'
            ];
            
            return new JsonResponse([
                'monto' => $montoTotalFinal,
                'montoBase' => $montoTotalBase,
                'montoConInteres' => $montoTotalConInteres,
                'porcentajeInteres' => $porcentajeInteres,
                'motivoInteres' => $motivoInteres,
                'descuentosAplicados' => $descuentosAplicados,
                'porcentajeDescuentoTotal' => $porcentajeDescuentoTotal,
                'ordenCalculo' => $ordenCalculo,
                'ordenCalculoTexto' => $ordenTexto[$ordenCalculo] ?? 'Interés primero, luego descuentos',
                'ordenCalculoDescripcion' => $ordenDescripcion[$ordenCalculo] ?? 'Se aplica el interés sobre el monto base, luego los descuentos sobre el resultado.'
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
        $alumnoOriginal = $pago->getAlumno();
        $cursoOriginal = $pago->getCurso();
        $mesOriginal = $pago->getMes();
        $anoOriginal = $pago->getAno();
        $montoOriginal = (float) $pago->getMonto();

        $alumnoId = $request->query->get('id');
        $cursoId = $request->query->get('curso');
        if($alumnoId){
            $alumno = $alumnoRepository->find($alumnoId);
            $pago->setAlumno($alumno);
        }
        if($cursoId){
            $curso = $cursoRepository->find($cursoId);
            $pago->setCurso($curso);
        }
        // En edición permitir seleccionar cualquier curso del instituto (corrección de carga)
        $cursos = $cursoRepository->findBy(['instituto' => $instituto]);

        // Obtener los vencimientos del instituto
        $vencimientos = $instituto->getVencimientos();

        // Crear el formulario
        $alumnos = $instituto->getAlumnos();
        $form = $this->createForm(AlumnosPagosType::class, $pago, [
            'alumnos' => $alumnos,
            'cursos' => array_values($cursos),
            'vencimientos' => $vencimientos,
            'modo_edicion' => false,
            'bloquear_monto_en_edicion' => false,
            'mes_multiple' => false
        ]);

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
                    $mesForm = $request->request->all('alumnos_pagos')['mes'] ?? null;
                    if (is_array($mesForm) && !empty($mesForm)) {
                        $pago->setMes((int) $mesForm[0]);
                    } elseif ($mesForm !== null && $mesForm !== '') {
                        $pago->setMes((int) $mesForm);
                    }

                    if ($pago->getMes() === null || $pago->getAno() === null) {
                        $this->addFlash('danger', 'Debe seleccionar un mes y un año válidos.');
                        return $this->redirectToRoute('app_alumnos_pagos_edit', ['id' => $pago->getId()]);
                    }

                    $montoActualizado = (float) $pago->getMonto();
                    $montoModificado = abs($montoActualizado - $montoOriginal) > 0.00001;
                    $claveModificada = $montoModificado
                        || $pago->getAlumno() !== $alumnoOriginal
                        || $pago->getCurso() !== $cursoOriginal
                        || (int) $pago->getMes() !== (int) $mesOriginal
                        || (int) $pago->getAno() !== (int) $anoOriginal;
                    $deudasIdsAReaplicar = [];

                    if ($claveModificada) {
                        // Revertir aplicaciones actuales del pago y reaplicar con el nuevo monto.
                        foreach ($pago->getAplicaciones()->toArray() as $aplicacion) {
                            $deudasIdsAReaplicar[] = $aplicacion->getDeuda()->getId();
                            $pago->removeAplicacion($aplicacion);
                            $this->entityManager->remove($aplicacion);
                        }
                        $pago->setMontoRestante($montoActualizado);
                    }

                    // Verificar si ya existe un pago para este alumno, curso, mes y año
                    $pagoExistente = $this->entityManager->getRepository(AlumnosPagos::class)->findOneBy([
                        'alumno' => $pago->getAlumno(),
                        'curso' => $pago->getCurso(),
                        'mes' => $pago->getMes(),
                        'ano' => $pago->getAno()
                    ]);

                    if ($pagoExistente && $pagoExistente->getId() !== $pago->getId()) {
                        $this->addFlash('danger', 'Ya existe un pago registrado para este alumno en este curso para el mes y año seleccionados.');
                        return $this->redirectToRoute('app_alumnos_pagos_edit', ['id' => $pago->getId()]);
                    }

                    // Actualizar el pago en el historial
                    //$this->historialCursosService->actualizarPago($pago);
                    $this->entityManager->persist($pago);
                    $this->entityManager->flush();

                    if ($claveModificada) {
                        $deudaEspecifica = $this->entityManager->getRepository(\App\Entity\DeudaAlumno::class)
                            ->findOneBy([
                                'alumno' => $pago->getAlumno(),
                                'curso' => $pago->getCurso(),
                                'mes' => $pago->getMes(),
                                'ano' => $pago->getAno()
                            ]);

                        if ($deudaEspecifica) {
                            $deudasIdsAReaplicar = [$deudaEspecifica->getId()];
                        }

                        if (!empty($deudasIdsAReaplicar)) {
                            $this->pagoService->aplicarPagoRestante(
                                $pago,
                                array_values(array_unique($deudasIdsAReaplicar))
                            );
                        }
                    }
                    
                    // Consumir tokens después de guardar exitosamente
                    $this->tokenService->consumeTokens(
                        $instituto,
                        'pago.edit',
                        $this->getUser(),
                        'Editar pago: ' . $pago->getAlumno()->getNombreApellido() . ' - ' . $pago->getCurso()->getNombre() . ' (' . $pago->getMes() . '/' . $pago->getAno() . ')',
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
                'Eliminar pago: ' . $pago->getAlumno()->getNombreApellido() . ' - ' . $pago->getCurso()->getNombre() . ' (' . $pago->getMes() . '/' . $pago->getAno() . ')',
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
        \App\Service\DeudaService $deudaService,
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
                // Crear el pago
                $pago = new AlumnosPagos();
                $pago->setAlumno($deuda->getAlumno());
                $pago->setCurso($deuda->getCurso());
                $pago->setMes($deuda->getMes());
                $pago->setAno($deuda->getAno());
                $pago->setMonto((float)$monto);
                $pago->setFecha(new \DateTime());
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
                    'Registrar pago: ' . $deuda->getAlumno()->getNombreApellido() . ' - ' . $deuda->getCurso()->getNombre() . ' (' . $deuda->getPeriodo() . ')',
                    'AlumnosPagos',
                    $pago->getId()
                );
                
                // Enviar email con el recibo si está configurado
                try {
                    $this->notificationService->enviarReciboPago($pago);
                } catch (\Exception $e) {
                    // No interrumpir el flujo si falla el envío del email
                }
                
                // Mostrar información sobre el resultado
                $mensaje = sprintf(
                    'Pago de $%s registrado correctamente para %s %s, curso %s, periodo %s.', 
                    number_format($monto, 2, ',', '.'),
                    $deuda->getAlumno()->getNombre(), 
                    $deuda->getAlumno()->getApellido(),
                    $deuda->getCurso()->getNombre(),
                    $deuda->getPeriodo()
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
                
                $this->addFlash('success', $mensaje);
                
                return $this->redirectToRoute('app_alumnos_pagos_index', ['alumno' => $deuda->getAlumno()->getId()]);
            } catch (\Exception $e) {
                $this->addFlash('danger', 'Error al registrar el pago: ' . $e->getMessage());
            }
        }
        
        // Obtener los métodos de pago disponibles
        $metodosPago = $this->entityManager->getRepository(AlumnosPagos::class)
            ->createQueryBuilder('p')
            ->select('DISTINCT p.metodoPago')
            ->getQuery()
            ->getSingleColumnResult();
        
        // Si no hay métodos registrados, definir los métodos predeterminados
        if (empty($metodosPago)) {
            $metodosPago = ['Efectivo', 'Transferencia', 'Débito', 'Crédito'];
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
            'metodosPago' => $metodosPago
        ]);
    }
}
