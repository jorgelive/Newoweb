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
        // 1. Reclamar ítems usando el patrón de bloqueo optimista/pesimista del repositorio.
        // TTL de 90 segundos: Si el worker muere, los ítems se liberan automáticamente después de este tiempo.
        $items = $this->repository->claimRunnable(
            limit: $limit,
            workerId: $workerId,
            now: $now,
            ttl: 90
        );

        if (empty($items)) {
            return null;
        }

        /** @var PmsBookingsPushQueue $representative */
        $representative = $items[0];

        // 2. Extracción y Validación de Contexto
        // Garantizamos que el lote tenga todos los metadatos necesarios para el Strategy.
        $config = $representative->getBeds24Config();
        $endpoint = $representative->getEndpoint();

        if (!$config) {
            throw new RuntimeException(sprintf(
                "Integridad violada: El ítem de cola PUSH #%d no tiene configuración Beds24.",
                $representative->getId()
            ));
        }

        if (!$endpoint) {
            throw new RuntimeException(sprintf(
                "Integridad violada: El ítem de cola PUSH #%d no tiene endpoint asociado.",
                $representative->getId()
            ));
        }

        // 3. Empaquetado Homogéneo
        return new HomogeneousBatch(
            config:   $config,
            endpoint: $endpoint,
            items:    $items
        );
    }
}