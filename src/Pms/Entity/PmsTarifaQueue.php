<?php
declare(strict_types=1);

namespace App\Pms\Entity;

use App\Entity\MaestroMoneda;
use DateTimeInterface;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Gedmo\Mapping\Annotation as Gedmo;

#[ORM\Entity]
#[ORM\Table(
    name: 'pms_tarifa_queue',
    indexes: [
        new ORM\Index(columns: ['status', 'needsSync'], name: 'idx_tarifa_q_status_needsync'),
        new ORM\Index(columns: ['pms_unidad_id'], name: 'idx_tarifa_q_unidad'),
        new ORM\Index(columns: ['pms_tarifa_rango_id'], name: 'idx_tarifa_q_rango'),
        new ORM\Index(columns: ['fechaInicio', 'fechaFin'], name: 'idx_tarifa_q_fechas'),
        new ORM\Index(columns: ['effectiveAt'], name: 'idx_tarifa_q_effectiveat'),
        new ORM\Index(columns: ['created'], name: 'idx_tarifa_q_created'),
        new ORM\Index(columns: ['updated'], name: 'idx_tarifa_q_updated'),
        new ORM\Index(columns: ['endpoint_id', 'needsSync', 'status'], name: 'idx_tarifa_q_endpoint_state'),
    ]
)]
class PmsTarifaQueue
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_SUCCESS = 'success';
    public const STATUS_FAILED = 'failed';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: PmsTarifaRango::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?PmsTarifaRango $tarifaRango = null;

    #[ORM\ManyToOne(targetEntity: PmsUnidad::class)]
    #[ORM\JoinColumn(nullable: false)]
    private ?PmsUnidad $unidad = null;

    #[ORM\ManyToOne(targetEntity: PmsBeds24Endpoint::class)]
    #[ORM\JoinColumn(nullable: false)]
    private ?PmsBeds24Endpoint $endpoint = null;

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

    #[ORM\Column(type: 'boolean', options: ['default' => true])]
    private ?bool $needsSync = true;

    #[ORM\Column(type: 'string', length: 20, options: ['default' => self::STATUS_PENDING])]
    private string $status = self::STATUS_PENDING;

    #[ORM\Column(type: 'string', length: 120, nullable: true)]
    private ?string $dedupeKey = null;

    /**
     * Marca lógica de "cuándo" este queue debe considerarse efectivo para ordenamiento del worker.
     *
     * Importante:
     * - NO usar created/updated (son técnicos).
     * - Este valor se setea desde el listener cuando se crea o actualiza el queue.
     * - Debe copiarse a todos los deliveries del queue.
     */
    #[ORM\Column(type: 'datetime')]
    private ?DateTimeInterface $effectiveAt = null;

    /**
     * Deliveries (fan-out por map/config).
     */
    #[ORM\OneToMany(mappedBy: 'queue', targetEntity: PmsTarifaQueueDelivery::class, cascade: ['persist'], orphanRemoval: true)]
    private Collection $deliveries;

    #[Gedmo\Timestampable(on: 'create')]
    #[ORM\Column(type: 'datetime')]
    private ?DateTimeInterface $created = null;

    #[Gedmo\Timestampable(on: 'update')]
    #[ORM\Column(type: 'datetime')]
    private ?DateTimeInterface $updated = null;

    public function __construct()
    {
        $this->deliveries = new ArrayCollection();
    }

    public function getId(): ?int { return $this->id; }

    public function getTarifaRango(): ?PmsTarifaRango { return $this->tarifaRango; }
    public function setTarifaRango(?PmsTarifaRango $tarifaRango): self { $this->tarifaRango = $tarifaRango; return $this; }

    public function getUnidad(): ?PmsUnidad { return $this->unidad; }
    public function setUnidad(?PmsUnidad $unidad): self { $this->unidad = $unidad; return $this; }

    public function getEndpoint(): ?PmsBeds24Endpoint { return $this->endpoint; }
    public function setEndpoint(?PmsBeds24Endpoint $endpoint): self { $this->endpoint = $endpoint; return $this; }

    public function getFechaInicio(): ?DateTimeInterface { return $this->fechaInicio; }
    public function setFechaInicio(?DateTimeInterface $fechaInicio): self { $this->fechaInicio = $fechaInicio; return $this; }

    public function getFechaFin(): ?DateTimeInterface { return $this->fechaFin; }
    public function setFechaFin(?DateTimeInterface $fechaFin): self { $this->fechaFin = $fechaFin; return $this; }

    public function getPrecio(): ?string { return $this->precio; }
    public function setPrecio(?string $precio): self { $this->precio = $precio; return $this; }

    public function getMinStay(): ?int { return $this->minStay; }
    public function setMinStay(?int $minStay): self { $this->minStay = $minStay; return $this; }

    public function getMoneda(): ?MaestroMoneda { return $this->moneda; }
    public function setMoneda(?MaestroMoneda $moneda): self { $this->moneda = $moneda; return $this; }

    public function getNeedsSync(): ?bool { return $this->needsSync; }
    public function setNeedsSync(?bool $needsSync): self { $this->needsSync = $needsSync; return $this; }

    public function getStatus(): string { return $this->status; }
    public function setStatus(string $status): self { $this->status = $status; return $this; }

    public function getDedupeKey(): ?string { return $this->dedupeKey; }
    public function setDedupeKey(?string $dedupeKey): self { $this->dedupeKey = $dedupeKey; return $this; }

    public function getEffectiveAt(): ?DateTimeInterface
    {
        return $this->effectiveAt;
    }

    public function setEffectiveAt(?DateTimeInterface $effectiveAt): self
    {
        $this->effectiveAt = $effectiveAt;
        return $this;
    }

    public function getDeliveries(): Collection
    {
        return $this->deliveries;
    }

    public function addDelivery(PmsTarifaQueueDelivery $delivery): self
    {
        if (!$this->deliveries->contains($delivery)) {
            $this->deliveries->add($delivery);
            $delivery->setQueue($this);
        }
        return $this;
    }

    public function removeDelivery(PmsTarifaQueueDelivery $delivery): self
    {
        if ($this->deliveries->removeElement($delivery)) {
            // owning side handled by orphanRemoval
        }
        return $this;
    }

    public function getCreated(): ?DateTimeInterface { return $this->created; }
    public function getUpdated(): ?DateTimeInterface { return $this->updated; }

    /**
     * Útil para que el worker “cierre” el queue según deliveries.
     * - SUCCESS: todos needsSync=false
     * - FAILED: alguno FAILED
     * - PROCESSING: alguno PROCESSING
     * - PENDING: caso contrario
     */
    public function refreshAggregateStatusFromDeliveries(): self
    {
        if ($this->deliveries->count() === 0) {
            $this->needsSync = false;
            $this->status = self::STATUS_SUCCESS;
            return $this;
        }

        $anyProcessing = false;
        $anyFailed = false;
        $anyPending = false;
        $anyNeedsSync = false;

        foreach ($this->deliveries as $d) {
            /** @var PmsTarifaQueueDelivery $d */
            if ($d->getNeedsSync() === true) {
                $anyNeedsSync = true;
            }
            if ($d->getStatus() === PmsTarifaQueueDelivery::STATUS_PROCESSING) $anyProcessing = true;
            if ($d->getStatus() === PmsTarifaQueueDelivery::STATUS_FAILED) $anyFailed = true;
            if ($d->getStatus() === PmsTarifaQueueDelivery::STATUS_PENDING) $anyPending = true;
        }

        $this->needsSync = $anyNeedsSync;

        if ($anyProcessing) {
            $this->status = self::STATUS_PROCESSING;
        } elseif ($anyFailed) {
            $this->status = self::STATUS_FAILED;
        } elseif ($anyPending || $anyNeedsSync) {
            $this->status = self::STATUS_PENDING;
        } else {
            $this->status = self::STATUS_SUCCESS;
        }

        return $this;
    }

    public function __toString(): string
    {
        $id = $this->id ?? '¿?';
        $status = $this->status ?? self::STATUS_PENDING;
        $endpoint = $this->endpoint?->getAccion() ?? 'endpoint';

        $unidad = $this->unidad?->getNombre() ?? 'Unidad';
        $fi = $this->fechaInicio?->format('Y-m-d') ?? '?';
        $ff = $this->fechaFin?->format('Y-m-d') ?? '?';

        return 'TarifaQueue #' . $id . ' - ' . $unidad . ' ' . $fi . '→' . $ff . ' - ' . $endpoint . ' (' . $status . ')';
    }
}