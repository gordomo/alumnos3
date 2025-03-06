<?php

namespace App\Repository;

use App\Entity\Profesor;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\OptimisticLockException;
use Doctrine\ORM\ORMException;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @method Profesor|null find($id, $lockMode = null, $lockVersion = null)
 * @method Profesor|null findOneBy(array $criteria, array $orderBy = null)
 * @method Profesor[]    findAll()
 * @method Profesor[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class ProfesorRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Profesor::class);
    }

    /**
     * @throws ORMException
     * @throws OptimisticLockException
     */
    public function add(Profesor $entity, bool $flush = true): void
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
    public function remove(Profesor $entity, bool $flush = true): void
    {
        $this->_em->remove($entity);
        if ($flush) {
            $this->_em->flush();
        }
    }

    // /**
    //  * @return Profesor[] Returns an array of Profesor objects
    //  */
    public function countProfesors($value)
    {
        $query =  $this->createQueryBuilder('p')->select('count(p.id)');
            if ($value) {
                $query->andWhere('p.apellido like :val')->setParameter('val', '%'.$value.'%');
            }

        return $query->orderBy('p.id', 'ASC')->getQuery()->getOneOrNullResult();
    }


    public function findByApellido($value, $limit, $offset)
    {
        $query =  $this->createQueryBuilder('p');
        if ($value) {
            $query->andWhere('p.apellido like :val')->setParameter('val', '%'.$value.'%');
        }
        if ($limit) {
            $query->setMaxResults($limit);
        }
        if ($offset) {
            $query->setFirstResult($offset);
        }

        return $query->orderBy('p.apellido', 'ASC')->getQuery()->getResult();
    }
}
