<?php

declare(strict_types=1);

namespace App\Pms\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\GetCollection;
use App\Entity\Trait\TimestampTrait;
use App\Security\Roles;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;

/**
 * Entidad PmsEventoEstado.
 * Define los estados internos del PMS y su mapeo con Beds24.
 * IDs Naturales basados en los códigos originales.
 */
#[ApiResource(
    operations: [
        // Solo lectura: alimenta el selector de estado del calendario SPA (Vue).
        new GetCollection(
            security: "is_granted('" . Roles::RESERVAS_SHOW . "')",
            normalizationContext: ['groups' => ['pms_evento_estado:read']],
        ),
    ],
    routePrefix: '/pms',
    order: ['orden' => 'ASC'],
    paginationEnabled: false,
)]
#[ORM\Entity]
#[ORM\Table(name: 'pms_evento_estado')]
#[ORM\HasLifecycleCallbacks]
class PmsEventoEstado
{
    /** Gestión de auditoría temporal (createdAt, updatedAt) */
    use TimestampTrait;

    /* ======================================================
     * CONSTANTES DE ID (Valores originales restaurados)
     * ====================================================== */

    public const string CODIGO_PENDIENTE      = 'pendiente';
    public const string CODIGO_CONFIRMADA     = 'confirmada';
    public const string CODIGO_CANCELADA      = 'cancelada';
    public const string CODIGO_ABIERTO        = 'abierto';
    public const string CODIGO_REQUERIMIENTO  = 'requerimiento';
    public const string CODIGO_BLOQUEO        = 'bloqueo';

    /**
     * Extensión de horario: la noche que una estancia inutiliza sin venderla
     * (entrada temprana o salida tardía).
     *
     * Es un evento REAL —ocupa la unidad y viaja a Beds24 como `black`— pero
     * INVISIBLE: no sale en los calendarios, ni en el listado de eventos, ni en
     * las estancias del drawer, ni suma en el rollup de la reserva. Lo crea y lo
     * retira solo `PmsExtensionEstanciaService`; nadie lo edita a mano.
     *
     * Ver docs/PmsBeds24ReservasSync.md §7.1.b.
     */
    public const string CODIGO_EXTENSION      = 'extension';

    public const array MOSTRAR_EVENTO_GUIA = [
        PmsEventoEstado::CODIGO_PENDIENTE,
        PmsEventoEstado::CODIGO_CONFIRMADA,
        PmsEventoEstado::CODIGO_REQUERIMIENTO,
        PmsEventoEstado::CODIGO_ABIERTO
    ];

    /**
     * Estados en los que el evento OCUPA la casita de cara a la venta.
     *
     * Es la lista que tiñe de beige el calendario de tarifas (calendario
     * `pms_eventos_ocupacion_spa`, ver docs/Calendar_architecture.md §12): los
     * días que NO están aquí son huecos que se pueden tarifar. Quedan fuera
     * `cancelada` (la casita volvió a estar libre), `abierto` (inquiry de
     * Airbnb: consulta sin reserva) y `bloqueo` (no es una venta que tarifar).
     *
     * Se declara en POSITIVO y como lista blanca a propósito: un estado nuevo
     * que se agregue mañana no pinta ocupación hasta que alguien lo decida aquí.
     *
     * OJO: coincide exactamente con el COMPLEMENTO de
     * PmsEventoCalendario::OTA_ESTADOS_NO_SELECCIONABLES, pero es CASUALIDAD —
     * aquella regula qué puede elegir un humano en un evento OTA, ésta qué se
     * pinta como ocupado. Son reglas distintas: no se deriven la una de la otra
     * o cambiar una arrastrará a la otra sin querer.
     */
    public const array OCUPAN_UNIDAD = [
        PmsEventoEstado::CODIGO_PENDIENTE,
        PmsEventoEstado::CODIGO_CONFIRMADA,
        PmsEventoEstado::CODIGO_REQUERIMIENTO,
    ];

