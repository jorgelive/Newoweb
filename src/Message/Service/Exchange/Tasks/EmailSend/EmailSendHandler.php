<?php

declare(strict_types=1);

namespace App\Message\Service\Exchange\Tasks\EmailSend;

use App\Exchange\Service\Contract\ExchangeHandlerInterface;
use App\Exchange\Service\Contract\ExchangeQueueItemInterface;
use App\Message\Entity\EmailSendQueue;
use App\Message\Entity\Message;
use App\Message\Service\MessageJsonMerger;
use DateTimeImmutable;
use Throwable;

/**
 * Qué pasa con el mensaje cuando el correo sale — o no.
 *
 * El estado del `Message` es lo que ve el operador en el chat, y tiene que reflejar lo que de
 * verdad ocurrió: `sent` cuando el transporte lo aceptó, `failed` con el motivo cuando no.
 *
 * ⚠️ **«Aceptado por el transporte» no es «entregado».** Graph confirma que recogió el correo,
 * no que llegara a la bandeja: los rebotes llegan después y por otro camino. Marcarlo como
 * entregado sería prometer algo que no consta — el día que se lea el buzón de rebotes, ahí es
 * donde se afinará.
 */
final readonly class EmailSendHandler implements ExchangeHandlerInterface
{
    public function __construct(private MessageJsonMerger $merger)
    {
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function handleSuccess(array $data, ExchangeQueueItemInterface $item): array
    {
        if (!$item instanceof EmailSendQueue) {
            return ['status' => 'error'];
        }

        $item->setExternalId(isset($data['messageId']) ? (string) $data['messageId'] : null);

        // ⚠️ **CIERRA LA COLA.** El orquestador no lo hace: delega el estado final en el handler
        // de cada canal, y sin esta línea el ítem se queda en `processing` con su bloqueo puesto.
        // Entonces el watchdog lo declara `watchdog_timeout` pasado el TTL, vuelve a `failed` con
        // reintento pendiente… y **el correo sale por segunda vez**. Pasó con el primer envío
        // real: el mensaje llegó, la cola se quedó abierta.
        $item->markSuccess(new DateTimeImmutable());

        $mensaje = $item->getMessage();

        if ($mensaje !== null) {
            $this->merger->merge($mensaje, 'email', [
                'sent_at' => new DateTimeImmutable()->format('Y-m-d\TH:i:s\Z'),
                'message_id' => $item->getExternalId(),
                'to' => $item->getDestinationEmail(),
                'error_reason' => '',
            ], 'email', $item->getExternalId());

            $mensaje->setStatus(Message::STATUS_SENT);
        }

        return ['status' => 'ok'];
    }

    public function handleFailure(Throwable $e, ExchangeQueueItemInterface $item): void
    {
        if (!$item instanceof EmailSendQueue) {
            return;
        }

        $motivo = $e->getMessage() !== '' ? $e->getMessage() : ($item->getFailedReason() ?? 'Error desconocido al enviar el correo.');
        $mensaje = $item->getMessage();

        $item->setFailedReason($motivo);

        if ($mensaje !== null) {
            $this->merger->merge($mensaje, 'email', ['error_reason' => $motivo], 'email', null);

            // Sólo se marca fallido cuando ya no queda reintento: mientras el motor vaya a
            // volver a intentarlo, el mensaje sigue en curso y decir «falló» sería mentir a
            // quien lo está mirando.
            if ($item->getRetryCount() >= $item->getMaxAttempts()) {
                $mensaje->setStatus(Message::STATUS_FAILED);
            }
        }
    }
}
