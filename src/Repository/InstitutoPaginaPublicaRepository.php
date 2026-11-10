<?php
namespace App\Repository;

use App\Entity\InstitutoPaginaPublica;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class InstitutoPaginaPublicaRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry) { parent::__construct($registry, InstitutoPaginaPublica::class); }

    public function findPublicada(string $slug): ?InstitutoPaginaPublica
    {
        return $this->createQueryBuilder('p')->innerJoin('p.instituto', 'i')->addSelect('i')
            ->where('p.slug = :slug AND p.publicada = true AND i.activo = true')
            ->setParameter('slug', $slug)->getQuery()->getOneOrNullResult();
    }
}
