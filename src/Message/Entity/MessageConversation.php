<?php

declare(strict_types=1);

namespace App\Message\Entity;

use ApiPlatform\Doctrine\Orm\Filter\OrderFilter;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use App\Entity\Maestro\MaestroIdioma;
use App\Entity\Trait\IdTrait;
use App\Entity\Trait\TimestampTrait;
use DateTimeInterface;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Annotation\Groups;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'msg_conversation')]
#[ApiResource(
    shortName: 'Conversation',
    operations: [
        new GetCollection(uriTemplate: '/user/util/msg/conversations'),
        new Get(uriTemplate: '/user/util/msg/conversations/{id}')
    ],
    normalizationContext: ['groups' => ['conversation:read']],
    order: ['lastMessageAt' => 'DESC']
)]
#[ApiFilter(OrderFilter::class, properties: ['lastMessageAt' => 'DESC', 'createdAt' => 'DESC'])]
class MessageConversation
{
    use IdTrait;
    use TimestampTrait;

    public const STATUS_OPEN = 'open';
    public const STATUS_CLOSED = 'closed';
    public const STATUS_ARCHIVED = 'archived';

    #[ORM\Column(type: 'string', length: 20, options: ['default' => self::STATUS_OPEN])]
    #[Groups(['conversation:read'])]
    private string $status = self::STATUS_OPEN;

    #[ORM\Column(type: 'string', length: 50)]
    #[Groups(['conversation:read'])]
    private string $contextType;

    #[ORM\Column(type: 'string', length: 100)]
    #[Groups(['conversation:read'])]
    private string $contextId;

    #[ORM\Column(type: 'string', length: 180, nullable: true)]
    #[Groups(['conversation:read'])]
    private ?string $guestName = null;

    #[ORM\Column(type: 'string', length: 30, nullable: true)]
    #[Groups(['conversation:read'])]
    private ?string $guestPhone = null;

    #[ORM\ManyToOne(targetEntity: MaestroIdioma::class)]
    #[ORM\JoinColumn(name: 'idioma_id', referencedColumnName: 'id', nullable: false)]
    #[Groups(['conversation:read'])]
    private MaestroIdioma $idioma;

    #[ORM\Column(type: 'datetime', nullable: true)]
    #[Groups(['conversation:read'])]
    private ?DateTimeInterface $lastMessageAt = null;

    #[ORM\Column(type: 'datetime', nullable: true)]
    #[Groups(['conversation:read'])]
    private ?DateTimeInterface $lastInboundAt = null;

    #[ORM\Column(type: 'integer', options: ['default' => 0])]
    #[Groups(['conversation:read'])]
    private int $unreadCount = 0;

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $contextData = [];

    #[ORM\OneToMany(mappedBy: 'conversation', targetEntity: Message::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['createdAt' => 'ASC'])]
    private Collection $messages;

    public function __construct(string $contextType, string $contextId)
    {
        $this->contextType = $contextType;
        $this->contextId = $contextId;
        $this->messages = new ArrayCollection();
        $this->contextData = [];
        $this->id = Uuid::v7();
    }

    #[Groups(['conversation:read'])]
    public function getId(): ?Uuid { return $this->id; }

    #[Groups(['conversation:read'])]
    public function getCreatedAt(): ?DateTimeInterface { return $this->createdAt ?? null; }

    public function getStatus(): string { return $this->status; }
    public function setStatus(string $status): self { $this->status = $status; return $this; }

    public function getContextType(): string { return $this->contextType; }
    public function getContextId(): string { return $this->contextId; }

    public function getGuestName(): ?string { return $this->guestName; }
    public function setGuestName(?string $guestName): self { $this->guestName = $guestName; return $this; }

    public function getGuestPhone(): ?string { return $this->guestPhone; }
    public function setGuestPhone(?string $guestPhone): self { $this->guestPhone = $guestPhone; return $this; }

    public function getIdioma(): MaestroIdioma { return $this->idioma; }
    public function setIdioma(MaestroIdioma $idioma): self { $this->idioma = $idioma; return $this; }

    public function getLastMessageAt(): ?DateTimeInterface { return $this->lastMessageAt; }
    public function setLastMessageAt(?DateTimeInterface $lastMessageAt): self { $this->lastMessageAt = $lastMessageAt; return $this; }

    public function getLastInboundAt(): ?DateTimeInterface { return $this->lastInboundAt; }
    public function setLastInboundAt(?DateTimeInterface $lastInboundAt): self { $this->lastInboundAt = $lastInboundAt; return $this; }

