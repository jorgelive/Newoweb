<?php

declare(strict_types=1);

namespace App\Pms\Entity;

use App\Entity\Trait\IdTrait;
use App\Entity\Trait\TimestampTrait;
use App\Pms\Entity\PmsBookingsPushQueue;
use App\Pms\Entity\PmsChannel;
use DateTimeInterface;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * Entidad PmsEventoBeds24Link.
 * Vincula técnicamente un evento del sistema con una sub-reserva de Beds24.
 * * CAMBIO ARQUITECTURA:
 * Se elimina la jerarquía recursiva (Parent/Child).
 * Ahora es una estructura plana donde un link se marca como 'esPrincipal'.
 */
#[ORM\Entity]
#[ORM\Table(
    name: 'pms_evento_beds24_link',
    indexes: [
        new ORM\Index(columns: ['evento_id'], name: 'idx_pms_evento_beds24_evento'),
        new ORM\Index(columns: ['unidad_beds24_map_id'], name: 'idx_pms_evento_beds24_map'),
        // Eliminado índice de origin_link
    ],
    uniqueConstraints: [
        new ORM\UniqueConstraint(name: 'uniq_pms_evento_beds24_bookid', columns: ['beds24BookId']),
        new ORM\UniqueConstraint(name: 'uniq_pms_evento_beds24_evento_map', columns: ['evento_id', 'unidad_beds24_map_id']),
    ]
)]
#[ORM\HasLifecycleCallbacks]
class PmsEventoBeds24Link
{
    /**
     * Gestión de Identificador UUID (BINARY 16).
     */
    use IdTrait;

    /**
     * Gestión de auditoría temporal (DateTimeImmutable).
     */
    use TimestampTrait;

    /**
     * Estados que el sistema sabe producir Y consumir. No añadas uno sin las dos mitades:
     * `detached` y `pending_move` vivieron aquí sin que nadie los escribiera ni los leyera,
     * y lo único que lograron fue aparentar un flujo de desvinculación y otro de movimiento
     * en dos fases que nunca existieron (el movimiento se resuelve reutilizando el link y
     * cambiándole el mapa, §6.3.b del doc).
     *
     * - `active`         : el normal. Lo pone `markActive()` en cada hidratación.
     * - `pending_delete` : borrado remoto SIN borrar la fila. Hoy sólo se activa a mano desde
     *                      el panel; lo consume `Beds24BookingsPushQueueListener`.
     * - `synced_deleted` : terminal. Lo sella `BookingsPushHandler::handleSuccess()` al
     *                      completar el DELETE, y es el que excluyen los finders y el job.
     */
    public const STATUS_ACTIVE = 'active';
    public const STATUS_PENDING_DELETE = 'pending_delete';
    public const STATUS_SYNCED_DELETED = 'synced_deleted';

