<?php

namespace App\Repository;

use App\Entity\InstitutoAdmin;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<InstitutoAdmin>
 *
 * @method InstitutoAdmin|null find($id, $lockMode = null, $lockVersion = null)
 * @method InstitutoAdmin|null findOneBy(array $criteria, array $orderBy = null)
 * @method InstitutoAdmin[]    findAll()
 * @method InstitutoAdmin[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class InstitutoAdminRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, InstitutoAdmin::class);
    }

    /**
     * Verifica si un usuario tiene acceso a un instituto
     * 
     * @param \App\Entity\User $usuario
     * @param \App\Entity\Instituto $instituto
     * @return bool
     */
    public function usuarioTieneAcceso(\App\Entity\User $usuario, \App\Entity\Instituto $instituto): bool
    {
        // Si es SUPER_ADMIN, tiene acceso a todos
        if (in_array('ROLE_SUPER_ADMIN', $usuario->getRoles())) {
            return true;
        }
        
        // Verificar si existe un registro activo en la tabla intermedia
        $admin = $this->findOneBy([
            'user' => $usuario,
            'instituto' => $instituto,
            'activo' => true
        ]);
        
        return $admin !== null;
    }

    /**
     * Obtiene todos los institutos creados por un usuario
     * 
     * @param \App\Entity\User $usuario
     * @return InstitutoAdmin[]
     */
    public function findInstitutosCreadosPorUsuario(\App\Entity\User $usuario): array
    {
        return $this->createQueryBuilder('ia')
            ->where('ia.user = :usuario')
            ->setParameter('usuario', $usuario)
            ->orderBy('ia.id', 'DESC')
            ->getQuery()
            ->getResult();
    }
}
