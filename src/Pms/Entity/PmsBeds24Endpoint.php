<?php
declare(strict_types=1);

namespace App\Pms\Entity;

use App\Exchange\Service\Contract\EndpointInterface;
use App\Pms\Repository\PmsBeds24EndpointRepository;
use DateTimeInterface;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Gedmo\Mapping\Annotation as Gedmo;

#[ORM\Entity(repositoryClass: PmsBeds24EndpointRepository::class)]
#[ORM\Table(name: 'pms_beds24_endpoint')]
class PmsBeds24Endpoint implements EndpointInterface
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /**
     * Nombre lógico de la acción (ej: 'GET_BOOKINGS', 'CALENDAR_POST').
     */
    #[ORM\Column(type: 'string', length: 50, unique: true)]
    private ?string $accion = null;

    /**
     * Path relativo del endpoint (ej: '/bookings', '/inventory/calendar').
     */
    #[ORM\Column(type: 'string', length: 255)]
    private ?string $endpoint = null;

    /**
     * Método HTTP (ej: 'GET', 'POST', 'DELETE').
     */
    #[ORM\Column(type: 'string', length: 10)]
    private ?string $metodo = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $descripcion = null;

    #[ORM\Column(type: 'boolean', options: ['default' => true])]
    private ?bool $activo = true;

    #[Gedmo\Timestampable(on: 'create')]
    #[ORM\Column(type: 'datetime')]
    private ?DateTimeInterface $created = null;

    #[Gedmo\Timestampable(on: 'update')]
    #[ORM\Column(type: 'datetime')]
    private ?DateTimeInterface $updated = null;

    /**
     * Relación con la cola de Reservas (Push)
     * @var Collection<int, PmsBookingsPushQueue>
     */
    #[ORM\OneToMany(mappedBy: 'endpoint', targetEntity: PmsBookingsPushQueue::class)]
    private Collection $queues;

    /**
     * Relación con la cola de Tarifas (Push - Entidad Plana)
     * ✅ AGREGADO para resolver error de validación
     * @var Collection<int, PmsRatesPushQueue>
     */
    #[ORM\OneToMany(mappedBy: 'endpoint', targetEntity: PmsRatesPushQueue::class)]
    private Collection $ratesQueues;

    /**
     * Relación con los procesos de Pull
     * ✅ AGREGADO para resolver error de validación
     * @var Collection<int, PmsBookingsPullQueue>
     */
    #[ORM\OneToMany(mappedBy: 'endpoint', targetEntity: PmsBookingsPullQueue::class)]
    private Collection $pullQueueJobs;

    public function __construct()
    {
        $this->queues = new ArrayCollection();
        $this->ratesQueues = new ArrayCollection();
        $this->pullQueueJobs = new ArrayCollection();
    }

    // --- IMPLEMENTACIÓN EndpointInterface ---

    public function getEndpoint(): ?string
    {
        return $this->endpoint;
    }

    public function getMetodo(): ?string
    {
        return $this->metodo;
    }

    // --- GETTERS Y SETTERS ---

    public function getId(): ?int { return $this->id; }

    public function getAccion(): ?string { return $this->accion; }
    public function setAccion(?string $accion): self { $this->accion = $accion; return $this; }

    public function setEndpoint(?string $endpoint): self { $this->endpoint = $endpoint; return $this; }

    public function setMetodo(?string $metodo): self { $this->metodo = $metodo; return $this; }

    public function getDescripcion(): ?string { return $this->descripcion; }
    public function setDescripcion(?string $descripcion): self { $this->descripcion = $descripcion; return $this; }

    public function isActivo(): ?bool { return $this->activo; }
    public function setActivo(?bool $activo): self { $this->activo = $activo; return $this; }

    public function getCreated(): ?DateTimeInterface { return $this->created; }
    public function getUpdated(): ?DateTimeInterface { return $this->updated; }

    // --- GESTIÓN DE BOOKINGS PUSH QUEUE ---

    /** @return Collection<int, PmsBookingsPushQueue> */
    public function getQueues(): Collection { return $this->queues; }

    public function addQueue(PmsBookingsPushQueue $queue): self
    {
        if (!$this->queues->contains($queue)) {
            $this->queues->add($queue);
            $queue->setEndpoint($this);
        }
        return $this;
    }

    public function removeQueue(PmsBookingsPushQueue $queue): self
    {
        if ($this->queues->removeElement($queue)) {
            if ($queue->getEndpoint() === $this) {
                $queue->setEndpoint(null);
            }
        }
        return $this;
    }

    // --- GESTIÓN DE RATES PUSH QUEUE ---

    /** @return Collection<int, PmsRatesPushQueue> */
    public function getRatesQueues(): Collection { return $this->ratesQueues; }

    public function addRatesQueue(PmsRatesPushQueue $ratesQueue): self
    {
        if (!$this->ratesQueues->contains($ratesQueue)) {
            $this->ratesQueues->add($ratesQueue);
            $ratesQueue->setEndpoint($this);
        }
        return $this;
    }

    public function removeRatesQueue(PmsRatesPushQueue $ratesQueue): self
    {
        if ($this->ratesQueues->removeElement($ratesQueue)) {
            if ($ratesQueue->getEndpoint() === $this) {
                $ratesQueue->setEndpoint(null);
            }
        }
        return $this;
    }

    // --- GESTIÓN DE BOOKINGS PULL QUEUE ---

    /** @return Collection<int, PmsBookingsPullQueue> */
    public function getPullQueueJobs(): Collection { return $this->pullQueueJobs; }

    public function addPullQueueJob(PmsBookingsPullQueue $pullJob): self
    {
        if (!$this->pullQueueJobs->contains($pullJob)) {
            $this->pullQueueJobs->add($pullJob);
            $pullJob->setEndpoint($this);
        }
        return $this;
    }

    public function removePullQueueJob(PmsBookingsPullQueue $pullJob): self
    {
        if ($this->pullQueueJobs->removeElement($pullJob)) {
            if ($pullJob->getEndpoint() === $this) {
                $pullJob->setEndpoint(null);
            }
        }
        return $this;
    }

    public function __toString(): string
    {
        return $this->accion ?? ('Endpoint #' . $this->id);
    }
}