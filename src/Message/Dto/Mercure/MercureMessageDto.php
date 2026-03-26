<?php

declare(strict_types=1);

namespace App\Message\Dto\Mercure;

use App\Message\Entity\Message;
use DateTimeInterface;
use JsonSerializable;

/**
 * Data Transfer Object para estandarizar el payload de los mensajes
 * que se transmiten en tiempo real a través de Mercure.
 * Aisla la capa de dominio de la capa de transporte, asegurando
 * que Vue reciba la estructura exacta que espera ApiMessage.
 */
class MercureMessageDto implements JsonSerializable
{
    private string $context = '/platform/contexts/Message';
    private string $iri;
    private string $type = 'Message';
    private string $id;
    private string $direction;
    private string $status;
    private string $senderType;
    private ?string $contentLocal;
    private ?string $contentExternal;
    private ?string $createdAt;
    private ?string $scheduledAt;
    private ?string $effectiveDateTime;
    private bool $isScheduledForFuture;
    private string $conversation;
    private array $metadata = [];
    private ?array $channel = null;
    private array $attachments = [];
    private array $beds24SendQueues = [];
    private array $whatsappMetaSendQueues = [];

    /**
     * @var string|array|null Puede contener el IRI de la plantilla o un array con sus datos.
     */
    private string|array|null $template = null;

    /**
     * Construye el DTO a partir de una entidad Message de Doctrine.
     * Mapea manualmente las relaciones para evitar referencias circulares
     * y controla la inyección del dominio absoluto para los archivos adjuntos.
     *
     * @param Message $message La entidad origen.
     */
    public function __construct(Message $message)
    {
        $this->iri = '/platform/user/util/msg/messages/' . $message->getId();
        $this->id = (string) $message->getId();
        $this->direction = $message->getDirection();
        $this->status = $message->getStatus();
        $this->senderType = $message->getSenderType();
        $this->contentLocal = $message->getContentLocal();
        $this->contentExternal = $message->getContentExternal();
        $this->createdAt = $message->getCreatedAt() ? $message->getCreatedAt()->format(DateTimeInterface::ATOM) : null;
        $this->scheduledAt = $message->getScheduledAt() ? $message->getScheduledAt()->format(DateTimeInterface::ATOM) : null;
        $this->effectiveDateTime = $message->getEffectiveDateTime() ? $message->getEffectiveDateTime()->format(DateTimeInterface::ATOM) : null;

        // Asignamos el flag virtual
        $this->isScheduledForFuture = $message->isScheduledForFuture();

        $this->conversation = '/platform/user/util/msg/conversations/' . $message->getConversation()->getId();
        $this->metadata = $message->getMetadata();

        if ($message->getChannel()) {
            $this->channel = [
                '@type' => 'MessageChannel',
                '@id' => '/platform/.well-known/genid/' . uniqid(),
                'id' => (string) $message->getChannel()->getId()
            ];
        }

        // Mapeo de la plantilla como IRI para mantener compatibilidad con Vue
        if ($message->getTemplate()) {
            $this->template = '/platform/message_templates/' . $message->getTemplate()->getId();
        }

        // Definimos el dominio base una sola vez para concatenarlo a las rutas relativas
        $apiBaseUrl = rtrim($_ENV['APP_URL'] ?? 'https://api.openperu.pe', '/');

        // Se mapean los adjuntos formateando la URL para el frontend
        foreach ($message->getAttachments() as $attachment) {
            $fileUrl = $attachment->getFileUrl();

            // Si hay URL y es relativa (no empieza con http), le concatenamos el dominio de la API
            if ($fileUrl !== null && !str_starts_with($fileUrl, 'http://') && !str_starts_with($fileUrl, 'https://')) {
                $fileUrl = $apiBaseUrl . '/' . ltrim($fileUrl, '/');
            }

            $this->attachments[] = [
                '@id' => '/platform/user/util/msg/attachments/' . $attachment->getId(),
                '@type' => 'MessageAttachment',
                'id' => (string) $attachment->getId(),
                'originalName' => $attachment->getOriginalName(),
                'mimeType' => $attachment->getMimeType(),
                'fileUrl' => $fileUrl
            ];
        }

        foreach ($message->getBeds24SendQueues() as $queue) {
            $this->beds24SendQueues[] = ['status' => $queue->getStatus()];
        }

        foreach ($message->getWhatsappMetaSendQueues() as $queue) {
            $this->whatsappMetaSendQueues[] = [
                'status' => $queue->getStatus(),
                'deliveryStatus' => $queue->getDeliveryStatus()
            ];
        }
    }

