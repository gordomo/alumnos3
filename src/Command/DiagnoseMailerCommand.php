<?php

namespace App\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;

#[AsCommand(
    name: 'app:diagnose-mailer',
    description: 'Diagnostica la configuración del mailer',
)]
class DiagnoseMailerCommand extends Command
{
    private MailerInterface $mailer;
    private ParameterBagInterface $parameterBag;

    public function __construct(MailerInterface $mailer, ParameterBagInterface $parameterBag)
    {
        parent::__construct();
        $this->mailer = $mailer;
        $this->parameterBag = $parameterBag;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        
        $io->title('Diagnóstico de Configuración del Mailer');
        
        // Verificar MAILER_DSN
        $mailerDsn = $_ENV['MAILER_DSN'] ?? $_SERVER['MAILER_DSN'] ?? getenv('MAILER_DSN') ?: 'No configurado';
        
        $io->section('Configuración');
        $io->definitionList(
            ['MAILER_DSN' => $mailerDsn],
            ['Mailer Service' => get_class($this->mailer)]
        );
        
        // Intentar obtener información del transporte
        try {
            $reflection = new \ReflectionClass($this->mailer);
            $transportProperty = $reflection->getProperty('transports');
            $transportProperty->setAccessible(true);
            $transports = $transportProperty->getValue($this->mailer);
            
            if (is_array($transports) && !empty($transports)) {
                $io->section('Transportes Configurados');
                foreach ($transports as $name => $transport) {
                    $io->text("Transporte '{$name}': " . get_class($transport));
                }
            } else {
                $io->warning('No se encontraron transportes configurados');
            }
        } catch (\Exception $e) {
            $io->note('No se pudo obtener información detallada del transporte: ' . $e->getMessage());
        }
        
        // Verificar si el DSN es válido
        if ($mailerDsn !== 'No configurado') {
            if (strpos($mailerDsn, 'smtp://') === 0 || strpos($mailerDsn, 'smtps://') === 0) {
                $io->success('MAILER_DSN parece estar configurado para SMTP');
                
                // Extraer información del DSN
                $parsed = parse_url($mailerDsn);
                if ($parsed) {
                    $io->definitionList(
                        ['Protocolo' => $parsed['scheme'] ?? 'N/A'],
                        ['Host' => $parsed['host'] ?? 'N/A'],
                        ['Puerto' => $parsed['port'] ?? 'N/A'],
                        ['Usuario' => $parsed['user'] ?? 'N/A']
                    );
                }
            } elseif (strpos($mailerDsn, 'null://') === 0) {
                $io->warning('MAILER_DSN está configurado como "null://" - los emails no se enviarán realmente');
            } else {
                $io->note('MAILER_DSN tiene un formato desconocido');
            }
        } else {
            $io->error('MAILER_DSN no está configurado. Los emails no se enviarán.');
            $io->note('Configura MAILER_DSN en tu archivo .env');
        }
        
        $io->section('Recomendaciones');
        $io->listing([
            'Verifica que MAILER_DSN esté configurado en tu archivo .env',
            'Si usas SMTP, verifica que las credenciales sean correctas',
            'Revisa la carpeta de spam de tu email',
            'Para desarrollo, considera usar Mailtrap o similar',
            'Verifica los logs del servidor SMTP si tienes acceso'
        ]);
        
        return Command::SUCCESS;
    }
}

