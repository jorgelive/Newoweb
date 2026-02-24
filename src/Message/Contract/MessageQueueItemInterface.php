<?php

declare(strict_types=1);

namespace App\Message\Contract;

use App\Exchange\Service\Contract\ExchangeQueueItemInterface;
use App\Message\Entity\Message;

/**
 * Contrato para cualquier entidad de cola originada desde el módulo de Mensajes.
 * Garantiza que la cola pueda ser procesada por Exchange y que mantenga
 * su relación bi-direccional con el mensaje original.
 */
interface MessageQueueItemInterface extends ExchangeQueueItemInterface
{
    public function getMessage(): ?Message;

    public function setMessage(?Message $message): self;
}