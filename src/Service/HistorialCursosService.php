<?php

namespace App\Service;

use App\Entity\Alumno;
use App\Entity\Curso;
use App\Entity\AlumnoCursoHistorico;
use App\Entity\AlumnosPagos;
use App\Entity\DeudaAlumno;
use App\Entity\Instituto;
use Doctrine\ORM\EntityManagerInterface;
use App\Service\DeudaService;

class HistorialCursosService
{
    private $entityManager;
    private $deudaService;

    public function __construct(EntityManagerInterface $entityManager, DeudaService $deudaService = null)
    {
        $this->entityManager = $entityManager;
        $this->deudaService = $deudaService;
    }

    /**
     * Registra la inscripción de un alumno a un curso
     */
    public function inscribirAlumnoEnCurso(
        Alumno $alumno, 
        Curso $curso, 
        \DateTime $fechaAlta = null
    ): AlumnoCursoHistorico {
        // Verificar si ya existe un histórico activo para este alumno y curso
        $historicoExistente = $this->getHistoricoActivoPorAlumnoYCurso($alumno, $curso);
        
        if ($historicoExistente) {
            return $historicoExistente;
        }
        
        // Si no hay fecha de alta especificada, usar la fecha actual
        if (!$fechaAlta) {
            $fechaAlta = new \DateTime();
        }
        
        // Crear nuevo registro histórico
        $historico = new AlumnoCursoHistorico();
        $historico->setAlumno($alumno);
        $historico->setCurso($curso);
        $historico->setFechaAlta($fechaAlta);
        $historico->setFechaBaja(null); // No tiene fecha de baja porque está activo
        $historico->setActivo(true);
    
        // Persistir el nuevo registro
        $this->entityManager->persist($historico);
        $this->entityManager->flush();
        
        // Verificar si el curso está activo antes de generar deudas
        if ($curso->getDisabled() === true) {
            // Si el curso está deshabilitado, no generar deudas
            return $historico;
        }
        
        // Generar deudas usando DeudaService si está disponible
        if ($this->deudaService) {
            $this->deudaService->generarDeudasParaHistorico($historico);
        } else {
            // Mantener el método original como fallback
            $this->generarDeudasParaHistorico($historico);
        }
        
        return $historico;
    }
    
    /**
     * Finaliza la inscripción de un alumno a un curso
     */
    public function finalizarInscripcion(Alumno $alumno, Curso $curso, \DateTime $fechaBaja = null): bool
    {
        $historico = $this->getHistoricoActivoPorAlumnoYCurso($alumno, $curso);
        
        if (!$historico) {
            return false;
        }
        
        // Si no se proporciona fecha de baja, usar la fecha actual
        if (!$fechaBaja) {
            $fechaBaja = new \DateTime();
        }
        
        $historico->setFechaBaja($fechaBaja);
        $historico->setActivo(false);
        
        $this->entityManager->flush();
        
        return true;
    }
    
    /**
     * Obtiene el registro histórico activo para un alumno y curso
     */
    public function getHistoricoActivoPorAlumnoYCurso(Alumno $alumno, Curso $curso): ?AlumnoCursoHistorico
    {
        return $this->entityManager->getRepository(AlumnoCursoHistorico::class)
            ->findOneBy([
                'alumno' => $alumno,
                'curso' => $curso,
                'activo' => true
            ]);
    }
    
    /**
     * Obtiene todos los registros históricos para un alumno
     */
    public function getHistoricoPorAlumno(Alumno $alumno): array
    {
        return $this->entityManager->getRepository(AlumnoCursoHistorico::class)
            ->findBy(['alumno' => $alumno], ['fechaInicio' => 'DESC']);
    }
    
    /**
     * Obtiene los registros históricos activos para un alumno
     */
    public function getHistoricoActivoPorAlumno(Alumno $alumno): array
    {
        return $this->entityManager->getRepository(AlumnoCursoHistorico::class)
            ->findBy([
                'alumno' => $alumno,
                'activo' => true
            ]);
    }
    
    /**
     * Obtiene los alumnos activos para un curso
     */
    public function getAlumnosActivosPorCurso(Curso $curso): array
    {
        $historicos = $this->entityManager->getRepository(AlumnoCursoHistorico::class)
            ->findBy([
                'curso' => $curso,
                'activo' => true
            ]);
        
        $alumnos = [];
        foreach ($historicos as $historico) {
            $alumnos[] = $historico->getAlumno();
        }
        
        return $alumnos;
    }
    
    /**
     * Verifica si un alumno está inscrito en un curso específico
     */
    public function alumnoEstaInscritoEnCurso(Alumno $alumno, Curso $curso): bool
    {
        return null !== $this->getHistoricoActivoPorAlumnoYCurso($alumno, $curso);
    }
    
