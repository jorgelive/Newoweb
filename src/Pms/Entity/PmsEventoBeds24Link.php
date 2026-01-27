<?php

declare(strict_types=1);

namespace App\Pms\Entity;

use App\Entity\Trait\IdTrait;
use App\Entity\Trait\TimestampTrait;
use App\Pms\Entity\PmsBookingsPushQueue;
use DateTimeInterface;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * Entidad PmsEventoBeds24Link.
 * Vincula técnicamente un evento del sistema con una sub-reserva de Beds24.
 * Soporta lógica de espejos (mirrors) para reservas multi-unidad mediante relaciones UUID.
 */
#[ORM\Entity]
#[ORM\Table(
    name: 'pms_evento_beds24_link',
    indexes: [
        new ORM\Index(columns: ['evento_id'], name: 'idx_pms_evento_beds24_evento'),
        new ORM\Index(columns: ['unidad_beds24_map_id'], name: 'idx_pms_evento_beds24_map'),
        new ORM\Index(columns: ['origin_link_id'], name: 'idx_pms_evento_beds24_origin'),
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

    public const STATUS_ACTIVE = 'active';
    public const STATUS_DETACHED = 'detached';
    public const STATUS_PENDING_DELETE = 'pending_delete';
    public const STATUS_PENDING_MOVE = 'pending_move';
    public const STATUS_SYNCED_DELETED = 'synced_deleted';

    /**
     * Relación con el evento del calendario.
     * Se debe asegurar que PmsEventoCalendario también use IdTrait.
     */
    #[ORM\ManyToOne(targetEntity: PmsEventoCalendario::class, inversedBy: 'beds24Links')]
    #[ORM\JoinColumn(
        name: 'evento_id',
        referencedColumnName: 'id',
        nullable: false,
        onDelete: 'CASCADE',
        columnDefinition: 'BINARY(16) COMMENT "(DC2Type:uuid)"'
    )]
    private ?PmsEventoCalendario $evento = null;

    /**
     * Relación con el mapeo de unidad de Beds24.
     */
    #[ORM\ManyToOne(targetEntity: PmsUnidadBeds24Map::class)]
    #[ORM\JoinColumn(
        name: 'unidad_beds24_map_id',
        referencedColumnName: 'id',
        nullable: false,
        onDelete: 'CASCADE',
        columnDefinition: 'BINARY(16) COMMENT "(DC2Type:uuid)"'
    )]
    private ?PmsUnidadBeds24Map $unidadBeds24Map = null;

    /**
     * ID único de la sub-reserva en Beds24 (bookId).
     */
    #[ORM\Column(type: 'bigint', unique: true, nullable: true)]
    private ?string $beds24BookId = null;

    /**
     * Referencia al link principal si este es un espejo (mirror).
     * Relación reflexiva vinculada mediante UUID.
     */
    #[ORM\ManyToOne(targetEntity: self::class)]
    #[ORM\JoinColumn(
        name: 'origin_link_id',
        referencedColumnName: 'id',
        nullable: true,
        onDelete: 'SET NULL',
        columnDefinition: 'BINARY(16) COMMENT "(DC2Type:uuid)"'
    )]
    private ?self $originLink = null;

    #[ORM\Column(type: 'datetime', nullable: true)]
    private ?DateTimeInterface $lastSeenAt = null;

    #[ORM\Column(type: 'string', length: 20, options: ['default' => 'active'])]
    private ?string $status = self::STATUS_ACTIVE;

    #[ORM\Column(type: 'datetime', nullable: true)]
    private ?DateTimeInterface $deactivatedAt = null;

    /**
     * @var Collection<int, PmsBookingsPushQueue>
     */
    #[ORM\OneToMany(mappedBy: 'link', targetEntity: PmsBookingsPushQueue::class, cascade: ['persist'], orphanRemoval: false)]
    private Collection $queues;

    public function __construct()
    {
        $this->queues = new ArrayCollection();
    }

    /*
     * -------------------------------------------------------------------------
     * GETTERS Y SETTERS EXPLÍCITOS
     * -------------------------------------------------------------------------
     */

    public function getEvento(): ?PmsEventoCalendario
    {
        return $this->evento;
    }

    public function setEvento(?PmsEventoCalendario $evento): self
    {
        if ($evento !== null) {
            $this->evento = $evento;
        }
        return $this;
    }

    public function getUnidadBeds24Map(): ?PmsUnidadBeds24Map
    {
        return $this->unidadBeds24Map;
    }

    public function setUnidadBeds24Map(?PmsUnidadBeds24Map $unidadBeds24Map): self
    {
        if ($unidadBeds24Map !== null) {
            $this->unidadBeds24Map = $unidadBeds24Map;
        }
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

    public function getOriginLink(): ?self
    {
        return $this->originLink;
    }

    public function setOriginLink(?self $originLink): self
    {
        $this->originLink = $originLink;
        return $this;
    }

    public function isDerived(): bool
    {
        return $this->originLink !== null;
    }

    public function isMirror(): bool
    {
        return $this->originLink !== null;
    }

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

    public function markDetached(?DateTimeInterface $now = null): self
    {
        $this->status = self::STATUS_DETACHED;
        $this->deactivatedAt = $now;
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

    public function markPendingMove(?DateTimeInterface $now = null): self
    {
        $this->status = self::STATUS_PENDING_MOVE;
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

    public function __toString(): string
    {
        $id = $this->getId() ?? 'NEW';
        $bookId = $this->beds24BookId ?? '-';
        $kind = $this->originLink ? 'mirror' : 'root';
        $status = $this->status ?? self::STATUS_ACTIVE;

        return sprintf('Link #%s • %s • %s • bookId %s', (string)$id, $kind, $status, $bookId);
    }
}