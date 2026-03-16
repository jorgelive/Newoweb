<?php

declare(strict_types=1);

namespace App\Message\Service\Exchange\Tasks\Beds24Receive;

use App\Message\Dto\Beds24MessageDto;
use App\Message\Entity\Message;
use App\Message\Entity\MessageChannel;
use App\Message\Factory\MessageAttachmentFactory;
use App\Message\Factory\MessageConversationFactory;
use App\Pms\Entity\PmsReserva;
use App\Pms\Repository\PmsReservaRepository;
use App\Pms\Service\Message\PmsReservaMessageContext;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * Encargado de persistir mensajes provenientes de Beds24 (Pull o Webhooks)
 * garantizando idempotencia total y gestionando archivos adjuntos en Base64.
 * * Implementa manejo estricto de zonas horarias:
 * - La fecha de creación (createdAt) se persiste en America/Lima para visualización local.
 * - La metadata de auditoría (recibido/leído) se mantiene en UTC (ISO 8601).
 */
class Beds24ReceivePersister
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly MessageConversationFactory $conversationFactory,
        private readonly MessageAttachmentFactory $attachmentFactory,
        private readonly LoggerInterface $logger
    ) {}

    /**
     * Inserta o actualiza una colección de mensajes provenientes de Beds24.
     *
     * @param string $targetBookId El ID de la reserva en Beds24.
     * @param array<int, Beds24MessageDto> $messages Colección de DTOs de mensajes.
     * @return array<string, int> Estadísticas del proceso (imported, updated, skipped).
     * @throws RuntimeException Si la reserva no existe en el sistema local.
     */
    public function upsertMessages(string $targetBookId, array $messages): array
    {
        /** @var PmsReservaRepository $repo */
        $repo = $this->em->getRepository(PmsReserva::class);
        $reserva = $repo->findByAnyBeds24Id($targetBookId);

        if (!$reserva) {
            throw new RuntimeException("Reserva Beds24 $targetBookId (Master o Child) no encontrada localmente.");
        }

        $context = new PmsReservaMessageContext($reserva);
        $conversation = $this->conversationFactory->upsertFromContext($context);

        $channel = $this->em->getReference(MessageChannel::class, 'beds24');

        $stats = ['imported' => 0, 'updated' => 0, 'skipped' => 0];

        foreach ($messages as $dto) {
            if (!$dto->id) continue;

            $extId = (string) $dto->id;
            $source = $dto->source ?? Message::SENDER_GUEST;

            // =================================================================
            // 1. IDEMPOTENCIA Y ACTUALIZACIÓN DE ESTADO
            // =================================================================
            $existing = null;
            foreach ($conversation->getMessages() as $m) {
                if ($m->getBeds24ExternalId() === $extId) {
                    $existing = $m;
                    break;
                }
            }

            if ($existing) {
                // Solo actualizamos el estado si es del Huésped (Incoming)
                // y ha pasado de no-leído a leído en Beds24.
                if ($existing->getDirection() === Message::DIRECTION_INCOMING
                    && $dto->read === true
                    && $existing->getStatus() !== Message::STATUS_READ) {

                    $existing->setStatus(Message::STATUS_READ);

                    // Metadata: Guardamos el estado original y la auditoría del servidor en UTC
                    $existing->addBeds24Metadata('read', true);
                    $nowUtc = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z');
                    $existing->setBeds24ReadAt($nowUtc);

                    $stats['updated']++;
                } else {
                    // Protege los mensajes del Host (Outgoing) y los adjuntos locales de ser sobrescritos
                    $stats['skipped']++;
                }
                continue;
            }

            // =================================================================
            // 2. CREACIÓN DEL NUEVO MENSAJE
            // =================================================================
            $message = new Message();
            $message->setConversation($conversation);
            $message->setContentExternal($dto->message ?? '');
            $message->setBeds24ExternalId($extId);
            $message->setChannel($channel);
            $message->setSenderType($source);

            if ($source === Message::SENDER_HOST) {
                // Mensaje enviado por el Host directamente en OTA / Beds24
                $message->setDirection(Message::DIRECTION_OUTGOING);
                $message->setStatus(Message::STATUS_SENT);
            } else {
                // Mensaje entrante del huésped
                $message->setDirection(Message::DIRECTION_INCOMING);

                // 🔥 Se registra el momento de recepción en UTC para TODOS los mensajes entrantes
                $nowUtc = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z');
                $message->setBeds24ReceivedAt($nowUtc);

                if ($dto->read === true) {
                    $message->setStatus(Message::STATUS_READ);
                    $message->addBeds24Metadata('read', true);
                    // Como ya viene leído desde el inicio, registramos también la lectura en UTC
                    $message->setBeds24ReadAt($nowUtc);
                } else {
                    $message->setStatus(Message::STATUS_RECEIVED);
                    $message->addBeds24Metadata('read', false);
                }
            }

            // =================================================================
            // 3. MANEJO DE ZONAS HORARIAS (UTC -> America/Lima)
            // =================================================================
            // El 'time' que envía Beds24 incluye la 'Z' (Zulu/UTC).
            // Convertimos ese instante a la zona horaria de Perú para que la
            // base de datos (que no guarda Timezones) asuma la hora correcta.
            if ($dto->time !== null) {
                // Aseguramos inmutabilidad del objeto original
                $timeUtc = $dto->time instanceof DateTimeImmutable
                    ? $dto->time
                    : DateTimeImmutable::createFromInterface($dto->time);

                // Desplazamos la hora a Lima (UTC-5)
                $timeLima = $timeUtc->setTimezone(new DateTimeZone('America/Lima'));

                $message->setCreatedAt($timeLima);
            }

            // =================================================================
            // 4. PROCESAMIENTO DE ADJUNTOS (Blindado contra getattach.php)
            // =================================================================
            // Solo procesamos adjuntos si vienen del HUÉSPED para no procesar
            // los links inalcanzables que genera Beds24 para los mensajes del Host.
            if (!empty($dto->attachment) && $source === Message::SENDER_GUEST) {
                try {
                    $attachment = $this->attachmentFactory->createFromBase64(
                        $dto->attachment,
                        $dto->attachmentName ?? 'adjunto_' . uniqid() . '.file',
                        $dto->attachmentMimeType ?? 'application/octet-stream'
                    );

                    $message->addAttachment($attachment);
                    $this->em->persist($attachment);

                } catch (Throwable $e) {
                    $this->logger->error(sprintf(
                        'Fallo al procesar adjunto de Beds24 en msg %s: %s',
                        $extId,
                        $e->getMessage()
                    ));
                }
            }

            $conversation->addMessage($message);
            $this->em->persist($message);

            $stats['imported']++;
        }

        return $stats;
    }
}