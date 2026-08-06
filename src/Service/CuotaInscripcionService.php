<?php

namespace App\Service;

use App\Entity\Alumno;
use App\Entity\DeudaAlumno;
use App\Entity\Instituto;
use App\Entity\InstitutoConfiguracion;
use Doctrine\ORM\EntityManagerInterface;

class CuotaInscripcionService
{
    private EntityManagerInterface $entityManager;
    private InstitutoTimezoneService $institutoTimezoneService;

    public function __construct(
        EntityManagerInterface $entityManager,
        InstitutoTimezoneService $institutoTimezoneService
    ) {
        $this->entityManager = $entityManager;
        $this->institutoTimezoneService = $institutoTimezoneService;
    }

    /**
     * Genera la cuota de inscripción anual para un alumno si está configurada
     * 
     * @param Alumno $alumno El alumno para el que se generará la cuota
     * @param int|null $anoEspecifico Año específico para generar la cuota (si es null, usa el año actual)
     * @return DeudaAlumno|null La deuda creada o null si no se creó
     */
    public function generarCuotaInscripcionParaAlumno(Alumno $alumno, ?int $anoEspecifico = null): ?DeudaAlumno
    {
        $instituto = $alumno->getInstituto();
        $configuracion = $instituto->getConfiguracion();

        // Verificar si está habilitada la cuota de inscripción
        if (!$configuracion || !$configuracion->getCobrarCuotaInscripcionAnual()) {
            return null;
        }

        // Verificar que haya un monto configurado
        $monto = $configuracion->getMontoCuotaInscripcionAnual();
        if ($monto === null || $monto <= 0) {
            return null;
        }

        // Determinar el año y mes para la cuota
        $fechaActual = $this->institutoTimezoneService->getNowForInstituto($instituto);
        $ano = $anoEspecifico ?? (int) $fechaActual->format('Y');
        $mes = $configuracion->getMesCobroCuotaInscripcionAnual() ?? 1; // Por defecto enero

        // Verificar si ya existe una cuota de inscripción para este año
        $deudaExistente = $this->entityManager->getRepository(DeudaAlumno::class)
            ->findOneBy([
                'alumno' => $alumno,
                'ano' => $ano,
                'esCuotaInscripcionAnual' => true
            ]);

        if ($deudaExistente) {
            return $deudaExistente;
        }

        // Crear la nueva deuda de inscripción anual
        $deuda = new DeudaAlumno();
        $deuda->setAlumno($alumno);
        $deuda->setCurso(null); // No está asociada a un curso específico
        $deuda->setCursoHistorico(null);
        $deuda->setMes($mes);
        $deuda->setAno($ano);
        $deuda->setMonto($monto);
        $deuda->setInteres(0);
        $deuda->setInstituto($instituto);
        $deuda->setEsCuotaInscripcionAnual(true);

        $this->entityManager->persist($deuda);
        $this->entityManager->flush();

        return $deuda;
    }

    /**
     * Genera las cuotas de inscripción anual para todos los alumnos activos de un instituto
     * 
     * @param Instituto $instituto El instituto
     * @param int|null $anoEspecifico Año específico (si es null, usa el año actual)
     * @return array Estadísticas de generación
     */
    public function generarCuotasInscripcionParaInstituto(Instituto $instituto, ?int $anoEspecifico = null): array
    {
        $configuracion = $instituto->getConfiguracion();

        // Verificar si está habilitada la cuota de inscripción
        if (!$configuracion || !$configuracion->getCobrarCuotaInscripcionAnual()) {
            return [
                'generadas' => 0,
                'omitidas' => 0,
                'error' => 'Cuota de inscripción anual no habilitada'
            ];
        }

        $fechaActual = $this->institutoTimezoneService->getNowForInstituto($instituto);
        $ano = $anoEspecifico ?? (int) $fechaActual->format('Y');
        $mesConfiguracion = $configuracion->getMesCobroCuotaInscripcionAnual() ?? 1;
        $mesActual = (int) $fechaActual->format('n');

        // Solo generar si estamos en el mes de cobro o después
        if ($anoEspecifico === null && $mesActual < $mesConfiguracion) {
            return [
                'generadas' => 0,
                'omitidas' => 0,
                'error' => 'Aún no es el mes de cobro configurado'
            ];
        }

        $alumnosActivos = $this->entityManager->getRepository(Alumno::class)
            ->findBy([
                'instituto' => $instituto,
                'activo' => true
            ]);

        $generadas = 0;
        $omitidas = 0;

        foreach ($alumnosActivos as $alumno) {
            // Se pregunta antes en vez de mirar si el id quedó en null:
            // generarCuotaInscripcionParaAlumno() hace flush antes de devolver, así que el id
            // siempre viene cargado y "generadas" contaba siempre 0.
            $yaTenia = $this->getCuotaInscripcionPorAno($alumno, $ano) !== null;

            $deuda = $this->generarCuotaInscripcionParaAlumno($alumno, $ano);

            if ($deuda === null) {
                continue;
            }

            if ($yaTenia) {
                $omitidas++;
            } else {
                $generadas++;
            }
        }

        return [
            'generadas' => $generadas,
            'omitidas' => $omitidas,
            'ano' => $ano
        ];
    }

