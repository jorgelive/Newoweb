<?php

declare(strict_types=1);

namespace App\Pms\Repository;

use App\Pms\Entity\PmsCargoFinanciero;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PmsCargoFinanciero>
 */
class PmsCargoFinancieroRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PmsCargoFinanciero::class);
    }
}
