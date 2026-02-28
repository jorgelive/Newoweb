<?php

declare(strict_types=1);

namespace App\Message\Service\Queue;

use App\Exchange\Entity\ExchangeEndpoint;
use App\Exchange\Enum\ConnectivityProvider;
use App\Message\Contract\ChannelEnqueuerInterface;
use App\Message\Contract\MessageQueueItemInterface;
use App\Message\Entity\Beds24SendQueue;
use App\Message\Entity\Message;
use App\Message\Entity\MessageChannel;
use App\Message\Service\MessageDataResolverRegistry;
use Doctrine\ORM\EntityManagerInterface;

class Beds24Enqueuer implements ChannelEnqueuerInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly MessageDataResolverRegistry $resolverRegistry
    ) {}

    public function supports(MessageChannel $channel): bool
    {
        return $channel->getId() === 'beds24';
    }

    public function createQueueEntity(Message $message, MessageChannel $channel, \DateTimeImmutable $runAt): ?MessageQueueItemInterface
    {
        $conversation = $message->getConversation();
        if (!$conversation) {
            return null;
        }

        $resolver = $this->resolverRegistry->getResolver($conversation->getContextType());
        if (!$resolver) {
            return null;
        }

        // 1. Obtener la Metadata del PMS (Snapshot)
        $metadata = $resolver->getMetadata($conversation->getContextId());
        $config = $metadata['beds24_config'] ?? null;
        $bookId = $metadata['beds24_book_id'] ?? null;

        if (!$config || !$bookId) {
            return null;
        }

        // 2. Obtener el Endpoint Técnico
        $endpoint = $this->em->getRepository(ExchangeEndpoint::class)->findOneBy([
            'provider' => ConnectivityProvider::BEDS24,
            'accion'   => 'SEND_MESSAGE',
            'activo'   => true
        ]);

        if (!$endpoint) {
            return null;
        }

        // 3. Ensamblar la Cola
        $queue = new Beds24SendQueue();
        $queue->setMessage($message);
        $queue->setConfig($config);
        $queue->setEndpoint($endpoint);

        // 🔥 GUARDAMOS EL SNAPSHOT INICIAL
        $queue->setTargetBookId((string) $bookId);

        $queue->setStatus(Beds24SendQueue::STATUS_PENDING);
        $queue->setRunAt($runAt);
        $queue->setRetryCount(0);
        $queue->setMaxAttempts(3);

        return $queue;
    }
}