<?php

declare(strict_types=1);

namespace App\Message\Service\Queue;

use App\Exchange\Entity\Beds24Config;
use App\Exchange\Entity\ExchangeEndpoint;
use App\Message\Entity\Beds24ReceiveQueue;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;

final class Beds24ReceiveEnqueuer
{
    /** @var array<string, bool> Cache en memoria para deduplicar en el mismo lote */
    private array $runtimeDedupe = [];

    public function __construct(
        private readonly EntityManagerInterface $em
    ) {}

    public function enqueue(string $targetBookId, Beds24Config $config, ExchangeEndpoint $endpoint): ?Beds24ReceiveQueue
    {
        $dedupeKey = $config->getId() . '_' . $targetBookId;

        // 1. Deduplicación en Runtime (Lote actual)
        if (isset($this->runtimeDedupe[$dedupeKey])) {
            return null;
        }

        // 2. Deduplicación en Base de Datos (Si ya hay una pendiente, no la duplicamos)
        $existing = $this->em->getRepository(Beds24ReceiveQueue::class)->findOneBy([
            'targetBookId' => $targetBookId,
            'status'       => Beds24ReceiveQueue::STATUS_PENDING
        ]);

        if ($existing) {
            $this->runtimeDedupe[$dedupeKey] = true;
            return null;
        }

        // 3. Creación
        $queue = new Beds24ReceiveQueue($targetBookId);
        $queue->setConfig($config);
        $queue->setEndpoint($endpoint);
        $queue->setStatus(Beds24ReceiveQueue::STATUS_PENDING);
        $queue->setRunAt(new DateTimeImmutable());
        $queue->setRetryCount(0);
        $queue->setMaxAttempts(3);

        $this->em->persist($queue);
        $this->runtimeDedupe[$dedupeKey] = true;

        return $queue;
    }

    public function clearCache(): void
    {
        $this->runtimeDedupe = [];
    }
}