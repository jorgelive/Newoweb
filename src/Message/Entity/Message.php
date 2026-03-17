<?php

declare(strict_types=1);

namespace App\Message\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Link;
use ApiPlatform\Metadata\Post;
use App\Entity\Trait\IdTrait;
use App\Entity\Trait\TimestampTrait;
use App\Message\ApiPlatform\State\MessageMultipartProcessor;
use App\Message\Validator\ValidTemplateScope;
use DateTimeImmutable;
use DateTimeInterface;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use InvalidArgumentException;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Uid\UuidV7;

/**
 * Entidad que representa un mensaje individual dentro de una conversación.
 * Expuesta a través de API Platform permitiendo lectura y escritura.
 */
#[ORM\Entity]
#[ORM\Table(name: 'msg_message')]
#[ORM\Index(columns: ['status'], name: 'idx_msg_status')]
#[ORM\Index(columns: ['direction'], name: 'idx_msg_direction')]
#[ORM\HasLifecycleCallbacks]
#[ValidTemplateScope]
#[ApiResource(
    operations: [
        new GetCollection(
            uriTemplate: '/user/util/msg/conversations/{id}/messages',
            uriVariables: [
                'id' => new Link(
                    toProperty: 'conversation',
                    fromClass: MessageConversation::class
                )
            ],
            order: ['createdAt' => 'DESC']
        ),
        new GetCollection(uriTemplate: '/user/util/msg/messages'),
        new Get(uriTemplate: '/user/util/msg/messages/{id}'),

        new Post(
            uriTemplate: '/user/util/msg/messages',
            inputFormats: [
                'jsonld' => ['application/ld+json'],
                'multipart' => ['multipart/form-data']
            ],
            processor: MessageMultipartProcessor::class
        )
    ],
    normalizationContext: ['groups' => ['message:read']],
    denormalizationContext: ['groups' => ['message:write']]
)]
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

    public const string SENDER_HOST     = 'host';
    public const string SENDER_GUEST    = 'guest';
    public const string SENDER_SYSTEM   = 'system';
    public const string SENDER_INTERNAL = 'internalNote';

    #[ORM\ManyToOne(targetEntity: MessageConversation::class, inversedBy: 'messages')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    #[Groups(['message:read', 'message:write'])]
    private ?MessageConversation $conversation = null;

    #[ORM\ManyToOne(targetEntity: MessageChannel::class)]
    #[ORM\JoinColumn(name: 'channel_id', referencedColumnName: 'id', nullable: true)]
    #[Groups(['message:read'])]
    private ?MessageChannel $channel = null;

    #[ORM\ManyToOne(targetEntity: MessageTemplate::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    #[Groups(['message:read', 'message:write'])]
    private ?MessageTemplate $template = null;

    #[ORM\OneToMany(mappedBy: 'message', targetEntity: WhatsappGupshupSendQueue::class, cascade: ['persist', 'remove'])]
    #[Groups(['message:read'])]
    private Collection $whatsappGupshupSendQueues;

    #[ORM\OneToMany(mappedBy: 'message', targetEntity: Beds24SendQueue::class, cascade: ['persist', 'remove'])]
    #[Groups(['message:read'])]
    private Collection $beds24SendQueues;

    #[ORM\OneToMany(mappedBy: 'message', targetEntity: MessageAttachment::class, cascade: ['persist', 'remove'])]
    #[Groups(['message:read'])]
    private Collection $attachments;

    #[ORM\Column(length: 10, options: ['default' => 'es'])]
    private string $languageCode = 'es';

    #[ORM\Column(type: 'text', nullable: true)]
    #[Groups(['message:read', 'message:write'])]
    private ?string $contentLocal = null;

    #[ORM\Column(type: 'text', nullable: true)]
    #[Groups(['message:read'])]
    private ?string $contentExternal = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $subjectLocal = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $subjectExternal = null;

    #[ORM\Column(type: 'json')]
    private array $templateContext = [];

    #[ORM\Column(type: 'json')]
    #[Groups(['message:read'])]
    private array $metadata = ['beds24' => [], 'whatsappGupshup' => []];

    #[ORM\Column(length: 20, options: ['default' => self::DIRECTION_OUTGOING])]
    #[Groups(['message:read', 'message:write'])]
    private string $direction = self::DIRECTION_OUTGOING;

    #[ORM\Column(length: 20, options: ['default' => self::STATUS_PENDING])]
    #[Groups(['message:read', 'message:write'])]
    private string $status = self::STATUS_PENDING;

    #[ORM\Column(length: 30, options: ['default' => self::SENDER_HOST])]
    #[Groups(['message:read', 'message:write'])]
    private string $senderType = self::SENDER_HOST;

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $externalIds = [];

    #[Groups(['message:write'])]
    private array $transientChannels = [];

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    #[Groups(['message:read', 'message:write'])]
    private ?DateTimeImmutable $scheduledAt = null;

    public function __construct()
    {
        $this->id = Uuid::v7();
        $this->whatsappGupshupSendQueues = new ArrayCollection();
        $this->beds24SendQueues  = new ArrayCollection();
        $this->attachments   = new ArrayCollection();
        $this->externalIds   = [];
    }

    public function __toString(): string
    {
        return $this->template ? ('Plantilla: ' . $this->template->getName()) : 'Mensaje Libre';
    }

    #[Groups(['message:read'])]
    public function getId(): UuidV7 { return $this->id; }

    #[Groups(['message:read'])]
    public function getCreatedAt(): ?DateTimeInterface { return $this->createdAt ?? null; }

    /**
     * Obtiene la fecha efectiva del mensaje para el ordenamiento en el frontend.
     * Si está programado, devuelve la fecha de programación; si no, la de creación.
     *
     * @return DateTimeInterface|null La fecha más relevante del mensaje.
     */
    #[Groups(['message:read'])]
    public function getEffectiveDateTime(): ?DateTimeInterface
    {
        return $this->scheduledAt ?? $this->createdAt ?? null;
    }

    /**
     * Determina de forma robusta si este mensaje es una programación futura.
     * Evalúa estrictamente que el estado sea PENDING y que la fecha objetivo sea mayor a la actual.
     *
     * @return bool True si el mensaje debe considerarse en el pool de "Programados".
     */
    #[Groups(['message:read'])]
    public function getIsScheduledForFuture(): bool
    {
        // 1. Si no tiene fecha programada, es un mensaje inmediato
        if ($this->scheduledAt === null) {
            return false;
        }

        // 2. 🔥 ACEPTAMOS AMBOS ESTADOS: 'pending' (por si acaso) y 'queued' (el real)
        if (!in_array($this->status, [self::STATUS_PENDING, self::STATUS_QUEUED], true)) {
            return false;
        }

        // 3. Verificamos que la fecha objetivo aún no haya sido superada
        $now = new DateTimeImmutable();
        return $this->scheduledAt > $now;
    }

    public function getConversation(): ?MessageConversation { return $this->conversation; }
    public function setConversation(?MessageConversation $conversation): self { $this->conversation = $conversation; return $this; }

    public function getChannel(): ?MessageChannel { return $this->channel; }
    public function setChannel(?MessageChannel $channel): self { $this->channel = $channel; return $this; }

    public function getTemplate(): ?MessageTemplate { return $this->template; }
    public function setTemplate(?MessageTemplate $template): self { $this->template = $template; return $this; }

    public function getTransientChannels(): array { return $this->transientChannels; }
    public function setTransientChannels(array $channels): self { $this->transientChannels = $channels; return $this; }

    public function getScheduledAt(): ?DateTimeImmutable { return $this->scheduledAt; }
    public function setScheduledAt(?DateTimeImmutable $scheduledAt): self { $this->scheduledAt = $scheduledAt; return $this; }

    public function getLanguageCode(): string { return $this->languageCode; }
    public function setLanguageCode(string $languageCode): self { $this->languageCode = $languageCode; return $this; }

    public function getContentLocal(): ?string { return $this->contentLocal; }
    public function setContentLocal(?string $contentLocal): self { $this->contentLocal = $contentLocal; return $this; }

    public function getContentExternal(): ?string { return $this->contentExternal; }
    public function setContentExternal(?string $contentExternal): self { $this->contentExternal = $contentExternal; return $this; }

    public function getSubjectLocal(): ?string { return $this->subjectLocal; }
    public function setSubjectLocal(?string $subjectLocal): self { $this->subjectLocal = $subjectLocal; return $this; }

    public function getSubjectExternal(): ?string { return $this->subjectExternal; }
    public function setSubjectExternal(?string $subjectExternal): self { $this->subjectExternal = $subjectExternal; return $this; }

    public function getFullContentLocal(): string {
        $content = $this->contentLocal ?? $this->contentExternal ?? '';
        $subject = $this->subjectLocal ?? $this->subjectExternal ?? '';
        if (!empty($subject)) return sprintf("*%s*\n\n%s", trim($subject), trim($content));
        return $content;
    }

    public function getFullContentExternal(): string {
        $content = $this->contentExternal ?? $this->contentLocal ?? '';
        $subject = $this->subjectExternal ?? $this->subjectLocal ?? '';
        if (!empty($subject)) return sprintf("*%s*\n\n%s", trim($subject), trim($content));
        return $content;
    }

    public function getTemplateContext(): array { return $this->templateContext; }
    public function setTemplateContext(array $templateContext): self { $this->templateContext = $templateContext; return $this; }

    public function getMetadata(): array { return array_merge(['beds24' => [], 'whatsappGupshup' => []], $this->metadata); }
    public function setMetadata(array $metadata): self { $this->metadata = $metadata; return $this; }
    public function addMetadata(string $key, mixed $value): self { $this->metadata[$key] = $value; return $this; }

    public function getBeds24Metadata(): array { return $this->metadata['beds24'] ?? []; }
    public function setBeds24Metadata(array $data): self { $this->metadata['beds24'] = $data; return $this; }
    public function addBeds24Metadata(string $key, mixed $value): self {
        if (!isset($this->metadata['beds24']) || !is_array($this->metadata['beds24'])) $this->metadata['beds24'] = [];
        $this->metadata['beds24'][$key] = $value;
        return $this;
    }
    public function getBeds24ReceivedAt(): ?string { return $this->metadata['beds24']['received_at'] ?? null; }
    public function setBeds24ReceivedAt(string $dateTimeIso8601): self { return $this->addBeds24Metadata('received_at', $dateTimeIso8601); }
    public function getBeds24ReadAt(): ?string { return $this->metadata['beds24']['read_at'] ?? null; }
    public function setBeds24ReadAt(string $dateTimeIso8601): self { return $this->addBeds24Metadata('read_at', $dateTimeIso8601); }

    public function getGupshupMetadata(): array { return $this->metadata['whatsappGupshup'] ?? []; }
    public function setGupshupMetadata(array $data): self { $this->metadata['whatsappGupshup'] = $data; return $this; }
    public function addGupshupMetadata(string $key, mixed $value): self {
        if (!isset($this->metadata['whatsappGupshup']) || !is_array($this->metadata['whatsappGupshup'])) $this->metadata['whatsappGupshup'] = [];
        $this->metadata['whatsappGupshup'][$key] = $value;
        return $this;
    }
    public function getGupshupSentAt(): ?string { return $this->metadata['whatsappGupshup']['sent_at'] ?? null; }
    public function setGupshupSentAt(string $dateTimeIso8601): self { return $this->addGupshupMetadata('sent_at', $dateTimeIso8601); }
    public function getGupshupDeliveredAt(): ?string { return $this->metadata['whatsappGupshup']['delivered_at'] ?? null; }
    public function setGupshupDeliveredAt(string $dateTimeIso8601): self { return $this->addGupshupMetadata('delivered_at', $dateTimeIso8601); }
    public function getGupshupReadAt(): ?string { return $this->metadata['whatsappGupshup']['read_at'] ?? null; }
    public function setGupshupReadAt(string $dateTimeIso8601): self { return $this->addGupshupMetadata('read_at', $dateTimeIso8601); }
    public function getGupshupErrorCode(): ?string { return $this->metadata['whatsappGupshup']['error_code'] ?? null; }
    public function setGupshupErrorCode(string $code): self { return $this->addGupshupMetadata('error_code', $code); }
    public function getGupshupErrorReason(): ?string { return $this->metadata['whatsappGupshup']['error_reason'] ?? null; }
    public function setGupshupErrorReason(string $reason): self { return $this->addGupshupMetadata('error_reason', $reason); }

    public function getDirection(): string { return $this->direction; }
    public function setDirection(string $direction): self {
        if (!in_array($direction, [self::DIRECTION_INCOMING, self::DIRECTION_OUTGOING])) throw new InvalidArgumentException("Dirección inválida");
        $this->direction = $direction; return $this;
    }

    public function getStatus(): string { return $this->status; }
    public function setStatus(string $status): self { $this->status = $status; return $this; }

    public function getSenderType(): string { return $this->senderType; }
    public function setSenderType(string $senderType): self { $this->senderType = $senderType; return $this; }

    public function getExternalIds(): array { return $this->externalIds ?? []; }
    public function setExternalIds(?array $externalIds): self { $this->externalIds = $externalIds; return $this; }
    public function getBeds24ExternalId(): ?string { return $this->externalIds['beds24'] ?? null; }
    public function setBeds24ExternalId(?string $id): self { $this->externalIds['beds24'] = $id; return $this; }
    public function getGupshupExternalId(): ?string { return $this->externalIds['gupshup'] ?? null; }
    public function setGupshupExternalId(?string $id): self { $this->externalIds['gupshup'] = $id; return $this; }

    public function getWhatsappGupshupSendQueues(): Collection { return $this->whatsappGupshupSendQueues; }

    public function addWhatsappGupshupSendQueue(WhatsappGupshupSendQueue $queue): self {
        if (!$this->whatsappGupshupSendQueues->contains($queue)) {
            $this->whatsappGupshupSendQueues->add($queue);
            if ($queue->getMessage() !== $this) $queue->setMessage($this);
        }
        return $this;
    }

    public function getBeds24SendQueues(): Collection { return $this->beds24SendQueues; }
    public function addBeds24SendQueue(Beds24SendQueue $queue): self {
        if (!$this->beds24SendQueues->contains($queue)) {
            $this->beds24SendQueues->add($queue);
            if ($queue->getMessage() !== $this) $queue->setMessage($this);
        }
        return $this;
    }

    public function getAttachments(): Collection { return $this->attachments; }

    public function addAttachment(MessageAttachment $attachment): self {
        if (!$this->attachments->contains($attachment)) {
            $this->attachments->add($attachment);
            if ($attachment->getMessage() !== $this) $attachment->setMessage($this);
        }
        return $this;
    }
}