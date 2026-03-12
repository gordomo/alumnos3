<?php

namespace App\Service;

use App\Entity\Profesor;
use App\Entity\Curso;
use App\Entity\Instituto;
use App\Repository\AlumnoRepository;
use App\Repository\AsistenciaProfesoresRepository;
use Doctrine\ORM\EntityManagerInterface;

class ProfesorPagoService
{
    private $entityManager;
    private $alumnoRepository;
    private $asistenciaProfesoresRepository;

    public function __construct(
        EntityManagerInterface $entityManager,
        AlumnoRepository $alumnoRepository,
        AsistenciaProfesoresRepository $asistenciaProfesoresRepository
    ) {
        $this->entityManager = $entityManager;
        $this->alumnoRepository = $alumnoRepository;
        $this->asistenciaProfesoresRepository = $asistenciaProfesoresRepository;
    }

    /**
     * Calcula el monto a pagar a un profesor por un mes específico
     * 
     * @return array ['monto' => float, 'detalle' => array]
     */
    public function calcularPagoMensual(Profesor $profesor, int $mes, int $ano, ?Curso $cursoFiltro = null): array
    {
        $tipoPago = $profesor->getTipoPago();
        $detalle = [
            'tipo_pago' => $tipoPago,
            'mes' => $mes,
            'ano' => $ano,
            'cursos' => []
        ];

        $montoTotal = 0;

        switch ($tipoPago) {
            case 'por_hora':
                $resultado = $this->calcularPorHora($profesor, $mes, $ano, $cursoFiltro);
                $montoTotal = $resultado['monto'];
                $detalle['cursos'] = $resultado['detalle'];
                break;

            case 'fijo_mensual':
                $montoTotal = $profesor->getMontoFijoMensual() ?? 0;
                $detalle['monto_fijo'] = $montoTotal;
                break;

            case 'porcentaje':
                $resultado = $this->calcularPorPorcentaje($profesor, $mes, $ano, $cursoFiltro);
                $montoTotal = $resultado['monto'];
                $detalle['cursos'] = $resultado['detalle'];
                break;

            case 'combinado':
                // Fijo mensual + porcentaje
                $montoFijo = $profesor->getMontoFijoMensual() ?? 0;
                $resultado = $this->calcularPorPorcentaje($profesor, $mes, $ano, $cursoFiltro);
                $montoPorcentaje = $resultado['monto'];
                
                $montoTotal = $montoFijo + $montoPorcentaje;
                $detalle['monto_fijo'] = $montoFijo;
                $detalle['monto_porcentaje'] = $montoPorcentaje;
                $detalle['cursos'] = $resultado['detalle'];
                break;

            default:
                // Por defecto, usar por hora
                $resultado = $this->calcularPorHora($profesor, $mes, $ano, $cursoFiltro);
                $montoTotal = $resultado['monto'];
                $detalle['cursos'] = $resultado['detalle'];
        }

        return [
            'monto' => $montoTotal,
            'detalle' => $detalle
        ];
    }

    /**
     * Calcula el pago por hora basado en asistencias
     */
    private function calcularPorHora(Profesor $profesor, int $mes, int $ano, ?Curso $cursoFiltro = null): array
    {
        $precioHora = (float)$profesor->getPrecioHora();
        $viatico = (float)$profesor->getViatico();
        
        $cursos = $cursoFiltro ? [$cursoFiltro] : $profesor->getCursos()->toArray();
        
        $montoTotal = 0;
        $detalleCursos = [];

        foreach ($cursos as $curso) {
            // Crear rango de fechas para el mes/año
            $inicioMes = new \DateTime("$ano-$mes-01");
            $finMes = clone $inicioMes;
            $finMes->modify('last day of this month')->setTime(23, 59, 59);
            
            // Obtener asistencias del profesor en este curso para el mes/año
            $asistencias = $this->asistenciaProfesoresRepository->createQueryBuilder('ap')
                ->andWhere('ap.profesor = :profesor')
                ->andWhere('ap.curso = :curso')
                ->andWhere('ap.fecha >= :inicioMes')
                ->andWhere('ap.fecha <= :finMes')
                ->andWhere('ap.presente = true')
                ->setParameter('profesor', $profesor)
                ->setParameter('curso', $curso)
                ->setParameter('inicioMes', $inicioMes)
                ->setParameter('finMes', $finMes)
                ->getQuery()
                ->getResult();

            $horasTrabajadas = 0;
            foreach ($asistencias as $asistencia) {
                $horasTrabajadas += $asistencia->getHorasTrabajadas() ?? 0;
            }

            $montoCurso = ($horasTrabajadas * $precioHora) + $viatico;
            $montoTotal += $montoCurso;

            $detalleCursos[] = [
                'curso' => $curso->getNombre(),
                'horas_trabajadas' => $horasTrabajadas,
                'precio_hora' => $precioHora,
                'viatico' => $viatico,
                'monto' => $montoCurso
            ];
        }

        return [
            'monto' => $montoTotal,
            'detalle' => $detalleCursos
        ];
    }

