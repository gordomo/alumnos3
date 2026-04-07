<?php

namespace App\Service;

use App\Entity\Alumno;
use App\Entity\AlumnosPagos;
use App\Entity\DeudaAlumno;
use App\Entity\PagoAplicacion;
use App\Entity\SaldoFavor;
use Doctrine\ORM\EntityManagerInterface;

class PagoService
{
    private EntityManagerInterface $entityManager;
    private InstitutoTimezoneService $institutoTimezoneService;
    private HistorialCursosService $historialCursosService;

    public function __construct(
        EntityManagerInterface $entityManager,
        InstitutoTimezoneService $institutoTimezoneService,
        HistorialCursosService $historialCursosService
    ) {
        $this->entityManager = $entityManager;
        $this->institutoTimezoneService = $institutoTimezoneService;
        $this->historialCursosService = $historialCursosService;
    }

    /**
     * Registra un pago y lo aplica automáticamente a las deudas pendientes
     * 
     * @param AlumnosPagos $pago El pago a registrar
     * @param array|null $deudasSeleccionadas Array de IDs de deudas a las que aplicar el pago (null = aplicar automáticamente)
     * @param bool $permitirAdelantado Si true, permite crear deudas futuras si no existen
     * @return array Información sobre el resultado: ['aplicaciones' => [], 'montoRestante' => float]
     */
    public function registrarPago(
        AlumnosPagos $pago,
        ?array $deudasSeleccionadas = null,
        bool $permitirAdelantado = true
    ): array {
        // Validar que el pago tenga mes y año
        if ($pago->getMes() === null || $pago->getAno() === null) {
            throw new \InvalidArgumentException('El pago debe tener mes y año especificados.');
        }

        // Inicializar monto restante con el monto total del pago
        $montoRestante = $pago->getMonto();
        $aplicaciones = [];

        // Asociar el historial del curso ANTES de persistir (cursoHistorico es obligatorio)
        $this->asociarHistorial($pago);

        // Persistir el pago después de asociar el historial
        $this->entityManager->persist($pago);
        $this->entityManager->flush();

        // Si se especificaron deudas, aplicar solo a esas
        if ($deudasSeleccionadas !== null && !empty($deudasSeleccionadas)) {
            $deudas = $this->entityManager->getRepository(DeudaAlumno::class)
                ->findBy(['id' => $deudasSeleccionadas]);
            
            foreach ($deudas as $deuda) {
                if ($montoRestante <= 0) {
                    break;
                }
                
                // Verificar que la deuda pertenece al mismo alumno
                if ($deuda->getAlumno()->getId() !== $pago->getAlumno()->getId()) {
                    continue;
                }
                
                $aplicacion = $this->aplicarPagoADeuda($pago, $deuda, $montoRestante, true);
                if ($aplicacion) {
                    $aplicaciones[] = $aplicacion;
                    $montoRestante -= $aplicacion->getMontoAplicado();
                }
            }
        } else {
            // Aplicar automáticamente: primero a deudas del mismo mes/año/curso
            $deudaEspecifica = $this->buscarDeudaEspecifica($pago);
            
            if ($deudaEspecifica && $deudaEspecifica->getMontoPendiente() > 0) {
                $aplicacion = $this->aplicarPagoADeuda($pago, $deudaEspecifica, $montoRestante, true);
                if ($aplicacion) {
                    $aplicaciones[] = $aplicacion;
                    $montoRestante -= $aplicacion->getMontoAplicado();
                }
            } elseif ($permitirAdelantado) {
                // Si no existe la deuda y se permite adelantado, crear la deuda
                $deudaEspecifica = $this->crearDeudaParaPagoAdelantado($pago);
                if ($deudaEspecifica) {
                    $aplicacion = $this->aplicarPagoADeuda($pago, $deudaEspecifica, $montoRestante, true);
                    if ($aplicacion) {
                        $aplicaciones[] = $aplicacion;
                        $montoRestante -= $aplicacion->getMontoAplicado();
                    }
                }
            }

            // Si aún hay monto restante, aplicar a otras deudas pendientes del alumno (ordenadas por fecha)
            if ($montoRestante > 0) {
                $deudasPendientes = $this->obtenerDeudasPendientes($pago->getAlumno(), $pago->getCurso());
                
                foreach ($deudasPendientes as $deuda) {
                    if ($montoRestante <= 0) {
                        break;
                    }
                    
                    // No aplicar a la deuda que ya se aplicó
                    $yaAplicada = false;
                    foreach ($aplicaciones as $aplicacionExistente) {
                        if ($aplicacionExistente->getDeuda()->getId() === $deuda->getId()) {
                            $yaAplicada = true;
                            break;
                        }
                    }
                    
                    if (!$yaAplicada) {
                        $aplicacion = $this->aplicarPagoADeuda($pago, $deuda, $montoRestante, true);
                        if ($aplicacion) {
                            $aplicaciones[] = $aplicacion;
                            $montoRestante -= $aplicacion->getMontoAplicado();
                        }
                    }
                }
            }
        }

        // Actualizar monto restante del pago
        $pago->setMontoRestante($montoRestante);

        // Si hay sobrepago, crear automáticamente un saldo a favor
        if ($montoRestante > 0.01) {
            $saldoFavor = new SaldoFavor();
            $saldoFavor->setAlumno($pago->getAlumno());
            $saldoFavor->setInstituto($pago->getAlumno()->getInstituto());
            $saldoFavor->setMonto(number_format($montoRestante, 2, '.', ''));
            $saldoFavor->setMontoDisponible(number_format($montoRestante, 2, '.', ''));
            $saldoFavor->setTipo(SaldoFavor::TIPO_SOBREPAGO);
            $saldoFavor->setDescripcion('Saldo a favor generado automáticamente por sobrepago');
            $saldoFavor->setPagoOrigen($pago);
            if ($pago->getCurso()) {
                $saldoFavor->setCurso($pago->getCurso());
            }
            $this->entityManager->persist($saldoFavor);
        }

        $this->entityManager->flush();

        return [
            'aplicaciones' => $aplicaciones,
            'montoRestante' => $montoRestante,
            'pago' => $pago
        ];
    }

