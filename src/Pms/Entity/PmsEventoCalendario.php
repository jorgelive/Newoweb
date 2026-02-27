<?php

declare(strict_types=1);

namespace App\Pms\Entity;

use App\Entity\Trait\IdTrait;
use App\Entity\Trait\LocatorTrait;
use App\Entity\Trait\TimestampTrait;
use DateTimeInterface;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Annotation\Groups;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * Entidad PmsEventoCalendario.
 * Gestiona bloqueos y reservas.
 * ✅ Restaurados todos los campos de Beds24 y lógica de sincronización.
 */
#[ORM\Entity]
#[ORM\Table(name: 'pms_evento_calendario')]
#[ORM\HasLifecycleCallbacks]
class PmsEventoCalendario
{
    use IdTrait;
    use LocatorTrait;
    use TimestampTrait;

    /* ======================================================
     * CONSTANTES DE LÓGICA
     * ====================================================== */
    public const ESTADOS_BORRABLES_CON_ID = [
        PmsEventoEstado::CODIGO_CANCELADA,
        PmsEventoEstado::CODIGO_BLOQUEO,
    ];

    /**
     * ✅ Blindaje: estados que un evento OTA NO debe poder seleccionar manualmente.
     * (Si el OTA llega en esos estados por import/legacy, igual lo verás,
     *  pero aquí evitamos “cambiar a” esos estados mediante formularios).
     */
    public const OTA_ESTADOS_NO_SELECCIONABLES = [
        PmsEventoEstado::CODIGO_CANCELADA,
        PmsEventoEstado::CODIGO_CONSULTA,
        PmsEventoEstado::CODIGO_BLOQUEO,
    ];

    /* ======================================================
     * RELACIONES DE NEGOCIO (UUID v7)
     * ====================================================== */

    #[ORM\ManyToOne(targetEntity: PmsUnidad::class)]
    #[ORM\JoinColumn(name: 'pms_unidad_id', referencedColumnName: 'id', nullable: false, columnDefinition: 'BINARY(16)')]
    #[Assert\NotNull(message: "La unidad es obligatoria.")]
    #[Groups(['pax_reserva:read'])]
    private ?PmsUnidad $pmsUnidad = null;

    #[ORM\ManyToOne(targetEntity: PmsReserva::class, inversedBy: 'eventosCalendario')]
    #[ORM\JoinColumn(name: 'reserva_id', referencedColumnName: 'id', nullable: true, columnDefinition: 'BINARY(16)')]
    private ?PmsReserva $reserva = null;

    #[ORM\ManyToOne(targetEntity: PmsChannel::class, inversedBy: 'eventosCalendario')]
    #[ORM\JoinColumn(name: 'channel_id', referencedColumnName: 'id', nullable: true)]
    #[Assert\NotNull(message: 'El canal es obligatorio.')]
    private ?PmsChannel $channel = null;

    #[ORM\Column(type: 'string', length: 100, nullable: true)]
    #[Assert\Length(max: 100)]
    private ?string $referenciaCanal = null;

    #[ORM\Column(type: 'string', length: 20, nullable: true)]
    #[Assert\Length(max: 20)]
    private ?string $horaLlegadaCanal = null;

    #[ORM\Column(type: 'datetime', nullable: true)]
    private ?DateTimeInterface $fechaReservaCanal = null;

    #[ORM\Column(type: 'datetime', nullable: true)]
    private ?DateTimeInterface $fechaModificacionCanal = null;


    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $comentariosHuesped = null;

    /* ======================================================
     * RELACIONES MAESTRAS (IDs NATURALES - Strings)
     * ====================================================== */

    #[ORM\ManyToOne(targetEntity: PmsEventoEstado::class)]
    #[ORM\JoinColumn(name: 'estado_id', referencedColumnName: 'id', nullable: false)]
    #[Assert\NotNull(message: "El estado es obligatorio.")]
    private ?PmsEventoEstado $estado = null;

    #[ORM\ManyToOne(targetEntity: PmsEventoEstadoPago::class)]
    #[ORM\JoinColumn(name: 'estado_pago_id', referencedColumnName: 'id', nullable: false)]
    #[Assert\NotNull(message: "El estado de pago es obligatorio.")]
    private ?PmsEventoEstadoPago $estadoPago = null;

    /* ======================================================
     * PROPIEDADES DE TIEMPO Y CONTENIDO
     * ====================================================== */

    #[ORM\Column(type: 'datetime')]
    #[Assert\NotBlank(message: "La fecha de inicio es obligatoria.")]
    private ?DateTimeInterface $inicio = null;

    #[ORM\Column(type: 'datetime')]
    #[Assert\NotBlank(message: "La fecha de fin es obligatoria.")]
    private ?DateTimeInterface $fin = null;

    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $descripcion = null;

    #[ORM\Column(type: 'decimal', precision: 10, scale: 2, options: ['default' => '0.00'])]
    private string $monto = '0.00';

