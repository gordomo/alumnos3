<?php

namespace App\Command;

use App\Entity\TokenTransaction;
use App\Repository\TokenBalanceRepository;
use App\Repository\InstitutoRepository;
use App\Repository\TokenTransactionRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:reset-tokens-monthly',
    description: 'Resetea los tokens de todos los institutos el primer día de cada mes',
)]
class ResetTokensMonthlyCommand extends Command
{
    private EntityManagerInterface $entityManager;
    private TokenBalanceRepository $tokenBalanceRepository;
    private InstitutoRepository $institutoRepository;
    private TokenTransactionRepository $tokenTransactionRepository;
    private LoggerInterface $logger;

    public function __construct(
        EntityManagerInterface $entityManager,
        TokenBalanceRepository $tokenBalanceRepository,
        InstitutoRepository $institutoRepository,
        TokenTransactionRepository $tokenTransactionRepository,
        LoggerInterface $logger
    ) {
        parent::__construct();
        $this->entityManager = $entityManager;
        $this->tokenBalanceRepository = $tokenBalanceRepository;
        $this->institutoRepository = $institutoRepository;
        $this->tokenTransactionRepository = $tokenTransactionRepository;
        $this->logger = $logger;
    }

    protected function configure(): void
    {
        $this->addOption('force', null, InputOption::VALUE_NONE, 'Forzar ejecución aunque no sea día 1');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        
        // Verificar que sea el día 1 del mes
        $today = new \DateTime();
        if ($today->format('d') !== '01' && !$input->getOption('force')) {
            $io->warning('Este comando solo debe ejecutarse el día 1 de cada mes.');
            $io->note('Fecha actual: ' . $today->format('Y-m-d'));
            $io->note('Usa --force para ejecutar de todas formas (solo para testing)');
            return Command::FAILURE;
        }

        $io->title('Reseteo Mensual de Tokens');
        
        $institutos = $this->institutoRepository->findAll();
        $resetCount = 0;
        $totalTokensReset = 0;

        foreach ($institutos as $instituto) {
            $balance = $this->tokenBalanceRepository->findOneBy(['instituto' => $instituto]);
            
            if (!$balance) {
                continue;
            }

            $tokensBefore = $balance->getBalance();
            
            if ($tokensBefore > 0) {
                // Registrar transacción de expiración
                $transaction = new TokenTransaction();
                $transaction->setInstituto($instituto);
                $transaction->setAction('tokens.expired');
                $transaction->setDescription('Tokens expirados por reseteo mensual');
                $transaction->setAmount(-$tokensBefore);
                $transaction->setBalanceAfter(0);
                $transaction->setCreatedAt(new \DateTime());

                // Resetear balance a 0
                $balance->setBalance(0);
                
                $this->entityManager->persist($transaction);
                $this->entityManager->persist($balance);
                
                $resetCount++;
                $totalTokensReset += $tokensBefore;
                
                $this->logger->info(
                    "Tokens reseteados para instituto {$instituto->getNombre()}: {$tokensBefore} tokens"
                );
            }
        }

        $this->entityManager->flush();

        $io->success([
            "Reseteo completado exitosamente",
            "Institutos afectados: {$resetCount}",
            "Total de tokens reseteados: {$totalTokensReset}"
        ]);

        return Command::SUCCESS;
    }
}