    public function getUnreadCount(): int { return $this->unreadCount; }
    public function setUnreadCount(int $unreadCount): self { $this->unreadCount = $unreadCount; return $this; }
    public function incrementUnreadCount(): self { $this->unreadCount++; return $this; }
    public function resetUnreadCount(): self { $this->unreadCount = 0; return $this; }

    public function getContextData(): ?array { return $this->contextData; }
    public function setContextData(?array $contextData): self { $this->contextData = $contextData; return $this; }

    /**
     * @return Collection<int, Message>
     */
    #[Groups(['conversation:read'])]
    public function getMessages(): Collection
    {
        return $this->messages;
    }

    /**
     * Añade un mensaje y actualiza metadatos (fecha y contadores).
     */
    public function addMessage(Message $message): self
    {
        if (!$this->messages->contains($message)) {
            $this->messages->add($message);
            $message->setConversation($this);

            // 1. Clonamos la fecha de creación del mensaje para el puntero de "último mensaje"
            $fechaMensaje = clone ($message->getCreatedAt() ?? new \DateTime());
            $this->setLastMessageAt($fechaMensaje);

            // 2. Si el mensaje es entrante, actualizamos el puntero de entrada e incrementamos no leídos
            if (method_exists($message, 'getDirection') && $message->getDirection() === 'inbound') {
                $this->setLastInboundAt($fechaMensaje);
                $this->incrementUnreadCount();
            }
        }
        return $this;
    }

    /**
     * Elimina un mensaje y rompe la relación bidireccional de forma segura.
     */
    public function removeMessage(Message $message): self
    {
        if ($this->messages->removeElement($message)) {
            // Seteamos a null la relación en el lado del mensaje si apuntaba a esta conversación
            if ($message->getConversation() === $this) {
                $message->setConversation(null);
            }
        }
        return $this;
    }

    private function initContextData(): void
    {
        if ($this->contextData === null) {
            $this->contextData = [];
        }
    }

    // =========================================================================
    // GETTERS Y SETTERS VIRTUALES (EL MOTOR DE METADATA)
    // =========================================================================

    #[Groups(['conversation:read'])]
    public function getContextOrigin(): ?string { return $this->contextData['origin'] ?? null; }
    public function setContextOrigin(?string $origin): self {
        $this->initContextData();
        $this->contextData['origin'] = $origin;
        return $this;
    }

    #[Groups(['conversation:read'])]
    public function getContextStatusTag(): ?string { return $this->contextData['status_tag'] ?? null; }
    public function setContextStatusTag(?string $statusTag): self {
        $this->initContextData();
        $this->contextData['status_tag'] = $statusTag;
        return $this;
    }

    #[Groups(['conversation:read'])]
    public function getContextMilestones(): array { return $this->contextData['milestones'] ?? []; }

    public function setContextMilestones(array $milestones): self {
        $this->initContextData();
        $this->contextData['milestones'] = [];
        foreach ($milestones as $key => $value) {
            $this->addContextMilestone($key, $value);
        }
        return $this;
    }

    public function addContextMilestone(string $key, \DateTimeInterface|string|null $date): self {
        $this->initContextData();
        if (!isset($this->contextData['milestones'])) $this->contextData['milestones'] = [];
        if ($date === null) return $this;
        $this->contextData['milestones'][$key] = $date instanceof \DateTimeInterface
            ? $date->format('Y-m-d\TH:i:s') : $date;
        return $this;
    }

    #[Groups(['conversation:read'])]
    public function getContextItems(): array { return $this->contextData['items'] ?? []; }
    public function setContextItems(array $items): self {
        $this->initContextData();
        $this->contextData['items'] = array_values($items);
        return $this;
    }

    #[Groups(['conversation:read'])]
    public function getContextFinancialTotal(): ?float {
        return isset($this->contextData['financials']['total']) ? (float) $this->contextData['financials']['total'] : null;
    }

    #[Groups(['conversation:read'])]
    public function getContextFinancialIsCleared(): bool {
        return (bool) ($this->contextData['financials']['is_cleared'] ?? false);
    }

    public function setContextFinancials(?float $total, bool $isCleared = false): self {
        $this->initContextData();
        $this->contextData['financials'] = ['total' => $total, 'is_cleared' => $isCleared];
        return $this;
    }

    public function __toString(): string {
        return sprintf('%s (%s)', $this->guestName ?? 'Sin Nombre', $this->guestPhone ?? 'Sin Teléfono');
    }
}