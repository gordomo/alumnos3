<?php

namespace App\Command;

use App\Entity\Instituto;
use App\Entity\User;
use App\Repository\BillingConfigRepository;
use App\Repository\InstitutoRepository;
use App\Service\EmailService;
use App\Service\SuscripcionService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Avisa por mail a los institutos sobre el estado de su suscripción.
 *
 * Pensado para correrse una vez por día desde el cron. El aviso se manda solo el día en que el
 * instituto cambia de escalón (falta poco, venció, pasó a consulta, quedó bloqueado) y no todos
 * los días que sigue en ese escalón: así no hace falta guardar en la base qué se mandó y cuándo,
 * y el instituto no recibe el mismo mail quince veces.
 *
 * La contra de esto es que si un día el cron no corre, ese aviso se pierde. Es aceptable: el
 * banner dentro del sistema sí se muestra todos los días, y es el que realmente avisa.
 */
#[AsCommand(
    name: 'app:avisar-suscripciones',
    description: 'Envía los avisos de vencimiento y bloqueo de las suscripciones de los institutos',
)]
class AvisarSuscripcionesCommand extends Command
{
    public function __construct(
        private InstitutoRepository $institutoRepository,
        private SuscripcionService $suscripcionService,
        private BillingConfigRepository $configRepository,
        private EmailService $emailService,
        private EntityManagerInterface $em
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Muestra a quién le escribiría, sin mandar nada')
            ->addOption('instituto', null, InputOption::VALUE_OPTIONAL, 'Solo este instituto (id)')
            ->addOption('todos', null, InputOption::VALUE_NONE, 'Avisa a todos los que estén en algún escalón, no solo a los que cambian hoy')
            ->setHelp('Corriéndolo una vez por día, cada instituto recibe un mail el día que cambia de escalón.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');
        $todos = (bool) $input->getOption('todos');
        $soloId = $input->getOption('instituto');

        $config = $this->configRepository->getOrCreatePriceConfig();

        $institutos = $soloId
            ? array_filter([$this->institutoRepository->find((int) $soloId)])
            : $this->institutoRepository->findAll();

        if (!$institutos) {
            $io->warning('No hay institutos para revisar.');

            return Command::SUCCESS;
        }

        $io->title('Avisos de suscripción');
        if ($dryRun) {
            $io->note('DRY-RUN: no se envía ningún mail.');
        }

        $enviados = 0;
        $omitidos = 0;
        $errores = 0;
        $filas = [];

        foreach ($institutos as $instituto) {
            $estado = $this->suscripcionService->estado($instituto);

            if (!$estado['avisar']) {
                ++$omitidos;
                continue;
            }

            if (!$todos && !$this->esElDiaDelAviso($estado, $config)) {
                ++$omitidos;
                continue;
            }

            $destinatarios = $this->destinatarios($instituto);
            if (!$destinatarios) {
                $filas[] = [$instituto->getNombre(), $estado['estado'], 'sin mail de admin'];
                ++$omitidos;
                continue;
            }

            $filas[] = [
                $instituto->getNombre(),
                $estado['estado'],
                implode(', ', $destinatarios),
            ];

            if ($dryRun) {
                continue;
            }

            foreach ($destinatarios as $mail) {
                try {
                    $this->emailService->sendGeneralCommunication(
                        $mail,
                        $this->asunto($estado),
                        'emails/suscripcion_aviso.html.twig',
                        [
                            'instituto' => $instituto,
                            'suscripcion' => $estado,
                            'asunto' => $this->asunto($estado),
                        ]
                    );
                    ++$enviados;
                } catch (\Throwable $e) {
                    ++$errores;
                    $io->error(sprintf('%s (%s): %s', $instituto->getNombre(), $mail, $e->getMessage()));
                }
            }
        }

        if ($filas) {
            $io->table(['Instituto', 'Estado', 'Destinatarios'], $filas);
        }

        $io->success(sprintf('Enviados: %d | Omitidos: %d | Errores: %d', $enviados, $omitidos, $errores));

        return $errores > 0 ? Command::FAILURE : Command::SUCCESS;
    }

    /**
     * Si hoy es justo el día en que el instituto entró en este escalón.
     */
    private function esElDiaDelAviso(array $estado, \App\Entity\BillingConfig $config): bool
    {
        switch ($estado['estado']) {
            case SuscripcionService::POR_VENCER:
                // El primer día de la ventana de aviso previo, y de nuevo el día antes de vencer.
                return $estado['dias'] === $config->getDiasAvisoPrevio() || $estado['dias'] === 1;

            case SuscripcionService::VENCIDA:
                return $estado['atraso'] === 1;

            case SuscripcionService::SOLO_LECTURA:
                return $estado['atraso'] === $config->getDiasGracia() + 1;

            case SuscripcionService::BLOQUEADA:
                return $estado['atraso'] === $config->getDiasHastaBloqueo() + 1;
        }

        return false;
    }

    private function asunto(array $estado): string
    {
        switch ($estado['estado']) {
            case SuscripcionService::POR_VENCER:
                return 'Tu suscripción vence en ' . $estado['dias'] . ' día(s)';
            case SuscripcionService::VENCIDA:
                return 'Tu suscripción está vencida';
            case SuscripcionService::SOLO_LECTURA:
                return 'Tu sistema quedó en modo consulta';
            case SuscripcionService::BLOQUEADA:
                return 'Tu acceso al sistema está bloqueado';
        }

        return 'Estado de tu suscripción';
    }

    /**
     * Los mails de los admins del instituto. Se piden a la base directo porque los roles están
     * guardados como JSON y no hay un método de repositorio para esto.
     *
     * @return string[]
     */
    private function destinatarios(Instituto $instituto): array
    {
        $usuarios = $this->em->getRepository(User::class)->createQueryBuilder('u')
            ->andWhere('u.instituto = :instituto')
            ->andWhere('u.roles LIKE :rol')
            ->setParameter('instituto', $instituto)
            ->setParameter('rol', '%ROLE_ADMIN_INSTITUTO%')
            ->getQuery()
            ->getResult();

        $mails = [];
        foreach ($usuarios as $usuario) {
            $mail = $usuario->getEmail();
            if ($mail && filter_var($mail, FILTER_VALIDATE_EMAIL)) {
                $mails[$mail] = $mail;
            }
        }

        return array_values($mails);
    }
}
