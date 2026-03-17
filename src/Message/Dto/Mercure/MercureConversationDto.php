<?php

declare(strict_types=1);

namespace App\Message\Dto\Mercure;

use App\Message\Entity\MessageConversation;
use DateTimeInterface;
use JsonSerializable;

class MercureConversationDto implements JsonSerializable
{
    private string $eventType;
    private string $iri;
    private string $type = 'Conversation';
    private string $id;
    private string $status;
    private string $contextType;
    private string $contextId;
    private ?string $guestName;
    private ?string $guestPhone;
    private ?string $idioma;
    private bool $idiomaFijado;
    private ?string $lastMessageAt;
    private int $unreadCount;
    private ?string $createdAt;
    private bool $whatsappSessionActive;
    private ?string $contextOrigin;
    private ?string $contextStatusTag;
    private array $contextMilestones = [];
    private array $contextItems = [];
    private ?float $contextFinancialTotal;
    private bool $contextFinancialIsCleared;

    public function __construct(MessageConversation $conversation, string $eventType = 'conversation_updated')
    {
        $this->eventType = $eventType;
        $this->iri = '/platform/user/util/msg/conversations/' . $conversation->getId();
        $this->id = (string) $conversation->getId();
        $this->status = $conversation->getStatus();
        $this->contextType = $conversation->getContextType();
        $this->contextId = $conversation->getContextId();
        $this->guestName = $conversation->getGuestName();
        $this->guestPhone = $conversation->getGuestPhone();
        $this->idioma = $conversation->getIdioma() ? '/platform/public/maestro_idioma/' . $conversation->getIdioma()->getId() : null;
        $this->idiomaFijado = $conversation->isIdiomaFijado();
        $this->lastMessageAt = $conversation->getLastMessageAt() ? $conversation->getLastMessageAt()->format(DateTimeInterface::ATOM) : null;
        $this->unreadCount = $conversation->getUnreadCount();
        $this->createdAt = $conversation->getCreatedAt() ? $conversation->getCreatedAt()->format(DateTimeInterface::ATOM) : null;
        $this->whatsappSessionActive = $conversation->isWhatsappSessionActive();
        $this->contextOrigin = $conversation->getContextOrigin();
        $this->contextStatusTag = $conversation->getContextStatusTag();
        $this->contextMilestones = $conversation->getContextMilestones();
        $this->contextItems = $conversation->getContextItems();
        $this->contextFinancialTotal = $conversation->getContextFinancialTotal();
        $this->contextFinancialIsCleared = $conversation->getContextFinancialIsCleared();
    }

    public function jsonSerialize(): array
    {
        return [
            'type' => $this->getEventType(),
            'conversation' => [
                '@id' => $this->getIri(),
                '@type' => $this->getType(),
                'id' => $this->getId(),
                'status' => $this->getStatus(),
                'contextType' => $this->getContextType(),
                'contextId' => $this->getContextId(),
                'guestName' => $this->getGuestName(),
                'guestPhone' => $this->getGuestPhone(),
                'idioma' => $this->getIdioma(),
                'idiomaFijado' => $this->isIdiomaFijado(),
                'lastMessageAt' => $this->getLastMessageAt(),
                'unreadCount' => $this->getUnreadCount(),
                'createdAt' => $this->getCreatedAt(),
                'whatsappSessionActive' => $this->isWhatsappSessionActive(),
                'contextOrigin' => $this->getContextOrigin(),
                'contextStatusTag' => $this->getContextStatusTag(),
                'contextMilestones' => $this->getContextMilestones(),
                'contextItems' => $this->getContextItems(),
                'contextFinancialTotal' => $this->getContextFinancialTotal(),
                'contextFinancialIsCleared' => $this->getContextFinancialIsCleared(),
            ]
        ];
    }

    // =========================================================================
    // GETTERS Y SETTERS EXPLÍCITOS
    // =========================================================================

    public function getEventType(): string { return $this->eventType; }
    public function setEventType(string $eventType): self { $this->eventType = $eventType; return $this; }

    public function getIri(): string { return $this->iri; }
    public function setIri(string $iri): self { $this->iri = $iri; return $this; }

    public function getType(): string { return $this->type; }
    public function setType(string $type): self { $this->type = $type; return $this; }

    public function getId(): string { return $this->id; }
    public function setId(string $id): self { $this->id = $id; return $this; }

    public function getStatus(): string { return $this->status; }
    public function setStatus(string $status): self { $this->status = $status; return $this; }

    public function getContextType(): string { return $this->contextType; }
    public function setContextType(string $contextType): self { $this->contextType = $contextType; return $this; }

    public function getContextId(): string { return $this->contextId; }
    public function setContextId(string $contextId): self { $this->contextId = $contextId; return $this; }

    public function getGuestName(): ?string { return $this->guestName; }
    public function setGuestName(?string $guestName): self { $this->guestName = $guestName; return $this; }

    public function getGuestPhone(): ?string { return $this->guestPhone; }
    public function setGuestPhone(?string $guestPhone): self { $this->guestPhone = $guestPhone; return $this; }

    public function getIdioma(): ?string { return $this->idioma; }
    public function setIdioma(?string $idioma): self { $this->idioma = $idioma; return $this; }

    public function isIdiomaFijado(): bool { return $this->idiomaFijado; }
    public function setIdiomaFijado(bool $idiomaFijado): self { $this->idiomaFijado = $idiomaFijado; return $this; }

    public function getLastMessageAt(): ?string { return $this->lastMessageAt; }
    public function setLastMessageAt(?string $lastMessageAt): self { $this->lastMessageAt = $lastMessageAt; return $this; }

    public function getUnreadCount(): int { return $this->unreadCount; }
    public function setUnreadCount(int $unreadCount): self { $this->unreadCount = $unreadCount; return $this; }

    public function getCreatedAt(): ?string { return $this->createdAt; }
    public function setCreatedAt(?string $createdAt): self { $this->createdAt = $createdAt; return $this; }

    public function isWhatsappSessionActive(): bool { return $this->whatsappSessionActive; }
    public function setWhatsappSessionActive(bool $whatsappSessionActive): self { $this->whatsappSessionActive = $whatsappSessionActive; return $this; }

    public function getContextOrigin(): ?string { return $this->contextOrigin; }
    public function setContextOrigin(?string $contextOrigin): self { $this->contextOrigin = $contextOrigin; return $this; }

    public function getContextStatusTag(): ?string { return $this->contextStatusTag; }
    public function setContextStatusTag(?string $contextStatusTag): self { $this->contextStatusTag = $contextStatusTag; return $this; }

    public function getContextMilestones(): array { return $this->contextMilestones; }
    public function setContextMilestones(array $contextMilestones): self { $this->contextMilestones = $contextMilestones; return $this; }

    public function getContextItems(): array { return $this->contextItems; }
    public function setContextItems(array $contextItems): self { $this->contextItems = $contextItems; return $this; }

    public function getContextFinancialTotal(): ?float { return $this->contextFinancialTotal; }
    public function setContextFinancialTotal(?float $contextFinancialTotal): self { $this->contextFinancialTotal = $contextFinancialTotal; return $this; }

    public function getContextFinancialIsCleared(): bool { return $this->contextFinancialIsCleared; }
    public function setContextFinancialIsCleared(bool $contextFinancialIsCleared): self { $this->contextFinancialIsCleared = $contextFinancialIsCleared; return $this; }
}