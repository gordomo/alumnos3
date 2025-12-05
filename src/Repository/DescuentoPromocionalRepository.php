<?php

namespace App\Repository;

use App\Entity\DescuentoPromocional;
use App\Entity\InstitutoConfiguracion;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<DescuentoPromocional>
 */
class DescuentoPromocionalRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, DescuentoPromocional::class);
    }

    /**
     * Obtiene todos los descuentos promocionales activos de una configuración
     */
    public function findActivosByConfiguracion(InstitutoConfiguracion $configuracion): array
    {
        return $this->createQueryBuilder('d')
            ->where('d.configuracion = :configuracion')
            ->andWhere('d.activo = :activo')
            ->setParameter('configuracion', $configuracion)
            ->setParameter('activo', true)
            ->orderBy('d.nombre', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Obtiene todos los descuentos promocionales de una configuración
     */
    public function findByConfiguracion(InstitutoConfiguracion $configuracion): array
    {
        return $this->createQueryBuilder('d')
            ->where('d.configuracion = :configuracion')
            ->setParameter('configuracion', $configuracion)
            ->orderBy('d.nombre', 'ASC')
            ->getQuery()
            ->getResult();
    }
}

