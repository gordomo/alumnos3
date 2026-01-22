<?php

namespace App\Repository;

use App\Entity\BillingConfig;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<BillingConfig>
 *
 * @method BillingConfig|null find($id, $lockMode = null, $lockVersion = null)
 * @method BillingConfig|null findOneBy(array $criteria, array $orderBy = null)
 * @method BillingConfig[]    findAll()
 * @method BillingConfig[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class BillingConfigRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, BillingConfig::class);
    }

    /**
     * Obtiene la configuración de precio por alumno
     * Si no existe, retorna null
     */
    public function getPriceConfig(): ?BillingConfig
    {
        return $this->findOneBy(['configKey' => 'price_per_student_monthly']);
    }

    /**
     * Obtiene o crea la configuración de precio por alumno
     */
    public function getOrCreatePriceConfig(): BillingConfig
    {
        $config = $this->getPriceConfig();
        
        if (!$config) {
            $config = new BillingConfig();
            $config->setConfigKey('price_per_student_monthly');
            $config->setPricePerStudentMonthly(50.0);
            $config->setDescription('Precio por alumno activo por mes');
            
            $this->getEntityManager()->persist($config);
            $this->getEntityManager()->flush();
        }
        
        return $config;
    }
}
