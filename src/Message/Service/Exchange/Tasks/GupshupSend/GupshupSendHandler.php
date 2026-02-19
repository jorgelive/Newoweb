<?php

declare(strict_types=1);

namespace App\Message\Service\Exchange\Tasks\GupshupSend;

use App\Exchange\Service\Contract\ExchangeHandlerInterface;
use App\Exchange\Service\Contract\ExchangeQueueItemInterface;
use App\Message\Entity\GupshupSendQueue;
use App\Message\Entity\Message;
use DateTimeImmutable;
use Throwable;

final class GupshupSendHandler implements ExchangeHandlerInterface
{
    public function handleSuccess(array $data, ExchangeQueueItemInterface $item): array
    {
        if (!$item instanceof GupshupSendQueue) {
            return ['status' => 'error'];
        }

        $item->setLastHttpCode(200);

        // 1. Guardar el ID externo (CRÍTICO para Webhooks)
        $remoteId = $data['messageId'] ?? null;
        if ($remoteId) {
            $item->setExternalMessageId((string)$remoteId);

            // También lo guardamos en el mensaje padre para referencia rápida
            if ($item->getMessage()) {
                $item->getMessage()->setExternalId((string)$remoteId);
            }
        }

        // 2. Actualizar Estado de Negocio
        $item->setDeliveryStatus(GupshupSendQueue::DELIVERY_SUBMITTED);

        // 3. Actualizar Mensaje Padre
        $msg = $item->getMessage();
        if ($msg && $msg->getStatus() !== Message::STATUS_READ) {
            $msg->setStatus(Message::STATUS_SENT);
        }

        $item->markSuccess(new DateTimeImmutable());

        return ['status' => 'success', 'gupshup_id' => $remoteId];
    }

    public function handleFailure(Throwable $e, ExchangeQueueItemInterface $item): void
    {
        if (!$item instanceof GupshupSendQueue) return;

        $httpCode = (int)$e->getCode() ?: 500;
        $msgError = sprintf('[Code %s] %s', $httpCode, $e->getMessage());

        $item->setLastHttpCode($httpCode);

        // Fallo en Gupshup -> Failed en Mensaje Padre
        if ($item->getMessage()) {
            $item->getMessage()->setStatus(Message::STATUS_FAILED);
        }

        // Reintento rápido (1 min)
        $item->markFailure($msgError, $httpCode, new DateTimeImmutable('+1 minute'));
    }
}