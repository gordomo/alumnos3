<?php

namespace App\Command;

use App\Entity\Instituto;
use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Diagnóstico de consistencia de datos. NO ESCRIBE NADA: solo consulta e informa.
 *
 * Busca situaciones que el sistema no puede detectar solo y que tienen consecuencias en plata
 * o en lo que se le cobra a una familia. Cada hallazgo trae qué hacer al respecto.
 */
class DiagnosticoDatosCommand extends Command
{
    protected static $defaultName = 'app:diagnostico-datos';
    protected static $defaultDescription = 'Revisa la consistencia de inscripciones, precios y deudas (solo lectura)';

    public function __construct(private Connection $conexion)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('instituto', null, InputOption::VALUE_REQUIRED, 'Id de un instituto puntual. Por defecto todos.')
            ->addOption('detalle', null, InputOption::VALUE_NONE, 'Lista las filas de cada hallazgo, no solo el conteo.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Diagnóstico de datos');
        $io->text('Este comando no modifica nada: solo consulta.');

        $institutoId = $input->getOption('instituto') !== null ? (int) $input->getOption('instituto') : null;
        $detalle = (bool) $input->getOption('detalle');
        $filtro = $institutoId !== null ? ' AND a.instituto_id = :instituto' : '';
        $params = $institutoId !== null ? ['instituto' => $institutoId] : [];

        $hallazgos = 0;

        // 1. Alumnos dados de baja a los que se les sigue generando deuda.
        //
        // La deuda se calcula sobre las inscripciones activas, no sobre el flag del alumno. Un
        // alumno inactivo con una inscripción abierta sigue acumulando cuotas todos los meses.
        $sql = "SELECT a.instituto_id, a.id AS alumno_id, CONCAT(a.apellido, ', ', a.nombre) AS alumno,
                       c.nombre AS curso, h.id AS inscripcion, h.fecha_alta
                FROM alumno_curso_historico h
                JOIN alumno a ON a.id = h.alumno_id
                JOIN curso c ON c.id = h.curso_id
                WHERE h.activo = 1 AND a.activo = 0" . $filtro . "
                ORDER BY a.instituto_id, a.apellido";
        $hallazgos += $this->informar(
            $io,
            'Alumn@s inactiv@s con la inscripción todavía abierta',
            'Se les sigue generando deuda cada mes aunque estén dados de baja. Solución: entrar a su ficha y darlos de baja de nuevo, que ahora cierra la inscripción correctamente.',
            $this->conexion->fetchAllAssociative($sql, $params),
            $detalle
        );

        // 2. Inscripciones a un precio distinto del precio actual del curso.
        //
        // El precio se copia a la inscripción, así que un aumento en el curso no llega a los ya
        // inscriptos hasta que se aplica explícitamente.
        $sql = "SELECT a.instituto_id, c.id AS curso_id, c.nombre AS curso,
                       c.precio AS precio_curso, h.precio_mensual AS precio_inscripcion,
                       COUNT(*) AS alumnos
                FROM alumno_curso_historico h
                JOIN curso c ON c.id = h.curso_id
                JOIN alumno a ON a.id = h.alumno_id
                WHERE h.activo = 1
                  AND h.precio_mensual IS NOT NULL
                  AND ABS(h.precio_mensual - c.precio) > 0.001" . $filtro . "
                GROUP BY a.instituto_id, c.id, c.nombre, c.precio, h.precio_mensual
                ORDER BY alumnos DESC";
        $desfasados = $this->conexion->fetchAllAssociative($sql, $params);
        $hallazgos += $this->informar(
            $io,
            'Inscripciones a un precio distinto del actual del curso',
            'Sus cuotas se siguen calculando al precio viejo. Solución: editar el curso y tildar "Aplicar el precio actual a los inscript@s". Ojo que eso cambia lo que esas familias deben, así que es una decisión del instituto.',
            $desfasados,
            true
        );

        // Cuánta plata hay en juego en cada curso desfasado.
        foreach ($desfasados as $fila) {
            $impagas = $this->conexion->fetchAssociative(
                "SELECT COUNT(*) AS cuotas,
                        COUNT(DISTINCT d.alumno_id) AS alumnos,
                        COALESCE(SUM(d.monto + COALESCE(d.interes, 0)), 0) AS total
                 FROM deuda_alumno d
                 JOIN alumno_curso_historico h
                      ON h.alumno_id = d.alumno_id AND h.curso_id = d.curso_id AND h.activo = 1
                 WHERE d.curso_id = :curso
                   AND d.es_cuota_inscripcion_anual = 0
                   AND NOT EXISTS (SELECT 1 FROM pago_aplicacion pa WHERE pa.deuda_id = d.id)",
                ['curso' => $fila['curso_id']]
            );

            if ((int) $impagas['cuotas'] > 0) {
                $io->text(sprintf(
                    '   %s: %d cuota(s) impaga(s) de %d alumn@(s), hoy por $%s. Al precio actual pasarían a cerca de $%s.',
                    $fila['curso'],
                    $impagas['cuotas'],
                    $impagas['alumnos'],
                    number_format((float) $impagas['total'], 2, ',', '.'),
                    number_format((float) $impagas['cuotas'] * (float) $fila['precio_curso'], 2, ',', '.')
                ));
            }
        }

