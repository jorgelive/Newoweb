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
     * Busca colas pendientes para una unidad en un rango de fechas.
     *
     * @param string $unidadId  UUID de la unidad (String)
     * @param \DateTimeInterface $start
     * @param \DateTimeInterface $end
     * @return PmsRatesPushQueue[]
     */
    public function findPendingForUnit(string $unidadId, \DateTimeInterface $start, \DateTimeInterface $end): array
    {
        return $this->createQueryBuilder('q')
            ->join('q.unidad', 'u')
            ->where('u.id = :unidadId')
            ->andWhere('q.status = :status')
            // Solapamiento de fechas: (StartA <= EndB) and (EndA >= StartB)
            ->andWhere('q.fechaInicio <= :end')
            ->andWhere('q.fechaFin >= :start')
            ->setParameter('unidadId', $unidadId, 'uuid') // 'uuid' ayuda a Doctrine si usas Binary(16)
            ->setParameter('status', \App\Pms\Entity\PmsRatesPushQueue::STATUS_PENDING)
            ->setParameter('start', $start)
            ->setParameter('end', $end)
            ->getQuery()
            ->getResult();
    }

}