<?php
/** Integración con MySQL: las tablas temporales de esta conexión aíslan todos los datos de prueba. */
require dirname(__DIR__) . '/vendor/autoload.php';
(new Symfony\Component\Dotenv\Dotenv())->bootEnv(dirname(__DIR__) . '/.env');
$kernel = new App\Kernel($_SERVER['APP_ENV'], false); $kernel->boot();
$em = $kernel->getContainer()->get('doctrine')->getManager(); $db = $em->getConnection(); $tablas = [];
function landingCheck(bool $ok, string $msg): void { if (!$ok) throw new RuntimeException($msg); echo 'OK: '.$msg.PHP_EOL; }
try {
    foreach (['instituto', 'instituto_pagina_publica', 'curso', 'curso_horario', 'instituto_configuracion'] as $tabla) {
        $ddl = $db->fetchAssociative('SHOW CREATE TABLE '.$tabla)['Create Table'];
        $ddl = preg_replace('/^CREATE TABLE/', 'CREATE TEMPORARY TABLE', $ddl);
        $ddl = preg_replace('/^  CONSTRAINT[^\n]*\n/m', '', $ddl);
        $ddl = preg_replace('/,\n\) ENGINE/', "\n) ENGINE", $ddl);
        $db->executeStatement($ddl); $tablas[] = $tabla;
    }
    foreach ([991,992,993] as $id) $db->insert('instituto', ['id'=>$id,'nombre'=>$id===993?'Ayuda':'Institución Prueba','activo'=>1,'email'=>'contacto@example.test','dir'=>'Calle Pública 123','tel'=>'+54 341 5551234']);
    foreach ([[101,991,0,0,'2099-12-31'],[102,991,1,0,'2099-12-31'],[103,991,0,1,'2099-12-31'],[104,991,0,0,'2000-12-31'],[201,992,0,0,'2099-12-31']] as [$id,$inst,$disabled,$cerrado,$fin]) {
        $db->insert('curso',['id'=>$id,'instituto_id'=>$inst,'nombre'=>'Curso '.$id,'dias'=>'["lunes"]','horario_inicio'=>'08:00:00','horario_fin'=>'09:00:00','fecha_inicio'=>'2020-01-01','fecha_fin'=>$fin,'duracion'=>1,'disabled'=>$disabled,'cerrado'=>$cerrado,'precio'=>'87654.32']);
    }
    $db->update('curso', ['descripcion_publica'=>"Inglés para conversar.\nObjetivos y contenidos <script>alert('x')</script>"], ['id'=>101]);
    $db->insert('curso_horario', ['curso_id'=>104, 'dia'=>'Martes', 'horario_inicio'=>'18:00:00', 'horario_fin'=>'19:30:00']);
    $db->insert('curso_horario', ['curso_id'=>104, 'dia'=>'Jueves', 'horario_inicio'=>'18:30:00', 'horario_fin'=>'20:00:00']);
    $router=$kernel->getContainer()->get('router');
    $paginas=new App\Service\InstitutoPaginaPublicaService($em->getRepository(App\Entity\InstitutoPaginaPublica::class),$em,new Symfony\Component\String\Slugger\AsciiSlugger(),$router,'https://example.test','instituto');
    $uno=$paginas->obtenerOCrear($em->find(App\Entity\Instituto::class,991));
    $dos=$paginas->obtenerOCrear($em->find(App\Entity\Instituto::class,992));
    $reservado=$paginas->obtenerOCrear($em->find(App\Entity\Instituto::class,993));
    landingCheck(!$uno->isPublicada() && $uno->getSlug()==='institucion-prueba','URL legible y borrador inicial');
    landingCheck($uno->getSlug()!==$dos->getSlug() && $reservado->getSlug()!=='ayuda','Nombres repetidos y rutas reservadas no colisionan');
    landingCheck($paginas->obtenerOCrear($uno->getInstituto())->getId()===$uno->getId(),'Reservar otra vez conserva URL y configuración');
    $repo=$em->getRepository(App\Entity\InstitutoPaginaPublica::class);
    landingCheck($repo->findPublicada($uno->getSlug())===null,'Borradores no se publican');
    $uno->setPublicada(true)->setPresentacion('<script>alert("x")</script>');$em->flush();
    landingCheck($repo->findPublicada($uno->getSlug())!==null,'Publicar habilita solo esa página');
    $timezone=new App\Service\InstitutoTimezoneService($em->getRepository(App\Entity\InstitutoConfiguracion::class));
    $presenter=new App\Service\InstitutoLandingPresenter($em,$timezone);$datos=$presenter->datos($uno);
    landingCheck(array_column($datos['cursos'],'nombre')===['Curso 101','Curso 104'],'Catálogo incluye ediciones finalizadas y excluye ajenos, deshabilitados y cerrados');
    landingCheck($datos['cursos'][0]['horarios'][0]['inicio']==='08:00' && isset($datos['cursos'][0]['precio']),'Horarios y precios visibles');
    landingCheck(!$datos['cursos'][0]['finalizado'] && $datos['cursos'][1]['finalizado'], 'Las ediciones finalizadas no se anuncian como vigentes');
    landingCheck(count($datos['cursos'][1]['horarios'])===2 && in_array('18:30',array_column($datos['cursos'][1]['horarios'],'inicio'),true), 'Se muestran todos los horarios por día y también el formato anterior');
    $twig=$kernel->getContainer()->get('twig');
    $html=$twig->render('instituto_pagina_publica/landing.html.twig',['sitio'=>$datos,'preview'=>false,'urlPublica'=>$paginas->url($uno)]);
    landingCheck(str_contains($html,'87.654,32') && !str_contains($html,'<script>'),'Render con precio y presentación escapada');
    landingCheck(str_contains($html,'Más info') && str_contains($html,'Inglés para conversar.') && str_contains($html,'&lt;script&gt;') && str_contains($html,'Edición finalizada') && str_contains($html,'Próxima edición'),'Descripción pública escapada y consulta por próximas ediciones');
    $validador=$kernel->getContainer()->get('validator');
    $curso=$em->find(App\Entity\Curso::class,101);$curso->setDescripcionPublica(str_repeat('a',3001));
    landingCheck(count($validador->validateProperty($curso,'descripcionPublica'))>0,'La descripción tiene límite de 3000 caracteres');
    $curso->setDescripcionPublica(null);
    landingCheck(count($validador->validateProperty($curso,'descripcionPublica'))===0,'La descripción es opcional para cursos existentes');
    $em->refresh($curso);
    $response=$kernel->handle(Symfony\Component\HttpFoundation\Request::create('https://example.test/'.$uno->getSlug()));
    landingCheck($response->getStatusCode()===200 && str_contains($response->getContent(),'Curso 101') && !$response->headers->has('Set-Cookie'),'Página pública funciona sin sesión ni cookies');
    $uno->setMostrarPrecios(false);$datos=$presenter->datos($uno);
    $html=$twig->render('instituto_pagina_publica/landing.html.twig',['sitio'=>$datos,'preview'=>false,'urlPublica'=>$paginas->url($uno)]);
    landingCheck(!array_key_exists('precio',$datos['cursos'][0]) && !str_contains($html,'87.654') && !str_contains($html,'87654'),'Ocultar precios elimina importes del DTO y del HTML');
    $uno->setColorPrincipal('red;display:none');$datos=$presenter->datos($uno);
    landingCheck($datos['principal']==='#1b4f9c' && App\Service\InstitutoLandingPresenter::contraste('#ffffff')==='#111827','Colores seguros y contraste automático');
    $uno->setColorPrincipal('#1b4f9c');$uno->getInstituto()->darDeBaja();$em->flush();
    landingCheck($repo->findPublicada($uno->getSlug())===null,'Institución dada de baja no tiene página pública');
    $matcher=new App\Security\PaginaPublicaRequestMatcher();
    $request=Symfony\Component\HttpFoundation\Request::create('/ayuda?_route=app_instituto_landing_publica');
    landingCheck(!$matcher->matches($request),'El parámetro de consulta no permite eludir la autenticación');
    $request->attributes->set('_route','app_instituto_landing_publica');landingCheck($matcher->matches($request),'Solo la ruta pública omite la sesión');
    foreach (['/ayuda'=>'app_ayuda_index','/login'=>'app_login','/instituto/config/pagina-publica'=>'instituto_pagina_publica_config'] as $path=>$route) landingCheck($router->match($path)['_route']===$route,'La landing no desplaza '.$path);
} finally { $em->clear();foreach(array_reverse($tablas) as $tabla)$db->executeStatement('DROP TEMPORARY TABLE '.$tabla);$kernel->shutdown(); }
