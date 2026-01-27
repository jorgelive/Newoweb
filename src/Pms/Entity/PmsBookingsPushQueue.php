<?php

declare(strict_types=1);

namespace App\Pms\Entity;

use App\Entity\Trait\IdTrait;
use App\Entity\Trait\TimestampTrait;
use App\Exchange\Service\Contract\ExchangeQueueItemInterface;
use App\Pms\Repository\PmsBookingsPushQueueRepository;
use DateTimeImmutable;
use DateTimeInterface;
use Doctrine\ORM\Mapping as ORM;

/**
 * Entidad PmsBookingsPushQueue.
 * Gestiona la cola de envío (Push) de actualizaciones hacia Beds24.
 */
#[ORM\Entity(repositoryClass: PmsBookingsPushQueueRepository::class)]
#[ORM\HasLifecycleCallbacks]
#[ORM\Table(name: 'pms_bookings_push_queue')]
class PmsBookingsPushQueue implements ExchangeQueueItemInterface
{
    /**
     * Gestión de Identificador UUID (BINARY 16).
     */
    use IdTrait;

    /**
     * Gestión de auditoría temporal (DateTimeImmutable).
     */
    use TimestampTrait;

    public const STATUS_PENDING    = 'pending';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_SUCCESS    = 'success';
    public const STATUS_FAILED     = 'failed';
    public const STATUS_CANCELLED  = 'canceled';

    // --- RELACIONES ESPECÍFICAS DE PUSH ---

    #[ORM\ManyToOne(targetEntity: PmsEventoBeds24Link::class, inversedBy: 'queues', cascade: ['persist'])]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?PmsEventoBeds24Link $link = null;

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $linkIdOriginal = null;

    #[ORM\Column(type: 'bigint', nullable: true)]
    private ?string $beds24BookIdOriginal = null;

    #[ORM\ManyToOne(targetEntity: PmsBeds24Endpoint::class, inversedBy: 'bookingsPushQueues')]
    #[ORM\JoinColumn(nullable: false)]
    private ?PmsBeds24Endpoint $endpoint = null;

    #[ORM\ManyToOne(targetEntity: Beds24Config::class)]
    #[ORM\JoinColumn(name: 'beds24_config_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ?Beds24Config $beds24Config = null;

    // --- LÓGICA DE CONTROL ---

    #[ORM\Column(type: 'string', length: 120, nullable: true)]
    private ?string $dedupeKey = null;

    #[ORM\Column(type: 'string', length: 64, nullable: true)]
    private ?string $payloadHash = null;

    #[ORM\Column(type: 'datetime', nullable: true)]
    private ?DateTimeInterface $lastSync = null;

    // --- CAMPOS ESTANDARIZADOS PARA EL MOTOR ---

    #[ORM\Column(type: 'string', length: 20, options: ['default' => self::STATUS_PENDING])]
    private string $status = self::STATUS_PENDING;

    #[ORM\Column(name: 'run_at', type: 'datetime', nullable: true)]
    private ?DateTimeInterface $runAt = null;

    #[ORM\Column(name: 'locked_at', type: 'datetime', nullable: true)]
    private ?DateTimeInterface $lockedAt = null;

    #[ORM\Column(name: 'locked_by', type: 'string', length: 64, nullable: true)]
    private ?string $lockedBy = null;

    #[ORM\Column(name: 'retry_count', type: 'smallint', options: ['default' => 0])]
    private int $retryCount = 0;

    #[ORM\Column(name: 'max_attempts', type: 'smallint', options: ['default' => 5])]
    private int $maxAttempts = 5;

    // --- AUDITORÍA ESTÁNDAR ---

    #[ORM\Column(name: 'last_request_raw', type: 'text', nullable: true)]
    private ?string $lastRequestRaw = null;

    #[ORM\Column(name: 'last_response_raw', type: 'text', nullable: true)]
    private ?string $lastResponseRaw = null;

    #[ORM\Column(name: 'last_http_code', type: 'smallint', nullable: true)]
    private ?int $lastHttpCode = null;

    #[ORM\Column(name: 'execution_result', type: 'json', nullable: true)]
    private ?array $executionResult = null;

    #[ORM\Column(name: 'failed_reason', type: 'string', length: 255, nullable: true)]
    private ?string $failedReason = null;

