<?php
declare(strict_types=1);

namespace App\Pms\Service\Exchange\Tasks\RatesPush;

use App\Exchange\Service\Common\HomogeneousBatch;
use App\Exchange\Service\Contract\ExchangeQueueProviderInterface;
use App\Pms\Entity\PmsRatesPushQueue;
use App\Pms\Repository\PmsRatesPushQueueRepository;
use DateTimeImmutable;
use RuntimeException;

final readonly class RatesPushQueueProvider implements ExchangeQueueProviderInterface
{
    public function __construct(
        private PmsRatesPushQueueRepository $repository
    ) {}

    /**
     * Reclama un lote de envíos de tarifas (Rates).
     * Extrae configuración y endpoint directamente de la entidad aplanada.
     */
    public function claimBatch(int $limit, string $workerId, DateTimeImmutable $now): ?HomogeneousBatch
    {
        // 1. Reclamar ítems (Locking en DB)
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

        // 2. Extracción de contexto (Directo, sin navegar a padre)
        $config = $representative->getBeds24Config();
        $endpoint = $representative->getEndpoint();

        if (!$endpoint) {
            throw new RuntimeException(sprintf(
                "Integridad de datos violada: El ítem de cola #%d no tiene un endpoint asociado.",
                $representative->getId()
            ));
        }

        // 3. Empaquetar en el objeto de transporte seguro
        return new HomogeneousBatch(
            config:   $config,
            endpoint: $endpoint,
            items:    $items
        );
    }
}