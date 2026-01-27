<?php

declare(strict_types=1);

namespace App\Pms\Entity;

use App\Entity\Maestro\MaestroMoneda;
use App\Entity\Trait\IdTrait;
use App\Entity\Trait\TimestampTrait;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * Entidad PmsUnidad.
 * Representa un apartamento o habitación específica.
 * IDs: UUID (Propio), UUID (Establecimiento), String (Moneda).
 */
#[ORM\Entity]
#[ORM\Table(name: 'pms_unidad')]
#[ORM\HasLifecycleCallbacks]
class PmsUnidad
{
    /** Gestión de Identificador UUID (BINARY 16) */
    use IdTrait;

    /** Gestión de auditoría temporal (DateTimeImmutable) */
    use TimestampTrait;

    /* ======================================================
     * RELACIONES DE NEGOCIO (UUID - BINARY 16)
     * ====================================================== */

    #[ORM\ManyToOne(targetEntity: PmsEstablecimiento::class, inversedBy: 'unidades')]
    #[ORM\JoinColumn(name: 'establecimiento_id', referencedColumnName: 'id', nullable: false, columnDefinition: 'BINARY(16)')]
    private ?PmsEstablecimiento $establecimiento = null;

    /* ======================================================
     * PROPIEDADES BÁSICAS
     * ====================================================== */

    #[ORM\Column(type: 'string', length: 150, nullable: true)]
    private ?string $nombre = null;

    #[ORM\Column(type: 'string', length: 100, nullable: true)]
    private ?string $codigoInterno = null;

    #[ORM\Column(type: 'smallint', nullable: true)]
    private ?int $capacidad = null;

    #[ORM\Column(type: 'boolean', options: ['default' => true])]
    private bool $activo = true;

    /* ======================================================
     * TARIFA BASE (fallback cuando no hay rangos ganadores)
     * ====================================================== */

    #[ORM\Column(type: 'decimal', precision: 10, scale: 2, nullable: false, options: ['default' => '0.00'])]
    private string $tarifaBasePrecio = '0.00';

    #[ORM\Column(type: 'smallint', options: ['default' => 2], nullable: false)]
    private int $tarifaBaseMinStay = 2;

    /**
     * Moneda base: Mapeada a ID Natural String(3)
     */
    #[ORM\ManyToOne(targetEntity: MaestroMoneda::class)]
    #[ORM\JoinColumn(name: 'tarifa_base_moneda_id', referencedColumnName: 'id', nullable: false)]
    private ?MaestroMoneda $tarifaBaseMoneda = null;

    #[ORM\Column(type: 'boolean', options: ['default' => true])]
    private bool $tarifaBaseActiva = true;

    /* ======================================================
     * COLECCIONES Y RELACIONES (BEDS24 & QUEUES)
     * ====================================================== */

    /** @var Collection<int, PmsUnidadBeds24Map> */
    #[ORM\OneToMany(mappedBy: 'pmsUnidad', targetEntity: PmsUnidadBeds24Map::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $beds24Maps;

    /** @var Collection<int, PmsRatesPushQueue> */
    #[ORM\OneToMany(mappedBy: 'unidad', targetEntity: PmsRatesPushQueue::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $tarifaQueues;

    /** @var Collection<int, PmsBookingsPullQueue> */
    #[ORM\ManyToMany(targetEntity: PmsBookingsPullQueue::class, mappedBy: 'unidades')]
    private Collection $pullQueueJobs;

    public function __construct()
    {
        $this->beds24Maps = new ArrayCollection();
        $this->tarifaQueues = new ArrayCollection();
        $this->pullQueueJobs = new ArrayCollection();
    }

    /* ======================================================
     * GETTERS Y SETTERS EXPLÍCITOS
     * ====================================================== */

    public function getEstablecimiento(): ?PmsEstablecimiento { return $this->establecimiento; }
    public function setEstablecimiento(?PmsEstablecimiento $val): self { $this->establecimiento = $val; return $this; }

    public function getNombre(): ?string { return $this->nombre; }
    public function setNombre(?string $val): self { $this->nombre = $val; return $this; }

