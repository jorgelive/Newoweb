<?php

declare(strict_types=1);

namespace App\Message\Service\Exchange\Tasks\WhatsappMetaSend;

use App\Exchange\Service\Contract\ExchangeHandlerInterface;
use App\Exchange\Service\Contract\ExchangeQueueItemInterface;
use App\Message\Entity\Message;
use App\Message\Entity\WhatsappMetaSendQueue;
use DateTimeImmutable;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Throwable;

final class WhatsappMetaSendHandler implements ExchangeHandlerInterface
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
            // 🔥 PROTECCIÓN DE METADATA CONCURRENTE
            // Como ya estamos dentro de una transacción gestionada por el worker padre,
            // aplicamos candado a la fila en MySQL y refrescamos los datos en memoria.
            $this->em->lock($msg, LockMode::PESSIMISTIC_WRITE);
            $this->em->refresh($msg);

            if ($remoteId) {
                $msg->setWhatsappMetaExternalId((string) $remoteId);
            }

            // 🔥 PROTECCIÓN OMNICANAL
            $currentStatus = $msg->getStatus();
            if (in_array($currentStatus, [Message::STATUS_PENDING, Message::STATUS_QUEUED, Message::STATUS_FAILED], true)) {
                if ($item->getEndpoint()->getAccion()  === 'MARK_WHATSAPP_MESSAGE_READ'){
                    // 🔥Volvemos a poner Read a los mensajes que fueron puestos como queued por el encolador
                    $msg->setStatus(Message::STATUS_READ);
                }else{
                    $msg->setStatus(Message::STATUS_SENT);
                }
            }

            // 🔥 OMNICANALIDAD: Guardamos la verdad absoluta del canal en la metadata
            $isoDate = (new DateTimeImmutable())->format('Y-m-d\TH:i:s\Z');
            $msg->setWhatsappMetaSentAt($isoDate);

            // Limpiamos errores previos en caso de que esto sea un reintento exitoso
            $msg->setWhatsappMetaErrorCode('');
            $msg->setWhatsappMetaErrorReason('');

            // Hacemos flush del mensaje modificado (el commit lo hará el proceso padre)
            $this->em->flush();
        }

        // 4. Construir el Resultado de Ejecución
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
        $msg = $item->getMessage();
        if ($msg) {
            // 🔥 PROTECCIÓN DE METADATA CONCURRENTE
            $this->em->lock($msg, LockMode::PESSIMISTIC_WRITE);
            $this->em->refresh($msg);

            // 🔥 PROTECCIÓN OMNICANAL
            $currentStatus = $msg->getStatus();
            if (in_array($currentStatus, [Message::STATUS_PENDING, Message::STATUS_QUEUED], true)) {
                $msg->setStatus(Message::STATUS_FAILED);
            }

            // Guardamos el error específico del canal
            $msg->setWhatsappMetaErrorCode((string)$httpCode);
            $msg->setWhatsappMetaErrorReason($msgError);

            $this->em->flush();
        }

        // Reintento rápido (1 min)
        $item->markFailure($msgError, $httpCode, new DateTimeImmutable('+1 minute'));
    }
}