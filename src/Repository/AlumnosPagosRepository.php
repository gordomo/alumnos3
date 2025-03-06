<?php

namespace App\Repository;

use App\Entity\AlumnosPagos;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\OptimisticLockException;
use Doctrine\ORM\ORMException;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @method AlumnosPagos|null find($id, $lockMode = null, $lockVersion = null)
 * @method AlumnosPagos|null findOneBy(array $criteria, array $orderBy = null)
 * @method AlumnosPagos[]    findAll()
 * @method AlumnosPagos[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class AlumnosPagosRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AlumnosPagos::class);
    }

    /**
     * @throws ORMException
     * @throws OptimisticLockException
     */
    public function add(AlumnosPagos $entity, bool $flush = true): void
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
    public function remove(AlumnosPagos $entity, bool $flush = true): void
    {
        $this->_em->remove($entity);
        if ($flush) {
            $this->_em->flush();
        }
    }

    // /**
    //  * @return AlumnosPagos[] Returns an array of AlumnosPagos objects
    //  */

    public function findLastPagos($value, $desde, $hasta, $max = 50)
    {
        $query = $this->createQueryBuilder('a');

        if ($desde and $hasta) {
            $desde = new \DateTime($desde);
            $hasta = new \DateTime($hasta);
            $query->andWhere('a.fecha BETWEEN :desde and :hasta')->setParameters(['desde' => $desde, 'hasta' => $hasta]);
        }

        if ($value) {
            $query->andWhere('a.alumno IN (:val)')->setParameter('val', $value);
        }

        if ($max != '') {
            $query->setMaxResults($max);
        }

        return $query
            ->orderBy('a.ano, a.mes', 'DESC')
            ->getQuery()
            ->getResult()
        ;
    }

    public function findPagosAtiempo()
    {
        $query = $this->createQueryBuilder('a');
        return $query->where('DAY(a.fecha) < 21')->getQuery()->getResult();
    }
    public function findPagosFueraDeTiempo()
    {
        $query = $this->createQueryBuilder('a');
        return $query->where('DAY(a.fecha) >= 21')->getQuery()->getResult();
    }


    /*
    public function findOneBySomeField($value): ?AlumnosPagos
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