    /**
     * Verifica si un alumno debe tener una cuota de inscripción para el año actual
     * y la genera si no existe
     * 
     * @param Alumno $alumno El alumno
     * @return bool True si se generó o ya existía la cuota
     */
    public function verificarYGenerarCuotaInscripcion(Alumno $alumno): bool
    {
        $configuracion = $alumno->getInstituto()->getConfiguracion();

        if (!$configuracion || !$configuracion->getCobrarCuotaInscripcionAnual()) {
            return false;
        }

        $fechaActual = $this->institutoTimezoneService->getNowForInstituto($alumno->getInstituto());
        $anoActual = (int) $fechaActual->format('Y');
        $mesActual = (int) $fechaActual->format('n');
        $mesConfiguracion = $configuracion->getMesCobroCuotaInscripcionAnual() ?? 1;

        // Solo generar si ya pasó el mes de cobro configurado
        if ($mesActual < $mesConfiguracion) {
            return false;
        }

        $deuda = $this->generarCuotaInscripcionParaAlumno($alumno, $anoActual);
        
        return $deuda !== null;
    }

    /**
     * Obtiene todas las cuotas de inscripción anual de un alumno
     * 
     * @param Alumno $alumno El alumno
     * @return DeudaAlumno[] Array de deudas
     */
    public function getCuotasInscripcionAlumno(Alumno $alumno): array
    {
        return $this->entityManager->getRepository(DeudaAlumno::class)
            ->findBy([
                'alumno' => $alumno,
                'esCuotaInscripcionAnual' => true
            ], ['ano' => 'DESC']);
    }

    /**
     * Cuotas de inscripción del alumno que todavía tienen saldo pendiente, de la más nueva
     * a la más vieja.
     *
     * @return DeudaAlumno[]
     */
    public function getCuotasPendientes(Alumno $alumno): array
    {
        return array_values(array_filter(
            $this->getCuotasInscripcionAlumno($alumno),
            static function (DeudaAlumno $deuda) {
                return $deuda->getMontoPendiente() > 0;
            }
        ));
    }

    /**
     * Cuánto le falta pagar al alumno en concepto de inscripción, sumando todos los años.
     */
    public function getTotalPendiente(Alumno $alumno): float
    {
        $total = 0.0;
        foreach ($this->getCuotasPendientes($alumno) as $deuda) {
            $total += $deuda->getMontoPendiente();
        }

        return $total;
    }

    /**
     * Obtiene la cuota de inscripción de un alumno para un año específico
     *
     * @param Alumno $alumno El alumno
     * @param int $ano El año
     * @return DeudaAlumno|null La deuda o null si no existe
     */
    public function getCuotaInscripcionPorAno(Alumno $alumno, int $ano): ?DeudaAlumno
    {
        return $this->entityManager->getRepository(DeudaAlumno::class)
            ->findOneBy([
                'alumno' => $alumno,
                'ano' => $ano,
                'esCuotaInscripcionAnual' => true
            ]);
    }
}
