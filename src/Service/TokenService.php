<?php

namespace App\Service;

use App\Entity\Instituto;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Servicio de compatibilidad temporal para TokenService
 * Este servicio no hace nada ya que el sistema de tokens fue reemplazado por facturación por alumnos
 */
class TokenService
{
    public function __construct(
        EntityManagerInterface $entityManager,
        LoggerInterface $logger
    ) {
        // Servicio de compatibilidad - no hace nada
    }

    public function getBalance(Instituto $instituto): object
    {
        // Retorna un objeto mock para compatibilidad
        return new class {
            public int $balance = 999999;
            public function getBalance(): int { return $this->balance; }
            public function getUpdatedAt() { return new \DateTime(); }
        };
    }

    public function hasEnoughTokens(Instituto $instituto, string $actionCode): bool
    {
        // Siempre retorna true - sin límites
        return true;
    }

    public function consumeTokens(Instituto $instituto, string $actionCode, ?User $user = null, ?string $description = null, ?string $entityType = null, ?int $entityId = null): bool
    {
        // No consume tokens - operación gratuita
        return true;
    }

    public function addTokens(Instituto $instituto, int $amount, ?User $user = null, ?string $description = null): void
    {
        // No hace nada - tokens ya no existen
    }

    public function getConsumptionByPeriod(Instituto $instituto, \DateTime $startDate, \DateTime $endDate): array
    {
        // Retorna array vacío para compatibilidad
        return [];
    }

    public function getTotalConsumptionByPeriod(Instituto $instituto, \DateTime $startDate, \DateTime $endDate): int
    {
        // Siempre retorna 0
        return 0;
    }

    public function getRecentTransactions(Instituto $instituto, int $limit = 50): array
    {
        // Retorna array vacío
        return [];
    }

    public function getAllActions(): array
    {
        // Retorna array vacío
        return [];
    }

    public function getActionCost(string $actionCode): int
    {
        // Siempre retorna 0
        return 0;
    }
}
