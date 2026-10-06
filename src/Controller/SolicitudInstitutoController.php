<?php

namespace App\Controller;

use App\Entity\SolicitudInstituto;
use App\Repository\SolicitudInstitutoRepository;
use App\Service\AltaInstitutoService;
use App\Service\EmailService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

/**
 * La bandeja de solicitudes de alta.
 *
 * Es la pantalla que reemplaza al registro automático: acá alguien mira quién pidió entrar y
 * decide. Confirmar crea el instituto y manda la invitación; descartar guarda el motivo, para no
 * perder el historial de lo que se decidió y por qué.
 *
 * Cuelga de /admin, que ya está restringido a ROLE_ADMIN y ROLE_SUPER_ADMIN en security.yaml.
 *
 * @Route("/admin/solicitudes")
 */
class SolicitudInstitutoController extends AbstractController
{
    /**
     * @Route("/", name="admin_solicitud_index", methods={"GET"})
     */
    public function index(Request $request, SolicitudInstitutoRepository $solicitudes): Response
    {
        $estado = $request->query->get('estado');
        if (!in_array($estado, [SolicitudInstituto::PENDIENTE, SolicitudInstituto::CONFIRMADA, SolicitudInstituto::DESCARTADA], true)) {
            $estado = null;
        }

        return $this->render('admin/solicitud/index.html.twig', [
            'solicitudes' => $solicitudes->listar($estado),
            'estadoSeleccionado' => $estado,
            'pendientes' => $solicitudes->contarPendientes(),
        ]);
    }

    /**
     * @Route("/{id}", name="admin_solicitud_show", methods={"GET"}, requirements={"id"="\d+"})
     */
    public function show(SolicitudInstituto $solicitud): Response
    {
        return $this->render('admin/solicitud/show.html.twig', [
            'solicitud' => $solicitud,
            'diasInvitacion' => AltaInstitutoService::DIAS_INVITACION,
        ]);
    }

    /**
     * @Route("/{id}/notas", name="admin_solicitud_notas", methods={"POST"}, requirements={"id"="\d+"})
     */
    public function notas(Request $request, SolicitudInstituto $solicitud, EntityManagerInterface $em): Response
    {
        if ($this->isCsrfTokenValid('solicitud_notas' . $solicitud->getId(), (string) $request->request->get('_token'))) {
            $solicitud->setNotas($request->request->get('notas'));
            $em->flush();
            $this->addFlash('success', 'Notas guardadas.');
        }

        return $this->redirectToRoute('admin_solicitud_show', ['id' => $solicitud->getId()]);
    }

