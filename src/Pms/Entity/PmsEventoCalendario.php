<?php

namespace App\Pms\Entity;

use DateTimeInterface;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Gedmo\Mapping\Annotation as Gedmo;

#[ORM\Entity]
#[ORM\Table(name: 'pms_evento_calendario')]
class PmsEventoCalendario
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: PmsUnidad::class)]
    #[ORM\JoinColumn(nullable: false)]
    private ?PmsUnidad $pmsUnidad = null;

    #[ORM\ManyToOne(targetEntity: PmsReserva::class)]
    #[ORM\JoinColumn(nullable: true)]
    private ?PmsReserva $reserva = null;

    #[ORM\ManyToOne(targetEntity: PmsEventoCalendarioTipo::class)]
    #[ORM\JoinColumn(nullable: true)]
    private ?PmsEventoCalendarioTipo $tipo = null;

    #[ORM\Column(type: 'datetime')]
    private ?DateTimeInterface $inicio = null;

    #[ORM\Column(type: 'datetime')]
    private ?DateTimeInterface $fin = null;

    #[ORM\Column(type: 'string', length: 180, nullable: true)]
    private ?string $tituloCache = null;

    #[ORM\Column(type: 'string', length: 50, nullable: true)]
    private ?string $origenCache = null;

    /**
     * @var Collection<int, PmsEventoCalendarioQueue>
     */
    #[ORM\OneToMany(
        mappedBy: 'evento',
        targetEntity: PmsEventoCalendarioQueue::class,
        cascade: ['persist', 'remove'],
        orphanRemoval: true
    )]
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

    public function getPmsUnidad(): ?PmsUnidad
    {
        return $this->pmsUnidad;
    }

    public function setPmsUnidad(?PmsUnidad $pmsUnidad): self
    {
        $this->pmsUnidad = $pmsUnidad;

        return $this;
    }

    public function getReserva(): ?PmsReserva
    {
        return $this->reserva;
    }

    public function setReserva(?PmsReserva $reserva): self
    {
        $this->reserva = $reserva;

        return $this;
    }

    public function getTipo(): ?PmsEventoCalendarioTipo
    {
        return $this->tipo;
    }

    public function setTipo(?PmsEventoCalendarioTipo $tipo): self
    {
        $this->tipo = $tipo;

        return $this;
    }

    public function getInicio(): ?DateTimeInterface
    {
        return $this->inicio;
    }

    public function setInicio(?DateTimeInterface $inicio): self
    {
        $this->inicio = $inicio;

        return $this;
    }

    public function getFin(): ?DateTimeInterface
    {
        return $this->fin;
    }

    public function setFin(?DateTimeInterface $fin): self
    {
        $this->fin = $fin;

        return $this;
    }

    public function getTituloCache(): ?string
    {
        return $this->tituloCache;
    }

    public function setTituloCache(?string $tituloCache): self
    {
        $this->tituloCache = $tituloCache;

        return $this;
    }

    public function getOrigenCache(): ?string
    {
        return $this->origenCache;
    }

    public function setOrigenCache(?string $origenCache): self
    {
        $this->origenCache = $origenCache;

        return $this;
    }

    /**
     * @return Collection<int, PmsEventoCalendarioQueue>
     */
    public function getQueues(): Collection
    {
        return $this->queues;
    }

    public function addQueue(PmsEventoCalendarioQueue $queue): self
    {
        if (!$this->queues->contains($queue)) {
            $this->queues->add($queue);
            $queue->setEvento($this);
        }

        return $this;
    }

    public function removeQueue(PmsEventoCalendarioQueue $queue): self
    {
        if ($this->queues->removeElement($queue)) {
            if ($queue->getEvento() === $this) {
                $queue->setEvento(null);
            }
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
        if ($this->tituloCache) {
            return $this->tituloCache;
        }

        $inicio = $this->inicio?->format('Y-m-d') ?? 'sin fecha';
        $unidad = $this->pmsUnidad?->getNombre() ?? 'Unidad';

        return $unidad . ' - ' . $inicio;
    }
}