    /**
     * Los tres estados de una reserva OTA VIVA. Intercambiables entre sí.
     *
     * Que una reserva de Booking esté en `new`, `request` o `confirmed` es una gradación del
     * mismo hecho —hay alguien que quiere esas noches— y moverla entre ellos no le quita ni le
     * da la habitación a nadie. Por eso el PMS sí puede imponérselos al canal.
     *
     * ⚠️ Coincide con `OCUPAN_UNIDAD`, y también es CASUALIDAD. Aquélla dice qué se pinta como
     * ocupado; ésta, qué transiciones puede imponerle el PMS a una OTA. No se deriven la una de
     * la otra.
     */
    public const array OTA_VIVOS = [
        self::CODIGO_PENDIENTE,
        self::CODIGO_CONFIRMADA,
        self::CODIGO_REQUERIMIENTO,
    ];

    /**
     * ¿Puede el PMS llevar una reserva de OTA de `$desde` a `$hasta`?
     *
     * **Fuente única de la regla.** La aplican tres sitios que antes decidían por su cuenta:
     * `PmsEventoCalendarioSecurityListener` (bloquea la mutación local),
     * `BookingsPushMappingStrategy` (decide si el `status` viaja al canal) y el desplegable de
     * `util` (`pmsReservaModel.ts::estadosSeleccionables()`, espejo declarado).
     *
     * ```
     *   pendiente ⇄ confirmada ⇄ requerimiento     ✔  los tres, entre sí
     *   abierto   → cancelada                      ✔  unidireccional: limpiar una consulta
     *   todo lo demás                              ✘
     * ```
     *
     * El porqué de cada mitad:
     *
     * - **Los tres vivos son intercambiables** porque moverse entre ellos no cambia quién tiene
     *   la habitación. Bloquearlos era lo que dejaba estancias cobradas en `pendiente` sin
     *   poder decírselo al canal (§12.9.a del doc de sync).
     * - **Nada entra ni sale del par `abierto`/`cancelada`**, salvo esa flecha. `cancelada` es
     *   terminal —resucitar una reserva que el canal liberó es vender dos veces la misma
     *   noche— y `abierto` es una consulta: degradar a consulta una reserva en firme no
     *   significa nada en el canal. La flecha que sí existe es la de limpiar consultas muertas.
     *
     * ⚠️ **`$desde === null` NIEGA, no permite.** Es una regla de seguridad y su valor por
     * defecto tiene que caer del lado cerrado. El caso que lo destapó: el mapper traduce el
     * `status` crudo del canal con `desdeCodigoBeds24()`, que devuelve `null` para `black`
     * —y para cualquier literal que Beds24 añada mañana—; con el defecto permisivo, un
     * `black → confirmed` viajaba al canal. Quien tenga un caso legítimo sin origen (un
     * evento recién nacido) que lo resuelva antes de preguntar.
     */
    public static function transicionOtaPermitida(?string $desde, ?string $hasta): bool
    {
        if ($desde === null || $hasta === null) {
            return false;
        }

        if ($desde === $hasta) {
            return true;
        }

        if (in_array($desde, self::OTA_VIVOS, true) && in_array($hasta, self::OTA_VIVOS, true)) {
            return true;
        }

        return $desde === self::CODIGO_ABIERTO && $hasta === self::CODIGO_CANCELADA;
    }