    #[ORM\Column(type: 'decimal', precision: 10, scale: 2, nullable: true, options: ['default' => '0.00'])]
    private ?string $comision = '0.00';

    #[ORM\Column(type: 'integer', options: ['default' => 0])]
    private int $cantidadAdultos = 0;

    #[ORM\Column(type: 'integer', options: ['default' => 0])]
    private int $cantidadNinos = 0;

    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private bool $isOta = false;

    #[ORM\Column(type: 'string', length: 180, nullable: true)]
    private ?string $tituloCache = null;

    /* ======================================================
     * CAMPOS DE DOMINIO BEDS24 (⚠️ NO ELIMINAR)
     * ====================================================== */

    #[ORM\Column(name: 'rate_description', type: 'text', nullable: true)]
    private ?string $rateDescription = null;

    #[ORM\Column(name: 'estado_beds24', type: 'string', length: 50, nullable: true)]
    private ?string $estadoBeds24 = null;

    #[ORM\Column(name: 'subestado_beds24', type: 'string', length: 50, nullable: true)]
    private ?string $subestadoBeds24 = null;

    /* ======================================================
     * COLECCIONES
     * ====================================================== */

    /** @var Collection<int, PmsEventoBeds24Link> */
    #[ORM\OneToMany(mappedBy: 'evento', targetEntity: PmsEventoBeds24Link::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $beds24Links;

    /** @var Collection<int, PmsEventAssignment> */
    #[ORM\OneToMany(mappedBy: 'evento', targetEntity: PmsEventAssignment::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $assignments;

    public function __construct()
    {
        $this->beds24Links = new ArrayCollection();
        $this->assignments = new ArrayCollection();

        $this->id = Uuid::v7();
        $this->initializeLocator();
    }

    /* ======================================================
     * ✅ BLINDAJE TOTAL (SERVER-SIDE)
     * ====================================================== */

    #[Assert\Callback]
    public function validateOtaEstado(ExecutionContextInterface $context): void
    {
        // Solo aplica a OTAs
        if (!$this->isOta) {
            return;
        }

        $estadoId = $this->estado?->getId();
        if (!$estadoId) {
            return;
        }

        // No permitir seleccionar esos estados en OTA
        if (in_array($estadoId, self::OTA_ESTADOS_NO_SELECCIONABLES, true)) {
            $context->buildViolation('En reservas OTA no se permite seleccionar este estado.')
                ->atPath('estado')
                ->addViolation();
        }
    }

    /* ======================================================
     * LÓGICA DE NEGOCIO Y SINCRONIZACIÓN
     * ====================================================== */

    public function isSynced(): bool
    {
        if ($this->beds24Links->isEmpty()) return true;

        foreach ($this->beds24Links as $link) {
            foreach ($link->getQueues() as $queue) {
                if (!in_array($queue->getStatus(), ['success', 'canceled'], true)) return false;
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
                if ($queue->getStatus() === 'failed') return 'error';
                if (in_array($queue->getStatus(), ['pending', 'processing'], true)) $isPending = true;
            }
        }

        return $isPending ? 'pending' : 'synced';
    }

    public function isSafeToDelete(): bool
    {
        if ($this->isOta()) return false;

        foreach ($this->beds24Links as $link) {
            if (null !== $link->getBeds24BookId()) {
                if (!in_array($this->getEstado()?->getId(), self::ESTADOS_BORRABLES_CON_ID, true)) return false;
            }

            foreach ($link->getQueues() as $queue) {
                if ($queue->getStatus() === 'processing' || $queue->getLockedAt() !== null) return false;
            }
        }

        return true;
    }

    /* ======================================================
     * GETTERS Y SETTERS EXPLÍCITOS
     * ====================================================== */

    #[Groups(['pax_reserva:read'])]
    public function getId(): ?Uuid
    {
        return $this->id;
    }

    #[Groups(['pax_reserva:read'])]
    public function getPmsUnidad(): ?PmsUnidad { return $this->pmsUnidad; }
    public function setPmsUnidad(?PmsUnidad $pmsUnidad): self { $this->pmsUnidad = $pmsUnidad; return $this; }

    #[Groups(['pax_reserva:read'])]
    public function getReserva(): ?PmsReserva { return $this->reserva; }
    public function setReserva(?PmsReserva $reserva): self { $this->reserva = $reserva; return $this; }

    #[Groups(['pax_reserva:read'])]
    public function getChannel(): ?PmsChannel { return $this->channel; }
    public function setChannel(?PmsChannel $val): self { $this->channel = $val; return $this; }

    #[Groups(['pax_reserva:read'])]
    public function getReferenciaCanal(): ?string { return $this->referenciaCanal; }
    public function setReferenciaCanal(?string $val): self { $this->referenciaCanal = $val; return $this; }

