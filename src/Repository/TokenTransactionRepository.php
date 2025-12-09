<?php

namespace App\Repository;

use App\Entity\TokenTransaction;
use App\Entity\Instituto;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<TokenTransaction>
 */
class TokenTransactionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, TokenTransaction::class);
    }

    public function getConsumptionByPeriod(Instituto $instituto, \DateTime $startDate, \DateTime $endDate): array
    {
        return $this->createQueryBuilder('t')
            ->select('t.action, SUM(ABS(t.amount)) as total')
            ->where('t.instituto = :instituto')
            ->andWhere('t.amount < 0') // Solo débitos
            ->andWhere('t.createdAt >= :startDate')
            ->andWhere('t.createdAt <= :endDate')
            ->setParameter('instituto', $instituto)
            ->setParameter('startDate', $startDate)
            ->setParameter('endDate', $endDate)
            ->groupBy('t.action')
            ->orderBy('total', 'DESC')
            ->getQuery()
            ->getResult();
    }

    public function getTotalConsumptionByPeriod(Instituto $instituto, \DateTime $startDate, \DateTime $endDate): int
    {
        $result = $this->createQueryBuilder('t')
            ->select('SUM(ABS(t.amount))')
            ->where('t.instituto = :instituto')
            ->andWhere('t.amount < 0') // Solo débitos
            ->andWhere('t.createdAt >= :startDate')
            ->andWhere('t.createdAt <= :endDate')
            ->setParameter('instituto', $instituto)
            ->setParameter('startDate', $startDate)
            ->setParameter('endDate', $endDate)
            ->getQuery()
            ->getSingleScalarResult();

        return (int) ($result ?? 0);
    }

    public function getRecentTransactions(Instituto $instituto, int $limit = 50): array
    {
        return $this->createQueryBuilder('t')
            ->where('t.instituto = :instituto')
            ->setParameter('instituto', $instituto)
            ->orderBy('t.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }
}

