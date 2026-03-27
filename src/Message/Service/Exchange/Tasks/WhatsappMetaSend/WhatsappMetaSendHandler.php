<?php

declare(strict_types=1);

namespace App\Message\Service\Exchange\Tasks\WhatsappMetaSend;

use App\Exchange\Service\Contract\ExchangeHandlerInterface;
use App\Exchange\Service\Contract\ExchangeQueueItemInterface;
use App\Message\Entity\Message;
use App\Message\Entity\WhatsappMetaSendQueue;
use App\Message\Service\MessageJsonMerger;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Throwable;

final readonly class WhatsappMetaSendHandler implements ExchangeHandlerInterface
{
    /**
     * @param EntityManagerInterface $em Inyectado para aplicar bloqueo pesimista
     * y evitar sobrescritura de campos JSON concurrentes.
     */
    public function __construct(
        private EntityManagerInterface $em,
        private MessageJsonMerger      $merger
    ) {}

    public function handleSuccess(array $data, ExchangeQueueItemInterface $item): array
    {
        if (!$item instanceof WhatsappMetaSendQueue) {
            return ['status' => 'error'];
        }

        $item->setLastHttpCode(200);

        // 1. Obtenemos el Remote Id
        $remoteId = $data['messageId'] ?? null;

        // 2. Actualizar Estado de Negocio de la Cola
        $item->setDeliveryStatus(WhatsappMetaSendQueue::DELIVERY_SUBMITTED);

        // 3. Actualizar Mensaje Padre
        $msg = $item->getMessage();

        if ($msg) {
            // 1. Operación Atómica PRIMERO
            $isoDate = new DateTimeImmutable()->format('Y-m-d\TH:i:s\Z');

            $this->merger->merge(
                $msg,
                'whatsappMeta',
                ['sent_at' => $isoDate, 'error_code' => '', 'error_reason' => ''],
                'whatsapp_meta',
                $remoteId ? (string)$remoteId : null
            );

            // 2. Transiciones de Estado PHP + Touch para Mercure
            $currentStatus = $msg->getStatus();
            if (in_array($currentStatus, [Message::STATUS_PENDING, Message::STATUS_QUEUED, Message::STATUS_FAILED], true)) {
                if ($item->getEndpoint()->getAccion() === 'MARK_WHATSAPP_MESSAGE_READ') {
                    $msg->setStatus(Message::STATUS_READ);
                } else {
                    $msg->setStatus(Message::STATUS_SENT);
                }
            } else {
                // Touch explícito para que Doctrine dispare postUpdate
                $msg->setUpdatedAt(new DateTimeImmutable());
            }
        }

        // 4. Construir el Resultado de Ejecución
        $summary = [
            'status' => 'success',
            'remote_whatsapp_meta_id' => $remoteId,
            'timestamp' => new DateTimeImmutable()->format('Y-m-d H:i:s')
        ];

        $item->markSuccess(new DateTimeImmutable());

        return $summary;
    }

    public function handleFailure(Throwable $e, ExchangeQueueItemInterface $item): void
    {
        if (!$item instanceof WhatsappMetaSendQueue) return;

        $httpCode = $e->getCode() ?: 500;
        $msgError = sprintf('[Code %s] %s', $httpCode, $e->getMessage());

        $item->setLastHttpCode($httpCode);
        $msg = $item->getMessage();

        if ($msg) {
            // 1. Operación Atómica para registrar el error
            $this->merger->merge($msg, 'whatsappMeta', [
                'error_code'   => (string)$httpCode,
                'error_reason' => $msgError
            ]);

            // 2. Transiciones PHP + Touch
            $currentStatus = $msg->getStatus();
            if (in_array($currentStatus, [Message::STATUS_PENDING, Message::STATUS_QUEUED], true)) {
                $msg->setStatus(Message::STATUS_FAILED);
            } else {
                $msg->setUpdatedAt(new DateTimeImmutable());
            }
        }

        // Reintento rápido (1 min)
        $item->markFailure($msgError, $httpCode, new DateTimeImmutable('+1 minute'));
    }
}