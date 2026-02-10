<?php

declare(strict_types=1);

namespace App\Pms\Entity;

use App\Entity\Trait\IdTrait;
use App\Entity\Trait\TimestampTrait;
use App\Entity\Maestro\MaestroMoneda;
use App\Pms\Repository\PmsTarifaRangoRepository;
use DateTimeInterface;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * Entidad PmsTarifaRango.
 * Define precios y estancias mínimas por unidad y rango de fechas.
 * IDs: UUID (Propio/Negocio), String 3 (Moneda).
 */
#[ORM\Entity(repositoryClass: PmsTarifaRangoRepository::class)]
#[ORM\Table(name: 'pms_tarifa_rango')]
#[ORM\HasLifecycleCallbacks]
class PmsTarifaRango
{
    /** Gestión de Identificador UUID (BINARY 16) */
    use IdTrait;

    /** Gestión de auditoría temporal (DateTimeImmutable) */
    use TimestampTrait;

    /* ======================================================
     * RELACIONES DE NEGOCIO (UUID - BINARY 16)
     * ====================================================== */

    #[ORM\ManyToOne(targetEntity: PmsUnidad::class)]
    #[ORM\JoinColumn(
        name: 'unidad_id',
        referencedColumnName: 'id',
        nullable: false,
        columnDefinition: 'BINARY(16) COMMENT "(DC2Type:uuid)"'
    )]
    private ?PmsUnidad $unidad = null;

    /* ======================================================
     * DATOS DE TARIFA Y RANGO
     * ====================================================== */

    #[ORM\Column(type: 'date')]
    private ?DateTimeInterface $fechaInicio = null;

    #[ORM\Column(type: 'date')]
    private ?DateTimeInterface $fechaFin = null;

    #[ORM\Column(type: 'decimal', precision: 10, scale: 2)]
    private ?string $precio = null;

    /**
     * Moneda: ID Natural (String 3)
     * SE ELIMINA BINARY(16)
     */
    #[ORM\ManyToOne(targetEntity: MaestroMoneda::class)]
    #[ORM\JoinColumn(name: 'moneda_id', referencedColumnName: 'id', nullable: false)]
    private ?MaestroMoneda $moneda = null;

    #[ORM\Column(type: 'integer', options: ['default' => 2])]
    private int $minStay = 2;

    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private bool $importante = false;

    #[ORM\Column(type: 'integer', options: ['default' => 0])]
    private int $prioridad = 0;

    #[ORM\Column(type: 'boolean', options: ['default' => true])]
    private bool $activo = true;

    /* ======================================================
     * RELACIONES TÉCNICAS (COLAS PUSH)
     * ====================================================== */

    /** @var Collection<int, PmsRatesPushQueue> */
    #[ORM\OneToMany(mappedBy: 'tarifaRango', targetEntity: PmsRatesPushQueue::class, orphanRemoval: true)]
    private Collection $queues;

    public function __construct()
    {
        $this->queues = new ArrayCollection();

        $this->id = Uuid::v7();
    }

    /* ======================================================
     * GETTERS Y SETTERS
     * ====================================================== */

    public function getUnidad(): ?PmsUnidad { return $this->unidad; }
    public function setUnidad(?PmsUnidad $unidad): self { $this->unidad = $unidad; return $this; }

    public function getFechaInicio(): ?DateTimeInterface { return $this->fechaInicio; }
    public function setFechaInicio(?DateTimeInterface $fechaInicio): self { $this->fechaInicio = $fechaInicio; return $this; }

    public function getFechaFin(): ?DateTimeInterface { return $this->fechaFin; }
    public function setFechaFin(?DateTimeInterface $fechaFin): self { $this->fechaFin = $fechaFin; return $this; }

    public function getPrecio(): ?string { return $this->precio; }
    public function setPrecio(?string $precio): self { $this->precio = $precio; return $this; }

    public function getMoneda(): ?MaestroMoneda { return $this->moneda; }
    public function setMoneda(?MaestroMoneda $moneda): self { $this->moneda = $moneda; return $this; }

    public function getMinStay(): int { return $this->minStay; }
    public function setMinStay(int $minStay): self { $this->minStay = $minStay; return $this; }

    public function isImportante(): bool { return $this->importante; }
    public function setImportante(bool $importante): self { $this->importante = $importante; return $this; }

    public function getPrioridad(): int { return $this->prioridad; }
    public function setPrioridad(int $prioridad): self { $this->prioridad = $prioridad; return $this; }

    public function isActivo(): bool { return $this->activo; }
    public function setActivo(bool $activo): self { $this->activo = $activo; return $this; }

    /** @return Collection<int, PmsRatesPushQueue> */
    public function getQueues(): Collection { return $this->queues; }

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
            if ($queue->getTarifaRango() === $this) {
                $queue->setTarifaRango(null);
            }
        }
        return $this;
    }

    public function __toString(): string
    {
        $unidad = $this->unidad ? $this->unidad->getNombre() : 'Unidad';
        $inicio = $this->fechaInicio ? $this->fechaInicio->format('Y-m-d') : '...';
        $fin = $this->fechaFin ? $this->fechaFin->format('Y-m-d') : '...';

        return sprintf('%s (%s a %s)', $unidad, $inicio, $fin);
    }
}