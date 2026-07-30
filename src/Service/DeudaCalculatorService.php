<?php

namespace App\Service;

use App\Entity\Alumno;
use App\Entity\AlumnoCursoHistorico;
use App\Entity\AlumnosPagos;
use App\Entity\Instituto;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Servicio para calcular deudas on-demand desde el historial de cursos
 * Sin depender de la tabla deuda_alumno
 */
class DeudaCalculatorService
{
    private $entityManager;
    private $institutoTimezoneService;

    public function __construct(
        EntityManagerInterface $entityManager,
        InstitutoTimezoneService $institutoTimezoneService
    ) {
        $this->entityManager = $entityManager;
        $this->institutoTimezoneService = $institutoTimezoneService;
    }

    /**
     * Último día del mes de $fecha a las 23:59:59 UTC, como \DateTime mutable.
     *
     * Se construye explícitamente porque las fechas del instituto son DateTimeImmutable
     * y ahí modify('last day of this month') devuelve una instancia nueva sin mutar.
     *
     * UTC para comparar contra las fechas sin hora del histórico, que se guardan a
     * medianoche UTC (ver InstitutoTimezoneService::normalizeDateOnly()). Se toma el mes
     * civil de $fecha, así que una fecha en la zona del instituto conserva su mes.
     */
    private static function ultimoDiaDelMes(\DateTimeInterface $fecha): \DateTime
    {
        return new \DateTime($fecha->format('Y-m-t') . ' 23:59:59', new \DateTimeZone('UTC'));
    }

    /**
     * Índice de meses con pago precargado: 'alumnoId_cursoId_mes_ano' => true.
     *
     * Cuando está poblado, existePagoParaMes() lo consulta en memoria en lugar de
     * hacer un COUNT por cada mes de cada histórico de cada alumno. null = sin
     * precargar (se cae al COUNT individual, para las llamadas de un solo alumno).
     */
    private ?array $indicePagos = null;

    /**
     * Históricos activos precargados: id de alumno => AlumnoCursoHistorico[].
     * null = sin precargar (se cae al findBy por alumno).
     */
    private ?array $indiceHistoricos = null;

    /**
     * Vencimientos por id de instituto, cacheados durante el request.
     */
    private array $cacheVencimientos = [];

    /**
     * Calcula las deudas de varios alumnos precargando los pagos en una sola query.
     *
     * Sin esto, el cálculo hacía (#alumnos × #históricos × #meses) COUNT: con unos
     * cientos de alumnos son miles de queries por carga de pantalla.
     *
     * @param Alumno[] $alumnos
     * @return array<int, array> deudas por id de alumno, en el mismo formato que calcularDeudasAlumno()
     */
    public function calcularDeudasParaAlumnos(array $alumnos): array
    {
        if (!$alumnos) {
            return [];
        }

        $pagosAnterior = $this->indicePagos;
        $historicosAnterior = $this->indiceHistoricos;
        $this->indicePagos = $this->cargarIndicePagos($alumnos);
        $this->indiceHistoricos = $this->cargarIndiceHistoricos($alumnos);

        try {
            $porAlumno = [];
            foreach ($alumnos as $alumno) {
                $porAlumno[$alumno->getId()] = $this->calcularDeudasAlumno($alumno);
            }

            return $porAlumno;
        } finally {
            $this->indicePagos = $pagosAnterior;
            $this->indiceHistoricos = $historicosAnterior;
        }
    }

    /**
     * Carga en una query los históricos activos de los alumnos dados.
     *
     * @return array<int, AlumnoCursoHistorico[]> históricos por id de alumno
     */
    private function cargarIndiceHistoricos(array $alumnos): array
    {
        $historicos = $this->entityManager->getRepository(AlumnoCursoHistorico::class)
            ->createQueryBuilder('h')
            ->innerJoin('h.curso', 'c')
            ->addSelect('c')
            ->where('h.alumno IN (:alumnos)')
            ->andWhere('h.activo = :activo')
            ->setParameter('alumnos', $alumnos)
            ->setParameter('activo', true)
            ->getQuery()
            ->getResult();

        $indice = [];
        foreach ($historicos as $historico) {
            $indice[$historico->getAlumno()->getId()][] = $historico;
        }

        return $indice;
    }