    #[ORM\ManyToOne(targetEntity: PmsEventoCalendario::class, inversedBy: 'beds24Links')]
    #[ORM\JoinColumn(
        name: 'evento_id',
        referencedColumnName: 'id',
        nullable: true,
        onDelete: 'CASCADE'
    )]
    private ?PmsEventoCalendario $evento = null;

    #[ORM\ManyToOne(targetEntity: PmsUnidadBeds24Map::class)]
    #[ORM\JoinColumn(
        name: 'unidad_beds24_map_id',
        referencedColumnName: 'id',
        nullable: false,
        onDelete: 'CASCADE'
    )]
    private ?PmsUnidadBeds24Map $unidadBeds24Map = null;

    #[ORM\Column(type: 'bigint', unique: true, nullable: true)]
    private ?string $beds24BookId = null;

    /**
     * ✅ NUEVO: Flag plano para identificar el link maestro.
     * Reemplaza a la relación originLink.
     */
    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private bool $esPrincipal = false;

    #[ORM\Column(type: 'datetime', nullable: true)]
    private ?DateTimeInterface $lastSeenAt = null;

    #[ORM\Column(type: 'string', length: 20, options: ['default' => 'active'])]
    private ?string $status = self::STATUS_ACTIVE;

    #[ORM\Column(type: 'datetime', nullable: true)]
    private ?DateTimeInterface $deactivatedAt = null;

    #[ORM\Column(type: 'string', length: 150, nullable: true)]
    private ?string $referenciaCanal = null;

    #[ORM\ManyToOne(targetEntity: PmsChannel::class)]
    #[ORM\JoinColumn(name: 'channel_id', referencedColumnName: 'id', nullable: true)]
    private ?PmsChannel $channel = null;

    /**
     * @var Collection<int, PmsBookingsPushQueue>
     */
    #[ORM\OneToMany(mappedBy: 'link', targetEntity: PmsBookingsPushQueue::class, cascade: ['persist'], orphanRemoval: false)]
    private Collection $queues;

    public function __construct()
    {
        $this->queues = new ArrayCollection();
        // ✅ UUID Generado en constructor para evitar problemas en onFlush
        $this->id = Uuid::v7();
    }

    /*
     * -------------------------------------------------------------------------
     * GETTERS Y SETTERS
     * -------------------------------------------------------------------------
     */

    public function getEvento(): ?PmsEventoCalendario
    {
        return $this->evento;
    }

    public function setEvento(?PmsEventoCalendario $evento): self
    {
        $this->evento = $evento;
        return $this;
    }

    public function getUnidadBeds24Map(): ?PmsUnidadBeds24Map
    {
        return $this->unidadBeds24Map;
    }

    public function setUnidadBeds24Map(?PmsUnidadBeds24Map $unidadBeds24Map): self
    {
        $this->unidadBeds24Map = $unidadBeds24Map;
        return $this;
    }

    public function getBeds24BookId(): ?string
    {
        return $this->beds24BookId;
    }

    public function setBeds24BookId(?string $beds24BookId): self
    {
        $this->beds24BookId = $beds24BookId;
        return $this;
    }

    // ✅ Gestión de Principalidad

    public function isEsPrincipal(): bool
    {
        return $this->esPrincipal;
    }

    public function setEsPrincipal(bool $esPrincipal): self
    {
        $this->esPrincipal = $esPrincipal;
        return $this;
    }

    public function hacerPrincipal(): self
    {
        $this->esPrincipal = true;
        return $this;
    }

    public function isMirror(): bool
    {
        return !$this->esPrincipal;
    }

    // --- Estados ---

    public function getStatus(): ?string
    {
        return $this->status;
    }

    public function setStatus(?string $status): self
    {
        $this->status = $status;
        return $this;
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    public function getDeactivatedAt(): ?DateTimeInterface
    {
        return $this->deactivatedAt;
    }

    public function setDeactivatedAt(?DateTimeInterface $deactivatedAt): self
    {
        $this->deactivatedAt = $deactivatedAt;
        return $this;
    }

    public function getLastSeenAt(): ?DateTimeInterface
    {
        return $this->lastSeenAt;
    }

    public function setLastSeenAt(?DateTimeInterface $lastSeenAt): self
    {
        $this->lastSeenAt = $lastSeenAt;
        return $this;
    }

    /** @return Collection<int, PmsBookingsPushQueue> */
    public function getQueues(): Collection
    {
        return $this->queues;
    }

    public function addQueue(PmsBookingsPushQueue $queue): self
    {
        if (!$this->queues->contains($queue)) {
            $this->queues->add($queue);
            $queue->setLink($this);
        }
        return $this;
    }

    public function removeQueue(PmsBookingsPushQueue $queue): self
    {
        $this->queues->removeElement($queue);
        return $this;
    }

    /*
     * -------------------------------------------------------------------------
     * LÓGICA DE ESTADOS SEMÁNTICOS
     * -------------------------------------------------------------------------
     */

    public function markActive(): self
    {
        $this->status = self::STATUS_ACTIVE;
        $this->deactivatedAt = null;
        return $this;
    }

    public function markPendingDelete(?DateTimeInterface $now = null): self
    {
        $this->status = self::STATUS_PENDING_DELETE;
        if ($this->deactivatedAt === null) {
            $this->deactivatedAt = $now;
        }
        return $this;
    }

    public function markSyncedDeleted(?DateTimeInterface $now = null): self
    {
        $this->status = self::STATUS_SYNCED_DELETED;
        if ($this->deactivatedAt === null) {
            $this->deactivatedAt = $now;
        }
        return $this;
    }

    public function getReferenciaCanal(): ?string { return $this->referenciaCanal; }
    public function setReferenciaCanal(?string $v): self { $this->referenciaCanal = $v; return $this; }

    public function getChannel(): ?PmsChannel { return $this->channel; }
    public function setChannel(?PmsChannel $v): self { $this->channel = $v; return $this; }

    public function __toString(): string
    {
        $id = $this->getId() ?? 'NEW';
        $bookId = $this->beds24BookId ?? '-';
        $kind = $this->esPrincipal ? 'ROOT' : 'MIRROR';
        $status = $this->status ?? self::STATUS_ACTIVE;

        return sprintf('Link #%s [%s] • %s • bookId %s', (string)$id, $kind, $status, $bookId);
    }
}