    public function getCodigoInterno(): ?string { return $this->codigoInterno; }
    public function setCodigoInterno(?string $val): self { $this->codigoInterno = $val; return $this; }

    public function getCapacidad(): ?int { return $this->capacidad; }
    public function setCapacidad(?int $val): self { $this->capacidad = $val; return $this; }

    public function isActivo(): bool { return $this->activo; }
    public function setActivo(bool $val): self { $this->activo = $val; return $this; }

    public function getTarifaBasePrecio(): string { return $this->tarifaBasePrecio; }
    public function setTarifaBasePrecio(string $val): self { $this->tarifaBasePrecio = $val; return $this; }

    public function getTarifaBaseMinStay(): int { return $this->tarifaBaseMinStay; }
    public function setTarifaBaseMinStay(int $val): self { $this->tarifaBaseMinStay = $val; return $this; }

    public function getTarifaBaseMoneda(): ?MaestroMoneda { return $this->tarifaBaseMoneda; }
    public function setTarifaBaseMoneda(?MaestroMoneda $val): self { $this->tarifaBaseMoneda = $val; return $this; }

    public function isTarifaBaseActiva(): bool { return $this->tarifaBaseActiva; }
    public function setTarifaBaseActiva(bool $val): self { $this->tarifaBaseActiva = $val; return $this; }

    /* ======================================================
     * GESTIÓN DE COLECCIONES (BEDS24 MAPS)
     * ====================================================== */

    /** @return Collection<int, PmsUnidadBeds24Map> */
    public function getBeds24Maps(): Collection { return $this->beds24Maps; }

    public function addBeds24Map(PmsUnidadBeds24Map $map): self
    {
        if (!$this->beds24Maps->contains($map)) {
            $this->beds24Maps->add($map);
            $map->setPmsUnidad($this);
        }
        return $this;
    }

    public function removeBeds24Map(PmsUnidadBeds24Map $map): self
    {
        if ($this->beds24Maps->removeElement($map)) {
            if ($map->getPmsUnidad() === $this) {
                $map->setPmsUnidad(null);
            }
        }
        return $this;
    }

    /* ======================================================
     * GESTIÓN DE COLECCIONES (RATES PUSH QUEUE)
     * ====================================================== */

    /** @return Collection<int, PmsRatesPushQueue> */
    public function getTarifaQueues(): Collection { return $this->tarifaQueues; }

    public function addTarifaQueue(PmsRatesPushQueue $queue): self
    {
        if (!$this->tarifaQueues->contains($queue)) {
            $this->tarifaQueues->add($queue);
            $queue->setUnidad($this);
        }
        return $this;
    }

    public function removeTarifaQueue(PmsRatesPushQueue $queue): self
    {
        if ($this->tarifaQueues->removeElement($queue)) {
            if ($queue->getUnidad() === $this) {
                $queue->setUnidad(null);
            }
        }
        return $this;
    }

    /* ======================================================
     * GESTIÓN DE COLECCIONES (BOOKINGS PULL QUEUE)
     * ====================================================== */

    /** @return Collection<int, PmsBookingsPullQueue> */
    public function getPullQueueJobs(): Collection { return $this->pullQueueJobs; }

    public function addPullQueueJob(PmsBookingsPullQueue $job): self
    {
        if (!$this->pullQueueJobs->contains($job)) {
            $this->pullQueueJobs->add($job);
            $job->addUnidad($this); // Relación ManyToMany
        }
        return $this;
    }

    public function removePullQueueJob(PmsBookingsPullQueue $job): self
    {
        if ($this->pullQueueJobs->removeElement($job)) {
            $job->removeUnidad($this);
        }
        return $this;
    }

    /* ======================================================
     * UTILIDADES
     * ====================================================== */

    public function __toString(): string
    {
        return $this->nombre ?? ('Unidad UUID ' . ($this->getId() ? $this->getId()->toBase32() : 'Nueva'));
    }

    /**
     * Helper para obtener el mapeo principal de Beds24
     */
    public function getBeds24MapPrincipal(): ?PmsUnidadBeds24Map
    {
        foreach ($this->beds24Maps as $map) {
            if ($map->isEsPrincipal()) {
                return $map;
            }
        }
        return $this->beds24Maps->first() ?: null;
    }
}