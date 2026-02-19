<?php

declare(strict_types=1);

namespace App\Exchange\Service\Contract;

use App\Exchange\Service\Common\HomogeneousBatch;
use DateTimeImmutable;

interface ExchangeQueueProviderInterface
{
    /**
     * Reclama un lote de ítems garantizados de ser homogéneos (mismo Config y Endpoint).
     * Modo Cron: Busca lo más antiguo pendiente respetando límites.
     * Retorna null si no hay nada pendiente.
     */
    public function claimBatch(int $limit, string $workerId, DateTimeImmutable $now): ?HomogeneousBatch;

    /**
     * Modo Manual (On-Demand): Busca IDs específicos ignorando el orden FIFO.
     * DEBE validar que todos los IDs pertenezcan a la misma configuración.
     * @param string[] $ids Array de UUIDs.
     */
    public function claimSpecificBatch(array $ids, string $workerId, DateTimeImmutable $now): ?HomogeneousBatch;
}