<?php

namespace App\Command;

use App\Service\NotificationService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:send-notifications',
    description: 'Envía notificaciones automáticas de recordatorios de deudas pendientes',
)]
class SendNotificationsCommand extends Command
{
    private NotificationService $notificationService;

    public function __construct(NotificationService $notificationService)
    {
        parent::__construct();
        $this->notificationService = $notificationService;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        
        $io->title('Envío de Notificaciones Automáticas');
        
        try {
            $enviados = $this->notificationService->procesarRecordatoriosAutomaticos();
            
            if ($enviados > 0) {
                $io->success(sprintf('Se enviaron %d notificaciones de recordatorios de deudas.', $enviados));
            } else {
                $io->info('No se encontraron deudas pendientes que requieran notificación en este momento.');
            }
            
            return Command::SUCCESS;
        } catch (\Exception $e) {
            $io->error('Error al procesar las notificaciones: ' . $e->getMessage());
            return Command::FAILURE;
        }
    }
}
