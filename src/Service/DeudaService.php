<?php

namespace App\Service;

use App\Entity\Alumno;
use App\Entity\AlumnoCursoHistorico;
use App\Entity\AlumnosPagos;
use App\Entity\Curso;
use App\Entity\DeudaAlumno;
use App\Entity\Instituto;
use App\Service\InstitutoTimezoneService;
use Doctrine\ORM\EntityManagerInterface;

class DeudaService
{
    private EntityManagerInterface $entityManager;
    private ?\App\Service\PagoService $pagoService = null;
    private InstitutoTimezoneService $institutoTimezoneService;

    public function __construct(
        EntityManagerInterface $entityManager,
        InstitutoTimezoneService $institutoTimezoneService
    ) {
        $this->entityManager = $entityManager;
        $this->institutoTimezoneService = $institutoTimezoneService;
    }
    
    /**
     * Establece el servicio de pagos (inyección opcional para evitar dependencia circular)
     */
    public function setPagoService(\App\Service\PagoService $pagoService): void
    {
        $this->pagoService = $pagoService;
    }
    
    /**
     * Genera deudas para un alumno en un curso histórico específico
     * Solo genera hasta el mes actual, no meses futuros
     */
    public function generarDeudasParaHistorico(
        AlumnoCursoHistorico $historico, 
        bool $comenzarDeudaProximoMes = false,
        bool $force = false
    ): array {
        $alumno = $historico->getAlumno();
        $curso = $historico->getCurso();
        $instituto = $curso->getInstituto();
        $nowInstituto = $this->institutoTimezoneService->getNowForInstituto($instituto);

        // Utilizar fecha de inicio efectiva: max(inicio del curso, alta del alumno) (fallbacks en zona del instituto)
        $fechaInicioCurso = $curso->getFechaInicio() ?: \DateTime::createFromImmutable($nowInstituto);
        $fechaAlta = $historico->getFechaAlta() ?: \DateTime::createFromImmutable($nowInstituto);
        $fechaInicio = $fechaInicioCurso > $fechaAlta ? clone $fechaInicioCurso : clone $fechaAlta;
        $fechaInicio->modify('first day of this month');
        
        if ($comenzarDeudaProximoMes) {
            $fechaInicio->modify('first day of next month');
        }

        // IMPORTANTE: Solo generar deudas hasta el mes actual, no meses futuros (en zona horaria del instituto)
        $fechaActual = $nowInstituto;
        $fechaFinCurso = $curso->getFechaFin() ?: $nowInstituto->setDate((int) $nowInstituto->format('Y'), 12, 31);
        
        // Generar solo hasta el mes actual o hasta que termine el curso, lo que ocurra primero
        $fechaFin = $fechaActual < $fechaFinCurso ? $fechaActual : $fechaFinCurso;

        if ($fechaInicio > $fechaFin) {
            return [
                'creadas' => 0,
                'actualizadas' => 0
            ];
        }

        $fechaFinDt = \DateTime::createFromImmutable($fechaFin);
        return $this->generarDeudasParaPeriodo(
            $alumno,
            $curso,
            $historico,
            $fechaInicio,
            $fechaFinDt,
            $force
        );
    }
    