    /**
     * `status` de Beds24 → código del maestro. `null` si no lo conocemos.
     *
     * Existe para que `BookingsPushMappingStrategy` pueda aplicar `transicionOtaPermitida()`
     * sobre lo que dice el CANAL (`PmsEventoCalendario::$estadoBeds24`, texto crudo) sin
     * bajar a la base de datos en mitad del mapeo de un lote.
     *
     * ⚠️ Espejo de la columna `pms_evento_estado.codigo_beds24`. `black` queda fuera a
     * propósito: lo comparten `bloqueo` y `extension`, así que no tiene vuelta única — y
     * ninguno de los dos es un estado de reserva de OTA.
     */
    public static function desdeCodigoBeds24(?string $codigo): ?string
    {
        return match ($codigo) {
            'new'       => self::CODIGO_PENDIENTE,
            'confirmed' => self::CODIGO_CONFIRMADA,
            'request'   => self::CODIGO_REQUERIMIENTO,
            'inquiry'   => self::CODIGO_ABIERTO,
            'cancelled' => self::CODIGO_CANCELADA,
            default     => null,
        };
    }

    /**
     * Estados en los que un evento acredita que ALGUIEN es huésped nuestro.
     *
     * La usa la identificación por teléfono del chat entrante
     * (`PmsReservaRepository::findVivasByTelefono()`): decide si el número que escribe tiene
     * derecho a que le contemos su reserva y su estado de cuenta.
     *
     * ⚠️ Coincide hoy con `OCUPAN_UNIDAD`, y aun así se declara aparte **a propósito**, por el
     * mismo motivo que aquella se separó de `IMPIDEN_VENTA`: responden preguntas distintas.
     * Aquella dice «¿pinto esta noche como ocupada?»; ésta, «¿le enseño datos privados a quien
     * escribe?». Si algún día un `bloqueo` pasara a pintar ocupación, derivar una de otra
     * abriría la cuenta de un huésped a quien reservó una casita cerrada por mantenimiento.
     *
     * Quedan fuera, y cada uno por su razón:
     *
     * - `cancelada`: la reserva murió. Los datos siguen ahí intactos, pero ya no es huésped.
     * - `bloqueo`: no hay huésped, es la casita cerrada por nosotros o por el canal.
     * - `abierto`: un inquiry de Airbnb es una CONSULTA, no una reserva. Todavía no hay
     *   relación que dé acceso a nada.
     * - `extension`: la noche fantasma de una entrada temprana o salida tardía (§7.1.b), no
     *   una estancia. Su reserva ya entra por el tramo que la generó.
     */
    public const array IDENTIFICAN_HUESPED = [
        PmsEventoEstado::CODIGO_PENDIENTE,
        PmsEventoEstado::CODIGO_CONFIRMADA,
        PmsEventoEstado::CODIGO_REQUERIMIENTO,
    ];

    /**
     * Estados en los que la casita NO se puede vender esa noche.
     *
     * ⚠️ NO es lo mismo que OCUPAN_UNIDAD, y por eso se declara aparte en vez de
     * derivarse de ella. Aquella responde «¿pinto beige esta noche en el calendario de
     * tarifas?»; ésta responde «¿puedo alojar a alguien aquí?». Dos estados las separan:
     *
     * - `bloqueo`: no es una venta que tarifar (fuera de OCUPAN_UNIDAD), pero la casita
     *   está cerrada por mantenimiento o por el canal — no se puede vender.
     * - `extension`: la noche que inutiliza una entrada temprana o una salida tardía
     *   (§7.1.b de docs/PmsBeds24ReservasSync.md). Es invisible en el PMS y no se tarifa,
     *   pero la unidad está físicamente ocupada.
     *
     * `abierto` (inquiry de Airbnb) queda FUERA a propósito: es una consulta sin reserva,
     * no retiene la unidad. Si algún día se decide que un inquiry sí la retiene, se añade
     * aquí — no en OCUPAN_UNIDAD.
     *
     * La usa PmsDisponibilidadService. Ver docs/PmsDisponibilidad.md.
     */
    public const array IMPIDEN_VENTA = [
        PmsEventoEstado::CODIGO_PENDIENTE,
        PmsEventoEstado::CODIGO_CONFIRMADA,
        PmsEventoEstado::CODIGO_REQUERIMIENTO,
        PmsEventoEstado::CODIGO_BLOQUEO,
        PmsEventoEstado::CODIGO_EXTENSION,
    ];