    /**
     * Define la estructura exacta que se convertirá a JSON al emitir por Mercure.
     * Garantiza compatibilidad absoluta con la interfaz ApiMessage de Vue.
     *
     * @return array<string, mixed> El array asociativo listo para ser serializado a JSON.
     */
    public function jsonSerialize(): array
    {
        return [
            '@context' => $this->getContext(),
            '@id' => $this->getIri(),
            '@type' => $this->getType(),
            'id' => $this->getId(),
            'direction' => $this->getDirection(),
            'status' => $this->getStatus(),
            'senderType' => $this->getSenderType(),
            'contentLocal' => $this->getContentLocal(),
            'contentExternal' => $this->getContentExternal(),
            'createdAt' => $this->getCreatedAt(),
            'scheduledAt' => $this->getScheduledAt(),
            'effectiveDateTime' => $this->getEffectiveDateTime(),
            'isScheduledForFuture' => $this->getIsScheduledForFuture(),
            'conversation' => $this->getConversation(),
            'metadata' => $this->getMetadata(),
            'channel' => $this->getChannel(),
            'attachments' => $this->getAttachments(),
            'beds24SendQueues' => $this->getBeds24SendQueues(),
            'whatsappMetaSendQueues' => $this->getWhatsappMetaSendQueues(),
            'template' => $this->getTemplate(), // Se expone la propiedad recién agregada
        ];
    }

    // =========================================================================
    // GETTERS Y SETTERS EXPLÍCITOS
    // =========================================================================

    /**
     * Obtiene el contexto JSON-LD.
     */
    public function getContext(): string { return $this->context; }

    /**
     * Define el contexto JSON-LD.
     */
    public function setContext(string $context): self { $this->context = $context; return $this; }

    /**
     * Obtiene el identificador de recurso internacionalizado (IRI) para API Platform.
     */
    public function getIri(): string { return $this->iri; }

    /**
     * Define el identificador de recurso internacionalizado (IRI).
     */
    public function setIri(string $iri): self { $this->iri = $iri; return $this; }

    /**
     * Obtiene el tipo de recurso JSON-LD.
     */
    public function getType(): string { return $this->type; }

    /**
     * Define el tipo de recurso JSON-LD.
     */
    public function setType(string $type): self { $this->type = $type; return $this; }

    /**
     * Obtiene el identificador UUID del mensaje.
     */
    public function getId(): string { return $this->id; }

    /**
     * Define el identificador UUID del mensaje.
     */
    public function setId(string $id): self { $this->id = $id; return $this; }

    /**
     * Obtiene la dirección del mensaje (ej: 'incoming' o 'outgoing').
     */
    public function getDirection(): string { return $this->direction; }

    /**
     * Define la dirección del mensaje.
     */
    public function setDirection(string $direction): self { $this->direction = $direction; return $this; }

    /**
     * Obtiene el estado actual de entrega o lectura del mensaje.
     */
    public function getStatus(): string { return $this->status; }

    /**
     * Define el estado actual del mensaje.
     */
    public function setStatus(string $status): self { $this->status = $status; return $this; }

    /**
     * Obtiene el tipo de remitente (ej: 'guest', 'system', 'agent').
     */
    public function getSenderType(): string { return $this->senderType; }

    /**
     * Define el tipo de remitente.
     */
    public function setSenderType(string $senderType): self { $this->senderType = $senderType; return $this; }

    /**
     * Obtiene el contenido local del mensaje, visible internamente.
     */
    public function getContentLocal(): ?string { return $this->contentLocal; }

    /**
     * Define el contenido local del mensaje.
     */
    public function setContentLocal(?string $contentLocal): self { $this->contentLocal = $contentLocal; return $this; }

