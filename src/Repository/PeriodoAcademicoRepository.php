<?php

namespace App\Repository;

use App\Entity\Instituto;
use App\Entity\PeriodoAcademico;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PeriodoAcademico>
 */
class PeriodoAcademicoRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PeriodoAcademico::class);
    }

    /**
     * Períodos del instituto, en orden. Solo los vigentes por default.
     *
     * @return PeriodoAcademico[]
     */
    public function findByInstituto(Instituto $instituto, bool $soloActivos = true): array
    {
        $qb = $this->createQueryBuilder('p')
            ->andWhere('p.instituto = :instituto')
            ->setParameter('instituto', $instituto)
            ->orderBy('p.orden', 'ASC')
            ->addOrderBy('p.id', 'ASC');

        if ($soloActivos) {
            $qb->andWhere('p.activo = :activo')->setParameter('activo', true);
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * El período al que corresponde una fecha, o null si ninguno la contiene.
     *
     * Solo se consideran los de cursada: los de examen son instancias que el profesor elige
     * a mano, porque su mes suele solaparse con el de un trimestre.
     */
    public function findParaFecha(Instituto $instituto, \DateTimeInterface $fecha): ?PeriodoAcademico
    {
        $mes = (int) $fecha->format('n');

        foreach ($this->findByInstituto($instituto) as $periodo) {
            if (!$periodo->esExamen() && $periodo->contieneMes($mes)) {
                return $periodo;
            }
        }

        return null;
    }

    /**
     * Cuántas evaluaciones tiene asignadas. Se consulta antes de ofrecer borrarlo.
     */
    public function contarUsos(PeriodoAcademico $periodo): int
    {
        return (int) $this->getEntityManager()->createQueryBuilder()
            ->select('COUNT(e.id)')
            ->from(\App\Entity\Evaluacion::class, 'e')
            ->andWhere('e.periodo = :periodo')
            ->setParameter('periodo', $periodo)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Siguiente valor de orden para agregar un período al final.
     */
    public function siguienteOrden(Instituto $instituto): int
    {
        $max = $this->createQueryBuilder('p')
            ->select('MAX(p.orden)')
            ->andWhere('p.instituto = :instituto')
            ->setParameter('instituto', $instituto)
            ->getQuery()
            ->getSingleScalarResult();

        return ((int) $max) + 1;
    }
}
