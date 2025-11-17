<?php

namespace App\Service;

use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Address;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

class EmailService
{
    private MailerInterface $mailer;
    private string $senderEmail;
    private string $senderName;
    private UrlGeneratorInterface $urlGenerator;

    public function __construct(
        MailerInterface $mailer,
        UrlGeneratorInterface $urlGenerator,
        string $senderEmail = 'contacto@teambuilder.com.ar',
        string $senderName = 'Team Builder'
    ) {
        $this->mailer = $mailer;
        $this->urlGenerator = $urlGenerator;
        $this->senderEmail = $senderEmail;
        $this->senderName = $senderName;
    }

    /**
     * Envía un correo de recuperación de contraseña
     */
    public function sendPasswordResetEmail(string $toEmail, string $resetToken, string $username): void
    {
        try {
            // Generar la URL base para las imágenes en el email
            $baseUrl = $this->urlGenerator->generate('app_start', [], UrlGeneratorInterface::ABSOLUTE_URL);
            // Remover la ruta final para obtener solo el dominio
            $baseUrl = rtrim($baseUrl, '/');
        } catch (\Exception $e) {
            // Si falla, usar el default_uri configurado en routing.yaml
            $baseUrl = 'http://localhost:8000';
        }
        
        // Construir la URL completa del logo
        $logoUrl = $baseUrl . '/assets/img/logoteam.png';
        
        $email = (new TemplatedEmail())
            ->from(new Address($this->senderEmail, $this->senderName))
            ->to($toEmail)
            ->subject('Recuperación de Contraseña')
            ->htmlTemplate('emails/password_reset.html.twig')
            ->context([
                'resetToken' => $resetToken,
                'username' => $username,
                'expirationMessageHours' => 24,
                'logo_url' => $logoUrl
            ]);

        $this->mailer->send($email);
    }

    /**
     * Envía un correo de comunicación general
     */
    public function sendGeneralCommunication(
        string $toEmail, 
        string $subject, 
        string $template, 
        array $context = []
    ): void {
        $email = (new TemplatedEmail())
            ->from(new Address($this->senderEmail, $this->senderName))
            ->to($toEmail)
            ->subject($subject)
            ->htmlTemplate($template)
            ->context($context);

        $this->mailer->send($email);
    }

    /**
     * Envía un correo de bienvenida al usuario
     */
    public function sendWelcomeEmail(string $toEmail, string $username): void
    {
        $email = (new TemplatedEmail())
            ->from(new Address($this->senderEmail, $this->senderName))
            ->to($toEmail)
            ->subject('¡Bienvenido a Team Builder!')
            ->htmlTemplate('emails/welcome.html.twig')
            ->context([
                'username' => $username
            ]);

        $this->mailer->send($email);
    }
}
