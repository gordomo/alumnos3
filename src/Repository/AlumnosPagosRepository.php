<?php

namespace App\Repository;

use App\Entity\AlumnosPagos;
use App\Entity\Instituto;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\OptimisticLockException;
use Doctrine\ORM\ORMException;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<AlumnosPagos>
 *
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

    public function findPagosAtiempo($instituto)
    {
        $diaCorte = $this->getPrimerDiaVencimiento($instituto);
        $query = $this->createQueryBuilder('a');
        $query->where('DAY(a.fecha) < :diaCorte')
            ->setParameter('diaCorte', $diaCorte);
        $query->join('a.alumno', 'p')
            ->andWhere('p.instituto = :instituto')  
            ->setParameter('instituto', $instituto);

        return $query->getQuery()->getResult();
    }

    public function findPagosFueraDeTiempo($instituto)
    {
        $diaCorte = $this->getPrimerDiaVencimiento($instituto);
        $query = $this->createQueryBuilder('a');
        $query->where('DAY(a.fecha) >= :diaCorte')
            ->setParameter('diaCorte', $diaCorte);
        $query->join('a.alumno', 'p')
            ->andWhere('p.instituto = :instituto')  
            ->setParameter('instituto', $instituto);

        return $query->getQuery()->getResult();
    }

    public function findByInstituto(Instituto $instituto): array
    {
        return $this->createQueryBuilder('p')
            ->join('p.alumno', 'a')
            ->where('a.instituto = :instituto')
            ->setParameter('instituto', $instituto)
            ->orderBy('p.fecha', 'DESC')
            ->getQuery()
            ->getResult();
    }

    public function findByAlumno(int $alumnoId): array
    {
        return $this->createQueryBuilder('p')
            ->join('p.alumno', 'a')
            ->where('a.id = :alumnoId')
            ->setParameter('alumnoId', $alumnoId)
            ->orderBy('p.fecha', 'DESC')
            ->getQuery()
            ->getResult();
    }

    public function save(AlumnosPagos $entity, bool $flush = false): void
    {
        $this->getEntityManager()->persist($entity);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    private function getPrimerDiaVencimiento($instituto): int
    {
        if (!$instituto || !method_exists($instituto, 'getVencimientos')) {
            return 5;
        }

        $vencimientos = $instituto->getVencimientos()->toArray();
        if (empty($vencimientos)) {
            return 5;
        }

        usort($vencimientos, function($a, $b) {
            return $a->getDiaVencimiento() <=> $b->getDiaVencimiento();
        });

        return (int) $vencimientos[0]->getDiaVencimiento();
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
