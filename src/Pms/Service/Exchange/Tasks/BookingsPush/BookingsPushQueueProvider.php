<?php

declare(strict_types=1);

namespace App\Pms\Service\Exchange\Tasks\BookingsPush;

use App\Exchange\Service\Common\HomogeneousBatch;
use App\Exchange\Service\Contract\ExchangeQueueProviderInterface;
use App\Pms\Entity\PmsBookingsPushQueue;
use App\Pms\Repository\PmsBookingsPushQueueRepository;
use DateTimeImmutable;
use RuntimeException;

/**
 * Proveedor de cola para el envío de reservas (PUSH).
 * * Implementa el patrón "Claim Check" para garantizar que solo un worker procese el lote.
 * * Empaqueta ítems homogéneos (mismo endpoint/config) para procesamiento masivo.
 */
final readonly class BookingsPushQueueProvider implements ExchangeQueueProviderInterface
{
    public function __construct(
        private PmsBookingsPushQueueRepository $repository
    ) {}

    /**
     * Reclama un lote de tareas pendientes de subida de reservas.
     * * @param int $limit Número máximo de reservas a procesar en este ciclo.
     * @param string $workerId Identificador del proceso actual (para locking).
     * @param DateTimeImmutable $now Marca de tiempo actual.
     * * @return HomogeneousBatch|null Lote listo para procesar o null si la cola está vacía.
     * @throws RuntimeException Si se detecta corrupción de datos (falta de config/endpoint).
     */
    public function claimBatch(int $limit, string $workerId, DateTimeImmutable $now): ?HomogeneousBatch
    {
        $items = $this->repository->claimRunnable($limit, $workerId, $now, 90);
        return $this->packItems($items);
    }

    public function claimSpecificBatch(array $ids, string $workerId, DateTimeImmutable $now): ?HomogeneousBatch
    {
        $items = $this->repository->claimSpecificItems($ids, $workerId, $now);
        return $this->packItems($items, true);
    }

    /** @param PmsBookingsPushQueue[] $items */
    private function packItems(array $items, bool $strictCheck = false): ?HomogeneousBatch
    {
        if (empty($items)) return null;

        $representative = $items[0];
        $config = $representative->getConfig();
        $endpoint = $representative->getEndpoint();

        if (!$config || !$endpoint) {
            throw new RuntimeException("Integridad violada: Ítem PUSH #{$representative->getId()} incompleto.");
        }

        if ($strictCheck && count($items) > 1) {
            $refId = (string) $config->getId();
            foreach ($items as $item) {
                if ((string)$item->getConfig()->getId() !== $refId) {
                    throw new RuntimeException("Violación de homogeneidad en PUSH manual.");
                }
            }
        }

        return new HomogeneousBatch($config, $endpoint, $items);
    }

    /**
     * ✅ NUEVO: Método Proxy para obtener metadatos sin exponer el Repositorio completo.
     */
    public function getGroupingMetadata(array $ids): array
    {
        return $this->repository->getGroupingMetadata($ids);
    }
}