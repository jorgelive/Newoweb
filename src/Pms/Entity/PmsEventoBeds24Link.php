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
// ⚠️ Los índices van como atributos de CLASE, no dentro de `#[ORM\Table(indexes: …)]`: este Doctrine
// ignora esos argumentos anidados sin avisar. Aquí había declarado un único `(evento, mapa)` que
// nunca llegó a la base, y `schema:validate` decía «in sync» porque compara contra lo que lee.
// Los índices de `evento_id`, `unidad_beds24_map_id` y `channel_id` los pone Doctrine solo, por
// ser claves foráneas, y el único de `beds24BookId` sale del `unique: true` de su columna.
#[ORM\Table(name: 'pms_evento_beds24_link')]
#[ORM\UniqueConstraint(name: 'uniq_link_evento_mapa_rol', columns: ['evento_id', 'unidad_beds24_map_id', 'rol'])]
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

    /**
     * Qué representa en Beds24 la reserva de este link.
     *
     * - `estancia`      : la propia estancia — el principal (la reserva del canal o la directa)
     *                     y sus espejos. Son los únicos que gestiona `PmsEventoCalendarioFactory`.
     * - `extra_entrada` : la `black` que bloquea la víspera de una entrada temprana.
     * - `extra_salida`  : la `black` que bloquea la noche del día de salida de una salida tardía.
     *
     * Un link extra es siempre NUESTRO y nunca principal: la reserva de la estancia sigue siendo
     * la del link principal de rol `estancia`, que es la que buscan facturas, mensajes y pull.
     * Ver docs/PlanHorarioExtraSinEventos.md.
     */
    public const ROL_ESTANCIA = 'estancia';
    public const ROL_EXTRA_ENTRADA = 'extra_entrada';
    public const ROL_EXTRA_SALIDA = 'extra_salida';

    public const ROLES = [self::ROL_ESTANCIA, self::ROL_EXTRA_ENTRADA, self::ROL_EXTRA_SALIDA];

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

    #[ORM\Column(type: 'string', length: 20, options: ['default' => self::ROL_ESTANCIA])]
    private string $rol = self::ROL_ESTANCIA;

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
        if ($esPrincipal) {
            $this->exigirDeEstancia();
        }
        $this->esPrincipal = $esPrincipal;
        return $this;
    }

    public function hacerPrincipal(): self
    {
        return $this->setEsPrincipal(true);
    }

    /**
     * Espejo de la estancia en el otro establecimiento virtual. Un link extra tampoco es principal,
     * pero no es un espejo: no repite la estancia, bloquea otra noche.
     */
    public function isMirror(): bool
    {
        return !$this->esPrincipal && $this->esDeEstancia();
    }

    // --- Rol ---

    public function getRol(): string
    {
        return $this->rol;
    }

    public function setRol(string $rol): self
    {
        if (!in_array($rol, self::ROLES, true)) {
            throw new \InvalidArgumentException(sprintf('Rol de link desconocido: «%s».', $rol));
        }
        if ($rol !== self::ROL_ESTANCIA && $this->esPrincipal) {
            throw new \LogicException('Un link principal no puede pasar a extra: la estancia se quedaría sin su reserva.');
        }
        $this->rol = $rol;
        return $this;
    }

    public function esDeEstancia(): bool
    {
        return $this->rol === self::ROL_ESTANCIA;
    }

    private function exigirDeEstancia(): void
    {
        if (!$this->esDeEstancia()) {
            throw new \LogicException(sprintf('Un link «%s» no puede ser principal: la reserva de la estancia es la del rol «estancia».', $this->rol));
        }
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
        $kind = $this->esPrincipal ? 'ROOT' : ($this->esDeEstancia() ? 'MIRROR' : strtoupper($this->rol));
        $status = $this->status ?? self::STATUS_ACTIVE;

        return sprintf('Link #%s [%s] • %s • bookId %s', (string)$id, $kind, $status, $bookId);
    }
}