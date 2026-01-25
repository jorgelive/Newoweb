<?php
declare(strict_types=1);

namespace App\Pms\Service\Exchange\Tasks\BookingsPull;

use App\Exchange\Service\Common\HomogeneousBatch;
use App\Exchange\Service\Contract\ExchangeQueueProviderInterface;
use App\Pms\Entity\PmsBookingsPullQueue;
use App\Pms\Repository\PmsBookingsPullQueueRepository;
use DateTimeImmutable;

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
        // 1. Obtener los registros de la base de datos (Garantizados de ser homogéneos por el Repo)
        $items = $this->repository->claimRunnable(
            limit: $limit,
            workerId: $workerId,
            now: $now,
            ttl: 300 // 5 minutos de margen para descargas pesadas
        );

        if (empty($items)) {
            return null;
        }

        /** @var PmsBookingsPullQueue $representative */
        $representative = $items[0];

        // 2. Empaquetar en el objeto de transporte seguro
        return new HomogeneousBatch(
            config:   $representative->getBeds24Config(),
            endpoint: $representative->getEndpoint(),
            items:    $items
        );
    }
}