<?php
/** Usa tablas temporales y un mailer en memoria: nunca envía correos reales. */
require dirname(__DIR__).'/vendor/autoload.php';
(new Symfony\Component\Dotenv\Dotenv())->bootEnv(dirname(__DIR__).'/.env');
$kernel=new App\Kernel($_SERVER['APP_ENV'],false);$kernel->boot();$c=$kernel->getContainer();$em=$c->get('doctrine')->getManager();$db=$em->getConnection();$tablas=[];
function respuestaCheck(bool $ok,string $msg): void {if(!$ok)throw new RuntimeException($msg);echo 'OK: '.$msg.PHP_EOL;}
try{
 foreach(['instituto','user','solicitud_pagina_publica','respuesta_solicitud_publica','email_log'] as $tabla){$ddl=$db->fetchAssociative('SHOW CREATE TABLE '.$tabla)['Create Table'];$ddl=preg_replace('/^CREATE TABLE/','CREATE TEMPORARY TABLE',$ddl);$ddl=preg_replace('/^  CONSTRAINT[^\n]*\n/m','',$ddl);$ddl=preg_replace('/,\n\) ENGINE/',"\n) ENGINE",$ddl);$db->executeStatement($ddl);$tablas[]=$tabla;}
 foreach([991,992] as $id)$db->insert('instituto',['id'=>$id,'nombre'=>'Instituto prueba '.$id,'activo'=>1,'email'=>'instituto'.$id.'@example.test']);
 $db->insert('user',['id'=>90001,'instituto_id'=>991,'email'=>'admin@example.test','roles'=>'["ROLE_ADMIN_INSTITUTO"]','password'=>'solo-fixture-no-login']);
 foreach([[501,991,'contacto'],[502,991,'contacto'],[503,991,'preinscripcion'],[601,992,'contacto']] as [$id,$inst,$tipo])$db->insert('solicitud_pagina_publica',['id'=>$id,'instituto_id'=>$inst,'tipo'=>$tipo,'estado'=>'pendiente','nombre'=>'Ana','apellido'=>'Prueba','email'=>'destino@example.test','mensaje'=>'Quiero información','fecha'=>'2026-11-10 10:00:00','token_hash'=>hash('sha256',(string)$id)]);
 $mailer=new class implements Symfony\Component\Mailer\MailerInterface{
  public int $intentos=0;public bool $fallar=false;public array $mensajes=[];
  public function send(Symfony\Component\Mime\RawMessage $message,?Symfony\Component\Mailer\Envelope $envelope=null):void{++$this->intentos;if($this->fallar)throw new RuntimeException('Fallo simulado');$this->mensajes[]=$message;}
 };
 $emails=new App\Service\EmailService($mailer,$c->get('router'),dirname(__DIR__),'https://example.test');$servicio=new App\Service\RespuestaSolicitudService($em,$emails,new Psr\Log\NullLogger());
 $autor=$em->find(App\Entity\User::class,90001);$s=$em->find(App\Entity\SolicitudPaginaPublica::class,501);$nonce=bin2hex(random_bytes(16));
 $r=$servicio->enviar($s,$autor,'Información de cursos',"Hola Ana,\nEstos son los horarios. <script>alert(1)</script>",$nonce);
 respuestaCheck($r->getEstado()==='enviada'&&$s->getEstado()==='atendida'&&$mailer->intentos===1,'Enviar una respuesta atiende la consulta solo después del envío');
 $otra=$servicio->enviar($s,$autor,'Información de cursos','Texto repetido',$nonce);respuestaCheck($otra->getId()===$r->getId()&&$mailer->intentos===1,'Doble envío con la misma clave no duplica el correo');
 $email=$mailer->mensajes[0];(new Symfony\Bridge\Twig\Mime\BodyRenderer($c->get('twig')))->render($email);
 respuestaCheck($email->getTo()[0]->getAddress()==='destino@example.test'&&$email->getReplyTo()[0]->getAddress()==='instituto991@example.test'&&$email->getFrom()[0]->getAddress()==='contacto@teambuilder.com.ar','Destinatario fijo, Reply-To del instituto y remitente autorizado');
 respuestaCheck(!str_contains($email->getHtmlBody(),'<script>')&&str_contains($email->getHtmlBody(),'&lt;script&gt;'),'El mensaje se envía escapado, sin interpretar HTML');
 respuestaCheck($db->fetchOne('SELECT estado FROM email_log WHERE tipo = ?',['respuesta_consulta'])==='enviado','El envío queda en Historial de Emails');
 $fallida=$em->find(App\Entity\SolicitudPaginaPublica::class,502);$mailer->fallar=true;
 $f=$servicio->enviar($fallida,$autor,'Consulta pendiente','Texto que no debe perderse',bin2hex(random_bytes(16)));
 respuestaCheck($f->getEstado()==='fallida'&&$fallida->getEstado()==='pendiente'&&$f->getMensaje()==='Texto que no debe perderse','Un fallo conserva el texto y no atiende la consulta');
 respuestaCheck($db->fetchOne('SELECT COUNT(*) FROM email_log WHERE estado = ?',['fallido'])==1,'El fallo queda registrado sin exponer detalles del transporte');
 $mailer->fallar=false;$pre=$em->find(App\Entity\SolicitudPaginaPublica::class,503);$servicio->enviar($pre,$autor,'Sobre tu preinscripción','Coordinemos los detalles.',bin2hex(random_bytes(16)));
 respuestaCheck($pre->getEstado()==='pendiente','Responder una preinscripción no la confirma automáticamente');
 try{$servicio->enviar($em->find(App\Entity\SolicitudPaginaPublica::class,601),$autor,'Asunto','Mensaje',bin2hex(random_bytes(16)));throw new RuntimeException('Aceptó solicitud ajena');}catch(LogicException $e){respuestaCheck(true,'El servicio impide responder solicitudes ajenas');}
 try{$servicio->enviar($s,$autor,"Asunto\r\nBcc: otro@example.test",'Mensaje',bin2hex(random_bytes(16)));throw new RuntimeException('Aceptó inyección de cabeceras');}catch(InvalidArgumentException $e){respuestaCheck(true,'Valida asunto, longitud y clave de envío');}
 $req=Symfony\Component\HttpFoundation\Request::create('/instituto/solicitudes/501');$req->attributes->set('_route','instituto_solicitudes_show');$req->setSession(new Symfony\Component\HttpFoundation\Session\Session(new Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage()));$c->get('request_stack')->push($req);
 $c->get('security.token_storage')->setToken(new Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken($autor,'main',$autor->getRoles()));
 $controller=new App\Controller\InstitutoSolicitudesController();$controller->setContainer($c);$method=new ReflectionMethod($controller,'formRespuesta');$method->setAccessible(true);$form=$method->invoke($controller,$s);
 $view=$form->createView();$form->submit(['asunto'=>'Respuesta','mensaje'=>'Mensaje','envio'=>$view->children['envio']->vars['value'],'_token'=>'invalido']);respuestaCheck(!$form->isValid(),'El formulario de respuesta rechaza CSRF inválido');
 $form=$method->invoke($controller,$s);$view=$form->createView();$html=$c->get('twig')->load('instituto_solicitudes/show.html.twig')->renderBlock('body',['solicitud'=>$s,'existentes'=>[],'respuestas'=>[$r,$f],'respuestaForm'=>$view,'puedeResponder'=>true]);
 respuestaCheck(str_contains($html,'Enviar respuesta')&&str_contains($html,'Historial de respuestas')&&str_contains($html,'Texto que no debe perderse'),'La pantalla muestra editor e historial incluyendo fallos');
 $form->submit(['asunto'=>'Respuesta','mensaje'=>'Mensaje','envio'=>$view->children['envio']->vars['value'],'_token'=>$view->children['_token']->vars['value']]);respuestaCheck($form->isValid(),'Formulario con firma CSRF y campos válidos');
 $router=$c->get('router');$matched=$router->match('/instituto/solicitudes/501/responder');
}catch(Symfony\Component\Routing\Exception\MethodNotAllowedException $e){respuestaCheck(in_array('POST',$e->getAllowedMethods(),true),'Responder requiere POST');}
finally{$em->clear();foreach(array_reverse($tablas)as$tabla)$db->executeStatement('DROP TEMPORARY TABLE '.$tabla);$kernel->shutdown();}
