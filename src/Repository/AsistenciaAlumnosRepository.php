<?php

namespace App\Repository;

use App\Entity\AsistenciaAlumnos;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<AsistenciaAlumnos>
 */
class AsistenciaAlumnosRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AsistenciaAlumnos::class);
    }

    public function add(AsistenciaAlumnos $entity, bool $flush = false): void
    {
        $this->getEntityManager()->persist($entity);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function remove(AsistenciaAlumnos $entity, bool $flush = false): void
    {
        $this->getEntityManager()->remove($entity);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function findByInstitutoAndDateRange($instituto, $desde, $hasta)
    {
        return $this->createQueryBuilder('a')
            ->join('a.alumno', 'al')
            ->join('a.curso', 'c')
            ->where('al.instituto = :instituto')
            ->andWhere('a.fecha BETWEEN :desde AND :hasta')
            ->setParameter('instituto', $instituto)
            ->setParameter('desde', $desde)
            ->setParameter('hasta', $hasta)
            ->orderBy('a.fecha', 'DESC')
            ->getQuery()
            ->getResult();
    }

    public function findByCursoAndDate($curso, $fecha)
    {
        return $this->createQueryBuilder('a')
            ->where('a.curso = :curso')
            ->andWhere('a.fecha = :fecha')
            ->setParameter('curso', $curso)
            ->setParameter('fecha', $fecha)
            ->getQuery()
            ->getResult();
    }
    
    /**
     * Busca asistencias por curso y rango de fechas
     * 
     * @param mixed $curso El curso a buscar
     * @param \DateTime $fechaInicio Fecha de inicio del rango
     * @param \DateTime $fechaFin Fecha de fin del rango
     * @return AsistenciaAlumnos[] Retorna un array de entidades AsistenciaAlumnos
     */
    public function findByDateRange($curso, \DateTime $fechaInicio, \DateTime $fechaFin)
    {
        return $this->createQueryBuilder('a')
            ->where('a.curso = :curso')
            ->andWhere('a.fecha BETWEEN :fechaInicio AND :fechaFin')
            ->setParameter('curso', $curso)
            ->setParameter('fechaInicio', $fechaInicio)
            ->setParameter('fechaFin', $fechaFin)
            ->orderBy('a.fecha', 'ASC')
            ->addOrderBy('a.alumno', 'ASC')
            ->getQuery()
            ->getResult();
    }
} 