<?php
declare(strict_types=1);

namespace App\Pms\Entity;

use App\Pms\Entity\Beds24Config;
use App\Pms\Entity\PmsUnidad;
use DateTimeImmutable;
use DateTimeInterface;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Gedmo\Mapping\Annotation as Gedmo;

#[ORM\Entity(repositoryClass: \App\Pms\Repository\PmsPullQueueJobRepository::class)]
#[ORM\HasLifecycleCallbacks]
#[ORM\Table(
    name: 'pms_pull_queue_job',
    indexes: [
        // Lookup principal del worker/claim
        new ORM\Index(columns: ['type', 'status', 'runAt', 'priority', 'id'], name: 'idx_pull_queue_claim'),
        // Útil para pantallas y reportes (status + runAt)
        new ORM\Index(columns: ['status', 'runAt'], name: 'idx_pull_queue_status_runat'),
        // Filtro por config
        new ORM\Index(columns: ['beds24_config_id'], name: 'idx_pull_queue_config'),
        // Watchdog TTL (limpieza de locks colgados)
        new ORM\Index(columns: ['status', 'lockedAt'], name: 'idx_pull_queue_running_lockedat'),
    ]
)]
class PmsPullQueueJob
{
    /** Único tipo activo hoy */
    public const TYPE_BEDS24_BOOKINGS_ARRIVAL_RANGE = 'beds24_bookings_arrival_range';

    /** Estados */
    public const STATUS_PENDING = 'pending';
    public const STATUS_RUNNING = 'running';
    public const STATUS_DONE    = 'done';
    public const STATUS_FAILED  = 'failed';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /**
     * Tipo de job.
     * Hoy es fijo y se inicializa por defecto.
     */
    #[ORM\Column(type: 'string', length: 50)]
    private string $type = self::TYPE_BEDS24_BOOKINGS_ARRIVAL_RANGE;

    /**
     * Config Beds24 (obligatoria).
     */
    #[ORM\ManyToOne(targetEntity: Beds24Config::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Beds24Config $beds24Config = null;

    /**
     * Unidades opcionales.
     * Solo se usan para resolver roomIds desde los maps.
     */
    #[ORM\ManyToMany(targetEntity: PmsUnidad::class)]
    #[ORM\JoinTable(name: 'pms_pull_queue_job_unidad')]
    private Collection $unidades;

    /**
     * Rango de llegada (arrival).
     */
    #[ORM\Column(type: 'date')]
    private ?DateTimeInterface $arrivalFrom = null;

    #[ORM\Column(type: 'date')]
    private ?DateTimeInterface $arrivalTo = null;

    /**
     * Programación y control.
     */
    #[ORM\Column(type: 'datetime')]
    private ?DateTimeInterface $runAt = null;

    #[ORM\Column(type: 'string', length: 20)]
    private string $status = self::STATUS_PENDING;

    #[ORM\Column(type: 'integer')]
    private int $priority = 100;

    #[ORM\Column(type: 'integer')]
    private int $attempts = 0;

    #[ORM\Column(type: 'integer')]
    private int $maxAttempts = 3;

    #[ORM\Column(type: 'datetime', nullable: true)]
    private ?DateTimeInterface $lockedAt = null;

    #[ORM\Column(type: 'string', length: 100, nullable: true)]
    private ?string $lockedBy = null;

    /**
     * Debug / auditoría técnica.
     */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $payloadComputed = null;

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $responseMeta = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $lastError = null;

    #[Gedmo\Timestampable(on: 'create')]
    #[ORM\Column(type: 'datetime')]
    private ?DateTimeInterface $created = null;

    #[Gedmo\Timestampable(on: 'update')]
    #[ORM\Column(type: 'datetime')]
    private ?DateTimeInterface $updated = null;

    public function __construct()
    {
        $this->unidades = new ArrayCollection();
    }

    #[ORM\PrePersist]
    public function ensureRunAtOnCreate(): void
    {
        if ($this->runAt === null) {
            $this->runAt = new DateTimeImmutable('+5 minutes');
        }
    }

    // -------------------------
    // Getters / setters simples
    // -------------------------

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getType(): string
    {
        return $this->type;
    }

    // NO setter de type (intencional)

    public function getBeds24Config(): ?Beds24Config
    {
        return $this->beds24Config;
    }

    public function setBeds24Config(Beds24Config $config): void
    {
        $this->beds24Config = $config;
    }

    public function getArrivalFrom(): ?DateTimeInterface
    {
        return $this->arrivalFrom;
    }

    public function setArrivalFrom(DateTimeInterface $from): void
    {
        $this->arrivalFrom = $from;
    }

    public function getArrivalTo(): ?DateTimeInterface
    {
        return $this->arrivalTo;
    }

    public function setArrivalTo(DateTimeInterface $to): void
    {
        $this->arrivalTo = $to;
    }

    public function getRunAt(): ?DateTimeInterface
    {
        return $this->runAt;
    }

    public function setRunAt(DateTimeInterface $runAt): void
    {
        $this->runAt = $runAt;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): void
    {
        $this->status = $status;
    }

    public function getPriority(): int
    {
        return $this->priority;
    }

    public function setPriority(int $priority): void
    {
        $this->priority = $priority;
    }

    public function getAttempts(): int
    {
        return $this->attempts;
    }

    public function setAttempts(int $attempts): void
    {
        $this->attempts = $attempts;
    }

    public function incrementAttempts(): void
    {
        $this->attempts++;
    }

    public function getMaxAttempts(): int
    {
        return $this->maxAttempts;
    }

    public function setMaxAttempts(int $maxAttempts): void
    {
        $this->maxAttempts = $maxAttempts;
    }

    public function getLockedAt(): ?DateTimeInterface
    {
        return $this->lockedAt;
    }

    public function setLockedAt(?DateTimeInterface $lockedAt): void
    {
        $this->lockedAt = $lockedAt;
    }

    public function getLockedBy(): ?string
    {
        return $this->lockedBy;
    }

    public function setLockedBy(?string $lockedBy): void
    {
        $this->lockedBy = $lockedBy;
    }

    public function getPayloadComputed(): ?array
    {
        return $this->payloadComputed;
    }

    public function setPayloadComputed(?array $payloadComputed): void
    {
        $this->payloadComputed = $payloadComputed;
    }

    public function getResponseMeta(): ?array
    {
        return $this->responseMeta;
    }

    public function setResponseMeta(?array $responseMeta): void
    {
        $this->responseMeta = $responseMeta;
    }

    public function getLastError(): ?string
    {
        return $this->lastError;
    }

    public function setLastError(?string $lastError): void
    {
        $this->lastError = $lastError;
    }

    public function getCreated(): ?DateTimeInterface
    {
        return $this->created;
    }

    public function getUpdated(): ?DateTimeInterface
    {
        return $this->updated;
    }

    public function getUnidades(): Collection
    {
        return $this->unidades;
    }

    public function addUnidad(PmsUnidad $unidad): void
    {
        if (!$this->unidades->contains($unidad)) {
            $this->unidades->add($unidad);
        }
    }

    public function removeUnidad(PmsUnidad $unidad): void
    {
        if ($this->unidades->contains($unidad)) {
            $this->unidades->removeElement($unidad);
        }
    }

    public function clearUnidades(): void
    {
        $this->unidades->clear();
    }
}