<?php

namespace App\Repository;

use App\Entity\Curso;
use App\Entity\Instituto;
use App\Entity\Tarea;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Tarea>
 */
class TareaRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Tarea::class);
    }

    /**
     * Tareas de un curso, de la más nueva a la más vieja, con sus entregas ya cargadas.
     *
     * El fetch-join de entregas evita una query por tarea al mostrar cuántas se entregaron.
     *
     * @return Tarea[]
     */
    public function findByCurso(Curso $curso): array
    {
        return $this->createQueryBuilder('t')
            ->leftJoin('t.entregas', 'e')->addSelect('e')
            ->leftJoin('t.periodo', 'p')->addSelect('p')
            ->andWhere('t.curso = :curso')
            ->setParameter('curso', $curso)
            ->orderBy('t.fecha', 'DESC')
            ->addOrderBy('t.id', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Tareas de un curso sin traer las entregas, para la pantalla del alumno.
     *
     * A propósito no se hace fetch-join de entregas: el alumno no debe ver las de sus
     * compañeros, y recortar la colección con un WITH no es confiable (si las mismas tareas
     * ya se hidrataron en otra consulta, Doctrine devuelve la colección que cargó antes).
     * Las entregas se piden aparte, por inscripción.
     *
     * @return Tarea[]
     */
    public function findParaAlumno(Curso $curso): array
    {
        return $this->createQueryBuilder('t')
            ->leftJoin('t.periodo', 'p')->addSelect('p')
            ->andWhere('t.curso = :curso')
            ->setParameter('curso', $curso)
            ->orderBy('t.fecha', 'DESC')
            ->addOrderBy('t.id', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Cuántas tareas tiene cada curso del instituto, para el listado.
     *
     * @return array<int, int> cantidad por id de curso
     */
    public function contarPorCurso(Instituto $instituto): array
    {
        $filas = $this->createQueryBuilder('t')
            ->select('c.id AS curso, COUNT(t.id) AS cantidad')
            ->innerJoin('t.curso', 'c')
            ->andWhere('t.instituto = :instituto')
            ->setParameter('instituto', $instituto)
            ->groupBy('c.id')
            ->getQuery()
            ->getScalarResult();

        $porCurso = [];
        foreach ($filas as $fila) {
            $porCurso[(int) $fila['curso']] = (int) $fila['cantidad'];
        }

        return $porCurso;
    }
}
