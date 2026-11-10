<?php
/** MySQL: tablas temporales de esta conexión; no altera profesores ni cursos reales. */
require dirname(__DIR__) . '/vendor/autoload.php';
(new Symfony\Component\Dotenv\Dotenv())->bootEnv(dirname(__DIR__) . '/.env');
$kernel = new App\Kernel($_SERVER['APP_ENV'], false); $kernel->boot();
$em = $kernel->getContainer()->get('doctrine')->getManager(); $db = $em->getConnection();
$tablas = [];
function comprobarProfesor(bool $ok, string $msg): void {
    if (!$ok) throw new RuntimeException($msg);
    echo 'OK: ' . $msg . PHP_EOL;
}
try {
    foreach (['profesor', 'curso', 'curso_profesor', 'profesor_curso_pago'] as $tabla) {
        $definicion = $db->fetchAssociative('SHOW CREATE TABLE ' . $tabla)['Create Table'];
        $definicion = preg_replace('/^CREATE TABLE/', 'CREATE TEMPORARY TABLE', $definicion);
        // MySQL no admite claves foráneas en tablas temporales.
        $definicion = preg_replace('/^  CONSTRAINT[^\n]*\n/m', '', $definicion);
        $definicion = preg_replace('/,\n\) ENGINE/', "\n) ENGINE", $definicion);
        $db->executeStatement($definicion);
        $tablas[] = $tabla;
    }
    foreach ([[11, 991, 'Ana', 'Zeta'], [12, 991, 'Bruno', 'Beta'], [13, 991, 'Carla', 'Alfa'], [21, 992, 'Ana', 'Ajena']] as [$id, $instituto, $nombre, $apellido]) {
        $db->insert('profesor', ['id' => $id, 'instituto_id' => $instituto, 'nombre' => $nombre, 'apellido' => $apellido, 'dni' => (string) $id, 'email' => $id . '@example.test']);
    }
    foreach ([[101, 991], [102, 991], [201, 992]] as [$id, $instituto]) {
        $db->insert('curso', ['id' => $id, 'instituto_id' => $instituto, 'nombre' => 'Curso de prueba', 'dias' => '[]', 'horario_inicio' => '08:00:00', 'horario_fin' => '09:00:00', 'fecha_inicio' => '2026-01-01', 'fecha_fin' => '2026-12-31', 'duracion' => 1, 'disabled' => 0, 'precio' => '100']);
    }
    foreach ([[101, 11], [102, 11], [102, 12], [201, 21], [201, 12]] as [$curso, $profesor]) {
        $db->insert('curso_profesor', ['curso_id' => $curso, 'profesor_id' => $profesor]);
    }
    $inst = new App\Entity\Instituto();
    (new ReflectionProperty(App\Entity\Instituto::class, 'id'))->setValue($inst, 991);
    $controller = (new ReflectionClass(App\Controller\ProfesorController::class))->newInstanceWithoutConstructor();
    $metodo = new ReflectionMethod($controller, 'createQuery'); $metodo->setAccessible(true);
    $repo = $em->getRepository(App\Entity\Profesor::class);
    $buscar = static function (int $curso = 0, string $busqueda = '', string $sort = 'apellido', string $order = 'asc') use ($metodo, $controller, $repo, $inst): array {
        $qb = $metodo->invoke($controller, $repo, $inst, $sort, $order, $busqueda, $curso);
        return array_map('intval', array_column($qb->select('p.id')->getQuery()->getScalarResult(), 'id'));
    };
    comprobarProfesor($buscar() === [13, 12, 11], 'Listado incluye profesores sin curso y excluye otra institución');
    comprobarProfesor($buscar(101) === [11], 'Filtra por curso de la institución');
    comprobarProfesor($buscar(102) === [12, 11], 'Incluye todos los profesores del curso sin duplicarlos');
    comprobarProfesor($buscar(201) === [], 'Curso de otra institución no revela profesores, incluso con una asociación cruzada');
    comprobarProfesor($buscar(101, 'Bruno') === [], 'Búsqueda y curso se combinan');
    comprobarProfesor($buscar(102, 'Bruno') === [12], 'Búsqueda encuentra al profesor dentro del curso');
    comprobarProfesor($buscar(102, '', 'nombre', 'desc') === [12, 11], 'Mantiene el orden seleccionado al filtrar');
    foreach ([[1, 991, 11, 101], [2, 991, 11, 201], [3, 992, 11, 102], [4, 991, 21, 101], [5, 991, 12, 102]] as [$id, $instituto, $profesor, $curso]) {
        $db->insert('profesor_curso_pago', ['id' => $id, 'instituto_id' => $instituto, 'profesor_id' => $profesor, 'curso_id' => $curso, 'modalidad' => 'por_hora', 'activo' => 1, 'updated_at' => '2026-11-10 12:00:00']);
    }
    $reglas = $em->getRepository(App\Entity\ProfesorCursoPago::class)->findParaListado($inst, [
        $em->getReference(App\Entity\Profesor::class, 11), $em->getReference(App\Entity\Profesor::class, 12),
    ]);
    comprobarProfesor(array_keys($reglas[11]) === [101] && array_keys($reglas[12]) === [102], 'Resumen de pagos excluye reglas con institución, profesor o curso ajenos');
    comprobarProfesor($em->getRepository(App\Entity\ProfesorCursoPago::class)->findParaListado($inst, []) === [], 'Página vacía no consulta reglas de otros profesores');

} finally {
    foreach (array_reverse($tablas) as $tabla) $db->executeStatement('DROP TEMPORARY TABLE ' . $tabla);
    $kernel->shutdown();
}
