<?php

declare(strict_types=1);

namespace App\Pms\Entity;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Link;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use App\Api\Processor\Pms\PmsCambiarMonedaBaseProcessor;
use App\Api\Provider\Pms\PmsInformacionFinancieraPorReservaProvider;
use App\Entity\Maestro\MaestroMoneda;
use App\Entity\Trait\IdTrait;
use App\Entity\Trait\TimestampTrait;
use App\Pms\Enum\PmsTipoCargo;
use App\Pms\Repository\PmsInformacionFinancieraRepository;
use App\Security\Roles;
use DateTimeInterface;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;

/**
 * Cabecera financiera de una reserva (espejo de App\Message\Entity\MessageConversation).
 *
 * Es el registro "padre" que agrupa los conceptos financieros (PmsCargoFinanciero) que
 * Beds24 reporta para una reserva: alojamiento, limpieza, cargo por servicio, pagos, etc.
 * Análogo a la conversación que agrupa mensajes. Hay una por PmsReserva (1:1 lógico).
 */
#[ApiResource(
    operations: [
        new GetCollection(security: "is_granted('" . Roles::RESERVAS_SHOW . "')"),
        new Get(security: "is_granted('" . Roles::RESERVAS_SHOW . "')"),
        // Acceso por reserva. NO se usa un SearchFilter sobre la relación `reserva`: con
        // estos UUID binarios no vincula el tipo Doctrine y devuelve siempre vacío sin
        // error (§12.6). El provider delega en un repositorio que sí tipa el parámetro.
        new Get(
            uriTemplate: '/pms_informacion_financieras/por-reserva/{reservaId}',
            // `uriVariables` es OBLIGATORIO en una variable que no es el identificador del
            // recurso: sin declararla, API Platform intenta casarla con el `id` de
            // PmsInformacionFinanciera y responde 404 "Invalid uri variables" antes incluso
            // de llegar al provider. Mismo patrón que la operación `{localizador}` de PmsReserva.
            uriVariables: [
                'reservaId' => new Link(
                    fromClass: PmsReserva::class,
                    identifiers: ['id'],
                ),
            ],
            security: "is_granted('" . Roles::RESERVAS_SHOW . "')",
            provider: PmsInformacionFinancieraPorReservaProvider::class,
        ),
        // Sólo para que el operador reactive/anule los cargos de una reserva cancelada
        // en la OTA que en realidad sigue adelante como directa (§12.7).
        new Patch(
            security: "is_granted('" . Roles::RESERVAS_WRITE . "')",
            securityMessage: 'No tienes permiso para editar la información financiera.',
        ),
        // Cambio de moneda base. Operación propia y no un PATCH sobre `moneda` porque tiene
        // efectos (reescribe cargos, rellena TC, recalcula) y necesita un tipo de cambio que
        // no es un campo de la cabecera (§12.4.4).
        new Post(
            uriTemplate: '/pms_informacion_financieras/{id}/moneda-base',
            security: "is_granted('" . Roles::RESERVAS_WRITE . "')",
            securityMessage: 'No tienes permiso para cambiar la moneda base.',
            deserialize: false,
            processor: PmsCambiarMonedaBaseProcessor::class,
        ),
    ],
    routePrefix: '/pms',
    // Se incluyen los grupos de los hijos y de moneda para que el panel financiero
    // de la SPA cargue todo el árbol (cabecera + cargos + pagos) en una sola llamada.
    normalizationContext: ['groups' => [
        'pms_finanzas:read', 'pms_cargo:read', 'pms_pago:read', 'maestro:moneda:read',
    ]],
    denormalizationContext: ['groups' => ['pms_finanzas:write']],
)]
#[ORM\Entity(repositoryClass: PmsInformacionFinancieraRepository::class)]
#[ORM\Table(name: 'pms_informacion_financiera')]
#[ORM\HasLifecycleCallbacks]
class PmsInformacionFinanciera
{
    use IdTrait;
    use TimestampTrait;

