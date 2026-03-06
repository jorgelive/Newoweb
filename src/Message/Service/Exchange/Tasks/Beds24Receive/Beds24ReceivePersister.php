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
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * Encargado de persistir mensajes provenientes de Beds24 (Pull o Webhooks)
 * garantizando idempotencia total y gestionando archivos adjuntos en Base64.
 */
class Beds24ReceivePersister
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly MessageConversationFactory $conversationFactory,
        private readonly MessageAttachmentFactory $attachmentFactory,
        private readonly LoggerInterface $logger // Inyectamos el logger por si falla un adjunto
    ) {}

    public function upsertMessages(string $targetBookId, array $messages): array
    {
        // 1. Buscar la Reserva localmente
        /** @var PmsReservaRepository $repo*/
        $repo = $this->em->getRepository(PmsReserva::class);

        $reserva = $repo->findByAnyBeds24Id($targetBookId);

        if (!$reserva) {
            // Si no se encuentra, lanzamos excepción para que el Job quede como failed
            // o el Webhook devuelva error y no perdamos el rastro.
            throw new RuntimeException("Reserva Beds24 $targetBookId (Master o Child) no encontrada localmente.");
        }

        // 2. Obtener la Conversación y el Canal
        $context = new PmsReservaMessageContext($reserva);
        $conversation = $this->conversationFactory->upsertFromContext($context);

        $channel = $this->em->getReference(MessageChannel::class, 'beds24');

        $stats = ['imported' => 0, 'updated' => 0, 'skipped' => 0];

        // 3. Procesar cada mensaje
        foreach ($messages as $dto) {
            if (!$dto->id) continue;

            $extId = $dto->id;

            // Idempotencia
            $existing = null;
            foreach ($conversation->getMessages() as $m) {
                if ($m->getBeds24ExternalId() === $extId) {
                    $existing = $m;
                    break;
                }
            }

            if ($existing) {
                if ($existing->getDirection() === Message::DIRECTION_INCOMING
                    && $dto->read === true
                    && $existing->getStatus() !== Message::STATUS_READ) {

                    $existing->setStatus(Message::STATUS_READ);
                    $stats['updated']++;
                } else {
                    $stats['skipped']++;
                }
                continue;
            }

            // === CREACIÓN DEL NUEVO MENSAJE ===
            $message = new Message();
            $message->setConversation($conversation);
            $message->setContentExternal($dto->message ?? '');
            $message->setBeds24ExternalId($extId);
            $message->setChannel($channel);

            $source = $dto->source ?? Message::SENDER_GUEST;
            $message->setSenderType($source);

            if ($source === Message::SENDER_HOST) {
                $message->setDirection(Message::DIRECTION_OUTGOING);
                $message->setStatus(Message::STATUS_SENT);
            } else {
                $message->setDirection(Message::DIRECTION_INCOMING);
                $message->setStatus($dto->read ? Message::STATUS_READ : Message::STATUS_RECEIVED);
            }

            if ($dto->time) {
                $message->setCreatedAt($dto->time);
            }

            // === PROCESAMIENTO DE ADJUNTOS ===
            if (!empty($dto->attachment)) {
                try {
                    $attachment = $this->attachmentFactory->createFromBase64(
                        $dto->attachment,
                        $dto->attachmentName ?? 'adjunto_' . uniqid() . '.file',
                        $dto->attachmentMimeType ?? 'application/octet-stream'
                    );

                    $message->addAttachment($attachment);
                    // Es buena práctica persistir la entidad hija manualmente aunque haya Cascade
                    $this->em->persist($attachment);

                } catch (Throwable $e) {
                    // Si el adjunto falla (base64 corrupto), guardamos el mensaje pero logueamos el error
                    // para no perder el texto del huésped.
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