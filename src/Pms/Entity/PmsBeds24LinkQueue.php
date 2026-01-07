<?php

namespace App\Pms\Entity;

use App\Pms\Entity\PmsEventoBeds24Link;
use DateTimeInterface;
use Doctrine\ORM\Mapping as ORM;
use Gedmo\Mapping\Annotation as Gedmo;
use App\Pms\Entity\Beds24Config;

#[ORM\Entity]
#[ORM\Table(name: 'pms_beds24_link_queue')]
class PmsBeds24LinkQueue
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_SUCCESS = 'success';
    public const STATUS_FAILED = 'failed';
    public const STATUS_CANCELLED = 'canceled';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;


    #[ORM\ManyToOne(targetEntity: PmsEventoBeds24Link::class, inversedBy: 'queues')]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?PmsEventoBeds24Link $link = null;

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $linkIdOriginal = null;

    #[ORM\Column(type: 'bigint', nullable: true)]
    private ?string $beds24BookIdOriginal = null;

    #[ORM\ManyToOne(targetEntity: PmsBeds24Endpoint::class, inversedBy: 'queues')]
    #[ORM\JoinColumn(nullable: false)]
    private ?PmsBeds24Endpoint $endpoint = null;

    #[ORM\ManyToOne(targetEntity: Beds24Config::class)]
    #[ORM\JoinColumn(name: 'beds24_config_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?Beds24Config $beds24Config = null;

    #[ORM\Column(type: 'boolean', options: ['default' => true])]
    private ?bool $needsSync = true;

    #[ORM\Column(type: 'string', length: 20, options: ['default' => 'pending'])]
    private ?string $status = 'pending';

    /**
     * Código corto y estable para clasificar el motivo del fallo.
     *
     * Importante:
     * - NO es el mensaje humano (eso va en lastMessage)
     * - NO es el body (eso va en lastResponseJson)
     * - Se usa para métricas, watchdogs, reglas de reintento y diagnósticos rápidos.
     *
     * Ejemplos típicos:
     * - http_timeout
     * - http_401
     * - http_429
     * - beds24_validation
     * - payload_invalid
     * - watchdog_timeout
     */
    #[ORM\Column(type: 'string', length: 32, nullable: true)]
    private ?string $failedReason = null;

    #[ORM\Column(type: 'datetime', nullable: true)]
    private ?DateTimeInterface $nextRetryAt = null;

    #[ORM\Column(type: 'datetime', nullable: true)]
    private ?DateTimeInterface $lockedAt = null;

    /**
     * Timestamp explícito para watchdog.
     *
     * Se usa para detectar colas que quedaron "zombis" en estado processing
     * (worker muerto, timeout, kill, deploy, etc.).
     * No reemplaza lockedAt: lo complementa para diagnóstico y auditoría.
     */
    #[ORM\Column(type: 'datetime', nullable: true)]
    private ?DateTimeInterface $processingStartedAt = null;

    #[ORM\Column(type: 'string', length: 64, nullable: true)]
    private ?string $lockedBy = null;

    #[ORM\Column(type: 'smallint', options: ['default' => 0])]
    private ?int $retryCount = 0;

    #[ORM\Column(type: 'smallint', nullable: true)]
    private ?int $lastHttpCode = null;

    #[ORM\Column(type: 'string', length: 120, nullable: true)]
    private ?string $dedupeKey = null;

    #[ORM\Column(type: 'string', length: 64, nullable: true)]
    private ?string $payloadHash = null;

    #[ORM\Column(type: 'datetime', nullable: true)]
    private ?DateTimeInterface $lastSync = null;

    #[ORM\Column(type: 'string', length: 30, nullable: true)]
    private ?string $lastStatus = null;

    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $lastMessage = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $lastRequestJson = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $lastResponseJson = null;

    #[Gedmo\Timestampable(on: 'create')]
    #[ORM\Column(type: 'datetime')]
    private ?DateTimeInterface $created = null;

    #[Gedmo\Timestampable(on: 'update')]
    #[ORM\Column(type: 'datetime')]
    private ?DateTimeInterface $updated = null;

    public function getId(): ?int { return $this->id; }


    public function getLink(): ?PmsEventoBeds24Link { return $this->link; }
    public function setLink(?PmsEventoBeds24Link $link): self
    {
        $this->link = $link;

        if ($link !== null && $link->getId() !== null) {
            $this->linkIdOriginal = $link->getId();
        }

        // Snapshot estable para operaciones (sobre todo DELETE) aunque el link se elimine
        // (onDelete=SET NULL). Caso especial PULL/mirrors: si el mirror no tiene bookId,
        // usamos el del originLink (padre).
        if ($link !== null) {
            $bookId = $link->getBeds24BookId();
            $this->beds24BookIdOriginal = ($bookId !== null && $bookId !== '') ? (string) $bookId : null;
        }

        return $this;
    }

    public function getLinkIdOriginal(): ?int { return $this->linkIdOriginal; }
    public function setLinkIdOriginal(?int $linkIdOriginal): self { $this->linkIdOriginal = $linkIdOriginal; return $this; }

    public function getBeds24BookIdOriginal(): ?string { return $this->beds24BookIdOriginal; }
    public function setBeds24BookIdOriginal(?string $beds24BookIdOriginal): self { $this->beds24BookIdOriginal = $beds24BookIdOriginal; return $this; }

    public function getEndpoint(): ?PmsBeds24Endpoint { return $this->endpoint; }
    public function setEndpoint(?PmsBeds24Endpoint $endpoint): self { $this->endpoint = $endpoint; return $this; }

    public function getBeds24Config(): ?Beds24Config
    {
        return $this->beds24Config;
    }

    public function setBeds24Config(?Beds24Config $beds24Config): self
    {
        $this->beds24Config = $beds24Config;
        return $this;
    }

    public function isNeedsSync(): ?bool { return $this->needsSync; }
    public function setNeedsSync(?bool $needsSync): self { $this->needsSync = $needsSync; return $this; }

    public function getStatus(): ?string { return $this->status; }
    public function setStatus(?string $status): self { $this->status = $status; return $this; }

    public function getFailedReason(): ?string { return $this->failedReason; }
    public function setFailedReason(?string $failedReason): self { $this->failedReason = $failedReason; return $this; }

    public function getNextRetryAt(): ?DateTimeInterface { return $this->nextRetryAt; }
    public function setNextRetryAt(?DateTimeInterface $nextRetryAt): self { $this->nextRetryAt = $nextRetryAt; return $this; }

    public function getLockedAt(): ?DateTimeInterface { return $this->lockedAt; }
    public function setLockedAt(?DateTimeInterface $lockedAt): self { $this->lockedAt = $lockedAt; return $this; }

    public function getLockedBy(): ?string { return $this->lockedBy; }
    public function setLockedBy(?string $lockedBy): self { $this->lockedBy = $lockedBy; return $this; }

    public function getRetryCount(): ?int { return $this->retryCount; }
    public function setRetryCount(?int $retryCount): self { $this->retryCount = $retryCount; return $this; }

    public function getLastHttpCode(): ?int { return $this->lastHttpCode; }
    public function setLastHttpCode(?int $lastHttpCode): self { $this->lastHttpCode = $lastHttpCode; return $this; }

    public function getDedupeKey(): ?string { return $this->dedupeKey; }
    public function setDedupeKey(?string $dedupeKey): self { $this->dedupeKey = $dedupeKey; return $this; }

    public function getPayloadHash(): ?string { return $this->payloadHash; }
    public function setPayloadHash(?string $payloadHash): self { $this->payloadHash = $payloadHash; return $this; }

    public function getLastSync(): ?DateTimeInterface { return $this->lastSync; }
    public function setLastSync(?DateTimeInterface $lastSync): self { $this->lastSync = $lastSync; return $this; }

    public function getLastStatus(): ?string { return $this->lastStatus; }
    public function setLastStatus(?string $lastStatus): self { $this->lastStatus = $lastStatus; return $this; }

    public function getLastMessage(): ?string { return $this->lastMessage; }
    public function setLastMessage(?string $lastMessage): self { $this->lastMessage = $lastMessage; return $this; }

    public function getLastRequestJson(): ?string { return $this->lastRequestJson; }
    public function setLastRequestJson(?string $lastRequestJson): self { $this->lastRequestJson = $lastRequestJson; return $this; }

    public function getLastResponseJson(): ?string { return $this->lastResponseJson; }
    public function setLastResponseJson(?string $lastResponseJson): self { $this->lastResponseJson = $lastResponseJson; return $this; }

    public function getCreated(): ?DateTimeInterface { return $this->created; }
    public function getUpdated(): ?DateTimeInterface { return $this->updated; }

    public function canRunNow(DateTimeInterface $now): bool
    {
        if ($this->needsSync !== true) return false;
        if ($this->status === self::STATUS_PROCESSING) return false;
        return $this->nextRetryAt === null || $this->nextRetryAt <= $now;
    }

    public function markProcessing(string $workerId, DateTimeInterface $now): self
    {
        $this->status = self::STATUS_PROCESSING;
        $this->lockedBy = $workerId;
        $this->lockedAt = $now;
        // Se guarda explícitamente para watchdogs y diagnósticos
        $this->processingStartedAt = $now;
        return $this;
    }

    public function markSuccess(DateTimeInterface $now): self
    {
        $this->needsSync = false;
        $this->status = self::STATUS_SUCCESS;
        $this->lastSync = $now;
        $this->nextRetryAt = null;
        $this->lockedAt = null;
        $this->lockedBy = null;
        // Limpieza explícita del watchdog
        $this->processingStartedAt = null;
        // Limpieza: si quedó un motivo de fallo anterior, lo borramos al quedar OK.
        $this->failedReason = null;
        return $this;
    }

    public function markFailure(string $message, ?int $httpCode, DateTimeInterface $nextRetryAt, ?string $failedReason = null): self
    {
        $this->needsSync = true;
        $this->status = self::STATUS_FAILED;
        $this->retryCount = (int) ($this->retryCount ?? 0) + 1;
        $this->lastMessage = $message;
        $this->lastHttpCode = $httpCode;
        // Clasificación estable del fallo (para métricas y reglas). Puede venir null.
        $this->failedReason = $failedReason;
        $this->nextRetryAt = $nextRetryAt;
        $this->lockedAt = null;
        $this->lockedBy = null;
        // Limpieza explícita del watchdog (la cola deja de estar en processing)
        $this->processingStartedAt = null;
        return $this;
    }

    public function getProcessingStartedAt(): ?DateTimeInterface
    {
        return $this->processingStartedAt;
    }

    public function setProcessingStartedAt(?DateTimeInterface $processingStartedAt): self
    {
        $this->processingStartedAt = $processingStartedAt;
        return $this;
    }

    public function __toString(): string
    {
        $id = $this->id ?? '¿?';
        $status = $this->status ?? $this->lastStatus ?? self::STATUS_PENDING;
        $reason = $this->failedReason ? (' reason=' . $this->failedReason) : '';
        $endpoint = $this->endpoint?->getAccion() ?? 'endpoint';
        $link = $this->link?->__toString()
            ?? ('link:' . ($this->linkIdOriginal ?? '¿?') . ' bookId:' . ($this->beds24BookIdOriginal ?? '¿?'));

        return 'Queue #' . $id . ' - ' . $endpoint . ' (' . $status . $reason . ') - ' . $link;
    }
}