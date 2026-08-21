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

    /**
     * Identificador del MessageChannel al que pertenece esta cola ('beds24', 'whatsapp_meta'…).
     *
     * Existe para que el MessageRuleEngine pueda podar colas por canal sin conocer las clases
     * concretas: es el mismo id que el enqueuer declara soportar en `supports()`.
     */
    public function getChannelId(): string;

    /**
     * La tarea del motor que envía esta cola. Ej.: `beds24_message_send`.
     *
     * ── Por qué lo dice la cola y no un `match` en el listener ──────────────
     * Porque ahí estaba, nombrando los canales a mano en cuatro sitios —dos `instanceof` al
     * recoger, dos `if` al despachar— y **olvidar uno no rompía nada**: la cola se creaba, el
     * mensaje decía «encolado», y no salía nunca. Pasó con el correo: se encoló y se quedó
     * esperando a que alguien lo lanzara.
     *
     * Es la misma lección que ya está escrita en {@see \App\Message\Entity\Message::addQueue()}
     * — el canal nuevo no debe obligar a acordarse de nada.
     */
    public function getSendTaskName(): string;
}