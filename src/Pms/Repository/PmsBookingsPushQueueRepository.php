<?php
declare(strict_types=1);

namespace App\Pms\Repository;

use App\Pms\Entity\PmsBookingsPushQueue;
use Doctrine\Persistence\ManagerRegistry;

final class PmsBookingsPushQueueRepository extends AbstractExchangeRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PmsBookingsPushQueue::class);
    }

    protected function getTableName(): string { return 'pms_bookings_push_queue'; }

    protected function hydrateItems(array $ids): array
    {
        return $this->createQueryBuilder('q')
            ->addSelect('cfg', 'ep', 'l', 'ev', 'res')
            ->innerJoin('q.beds24Config', 'cfg')
            ->innerJoin('q.endpoint', 'ep')
            ->leftJoin('q.link', 'l')
            ->leftJoin('l.evento', 'ev')
            ->leftJoin('ev.reserva', 'res')
            ->andWhere('q.id IN (:ids)')
            ->setParameter('ids', $ids)
            ->getQuery()
            ->getResult();
    }
}