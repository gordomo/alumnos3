<?php

namespace App\Repository;

use App\Entity\AsistenciaProfesores;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\OptimisticLockException;
use Doctrine\ORM\ORMException;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @method AsistenciaProfesores|null find($id, $lockMode = null, $lockVersion = null)
 * @method AsistenciaProfesores|null findOneBy(array $criteria, array $orderBy = null)
 * @method AsistenciaProfesores[]    findAll()
 * @method AsistenciaProfesores[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class AsistenciaProfesoresRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AsistenciaProfesores::class);
    }

    /**
     * @throws ORMException
     * @throws OptimisticLockException
     */
    public function add(AsistenciaProfesores $entity, bool $flush = true): void
    {
        $this->_em->persist($entity);
        if ($flush) {
            $this->_em->flush();
        }
    }

    /**
     * @throws ORMException
     * @throws OptimisticLockException
     */
    public function remove(AsistenciaProfesores $entity, bool $flush = true): void
    {
        $this->_em->remove($entity);
        if ($flush) {
            $this->_em->flush();
        }
    }

    // /**
    //  * @return AsistenciaProfesores[] Returns an array of AsistenciaProfesores objects
    //  */

    public function findByFechas($from, $to)
    {
        return $this->createQueryBuilder('a')
            ->andWhere('a.fecha >= :from')
            ->setParameter('from', $from)
            ->andWhere('a.fecha <= :to')
            ->setParameter('to', $to)
            ->orderBy('a.id', 'ASC')
            ->getQuery()
            ->getResult()
        ;
    }

    public function findByFechaProfe($date, $profe)
    {
        return $this->createQueryBuilder('a')
            ->andWhere('a.fecha = :date')
            ->setParameter('date', $date)
            ->andWhere('a.profesor = :profe')
            ->setParameter('profe', $profe)
            ->orderBy('a.id', 'ASC')
            ->getQuery()
            ->getResult()
            ;
    }


    /*
    public function findOneBySomeField($value): ?AsistenciaProfesores
    {
        return $this->createQueryBuilder('a')
            ->andWhere('a.exampleField = :val')
            ->setParameter('val', $value)
            ->getQuery()
            ->getOneOrNullResult()
        ;
    }
    */
}
