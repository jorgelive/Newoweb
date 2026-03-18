<?php

declare(strict_types=1);

namespace App\Message\Service\Exchange\Tasks\WhatsappMetaSend;

use App\Exchange\Service\Contract\ExchangeHandlerInterface;
use App\Exchange\Service\Contract\ExchangeQueueItemInterface;
use App\Message\Entity\Message;
use App\Message\Entity\WhatsappMetaSendQueue;
use DateTimeImmutable;
use Throwable;

final class WhatsappMetaSendHandler implements ExchangeHandlerInterface
{
    public function handleSuccess(array $data, ExchangeQueueItemInterface $item): array
    {
        if (!$item instanceof WhatsappMetaSendQueue) {
            return ['status' => 'error'];
        }

        $item->setLastHttpCode(200);

        // 1. Obtenemos el Remote Id
        $remoteId = $data['messageId'] ?? null;

        // 2. Actualizar Estado de Negocio
        $item->setDeliveryStatus(WhatsappMetaSendQueue::DELIVERY_SUBMITTED);

        // 3. Actualizar Mensaje Padre
        $msg = $item->getMessage();
        if ($msg) {

            if ($remoteId) {
                $msg->setWhatsappMetaExternalId((string) $remoteId);
            }

            if ($msg->getStatus() !== Message::STATUS_READ) {
                $msg->setStatus(Message::STATUS_SENT);
            }
        }

        // 4. Construir el Resultado de Ejecución para la auditoría JSON de la cola
        $summary = [
            'status' => 'success',
            'remote_whatsapp_meta_id' => $remoteId,
            'timestamp' => (new DateTimeImmutable())->format('Y-m-d H:i:s')
        ];

        $item->markSuccess(new DateTimeImmutable());

        return $summary;
    }

    public function handleFailure(Throwable $e, ExchangeQueueItemInterface $item): void
    {
        if (!$item instanceof WhatsappMetaSendQueue) return;

        $httpCode = (int)$e->getCode() ?: 500;
        $msgError = sprintf('[Code %s] %s', $httpCode, $e->getMessage());

        $item->setLastHttpCode($httpCode);

        // Fallo en Meta -> Failed en Mensaje Padre
        if ($item->getMessage()) {
            $item->getMessage()->setStatus(Message::STATUS_FAILED);
        }

        // Reintento rápido (1 min)
        $item->markFailure($msgError, $httpCode, new DateTimeImmutable('+1 minute'));
    }
}