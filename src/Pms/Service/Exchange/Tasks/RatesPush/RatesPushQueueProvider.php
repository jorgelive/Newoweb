<?php

declare(strict_types=1);

namespace App\Pms\Service\Exchange\Tasks\RatesPush;

use App\Exchange\Service\Common\HomogeneousBatch;
use App\Exchange\Service\Contract\ExchangeQueueProviderInterface;
use App\Pms\Entity\PmsRatesPushQueue;
use App\Pms\Repository\PmsRatesPushQueueRepository;
use DateTimeImmutable;
use RuntimeException;

/**
 * Proveedor de cola para la sincronización de tarifas (Rates).
 * * Implementa ExchangeQueueProviderInterface para alimentar al Worker genérico.
 * * Utiliza la entidad aplanada PmsRatesPushQueue para minimizar JOINs y mejorar rendimiento.
 */
final readonly class RatesPushQueueProvider implements ExchangeQueueProviderInterface
{
    /**
     * @param PmsRatesPushQueueRepository $repository Repositorio especializado en gestión de estados de cola.
     */
    public function __construct(
        private PmsRatesPushQueueRepository $repository
    ) {}

    /**
     * Reclama un lote de envíos de tarifas (Rates) pendientes de procesar.
     * * @param int $limit Cantidad máxima de ítems por lote.
     * @param string $workerId Identificador único del proceso worker.
     * @param DateTimeImmutable $now Marca de tiempo para filtrar el run_at.
     * @return HomogeneousBatch|null Un lote homogéneo listo para envío o null si no hay trabajo.
     * @throws RuntimeException Si se detecta inconsistencia en el endpoint o configuración.
     */
    public function claimBatch(int $limit, string $workerId, DateTimeImmutable $now): ?HomogeneousBatch
    {
        $items = $this->repository->claimRunnable($limit, $workerId, $now, 90);
        return $this->packItems($items);
    }

    public function claimSpecificBatch(array $ids, string $workerId, DateTimeImmutable $now): ?HomogeneousBatch
    {
        $items = $this->repository->claimSpecificItems($ids, $workerId, $now);
        return $this->packItems($items, true); // true = validación estricta
    }

    /**
     * Helper privado para evitar duplicar lógica de empaquetado y validación.
     * @param PmsRatesPushQueue[] $items
     */
    private function packItems(array $items, bool $strictCheck = false): ?HomogeneousBatch
    {
        if (empty($items)) {
            return null;
        }

        $representative = $items[0];
        $config = $representative->getConfig();
        $endpoint = $representative->getEndpoint();

        if (!$config || !$endpoint) {
            throw new RuntimeException("Integridad violada: Ítem #{$representative->getId()} sin config/endpoint.");
        }

        // Validación Defensiva de Homogeneidad (Vital para modo manual)
        if ($strictCheck && count($items) > 1) {
            $refConfigId = (string) $config->getId();
            $refEndpointId = (string) $endpoint->getId();

            foreach ($items as $item) {
                if ((string)$item->getConfig()->getId() !== $refConfigId ||
                    (string)$item->getEndpoint()->getId() !== $refEndpointId) {
                    throw new RuntimeException(
                        "Error Crítico: Intento de procesar un lote mixto en modo manual. " .
                        "El Caller debe agrupar los IDs por Config y Endpoint antes de enviar."
                    );
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