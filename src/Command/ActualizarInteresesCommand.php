<?php

namespace App\Command;

use App\Entity\DeudaAlumno;
use App\Repository\DeudaAlumnoRepository;
use App\Service\InstitutoTimezoneService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

class ActualizarInteresesCommand extends Command
{
    protected static $defaultName = 'app:actualizar-intereses';
    protected static $defaultDescription = 'Sincroniza las deudas con intereses calculados on-demand';

    private EntityManagerInterface $entityManager;
    private DeudaAlumnoRepository $deudaRepository;
    private InstitutoTimezoneService $timezoneService;
    private $deudaCalculator;

    public function __construct(
        EntityManagerInterface $entityManager,
        DeudaAlumnoRepository $deudaRepository,
        InstitutoTimezoneService $timezoneService,
        \App\Service\DeudaCalculatorService $deudaCalculator
    ) {
        parent::__construct();
        $this->entityManager = $entityManager;
        $this->deudaRepository = $deudaRepository;
        $this->timezoneService = $timezoneService;
        $this->deudaCalculator = $deudaCalculator;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        
        $io->title('Sincronizando deudas con intereses calculados on-demand');

        // Obtener todos los alumnos activos
        $alumnos = $this->entityManager->getRepository(\App\Entity\Alumno::class)
            ->findBy(['activo' => true]);

        $alumnosProcesados = 0;
        $deudasSincronizadas = 0;

        foreach ($alumnos as $alumno) {
            try {
                $deudasEntidades = $this->deudaCalculator->sincronizarDeudasConTabla($alumno);
                $deudasSincronizadas += count($deudasEntidades);
                $alumnosProcesados++;
            } catch (\Exception $e) {
                $io->error("Error procesando alumno {$alumno->getNombreApellido()}: " . $e->getMessage());
            }
        }

        $io->success("Se sincronizaron $deudasSincronizadas deudas para $alumnosProcesados alumnos.");
        $io->note("Los intereses se calculan automáticamente según la fecha actual y los vencimientos configurados.");

        return Command::SUCCESS;
    }
}
