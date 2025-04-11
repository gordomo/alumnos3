<?php

namespace App\Command;

use App\Entity\Alumno;
use App\Entity\AlumnoCursoHistorico;
use App\Entity\AlumnosPagos;
use App\Entity\Curso;
use App\Entity\DeudaAlumno;
use App\Service\DeudaService;
use App\Service\HistorialCursosService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:generar-deudas',
    description: 'Genera registros de deudas para todos los alumnos activos',
)]
class GenerarDeudasCommand extends Command
{
    private EntityManagerInterface $entityManager;
    private HistorialCursosService $historialService;
    private DeudaService $deudaService;

    public function __construct(
        EntityManagerInterface $entityManager,
        HistorialCursosService $historialService,
        DeudaService $deudaService
    ) {
        parent::__construct();
        $this->entityManager = $entityManager;
        $this->historialService = $historialService;
        $this->deudaService = $deudaService;
    }

    protected function configure(): void
    {
        $this
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Forzar la regeneración de todas las deudas, incluso si ya existen')
            ->addOption('sincronizar-pagos', 's', InputOption::VALUE_NONE, 'Sincronizar con pagos existentes')
            ->addOption('verificar-faltantes', 'v', InputOption::VALUE_NONE, 'Verificar y generar deudas para alumnos que no tienen deudas generadas');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $force = $input->getOption('force');
        $sincronizarPagos = $input->getOption('sincronizar-pagos');
        $verificarFaltantes = $input->getOption('verificar-faltantes');

        $io->title('Generando registros de deudas para alumnos activos');

        // Obtener todos los alumnos activos
        $alumnos = $this->entityManager->getRepository(Alumno::class)
            ->findBy(['activo' => true]);

        $totalAlumnos = count($alumnos);
        $io->progressStart($totalAlumnos);

        $totalDeudas = 0;
        $totalDeudasActualizadas = 0;
        $totalDeudasFaltantes = 0;

        foreach ($alumnos as $alumno) {
            // Para cada alumno, obtener sus cursos históricos
            $historicos = $alumno->getCursosHistoricos();

            foreach ($historicos as $historico) {
                if (!$historico->isActivo()) {
                    continue;
                }

                $curso = $historico->getCurso();
                $fechaInicio = $historico->getFechaInicio();
                $fechaFin = $historico->getFechaFin();

                if (!$fechaInicio) {
                    continue;
                }

                // Si no hay fecha fin, usar fecha actual
                if (!$fechaFin) {
                    $fechaFin = new \DateTime();
                }

                // Generar deudas para el periodo - usar el método local que no depende de servicios externos
                $deudas = $this->generarDeudasParaPeriodo(
                    $alumno,
                    $curso,
                    $historico,
                    $fechaInicio,
                    $fechaFin,
                    $force
                );

                $totalDeudas += $deudas['creadas'];
                $totalDeudasActualizadas += $deudas['actualizadas'];
            }

            // Si se solicitó verificar faltantes, comprobar los cursos actuales
            if ($verificarFaltantes) {
                foreach ($alumno->getCurso() as $curso) {
                    // Verificar si el alumno tiene deudas generadas para este curso
                    $deudasFaltantes = $this->verificarYGenerarDeudasFaltantes($alumno, $curso);
                    $totalDeudasFaltantes += $deudasFaltantes;
                }
            }

            $io->progressAdvance();
        }

        $io->progressFinish();

        // Si se solicitó sincronizar con pagos existentes
        if ($sincronizarPagos) {
            $io->section('Sincronizando con pagos existentes');
            $pagosSync = $this->sincronizarConPagosExistentes();
            $io->success("Se sincronizaron {$pagosSync} pagos con deudas existentes");
        }

        $mensaje = "Se generaron {$totalDeudas} nuevos registros de deudas y se actualizaron {$totalDeudasActualizadas}";
        
        if ($verificarFaltantes) {
            $mensaje .= ". Además, se generaron {$totalDeudasFaltantes} deudas faltantes para alumnos ya inscritos.";
        }
        
        $io->success($mensaje);

        return Command::SUCCESS;
    }

    private function generarDeudasParaPeriodo(
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
                    // Actualizar deuda existente si se forzó la operación
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

    private function sincronizarConPagosExistentes(): int
    {
        $pagos = $this->entityManager->getRepository(AlumnosPagos::class)->findAll();
        $totalSincronizados = 0;

        foreach ($pagos as $pago) {
            $alumno = $pago->getAlumno();
            $curso = $pago->getCurso();
            
            if (!$alumno || !$curso) {
                continue;
            }

            $mes = $pago->getMes();
            $ano = $pago->getAno();

            // Buscar si existe una deuda correspondiente a este pago
            $deuda = $this->entityManager->getRepository(DeudaAlumno::class)
                ->findOneBy([
                    'alumno' => $alumno,
                    'curso' => $curso,
                    'mes' => $mes,
                    'ano' => $ano
                ]);

            if ($deuda) {
                // Actualizar la deuda existente
                $deuda->setPagado(true);
                $deuda->setPago($pago);
                $deuda->setFechaPago($pago->getFecha() ?? new \DateTime());
                $deuda->setMonto($pago->getMonto());
                
                // Buscar el curso histórico asociado
                $historico = $this->entityManager->getRepository(AlumnoCursoHistorico::class)
                    ->findOneBy([
                        'alumno' => $alumno,
                        'curso' => $curso
                    ]);
                
                if ($historico) {
                    $deuda->setCursoHistorico($historico);
                }
                
                $totalSincronizados++;
            } else {
                // Crear una nueva deuda ya pagada
                $deuda = new DeudaAlumno();
                $deuda->setAlumno($alumno);
                $deuda->setCurso($curso);
                $deuda->setMes($mes);
                $deuda->setAno($ano);
                $deuda->setPagado(true);
                $deuda->setPago($pago);
                $deuda->setFechaPago($pago->getFecha() ?? new \DateTime());
                $deuda->setMonto($pago->getMonto());
                $deuda->setInstituto($alumno->getInstituto());
                
                // Buscar el curso histórico asociado
                $historico = $this->entityManager->getRepository(AlumnoCursoHistorico::class)
                    ->findOneBy([
                        'alumno' => $alumno,
                        'curso' => $curso
                    ]);
                
                if ($historico) {
                    $deuda->setCursoHistorico($historico);
                }
                
                $this->entityManager->persist($deuda);
                $totalSincronizados++;
            }
        }

        $this->entityManager->flush();
        return $totalSincronizados;
    }

    /**
     * Verifica y genera deudas faltantes para un alumno y curso.
     * Método interno del comando para evitar dependencias circulares.
     */
    private function verificarYGenerarDeudasFaltantes(Alumno $alumno, Curso $curso): int
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
        
        // Obtener el registro histórico
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
        
        // Generar deudas hasta fin de año
        $fechaActual = new \DateTime();
        $finDeAno = new \DateTime($fechaActual->format('Y') . '-12-31');
        
        $resultado = $this->generarDeudasParaPeriodo(
            $alumno,
            $curso,
            $historico,
            $fechaActual,
            $finDeAno,
            false // No forzar sobrescritura de deudas existentes
        );
        
        return $resultado['creadas'];
    }
} 