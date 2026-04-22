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
use App\Security\Roles;
use DateTimeImmutable;
use DateTimeInterface;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use InvalidArgumentException;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Uid\UuidV7;
use App\Message\Contract\MessageQueueItemInterface;

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
            order: ['scheduledAt' => 'DESC', 'createdAt' => 'DESC']
        ),
        new GetCollection(uriTemplate: '/user/util/msg/messages'),

        // Al quitar la seguridad local, hereda el escudo global (MENSAJES_SHOW)
        new Get(uriTemplate: '/user/util/msg/messages/{id}'),

        new Post(
            uriTemplate: '/user/util/msg/messages',
            inputFormats: [
                'jsonld' => ['application/ld+json'],
                'multipart' => ['multipart/form-data']
            ],
            // 🔥 Verificamos ÚNICAMENTE el rol explícito de escritura
            securityPostDenormalize: "is_granted('" . Roles::MENSAJES_WRITE . "')",
            securityPostDenormalizeMessage: 'No tienes permiso para enviar mensajes.',
            processor: MessageMultipartProcessor::class
        )
    ],
    normalizationContext: ['groups' => ['message:read']],
    denormalizationContext: ['groups' => ['message:write']],

    // 🔥 Escudo global: Solo usuarios con permiso de ver mensajes entran aquí
    security: "is_granted('" . Roles::MENSAJES_SHOW . "')",
    securityMessage: 'Acceso denegado al módulo de mensajería.'
)]
class Message
{
    use IdTrait;
    use TimestampTrait;

    public const string STATUS_FAILED    = 'failed';
    public const string STATUS_PENDING   = 'pending';
    public const string STATUS_QUEUED    = 'queued';
    public const string STATUS_SENT      = 'sent';
    public const string STATUS_DELIVERED = 'sent';
    public const string STATUS_RECEIVED  = 'received';
    public const string STATUS_READ      = 'read';
    public const string STATUS_CANCELLED = 'cancelled';

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

    #[ORM\OneToMany(mappedBy: 'message', targetEntity: WhatsappMetaSendQueue::class, cascade: ['persist', 'remove'])]
    #[Groups(['message:read'])]
    private Collection $whatsappMetaSendQueues;

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
    #[Groups(['message:read'])]
    private array $metadata = [];

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
        $this->whatsappMetaSendQueues = new ArrayCollection();
        $this->beds24SendQueues       = new ArrayCollection();
        $this->attachments            = new ArrayCollection();

