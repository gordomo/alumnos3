<?php

namespace App\Repository;

use App\Entity\Alumno;
use App\Entity\Curso;
use App\Entity\DeudaAlumno;
use App\Entity\Instituto;
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
     * Deudas con saldo pendiente de todos los alumnos de un curso, agrupadas por alumno.
     *
     * Reemplaza al viejo findDeudaByAlumnoAndCurso(), con el mismo criterio de filtrado
     * (montoPendiente > 0). Existe porque el cierre de curso lo llamaba dentro del loop de
     * alumnos, y además getMontoPendiente() recorre las colecciones aplicaciones y
     * creditoAplicaciones, que están mapeadas como fetch="EAGER": en un OneToMany eso hace
     * una query extra por colección por deuda. Con 16 alumnos eran decenas de queries.
     *
     * Acá las dos colecciones se traen con fetch-join, así Doctrine las marca inicializadas
     * y no dispara las consultas EAGER.
     *
     * @return array<int, DeudaAlumno[]> [alumnoId => deudas pendientes]
     */
    public function findPendientesByCursoAgrupadasPorAlumno(Curso $curso): array
    {
        $deudas = $this->createQueryBuilder('d')
            ->leftJoin('d.aplicaciones', 'ap')
            ->addSelect('ap')
            ->leftJoin('d.creditoAplicaciones', 'ca')
            ->addSelect('ca')
            ->andWhere('d.curso = :curso')
            ->setParameter('curso', $curso)
            ->orderBy('d.ano', 'ASC')
            ->addOrderBy('d.mes', 'ASC')
            ->getQuery()
            ->getResult();

        $porAlumno = [];
        foreach ($deudas as $deuda) {
            if ($deuda->getMontoPendiente() > 0) {
                // getAlumno() devuelve un proxy; pedirle el id no lo inicializa.
                $porAlumno[$deuda->getAlumno()->getId()][] = $deuda;
            }
        }

        return $porAlumno;
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
        $deudasConSaldo = $this->createQueryBuilder('d')
            ->leftJoin('d.aplicaciones', 'pa')
            ->groupBy('d.id')
            ->having('COALESCE(SUM(pa.montoAplicado), 0) < d.monto + COALESCE(d.interes, 0)')
            ->orderBy('d.ano', 'ASC')
            ->addOrderBy('d.mes', 'ASC')
            ->getQuery()
            ->getResult();

        $deudasVencidas = [];
        foreach ($deudasConSaldo as $deuda) {
            $instituto = $deuda->getInstituto();
            $fechaActual = $this->getNowForInstituto($instituto);
            $mesActual = (int) $fechaActual->format('n');
            $anoActual = (int) $fechaActual->format('Y');
            $diaActual = (int) $fechaActual->format('j');
            $primerDiaVencimiento = $this->getPrimerDiaVencimiento($instituto);

            $esMesAnterior = $deuda->getAno() < $anoActual
                || ($deuda->getAno() == $anoActual && $deuda->getMes() < $mesActual);
            $esMesActualVencido = $deuda->getAno() == $anoActual
                && $deuda->getMes() == $mesActual
                && $diaActual >= $primerDiaVencimiento;

            if ($esMesAnterior || $esMesActualVencido) {
                $deudasVencidas[] = $deuda;
            }
        }

        return $deudasVencidas;
    }

    private function getNowForInstituto(?Instituto $instituto): \DateTimeImmutable
    {
        $timezone = null;
        if ($instituto && $instituto->getConfiguracion()) {
            $timezone = $instituto->getConfiguracion()->getTimezone();
        }

        if (!empty($timezone)) {
            try {
                return new \DateTimeImmutable('now', new \DateTimeZone($timezone));
            } catch (\Exception $e) {
                // fallback
            }
        }

        return new \DateTimeImmutable();
    }

    private function getPrimerDiaVencimiento(?Instituto $instituto): int
    {
        if (!$instituto) {
            return 5;
        }

        $vencimientos = $instituto->getVencimientos()->toArray();
        if (empty($vencimientos)) {
            return 5;
        }

        usort($vencimientos, function($a, $b) {
            return $a->getDiaVencimiento() <=> $b->getDiaVencimiento();
        });

        return (int) $vencimientos[0]->getDiaVencimiento();
    }
}