    /**
     * Calcula el pago por porcentaje sobre el total del curso
     */
    private function calcularPorPorcentaje(Profesor $profesor, int $mes, int $ano, ?Curso $cursoFiltro = null): array
    {
        $porcentaje = $profesor->getPorcentajeCurso() ?? 0;
        
        $cursos = $cursoFiltro ? [$cursoFiltro] : $profesor->getCursos()->toArray();
        
        $montoTotal = 0;
        $detalleCursos = [];

        foreach ($cursos as $curso) {
            // Contar alumnos activos en el curso para el mes/año
            $alumnosActivos = $this->alumnoRepository->createQueryBuilder('a')
                ->innerJoin('a.cursos', 'c')
                ->andWhere('c.id = :curso')
                ->andWhere('a.instituto = :instituto')
                ->setParameter('curso', $curso->getId())
                ->setParameter('instituto', $profesor->getInstituto())
                ->getQuery()
                ->getResult();

            // Filtrar alumnos que estaban activos en el mes/año específico
            $alumnosActivosEnMes = [];
            foreach ($alumnosActivos as $alumno) {
                // Verificar si el alumno tenía el curso activo en ese mes/año
                $historial = $alumno->getHistorialCursos();
                foreach ($historial as $hc) {
                    if ($hc->getCurso()->getId() === $curso->getId()) {
                        $fechaInicio = $hc->getFechaInicio();
                        $fechaFin = $hc->getFechaFin();
                        
                        // Crear fecha del mes/año a verificar
                        $fechaMes = new \DateTime("$ano-$mes-01");
                        
                        if ($fechaInicio <= $fechaMes && (!$fechaFin || $fechaFin >= $fechaMes)) {
                            $alumnosActivosEnMes[] = $alumno;
                            break;
                        }
                    }
                }
            }

            $cantidadAlumnos = count($alumnosActivosEnMes);
            $precioCurso = (float)$curso->getPrecio();
            $totalCurso = $cantidadAlumnos * $precioCurso;
            $montoCurso = ($totalCurso * $porcentaje) / 100;
            
            $montoTotal += $montoCurso;

            $detalleCursos[] = [
                'curso' => $curso->getNombre(),
                'cantidad_alumnos' => $cantidadAlumnos,
                'precio_curso' => $precioCurso,
                'total_curso' => $totalCurso,
                'porcentaje' => $porcentaje,
                'monto' => $montoCurso
            ];
        }

        return [
            'monto' => $montoTotal,
            'detalle' => $detalleCursos
        ];
    }

    /**
     * Obtiene un resumen de liquidación para un profesor en un mes/año
     */
    public function obtenerLiquidacion(Profesor $profesor, int $mes, int $ano): array
    {
        $calculo = $this->calcularPagoMensual($profesor, $mes, $ano);
        
        // Obtener pagos ya realizados
        $pagosRealizados = $this->entityManager->getRepository(\App\Entity\ProfesorPago::class)
            ->findByProfesorMesAno($profesor, $mes, $ano);
        
        $totalPagado = 0;
        foreach ($pagosRealizados as $pago) {
            $totalPagado += $pago->getMonto();
        }
        
        $saldoPendiente = $calculo['monto'] - $totalPagado;
        
        return [
            'monto_calculado' => $calculo['monto'],
            'detalle_calculo' => $calculo['detalle'],
            'total_pagado' => $totalPagado,
            'pagos_realizados' => $pagosRealizados,
            'saldo_pendiente' => $saldoPendiente
        ];
    }
}
