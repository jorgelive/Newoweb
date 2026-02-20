<?php

declare(strict_types=1);

namespace App\Message\Entity;

use App\Entity\Trait\IdTrait;
use App\Entity\Trait\TimestampTrait;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use InvalidArgumentException;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Uid\UuidV7;

#[ORM\Entity]
#[ORM\Table(name: 'msg_message')]
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
    public const string STATUS_FAILED   = 'failed';
    public const string STATUS_RECEIVED = 'received';
    public const string STATUS_READ     = 'read';

    public const string DIRECTION_INCOMING = 'incoming';
    public const string DIRECTION_OUTGOING = 'outgoing';

    // =========================================================================
    // RELACIONES PRINCIPALES
    // =========================================================================

    #[ORM\ManyToOne(targetEntity: MessageConversation::class, inversedBy: 'messages')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?MessageConversation $conversation = null;

    #[ORM\ManyToOne(targetEntity: MessageChannel::class)]
    #[ORM\JoinColumn(name: 'channel_id', referencedColumnName: 'id', nullable: false)]
    private ?MessageChannel $channel = null;

    #[ORM\ManyToOne(targetEntity: MessageTemplate::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?MessageTemplate $template = null;

    // =========================================================================
    // RELACIONES INVERSAS (COLAS Y ADJUNTOS)
    // =========================================================================

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

    /**
     * @var Collection<int, MessageAttachment>
     */
    #[ORM\OneToMany(mappedBy: 'message', targetEntity: MessageAttachment::class, cascade: ['persist', 'remove'])]
    private Collection $attachments;

    // =========================================================================
    // CONTENIDO Y SNAPSHOT
    // =========================================================================

    #[ORM\Column(length: 10, options: ['default' => 'es'])]
    private string $languageCode = 'es';

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $contentOriginal = null;

    /**
     * Texto traducido al idioma base del CRM (ej: Español) para la vista del panel.
     */
    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $contentTranslated = null;

    /**
     * El estado exacto de las variables de negocio en el milisegundo en que se encoló.
     * (El Bolsillo para Humanos)
     */
    #[ORM\Column(type: 'json')]
    private array $templateContext = [];

    /**
     * Coordenadas de enrutamiento para los Workers (Ej: beds24_book_id).
     * (El Bolsillo para Máquinas)
     */
    #[ORM\Column(type: 'json')]
    private array $metadata = [];

    #[ORM\Column(length: 20, options: ['default' => self::DIRECTION_OUTGOING])]
    private string $direction = self::DIRECTION_OUTGOING;

    #[ORM\Column(length: 20, options: ['default' => self::STATUS_PENDING])]
    private string $status = self::STATUS_PENDING;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $externalId = null;

    public function __construct()
    {
        $this->id = Uuid::v7();
        $this->gupshupQueues = new ArrayCollection();
        $this->beds24Queues  = new ArrayCollection();
        $this->attachments   = new ArrayCollection();
    }

    public function __toString(): string
    {
        return $this->template ? ('Plantilla: ' . $this->template->getName()) : 'Mensaje Libre';
    }

    // =========================================================================
    // GETTERS Y SETTERS BÁSICOS
    // =========================================================================

    public function getId(): UuidV7 { return $this->id; }

    public function getConversation(): ?MessageConversation { return $this->conversation; }
    public function setConversation(?MessageConversation $conversation): self { $this->conversation = $conversation; return $this; }

    public function getChannel(): ?MessageChannel { return $this->channel; }
    public function setChannel(?MessageChannel $channel): self { $this->channel = $channel; return $this; }

    public function getTemplate(): ?MessageTemplate { return $this->template; }
    public function setTemplate(?MessageTemplate $template): self { $this->template = $template; return $this; }

    public function getLanguageCode(): string { return $this->languageCode; }
    public function setLanguageCode(string $languageCode): self { $this->languageCode = $languageCode; return $this; }

    public function getContentOriginal(): ?string { return $this->contentOriginal; }
    public function setContentOriginal(?string $contentOriginal): self { $this->contentOriginal = $contentOriginal; return $this; }

    public function getContentTranslated(): ?string { return $this->contentTranslated; }
    public function setContentTranslated(?string $contentTranslated): self { $this->contentTranslated = $contentTranslated; return $this; }

    public function getTemplateContext(): array { return $this->templateContext; }
    public function setTemplateContext(array $templateContext): self { $this->templateContext = $templateContext; return $this; }

    public function getMetadata(): array { return $this->metadata; }
    public function setMetadata(array $metadata): self { $this->metadata = $metadata; return $this; }
    public function addMetadata(string $key, mixed $value): self { $this->metadata[$key] = $value; return $this; }

    public function getDirection(): string { return $this->direction; }
    public function setDirection(string $direction): self {
        if (!in_array($direction, [self::DIRECTION_INCOMING, self::DIRECTION_OUTGOING])) {
            throw new InvalidArgumentException("Dirección inválida");
        }
        $this->direction = $direction;
        return $this;
    }

    public function getStatus(): string { return $this->status; }
    public function setStatus(string $status): self { $this->status = $status; return $this; }

    public function getExternalId(): ?string { return $this->externalId; }
    public function setExternalId(?string $externalId): self { $this->externalId = $externalId; return $this; }

    // =========================================================================
    // MÉTODOS DE COLECCIONES (Gupshup)
    // =========================================================================

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

    // =========================================================================
    // MÉTODOS DE COLECCIONES (Beds24)
    // =========================================================================

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

    // =========================================================================
    // MÉTODOS DE COLECCIONES (Adjuntos)
    // =========================================================================

    public function getAttachments(): Collection
    {
        return $this->attachments;
    }

    public function addAttachment(MessageAttachment $attachment): self
    {
        if (!$this->attachments->contains($attachment)) {
            $this->attachments->add($attachment);
            if ($attachment->getMessage() !== $this) {
                $attachment->setMessage($this);
            }
        }
        return $this;
    }

    public function removeAttachment(MessageAttachment $attachment): self
    {
        if ($this->attachments->removeElement($attachment)) {
            if ($attachment->getMessage() === $this) {
                $attachment->setMessage(null);
            }
        }
        return $this;
    }
}