<?php

namespace App\Repository;

use App\Entity\Instituto;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Instituto>
 *
 * @method Instituto|null find($id, $lockMode = null, $lockVersion = null)
 * @method Instituto|null findOneBy(array $criteria, array $orderBy = null)
 * @method Instituto[]    findAll()
 * @method Instituto[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class InstitutoRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Instituto::class);
    }

    /**
     * Encuentra todos los institutos que un usuario puede ver según su rol
     * 
     * @param \App\Entity\User|null $usuario
     * @return Instituto[]
     */
    public function findAllForUser(?\App\Entity\User $usuario = null): array
    {
        $qb = $this->createQueryBuilder('i');
        
        // Si el usuario tiene ROLE_SUPER_ADMIN, mostrar todos los institutos
        if ($usuario && in_array('ROLE_SUPER_ADMIN', $usuario->getRoles())) {
            return $qb->orderBy('i.nombre', 'ASC')->getQuery()->getResult();
        }
        
        // Si el usuario tiene ROLE_ADMIN, mostrar solo los que tiene asignados (activos)
        if ($usuario && in_array('ROLE_ADMIN', $usuario->getRoles())) {
            $qb->innerJoin('i.admins', 'ia')
               ->andWhere('ia.user = :usuario')
               ->andWhere('ia.activo = :activo')
               ->setParameter('usuario', $usuario)
               ->setParameter('activo', true);
        }
        
        return $qb->orderBy('i.nombre', 'ASC')->getQuery()->getResult();
    }
}
