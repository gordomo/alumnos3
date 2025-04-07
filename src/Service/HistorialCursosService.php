<?php

namespace App\Service;

use App\Entity\Alumno;
use App\Entity\Curso;
use App\Entity\AlumnoCursoHistorico;
use App\Entity\AlumnosPagos;
use Doctrine\ORM\EntityManagerInterface;

class HistorialCursosService
{
    private $entityManager;

    public function __construct(EntityManagerInterface $entityManager)
    {
        $this->entityManager = $entityManager;
    }

    /**
     * Crea un nuevo registro histórico para un alumno en un curso
     */
    public function crearHistorial(Alumno $alumno, Curso $curso): AlumnoCursoHistorico
    {
        $historico = new AlumnoCursoHistorico();
        $historico->setAlumno($alumno);
        $historico->setCurso($curso);
        $historico->setFechaInicio($curso->getFechaInicio());
        $historico->setFechaFin($curso->getFechaFin());
        $historico->setActivo(true);

        $this->entityManager->persist($historico);
        $this->entityManager->flush();

        return $historico;
    }

    /**
     * Registra un pago y actualiza el historial correspondiente
     */
    public function registrarPago(AlumnosPagos $pago): void
    {
        $alumno = $pago->getAlumno();
        $curso = $pago->getCurso();
        $fechaPago = new \DateTime($pago->getAno() . '-' . $pago->getMes() . '-01');

        // Buscar el historial correspondiente
        $historico = $this->buscarHistorial($alumno, $curso, $fechaPago);

        if (!$historico) {
            // Si no existe, crear uno nuevo
            $historico = $this->crearHistorial($alumno, $curso);
            // Asegurarse de que el historial esté persistido
            $this->entityManager->persist($historico);
            $this->entityManager->flush();
        }

        // Asociar el pago con el historial
        $pago->setCursoHistorico($historico);
        $this->entityManager->persist($pago);
        $this->entityManager->flush();
    }

    /**
     * Busca el historial correspondiente a un mes y año específicos
     */
    public function buscarHistorial(Alumno $alumno, Curso $curso, \DateTime $fechaPago): ?AlumnoCursoHistorico
    {
        // Primero buscar en cursos actuales
        if ($alumno->getCurso()->contains($curso)) {
            // Si el curso es actual, buscar o crear un historial
            foreach ($alumno->getCursosHistoricos() as $historico) {
                if ($historico->getCurso() === $curso && 
                    $historico->getFechaInicio() <= $fechaPago && 
                    (!$historico->getFechaFin() || $historico->getFechaFin() >= $fechaPago) &&
                    $historico->isActivo()) {
                    return $historico;
                }
            }
        }

        // Si no se encuentra en cursos actuales, buscar en históricos
        foreach ($alumno->getCursosHistoricos() as $historico) {
            if ($historico->getCurso() === $curso && 
                $historico->getFechaInicio() <= $fechaPago && 
                (!$historico->getFechaFin() || $historico->getFechaFin() >= $fechaPago) &&
                $historico->isActivo()) {
                return $historico;
            }
        }

        return null;
    }

    /**
     * Verifica los meses adeudados para un alumno
     */
    public function verificarMesesAdeudados(Alumno $alumno): array
    {
        $mesesAdeudados = [];
        $nombresMeses = [
            1 => 'Enero', 2 => 'Febrero', 3 => 'Marzo', 4 => 'Abril',
            5 => 'Mayo', 6 => 'Junio', 7 => 'Julio', 8 => 'Agosto',
            9 => 'Septiembre', 10 => 'Octubre', 11 => 'Noviembre', 12 => 'Diciembre'
        ];

        // Obtener fecha actual
        $fechaActual = new \DateTime();
        $mesActual = (int)$fechaActual->format('n');
        $anoActual = (int)$fechaActual->format('Y');
        $diaActual = (int)$fechaActual->format('d');

        // Obtener el último día de vencimiento del mes actual
        $ultimoVencimiento = null;
        foreach ($alumno->getInstituto()->getVencimientos() as $vencimiento) {
            if ($vencimiento->getDiaVencimiento() > $diaActual) {
                $ultimoVencimiento = $vencimiento;
            }
        }

        // Si hay un vencimiento posterior al día actual, considerar el mes actual como adeudado
        $incluirMesActual = $ultimoVencimiento !== null;

        // Obtener todos los pagos del alumno
        $pagos = $this->entityManager->getRepository(AlumnosPagos::class)->findBy(['alumno' => $alumno]);
        $mesesPagados = [];
        foreach ($pagos as $pago) {
            $mesesPagados[] = $pago->getAno() . '-' . $pago->getMes();
        }

        // Verificar todos los cursos históricos activos
        foreach ($alumno->getCursosHistoricos() as $historico) {
            if (!$historico->isActivo()) {
                continue;
            }

            $curso = $historico->getCurso();
            $fechaInicio = $historico->getFechaInicio();
            $fechaFin = $historico->getFechaFin();
            
            // Generar todos los meses entre fecha inicio y fin
            $fechaActual = clone $fechaInicio;
            // Si hay fecha fin, usar el último día del mes
            $fechaFinLimite = $fechaFin ? new \DateTime($fechaFin->format('Y-m-t')) : new \DateTime();
            
            while ($fechaActual <= $fechaFinLimite) {
                $mesKey = $fechaActual->format('Y-n');
                $mes = (int)$fechaActual->format('n');
                $ano = (int)$fechaActual->format('Y');
                
                // Verificar si el mes debe incluirse
                $debeIncluirse = false;
                if ($ano < $anoActual) {
                    $debeIncluirse = true;
                } elseif ($ano == $anoActual) {
                    if ($mes < $mesActual) {
                        $debeIncluirse = true;
                    } elseif ($mes == $mesActual && $incluirMesActual) {
                        $debeIncluirse = true;
                    }
                }

                if ($debeIncluirse && !in_array($mesKey, $mesesPagados)) {
                    $mesesAdeudados[] = [
                        'mes' => $mes,
                        'ano' => $ano,
                        'nombre' => $nombresMeses[$mes] . ' ' . $ano,
                        'curso' => $curso->getNombre(),
                        'curso_obj' => $curso
                    ];
                }

                $fechaActual->modify('+1 month');
            }
        }

        return $mesesAdeudados;
    }

