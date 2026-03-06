<?php

declare(strict_types=1);

namespace App\Message\Service\Exchange\Tasks\Beds24Receive;

use App\Exchange\Service\Common\HomogeneousBatch;
use App\Exchange\Service\Contract\ExchangeQueueProviderInterface;
use App\Message\Repository\Beds24ReceiveQueueRepository;
use DateTimeImmutable;

final readonly class Beds24ReceiveQueueProvider implements ExchangeQueueProviderInterface
{
    public function __construct(private Beds24ReceiveQueueRepository $repository) {}

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

    private function packItems(array $items, bool $strictCheck = false): ?HomogeneousBatch
    {
        if (empty($items)) {
            return null;
        }
        return new HomogeneousBatch($items[0]->getConfig(), $items[0]->getEndpoint(), $items);
    }

    public function getGroupingMetadata(array $ids): array
    {
        return $this->repository->getGroupingMetadata($ids);
    }
}