<?php

namespace App\Repository;

use App\Entity\SaldoFavorAplicacion;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<SaldoFavorAplicacion>
 *
 * @method SaldoFavorAplicacion|null find($id, $lockMode = null, $lockVersion = null)
 * @method SaldoFavorAplicacion|null findOneBy(array $criteria, array $orderBy = null)
 * @method SaldoFavorAplicacion[]    findAll()
 * @method SaldoFavorAplicacion[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class SaldoFavorAplicacionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SaldoFavorAplicacion::class);
    }
}
