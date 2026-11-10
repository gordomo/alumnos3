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
        private string $projectDir,
        private string $baseUrl,
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
        // APP_URL es el dominio de esta instalación, también detrás del proxy HTTPS.
        $resetUrl = rtrim($this->baseUrl, '/') . $this->urlGenerator->generate('app_reset_password_confirm', ['token' => $resetToken], UrlGeneratorInterface::ABSOLUTE_PATH);

        $email = (new TemplatedEmail())
            ->from(new Address($this->senderEmail, $this->senderName))
            ->to($toEmail)
            ->subject('Recuperación de Contraseña')
            ->htmlTemplate('emails/password_reset.html.twig')
            ->embedFromPath($this->projectDir . '/public/assets/img/logoteam.png', 'logo-team-builder', 'image/png')
            ->context([
                'resetUrl' => $resetUrl,
                'username' => $username,
                'expirationMessageHours' => 1
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
