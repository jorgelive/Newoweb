<?php

namespace App\Pms\Entity;

use DateTimeInterface;
use Doctrine\ORM\Mapping as ORM;
use Gedmo\Mapping\Annotation as Gedmo;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use App\Pms\Entity\PmsTarifaQueue;
use App\Pms\Entity\PmsPullQueueJob;
use App\Entity\MaestroMoneda;

#[ORM\Entity]
#[ORM\Table(name: 'pms_unidad')]
class PmsUnidad
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: PmsEstablecimiento::class)]
    #[ORM\JoinColumn(nullable: false)]
    private ?PmsEstablecimiento $establecimiento = null;

    #[ORM\Column(type: 'string', length: 150, nullable: true)]
    private ?string $nombre = null;

    #[ORM\Column(type: 'string', length: 100, nullable: true)]
    private ?string $codigoInterno = null;

    #[ORM\Column(type: 'smallint', nullable: true)]
    private ?int $capacidad = null;

    #[ORM\Column(type: 'boolean', options: ['default' => true])]
    private ?bool $activo = null;

    // --- TARIFA BASE (fallback cuando no hay rangos ganadores) ---

    #[ORM\Column(type: 'decimal', precision: 10, scale: 2, nullable: true)]
    private ?string $tarifaBasePrecio = null;

    #[ORM\Column(type: 'smallint', options: ['default' => 2], nullable: true)]
    private ?int $tarifaBaseMinStay = 2;

    #[ORM\ManyToOne(targetEntity: MaestroMoneda::class)]
    #[ORM\JoinColumn(nullable: true)]
    private ?MaestroMoneda $tarifaBaseMoneda = null;

    #[ORM\Column(type: 'boolean', options: ['default' => true], nullable: true)]
    private ?bool $tarifaBaseActiva = true;

    #[ORM\OneToMany(mappedBy: 'pmsUnidad', targetEntity: PmsUnidadBeds24Map::class, orphanRemoval: true)]
    private Collection $beds24Maps;

    #[ORM\OneToMany(mappedBy: 'unidad', targetEntity: PmsTarifaQueue::class, orphanRemoval: true)]
    private Collection $tarifaQueues;

    #[ORM\ManyToMany(targetEntity: PmsPullQueueJob::class, mappedBy: 'unidades')]
    private Collection $pullQueueJobs;

    #[Gedmo\Timestampable(on: 'create')]
    #[ORM\Column(type: 'datetime')]
    private ?DateTimeInterface $created = null;

    public function __construct()
    {
        $this->beds24Maps = new ArrayCollection();
        $this->tarifaQueues = new ArrayCollection();
        $this->pullQueueJobs = new ArrayCollection();
        // Ensure tarifa base fields are not null at runtime
        $this->tarifaBasePrecio = $this->tarifaBasePrecio ?? '0.00';
        $this->tarifaBaseMinStay = $this->tarifaBaseMinStay ?? 2;
        $this->tarifaBaseActiva = $this->tarifaBaseActiva ?? true;
    }


    #[Gedmo\Timestampable(on: 'update')]
    #[ORM\Column(type: 'datetime')]
    private ?DateTimeInterface $updated = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getEstablecimiento(): ?PmsEstablecimiento
    {
        return $this->establecimiento;
    }

    public function setEstablecimiento(?PmsEstablecimiento $establecimiento): self
    {
        $this->establecimiento = $establecimiento;

        return $this;
    }

    public function getNombre(): ?string
    {
        return $this->nombre;
    }

    public function setNombre(?string $nombre): self
    {
        $this->nombre = $nombre;

        return $this;
    }

    public function getCodigoInterno(): ?string
    {
        return $this->codigoInterno;
    }

    public function setCodigoInterno(?string $codigoInterno): self
    {
        $this->codigoInterno = $codigoInterno;

        return $this;
    }

    public function getCapacidad(): ?int
    {
        return $this->capacidad;
    }

    public function setCapacidad(?int $capacidad): self
    {
        $this->capacidad = $capacidad;

        return $this;
    }

    public function isActivo(): ?bool
    {
        return $this->activo;
    }

    public function setActivo(?bool $activo): self
    {
        $this->activo = $activo;

        return $this;
    }

    public function getTarifaBasePrecio(): ?string
    {
        return $this->tarifaBasePrecio ?? '0.00';
    }

    public function setTarifaBasePrecio(?string $tarifaBasePrecio): self
    {
        $this->tarifaBasePrecio = $tarifaBasePrecio;
        return $this;
    }

    public function getTarifaBaseMinStay(): ?int
    {
        return $this->tarifaBaseMinStay ?? 2;
    }

    public function setTarifaBaseMinStay(?int $tarifaBaseMinStay): self
    {
        $this->tarifaBaseMinStay = $tarifaBaseMinStay;
        return $this;
    }

    public function getTarifaBaseMoneda(): ?MaestroMoneda
    {
        // May return null if not set; use getTarifaBaseMonedaOrFail() for a guaranteed value.
        return $this->tarifaBaseMoneda;
    }

    public function getTarifaBaseMonedaOrFail(): MaestroMoneda
    {
        if ($this->tarifaBaseMoneda === null) {
            throw new \LogicException('Tarifa base activa sin moneda definida en la unidad #' . ($this->id ?? 'new'));
        }
        return $this->tarifaBaseMoneda;
    }

    public function setTarifaBaseMoneda(?MaestroMoneda $tarifaBaseMoneda): self
    {
        $this->tarifaBaseMoneda = $tarifaBaseMoneda;
        return $this;
    }

    public function isTarifaBaseActiva(): ?bool
    {
        return $this->tarifaBaseActiva ?? true;
    }

    public function setTarifaBaseActiva(?bool $tarifaBaseActiva): self
    {
        $this->tarifaBaseActiva = $tarifaBaseActiva;
        return $this;
    }

    public function getBeds24Maps(): Collection
    {
        return $this->beds24Maps;
    }

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
            // owning side handled by orphanRemoval
        }

        return $this;
    }

    public function getCreated(): ?DateTimeInterface
    {
        return $this->created;
    }

    public function getUpdated(): ?DateTimeInterface
    {
        return $this->updated;
    }

    public function __toString(): string
    {
        return $this->nombre ?? ('Unidad #' . $this->id);
    }

    public function getTarifaQueues(): Collection
    {
        return $this->tarifaQueues;
    }

    public function addTarifaQueue(PmsTarifaQueue $queue): self
    {
        if (!$this->tarifaQueues->contains($queue)) {
            $this->tarifaQueues->add($queue);
            $queue->setUnidad($this);
        }

        return $this;
    }

    public function removeTarifaQueue(PmsTarifaQueue $queue): self
    {
        if ($this->tarifaQueues->removeElement($queue)) {
            // owning side handled by orphanRemoval
        }

        return $this;
    }

    public function getPullQueueJobs(): Collection
    {
        return $this->pullQueueJobs;
    }

    public function addPullQueueJob(PmsPullQueueJob $job): self
    {
        if (!$this->pullQueueJobs->contains($job)) {
            $this->pullQueueJobs->add($job);
            $job->addUnidad($this);
        }

        return $this;
    }

    public function removePullQueueJob(PmsPullQueueJob $job): self
    {
        if ($this->pullQueueJobs->removeElement($job)) {
            // owning side handled by PmsPullQueueJob
        }

        return $this;
    }
}