<?php
namespace App\Pms\Entity;

use App\Entity\MaestroMoneda;
use App\Pms\Repository\PmsTarifaRangoRepository;
use DateTimeInterface;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Gedmo\Mapping\Annotation as Gedmo;

#[ORM\Entity(repositoryClass: PmsTarifaRangoRepository::class)]
#[ORM\Table(name: 'pms_tarifa_rango')]
class PmsTarifaRango {
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: PmsUnidad::class)]
    #[ORM\JoinColumn(nullable: false)]
    private ?PmsUnidad $unidad = null;

    #[ORM\Column(type: 'date')]
    private ?DateTimeInterface $fechaInicio = null;

    #[ORM\Column(type: 'date')]
    private ?DateTimeInterface $fechaFin = null;

    #[ORM\Column(type: 'decimal', precision: 10, scale: 2)]
    private ?string $precio = null;

    #[ORM\ManyToOne(targetEntity: MaestroMoneda::class)]
    #[ORM\JoinColumn(nullable: false)]
    private ?MaestroMoneda $moneda = null;

    #[ORM\Column(type: 'integer', options: ['default' => 2])]
    private ?int $minStay = 2;

    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private ?bool $importante = null;

    #[ORM\Column(type: 'integer', options: ['default' => 0])]
    private ?int $peso = 0;

    #[ORM\Column(type: 'boolean', options: ['default' => true])]
    private ?bool $activo = true;

    #[ORM\OneToMany(mappedBy: 'tarifaRango', targetEntity: PmsRatesPushQueue::class, orphanRemoval: true)]
    private Collection $queues;

    #[Gedmo\Timestampable(on: 'create')]
    #[ORM\Column(type: 'datetime')]
    private ?DateTimeInterface $created = null;

    #[Gedmo\Timestampable(on: 'update')]
    #[ORM\Column(type: 'datetime')]
    private ?DateTimeInterface $updated = null;

    public function __construct()
    {
        $this->queues = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUnidad(): ?PmsUnidad
    {
        return $this->unidad;
    }

    public function setUnidad(?PmsUnidad $unidad): self
    {
        $this->unidad = $unidad;
        return $this;
    }

    public function getFechaInicio(): ?DateTimeInterface
    {
        return $this->fechaInicio;
    }

    public function setFechaInicio(?DateTimeInterface $fechaInicio): self
    {
        $this->fechaInicio = $fechaInicio;
        return $this;
    }

    public function getFechaFin(): ?DateTimeInterface
    {
        return $this->fechaFin;
    }

    public function setFechaFin(?DateTimeInterface $fechaFin): self
    {
        $this->fechaFin = $fechaFin;
        return $this;
    }

    public function getPrecio(): ?string
    {
        return $this->precio;
    }

    public function setPrecio(?string $precio): self
    {
        $this->precio = $precio;
        return $this;
    }

    public function getMoneda(): ?MaestroMoneda
    {
        return $this->moneda;
    }

    public function setMoneda(?MaestroMoneda $moneda): self
    {
        $this->moneda = $moneda;
        return $this;
    }

    public function getMinStay(): ?int
    {
        return $this->minStay;
    }
    public function setMinStay(?int $minStay): self
    {
        $this->minStay = $minStay;
        return $this;
    }

    public function getQueues(): Collection
    {
        return $this->queues;
    }

    public function addQueue(PmsRatesPushQueue $queue): self
    {
        if (!$this->queues->contains($queue)) {
            $this->queues->add($queue);
            $queue->setTarifaRango($this);
        }

        return $this;
    }

    public function removeQueue(PmsRatesPushQueue $queue): self
    {
        if ($this->queues->removeElement($queue)) {
            // owning side handled by orphanRemoval
        }

        return $this;
    }

    public function isImportante(): ?bool
    {
        return $this->importante;
    }

    public function setImportante(?bool $importante): self
    {
        $this->importante = $importante;
        return $this;
    }

    public function getPeso(): ?int
    {
        return $this->peso;
    }

    public function setPeso(?int $peso): self
    {
        $this->peso = $peso;
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
        $unidad = $this->unidad?->getNombre() ?? 'Unidad';
        $inicio = $this->fechaInicio?->format('Y-m-d') ?? 'sin inicio';
        $fin = $this->fechaFin?->format('Y-m-d') ?? 'sin fin';

        return $unidad . ' (' . $inicio . ' → ' . $fin . ')';
    }
}