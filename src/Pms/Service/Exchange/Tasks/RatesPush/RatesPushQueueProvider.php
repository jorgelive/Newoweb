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
        // 1. Reclamo de ítems mediante lógica de Locking en DB (Optimistic/Pessimistic según Repo)
        // Se define un TTL de 90 segundos para evitar bloqueos infinitos por fallos de proceso.
        $items = $this->repository->claimRunnable(
            limit: $limit,
            workerId: $workerId,
            now: $now,
            ttl: 90
        );

        if (empty($items)) {
            return null;
        }

        /** @var PmsRatesPushQueue $representative */
        $representative = $items[0];

        // 2. Extracción de contexto de red (Directo desde la entidad)
        // Al estar aplanado, evitamos navegar por múltiples relaciones (N+1) para obtener la URL.
        $config = $representative->getBeds24Config();
        $endpoint = $representative->getEndpoint();

        // Validación de Seguridad Técnica
        if (!$config) {
            throw new RuntimeException(sprintf(
                "Inconsistencia: El ítem de cola #%d no tiene configuración Beds24 asignada.",
                $representative->getId()
            ));
        }

        if (!$endpoint) {
            throw new RuntimeException(sprintf(
                "Integridad de datos violada: El ítem de cola #%d no tiene un endpoint asociado.",
                $representative->getId()
            ));
        }

        // 3. Empaquetar en el objeto de transporte HomogeneousBatch
        // Esto garantiza que todos los ítems del lote comparten el mismo destino y credenciales.
        return new HomogeneousBatch(
            config:   $config,
            endpoint: $endpoint,
            items:    $items
        );
    }
}