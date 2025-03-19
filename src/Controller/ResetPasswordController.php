<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

/**
 * @Route("/reset-password")
 */
class ResetPasswordController extends AbstractController
{
    /**
     * @Route("/{token}", name="app_reset_password")
     */
    public function resetPassword(string $token): Response
    {
        // TODO: Implementar la lógica de reseteo de contraseña
        return new Response('Token recibido: ' . $token);
    }
} 