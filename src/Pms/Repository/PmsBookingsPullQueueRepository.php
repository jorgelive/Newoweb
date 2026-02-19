<?php
declare(strict_types=1);

namespace App\Pms\Repository;

use App\Exchange\Repository\AbstractExchangeRepository;
use App\Pms\Entity\PmsBookingsPullQueue;
use Doctrine\DBAL\ArrayParameterType; // <--- AGREGAR ESTO
use Doctrine\Persistence\ManagerRegistry;

final class PmsBookingsPullQueueRepository extends AbstractExchangeRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PmsBookingsPullQueue::class);
    }

    protected function getTableName(): string { return 'pms_bookings_pull_queue'; }

    /**
     * @param string[] $ids IDs en formato BINARIO (16 bytes)
     */
    protected function hydrateItems(array $ids): array
    {
        return $this->createQueryBuilder('j')
            ->addSelect('cfg', 'ep', 'u', 'm')
            ->innerJoin('j.config', 'cfg')
            ->innerJoin('j.endpoint', 'ep')
            ->leftJoin('j.unidades', 'u')
            ->leftJoin('u.beds24Maps', 'm')
            ->andWhere('j.id IN (:ids)')
            // CAMBIO CRÍTICO:
            // Le decimos a Doctrine que $ids es un array de strings (bytes),
            // para que no intente convertirlos ni escaparlos como texto UTF-8.
            ->setParameter('ids', $ids, ArrayParameterType::BINARY)
            ->getQuery()
            ->getResult();
    }
}