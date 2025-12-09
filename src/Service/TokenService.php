<?php

namespace App\Service;

use App\Entity\Instituto;
use App\Entity\TokenBalance;
use App\Entity\TokenTransaction;
use App\Entity\TokenAction;
use App\Entity\User;
use App\Repository\TokenBalanceRepository;
use App\Repository\TokenTransactionRepository;
use App\Repository\TokenActionRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

class TokenService
{
    private EntityManagerInterface $entityManager;
    private TokenBalanceRepository $tokenBalanceRepository;
    private TokenTransactionRepository $tokenTransactionRepository;
    private TokenActionRepository $tokenActionRepository;
    private LoggerInterface $logger;

    public function __construct(
        EntityManagerInterface $entityManager,
        TokenBalanceRepository $tokenBalanceRepository,
        TokenTransactionRepository $tokenTransactionRepository,
        TokenActionRepository $tokenActionRepository,
        LoggerInterface $logger
    ) {
        $this->entityManager = $entityManager;
        $this->tokenBalanceRepository = $tokenBalanceRepository;
        $this->tokenTransactionRepository = $tokenTransactionRepository;
        $this->tokenActionRepository = $tokenActionRepository;
        $this->logger = $logger;
    }

    /**
     * Obtiene o crea el balance de tokens para un instituto
     */
    public function getBalance(Instituto $instituto): TokenBalance
    {
        $balance = $this->tokenBalanceRepository->findOneBy(['instituto' => $instituto]);
        
        if (!$balance) {
            $balance = new TokenBalance();
            $balance->setInstituto($instituto);
            $balance->setBalance(0);
            $this->entityManager->persist($balance);
            $this->entityManager->flush();
        }
        
        return $balance;
    }

    /**
     * Verifica si el instituto tiene suficientes tokens para una acción
     */
    public function hasEnoughTokens(Instituto $instituto, string $actionCode): bool
    {
        $action = $this->tokenActionRepository->findByCode($actionCode);
        if (!$action || !$action->isActive()) {
            return true; // Si la acción no existe o está desactivada, permitir
        }

        $balance = $this->getBalance($instituto);
        return $balance->getBalance() >= $action->getCost();
    }

    /**
     * Consume tokens para una acción
     * 
     * @param Instituto $instituto
     * @param string $actionCode Código de la acción (ej: 'alumno.create')
     * @param User|null $user Usuario que realiza la acción
     * @param string|null $description Descripción adicional
     * @param string|null $entityType Tipo de entidad afectada
     * @param int|null $entityId ID de la entidad afectada
     * @return bool True si se consumieron tokens exitosamente, false si no hay suficientes tokens
     * @throws \RuntimeException Si la acción no existe o está desactivada
     */
    public function consumeTokens(
        Instituto $instituto,
        string $actionCode,
        ?User $user = null,
        ?string $description = null,
        ?string $entityType = null,
        ?int $entityId = null
    ): bool {
        $action = $this->tokenActionRepository->findByCode($actionCode);
        
        if (!$action) {
            $this->logger->warning("Token action not found: {$actionCode}");
            return true; // Si la acción no está configurada, permitir sin consumir tokens
        }

        if (!$action->isActive()) {
            return true; // Si la acción está desactivada, permitir sin consumir tokens
        }

        $cost = $action->getCost();
        if ($cost <= 0) {
            return true; // Si el costo es 0 o negativo, no consumir tokens
        }

        $balance = $this->getBalance($instituto);
        
        if ($balance->getBalance() < $cost) {
            $this->logger->warning("Insufficient tokens for {$instituto->getNombre()}. Required: {$cost}, Available: {$balance->getBalance()}");
            return false;
        }

        // Consumir tokens
        $balance->subtractTokens($cost);
        $newBalance = $balance->getBalance();

        // Registrar transacción
        $transaction = new TokenTransaction();
        $transaction->setInstituto($instituto);
        $transaction->setAction($actionCode);
        $transaction->setDescription($description ?? $action->getName());
        $transaction->setAmount(-$cost); // Negativo porque es un débito
        $transaction->setBalanceAfter($newBalance);
        $transaction->setUser($user);
        $transaction->setEntityType($entityType);
        $transaction->setEntityId($entityId);

        $this->entityManager->persist($transaction);
        $this->entityManager->flush();

        $this->logger->info("Tokens consumed: {$cost} for action {$actionCode} by {$instituto->getNombre()}. New balance: {$newBalance}");

        return true;
    }

    /**
     * Agrega tokens a un instituto (usado por super admin)
     */
    public function addTokens(
        Instituto $instituto,
        int $amount,
        ?User $user = null,
        ?string $description = null
    ): void {
        if ($amount <= 0) {
            throw new \InvalidArgumentException("Amount must be positive");
        }

        $balance = $this->getBalance($instituto);
        $balance->addTokens($amount);
        $newBalance = $balance->getBalance();

        // Registrar transacción de crédito
        $transaction = new TokenTransaction();
        $transaction->setInstituto($instituto);
        $transaction->setAction('tokens.added');
        $transaction->setDescription($description ?? "Tokens agregados manualmente");
        $transaction->setAmount($amount); // Positivo porque es un crédito
        $transaction->setBalanceAfter($newBalance);
        $transaction->setUser($user);

        $this->entityManager->persist($transaction);
        $this->entityManager->flush();

        $this->logger->info("Tokens added: {$amount} to {$instituto->getNombre()}. New balance: {$newBalance}");
    }

    /**
     * Obtiene el consumo de tokens en un período
     */
    public function getConsumptionByPeriod(Instituto $instituto, \DateTime $startDate, \DateTime $endDate): array
    {
        return $this->tokenTransactionRepository->getConsumptionByPeriod($instituto, $startDate, $endDate);
    }

    /**
     * Obtiene el consumo total en un período
     */
    public function getTotalConsumptionByPeriod(Instituto $instituto, \DateTime $startDate, \DateTime $endDate): int
    {
        return $this->tokenTransactionRepository->getTotalConsumptionByPeriod($instituto, $startDate, $endDate);
    }

    /**
     * Obtiene las transacciones recientes
     */
    public function getRecentTransactions(Instituto $instituto, int $limit = 50): array
    {
        return $this->tokenTransactionRepository->getRecentTransactions($instituto, $limit);
    }

    /**
     * Obtiene todas las acciones disponibles con sus costos
     */
    public function getAllActions(): array
    {
        return $this->tokenActionRepository->findAllActive();
    }

    /**
     * Obtiene el costo de una acción específica
     */
    public function getActionCost(string $actionCode): int
    {
        $action = $this->tokenActionRepository->findByCode($actionCode);
        return $action ? $action->getCost() : 0;
    }
}

