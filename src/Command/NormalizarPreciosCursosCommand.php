<?php

namespace App\Command;

use App\Entity\Curso;
use App\Entity\Instituto;
use App\Service\PrecioCursoService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Deja las inscripciones activas al precio actual de su curso.
 *
 * Es para corregir de una vez los cursos donde ya convivían dos precios, situación que no
 * puede volver a generarse: guardar un curso ahora reconcilia siempre.
 *
 * Usa el mismo PrecioCursoService que la pantalla del curso, así que el recargo por mora se
 * recalcula con la misma regla y las cuotas ya pagadas no se tocan. Arranca en modo simulación:
 * hay que pasar --aplicar para que escriba.
 */
class NormalizarPreciosCursosCommand extends Command
{
    protected static $defaultName = 'app:normalizar-precios-cursos';
    protected static $defaultDescription = 'Aplica el precio actual de cada curso a las cuotas impagas de sus inscript@s';

    public function __construct(
        private EntityManagerInterface $entityManager,
        private PrecioCursoService $precioCursoService
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('aplicar', null, InputOption::VALUE_NONE, 'Escribe los cambios. Sin esta opción solo simula.')
            ->addOption('instituto', null, InputOption::VALUE_REQUIRED, 'Id de un instituto puntual. Por defecto todos.')
            ->addOption('curso', null, InputOption::VALUE_REQUIRED, 'Id de un curso puntual.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Normalizar precios de cursos');

        $aplicar = (bool) $input->getOption('aplicar');
        if (!$aplicar) {
            $io->note('Modo simulación: no se escribe nada. Agregá --aplicar para escribir.');
        }

        $criterios = [];
        if ($input->getOption('curso') !== null) {
            $criterios['id'] = (int) $input->getOption('curso');
        }
        if ($input->getOption('instituto') !== null) {
            $instituto = $this->entityManager->getRepository(Instituto::class)->find((int) $input->getOption('instituto'));
            if (!$instituto) {
                $io->error('No existe ese instituto.');

                return Command::FAILURE;
            }
            $criterios['instituto'] = $instituto;
        }

        $cursos = $this->entityManager->getRepository(Curso::class)->findBy($criterios);

        $filas = [];
        $totalAlumnos = 0;
        $totalCuotas = 0;

        foreach ($cursos as $curso) {
            $previo = $this->precioCursoService->previsualizar($curso);

            if ($previo['alumnos'] === 0) {
                continue;
            }

            if ($aplicar) {
                $resultado = $this->precioCursoService->aplicar($curso);
                $alumnos = $resultado['alumnos'];
                $cuotas = $resultado['cuotas'];
            } else {
                $alumnos = $previo['alumnos'];
                $cuotas = $previo['cuotas'];
            }

            $filas[] = [
                $curso->getInstituto() ? $curso->getInstituto()->getId() : '—',
                $curso->getId(),
                $curso->getNombre(),
                number_format((float) ($previo['precioAnterior'] ?? 0), 2, ',', '.'),
                number_format($previo['precioActual'], 2, ',', '.'),
                $alumnos,
                $cuotas,
            ];

            $totalAlumnos += $alumnos;
            $totalCuotas += $cuotas;
        }

        if (!$filas) {
            $io->success('No hay inscripciones desfasadas: todos los cursos ya están consistentes.');

            return Command::SUCCESS;
        }

        $io->table(
            ['Instituto', 'Curso', 'Nombre', 'Precio viejo', 'Precio actual', 'Alumn@s', 'Cuotas impagas'],
            $filas
        );

        if ($aplicar) {
            $io->success(sprintf(
                'Se normalizaron %d inscripción(es) y %d cuota(s) impaga(s) en %d curso(s).',
                $totalAlumnos,
                $totalCuotas,
                count($filas)
            ));
        } else {
            $io->warning(sprintf(
                'Simulación: se normalizarían %d inscripción(es) y %d cuota(s) impaga(s) en %d curso(s). Nada se modificó.',
                $totalAlumnos,
                $totalCuotas,
                count($filas)
            ));
        }

        return Command::SUCCESS;
    }
}
