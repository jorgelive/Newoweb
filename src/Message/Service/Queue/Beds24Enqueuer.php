<?php

declare(strict_types=1);

namespace App\Message\Service\Queue;

use App\Message\Contract\ChannelEnqueuerInterface;
use App\Message\Contract\MessageQueueItemInterface;
use App\Message\Entity\Beds24SendQueue;
use App\Message\Entity\Message;
use App\Message\Entity\MessageChannel;

class Beds24Enqueuer implements ChannelEnqueuerInterface
{
    public function supports(MessageChannel $channel): bool
    {
        return $channel->getId() === 'beds24';
    }

    public function createQueueEntity(
        Message $message,
        MessageChannel $channel,
        \DateTimeImmutable $runAt
    ): MessageQueueItemInterface {
        $queue = new Beds24SendQueue();

        $queue->setMessage($message);
        $queue->setStatus('pending');
        $queue->setRunAt($runAt);
        $queue->setRetryCount(0);
        $queue->setMaxAttempts(3);

        return $queue;
    }
}