    /**
     * Obtiene estadísticas de inscripciones por instituto
     */
    public function getEstadisticasInscripciones(Instituto $instituto): array
    {
        $historicosActivos = $this->entityManager->getRepository(AlumnoCursoHistorico::class)
            ->findBy([
                'instituto' => $instituto,
                'activo' => true
            ]);
        
        $totalInscripciones = count($historicosActivos);
        $inscripcionesPorCurso = [];
        
        foreach ($historicosActivos as $historico) {
            $cursoId = $historico->getCurso()->getId();
            
            if (!isset($inscripcionesPorCurso[$cursoId])) {
                $inscripcionesPorCurso[$cursoId] = [
                    'curso' => $historico->getCurso()->getNombre(),
                    'total' => 0
                ];
            }
            
            $inscripcionesPorCurso[$cursoId]['total']++;
        }
        
        return [
            'totalInscripciones' => $totalInscripciones,
            'inscripcionesPorCurso' => array_values($inscripcionesPorCurso)
        ];
    }
    
    /**
     * Obtiene el historial de todos los cursos de un instituto
     */
    public function getHistoricoPorInstituto(Instituto $instituto): array
    {
        return $this->entityManager->getRepository(AlumnoCursoHistorico::class)
            ->findBy(['instituto' => $instituto], ['fechaInicio' => 'DESC']);
    }

    /**
     * Crea un nuevo registro histórico para un alumno y un curso
     */
    public function crearHistorial(Alumno $alumno, Curso $curso, \DateTime $fechaInicio = null): AlumnoCursoHistorico
    {
        // Este método es un alias para inscribirAlumnoEnCurso para mantener compatibilidad con código existente
        return $this->inscribirAlumnoEnCurso($alumno, $curso, $fechaInicio);
    }

    /**
     * Genera registros de deuda para un historial de curso
     * Utiliza las fechas del curso para determinar el período de deudas
     */
    public function generarDeudasParaHistorico(AlumnoCursoHistorico $historico): void
    {
        $alumno = $historico->getAlumno();
        $curso = $historico->getCurso();
        
        // Verificar si el curso está activo
        if (method_exists($curso, 'getActivo') && $curso->getActivo() === false) {
            // Si el curso está inactivo, no generar deudas
            return;
        }

        // Obtener fechas de inicio y fin del curso (no del histórico)
        $fechaInicioCurso = $curso->getFechaInicio();
        $fechaFinCurso = $curso->getFechaFin();
        
        // Si no hay fecha de inicio del curso, no podemos generar deudas
        if (!$fechaInicioCurso) {
            return;
        }

        // Si no hay fecha de fin del curso, usar el fin del año actual
        if (!$fechaFinCurso) {
            $fechaFinCurso = new \DateTime($fechaInicioCurso->format('Y') . '-12-31');
        }

        // Generar deudas para cada mes entre la fecha inicio y fin del curso
        $fechaIteracion = clone $fechaInicioCurso;
        $fechaIteracion->modify('first day of this month');
        $fechaFinIteracion = clone $fechaFinCurso;
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

            if (!$deudaExistente) {
                // Crear nueva deuda
                $deuda = new DeudaAlumno();
                $deuda->setAlumno($alumno);
                $deuda->setCurso($curso);
                $deuda->setCursoHistorico($historico);
                $deuda->setMes($mes);
                $deuda->setAno($ano);
                
                // Si la fecha de alta del alumno es posterior al inicio del mes,
                // opcionalmente podríamos marcar la deuda como pagada automáticamente
                // $fechaPrimerDiaMes = new \DateTime($ano . '-' . $mes . '-01');
                // if ($historico->getFechaAlta() > $fechaPrimerDiaMes) {
                //    $deuda->setPagado(true);
                // } else {
                //    $deuda->setPagado(false);
                // }
                
                // Por ahora, dejamos todas como pendientes para que el administrador decida
                $deuda->setPagado(false);
                
                $deuda->setMonto($curso->getPrecio());
                
                // Asegurarse de establecer el instituto
                $instituto = $alumno->getInstituto();
                if ($instituto) {
                    $deuda->setInstituto($instituto);
                }

                $this->entityManager->persist($deuda);
            }

            // Avanzar al siguiente mes
            $fechaIteracion->modify('+1 month');
        }

