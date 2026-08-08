<?php

declare(strict_types=1);

namespace App\Finanzas\Repository;

use App\Finanzas\Entity\FinPasarelaWebhookAudit;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<FinPasarelaWebhookAudit>
 */
class FinPasarelaWebhookAuditRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, FinPasarelaWebhookAudit::class);
    }
}
