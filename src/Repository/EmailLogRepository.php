<?php

namespace App\Repository;

use App\Entity\EmailLog;
use App\Entity\Instituto;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<EmailLog>
 */
class EmailLogRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, EmailLog::class);
    }

    public function add(EmailLog $entity, bool $flush = true): void
    {
        $this->getEntityManager()->persist($entity);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function remove(EmailLog $entity, bool $flush = true): void
    {
        $this->getEntityManager()->remove($entity);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    /**
     * Encuentra emails por instituto ordenados por fecha
     */
    public function findByInstitutoOrdered(Instituto $instituto, int $limit = 100): array
    {
        return $this->createQueryBuilder('e')
            ->andWhere('e.instituto = :instituto')
            ->setParameter('instituto', $instituto)
            ->orderBy('e.fechaEnvio', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Cuenta emails por estado para un instituto
     */
    public function countByEstado(Instituto $instituto, string $estado): int
    {
        return $this->createQueryBuilder('e')
            ->select('COUNT(e.id)')
            ->andWhere('e.instituto = :instituto')
            ->andWhere('e.estado = :estado')
            ->setParameter('instituto', $instituto)
            ->setParameter('estado', $estado)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Encuentra emails por alumno
     */
    public function findByAlumno($alumno): array
    {
        return $this->createQueryBuilder('e')
            ->andWhere('e.alumno = :alumno')
            ->setParameter('alumno', $alumno)
            ->orderBy('e.fechaEnvio', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Encuentra el último recordatorio enviado a un alumno
     */
    public function findUltimoRecordatorio($alumno): ?EmailLog
    {
        return $this->createQueryBuilder('e')
            ->andWhere('e.alumno = :alumno')
            ->andWhere('e.tipo = :tipo')
            ->setParameter('alumno', $alumno)
            ->setParameter('tipo', 'recordatorio')
            ->orderBy('e.fechaEnvio', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Verifica si se puede enviar un recordatorio (no se envió en las últimas X horas)
     */
    public function puedeEnviarRecordatorio($alumno, int $horasMinimas = 48): bool
    {
        $ultimoRecordatorio = $this->findUltimoRecordatorio($alumno);
        
        if (!$ultimoRecordatorio) {
            return true;
        }
        
        $ahora = new \DateTime();
        $diferencia = $ahora->diff($ultimoRecordatorio->getFechaEnvio());
        $horasDesdeUltimo = ($diferencia->days * 24) + $diferencia->h;
        
        return $horasDesdeUltimo >= $horasMinimas;
    }
}
