<?php

declare(strict_types=1);

namespace App\Pms\Entity;

use App\Entity\Trait\IdTrait;
use App\Entity\Trait\TimestampTrait;
use App\Exchange\Entity\Beds24Config;
use App\Exchange\Entity\ExchangeEndpoint;
use App\Exchange\Service\Contract\ChannelConfigInterface;
use App\Exchange\Service\Contract\EndpointInterface;
use App\Exchange\Service\Contract\ExchangeQueueItemInterface;
use App\Exchange\Service\Contract\MemoryCleanableInterface;
use App\Pms\Repository\PmsBookingsPullQueueRepository;
use DateTimeImmutable;
use DateTimeInterface;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;
use InvalidArgumentException;

/**
 * Entidad PmsBookingsPullQueue.
 * Gestiona la cola de procesos para la obtención (Pull) de reservas desde canales externos.
 */
#[ORM\Entity(repositoryClass: PmsBookingsPullQueueRepository::class)]
#[ORM\HasLifecycleCallbacks]
#[ORM\Table(name: 'pms_bookings_pull_queue')]
class PmsBookingsPullQueue implements ExchangeQueueItemInterface, MemoryCleanableInterface
{
    /**
     * Gestión de Identificador UUID (BINARY 16).
     */
    use IdTrait;

    /**
     * Gestión de auditoría temporal (DateTimeImmutable).
     */
    use TimestampTrait;

    // --- CONSTANTES DE ESTADO ---
    public const STATUS_PENDING    = 'pending';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_SUCCESS    = 'success';
    public const STATUS_FAILED     = 'failed';

    #[ORM\Column(type: 'string', length: 50)]
    private string $type = 'beds24_bookings_arrival_range';

    // ✅ CORRECCIÓN: Usamos PmsBeds24Config para consistencia con el resto del módulo
    #[ORM\ManyToOne(targetEntity: Beds24Config::class, inversedBy: 'bookingsPullQueues')]
    #[ORM\JoinColumn(name: 'config_id', referencedColumnName: 'id', nullable: false)]
    private ?Beds24Config $config = null;

    #[ORM\ManyToOne(targetEntity: ExchangeEndpoint::class, inversedBy: 'bookingsPullQueues')]
    #[ORM\JoinColumn(nullable: false)]
    private ?ExchangeEndpoint $endpoint = null;

    /** @var Collection<int, PmsUnidad> */
    #[ORM\ManyToMany(targetEntity: PmsUnidad::class, inversedBy: 'bookingsPullQueues')]
    #[ORM\JoinTable(name: 'pms_pull_queue_job_unidad')]
    #[ORM\JoinColumn(name: 'pull_queue_id', referencedColumnName: 'id')]
    #[ORM\InverseJoinColumn(name: 'unidad_id', referencedColumnName: 'id')]
    private Collection $unidades;

    #[ORM\Column(type: 'date', nullable: true)]
    private ?DateTimeInterface $arrivalFrom = null;

    #[ORM\Column(type: 'date', nullable: true)]
    private ?DateTimeInterface $arrivalTo = null;

    #[ORM\Column(name: 'run_at', type: 'datetime')]
    private ?DateTimeInterface $runAt = null;

    #[ORM\Column(type: 'string', length: 20)]
    private string $status = self::STATUS_PENDING;

    #[ORM\Column(name: 'retry_count', type: 'smallint', options: ['default' => 0])]
    private int $retryCount = 0;

    #[ORM\Column(name: 'max_attempts', type: 'smallint', options: ['default' => 5])]
    private int $maxAttempts = 5;

    #[ORM\Column(name: 'locked_at', type: 'datetime', nullable: true)]
    private ?DateTimeInterface $lockedAt = null;

    #[ORM\Column(name: 'locked_by', type: 'string', length: 100, nullable: true)]
    private ?string $lockedBy = null;

    #[ORM\Column(name: 'last_request_raw', type: 'text', nullable: true)]
    private ?string $lastRequestRaw = null;

    #[ORM\Column(name: 'last_response_raw', type: 'text', nullable: true)]
    private ?string $lastResponseRaw = null;

    /** @var array<string, mixed>|null Volcado de la respuesta del canal; su forma la fija el proveedor. */
    #[ORM\Column(name: 'execution_result', type: 'json', nullable: true)]
    private ?array $executionResult = null;

    #[ORM\Column(name: 'last_http_code', type: 'smallint', nullable: true)]
    private ?int $lastHttpCode = null;

    #[ORM\Column(name: 'failed_reason', type: 'string', length: 255, nullable: true)]
    private ?string $failedReason = null;

    public function __construct() {
        $this->unidades = new ArrayCollection();

        $this->id = Uuid::v7();
    }

    public function getRelatedEntitiesToDetach(): array
    {
        // Limpiamos la colección de Unidades que se hayan consultado para este Pull
        return $this->unidades ? $this->unidades->toArray() : [];
    }

    /**
     * Asegura que el trabajo tenga una fecha de ejecución al crearse.
     */
    #[ORM\PrePersist]
    public function ensureRunAtOnCreate(): void {
        if ($this->runAt === null) {
            $this->runAt = new DateTimeImmutable('+1 minute');
        }
    }

    // =========================================================================
    // IMPLEMENTACIÓN ExchangeQueueItemInterface (ESTRICTA)
    // =========================================================================

    public function getConfig(): ?Beds24Config { return $this->config; }

