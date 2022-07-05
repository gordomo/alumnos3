<?php

namespace App\Repository;

use App\Entity\Alumno;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\OptimisticLockException;
use Doctrine\ORM\ORMException;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @method Alumno|null find($id, $lockMode = null, $lockVersion = null)
 * @method Alumno|null findOneBy(array $criteria, array $orderBy = null)
 * @method Alumno[]    findAll()
 * @method Alumno[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class AlumnoRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Alumno::class);
    }

    /**
     * @throws ORMException
     * @throws OptimisticLockException
     */
    public function add(Alumno $entity, bool $flush = true): void
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
    public function remove(Alumno $entity, bool $flush = true): void
    {
        $this->_em->remove($entity);
        if ($flush) {
            $this->_em->flush();
        }
    }

    public function flush()
    {
        $this->_em->flush();
    }

    // /**
    //  * @return Alumno[] Returns an array of Alumno objects
    //  */
    public function countAlumnos($value, $activo)
    {
        $query =  $this->createQueryBuilder('p')->select('count(p.id)');
        if ($value) {
            $query->andWhere('p.apellido like :val')->setParameter('val', '%'.$value.'%');
        }
        if ($activo !== 'todos') {
            $query->andWhere('p.activo = :activo')->setParameter('activo', $activo);
        }

        return $query->orderBy('p.id', 'ASC')->getQuery()->getOneOrNullResult();
    }

    // /**
    //  * @return Alumno[] Returns an array of Alumno objects
    //  */

    public function findByApellido($value, $limit, $offset, $activo = null)
    {
        $query =  $this->createQueryBuilder('p');
        if ($value) {
            $query->andWhere('p.apellido like :val')->setParameter('val', '%'.$value.'%');
        }
        if ($activo !== 'todos') {
            $query->andWhere('p.activo = :activo')->setParameter('activo', $activo);
        }
        if ($limit) {
            $query->setMaxResults($limit);
        }
        if ($offset) {
            $query->setFirstResult($offset);
        }

        return $query->orderBy('p.apellido', 'ASC')->getQuery()->getResult();
    }

    // /**
    //  * @return Alumno[] Returns an array of Alumno objects
    //  */
    /*
    public function findByExampleField($value)
    {
        return $this->createQueryBuilder('a')
            ->andWhere('a.exampleField = :val')
            ->setParameter('val', $value)
            ->orderBy('a.id', 'ASC')
            ->setMaxResults(10)
            ->getQuery()
            ->getResult()
        ;
    }
    */

    /*
    public function findOneBySomeField($value): ?Alumno
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