        $this->entityManager->flush();
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
            $historico = $this->inscribirAlumnoEnCurso($alumno, $curso);
        }

        // Asociar el pago con el historial
        $pago->setCursoHistorico($historico);
        $this->entityManager->persist($pago);

        // Marcar la deuda correspondiente como pagada
        $deuda = $this->entityManager->getRepository(DeudaAlumno::class)
            ->findOneBy([
                'alumno' => $alumno,
                'curso' => $curso,
                'mes' => (int)$fechaPago->format('n'),
                'ano' => (int)$fechaPago->format('Y')
            ]);

        if ($deuda) {
            $deuda->setPagado(true);
            $deuda->setPago($pago);
            $deuda->setFechaPago(new \DateTime());
        } else {
            // Si no existe la deuda, crearla como pagada
            $deuda = new DeudaAlumno();
            $deuda->setAlumno($alumno);
            $deuda->setCurso($curso);
            $deuda->setCursoHistorico($historico);
            $deuda->setMes((int)$fechaPago->format('n'));
            $deuda->setAno((int)$fechaPago->format('Y'));
            $deuda->setPagado(true);
            $deuda->setPago($pago);
            $deuda->setMonto($pago->getMonto());
            $deuda->setFechaPago(new \DateTime());
            $deuda->setInstituto($alumno->getInstituto());

            $this->entityManager->persist($deuda);
        }

        $this->entityManager->flush();
    }

    /**
     * Busca el historial correspondiente a un mes y año específicos
     */
    public function buscarHistorial(Alumno $alumno, Curso $curso, \DateTime $fechaPago): ?AlumnoCursoHistorico
    {
        // Primero buscar un historial activo
        $historicoActivo = $this->getHistoricoActivoPorAlumnoYCurso($alumno, $curso);
        if ($historicoActivo) {
            return $historicoActivo;
        }
        
        // Si no se encuentra un historial activo, buscar en el historial por fecha
        foreach ($alumno->getCursosHistoricos() as $historico) {
            if ($historico->getCurso() === $curso) {
                $fechaAlta = $historico->getFechaAlta();
                $fechaBaja = $historico->getFechaBaja();
                
                // El historial es válido si la fecha de pago está entre la fecha de alta y baja
                // Si no hay fecha de baja, significa que podría estar activo en esa fecha
                if ($fechaAlta <= $fechaPago && (!$fechaBaja || $fechaBaja >= $fechaPago)) {
                    return $historico;
                }
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

        // Obtener todas las deudas no pagadas del alumno
        $deudas = $this->entityManager->getRepository(DeudaAlumno::class)
            ->findBy([
                'alumno' => $alumno,
                'pagado' => false
            ], ['ano' => 'ASC', 'mes' => 'ASC']);

        foreach ($deudas as $deuda) {
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

        // Si el alumno no está activo, no debe nada
        if (!$alumno->getActivo()) {
            return $mesesAdeudados;
        }

        // Obtener todas las deudas no pagadas del alumno para este curso
        $deudas = $this->entityManager->getRepository(DeudaAlumno::class)
            ->findBy([
                'alumno' => $alumno,
                'curso' => $curso,
                'pagado' => false
            ], ['ano' => 'ASC', 'mes' => 'ASC']);

        foreach ($deudas as $deuda) {
            $mes = $deuda->getMes();
            $ano = $deuda->getAno();
            
            // Determinar si este mes debe incluirse según la lógica de vencimientos
            $debeIncluirse = false;
            $razonIncluido = "";
            
            if ($ano < $anoActual) {
                $debeIncluirse = true;
                $razonIncluido = "Mes de año anterior";
            } elseif ($ano == $anoActual) {
                if ($mes < $mesActual) {
                    $debeIncluirse = true;
                    $razonIncluido = "Mes anterior al actual";
                } elseif ($mes == $mesActual) {
                    // Para el mes actual, verificar vencimientos
                    $vencimientos = $alumno->getInstituto()->getVencimientos();
                    $vencimientosOrdenados = $vencimientos->toArray();
                    
                    // Ordenar vencimientos por día (ascendente)
                    usort($vencimientosOrdenados, function($a, $b) {
                        return $a->getDiaVencimiento() <=> $b->getDiaVencimiento();
                    });
                    
                    // Determinar si hay un vencimiento ya pasado
                    $vencimientoPasado = false;
                    foreach ($vencimientosOrdenados as $vencimiento) {
                        if ($diaActual > $vencimiento->getDiaVencimiento()) {
                            $vencimientoPasado = true;
                            break;
                        }
                    }
                    
                    if ($vencimientoPasado) {
                        $debeIncluirse = true;
                        $razonIncluido = "Mes actual con vencimiento pasado";
                    }
                }
            }
            
            if ($debeIncluirse) {
                $mesesAdeudados[] = [
                    'mes' => $mes,
                    'ano' => $ano,
                    'nombre' => $nombresMeses[$mes] . ' ' . $ano,
                    'curso_id' => $curso->getId(),
                    'curso_nombre' => $curso->getNombre(),
                    'curso_obj' => $curso,
                    'razon' => $razonIncluido,
                    'antiguedad' => $this->calcularAntiguedadDeuda($mes, $ano, $mesActual, $anoActual),
                    'monto' => $deuda->getMonto(),
                    'deuda_id' => $deuda->getId()
                ];
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

    /**
     * Crea un nuevo registro histórico para un alumno y un curso y genera deudas hasta fin de año
     * 
     * @param Alumno $alumno
     * @param Curso $curso
     * @return AlumnoCursoHistorico
     */
    public function crearHistorialConDeudasHastaFinDeAno(Alumno $alumno, Curso $curso): AlumnoCursoHistorico
    {
        // Verificar si ya existe un histórico activo
        $historicoExistente = $this->getHistoricoActivoPorAlumnoYCurso($alumno, $curso);
        if ($historicoExistente) {
            // Si ya existe, no hacer nada y retornarlo
            return $historicoExistente;
        }
        
        // Crear historial básico con fecha actual
        $fechaAlta = new \DateTime();
        $historico = $this->inscribirAlumnoEnCurso($alumno, $curso, $fechaAlta);
        
        return $historico;
    }
} 