    /**
     * @Route("/{id}/confirmar", name="admin_solicitud_confirmar", methods={"POST"}, requirements={"id"="\d+"})
     */
    public function confirmar(
        Request $request,
        SolicitudInstituto $solicitud,
        EntityManagerInterface $em,
        AltaInstitutoService $altaInstituto,
        EmailService $emailService
    ): Response {
        if (!$this->isCsrfTokenValid('solicitud_confirmar' . $solicitud->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('danger', 'Token de seguridad inválido.');

            return $this->redirectToRoute('admin_solicitud_show', ['id' => $solicitud->getId()]);
        }

        if (!$solicitud->estaPendiente()) {
            $this->addFlash('warning', 'Esta solicitud ya estaba resuelta.');

            return $this->redirectToRoute('admin_solicitud_show', ['id' => $solicitud->getId()]);
        }

        // Entre que llegó la solicitud y la confirmamos, ese mail puede haber quedado en uso.
        if ($em->getRepository(\App\Entity\User::class)->findOneBy(['email' => $solicitud->getEmail()])) {
            $this->addFlash('danger', 'Ya existe un usuario con el email ' . $solicitud->getEmail() . '. Revisalo antes de confirmar.');

            return $this->redirectToRoute('admin_solicitud_show', ['id' => $solicitud->getId()]);
        }

        $usuario = $altaInstituto->crearDesdeSolicitud($solicitud);
        $token = $altaInstituto->generarInvitacion($usuario);
        $em->flush();

        $enviado = $this->enviarInvitacion($emailService, $solicitud, $token);

        $this->addFlash(
            $enviado ? 'success' : 'warning',
            $enviado
                ? 'Instituto creado y invitación enviada a ' . $solicitud->getEmail() . '.'
                : 'El instituto quedó creado, pero el mail de invitación no salió. Reenviala desde esta pantalla.'
        );

        return $this->redirectToRoute('admin_solicitud_show', ['id' => $solicitud->getId()]);
    }

    /**
     * @Route("/{id}/reenviar", name="admin_solicitud_reenviar", methods={"POST"}, requirements={"id"="\d+"})
     */
    public function reenviar(
        Request $request,
        SolicitudInstituto $solicitud,
        EntityManagerInterface $em,
        AltaInstitutoService $altaInstituto,
        EmailService $emailService
    ): Response {
        if (!$this->isCsrfTokenValid('solicitud_reenviar' . $solicitud->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('danger', 'Token de seguridad inválido.');

            return $this->redirectToRoute('admin_solicitud_show', ['id' => $solicitud->getId()]);
        }

        $instituto = $solicitud->getInstituto();
        $usuario = $instituto ? $em->getRepository(\App\Entity\User::class)->findOneBy([
            'instituto' => $instituto,
            'email' => $solicitud->getEmail(),
        ]) : null;

        if (!$usuario) {
            $this->addFlash('danger', 'No encontré el usuario de esta solicitud. Revisalo en Institutos.');

            return $this->redirectToRoute('admin_solicitud_show', ['id' => $solicitud->getId()]);
        }

        $token = $altaInstituto->generarInvitacion($usuario);
        $em->flush();

        $this->addFlash(
            $this->enviarInvitacion($emailService, $solicitud, $token) ? 'success' : 'danger',
            'Invitación reenviada a ' . $solicitud->getEmail() . '.'
        );

        return $this->redirectToRoute('admin_solicitud_show', ['id' => $solicitud->getId()]);
    }

    /**
     * @Route("/{id}/descartar", name="admin_solicitud_descartar", methods={"POST"}, requirements={"id"="\d+"})
     */
    public function descartar(Request $request, SolicitudInstituto $solicitud, EntityManagerInterface $em): Response
    {
        if (!$this->isCsrfTokenValid('solicitud_descartar' . $solicitud->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('danger', 'Token de seguridad inválido.');

            return $this->redirectToRoute('admin_solicitud_show', ['id' => $solicitud->getId()]);
        }

        if (!$solicitud->estaPendiente()) {
            $this->addFlash('warning', 'Esta solicitud ya estaba resuelta.');

            return $this->redirectToRoute('admin_solicitud_show', ['id' => $solicitud->getId()]);
        }

        // No se le avisa a nadie: descartar es una decisión interna y un mail automático de
        // rechazo hace más daño que bien. Si hay que contestarle, se le escribe a mano.
        $solicitud->setEstado(SolicitudInstituto::DESCARTADA);
        $solicitud->setMotivoDescarte($request->request->get('motivo'));
        $solicitud->setFechaResolucion(new \DateTime());
        $em->flush();

        $this->addFlash('success', 'Solicitud descartada.');

        return $this->redirectToRoute('admin_solicitud_show', ['id' => $solicitud->getId()]);
    }

    private function enviarInvitacion(EmailService $emailService, SolicitudInstituto $solicitud, string $token): bool
    {
        try {
            $emailService->sendGeneralCommunication(
                $solicitud->getEmail(),
                'Tu instituto en Team Builder ya está listo',
                'emails/invitacion_instituto.html.twig',
                [
                    'solicitud' => $solicitud,
                    'token' => $token,
                    'dias' => AltaInstitutoService::DIAS_INVITACION,
                ]
            );

            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }
}
