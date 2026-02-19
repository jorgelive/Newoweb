<?php

declare(strict_types=1);

namespace App\Message\Service\Exchange\Tasks\Beds24Send;

use App\Exchange\Service\Common\HomogeneousBatch;
use App\Exchange\Service\Contract\ExchangeQueueProviderInterface;
use App\Message\Entity\Beds24SendQueue;
use App\Message\Repository\Beds24SendQueueRepository;
use DateTimeImmutable;
use RuntimeException;

final readonly class Beds24SendQueueProvider implements ExchangeQueueProviderInterface
{
    public function __construct(
        private Beds24SendQueueRepository $repository
    ) {}

    public function claimBatch(int $limit, string $workerId, DateTimeImmutable $now): ?HomogeneousBatch
    {
        $items = $this->repository->claimRunnable($limit, $workerId, $now, 60);
        return $this->packItems($items);
    }

    public function claimSpecificBatch(array $ids, string $workerId, DateTimeImmutable $now): ?HomogeneousBatch
    {
        $items = $this->repository->claimSpecificItems($ids, $workerId, $now);
        return $this->packItems($items, true);
    }

    /** @param Beds24SendQueue[] $items */
    private function packItems(array $items, bool $strictCheck = false): ?HomogeneousBatch
    {
        if (empty($items)) return null;

        $rep = $items[0];
        $config = $rep->getConfig();
        $endpoint = $rep->getEndpoint();

        if (!$config || !$endpoint) {
            throw new RuntimeException("Integridad violada: Cola Beds24Send #{$rep->getId()} incompleta.");
        }

        if ($strictCheck && count($items) > 1) {
            $refCfg = (string)$config->getId();
            $refEp = (string)$endpoint->getId();

            foreach ($items as $item) {
                if ((string)$item->getConfig()->getId() !== $refCfg ||
                    (string)$item->getEndpoint()->getId() !== $refEp) {
                    throw new RuntimeException("Violación de homogeneidad en Beds24Send Batch Manual.");
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