<?php

declare(strict_types=1);

namespace App\Message\Service\Exchange\Tasks\Beds24Send;

use App\Exchange\Service\Contract\ExchangeHandlerInterface;
use App\Exchange\Service\Contract\ExchangeQueueItemInterface;
use App\Message\Entity\Beds24SendQueue;
use App\Message\Entity\Message;
use App\Message\Service\MessageJsonMerger;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Throwable;

final class Beds24SendHandler implements ExchangeHandlerInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly MessageJsonMerger $merger
    ) {}

    public function handleSuccess(array $data, ExchangeQueueItemInterface $item): array
    {
        if (!$item instanceof Beds24SendQueue) {
            return ['status' => 'error', 'message' => 'Entidad incorrecta'];
        }

        // 1. Auditoría RAW (El 'lastResponseRaw' ya se llenó en el BatchProcessor)
        $item->setLastHttpCode(200);

        // 2. Extraer el ID externo del payload de Beds24
        // Según la API v2 de Beds24, al crear un mensaje viene en ['new']['id']
        // Dejamos el fallback ['id'] por si en algún momento hacen un update o cambia la respuesta.
        $remoteId = $data['new']['id'] ?? $data['id'] ?? null;

        // 3. Actualizar Estado del Mensaje Padre y Guardar ID de Idempotencia
        $msg = $item->getMessage();

        if ($msg) {
            // 1. Guardar el estado global de Doctrine
            $currentStatus = $msg->getStatus();
            if (in_array($currentStatus, [Message::STATUS_PENDING, Message::STATUS_QUEUED, Message::STATUS_FAILED], true)) {
                //Este fue un flujo de confirmación de lectura
                if ($msg->getDirection() === Message::DIRECTION_INCOMING) {
                    // 🔥Volvemos a poner Read a los mensajes que fueron puestos como queued por el encolador
                    $msg->setStatus(Message::STATUS_READ);
                } else {
                    $msg->setStatus(Message::STATUS_SENT);
                }
            }
            $this->em->flush();

            // 2. Operación Atómica de JSON (Metadata + External ID)
            $isoDate = (new DateTimeImmutable())->format('Y-m-d\TH:i:s\Z');

            $this->merger->merge(
                $msg,
                'beds24',
                ['sent_at' => $isoDate, 'error' => null],
                'beds24',
                $remoteId ? (string)$remoteId : null
            );
        }

        // 4. Construir el Resultado de Ejecución
        $summary = [
            'status' => 'success',
            'remote_beds24_id' => $remoteId,
            'timestamp' => (new DateTimeImmutable())->format('Y-m-d H:i:s')
        ];

        // 5. Marcar la cola como Éxito
        $item->markSuccess(new DateTimeImmutable());

        return $summary;
    }

    public function handleFailure(Throwable $e, ExchangeQueueItemInterface $item): void
    {
        if (!$item instanceof Beds24SendQueue) {
            return;
        }

        $httpCode = (int) $e->getCode();
        $auditCode = $httpCode === 0 ? 500 : $httpCode;
        $msgError = sprintf('[Code %s] %s', $httpCode, $e->getMessage());

        $item->setLastHttpCode($auditCode);

        // Si falla el envío, marcamos el mensaje padre como FAILED también
        $msg = $item->getMessage();

        if ($msg) {
            // 1. Guardar el estado global de Doctrine
            $currentStatus = $msg->getStatus();
            if (in_array($currentStatus, [Message::STATUS_PENDING, Message::STATUS_QUEUED], true)) {
                $msg->setStatus(Message::STATUS_FAILED);
            }
            $this->em->flush();

            // 2. Operación Atómica de JSON para registrar el error
            $this->merger->merge($msg, 'beds24', ['error' => $msgError]);
        }

        // Reintento: 2 minutos
        $nextRetry = new DateTimeImmutable('+2 minutes');
        $item->markFailure($msgError, $auditCode, $nextRetry);
    }
}