    /**
     * Cabecera financiera de la reserva, 1:1.
     *
     * Es `OneToOne` y NO `ManyToOne+unique` por una razón concreta: el lado inverso
     * (`PmsReserva::$informacionFinanciera`) es el que cascadea el borrado. Mientras la
     * relación fue unidireccional, Doctrine no sabía que esta fila existía, emitía el
     * `DELETE FROM pms_reserva` con la FK todavía apuntando y MySQL lo rechazaba con
     * un 1451 — ninguna reserva se podía borrar desde el panel. Ver §"Borrado" en
     * `docs/PmsBeds24ReservasSync.md`.
     *
     * La columna ya tenía índice único (`UNIQ_2AFB3104D67139E8`), así que el cambio de
     * mapeo no arrastra migración.
     */
    #[ORM\OneToOne(targetEntity: PmsReserva::class, inversedBy: 'informacionFinanciera')]
    #[ORM\JoinColumn(name: 'reserva_id', referencedColumnName: 'id', nullable: false)]
    private ?PmsReserva $reserva = null;

    /** Moneda principal de la reserva (resolver contra maestro; default USD). */
    #[ORM\ManyToOne(targetEntity: MaestroMoneda::class)]
    #[ORM\JoinColumn(name: 'moneda_id', referencedColumnName: 'id', nullable: true)]
    #[Groups(['pms_finanzas:read'])]
    private ?MaestroMoneda $moneda = null;

    /**
     * Cache de la suma de los cargos de Beds24 (PmsCargoFinanciero con tipo=charge), convertidos
     * a esta moneda. Recalculado por PmsInformacionFinancieraCoherenciaListener.
     */
    #[ORM\Column(name: 'total_cargos', type: 'decimal', precision: 10, scale: 2, options: ['default' => '0.00'])]
    #[Groups(['pms_finanzas:read'])]
    private string $totalCargos = '0.00';

    /**
     * Cache de la suma de nuestros pagos propios (PmsPagoFinanciero), convertidos a esta moneda.
     * Recalculado por PmsInformacionFinancieraCoherenciaListener.
     */
    #[ORM\Column(name: 'total_pagos', type: 'decimal', precision: 10, scale: 2, options: ['default' => '0.00'])]
    #[Groups(['pms_finanzas:read'])]
    private string $totalPagos = '0.00';

    /**
     * ¿Los cargos de la estancia siguen contando para el saldo?
     *
     * Se separa a propósito del estado de la reserva en Beds24, porque no siempre coinciden:
     * un huésped que negocia pasar a **reserva directa** cancela en la OTA, y Beds24 manda
     * `status: cancelled` con `price: 0` — pero la estancia SÍ ocurre y hay que cobrarla.
     *
     * - `true`  → suman todos los cargos (caso normal, y también el de la directa negociada).
     * - `false` → sólo suma la PENALIZACIÓN (el "Cancel Fee"): es lo que de verdad se debe
     *   tras una cancelación real. Los cargos de la estancia **no se borran**, siguen en la
     *   BD y visibles en el panel; simplemente dejan de computar.
     *
     * Lo baja automáticamente `PmsInformacionFinancieraCoherenciaListener` al detectar la
     * TRANSICIÓN a cancelada, pero el operador puede volver a subirlo y su decisión se
     * respeta: los webhooks repetidos no vuelven a tocarlo (ver §12.7).
     */
    #[ORM\Column(type: 'boolean', options: ['default' => true])]
    #[Groups(['pms_finanzas:read', 'pms_finanzas:write'])]
    private bool $activa = true;

    #[ORM\Column(name: 'last_synced_at', type: 'datetime', nullable: true)]
    #[Groups(['pms_finanzas:read'])]
    private ?DateTimeInterface $lastSyncedAt = null;

    /** @var Collection<int, PmsCargoFinanciero> */
    #[ORM\OneToMany(mappedBy: 'informacionFinanciera', targetEntity: PmsCargoFinanciero::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['fechaCreacionBeds24' => 'ASC'])]
    #[Groups(['pms_finanzas:read'])]
    private Collection $cargos;

