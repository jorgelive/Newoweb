<?php

namespace App\Pms\Entity;

use DateTimeInterface;
use Doctrine\ORM\Mapping as ORM;
use Gedmo\Mapping\Annotation as Gedmo;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use App\Pms\Entity\PmsBeds24LinkQueue;

#[ORM\Entity]
#[ORM\Table(
    name: 'pms_evento_beds24_link',
    indexes: array(
        new ORM\Index(columns: array('evento_id'), name: 'idx_pms_evento_beds24_evento'),
        new ORM\Index(columns: array('unidad_beds24_map_id'), name: 'idx_pms_evento_beds24_map'),
        new ORM\Index(columns: array('origin_link_id'), name: 'idx_pms_evento_beds24_origin'),
    ),
    uniqueConstraints: [
        new ORM\UniqueConstraint(name: 'uniq_pms_evento_beds24_bookid', columns: ['beds24BookId']),
        new ORM\UniqueConstraint(name: 'uniq_pms_evento_beds24_evento_map', columns: ['evento_id', 'unidad_beds24_map_id']),
    ]
)]
class PmsEventoBeds24Link
{
    public const STATUS_ACTIVE = 'active';
    public const STATUS_DETACHED = 'detached';
    public const STATUS_PENDING_DELETE = 'pending_delete';
    public const STATUS_PENDING_MOVE = 'pending_move';
    public const STATUS_SYNCED_DELETED = 'synced_deleted';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: PmsEventoCalendario::class, inversedBy: 'beds24Links')]
    #[ORM\JoinColumn(name: 'evento_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ?PmsEventoCalendario $evento = null;

    #[ORM\ManyToOne(targetEntity: PmsUnidadBeds24Map::class)]
    #[ORM\JoinColumn(name: 'unidad_beds24_map_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ?PmsUnidadBeds24Map $unidadBeds24Map = null;

    /**
     * ID único de la sub-reserva en Beds24 (bookId).
     * Este es el identificador técnico clave para resolver resync sin duplicar.
     */
    #[ORM\Column(type: 'bigint', unique: true, nullable: true)]
    private ?string $beds24BookId = null;

    /**
     * Si está seteado, este link es derivado (mirror) del originLink.
     * Si es null, este link es raíz (principal en el sentido de origen).
     */
    #[ORM\ManyToOne(targetEntity: self::class)]
    #[ORM\JoinColumn(name: 'origin_link_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?self $originLink = null;

    /**
     * Marca opcional para auditoría (no necesaria para lógica).
     * Útil si quieres ver rápidamente qué link está “vivo” en un resync.
     */
    #[ORM\Column(type: 'datetime', nullable: true)]
    private ?DateTimeInterface $lastSeenAt = null;

    #[ORM\Column(type: 'string', length: 20, options: ['default' => 'active'])]
    private ?string $status = self::STATUS_ACTIVE;

    #[ORM\Column(type: 'datetime', nullable: true)]
    private ?DateTimeInterface $deactivatedAt = null;

    #[Gedmo\Timestampable(on: 'create')]
    #[ORM\Column(type: 'datetime')]
    private ?DateTimeInterface $created = null;

    #[Gedmo\Timestampable(on: 'update')]
    #[ORM\Column(type: 'datetime')]
    private ?DateTimeInterface $updated = null;

    #[ORM\OneToMany(mappedBy: 'link', targetEntity: PmsBeds24LinkQueue::class, cascade: ['persist'], orphanRemoval: false,)]
    private Collection $queues;

    public function __construct()
    {
        $this->queues = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getEvento(): ?PmsEventoCalendario
    {
        return $this->evento;
    }

    public function setEvento(?PmsEventoCalendario $evento): self
    {
        if ($evento === null) {
            return $this; // guard: evento es obligatorio
        }

        $this->evento = $evento;
        return $this;
    }

    public function getUnidadBeds24Map(): ?PmsUnidadBeds24Map
    {
        return $this->unidadBeds24Map;
    }

    public function setUnidadBeds24Map(?PmsUnidadBeds24Map $unidadBeds24Map): self
    {
        if ($unidadBeds24Map === null) {
            return $this; // guard: map es obligatorio
        }

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

    /**
     * Indica explícitamente si este link es un espejo (mirror).
     *
     * Regla de dominio:
     * - Un link es mirror si y solo si tiene originLink.
     *
     * Este método existe por claridad semántica:
     * - Evita que otros servicios dependan directamente de originLink !== null
     * - Centraliza la regla de negocio
     * - Permite, a futuro, cambiar la implementación sin romper contratos
     */
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

    public function getLastSeenAt(): ?DateTimeInterface
    {
        return $this->lastSeenAt;
    }

    public function setLastSeenAt(?DateTimeInterface $lastSeenAt): self
    {
        $this->lastSeenAt = $lastSeenAt;
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

    public function getQueues(): Collection
    {
        return $this->queues;
    }

    public function addQueue(PmsBeds24LinkQueue $queue): self
    {
        if (!$this->queues->contains($queue)) {
            $this->queues->add($queue);
            $queue->setLink($this);
        }

        return $this;
    }

    public function removeQueue(PmsBeds24LinkQueue $queue): self
    {
        $this->queues->removeElement($queue);

        if (method_exists($queue, 'getLink') && $queue->getLink() === $this) {
            $queue->setLink(null);
        }

        return $this;
    }

    public function __toString(): string
    {
        $id = $this->id ?? '¿?';
        $bookId = $this->beds24BookId ?? '-';
        $kind = $this->originLink ? 'mirror' : 'root';
        $mapId = $this->unidadBeds24Map?->getId() ?? '?';
        $status = $this->status ?? self::STATUS_ACTIVE;

        return sprintf('Link #%s • %s • %s • bookId %s • map %s', $id, $kind, $status, $bookId, $mapId);
    }
}