    /**
     * El ID es el código string del estado.
     * Sin GeneratedValue por ser ID Natural.
     */
    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 50)]
    private ?string $id = null;

    /**
     * Nombre de visualización para la interfaz.
     */
    #[ORM\Column(type: 'string', length: 100)]
    private ?string $nombre = null;

    /**
     * Color visual en formato hexadecimal (ej: #FF5733).
     */
    #[ORM\Column(type: 'string', length: 7, nullable: true)]
    private ?string $color = null;

    /**
     * Icono FontAwesome del estado, con su prefijo (ej: `fas fa-circle-check`).
     *
     * Dato, no código: se guarda en el maestro para que un estado nuevo llegue con su icono
     * sin tocar PHP ni recompilar el front. El mismo valor lo usan el CRUD del panel, el
     * calendario de reservas y cualquier consumidor futuro — así el estado se ve igual en
     * todas partes, que era lo que un `#RRGGBB` a secas no conseguía.
     *
     * La familia es deliberada: `circle-*` para los estados de una reserva viva y un glifo
     * inequívoco para los terminales. De un vistazo, ✓ / − / ? / ✗ dicen más que el nombre.
     */
    #[ORM\Column(type: 'string', length: 50, nullable: true)]
    private ?string $icono = null;

    /**
     * Valor que espera Beds24 (ej: "confirmed", "cancelled", "request").
     */
    #[ORM\Column(type: 'string', length: 50, nullable: true)]
    private ?string $codigoBeds24 = null;

    /**
     * Orden para listados en la UI.
     */
    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $orden = null;

    public function __construct(?string $id = null)
    {
        if ($id) {
            $this->id = $id;
        }
    }

    /* ======================================================
     * LÓGICA DE NORMALIZACIÓN DE COLOR
     * ====================================================== */

    #[ORM\PrePersist]
    #[ORM\PreUpdate]
    public function normalizeColor(): void
    {
        if (empty($this->color)) {
            $this->color = null;
            return;
        }

        $c = trim($this->color);

        if (!str_starts_with($c, '#') && preg_match('/^[0-9a-fA-F]{6}$/', $c)) {
            $c = '#' . $c;
        }

        if (str_starts_with($c, '#') && preg_match('/^#[0-9a-fA-F]{6}$/', $c)) {
            $this->color = strtoupper($c);
        } else {
            $this->color = null;
        }
    }

    /* ======================================================
     * GETTERS Y SETTERS
     * ====================================================== */

    #[Groups(['pms_evento_estado:read', 'pms_evento:read'])]
    public function getId(): ?string { return $this->id; }
    public function setId(string $id): self { $this->id = $id; return $this; }

    #[Groups(['pax_reserva:read', 'pms_evento_estado:read', 'pms_evento:read'])]
    public function getNombre(): ?string { return $this->nombre; }
    public function setNombre(?string $nombre): self { $this->nombre = $nombre; return $this; }

    #[Groups(['pms_evento_estado:read', 'pms_evento:read'])]
    public function getColor(): ?string { return $this->color; }
    public function setColor(?string $color): self { $this->color = $color; return $this; }

    #[Groups(['pms_evento_estado:read', 'pms_evento:read'])]
    public function getIcono(): ?string { return $this->icono; }
    public function setIcono(?string $icono): self { $this->icono = $icono; return $this; }

    public function getCodigoBeds24(): ?string { return $this->codigoBeds24; }
    public function setCodigoBeds24(?string $codigoBeds24): self { $this->codigoBeds24 = $codigoBeds24; return $this; }

    #[Groups(['pms_evento_estado:read'])]
    public function getOrden(): ?int { return $this->orden; }
    public function setOrden(?int $orden): self { $this->orden = $orden; return $this; }

    public function __toString(): string
    {
        return $this->nombre ?? (string) $this->id;
    }
}