    /**
     * Obligatorio: el motor agrupa los lotes por `(config_id, endpoint_id)`. Ver el contrato.
     */
    public function getEndpoint(): ExchangeEndpoint
    {
        if ($this->endpoint === null) {
            throw new InvalidArgumentException('Cola de pull de reservas sin endpoint: no se puede armar el lote.');
        }

        return $this->endpoint;
    }

    public function setConfig(?ChannelConfigInterface $config): self
    {
        // El contrato admite cualquier configuración de canal; esta cola sólo sabe hablar con
        // Beds24. Se rechaza aquí, diciendo qué llegó, en vez de dejar que reviente más adelante
        // al llamar a un método que esa otra configuración no tiene.
        if ($config !== null && !$config instanceof Beds24Config) {
            throw new \InvalidArgumentException(sprintf(
                '%s sólo admite Beds24Config; llegó %s.',
                self::class,
                $config::class,
            ));
        }

        $this->config = $config;

        return $this;
    }

    public function setEndpoint(?EndpointInterface $endpoint): self
    {
        // Mismo motivo que en setConfig: el contrato es ancho y la propiedad es estrecha.
        if ($endpoint !== null && !$endpoint instanceof ExchangeEndpoint) {
            throw new \InvalidArgumentException(sprintf(
                '%s sólo admite ExchangeEndpoint; llegó %s.',
                self::class,
                $endpoint::class,
            ));
        }

        $this->endpoint = $endpoint;

        return $this;
    }

    public function getRunAt(): ?DateTimeInterface { return $this->runAt; }

    public function setRunAt(?DateTimeInterface $at): self {
        $this->runAt = $at;
        return $this;
    }

    public function getRetryCount(): int { return $this->retryCount; }

    public function setRetryCount(int $count): self {
        $this->retryCount = $count;
        return $this;
    }

    public function getMaxAttempts(): int { return $this->maxAttempts; }

    public function setMaxAttempts(int $limit): self {
        $this->maxAttempts = $limit;
        return $this;
    }

    public function markProcessing(string $workerId, DateTimeImmutable $now): void {
        $this->status = self::STATUS_PROCESSING;
        $this->lockedBy = $workerId;
        $this->lockedAt = $now;
    }

    /**
     * @param DateTimeImmutable $now Requerido por la interfaz
     */
    public function markSuccess(DateTimeImmutable $now): void {
        $this->status = self::STATUS_SUCCESS;
        $this->lockedBy = null;
        $this->lockedAt = null;
        $this->failedReason = null;
        $this->retryCount = 0;

        // La interfaz pide void, no retornamos $this
    }

    /**
     * @param string $reason
     * @param int|null $httpCode
     * @param DateTimeImmutable $nextRetry
     */
    public function markFailure(string $reason, ?int $httpCode, DateTimeImmutable $nextRetry): void {
        $this->status = self::STATUS_FAILED;
        $this->failedReason = mb_substr($reason, 0, 65000);
        $this->lastHttpCode = $httpCode;
        $this->runAt = $nextRetry;
        $this->lockedAt = null;
        $this->lockedBy = null;

        // La interfaz pide void, no retornamos $this
    }

    // =========================================================================
    // GETTERS Y SETTERS DE AUDITORÍA
    // =========================================================================

    public function setLastRequestRaw(?string $raw): self {
        $this->lastRequestRaw = $raw;
        return $this;
    }

    public function getLastRequestRaw(): ?string { return $this->lastRequestRaw; }

    public function setLastResponseRaw(?string $raw): self {
        $this->lastResponseRaw = $raw;
        return $this;
    }

    public function getLastResponseRaw(): ?string { return $this->lastResponseRaw; }

    public function setLastHttpCode(?int $code): self {
        $this->lastHttpCode = $code;
        return $this;
    }

    public function getLastHttpCode(): ?int { return $this->lastHttpCode; }

    /**
     * @param array<string, mixed>|null $result
     */
    public function setExecutionResult(?array $result): self {
        $this->executionResult = $result;
        return $this;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getExecutionResult(): ?array { return $this->executionResult; }

    public function setFailedReason(?string $reason): self {
        $this->failedReason = $reason;
        return $this;
    }

    public function getFailedReason(): ?string { return $this->failedReason; }

    // =========================================================================
    // GETTERS Y SETTERS PROPIOS
    // =========================================================================

    public function getStatus(): string { return $this->status; }

    public function setStatus(string $status): self {
        $this->status = $status;
        return $this;
    }

    /** @return Collection<int, PmsUnidad> */
    public function getUnidades(): Collection { return $this->unidades; }

    public function addUnidad(PmsUnidad $unidad): self {
        if (!$this->unidades->contains($unidad)) {
            $this->unidades->add($unidad);
        }
        return $this;
    }

    public function removeUnidad(PmsUnidad $unidad): self {
        $this->unidades->removeElement($unidad);
        return $this;
    }

    public function getArrivalFrom(): ?DateTimeInterface { return $this->arrivalFrom; }

    public function setArrivalFrom(?DateTimeInterface $dt): self {
        $this->arrivalFrom = $dt;
        return $this;
    }

    public function getArrivalTo(): ?DateTimeInterface { return $this->arrivalTo; }

    public function setArrivalTo(?DateTimeInterface $dt): self {
        $this->arrivalTo = $dt;
        return $this;
    }

    public function getLockedAt(): ?DateTimeInterface { return $this->lockedAt; }

    public function setLockedAt(?DateTimeInterface $dt): self {
        $this->lockedAt = $dt;
        return $this;
    }

    public function getLockedBy(): ?string { return $this->lockedBy; }

    public function setLockedBy(?string $by): self {
        $this->lockedBy = $by;
        return $this;
    }

}