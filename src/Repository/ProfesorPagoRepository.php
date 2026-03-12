<?php

namespace App\Repository;

use App\Entity\ProfesorPago;
use App\Entity\Profesor;
use App\Entity\Instituto;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ProfesorPago>
 */
class ProfesorPagoRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ProfesorPago::class);
    }

    /**
     * Obtiene los pagos de un profesor en un mes/año específico
     */
    public function findByProfesorMesAno(Profesor $profesor, int $mes, int $ano): array
    {
        return $this->createQueryBuilder('pp')
            ->andWhere('pp.profesor = :profesor')
            ->andWhere('pp.mes = :mes')
            ->andWhere('pp.ano = :ano')
            ->setParameter('profesor', $profesor)
            ->setParameter('mes', $mes)
            ->setParameter('ano', $ano)
            ->orderBy('pp.fechaPago', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Obtiene el total pagado a un profesor en un mes/año específico
     */
    public function getTotalPagadoProfesorMesAno(Profesor $profesor, int $mes, int $ano): float
    {
        $result = $this->createQueryBuilder('pp')
            ->select('SUM(pp.monto)')
            ->andWhere('pp.profesor = :profesor')
            ->andWhere('pp.mes = :mes')
            ->andWhere('pp.ano = :ano')
            ->setParameter('profesor', $profesor)
            ->setParameter('mes', $mes)
            ->setParameter('ano', $ano)
            ->getQuery()
            ->getSingleScalarResult();

        return $result ? (float)$result : 0.0;
    }

    /**
     * Obtiene todos los pagos de un instituto
     */
    public function findByInstituto(Instituto $instituto): array
    {
        return $this->createQueryBuilder('pp')
            ->innerJoin('pp.profesor', 'prof')
            ->andWhere('prof.instituto = :instituto')
            ->setParameter('instituto', $instituto)
            ->orderBy('pp.fechaPago', 'DESC')
            ->getQuery()
            ->getResult();
    }
}