    public function getHoraLlegadaCanal(): ?string { return $this->horaLlegadaCanal; }
    public function setHoraLlegadaCanal(?string $val): self { $this->horaLlegadaCanal = $val; return $this; }

    public function getFechaReservaCanal(): ?DateTimeInterface { return $this->fechaReservaCanal; }
    public function setFechaReservaCanal(?DateTimeInterface $val): self { $this->fechaReservaCanal = $val; return $this; }

    public function getFechaModificacionCanal(): ?DateTimeInterface { return $this->fechaModificacionCanal; }
    public function setFechaModificacionCanal(?DateTimeInterface $val): self { $this->fechaModificacionCanal = $val; return $this; }


    public function getComentariosHuesped(): ?string { return $this->comentariosHuesped; }
    public function setComentariosHuesped(?string $val): self { $this->comentariosHuesped = $val; return $this; }

    #[Groups(['pax_reserva:read'])]
    public function getEstado(): ?PmsEventoEstado { return $this->estado; }
    public function setEstado(?PmsEventoEstado $estado): self { $this->estado = $estado; return $this; }

    public function getEstadoPago(): ?PmsEventoEstadoPago { return $this->estadoPago; }
    public function setEstadoPago(?PmsEventoEstadoPago $estadoPago): self { $this->estadoPago = $estadoPago; return $this; }

    #[Groups(['pax_reserva:read'])]
    public function getInicio(): ?DateTimeInterface { return $this->inicio; }
    public function setInicio(?DateTimeInterface $inicio): self { $this->inicio = $inicio; return $this; }

    #[Groups(['pax_reserva:read'])]
    public function getFin(): ?DateTimeInterface { return $this->fin; }
    public function setFin(?DateTimeInterface $fin): self { $this->fin = $fin; return $this; }

    public function getDescripcion(): ?string { return $this->descripcion; }
    public function setDescripcion(?string $descripcion): self { $this->descripcion = $descripcion; return $this; }

    public function getMonto(): string { return $this->monto; }
    public function setMonto(string $monto): self { $this->monto = $monto; return $this; }

    public function getComision(): ?string { return $this->comision; }
    public function setComision(?string $comision): self { $this->comision = $comision; return $this; }

    #[Groups(['pax_reserva:read'])]
    public function getCantidadAdultos(): int { return $this->cantidadAdultos; }
    public function setCantidadAdultos(int $cantidadAdultos): self { $this->cantidadAdultos = $cantidadAdultos; return $this; }

    #[Groups(['pax_reserva:read'])]
    public function getCantidadNinos(): int { return $this->cantidadNinos; }
    public function setCantidadNinos(int $cantidadNinos): self { $this->cantidadNinos = $cantidadNinos; return $this; }

    public function isOta(): bool { return $this->isOta; }
    public function setIsOta(bool $isOta): self { $this->isOta = $isOta; return $this; }

    public function getTituloCache(): ?string { return $this->tituloCache; }
    public function setTituloCache(?string $tituloCache): self { $this->tituloCache = $tituloCache; return $this; }

    public function getRateDescription(): ?string { return $this->rateDescription; }
    public function setRateDescription(?string $val): self { $this->rateDescription = $val; return $this; }

    public function getEstadoBeds24(): ?string { return $this->estadoBeds24; }
    public function setEstadoBeds24(?string $val): self { $this->estadoBeds24 = $val; return $this; }

    public function getSubestadoBeds24(): ?string { return $this->subestadoBeds24; }
    public function setSubestadoBeds24(?string $val): self { $this->subestadoBeds24 = $val; return $this; }

    /** @return Collection<int, PmsEventoBeds24Link> */
    public function getBeds24Links(): Collection { return $this->beds24Links; }

    public function addBeds24Link(PmsEventoBeds24Link $link): self
    {
        if (!$this->beds24Links->contains($link)) {
            $this->beds24Links->add($link);
            $link->setEvento($this);
        }
        return $this;
    }

    public function removeBeds24Link(PmsEventoBeds24Link $link): self
    {
        if ($this->beds24Links->removeElement($link)) {
            if ($link->getEvento() === $this) $link->setEvento(null);
        }
        return $this;
    }

    /** @return Collection<int, PmsEventAssignment> */
    public function getAssignments(): Collection { return $this->assignments; }

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
            if ($assignment->getEvento() === $this) $assignment->setEvento(null);
        }
        return $this;
    }

    public function __toString(): string
    {
        $unidad = $this->pmsUnidad?->getNombre();
        $inicio = $this->inicio?->format('d/m');
        $descripcion = $this->descripcion;

        // 1. Título dinámico completo
        if ($unidad && $inicio) {
            return sprintf(
                '%s | %s - %s',
                $unidad,
                $inicio,
                $descripcion ?: 'Reserva'
            );
        }

        // 2. Fallback cacheado
        if ($this->tituloCache) {
            return $this->tituloCache;
        }

        // 3. Último recurso absoluto
        return 'Reserva';
    }
}