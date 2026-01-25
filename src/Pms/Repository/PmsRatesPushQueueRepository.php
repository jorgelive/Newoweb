<?php
declare(strict_types=1);

namespace App\Pms\Repository;

use App\Pms\Entity\PmsRatesPushQueue;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Repositorio unificado para la cola de tarifas.
 */
final class PmsRatesPushQueueRepository extends AbstractExchangeRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PmsRatesPushQueue::class);
    }

    protected function getTableName(): string
    {
        return 'pms_rates_push_queue';
    }

    /**
     * Hydrate optimizado para el Worker.
     * Trae Config, Endpoint y Map en un solo Join para evitar N+1 en el Exchange.
     */
    protected function hydrateItems(array $ids): array
    {
        return $this->createQueryBuilder('q')
            ->addSelect('cfg', 'ep', 'm')
            ->innerJoin('q.beds24Config', 'cfg')
            ->innerJoin('q.endpoint', 'ep')
            ->innerJoin('q.unidadBeds24Map', 'm')
            ->andWhere('q.id IN (:ids)')
            ->setParameter('ids', $ids)
            ->getQuery()
            ->getResult();
    }

    /**
     * Usado por el Creator para encontrar duplicados/updates.
     * Busca coincidencias de unidad y fechas en estado PENDING.
     */
    public function findPendingForUnit(int $unidadId, \DateTimeInterface $from, \DateTimeInterface $to): array
    {
        return $this->createQueryBuilder('q')
            ->where('q.unidad = :uid')
            ->andWhere('q.status = :st')
            ->andWhere('q.fechaInicio < :to AND q.fechaFin > :from')
            ->setParameters([
                'uid' => $unidadId,
                'st'   => PmsRatesPushQueue::STATUS_PENDING,
                'from' => $from,
                'to'   => $to
            ])
            ->getQuery()
            ->getResult();
    }
}