        // 3. Inscripciones activas duplicadas del mismo alumno al mismo curso.
        $sql = "SELECT a.instituto_id, a.id AS alumno_id, CONCAT(a.apellido, ', ', a.nombre) AS alumno,
                       c.nombre AS curso, COUNT(*) AS inscripciones
                FROM alumno_curso_historico h
                JOIN alumno a ON a.id = h.alumno_id
                JOIN curso c ON c.id = h.curso_id
                WHERE h.activo = 1" . $filtro . "
                GROUP BY a.instituto_id, a.id, a.apellido, a.nombre, c.id, c.nombre
                HAVING COUNT(*) > 1";
        $hallazgos += $this->informar(
            $io,
            'Inscripciones activas duplicadas al mismo curso',
            'Duplica las cuotas que se le generan. Solución: dar de baja una de las dos desde la ficha del alumno.',
            $this->conexion->fetchAllAssociative($sql, $params),
            $detalle
        );

        // 4. Inscripciones activas a cursos deshabilitados.
        $sql = "SELECT a.instituto_id, c.nombre AS curso, COUNT(*) AS alumnos
                FROM alumno_curso_historico h
                JOIN curso c ON c.id = h.curso_id
                JOIN alumno a ON a.id = h.alumno_id
                WHERE h.activo = 1 AND c.disabled = 1" . $filtro . "
                GROUP BY a.instituto_id, c.id, c.nombre";
        $hallazgos += $this->informar(
            $io,
            'Inscripciones activas a cursos deshabilitados',
            'El curso ya no se usa pero los alumnos siguen inscriptos. Revisar si corresponde darlos de baja.',
            $this->conexion->fetchAllAssociative($sql, $params),
            $detalle
        );

        // 5. Desfasaje entre la tabla de unión y las inscripciones.
        //
        // Son dos fuentes para lo mismo. La que manda para generar deuda es la inscripción; la
        // tabla de unión es la que se ve en el modal de cursos. Si difieren, la pantalla y el
        // cálculo dicen cosas distintas.
        $sql = "SELECT a.instituto_id, a.id AS alumno_id, CONCAT(a.apellido, ', ', a.nombre) AS alumno,
                       c.nombre AS curso, 'inscripción abierta sin figurar en sus cursos' AS caso
                FROM alumno_curso_historico h
                JOIN alumno a ON a.id = h.alumno_id
                JOIN curso c ON c.id = h.curso_id
                WHERE h.activo = 1
                  AND NOT EXISTS (SELECT 1 FROM alumno_curso ac WHERE ac.alumno_id = h.alumno_id AND ac.curso_id = h.curso_id)"
                  . $filtro . "
                UNION ALL
                SELECT a.instituto_id, a.id, CONCAT(a.apellido, ', ', a.nombre), c.nombre,
                       'figura en sus cursos sin inscripción abierta'
                FROM alumno_curso ac
                JOIN alumno a ON a.id = ac.alumno_id
                JOIN curso c ON c.id = ac.curso_id
                WHERE NOT EXISTS (SELECT 1 FROM alumno_curso_historico h WHERE h.alumno_id = ac.alumno_id AND h.curso_id = ac.curso_id AND h.activo = 1)"
                  . $filtro;
        $hallazgos += $this->informar(
            $io,
            'Diferencias entre la lista de cursos del alumno y sus inscripciones',
            'El modal de cursos y el cálculo de deudas leen fuentes distintas. Solución: abrir el modal de cursos del alumno y guardar con los cursos correctos, que reescribe las dos.',
            $this->conexion->fetchAllAssociative($sql, $institutoId !== null ? ['instituto' => $institutoId] : []),
            $detalle
        );

        $io->newLine();
        if ($hallazgos === 0) {
            $io->success('Sin hallazgos.');
        } else {
            $io->warning(sprintf('%d chequeo(s) con hallazgos. Nada se modificó.', $hallazgos));
        }

        return Command::SUCCESS;
    }

    /**
     * Informa un chequeo. Devuelve 1 si encontró algo, 0 si no.
     */
    private function informar(SymfonyStyle $io, string $titulo, string $queHacer, array $filas, bool $detalle): int
    {
        $io->newLine();

        if (!$filas) {
            $io->text(sprintf('<info>OK</info>  %s: sin casos.', $titulo));

            return 0;
        }

        $io->text(sprintf('<comment>%d caso(s)</comment>  %s', count($filas), $titulo));
        $io->text('   ' . $queHacer);

        if ($detalle) {
            $io->table(array_keys($filas[0]), array_map('array_values', $filas));
        }

        return 1;
    }
}
