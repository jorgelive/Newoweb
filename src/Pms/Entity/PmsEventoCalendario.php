<?php

declare(strict_types=1);

namespace App\Pms\Entity;

use App\Entity\Trait\IdTrait;
use App\Entity\Trait\LocatorTrait;
use App\Entity\Trait\TimestampTrait;
use DateTimeInterface;
use DateTimeImmutable;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * Entidad PmsEventoCalendario.
 * Gestiona bloqueos y reservas.
 * ✅ Entidad limpia de hacks temporales. La protección de estados OTA
 * se delega a la UI (EasyAdmin) y al Listener de Doctrine (UnitOfWork).
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
     * Utilizado por el formulario para ocultarlos y por el Listener para bloquear mutaciones.
     */
    public const OTA_ESTADOS_NO_SELECCIONABLES = [
        PmsEventoEstado::CODIGO_CANCELADA,
        PmsEventoEstado::CODIGO_ABIERTO,
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

    /**
     * Indica si la asignación de guía para este evento está deshabilitada.
     */
    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private bool $guiaDisabled = false;

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
     * VALIDACIONES DE FORMULARIO
     * ====================================================== */

    /**
     * Valida que la fecha de salida sea al menos al día siguiente de la fecha de entrada.
     * Soporta casos extremos (ej. entrada 23:59 y salida 00:01 del día siguiente) porque
     * compara estrictamente las fechas calendario ignorando las horas.
     *
     * @param ExecutionContextInterface $context El contexto del validador.
     */
    #[Assert\Callback]
    public function validateFechasCoherentes(ExecutionContextInterface $context): void
    {
        if (null === $this->inicio || null === $this->fin) {
            return;
        }

        // Usamos DateTimeImmutable para evitar alterar la referencia original en la entidad.
        // Ponemos la hora a 00:00:00 para comparar solo el "día calendario".
        $inicioDia = DateTimeImmutable::createFromInterface($this->inicio)->setTime(0, 0, 0);
        $finDia = DateTimeImmutable::createFromInterface($this->fin)->setTime(0, 0, 0);

        if ($inicioDia >= $finDia) {
            $context->buildViolation('La fecha de salida debe ser al menos al día siguiente de la fecha de entrada.')
                ->atPath('fin')
                ->addViolation();
        }
    }

    /* ======================================================
     * LÓGICA DE NEGOCIO Y SINCRONIZACIÓN
     * ====================================================== */

    /**
     * Comprueba si el evento está completamente sincronizado.
     *
     * @return bool True si todos los enlaces y colas tienen éxito o están cancelados, o si no hay enlaces.
     */
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

    /**
     * Obtiene el estado consolidado de la sincronización.
     *
     * @return string Puede ser 'local', 'error', 'pending' o 'synced'.
     */
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

    /**
     * Determina si la entidad es segura de eliminar basándose en su origen y estado de sincronización.
     *
     * @return bool True si se puede eliminar sin causar inconsistencias, false de lo contrario.
     */
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

    /**
     * Obtiene si la asignación de guía está deshabilitada.
     * * @return bool
     */
    public function isGuiaDisabled(): bool
    {
        return $this->guiaDisabled;
    }

    /**
     * Define si la asignación de guía para este evento debe estar deshabilitada.
     * * @param bool $guiaDisabled
     * @return self
     */
    public function setGuiaDisabled(bool $guiaDisabled): self
    {
        $this->guiaDisabled = $guiaDisabled;
        return $this;
    }

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

        if ($unidad && $inicio) {
            return sprintf('%s | %s - %s', $unidad, $inicio, $descripcion ?: 'Reserva');
        }

        if ($this->tituloCache) {
            return $this->tituloCache;
        }

        return 'Reserva';
    }

    /**
     * Getters virtuales para EasyAdmin (Trazabilidad)
     */
    public function getTrazabilidadReserva(): ?string
    {
        return null;
    }

    public function getTrazabilidadLinks(): ?string
    {
        return null;
    }

    /**
     * Calcula la cantidad de noches (días calendario) de la estancia.
     * Ignora las horas de check-in y check-out para evitar errores matemáticos
     * en estancias menores a 24 horas reloj (ej. check-in 14:00, check-out 10:00).
     *
     * @return int
     */
    #[Groups(['pax_reserva:read'])]
    public function getNoches(): int
    {
        if (null === $this->inicio || null === $this->fin) {
            return 0;
        }

        // Normalizamos a medianoche para contar solo los saltos de calendario
        $inicioDia = \DateTimeImmutable::createFromInterface($this->inicio)->setTime(0, 0, 0);
        $finDia = \DateTimeImmutable::createFromInterface($this->fin)->setTime(0, 0, 0);

        $interval = $inicioDia->diff($finDia);

        // '%a' devuelve la cantidad total de días de diferencia
        return (int) $interval->format('%a');
    }
}