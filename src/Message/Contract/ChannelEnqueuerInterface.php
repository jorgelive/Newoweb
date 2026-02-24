<?php

declare(strict_types=1);

namespace App\Message\Contract;

use App\Message\Entity\Message;
use App\Message\Entity\MessageChannel;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AutoconfigureTag('app.message.enqueuer')]
interface ChannelEnqueuerInterface
{
    public function supports(MessageChannel $channel): bool;

    /**
     * Instancia la entidad de cola correspondiente y la asocia al mensaje.
     * * @param Message $message El mensaje a encolar.
     * @param MessageChannel $channel El canal por donde saldrá.
     * @param \DateTimeImmutable $runAt La fecha/hora exacta en la que debe ejecutarse.
     * @return object Entidad de cola (ej: WhatsappGupshupSendQueue).
     */
    public function createQueueEntity(
        Message $message,
        MessageChannel $channel,
        \DateTimeImmutable $runAt
    ): object;
}