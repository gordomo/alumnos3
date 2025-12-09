<?php

namespace App\Repository;

use App\Entity\TokenBalance;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<TokenBalance>
 */
class TokenBalanceRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, TokenBalance::class);
    }

    public function findOrCreateForInstituto($instituto): TokenBalance
    {
        $balance = $this->findOneBy(['instituto' => $instituto]);
        
        if (!$balance) {
            $balance = new TokenBalance();
            $balance->setInstituto($instituto);
            $balance->setBalance(0);
        }
        
        return $balance;
    }
}

