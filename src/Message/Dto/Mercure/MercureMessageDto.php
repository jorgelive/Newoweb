<?php

declare(strict_types=1);

namespace App\Message\Dto\Mercure;

use App\Message\Entity\Message;
use DateTimeInterface;
use JsonSerializable;

/**
 * Data Transfer Object para estandarizar el payload de los mensajes
 * que se transmiten en tiempo real a través de Mercure.
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
    private array $whatsappGupshupSendQueues = [];

    /**
     * Construye el DTO a partir de una entidad Message de Doctrine.
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
        $this->isScheduledForFuture = $message->getIsScheduledForFuture();

        $this->conversation = '/platform/user/util/msg/conversations/' . $message->getConversation()->getId();
        $this->metadata = $message->getMetadata();

        if ($message->getChannel()) {
            $this->channel = [
                '@type' => 'MessageChannel',
                '@id' => '/platform/.well-known/genid/' . uniqid(),
                'id' => (string) $message->getChannel()->getId()
            ];
        }

        foreach ($message->getAttachments() as $attachment) {
            $this->attachments[] = [
                '@id' => '/platform/user/util/msg/attachments/' . $attachment->getId(),
                '@type' => 'MessageAttachment',
                'id' => (string) $attachment->getId(),
                'originalName' => $attachment->getOriginalName(),
                'mimeType' => $attachment->getMimeType()
            ];
        }

        foreach ($message->getBeds24SendQueues() as $queue) {
            $this->beds24SendQueues[] = ['status' => $queue->getStatus()];
        }

        foreach ($message->getWhatsappGupshupSendQueues() as $queue) {
            $this->whatsappGupshupSendQueues[] = [
                'status' => $queue->getStatus(),
                'deliveryStatus' => $queue->getDeliveryStatus()
            ];
        }
    }

    /**
     * Define la estructura exacta que se convertirá a JSON al emitir por Mercure.
     * Garantiza compatibilidad absoluta con la interfaz ApiMessage de Vue.
     *
     * @return array<string, mixed>
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
            'whatsappGupshupSendQueues' => $this->getWhatsappGupshupSendQueues(),
        ];
    }

    // =========================================================================
    // GETTERS Y SETTERS EXPLÍCITOS
    // =========================================================================

    public function getContext(): string { return $this->context; }
    public function setContext(string $context): self { $this->context = $context; return $this; }
    public function getIri(): string { return $this->iri; }
    public function setIri(string $iri): self { $this->iri = $iri; return $this; }
    public function getType(): string { return $this->type; }
    public function setType(string $type): self { $this->type = $type; return $this; }
    public function getId(): string { return $this->id; }
    public function setId(string $id): self { $this->id = $id; return $this; }
    public function getDirection(): string { return $this->direction; }
    public function setDirection(string $direction): self { $this->direction = $direction; return $this; }
    public function getStatus(): string { return $this->status; }
    public function setStatus(string $status): self { $this->status = $status; return $this; }
    public function getSenderType(): string { return $this->senderType; }
    public function setSenderType(string $senderType): self { $this->senderType = $senderType; return $this; }
    public function getContentLocal(): ?string { return $this->contentLocal; }
    public function setContentLocal(?string $contentLocal): self { $this->contentLocal = $contentLocal; return $this; }
    public function getContentExternal(): ?string { return $this->contentExternal; }
    public function setContentExternal(?string $contentExternal): self { $this->contentExternal = $contentExternal; return $this; }
    public function getCreatedAt(): ?string { return $this->createdAt; }
    public function setCreatedAt(?string $createdAt): self { $this->createdAt = $createdAt; return $this; }
    public function getScheduledAt(): ?string { return $this->scheduledAt; }
    public function setScheduledAt(?string $scheduledAt): self { $this->scheduledAt = $scheduledAt; return $this; }
    public function getEffectiveDateTime(): ?string { return $this->effectiveDateTime; }
    public function setEffectiveDateTime(?string $effectiveDateTime): self { $this->effectiveDateTime = $effectiveDateTime; return $this; }
    public function getIsScheduledForFuture(): bool { return $this->isScheduledForFuture; }
    public function setIsScheduledForFuture(bool $isScheduledForFuture): self { $this->isScheduledForFuture = $isScheduledForFuture; return $this; }
    public function getConversation(): string { return $this->conversation; }
    public function setConversation(string $conversation): self { $this->conversation = $conversation; return $this; }
    public function getMetadata(): array { return $this->metadata; }
    public function setMetadata(array $metadata): self { $this->metadata = $metadata; return $this; }
    public function getChannel(): ?array { return $this->channel; }
    public function setChannel(?array $channel): self { $this->channel = $channel; return $this; }
    public function getAttachments(): array { return $this->attachments; }
    public function setAttachments(array $attachments): self { $this->attachments = $attachments; return $this; }
    public function getBeds24SendQueues(): array { return $this->beds24SendQueues; }
    public function setBeds24SendQueues(array $beds24SendQueues): self { $this->beds24SendQueues = $beds24SendQueues; return $this; }
    public function getWhatsappGupshupSendQueues(): array { return $this->whatsappGupshupSendQueues; }
    public function setWhatsappGupshupSendQueues(array $whatsappGupshupSendQueues): self { $this->whatsappGupshupSendQueues = $whatsappGupshupSendQueues; return $this; }
}