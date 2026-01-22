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

    public function __construct(
        EntityManagerInterface $entityManager, 
        HistorialCursosService $historialCursosService,
        DeudaService $deudaService,
        NotificationService $notificationService,
        TokenService $tokenService
    ) {
        $this->entityManager = $entityManager;
        $this->historialCursosService = $historialCursosService;
        $this->deudaService = $deudaService;
        $this->notificationService = $notificationService;
        $this->tokenService = $tokenService;
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

        $alumnoId = $request->query->get('alumno');
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

        if($alumnoId){
            $qb->andWhere('p.alumno = :alumno')
               ->setParameter('alumno', $alumno);
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

        // Obtener próximos vencimientos (deudas pendientes)
        $deudaRepository = $this->entityManager->getRepository(\App\Entity\DeudaAlumno::class);
        $deudasPendientes = $deudaRepository->createQueryBuilder('d')
            ->leftJoin('d.alumno', 'a')
            ->leftJoin('d.curso', 'c')
            ->andWhere('d.pagado = :pagado')
            ->andWhere('a.instituto = :instituto')
            ->andWhere('a.activo = :activo')
            ->setParameter('pagado', false)
            ->setParameter('instituto', $instituto)
            ->setParameter('activo', true)
            ->orderBy('d.ano', 'ASC')
            ->addOrderBy('d.mes', 'ASC')
            ->setMaxResults(10)
            ->getQuery()
            ->getResult();

        return $this->render('alumnos_pagos/index.html.twig', [
            'pagos' => $pagination,
            'alumno' => $alumno,
            'nombreAlumno' => $nombreAlumno,
            'cursos' => $cursos,
            'cursoSelected' => $cursoSelected,
            'metodosPago' => $metodosPago,
            'metodoSelected' => $metodoSelected,
            'fechaDesde' => $fechaDesde,
            'fechaHasta' => $fechaHasta,
            'busqueda' => $busqueda,
            'sort' => $sort,
            'order' => $order,
            'alumnoId' => $alumnoId,
            'total' => $pagination->getTotalItemCount(),
            'estadisticas' => [
                'hoy' => ['total' => $totalHoy, 'cantidad' => $cantidadHoy],
                'semana' => ['total' => $totalSemana, 'cantidad' => $cantidadSemana],
                'mes' => ['total' => $totalMes, 'cantidad' => $cantidadMes],
                'ano' => ['total' => $totalAno, 'cantidad' => $cantidadAno],
            ],
            'deudasPendientes' => $deudasPendientes
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
     */
    private function calcularMonto(Alumno $alumno, Curso $curso, $vencimientos, array $mesesAdeudados, array $descuentosPromocionalesSeleccionados = []): array
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

        // Si hay meses adeudados (deudas anteriores)
        if (!empty($mesesAdeudados)) {
            // Para deudas de meses anteriores, aplicar el máximo interés configurado
            $maxInteres = 0;
            $vencimientoAplicado = null;
            
            foreach ($vencimientosOrdenados as $vencimiento) {
                if ($vencimiento->getPorcentajeInteres() > $maxInteres) {
                    $maxInteres = $vencimiento->getPorcentajeInteres();
                    $vencimientoAplicado = $vencimiento;
                }
            }
            
            $porcentajeInteres = $maxInteres;
            $motivoInteres = "Máximo interés aplicado por deudas anteriores (" . count($mesesAdeudados) . " meses)";
        } 
        // Si es para el mes actual
        else {
            // Determinar el vencimiento aplicable según el día actual
            $vencimientoAplicado = null;
            
            // Recorrer vencimientos ordenados por día (ascendente)
            foreach ($vencimientosOrdenados as $vencimiento) {
                // Si el día actual ya pasó este vencimiento
                if ($diaActual > $vencimiento->getDiaVencimiento()) {
                    $vencimientoAplicado = $vencimiento;
                    // Seguimos iterando para encontrar el último vencimiento aplicable
                } else {
                    // Si encontramos un vencimiento que aún no pasó, salimos del bucle
                    break;
                }
            }
            
            // Si existe un vencimiento aplicable, usamos su interés
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
            // Aplicar descuento por pago en efectivo si corresponde
            if ($configuracion->getDescuentoEfectivo() && $configuracion->getDescuentoEfectivo() > 0) {
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
        if($alumnoId){
            $alumno = $alumnoRepository->find($alumnoId);
        } else {
            $alumno = $alumnoRepository->findOneBy(['instituto' => $this->getUser()->getInstituto()]);
        }
        $alumnosPago = new AlumnosPagos();
        $alumnosPago->setAlumno($alumno);
        $alumnosPago->setFecha(new \DateTime());
        $alumnosPago->setMetodoPago('Efectivo');

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
                'monto' => $deuda->getMonto()
            ];
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
            
            // Debug: Log para ver qué se está recibiendo
            error_log('DEBUG submit - modo_pago_multiple: ' . ($modoPagoMultiple ? '1' : '0'));
            error_log('DEBUG submit - meses_adeudados recibidos: ' . json_encode($mesesAdeudadosSeleccionados));
            error_log('DEBUG submit - meses del formulario: ' . json_encode($mesesSeleccionadosForm));
            
            // Priorizar checkboxes de meses_adeudados sobre el select múltiple
            // Si hay meses seleccionados en los checkboxes, usar esos (ya tienen formato mes_ano_cursoId)
            if (!empty($mesesAdeudadosSeleccionados) && is_array($mesesAdeudadosSeleccionados)) {
                $modoPagoMultiple = true;
                // Los valores ya están en el formato correcto: mes_ano_cursoId
                error_log('DEBUG submit - Usando checkboxes, modo múltiple activado');
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
                error_log('DEBUG submit - Usando select múltiple, meses convertidos: ' . json_encode($mesesAdeudadosSeleccionados));
            }
            
            error_log('DEBUG submit - Final: modoPagoMultiple=' . ($modoPagoMultiple ? 'true' : 'false') . ', mesesAdeudadosSeleccionados count=' . count($mesesAdeudadosSeleccionados));
            
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
                    
                    // Calcular el monto total con descuentos aplicados sobre el total
                    $montoTotalBase = 0;
                    $mesesInfo = [];
                    $maxInteres = 0;
                    $motivoInteres = "Sin interés aplicado";
                    
                    // Primero, calcular monto base total y determinar interés máximo
                    foreach ($mesesAdeudadosSeleccionados as $mesAdeudado) {
                        list($mes, $ano, $cursoId) = explode('_', $mesAdeudado);
                        $curso = $this->entityManager->getRepository(Curso::class)->find($cursoId);
                        
                        if (!$curso) {
                            continue;
                        }
                        
                        $montoBaseCurso = $curso->getPrecio();
                        $montoTotalBase += $montoBaseCurso;
                        
                        $mesesInfo[] = [
                            'mes' => (int)$mes,
                            'ano' => (int)$ano,
                            'curso' => $curso,
                            'montoBase' => $montoBaseCurso
                        ];
                        
                        // Verificar meses adeudados para determinar interés
                        $mesesAdeudadosCurso = $this->historialCursosService->verificarMesesAdeudadosPorCurso($alumno, $curso);
                        if (!empty($mesesAdeudadosCurso)) {
                            $calculoTemporal = $this->calcularMonto($alumno, $curso, $vencimientos, $mesesAdeudadosCurso, []);
                            if ($calculoTemporal['porcentajeInteres'] > $maxInteres) {
                                $maxInteres = $calculoTemporal['porcentajeInteres'];
                                $motivoInteres = $calculoTemporal['motivoInteres'];
                            }
                        }
                    }
                    
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
                    
                    // Aplicar intereses y descuentos según el orden configurado
                    switch ($ordenCalculo) {
                        case 'descuento_primero':
                            // Descuentos primero, luego interés
                            $montoTotalFinal = $montoTotalBase;
                            if ($porcentajeDescuentoTotal > 0) {
                                $montoTotalFinal = $montoTotalBase * (1 - ($porcentajeDescuentoTotal / 100));
                            }
                            if ($porcentajeInteres > 0) {
                                $montoTotalFinal = $montoTotalFinal * (1 + ($porcentajeInteres / 100));
                            }
                            break;
                            
                        case 'neto':
                            // Ambos sobre base, diferencia neta
                            $montoInteres = $porcentajeInteres > 0 ? $montoTotalBase * ($porcentajeInteres / 100) : 0;
                            $montoDescuento = $porcentajeDescuentoTotal > 0 ? $montoTotalBase * ($porcentajeDescuentoTotal / 100) : 0;
                            $montoTotalFinal = $montoTotalBase + $montoInteres - $montoDescuento;
                            break;
                            
                        case 'descuentos_solo_base':
                            // Descuentos sobre base, interés sobre base original
                            $montoTotalFinal = $montoTotalBase;
                            if ($porcentajeDescuentoTotal > 0) {
                                $montoTotalFinal = $montoTotalBase * (1 - ($porcentajeDescuentoTotal / 100));
                            }
                            if ($porcentajeInteres > 0) {
                                $montoTotalFinal = $montoTotalFinal + ($montoTotalBase * ($porcentajeInteres / 100));
                            }
                            break;
                            
                        case 'interes_primero':
                        default:
                            // Interés primero, luego descuentos (comportamiento actual)
                            $montoTotalConInteres = $montoTotalBase;
                            if ($porcentajeInteres > 0) {
                                $montoTotalConInteres = $montoTotalBase * (1 + ($porcentajeInteres / 100));
                            }
                            $montoTotalFinal = $montoTotalConInteres;
                            if ($porcentajeDescuentoTotal > 0) {
                                $montoTotalFinal = $montoTotalConInteres * (1 - ($porcentajeDescuentoTotal / 100));
                            }
                            break;
                    }
                    
                    // Distribuir el monto total proporcionalmente entre los meses
                    $pagosCreados = 0;
                    $errores = [];
                    
                    foreach ($mesesInfo as $mesInfo) {
                        // Verificar si ya existe un pago para este mes/año/curso
                        $pagoExistente = $this->entityManager->getRepository(AlumnosPagos::class)->findOneBy([
                            'alumno' => $alumno,
                            'curso' => $mesInfo['curso'],
                            'mes' => $mesInfo['mes'],
                            'ano' => $mesInfo['ano']
                        ]);
                        
                        if ($pagoExistente) {
                            $errores[] = "Ya existe un pago para {$mesInfo['mes']}/{$mesInfo['ano']} en {$mesInfo['curso']->getNombre()}";
                            continue;
                        }
                        
                        // Calcular monto proporcional para este mes
                        $proporcion = $mesInfo['montoBase'] / $montoTotalBase;
                        $montoMes = $montoTotalFinal * $proporcion;
                        
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
                        
                        // Registrar el pago
                        $this->historialCursosService->registrarPago($nuevoPago);
                        $pagosCreados++;
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
                        
                        // Recalcular el monto con los descuentos promocionales seleccionados
                        if ($alumnosPago->getCurso()) {
                            $mesesAdeudadosCurso = $this->historialCursosService->verificarMesesAdeudadosPorCurso($alumno, $alumnosPago->getCurso());
                            $descuentosPromocionalesSeleccionados = [];
                            foreach ($descuentosPromocionalesIds as $id) {
                                $descuento = $descuentoPromocionalRepository->find($id);
                                if ($descuento && $descuento->getActivo()) {
                                    $descuentosPromocionalesSeleccionados[] = $descuento;
                                }
                            }
                            $calculoMonto = $this->calcularMonto($alumno, $alumnosPago->getCurso(), $vencimientos, $mesesAdeudadosCurso, $descuentosPromocionalesSeleccionados);
                            $alumnosPago->setMonto($calculoMonto['monto']);
                        }
                        
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

                    // Registrar el pago en el historial
                    $this->historialCursosService->registrarPago($alumnosPago);
                    
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

        // Obtener configuración del orden de cálculo
        $configuracion = $alumno->getInstituto()->getConfiguracion();
        $ordenCalculo = $configuracion ? $configuracion->getOrdenCalculoInteresesDescuentos() : 'interes_primero';

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
        
        $vencimientos = $alumno->getInstituto()->getVencimientos();
        
        // Si hay múltiples meses seleccionados, calcular el total
        if (!empty($mesesSeleccionados) && is_array($mesesSeleccionados)) {
            $montoTotalBase = 0;
            $montoTotalConInteres = 0;
            $porcentajeInteres = 0;
            $motivoInteres = "Sin interés aplicado";
            $descuentosAplicados = [];
            $porcentajeDescuentoTotal = 0;
            
            // Debug: Log para ver qué se está recibiendo
            error_log('DEBUG calcularMontoAjax - mesesSeleccionados: ' . json_encode($mesesSeleccionados));
            error_log('DEBUG calcularMontoAjax - descuentosPromocionalesIds: ' . json_encode($descuentosPromocionalesIds));
            
            // Agrupar meses por curso para calcular intereses correctamente
            $mesesPorCurso = [];
            foreach ($mesesSeleccionados as $mesData) {
                list($mes, $ano, $cursoId) = explode('_', $mesData);
                if (!isset($mesesPorCurso[$cursoId])) {
                    $mesesPorCurso[$cursoId] = [];
                }
                $mesesPorCurso[$cursoId][] = ['mes' => $mes, 'ano' => $ano];
            }
            
            // Debug: Log para ver cómo se agruparon los meses
            error_log('DEBUG calcularMontoAjax - mesesPorCurso: ' . json_encode($mesesPorCurso));
            
            // Calcular monto base total y determinar interés máximo
            $maxInteres = 0;
            foreach ($mesesPorCurso as $cursoId => $meses) {
                $curso = $this->entityManager->getRepository(Curso::class)->find($cursoId);
                if (!$curso) continue;
                
                $montoBaseCurso = $curso->getPrecio();
                $montoTotalBase += $montoBaseCurso * count($meses);
                
                // Debug: Log para ver el cálculo del monto base
                error_log("DEBUG calcularMontoAjax - Curso ID: $cursoId, Precio: $montoBaseCurso, Meses: " . count($meses) . ", Subtotal: " . ($montoBaseCurso * count($meses)));
                
                // Verificar meses adeudados para este curso
                $mesesAdeudadosCurso = $this->historialCursosService->verificarMesesAdeudadosPorCurso($alumno, $curso);
                if (!empty($mesesAdeudadosCurso)) {
                    // Calcular interés para este curso
                    $calculoTemporal = $this->calcularMonto($alumno, $curso, $vencimientos, $mesesAdeudadosCurso, []);
                    if ($calculoTemporal['porcentajeInteres'] > $maxInteres) {
                        $maxInteres = $calculoTemporal['porcentajeInteres'];
                        $motivoInteres = $calculoTemporal['motivoInteres'];
                    }
                }
            }
            
            $porcentajeInteres = $maxInteres;
            
            // Obtener configuración del orden de cálculo
            $configuracion = $alumno->getInstituto()->getConfiguracion();
            $ordenCalculo = $configuracion ? $configuracion->getOrdenCalculoInteresesDescuentos() : 'interes_primero';
            
            // Calcular porcentajes de descuento promocional
            $porcentajeDescuentoTotal = 0;
            foreach ($descuentosPromocionalesSeleccionados as $descuentoPromocional) {
                $porcentajeDescuentoPromocional = (float)$descuentoPromocional->getPorcentaje();
                $porcentajeDescuentoTotal += $porcentajeDescuentoPromocional;
                $descuentosAplicados[] = "Descuento promocional: " . $descuentoPromocional->getNombre() . " (" . $porcentajeDescuentoPromocional . "%)";
            }
            
            // Aplicar intereses y descuentos según el orden configurado
            $montoTotalConInteres = $montoTotalBase;
            switch ($ordenCalculo) {
                case 'descuento_primero':
                    // Descuentos primero, luego interés
                    $montoFinal = $montoTotalBase;
                    if ($porcentajeDescuentoTotal > 0) {
                        $montoFinal = $montoTotalBase * (1 - ($porcentajeDescuentoTotal / 100));
                    }
                    if ($porcentajeInteres > 0) {
                        $montoFinal = $montoFinal * (1 + ($porcentajeInteres / 100));
                        $montoTotalConInteres = $montoFinal;
                    }
                    break;
                    
                case 'neto':
                    // Ambos sobre base, diferencia neta
                    $montoInteres = $porcentajeInteres > 0 ? $montoTotalBase * ($porcentajeInteres / 100) : 0;
                    $montoDescuento = $porcentajeDescuentoTotal > 0 ? $montoTotalBase * ($porcentajeDescuentoTotal / 100) : 0;
                    $montoFinal = $montoTotalBase + $montoInteres - $montoDescuento;
                    $montoTotalConInteres = $montoTotalBase + $montoInteres;
                    break;
                    
                case 'descuentos_solo_base':
                    // Descuentos sobre base, interés sobre base original
                    $montoFinal = $montoTotalBase;
                    if ($porcentajeDescuentoTotal > 0) {
                        $montoFinal = $montoTotalBase * (1 - ($porcentajeDescuentoTotal / 100));
                    }
                    if ($porcentajeInteres > 0) {
                        $montoFinal = $montoFinal + ($montoTotalBase * ($porcentajeInteres / 100));
                        $montoTotalConInteres = $montoTotalBase * (1 + ($porcentajeInteres / 100));
                    }
                    break;
                    
                case 'interes_primero':
                default:
                    // Interés primero, luego descuentos (comportamiento actual)
                    if ($porcentajeInteres > 0) {
                        $montoTotalConInteres = $montoTotalBase * (1 + ($porcentajeInteres / 100));
                    }
                    $montoFinal = $montoTotalConInteres;
                    if ($porcentajeDescuentoTotal > 0) {
                        $montoFinal = $montoTotalConInteres * (1 - ($porcentajeDescuentoTotal / 100));
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
                'monto' => $montoFinal,
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
        
        // Calcular el monto
        $mesesAdeudadosCurso = $this->historialCursosService->verificarMesesAdeudadosPorCurso($alumno, $curso);
        $calculoMonto = $this->calcularMonto($alumno, $curso, $vencimientos, $mesesAdeudadosCurso, $descuentosPromocionalesSeleccionados);
        
        return new JsonResponse([
            'monto' => $calculoMonto['monto'],
            'montoBase' => $calculoMonto['montoBase'],
            'porcentajeInteres' => $calculoMonto['porcentajeInteres'],
            'motivoInteres' => $calculoMonto['motivoInteres'],
            'descuentosAplicados' => $calculoMonto['descuentosAplicados'],
            'porcentajeDescuentoTotal' => $calculoMonto['porcentajeDescuentoTotal']
        ]);
    }

    /**
     * @Route("/{id}", name="app_alumnos_pagos_show", methods={"GET"})
     */
    public function show(AlumnosPagos $pago): Response
    {
        $instituto = $this->getUser()->getInstituto();
        
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
        $alumno = $pago->getAlumno();

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
        $instituto = $this->getUser()->getInstituto();
        
        // Obtener los cursos históricos del alumno
        $cursosHistoricos = $alumno->getCursosHistoricos();
        $cursos = [];
        foreach ($cursosHistoricos as $cursoHistorico) {
            $cursos[] = $cursoHistorico->getCurso();
        }

        // Obtener los vencimientos del instituto
        $vencimientos = $instituto->getVencimientos();

        // Crear el formulario
        $alumnos = $instituto->getAlumnos();
        $form = $this->createForm(AlumnosPagosType::class, $pago, [
            'alumnos' => $alumnos,
            'cursos' => array_values($cursos),
            'vencimientos' => $vencimientos
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
                    
                    // Consumir tokens después de guardar exitosamente
                    $this->tokenService->consumeTokens(
                        $instituto,
                        'pago.edit',
                        $this->getUser(),
                        'Editar pago: ' . $alumno->getNombreApellido() . ' - ' . $pago->getCurso()->getNombre() . ' (' . $pago->getMes() . '/' . $pago->getAno() . ')',
                        'AlumnosPagos',
                        $pago->getId()
                    );
                    
                    $this->addFlash('success', 'Pago actualizado correctamente.');
                    return $this->redirectToRoute('app_alumnos_pagos_index', ['alumno' => $alumno->getId()]);
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
        // Verificar que la deuda no esté pagada
        if ($deuda->isPagado()) {
            $this->addFlash('warning', 'Esta deuda ya ha sido pagada.');
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
            
            if (!$monto) {
                $this->addFlash('danger', 'El monto es obligatorio.');
                return $this->redirectToRoute('app_alumnos_pagos_por_deuda', ['id' => $deuda->getId()]);
            }
            
            if (!$metodoPago) {
                $this->addFlash('danger', 'El método de pago es obligatorio.');
                return $this->redirectToRoute('app_alumnos_pagos_por_deuda', ['id' => $deuda->getId()]);
            }
            
            // Verificar si ya existe un pago para esta deuda
            if ($deuda->getPago()) {
                $this->addFlash('warning', 'Ya existe un pago registrado para esta deuda.');
                return $this->redirectToRoute('app_alumnos_pagos_index', ['alumno' => $deuda->getAlumno()->getId()]);
            }
            
            try {
                // Registrar el pago a través del servicio
                $pago = $deudaService->registrarPago($deuda, (float)$monto);
                
                // Actualizar metodo de pago y observaciones
                $pago->setMetodoPago($metodoPago);
                $pago->setObservacion($observacion);
                
                // Validar el pago creado
                $errores = $validator->validate($pago);
                if (count($errores) > 0) {
                    $this->addFlash('danger', 'Error al validar el pago: ' . $errores[0]->getMessage());
                    return $this->redirectToRoute('app_alumnos_pagos_por_deuda', ['id' => $deuda->getId()]);
                }
                
                // Confirmar cambios
                $this->entityManager->flush();
                
                // Enviar email con el recibo si está configurado
                try {
                    $this->notificationService->enviarReciboPago($pago);
                } catch (\Exception $e) {
                    // No interrumpir el flujo si falla el envío del email
                }
                
                $this->addFlash('success', sprintf(
                    'Pago registrado correctamente para %s %s, curso %s, periodo %s.', 
                    $deuda->getAlumno()->getNombre(), 
                    $deuda->getAlumno()->getApellido(),
                    $deuda->getCurso()->getNombre(),
                    $deuda->getPeriodo()
                ));
                
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
        
        // Renderizar formulario
        return $this->render('alumnos_pagos/registrar_por_deuda.html.twig', [
            'deuda' => $deuda,
            'montoTotal' => $montoTotal,
            'metodosPago' => $metodosPago
        ]);
    }
}
