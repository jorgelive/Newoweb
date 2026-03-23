<?php

declare(strict_types=1);

namespace App\Message\Entity;

use App\Entity\Trait\IdTrait;
use App\Entity\Trait\TimestampTrait;
use App\Exchange\Entity\Beds24Config;
use App\Exchange\Entity\ExchangeEndpoint;
use App\Exchange\Service\Contract\ChannelConfigInterface;
use App\Exchange\Service\Contract\EndpointInterface;
use App\Exchange\Service\Contract\ExchangeQueueItemInterface;
use App\Message\Repository\Beds24ReceiveQueueRepository;
use DateTimeImmutable;
use DateTimeInterface;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Uid\UuidV7;

#[ORM\Entity(repositoryClass: Beds24ReceiveQueueRepository::class)]
#[ORM\Table(name: 'msg_beds24_receive_queue')]
#[ORM\Index(columns: ['status', 'run_at'], name: 'idx_msg_receive_worker')]
#[ORM\HasLifecycleCallbacks]
class Beds24ReceiveQueue implements ExchangeQueueItemInterface
{
    use IdTrait;
    use TimestampTrait;

    public const string STATUS_PENDING    = 'pending';
    public const string STATUS_PROCESSING = 'processing';
    public const string STATUS_SUCCESS    = 'success';
    public const string STATUS_FAILED     = 'failed';

    #[ORM\ManyToOne(targetEntity: Beds24Config::class, inversedBy: 'beds24ReceiveQueues')]
    #[ORM\JoinColumn(name: 'config_id', referencedColumnName: 'id', nullable: false)]
    private ?Beds24Config $config = null;

    #[ORM\ManyToOne(targetEntity: ExchangeEndpoint::class, inversedBy: 'beds24ReceiveQueues')]
    #[ORM\JoinColumn(name: 'endpoint_id', referencedColumnName: 'id', nullable: false)]
    private ?ExchangeEndpoint $endpoint = null;

    // 🔥 El ID de la reserva en Beds24 a consultar
    #[ORM\Column(type: 'string', length: 50)]
    private string $targetBookId;

    #[ORM\Column(name: 'run_at', type: 'datetime')]
    private ?DateTimeInterface $runAt = null;

    #[ORM\Column(type: 'string', length: 20)]
    private string $status = self::STATUS_PENDING;

    #[ORM\Column(name: 'retry_count', type: 'smallint', options: ['default' => 0])]
    private int $retryCount = 0;

    #[ORM\Column(name: 'max_attempts', type: 'smallint', options: ['default' => 3])]
    private int $maxAttempts = 3;

    #[ORM\Column(name: 'locked_at', type: 'datetime', nullable: true)]
    private ?DateTimeInterface $lockedAt = null;

    #[ORM\Column(name: 'locked_by', type: 'string', length: 100, nullable: true)]
    private ?string $lockedBy = null;

    #[ORM\Column(name: 'last_request_raw', type: 'text', nullable: true)]
    private ?string $lastRequestRaw = null;

    #[ORM\Column(name: 'last_response_raw', type: 'text', nullable: true)]
    private ?string $lastResponseRaw = null;

    #[ORM\Column(name: 'execution_result', type: 'json', nullable: true)]
    private ?array $executionResult = null;

    #[ORM\Column(name: 'last_http_code', type: 'smallint', nullable: true)]
    private ?int $lastHttpCode = null;

    #[ORM\Column(name: 'failed_reason', type: 'string', length: 255, nullable: true)]
    private ?string $failedReason = null;

    public function __construct(string $targetBookId)
    {
        $this->id = Uuid::v7();
        $this->targetBookId = $targetBookId;
    }

    #[ORM\PrePersist]
    public function ensureRunAtOnCreate(): void
    {
        if ($this->runAt === null) {
            $this->runAt = new DateTimeImmutable();
        }
    }

    // =========================================================================
    // GETTERS Y SETTERS ESPECÍFICOS
    // =========================================================================

    public function getTargetBookId(): string
    {
        return $this->targetBookId;
    }

    public function setTargetBookId(string $targetBookId): self
    {
        $this->targetBookId = $targetBookId;
        return $this;
    }

    // =========================================================================
    // IMPLEMENTACIÓN ExchangeQueueItemInterface
    // =========================================================================

    public function getId(): UuidV7 { return $this->id; }
    public function getConfig(): ?ChannelConfigInterface { return $this->config; }
    public function setConfig(?ChannelConfigInterface $config): self { $this->config = $config; return $this; }
    public function getEndpoint(): ?EndpointInterface { return $this->endpoint; }
    public function setEndpoint(?EndpointInterface $endpoint): self { $this->endpoint = $endpoint; return $this; }
    public function getRunAt(): ?DateTimeInterface { return $this->runAt; }
    public function setRunAt(?DateTimeInterface $at): self { $this->runAt = $at; return $this; }
    public function getRetryCount(): int { return $this->retryCount; }
    public function setRetryCount(int $count): self { $this->retryCount = $count; return $this; }
    public function getMaxAttempts(): int { return $this->maxAttempts; }
    public function setMaxAttempts(int $limit): self { $this->maxAttempts = $limit; return $this; }

    public function markProcessing(string $workerId, DateTimeImmutable $now): void
    {
        $this->status = self::STATUS_PROCESSING;
        $this->lockedBy = $workerId;
        $this->lockedAt = $now;
    }

    public function markSuccess(DateTimeImmutable $now): void
    {
        $this->status = self::STATUS_SUCCESS;
        $this->lockedBy = null;
        $this->lockedAt = null;
        $this->failedReason = null;
    }

    public function markFailure(string $reason, ?int $httpCode, DateTimeImmutable $nextRetry): void
    {
        $this->status = self::STATUS_FAILED;
        $this->failedReason = mb_substr($reason, 0, 255);
        $this->lastHttpCode = $httpCode;
        $this->runAt = $nextRetry;
        $this->lockedAt = null;
        $this->lockedBy = null;
        $this->retryCount++;
    }

    public function setLastRequestRaw(?string $raw): self { $this->lastRequestRaw = $raw; return $this; }
    public function getLastRequestRaw(): ?string { return $this->lastRequestRaw; }
    public function setLastResponseRaw(?string $raw): self { $this->lastResponseRaw = $raw; return $this; }
    public function getLastResponseRaw(): ?string { return $this->lastResponseRaw; }
    public function setLastHttpCode(?int $code): self { $this->lastHttpCode = $code; return $this; }
    public function getLastHttpCode(): ?int { return $this->lastHttpCode; }
    public function setExecutionResult(?array $result): self { $this->executionResult = $result; return $this; }
    public function getExecutionResult(): ?array { return $this->executionResult; }
    public function setFailedReason(?string $reason): self { $this->failedReason = $reason; return $this; }
    public function getFailedReason(): ?string { return $this->failedReason; }
    public function getStatus(): string { return $this->status; }
    public function setStatus(string $status): self { $this->status = $status; return $this; }
}