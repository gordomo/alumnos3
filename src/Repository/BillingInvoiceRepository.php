<?php

namespace App\Repository;

use App\Entity\BillingInvoice;
use App\Entity\Instituto;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<BillingInvoice>
 */
class BillingInvoiceRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, BillingInvoice::class);
    }

    /**
     * Encuentra facturas por instituto y período
     */
    public function findByInstitutoAndPeriod(Instituto $instituto, int $year, int $month): ?BillingInvoice
    {
        return $this->findOneBy([
            'instituto' => $instituto,
            'periodYear' => $year,
            'periodMonth' => $month
        ]);
    }

    /**
     * Obtiene facturas pendientes por instituto
     */
    public function findPendingByInstituto(Instituto $instituto): array
    {
        return $this->findBy([
            'instituto' => $instituto,
            'status' => 'pending'
        ], ['periodYear' => 'DESC', 'periodMonth' => 'DESC']);
    }

    /**
     * Obtiene facturas por instituto ordenadas por período descendente
     */
    public function findByInstitutoOrderedByPeriod(Instituto $instituto): array
    {
        return $this->findBy(
            ['instituto' => $instituto],
            ['periodYear' => 'DESC', 'periodMonth' => 'DESC']
        );
    }

    /**
     * Obtiene todas las facturas pendientes del sistema
     */
    public function findAllPending(): array
    {
        return $this->findBy(
            ['status' => 'pending'],
            ['createdAt' => 'ASC']
        );
    }

    /**
     * Obtiene facturas por período específico
     */
    public function findByPeriod(int $year, int $month): array
    {
        return $this->findBy([
            'periodYear' => $year,
            'periodMonth' => $month
        ]);
    }

    /**
     * Obtiene el consumo histórico por instituto
     */
    public function getBillingHistory(Instituto $instituto, int $limit = 12): array
    {
        return $this->createQueryBuilder('b')
            ->where('b.instituto = :instituto')
            ->setParameter('instituto', $instituto)
            ->orderBy('b.periodYear', 'DESC')
            ->addOrderBy('b.periodMonth', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Calcula el total facturado en un período
     */
    public function getTotalBilledByPeriod(int $year, int $month): float
    {
        $result = $this->createQueryBuilder('b')
            ->select('SUM(b.totalAmount) as total')
            ->where('b.periodYear = :year')
            ->andWhere('b.periodMonth = :month')
            ->andWhere('b.status = :status')
            ->setParameter('year', $year)
            ->setParameter('month', $month)
            ->setParameter('status', 'paid')
            ->getQuery()
            ->getSingleScalarResult();

        return (float) ($result ?? 0);
    }

    /**
     * Obtiene estadísticas de facturación por instituto
     */
    public function getInstitutoBillingStats(Instituto $instituto): array
    {
        $qb = $this->createQueryBuilder('b')
            ->select([
                'COUNT(b.id) as totalInvoices',
                'SUM(CASE WHEN b.status = \'paid\' THEN b.totalAmount ELSE 0 END) as totalPaid',
                'SUM(CASE WHEN b.status = \'pending\' THEN b.totalAmount ELSE 0 END) as totalPending',
                'AVG(b.activeStudentsCount) as avgStudents'
            ])
            ->where('b.instituto = :instituto')
            ->setParameter('instituto', $instituto);

        $result = $qb->getQuery()->getSingleResult();

        return [
            'totalInvoices' => (int) ($result['totalInvoices'] ?? 0),
            'totalPaid' => (float) ($result['totalPaid'] ?? 0),
            'totalPending' => (float) ($result['totalPending'] ?? 0),
            'avgStudents' => (float) ($result['avgStudents'] ?? 0)
        ];
    }
}
