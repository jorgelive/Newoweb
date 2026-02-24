<?php

declare(strict_types=1);

namespace App\Pms\Entity;

use App\Entity\Trait\IdTrait;
use App\Entity\Trait\TimestampTrait;
use App\Exchange\Service\Contract\ChannelConfigInterface;
use App\Exchange\Service\Contract\EndpointInterface;
use App\Exchange\Service\Contract\ExchangeQueueItemInterface;
use App\Pms\Repository\PmsBookingsPushQueueRepository;
use DateTimeImmutable;
use DateTimeInterface;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * Entidad PmsBookingsPushQueue.
 * Cola PUSH: Subida de reservas a Beds24.
 * Incluye auditoría completa (Request/Response RAW) para depuración forense.
 */
#[ORM\Entity(repositoryClass: PmsBookingsPushQueueRepository::class)]
#[ORM\Table(name: 'pms_bookings_push_queue')]
#[ORM\Index(columns: ['status', 'run_at'], name: 'idx_pms_b24_queue_worker')]
#[ORM\Index(columns: ['dedupe_key'], name: 'idx_pms_b24_queue_dedupe')]
#[ORM\HasLifecycleCallbacks]
class PmsBookingsPushQueue implements ExchangeQueueItemInterface
{
    use IdTrait;
    use TimestampTrait;

    public const STATUS_PENDING   = 'pending';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_SUCCESS   = 'success';
    public const STATUS_FAILED    = 'failed';
    public const STATUS_CANCELLED = 'cancelled';

    // --- RELACIONES ---

    // ✅ CORRECCIÓN: Agregado cascade: ['persist'] para soportar Links nuevos en batch
    #[ORM\ManyToOne(targetEntity: PmsEventoBeds24Link::class, inversedBy: 'queues', cascade: ['persist'])]
    #[ORM\JoinColumn(name: 'link_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?PmsEventoBeds24Link $link = null;

    #[ORM\ManyToOne(targetEntity: Beds24Endpoint::class, inversedBy: 'bookingsPushQueues')]
    #[ORM\JoinColumn(name: 'endpoint_id', referencedColumnName: 'id', nullable: false)]
    private ?Beds24Endpoint $endpoint = null;

    #[ORM\ManyToOne(targetEntity: Beds24Config::class, inversedBy: 'bookingsPushQueues')]
    #[ORM\JoinColumn(name: 'config_id', referencedColumnName: 'id', nullable: true)]
    private ?Beds24Config $config = null;

    // --- DATOS LÓGICOS ---

    #[ORM\Column(type: 'string', length: 191, nullable: true)]
    private ?string $dedupeKey = null;

    #[ORM\Column(type: 'string', length: 40, nullable: true)]
    private ?string $payloadHash = null;

    #[ORM\Column(type: 'bigint', nullable: true)]
    private ?string $beds24BookIdOriginal = null;

    #[ORM\Column(type: 'string', length: 36, nullable: true)]
    private ?string $linkIdOriginal = null;

    // --- AUDITORÍA TÉCNICA (COMPLETA - NO SIMPLIFICADA) ---

    #[ORM\Column(name: 'last_request_raw', type: 'text', nullable: true)]
    private ?string $lastRequestRaw = null;

    #[ORM\Column(name: 'last_response_raw', type: 'text', nullable: true)]
    private ?string $lastResponseRaw = null;

    #[ORM\Column(name: 'execution_result', type: 'json', nullable: true)]
    private ?array $executionResult = null;

    #[ORM\Column(name: 'last_http_code', type: 'smallint', nullable: true)]
    private ?int $lastHttpCode = null;

    // --- WORKER CONTROL ---

    #[ORM\Column(type: 'string', length: 20, options: ['default' => self::STATUS_PENDING])]
    private string $status = self::STATUS_PENDING;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?DateTimeImmutable $runAt = null;

    #[ORM\Column(type: 'datetime', nullable: true)]
    private ?DateTimeInterface $lockedAt = null;

    #[ORM\Column(type: 'string', length: 100, nullable: true)]
    private ?string $lockedBy = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $failedReason = null;

    #[ORM\Column(type: 'integer', options: ['default' => 0])]
    private int $retryCount = 0;

    #[ORM\Column(name: 'max_attempts', type: 'smallint', options: ['default' => 5])]
    private int $maxAttempts = 5;

    public function __construct()
    {
        $this->runAt = new DateTimeImmutable();

        $this->id = Uuid::v7();
    }

    // =========================================================================
    // MÁQUINA DE ESTADOS (ESTRICTA SEGÚN INTERFAZ)
    // =========================================================================

    public function markProcessing(string $workerId, DateTimeImmutable $now): void
    {
        $this->status = self::STATUS_PROCESSING;
        $this->lockedBy = $workerId;
        $this->lockedAt = $now;
    }

