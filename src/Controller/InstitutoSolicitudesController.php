<?php
namespace App\Controller;

use App\Entity\{Alumno, SolicitudPaginaPublica};
use App\Repository\SolicitudPaginaPublicaRepository;
use App\Service\InstitutoTimezoneService;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Knp\Component\Pager\PaginatorInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\{Request, Response};
use Symfony\Component\Routing\Annotation\Route;

/** @Route("/instituto/solicitudes") */
class InstitutoSolicitudesController extends AbstractController
{
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
        if (!in_array($estado,['pendiente','confirmada','atendida','descartada','inscrita','todas'],true)) { $estado='pendiente'; }
        return $this->render('instituto_solicitudes/index.html.twig',['solicitudes'=>$paginador->paginate($repo->bandeja($instituto,$estado),max(1,$request->query->getInt('page',1)),20),'estado'=>$estado]);
    }
    /** @Route("/{id}", name="instituto_solicitudes_show", requirements={"id"="\d+"}, methods={"GET"}) */
    public function show(int $id, SolicitudPaginaPublicaRepository $repo, EntityManagerInterface $em): Response
    {
        $s=$this->propia($id,$repo);
        $existentes=$em->getRepository(Alumno::class)->findBy(['instituto'=>$s->getInstituto(),'email'=>$s->getEmail()]);
        return $this->render('instituto_solicitudes/show.html.twig',['solicitud'=>$s,'existentes'=>$existentes]);
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
                $this->addFlash('success',$accion==='confirmada'?'Preinscripción confirmada para continuar el alta. Contactá a la persona para coordinar los detalles.':'Solicitud actualizada.');
            }
            $em->flush();$em->getConnection()->commit();
        } catch (\Throwable $e) { $em->getConnection()->rollBack();throw $e; }
        return $this->redirectToRoute('instituto_solicitudes_show',['id'=>$id],303);
    }
}
