<?php

declare(strict_types=1);

namespace App\Pms\Repository;

use App\Exchange\Repository\AbstractExchangeRepository;
use App\Pms\Entity\PmsRatesPushQueue;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends AbstractExchangeRepository<PmsRatesPushQueue>
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
     * Optimizado para el Worker (SQL Nativo / Binary).
     */
    protected function hydrateItems(array $ids): array
    {
        if (empty($ids)) return [];

        return $this->createQueryBuilder('q')
            ->addSelect('cfg', 'ep', 'm')
            ->leftJoin('q.config', 'cfg')
            ->innerJoin('q.endpoint', 'ep')
            ->innerJoin('q.unidadBeds24Map', 'm')
            ->where('q.id IN (:ids)')
            ->setParameter('ids', $ids, ArrayParameterType::BINARY)
            ->getQuery()
            ->getResult();
    }

    /**
     * Busca colas pendientes que solapan con el intervalo dado.
     * Criterio de Solape: (StartA < EndB) AND (EndA > StartB)
     *
     * @return PmsRatesPushQueue[]
     */
    public function findPendingForUnit(string $unidadId, \DateTimeInterface $start, \DateTimeInterface $end): array
    {
        return $this->createQueryBuilder('q')
            ->join('q.unidadBeds24Map', 'm') // Usamos el mapa para llegar a la unidad
            ->where('m.pmsUnidad = :unidadId')
            ->andWhere('q.status = :status')
            ->andWhere('q.fechaInicio < :end')
            ->andWhere('q.fechaFin > :start')
            ->setParameter('unidadId', $unidadId) // UUID String
            ->setParameter('status', PmsRatesPushQueue::STATUS_PENDING)
            ->setParameter('start', $start)
            ->setParameter('end', $end)
            ->getQuery()
            ->getResult();
    }
}