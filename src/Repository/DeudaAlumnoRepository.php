<?php

namespace App\Repository;

use App\Entity\Alumno;
use App\Entity\Curso;
use App\Entity\DeudaAlumno;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<DeudaAlumno>
 *
 * @method DeudaAlumno|null find($id, $lockMode = null, $lockVersion = null)
 * @method DeudaAlumno|null findOneBy(array $criteria, array $orderBy = null)
 * @method DeudaAlumno[]    findAll()
 * @method DeudaAlumno[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class DeudaAlumnoRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, DeudaAlumno::class);
    }

    /**
     * Guarda una entidad DeudaAlumno en la base de datos
     */
    public function add(DeudaAlumno $entity, bool $flush = false): void
    {
        $this->getEntityManager()->persist($entity);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    /**
     * Elimina una entidad DeudaAlumno de la base de datos
     */
    public function remove(DeudaAlumno $entity, bool $flush = false): void
    {
        $this->getEntityManager()->remove($entity);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    /**
     * Encuentra todas las deudas no pagadas de un alumno
     */
    public function findDeudaByAlumno(Alumno $alumno): array
    {
        $todasDeudas = $this->createQueryBuilder('d')
            ->andWhere('d.alumno = :alumno')
            ->setParameter('alumno', $alumno)
            ->orderBy('d.ano', 'ASC')
            ->addOrderBy('d.mes', 'ASC')
            ->getQuery()
            ->getResult();
        
        // Filtrar solo las que tienen monto pendiente
        return array_filter($todasDeudas, function($deuda) {
            return $deuda->getMontoPendiente() > 0;
        });
    }

    /**
     * Encuentra todas las deudas no pagadas de un alumno para un curso específico
     */
    public function findDeudaByAlumnoAndCurso(Alumno $alumno, Curso $curso): array
    {
        $todasDeudas = $this->createQueryBuilder('d')
            ->andWhere('d.alumno = :alumno')
            ->andWhere('d.curso = :curso')
            ->setParameter('alumno', $alumno)
            ->setParameter('curso', $curso)
            ->orderBy('d.ano', 'ASC')
            ->addOrderBy('d.mes', 'ASC')
            ->getQuery()
            ->getResult();
        
        // Filtrar solo las que tienen monto pendiente
        return array_filter($todasDeudas, function($deuda) {
            return $deuda->getMontoPendiente() > 0;
        });
    }

    /**
     * Verifica si un alumno tiene alguna deuda pendiente
     */
    public function tieneDeuda(Alumno $alumno): bool
    {
        $todasDeudas = $this->findBy(['alumno' => $alumno]);
        
        foreach ($todasDeudas as $deuda) {
            if ($deuda->getMontoPendiente() > 0) {
                return true;
            }
        }
        
        return false;
    }

    /**
     * Encuentra una deuda específica por alumno, curso, mes y año
     */
    public function findOneDeuda(Alumno $alumno, Curso $curso, int $mes, int $ano): ?DeudaAlumno
    {
        return $this->createQueryBuilder('d')
            ->andWhere('d.alumno = :alumno')
            ->andWhere('d.curso = :curso')
            ->andWhere('d.mes = :mes')
            ->andWhere('d.ano = :ano')
            ->setParameter('alumno', $alumno)
            ->setParameter('curso', $curso)
            ->setParameter('mes', $mes)
            ->setParameter('ano', $ano)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * @deprecated Este método ya no es necesario. Los pagos se aplican automáticamente mediante PagoAplicacion.
     * Marca como pagadas todas las deudas asociadas a un pago
     */
    public function marcarPagadas(Alumno $alumno, Curso $curso, int $mes, int $ano, $pago): void
    {
        // Este método está deprecado. Los pagos ahora se aplican mediante PagoService y PagoAplicacion.
        // No hacer nada aquí ya que la lógica de aplicación de pagos está en PagoService.
    }

    /**
     * Encuentra todas las deudas con vencimiento (mes actual con vencimiento pasado o meses anteriores)
     */
    public function findDeudasVencidas(): array
    {
        $fechaActual = new \DateTime();
        $mesActual = (int)$fechaActual->format('n');
        $anoActual = (int)$fechaActual->format('Y');
        $diaActual = (int)$fechaActual->format('j');

        $qb = $this->createQueryBuilder('d')
            ->leftJoin('d.aplicaciones', 'pa')
            ->groupBy('d.id')
            ->having('COALESCE(SUM(pa.montoAplicado), 0) < d.monto + COALESCE(d.interes, 0)');

        // Deudas de meses anteriores
        $qb->andWhere(
            $qb->expr()->orX(
                // Años anteriores
                $qb->expr()->lt('d.ano', ':anoActual'),
                // Mismo año, meses anteriores
                $qb->expr()->andX(
                    $qb->expr()->eq('d.ano', ':anoActual'),
                    $qb->expr()->lt('d.mes', ':mesActual')
                )
            )
        )
        ->setParameter('anoActual', $anoActual)
        ->setParameter('mesActual', $mesActual);

        return $qb->orderBy('d.ano', 'ASC')
            ->addOrderBy('d.mes', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
