<?php

declare(strict_types=1);

namespace App\Message\Service\Exchange\Tasks\Beds24Receive;

use App\Message\Dto\Beds24MessageDto;
use App\Message\Entity\Message;
use App\Message\Entity\MessageChannel;
use App\Message\Entity\MessageConversation;
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
 * Encargado de persistir mensajes provenientes de Beds24 (Pull o Webhooks).
 * Utiliza Deduplicación en Memoria (Un solo flush) con algoritmo de "Pareja más cercana"
 * para máxima performance y seguridad ante ráfagas de mensajes (ida y vuelta).
 */
class Beds24ReceivePersister
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly MessageConversationFactory $conversationFactory,
        private readonly MessageAttachmentFactory $attachmentFactory,
        private readonly LoggerInterface $logger
    ) {}

    public function upsertMessages(string $targetBookId, array $messages): array
    {
        /** @var PmsReservaRepository $repo */
        $repo = $this->em->getRepository(PmsReserva::class);
        $reserva = $repo->findByAnyBeds24Id($targetBookId);

        if (!$reserva) {
            throw new RuntimeException("Reserva Beds24 $targetBookId no encontrada.");
        }

        $context = new PmsReservaMessageContext($reserva);
        $conversation = $this->conversationFactory->upsertFromContext($context);
        $channel = $this->em->getReference(MessageChannel::class, 'beds24');

        $stats = ['imported' => 0, 'updated' => 0, 'skipped' => 0];

        // =====================================================================
        // 1. PROCESAMIENTO E INSERCIÓN EN MEMORIA
        // =====================================================================
        foreach ($messages as $dto) {
            if (!$dto->id) continue;

            $extId = (string) $dto->id;
            $source = $dto->source ?? Message::SENDER_GUEST;

            $existing = null;
            // Búsqueda fuerte inicial por ID exacto
            foreach ($conversation->getMessages() as $m) {
                if ($m->getBeds24ExternalId() === $extId) {
                    $existing = $m;
                    break;
                }
            }

            // Si ya existe y está identificado, actualizamos lectura y saltamos
            if ($existing) {
                if ($existing->getDirection() === Message::DIRECTION_INCOMING && $dto->read === true && $existing->getStatus() !== Message::STATUS_READ) {
                    $existing->setStatus(Message::STATUS_READ);
                    $existing->addBeds24Metadata('read', true);
                    $nowUtc = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z');
                    $existing->setBeds24ReadAt($nowUtc);
                    $stats['updated']++;
                } else {
                    $stats['skipped']++;
                }
                continue;
            }

            // Crear el mensaje nuevo (o potencial clon) en memoria
            $message = new Message();
            $message->setConversation($conversation);
            $message->setContentExternal($dto->message ?? '');
            $message->setBeds24ExternalId($extId);
            $message->setChannel($channel);
            $message->setSenderType($source);

            if ($source === Message::SENDER_HOST) {
                $message->setDirection(Message::DIRECTION_OUTGOING);
                $message->setStatus(Message::STATUS_SENT);
            } else {
                $message->setDirection(Message::DIRECTION_INCOMING);
                $nowUtc = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z');
                $message->setBeds24ReceivedAt($nowUtc);

                if ($dto->read === true) {
                    $message->setStatus(Message::STATUS_READ);
                    $message->addBeds24Metadata('read', true);
                    $message->setBeds24ReadAt($nowUtc);
                } else {
                    $message->setStatus(Message::STATUS_RECEIVED);
                    $message->addBeds24Metadata('read', false);
                }
            }

            // Manejo de zonas horarias (UTC -> America/Lima)
            if ($dto->time !== null) {
                $timeUtc = $dto->time instanceof DateTimeImmutable ? $dto->time : DateTimeImmutable::createFromInterface($dto->time);
                $timeLima = $timeUtc->setTimezone(new DateTimeZone('America/Lima'));
                $message->setCreatedAt($timeLima);
            }

            // Adjuntos del Huésped
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
                    $this->logger->error("Fallo adjunto msg $extId: {$e->getMessage()}");
                }
            }

            $conversation->addMessage($message);
            $this->em->persist($message);
            $stats['imported']++;
        }

        // =====================================================================
        // 2. DEDUPLICACIÓN EN MEMORIA (Antes del Flush)
        // =====================================================================
        $deduplicatedCount = $this->deduplicateOutgoingMessages($conversation);

        // Ajustamos las estadísticas de la operación
        $stats['imported'] -= $deduplicatedCount;
        $stats['updated'] += $deduplicatedCount;

        // =====================================================================
        // 3. 🔥 EL ÚNICO FLUSH 🔥
        // =====================================================================
        $this->em->flush();

        return $stats;
    }

    /**
     * Barre la memoria buscando huérfanos locales y los empareja con clones entrantes.
     * Soporta ráfagas de mensajes mediante ordenamiento cronológico y búsqueda de "pareja más cercana".
     * * @return int La cantidad de clones destruidos en memoria.
     */
    private function deduplicateOutgoingMessages(MessageConversation $conversation): int
    {
        $orphans = [];
        $withIds = [];
        $destroyedCount = 0;

        // 1. Clasificamos los mensajes de SALIDA
        foreach ($conversation->getMessages() as $message) {
            if ($message->getDirection() === Message::DIRECTION_OUTGOING) {
                if ($message->getBeds24ExternalId() === null) {
                    $orphans[] = $message;
                } else {
                    $withIds[] = $message;
                }
            }
        }

        // 2. Ordenamos cronológicamente (Del más viejo al más nuevo)
        // Usamos el operador Spaceship (<=>) para una comparación rápida.
        $sortByDate = fn($a, $b) => ($a->getCreatedAt() <=> $b->getCreatedAt());
        usort($orphans, $sortByDate);
        usort($withIds, $sortByDate);

        // 3. Buscamos la pareja ideal para cada huérfano
        foreach ($orphans as $orphan) {
            if ($orphan->getCreatedAt() === null) continue;

            $bestMatchIndex = null;
            $smallestDiff = 901; // Tolerancia de 15 minutos (900s)

            foreach ($withIds as $index => $withId) {
                if ($withId->getCreatedAt() === null) continue;

                $diffSeconds = abs($orphan->getCreatedAt()->getTimestamp() - $withId->getCreatedAt()->getTimestamp());

                // Buscamos el clon que tenga la MENOR diferencia de tiempo dentro de los 15 min
                if ($diffSeconds < $smallestDiff) {
                    $smallestDiff = $diffSeconds;
                    $bestMatchIndex = $index;
                }
            }

            // Si encontramos a la pareja ideal...
            if ($bestMatchIndex !== null) {
                $bestMatch = $withIds[$bestMatchIndex];

                // A) Heredamos la data oficial de Beds24 al mensaje original
                $orphan->setBeds24ExternalId($bestMatch->getBeds24ExternalId());
                $orphan->setChannel($bestMatch->getChannel());

                if (empty(trim($orphan->getContentExternal() ?? ''))) {
                    $orphan->setContentExternal($bestMatch->getContentExternal());
                }

                // B) Eliminamos al clon de la conversación
                $conversation->removeMessage($bestMatch);

                // C) Eliminamos al clon de la memoria de Doctrine (Nunca tocará MySQL)
                $this->em->remove($bestMatch);

                // D) Sacamos al clon de la piscina de disponibles
                unset($withIds[$bestMatchIndex]);

                $destroyedCount++;
            }
        }

        return $destroyedCount;
    }
}