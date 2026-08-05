<?php

declare(strict_types=1);

namespace App\Pms\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use App\Entity\Trait\IdTrait;
use App\Entity\Trait\LocatorTrait;
use App\Entity\Trait\TimestampTrait;
use App\Pms\ApiPlatform\State\PmsEventoCalendarioProcessor;
use App\Security\Roles;
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
#[ApiResource(
    operations: [
        // App util (Vue) — calendario SPA de reservas. La integridad OTA (fechas,
        // unidad, estado) queda blindada por PmsEventoCalendarioSecurityListener,
        // no por este recurso; el processor solo reconstruye los links Beds24.
        new Get(
            security: "is_granted('" . Roles::RESERVAS_SHOW . "')",
            normalizationContext: ['groups' => ['pms_evento:read', 'timestamp:read']],
        ),
        new Post(
            securityPostDenormalize: "is_granted('" . Roles::RESERVAS_WRITE . "')",
            securityPostDenormalizeMessage: 'No tienes permiso para crear eventos de calendario.',
            normalizationContext: ['groups' => ['pms_evento:read', 'timestamp:read']],
            // 'pms_evento:write_create' habilita `reserva` SOLO en este Post (ver la
            // propiedad más abajo): permite agregar una estancia más (otra casita) a
            // una reserva ya existente desde el calendario SPA.
            denormalizationContext: ['groups' => ['pms_evento:write', 'pms_evento:write_create']],
            processor: PmsEventoCalendarioProcessor::class,
        ),
        new Patch(
            security: "is_granted('" . Roles::RESERVAS_WRITE . "')",
            securityMessage: 'No tienes permiso para editar eventos de calendario.',
            normalizationContext: ['groups' => ['pms_evento:read', 'timestamp:read']],
            denormalizationContext: ['groups' => ['pms_evento:write']],
            processor: PmsEventoCalendarioProcessor::class,
        ),
        // El permiso solo abre la puerta: quién puede borrar *qué* lo decide
        // isSafeToDelete() vía PmsEventoCalendarioSecurityListener::preRemove
        // (OTA, existencia en Beds24, sincronización en curso).
        new Delete(
            security: "is_granted('" . Roles::RESERVAS_DELETE . "')",
            securityMessage: 'No tienes permiso para eliminar eventos de calendario.',
        ),
    ],
    routePrefix: '/pms',
)]
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

    /**
     * Únicos estados hacia los que un evento OTA en estado ABIERTO puede transicionar manualmente.
     * (Airbnb "inquiry": solo se puede confirmar como cancelación o mantener abierto.)
     */
    public const OTA_ABIERTO_ESTADOS_SELECCIONABLES = [
        PmsEventoEstado::CODIGO_ABIERTO,
        PmsEventoEstado::CODIGO_CANCELADA,
    ];

    /**
     * Estados a los que un pago registrado NO les cambia nada:
     * - `cancelada` es terminal (un reembolso no resucita una reserva muerta),
     * - `bloqueo` no es la estancia de un huésped, es calendario cerrado.
     * El resto sí se auto-confirma (ver requiereAutoConfirmacionPorPago()).
     */
    /**
     * Estados a los que NO les aplica la auto-confirmación por pago.
     *
     * `extension` está aquí por seguridad doble: `PmsEstadoPagoEventosService` ya
     * no toca las extensiones, pero si por cualquier vía una acabara con estado de
     * pago «confiable», esta regla la convertiría en CONFIRMADA —dejaría de ser
     * una extensión y pasaría de `black` a `confirmed` en Beds24—.
     */
    public const ESTADOS_SIN_AUTO_CONFIRMACION = [
        PmsEventoEstado::CODIGO_CANCELADA,
        PmsEventoEstado::CODIGO_BLOQUEO,
        PmsEventoEstado::CODIGO_EXTENSION,
    ];


    /* ======================================================
     * RELACIONES DE NEGOCIO (UUID v7)
     * ====================================================== */

    #[ORM\ManyToOne(targetEntity: PmsUnidad::class)]
    #[ORM\JoinColumn(name: 'pms_unidad_id', referencedColumnName: 'id', nullable: false, columnDefinition: 'BINARY(16)')]
    #[Assert\NotNull(message: "La unidad es obligatoria.")]
    #[Groups(['pax_reserva:read', 'pms_evento:read', 'pms_evento:write'])]
    private ?PmsUnidad $pmsUnidad = null;

    #[ORM\ManyToOne(targetEntity: PmsReserva::class, inversedBy: 'eventosCalendario')]
    #[ORM\JoinColumn(name: 'reserva_id', referencedColumnName: 'id', nullable: true, columnDefinition: 'BINARY(16)')]
    // 'pms_evento:write_create' es un grupo EXCLUSIVO del Post (ver ApiResource más abajo):
    // permite enlazar el evento a una reserva existente solo al crearlo. Nunca se agrega
    // al Patch, para que un evento no pueda re-parentarse a otra reserva por accidente
    // (ver también PmsEventoCalendarioSecurityListener::preUpdate).
    #[Groups(['pms_evento:read', 'pms_evento:write_create'])]
    private ?PmsReserva $reserva = null;

    #[ORM\ManyToOne(targetEntity: PmsChannel::class, inversedBy: 'eventosCalendario')]
    #[ORM\JoinColumn(name: 'channel_id', referencedColumnName: 'id', nullable: true)]
    #[Assert\NotNull(message: 'El canal es obligatorio.')]
    #[Groups(['pms_evento:read', 'pms_evento:write'])]
    private ?PmsChannel $channel = null;

    #[ORM\Column(type: 'string', length: 100, nullable: true)]
    #[Assert\Length(max: 100)]
    #[Groups(['pms_evento:read'])]
    private ?string $referenciaCanal = null;

    #[ORM\Column(type: 'string', length: 20, nullable: true)]
    #[Assert\Length(max: 20)]
    private ?string $horaLlegadaCanal = null;

    #[ORM\Column(type: 'datetime', nullable: true)]
    private ?DateTimeInterface $fechaReservaCanal = null;

    #[ORM\Column(type: 'datetime', nullable: true)]
    private ?DateTimeInterface $fechaModificacionCanal = null;

    #[ORM\Column(type: 'text', nullable: true)]
    #[Groups(['pms_evento:read', 'pms_evento:write'])]
    private ?string $comentariosHuesped = null;

    /* ======================================================
     * RELACIONES MAESTRAS (IDs NATURALES - Strings)
     * ====================================================== */

    #[ORM\ManyToOne(targetEntity: PmsEventoEstado::class)]
    #[ORM\JoinColumn(name: 'estado_id', referencedColumnName: 'id', nullable: false)]
    #[Assert\NotNull(message: "El estado es obligatorio.")]
    #[Groups(['pms_evento:read', 'pms_evento:write'])]
    private ?PmsEventoEstado $estado = null;

    #[ORM\ManyToOne(targetEntity: PmsEventoEstadoPago::class)]
    #[ORM\JoinColumn(name: 'estado_pago_id', referencedColumnName: 'id', nullable: false)]
    #[Assert\NotNull(message: "El estado de pago es obligatorio.")]
    #[Groups(['pms_evento:read', 'pms_evento:write'])]
    private ?PmsEventoEstadoPago $estadoPago = null;

    /* ======================================================
     * PROPIEDADES DE TIEMPO Y CONTENIDO
     * ====================================================== */

    #[ORM\Column(type: 'datetime')]
    #[Assert\NotBlank(message: "La fecha de inicio es obligatoria.")]
    #[Groups(['pms_evento:read', 'pms_evento:write'])]
    private ?DateTimeInterface $inicio = null;

    #[ORM\Column(type: 'datetime')]
    #[Assert\NotBlank(message: "La fecha de fin es obligatoria.")]
    #[Groups(['pms_evento:read', 'pms_evento:write'])]
    private ?DateTimeInterface $fin = null;

    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    #[Groups(['pms_evento:read', 'pms_evento:write'])]
    private ?string $descripcion = null;

    #[ORM\Column(type: 'decimal', precision: 10, scale: 2, options: ['default' => '0.00'])]
    #[Groups(['pms_evento:read', 'pms_evento:write'])]
    private string $monto = '0.00';

    #[ORM\Column(type: 'decimal', precision: 10, scale: 2, nullable: true, options: ['default' => '0.00'])]
    #[Groups(['pms_evento:read', 'pms_evento:write'])]
    private ?string $comision = '0.00';

    #[ORM\Column(type: 'integer', options: ['default' => 0])]
    #[Groups(['pms_evento:read', 'pms_evento:write'])]
    private int $cantidadAdultos = 0;

    #[ORM\Column(type: 'integer', options: ['default' => 0])]
    #[Groups(['pms_evento:read', 'pms_evento:write'])]
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

    /**
     * Salida tardía pactada: el huésped se va por la tarde del día de salida.
     *
     * NO es una noche más de estancia —`fin` sigue siendo el día real de salida,
     * con su hora—, pero sí **ocupa la casita esa noche de cara a la venta**: con
     * la habitación libre a las 17:00 no da tiempo a limpiar y entregar, así que
     * hay que impedir que se venda.
     *
     * Se marca desde el drawer (o desde EasyAdmin) y arrastra dos consecuencias
     * automáticas (ver docs/PmsBeds24ReservasSync.md §7.1.b):
     *   1. Nace un evento hermano en estado `extension` que cubre esa noche:
     *      ocupa la unidad en el PMS y viaja a Beds24 como `black`
     *      (PmsExtensionEstanciaService).
     *   2. Se abre un cargo de SERVICIO en 0.00 que valora el operador
     *      (PmsCargosAutomaticosService::sincronizarExtras()).
     *
     * Sustituye a la práctica anterior de crear una SEGUNDA estancia de una
     * noche: aquella inflaba las noches vendidas y el ADR, partía la reserva en
     * dos en el calendario y en la guía, y obligaba a inventar un check-in de
     * las 14:00 que nunca ocurría.
     */
    #[ORM\Column(name: 'salida_tardia', type: 'boolean', options: ['default' => false])]
    #[Groups(['pms_evento:read', 'pms_evento:write'])]
    private bool $salidaTardia = false;

    /**
     * Entrada temprana pactada: el huésped llega por la mañana del día de entrada.
     *
     * El espejo de `$salidaTardia`, hacia atrás: `inicio` sigue siendo el día real
     * de llegada con su hora, pero **la noche ANTERIOR deja de ser vendible** —con
     * la casita entregada a las 09:00 no se puede alojar a nadie la víspera—, así
     * que nace una `extension` que la cubre.
     *
     * Genera su cargo en 0.00, igual que la salida tardía: lo que se cobre por
     * entrar antes se negocia caso por caso.
     */
    #[ORM\Column(name: 'entrada_temprana', type: 'boolean', options: ['default' => false])]
    #[Groups(['pms_evento:read', 'pms_evento:write'])]
    private bool $entradaTemprana = false;

    /**
     * Estancia que generó esta EXTENSIÓN (estado `extension`).
     *
     * Solo lo llevan los eventos de extensión, y es lo que permite retirarlos
     * cuando se desmarca la casilla: identificarlos por fechas o por la
     * descripción sería adivinar. En una estancia normal es `null`.
     *
     * Sin cascada a propósito: el borrado de la estancia lo gestiona
     * `PmsExtensionEstanciaService`, que también avisa a Beds24.
     */
    #[ORM\ManyToOne(targetEntity: self::class)]
    #[ORM\JoinColumn(name: 'evento_origen_id', referencedColumnName: 'id', nullable: true, onDelete: 'CASCADE')]
    // Se expone en lectura para que el frontend distinga una extensión de una
    // estancia. Va el dato crudo y no un flag calculado: API Platform sólo
    // serializa métodos con prefijo `get`/`is`, y `esExtension()` no lo tiene.
    #[Groups(['pms_evento:read'])]
    private ?self $eventoOrigen = null;

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
     * Se serializa porque `isSafeToDelete()` NO basta para decidir si conviene borrar:
     * en cuanto la estancia pasa a «cancelada» entra en ESTADOS_BORRABLES_CON_ID y el
     * motivo desaparece, aunque el push de esa cancelación siga en cola (`pending` no
     * bloquea, sólo `processing`). Borrar en esa ventana es una carrera perdida: al
     * eliminar el link, `cancelPendingPostForLink()` mata el POST de la cancelación y el
     * DELETE llega a Beds24 sobre una reserva todavía confirmada, que es justo lo único
     * que Beds24 no acepta borrar. El frontend espera a `synced` antes de ofrecer el
     * borrado (ver `ReservaEditDrawer`).
     *
     * @return string Puede ser 'local', 'error', 'pending' o 'synced'.
     */
    #[Groups(['pms_evento:read'])]
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
     * Regla de negocio: una estancia con dinero recibido (pago total, parcial o de
     * alojamiento) tiene que quedar CONFIRMADA. Nunca se guarda un evento pagado en
     * "pendiente"/"requerimiento": si el operador lo intenta, se corrige al vuelo.
     *
     * Fuente única de verdad de PmsEventoCalendarioIntegrityListener (que la aplica en
     * prePersist/preUpdate, o sea para CUALQUIER entrypoint: API util, EasyAdmin, consola)
     * y espejo exacto de `requiereAutoConfirmacionPorPago()` en
     * util/src/types/pmsReservaModel.ts, que la anticipa en el editor.
     *
     * Nota: la regla es asimétrica a propósito. Registrar un pago confirma, pero volver a
     * "no pagado" NO degrada el estado: quitar un pago mal cargado no debe descancelar ni
     * despendientizar una reserva que el operador ya trabajó.
     *
     * @return bool True si el estado actual debe ser reemplazado por "confirmada".
     */
    public function requiereAutoConfirmacionPorPago(): bool
    {
        $estadoPagoId = $this->estadoPago?->getId();
        $estadoId = $this->estado?->getId();

        // Sin relaciones maestras no hay nada que decidir (la validación NotNull ya se queja).
        if (null === $estadoPagoId || null === $estadoId) {
            return false;
        }

        if (!in_array($estadoPagoId, PmsEventoEstadoPago::ESTADOS_PAGO_CONFIABLES, true)) {
            return false;
        }

        if (in_array($estadoId, self::ESTADOS_SIN_AUTO_CONFIRMACION, true)) {
            return false;
        }

        return $estadoId !== PmsEventoEstado::CODIGO_CONFIRMADA;
    }

    /**
     * Determina si la entidad es segura de eliminar basándose en su origen y estado de sincronización.
     *
     * @return bool True si se puede eliminar sin causar inconsistencias, false de lo contrario.
     */
    #[Groups(['pms_evento:read'])]
    public function isSafeToDelete(): bool
    {
        return null === $this->getMotivoNoBorrable();
    }

    /**
     * Motivo legible por el que este evento NO se puede eliminar, o null si sí se puede.
     * Fuente única de verdad de isSafeToDelete(): el frontend lo usa para explicar
     * al operador por qué el botón de eliminar está deshabilitado, en lugar de
     * dejarlo descubrirlo con un 403 del listener.
     *
     * @return string|null
     */
    #[Groups(['pms_evento:read'])]
    public function getMotivoNoBorrable(): ?string
    {
        if ($this->isOta()) {
            return 'Es una reserva de un canal externo (Booking/Airbnb): debe cancelarse directamente en el canal.';
        }

        foreach ($this->beds24Links as $link) {
            if (null !== $link->getBeds24BookId()
                && !in_array($this->getEstado()?->getId(), self::ESTADOS_BORRABLES_CON_ID, true)
            ) {
                return 'Ya existe en Beds24: primero debes pasarla a "cancelada" y esperar la sincronización.';
            }

            foreach ($link->getQueues() as $queue) {
                if ($queue->getStatus() === 'processing' || $queue->getLockedAt() !== null) {
                    return 'Se está sincronizando con Beds24 en este momento. Intenta de nuevo en unos minutos.';
                }
            }
        }

        return null;
    }

    /* ======================================================
     * GETTERS Y SETTERS EXPLÍCITOS
     * ====================================================== */

    #[Groups(['pax_reserva:read', 'pms_evento:read', 'pms_reserva:read'])]
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

    #[Groups(['pms_evento:read'])]
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
    #[Groups(['pax_reserva:read', 'pms_evento:read'])]
    public function isSalidaTardia(): bool
    {
        return $this->salidaTardia;
    }

    public function setSalidaTardia(bool $salidaTardia): self
    {
        $this->salidaTardia = $salidaTardia;

        return $this;
    }

    /**
     * La estancia que generó esta extensión. `null` en una estancia normal.
     */
    public function getEventoOrigen(): ?self
    {
        return $this->eventoOrigen;
    }

    public function setEventoOrigen(?self $eventoOrigen): self
    {
        $this->eventoOrigen = $eventoOrigen;

        return $this;
    }

    /**
     * ¿Es una extensión de horario (la noche invisible que bloquea la unidad)?
     *
     * Se mira `eventoOrigen`, NO el estado. El estado `extension` sólo lo tiene
     * mientras está activa: al desmarcar la casilla pasa a `cancelada` y seguiría
     * siendo una extensión, no una estancia. Filtrar por estado las dejaba
     * reaparecer como estancias fantasma en el drawer y en el calendario «Todas»
     * —una por cada vez que se marcó y desmarcó—, que es justo lo que hay que
     * evitar. `eventoOrigen` no cambia nunca.
     */
    public function esExtension(): bool
    {
        return $this->eventoOrigen !== null;
    }

    public function isEntradaTemprana(): bool
    {
        return $this->entradaTemprana;
    }

    public function setEntradaTemprana(bool $entradaTemprana): self
    {
        $this->entradaTemprana = $entradaTemprana;

        return $this;
    }

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