<?php

declare(strict_types=1);

namespace App\Message\Entity;

use App\Entity\Trait\IdTrait;
use App\Entity\Trait\TimestampTrait;
use App\Message\Entity\Beds24SendQueue;
use App\Message\Entity\GupshupSendQueue;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use InvalidArgumentException;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'message')]
#[ORM\Index(columns: ['status'], name: 'idx_msg_status')]
#[ORM\Index(columns: ['direction'], name: 'idx_msg_direction')]
#[ORM\HasLifecycleCallbacks]
class Message
{
    use IdTrait;
    use TimestampTrait;

    public const string STATUS_PENDING  = 'pending';
    public const string STATUS_QUEUED   = 'queued';
    public const string STATUS_SENT     = 'sent';
    public const string STATUS_RECEIVED = 'received';
    public const string STATUS_READ     = 'read';
    public const string STATUS_FAILED   = 'failed';

    public const string DIRECTION_INCOMING = 'incoming';
    public const string DIRECTION_OUTGOING = 'outgoing';

    // =========================================================================
    // RELACIONES
    // =========================================================================

    #[ORM\ManyToOne(targetEntity: MessageConversation::class, inversedBy: 'messages')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?MessageConversation $conversation = null;

    #[ORM\ManyToOne(targetEntity: MessageChannel::class)]
    #[ORM\JoinColumn(name: 'channel_id', referencedColumnName: 'id', nullable: false)]
    private ?MessageChannel $channel = null;

    // --- Relaciones Inversas (Necesarias para las Colas) ---

    /**
     * @var Collection<int, GupshupSendQueue>
     */
    #[ORM\OneToMany(mappedBy: 'message', targetEntity: GupshupSendQueue::class, cascade: ['persist', 'remove'])]
    private Collection $gupshupQueues;

    /**
     * @var Collection<int, Beds24SendQueue>
     */
    #[ORM\OneToMany(mappedBy: 'message', targetEntity: Beds24SendQueue::class, cascade: ['persist', 'remove'])]
    private Collection $beds24Queues;

    // =========================================================================
    // PROPIEDADES DE CONTENIDO
    // =========================================================================

    #[ORM\Column(length: 10, options: ['default' => 'es'])]
    private string $languageCode = 'es';

    #[ORM\Column(type: 'text')]
    private ?string $contentOriginal = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $contentTranslated = null;

    #[ORM\Column(length: 20, options: ['default' => self::DIRECTION_OUTGOING])]
    private string $direction = self::DIRECTION_OUTGOING;

    #[ORM\Column(length: 20, options: ['default' => self::STATUS_PENDING])]
    private string $status = self::STATUS_PENDING;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $externalId = null;

    #[ORM\Column(type: 'json')]
    private array $metadata = [];

    public function __construct()
    {
        $this->id = Uuid::v7();
        $this->gupshupQueues = new ArrayCollection();
        $this->beds24Queues = new ArrayCollection();
    }

    public function __toString(): string
    {
        return mb_substr($this->contentOriginal ?? 'Empty Message', 0, 50) . '...';
    }

    // =========================================================================
    // GETTERS Y SETTERS EXPLÍCITOS
    // =========================================================================

    public function getId(): mixed
    {
        return $this->id;
    }

    // --- Conversation ---
    public function getConversation(): ?MessageConversation
    {
        return $this->conversation;
    }

    public function setConversation(?MessageConversation $conversation): self
    {
        $this->conversation = $conversation;
        return $this;
    }

    // --- Channel ---
    public function getChannel(): ?MessageChannel
    {
        return $this->channel;
    }

    public function setChannel(?MessageChannel $channel): self
    {
        $this->channel = $channel;
        return $this;
    }

    // --- Content & Language ---
    public function getLanguageCode(): string
    {
        return $this->languageCode;
    }

    public function setLanguageCode(string $languageCode): self
    {
        $this->languageCode = $languageCode;
        return $this;
    }

    public function getContentOriginal(): ?string
    {
        return $this->contentOriginal;
    }

    public function setContentOriginal(?string $contentOriginal): self
    {
        $this->contentOriginal = $contentOriginal;
        return $this;
    }

    public function getContentTranslated(): ?string
    {
        return $this->contentTranslated;
    }

    public function setContentTranslated(?string $contentTranslated): self
    {
        $this->contentTranslated = $contentTranslated;
        return $this;
    }

    // --- Direction & Status ---
    public function getDirection(): string
    {
        return $this->direction;
    }

    public function setDirection(string $direction): self
    {
        if (!in_array($direction, [self::DIRECTION_INCOMING, self::DIRECTION_OUTGOING])) {
            throw new InvalidArgumentException("Dirección de mensaje inválida: $direction");
        }
        $this->direction = $direction;
        return $this;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): self
    {
        $this->status = $status;
        return $this;
    }

    // --- External ID & Metadata ---
    public function getExternalId(): ?string
    {
        return $this->externalId;
    }

    public function setExternalId(?string $externalId): self
    {
        $this->externalId = $externalId;
        return $this;
    }

    public function getMetadata(): array
    {
        return $this->metadata;
    }

    public function setMetadata(array $metadata): self
    {
        $this->metadata = $metadata;
        return $this;
    }

    public function addMetadata(string $key, mixed $value): self
    {
        $this->metadata[$key] = $value;
        return $this;
    }

    // =========================================================================
    // GESTIÓN DE COLECCIONES (OneToMany)
    // =========================================================================

    /**
     * @return Collection<int, GupshupSendQueue>
     */
    public function getGupshupQueues(): Collection
    {
        return $this->gupshupQueues;
    }

    public function addGupshupQueue(GupshupSendQueue $queue): self
    {
        if (!$this->gupshupQueues->contains($queue)) {
            $this->gupshupQueues->add($queue);
            if ($queue->getMessage() !== $this) {
                $queue->setMessage($this);
            }
        }
        return $this;
    }

    public function removeGupshupQueue(GupshupSendQueue $queue): self
    {
        if ($this->gupshupQueues->removeElement($queue)) {
            if ($queue->getMessage() === $this) {
                $queue->setMessage(null);
            }
        }
        return $this;
    }

    /**
     * @return Collection<int, Beds24SendQueue>
     */
    public function getBeds24Queues(): Collection
    {
        return $this->beds24Queues;
    }

    public function addBeds24Queue(Beds24SendQueue $queue): self
    {
        if (!$this->beds24Queues->contains($queue)) {
            $this->beds24Queues->add($queue);
            if ($queue->getMessage() !== $this) {
                $queue->setMessage($this);
            }
        }
        return $this;
    }

    public function removeBeds24Queue(Beds24SendQueue $queue): self
    {
        if ($this->beds24Queues->removeElement($queue)) {
            if ($queue->getMessage() === $this) {
                $queue->setMessage(null);
            }
        }
        return $this;
    }
}