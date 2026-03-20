<?php

namespace App\Service;

use App\Entity\Alumno;
use App\Entity\AlumnoCursoHistorico;
use App\Entity\AlumnosPagos;
use App\Entity\Instituto;
use App\Entity\PagoAplicacion;
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
     * Calcula todas las deudas de un alumno basándose en su historial de cursos
     * 
     * @return array Array de deudas calculadas con estructura similar a DeudaAlumno
     */
    public function calcularDeudasAlumno(Alumno $alumno): array
    {
        $instituto = $alumno->getInstituto();
        $deudas = [];
        
        // Obtener todos los históricos activos del alumno
        $historicos = $this->entityManager->getRepository(AlumnoCursoHistorico::class)->findBy([
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
        
        // Generar deudas solo hasta el mes actual (no futuras)
        $fechaLimite = clone $fechaActual;
        $fechaLimite->modify('last day of this month');
        
        // Si el curso ya finalizó, usar esa fecha como límite
        if ($fechaFin && $fechaFin < $fechaLimite) {
            $fechaLimite = clone $fechaFin;
            $fechaLimite->modify('last day of this month');
        }
        
        // Iterar mes por mes
        while ($fechaIteracion <= $fechaLimite) {
            $mes = (int)$fechaIteracion->format('n');
            $ano = (int)$fechaIteracion->format('Y');
            
            // Calcular cuánto se ha pagado para este mes/año/curso
            $montoPagado = $this->calcularMontoPagadoParaMes($alumno, $curso, $mes, $ano);
            
            // Solo crear deuda si hay saldo pendiente
            $saldoPendiente = $precioMensual - $montoPagado;
            
            if ($saldoPendiente > 0.01) { // Tolerancia para errores de redondeo
                // Calcular interés basado en vencimientos
                $interes = $this->calcularInteres($instituto, $precioMensual, $mes, $ano, $fechaActual);
                
                $deudas[] = [
                    'id' => null, // No hay ID porque es calculado on-demand
                    'alumno' => $alumno,
                    'curso' => $curso,
                    'cursoHistorico' => $historico,
                    'mes' => $mes,
                    'ano' => $ano,
                    'monto' => $saldoPendiente,
                    'interes' => $interes,
                    'montoPagado' => $montoPagado,
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
    private function calcularMontoPagadoParaMes(Alumno $alumno, $curso, int $mes, int $ano): float
    {
        $qb = $this->entityManager->createQueryBuilder();
        
        // Buscar todas las aplicaciones de pago para este alumno/curso/mes/año
        // PagoAplicacion -> DeudaAlumno -> Curso
        $qb->select('COALESCE(SUM(pa.montoAplicado), 0)')
           ->from(PagoAplicacion::class, 'pa')
           ->join('pa.pago', 'p')
           ->join('pa.deuda', 'd')
           ->where('p.alumno = :alumno')
           ->andWhere('d.curso = :curso')
           ->andWhere('d.mes = :mes')
           ->andWhere('d.ano = :ano')
           ->setParameter('alumno', $alumno)
           ->setParameter('curso', $curso)
           ->setParameter('mes', $mes)
           ->setParameter('ano', $ano);
        
        return (float)$qb->getQuery()->getSingleScalarResult();
    }

    /**
     * Calcula el interés para una deuda basándose en los vencimientos configurados
     */
    private function calcularInteres(Instituto $instituto, float $montoBase, int $mes, int $ano, \DateTimeImmutable $fechaActual): float
    {
        // Obtener vencimientos configurados
        $vencimientos = $this->entityManager->getRepository('App\Entity\Vencimiento')
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
     * Sincroniza las deudas calculadas con la tabla deuda_alumno
     * Crea o actualiza registros en la tabla para que el sistema de pagos funcione
     * 
     * @return array Array de entidades DeudaAlumno sincronizadas
     */
    public function sincronizarDeudasConTabla(Alumno $alumno): array
    {
        $deudasCalculadas = $this->calcularDeudasAlumno($alumno);
        $deudasEntidades = [];
        
        foreach ($deudasCalculadas as $deudaCalc) {
            // Buscar si ya existe una deuda en la tabla para este mes/año/curso
            $deudaExistente = $this->entityManager->getRepository(\App\Entity\DeudaAlumno::class)
                ->findOneBy([
                    'alumno' => $alumno,
                    'curso' => $deudaCalc['curso'],
                    'mes' => $deudaCalc['mes'],
                    'ano' => $deudaCalc['ano']
                ]);
            
            if ($deudaExistente) {
                // Actualizar monto e interés si cambió
                if ($deudaExistente->getMonto() != $deudaCalc['monto']) {
                    $deudaExistente->setMonto($deudaCalc['monto']);
                }
                if ($deudaExistente->getInteres() != $deudaCalc['interes']) {
                    $deudaExistente->setInteres($deudaCalc['interes']);
                }
                $deudasEntidades[] = $deudaExistente;
            } else {
                // Crear nueva deuda en la tabla
                $nuevaDeuda = new \App\Entity\DeudaAlumno();
                $nuevaDeuda->setAlumno($alumno);
                $nuevaDeuda->setCurso($deudaCalc['curso']);
                $nuevaDeuda->setCursoHistorico($deudaCalc['cursoHistorico']);
                $nuevaDeuda->setMes($deudaCalc['mes']);
                $nuevaDeuda->setAno($deudaCalc['ano']);
                $nuevaDeuda->setMonto($deudaCalc['monto']);
                $nuevaDeuda->setInteres($deudaCalc['interes']);
                $nuevaDeuda->setInstituto($deudaCalc['instituto']);
                
                $this->entityManager->persist($nuevaDeuda);
                $deudasEntidades[] = $nuevaDeuda;
            }
        }
        
        $this->entityManager->flush();
        
        return $deudasEntidades;
    }


    /**
     * Obtiene estadísticas de deudas para el dashboard
     */
    public function getEstadisticasDeudas(Instituto $instituto, ?\DateTime $fechaInicio = null, ?\DateTime $fechaFin = null): array
    {
        // Obtener todos los alumnos activos del instituto
        $alumnos = $this->entityManager->getRepository(Alumno::class)->findBy([
            'instituto' => $instituto,
            'activo' => true
        ]);
        
        $totalDeudores = 0;
        $montoTotalAdeudado = 0;
        $montoAdeudadoMensual = 0;
        
        $fechaActual = $this->institutoTimezoneService->getNowForInstituto($instituto);
        $mesActual = (int)$fechaActual->format('n');
        $anoActual = (int)$fechaActual->format('Y');
        
        foreach ($alumnos as $alumno) {
            $deudas = $this->calcularDeudasAlumno($alumno);
            
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
        ];
    }
}