    /**
     * Obtiene el contenido externo del mensaje que se envía a plataformas terceras.
     */
    public function getContentExternal(): ?string { return $this->contentExternal; }

    /**
     * Define el contenido externo del mensaje.
     */
    public function setContentExternal(?string $contentExternal): self { $this->contentExternal = $contentExternal; return $this; }

    /**
     * Obtiene la fecha de creación del mensaje en formato ATOM.
     */
    public function getCreatedAt(): ?string { return $this->createdAt; }

    /**
     * Define la fecha de creación del mensaje.
     */
    public function setCreatedAt(?string $createdAt): self { $this->createdAt = $createdAt; return $this; }

    /**
     * Obtiene la fecha programada para el envío futuro, si aplica.
     */
    public function getScheduledAt(): ?string { return $this->scheduledAt; }

    /**
     * Define la fecha programada para el envío.
     */
    public function setScheduledAt(?string $scheduledAt): self { $this->scheduledAt = $scheduledAt; return $this; }

    /**
     * Obtiene la fecha y hora efectiva en la que el mensaje se considera activo/enviado.
     */
    public function getEffectiveDateTime(): ?string { return $this->effectiveDateTime; }

    /**
     * Define la fecha y hora efectiva.
     */
    public function setEffectiveDateTime(?string $effectiveDateTime): self { $this->effectiveDateTime = $effectiveDateTime; return $this; }

    /**
     * Indica si el mensaje está encolado para un envío futuro.
     */
    public function getIsScheduledForFuture(): bool { return $this->isScheduledForFuture; }

    /**
     * Define si el mensaje debe tratarse como programado en el futuro.
     */
    public function setIsScheduledForFuture(bool $isScheduledForFuture): self { $this->isScheduledForFuture = $isScheduledForFuture; return $this; }

    /**
     * Obtiene el IRI de la conversación a la que pertenece este mensaje.
     */
    public function getConversation(): string { return $this->conversation; }

    /**
     * Define la conversación asociada.
     */
    public function setConversation(string $conversation): self { $this->conversation = $conversation; return $this; }

    /**
     * Obtiene los metadatos asociados al mensaje (respuestas de canales, etc).
     */
    public function getMetadata(): array { return $this->metadata; }

    /**
     * Define los metadatos del mensaje.
     */
    public function setMetadata(array $metadata): self { $this->metadata = $metadata; return $this; }

    /**
     * Obtiene la estructura del canal por el cual se transmitió el mensaje.
     */
    public function getChannel(): ?array { return $this->channel; }

    /**
     * Define la estructura del canal asociado.
     */
    public function setChannel(?array $channel): self { $this->channel = $channel; return $this; }

    /**
     * Obtiene la colección de archivos adjuntos del mensaje formateada para Vue.
     */
    public function getAttachments(): array { return $this->attachments; }

    /**
     * Define los archivos adjuntos.
     */
    public function setAttachments(array $attachments): self { $this->attachments = $attachments; return $this; }

    /**
     * Obtiene el estado en las colas de envío hacia Beds24.
     */
    public function getBeds24SendQueues(): array { return $this->beds24SendQueues; }

    /**
     * Define el estado en las colas de Beds24.
     */
    public function setBeds24SendQueues(array $beds24SendQueues): self { $this->beds24SendQueues = $beds24SendQueues; return $this; }

    /**
     * Obtiene el estado en las colas de envío hacia WhatsApp Meta.
     */
    public function getWhatsappMetaSendQueues(): array { return $this->whatsappMetaSendQueues; }

    /**
     * Define el estado en las colas de WhatsApp Meta.
     */
    public function setWhatsappMetaSendQueues(array $whatsappMetaSendQueues): self { $this->whatsappMetaSendQueues = $whatsappMetaSendQueues; return $this; }

    /**
     * Obtiene la plantilla asociada al mensaje, si existe.
     *
     * @return string|array|null
     */
    public function getTemplate(): string|array|null { return $this->template; }

    /**
     * Define la plantilla asociada al mensaje.
     *
     * @param string|array|null $template
     * @return self
     */
    public function setTemplate(string|array|null $template): self { $this->template = $template; return $this; }
}