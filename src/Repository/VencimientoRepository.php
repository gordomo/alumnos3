<?php

namespace App\Repository;

use App\Entity\Vencimiento;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Vencimiento>
 *
 * @method Vencimiento|null find($id, $lockMode = null, $lockVersion = null)
 * @method Vencimiento|null findOneBy(array $criteria, array $orderBy = null)
 * @method Vencimiento[]    findAll()
 * @method Vencimiento[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class VencimientoRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Vencimiento::class);
    }

    /**
     * Encuentra todos los vencimientos de un instituto ordenados por orden
     */
    public function findByInstitutoOrdered($instituto): array
    {
        return $this->createQueryBuilder('v')
            ->andWhere('v.instituto = :instituto')
            ->setParameter('instituto', $instituto)
            ->orderBy('v.orden', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Encuentra el próximo vencimiento basado en la fecha actual
     */
    public function findNextVencimiento($instituto): ?Vencimiento
    {
        $timezone = null;
        if ($instituto && method_exists($instituto, 'getConfiguracion') && $instituto->getConfiguracion()) {
            $timezone = $instituto->getConfiguracion()->getTimezone();
        }

        if (!empty($timezone)) {
            try {
                $hoy = new \DateTimeImmutable('now', new \DateTimeZone($timezone));
            } catch (\Exception $e) {
                $hoy = new \DateTimeImmutable();
            }
        } else {
            $hoy = new \DateTimeImmutable();
        }
        $diaActual = (int)$hoy->format('d');

        return $this->createQueryBuilder('v')
            ->andWhere('v.instituto = :instituto')
            ->andWhere('v.diaVencimiento >= :diaActual')
            ->setParameter('instituto', $instituto)
            ->setParameter('diaActual', $diaActual)
            ->orderBy('v.diaVencimiento', 'ASC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }
} 