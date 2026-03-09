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
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Annotation\Groups;
use Symfony\Component\Uid\Uuid;

/**
 * Entidad que representa una conversación de mensajería.
 * Expuesta a través de API Platform para el consumo del frontend (ChatView).
 */
#[ORM\Entity]
#[ORM\Table(name: 'msg_conversation')]
#[ApiResource(
    shortName: 'Conversation',
    operations: [
        new GetCollection(uriTemplate: '/user/util/msg/conversations'),
        new Get(uriTemplate: '/user/util/msg/conversations/{id}')
    ],
    normalizationContext: ['groups' => ['conversation:read']],
    order: ['createdAt' => 'DESC']
)]
#[ApiFilter(OrderFilter::class, properties: ['createdAt' => 'DESC'])]
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
    private MaestroIdioma $idioma;

    #[ORM\OneToMany(mappedBy: 'conversation', targetEntity: Message::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['createdAt' => 'ASC'])]
    private Collection $messages;

    public function __construct(string $contextType, string $contextId)
    {
        $this->contextType = $contextType;
        $this->contextId = $contextId;
        $this->messages = new ArrayCollection();

        $this->id = Uuid::v7();
    }

    // =========================================================================
    // GETTERS EXPLÍCITOS PARA API PLATFORM (Basados en Traits)
    // =========================================================================

    /**
     * Devuelve el ID único de la conversación.
     * Expuesto explícitamente para garantizar su serialización en API Platform.
     * * @return Uuid|null
     */
    #[Groups(['conversation:read'])]
    public function getId(): ?Uuid
    {
        return $this->id;
    }

    /**
     * Devuelve la fecha de creación de la conversación.
     * Expuesto explícitamente para garantizar su serialización en API Platform.
     * * @return \DateTimeInterface|null
     */
    #[Groups(['conversation:read'])]
    public function getCreatedAt(): ?\DateTimeInterface
    {
        return $this->createdAt ?? null;
    }

    // =========================================================================
    // GETTERS Y SETTERS BÁSICOS
    // =========================================================================

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

    public function getMessages(): Collection { return $this->messages; }

    /**
     * Añade un mensaje a la colección de la conversación.
     * * @param Message $message
     * @return self
     */
    public function addMessage(Message $message): self {
        if (!$this->messages->contains($message)) {
            $this->messages->add($message);
            $message->setConversation($this);
        }
        return $this;
    }

    /**
     * Elimina un mensaje de la colección de la conversación.
     * * @param Message $message
     * @return self
     */
    public function removeMessage(Message $message): self {
        if ($this->messages->removeElement($message)) {
            if ($message->getConversation() === $this) {
                $message->setConversation(null);
            }
        }
        return $this;
    }

    public function __toString(): string
    {
        return sprintf('%s (%s)', $this->guestName ?? 'Sin Nombre', $this->guestPhone ?? 'Sin Teléfono');
    }
}