<?php

declare(strict_types=1);

namespace App\Message\Entity;

use App\Entity\Trait\IdTrait;
use App\Entity\Trait\TimestampTrait;
use App\Exchange\Service\Contract\ChannelConfigInterface;
use App\Exchange\Service\Contract\EndpointInterface;
use App\Exchange\Service\Contract\ExchangeQueueItemInterface;
use App\Message\Repository\GupshupSendQueueRepository;
use DateTimeImmutable;
use DateTimeInterface;
use Doctrine\ORM\Mapping as ORM;
use InvalidArgumentException;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Uid\UuidV7;

#[ORM\Entity(repositoryClass: GupshupSendQueueRepository::class)]
#[ORM\Table(name: 'msg_gupshup_queue')]
#[ORM\Index(columns: ['status', 'run_at'], name: 'idx_msg_gupshup_worker')]
#[ORM\Index(columns: ['external_message_id'], name: 'idx_msg_gupshup_ext_id')]
#[ORM\HasLifecycleCallbacks]
class GupshupSendQueue implements ExchangeQueueItemInterface
{
    use IdTrait;
    use TimestampTrait;

    // Status Worker (Infraestructura)
    public const string STATUS_PENDING    = 'pending';
    public const string STATUS_PROCESSING = 'processing';
    public const string STATUS_SUCCESS    = 'success';
    public const string STATUS_FAILED     = 'failed';
    public const string STATUS_CANCELLED  = 'cancelled';

    // Status Negocio (Gupshup/WhatsApp)
    public const string DELIVERY_UNKNOWN   = 'unknown';
    public const string DELIVERY_SUBMITTED = 'submitted';
    public const string DELIVERY_DELIVERED = 'delivered';
    public const string DELIVERY_READ      = 'read';

    // =========================================================================
    // RELACIONES
    // =========================================================================

    #[ORM\ManyToOne(targetEntity: Message::class, inversedBy: 'gupshupQueues')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Message $message = null;

    #[ORM\ManyToOne(targetEntity: GupshupConfig::class, inversedBy: 'gupshupSendQueues')]
    #[ORM\JoinColumn(name: 'config_id', referencedColumnName: 'id', nullable: false, columnDefinition: 'BINARY(16) COMMENT "(DC2Type:uuid)"')]
    private ?GupshupConfig $config = null;

    #[ORM\ManyToOne(targetEntity: GupshupEndpoint::class, inversedBy: 'gupshupSendQueues')]
    #[ORM\JoinColumn(name: 'endpoint_id', referencedColumnName: 'id', nullable: false)]
    private ?GupshupEndpoint $endpoint = null;

    // =========================================================================
    // SNAPSHOTS & DATOS TÉCNICOS
    // =========================================================================

    #[ORM\Column(length: 50)]
    private ?string $destinationPhone = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $externalMessageId = null;

    #[ORM\Column(length: 20, options: ['default' => self::DELIVERY_UNKNOWN])]
    private string $deliveryStatus = self::DELIVERY_UNKNOWN;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?DateTimeImmutable $deliveredAt = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?DateTimeImmutable $readAt = null;

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $webhookHistory = [];

    // =========================================================================
    // CAMPOS DE WORKER (Infraestructura)
    // =========================================================================

    #[ORM\Column(length: 20, options: ['default' => self::STATUS_PENDING])]
    private string $status = self::STATUS_PENDING;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?DateTimeImmutable $runAt = null;

    #[ORM\Column(type: 'datetime', nullable: true)]
    private ?DateTimeInterface $lockedAt = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $lockedBy = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $failedReason = null;

    #[ORM\Column(type: 'integer', options: ['default' => 0])]
    private int $retryCount = 0;

    #[ORM\Column(type: 'smallint', options: ['default' => 3])]
    private int $maxAttempts = 3;

    // =========================================================================
    // AUDITORÍA RAW
    // =========================================================================

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $lastRequestRaw = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $lastResponseRaw = null;

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $executionResult = null;

    #[ORM\Column(type: 'smallint', nullable: true)]
    private ?int $lastHttpCode = null;

    public function __construct()
    {
        $this->id = Uuid::v7();
        $this->runAt = new DateTimeImmutable();
    }

    // =========================================================================
    // IMPLEMENTACIÓN ExchangeQueueItemInterface (Lógica de Worker)
    // =========================================================================

    public function markProcessing(string $workerId, DateTimeImmutable $now): void
    {
        $this->status = self::STATUS_PROCESSING;
        $this->lockedBy = $workerId;
        $this->lockedAt = $now;
    }

    public function markSuccess(DateTimeImmutable $now): void
    {
        $this->status = self::STATUS_SUCCESS;
        $this->runAt = null;
        $this->lockedAt = null;
        $this->lockedBy = null;
        $this->failedReason = null;

        // Lógica de negocio específica de Gupshup:
        // Si no sabemos nada del delivery, asumimos que al menos fue "enviado" a la API
        if ($this->deliveryStatus === self::DELIVERY_UNKNOWN) {
            $this->deliveryStatus = self::DELIVERY_SUBMITTED;
        }
    }

    public function markFailure(string $reason, ?int $httpCode, DateTimeImmutable $nextRetry): void
    {
        $this->status = self::STATUS_PENDING;
        // Usamos mb_substr para seguridad con caracteres UTF-8
        $this->failedReason = mb_substr($reason, 0, 65000);
        $this->lastHttpCode = $httpCode;
        $this->runAt = $nextRetry;
        $this->lockedAt = null;
        $this->lockedBy = null;
        $this->retryCount++;
    }