    /**
     * Genera deudas mensuales para un alumno en un curso durante un período de tiempo
     */
    public function generarDeudasParaPeriodo(
        Alumno $alumno,
        Curso $curso,
        AlumnoCursoHistorico $historico,
        \DateTime $fechaInicio,
        \DateTime $fechaFin,
        bool $force = false
    ): array {
        $deudasCreadas = 0;
        $deudasActualizadas = 0;

        $fechaIteracion = clone $fechaInicio;
        $fechaIteracion->modify('first day of this month');
        $fechaFinIteracion = clone $fechaFin;
        $fechaFinIteracion->modify('last day of this month');

        while ($fechaIteracion <= $fechaFinIteracion) {
            $mes = (int)$fechaIteracion->format('n');
            $ano = (int)$fechaIteracion->format('Y');

            // Verificar si ya existe una deuda para este mes/año/curso
            $deudaExistente = $this->entityManager->getRepository(DeudaAlumno::class)
                ->findOneBy([
                    'alumno' => $alumno,
                    'curso' => $curso,
                    'mes' => $mes,
                    'ano' => $ano
                ]);

            if (!$deudaExistente || $force) {
                if ($deudaExistente && $force) {
                    // Actualizar deuda existente
                    $deudaExistente->setMonto($curso->getPrecio());
                    $deudaExistente->setCursoHistorico($historico);
                    $deudasActualizadas++;
                } else {
                    // Crear nueva deuda
                    $deuda = new DeudaAlumno();
                    $deuda->setAlumno($alumno);
                    $deuda->setCurso($curso);
                    $deuda->setCursoHistorico($historico);
                    $deuda->setMes($mes);
                    $deuda->setAno($ano);
                    // El estado se calcula automáticamente basado en las aplicaciones de pago
                    $deuda->setMonto($curso->getPrecio());
                    $deuda->setInstituto($alumno->getInstituto());

                    $this->entityManager->persist($deuda);
                    $deudasCreadas++;
                }
            }

            // Avanzar al siguiente mes
            $fechaIteracion->modify('+1 month');
        }

        $this->entityManager->flush();

        return [
            'creadas' => $deudasCreadas,
            'actualizadas' => $deudasActualizadas
        ];
    }
    
    /**
     * Registra un pago para una deuda específica
     * @deprecated Usar PagoService::registrarPago() en su lugar
     */
    public function registrarPago(DeudaAlumno $deuda, float $monto, \DateTime $fecha = null): AlumnosPagos
    {
        if ($fecha === null) {
            $instituto = $deuda->getAlumno()->getInstituto();
            $fecha = $this->institutoTimezoneService->getNowForInstituto($instituto);
        }
        
        $pago = new AlumnosPagos();
        $pago->setAlumno($deuda->getAlumno());
        $pago->setCurso($deuda->getCurso());
        $pago->setMes($deuda->getMes());
        $pago->setAno($deuda->getAno());
        $pago->setMonto($monto);
        $pago->setFecha($fecha);
        $pago->setMetodoPago('Efectivo'); // Valor por defecto
        
        // Buscar curso histórico
        $historico = $this->entityManager->getRepository(\App\Entity\AlumnoCursoHistorico::class)
            ->findOneBy([
                'alumno' => $deuda->getAlumno(),
                'curso' => $deuda->getCurso(),
                'activo' => true
            ]);
        
        if ($historico) {
            $pago->setCursoHistorico($historico);
        }
        
        // Usar PagoService para aplicar el pago
        if ($this->pagoService === null) {
            $this->pagoService = new \App\Service\PagoService($this->entityManager, $this->institutoTimezoneService);
        }
        
        $resultado = $this->pagoService->registrarPago($pago, [$deuda->getId()], false);
        
        return $resultado['pago'];
    }
    
    /**
     * Obtiene todas las deudas pendientes de un alumno
     * Genera automáticamente las deudas vencidas que no existan
     */
    public function getDeudasPendientesPorAlumno(Alumno $alumno): array
    {
        // Primero, generar cualquier deuda vencida que no exista
        $this->generarDeudasVencidasFaltantes($alumno);
        
        $deudas = $this->entityManager->getRepository(DeudaAlumno::class)
            ->findBy([
                'alumno' => $alumno
            ], ['ano' => 'ASC', 'mes' => 'ASC']);
        
        // Filtrar solo las que tienen monto pendiente
        return array_filter($deudas, function(DeudaAlumno $deuda) {
            return $deuda->getMontoPendiente() > 0;
        });
    }
    
    /**
     * Obtiene todas las deudas pendientes para un curso
     */
    public function getDeudasPendientesPorCurso(Curso $curso): array
    {
        $deudas = $this->entityManager->getRepository(DeudaAlumno::class)
            ->findBy([
                'curso' => $curso
            ], ['alumno' => 'ASC', 'ano' => 'ASC', 'mes' => 'ASC']);
        
        // Filtrar solo las que tienen monto pendiente
        return array_filter($deudas, function(DeudaAlumno $deuda) {
            return $deuda->getMontoPendiente() > 0;
        });
    }
    
