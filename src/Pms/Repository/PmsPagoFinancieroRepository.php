<?php

declare(strict_types=1);

namespace App\Pms\Repository;

use App\Pms\Entity\PmsPagoFinanciero;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PmsPagoFinanciero>
 */
class PmsPagoFinancieroRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PmsPagoFinanciero::class);
    }
}
