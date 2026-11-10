<?php
/** Construye y renderiza el MIME real sin enviar correos ni generar tokens de usuarios. */
require dirname(__DIR__).'/vendor/autoload.php';
(new Symfony\Component\Dotenv\Dotenv())->bootEnv(dirname(__DIR__).'/.env');
$kernel=new App\Kernel($_SERVER['APP_ENV'],false);$kernel->boot();
$mailer=new class implements Symfony\Component\Mailer\MailerInterface {
 public ?Symfony\Component\Mime\RawMessage $message=null;
 public function send(Symfony\Component\Mime\RawMessage $message, ?Symfony\Component\Mailer\Envelope $envelope=null): void {$this->message=$message;}
};
$c=$kernel->getContainer();$renderer=new Symfony\Bridge\Twig\Mime\BodyRenderer($c->get('twig'));
foreach(['https://institutos.example.test','https://clubes.example.test'] as $base){
 $service=new App\Service\EmailService($mailer,$c->get('router'),dirname(__DIR__),$base);
 $service->sendPasswordResetEmail('destino@example.test','token-ficticio-de-prueba','Usuario');
 $email=$mailer->message;$renderer->render($email);$html=$email->getHtmlBody();$mime=$email->toString();
 $parts=$email->getAttachments();
 if(count($parts)!==1 || !str_contains($parts[0]->toString(),'Content-Disposition: inline') || $parts[0]->getMediaType()!=='image' || $parts[0]->getMediaSubtype()!=='png')throw new RuntimeException('Logo no incluido como imagen inline');
 if(!preg_match('/Content-ID: <([^>]+)>/', $mime, $cid) || !str_contains(quoted_printable_decode($mime),'cid:'.$cid[1]))throw new RuntimeException('La imagen no coincide con el CID del HTML');
 if(str_contains($html,'pruebas.institutos') || str_contains($html,'http://') || !str_contains($html,$base.'/reset-password/token-ficticio-de-prueba'))throw new RuntimeException('Dominio o enlace incorrecto');
 if(!str_contains($html,'en 1 hora') || str_contains($html,'24 horas'))throw new RuntimeException('Vencimiento incorrecto');
 echo 'OK: Logo PNG integrado, CID válido, URL HTTPS y vencimiento correcto para '.$base.PHP_EOL;
}
$kernel->shutdown();
