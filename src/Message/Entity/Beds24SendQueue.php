<?php

declare(strict_types=1);

namespace App\Message\Entity;

use App\Entity\Trait\IdTrait;
use App\Entity\Trait\TimestampTrait;
use App\Exchange\Entity\Beds24Config;
use App\Exchange\Entity\ExchangeEndpoint;
use App\Exchange\Service\Contract\ChannelConfigInterface;
use App\Exchange\Service\Contract\EndpointInterface;
use App\Message\Contract\MessageQueueItemInterface;
use App\Message\Repository\Beds24SendQueueRepository;
use DateTimeImmutable;
use DateTimeInterface;
use Doctrine\ORM\Mapping as ORM;
use InvalidArgumentException;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Uid\UuidV7;

#[ORM\Entity(repositoryClass: Beds24SendQueueRepository::class)]
#[ORM\Table(name: 'msg_beds24_queue')]
#[ORM\Index(columns: ['status', 'run_at'], name: 'idx_msg_b24_worker')]
#[ORM\HasLifecycleCallbacks]
class Beds24SendQueue implements MessageQueueItemInterface
{
    use IdTrait;
    use TimestampTrait;

    public const string STATUS_PENDING = 'pending';
    public const string STATUS_PROCESSING = 'processing';
    public const string STATUS_SUCCESS = 'success';
    public const string STATUS_FAILED = 'failed';
    public const string STATUS_CANCELLED = 'cancelled';

    // =========================================================================
    // RELACIONES
    // =========================================================================

    #[ORM\ManyToOne(targetEntity: Message::class, inversedBy: 'beds24Queues')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Message $message = null;

    #[ORM\ManyToOne(targetEntity: Beds24Config::class)]
    #[ORM\JoinColumn(name: 'config_id', referencedColumnName: 'id', nullable: false)]
    private ?Beds24Config $config = null;

    #[ORM\ManyToOne(targetEntity: ExchangeEndpoint::class)]
    #[ORM\JoinColumn(name: 'endpoint_id', referencedColumnName: 'id', nullable: false)]
    private ?ExchangeEndpoint $endpoint = null;

    // =========================================================================
    // DATOS DEL MENSAJE (SNAPSHOT INICIAL)
    // =========================================================================

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $targetBookId = null;

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
    // INTERFAZ ExchangeQueueItemInterface (Lógica de Worker)
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
    }

    public function markFailure(string $reason, ?int $httpCode, DateTimeImmutable $nextRetry): void
    {
        $this->status = self::STATUS_PENDING;
        // Cortamos el error para que quepa en la columna TEXT (65kb)
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

    public function getMessage(): ?Message
    {
        return $this->message;
    }

    public function setMessage(?Message $message): self
    {
        $this->message = $message;
        return $this;
    }

    public function getTargetBookId(): ?string
    {
        return $this->targetBookId;
    }

    public function setTargetBookId(?string $targetBookId): self
    {
        $this->targetBookId = $targetBookId;
        return $this;
    }

    public function getConfig(): ?ChannelConfigInterface
    {
        return $this->config;
    }

    public function setConfig(?ChannelConfigInterface $config): self
    {
        if ($config !== null && !$config instanceof Beds24Config) {
            throw new InvalidArgumentException(sprintf('Configuración inválida. Se esperaba %s', Beds24Config::class));
        }
        $this->config = $config;
        return $this;
    }

    public function getEndpoint(): ?EndpointInterface
    {
        return $this->endpoint;
    }

    public function setEndpoint(?EndpointInterface $endpoint): self
    {
        if ($endpoint !== null && !$endpoint instanceof ExchangeEndpoint) {
            throw new InvalidArgumentException(sprintf('Endpoint inválido. Se esperaba %s', ExchangeEndpoint::class));
        }
        $this->endpoint = $endpoint;
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

    public function getRunAt(): ?DateTimeImmutable
    {
        return $this->runAt;
    }

    public function setRunAt(?DateTimeInterface $at): self
    {
        $this->runAt = $at instanceof DateTimeInterface ? DateTimeImmutable::createFromInterface($at) : null;
        return $this;
    }

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

    public function getExecutionResult(): ?array
    {
        return $this->executionResult;
    }

    public function setExecutionResult(?array $result): self
    {
        $this->executionResult = $result;
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
}