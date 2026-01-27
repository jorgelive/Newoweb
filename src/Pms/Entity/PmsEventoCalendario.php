<?php

declare(strict_types=1);

namespace App\Pms\Entity;

use App\Entity\Trait\IdTrait;
use App\Entity\Trait\TimestampTrait;
use DateTimeInterface;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Entidad PmsEventoCalendario.
 * Representa un bloqueo o reserva en el tiempo para una unidad específica.
 * IDs: UUID para negocio, Strings para Maestros.
 */
#[ORM\Entity]
#[ORM\Table(name: 'pms_evento_calendario')]
#[ORM\HasLifecycleCallbacks]
class PmsEventoCalendario
{
    /** Gestión de Identificador UUID (BINARY 16) */
    use IdTrait;

    /** Gestión de auditoría temporal (DateTimeImmutable) */
    use TimestampTrait;

    /* ======================================================
     * CONSTANTES DE LÓGICA
     * ====================================================== */
    private const ESTADOS_BORRABLES_CON_ID = [
        PmsEventoEstado::CODIGO_CANCELADA,
        PmsEventoEstado::CODIGO_BLOQUEO,
    ];

    /* ======================================================
     * RELACIONES DE NEGOCIO (UUID - BINARY 16)
     * ====================================================== */

    #[ORM\ManyToOne(targetEntity: PmsUnidad::class)]
    #[ORM\JoinColumn(name: 'pms_unidad_id', referencedColumnName: 'id', nullable: false, columnDefinition: 'BINARY(16) COMMENT "(DC2Type:uuid)"')]
    #[Assert\NotNull(message: "La unidad es obligatoria.")]
    private ?PmsUnidad $pmsUnidad = null;

    #[ORM\ManyToOne(targetEntity: PmsReserva::class, inversedBy: 'eventosCalendario')]
    #[ORM\JoinColumn(name: 'reserva_id', referencedColumnName: 'id', nullable: true, columnDefinition: 'BINARY(16) COMMENT "(DC2Type:uuid)"')]
    private ?PmsReserva $reserva = null;

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
     * CAMPOS DE DOMINIO (BEDS24 / EXTERNOS)
     * ====================================================== */

    #[ORM\Column(name: 'rate_description', type: 'text', nullable: true)]
    private ?string $rateDescription = null;

    #[ORM\Column(name: 'estado_beds24', type: 'string', length: 50, nullable: true)]
    private ?string $estadoBeds24 = null;

    #[ORM\Column(name: 'subestado_beds24', type: 'string', length: 50, nullable: true)]
    private ?string $subestadoBeds24 = null;

    /* ======================================================
     * RELACIONES TÉCNICAS BEDS24 Y ASIGNACIONES
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
    }

    /* ======================================================
     * GETTERS Y SETTERS
     * ====================================================== */

    public function getPmsUnidad(): ?PmsUnidad { return $this->pmsUnidad; }
    public function setPmsUnidad(?PmsUnidad $pmsUnidad): self { $this->pmsUnidad = $pmsUnidad; return $this; }

    public function getReserva(): ?PmsReserva { return $this->reserva; }
    public function setReserva(?PmsReserva $reserva): self { $this->reserva = $reserva; return $this; }

    public function getEstado(): ?PmsEventoEstado { return $this->estado; }
    public function setEstado(?PmsEventoEstado $estado): self { $this->estado = $estado; return $this; }

    public function getEstadoPago(): ?PmsEventoEstadoPago { return $this->estadoPago; }
    public function setEstadoPago(?PmsEventoEstadoPago $estadoPago): self { $this->estadoPago = $estadoPago; return $this; }

    public function getInicio(): ?DateTimeInterface { return $this->inicio; }
    public function setInicio(?DateTimeInterface $inicio): self { $this->inicio = $inicio; return $this; }

    public function getFin(): ?DateTimeInterface { return $this->fin; }
    public function setFin(?DateTimeInterface $fin): self { $this->fin = $fin; return $this; }

    public function getDescripcion(): ?string { return $this->descripcion; }
    public function setDescripcion(?string $descripcion): self { $this->descripcion = $descripcion; return $this; }

    public function getMonto(): string { return $this->monto; }
    public function setMonto(string $monto): self { $this->monto = $monto; return $this; }

    public function getComision(): ?string { return $this->comision; }
    public function setComision(?string $comision): self { $this->comision = $comision; return $this; }

    public function getCantidadAdultos(): int { return $this->cantidadAdultos; }
    public function setCantidadAdultos(int $cantidadAdultos): self { $this->cantidadAdultos = $cantidadAdultos; return $this; }

    public function getCantidadNinos(): int { return $this->cantidadNinos; }
    public function setCantidadNinos(int $cantidadNinos): self { $this->cantidadNinos = $cantidadNinos; return $this; }

    public function isOta(): bool { return $this->isOta; }
    public function setIsOta(bool $isOta): self { $this->isOta = $isOta; return $this; }

    public function getTituloCache(): ?string { return $this->tituloCache; }
    public function setTituloCache(?string $tituloCache): self { $this->tituloCache = $tituloCache; return $this; }

    // Getters y Setters de Dominios Beds24
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

    /* ======================================================
     * LÓGICA DE NEGOCIO BEDS24 / SINCRONIZACIÓN
     * ====================================================== */

    public function isSynced(): bool
    {
        if ($this->beds24Links->isEmpty()) return true;

        foreach ($this->beds24Links as $link) {
            foreach ($link->getQueues() as $queue) {
                if (!in_array($queue->getStatus(), ['success', 'canceled'], true)) {
                    return false;
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
            $hasBookingId = (null !== $link->getBeds24BookId());
            if ($hasBookingId) {
                if (!in_array($this->getEstado()?->getId(), self::ESTADOS_BORRABLES_CON_ID, true)) {
                    return false;
                }
            }
            foreach ($link->getQueues() as $queue) {
                if ($queue->getStatus() === 'processing' || $queue->getLockedAt() !== null) return false;
            }
        }
        return true;
    }

    public function __toString(): string
    {
        if ($this->tituloCache) return $this->tituloCache;

        $unidad = $this->pmsUnidad ? $this->pmsUnidad->getNombre() : 'Sin Unidad';
        $inicio = $this->inicio ? $this->inicio->format('d/m') : '?';
        return sprintf('%s | %s - %s', $unidad, $inicio, $this->descripcion ?: 'Reserva');
    }
}