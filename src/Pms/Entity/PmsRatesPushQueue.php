<?php
declare(strict_types=1);

namespace App\Pms\Entity;

use App\Entity\MaestroMoneda;
use App\Exchange\Service\Contract\ExchangeQueueItemInterface;
use App\Pms\Repository\PmsRatesPushQueueRepository;
use DateTimeImmutable;
use DateTimeInterface;
use Doctrine\ORM\Mapping as ORM;
use Gedmo\Mapping\Annotation as Gedmo;

#[ORM\Entity(repositoryClass: PmsRatesPushQueueRepository::class)]
#[ORM\Table(
    name: 'pms_rates_push_queue',
    indexes: [
        // Índices de Negocio
        new ORM\Index(columns: ['pms_unidad_id'], name: 'idx_rpq_unidad'),
        new ORM\Index(columns: ['pms_unidad_beds24_map_id'], name: 'idx_rpq_map'),
        new ORM\Index(columns: ['fechaInicio', 'fechaFin'], name: 'idx_rpq_fechas'),
        new ORM\Index(columns: ['effectiveAt'], name: 'idx_rpq_effective'),

        // Índices Técnicos (Worker)
        new ORM\Index(columns: ['status'], name: 'idx_rpq_status'),
        new ORM\Index(columns: ['run_at'], name: 'idx_rpq_run_at'),
        new ORM\Index(columns: ['endpoint_id'], name: 'idx_rpq_endpoint'),
    ]
)]
class PmsRatesPushQueue implements ExchangeQueueItemInterface
{
    public const STATUS_PENDING    = 'pending';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_SUCCESS    = 'success';
    public const STATUS_FAILED     = 'failed';

    #[ORM\Id] #[ORM\GeneratedValue] #[ORM\Column]
    private ?int $id = null;

    // --- 1. CONTEXTO DE NEGOCIO ---

    /**
     * inversedBy: Debe coincidir con PmsUnidad::$tarifaQueues
     */
    #[ORM\ManyToOne(targetEntity: PmsUnidad::class, inversedBy: 'tarifaQueues')]
    #[ORM\JoinColumn(nullable: false)]
    private ?PmsUnidad $unidad = null;

    /**
     * El mapa es vital para obtener el roomid. Sin inversedBy (unidireccional preferido aquí).
     */
    #[ORM\ManyToOne(targetEntity: PmsUnidadBeds24Map::class)]
    #[ORM\JoinColumn(name: 'pms_unidad_beds24_map_id', nullable: false, onDelete: 'CASCADE')]
    private ?PmsUnidadBeds24Map $unidadBeds24Map = null;

    #[ORM\ManyToOne(targetEntity: Beds24Config::class, inversedBy: 'ratesQueues')]
    #[ORM\JoinColumn(name: 'beds24_config_id', referencedColumnName: 'id', nullable: false)]
    private ?Beds24Config $beds24Config = null;

    /**
     * inversedBy: Debe coincidir con PmsBeds24Endpoint::$ratesQueues
     */
    #[ORM\ManyToOne(targetEntity: PmsBeds24Endpoint::class, inversedBy: 'ratesQueues')]
    #[ORM\JoinColumn(nullable: false)]
    private ?PmsBeds24Endpoint $endpoint = null;

    // --- 2. DATOS DE TARIFA (PAYLOAD) ---

    #[ORM\Column(type: 'date')]
    private ?DateTimeInterface $fechaInicio = null;

    #[ORM\Column(type: 'date')]
    private ?DateTimeInterface $fechaFin = null;

    #[ORM\Column(type: 'decimal', precision: 10, scale: 2)]
    private ?string $precio = null;

    #[ORM\Column(type: 'smallint', options: ['default' => 2])]
    private ?int $minStay = 2;

    #[ORM\ManyToOne(targetEntity: MaestroMoneda::class)]
    #[ORM\JoinColumn(nullable: true)]
    private ?MaestroMoneda $moneda = null;

    /**
     * inversedBy: Debe coincidir con PmsTarifaRango::$queues
     */
    #[ORM\ManyToOne(targetEntity: PmsTarifaRango::class, inversedBy: 'queues')]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?PmsTarifaRango $tarifaRango = null;

    // --- 3. DATOS DE EXCHANGE (WORKER) ---

    #[ORM\Column(type: 'string', length: 20, options: ['default' => self::STATUS_PENDING])]
    private string $status = self::STATUS_PENDING;

    #[ORM\Column(name: 'run_at', type: 'datetime', nullable: true)]
    private ?DateTimeInterface $runAt = null;

