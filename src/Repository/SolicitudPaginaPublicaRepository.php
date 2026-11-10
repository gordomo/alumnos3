<?php
namespace App\Repository;
use App\Entity\{Instituto, SolicitudPaginaPublica};
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
class SolicitudPaginaPublicaRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry) { parent::__construct($registry, SolicitudPaginaPublica::class); }
    public function bandeja(Instituto $instituto, string $estado): \Doctrine\ORM\QueryBuilder
    {
        $qb=$this->createQueryBuilder('s')->leftJoin('s.curso','c')->addSelect('c')->where('s.instituto = :instituto')->setParameter('instituto',$instituto);
        if ($estado !== 'todas') { $qb->andWhere('s.estado = :estado')->setParameter('estado',$estado); }
        return $qb->orderBy('s.fecha','DESC')->addOrderBy('s.id','DESC');
    }
}
