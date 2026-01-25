<?php
declare(strict_types=1);

namespace App\Pms\Entity;

use App\Exchange\Service\Contract\ExchangeQueueItemInterface;
use App\Pms\Repository\PmsBookingsPullQueueRepository;
use DateTimeImmutable;
use DateTimeInterface;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Gedmo\Mapping\Annotation as Gedmo;

#[ORM\Entity(repositoryClass: PmsBookingsPullQueueRepository::class)]
#[ORM\HasLifecycleCallbacks]
#[ORM\Table(name: 'pms_bookings_pull_queue')]
class PmsBookingsPullQueue implements ExchangeQueueItemInterface
{
    // --- CONSTANTES DE ESTADO ---
    public const STATUS_PENDING    = 'pending';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_SUCCESS    = 'success';
    public const STATUS_FAILED     = 'failed';

    #[ORM\Id] #[ORM\GeneratedValue] #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(type: 'string', length: 50)]
    private string $type = 'beds24_bookings_arrival_range';

    #[ORM\ManyToOne(targetEntity: Beds24Config::class, inversedBy: 'pullQueueJobs')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Beds24Config $beds24Config = null;

    // ✅ CORRECCIÓN: Se añade inversedBy para sincronizar con PmsBeds24Endpoint
    #[ORM\ManyToOne(targetEntity: PmsBeds24Endpoint::class, inversedBy: 'pullQueueJobs')]
    #[ORM\JoinColumn(nullable: false)]
    private ?PmsBeds24Endpoint $endpoint = null;

    /** * @var Collection<int, PmsUnidad>
     * ✅ CORRECCIÓN: Se añade inversedBy para sincronizar con PmsUnidad
     */
    #[ORM\ManyToMany(targetEntity: PmsUnidad::class, inversedBy: 'pullQueueJobs')]
    #[ORM\JoinTable(name: 'pms_pull_queue_job_unidad')]
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

    #[ORM\Column(name: 'execution_result', type: 'json', nullable: true)]
    private ?array $executionResult = null;

    #[ORM\Column(name: 'last_http_code', type: 'smallint', nullable: true)]
    private ?int $lastHttpCode = null;

    #[ORM\Column(name: 'failed_reason', type: 'string', length: 255, nullable: true)]
    private ?string $failedReason = null;

    #[Gedmo\Timestampable(on: 'create')]
    #[ORM\Column(type: 'datetime')]
    private ?DateTimeInterface $created = null;

    #[Gedmo\Timestampable(on: 'update')]
    #[ORM\Column(type: 'datetime')]
    private ?DateTimeInterface $updated = null;

    public function __construct() {
        $this->unidades = new ArrayCollection();
    }

    #[ORM\PrePersist]
    public function ensureRunAtOnCreate(): void {
        if ($this->runAt === null) {
            $this->runAt = new DateTimeImmutable('+1 minute');
        }
    }

    // =========================================================================
    // IMPLEMENTACIÓN ExchangeQueueItemInterface
    // =========================================================================

    public function getId(): ?int { return $this->id; }

    public function getBeds24Config(): ?Beds24Config { return $this->beds24Config; }

    public function getEndpoint(): ?PmsBeds24Endpoint { return $this->endpoint; }

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

    public function markSuccess(DateTimeImmutable $now): void {
        $this->status = self::STATUS_SUCCESS;
        $this->lockedBy = null;
        $this->lockedAt = null;
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
    // GETTERS Y SETTERS DE AUDITORÍA
    // =========================================================================

    public function setLastRequestRaw(?string $raw): self {
        $this->lastRequestRaw = $raw;
        return $this;
    }

    public function getLastRequestRaw(): ?string {
        return $this->lastRequestRaw;
    }

    public function setLastResponseRaw(?string $raw): self {
        $this->lastResponseRaw = $raw;
        return $this;
    }

    public function getLastResponseRaw(): ?string {
        return $this->lastResponseRaw;
    }

    public function setLastHttpCode(?int $code): self {
        $this->lastHttpCode = $code;
        return $this;
    }

    public function getLastHttpCode(): ?int {
        return $this->lastHttpCode;
    }

    public function setExecutionResult(?array $result): self {
        $this->executionResult = $result;
        return $this;
    }

    public function getExecutionResult(): ?array {
        return $this->executionResult;
    }

    public function setFailedReason(?string $reason): self {
        $this->failedReason = $reason;
        return $this;
    }

    public function getFailedReason(): ?string {
        return $this->failedReason;
    }

    // =========================================================================
    // GETTERS Y SETTERS PROPIOS
    // =========================================================================

    public function setBeds24Config(?Beds24Config $beds24Config): self {
        $this->beds24Config = $beds24Config;
        return $this;
    }

    public function setEndpoint(?PmsBeds24Endpoint $endpoint): self {
        $this->endpoint = $endpoint;
        return $this;
    }

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

    public function getCreated(): ?DateTimeInterface { return $this->created; }

    public function getUpdated(): ?DateTimeInterface { return $this->updated; }
}