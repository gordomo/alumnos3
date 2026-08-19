<?php

namespace App\Repository;

use App\Entity\AlumnoCursoHistorico;
use App\Entity\Tarea;
use App\Entity\TareaEntrega;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<TareaEntrega>
 */
class TareaEntregaRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, TareaEntrega::class);
    }

    /**
     * Entregas de una tarea, indexadas por id de inscripción, para armar la grilla sin buscar
     * dentro de un array en cada fila.
     *
     * @return array<int, TareaEntrega>
     */
    public function findByTareaIndexadoPorHistorico(Tarea $tarea): array
    {
        $porHistorico = [];
        foreach ($this->findBy(['tarea' => $tarea]) as $entrega) {
            $historico = $entrega->getCursoHistorico();
            if ($historico) {
                $porHistorico[$historico->getId()] = $entrega;
            }
        }

        return $porHistorico;
    }

    /**
     * Entregas de una inscripción, con la tarea y su período ya cargados.
     *
     * Es lo que consume la libreta para contar entregadas por período.
     *
     * @return TareaEntrega[]
     */
    public function findByHistorico(AlumnoCursoHistorico $historico): array
    {
        return $this->createQueryBuilder('e')
            ->innerJoin('e.tarea', 't')->addSelect('t')
            ->leftJoin('t.periodo', 'p')->addSelect('p')
            ->andWhere('e.cursoHistorico = :historico')
            ->setParameter('historico', $historico)
            ->orderBy('t.fecha', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