    /**
     * Carga en una query los meses que ya tienen algún pago, para los alumnos dados.
     */
    private function cargarIndicePagos(array $alumnos): array
    {
        $filas = $this->entityManager->createQueryBuilder()
            ->select('IDENTITY(p.alumno) AS alumnoId', 'IDENTITY(p.curso) AS cursoId', 'p.mes', 'p.ano')
            ->from(\App\Entity\AlumnosPagos::class, 'p')
            ->where('p.alumno IN (:alumnos)')
            ->setParameter('alumnos', $alumnos)
            ->groupBy('p.alumno', 'p.curso', 'p.mes', 'p.ano')
            ->getQuery()
            ->getArrayResult();

        $indice = [];
        foreach ($filas as $fila) {
            $indice[$fila['alumnoId'] . '_' . $fila['cursoId'] . '_' . $fila['mes'] . '_' . $fila['ano']] = true;
        }

        return $indice;
    }

    /**
     * Calcula todas las deudas de un alumno basándose en su historial de cursos
     *
     * @return array Array de deudas calculadas con estructura similar a DeudaAlumno
     */
    public function calcularDeudasAlumno(Alumno $alumno): array
    {
        $instituto = $alumno->getInstituto();
        $deudas = [];

        // Obtener todos los históricos activos del alumno (precargados en cálculo masivo)
        $historicos = $this->indiceHistoricos !== null
            ? ($this->indiceHistoricos[$alumno->getId()] ?? [])
            : $this->entityManager->getRepository(AlumnoCursoHistorico::class)->findBy([
                'alumno' => $alumno,
                'activo' => true
            ]);

        foreach ($historicos as $historico) {
            $deudasHistorico = $this->calcularDeudasParaHistorico($historico);
            $deudas = array_merge($deudas, $deudasHistorico);
        }
        
        // Ordenar por año y mes
        usort($deudas, function($a, $b) {
            if ($a['ano'] !== $b['ano']) {
                return $a['ano'] - $b['ano'];
            }
            return $a['mes'] - $b['mes'];
        });
        
        return $deudas;
    }

    /**
     * Calcula las deudas para un historial específico
     */
    public function calcularDeudasParaHistorico(AlumnoCursoHistorico $historico): array
    {
        $deudas = [];
        $alumno = $historico->getAlumno();
        $curso = $historico->getCurso();
        $instituto = $alumno->getInstituto();
        
        // Usar fechas del snapshot si están disponibles, sino del curso
        $fechaInicio = $historico->getFechaInicioPeriodo();
        $fechaFin = $historico->getFechaFinPeriodo();
        $precioMensual = $historico->getPrecioMensual() ?? $curso->getPrecio();
        
        if (!$fechaInicio) {
            return [];
        }
        
        // Fecha de alta del alumno en el curso
        $fechaAlta = $historico->getFechaAlta() ?: $this->institutoTimezoneService->getCurrentDateForInstituto($instituto);
        
        // Determinar fecha de inicio de deuda según el modo configurado
        $modoGeneracion = $historico->getModoGeneracionDeuda();
        
        switch ($modoGeneracion) {
            case 'inicio_curso':
                // Deuda desde el inicio del curso
                $fechaInicioDeuda = $fechaInicio;
                break;
                
            case 'proximo_mes':
                // Deuda desde el mes siguiente a la inscripción
                $fechaInicioDeuda = max($fechaInicio, $fechaAlta);
                $fechaInicioDeuda = clone $fechaInicioDeuda;
                $fechaInicioDeuda->modify('first day of next month');
                break;
                
            case 'inscripcion':
            default:
                // Deuda desde el mes de inscripción (pero no antes del inicio del curso)
                $fechaInicioDeuda = max($fechaInicio, $fechaAlta);
                break;
        }
        
        // Ajustar al primer día del mes
        $fechaIteracion = clone $fechaInicioDeuda;
        $fechaIteracion->modify('first day of this month');
        
        // Fecha actual del instituto
        $fechaActual = $this->institutoTimezoneService->getNowForInstituto($instituto);
        
        // Generar deudas solo hasta el mes actual (no futuras).
        // $fechaActual es DateTimeImmutable: modify() devuelve una instancia nueva y no muta,
        // por eso se construye el último día del mes explícitamente.
        $fechaLimite = self::ultimoDiaDelMes($fechaActual);

        // Si el curso ya finalizó, usar esa fecha como límite
        if ($fechaFin && $fechaFin < $fechaLimite) {
            $fechaLimite = self::ultimoDiaDelMes($fechaFin);
        }
        
        // Iterar mes por mes
        while ($fechaIteracion <= $fechaLimite) {
            $mes = (int)$fechaIteracion->format('n');
            $ano = (int)$fechaIteracion->format('Y');
            
            // Si existe algún pago para este mes/año/curso, la cuota se considera pagada
            $tienePago = $this->existePagoParaMes($alumno, $curso, $mes, $ano);
            
            if (!$tienePago) {
                // Calcular interés basado en vencimientos
                $interes = $this->calcularInteres($instituto, $precioMensual, $mes, $ano, $fechaActual);
                
                $deudas[] = [
                    'id' => null,
                    'alumno' => $alumno,
                    'curso' => $curso,
                    'cursoHistorico' => $historico,
                    'mes' => $mes,
                    'ano' => $ano,
                    'monto' => $precioMensual,
                    'interes' => $interes,
                    'montoPagado' => 0,
                    'fechaCreacion' => $fechaIteracion,
                    'instituto' => $instituto,
                ];
            }
            
            // Avanzar al siguiente mes
            $fechaIteracion->modify('first day of next month');
        }
        
        return $deudas;
    }

