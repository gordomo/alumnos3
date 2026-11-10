<?php
/**
 * Integración sin PHPUnit: ejecutar SOLO sobre una copia de la base.
 * DATABASE_URL=.../alumnos_test_... php tests/regression_creditos.php
 * Las filas de la prueba se revierten al terminar, incluso si falla.
 */
require dirname(__DIR__) . '/vendor/autoload.php';
(new Symfony\Component\Dotenv\Dotenv())->bootEnv(dirname(__DIR__) . '/.env');
$kernel = new App\Kernel($_SERVER['APP_ENV'], (bool) ($_SERVER['APP_DEBUG'] ?? false));
$kernel->boot();
$em = $kernel->getContainer()->get('doctrine')->getManager();
$db = $em->getConnection();
if (!preg_match('/^alumnos_(test|auditoria)_/', $db->getDatabase())) {
    throw new RuntimeException('Se requiere una base aislada alumnos_test_* o alumnos_auditoria_*.');
}
function comprobar(bool $condicion, string $mensaje): void {
    if (!$condicion) { throw new RuntimeException($mensaje); }
    echo "OK: $mensaje\n";
}
function igual(float $a, float $b): bool { return abs($a - $b) < 0.01; }
$db->beginTransaction();
try {
    $instituto = (new App\Entity\Instituto())->setNombre('Prueba de créditos');
    $em->persist($instituto);
    $hoy = new DateTimeImmutable('today');
    $inicio = new DateTime($hoy->format('Y-m-01'));
    $fin = (clone $inicio)->modify('last day of next month');
    $curso = (new App\Entity\Curso())->setInstituto($instituto)->setNombre('Créditos')
        ->setPrecio('10000')->setFechaInicio($inicio)->setFechaFin($fin);
    $curso->setDias(['Lunes']); $curso->setHorarioInicio(new DateTime('08:00')); $curso->setHorarioFin(new DateTime('09:00'));
    $em->persist($curso);
    $alumno = (new App\Entity\Alumno())->setInstituto($instituto)->setNombre('Prueba')->setApellido('Regresión')
        ->setEmail(bin2hex(random_bytes(6)) . '@example.test')->setDni((string) random_int(80000000, 99999999));
    $alumno->setActivo(true); $em->persist($alumno);
    $historico = (new App\Entity\AlumnoCursoHistorico())->setAlumno($alumno)->setCurso($curso)
        ->setFechaAlta(clone $inicio)->setFechaInicio(clone $inicio)->setFechaFin(clone $fin)
        ->setNombreCurso('Créditos')->setPrecioMensual('10000');
    $historico->setActivo(true);
    $em->persist($historico);
    $vencimiento = (new App\Entity\Vencimiento())->setInstituto($instituto)->setDiaVencimiento(1)->setPorcentajeInteres(10)->setOrden(1);
    $em->persist($vencimiento);
    $em->flush();
    $timezone = new App\Service\InstitutoTimezoneService($em->getRepository(App\Entity\InstitutoConfiguracion::class));
    $calculator = new App\Service\DeudaCalculatorService($em, $timezone);
    $mes = (int) $hoy->format('n'); $ano = (int) $hoy->format('Y');
    $cuota = $calculator->calcularCuotaParaMes($alumno, $curso, $mes, $ano, 10000);
    comprobar(igual($cuota['monto'] + $cuota['interes'], 11000), 'Sin crédito: base y recargo conservados');
    $deuda = (new App\Entity\DeudaAlumno())->setAlumno($alumno)->setInstituto($instituto)->setCurso($curso)
        ->setCursoHistorico($historico)->setMes($mes)->setAno($ano)->setMonto(10000)->setInteres(1000);
    $em->persist($deuda);
    $saldo = (new App\Entity\SaldoFavor())->setAlumno($alumno)->setInstituto($instituto)->setMonto('3000')->setMontoDisponible('0')->setTipo('nota_credito');
    $em->persist($saldo);
    $aplicacion = (new App\Entity\SaldoFavorAplicacion())->setSaldoFavor($saldo)->setMontoAplicado('3000');
    $deuda->addCreditoAplicacion($aplicacion); $em->persist($aplicacion); $em->flush();
    $cuota = $calculator->calcularCuotaParaMes($alumno, $curso, $mes, $ano, 10000);
    comprobar(igual($cuota['monto'], 8000) && igual($cuota['interes'], 0), 'Crédito de 3000 cubre recargo y reduce capital: pendiente 8000');
    $individual = $calculator->calcularDeudasAlumno($alumno);
    $masivo = $calculator->calcularDeudasParaAlumnos([$alumno]);
    comprobar(igual($individual[0]['monto'] + $individual[0]['interes'], 8000), 'Consulta individual descuenta crédito');
    comprobar(igual($masivo[$alumno->getId()][0]['monto'], 8000), 'Consulta masiva coincide con individual');
    $otro = (new App\Entity\Alumno())->setInstituto($instituto)->setNombre('Otro')->setApellido('Alumno')
        ->setEmail(bin2hex(random_bytes(6)) . '@example.test')->setDni((string) random_int(80000000, 99999999));
    $otro->setActivo(true); $em->persist($otro); $em->flush();
    $sinCredito = $calculator->calcularCuotaParaMes($otro, $curso, $mes, $ano, 10000);
    comprobar(igual($sinCredito['monto'] + $sinCredito['interes'], 11000), 'El crédito no se comparte con otro alumno');
    $futuro = $hoy->modify('first day of next month');
    $deudaFutura = (new App\Entity\DeudaAlumno())->setAlumno($alumno)->setInstituto($instituto)->setCurso($curso)
        ->setCursoHistorico($historico)->setMes((int) $futuro->format('n'))->setAno((int) $futuro->format('Y'))->setMonto(10000)->setInteres(0);
    $em->persist($deudaFutura);
    $saldoFuturo = (new App\Entity\SaldoFavor())->setAlumno($alumno)->setInstituto($instituto)->setMonto('10000')->setMontoDisponible('0')->setTipo('nota_credito');
    $em->persist($saldoFuturo);
    $apFutura = (new App\Entity\SaldoFavorAplicacion())->setSaldoFavor($saldoFuturo)->setMontoAplicado('10000');
    $deudaFutura->addCreditoAplicacion($apFutura);$em->persist($apFutura);$em->flush();
    $cuotaFutura = $calculator->calcularCuotaParaMes($alumno, $curso, (int) $futuro->format('n'), (int) $futuro->format('Y'), 10000);
    comprobar(igual($cuotaFutura['monto'] + $cuotaFutura['interes'], 0) && $deudaFutura->isPagado(), 'Crédito total cubre una cuota futura');
    $trasAumento = $calculator->calcularCuotaParaMes($alumno, $curso, (int) $futuro->format('n'), (int) $futuro->format('Y'), 12000);
    comprobar(igual($trasAumento['monto'] + $trasAumento['interes'], 0), 'Cuota saldada con crédito no reaparece tras un aumento');
    $pago = (new App\Entity\AlumnosPagos())->setAlumno($alumno)->setCurso($curso)->setFecha(new DateTime('today'))
        ->setMes($mes)->setAno($ano)->setMonto(7500)->setMetodoPago('Efectivo');
    $historial = new App\Service\HistorialCursosService($em, $timezone);
    $pagos = new App\Service\PagoService($em, $timezone, $historial, $calculator);
    $resultado = $pagos->registrarPago($pago, [$deuda->getId()], false);
    $em->refresh($deuda);
    comprobar(igual($deuda->getMontoPendiente(), 0), 'Cobro negociado cancela el resto sin perder el crédito aplicado');
    comprobar(igual($deuda->getMonto(), 10500), 'Total ajustado conserva 3000 de crédito más 7500 cobrados');
    comprobar(igual($resultado['montoRestante'], 0), 'No se genera sobrepago por descontar el mismo crédito dos veces');
    comprobar($calculator->calcularDeudasAlumno($alumno) === [], 'Cuotas cubiertas no reaparecen como deuda');
} finally {
    $db->rollBack(); $em->clear(); $kernel->shutdown();
}
