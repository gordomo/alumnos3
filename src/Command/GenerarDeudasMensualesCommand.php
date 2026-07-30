<?php

namespace App\Command;

use App\Service\DeudaService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Comando para sincronizar deudas calculadas on-demand con la tabla deuda_alumno
 * Esto asegura que el sistema de pagos y notificaciones funcione correctamente
 * 
 * Ejemplo de configuración cron:
 * 0 1 * * * php /ruta/a/proyecto/bin/console app:generar-deudas-mensuales
 */
class GenerarDeudasMensualesCommand extends Command
{
    protected static $defaultName = 'app:generar-deudas-mensuales';
    protected static $defaultDescription = 'Sincroniza las deudas calculadas on-demand con la tabla deuda_alumno';

    private DeudaService $deudaService;
    private EntityManagerInterface $entityManager;
    private $deudaCalculator;

    public function __construct(
        DeudaService $deudaService,
        EntityManagerInterface $entityManager,
        \App\Service\DeudaCalculatorService $deudaCalculator
    ) {
        parent::__construct();
        $this->deudaService = $deudaService;
        $this->entityManager = $entityManager;
        $this->deudaCalculator = $deudaCalculator;
    }

    protected function configure(): void
    {
        $this
            ->setDescription(self::$defaultDescription)
            ->setHelp('Este comando genera las deudas faltantes desde el inicio del curso hasta el mes actual para todos los alumnos activos. Si un curso comenzó en meses anteriores y no tiene deudas generadas, las creará automáticamente.')
            ->addOption(
                'instituto-id',
                'i',
                InputOption::VALUE_OPTIONAL,
                'ID del instituto específico para generar deudas (opcional)'
            )
            ->addOption(
                'dry-run',
                'd',
                InputOption::VALUE_NONE,
                'Ejecutar en modo prueba sin guardar cambios'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $institutoId = $input->getOption('instituto-id');
        $dryRun = $input->getOption('dry-run');

        $fechaActual = new \DateTime();
        $mesActual = $fechaActual->format('F Y'); // Ej: "January 2026"
        
        if ($dryRun) {
            $io->warning('Ejecutando en modo DRY-RUN - No se guardarán cambios');
        }

        $io->title('Generación de Deudas Mensuales - ' . $mesActual);

        $instituto = null;
        if ($institutoId) {
            $instituto = $this->entityManager->getRepository(\App\Entity\Instituto::class)->find($institutoId);
            
            if (!$instituto) {
                $io->error("No se encontró el instituto con ID: $institutoId");
                return Command::FAILURE;
            }
            
            $io->info("Procesando solo el instituto: {$instituto->getNombre()}");
        } else {
            $io->info("Procesando todos los institutos");
        }

        $io->section('Sincronizando deudas calculadas on-demand con la tabla deuda_alumno...');
        
        try {
            // Obtener todos los alumnos activos
            $qb = $this->entityManager->getRepository(\App\Entity\Alumno::class)
                ->createQueryBuilder('a')
                ->where('a.activo = :activo')
                ->setParameter('activo', true);
            
            if ($instituto) {
                $qb->andWhere('a.instituto = :instituto')
                   ->setParameter('instituto', $instituto);
            }
            
            $alumnos = $qb->getQuery()->getResult();
            
            $alumnosProcesados = 0;
            $deudasSincronizadas = 0;
            $errores = [];
            
            foreach ($alumnos as $alumno) {
                try {
                    // El sistema de deudas es 100% on-demand: no hay nada que persistir
                    // acá (las filas en deuda_alumno las crea PagoService al cobrar).
                    // Antes la corrida real llamaba a sincronizarDeudasConTabla(), que
                    // está deprecada y devuelve [] siempre, así que informaba 0 deudas
                    // mientras --dry-run informaba el número real. Ahora ambas cuentan
                    // lo mismo y el reporte deja de contradecirse.
                    $deudasCalculadas = $this->deudaCalculator->calcularDeudasAlumno($alumno);
                    $deudasSincronizadas += count($deudasCalculadas);
                    $alumnosProcesados++;
                } catch (\Exception $e) {
                    $errores[] = [
                        'alumno' => $alumno->getNombreApellido(),
                        'error' => $e->getMessage()
                    ];
                }
            }
            
            if ($dryRun) {
                $io->warning('Modo dry-run');
            }
            $io->note('Las deudas se calculan on-demand: este comando no persiste filas, solo informa el estado actual.');

            // Mostrar resultados
            $io->success('Proceso completado exitosamente');

            $io->table(
                ['Métrica', 'Cantidad'],
                [
                    ['Alumnos procesados', $alumnosProcesados],
                    ['Deudas pendientes calculadas', $deudasSincronizadas],
                    ['Errores', count($errores)]
                ]
            );
            
            // Mostrar errores si los hay
            if (!empty($errores)) {
                $io->section('Errores encontrados:');
                foreach ($errores as $error) {
                    $io->error(sprintf(
                        "Alumno: %s | Error: %s",
                        $error['alumno'],
                        $error['error']
                    ));
                }
            }
            
            if ($alumnosProcesados === 0) {
                $io->warning('No se encontraron alumnos activos para procesar.');
            }
            
            return Command::SUCCESS;
            
        } catch (\Exception $e) {
            $io->error('Error al generar deudas: ' . $e->getMessage());
            $io->text('Trace: ' . $e->getTraceAsString());
            return Command::FAILURE;
        }
    }
}