    /**
     * Aplica un pago a una deuda específica
     * 
     * @param AlumnosPagos $pago
     * @param DeudaAlumno $deuda
     * @param float $montoDisponible Monto disponible del pago para aplicar
     * @return PagoAplicacion|null La aplicación creada, o null si no se pudo aplicar
     */
    private function aplicarPagoADeuda(
        AlumnosPagos $pago,
        DeudaAlumno $deuda,
        float $montoDisponible,
        bool $cerrarDeudaSiMontoMenor = false
    ): ?PagoAplicacion {
        // Calcular cuánto se puede aplicar
        $montoPendiente = $deuda->getMontoPendiente();
        
        if ($montoPendiente <= 0) {
            return null; // La deuda ya está pagada
        }

        // Aplicar el menor entre el monto disponible y el monto pendiente
        $montoAplicar = min($montoDisponible, $montoPendiente);

        if ($montoAplicar <= 0) {
            return null;
        }

        // Crear la aplicación
        $aplicacion = new PagoAplicacion();
        $aplicacion->setPago($pago);
        $aplicacion->setDeuda($deuda);
        $aplicacion->setMontoAplicado($montoAplicar);
        $instituto = $pago->getAlumno()->getInstituto();
        $aplicacion->setFechaAplicacion($this->institutoTimezoneService->getNowForInstituto($instituto));

        // Política: si se paga menos que lo pendiente, ajustamos el total de la deuda al monto efectivamente pagado
        // para que quede cancelada (no permitimos dejar deudas parcialmente pagadas).
        if ($cerrarDeudaSiMontoMenor && $montoAplicar < $montoPendiente) {
            $montoPagadoAcumulado = $deuda->getMontoPagado() + $montoAplicar;
            $deuda->setMonto($montoPagadoAcumulado);
            $deuda->setInteres(0);
        }

        $this->entityManager->persist($aplicacion);

        return $aplicacion;
    }

    /**
     * Busca la deuda específica para el mes/año/curso del pago
     */
    private function buscarDeudaEspecifica(AlumnosPagos $pago): ?DeudaAlumno
    {
        return $this->entityManager->getRepository(DeudaAlumno::class)
            ->findOneBy([
                'alumno' => $pago->getAlumno(),
                'curso' => $pago->getCurso(),
                'mes' => $pago->getMes(),
                'ano' => $pago->getAno()
            ]);
    }

    /**
     * Crea una deuda para un pago adelantado
     */
    private function crearDeudaParaPagoAdelantado(AlumnosPagos $pago): ?DeudaAlumno
    {
        $alumno = $pago->getAlumno();
        $curso = $pago->getCurso();
        $mes = $pago->getMes();
        $ano = $pago->getAno();

        // Buscar el historial del curso
        $historico = $this->entityManager->getRepository(\App\Entity\AlumnoCursoHistorico::class)
            ->findOneBy([
                'alumno' => $alumno,
                'curso' => $curso,
                'activo' => true
            ]);

        if (!$historico) {
            return null;
        }

        // Validar límite de meses adelantados según si el curso tiene fecha fin
        $instituto = $alumno->getInstituto();
        $fechaActual = $this->institutoTimezoneService->getNowForInstituto($instituto);
        $fechaPago = new \DateTime(sprintf('%d-%02d-01', $ano, $mes));
        
        $fechaFinCurso = $curso->getFechaFin();
        if ($fechaFinCurso) {
            // Si el curso tiene fecha fin, validar que no sea posterior al fin del curso
            $fechaFinCursoPrimerDia = clone $fechaFinCurso;
            $fechaFinCursoPrimerDia->modify('first day of this month');
            if ($fechaPago > $fechaFinCursoPrimerDia) {
                throw new \InvalidArgumentException('No se pueden registrar pagos posteriores a la fecha de fin del curso (' . $fechaFinCurso->format('d/m/Y') . ').');
            }
        } else {
            // Si no tiene fecha fin, limitar a 12 meses adelante
            $diferencia = $fechaPago->diff($fechaActual);
            $mesesAdelante = ($diferencia->y * 12) + $diferencia->m;
            
            if ($mesesAdelante > 12) {
                throw new \InvalidArgumentException('No se pueden registrar pagos más de 12 meses adelante para cursos sin fecha de fin definida.');
            }
        }

        // Crear la deuda con precio del histórico (no del curso actual)
        $precioMensual = $historico->getPrecioMensual() ?? $curso->getPrecio();

        $deuda = new DeudaAlumno();
        $deuda->setAlumno($alumno);
        $deuda->setCurso($curso);
        $deuda->setCursoHistorico($historico);
        $deuda->setMes($mes);
        $deuda->setAno($ano);
        $deuda->setMonto($precioMensual);
        $deuda->setInstituto($alumno->getInstituto());

        $this->entityManager->persist($deuda);
        $this->entityManager->flush();

        return $deuda;
    }

