<?php

namespace App\Controller;

use App\Service\EmailService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

/**
 * @Route("/test")
 */
class TestEmailController extends AbstractController
{
    /**
     * @Route("/email", name="app_test_email")
     */
    public function testEmail(EmailService $emailService): Response
    {
        try {
            // Probamos el correo de bienvenida
            $emailService->sendWelcomeEmail(
                'morimartin@gmail.com',
                'Usuario de Prueba'
            );

            // Probamos el correo de recuperación de contraseña
            $emailService->sendPasswordResetEmail(
                'morimartin@gmail.com',
                'token-de-prueba-123',
                'Usuario de Prueba'
            );

            return new Response('¡Correos enviados con éxito! Revisa tu bandeja de entrada.');

        } catch (\Exception $e) {
            return new Response('Error al enviar los correos: ' . $e->getMessage(), 500);
        }
    }
} 