<?php

declare(strict_types=1);

namespace App\Message\Service\Exchange\Tasks\WhatsappMetaReceive;

use App\Exchange\Entity\MetaConfig;
use App\Message\Entity\Message;
use App\Message\Entity\MessageChannel;
use App\Message\Entity\MessageConversation;
use App\Message\Factory\MessageAttachmentFactory;
use App\Message\Service\MercureBroadcaster;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Motor central de persistencia para WhatsApp Meta.
 * Agnóstico al transporte: Llamado por el Fast-Track (Webhooks) o por Workers (Pull).
 */
class WhatsappMetaReceivePersister
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly MercureBroadcaster $mercureBroadcaster,
        private readonly MessageAttachmentFactory $attachmentFactory,
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface $logger
    ) {}

    /**
     * Procesa un mensaje entrante de un huésped.
     */
    public function upsertInboundMessage(array $messageData, array $contactData): void
    {
        $metaMessageId = $messageData['id'] ?? null;
        if (!$metaMessageId) return;

        // 1. Deduplicación en BD (Evita duplicados si Meta reenvía el Webhook por delays)
        $existingMessage = $this->findMessageByMetaId($metaMessageId);
        if ($existingMessage) return;

        // 2. Extraer Remitente
        $guestPhone = $contactData['wa_id'] ?? null; // Formato internacional (ej: 51987654321)
        if (!$guestPhone) return;

        $guestName = $contactData['profile']['name'] ?? 'Huésped WhatsApp';

        // 3. Resolución de Conversación (Match con PMS o Walk-in)
        $conversation = $this->resolveConversation($guestPhone, $guestName);
        $channel = $this->em->getReference(MessageChannel::class, 'whatsapp_meta');

        // 4. Instanciar Mensaje
        $message = new Message();
        $message->setConversation($conversation);
        $message->setChannel($channel);
        $message->setDirection(Message::DIRECTION_INCOMING);
        $message->setSenderType(Message::SENDER_GUEST);
        $message->setStatus(Message::STATUS_RECEIVED);
        $message->setWhatsappMetaExternalId($metaMessageId);

        // Fecha original en la que el usuario envió el mensaje
        $timestamp = $messageData['timestamp'] ?? time();
        $message->setCreatedAt((new DateTimeImmutable())->setTimestamp((int)$timestamp));

        // =====================================================================
        // 5. PROCESAMIENTO DE CONTENIDO (Texto, Botones y Multimedia)
        // =====================================================================
        $type = $messageData['type'] ?? 'text';

        if ($type === 'text') {
            $message->setContentExternal($messageData['text']['body'] ?? '');

        } elseif ($type === 'button') {
            // Respuestas a botones de plantillas antiguas (Quick Replies)
            $btnText = $messageData['button']['text'] ?? 'Botón seleccionado';
            $message->setContentExternal("🔘 [Respuesta rápida]: " . $btnText);

        } elseif ($type === 'interactive') {
            // Respuestas a botones nuevos o listas de opciones
            $intType = $messageData['interactive']['type'] ?? '';
            if ($intType === 'button_reply') {
                $btnText = $messageData['interactive']['button_reply']['title'] ?? 'Botón';
                $message->setContentExternal("🔘 [Botón interactivo]: " . $btnText);
            } elseif ($intType === 'list_reply') {
                $listText = $messageData['interactive']['list_reply']['title'] ?? 'Opción';
                $message->setContentExternal("📋 [Opción de lista]: " . $listText);
            } else {
                $message->setContentExternal('🤖 [Interacción no soportada]');
            }

        } elseif (in_array($type, ['image', 'document', 'audio', 'video', 'sticker'])) {
            // Manejo de Archivos Multimedia
            $mediaId = $messageData[$type]['id'] ?? null;
            $mimeType = $messageData[$type]['mime_type'] ?? 'application/octet-stream';
            $fileName = $messageData[$type]['filename'] ?? ($type . '_' . uniqid() . '.file');

            if ($mediaId) {
                // Descargamos el binario de los servidores de Meta
                $base64Data = $this->downloadMediaFromMeta($mediaId);

                if ($base64Data) {
                    try {
                        $attachment = $this->attachmentFactory->createFromBase64($base64Data, $fileName, $mimeType);
                        $message->addAttachment($attachment);
                        $this->em->persist($attachment);
                        $message->setContentExternal("📦 [" . ucfirst($type) . " recibido]");
                    } catch (\Throwable $e) {
                        $this->logger->error("Error creando adjunto WA: " . $e->getMessage());
                        $message->setContentExternal("🚫 [Error procesando archivo multimedia]");
                    }
                } else {
                    $message->setContentExternal("🚫 [Archivo multimedia expirado o inaccesible]");
                }
            } else {
                $message->setContentExternal("🚫 [Archivo multimedia sin ID válido]");
            }
        } elseif ($type === 'location') {
            $lat = $messageData['location']['latitude'] ?? '';
            $lng = $messageData['location']['longitude'] ?? '';
            $message->setContentExternal("📍 [Ubicación compartida]: https://maps.google.com/?q={$lat},{$lng}");
        } else {
            $message->setContentExternal("🤖 [Tipo de mensaje no soportado: {$type}]");
        }

        // =====================================================================
        // 6. GUARDAR Y NOTIFICAR (Real-time)
        // =====================================================================
        $conversation->addMessage($message);
        $this->em->persist($message);
        $this->em->flush();

        // Broadcast a Vue.js
        $this->mercureBroadcaster->broadcastMessage($message);
        $this->mercureBroadcaster->broadcastConversationUpdate($conversation);
    }

    /**
     * Actualiza el estado (Enviado, Entregado, Leído, Fallido) de un mensaje saliente.
     */
    public function updateMessageStatus(array $statusData): void
    {
        $metaMessageId = $statusData['id'] ?? null;
        $status = $statusData['status'] ?? null; // 'sent', 'delivered', 'read', 'failed'

        if (!$metaMessageId || !$status) return;

        $message = $this->findMessageByMetaId($metaMessageId);
        if (!$message) return;

        $requiresUpdate = false;

        if ($status === 'read' && $message->getStatus() !== Message::STATUS_READ) {
            $message->setStatus(Message::STATUS_READ);
            $requiresUpdate = true;

        } elseif ($status === 'failed' && $message->getStatus() !== Message::STATUS_FAILED) {
            $message->setStatus(Message::STATUS_FAILED);
            $message->setWhatsappMetaErrorReason(json_encode($statusData['errors'] ?? [], JSON_UNESCAPED_UNICODE));
            $requiresUpdate = true;

        } elseif (in_array($status, ['sent', 'delivered']) && in_array($message->getStatus(), [Message::STATUS_QUEUED, Message::STATUS_PENDING])) {
            $message->setStatus(Message::STATUS_SENT);
            $requiresUpdate = true;
        }

        if ($requiresUpdate) {
            $this->em->flush();
            $this->mercureBroadcaster->broadcastMessage($message);
        }
    }

    /**
     * Busca una conversación abierta por teléfono, o crea una nueva genérica (Walk-in).
     */
    private function resolveConversation(string $phone, string $guestName): MessageConversation
    {
        $repo = $this->em->getRepository(MessageConversation::class);

        // A. Buscar si el huésped ya tiene una conversación ABIERTA
        // (Puede ser una reserva de Beds24 que ya mapeó este número)
        $conversation = $repo->findOneBy([
            'guestPhone' => $phone,
            'status' => MessageConversation::STATUS_OPEN
        ]);

        if ($conversation) {
            // Actualizamos el nombre si el de WhatsApp es más fresco o no teníamos uno
            if (empty($conversation->getGuestName()) || $conversation->getGuestName() === 'Guest') {
                $conversation->setGuestName($guestName);
            }
            return $conversation;
        }

        // B. Si no hay reserva abierta, creamos un contexto manual (Walk-in / Consulta General)
        // Usamos el número de teléfono como ID de contexto para agrupar su historial.
        $conversation = new MessageConversation('manual', $phone);
        $conversation->setGuestName($guestName);
        $conversation->setGuestPhone($phone);
        $conversation->setContextOrigin('WhatsApp Directo');
        $conversation->setStatus(MessageConversation::STATUS_OPEN);

        $this->em->persist($conversation);

        return $conversation;
    }

    /**
     * Busca un mensaje en BD usando la función JSON_EXTRACT de MySQL.
     */
    private function findMessageByMetaId(string $metaMessageId): ?Message
    {
        return $this->em->getRepository(Message::class)->createQueryBuilder('m')
            ->where('JSON_EXTRACT(m.externalIds, \'$."whatsapp_meta"\') = :metaId')
            ->setParameter('metaId', $metaMessageId)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Descarga el binario de un archivo multimedia desde la Graph API de Meta.
     * Retorna el contenido codificado en Base64 listo para el Factory.
     */
    private function downloadMediaFromMeta(string $mediaId): ?string
    {
        $config = $this->em->getRepository(MetaConfig::class)->findOneBy(['activo' => true]);
        $apiKey = $config?->getCredential('apiKey');
        $baseUrl = rtrim($config?->getBaseUrl() ?? 'https://graph.facebook.com/v19.0', '/');

        if (!$apiKey) {
            $this->logger->error("Intento de descarga de Media WA sin API Key configurada.");
            return null;
        }

        try {
            // Paso 1: Obtener la URL temporal del CDN de Meta usando el Media ID
            $response = $this->httpClient->request('GET', "{$baseUrl}/{$mediaId}", [
                'headers' => ['Authorization' => 'Bearer ' . $apiKey]
            ]);

            $mediaData = $response->toArray(false);
            $url = $mediaData['url'] ?? null;

            if (!$url) {
                $this->logger->warning("Meta no devolvió una URL válida para el Media ID: {$mediaId}");
                return null;
            }

            // Paso 2: Descargar el binario desde la URL (Requiere el Bearer Token)
            $fileResponse = $this->httpClient->request('GET', $url, [
                'headers' => ['Authorization' => 'Bearer ' . $apiKey]
            ]);

            $content = $fileResponse->getContent(false);

            if ($fileResponse->getStatusCode() !== 200 || empty($content)) {
                $this->logger->warning("Fallo al descargar el contenido del CDN de Meta. HTTP Code: " . $fileResponse->getStatusCode());
                return null;
            }

            return base64_encode($content);

        } catch (\Throwable $e) {
            $this->logger->error("Excepción al descargar Media de Meta ({$mediaId}): " . $e->getMessage());
            return null;
        }
    }
}