    /**
     * Obtiene las deudas pendientes de un alumno para un curso (ordenadas por fecha)
     */
    private function obtenerDeudasPendientes(Alumno $alumno, ?\App\Entity\Curso $curso = null): array
    {
        $criteria = [
            'alumno' => $alumno
        ];

        if ($curso) {
            $criteria['curso'] = $curso;
        }

        $deudas = $this->entityManager->getRepository(DeudaAlumno::class)
            ->findBy($criteria, ['ano' => 'ASC', 'mes' => 'ASC']);

        // Filtrar solo las que tienen monto pendiente
        return array_filter($deudas, function(DeudaAlumno $deuda) {
            return $deuda->getMontoPendiente() > 0;
        });
    }

    /**
     * Aplica un pago existente a deudas específicas
     * Útil para aplicar saldo restante de un pago anterior
     */
    public function aplicarPagoRestante(AlumnosPagos $pago, array $deudasIds): array
    {
        $montoRestante = $pago->getMontoRestante() ?? $pago->calcularMontoRestante();
        
        if ($montoRestante <= 0) {
            return [
                'aplicaciones' => [],
                'montoRestante' => 0,
                'mensaje' => 'El pago no tiene saldo disponible'
            ];
        }

        $deudas = $this->entityManager->getRepository(DeudaAlumno::class)
            ->findBy(['id' => $deudasIds]);
        
        $aplicaciones = [];
        
        foreach ($deudas as $deuda) {
            if ($montoRestante <= 0) {
                break;
            }
            
            $aplicacion = $this->aplicarPagoADeuda($pago, $deuda, $montoRestante, true);
            if ($aplicacion) {
                $aplicaciones[] = $aplicacion;
                $montoRestante -= $aplicacion->getMontoAplicado();
            }
        }

        $pago->setMontoRestante($montoRestante);
        $this->entityManager->flush();

        return [
            'aplicaciones' => $aplicaciones,
            'montoRestante' => $montoRestante
        ];
    }

    /**
     * Asocia el historial del curso al pago
     */
    private function asociarHistorial(AlumnosPagos $pago): void
    {
        $alumno = $pago->getAlumno();
        $curso = $pago->getCurso();
        
        if ($pago->getMes() === null || $pago->getAno() === null) {
            return;
        }
        
        $fechaPago = new \DateTime(sprintf('%d-%02d-01', $pago->getAno(), $pago->getMes()));
        
        // Buscar historial existente
        // Usar fechaAlta y fechaBaja en lugar de fechaInicio y fechaFin
        $historico = $this->entityManager->getRepository(\App\Entity\AlumnoCursoHistorico::class)
            ->createQueryBuilder('h')
            ->where('h.alumno = :alumno')
            ->andWhere('h.curso = :curso')
            ->andWhere('h.activo = :activo')
            ->andWhere('h.fechaAlta <= :fecha')
            ->andWhere('(h.fechaBaja IS NULL OR h.fechaBaja >= :fecha)')
            ->setParameter('alumno', $alumno)
            ->setParameter('curso', $curso)
            ->setParameter('activo', true)
            ->setParameter('fecha', $fechaPago)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
        
        if (!$historico) {
            // Si no existe, crear uno nuevo usando HistorialCursosService
            $historico = $this->historialCursosService->inscribirAlumnoEnCurso($alumno, $curso);
            
            // Si aún no se pudo crear el historial, lanzar excepción
            if (!$historico) {
                throw new \RuntimeException(
                    sprintf(
                        'No se pudo crear o encontrar el historial del curso para el alumno %s y el curso %s.',
                        $alumno->getNombreApellido(),
                        $curso->getNombre()
                    )
                );
            }
        }
        
        $pago->setCursoHistorico($historico);
    }
}
