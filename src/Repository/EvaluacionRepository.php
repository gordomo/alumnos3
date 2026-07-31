<?php

namespace App\Repository;

use App\Entity\Curso;
use App\Entity\Evaluacion;
use App\Entity\Instituto;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Evaluacion>
 */
class EvaluacionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Evaluacion::class);
    }

    /**
     * Evaluaciones de un curso, de la más antigua a la más nueva.
     *
     * @return Evaluacion[]
     */
    public function findByCurso(Curso $curso): array
    {
        return $this->createQueryBuilder('e')
            ->andWhere('e.curso = :curso')
            ->setParameter('curso', $curso)
            ->orderBy('e.fecha', 'ASC')
            ->addOrderBy('e.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Cuántas evaluaciones del curso cuentan para el promedio. Se usa como compuerta:
     * sin ninguna, el criterio de notas no puede desaprobar a nadie.
     */
    public function contarQueCuentanParaPromedio(Curso $curso): int
    {
        return (int) $this->createQueryBuilder('e')
            ->select('COUNT(e.id)')
            ->andWhere('e.curso = :curso')
            ->andWhere('e.cuentaParaPromedio = :cuenta')
            ->setParameter('curso', $curso)
            ->setParameter('cuenta', true)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Cantidad de evaluaciones por curso, en una sola query, para listados de cursos.
     *
     * @param Curso[] $cursos
     * @return array<int, int> [cursoId => cantidad]
     */
    public function contarPorCursos(array $cursos): array
    {
        if (!$cursos) {
            return [];
        }

        $filas = $this->createQueryBuilder('e')
            ->select('IDENTITY(e.curso) AS cursoId', 'COUNT(e.id) AS total')
            ->andWhere('e.curso IN (:cursos)')
            ->setParameter('cursos', $cursos)
            ->groupBy('e.curso')
            ->getQuery()
            ->getArrayResult();

        $porCurso = [];
        foreach ($filas as $fila) {
            $porCurso[(int) $fila['cursoId']] = (int) $fila['total'];
        }

        return $porCurso;
    }

    /**
     * Evaluaciones del instituto en las que participó un alumno, con sus notas.
     *
     * @return Evaluacion[]
     */
    public function findByInstituto(Instituto $instituto): array
    {
        return $this->createQueryBuilder('e')
            ->innerJoin('e.curso', 'c')->addSelect('c')
            ->andWhere('e.instituto = :instituto')
            ->setParameter('instituto', $instituto)
            ->orderBy('e.fecha', 'DESC')
            ->getQuery()
            ->getResult();
    }
}
