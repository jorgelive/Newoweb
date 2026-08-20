<?php

declare(strict_types=1);

namespace App\Message\Contract;

use App\Message\Entity\Message;
use App\Message\Entity\MessageChannel;
use App\Message\Entity\MessageConversation;
use DateTimeImmutable;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AutoconfigureTag('app.message.enqueuer')]
interface ChannelEnqueuerInterface
{
    public function supports(MessageChannel $channel): bool;

    /**
     * Instancia la entidad de cola correspondiente y la asocia al mensaje.
     * Retorna NULL si faltan datos críticos (ej.: huésped sin teléfono, o reserva sin ID de Beds24).
     *
     * @param Message $message El mensaje a encolar.
     * @param MessageChannel $channel El canal por donde saldrá.
     * @param DateTimeImmutable $runAt La fecha/hora exacta en la que debe ejecutarse.
     * @return MessageQueueItemInterface|null
     */
    public function createQueueEntity(
        Message $message,
        MessageChannel $channel,
        DateTimeImmutable $runAt
    ): ?MessageQueueItemInterface;

    /**
     * Verifica en la base de datos si ya existe una cola activa para este mensaje.
     * Patrón Idempotencia para evitar duplicados por dobles flush() de Doctrine.
     */
    public function isAlreadyEnqueued(Message $message): bool;

    public function isValid(Message $message): bool;

    /**
     * ¿Puede este canal alcanzar a esta persona por este asunto, AHORA?
     *
     * Es {@see self::isValid()} sin el mensaje delante, y existe para poder preguntarlo **antes
     * de que el mensaje exista**: el panel necesita saber qué casillas ofrecer al operador
     * cuando abre un chat, y hasta ahora lo adivinaba en TypeScript.
     *
     * ⚠️ El espejo en el front estaba **sin declarar**, que es lo que lo hacía peligroso:
     * `ChatView.vue` decidía Beds24 con un `contextType !== 'pms_reserva'` y una lista de
     * orígenes copiada a mano de `Beds24SendEnqueuer`. Dos copias de una regla de negocio, y la
     * de TypeScript leía la CABECERA del hilo — la señal que dejó de ser fiable el día que las
     * conversaciones se fusionaron por persona.
     *
     * No se resolvió sondeando con un `Message` de mentira: `Message::setConversation()` se
     * engancha a la colección del hilo, que cascadea `persist`, así que el sondeo habría dejado
     * mensajes fantasma en cuanto algo hiciera flush en la misma petición.
     *
     * `null` en el asunto significa «no se sabe a cuál va»: entonces se juzga con el asunto
     * propio de la conversación, igual que hace el encolador.
     */
    public function disponiblePara(
        MessageConversation $conversacion,
        ?string $asuntoType = null,
        ?string $asuntoId = null
    ): bool;
}