        // 🔥 INICIALIZACIÓN CRÍTICA PARA EL JSON_MERGE_PATCH
        $this->externalIds = [];
        $this->metadata    = [];
    }

    public function __toString(): string
    {
        return $this->template ? ('Plantilla: ' . $this->template->getName()) : 'Mensaje Libre';
    }

    // =========================================================================
    // GETTERS BÁSICOS
    // =========================================================================

    #[Groups(['message:read'])]
    public function getId(): UuidV7 { return $this->id; }

    #[Groups(['message:read'])]
    public function getCreatedAt(): ?DateTimeInterface { return $this->createdAt ?? null; }

    /**
     * Obtiene la fecha efectiva del mensaje para el ordenamiento en el frontend.
     * Si está programado, devuelve la fecha de programación; si no, la de creación.
     */
    #[Groups(['message:read'])]
    public function getEffectiveDateTime(): ?DateTimeInterface
    {
        return $this->scheduledAt ?? $this->createdAt ?? null;
    }

    /**
     * Determina de forma robusta si este mensaje es una programación futura.
     * Evalúa estrictamente que el estado sea PENDING/QUEUED/FAILED y que la fecha objetivo sea mayor a la actual.
     */
    #[Groups(['message:read'])]
    public function isScheduledForFuture(): bool
    {
        if ($this->scheduledAt === null) {
            return false;
        }

        if (!in_array($this->status, [self::STATUS_PENDING, self::STATUS_QUEUED, self::STATUS_FAILED], true)) {
            return false;
        }

        return $this->scheduledAt > new DateTimeImmutable();
    }

    // =========================================================================
    // RELACIONES
    // =========================================================================

    public function getConversation(): ?MessageConversation { return $this->conversation; }

    public function setConversation(?MessageConversation $conversation): self
    {
        $this->conversation = $conversation;

        if ($conversation !== null && !$conversation->getMessages()->contains($this)) {
            $conversation->addMessage($this);
        }

        return $this;
    }

    public function getChannel(): ?MessageChannel { return $this->channel; }
    public function setChannel(?MessageChannel $channel): self { $this->channel = $channel; return $this; }

    public function getTemplate(): ?MessageTemplate { return $this->template; }
    public function setTemplate(?MessageTemplate $template): self { $this->template = $template; return $this; }

    public function getTransientChannels(): array { return $this->transientChannels; }
    public function setTransientChannels(array $channels): self { $this->transientChannels = $channels; return $this; }

    public function getScheduledAt(): ?DateTimeImmutable { return $this->scheduledAt; }
    public function setScheduledAt(?DateTimeImmutable $scheduledAt): self { $this->scheduledAt = $scheduledAt; return $this; }

    // =========================================================================
    // CAMPOS BÁSICOS
    // =========================================================================

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

    public function getFullContentLocal(): string
    {
        $content = $this->contentLocal ?? $this->contentExternal ?? '';
        $subject = $this->subjectLocal ?? $this->subjectExternal ?? '';
        if (!empty($subject)) return sprintf("*%s*\n\n%s", trim($subject), trim($content));
        return $content;
    }

    public function getFullContentExternal(): string
    {
        $content = $this->contentExternal ?? $this->contentLocal ?? '';
        $subject = $this->subjectExternal ?? $this->subjectLocal ?? '';
        if (!empty($subject)) return sprintf("*%s*\n\n%s", trim($subject), trim($content));
        return $content;
    }

    // =========================================================================
    // METADATA
    // =========================================================================

    public function getMetadata(): array { return array_merge(['beds24' => [], 'whatsappMeta' => []], $this->metadata); }

    public function setMetadata(array $metadata): self
    {
        $this->metadata = $metadata;
        return $this;
    }

    public function addMetadata(string $key, mixed $value): self
    {
        $meta = $this->metadata;
        $meta[$key] = $value;
        $this->metadata = $meta;
        $this->appendDebugTrace('global', "set_$key", $value);
        return $this;
    }

    public function getBeds24Metadata(): array { return $this->metadata['beds24'] ?? []; }

    public function setBeds24Metadata(array $data): self
    {
        $meta = $this->metadata;
        $meta['beds24'] = $data;
        $this->metadata = $meta;
        return $this;
    }

    public function addBeds24Metadata(string $key, mixed $value): self
    {
        $meta = $this->metadata;
        if (!isset($meta['beds24']) || !is_array($meta['beds24'])) {
            $meta['beds24'] = [];
        }
        $meta['beds24'][$key] = $value;

        $this->metadata = $meta;
        $this->appendDebugTrace('beds24', "set_$key", $value);
        return $this;
    }

    public function getBeds24SentAt(): ?string { return $this->metadata['beds24']['sent_at'] ?? null; }
    public function setBeds24SentAt(string $dateTimeIso8601): self { return $this->addBeds24Metadata('sent_at', $dateTimeIso8601); }
    public function getBeds24ReceivedAt(): ?string { return $this->metadata['beds24']['received_at'] ?? null; }
    public function setBeds24ReceivedAt(string $dateTimeIso8601): self { return $this->addBeds24Metadata('received_at', $dateTimeIso8601); }
    public function getBeds24ReadAt(): ?string { return $this->metadata['beds24']['read_at'] ?? null; }
    public function setBeds24ReadAt(string $dateTimeIso8601): self { return $this->addBeds24Metadata('read_at', $dateTimeIso8601); }

    public function getWhatsappMetaMetadata(): array { return $this->metadata['whatsappMeta'] ?? []; }

    public function setWhatsappMetaMetadata(array $data): self
    {
        $meta = $this->metadata;
        $meta['whatsappMeta'] = $data;
        $this->metadata = $meta;
        return $this;
    }

    public function addWhatsappMetaMetadata(string $key, mixed $value): self
    {
        $meta = $this->metadata;
        if (!isset($meta['whatsappMeta']) || !is_array($meta['whatsappMeta'])) {
            $meta['whatsappMeta'] = [];
        }
        $meta['whatsappMeta'][$key] = $value;

        $this->metadata = $meta;
        $this->appendDebugTrace('whatsappMeta', "set_$key", $value);
        return $this;
    }

    public function getWhatsappMetaSentAt(): ?string { return $this->metadata['whatsappMeta']['sent_at'] ?? null; }
    public function setWhatsappMetaSentAt(string $dateTimeIso8601): self { return $this->addWhatsappMetaMetadata('sent_at', $dateTimeIso8601); }
    public function getWhatsappMetaDeliveredAt(): ?string { return $this->metadata['whatsappMeta']['delivered_at'] ?? null; }
    public function setWhatsappMetaDeliveredAt(string $dateTimeIso8601): self { return $this->addWhatsappMetaMetadata('delivered_at', $dateTimeIso8601); }
    public function getWhatsappMetaReadAt(): ?string { return $this->metadata['whatsappMeta']['read_at'] ?? null; }
    public function setWhatsappMetaReadAt(string $dateTimeIso8601): self { return $this->addWhatsappMetaMetadata('read_at', $dateTimeIso8601); }
    public function getWhatsappMetaErrorCode(): ?string { return $this->metadata['whatsappMeta']['error_code'] ?? null; }
    public function setWhatsappMetaErrorCode(string $code): self { return $this->addWhatsappMetaMetadata('error_code', $code); }
    public function getWhatsappMetaErrorReason(): ?string { return $this->metadata['whatsappMeta']['error_reason'] ?? null; }
    public function setWhatsappMetaErrorReason(string $reason): self { return $this->addWhatsappMetaMetadata('error_reason', $reason); }

    // =========================================================================
    // ESTADO Y DIRECCIÓN
    // =========================================================================

    public function getDirection(): string { return $this->direction; }
    public function setDirection(string $direction): self {
        if (!in_array($direction, [self::DIRECTION_INCOMING, self::DIRECTION_OUTGOING])) {
            throw new InvalidArgumentException("Dirección inválida");
        }
        $this->direction = $direction;
        return $this;
    }

    public function getStatus(): string { return $this->status; }
    public function setStatus(string $status): self
    {
        $this->status = $status;
        return $this;
    }

    public function getSenderType(): string { return $this->senderType; }
    public function setSenderType(string $senderType): self { $this->senderType = $senderType; return $this; }

    // =========================================================================
    // IDS EXTERNOS
    // =========================================================================

    public function getExternalIds(): array { return $this->externalIds ?? []; }

    public function setExternalIds(?array $externalIds): self
    {
        $this->externalIds = $externalIds;
        return $this;
    }

    public function getBeds24ExternalId(): ?string { return $this->externalIds['beds24'] ?? null; }

    public function setBeds24ExternalId(?string $id): self
    {
        $ext = $this->externalIds ?? [];
        $ext['beds24'] = $id;
        $this->externalIds = $ext;
        return $this;
    }

    public function getWhatsappMetaExternalId(): ?string { return $this->externalIds['whatsapp_meta'] ?? null; }

    public function setWhatsappMetaExternalId(?string $id): self
    {
        $ext = $this->externalIds ?? [];
        $ext['whatsapp_meta'] = $id;
        $this->externalIds = $ext;
        return $this;
    }

    // =========================================================================
    // COLECCIONES
    // =========================================================================

    public function getWhatsappMetaSendQueues(): Collection { return $this->whatsappMetaSendQueues; }

    public function addWhatsappMetaSendQueue(WhatsappMetaSendQueue $queue): self
    {
        if (!$this->whatsappMetaSendQueues->contains($queue)) {
            $this->whatsappMetaSendQueues->add($queue);
            if ($queue->getMessage() !== $this) $queue->setMessage($this);
        }
        return $this;
    }

    public function getBeds24SendQueues(): Collection { return $this->beds24SendQueues; }
    public function addBeds24SendQueue(Beds24SendQueue $queue): self
    {
        if (!$this->beds24SendQueues->contains($queue)) {
            $this->beds24SendQueues->add($queue);
            if ($queue->getMessage() !== $this) $queue->setMessage($this);
        }
        return $this;
    }

    public function getAttachments(): Collection { return $this->attachments; }

    public function addAttachment(MessageAttachment $attachment): self
    {
        if (!$this->attachments->contains($attachment)) {
            $this->attachments->add($attachment);
            if ($attachment->getMessage() !== $this) $attachment->setMessage($this);
        }
        return $this;
    }

    // =========================================================================
    // AUTO-RESPONDER / INTENT ROUTER HELPERS
    // =========================================================================

    /**
     * Recupera la intención inyectada por los Webhooks para el motor de Inteligencia Artificial
     * o el enrutador determinista.
     *
     * @return array|null
     */
    public function getInboundIntent(): ?array
    {
        return $this->metadata['inbound_intent'] ?? null;
    }

    /**
     * Define la intención de entrada para ser evaluada asíncronamente por el Autorresponder.
     *
     * @param array $intentData
     * @return $this
     */
    public function setInboundIntent(array $intentData): self
    {
        return $this->addMetadata('inbound_intent', $intentData);
    }

    // =========================================================================
    // AUDITORÍA INTERNA
    // =========================================================================

    /**
     * HACK DE AUDITORÍA: Rastrea quién y cuándo modifica el JSON.
     * Esto dejará una huella en el JSON de la base de datos para cazar sobre escrituras.
     */
    private function appendDebugTrace(string $channel, string $action, $value): void
    {
        $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 3);

        $callerClass = $trace[1]['class'] ?? 'Función global/Closure';
        $callerMethod = $trace[1]['function'] ?? 'Unknown';

        $meta = $this->metadata;
        if (!isset($meta['_debug_trace'])) {
            $meta['_debug_trace'] = [];
        }

        $meta['_debug_trace'][] = [
            'timestamp' => new DateTimeImmutable()->format('Y-m-d H:i:s.v'),
            'sapi'      => php_sapi_name(),
            'pid'       => getmypid(),
            'channel'   => $channel,
            'action'    => $action,
            'value'     => $value,
            'caller'    => $callerClass . '::' . $callerMethod,
        ];

        $this->metadata = $meta;
    }

    /**
     * Agrupa todas las colas físicas asociadas a este mensaje en una única colección agnóstica.
     * * ¿Por qué existe?: Evita fugas de abstracción. Permite que servicios de dominio
     * (como MessageRuleEngine o el Dispatcher) puedan iterar y evaluar el estado de todas
     * las colas sin necesidad de acoplarse a las colecciones físicas individuales de cada canal
     * (Beds24, WhatsApp, etc.), respetando el Open/Closed Principle.
     * * Dependencias y Efectos: Este es el único punto de convergencia entre las colecciones
     * físicas de Doctrine y los contratos de dominio. Si en el futuro se añade un nuevo
     * canal de salida (ej. SMS Twilio), su colección Doctrine DEBE sumarse obligatoriamente
     * dentro de este método para que el motor de reglas pueda auditarlo.
     *
     * @return MessageQueueItemInterface[] Arreglo tipado donde cada elemento cumple el contrato de cola.
     */
    public function getAllQueues(): array
    {
        $queues = [];

        if ($this->beds24SendQueues !== null) {
            foreach ($this->beds24SendQueues as $q) {
                $queues[] = $q;
            }
        }

        if ($this->whatsappMetaSendQueues !== null) {
            foreach ($this->whatsappMetaSendQueues as $q) {
                $queues[] = $q;
            }
        }

        return $queues;
    }
}