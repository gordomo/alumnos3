<?php
namespace App\Controller;

use App\Entity\{Alumno, SolicitudPaginaPublica, RespuestaSolicitudPublica, MensajeEntranteSolicitud};
use App\Repository\SolicitudPaginaPublicaRepository;
use App\Service\InstitutoTimezoneService;
use App\Service\RespuestaSolicitudService;
use App\Service\CorreoConversaciones;
use App\Form\RespuestaSolicitudPublicaType;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Knp\Component\Pager\PaginatorInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\{Request, Response};
use Symfony\Component\Routing\Annotation\Route;

/** @Route("/instituto/solicitudes") */
class InstitutoSolicitudesController extends AbstractController
{
    public function __construct(private ?CorreoConversaciones $correo=null) {}
    private function propia(int $id, SolicitudPaginaPublicaRepository $repo): SolicitudPaginaPublica
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN_INSTITUTO');
        $instituto=$this->getUser()->getInstituto();
        if (!$instituto) { throw $this->createAccessDeniedException(); }
        $solicitud=$repo->findOneBy(['id'=>$id,'instituto'=>$instituto]);
        if (!$solicitud) { throw $this->createNotFoundException(); }
        return $solicitud;
    }
    /** @Route("/", name="instituto_solicitudes_index", methods={"GET"}) */
    public function index(Request $request, SolicitudPaginaPublicaRepository $repo, PaginatorInterface $paginador): Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN_INSTITUTO');
        $instituto=$this->getUser()->getInstituto();
        if (!$instituto) { throw $this->createAccessDeniedException(); }
        $estado=(string)$request->query->get('estado','pendiente');
        if (!in_array($estado,['pendiente','confirmada','atendida','descartada','inscrita','nuevos','todas'],true)) { $estado='pendiente'; }
        return $this->render('instituto_solicitudes/index.html.twig',['solicitudes'=>$paginador->paginate($repo->bandeja($instituto,$estado),max(1,$request->query->getInt('page',1)),20),'estado'=>$estado]);
    }
    /** @Route("/{id}", name="instituto_solicitudes_show", requirements={"id"="\d+"}, methods={"GET"}) */
    public function show(int $id, SolicitudPaginaPublicaRepository $repo, EntityManagerInterface $em): Response
    {
        $s=$this->propia($id,$repo);
        return $this->renderDetalle($s,$em,$this->formRespuesta($s));
    }
    private function formRespuesta(SolicitudPaginaPublica $s, ?Request $request = null): \Symfony\Component\Form\FormInterface
    {
        $nonce=bin2hex(random_bytes(16));
        if ($request) {
            $entrada=$request->request->all('respuesta_solicitud_publica');
            if (isset($entrada['envio']) && is_string($entrada['envio']) && preg_match('/^[a-f0-9]{32}$/D',$entrada['envio'])) { $nonce=$entrada['envio']; }
        }
        return $this->createForm(RespuestaSolicitudPublicaType::class,['asunto'=>'Respuesta de '.$s->getInstituto()->getNombre(),'mensaje'=>'','envio'=>$nonce],['action'=>$this->generateUrl('instituto_solicitudes_responder',['id'=>$s->getId()]),'csrf_token_id'=>'respuesta_solicitud_'.$s->getId().'_'.$nonce]);
    }
    private function renderDetalle(SolicitudPaginaPublica $s, EntityManagerInterface $em, \Symfony\Component\Form\FormInterface $form, int $status = 200): Response
    {
        $existentes=$em->getRepository(Alumno::class)->findBy(['instituto'=>$s->getInstituto(),'email'=>$s->getEmail()]);
        $respuestas=$em->getRepository(RespuestaSolicitudPublica::class)->findBy(['solicitud'=>$s],['fecha'=>'DESC','id'=>'DESC'],50);
        $entrantes=$em->getRepository(MensajeEntranteSolicitud::class)->findBy(['solicitud'=>$s],['fecha'=>'DESC','id'=>'DESC'],50);
        $conversacion=[];
        foreach($respuestas as $r) { $conversacion[]=['direccion'=>'saliente','item'=>$r,'fecha'=>$r->getFecha()]; }
        foreach($entrantes as $r) { $conversacion[]=['direccion'=>'entrante','item'=>$r,'fecha'=>$r->getFecha()]; }
        usort($conversacion,static fn($a,$b)=>($a['fecha']<=>$b['fecha'])?:strcmp($b['direccion'],$a['direccion']));
        $response=$this->render('instituto_solicitudes/show.html.twig',['solicitud'=>$s,'existentes'=>$existentes,'respuestaForm'=>$form->createView(),'respuestas'=>$respuestas,'conversacion'=>$conversacion,'recepcionActiva'=>$this->correo && $this->correo->isActivo(),'casillaConversaciones'=>$this->correo?$this->correo->casilla():null,'puedeResponder'=>($this->correo && $this->correo->isActivo()) || filter_var($s->getInstituto()->getEmail(),FILTER_VALIDATE_EMAIL)!==false]);
        $response->setStatusCode($status);return $response;
    }
    /** @Route("/{id}/responder", name="instituto_solicitudes_responder", requirements={"id"="\d+"}, methods={"POST"}) */
    public function responder(int $id, Request $request, SolicitudPaginaPublicaRepository $repo, EntityManagerInterface $em, RespuestaSolicitudService $servicio): Response
    {
        $s=$this->propia($id,$repo);$form=$this->formRespuesta($s,$request);$form->handleRequest($request);
        if (!$form->isSubmitted() || !$form->isValid()) { return $this->renderDetalle($s,$em,$form,422); }
        $datos=$form->getData();
        try { $respuesta=$servicio->enviar($s,$this->getUser(),$datos['asunto'],$datos['mensaje'],$datos['envio']); }
        catch (\InvalidArgumentException $e) { $form->addError(new \Symfony\Component\Form\FormError($e->getMessage()));return $this->renderDetalle($s,$em,$form,422); }
        $this->addFlash($respuesta->getEstado()==='enviada'?'success':'warning', $respuesta->getEstado()==='enviada'?'La respuesta fue enviada por email y quedó guardada en el historial.':($respuesta->getEstado()==='fallida'?'No se pudo confirmar el envío. El texto quedó guardado en el historial; revisá el correo antes de reintentarlo.':'El envío ya está en proceso. No lo repitas; revisá su estado en el historial.'));
        return $this->redirectToRoute('instituto_solicitudes_show',['id'=>$id,'_fragment'=>'respuestas'],303);
    }
    /** @Route("/{id}/correo/leido", name="instituto_solicitudes_correo_leido", requirements={"id"="\d+"}, methods={"POST"}) */
    public function leido(int $id, Request $request, SolicitudPaginaPublicaRepository $repo, EntityManagerInterface $em): Response
    {
        $s=$this->propia($id,$repo);
        if (!$this->isCsrfTokenValid('correo_leido_'.$id,(string)$request->request->get('_token'))) { throw $this->createAccessDeniedException(); }
        $s->marcarCorreosLeidos();$em->flush();return $this->redirectToRoute('instituto_solicitudes_show',['id'=>$id],303);
    }
    /** @Route("/{id}/correo/{mensaje}/original", name="instituto_solicitudes_correo_original", requirements={"id"="\d+","mensaje"="\d+"}, methods={"GET"}) */
    public function original(int $id,int $mensaje,SolicitudPaginaPublicaRepository $repo,EntityManagerInterface $em): Response
    {
        $s=$this->propia($id,$repo);$m=$em->getRepository(MensajeEntranteSolicitud::class)->findOneBy(['id'=>$mensaje,'solicitud'=>$s]);
        if (!$m || !preg_match('/^[a-f0-9]{64}\.eml$/D',$m->getArchivoOriginal())) { throw $this->createNotFoundException(); }
        $archivo=$this->getParameter('kernel.project_dir').'/var/correo_recibido/'.$m->getArchivoOriginal();
        if (!is_file($archivo)) { throw $this->createNotFoundException(); }
        $r=$this->file($archivo,'correo-'.$m->getId().'.eml');$r->headers->set('Content-Type','message/rfc822');$r->headers->set('X-Content-Type-Options','nosniff');$r->headers->set('Content-Security-Policy','sandbox');$r->headers->set('Cache-Control','private, no-store');return $r;
    }
    /** @Route("/{id}/resolver", name="instituto_solicitudes_resolver", requirements={"id"="\d+"}, methods={"POST"}) */
    public function resolver(int $id, Request $request, SolicitudPaginaPublicaRepository $repo, EntityManagerInterface $em, InstitutoTimezoneService $timezone): Response
    {
        $s=$this->propia($id,$repo);
        if (!$this->isCsrfTokenValid('solicitud_publica_'.$id,(string)$request->request->get('_token'))) { throw $this->createAccessDeniedException('El formulario venció.'); }
        $em->getConnection()->beginTransaction();
        try {
            $em->lock($s,LockMode::PESSIMISTIC_WRITE);$em->refresh($s);
            $accion=(string)$request->request->get('accion');
            if ($accion==='confirmada') {
                $curso=$s->getCurso();
                if (!$curso || $curso->getInstituto()->getId()!==$s->getInstituto()->getId() || $curso->getDisabled() || $curso->getCerrado() || ($curso->getFechaFin() && $curso->getFechaFin()->format('Y-m-d')<$timezone->getTodayForInstituto($s->getInstituto()))) {
                    $this->addFlash('warning','El curso ya no está disponible. Revisá sus fechas y estado antes de confirmar.');$accion='';
                }
            }
            if ($accion==='inscrita' && $s->getEstado()==='confirmada') {
                $alumno=$em->getRepository(Alumno::class)->findOneBy(['id'=>$request->request->getInt('alumno'),'instituto'=>$s->getInstituto()]);
                if (!$alumno || !$s->getCurso() || !$alumno->getCurso()->contains($s->getCurso())) { $this->addFlash('warning','Primero completá la inscripción del alumno en este curso.'); }
                else { $s->completarConAlumno($alumno);$this->addFlash('success','La solicitud quedó vinculada con la inscripción del alumno.'); }
            } elseif ($s->resolver($accion)) {
                $s->marcarCorreosLeidos();
                $this->addFlash('success',$accion==='confirmada'?'Preinscripción confirmada para continuar el alta. Contactá a la persona para coordinar los detalles.':'Solicitud actualizada.');
            }
            $em->flush();$em->getConnection()->commit();
        } catch (\Throwable $e) { $em->getConnection()->rollBack();throw $e; }
        return $this->redirectToRoute('instituto_solicitudes_show',['id'=>$id],303);
    }
}
