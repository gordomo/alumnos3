<?php

namespace App\Repository;

use App\Entity\Instituto;
use App\Entity\Profesor;
use App\Entity\ProfesorCursoPago;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ProfesorCursoPago>
 */
class ProfesorCursoPagoRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ProfesorCursoPago::class);
    }

    /**
     * Reglas de un profesor indexadas por id de curso, que es como las busca el cálculo.
     *
     * Devuelve también las apagadas: la pantalla de reglas necesita mostrarlas para poder
     * volver a prenderlas, y quien calcula filtra por activo.
     *
     * @return array<int, ProfesorCursoPago>
     */
    public function findByProfesorIndexadoPorCurso(Profesor $profesor): array
    {
        $reglas = $this->createQueryBuilder('r')
            ->innerJoin('r.curso', 'c')->addSelect('c')
            ->andWhere('r.profesor = :profesor')
            ->setParameter('profesor', $profesor)
            ->getQuery()
            ->getResult();

        $porCurso = [];
        foreach ($reglas as $regla) {
            if ($regla->getCurso()) {
                $porCurso[$regla->getCurso()->getId()] = $regla;
            }
        }

        return $porCurso;
    }

    /** Reglas de la página actual, limitadas a la institución en sus tres relaciones. */
    public function findParaListado(Instituto $instituto, array $profesores): array
    {
        if (!$profesores) { return []; }
        $filas = $this->createQueryBuilder('r')
            ->innerJoin('r.profesor', 'p')
            ->innerJoin('r.curso', 'c')->addSelect('c')
            ->andWhere('r.instituto = :instituto AND p.instituto = :instituto AND c.instituto = :instituto')
            ->andWhere('r.profesor IN (:profesores)')
            ->setParameter('instituto', $instituto)
            ->setParameter('profesores', $profesores)
            ->getQuery()->getResult();
        $resultado = [];
        foreach ($filas as $regla) {
            $resultado[$regla->getProfesor()->getId()][$regla->getCurso()->getId()] = $regla;
        }
        return $resultado;
    }

    /**
     * Cuántas reglas activas tiene cada profesor del instituto, para el listado.
     *
     * @return array<int, int> cantidad por id de profesor
     */
    public function contarActivasPorProfesor(Instituto $instituto): array
    {
        $filas = $this->createQueryBuilder('r')
            ->select('p.id AS profesor, COUNT(r.id) AS cantidad')
            ->innerJoin('r.profesor', 'p')
            ->andWhere('r.instituto = :instituto')
            ->andWhere('r.activo = true')
            ->setParameter('instituto', $instituto)
            ->groupBy('p.id')
            ->getQuery()
            ->getScalarResult();

        $porProfesor = [];
        foreach ($filas as $fila) {
            $porProfesor[(int) $fila['profesor']] = (int) $fila['cantidad'];
        }

        return $porProfesor;
    }
}