    #[ORM\Column(type: 'datetime', nullable: true)]
    private ?DateTimeInterface $effectiveAt = null;

    #[ORM\Column(type: 'string', length: 120, nullable: true)]
    private ?string $dedupeKey = null;

    #[ORM\Column(name: 'locked_at', type: 'datetime', nullable: true)]
    private ?DateTimeInterface $lockedAt = null;

    #[ORM\Column(name: 'locked_by', type: 'string', length: 64, nullable: true)]
    private ?string $lockedBy = null;

    #[ORM\Column(name: 'retry_count', type: 'smallint', options: ['default' => 0])]
    private int $retryCount = 0;

    #[ORM\Column(name: 'max_attempts', type: 'smallint', options: ['default' => 5])]
    private int $maxAttempts = 5;

    // --- 4. AUDITORÍA ---

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

    #[Gedmo\Timestampable(on: 'create')]
    #[ORM\Column(type: 'datetime')]
    private ?DateTimeInterface $created = null;

    #[Gedmo\Timestampable(on: 'update')]
    #[ORM\Column(type: 'datetime')]
    private ?DateTimeInterface $updated = null;

    // =========================================================================
    // INTERFAZ ExchangeQueueItemInterface
    // =========================================================================

    public function getId(): ?int { return $this->id; }

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

    // =========================================================================
    // GETTERS & SETTERS COMPLETOS
    // =========================================================================

    public function getUnidad(): ?PmsUnidad { return $this->unidad; }
    public function setUnidad(?PmsUnidad $u): self { $this->unidad = $u; return $this; }

    public function getUnidadBeds24Map(): ?PmsUnidadBeds24Map { return $this->unidadBeds24Map; }
    public function setUnidadBeds24Map(?PmsUnidadBeds24Map $m): self {
        $this->unidadBeds24Map = $m;
        if ($m) { $this->beds24Config = $m->getBeds24Config(); }
        return $this;
    }

    public function setBeds24Config(?Beds24Config $cfg): self { $this->beds24Config = $cfg; return $this; }

    public function setEndpoint(?PmsBeds24Endpoint $endpoint): self { $this->endpoint = $endpoint; return $this; }

    public function getFechaInicio(): ?DateTimeInterface { return $this->fechaInicio; }
    public function setFechaInicio(?DateTimeInterface $d): self { $this->fechaInicio = $d; return $this; }

    public function getFechaFin(): ?DateTimeInterface { return $this->fechaFin; }
    public function setFechaFin(?DateTimeInterface $d): self { $this->fechaFin = $d; return $this; }

    public function getPrecio(): ?string { return $this->precio; }
    public function setPrecio(?string $p): self { $this->precio = $p; return $this; }

    public function getMinStay(): ?int { return $this->minStay; }
    public function setMinStay(?int $m): self { $this->minStay = $m; return $this; }

    public function getMoneda(): ?MaestroMoneda { return $this->moneda; }
    public function setMoneda(?MaestroMoneda $m): self { $this->moneda = $m; return $this; }

    public function getTarifaRango(): ?PmsTarifaRango { return $this->tarifaRango; }
    public function setTarifaRango(?PmsTarifaRango $t): self { $this->tarifaRango = $t; return $this; }

    public function getStatus(): string { return $this->status; }
    public function setStatus(string $s): self { $this->status = $s; return $this; }

    public function getEffectiveAt(): ?DateTimeInterface { return $this->effectiveAt; }
    public function setEffectiveAt(?DateTimeInterface $e): self { $this->effectiveAt = $e; return $this; }

    public function getDedupeKey(): ?string { return $this->dedupeKey; }
    public function setDedupeKey(?string $k): self { $this->dedupeKey = $k; return $this; }

    public function getLockedAt(): ?DateTimeInterface { return $this->lockedAt; }
    public function setLockedAt(?DateTimeInterface $l): self { $this->lockedAt = $l; return $this; }

    public function getLockedBy(): ?string { return $this->lockedBy; }
    public function setLockedBy(?string $l): self { $this->lockedBy = $l; return $this; }

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

    public function getCreated(): ?DateTimeInterface { return $this->created; }
    public function getUpdated(): ?DateTimeInterface { return $this->updated; }

    public function __toString(): string
    {
        return sprintf(
            'RatesQueue #%d [%s] %s->%s (%s)',
            $this->id ?? 0,
            $this->status,
            $this->fechaInicio?->format('Y-m-d') ?? '?',
            $this->fechaFin?->format('Y-m-d') ?? '?',
            $this->unidadBeds24Map?->getBeds24RoomId() ?? 'NoMap'
        );
    }
}