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
 * Comando para generar las deudas mensuales de todos los alumnos activos
 * Debe ejecutarse automáticamente el primer día de cada mes mediante cron
 * 
 * Ejemplo de configuración cron:
 * 0 1 1 * * php /ruta/a/proyecto/bin/console app:generar-deudas-mensuales
 */
class GenerarDeudasMensualesCommand extends Command
{
    protected static $defaultName = 'app:generar-deudas-mensuales';
    protected static $defaultDescription = 'Genera las deudas faltantes desde el inicio del curso hasta el mes actual para todos los alumnos activos';

    private DeudaService $deudaService;
    private EntityManagerInterface $entityManager;

    public function __construct(
        DeudaService $deudaService,
        EntityManagerInterface $entityManager
    ) {
        parent::__construct();
        $this->deudaService = $deudaService;
        $this->entityManager = $entityManager;
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

        $io->section('Generando deudas faltantes desde el inicio de los cursos hasta el mes actual...');
        
        try {
            // Generar deudas (pasando el flag dryRun)
            $estadisticas = $this->deudaService->generarDeudasMesActual($instituto, $dryRun);
            
            if ($dryRun) {
                $io->warning('Cambios no guardados (modo dry-run)');
            }
            
            // Mostrar resultados
            $io->success('Proceso completado exitosamente');
            
            $io->table(
                ['Métrica', 'Cantidad'],
                [
                    ['Alumnos procesados', $estadisticas['alumnosProcesados']],
                    ['Deudas creadas', $estadisticas['deudasCreadas']],
                    ['Deudas omitidas (ya existían)', $estadisticas['deudasOmitidas']],
                    ['Errores', count($estadisticas['errores'])]
                ]
            );
            
            // Mostrar errores si los hay
            if (!empty($estadisticas['errores'])) {
                $io->section('Errores encontrados:');
                foreach ($estadisticas['errores'] as $error) {
                    $io->error(sprintf(
                        "Alumno: %s | Curso: %s | Error: %s",
                        $error['alumno'],
                        $error['curso'],
                        $error['error']
                    ));
                }
            }
            
            // Mensajes adicionales
            if ($estadisticas['deudasCreadas'] === 0 && $estadisticas['deudasOmitidas'] > 0) {
                $io->note('Todas las deudas del mes actual ya habían sido generadas previamente.');
            }
            
            if ($estadisticas['alumnosProcesados'] === 0) {
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
