<?php

namespace App\Repository;

use App\Entity\EventoAgenda;
use App\Entity\Instituto;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<EventoAgenda>
 */
class EventoAgendaRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, EventoAgenda::class);
    }

    /**
     * Eventos del instituto que se cruzan con el rango pedido.
     *
     * Se compara el rango completo del evento y no solo su fecha de inicio: un receso que
     * arrancó el 15 de julio tiene que seguir apareciendo cuando se mira agosto.
     *
     * @return EventoAgenda[]
     */
    public function findEnRango(Instituto $instituto, \DateTimeInterface $desde, \DateTimeInterface $hasta): array
    {
        return $this->createQueryBuilder('e')
            ->leftJoin('e.curso', 'c')->addSelect('c')
            ->andWhere('e.instituto = :instituto')
            ->andWhere('e.fechaInicio <= :hasta')
            ->andWhere('COALESCE(e.fechaFin, e.fechaInicio) >= :desde')
            ->setParameter('instituto', $instituto)
            ->setParameter('desde', $desde)
            ->setParameter('hasta', $hasta)
            ->orderBy('e.fechaInicio', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Los próximos eventos, para el panel de "qué se viene".
     *
     * @return EventoAgenda[]
     */
    public function findProximos(Instituto $instituto, \DateTimeInterface $desde, int $limite = 5): array
    {
        return $this->createQueryBuilder('e')
            ->andWhere('e.instituto = :instituto')
            ->andWhere('COALESCE(e.fechaFin, e.fechaInicio) >= :desde')
            ->setParameter('instituto', $instituto)
            ->setParameter('desde', $desde)
            ->orderBy('e.fechaInicio', 'ASC')
            ->setMaxResults($limite)
            ->getQuery()
            ->getResult();
    }

    /**
     * Los feriados y recesos que caen en un rango, para tapar las clases de esos días.
     *
     * @return EventoAgenda[]
     */
    public function findFeriadosEnRango(Instituto $instituto, \DateTimeInterface $desde, \DateTimeInterface $hasta): array
    {
        return $this->createQueryBuilder('e')
            ->andWhere('e.instituto = :instituto')
            ->andWhere('e.tipo = :tipo')
            ->andWhere('e.fechaInicio <= :hasta')
            ->andWhere('COALESCE(e.fechaFin, e.fechaInicio) >= :desde')
            ->setParameter('instituto', $instituto)
            ->setParameter('tipo', EventoAgenda::TIPO_FERIADO)
            ->setParameter('desde', $desde)
            ->setParameter('hasta', $hasta)
            ->getQuery()
            ->getResult();
    }
}
