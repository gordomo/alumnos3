<?php

namespace App\Repository;

use App\Entity\TokenAction;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<TokenAction>
 */
class TokenActionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, TokenAction::class);
    }

    public function findByCode(string $code): ?TokenAction
    {
        return $this->findOneBy(['code' => $code, 'active' => true]);
    }

    public function findAllActive(): array
    {
        return $this->findBy(['active' => true], ['name' => 'ASC']);
    }
}