    /**
     * Pagos efectivamente recibidos por nosotros (efectivo, yape, tarjeta, etc.).
     * A diferencia de los cargos (que vienen de Beds24), los pagos son registros propios.
     *
     * @var Collection<int, PmsPagoFinanciero>
     */
    #[ORM\OneToMany(mappedBy: 'informacionFinanciera', targetEntity: PmsPagoFinanciero::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['fechaPago' => 'ASC'])]
    #[Groups(['pms_finanzas:read'])]
    private Collection $pagos;

    public function __construct()
    {
        $this->id = Uuid::v7();
        $this->cargos = new ArrayCollection();
        $this->pagos = new ArrayCollection();
    }

    // =========================================================================
    // GETTERS Y SETTERS
    // =========================================================================

    #[Groups(['pms_finanzas:read'])]
    public function getId(): ?Uuid { return $this->id; }

    public function getReserva(): ?PmsReserva { return $this->reserva; }
    public function setReserva(?PmsReserva $reserva): self { $this->reserva = $reserva; return $this; }

    public function getMoneda(): ?MaestroMoneda { return $this->moneda; }
    public function setMoneda(?MaestroMoneda $moneda): self { $this->moneda = $moneda; return $this; }

    public function getTotalCargos(): string { return $this->totalCargos; }
    public function setTotalCargos(string $totalCargos): self { $this->totalCargos = $totalCargos; return $this; }

    public function getTotalPagos(): string { return $this->totalPagos; }
    public function setTotalPagos(string $totalPagos): self { $this->totalPagos = $totalPagos; return $this; }

    /**
     * Saldo pendiente en la moneda de la cabecera: cargos menos pagos (ambos ya convertidos
     * a esta moneda por el listener de coherencia). Cálculo en tiempo de lectura.
     */
    #[Groups(['pms_finanzas:read'])]
    public function getSaldo(): string
    {
        return number_format((float) $this->totalCargos - (float) $this->totalPagos, 2, '.', '');
    }

    /**
     * Prepago que todavía hay que pedir. Transitorio: NO es una columna.
     *
     * Lo calcula `PmsPrepagoCalculador::pendiente()` y lo inyecta
     * `PmsInformacionFinancieraPorReservaProvider`, igual que `PmsReservaPaxProvider` hace
     * con el resumen del huésped. No puede vivir dentro de la entidad porque depende de la
     * política del establecimiento virtual y de las noches de la reserva, y ese cálculo es
     * un servicio.
     *
     * ⚠️ Sólo lo rellena la operación `por-reserva`, que es la que usa el panel. En un
     * `GET` por id o en la colección llega `null` — no es que no haya prepago, es que ahí
     * nadie lo ha calculado. Si algún día hace falta en esas operaciones, se decoran igual.
     *
     * No viaja la `claveI18n` que devuelve el calculador: es una clave del diccionario de
     * `pax`, que se resuelve en el navegador del huésped. Al panel va `politicaEtiqueta`,
     * que sale de `PmsPoliticaPrepago::etiqueta()` — así la etiqueta sigue teniendo una
     * sola fuente y no hay que duplicarla en TypeScript.
     *
     * @var array{monto: string, politica: string, politicaEtiqueta: string, politicaCorta: string, concepto: string}|null
     */
    private ?array $prepagoPendiente = null;

