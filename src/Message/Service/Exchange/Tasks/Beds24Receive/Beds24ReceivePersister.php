<?php

declare(strict_types=1);

namespace App\Message\Service\Exchange\Tasks\Beds24Receive;

use App\Entity\Maestro\MaestroIdioma;
use App\Message\Dto\Beds24MessageDto;
use App\Message\Entity\Message;
use App\Message\Entity\MessageChannel;
use App\Message\Factory\MessageAttachmentFactory;
use App\Message\Factory\MessageConversationFactory;
use App\Message\Service\MessageJsonMerger;
use App\Message\Service\Translation\GuestLanguageDetectorService;
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
 * SRP: Recibe, limpia, detecta idioma localmente, enruta intención y persiste.
 */
readonly class Beds24ReceivePersister
{
    public function __construct(
        private EntityManagerInterface       $em,
        private MessageConversationFactory   $conversationFactory,
        private MessageAttachmentFactory     $attachmentFactory,
        private LoggerInterface              $logger,
        private MessageJsonMerger            $merger,
        private GuestLanguageDetectorService $languageDetector
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

        foreach ($messages as $dto) {
            if (!$dto->id) continue;

            $extId = (string) $dto->id;
            $source = $dto->source ?? Message::SENDER_GUEST;
            $existing = null;

            // Búsqueda de duplicados y actualización de lectura
            foreach ($conversation->getMessages() as $m) {
                if ($m->getBeds24ExternalId() === $extId) {
                    $existing = $m;
                    break;
                }
            }

            // Si ya existe y está identificado, actualizamos lectura y saltamos
            if ($existing) {
                if ($existing->getDirection() === Message::DIRECTION_INCOMING && $dto->read === true && $existing->getStatus() !== Message::STATUS_READ) {

                    $nowUtc = new DateTimeImmutable('now', new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');

                    try {
                        // 1. 🔥 MAGIA ATÓMICA AISLADA
                        $this->merger->merge($existing, 'beds24', ['read' => true, 'read_at' => $nowUtc]);
                    } catch (Throwable $e) {
                        $this->logger->warning("Falló el merge JSON atómico para el mensaje {$existing->getId()}: " . $e->getMessage());
                    }

                    // 2. Modificación normal vía UoW de Doctrine
                    $existing->setStatus(Message::STATUS_READ);

                    $stats['updated']++;

                } else {
                    $stats['skipped']++;
                }
                continue;
            }

            // =================================================================
            // NUEVO MENSAJE
            // =================================================================
            $message = new Message();
            $message->setConversation($conversation);
            $message->setBeds24ExternalId($extId);
            $message->setChannel($channel);
            $message->setSenderType($source);

            // 1. Limpieza de HTML de Airbnb y extracción de imágenes temporales
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

            // 2. Verdad histórica: Guardamos exactamente lo que llegó
            $message->setContentExternal($rawContent);

            $textoRecibido = trim(strip_tags($rawContent));
            $currentConversationLang = $conversation->getIdioma()?->getId() ?? 'es';

            if ($source === Message::SENDER_GUEST) {
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

                // =================================================================
                // 🧠 DETECCIÓN DINÁMICA DE IDIOMA (Local y Costo Cero)
                // =================================================================
                $idiomaEntity = null;
                $detectedLangCode = 'es';

                if (!empty($textoRecibido)) {
                    $rawDetected = $this->languageDetector->detectLanguageCode($textoRecibido, $currentConversationLang);

                    // Buscar el idioma detectado; si no existe en la tabla, fallback a 'en'
                    $idiomaEntity = $this->em->getRepository(MaestroIdioma::class)->find($rawDetected)
                        ?? $this->em->getRepository(MaestroIdioma::class)->find('en');

                    $detectedLangCode = $idiomaEntity?->getId() ?? 'es';
                }

                $message->setLanguageCode($detectedLangCode);

                // Auto-corrección del idioma de la conversación
                if ($idiomaEntity && $detectedLangCode !== $currentConversationLang) {
                    $conversation->setIdioma($idiomaEntity);
                }

                // =================================================================
                // 🎯 INTERCEPTOR DE MENÚS E INTENCIONES (Menu Fallback)
                // =================================================================
                $intent = [
                    'category'       => 'free_text',
                    'action_code'    => 'TXT_FREE',
                    'source_channel' => 'beds24',
                    'source_ota'     => $conversation->getContextOrigin(),
                    'context_type'   => $conversation->getContextType(),
                    'context_id'     => $conversation->getContextId(),
                    'resolved'       => false
                ];

                // 🎯 INTERCEPTOR DE MENÚS (Basado estrictamente en el último estado absoluto)
                if (strlen($textoRecibido) <= 20) {
                    if (preg_match('/^(?:opci[oó]n|opc|opt|option|n[uú]mero|num|#)?\s*(\d{1,2})$/i', $textoRecibido, $matches)) {
                        $opcionElegida = (int) $matches[1];

                        $lastMessage = $this->em->getRepository(Message::class)->findOneBy(
                            ['conversation' => $conversation],
                            ['createdAt' => 'DESC']
                        );

                        if ($lastMessage && $lastMessage->getTemplate() !== null) {
                            $metaJson = $lastMessage->getTemplate()->getWhatsappMetaTmpl();
                            $quickReplies = array_filter(
                                $metaJson['buttons_map'] ?? [],
                                fn($b) => strtolower($b['type'] ?? '') === 'quick_reply'
                            );
                            $quickRepliesList = array_values($quickReplies);

                            if (isset($quickRepliesList[$opcionElegida - 1])) {
                                $matchedButton = $quickRepliesList[$opcionElegida - 1];
                                $payloadOriginal = $matchedButton['payload'] ?? $matchedButton['resolver_key'] ?? null;

                                if ($payloadOriginal) {
                                    $intent['category'] = 'deterministic';
                                    $intent['action_code'] = $payloadOriginal;
                                }
                            }
                        }
                    }
                }

                $message->setInboundIntent($intent);

            } else {
                // Mensaje saliente (Host)
                $message->setDirection(Message::DIRECTION_OUTGOING);
                $message->setStatus(Message::STATUS_SENT);
                $message->setLanguageCode($currentConversationLang);
            }

            // Fechas y Zonas Horarias
            if ($dto->time !== null) {
                $timeUtc = $dto->time instanceof DateTimeImmutable ? $dto->time : DateTimeImmutable::createFromInterface($dto->time);
                $timeLima = $timeUtc->setTimezone(new DateTimeZone('America/Lima'));
                $message->setCreatedAt($timeLima);
            }

            // Adjuntos
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
                        $attachment = $this->attachmentFactory->createFromBase64($targetBase64, $targetFileName, $targetMime);
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
        }

        // =====================================================================
        // 🔥 EL ÚNICO FLUSH (Consolidación) 🔥
        // =====================================================================
        $this->em->flush();

        return $stats;
    }
}