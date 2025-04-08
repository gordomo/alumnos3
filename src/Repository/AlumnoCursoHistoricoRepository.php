<?php

namespace App\Repository;

use App\Entity\AlumnoCursoHistorico;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<AlumnoCursoHistorico>
 *
 * @method AlumnoCursoHistorico|null find($id, $lockMode = null, $lockVersion = null)
 * @method AlumnoCursoHistorico|null findOneBy(array $criteria, array $orderBy = null)
 * @method AlumnoCursoHistorico[]    findAll()
 * @method AlumnoCursoHistorico[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class AlumnoCursoHistoricoRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AlumnoCursoHistorico::class);
    }

    public function add(AlumnoCursoHistorico $entity, bool $flush = false): void
    {
        $this->getEntityManager()->persist($entity);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function remove(AlumnoCursoHistorico $entity, bool $flush = false): void
    {
        $this->getEntityManager()->remove($entity);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    /**
     * @return AlumnoCursoHistorico[] Returns an array of AlumnoCursoHistorico objects
     */
    public function findByCurso($curso): array
    {
        return $this->createQueryBuilder('a')
            ->andWhere('a.curso = :curso')
            ->setParameter('curso', $curso)
            ->orderBy('a.id', 'ASC')
            ->getQuery()
            ->getResult()
        ;
    }

    /**
     * @return AlumnoCursoHistorico[] Returns an array of AlumnoCursoHistorico objects
     */
    public function findByAlumno($alumno): array
    {
        return $this->createQueryBuilder('a')
            ->andWhere('a.alumno = :alumno')
            ->setParameter('alumno', $alumno)
            ->orderBy('a.id', 'ASC')
            ->getQuery()
            ->getResult()
        ;
    }
}