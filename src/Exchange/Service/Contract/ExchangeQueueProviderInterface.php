<?php
declare(strict_types=1);

namespace App\Exchange\Service\Contract;

use App\Exchange\Service\Common\HomogeneousBatch;
use DateTimeImmutable;

interface ExchangeQueueProviderInterface
{
    /**
     * Reclama un lote de ítems garantizados de ser homogéneos (mismo Config y Endpoint).
     * Retorna null si no hay nada pendiente.
     */
    public function claimBatch(int $limit, string $workerId, DateTimeImmutable $now): ?HomogeneousBatch;
}