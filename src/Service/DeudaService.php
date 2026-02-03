<?php

namespace App\Service;

use App\Entity\Alumno;
use App\Entity\AlumnoCursoHistorico;
use App\Entity\AlumnosPagos;
use App\Entity\Curso;
use App\Entity\DeudaAlumno;
use App\Entity\Instituto;
use Doctrine\ORM\EntityManagerInterface;

class DeudaService
{
    private EntityManagerInterface $entityManager;
    
    public function __construct(
        EntityManagerInterface $entityManager
    ) {
        $this->entityManager = $entityManager;
    }
    
    /**
     * Genera deudas para un alumno en un curso histórico específico
     * Solo genera hasta el mes actual, no meses futuros
     */
    public function generarDeudasParaHistorico(
        AlumnoCursoHistorico $historico, 
        bool $force = false
    ): array {
        $alumno = $historico->getAlumno();
        $curso = $historico->getCurso();
        
        // Utilizar las fechas del curso, no del histórico
        $fechaInicio = $curso->getFechaInicio() ?: new \DateTime();
        
        // IMPORTANTE: Solo generar deudas hasta el mes actual, no meses futuros
        $fechaActual = new \DateTime();
        $fechaFinCurso = $curso->getFechaFin() ?: new \DateTime(date('Y-12-31'));
        
        // Generar solo hasta el mes actual o hasta que termine el curso, lo que ocurra primero
        $fechaFin = $fechaActual < $fechaFinCurso ? $fechaActual : $fechaFinCurso;
        
        return $this->generarDeudasParaPeriodo(
            $alumno,
            $curso,
            $historico,
            $fechaInicio,
            $fechaFin,
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
                    $deuda->setPagado(false);
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
     */
    public function registrarPago(DeudaAlumno $deuda, float $monto, \DateTime $fecha = null): AlumnosPagos
    {
        if ($fecha === null) {
            $fecha = new \DateTime();
        }
        
        $pago = new AlumnosPagos();
        $pago->setAlumno($deuda->getAlumno());
        $pago->setCurso($deuda->getCurso());
        $pago->setMes($deuda->getMes());
        $pago->setAno($deuda->getAno());
        $pago->setMonto($monto);
        $pago->setFecha($fecha);
        $pago->setInstituto($deuda->getInstituto());
        
        $deuda->setPagado(true);
        $deuda->setPago($pago);
        $deuda->setFechaPago($fecha);
        
        $this->entityManager->persist($pago);
        $this->entityManager->flush();
        
        return $pago;
    }
    
    /**
     * Marca una deuda como pagada y asocia un pago existente
     */
    public function marcarComoPagada(DeudaAlumno $deuda, AlumnosPagos $pago): void
    {
        $deuda->setPagado(true);
        $deuda->setPago($pago);
        $deuda->setFechaPago($pago->getFecha() ?? new \DateTime());
        
        $this->entityManager->flush();
    }
    
    /**
     * Obtiene todas las deudas pendientes de un alumno
     * Genera automáticamente las deudas vencidas que no existan
     */
    public function getDeudasPendientesPorAlumno(Alumno $alumno): array
    {
        // Primero, generar cualquier deuda vencida que no exista
        $this->generarDeudasVencidasFaltantes($alumno);
        
        return $this->entityManager->getRepository(DeudaAlumno::class)
            ->findBy([
                'alumno' => $alumno,
                'pagado' => false
            ], ['ano' => 'ASC', 'mes' => 'ASC']);
    }
    
    /**
     * Obtiene todas las deudas pendientes para un curso
     */
    public function getDeudasPendientesPorCurso(Curso $curso): array
    {
        return $this->entityManager->getRepository(DeudaAlumno::class)
            ->findBy([
                'curso' => $curso,
                'pagado' => false
            ], ['alumno' => 'ASC', 'ano' => 'ASC', 'mes' => 'ASC']);
    }
    
    /**
     * Obtiene todas las deudas pendientes para un instituto
     */
    public function getDeudasPendientesPorInstituto(Instituto $instituto): array
    {
        return $this->entityManager->getRepository(DeudaAlumno::class)
            ->findBy([
                'instituto' => $instituto,
                'pagado' => false
            ], ['alumno' => 'ASC', 'curso' => 'ASC', 'ano' => 'ASC', 'mes' => 'ASC']);
    }
    
    /**
     * Verifica si un alumno tiene deudas pendientes para un curso específico
     */
    public function tieneDeudasPendientes(Alumno $alumno, Curso $curso = null): bool
    {
        $criteria = [
            'alumno' => $alumno,
            'pagado' => false
        ];
        
        if ($curso) {
            $criteria['curso'] = $curso;
        }
        
        return count($this->entityManager->getRepository(DeudaAlumno::class)->findBy($criteria)) > 0;
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
        $criteria = [
            'alumno' => $alumno,
            'curso' => $curso,
            'pagado' => false
        ];
        
        // Si solo queremos cancelar deudas futuras
        if ($soloFuturas) {
            $fechaActual = new \DateTime();
            $mesActual = (int)$fechaActual->format('n');
            $anoActual = (int)$fechaActual->format('Y');
            
            $qb = $this->entityManager->getRepository(DeudaAlumno::class)->createQueryBuilder('d');
            $qb->where('d.alumno = :alumno')
               ->andWhere('d.curso = :curso')
               ->andWhere('d.pagado = :pagado')
               ->andWhere(
                    $qb->expr()->orX(
                        // Años futuros
                        $qb->expr()->gt('d.ano', ':anoActual'),
                        // Mismo año, mes actual o futuro
                        $qb->expr()->andX(
                            $qb->expr()->eq('d.ano', ':anoActual'),
                            $qb->expr()->gte('d.mes', ':mesActual')
                        )
                    )
                )
               ->setParameter('alumno', $alumno)
               ->setParameter('curso', $curso)
               ->setParameter('pagado', false)
               ->setParameter('anoActual', $anoActual)
               ->setParameter('mesActual', $mesActual);
            
            $deudas = $qb->getQuery()->getResult();
        } else {
            // Cancelar todas las deudas pendientes
            $deudas = $this->entityManager->getRepository(DeudaAlumno::class)->findBy($criteria);
        }
        
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
        $fechaActual = new \DateTime();
        $fechaFinCurso = $curso->getFechaFin() ?: new \DateTime($fechaActual->format('Y') . '-12-31');
        
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
        
        // Obtener todas las deudas futuras no pagadas para este curso
        $qb = $this->entityManager->createQueryBuilder();
        $qb->select('d')
           ->from(DeudaAlumno::class, 'd')
           ->where('d.curso = :curso')
           ->andWhere('d.pagado = :pagado')
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
           ->setParameter('pagado', false)
           ->setParameter('anoFin', $anoFinalizacion)
           ->setParameter('mesFin', $mesFinalizacion);
           
        $deudasFuturas = $qb->getQuery()->getResult();
        
        // Eliminar las deudas futuras
        $cantidadEliminadas = count($deudasFuturas);
        foreach ($deudasFuturas as $deuda) {
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
        // Obtener todas las deudas no pagadas para este alumno
        $deudasPendientes = $this->entityManager->getRepository(DeudaAlumno::class)
            ->findBy([
                'alumno' => $alumno,
                'pagado' => false
            ]);
            
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
                $nuevaDeuda->setPagado(false);
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
     * Genera las deudas del mes actual para todos los alumnos activos de un instituto
     * Este método debe ser ejecutado el primer día de cada mes
     * 
     * @param Instituto|null $instituto Si es null, procesa todos los institutos
     * @param bool $dryRun Si es true, no guarda los cambios en la base de datos
     * @return array Estadísticas de deudas generadas
     */
    public function generarDeudasMesActual(?Instituto $instituto = null, bool $dryRun = false): array
    {
        $fechaActual = new \DateTime();
        $mesActual = (int)$fechaActual->format('n');
        $anoActual = (int)$fechaActual->format('Y');
        
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
                if ($fechaInicioCurso && $fechaInicioCurso > $fechaActual) {
                    continue;
                }
                
                // Si el curso ya finalizó, saltar
                if ($fechaFinCurso && $fechaFinCurso < $fechaActual) {
                    continue;
                }
                
                // Verificar si ya existe una deuda para este mes
                $deudaExistente = $this->entityManager->getRepository(DeudaAlumno::class)
                    ->findOneBy([
                        'alumno' => $alumno,
                        'curso' => $curso,
                        'mes' => $mesActual,
                        'ano' => $anoActual
                    ]);
                
                if ($deudaExistente) {
                    $estadisticas['deudasOmitidas']++;
                    continue;
                }
                
                // Crear la deuda del mes actual
                try {
                    $deuda = new DeudaAlumno();
                    $deuda->setAlumno($alumno);
                    $deuda->setCurso($curso);
                    $deuda->setCursoHistorico($historico);
                    $deuda->setMes($mesActual);
                    $deuda->setAno($anoActual);
                    $deuda->setPagado(false);
                    $deuda->setMonto($curso->getPrecio());
                    $deuda->setInstituto($alumno->getInstituto());
                    
                    $this->entityManager->persist($deuda);
                    $estadisticas['deudasCreadas']++;
                    
                    // Hacer flush cada 50 deudas para evitar problemas de memoria (solo si no es dry-run)
                    if (!$dryRun && $estadisticas['deudasCreadas'] % 50 === 0) {
                        $this->entityManager->flush();
                    }
                } catch (\Exception $e) {
                    $estadisticas['errores'][] = [
                        'alumno' => $alumno->getNombreCompleto(),
                        'curso' => $curso->getNombre(),
                        'error' => $e->getMessage()
                    ];
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
        
        if (!$alumno->isActivo()) {
            return $estadisticas;
        }
        
        $fechaActual = new \DateTime();
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
                    $deuda->setPagado(false);
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