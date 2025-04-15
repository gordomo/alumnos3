<?php

namespace App\Repository;

use App\Entity\InstitutoConfiguracion;
use App\Entity\Instituto;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<InstitutoConfiguracion>
 *
 * @method InstitutoConfiguracion|null find($id, $lockMode = null, $lockVersion = null)
 * @method InstitutoConfiguracion|null findOneBy(array $criteria, array $orderBy = null)
 * @method InstitutoConfiguracion[]    findAll()
 * @method InstitutoConfiguracion[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class InstitutoConfiguracionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, InstitutoConfiguracion::class);
    }

    public function save(InstitutoConfiguracion $entity, bool $flush = false): void
    {
        $this->getEntityManager()->persist($entity);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function remove(InstitutoConfiguracion $entity, bool $flush = false): void
    {
        $this->getEntityManager()->remove($entity);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    /**
     * Encuentra la configuración de un instituto o la crea si no existe
     */
    public function findOrCreateByInstituto(Instituto $instituto): InstitutoConfiguracion
    {
        $configuracion = $this->findOneBy(['instituto' => $instituto]);
        
        if (!$configuracion) {
            $configuracion = new InstitutoConfiguracion();
            $configuracion->setInstituto($instituto);
            $this->save($configuracion, true);
        }
        
        return $configuracion;
    }
} 