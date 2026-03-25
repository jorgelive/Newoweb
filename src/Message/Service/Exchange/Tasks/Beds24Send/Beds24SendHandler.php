<?php

declare(strict_types=1);

namespace App\Message\Service\Exchange\Tasks\Beds24Send;

use App\Exchange\Service\Contract\ExchangeHandlerInterface;
use App\Exchange\Service\Contract\ExchangeQueueItemInterface;
use App\Message\Entity\Beds24SendQueue;
use App\Message\Entity\Message;
use DateTimeImmutable;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Throwable;

final class Beds24SendHandler implements ExchangeHandlerInterface
{
    /**
     * @param EntityManagerInterface $em Inyectado para aplicar bloqueo pesimista
     * y evitar sobrescritura de campos JSON concurrentes.
     */
    public function __construct(
        private readonly EntityManagerInterface $em
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
            // 🔥 PROTECCIÓN DE METADATA CONCURRENTE
            // Como ya estamos dentro de una transacción gestionada por el worker padre,
            // aplicamos un candado a la fila en MySQL y refrescamos los datos en memoria
            // para no aplastar el JSON que pudo haber guardado el worker de Meta.
            $this->em->lock($msg, LockMode::PESSIMISTIC_WRITE);
            $this->em->refresh($msg);

            // 🔥 PROTECCIÓN OMNICANAL: Si estaba encolado, pendiente, o si el otro canal
            // falló previamente, nosotros lo "rescatamos" subiéndolo a SENT globalmente.
            $currentStatus = $msg->getStatus();
            if (in_array($currentStatus, [Message::STATUS_PENDING, Message::STATUS_QUEUED, Message::STATUS_FAILED], true)) {
                //Este fue un flujo de confirmación de lectura
                if ($msg->getDirection() === Message::DIRECTION_INCOMING){
                    // 🔥Volvemos a poner Read a los mensajes que fueron puestos como queued por el encolador
                    $msg->setStatus(Message::STATUS_READ);
                }else{
                    $msg->setStatus(Message::STATUS_SENT);
                }
            }

            // 🔥 OMNICANALIDAD: Guardamos la verdad absoluta del canal en la metadata
            $isoDate = (new DateTimeImmutable())->format('Y-m-d\TH:i:s\Z');
            $msg->addBeds24Metadata('sent_at', $isoDate);

            // Si por algún motivo venía de un error previo, lo limpiamos
            $msg->addBeds24Metadata('error', null);

            // 🔥 CRÍTICO: Guardamos el ID remoto en el mensaje
            if ($remoteId) {
                $msg->setBeds24ExternalId((string) $remoteId);
            }

            // Hacemos flush del mensaje modificado (el commit lo hará el proceso padre)
            $this->em->flush();
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
            // 🔥 PROTECCIÓN DE METADATA CONCURRENTE
            $this->em->lock($msg, LockMode::PESSIMISTIC_WRITE);
            $this->em->refresh($msg);

            // 🔥 PROTECCIÓN OMNICANAL: Solo lo pasamos a FAILED globalmente si ningún
            // otro canal ha logrado enviarlo. Si el otro canal ya lo pasó a SENT o READ,
            // respetamos ese éxito global (el frontend ya mostrará el error individual por la metadata).
            $currentStatus = $msg->getStatus();
            if (in_array($currentStatus, [Message::STATUS_PENDING, Message::STATUS_QUEUED], true)) {
                $msg->setStatus(Message::STATUS_FAILED);
            }

            // 🔥 OMNICANALIDAD: Guardamos el error específico del canal
            $msg->addBeds24Metadata('error', $msgError);

            $this->em->flush();
        }

        // Reintento: 2 minutos
        $nextRetry = new DateTimeImmutable('+2 minutes');

        $item->markFailure($msgError, $auditCode, $nextRetry);
    }
}