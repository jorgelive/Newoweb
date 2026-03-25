<?php

declare(strict_types=1);

namespace App\Message\Service\Exchange\Tasks\WhatsappMetaReceive;

use App\Entity\Maestro\MaestroIdioma;
use App\Exchange\Entity\MetaConfig;
use App\Message\Entity\Message;
use App\Message\Entity\MessageChannel;
use App\Message\Entity\MessageConversation;
use App\Message\Factory\MessageAttachmentFactory;
use DateTimeImmutable;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query\ResultSetMappingBuilder;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Motor central de persistencia para WhatsApp Meta.
 * Agnóstico al transporte: Llamado por el Fast-Track (Webhooks) o por Workers (Pull).
 * * Este servicio se encarga de traducir los webhooks brutos de Meta en entidades locales,
 * respetando estrictamente las reglas de negocio de MessageConversation (Walk-ins, idiomas,
 * ventanas de 24h y contadores de mensajes no leídos).
 */
class WhatsappMetaReceivePersister
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly MessageAttachmentFactory $attachmentFactory,
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface $logger
    ) {}

    /**
     * Procesa un mensaje entrante de un huésped, ya sea de una reserva activa
     * o creando una nueva conversación de tipo Walk-in (Consulta Directa).
     */
    public function upsertInboundMessage(array $messageData, array $contactData): void
    {
        $metaMessageId = $messageData['id'] ?? null;
        if (!$metaMessageId) return;

        // 1. Deduplicación en BD (Evita duplicados si Meta reenvía el Webhook por delays)
        $existingMessage = $this->findMessageByMetaId($metaMessageId);
        if ($existingMessage) return;

        // 2. Extraer Remitente
        $guestPhone = $contactData['wa_id'] ?? null;
        if (!$guestPhone) return;

        $guestName = $contactData['profile']['name'] ?? 'Desconocido (WhatsApp)';

        // 3. Resolución de Conversación
        $conversation = $this->resolveConversation($guestPhone, $guestName);

        $channel = $this->em->getReference(MessageChannel::class, 'whatsapp_meta');

        // 4. Instanciar Mensaje
        $message = new Message();
        $message->setChannel($channel);
        $message->setDirection(Message::DIRECTION_INCOMING);
        $message->setSenderType(Message::SENDER_GUEST);
        $message->setStatus(Message::STATUS_RECEIVED);
        $message->setWhatsappMetaExternalId($metaMessageId);

        $timestamp = $messageData['timestamp'] ?? time();
        $message->setCreatedAt((new DateTimeImmutable())->setTimestamp((int)$timestamp));

        // =====================================================================
        // 5. PROCESAMIENTO DE CONTENIDO
        // =====================================================================
        $type = $messageData['type'] ?? 'text';

        if ($type === 'text') {
            $message->setContentExternal($messageData['text']['body'] ?? '');

        } elseif ($type === 'button') {
            $btnText = $messageData['button']['text'] ?? 'Botón seleccionado';
            $message->setContentExternal("🔘 [Respuesta rápida]: " . $btnText);

        } elseif ($type === 'interactive') {
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
            $mediaId = $messageData[$type]['id'] ?? null;
            $mimeType = $messageData[$type]['mime_type'] ?? 'application/octet-stream';
            $fileName = $messageData[$type]['filename'] ?? ($type . '_' . uniqid() . '.file');

            if ($mediaId) {
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
        // 6. GUARDAR Y NOTIFICAR
        // =====================================================================
        $conversation->addMessage($message);

        $this->em->persist($message);

        // El flush() local se mantiene, pero el commit() lo hará el FastTrackService
        $this->em->flush();
    }

    /**
     * Actualiza la metadata (Enviado, Entregado, Leído, Fallido) de un mensaje saliente.
     * * PROTECCIÓN ACTIVA: Usa bloqueo pesimista. Se apoya en la transacción del FastTrackService.
     */
    public function updateMessageStatus(array $statusData): void
    {
        $metaMessageId = $statusData['id'] ?? null;
        $status = $statusData['status'] ?? null;
        $timestamp = $statusData['timestamp'] ?? null;

        if (!$metaMessageId || !$status) return;

        $message = $this->findMessageByMetaId($metaMessageId);
        if (!$message) return;

        // 🔥 BLINDAJE CONTRA CONDICIONES DE CARRERA
        // El FastTrackService ya abrió la transacción. Solo aplicamos el candado a la fila
        // de MySQL y refrescamos los datos para no aplastar JSONs de procesos concurrentes.
        $this->em->lock($message, LockMode::PESSIMISTIC_WRITE);
        $this->em->refresh($message);

        $requiresUpdate = false;

        $isoDate = $timestamp
            ? (new \DateTimeImmutable("@$timestamp"))->format('Y-m-d\TH:i:s\Z')
            : (new \DateTimeImmutable())->format('Y-m-d\TH:i:s\Z');

        // Cascada de metadata
        if ($status === 'read') {
            if (!$message->getWhatsappMetaReadAt()) {
                $message->setWhatsappMetaReadAt($isoDate);
                $requiresUpdate = true;
            }
        } elseif ($status === 'delivered') {
            if (!$message->getWhatsappMetaDeliveredAt()) {
                $message->setWhatsappMetaDeliveredAt($isoDate);
                $requiresUpdate = true;
            }
        } elseif ($status === 'sent') {
            if (!$message->getWhatsappMetaSentAt()) {
                $message->setWhatsappMetaSentAt($isoDate);
                $requiresUpdate = true;
            }
        } elseif ($status === 'failed') {
            $errorInfo = $statusData['errors'][0] ?? [];
            $message->setWhatsappMetaErrorCode((string)($errorInfo['code'] ?? 'unknown'));
            $message->setWhatsappMetaErrorReason($errorInfo['message'] ?? json_encode($statusData['errors'] ?? [], JSON_UNESCAPED_UNICODE));

            if ($message->getStatus() !== Message::STATUS_READ) {
                $message->setStatus(Message::STATUS_FAILED);
            }

            $requiresUpdate = true;
        }

        if ($requiresUpdate) {
            $this->em->flush();
        }
    }

    /**
     * Registra una llamada perdida.
     */
    public function processCall(array $callData, array $contactData): void
    {
        $guestPhone = $contactData['wa_id'] ?? null;
        if (!$guestPhone) return;

        $guestName = $contactData['profile']['name'] ?? 'Huésped';
        $conversation = $this->resolveConversation($guestPhone, $guestName);

        $message = new Message();
        $message->setConversation($conversation);
        $message->setDirection(Message::DIRECTION_INCOMING);
        $message->setSenderType(Message::SENDER_SYSTEM);
        $message->setStatus(Message::STATUS_RECEIVED);
        $message->setContentExternal("📞 [Llamada perdida]: El huésped intentó llamarte por WhatsApp.");

        $timestamp = $callData['timestamp'] ?? time();
        $message->setCreatedAt((new DateTimeImmutable())->setTimestamp((int)$timestamp));

        $this->em->persist($message);
    }

    private function resolveConversation(string $phone, string $guestName): MessageConversation
    {
        $repo = $this->em->getRepository(MessageConversation::class);

        $qb = $repo->createQueryBuilder('c')
            ->where('c.status = :status')
            ->setParameter('status', MessageConversation::STATUS_OPEN)
            ->orderBy('c.createdAt', 'DESC')
            ->setMaxResults(1);

        if (strlen($phone) >= 9) {
            $coreNumber = substr($phone, -8);
            $qb->andWhere('c.guestPhone = :exactPhone OR c.guestPhone LIKE :phoneTail')
                ->setParameter('exactPhone', $phone)
                ->setParameter('phoneTail', '%' . $coreNumber);
        } else {
            $qb->andWhere('c.guestPhone = :exactPhone')
                ->setParameter('exactPhone', $phone);
        }

        /** @var MessageConversation|null $conversation */
        $conversation = $qb->getQuery()->getOneOrNullResult();

        if ($conversation) {
            $currentName = $conversation->getGuestName();
            if (empty($currentName) || stripos($currentName, 'Guest') !== false || $currentName === 'Desconocido (WhatsApp)') {
                $conversation->setGuestName($guestName);
            }
            return $conversation;
        }

        $conversation = new MessageConversation('manual', $phone);
        $conversation->setContextOrigin('whatsapp');
        $conversation->setGuestPhone($phone);
        $conversation->setGuestName($guestName);
        $conversation->setStatus(MessageConversation::STATUS_OPEN);

        $repoIdioma = $this->em->getRepository(MaestroIdioma::class);
        $idiomaDefault = $repoIdioma->find('es') ?? $repoIdioma->findOneBy([]);

        if ($idiomaDefault) {
            $conversation->setIdioma($idiomaDefault);
        }

        $this->em->persist($conversation);

        return $conversation;
    }

    private function findMessageByMetaId(string $metaMessageId): ?Message
    {
        $rsm = new ResultSetMappingBuilder($this->em);
        $rsm->addRootEntityFromClassMetadata(Message::class, 'm');

        $sql = 'SELECT * FROM msg_message m WHERE JSON_EXTRACT(m.external_ids, :path) = :metaId LIMIT 1';

        $query = $this->em->createNativeQuery($sql, $rsm);
        $query->setParameter('path', '$."whatsapp_meta"');
        $query->setParameter('metaId', $metaMessageId);

        return $query->getOneOrNullResult();
    }

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
            $response = $this->httpClient->request('GET', "{$baseUrl}/{$mediaId}", [
                'headers' => ['Authorization' => 'Bearer ' . $apiKey]
            ]);

            $mediaData = $response->toArray(false);
            $url = $mediaData['url'] ?? null;

            if (!$url) {
                $this->logger->warning("Meta no devolvió una URL válida para el Media ID: {$mediaId}");
                return null;
            }

            $fileResponse = $this->httpClient->request('GET', $url, [
                'headers' => ['Authorization' => 'Bearer ' . $apiKey]
            ]);

            $content = $fileResponse->getContent(false);

            if ($fileResponse->getStatusCode() !== 200 || empty($content)) {
                $this->logger->warning("Fallo al descargar el contenido del CDN. HTTP Code: " . $fileResponse->getStatusCode());
                return null;
            }

            return base64_encode($content);

        } catch (\Throwable $e) {
            $this->logger->error("Excepción al descargar Media de Meta ({$mediaId}): " . $e->getMessage());
            return null;
        }
    }
}