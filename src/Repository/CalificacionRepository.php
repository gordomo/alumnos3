<?php

namespace App\Repository;

use App\Entity\Alumno;
use App\Entity\AlumnoCursoHistorico;
use App\Entity\Calificacion;
use App\Entity\Curso;
use App\Entity\Evaluacion;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Calificacion>
 */
class CalificacionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Calificacion::class);
    }

    /**
     * Notas de una evaluación, indexadas por id de histórico.
     *
     * Una sola query para armar la grilla completa, en lugar de un findOneBy por alumno.
     *
     * @return array<int, Calificacion>
     */
    public function findByEvaluacionIndexadoPorHistorico(Evaluacion $evaluacion): array
    {
        $calificaciones = $this->createQueryBuilder('c')
            ->leftJoin('c.concepto', 'co')->addSelect('co')
            ->andWhere('c.evaluacion = :evaluacion')
            ->setParameter('evaluacion', $evaluacion)
            ->getQuery()
            ->getResult();

        $porHistorico = [];
        foreach ($calificaciones as $calificacion) {
            $porHistorico[$calificacion->getCursoHistorico()->getId()] = $calificacion;
        }

        return $porHistorico;
    }

    /**
     * Todas las notas de un curso agrupadas por histórico, con la evaluación y el concepto
     * ya cargados. Es la base del cálculo de promedios sin N+1.
     *
     * @return array<int, Calificacion[]> [historicoId => Calificacion[]]
     */
    public function findByCursoAgrupadoPorHistorico(Curso $curso): array
    {
        $calificaciones = $this->createQueryBuilder('c')
            ->innerJoin('c.evaluacion', 'e')->addSelect('e')
            ->leftJoin('c.concepto', 'co')->addSelect('co')
            ->andWhere('e.curso = :curso')
            ->setParameter('curso', $curso)
            ->orderBy('e.fecha', 'ASC')
            ->addOrderBy('e.id', 'ASC')
            ->getQuery()
            ->getResult();

        $porHistorico = [];
        foreach ($calificaciones as $calificacion) {
            $porHistorico[$calificacion->getCursoHistorico()->getId()][] = $calificacion;
        }

        return $porHistorico;
    }

    /**
     * Notas de un histórico concreto, para el detalle de un alumno en un curso.
     *
     * @return Calificacion[]
     */
    public function findByHistorico(AlumnoCursoHistorico $historico): array
    {
        return $this->createQueryBuilder('c')
            ->innerJoin('c.evaluacion', 'e')->addSelect('e')
            ->leftJoin('c.concepto', 'co')->addSelect('co')
            ->andWhere('c.cursoHistorico = :historico')
            ->setParameter('historico', $historico)
            ->orderBy('e.fecha', 'ASC')
            ->addOrderBy('e.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Todas las notas de un alumno, de todos sus cursos. Para el panel del alumno.
     *
     * @return Calificacion[]
     */
    public function findByAlumno(Alumno $alumno): array
    {
        return $this->createQueryBuilder('c')
            ->innerJoin('c.evaluacion', 'e')->addSelect('e')
            ->innerJoin('e.curso', 'cu')->addSelect('cu')
            ->innerJoin('c.cursoHistorico', 'h')->addSelect('h')
            ->leftJoin('c.concepto', 'co')->addSelect('co')
            ->andWhere('h.alumno = :alumno')
            ->setParameter('alumno', $alumno)
            ->orderBy('cu.nombre', 'ASC')
            ->addOrderBy('e.fecha', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