    /**
     * Calcula cuánto se ha pagado para un mes/año/curso específico
     */
    /**
     * Verifica si existe algún pago registrado para un mes/año/curso específico
     */
    private function existePagoParaMes(Alumno $alumno, $curso, int $mes, int $ano): bool
    {
        // Si los pagos vienen precargados (cálculo masivo), se resuelve en memoria.
        if ($this->indicePagos !== null) {
            $clave = $alumno->getId() . '_' . $curso->getId() . '_' . $mes . '_' . $ano;

            return isset($this->indicePagos[$clave]);
        }

        $qb = $this->entityManager->createQueryBuilder();

        $qb->select('COUNT(p.id)')
           ->from(\App\Entity\AlumnosPagos::class, 'p')
           ->where('p.alumno = :alumno')
           ->andWhere('p.curso = :curso')
           ->andWhere('p.mes = :mes')
           ->andWhere('p.ano = :ano')
           ->setParameter('alumno', $alumno)
           ->setParameter('curso', $curso)
           ->setParameter('mes', $mes)
           ->setParameter('ano', $ano);
        
        return (int)$qb->getQuery()->getSingleScalarResult() > 0;
    }

    /**
     * Interés que corresponde a la cuota de un mes/año, con los vencimientos del instituto.
     *
     * Es la misma regla que usa el cálculo on-demand para mostrar la deuda, expuesta para
     * que al registrar un pago la fila de deuda_alumno se cree con el interés que
     * realmente corresponde. Devuelve 0 para meses futuros (pago adelantado).
     */
    public function calcularInteresParaMes(Instituto $instituto, float $montoBase, int $mes, int $ano): float
    {
        return $this->calcularInteres(
            $instituto,
            $montoBase,
            $mes,
            $ano,
            $this->institutoTimezoneService->getNowForInstituto($instituto)
        );
    }

