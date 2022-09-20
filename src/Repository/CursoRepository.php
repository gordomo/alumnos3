<?php

namespace App\Repository;

use App\Entity\Curso;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\OptimisticLockException;
use Doctrine\ORM\ORMException;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @method Curso|null find($id, $lockMode = null, $lockVersion = null)
 * @method Curso|null findOneBy(array $criteria, array $orderBy = null)
 * @method Curso[]    findAll()
 * @method Curso[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class CursoRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Curso::class);
    }

    /**
     * @throws ORMException
     * @throws OptimisticLockException
     */
    public function add(Curso $entity, bool $flush = true): void
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
    public function remove(Curso $entity, bool $flush = true): void
    {
        $entity->setDisabled(true);
        $this->_em->persist($entity);
        /*$this->_em->remove($entity);*/
        if ($flush) {
            $this->_em->flush();
        }
    }

    public function habilitar(Curso $entity, bool $flush = true): void
    {
        $entity->setDisabled(false);
        $this->_em->persist($entity);
        /*$this->_em->remove($entity);*/
        if ($flush) {
            $this->_em->flush();
        }
    }

    // /**
    //  * @return Curso[] Returns an array of Curso objects
    //  */

    public function findByDia($value)
    {
        return $this->createQueryBuilder('c')
            ->andWhere('c.dias like :val')
            ->setParameter('val', '%'.$value.'%')
            ->orderBy('c.id', 'ASC')
            ->getQuery()
            ->getResult()
        ;
    }

    public function findCursosConAlumnos($value)
    {
        $query = $this->_em->createQuery(
            'SELECT c, a
            FROM App\Entity\Curso c
            INNER JOIN c.alumnos a
            WHERE a.id IN (:ids)'
        )->setParameter('ids', $value);

        return $query->getResult();
    }

    /*
    public function findOneBySomeField($value): ?Curso
    {
        return $this->createQueryBuilder('c')
            ->andWhere('c.exampleField = :val')
            ->setParameter('val', $value)
            ->getQuery()
            ->getOneOrNullResult()
        ;
    }
    */
}
