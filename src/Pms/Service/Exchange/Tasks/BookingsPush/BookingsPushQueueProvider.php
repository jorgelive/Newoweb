<?php
declare(strict_types=1);

namespace App\Pms\Service\Exchange\Tasks\BookingsPush;

use App\Exchange\Service\Common\HomogeneousBatch;
use App\Exchange\Service\Contract\ExchangeQueueProviderInterface;
use App\Pms\Entity\PmsBookingsPushQueue;
use App\Pms\Repository\PmsBookingsPushQueueRepository;
use DateTimeImmutable;

final readonly class BookingsPushQueueProvider implements ExchangeQueueProviderInterface
{
    public function __construct(
        private PmsBookingsPushQueueRepository $repository
    ) {}

    /**
     * Reclama un lote de envíos de reservas (Push).
     * El repositorio garantiza que todos los registros compartan el mismo Config y Endpoint.
     */
    public function claimBatch(int $limit, string $workerId, DateTimeImmutable $now): ?HomogeneousBatch
    {
        // 1. Reclamar ítems usando el patrón Probe & Fetch del Repositorio
        $items = $this->repository->claimRunnable(
            limit: $limit,
            workerId: $workerId,
            now: $now,
            ttl: 90 // TTL estándar para operaciones Push
        );

        if (empty($items)) {
            return null;
        }

        /** @var PmsBookingsPushQueue $representative */
        $representative = $items[0];

        // 2. Empaquetar el lote con su contexto técnico
        return new HomogeneousBatch(
            config:   $representative->getBeds24Config(),
            endpoint: $representative->getEndpoint(),
            items:    $items
        );
    }
}