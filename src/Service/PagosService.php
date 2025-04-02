<?php

namespace App\Service;

use App\Entity\Alumno;
use App\Entity\AlumnoCursoHistorico;
use App\Entity\AlumnosPagos;
use App\Entity\Curso;
use DateTime;
use Doctrine\ORM\EntityManagerInterface;

class PagosService
{
    private $entityManager;

    public function __construct(EntityManagerInterface $entityManager)
    {
        $this->entityManager = $entityManager;
    }

    /**
     * Verifica si un alumno debe el mes actual
     */
    public function debeMes(Alumno $alumno, int $mes, int $ano, int $dia): bool
    {
        // Si el alumno no tiene cursos, no debe
        if ($alumno->getCurso()->isEmpty()) {
            return false;
        }

        // Obtener el historial de cursos activo para el mes actual
        $historicoActivo = $this->getHistoricoActivo($alumno, $mes, $ano);

        // Si no hay historial activo para el mes actual, debe
        if (!$historicoActivo) {
            return true;
        }

        // Verificar si el mes está en los meses pagados
        $mesActual = "$ano-$mes";
        return !in_array($mesActual, $historicoActivo->getMesesPagados());
    }

    /**
     * Verifica si un alumno debe el mes actual (método estático para usar desde las vistas)
     */
    public static function debeMesStatic(Alumno $alumno, int $mes, int $ano, int $dia): bool
    {
        // Si el alumno no tiene cursos, no debe
        if ($alumno->getCurso()->isEmpty()) {
            return false;
        }

        // Obtener el historial de cursos activo para el mes actual
        $historicoActivo = null;
        foreach ($alumno->getCursosHistoricos() as $historico) {
            $fechaInicio = $historico->getFechaInicio();
            $fechaFin = $historico->getFechaFin();
            
            // Verificar si el mes actual está dentro del período del curso
            if ($fechaInicio->format('Y-m') <= "$ano-$mes" && 
                ($fechaFin === null || $fechaFin->format('Y-m') >= "$ano-$mes")) {
                $historicoActivo = $historico;
                break;
            }
        }

        // Si no hay historial activo para el mes actual, debe
        if (!$historicoActivo) {
            return true;
        }

        // Verificar si el mes está en los meses pagados
        $mesActual = "$ano-$mes";
        return !in_array($mesActual, $historicoActivo->getMesesPagados());
    }

    /**
     * Verifica los meses adeudados de un alumno
     */
    public function verificarMesesAdeudados(Alumno $alumno): array
    {
        $mesesAdeudados = [];
        $fechaActual = new DateTime();
        $mesActual = (int)$fechaActual->format('m');
        $anoActual = (int)$fechaActual->format('Y');

        // Obtener el historial activo
        $historicoActivo = $this->getHistoricoActivo($alumno, $mesActual, $anoActual);

        if (!$historicoActivo) {
            return [];
        }

        // Obtener el día de vencimiento del instituto
        $vencimientos = $alumno->getInstituto()->getVencimientos()->toArray();
        if (empty($vencimientos)) {
            return [];
        }

        // Ordenar vencimientos por orden
        usort($vencimientos, function($a, $b) {
            return $a->getOrden() - $b->getOrden();
        });

        $diaVencimiento = $vencimientos[0]->getDiaVencimiento();

        // Obtener el primer mes del curso
        $fechaInicio = $historicoActivo->getFechaInicio();
        $mesInicio = (int)$fechaInicio->format('m');
        $anoInicio = (int)$fechaInicio->format('Y');

        // Obtener el último mes del curso
        $fechaFin = $historicoActivo->getFechaFin();
        $mesFin = $fechaFin ? (int)$fechaFin->format('m') : $mesActual;
        $anoFin = $fechaFin ? (int)$fechaFin->format('Y') : $anoActual;

        // Generar todos los meses posibles
        $mesesPosibles = [];
        $fecha = new DateTime("$anoInicio-$mesInicio-01");
        $fechaFin = new DateTime("$anoFin-$mesFin-01");
        
        while ($fecha <= $fechaFin) {
            $mesesPosibles[] = $fecha->format('Y-m');
            $fecha->modify('+1 month');
        }

        // Filtrar los meses que no están pagados
        foreach ($mesesPosibles as $mes) {
            if (!in_array($mes, $historicoActivo->getMesesPagados())) {
                $mesesAdeudados[] = $mes;
            }
        }

        return $mesesAdeudados;
    }

    /**
     * Verifica los meses adeudados para un alumno y curso específico
     */
    public function verificarMesesAdeudadosPorCurso(Alumno $alumno, Curso $curso, int $ano): array
    {
        $fechaInicio = $curso->getFechaInicio();
        $fechaFin = $curso->getFechaFin();
        
        if (!$fechaInicio || !$fechaFin) {
            return ['error' => 'El curso no tiene fechas de inicio y fin configuradas'];
        }

        // Obtener todos los pagos del alumno para este curso
        $pagos = $this->entityManager->getRepository(AlumnosPagos::class)
            ->createQueryBuilder('p')
            ->andWhere('p.alumno = :alumno')
            ->andWhere('p.curso = :curso')
            ->setParameter('alumno', $alumno)
            ->setParameter('curso', $curso)
            ->getQuery()
            ->getResult();

        // Crear un array con los meses pagados (incluyendo el año)
        $mesesPagados = array_map(function($pago) {
            return $pago->getAno() . '-' . $pago->getMes();
        }, $pagos);

        // Obtener los meses que deberían estar pagados
        $mesesAdeudados = [];
        $mesInicio = (int)$fechaInicio->format('n');
        $mesFin = (int)$fechaFin->format('n');
        $anoInicio = (int)$fechaInicio->format('Y');
        $anoFin = (int)$fechaFin->format('Y');

        // Generar todos los meses posibles desde el inicio hasta el fin del curso
        $fecha = new DateTime("$anoInicio-$mesInicio-01");
        $fechaFin = new DateTime("$anoFin-$mesFin-01");
        
        while ($fecha <= $fechaFin) {
            $mesActual = $fecha->format('Y-n');
            if (!in_array($mesActual, $mesesPagados)) {
                $mesesAdeudados[] = [
                    'mes' => (int)$fecha->format('n'),
                    'ano' => (int)$fecha->format('Y')
                ];
            }
            $fecha->modify('+1 month');
        }

        return [
            'mesesAdeudados' => $mesesAdeudados,
            'mesInicio' => $mesInicio,
            'mesFin' => $mesFin,
            'anoInicio' => $anoInicio,
            'anoFin' => $anoFin
        ];
    }

    /**
     * Obtiene el historial de curso activo para un mes y año específicos
     */
    private function getHistoricoActivo(Alumno $alumno, int $mes, int $ano): ?AlumnoCursoHistorico
    {
        foreach ($alumno->getCursosHistoricos() as $historico) {
            $fechaInicio = $historico->getFechaInicio();
            $fechaFin = $historico->getFechaFin();
            
            // Verificar si el mes actual está dentro del período del curso
            if ($fechaInicio->format('Y-m') <= "$ano-$mes" && 
                ($fechaFin === null || $fechaFin->format('Y-m') >= "$ano-$mes")) {
                return $historico;
            }
        }

        return null;
    }
} 