<?php

declare(strict_types=1);

namespace App\Pms\Service\Exchange\Tasks\BookingsPull;

use App\Exchange\Service\Common\HomogeneousBatch;
use App\Exchange\Service\Contract\ExchangeQueueProviderInterface;
use App\Pms\Entity\PmsBookingsPullQueue;
use App\Pms\Repository\PmsBookingsPullQueueRepository;
use DateTimeImmutable;
use RuntimeException;

final readonly class BookingsPullQueueProvider implements ExchangeQueueProviderInterface
{
    public function __construct(
        private PmsBookingsPullQueueRepository $repository
    ) {}

    /**
     * Reclama un lote de trabajos de descarga (Pull).
     * * Nota: Aunque el repositorio puede traer N items, en procesos de Pull
     * solemos usar un límite bajo (ej: 1) por la carga que implica procesar
     * múltiples respuestas grandes de la API simultáneamente.
     */
    public function claimBatch(int $limit, string $workerId, DateTimeImmutable $now): ?HomogeneousBatch
    {
        $items = $this->repository->claimRunnable($limit, $workerId, $now, 300);
        return $this->packItems($items);
    }

    public function claimSpecificBatch(array $ids, string $workerId, DateTimeImmutable $now): ?HomogeneousBatch
    {
        $items = $this->repository->claimSpecificItems($ids, $workerId, $now);
        return $this->packItems($items, true);
    }

    /** @param PmsBookingsPullQueue[] $items */
    private function packItems(array $items, bool $strictCheck = false): ?HomogeneousBatch
    {
        if (empty($items)) return null;

        // Nota: En PULL, 'config' es crítico, pero 'endpoint' también para saber la URL.
        $representative = $items[0];

        if ($strictCheck && count($items) > 1) {
            // Pull suele ser de 1 en 1, pero si mandan varios, deben ser de la misma config.
            $refId = (string) $representative->getConfig()->getId();
            foreach ($items as $item) {
                if ((string)$item->getConfig()->getId() !== $refId) {
                    throw new RuntimeException("Violación de homogeneidad en PULL manual.");
                }
            }
        }

        return new HomogeneousBatch(
            $representative->getConfig(),
            $representative->getEndpoint(),
            $items
        );
    }

    /**
     * ✅ NUEVO: Método Proxy para obtener metadatos sin exponer el Repositorio completo.
     */
    public function getGroupingMetadata(array $ids): array
    {
        return $this->repository->getGroupingMetadata($ids);
    }


}