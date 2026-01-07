<?php
declare(strict_types=1);

namespace App\Pms\Entity;

use App\Pms\Repository\PmsTarifaQueueDeliveryRepository;
use DateTimeInterface;
use Doctrine\ORM\Mapping as ORM;
use Gedmo\Mapping\Annotation as Gedmo;
use App\Pms\Entity\Beds24Config;

#[ORM\Entity(repositoryClass: PmsTarifaQueueDeliveryRepository::class)]
#[ORM\Table(
    name: 'pms_tarifa_queue_delivery',
    indexes: [
        new ORM\Index(columns: ['status', 'needsSync'], name: 'idx_tarifa_qd_status_needsync'),
        new ORM\Index(columns: ['lockedAt'], name: 'idx_tarifa_qd_lockedat'),
        new ORM\Index(columns: ['nextRetryAt'], name: 'idx_tarifa_qd_nextretry'),
        new ORM\Index(columns: ['retryCount'], name: 'idx_tarifa_qd_retrycount'),
        new ORM\Index(columns: ['beds24_config_id'], name: 'idx_tarifa_qd_beds24_config'),
        new ORM\Index(columns: ['pms_unidad_beds24_map_id'], name: 'idx_tarifa_qd_map'),
        new ORM\Index(columns: ['effectiveAt'], name: 'idx_tarifa_qd_effectiveat'),
        new ORM\Index(columns: ['processingStartedAt'], name: 'idx_tarifa_qd_processing_started'),
        new ORM\Index(columns: ['lastSync'], name: 'idx_tarifa_qd_lastsync'),
    ],
    uniqueConstraints: [
        // 1 delivery por queue+map (evita duplicados por unidad/config)
        new ORM\UniqueConstraint(name: 'uniq_tarifa_qd_queue_map', columns: ['pms_tarifa_queue_id', 'pms_unidad_beds24_map_id']),
        // dedupe opcional (si lo usas): 1 por key
        new ORM\UniqueConstraint(name: 'uniq_tarifa_qd_dedupe', columns: ['dedupeKey']),
    ]
)]
class PmsTarifaQueueDelivery
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_SUCCESS = 'success';
    public const STATUS_FAILED = 'failed';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: PmsTarifaQueue::class, inversedBy: 'deliveries')]
    #[ORM\JoinColumn(name: 'pms_tarifa_queue_id', nullable: false, onDelete: 'CASCADE')]
    private ?PmsTarifaQueue $queue = null;

    #[ORM\ManyToOne(targetEntity: PmsUnidadBeds24Map::class)]
    #[ORM\JoinColumn(name: 'pms_unidad_beds24_map_id', nullable: false, onDelete: 'CASCADE')]
    private ?PmsUnidadBeds24Map $unidadBeds24Map = null;

    /**
     * Denormalizado para agrupar por credenciales sin joins largos.
     * Debe coincidir con $unidadBeds24Map->getBeds24Config()
     */
    #[ORM\ManyToOne(targetEntity: Beds24Config::class)]
    #[ORM\JoinColumn(name: 'beds24_config_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ?Beds24Config $beds24Config = null;

    #[ORM\Column(type: 'boolean', options: ['default' => true])]
    private ?bool $needsSync = true;

    #[ORM\Column(type: 'string', length: 20, options: ['default' => self::STATUS_PENDING])]
    private string $status = self::STATUS_PENDING;

    #[ORM\Column(type: 'string', length: 32, nullable: true)]
    private ?string $failedReason = null;

    #[ORM\Column(type: 'datetime', nullable: true)]
    private ?DateTimeInterface $nextRetryAt = null;

    #[ORM\Column(type: 'datetime', nullable: true)]
    private ?DateTimeInterface $lockedAt = null;

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

    /**
     * Marca de orden robusta para procesamiento (no usar created/updated).
     *
     * Debe ser seteado por el listener (fan-out) y reflejar el “momento efectivo”
     * del cambio que originó el queue/delivery.
     */
    #[ORM\Column(type: 'datetime', nullable: true)]
    private ?DateTimeInterface $effectiveAt = null;

    #[ORM\Column(type: 'datetime', nullable: true)]
    private ?DateTimeInterface $lastSync = null;

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

    public function getQueue(): ?PmsTarifaQueue { return $this->queue; }
    public function setQueue(?PmsTarifaQueue $queue): self { $this->queue = $queue; return $this; }

    public function getUnidadBeds24Map(): ?PmsUnidadBeds24Map { return $this->unidadBeds24Map; }
    public function setUnidadBeds24Map(?PmsUnidadBeds24Map $map): self
    {
        $this->unidadBeds24Map = $map;
        $this->beds24Config = $map?->getBeds24Config();
        return $this;
    }

    public function getBeds24Config(): ?Beds24Config { return $this->beds24Config; }
    public function setBeds24Config(?Beds24Config $beds24Config): self { $this->beds24Config = $beds24Config; return $this; }

    public function getNeedsSync(): ?bool { return $this->needsSync; }
    public function setNeedsSync(?bool $needsSync): self { $this->needsSync = $needsSync; return $this; }

    public function getStatus(): string { return $this->status; }
    public function setStatus(string $status): self { $this->status = $status; return $this; }

    public function getFailedReason(): ?string { return $this->failedReason; }
    public function setFailedReason(?string $failedReason): self { $this->failedReason = $failedReason; return $this; }

    public function getNextRetryAt(): ?DateTimeInterface { return $this->nextRetryAt; }
    public function setNextRetryAt(?DateTimeInterface $nextRetryAt): self { $this->nextRetryAt = $nextRetryAt; return $this; }

    public function getLockedAt(): ?DateTimeInterface { return $this->lockedAt; }
    public function setLockedAt(?DateTimeInterface $lockedAt): self { $this->lockedAt = $lockedAt; return $this; }

    public function getProcessingStartedAt(): ?DateTimeInterface { return $this->processingStartedAt; }
    public function setProcessingStartedAt(?DateTimeInterface $processingStartedAt): self { $this->processingStartedAt = $processingStartedAt; return $this; }

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

    public function getEffectiveAt(): ?DateTimeInterface { return $this->effectiveAt; }
    public function setEffectiveAt(?DateTimeInterface $effectiveAt): self { $this->effectiveAt = $effectiveAt; return $this; }

    public function getLastSync(): ?DateTimeInterface { return $this->lastSync; }
    public function setLastSync(?DateTimeInterface $lastSync): self { $this->lastSync = $lastSync; return $this; }

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
        $this->processingStartedAt = null;
        $this->retryCount = 0;
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
        $this->failedReason = $failedReason;
        $this->nextRetryAt = $nextRetryAt;
        $this->lockedAt = null;
        $this->lockedBy = null;
        $this->processingStartedAt = null;
        return $this;
    }

    public function __toString(): string
    {
        $id = $this->id ?? '¿?';
        $status = $this->status ?? self::STATUS_PENDING;

        $map = $this->unidadBeds24Map?->__toString() ?? 'map:?';
        $queue = $this->queue?->__toString() ?? 'queue:?';

        return 'TarifaDelivery #' . $id . ' - ' . $status . ' - ' . $map . ' - ' . $queue;
    }
}