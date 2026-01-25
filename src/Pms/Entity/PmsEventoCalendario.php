<?php
declare(strict_types=1);

namespace App\Pms\Entity;

use DateTimeInterface;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Gedmo\Mapping\Annotation as Gedmo;
use Symfony\Component\Validator\Constraints as Assert;
use App\Pms\Entity\PmsBookingsPushQueue;

#[ORM\Entity]
#[ORM\Table(name: 'pms_evento_calendario')]
class PmsEventoCalendario
{
    private const ESTADOS_BORRABLES_CON_ID = [
        PmsEventoEstado::CODIGO_CANCELADA,
        PmsEventoEstado::CODIGO_BLOQUEO,
    ];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: PmsUnidad::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[Assert\NotNull(message: "La unidad es obligatoria y no puede quedar nula.")]
    private ?PmsUnidad $pmsUnidad = null;

    #[ORM\ManyToOne(targetEntity: PmsReserva::class, inversedBy: 'eventosCalendario')]
    #[ORM\JoinColumn(nullable: true)]
    private ?PmsReserva $reserva = null;

    #[ORM\Column(type: 'datetime')]
    private ?DateTimeInterface $inicio = null;

    #[ORM\Column(type: 'datetime')]
    private ?DateTimeInterface $fin = null;

    #[ORM\Column(type: 'string', length: 50, nullable: true)]
    private ?string $descripcion = null;

