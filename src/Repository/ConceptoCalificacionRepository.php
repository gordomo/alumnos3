<?php

namespace App\Repository;

use App\Entity\ConceptoCalificacion;
use App\Entity\Instituto;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ConceptoCalificacion>
 */
class ConceptoCalificacionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ConceptoCalificacion::class);
    }

    /**
     * Escala conceptual del instituto, en orden. Solo los vigentes por default.
     *
     * @return ConceptoCalificacion[]
     */
    public function findByInstituto(Instituto $instituto, bool $soloActivos = true): array
    {
        $qb = $this->createQueryBuilder('c')
            ->andWhere('c.instituto = :instituto')
            ->setParameter('instituto', $instituto)
            ->orderBy('c.orden', 'ASC')
            ->addOrderBy('c.id', 'ASC');

        if ($soloActivos) {
            $qb->andWhere('c.activo = :activo')->setParameter('activo', true);
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * Cuántas calificaciones usan este concepto. Se consulta antes de ofrecer borrarlo.
     */
    public function contarUsos(ConceptoCalificacion $concepto): int
    {
        return (int) $this->getEntityManager()->createQueryBuilder()
            ->select('COUNT(cal.id)')
            ->from(\App\Entity\Calificacion::class, 'cal')
            ->andWhere('cal.concepto = :concepto')
            ->setParameter('concepto', $concepto)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Siguiente valor de orden para agregar un concepto al final de la escala.
     */
    public function siguienteOrden(Instituto $instituto): int
    {
        $max = $this->createQueryBuilder('c')
            ->select('MAX(c.orden)')
            ->andWhere('c.instituto = :instituto')
            ->setParameter('instituto', $instituto)
            ->getQuery()
            ->getSingleScalarResult();

        return ((int) $max) + 1;
    }
}
