<?php

namespace App\Controller;

use App\Entity\SolicitudInstituto;
use App\Repository\SolicitudInstitutoRepository;
use App\Service\EmailService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * El formulario público para pedir una cuenta.
 *
 * Reemplaza al registro automático: antes cualquiera con el enlace creaba un instituto completo
 * sin que nadie lo mirara. Ahora deja una solicitud y alguien decide.
 *
 * Es una superficie pública sin sesión, así que lleva límite de intentos por IP y un campo
 * trampa: los bots completan todos los campos de un formulario, incluido el que está escondido.
 */
class ContactoController extends AbstractController
{
    /**
     * @Route("/contacto", name="app_contacto", methods={"POST"})
     */
    public function enviar(
        Request $request,
        EntityManagerInterface $em,
        ValidatorInterface $validator,
        SolicitudInstitutoRepository $solicitudes,
        EmailService $emailService,
        RateLimiterFactory $solicitudInstitutoLimiter
    ): Response {
        if (!$this->isCsrfTokenValid('contacto', (string) $request->request->get('_token'))) {
            $this->addFlash('contacto_error', 'Se venció el formulario. Volvé a enviarlo, por favor.');

            return $this->redirectToRoute('app_start', [], Response::HTTP_SEE_OTHER);
        }

        // Campo trampa: está oculto por CSS, una persona nunca lo completa.
        if (trim((string) $request->request->get('apellido_contacto')) !== '') {
            // Se le responde como si todo hubiera salido bien: si el bot ve el error, prueba otra cosa.
            $this->addFlash('contacto_ok', 'Recibimos tus datos. Te escribimos a la brevedad.');

            return $this->redirectToRoute('app_start', [], Response::HTTP_SEE_OTHER);
        }

        if (!$solicitudInstitutoLimiter->create($request->getClientIp())->consume()->isAccepted()) {
            $this->addFlash('contacto_error', 'Recibimos varios pedidos desde tu conexión. Probá de nuevo en un rato.');

            return $this->redirectToRoute('app_start', [], Response::HTTP_SEE_OTHER);
        }

        $solicitud = new SolicitudInstituto();
        $solicitud->setNombreInstituto((string) $request->request->get('nombre_instituto'));
        $solicitud->setNombreContacto((string) $request->request->get('nombre_contacto'));
        $solicitud->setEmail((string) $request->request->get('email'));
        $solicitud->setTelefono($request->request->get('telefono'));
        $solicitud->setAlumnosEstimados($request->request->get('alumnos_estimados'));
        $solicitud->setMensaje($request->request->get('mensaje'));
        $solicitud->setIp($request->getClientIp());

        $errores = $validator->validate($solicitud);
        if (count($errores) > 0) {
            $this->addFlash('contacto_error', $errores->get(0)->getMessage());

            return $this->redirectToRoute('app_start', [], Response::HTTP_SEE_OTHER);
        }

        // Dos envíos seguidos con el mismo mail no generan dos solicitudes: se contesta igual,
        // porque para la persona el resultado es el mismo y no tiene por qué saber que ya mandó una.
        if ($solicitudes->pendientePorEmail($solicitud->getEmail())) {
            $this->addFlash('contacto_ok', 'Recibimos tus datos. Te escribimos a la brevedad.');

            return $this->redirectToRoute('app_start', [], Response::HTTP_SEE_OTHER);
        }

        $em->persist($solicitud);
        $em->flush();

        // El aviso no puede voltear la solicitud: si el mail falla, la solicitud ya está guardada
        // y se ve igual en el panel.
        try {
            $emailService->sendGeneralCommunication(
                $this->getParameter('app.email_contacto'),
                'Nueva solicitud: ' . $solicitud->getNombreInstituto(),
                'emails/solicitud_nueva.html.twig',
                ['solicitud' => $solicitud]
            );
        } catch (\Throwable $e) {
            // Sin logger propio acá: el error ya queda en el log de la aplicación.
        }

        $this->addFlash('contacto_ok', 'Recibimos tus datos. Te escribimos a la brevedad.');

        return $this->redirectToRoute('app_start', [], Response::HTTP_SEE_OTHER);
    }
}
