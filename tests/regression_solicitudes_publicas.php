<?php
/** MySQL temporal: no envía correos, no crea alumnos reales ni modifica solicitudes existentes. */
require dirname(__DIR__).'/vendor/autoload.php';
(new Symfony\Component\Dotenv\Dotenv())->bootEnv(dirname(__DIR__).'/.env');
$kernel=new App\Kernel($_SERVER['APP_ENV'],false);$kernel->boot();$container=$kernel->getContainer();
$em=$container->get('doctrine')->getManager();$db=$em->getConnection();$tablas=[];
function solicitudCheck(bool $ok,string $mensaje): void { if(!$ok)throw new RuntimeException($mensaje);echo 'OK: '.$mensaje.PHP_EOL; }
try {
 foreach(['instituto','instituto_pagina_publica','curso','curso_horario','instituto_configuracion','solicitud_pagina_publica'] as $tabla){
  $ddl=$db->fetchAssociative('SHOW CREATE TABLE '.$tabla)['Create Table'];$ddl=preg_replace('/^CREATE TABLE/','CREATE TEMPORARY TABLE',$ddl);$ddl=preg_replace('/^  CONSTRAINT[^\n]*\n/m','',$ddl);$ddl=preg_replace('/,\n\) ENGINE/',"\n) ENGINE",$ddl);$db->executeStatement($ddl);$tablas[]=$tabla;
 }
 foreach([991,992] as $id)$db->insert('instituto',['id'=>$id,'nombre'=>'Instituto prueba '.$id,'activo'=>1]);
 foreach([[991,'prueba-formularios'],[992,'prueba-otra-institucion']] as [$id,$slug])$db->insert('instituto_pagina_publica',['instituto_id'=>$id,'slug'=>$slug,'publicada'=>1,'mostrar_precios'=>0,'color_principal'=>'#1b4f9c','color_acento'=>'#0f766e']);
 foreach([[101,991,0,0,'2099-12-31'],[102,991,1,0,'2099-12-31'],[103,991,0,0,'2000-01-01'],[104,991,0,1,'2099-12-31'],[201,992,0,0,'2099-12-31']] as [$id,$inst,$disabled,$cerrado,$fin])$db->insert('curso',['id'=>$id,'instituto_id'=>$inst,'nombre'=>'Curso '.$id,'dias'=>'["Lunes"]','horario_inicio'=>'08:00:00','horario_fin'=>'09:00:00','fecha_inicio'=>'2020-01-01','fecha_fin'=>$fin,'duracion'=>1,'disabled'=>$disabled,'cerrado'=>$cerrado,'precio'=>'12345.67']);
 $proteccion=new App\Service\FormularioPublicoProteccion($container->getParameter('kernel.secret'));
 $firma=$proteccion->emitir('prueba-formularios','contacto',time()-3);
 solicitudCheck($proteccion->validar($firma,'prueba-formularios','contacto')&&!$proteccion->validar($firma,'otra','contacto')&&!$proteccion->validar($firma,'prueba-formularios','preinscripcion'),'Firmas ligadas a institución y tipo de formulario');
 solicitudCheck(!$proteccion->validar($proteccion->emitir('prueba-formularios','contacto',time()-7201),'prueba-formularios','contacto')&&!$proteccion->validar($proteccion->emitir('prueba-formularios','contacto',time()+1),'prueba-formularios','contacto'),'Rechaza firmas vencidas o futuras');
 $baseEmail='prueba-'.bin2hex(random_bytes(6)).'@example.test';$n=0;
 $enviar=function(string $tipo,array $datos=[],?string $ip=null,string $slug='prueba-formularios')use($kernel,$proteccion,$baseEmail,&$n){
  ++$n;$request=Symfony\Component\HttpFoundation\Request::create('https://example.test/'.$slug.'/solicitudes/'.$tipo,'POST',array_replace(['_firma'=>$proteccion->emitir($slug,$tipo,time()-3),'nombre'=>'Ana','apellido'=>'Prueba','email'=>$baseEmail,'telefono'=>'3415551234','mensaje'=>'Quiero conocer los cursos.','consentimiento'=>'1','curso'=>'101'],$datos),[],[],['REMOTE_ADDR'=>$ip??'2001:db8::'.implode(':',str_split(bin2hex(random_bytes(6)),4)),'HTTP_SEC_FETCH_SITE'=>'same-origin']);return $kernel->handle($request);
 };
 $conteo=fn()=>(int)$db->fetchOne('SELECT COUNT(*) FROM solicitud_pagina_publica');
 $response=$enviar('contacto',['website'=>'spam']);solicitudCheck($response->getStatusCode()===303&&$conteo()===0,'Campo trampa no guarda solicitudes');
 $response=$enviar('contacto',['_firma'=>'inventada']);solicitudCheck($response->getStatusCode()===422&&$conteo()===0,'Firma inválida no guarda solicitudes');
 $response=$enviar('contacto',['consentimiento'=>'']);solicitudCheck($response->getStatusCode()===422&&$conteo()===0,'Exige consentimiento');
 foreach([201,102,103,104] as $curso){$response=$enviar('preinscripcion',['curso'=>(string)$curso]);solicitudCheck($response->getStatusCode()===422&&$conteo()===0,'Rechaza curso ajeno o no disponible: '.$curso);}
 $response=$enviar('contacto',['email'=>'no-es-email']);solicitudCheck($response->getStatusCode()===422&&$conteo()===0,'Valida el correo');
 $token=$proteccion->emitir('prueba-formularios','contacto',time()-3);$response=$enviar('contacto',['_firma'=>$token,'mensaje'=>"Consulta <script>alert(1)</script>\nSegunda línea"]);
 solicitudCheck($response->getStatusCode()===303&&$conteo()===1,'Contacto válido guardado sin crear alumnos ni enviar correos');
 $response=$enviar('contacto',['_firma'=>$token]);solicitudCheck($response->getStatusCode()===303&&$conteo()===1,'Repetir el mismo envío no duplica la consulta');
 $response=$enviar('preinscripcion');solicitudCheck($response->getStatusCode()===303&&$conteo()===2,'Preinscripción válida queda pendiente');
 $response=$enviar('preinscripcion');solicitudCheck($response->getStatusCode()===303&&$conteo()===2,'No duplica una preinscripción activa por curso y correo');
 $repo=$em->getRepository(App\Entity\SolicitudPaginaPublica::class);$inst=$em->find(App\Entity\Instituto::class,991);$otra=$em->find(App\Entity\Instituto::class,992);
 solicitudCheck(count($repo->bandeja($inst,'pendiente')->getQuery()->getResult())===2&&count($repo->bandeja($otra,'todas')->getQuery()->getResult())===0,'Bandeja aislada por institución');
 $s=$repo->findOneBy(['instituto'=>$inst,'tipo'=>'preinscripcion']);solicitudCheck($s->resolver('confirmada')&&!$s->resolver('confirmada')&&!$s->resolver('descartada'),'Confirmación idempotente y transiciones controladas');
 $alumno=(new App\Entity\Alumno())->setInstituto($otra)->addCurso($s->getCurso());
 try{$s->completarConAlumno($alumno);throw new RuntimeException('Aceptó alumno ajeno');}catch(LogicException $e){solicitudCheck(true,'No vincula alumnos de otra institución');}
 $alumno->setInstituto($inst);$s->completarConAlumno($alumno);solicitudCheck($s->getEstado()==='inscrita'&&$s->getClaveActiva()===null,'Vincular inscripción requiere alumno de la institución en el curso');$em->detach($s);
 $ip='2001:db8:1::'.implode(':',str_split(bin2hex(random_bytes(6)),4));$ultimo=null;for($i=0;$i<6;$i++)$ultimo=$enviar('contacto',['email'=>'invalido'],$ip);
 solicitudCheck($ultimo->getStatusCode()===422&&str_contains($ultimo->getContent(),'varios envíos desde tu conexión'),'Límite de envíos por conexión');
 $request=Symfony\Component\HttpFoundation\Request::create('https://example.test/prueba-formularios');$page=$kernel->handle($request);$html=$page->getContent();
 solicitudCheck($page->getStatusCode()===200&&str_contains($html,'form-contacto')&&str_contains($html,'form-preinscripcion')&&!$page->headers->has('Set-Cookie'),'Formularios públicos sin sesión ni cookies');
 $dom=new DOMDocument();@$dom->loadHTML('<?xml encoding="UTF-8">'.$html);$xpath=new DOMXPath($dom);
 solicitudCheck($xpath->query('//header//a[contains(@href,"login")]')->length===0&&$xpath->query('//footer//a[contains(@href,"login")]')->length===1,'Acceso al sistema solo en el footer');
 solicitudCheck($xpath->query('//form//input[@name="_firma"]')->length===2&&$xpath->query('//form//input[@name="website"]')->length===2,'Ambos formularios tienen firma y campo trampa');
 $admin=(new App\Entity\User())->setEmail('admin@example.test')->setRoles(['ROLE_ADMIN_INSTITUTO'])->setInstituto($inst);
 $container->get('security.token_storage')->setToken(new Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken($admin,'main',$admin->getRoles()));
 $controller=new App\Controller\InstitutoSolicitudesController();$controller->setContainer($container);
 $metodo=new ReflectionMethod($controller,'propia');$metodo->setAccessible(true);
 $propia=$repo->findOneBy(['instituto'=>$inst,'tipo'=>'contacto']);
 solicitudCheck($metodo->invoke($controller,$propia->getId(),$repo)->getId()===$propia->getId(),'El administrador accede a su solicitud');
 $sesion=new Symfony\Component\HttpFoundation\Session\Session(new Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage());
 $req=Symfony\Component\HttpFoundation\Request::create('/instituto/solicitudes/'.$propia->getId().'/resolver','POST',['accion'=>'atendida','_token'=>'invalido']);$req->setSession($sesion);$container->get('request_stack')->push($req);
 $timezone=new App\Service\InstitutoTimezoneService($em->getRepository(App\Entity\InstitutoConfiguracion::class));
 try{$controller->resolver($propia->getId(),$req,$repo,$em,$timezone);throw new RuntimeException('Aceptó acción sin CSRF');}catch(Symfony\Component\Security\Core\Exception\AccessDeniedException $e){solicitudCheck(true,'Resolver solicitudes exige CSRF');}
 $tokenCsrf=$container->get('security.csrf.token_manager')->getToken('solicitud_publica_'.$propia->getId())->getValue();$req->request->set('_token',$tokenCsrf);
 $res=$controller->resolver($propia->getId(),$req,$repo,$em,$timezone);solicitudCheck($res->getStatusCode()===303&&$db->fetchOne('SELECT estado FROM solicitud_pagina_publica WHERE id = ?',[$propia->getId()])==='atendida','El administrador puede atender la consulta con bloqueo y CSRF');
 $pre=$repo->findOneBy(['instituto'=>$inst,'tipo'=>'preinscripcion']);$req->request->set('accion','confirmada');$req->request->set('_token',$container->get('security.csrf.token_manager')->getToken('solicitud_publica_'.$pre->getId())->getValue());
 $res=$controller->resolver($pre->getId(),$req,$repo,$em,$timezone);solicitudCheck($res->getStatusCode()===303&&$pre->getEstado()==='confirmada','El administrador confirma una preinscripción vigente');
 $controller->resolver($pre->getId(),$req,$repo,$em,$timezone);solicitudCheck($pre->getEstado()==='confirmada','Repetir confirmar no vuelve a procesar la solicitud');
 $container->get('request_stack')->pop();
 $admin->setInstituto($otra);
 try{$metodo->invoke($controller,$propia->getId(),$repo);throw new RuntimeException('Leyó solicitud ajena');}catch(Symfony\Component\HttpKernel\Exception\NotFoundHttpException $e){solicitudCheck(true,'Una URL de otra institución devuelve 404');}
 $admin->setInstituto($inst)->setRoles(['ROLE_PROFESOR']);$container->get('security.token_storage')->setToken(new Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken($admin,'main',$admin->getRoles()));
 try{$metodo->invoke($controller,$propia->getId(),$repo);throw new RuntimeException('Un profesor leyó la bandeja');}catch(Symfony\Component\Security\Core\Exception\AccessDeniedException $e){solicitudCheck(true,'Profesores sin acceso a la bandeja');}
 $matcher=new App\Security\PaginaPublicaRequestMatcher();$request->attributes->set('_route','instituto_solicitudes_show');solicitudCheck(!$matcher->matches($request),'La bandeja no queda pública');
}finally{$em->clear();foreach(array_reverse($tablas)as$tabla)$db->executeStatement('DROP TEMPORARY TABLE '.$tabla);$kernel->shutdown();}