    /*
     * -------------------------------------------------------------------------
     * IMPLEMENTACIÓN ExchangeQueueItemInterface
     * -------------------------------------------------------------------------
     */

    public function getBeds24Config(): ?Beds24Config { return $this->beds24Config; }

    public function getEndpoint(): ?PmsBeds24Endpoint { return $this->endpoint; }

    public function getRunAt(): ?DateTimeInterface { return $this->runAt; }

    public function setRunAt(?DateTimeInterface $at): self { $this->runAt = $at; return $this; }

    public function getRetryCount(): int { return $this->retryCount; }

    public function setRetryCount(int $count): self { $this->retryCount = $count; return $this; }

    public function getMaxAttempts(): int { return $this->maxAttempts; }

    public function setMaxAttempts(int $limit): self { $this->maxAttempts = $limit; return $this; }

    public function markProcessing(string $workerId, DateTimeImmutable $now): void {
        $this->status = self::STATUS_PROCESSING;
        $this->lockedBy = $workerId;
        $this->lockedAt = $now;
    }

    public function markSuccess(DateTimeImmutable $now): void {
        $this->status = self::STATUS_SUCCESS;
        $this->lastSync = $now;
        $this->lockedAt = null;
        $this->lockedBy = null;
        $this->failedReason = null;
        $this->retryCount = 0;
    }

    public function markFailure(string $reason, ?int $httpCode, DateTimeImmutable $nextRetry): void {
        $this->status = self::STATUS_FAILED;
        $this->failedReason = mb_substr($reason, 0, 255);
        $this->lastHttpCode = $httpCode;
        $this->runAt = $nextRetry;
        $this->lockedAt = null;
        $this->lockedBy = null;
    }

    /*
     * -------------------------------------------------------------------------
     * GETTERS Y SETTERS EXPLÍCITOS
     * -------------------------------------------------------------------------
     */

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

    public function getLink(): ?PmsEventoBeds24Link { return $this->link; }

    public function setLink(?PmsEventoBeds24Link $link): self
    {
        $this->link = $link;
        if ($link) {
            if ($link->getId() !== null) {
                $this->linkIdOriginal = (int) $link->getId();
            }
            $bookId = $link->getBeds24BookId();
            if ($bookId !== null && $bookId !== '') {
                $this->beds24BookIdOriginal = (string) $bookId;
            }
        }
        return $this;
    }

    public function getLinkIdOriginal(): ?int { return $this->linkIdOriginal; }
    public function setLinkIdOriginal(?int $id): self { $this->linkIdOriginal = $id; return $this; }

    public function getBeds24BookIdOriginal(): ?string { return $this->beds24BookIdOriginal; }
    public function setBeds24BookIdOriginal(?string $id): self { $this->beds24BookIdOriginal = $id; return $this; }

    public function setEndpoint(?PmsBeds24Endpoint $ep): self { $this->endpoint = $ep; return $this; }

    public function setBeds24Config(?Beds24Config $config): self { $this->beds24Config = $config; return $this; }

    public function getStatus(): string { return $this->status; }
    public function setStatus(string $status): self { $this->status = $status; return $this; }

    public function getDedupeKey(): ?string { return $this->dedupeKey; }
    public function setDedupeKey(?string $key): self { $this->dedupeKey = $key; return $this; }

    public function getPayloadHash(): ?string { return $this->payloadHash; }
    public function setPayloadHash(?string $hash): self { $this->payloadHash = $hash; return $this; }

    public function getLastSync(): ?DateTimeInterface { return $this->lastSync; }
    public function setLastSync(?DateTimeInterface $at): self { $this->lastSync = $at; return $this; }

    public function getLockedAt(): ?DateTimeInterface { return $this->lockedAt; }
    public function setLockedAt(?DateTimeInterface $at): self { $this->lockedAt = $at; return $this; }

    public function getLockedBy(): ?string { return $this->lockedBy; }
    public function setLockedBy(?string $by): self { $this->lockedBy = $by; return $this; }

    /**
     * Representación textual de la tarea de Push.
     */
    public function __toString(): string
    {
        return 'Beds24PushQueue (UUID) ' . ($this->getId() ?? 'NEW') . ' [' . $this->status . ']';
    }
}