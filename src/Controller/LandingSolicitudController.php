<?php
namespace App\Controller;

use App\Entity\{Curso, SolicitudPaginaPublica};
use App\Repository\{InstitutoPaginaPublicaRepository, SolicitudPaginaPublicaRepository};
use App\Service\{EmailService, FormularioPublicoProteccion, InstitutoLandingPresenter, InstitutoPaginaPublicaService, InstitutoTimezoneService};
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\{Request, Response};
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Validator\Validator\ValidatorInterface;

class LandingSolicitudController extends AbstractController
{
    /** @Route("/{slug}/solicitudes/{tipo}", name="app_instituto_landing_publica_solicitud", requirements={"slug"="[a-z0-9]+(?:-[a-z0-9]+)*", "tipo"="contacto|preinscripcion"}, methods={"POST"}) */
    public function enviar(string $slug, string $tipo, Request $request, InstitutoPaginaPublicaRepository $paginas, InstitutoPaginaPublicaService $urls, InstitutoLandingPresenter $presenter, FormularioPublicoProteccion $proteccion, RateLimiterFactory $landingIpLimiter, RateLimiterFactory $landingEmailLimiter, ValidatorInterface $validator, EntityManagerInterface $em, SolicitudPaginaPublicaRepository $solicitudes, InstitutoTimezoneService $timezone, EmailService $emails, LoggerInterface $logger): Response
    {
        $pagina=$paginas->findPublicada($slug);
        if (!$pagina) { throw $this->createNotFoundException(); }
        $instituto=$pagina->getInstituto();
        $volver=fn()=> $this->redirectToRoute('app_instituto_landing_publica',['slug'=>$slug,'enviado'=>$tipo,'_fragment'=>$tipo==='contacto'?'contacto':'preinscripcion'],303);
        $entrada=$request->request->all();
        $valor=static fn(string $campo): string => isset($entrada[$campo]) && is_string($entrada[$campo]) ? trim($entrada[$campo]) : '';
        $errores=[];
        if (strlen($request->getContent())>20000) { return new Response('El formulario es demasiado extenso.',413); }
        if (!$landingIpLimiter->create($proteccion->huella('ip|'.($request->getClientIp() ?? 'desconocida')))->consume()->isAccepted()) { $errores[]='Recibimos varios envíos desde tu conexión. Probá de nuevo en unos minutos.'; }
        // No se guarda ni se envía correo ante un campo trampa completado.
        if (!$errores && $valor('website')!=='') { return $volver(); }
        if (!$proteccion->validar($valor('_firma'),$slug,$tipo)) { $errores[]='El formulario venció o se envió demasiado rápido. Revisá los datos y volvé a enviarlo.'; }
        if ($request->headers->get('Sec-Fetch-Site')==='cross-site') { $errores[]='Abrí el formulario desde la página del instituto para enviarlo.'; }
        if ($valor('consentimiento')!=='1') { $errores[]='Necesitamos tu autorización para usar estos datos y contactarte por esta solicitud.'; }
        $solicitud=(new SolicitudPaginaPublica())->setInstituto($instituto)->setTipo($tipo)->setNombre($valor('nombre'))->setApellido($valor('apellido'))->setEmail($valor('email'))->setTelefono($valor('telefono'))->setMensaje($valor('mensaje'))->setTokenHash(hash('sha256',$valor('_firma')));
        foreach ($validator->validate($solicitud) as $error) { $errores[]=$error->getMessage(); }
        if ($tipo==='contacto' && $valor('mensaje')==='') { $errores[]='Contanos en qué podemos ayudarte.'; }
        if ($tipo==='preinscripcion') {
            if ($valor('apellido')==='') { $errores[]='Ingresá el apellido de la persona que quiere inscribirse.'; }
            $id=ctype_digit($valor('curso')) ? (int)$valor('curso') : 0;
            $curso=$em->getRepository(Curso::class)->createQueryBuilder('c')->where('c.id = :id AND c.instituto = :instituto AND c.disabled = false AND c.cerrado = false')->andWhere('c.fechaFin IS NULL OR c.fechaFin >= :hoy')->setParameters(['id'=>$id,'instituto'=>$instituto,'hoy'=>$timezone->getCurrentDateForInstituto($instituto)])->getQuery()->getOneOrNullResult();
            if (!$curso) { $errores[]='Elegí un curso disponible de este instituto.'; }
            else {
                $solicitud->setCurso($curso)->setClaveActiva($proteccion->huella('preinscripcion|'.$instituto->getId().'|'.$curso->getId().'|'.$solicitud->getEmail()));
            }
        }
        if (!$errores) {
            if (!$landingEmailLimiter->create($proteccion->huella('email|'.$instituto->getId().'|'.$solicitud->getEmail()))->consume()->isAccepted()) { $errores[]='Ya recibimos varios envíos con este correo. Por favor, esperá antes de volver a intentar.'; }
            elseif ($solicitudes->findOneBy(['tokenHash'=>$solicitud->getTokenHash()]) || ($solicitud->getClaveActiva() && $solicitudes->findOneBy(['claveActiva'=>$solicitud->getClaveActiva()]))) { return $volver(); }
        }
        if ($errores) {
            $formularios=$proteccion->contexto($slug);$formularios['errores'][$tipo]=array_values(array_unique($errores));
            foreach (['nombre','apellido','email','telefono','mensaje','curso'] as $campo) { $formularios['valores'][$tipo][$campo]=mb_substr($valor($campo),0,3000); }
            $response=$this->render('instituto_pagina_publica/landing.html.twig',['sitio'=>$presenter->datos($pagina),'preview'=>false,'urlPublica'=>$urls->url($pagina),'formularios'=>$formularios]);
            $response->setStatusCode(422);$response->headers->set('Cache-Control','private, no-store');$response->headers->set('X-Robots-Tag','noindex');return $response;
        }
        try { $em->persist($solicitud); $em->flush(); }
        catch (UniqueConstraintViolationException $e) { return $volver(); }
        // La bandeja es la fuente de verdad: un fallo de entrega no pierde la consulta.
        if (filter_var($instituto->getEmail(),FILTER_VALIDATE_EMAIL)) {
            try { $emails->sendGeneralCommunication($instituto->getEmail(),$tipo==='contacto'?'Nueva consulta desde tu página pública':'Nueva preinscripción pendiente','emails/solicitud_pagina_publica.html.twig',['solicitud'=>$solicitud,'bandejaUrl'=>rtrim($this->getParameter('app.url'),'/').$this->generateUrl('instituto_solicitudes_show',['id'=>$solicitud->getId()])]); }
            catch (\Throwable $e) { $logger->warning('No se pudo enviar el aviso de una solicitud pública.', ['solicitud_id'=>$solicitud->getId()]); }
        }
        return $volver();
    }
}
