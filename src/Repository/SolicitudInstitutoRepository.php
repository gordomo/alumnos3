<?php

namespace App\Repository;

use App\Entity\SolicitudInstituto;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<SolicitudInstituto>
 *
 * @method SolicitudInstituto|null find($id, $lockMode = null, $lockVersion = null)
 * @method SolicitudInstituto|null findOneBy(array $criteria, array $orderBy = null)
 * @method SolicitudInstituto[]    findAll()
 * @method SolicitudInstituto[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class SolicitudInstitutoRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SolicitudInstituto::class);
    }

    /**
     * Las pendientes primero y, dentro de cada estado, la más nueva arriba.
     *
     * @return SolicitudInstituto[]
     */
    public function listar(?string $estado = null): array
    {
        $qb = $this->createQueryBuilder('s');

        if ($estado) {
            $qb->andWhere('s.estado = :estado')->setParameter('estado', $estado);
        }

        return $qb
            ->addSelect("CASE WHEN s.estado = 'pendiente' THEN 0 ELSE 1 END AS HIDDEN orden")
            ->orderBy('orden', 'ASC')
            ->addOrderBy('s.fechaSolicitud', 'DESC')
            ->getQuery()
            ->getResult();
    }

    public function contarPendientes(): int
    {
        return (int) $this->createQueryBuilder('s')
            ->select('COUNT(s.id)')
            ->andWhere('s.estado = :estado')
            ->setParameter('estado', SolicitudInstituto::PENDIENTE)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Para no aceptar dos veces el mismo email mientras una solicitud sigue pendiente.
     */
    public function pendientePorEmail(string $email): ?SolicitudInstituto
    {
        return $this->findOneBy([
            'email' => strtolower(trim($email)),
            'estado' => SolicitudInstituto::PENDIENTE,
        ]);
    }
}
