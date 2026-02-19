<?php
declare(strict_types=1);

namespace App\Pms\Repository;

use App\Exchange\Repository\AbstractExchangeRepository;
use App\Pms\Entity\PmsBookingsPushQueue;
use Doctrine\DBAL\ArrayParameterType; // <--- AGREGAR ESTO
use Doctrine\Persistence\ManagerRegistry;

final class PmsBookingsPushQueueRepository extends AbstractExchangeRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PmsBookingsPushQueue::class);
    }

    protected function getTableName(): string { return 'pms_bookings_push_queue'; }

    /**
     * @param string[] $ids IDs en formato BINARIO (16 bytes)
     */
    protected function hydrateItems(array $ids): array
    {
        return $this->createQueryBuilder('q')
            ->addSelect('cfg', 'ep', 'l', 'ev', 'res')
            ->innerJoin('q.config', 'cfg')
            ->innerJoin('q.endpoint', 'ep')
            ->leftJoin('q.link', 'l')
            ->leftJoin('l.evento', 'ev')
            ->leftJoin('ev.reserva', 'res')
            ->andWhere('q.id IN (:ids)')
            // CAMBIO CRÍTICO: Definir tipo explícito para los bytes
            ->setParameter('ids', $ids, ArrayParameterType::BINARY)
            ->getQuery()
            ->getResult();
    }
}