    /**
     * Verifica los meses adeudados para un curso específico
     */
    public function verificarMesesAdeudadosPorCurso(Alumno $alumno, Curso $curso): array
    {
        $mesesAdeudados = [];
        $nombresMeses = [
            1 => 'Enero', 2 => 'Febrero', 3 => 'Marzo', 4 => 'Abril',
            5 => 'Mayo', 6 => 'Junio', 7 => 'Julio', 8 => 'Agosto',
            9 => 'Septiembre', 10 => 'Octubre', 11 => 'Noviembre', 12 => 'Diciembre'
        ];

        // Obtener fecha actual
        $fechaActual = new \DateTime();
        $mesActual = (int)$fechaActual->format('n');
        $anoActual = (int)$fechaActual->format('Y');
        $diaActual = (int)$fechaActual->format('d');

        // Obtener el último día de vencimiento del mes actual
        $ultimoVencimiento = null;
        $vencimientos = $alumno->getInstituto()->getVencimientos();
        $vencimientosOrdenados = $vencimientos->toArray();
        
        // Ordenar vencimientos por día (ascendente)
        usort($vencimientosOrdenados, function($a, $b) {
            return $a->getDiaVencimiento() <=> $b->getDiaVencimiento();
        });

        // Si hay vencimientos, determinar el último
        if (!empty($vencimientosOrdenados)) {
            $ultimoVencimiento = $vencimientosOrdenados[count($vencimientosOrdenados) - 1];
        }

        // Si hay un vencimiento posterior al día actual, considerar el mes actual como adeudado
        $incluirMesActual = $ultimoVencimiento !== null && $diaActual > $ultimoVencimiento->getDiaVencimiento();

        // Obtener todos los pagos del alumno para este curso
        $pagos = $this->entityManager->getRepository(AlumnosPagos::class)->findBy([
            'alumno' => $alumno,
            'curso' => $curso
        ]);
        $mesesPagados = [];
        foreach ($pagos as $pago) {
            $mesesPagados[] = $pago->getAno() . '-' . $pago->getMes();
        }

        // Buscar el historial activo para este curso
        foreach ($alumno->getCursosHistoricos() as $historico) {
            if ($historico->getCurso() === $curso && $historico->isActivo()) {
                $fechaInicio = $historico->getFechaInicio();
                $fechaFin = $historico->getFechaFin();
                
                // Generar todos los meses entre fecha inicio y fin
                $fechaIteracion = clone $fechaInicio;
                // Si hay fecha fin, usar el último día del mes
                $fechaFinLimite = $fechaFin ? new \DateTime($fechaFin->format('Y-m-t')) : new \DateTime();
                
                while ($fechaIteracion <= $fechaFinLimite) {
                    $mesKey = $fechaIteracion->format('Y-n');
                    $mes = (int)$fechaIteracion->format('n');
                    $ano = (int)$fechaIteracion->format('Y');
                    
                    // Verificar si el mes debe incluirse
                    $debeIncluirse = false;
                    $razonIncluido = "";
                    
                    if ($ano < $anoActual) {
                        $debeIncluirse = true;
                        $razonIncluido = "Mes de año anterior";
                    } elseif ($ano == $anoActual) {
                        if ($mes < $mesActual) {
                            $debeIncluirse = true;
                            $razonIncluido = "Mes anterior al actual";
                        } elseif ($mes == $mesActual && $incluirMesActual) {
                            $debeIncluirse = true;
                            $razonIncluido = "Mes actual con vencimiento pasado";
                        }
                    }

                    if ($debeIncluirse && !in_array($mesKey, $mesesPagados)) {
                        $mesesAdeudados[] = [
                            'mes' => $mes,
                            'ano' => $ano,
                            'nombre' => $nombresMeses[$mes] . ' ' . $ano,
                            'curso_id' => $curso->getId(),
                            'curso_nombre' => $curso->getNombre(),
                            'curso_obj' => $curso,
                            'razon' => $razonIncluido,
                            'antiguedad' => $this->calcularAntiguedadDeuda($mes, $ano, $mesActual, $anoActual)
                        ];
                    }

                    $fechaIteracion->modify('+1 month');
                }
                break; // Solo procesamos el primer historial activo que encontremos
            }
        }

        return $mesesAdeudados;
    }

    /**
     * Calcula la antigüedad de una deuda en meses
     */
    private function calcularAntiguedadDeuda($mesDeuda, $anoDeuda, $mesActual, $anoActual): int
    {
        $mesesDiferencia = ($anoActual - $anoDeuda) * 12 + ($mesActual - $mesDeuda);
        return max(0, $mesesDiferencia);
    }
} 