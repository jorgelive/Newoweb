<?php

declare(strict_types=1);

namespace App\Message\Service\Exchange\Tasks\Beds24Receive;

use App\Message\Dto\Beds24MessageDto;
use App\Message\Entity\Message;
use App\Message\Entity\MessageChannel;
use App\Message\Factory\MessageAttachmentFactory;
use App\Message\Factory\MessageConversationFactory;
use App\Message\Service\MessageJsonMerger;
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
 * Actúa únicamente buscando por ID externo y actualizando/insertando.
 * Las condiciones de carrera se evitan asíncronamente en capas superiores.
 */
readonly class Beds24ReceivePersister
{
    public function __construct(
        private EntityManagerInterface     $em,
        private MessageConversationFactory $conversationFactory,
        private MessageAttachmentFactory   $attachmentFactory,
        private LoggerInterface            $logger,
        private MessageJsonMerger          $merger
    ) {}

    /**
     * Sincroniza los mensajes entrantes contra la base de datos local.
     * @param string $targetBookId El ID de la reserva en Beds24
     * @param Beds24MessageDto[] $messages Array de DTOs fuertemente tipados
     * @return array<string, int> Estadísticas de la operación ['imported', 'updated', 'skipped']
     */
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
        $mercureBroadcasts = [];

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
            // Si ya existe y está identificado, actualizamos lectura y saltamos
            if ($existing) {
                if ($existing->getDirection() === Message::DIRECTION_INCOMING && $dto->read === true && $existing->getStatus() !== Message::STATUS_READ) {

                    // 1. 🔥 MAGIA ATÓMICA PRIMERO: Hacemos el merge del JSON.
                    // El Merger actualiza la BD y hace un $em->refresh($existing) internamente.
                    $nowUtc = new DateTimeImmutable('now', new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
                    $this->merger->merge(
                        $existing,
                        'beds24',
                        ['read' => true, 'read_at' => $nowUtc]
                    );

                    // 2. CAMBIO DE ESTADO DESPUÉS: Como la entidad ya fue refrescada,
                    // ahora le cambiamos el status en la memoria de PHP de forma segura.
                    $existing->setStatus(Message::STATUS_READ);

                    $stats['updated']++;
                    $mercureBroadcasts[] = $existing;

                    // ❌ NO hacemos flush aquí.
                    // El $this->em->flush() global al final de la función guardará todos
                    // los cambios de status juntos al terminar el bucle.
                } else {
                    $stats['skipped']++;
                }
                continue;
            }

            // Crear el mensaje nuevo en memoria
            $message = new Message();
            $message->setConversation($conversation);
            $message->setBeds24ExternalId($extId);
            $message->setChannel($channel);
            $message->setSenderType($source);

            // 🔥 INTERCEPTOR DE IMÁGENES DE AIRBNB (Enlaces temporales AWS S3) 🔥
            $rawContent = $dto->message ?? '';
            $extractedImageContent = null;
            $extractedImageMime = 'image/png';
            $extractedImageName = 'airbnb_' . uniqid() . '.png';

            if (str_contains($rawContent, 'muscache.com')) {
                $cleanHtml = stripslashes($rawContent);

                if (preg_match('/src="(https:\/\/a0\.muscache\.com[^"]+)"/i', $cleanHtml, $matches)) {
                    $imageUrl = html_entity_decode($matches[1]);

                    try {
                        $contextStream = stream_context_create(['http' => ['timeout' => 5]]);
                        $imageStream = @file_get_contents($imageUrl, false, $contextStream);

                        if ($imageStream !== false) {
                            $extractedImageContent = base64_encode($imageStream);
                            $rawContent = '📷 Imagen recibida desde Airbnb';
                        } else {
                            $this->logger->warning("Imagen de Airbnb expirada o bloqueada. URL: " . substr($imageUrl, 0, 100) . "...");
                            $rawContent = '🚫 [La imagen compartida por el huésped ha expirado]';
                        }
                    } catch (Throwable $e) {
                        $this->logger->warning("Error al descargar imagen de Airbnb: {$e->getMessage()}");
                        $rawContent = '🚫 [Error al procesar la imagen de Airbnb]';
                    }
                }
            }

            $message->setContentExternal($rawContent);

            if ($source === Message::SENDER_HOST) {
                $message->setDirection(Message::DIRECTION_OUTGOING);
                $message->setStatus(Message::STATUS_SENT);
            } else {
                $message->setDirection(Message::DIRECTION_INCOMING);
                $nowUtc = new DateTimeImmutable('now', new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
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

            // =================================================================
            // MANEJO DE ADJUNTOS (Base64 Nativos de Beds24 o Imágenes Extraídas)
            // =================================================================
            if ($source === Message::SENDER_GUEST) {
                $targetBase64 = null;
                $targetFileName = null;
                $targetMime = null;

                if ($extractedImageContent !== null) {
                    $targetBase64 = $extractedImageContent;
                    $targetFileName = $extractedImageName;
                    $targetMime = $extractedImageMime;
                } elseif (!empty($dto->attachment)) {
                    $targetBase64 = $dto->attachment;
                    $targetFileName = $dto->attachmentName ?? 'adjunto_' . uniqid() . '.file';
                    $targetMime = $dto->attachmentMimeType ?? 'application/octet-stream';
                }

                if ($targetBase64 !== null) {
                    try {
                        $attachment = $this->attachmentFactory->createFromBase64(
                            $targetBase64,
                            $targetFileName,
                            $targetMime
                        );
                        $message->addAttachment($attachment);
                        $this->em->persist($attachment);
                    } catch (Throwable $e) {
                        $this->logger->error("Fallo adjunto msg $extId: {$e->getMessage()}");
                    }
                }
            }

            $conversation->addMessage($message);
            $this->em->persist($message);
            $stats['imported']++;
            $mercureBroadcasts[] = $message;
        }

        // =====================================================================
        // 🔥 EL ÚNICO FLUSH (Consolidación) 🔥
        // =====================================================================
        $this->em->flush();

        return $stats;
    }
}