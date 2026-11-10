<?php
/** Usa una tabla TEMPORARY de la conexión: no lee ni modifica contadores reales. */
require dirname(__DIR__) . '/vendor/autoload.php';
(new Symfony\Component\Dotenv\Dotenv())->bootEnv(dirname(__DIR__) . '/.env');
$kernel = new App\Kernel($_SERVER['APP_ENV'], false);
$kernel->boot();
$db = $kernel->getContainer()->get('doctrine')->getConnection();
// En MySQL una tabla temporal oculta la persistente solo para esta conexión.
$db->executeStatement('CREATE TEMPORARY TABLE ayuda_ia_cuota (clave VARCHAR(100) NOT NULL PRIMARY KEY, usos INT NOT NULL, vence BIGINT NOT NULL) ENGINE=InnoDB');
function cuotaCheck(bool $ok, string $msg): void {
    if (!$ok) throw new RuntimeException($msg);
    echo "OK: $msg\n";
}
try {
    $ahora = 2000000000;
    $cuota = new App\Service\AyudaIaCuota($db, 2, 4, 3);
    cuotaCheck($cuota->consumir(1, $ahora) === 0 && $cuota->consumir(1, $ahora) === 0, 'Cuota: llamadas dentro del límite');
    cuotaCheck($cuota->consumir(1, $ahora) > 0, 'Cuota: institución agotada');
    cuotaCheck((int) $db->fetchOne('SELECT MAX(usos) FROM ayuda_ia_cuota WHERE clave LIKE ?', ['global-dia-%']) === 2, 'Cuota: rechazo revierte contadores globales');
    cuotaCheck($cuota->consumir(2, $ahora) === 0, 'Cuota: otra institución conserva su cupo');
    cuotaCheck($cuota->consumir(2, $ahora) > 0, 'Cuota: límite global por minuto');
    cuotaCheck($cuota->consumir(2, $ahora + 60) === 0, 'Cuota: ventana de minuto se renueva');
    cuotaCheck($cuota->consumir(3, $ahora + 60) > 0, 'Cuota: límite diario global');
    cuotaCheck($cuota->consumir(1, $ahora + 86400) === 0, 'Cuota: ventana diaria se renueva');
    $nuevaInstancia = new App\Service\AyudaIaCuota($db, 2, 4, 3);
    cuotaCheck($nuevaInstancia->consumir(1, $ahora + 86400) === 0 && $nuevaInstancia->consumir(1, $ahora + 86400) > 0, 'Cuota: se comparte entre instancias y usuarios');
} finally {
    $db->executeStatement('DROP TEMPORARY TABLE ayuda_ia_cuota');
    $kernel->shutdown();
}
