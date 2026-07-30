<?php

namespace App\Controller;

use App\Service\EmailService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Endpoint de diagnóstico del mailer.
 *
 * Antes era alcanzable por cualquier usuario autenticado y enviaba a una casilla
 * hardcodeada, así que servía para disparar mails ajenos. Ahora solo SUPER_ADMIN,
 * y siempre a la casilla del propio usuario que lo invoca.
 *
 * @Route("/test")
 */
#[IsGranted('ROLE_SUPER_ADMIN')]
class TestEmailController extends AbstractController
{
    /**
     * @Route("/email", name="app_test_email", methods={"GET"})
     */
    public function testEmail(EmailService $emailService): Response
    {
        $user = $this->getUser();
        $destinatario = $user ? $user->getEmail() : null;
        if (!$destinatario) {
            return new Response('El usuario actual no tiene email configurado.', Response::HTTP_BAD_REQUEST);
        }

        try {
            // Probamos el correo de bienvenida
            $emailService->sendWelcomeEmail($destinatario, 'Usuario de Prueba');

            // Probamos el correo de recuperación de contraseña
            $emailService->sendPasswordResetEmail($destinatario, 'token-de-prueba-123', 'Usuario de Prueba');

            return new Response(sprintf('Correos de prueba enviados a %s.', $destinatario));
        } catch (\Exception $e) {
            // Sin exponer el mensaje interno: puede filtrar el DSN o credenciales del mailer.
            return new Response('Error al enviar los correos. Revisá los logs.', Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }
}