    /**
     * Obtiene todas las deudas pendientes para un instituto
     */
    public function getDeudasPendientesPorInstituto(Instituto $instituto): array
    {
        $deudas = $this->entityManager->getRepository(DeudaAlumno::class)
            ->findBy([
                'instituto' => $instituto
            ], ['alumno' => 'ASC', 'curso' => 'ASC', 'ano' => 'ASC', 'mes' => 'ASC']);
        
        // Filtrar solo las que tienen monto pendiente
        return array_filter($deudas, function(DeudaAlumno $deuda) {
            return $deuda->getMontoPendiente() > 0;
        });
    }
    
    /**
     * Verifica si un alumno tiene deudas pendientes para un curso específico
     */
    public function tieneDeudasPendientes(Alumno $alumno, Curso $curso = null): bool
    {
        $criteria = [
            'alumno' => $alumno
        ];
        
        if ($curso) {
            $criteria['curso'] = $curso;
        }
        
        $deudas = $this->entityManager->getRepository(DeudaAlumno::class)->findBy($criteria);
        
        // Verificar si alguna tiene monto pendiente
        foreach ($deudas as $deuda) {
            if ($deuda->getMontoPendiente() > 0) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Obtiene el resumen de deudas por curso para un alumno
     */
    public function getResumenDeudasPorAlumno(Alumno $alumno): array
    {
        $deudas = $this->getDeudasPendientesPorAlumno($alumno);
        $resumen = [];
        
        foreach ($deudas as $deuda) {
            $cursoId = $deuda->getCurso()->getId();
            
            if (!isset($resumen[$cursoId])) {
                $resumen[$cursoId] = [
                    'curso' => $deuda->getCurso(),
                    'total' => 0,
                    'meses' => []
                ];
            }
            
            $resumen[$cursoId]['total'] += $deuda->getMonto();
            $resumen[$cursoId]['meses'][] = [
                'mes' => $deuda->getMes(),
                'ano' => $deuda->getAno(),
                'monto' => $deuda->getMonto(),
                'deuda' => $deuda
            ];
        }
        
        return $resumen;
    }
    
    /**
     * Obtiene estadísticas de deudas para un instituto
     */
    public function getEstadisticasDeudas(Instituto $instituto): array
    {
        $deudasPendientes = $this->getDeudasPendientesPorInstituto($instituto);
        $totalDeudas = 0;
        $deudasPorCurso = [];
        $deudasPorMes = [];
        
        foreach ($deudasPendientes as $deuda) {
            $totalDeudas += $deuda->getMonto();
            $cursoId = $deuda->getCurso()->getId();
            $mesAno = $deuda->getAno() . '-' . str_pad($deuda->getMes(), 2, '0', STR_PAD_LEFT);
            
            // Deudas por curso
            if (!isset($deudasPorCurso[$cursoId])) {
                $deudasPorCurso[$cursoId] = [
                    'curso' => $deuda->getCurso()->getNombre(),
                    'total' => 0,
                    'cantidad' => 0
                ];
            }
            
            $deudasPorCurso[$cursoId]['total'] += $deuda->getMonto();
            $deudasPorCurso[$cursoId]['cantidad']++;
            
            // Deudas por mes
            if (!isset($deudasPorMes[$mesAno])) {
                $deudasPorMes[$mesAno] = [
                    'mes' => $deuda->getMes(),
                    'ano' => $deuda->getAno(),
                    'total' => 0,
                    'cantidad' => 0
                ];
            }
            
            $deudasPorMes[$mesAno]['total'] += $deuda->getMonto();
            $deudasPorMes[$mesAno]['cantidad']++;
        }
        
        // Ordenar por mes
        ksort($deudasPorMes);
        
        return [
            'totalDeudas' => $totalDeudas,
            'cantidadDeudas' => count($deudasPendientes),
            'deudasPorCurso' => array_values($deudasPorCurso),
            'deudasPorMes' => array_values($deudasPorMes)
        ];
    }
    
    /**
     * Cancela las deudas pendientes de un alumno para un curso específico
     * 
     * @param Alumno $alumno El alumno para el que se cancelarán las deudas
     * @param Curso $curso El curso asociado a las deudas
     * @param bool $soloFuturas Si es true, solo cancela deudas del mes actual y futuras
     * @return int Número de deudas canceladas
     */
    public function cancelarDeudasPendientesAlumnoCurso(Alumno $alumno, Curso $curso, bool $soloFuturas = true): int
    {
        // Obtener todas las deudas del alumno y curso
        $deudas = $this->entityManager->getRepository(DeudaAlumno::class)
            ->findBy([
                'alumno' => $alumno,
                'curso' => $curso
            ]);
        
        // Filtrar por fecha si es necesario
        if ($soloFuturas) {
            $fechaActual = $this->institutoTimezoneService->getNowForInstituto($alumno->getInstituto());
            $mesActual = (int)$fechaActual->format('n');
            $anoActual = (int)$fechaActual->format('Y');
            
            // Filtrar solo deudas futuras
            $deudas = array_filter($deudas, function(DeudaAlumno $deuda) use ($mesActual, $anoActual) {
                return ($deuda->getAno() > $anoActual) || 
                       ($deuda->getAno() == $anoActual && $deuda->getMes() >= $mesActual);
            });
        }
        
        // Filtrar solo las que tienen monto pendiente
        $deudas = array_filter($deudas, function(DeudaAlumno $deuda) {
            return $deuda->getMontoPendiente() > 0;
        });
        
        // Eliminar las deudas encontradas
        $count = count($deudas);
        foreach ($deudas as $deuda) {
            $this->entityManager->remove($deuda);
        }
        
        $this->entityManager->flush();
        
        return $count;
    }
    
    /**
     * Verifica si un alumno tiene deudas generadas para un curso y, si no, las genera
     * 
     * @param Alumno $alumno El alumno para verificar/generar deudas
     * @param Curso $curso El curso asociado a las deudas
     * @param AlumnoCursoHistorico|null $historico Registro histórico asociado
     * @return int Número de deudas generadas
     */
    public function verificarYGenerarDeudasAlumno(Alumno $alumno, Curso $curso, ?AlumnoCursoHistorico $historico = null): int
    {
        // Verificar si ya existen deudas para este alumno y curso
        $deudasExistentes = $this->entityManager->getRepository(DeudaAlumno::class)
            ->findBy([
                'alumno' => $alumno,
                'curso' => $curso
            ]);
            
        // Si ya existen deudas, no hacer nada
        if (count($deudasExistentes) > 0) {
            return 0;
        }
        
        // Obtener el registro histórico si no se proporcionó
        if ($historico === null) {
            $historico = $this->entityManager->getRepository(AlumnoCursoHistorico::class)
                ->findOneBy([
                    'alumno' => $alumno,
                    'curso' => $curso,
                    'activo' => true
                ]);
                
            if ($historico === null) {
                // Si no hay historial, no podemos generar deudas
                return 0;
            }
        }
        
        // IMPORTANTE: Solo generar deudas hasta el mes actual, no hasta fin de año
        $fechaActual = $this->institutoTimezoneService->getNowForInstituto($alumno->getInstituto());
        $fechaFinCurso = $curso->getFechaFin() ?: \DateTime::createFromImmutable($fechaActual->setDate((int)$fechaActual->format('Y'), 12, 31));
        
        // Generar solo hasta el mes actual o hasta que termine el curso
        $fechaFin = $fechaActual < $fechaFinCurso ? $fechaActual : $fechaFinCurso;
        
        $resultado = $this->generarDeudasParaPeriodo(
            $alumno,
            $curso,
            $historico,
            $fechaActual,
            $fechaFin,
            false // No forzar sobrescritura de deudas existentes
        );
        
        return $resultado['creadas'];
    }
    
    /**
     * Actualiza las deudas cuando un curso finaliza o se desactiva
     * Elimina las deudas futuras a partir de la fecha indicada, pero solo si no están pagadas
     * 
     * @param Curso $curso El curso finalizado o desactivado
     * @param \DateTime $fechaFin La fecha a partir de la cual se deben eliminar las deudas (inclusive)
     * @return int El número de deudas eliminadas
     */
    public function actualizarDeudasPorFinalizacionCurso(Curso $curso, \DateTime $fechaFin): int
    {
        $mesFinalizacion = (int)$fechaFin->format('n');
        $anoFinalizacion = (int)$fechaFin->format('Y');
        
        // Obtener todas las deudas futuras para este curso
        $qb = $this->entityManager->createQueryBuilder();
        $qb->select('d')
           ->from(DeudaAlumno::class, 'd')
           ->where('d.curso = :curso')
           ->andWhere(
                $qb->expr()->orX(
                    // Años posteriores
                    $qb->expr()->gt('d.ano', ':anoFin'),
                    // Mismo año, meses posteriores
                    $qb->expr()->andX(
                        $qb->expr()->eq('d.ano', ':anoFin'),
                        $qb->expr()->gt('d.mes', ':mesFin')
                    )
                )
            )
           ->setParameter('curso', $curso)
           ->setParameter('anoFin', $anoFinalizacion)
           ->setParameter('mesFin', $mesFinalizacion);
           
        $deudasFuturas = $qb->getQuery()->getResult();
        
        // Filtrar solo las que no tienen pagos aplicados (no se pueden eliminar deudas con pagos)
        $deudasAEliminar = array_filter($deudasFuturas, function(DeudaAlumno $deuda) {
            return $deuda->getAplicaciones()->isEmpty();
        });
        
        // Eliminar las deudas futuras sin pagos
        $cantidadEliminadas = count($deudasAEliminar);
        foreach ($deudasAEliminar as $deuda) {
            $this->entityManager->remove($deuda);
        }
        
        $this->entityManager->flush();
        
        return $cantidadEliminadas;
    }
    
    /**
     * Mantiene las deudas de un alumno aunque esté inactivo
     * Si hay deudas pendientes, el alumno no debería borrarlas al desactivarse
     * 
     * @param Alumno $alumno El alumno que se desactiva
     * @return array Información sobre las deudas pendientes
     */
    public function verificarDeudasPendientesAlumnoInactivo(Alumno $alumno): array
    {
        // Obtener todas las deudas para este alumno
        $deudas = $this->entityManager->getRepository(DeudaAlumno::class)
            ->findBy([
                'alumno' => $alumno
            ]);
        
        // Filtrar solo las que tienen monto pendiente
        $deudasPendientes = array_filter($deudas, function(DeudaAlumno $deuda) {
            return $deuda->getMontoPendiente() > 0;
        });
            
        $totalDeudas = count($deudasPendientes);
        $montoPendiente = 0;
        $deudasPorCurso = [];
        
        foreach ($deudasPendientes as $deuda) {
            $montoPendiente += $deuda->getMonto();
            
            $cursoId = $deuda->getCurso()->getId();
            $cursoNombre = $deuda->getCurso()->getNombre();
            
            if (!isset($deudasPorCurso[$cursoId])) {
                $deudasPorCurso[$cursoId] = [
                    'nombre' => $cursoNombre,
                    'cantidad' => 0,
                    'monto' => 0
                ];
            }
            
            $deudasPorCurso[$cursoId]['cantidad']++;
            $deudasPorCurso[$cursoId]['monto'] += $deuda->getMonto();
        }
        
        return [
            'totalDeudas' => $totalDeudas,
            'montoPendiente' => $montoPendiente,
            'deudasPorCurso' => array_values($deudasPorCurso)
        ];
    }
    
    /**
     * Actualiza las deudas cuando se modifican las fechas de un curso
     * 
     * @param Curso $curso El curso cuyas fechas han sido modificadas
     * @param \DateTime|null $fechaInicioAnterior La fecha de inicio anterior
     * @param \DateTime|null $fechaFinAnterior La fecha de fin anterior 
     * @param \DateTime|null $nuevaFechaInicio La nueva fecha de inicio
     * @param \DateTime|null $nuevaFechaFin La nueva fecha de fin
     * @return array Estadísticas de deudas creadas, actualizadas y eliminadas
     */
    public function actualizarDeudasPorCambioFechas(
        Curso $curso,
        ?\DateTime $fechaInicioAnterior,
        ?\DateTime $fechaFinAnterior,
        ?\DateTime $nuevaFechaInicio,
        ?\DateTime $nuevaFechaFin
    ): array {
        $estadisticas = [
            'creadas' => 0,
            'eliminadas' => 0,
            'actualizadas' => 0
        ];
        
        // Obtener todos los alumnos del curso
        $alumnos = $curso->getAlumnos();
        
        foreach ($alumnos as $alumno) {
            // 1. Obtener el historial de curso para este alumno
            $historicoActivo = $this->entityManager->getRepository(AlumnoCursoHistorico::class)
                ->findOneBy([
                    'alumno' => $alumno,
                    'curso' => $curso,
                    'activo' => true
                ]);
                
            if (!$historicoActivo) {
                continue; // Si no hay historial activo, continuar con el siguiente alumno
            }
            
            // 2. No actualizamos las fechas del histórico ya que ahora usamos directamente las del curso
            
            // 3. Recuperar todas las deudas existentes para este alumno y curso
            $deudasExistentes = $this->entityManager->getRepository(DeudaAlumno::class)
                ->findBy([
                    'alumno' => $alumno,
                    'curso' => $curso
                ]);
                
            // Crear un mapa de deudas por mes/año para facilitar la búsqueda
            $mapaDeudas = [];
            foreach ($deudasExistentes as $deuda) {
                $clave = $deuda->getAno() . '-' . str_pad($deuda->getMes(), 2, '0', STR_PAD_LEFT);
                $mapaDeudas[$clave] = $deuda;
            }
            
            // 4. Determinar el período para las deudas (siempre desde inicio a fin del curso)
            $fechaInicio = $nuevaFechaInicio ?: $curso->getFechaInicio();
            $fechaFin = $nuevaFechaFin ?: $curso->getFechaFin();
            
            // Si no hay fecha fin, usar fin de año como límite máximo
            if ($fechaFin === null) {
                $fechaFin = new \DateTime($fechaInicio->format('Y') . '-12-31');
            }
            
            // 5. Crear una lista de meses/años que deberían tener deudas
            $deberianExistir = [];
            $fechaIteracion = clone $fechaInicio;
            $fechaIteracion->modify('first day of this month');
            
            while ($fechaIteracion <= $fechaFin) {
                $mes = (int)$fechaIteracion->format('n');
                $ano = (int)$fechaIteracion->format('Y');
                $clave = $ano . '-' . str_pad($mes, 2, '0', STR_PAD_LEFT);
                
                $deberianExistir[$clave] = [
                    'mes' => $mes,
                    'ano' => $ano
                ];
                
                $fechaIteracion->modify('+1 month');
            }
            
            // 6. Identificar deudas a eliminar (aquellas que existen pero no deberían)
            foreach ($mapaDeudas as $clave => $deuda) {
                if (!isset($deberianExistir[$clave])) {
                    // Solo eliminar si no está pagada
                    if (!$deuda->isPagado()) {
                        $this->entityManager->remove($deuda);
                        $estadisticas['eliminadas']++;
                    }
                }
            }
            
            // 7. Crear deudas que deberían existir pero no existen
            foreach ($deberianExistir as $clave => $datos) {
                // Si la deuda ya existe, no hacer nada
                if (isset($mapaDeudas[$clave])) {
                    continue;
                }
                
                // Si no existe, crearla
                $nuevaDeuda = new DeudaAlumno();
                $nuevaDeuda->setAlumno($alumno);
                $nuevaDeuda->setCurso($curso);
                $nuevaDeuda->setCursoHistorico($historicoActivo);
                $nuevaDeuda->setMes($datos['mes']);
                $nuevaDeuda->setAno($datos['ano']);
                // El estado se calcula automáticamente basado en las aplicaciones de pago
                $nuevaDeuda->setMonto($curso->getPrecio());
                $nuevaDeuda->setInstituto($alumno->getInstituto());
                
                $this->entityManager->persist($nuevaDeuda);
                $estadisticas['creadas']++;
            }
        }
        
        $this->entityManager->flush();
        
        return $estadisticas;
    }
    
    /**
     * Genera las deudas faltantes desde el inicio del curso hasta el mes actual para todos los alumnos activos
     * Este método debe ser ejecutado el primer día de cada mes
     * Genera todas las deudas faltantes desde el inicio del curso hasta el mes actual, no solo el mes actual
     * 
     * @param Instituto|null $instituto Si es null, procesa todos los institutos
     * @param bool $dryRun Si es true, no guarda los cambios en la base de datos
     * @return array Estadísticas de deudas generadas
     */
    public function generarDeudasMesActual(?Instituto $instituto = null, bool $dryRun = false): array
    {
        // Si no se especifica instituto, no podemos determinar la fecha actual correctamente
        // Este método debería siempre recibir un instituto
        if ($instituto === null) {
            throw new \InvalidArgumentException('Se requiere un instituto para generar deudas mensuales');
        }
        
        $fechaActual = $this->institutoTimezoneService->getNowForInstituto($instituto);
        
        $estadisticas = [
            'alumnosProcesados' => 0,
            'deudasCreadas' => 0,
            'deudasOmitidas' => 0,
            'errores' => []
        ];
        
        // Obtener repositorios
        $alumnoRepo = $this->entityManager->getRepository(Alumno::class);
        $historicoRepo = $this->entityManager->getRepository(AlumnoCursoHistorico::class);
        
        // Construir query para obtener alumnos activos
        $qb = $alumnoRepo->createQueryBuilder('a')
            ->where('a.activo = :activo')
            ->setParameter('activo', true);
            
        if ($instituto !== null) {
            $qb->andWhere('a.instituto = :instituto')
               ->setParameter('instituto', $instituto);
        }
        
        $alumnos = $qb->getQuery()->getResult();
        
        foreach ($alumnos as $alumno) {
            $estadisticas['alumnosProcesados']++;
            $fechaActualAlumno = $this->institutoTimezoneService->getNowForInstituto($alumno->getInstituto());
            
            // Obtener todos los cursos activos del alumno
            $historicos = $historicoRepo->findBy([
                'alumno' => $alumno,
                'activo' => true
            ]);
            
            foreach ($historicos as $historico) {
                $curso = $historico->getCurso();
                
                // Verificar si el curso está activo y dentro del período
                $fechaInicioCurso = $curso->getFechaInicio();
                $fechaFinCurso = $curso->getFechaFin();
                
                // Si el curso no ha iniciado aún, saltar
                if ($fechaInicioCurso && $fechaInicioCurso > $fechaActualAlumno) {
                    continue;
                }
                
                // Si el curso ya finalizó, saltar
                if ($fechaFinCurso && $fechaFinCurso < $fechaActualAlumno) {
                    continue;
                }
                
                // Determinar el rango de fechas para generar deudas
                // Inicio: fecha de inicio del curso o fecha de inicio del histórico
                $fechaInicio = $fechaInicioCurso;
                if (!$fechaInicio) {
                    // Si no hay fecha de inicio del curso, usar la fecha de inicio del histórico
                    $fechaInicioHistorico = $historico->getFechaInicio();
                    if ($fechaInicioHistorico) {
                        $fechaInicio = $fechaInicioHistorico;
                    } else {
                        // Si tampoco hay fecha de inicio del histórico, usar el mes actual
                        $fechaInicio = \DateTime::createFromImmutable($fechaActualAlumno);
                        $fechaInicio->modify('first day of this month');
                    }
                }
                
                // Fin: mes actual (no generar deudas futuras)
                $fechaFin = \DateTime::createFromImmutable($fechaActualAlumno);
                $fechaFin->modify('last day of this month');
                
                // Si el curso tiene fecha fin y es anterior al mes actual, usar esa fecha
                if ($fechaFinCurso && $fechaFinCurso < $fechaFin) {
                    $fechaFin = clone $fechaFinCurso;
                    $fechaFin->modify('last day of this month');
                }
                
                // Asegurar que la fecha de inicio sea el primer día del mes
                $fechaInicio->modify('first day of this month');
                
                // Generar deudas para todos los meses desde el inicio hasta el mes actual
                $fechaIteracion = clone $fechaInicio;
                
                while ($fechaIteracion <= $fechaFin) {
                    $mes = (int)$fechaIteracion->format('n');
                    $ano = (int)$fechaIteracion->format('Y');
                    
                    // Verificar si ya existe una deuda para este mes/año/curso
                    $deudaExistente = $this->entityManager->getRepository(DeudaAlumno::class)
                        ->findOneBy([
                            'alumno' => $alumno,
                            'curso' => $curso,
                            'mes' => $mes,
                            'ano' => $ano
                        ]);
                    
                    if ($deudaExistente) {
                        $estadisticas['deudasOmitidas']++;
                    } else {
                        // Crear la deuda para este mes
                        try {
                            $deuda = new DeudaAlumno();
                            $deuda->setAlumno($alumno);
                            $deuda->setCurso($curso);
                            $deuda->setCursoHistorico($historico);
                            $deuda->setMes($mes);
                            $deuda->setAno($ano);
                            // El estado se calcula automáticamente basado en las aplicaciones de pago
                            $deuda->setMonto($curso->getPrecio());
                            $deuda->setInstituto($alumno->getInstituto());
                            
                            if (!$dryRun) {
                                $this->entityManager->persist($deuda);
                            }
                            $estadisticas['deudasCreadas']++;
                            
                            // Hacer flush cada 50 deudas para evitar problemas de memoria (solo si no es dry-run)
                            if (!$dryRun && $estadisticas['deudasCreadas'] % 50 === 0) {
                                $this->entityManager->flush();
                            }
                        } catch (\Exception $e) {
                            $estadisticas['errores'][] = [
                                'alumno' => $alumno->getNombreCompleto(),
                                'curso' => $curso->getNombre(),
                                'mes' => $mes,
                                'ano' => $ano,
                                'error' => $e->getMessage()
                            ];
                        }
                    }
                    
                    // Avanzar al siguiente mes
                    $fechaIteracion->modify('+1 month');
                }
            }
        }
        
        // Flush final (solo si no es dry-run)
        if (!$dryRun) {
            $this->entityManager->flush();
        } else {
            // En dry-run, limpiar el EntityManager sin guardar
            $this->entityManager->clear();
        }
        
        return $estadisticas;
    }
    
    /**
     * Genera las deudas vencidas que falten para un alumno específico
     * Solo genera deudas de meses pasados y el mes actual (si ya venció)
     * 
     * @param Alumno $alumno El alumno para el cual generar deudas faltantes
     * @return array Estadísticas de deudas generadas
     */
    public function generarDeudasVencidasFaltantes(Alumno $alumno): array
    {
        $estadisticas = [
            'deudasCreadas' => 0,
            'deudasOmitidas' => 0
        ];
        
        if (!$alumno->getActivo()) {
            return $estadisticas;
        }
        
        $fechaActual = $this->institutoTimezoneService->getNowForInstituto($alumno->getInstituto());
        $mesActual = (int)$fechaActual->format('n');
        $anoActual = (int)$fechaActual->format('Y');
        
        // Obtener todos los cursos activos del alumno
        $historicos = $this->entityManager->getRepository(AlumnoCursoHistorico::class)
            ->findBy([
                'alumno' => $alumno,
                'activo' => true
            ]);
        
        foreach ($historicos as $historico) {
            $curso = $historico->getCurso();
            
            // Obtener fechas del curso
            $fechaInicio = $curso->getFechaInicio() ?: $historico->getFechaInicio();
            $fechaFin = $curso->getFechaFin();
            
            if (!$fechaInicio) {
                continue; // Sin fecha de inicio, saltar
            }
            
            // Iterar desde la fecha de inicio del curso hasta el mes actual
            $fechaIteracion = clone $fechaInicio;
            $fechaIteracion->modify('first day of this month');
            
            while ($fechaIteracion <= $fechaActual) {
                $mes = (int)$fechaIteracion->format('n');
                $ano = (int)$fechaIteracion->format('Y');
                
                // Verificar si el curso ya finalizó antes de este mes
                if ($fechaFin && $fechaIteracion > $fechaFin) {
                    break;
                }
                
                // Verificar si ya existe la deuda
                $deudaExistente = $this->entityManager->getRepository(DeudaAlumno::class)
                    ->findOneBy([
                        'alumno' => $alumno,
                        'curso' => $curso,
                        'mes' => $mes,
                        'ano' => $ano
                    ]);
                
                if (!$deudaExistente) {
                    // Crear la deuda faltante
                    $deuda = new DeudaAlumno();
                    $deuda->setAlumno($alumno);
                    $deuda->setCurso($curso);
                    $deuda->setCursoHistorico($historico);
                    $deuda->setMes($mes);
                    $deuda->setAno($ano);
                    // El estado se calcula automáticamente basado en las aplicaciones de pago
                    $deuda->setMonto($curso->getPrecio());
                    $deuda->setInstituto($alumno->getInstituto());
                    
                    $this->entityManager->persist($deuda);
                    $estadisticas['deudasCreadas']++;
                } else {
                    $estadisticas['deudasOmitidas']++;
                }
                
                // Avanzar al siguiente mes
                $fechaIteracion->modify('+1 month');
            }
        }
        
        if ($estadisticas['deudasCreadas'] > 0) {
            $this->entityManager->flush();
        }
        
        return $estadisticas;
    }
} 