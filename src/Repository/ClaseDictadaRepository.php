<?php

namespace App\Repository;

use App\Entity\ClaseDictada;
use App\Entity\Curso;
use App\Entity\Instituto;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ClaseDictada>
 */
class ClaseDictadaRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ClaseDictada::class);
    }

    /**
     * El diario de un curso, de la clase más nueva a la más vieja.
     *
     * @return ClaseDictada[]
     */
    public function findByCurso(Curso $curso, ?int $limite = null): array
    {
        $qb = $this->createQueryBuilder('c')
            ->andWhere('c.curso = :curso')
            ->setParameter('curso', $curso)
            ->orderBy('c.fecha', 'DESC')
            ->addOrderBy('c.id', 'DESC');

        if ($limite) {
            $qb->setMaxResults($limite);
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * La clase de un curso en una fecha, para no duplicarla al guardar.
     */
    public function findUnaPorFecha(Curso $curso, \DateTimeInterface $fecha): ?ClaseDictada
    {
        return $this->createQueryBuilder('c')
            ->andWhere('c.curso = :curso')
            ->andWhere('c.fecha = :fecha')
            ->setParameter('curso', $curso)
            ->setParameter('fecha', $fecha->format('Y-m-d'))
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Cuántas clases tiene registrada cada curso del instituto.
     *
     * @return array<int, int>
     */
    public function contarPorCurso(Instituto $instituto): array
    {
        $filas = $this->createQueryBuilder('c')
            ->select('cu.id AS curso, COUNT(c.id) AS cantidad')
            ->innerJoin('c.curso', 'cu')
            ->andWhere('c.instituto = :instituto')
            ->setParameter('instituto', $instituto)
            ->groupBy('cu.id')
            ->getQuery()
            ->getScalarResult();

        $porCurso = [];
        foreach ($filas as $fila) {
            $porCurso[(int) $fila['curso']] = (int) $fila['cantidad'];
        }

        return $porCurso;
    }
}
