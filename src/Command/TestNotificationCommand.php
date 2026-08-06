<?php

namespace App\Command;

use App\Entity\Alumno;
use App\Entity\AlumnosPagos;
use App\Entity\DeudaAlumno;
use App\Service\NotificationService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:test-notification',
    description: 'Envía un email de prueba (recibo o recordatorio) para verificar el formato',
)]
class TestNotificationCommand extends Command
{
    private NotificationService $notificationService;
    private EntityManagerInterface $entityManager;

    public function __construct(
        NotificationService $notificationService,
        EntityManagerInterface $entityManager
    ) {
        parent::__construct();
        $this->notificationService = $notificationService;
        $this->entityManager = $entityManager;
    }

    protected function configure(): void
    {
        $this
            ->addArgument('tipo', InputArgument::REQUIRED, 'Tipo de email: "recibo" o "recordatorio"')
            ->addArgument('email', InputArgument::REQUIRED, 'Email de destino para la prueba')
            ->addOption('pago-id', null, InputOption::VALUE_OPTIONAL, 'ID del pago a usar para el recibo (si no se especifica, usa el último)')
            ->addOption('deuda-id', null, InputOption::VALUE_OPTIONAL, 'ID de la deuda a usar para el recordatorio (si no se especifica, usa la primera pendiente)')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $tipo = $input->getArgument('tipo');
        $emailDestino = $input->getArgument('email');

        if (!in_array($tipo, ['recibo', 'recordatorio'])) {
            $io->error('El tipo debe ser "recibo" o "recordatorio"');
            return Command::FAILURE;
        }

        try {
            if ($tipo === 'recibo') {
                $pagoId = $input->getOption('pago-id');

                if ($pagoId) {
                    $pago = $this->entityManager->getRepository(AlumnosPagos::class)->find($pagoId);
                    if (!$pago) {
                        $io->error("No se encontró un pago con ID: {$pagoId}");
                        return Command::FAILURE;
                    }
                } else {
                    // Usar el último pago registrado
                    $pago = $this->entityManager->getRepository(AlumnosPagos::class)
                        ->createQueryBuilder('p')
                        ->orderBy('p.fecha', 'DESC')
                        ->setMaxResults(1)
                        ->getQuery()
                        ->getOneOrNullResult();
                    
                    if (!$pago) {
                        $io->error('No se encontraron pagos en el sistema. Cree un pago primero.');
                        return Command::FAILURE;
                    }
                }
                
                $alumno = $pago->getAlumno();
                
                // Enviar con email de destino y forzando el envío (ignora configuración)
                try {
                    $enviado = $this->notificationService->enviarReciboPago($pago, $emailDestino, true);
                    
                    if ($enviado) {
                        $io->success("Email de recibo enviado a: {$emailDestino}");
                        $io->note("Pago usado: ID {$pago->getId()} - {$alumno->getNombre()} {$alumno->getApellido()} - {($pago->getCurso() ? $pago->getCurso()->getNombre() : 'Cuota de inscripcion')}");
                        $io->note("Instituto: {$alumno->getInstituto()->getNombre()}");
                        $io->note("Email remitente: " . ($alumno->getInstituto()->getEmail() ?? 'noreply@instituto.com'));
                    } else {
                        $io->warning("No se pudo enviar el email. Verifique la configuración del mailer.");
                    }
                } catch (\Exception $e) {
                    $io->error("Error al enviar el email: " . $e->getMessage());
                    $io->error("Trace: " . $e->getTraceAsString());
                    throw $e;
                }
                
            } else { // recordatorio
                $deudaId = $input->getOption('deuda-id');
                
                if ($deudaId) {
                    $deuda = $this->entityManager->getRepository(DeudaAlumno::class)->find($deudaId);
                    if (!$deuda) {
                        $io->error("No se encontró una deuda con ID: {$deudaId}");
                        return Command::FAILURE;
                    }
                } else {
                    // Usar la primera deuda pendiente
                    $deuda = $this->entityManager->getRepository(DeudaAlumno::class)
                        ->createQueryBuilder('d')
                        ->leftJoin('d.alumno', 'a')
                        ->leftJoin('d.aplicaciones', 'pa')
                        ->groupBy('d.id')
                        ->having('COALESCE(SUM(pa.montoAplicado), 0) < d.monto + COALESCE(d.interes, 0)')
                        ->andWhere('a.activo = :activo')
                        ->setParameter('activo', true)
                        ->orderBy('d.ano', 'ASC')
                        ->addOrderBy('d.mes', 'ASC')
                        ->setMaxResults(1)
                        ->getQuery()
                        ->getOneOrNullResult();
                    
                    if (!$deuda) {
                        $io->error('No se encontraron deudas pendientes en el sistema.');
                        return Command::FAILURE;
                    }
                }
                
                $alumno = $deuda->getAlumno();
                
                // Enviar con email de destino y forzando el envío (ignora configuración)
                try {
                    $enviado = $this->notificationService->enviarRecordatorioDeuda($alumno, $deuda, $emailDestino, true);
                    
                    if ($enviado) {
                        $io->success("Email de recordatorio enviado a: {$emailDestino}");
                        $io->note("Deuda usada: ID {$deuda->getId()} - {$alumno->getNombre()} {$alumno->getApellido()} - {$deuda->getCurso()->getNombre()}");
                        $io->note("Instituto: {$alumno->getInstituto()->getNombre()}");
                        $io->note("Email remitente: " . ($alumno->getInstituto()->getEmail() ?? 'noreply@instituto.com'));
                    } else {
                        $io->warning("No se pudo enviar el email. Verifique la configuración del mailer.");
                    }
                } catch (\Exception $e) {
                    $io->error("Error al enviar el email: " . $e->getMessage());
                    $io->error("Trace: " . $e->getTraceAsString());
                    throw $e;
                }
            }

        return Command::SUCCESS;
        } catch (\Exception $e) {
            $io->error('Error al enviar el email: ' . $e->getMessage());
            $io->error($e->getTraceAsString());
            return Command::FAILURE;
        }
    }
}
