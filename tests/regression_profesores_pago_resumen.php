<?php
/** Sin base de datos ni liquidaciones: usa las entidades y las reglas de resolución reales. */
require dirname(__DIR__) . '/vendor/autoload.php';
use App\Entity\{Instituto, Profesor, Curso, ProfesorCursoPago};
use App\Service\ProfesorPagoService;
function idResumen(object $obj, int $id): object { (new ReflectionProperty(get_class($obj), 'id'))->setValue($obj, $id); return $obj; }
function resumenCheck(bool $ok, string $texto): void { if (!$ok) throw new RuntimeException($texto); echo 'OK: '.$texto.PHP_EOL; }
$inst=idResumen(new Instituto(),991);
$p=idResumen((new Profesor())->setInstituto($inst),11);$p->setPrecioHora(9000);$p->setViatico(25000);$p->setMontoFijoMensual(100000);$p->setPorcentajeCurso(30);
$c1=idResumen((new Curso())->setNombre('Curso A')->setInstituto($inst),101);
$c2=idResumen((new Curso())->setNombre('Curso B')->setInstituto($inst),102);
$p->addCurso($c1)->addCurso($c2);
$servicio=(new ReflectionClass(ProfesorPagoService::class))->newInstanceWithoutConstructor();
$twig=new Twig\Environment(new Twig\Loader\FilesystemLoader(dirname(__DIR__).'/templates'),['strict_variables'=>true]);
$twig->addExtension(new App\Twig\VocabularioExtension(new App\Service\Vocabulario('instituto')));
foreach(['por_hora','fijo_mensual','porcentaje','combinado'] as $tipo){
 $p->setTipoPago($tipo);$r=$servicio->resumirConfiguracion($p,[]);
 resumenCheck($r['general']['modalidad']===$tipo && !$r['por_curso'], 'Modalidad general: '.$tipo);
 $html=$twig->render('profesor/_pago_valores.html.twig',['valores'=>$r['general']]);
 resumenCheck(str_contains($html,'9.000,00')===($tipo==='por_hora'), 'No muestra valor hora inactivo: '.$tipo);
 resumenCheck(str_contains($html,'Viático:')===in_array($tipo,['por_hora','fijo_mensual'],true), 'Viático solo donde se liquida: '.$tipo);
 resumenCheck(str_contains($html,'100.000,00')===in_array($tipo,['fijo_mensual','combinado'],true), 'Fijo solo donde se liquida: '.$tipo);
}
$p->setTipoPago('combinado');
$r1=(new ProfesorCursoPago())->setInstituto($inst)->setProfesor($p)->setCurso($c1)->setModalidad('porcentaje')->setPorcentaje(45);
$r2=(new ProfesorCursoPago())->setInstituto($inst)->setProfesor($p)->setCurso($c2)->setModalidad('fijo_mensual')->setMontoFijo(20000)->setViatico(500);
$r=$servicio->resumirConfiguracion($p,[101=>$r1]);
resumenCheck(count($r['por_curso'])===1 && $r['general']['modalidad']==='combinado' && $r['por_curso'][0]['valores']['porcentaje']===45.0,'Acuerdo de un curso convive con la configuración general de los demás');
$r=$servicio->resumirConfiguracion($p,[101=>$r1,102=>$r2]);
resumenCheck($r['general']['modalidad']==='fijo_mensual' && !$r['fijo_reemplazado'],'Todos los cursos con reglas: conserva fijo base si se suma, oculta porcentaje general inactivo');
$html=$twig->render('profesor/_pago_valores.html.twig',['valores'=>$r['por_curso'][1]['valores']]);
resumenCheck(!str_contains($html,'Viático:'),'Fijo propio del curso no muestra viático que no se liquida');
$p->setFijoSeSuma(false);$r=$servicio->resumirConfiguracion($p,[101=>$r1,102=>$r2]);
resumenCheck($r['general']===null && $r['fijo_reemplazado'],'Reglas de todos los cursos reemplazan el fijo cuando corresponde');
$r1->setActivo(false);$r2->setMontoFijo(null);$r=$servicio->resumirConfiguracion($p,[101=>$r1,102=>$r2]);
resumenCheck(!$r['por_curso'] && $r['general']['modalidad']==='combinado' && !$r['fijo_reemplazado'],'Reglas apagadas o incompletas vuelven a la configuración general');
$r1->setActivo(true)->setModalidad('por_hora')->setPrecioHora(null)->setViatico(null);$r=$servicio->resumirConfiguracion($p,[101=>$r1]);
resumenCheck($r['por_curso'][0]['valores']['precio_hora']===9000.0 && $r['por_curso'][0]['valores']['viatico']===25000.0,'Acuerdo por hora hereda valores no especificados');
$r1->setPrecioHora(0)->setViatico(0);$r=$servicio->resumirConfiguracion($p,[101=>$r1]);
resumenCheck($r['por_curso'][0]['valores']['precio_hora']===0.0 && $r['por_curso'][0]['valores']['viatico']===0.0,'Ceros explícitos no se reemplazan por valores generales');
$p->removeCurso($c1);$r=$servicio->resumirConfiguracion($p,[101=>$r1]);
resumenCheck(!$r['por_curso'],'Regla de un curso no asignado no se presenta como vigente');
$p->removeCurso($c2);$r=$servicio->resumirConfiguracion($p,[]);
resumenCheck($r['general']['modalidad']==='combinado','Sin cursos muestra la configuración general');

$config=(new App\Entity\InstitutoConfiguracion())->setBasePorcentajeProfesor('facturado');
$inst->setConfiguracion($config);$p->setTipoPago('porcentaje');
$r=$servicio->resumirConfiguracion($p,[]);
$html=$twig->render('profesor/_pago_valores.html.twig',['valores'=>$r['general']]);
resumenCheck(str_contains($html,'lo facturado'),'Porcentaje identifica la base configurada en la institución');
