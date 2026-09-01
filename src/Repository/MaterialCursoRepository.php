<?php

namespace App\Repository;

use App\Entity\Curso;
use App\Entity\Instituto;
use App\Entity\MaterialCurso;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<MaterialCurso>
 */
class MaterialCursoRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MaterialCurso::class);
    }

    /**
     * Los materiales de un curso. Con $soloVisibles se dejan afuera los apagados, que es lo que
     * corresponde cuando lo mira un alumn@.
     *
     * @return MaterialCurso[]
     */
    public function findByCurso(Curso $curso, bool $soloVisibles = false): array
    {
        $qb = $this->createQueryBuilder('m')
            ->andWhere('m.curso = :curso')
            ->setParameter('curso', $curso)
            ->orderBy('m.createdAt', 'DESC');

        if ($soloVisibles) {
            $qb->andWhere('m.visible = true');
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * @return array<int, int>
     */
    public function contarPorCurso(Instituto $instituto): array
    {
        $filas = $this->createQueryBuilder('m')
            ->select('cu.id AS curso, COUNT(m.id) AS cantidad')
            ->innerJoin('m.curso', 'cu')
            ->andWhere('m.instituto = :instituto')
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
