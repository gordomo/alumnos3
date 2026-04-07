<?php

namespace App\Repository;

use App\Entity\Alumno;
use App\Entity\Instituto;
use App\Entity\SaldoFavor;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<SaldoFavor>
 *
 * @method SaldoFavor|null find($id, $lockMode = null, $lockVersion = null)
 * @method SaldoFavor|null findOneBy(array $criteria, array $orderBy = null)
 * @method SaldoFavor[]    findAll()
 * @method SaldoFavor[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class SaldoFavorRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SaldoFavor::class);
    }

    public function add(SaldoFavor $entity, bool $flush = false): void
    {
        $this->getEntityManager()->persist($entity);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    /**
     * Encuentra todos los saldos a favor de un alumno
     */
    public function findByAlumno(Alumno $alumno): array
    {
        return $this->createQueryBuilder('s')
            ->andWhere('s.alumno = :alumno')
            ->setParameter('alumno', $alumno)
            ->orderBy('s.fecha', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Encuentra saldos con disponible > 0
     */
    public function findConSaldoDisponible(Alumno $alumno): array
    {
        return $this->createQueryBuilder('s')
            ->andWhere('s.alumno = :alumno')
            ->andWhere('s.montoDisponible > 0')
            ->setParameter('alumno', $alumno)
            ->orderBy('s.fecha', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Suma el saldo disponible total de un alumno
     */
    public function getSaldoDisponibleTotal(Alumno $alumno): float
    {
        $result = $this->createQueryBuilder('s')
            ->select('SUM(s.montoDisponible)')
            ->andWhere('s.alumno = :alumno')
            ->andWhere('s.montoDisponible > 0')
            ->setParameter('alumno', $alumno)
            ->getQuery()
            ->getSingleScalarResult();

        return (float) ($result ?? 0);
    }

    /**
     * Encuentra saldos a favor por instituto
     */
    public function findByInstituto(Instituto $instituto): array
    {
        return $this->createQueryBuilder('s')
            ->andWhere('s.instituto = :instituto')
            ->setParameter('instituto', $instituto)
            ->orderBy('s.fecha', 'DESC')
            ->getQuery()
            ->getResult();
    }
}
