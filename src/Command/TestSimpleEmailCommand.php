<?php

namespace App\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;

#[AsCommand(
    name: 'app:test-simple-email',
    description: 'Envía un email de prueba simple para verificar la configuración del mailer',
)]
class TestSimpleEmailCommand extends Command
{
    private MailerInterface $mailer;

    public function __construct(MailerInterface $mailer)
    {
        parent::__construct();
        $this->mailer = $mailer;
    }

    protected function configure(): void
    {
        $this
            ->addArgument('to', InputArgument::REQUIRED, 'Email de destino')
            ->addArgument('from', InputArgument::OPTIONAL, 'Email remitente (opcional)', 'noreply@test.com')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $to = $input->getArgument('to');
        $from = $input->getArgument('from');

        try {
            $email = (new Email())
                ->from($from)
                ->to($to)
                ->subject('Email de Prueba - ' . date('Y-m-d H:i:s'))
                ->text('Este es un email de prueba simple para verificar que el mailer funciona correctamente.')
                ->html('<h1>Email de Prueba</h1><p>Este es un email de prueba simple para verificar que el mailer funciona correctamente.</p><p>Enviado el: ' . date('Y-m-d H:i:s') . '</p>');

            $io->info("Enviando email desde: {$from}");
            $io->info("Enviando email hacia: {$to}");
            
            $this->mailer->send($email);
            
            $io->success("Email enviado exitosamente!");
            $io->note("Si no recibes el email, verifica:");
            $io->listing([
                'Carpeta de spam/correo no deseado',
                'Configuración del MAILER_DSN en .env',
                'Logs del servidor SMTP',
                'Que el servidor SMTP esté funcionando correctamente'
            ]);
            
            return Command::SUCCESS;
        } catch (\Exception $e) {
            $io->error('Error al enviar el email: ' . $e->getMessage());
            $io->error('Trace: ' . $e->getTraceAsString());
            return Command::FAILURE;
        }
    }
}