    // =========================================================================
    // GETTERS Y SETTERS TRADICIONALES
    // =========================================================================

    public function getId(): UuidV7
    {
        return $this->id;
    }

    // --- Message ---
    public function getMessage(): ?Message
    {
        return $this->message;
    }

    public function setMessage(?Message $message): self
    {
        $this->message = $message;
        return $this;
    }

    // --- Config (Polimórfico Seguro) ---
    public function getConfig(): ?ChannelConfigInterface
    {
        return $this->config;
    }

    public function setConfig(?ChannelConfigInterface $config): self
    {
        if ($config !== null && !$config instanceof GupshupConfig) {
            throw new InvalidArgumentException(sprintf(
                'Configuración inválida. Se esperaba %s, se recibió %s',
                GupshupConfig::class,
                get_class($config)
            ));
        }
        $this->config = $config;
        return $this;
    }

    // --- Endpoint (Polimórfico Seguro) ---
    public function getEndpoint(): ?EndpointInterface
    {
        return $this->endpoint;
    }

    public function setEndpoint(?EndpointInterface $endpoint): self
    {
        if ($endpoint !== null && !$endpoint instanceof GupshupEndpoint) {
            throw new InvalidArgumentException(sprintf(
                'Endpoint inválido. Se esperaba %s, se recibió %s',
                GupshupEndpoint::class,
                get_class($endpoint)
            ));
        }
        $this->endpoint = $endpoint;
        return $this;
    }

    // --- Snapshot: Destination Phone ---
    public function getDestinationPhone(): ?string
    {
        return $this->destinationPhone;
    }

    public function setDestinationPhone(string $destinationPhone): self
    {
        $this->destinationPhone = $destinationPhone;
        return $this;
    }

    // --- External ID ---
    public function getExternalMessageId(): ?string
    {
        return $this->externalMessageId;
    }

    public function setExternalMessageId(?string $externalMessageId): self
    {
        $this->externalMessageId = $externalMessageId;
        return $this;
    }

    // --- Delivery Status ---
    public function getDeliveryStatus(): string
    {
        return $this->deliveryStatus;
    }

    public function setDeliveryStatus(string $deliveryStatus): self
    {
        $this->deliveryStatus = $deliveryStatus;
        return $this;
    }

    public function getDeliveredAt(): ?DateTimeImmutable
    {
        return $this->deliveredAt;
    }

    public function setDeliveredAt(?DateTimeImmutable $deliveredAt): self
    {
        $this->deliveredAt = $deliveredAt;
        return $this;
    }

    public function getReadAt(): ?DateTimeImmutable
    {
        return $this->readAt;
    }

    public function setReadAt(?DateTimeImmutable $readAt): self
    {
        $this->readAt = $readAt;
        return $this;
    }

    public function getWebhookHistory(): array
    {
        return $this->webhookHistory ?? [];
    }

    public function setWebhookHistory(?array $webhookHistory): self
    {
        $this->webhookHistory = $webhookHistory;
        return $this;
    }

    public function addWebhookEntry(array $entry): self
    {
        if ($this->webhookHistory === null) {
            $this->webhookHistory = [];
        }
        $this->webhookHistory[] = $entry;
        return $this;
    }

    // --- Worker Status ---
    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): self
    {
        $this->status = $status;
        return $this;
    }

    // --- RunAt ---
    public function getRunAt(): ?DateTimeImmutable
    {
        return $this->runAt;
    }

    public function setRunAt(?DateTimeInterface $at): self
    {
        if ($at instanceof DateTimeInterface) {
            $this->runAt = DateTimeImmutable::createFromInterface($at);
        } else {
            $this->runAt = null;
        }
        return $this;
    }

    // --- Locking ---
    public function getLockedAt(): ?DateTimeInterface
    {
        return $this->lockedAt;
    }

    public function setLockedAt(?DateTimeInterface $lockedAt): self
    {
        $this->lockedAt = $lockedAt;
        return $this;
    }

    public function getLockedBy(): ?string
    {
        return $this->lockedBy;
    }

    public function setLockedBy(?string $lockedBy): self
    {
        $this->lockedBy = $lockedBy;
        return $this;
    }

    // --- Failures & Retries ---
    public function getFailedReason(): ?string
    {
        return $this->failedReason;
    }

    public function setFailedReason(?string $reason): self
    {
        $this->failedReason = $reason;
        return $this;
    }

    public function getRetryCount(): int
    {
        return $this->retryCount;
    }

    public function setRetryCount(int $count): self
    {
        $this->retryCount = $count;
        return $this;
    }

    public function getMaxAttempts(): int
    {
        return $this->maxAttempts;
    }

    public function setMaxAttempts(int $maxAttempts): self
    {
        $this->maxAttempts = $maxAttempts;
        return $this;
    }

    // --- Auditoría RAW ---
    public function getLastRequestRaw(): ?string
    {
        return $this->lastRequestRaw;
    }

    public function setLastRequestRaw(?string $raw): self
    {
        $this->lastRequestRaw = $raw;
        return $this;
    }

    public function getLastResponseRaw(): ?string
    {
        return $this->lastResponseRaw;
    }

    public function setLastResponseRaw(?string $raw): self
    {
        $this->lastResponseRaw = $raw;
        return $this;
    }

    public function getLastHttpCode(): ?int
    {
        return $this->lastHttpCode;
    }

    public function setLastHttpCode(?int $code): self
    {
        $this->lastHttpCode = $code;
        return $this;
    }

    public function getExecutionResult(): ?array
    {
        return $this->executionResult;
    }

    public function setExecutionResult(?array $result): self
    {
        $this->executionResult = $result;
        return $this;
    }
}