    /**
     * @param DateTimeImmutable $now Requerido por la interfaz
     */
    public function markSuccess(DateTimeImmutable $now): void
    {
        $this->status = self::STATUS_SUCCESS;
        $this->failedReason = null;
        $this->runAt = null;

        $this->lockedAt = null;
        $this->lockedBy = null;
        // Retorna void (Interface compliance)
    }

    /**
     * @param string $reason
     * @param int|null $httpCode
     * @param DateTimeImmutable $nextRetry
     */
    public function markFailure(string $reason, ?int $httpCode, DateTimeImmutable $nextRetry): void
    {
        $this->retryCount++;

        $this->failedReason = mb_substr($reason, 0, 65000);
        $this->lastHttpCode = $httpCode; // Guardamos el código HTTP del fallo

        $this->status = self::STATUS_PENDING;
        $this->runAt = $nextRetry;

        $this->lockedAt = null;
        $this->lockedBy = null;
        // Retorna void (Interface compliance)
    }

    // =========================================================================
    // GETTERS Y SETTERS PROPIOS
    // =========================================================================

    public function getLink(): ?PmsEventoBeds24Link { return $this->link; }

    public function setLink(?PmsEventoBeds24Link $link): self {
        $this->link = $link;
        if ($link) {
            if ($link->getBeds24BookId()) {
                $this->setBeds24BookIdOriginal($link->getBeds24BookId());
            }
            if ($link->getId()) {
                $this->setLinkIdOriginal((string) $link->getId());
            }
        }
        return $this;
    }

    public function getEndpoint(): ?Beds24Endpoint { return $this->endpoint; }
    public function setEndpoint(?EndpointInterface $endpoint): self { $this->endpoint = $endpoint; return $this; }

    public function getConfig(): ?Beds24Config { return $this->config; }
    public function setConfig(?ChannelConfigInterface $config): self { $this->config = $config; return $this; }

    public function getDedupeKey(): ?string { return $this->dedupeKey; }
    public function setDedupeKey(?string $key): self { $this->dedupeKey = $key; return $this; }

    public function getPayloadHash(): ?string { return $this->payloadHash; }
    public function setPayloadHash(?string $hash): self { $this->payloadHash = $hash; return $this; }

    public function getBeds24BookIdOriginal(): ?string { return $this->beds24BookIdOriginal; }
    public function setBeds24BookIdOriginal(?string $val): self { $this->beds24BookIdOriginal = $val; return $this; }

    public function getLinkIdOriginal(): ?string { return $this->linkIdOriginal; }
    public function setLinkIdOriginal(?string $val): self { $this->linkIdOriginal = $val; return $this; }

    // =========================================================================
    // GETTERS Y SETTERS DE AUDITORÍA (COMPLETOS)
    // =========================================================================

    public function getLastRequestRaw(): ?string { return $this->lastRequestRaw; }
    public function setLastRequestRaw(?string $raw): self {
        $this->lastRequestRaw = $raw;
        return $this;
    }

    public function getLastResponseRaw(): ?string { return $this->lastResponseRaw; }
    public function setLastResponseRaw(?string $raw): self {
        $this->lastResponseRaw = $raw;
        return $this;
    }

    public function getLastHttpCode(): ?int { return $this->lastHttpCode; }
    public function setLastHttpCode(?int $code): self {
        $this->lastHttpCode = $code;
        return $this;
    }

    public function getExecutionResult(): ?array { return $this->executionResult; }
    public function setExecutionResult(?array $result): self {
        $this->executionResult = $result;
        return $this;
    }

    // =========================================================================
    // GETTERS Y SETTERS DE WORKER
    // =========================================================================

    public function getStatus(): string { return $this->status; }
    public function setStatus(string $status): self { $this->status = $status; return $this; }

    public function getRunAt(): ?DateTimeImmutable { return $this->runAt; }
    public function setRunAt(?DateTimeInterface $at): self {
        $this->runAt = $at instanceof DateTimeImmutable || $at === null
            ? $at
            : DateTimeImmutable::createFromInterface($at);
        return $this;
    }

    public function getLockedAt(): ?DateTimeInterface { return $this->lockedAt; }
    public function setLockedAt(?DateTimeInterface $lockedAt): self {
        $this->lockedAt = $lockedAt;
        return $this;
    }

    public function getLockedBy(): ?string { return $this->lockedBy; }
    public function setLockedBy(?string $lockedBy): self { $this->lockedBy = $lockedBy; return $this; }

    public function getFailedReason(): ?string { return $this->failedReason; }
    public function setFailedReason(?string $reason): self { $this->failedReason = $reason; return $this; }

    public function getRetryCount(): int { return $this->retryCount; }
    public function setRetryCount(int $count): self { $this->retryCount = $count; return $this; }
    public function incrementRetryCount(): self { $this->retryCount++; return $this; }

    public function getMaxAttempts(): int { return $this->maxAttempts; }
    public function setMaxAttempts(int $limit): self { $this->maxAttempts = $limit; return $this; }
}