    // La forma se declara a mano: de un `?array` API Platform deduce `string[]`, y el tipo
    // que openapi-typescript genera para `util` sale inservible (`.monto` no existe en un
    // array de strings). Con esto el espejo TS se deriva del esquema como los demás.
    #[ApiProperty(openapiContext: [
        'type' => 'object',
        'nullable' => true,
        'description' => 'Prepago que todavía hay que pedir, o null si no procede.',
        // Sin `required`, openapi-typescript marca TODO como opcional y el espejo TS obliga
        // a comprobar un `monto` que siempre viaja cuando el objeto existe.
        'required' => ['monto', 'politica'],
        'properties' => [
            'monto' => ['type' => 'string', 'example' => '45.00'],
            'politica' => ['type' => 'string', 'example' => 'primera_noche_total'],
            'politicaEtiqueta' => ['type' => 'string', 'example' => 'Primera noche (sobre el total)'],
            'politicaCorta' => ['type' => 'string', 'example' => 'Primera noche'],
            'concepto' => ['type' => 'string', 'example' => 'Adelanto de reserva AB12CD — Casita 4'],
        ],
    ])]
    /**
     * @return array<string, mixed>|null
     *
     * @return array<string, mixed>|null
     */
    #[Groups(['pms_finanzas:read'])]
    public function getPrepagoPendiente(): ?array { return $this->prepagoPendiente; }
    /**
     * @param array<string, mixed>|null $prepago
     */
    public function setPrepagoPendiente(?array $prepago): self { $this->prepagoPendiente = $prepago; return $this; }

    /**
     * Suma de los cargos de un tipo concreto, en la moneda de la cabecera.
     *
     * Respeta la misma regla que el rollup (§12.7): si la reserva está anulada sólo cuenta la
     * PENALIZACIÓN. Se usa para desglosar el importe en las plantillas de mensajería
     * (alojamiento / limpieza / servicio) sin que el operador tenga que escribirlo a mano.
     */
    public function getTotalPorTipo(PmsTipoCargo $tipo): string
    {
        return $this->getDesglosePorTipo()[$tipo->value] ?? '0.00';
    }

    /**
     * Desglose de los cargos agrupado por `PmsTipoCargo`, en orden de lectura
     * (alojamiento → limpieza → servicio → penalización → otro).
     *
     * Fuente ÚNICA de las tres reglas del desglose, que antes solo vivían en
     * getTotalPorTipo() —hoy un envoltorio de este método— y que se habrían
     * duplicado al necesitarlas también el estado de cuenta del huésped:
     *
     *  1. **Anulación (§12.7)**: con `activa = false` sólo cuenta la PENALIZACIÓN.
     *  2. **`esCargo()`**: la colección también trae filas `payment` de Beds24.
     *  3. **`totalLinea ?? monto`**: el webhook no siempre manda la primera.
     *  4. **Conversión a la moneda de la cabecera**, con el TC congelado en cada
     *     cargo. Sin esto las líneas no sumarían `total_cargos` en una reserva con
     *     cargos en soles y en dólares, y quien lo lea hará la cuenta.
     *
     * Los tipos sin importe NO aparecen en el resultado: quien lo pinta no tiene
     * que filtrar ceros, y `getTotalPorTipo()` los resuelve con su `?? '0.00'`.
     *
     * @param bool $excluirEspejoCanal Deja fuera los cargos marcados como espejo
     *   contable del canal (Airbnb/VRBO). Lo usa el resumen del huésped, que no
     *   puede enseñar lo que la OTA nos remite. Ver PmsCargoFinanciero::$esAutomatico.
     *
     * @return array<string, string> valor del enum => importe con 2 decimales
     */
    public function getDesglosePorTipo(bool $excluirEspejoCanal = false): array
    {
        $acumulado = [];

        foreach ($this->cargos as $cargo) {
            if (!$cargo->esCargo()) {
                continue;
            }
            if ($excluirEspejoCanal && $cargo->isEsAutomatico()) {
                continue;
            }

            $tipo = $cargo->getTipoCargo() ?? PmsTipoCargo::OTRO;

            if (!$this->activa && $tipo !== PmsTipoCargo::PENALIZACION) {
                continue;
            }

            $acumulado[$tipo->value] = ($acumulado[$tipo->value] ?? 0.0) + $this->aMonedaBase(
                (float) ($cargo->getTotalLinea() ?? $cargo->getMonto() ?? '0'),
                $cargo->getMoneda()?->getId(),
                $cargo->getTipoCambio(),
            );
        }

        // Orden fijo de presentación, no el de llegada de los cargos.
        $desglose = [];
        foreach (PmsTipoCargo::cases() as $tipo) {
            if (isset($acumulado[$tipo->value])) {
                $desglose[$tipo->value] = number_format($acumulado[$tipo->value], 2, '.', '');
            }
        }

        return $desglose;
    }

    /**
     * Total de los cargos que COBRÓ EL CANAL por nosotros, en la moneda de la cabecera.
     *
     * "Del canal" = los marcados como espejo contable (`PmsCargoFinanciero::$esAutomatico`,
     * que el persister de invoiceItems pone en los canales de `PmsChannel::CANAL_PAGO_TOTAL`).
     * Es el importe que cuadra el depósito automático (`PmsPagoOtaAutomaticoService`): la OTA
     * deposita LO SUYO, no lo que el operador cargue a mano. Antes el depósito seguía a
     * `totalCargos` entero, y en una reserva mixta —estancia de Airbnb más una ampliación
     * directa en la misma reserva— se tragaba los cargos manuales: el saldo volvía a cero
     * hiciera lo que hiciera el operador, y registrar el pago real lo dejaba en negativo.
     *
     * Aplica las mismas reglas que el rollup (§12.2/§12.7): `esCargo()`, con la reserva
     * anulada sólo cuenta la penalización, `totalLinea ?? monto`, y conversión a la moneda
     * de la cabecera con el TC congelado en cada línea.
     */
    public function getTotalCargosDelCanal(): string
    {
        $total = 0.0;

        foreach ($this->cargos as $cargo) {
            if (!$cargo->esCargo() || !$cargo->isEsAutomatico()) {
                continue;
            }

            $tipo = $cargo->getTipoCargo() ?? PmsTipoCargo::OTRO;
            if (!$this->activa && $tipo !== PmsTipoCargo::PENALIZACION) {
                continue;
            }

            $total += $this->aMonedaBase(
                (float) ($cargo->getTotalLinea() ?? $cargo->getMonto() ?? '0'),
                $cargo->getMoneda()?->getId(),
                $cargo->getTipoCambio(),
            );
        }

        return number_format($total, 2, '.', '');
    }

    /**
     * Las MISMAS líneas que `getDesglosePorTipo()`, pero sin agrupar.
     *
     * Existe porque el estado de cuenta del huésped pasó a enseñar el detalle: agrupado por
     * tipo, un ajuste de cuadre de −0.20 aparecía sumado dentro de «Otros» y era imposible
     * de interpretar. Aquí cada cargo va con su descripción redactada para él.
     *
     * ⚠️ Aplica las CUATRO reglas del desglose y no puede separarse de él: anulación (§12.7),
     * `esCargo()`, `totalLinea ?? monto` y la conversión a la moneda de la cabecera. Si se
     * toca una allí, hay que tocarla aquí — por eso viven las dos en esta entidad y no en
     * quien las pinta.
     *
     * La descripción se devuelve CRUDA (`I18nContent[]`), sin resolver idioma: quien pinta
     * ya sabe en cuál está —el front tiene `maestroStore.traducir()`— y resolverlo aquí
     * obligaría a arrastrar el idioma del huésped hasta la entidad.
     *
     * El orden es el de llegada de los cargos, no el de presentación por tipo: en el detalle
     * lo que se lee es la secuencia de lo que se fue cobrando.
     *
     * @param bool $excluirEspejoCanal Igual que en getDesglosePorTipo().
     * @return list<array{tipo: string, descripcion: list<array{content: string, language: string}>, monto: string}>
     */
    public function getLineasCliente(bool $excluirEspejoCanal = false): array
    {
        $lineas = [];

        foreach ($this->cargos as $cargo) {
            if (!$cargo->esCargo()) {
                continue;
            }
            if ($excluirEspejoCanal && $cargo->isEsAutomatico()) {
                continue;
            }

            $tipo = $cargo->getTipoCargo() ?? PmsTipoCargo::OTRO;

            if (!$this->activa && $tipo !== PmsTipoCargo::PENALIZACION) {
                continue;
            }

            $monto = $this->aMonedaBase(
                (float) ($cargo->getTotalLinea() ?? $cargo->getMonto() ?? '0'),
                $cargo->getMoneda()?->getId(),
                $cargo->getTipoCambio(),
            );

            $lineas[] = [
                'tipo' => $tipo->value,
                'descripcion' => $cargo->getDescripcionCliente(),
                'monto' => number_format($monto, 2, '.', ''),
            ];
        }

        return $lineas;
    }

    /**
     * Versión pública y ya formateada de aMonedaBase(), para las líneas que no
     * pasan por getDesglosePorTipo() — los pagos del estado de cuenta del huésped.
     */
    public function convertirAMonedaBase(float $monto, ?string $monedaLinea, ?string $tipoCambio): string
    {
        return number_format($this->aMonedaBase($monto, $monedaLinea, $tipoCambio), 2, '.', '');
    }

    /**
     * Importe llevado a la moneda de la cabecera con el TC congelado en la línea.
     *
     * Espejo EXACTO de PmsInformacionFinancieraRecalculoService::expresionConvertida(),
     * que es la que produce `total_cargos` / `total_pagos` en SQL. **Si cambia una,
     * cambia la otra**, o el desglose dejará de sumar el total que se muestra al lado.
     *
     * Sin moneda se asume USD (regla de negocio: Beds24 no manda moneda en los
     * invoiceItems). Un par de monedas no contemplado se deja sin convertir, igual
     * que el `ELSE` del SQL: es preferible una cifra sin convertir a un cero.
     */
    private function aMonedaBase(float $monto, ?string $monedaLinea, ?string $tipoCambio): float
    {
        $origen = $monedaLinea ?? 'USD';
        $base = $this->moneda?->getId() ?? 'USD';
        $tc = (float) ($tipoCambio ?? '0');

        if ($origen === $base) {
            return $monto;
        }
        if ($origen === 'USD' && $base === 'PEN') {
            return $monto * $tc;
        }
        if ($origen === 'PEN' && $base === 'USD') {
            return $tc !== 0.0 ? $monto / $tc : 0.0;
        }

        return $monto;
    }

    /**
     * ¿Se puede cambiar la moneda base (contable) de esta reserva?
     *
     * Sólo en reservas DIRECTAS PURAS. El criterio no es el canal de la reserva sino **el origen
     * de los cargos**, que es lo que de verdad importa: si algún cargo vino de Beds24, su moneda
     * la manda el canal y reescribirla falsearía la verdad histórica (§11). Un cargo sincronizado
     * es, además, imposible de borrar para deshacer el estropicio.
     *
     * Se serializa para que la SPA sepa si pintar el selector; el backend lo vuelve a comprobar
     * en `PmsMonedaBaseService::cambiar()`, que es la defensa real.
     */
    #[Groups(['pms_finanzas:read'])]
    public function isMonedaBaseEditable(): bool
    {
        foreach ($this->cargos as $cargo) {
            if (!$cargo->isManual()) {
                return false;
            }
        }

        foreach ($this->reserva?->getEventosCalendario() ?? [] as $evento) {
            if ($evento->isOta()) {
                return false;
            }
        }

        return true;
    }

    #[Groups(['pms_finanzas:read'])]
    public function getTotalAlojamiento(): string { return $this->getTotalPorTipo(PmsTipoCargo::ALOJAMIENTO); }

    #[Groups(['pms_finanzas:read'])]
    public function getTotalLimpieza(): string { return $this->getTotalPorTipo(PmsTipoCargo::LIMPIEZA); }

    #[Groups(['pms_finanzas:read'])]
    public function getTotalServicio(): string { return $this->getTotalPorTipo(PmsTipoCargo::SERVICIO); }

    public function isActiva(): bool { return $this->activa; }
    public function setActiva(bool $activa): self { $this->activa = $activa; return $this; }

    public function getLastSyncedAt(): ?DateTimeInterface { return $this->lastSyncedAt; }
    public function setLastSyncedAt(?DateTimeInterface $at): self { $this->lastSyncedAt = $at; return $this; }

    /**
     * @return Collection<int, PmsCargoFinanciero>
     */
    public function getCargos(): Collection { return $this->cargos; }

    public function addCargo(PmsCargoFinanciero $cargo): self
    {
        if (!$this->cargos->contains($cargo)) {
            $this->cargos->add($cargo);
            $cargo->setInformacionFinanciera($this);
        }
        return $this;
    }

    public function removeCargo(PmsCargoFinanciero $cargo): self
    {
        if ($this->cargos->removeElement($cargo)) {
            if ($cargo->getInformacionFinanciera() === $this) {
                $cargo->setInformacionFinanciera(null);
            }
        }
        return $this;
    }

    /**
     * @return Collection<int, PmsPagoFinanciero>
     */
    public function getPagos(): Collection { return $this->pagos; }

    public function addPago(PmsPagoFinanciero $pago): self
    {
        if (!$this->pagos->contains($pago)) {
            $this->pagos->add($pago);
            $pago->setInformacionFinanciera($this);
        }
        return $this;
    }

    public function removePago(PmsPagoFinanciero $pago): self
    {
        if ($this->pagos->removeElement($pago)) {
            if ($pago->getInformacionFinanciera() === $this) {
                $pago->setInformacionFinanciera(null);
            }
        }
        return $this;
    }

    /**
     * Estancias de la reserva indexadas por su ID de booking en Beds24.
     *
     * Necesario para las RESERVAS AGRUPADAS (§11.6): un grupo de Booking.com genera una sola
     * cabecera con los cargos de varios bookings mezclados, y lo único que los distingue es
     * `PmsCargoFinanciero.beds24BookingId`. Sin este mapa el panel mostraría dos veces
     * "[ROOMNAME1] [FIRSTNIGHT] - [LEAVINGDAY]" (Beds24 no resuelve la plantilla) sin forma
     * de saber qué casita es cada una.
     *
     * Se devuelve SIEMPRE una entrada por estancia, tenga o no booking en Beds24: en una
     * reserva directa no hay `beds24BookingId`, pero la estancia existe igual y sus cargos
     * manuales se le imputan por `eventoId`. Ese `eventoId` es la clave con la que la UI
     * agrupa, venga el cargo del canal o lo haya escrito un operador.
     *
     * @return array<int, array{eventoId: string, beds24BookingId: string|null, unidad: string|null, inicio: string|null, fin: string|null}>
     */
    #[Groups(['pms_finanzas:read'])]
    public function getEstancias(): array
    {
        $estancias = [];

        foreach ($this->reserva?->getEventosCalendario() ?? [] as $evento) {
            $bookId = null;

            foreach ($evento->getBeds24Links() as $link) {
                // Sólo el link principal lleva el bookId real; los espejos nacen sin él (§6.3).
                if ($link->isEsPrincipal() && $link->getBeds24BookId() !== null) {
                    $bookId = $link->getBeds24BookId();
                    break;
                }
            }

            $estancias[] = [
                'eventoId' => (string) $evento->getId(),
                'beds24BookingId' => $bookId,
                'unidad' => $evento->getPmsUnidad()?->getNombre(),
                'inicio' => $evento->getInicio()?->format('Y-m-d'),
                'fin' => $evento->getFin()?->format('Y-m-d'),
            ];
        }

        return $estancias;
    }

    // NOTA: total_cargos y total_pagos NO se recalculan aquí. Los mantiene
    // PmsInformacionFinancieraCoherenciaListener (Doctrine onFlush/postFlush), que convierte
    // cada cargo/pago a la moneda de esta cabecera con su propio tipo de cambio antes de sumar.
    // Ver PmsInformacionFinancieraRecalculoService.
}