    #[ORM\OneToMany(
        mappedBy: 'evento',
        targetEntity: PmsEventoBeds24Link::class,
        cascade: ['persist', 'remove'],
        orphanRemoval: true
    )]
    private Collection $beds24Links;

    #[ORM\Column(type: 'string', length: 50, nullable: true)]
    private ?string $estadoBeds24 = null;

    #[ORM\Column(type: 'string', length: 50, nullable: true)]
    private ?string $subestadoBeds24 = null;

    #[ORM\ManyToOne(targetEntity: PmsEventoEstado::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[Assert\NotNull(message: "El estado es obligatorio.")]
    private ?PmsEventoEstado $estado = null;

    #[ORM\ManyToOne(targetEntity: PmsEventoEstadoPago::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[Assert\NotNull(message: "El estado de pago es obligatorio.")]
    private ?PmsEventoEstadoPago $estadoPago = null;

    #[ORM\Column(type: 'integer', nullable: false, options: ['default' => 0])]
    #[Assert\NotNull(message: "Debes indicar la cantidad de adultos.")]
    #[Assert\PositiveOrZero(message: "La cantidad no puede ser negativa.")]
    private ?int $cantidadAdultos = 0;

    #[ORM\Column(type: 'integer', nullable: false, options: ['default' => 0])]
    #[Assert\NotNull(message: "Debes indicar la cantidad de niños.")]
    #[Assert\PositiveOrZero(message: "La cantidad no puede ser negativa.")]
    private ?int $cantidadNinos = 0;

    #[ORM\Column(type: 'decimal', precision: 10, scale: 2, options: ['default' => '0.00'])]
    #[Assert\NotBlank(message: "El monto es obligatorio.")]
    private ?string $monto = '0.00';

    #[ORM\Column(type: 'decimal', precision: 10, scale: 2, nullable: true)]
    private ?string $comision = '0.00';

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $rateDescription = null;

    #[ORM\Column(type: 'string', length: 180, nullable: true)]
    private ?string $tituloCache = null;

    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private bool $isOta = false;

    #[ORM\OneToMany(
        mappedBy: 'evento',
        targetEntity: PmsEventAssignment::class,
        cascade: ['persist', 'remove'],
        orphanRemoval: true
    )]
    private Collection $assignments;

    #[Gedmo\Timestampable(on: 'create')]
    #[ORM\Column(type: 'datetime')]
    private ?DateTimeInterface $created = null;

    #[Gedmo\Timestampable(on: 'update')]
    #[ORM\Column(type: 'datetime')]
    private ?DateTimeInterface $updated = null;

    public function __construct()
    {
        $this->beds24Links = new ArrayCollection();
        $this->assignments = new ArrayCollection();
    }

    // --- GETTERS & SETTERS COMPLETOS ---

    public function getId(): ?int { return $this->id; }

    public function getPmsUnidad(): ?PmsUnidad { return $this->pmsUnidad; }
    public function setPmsUnidad(?PmsUnidad $pmsUnidad): self { $this->pmsUnidad = $pmsUnidad; return $this; }

    public function getReserva(): ?PmsReserva { return $this->reserva; }
    public function setReserva(?PmsReserva $reserva): self { $this->reserva = $reserva; return $this; }

    public function getInicio(): ?DateTimeInterface { return $this->inicio; }
    public function setInicio(?DateTimeInterface $inicio): self { $this->inicio = $inicio; return $this; }

    public function getFin(): ?DateTimeInterface { return $this->fin; }
    public function setFin(?DateTimeInterface $fin): self { $this->fin = $fin; return $this; }

    public function getDescripcion(): ?string { return $this->descripcion; }
    public function setDescripcion(?string $descripcion): self { $this->descripcion = $descripcion; return $this; }

    public function getBeds24Links(): Collection { return $this->beds24Links; }
    public function addBeds24Link(PmsEventoBeds24Link $link): self {
        if (!$this->beds24Links->contains($link)) {
            $this->beds24Links->add($link);
            $link->setEvento($this);
        }
        return $this;
    }
    public function removeBeds24Link(PmsEventoBeds24Link $link): self {
        if ($this->beds24Links->removeElement($link)) {
            if ($link->getEvento() === $this) { $link->setEvento(null); }
        }
        return $this;
    }

    public function getEstadoBeds24(): ?string { return $this->estadoBeds24; }
    public function setEstadoBeds24(?string $estadoBeds24): self { $this->estadoBeds24 = $estadoBeds24; return $this; }

    public function getSubestadoBeds24(): ?string { return $this->subestadoBeds24; }
    public function setSubestadoBeds24(?string $subestadoBeds24): self { $this->subestadoBeds24 = $subestadoBeds24; return $this; }

    public function getEstado(): ?PmsEventoEstado { return $this->estado; }
    public function setEstado(?PmsEventoEstado $estado): self { $this->estado = $estado; return $this; }

    public function getEstadoPago(): ?PmsEventoEstadoPago { return $this->estadoPago; }
    public function setEstadoPago(?PmsEventoEstadoPago $estadoPago): self { $this->estadoPago = $estadoPago; return $this; }

    public function getCantidadAdultos(): ?int { return $this->cantidadAdultos; }
    public function setCantidadAdultos(?int $cantidadAdultos): self { $this->cantidadAdultos = $cantidadAdultos; return $this; }

    public function getCantidadNinos(): ?int { return $this->cantidadNinos; }
    public function setCantidadNinos(?int $cantidadNinos): self { $this->cantidadNinos = $cantidadNinos; return $this; }

    public function getMonto(): ?string { return $this->monto; }
    public function setMonto(?string $monto): self { $this->monto = $monto; return $this; }

    public function getComision(): ?string { return $this->comision; }
    public function setComision(?string $comision): self { $this->comision = $comision; return $this; }

    public function getRateDescription(): ?string { return $this->rateDescription; }
    public function setRateDescription(?string $rateDescription): self { $this->rateDescription = $rateDescription; return $this; }

    public function getTituloCache(): ?string { return $this->tituloCache; }
    public function setTituloCache(?string $tituloCache): self { $this->tituloCache = $tituloCache; return $this; }

    public function isOta(): bool { return $this->isOta; }
    public function setIsOta(bool $isOta): self { $this->isOta = $isOta; return $this; }

    public function getCreated(): ?DateTimeInterface { return $this->created; }
    public function getUpdated(): ?DateTimeInterface { return $this->updated; }

    public function getAssignments(): Collection
    {
        return $this->assignments;
    }

    public function addAssignment(PmsEventAssignment $assignment): self
    {
        if (!$this->assignments->contains($assignment)) {
            $this->assignments->add($assignment);
            $assignment->setEvento($this);
        }

        return $this;
    }

    public function removeAssignment(PmsEventAssignment $assignment): self
    {
        if ($this->assignments->removeElement($assignment)) {
            if ($assignment->getEvento() === $this) {
                $assignment->setEvento(null);
            }
        }

        return $this;
    }

    public function __toString(): string
    {
        if ($this->inicio && $this->fin && $this->pmsUnidad) {
            $label = sprintf('%s al %s | %s', $this->inicio->format('d/m'), $this->fin->format('d/m'), $this->pmsUnidad->getNombre());
            if ($this->descripcion) $label .= ' - ' . $this->descripcion;
            return $label;
        }
        return $this->tituloCache ?: 'Evento nuevo';
    }

    // --- MÉTODOS DE SINCRONIZACIÓN ---

    public function isSynced(): bool
    {
        // Si no tiene links, es local (nada que sincronizar)
        if ($this->beds24Links->isEmpty()) {
            return true;
        }

        foreach ($this->beds24Links as $link) {
            // Si cualquier cola está en estado "no final", NO está sincronizado
            foreach ($link->getQueues() as $queue) {
                if (!in_array($queue->getStatus(), [
                    PmsBookingsPushQueue::STATUS_SUCCESS,
                    PmsBookingsPushQueue::STATUS_CANCELLED,
                ], true)) {
                    return false;
                }
            }

            // Si NO tiene bookingId, solo significa "sin ID remoto aún",
            // pero igual ya validamos arriba que no haya nada pendiente/error.
            // Seguimos con el resto de links.
            if (null === $link->getBeds24BookId()) {
                continue;
            }

            // Si tiene bookingId, la misma regla ya se aplicó arriba (colas finalizadas).
            // No hace falta repetir.
        }

        return true;
    }

    /**
     * Determina si es seguro borrar el registro físicamente.
     * Permite borrar aunque haya ERROR, siempre que NO exista un ID remoto,
     * y que no haya colas en curso (pending/processing/locked).
     */
    public function isSafeToDelete(): bool
    {
        // 1. Regla de oro: las OTAs nunca se borran físicamente
        if ($this->isOta()) {
            return false;
        }

        foreach ($this->beds24Links as $link) {
            $hasBookingId = (null !== $link->getBeds24BookId());

            // 2. Si existe en Beds24 (tiene ID remoto)
            if ($hasBookingId) {
                $estadoActual = $this->getEstado()?->getCodigo();

                if (!in_array($estadoActual, self::ESTADOS_BORRABLES_CON_ID, true)) {
                    // Reservas confirmadas NO se borran
                    return false;
                }
                // Canceladas/Bloqueos: revisar colas
            }

            // 3. Semáforo del worker
            foreach ($link->getQueues() as $queue) {
                $status   = $queue->getStatus();
                $isLocked = $queue->getLockedAt() !== null;

                // Worker en curso → no tocar
                if ($isLocked || $status === PmsBookingsPushQueue::STATUS_PROCESSING) {
                    return false;
                }

                if ($status === PmsBookingsPushQueue::STATUS_PENDING) {
                    if ($hasBookingId) {
                        // Update/cancel viajando a Beds24
                        return false;
                    }

                    // Aborto de nacimiento (nunca existió en Beds24)
                    continue;
                }
            }
        }

        return true;
    }

    public function getSyncStatus(): string
    {
        if ($this->beds24Links->isEmpty()) return 'local';
        $isPending = false;
        foreach ($this->beds24Links as $link) {
            foreach ($link->getQueues() as $queue) {
                $status = $queue->getStatus();
                if ($status === PmsBookingsPushQueue::STATUS_FAILED) return 'error';
                if (in_array($status, [PmsBookingsPushQueue::STATUS_PENDING, PmsBookingsPushQueue::STATUS_PROCESSING], true)) {
                    $isPending = true;
                }
            }
        }
        return $isPending ? 'pending' : 'synced';
    }
}