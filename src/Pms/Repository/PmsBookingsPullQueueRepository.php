<?php
declare(strict_types=1);

namespace App\Pms\Repository;

use App\Pms\Entity\PmsBookingsPullQueue;
use Doctrine\Persistence\ManagerRegistry;

final class PmsBookingsPullQueueRepository extends AbstractExchangeRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PmsBookingsPullQueue::class);
    }

    protected function getTableName(): string { return 'pms_bookings_pull_queue'; }

    protected function hydrateItems(array $ids): array
    {
        return $this->createQueryBuilder('j')
            ->addSelect('cfg', 'ep', 'u', 'm')
            ->innerJoin('j.beds24Config', 'cfg')
            ->innerJoin('j.endpoint', 'ep')
            ->leftJoin('j.unidades', 'u')
            ->leftJoin('u.beds24Maps', 'm') // Necesario para filtrar por Property/Room ID
            ->andWhere('j.id IN (:ids)')
            ->setParameter('ids', $ids)
            ->getQuery()
            ->getResult();
    }
}