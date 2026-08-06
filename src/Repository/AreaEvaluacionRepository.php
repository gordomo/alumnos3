<?php

namespace App\Repository;

use App\Entity\AreaEvaluacion;
use App\Entity\Instituto;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<AreaEvaluacion>
 */
class AreaEvaluacionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AreaEvaluacion::class);
    }

    /**
     * Áreas del instituto, en orden. Solo las vigentes por default.
     *
     * @return AreaEvaluacion[]
     */
    public function findByInstituto(Instituto $instituto, bool $soloActivas = true): array
    {
        $qb = $this->createQueryBuilder('a')
            ->andWhere('a.instituto = :instituto')
            ->setParameter('instituto', $instituto)
            ->orderBy('a.orden', 'ASC')
            ->addOrderBy('a.id', 'ASC');

        if ($soloActivas) {
            $qb->andWhere('a.activo = :activo')->setParameter('activo', true);
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * Cuántas evaluaciones tiene asignadas. Se consulta antes de ofrecer borrarla.
     */
    public function contarUsos(AreaEvaluacion $area): int
    {
        return (int) $this->getEntityManager()->createQueryBuilder()
            ->select('COUNT(e.id)')
            ->from(\App\Entity\Evaluacion::class, 'e')
            ->andWhere('e.area = :area')
            ->setParameter('area', $area)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Siguiente valor de orden para agregar un área al final.
     */
    public function siguienteOrden(Instituto $instituto): int
    {
        $max = $this->createQueryBuilder('a')
            ->select('MAX(a.orden)')
            ->andWhere('a.instituto = :instituto')
            ->setParameter('instituto', $instituto)
            ->getQuery()
            ->getSingleScalarResult();

        return ((int) $max) + 1;
    }
}
