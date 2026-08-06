<?php

namespace App\Command;

use App\Entity\Instituto;
use App\Service\CuotaInscripcionService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Genera la cuota de inscripción anual de los alumnos ya inscriptos.
 *
 * Los alumnos que se inscriben de acá en adelante la reciben solos, porque
 * HistorialCursosService la genera al inscribir. Este comando es para los que ya estaban:
 * el instituto que recién activa la opción, y el arranque de cada año nuevo.
 *
 * Es idempotente: al alumno que ya tiene la cuota del año no se le crea otra, así que se
 * puede correr las veces que haga falta.
 */
class GenerarCuotasInscripcionCommand extends Command
{
    protected static $defaultName = 'app:generar-cuotas-inscripcion';
    protected static $defaultDescription = 'Genera la cuota de inscripción anual de los alumnos activos';

    private EntityManagerInterface $entityManager;
    private CuotaInscripcionService $cuotaInscripcionService;

    public function __construct(
        EntityManagerInterface $entityManager,
        CuotaInscripcionService $cuotaInscripcionService
    ) {
        parent::__construct();
        $this->entityManager = $entityManager;
        $this->cuotaInscripcionService = $cuotaInscripcionService;
    }

    protected function configure(): void
    {
        $this
            ->addOption('ano', null, InputOption::VALUE_REQUIRED, 'Año a generar. Por defecto el actual.')
            ->addOption('instituto', null, InputOption::VALUE_REQUIRED, 'Id de un instituto puntual. Por defecto todos.')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Muestra qué haría sin escribir nada.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Cuota de inscripción anual');

        $ano = $input->getOption('ano') !== null ? (int) $input->getOption('ano') : null;
        $institutoId = $input->getOption('instituto');
        $dryRun = (bool) $input->getOption('dry-run');

        $repo = $this->entityManager->getRepository(Instituto::class);
        $institutos = $institutoId !== null
            ? array_filter([$repo->find((int) $institutoId)])
            : $repo->findAll();

        if (!$institutos) {
            $io->error('No se encontró ningún instituto.');

            return Command::FAILURE;
        }

        $filas = [];
        $totalGeneradas = 0;

        foreach ($institutos as $instituto) {
            $configuracion = $instituto->getConfiguracion();

            if (!$configuracion || !$configuracion->getCobrarCuotaInscripcionAnual()) {
                $filas[] = [$instituto->getId(), $instituto->getNombre(), '—', '—', 'no la tiene activada'];
                continue;
            }

            if ($dryRun) {
                // Sin escribir: se cuenta a cuántos alumnos activos les falta la cuota.
                $alumnos = $this->entityManager->getRepository(\App\Entity\Alumno::class)
                    ->findBy(['instituto' => $instituto, 'activo' => true]);

                $anoObjetivo = $ano ?? (int) date('Y');
                $faltantes = 0;
                foreach ($alumnos as $alumno) {
                    if ($this->cuotaInscripcionService->getCuotaInscripcionPorAno($alumno, $anoObjetivo) === null) {
                        $faltantes++;
                    }
                }

                $filas[] = [$instituto->getId(), $instituto->getNombre(), $faltantes, 0, 'simulado'];
                continue;
            }

            $resultado = $this->cuotaInscripcionService->generarCuotasInscripcionParaInstituto($instituto, $ano);

            $filas[] = [
                $instituto->getId(),
                $instituto->getNombre(),
                $resultado['generadas'],
                $resultado['omitidas'],
                $resultado['error'] ?? 'ok',
            ];

            $totalGeneradas += $resultado['generadas'];
        }

        $io->table(['Id', 'Instituto', 'Generadas', 'Ya tenían', 'Detalle'], $filas);

        if ($dryRun) {
            $io->note('Simulación: no se escribió nada. La columna "Generadas" es lo que se crearía.');

            return Command::SUCCESS;
        }

        $io->success(sprintf('Se generaron %d cuotas de inscripción.', $totalGeneradas));

        return Command::SUCCESS;
    }
}