    /**
     * Calcula el interés para una deuda basándose en los vencimientos configurados
     */
    private function calcularInteres(Instituto $instituto, float $montoBase, int $mes, int $ano, \DateTimeImmutable $fechaActual): float
    {
        // Obtener vencimientos configurados. Se cachean por instituto: antes se
        // consultaban de nuevo para cada mes de cada histórico de cada alumno, que era
        // el grueso de las queries del dashboard (no cambian durante el request).
        $vencimientos = $this->cacheVencimientos[$instituto->getId()]
            ??= $this->entityManager->getRepository(\App\Entity\Vencimiento::class)
                ->findBy(['instituto' => $instituto], ['diaVencimiento' => 'ASC']);

        if (empty($vencimientos)) {
            return 0;
        }
        
        $diaActual = (int)$fechaActual->format('d');
        $mesActual = (int)$fechaActual->format('n');
        $anoActual = (int)$fechaActual->format('Y');
        
        $primerDiaVencimiento = $vencimientos[0]->getDiaVencimiento();
        
        // Deudas de meses anteriores: aplicar máximo interés
        if ($ano < $anoActual || ($ano == $anoActual && $mes < $mesActual)) {
            $maxInteres = 0;
            foreach ($vencimientos as $vencimiento) {
                if ($vencimiento->getPorcentajeInteres() > $maxInteres) {
                    $maxInteres = $vencimiento->getPorcentajeInteres();
                }
            }
            return $montoBase * ($maxInteres / 100);
        }
        
        // Deuda del mes actual: aplicar interés según día actual
        if ($ano == $anoActual && $mes == $mesActual) {
            if ($diaActual >= $primerDiaVencimiento) {
                $porcentajeInteres = 0;
                foreach ($vencimientos as $vencimiento) {
                    $diaVenc = $vencimiento->getDiaVencimiento();
                    if ($diaActual >= $diaVenc) {
                        $porcentajeInteres = $vencimiento->getPorcentajeInteres();
                    } else {
                        break;
                    }
                }
                return $montoBase * ($porcentajeInteres / 100);
            }
        }
        
        // Deudas futuras: sin interés
        return 0;
    }

    /**
     * Obtiene el total adeudado por un alumno (suma de todas las deudas pendientes)
     */
    public function getTotalAdeudado(Alumno $alumno): float
    {
        $deudas = $this->calcularDeudasAlumno($alumno);
        $total = 0;
        
        foreach ($deudas as $deuda) {
            $total += $deuda['monto'] + $deuda['interes'];
        }
        
        return $total;
    }

    /**
     * Verifica si un alumno tiene deudas pendientes
     */
    public function tieneDeudas(Alumno $alumno): bool
    {
        $deudas = $this->calcularDeudasAlumno($alumno);
        return count($deudas) > 0;
    }

    /**
     * @deprecated No usar: el sistema ahora es 100% on-demand. Las deudas en tabla
     * se crean solo al momento de registrar un pago (ver PagoService).
     */
    public function sincronizarDeudasConTabla(Alumno $alumno): array
    {
        return [];
    }


    /**
     * Obtiene estadísticas de deudas para el dashboard.
     *
     * Devuelve además 'deudasPorAlumno' (id de alumno => deudas) para que quien
     * necesite el detalle no vuelva a calcular lo mismo. Los parámetros
     * $fechaInicio/$fechaFin que recibía antes se eliminaron: nunca se usaron dentro
     * del método y ningún llamador los pasaba, así que solo prometían un filtro
     * inexistente.
     *
     * @param Alumno[]|null $alumnos Alumnos ya cargados, para no volver a consultarlos.
     */
    public function getEstadisticasDeudas(Instituto $instituto, ?array $alumnos = null): array
    {
        // Obtener todos los alumnos activos del instituto
        if ($alumnos === null) {
            $alumnos = $this->entityManager->getRepository(Alumno::class)->findBy([
                'instituto' => $instituto,
                'activo' => true
            ]);
        }

        $totalDeudores = 0;
        $montoTotalAdeudado = 0;
        $montoAdeudadoMensual = 0;

        $fechaActual = $this->institutoTimezoneService->getNowForInstituto($instituto);
        $mesActual = (int)$fechaActual->format('n');
        $anoActual = (int)$fechaActual->format('Y');

        // Una sola query de pagos para todos los alumnos, en lugar de un COUNT por mes.
        $deudasPorAlumno = $this->calcularDeudasParaAlumnos($alumnos);

        foreach ($deudasPorAlumno as $deudas) {
            if (count($deudas) > 0) {
                $totalDeudores++;
                
                foreach ($deudas as $deuda) {
                    $montoDeuda = $deuda['monto'] + $deuda['interes'];
                    $montoTotalAdeudado += $montoDeuda;
                    
                    // Sumar al monto mensual si es del mes actual
                    if ($deuda['mes'] == $mesActual && $deuda['ano'] == $anoActual) {
                        $montoAdeudadoMensual += $montoDeuda;
                    }
                }
            }
        }
        
        return [
            'totalDeudores' => $totalDeudores,
            'montoTotalAdeudado' => $montoTotalAdeudado,
            'montoAdeudadoMensual' => $montoAdeudadoMensual,
            'deudasPorAlumno' => $deudasPorAlumno,
        ];
    }
}
