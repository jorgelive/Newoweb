<?php

declare(strict_types=1);

namespace App\Pms\Service\Message;

use App\Contract\ConversationMilestoneInterface;
use App\Contract\MapaDeHitos;
use App\Message\Contract\MessageContextInterface;
use App\Message\Enum\IdentidadTipo;
use App\Contract\VinculoComercial;
use App\Pms\Entity\PmsEventoEstado;
use App\Pms\Entity\PmsInformacionFinanciera;
use App\Pms\Entity\PmsReserva;
use App\Pms\Service\Finance\PmsTotalesPorMoneda;
use DateTimeImmutable;
use DateTimeZone;
use Throwable;

/**
 * Patrón Adaptador: Envuelve una entidad PmsReserva para que cumpla
 * con el contrato genérico que el módulo de Mensajes espera.
 * * Actúa como puente traduciendo la complejidad del PMS (reservas, estados, fechas)
 * a un contexto plano y agnóstico consumible por el motor de plantillas.
 */
class PmsReservaMessageContext implements MessageContextInterface
{
    /**
     * La cabecera financiera se INYECTA en vez de resolverse aquí dentro: este adaptador no
     * tiene EntityManager, y añadir la relación inversa en PmsReserva obligaría a Doctrine a
     * cargar la cabecera en cada hidratación de reserva (el lado inverso de un OneToOne no se
     * puede proxiar), lo que penalizaría el calendario entero por un dato que sólo usa el chat.
     * Si llega null se cae al `montoTotal` de la reserva, que es lo que había antes.
     */
    public function __construct(
        private readonly PmsReserva $reserva,
        private readonly ?PmsInformacionFinanciera $informacionFinanciera = null
    ) {}

    // =========================================================================
    // IDENTIFICADORES Y CONTACTO BASE
    // =========================================================================

    /**
     * Define la familia del contexto para aislar reglas de negocio.
     */
    public function getContextType(): string { return 'pms_reserva'; }

    /**
     * Identificador único de la entidad subyacente.
     */
    public function getContextId(): string { return (string) $this->reserva->getId(); }

    /**
     * Idioma preferido del huésped para la selección de plantillas traducidas.
     */
    public function getContextLanguage(): string {
        return $this->reserva->getIdioma()?->getId() ?? 'en';
    }

    /**
     * Nombre compuesto del huésped.
     */
    public function getContextName(): ?string
    {
        return trim($this->reserva->getNombreCliente() . ' ' . $this->reserva->getApellidoCliente());
    }

    /**
     * Teléfono primario o secundario para envíos por WhatsApp Meta.
     */
    public function getContextPhone(): ?string
    {
        return $this->reserva->getTelefonoContacto();
    }

    /**
     * Por dónde se alcanza a este huésped.
     *
     * ⚠️ **El `bookId` de Beds24 es el importante.** Una reserva de OTA nace sin teléfono y sin
     * correo —el huésped todavía no ha escrito— y aun así se le puede escribir: la salida por
     * Beds24 se dirige con él. Sin registrarlo, ese hilo no tendría ningún identificador.
     *
     * El correo también, cuando lo hay: es por donde llegarán las respuestas del buzón.
     *
     * @return array<string, string>
     */
    public function getIdentificadores(): array
    {
        $identificadores = [];

        if (($telefono = $this->reserva->getTelefonoContacto()) !== null && trim($telefono) !== '') {
            $identificadores[IdentidadTipo::TELEFONO->value] = $telefono;
        }

        if (($email = $this->reserva->getEmailCliente()) !== null && trim($email) !== '') {
            $identificadores[IdentidadTipo::EMAIL->value] = $email;
        }

        if (($bookId = $this->reserva->getBeds24BookIdPrincipal()) !== null && trim($bookId) !== '') {
            $identificadores[IdentidadTipo::BEDS24->value] = $bookId;
        }

        return $identificadores;
    }

    // =========================================================================
    // DICCIONARIO AGNÓSTICO PARA EL JSON
    // =========================================================================

    /**
     * Origen principal de la reserva (ej. Airbnb, Booking, Directo).
     */
    public function getOrigin(): ?string
    {
        return $this->reserva->getChannel()?->getId() ?? 'directo';
    }

    /**
     * El PMS todavía no modela agencias mayoristas sobre la reserva.
     *
     * Devolver null es deliberado y NO es un "pendiente silencioso": hace que las reglas
     * con `allowedAgencies` configuradas no apliquen a reservas del PMS, en vez de que el
     * filtro se ignore. Cuando exista la relación, se devuelve aquí su identificador.
     */
    public function getAgencyId(): ?string
    {
        return null;
    }

    /**
     * Etiqueta de estado simplificada para renderizado rápido en el UI del Chat
     * y filtros de reglas en el RuleEngine.
     */
    public function getStatusTag(): ?string
    {
        if ($this->isCancelled()) {
            return 'cancelled';
        }

        if ($this->isAbiertoOrBloqueo()) {
            return 'inquiry';
        }

        return 'confirmed';
    }

    /**
     * Qué hace cliente a alguien EN ALOJAMIENTO: que su reserva esté en firme.
     *
     * Una solicitud abierta o un bloqueo todavía no lo son —es el caso de las consultas de
     * OTA, donde nadie ha reservado nada— y una cancelada dejó de serlo. Otro dominio puede
     * medirlo por otra cosa: en un tour, lo natural sería el pago. Ese es justamente el motivo
     * de que la pregunta la responda cada contexto y no el agente.
     */
    public function getVinculo(): VinculoComercial
    {
        if ($this->isCancelled()) {
            return VinculoComercial::Terminado;
        }

        return $this->isAbiertoOrBloqueo()
            ? VinculoComercial::Interesado
            : VinculoComercial::Cliente;
    }

    /**
     * Genera el diccionario agnóstico de hitos cronológicos (Fechas clave).
     * Estos hitos son el núcleo matemático con el que el MessageRuleEngine calcula los offsets de envío.
     *
     */
    public function getMilestones(): MapaDeHitos
    {
        // 🔥 CORTAFUEGOS ANTI-SPAM PARA INQUIRIES Y BLOQUEOS
        if ($this->isAbiertoOrBloqueo()) {
            return MapaDeHitos::vacio();
        }

        //TODO: Refactor: poner todo en UTC para mensajes
        $tzLima = new DateTimeZone('America/Lima');

        // 🔹 HORAS
        $horaCheckInRaw  = $this->reserva->getEstablecimiento()?->getHoraCheckIn();
        $horaCheckOutRaw = $this->reserva->getEstablecimiento()?->getHoraCheckOut();

        $horaCheckInStr  = $horaCheckInRaw instanceof \DateTimeInterface ? $horaCheckInRaw->format('H:i:s') : (string) ($horaCheckInRaw ?: '14:00:00');
        $horaCheckOutStr = $horaCheckOutRaw instanceof \DateTimeInterface ? $horaCheckOutRaw->format('H:i:s') : (string) ($horaCheckOutRaw ?: '10:00:00');

        // 🔹 PARSEO
        [$hIn, $mIn, $sIn]    = array_map('intval', explode(':', $horaCheckInStr));
        [$hOut, $mOut, $sOut] = array_map('intval', explode(':', $horaCheckOutStr));

        // 🔹 START / END (🚨 CORRECCIÓN DEL CLONE Y DEL SETTIME)
        $start = null;
        $llegadaOrig = $this->reserva->getFechaLlegada();
        if ($llegadaOrig instanceof \DateTimeInterface) {
            $start = clone $llegadaOrig;
            // Se reasigna por si el objeto es DateTimeImmutable
            $start = $start->setTime($hIn, $mIn, $sIn);
        }

        $end = null;
        $salidaOrig = $this->reserva->getFechaSalida();
        if ($salidaOrig instanceof \DateTimeInterface) {
            $end = clone $salidaOrig;
            // Se reasigna por si el objeto es DateTimeImmutable
            $end = $end->setTime($hOut, $mOut, $sOut);
        }

        // 🔹 CREATED (caso mixto)
        $created = null;
        if ($this->reserva->getPrimeraFechaReservaCanal() !== null) {
            $fechaCanal = $this->reserva->getPrimeraFechaReservaCanal();
            $createdUtc = new \DateTimeImmutable($fechaCanal->format('Y-m-d H:i:s'), new \DateTimeZone('UTC'));
            $createdLima = $createdUtc->setTimezone($tzLima);
            $created = new \DateTimeImmutable($createdLima->format('Y-m-d H:i:s'));
        } else {
            $createdAt = $this->reserva->getCreatedAt();
            if ($createdAt instanceof \DateTime) {
                $created = \DateTimeImmutable::createFromMutable($createdAt);
            } else {
                $created = $createdAt;
            }
        }

        // 🔹 RESULTADO
        $milestones = [];
        if ($start) $milestones[ConversationMilestoneInterface::START] = $start;
        if ($end) $milestones[ConversationMilestoneInterface::END] = $end;
        if ($created) $milestones[ConversationMilestoneInterface::CREATED] = $created;

        // 🔥 Llegada Esperada (Expected Arrival)
        $expectedArrivalRaw = $this->reserva->getHoraLlegadaCanalAggregate();

        if ($expectedArrivalRaw) {
            if ($expectedArrivalRaw instanceof \DateTimeInterface) {
                $milestones[ConversationMilestoneInterface::EXPECTED_ARRIVAL] = $expectedArrivalRaw;
            } else {
                // 🚨 CORRECCIÓN DEL CLONE AQUÍ TAMBIÉN
                $fechaLlegada = $this->reserva->getFechaLlegada();
                if ($fechaLlegada instanceof \DateTimeInterface) {
                    // `horaLlegadaCanalAggregate` es un GROUP_CONCAT de todos los eventos de la
                    // reserva (ver PmsReservaRecalculoService, separador ' | '). Con dos unidades
                    // que informaron ETA distinta llegaba "14:00 | 16:00", el parseo reventaba y
                    // el hito desaparecía sin rastro. Nos quedamos con la hora MÁS TEMPRANA: es
                    // cuando el huésped aparece por recepción, que es lo que dispara el mensaje.
                    $horasCandidatas = array_filter(array_map('trim', explode('|', (string) $expectedArrivalRaw)));
                    $fechaString = $fechaLlegada->format('Y-m-d');
                    $masTemprana = null;

                    foreach ($horasCandidatas as $horaLimpia) {
                        try {
                            $candidata = new \DateTimeImmutable("$fechaString $horaLimpia");
                        } catch (Throwable) {
                            continue; // Texto libre del canal ("late night"): no es una hora.
                        }

                        if ($masTemprana === null || $candidata < $masTemprana) {
                            $masTemprana = $candidata;
                        }
                    }

                    if ($masTemprana !== null) {
                        $milestones[ConversationMilestoneInterface::EXPECTED_ARRIVAL] = $masTemprana;
                    }
                }
            }
        }

        if ($this->isCancelled() && $this->reserva->getUltimaFechaModificacionCanal()) {
            $milestones[ConversationMilestoneInterface::CANCELLED] = $this->reserva->getUltimaFechaModificacionCanal();
        }

        return MapaDeHitos::desdeCrudo($milestones);
    }

    /**
     * Unidades habitacionales asignadas a la reserva.
     */
    public function getItems(): array
    {
        $unidadesString = $this->reserva->getUnidadesAggregate();
        if (!$unidadesString) {
            return [];
        }
        return array_map('trim', explode(',', $unidadesString));
    }

    /**
     * Monto financiero total de la reserva.
     *
     * Sale de la cabecera financiera (§12): `montoTotal` sólo se rellena en las OTA, así que
     * en una reserva directa daba 0 aunque tuviera cargos registrados.
     */
    /**
     * ⚠️ Sigue siendo UN float sin moneda, y no se puede cambiar sin arrastrar medio módulo: es
     * contrato de `MessageContextInterface`, se persiste en `MessageConversation::$contextFinancialTotal`
     * (columna float) y viaja por Mercure en `MercureConversationDto`.
     *
     * Con la contabilidad por moneda (§12.2b) eso ya no es «el total»: es el de la moneda con más
     * cargos. Sirve para lo que se usa —ordenar y filtrar hilos— y no para decirle una cifra a
     * nadie. Quien tenga que hablar de dinero usa `consultar_cuenta`, que lo da desglosado.
     */
    public function getFinancialTotal(): ?float
    {
        $info = $this->informacionFinanciera;

        if ($info === null) {
            return (float) $this->reserva->getMontoTotal();
        }

        $cargos = array_map(
            static fn (array $c): float => (float) $c['cargos'],
            PmsTotalesPorMoneda::de($info)->porMoneda,
        );

        return $cargos === [] ? 0.0 : max($cargos);
    }

    /**
     * Indica si la reserva ya ha sido pagada en su totalidad.
     *
     * Exige que HAYA algo que cobrar: sin cargos el saldo también es cero, y dar por saldada
     * una reserva recién creada haría que el chat saltara mensajes de cobro.
     */
    public function isFinancialCleared(): bool
    {
        $info = $this->informacionFinanciera;

        if ($info === null) {
            return false;
        }

        // Por moneda, y con la tolerancia del cuadre: una reserva pagada en soles una deuda en
        // dólares está saldada aunque sobren diez céntimos de redondeo del cambio, y si el chat
        // no lo entiende así le sigue mandando recordatorios de cobro a alguien que ya pagó.
        $totales = PmsTotalesPorMoneda::de($info);

        return $totales->hayCargos() && $totales->cuadra();
    }

    // =========================================================================
    // REGLAS DE NEGOCIO DEL CHAT Y VALIDACIONES DE ESTADO
    // =========================================================================

    /**
     * Indica si la reserva está anulada financieramente y operativamente en el PMS.
     */
    public function isCancelled(): bool
    {
        return $this->reserva->isTotalmenteCancelada();
    }

    /**
     * Evalúa de manera estricta y tipada si la reserva es exclusivamente
     * un inquiry (estado abierto) o un bloqueo de calendario.
     * * Al tener acceso al modelo exacto, evaluamos la colección de Doctrine real.
     *
     * @return bool True si es puramente inquiry o bloqueo, False en caso de tener reservas vivas o estar vacía.
     */
    public function isAbiertoOrBloqueo(): bool
    {
        $eventos = $this->reserva->getEventosCalendario();

        // Si no hay eventos, no podemos catalogarlo como inquiry/bloqueo (es un draft o error)
        if ($eventos->isEmpty()) {
            return false;
        }

        foreach ($eventos as $evento) {
            $estadoId = $evento->getEstado()?->getId();

            // Si encontramos un solo evento con un estado que NO sea Inquiry o Bloqueo
            // (ej. Confirmada, Pendiente), la reserva general deja de ser un mero bloqueo.
            if ($estadoId !== PmsEventoEstado::CODIGO_ABIERTO && $estadoId !== PmsEventoEstado::CODIGO_BLOQUEO) {
                return false;
            }
        }

        return true;
    }

    /**
     * Evalúa si la reserva es exclusivamente un bloqueo de calendario (agenda cerrada,
     * mantenimiento, sync de canal sin huésped), sin mezclar el caso de inquiry ("abierto").
     * * A diferencia de isAbiertoOrBloqueo(), los inquiries (ej. consultas de Airbnb) NO
     * cuentan aquí: sí deben generar/actualizar su conversación de chat, porque representan
     * contacto real de un huésped potencial.
     *
     * @return bool True si TODOS los eventos son bloqueo puro, False si hay algún evento
     *              de otro tipo (incluido "abierto") o si la colección está vacía.
     */
    public function isSoloBloqueo(): bool
    {
        $eventos = $this->reserva->getEventosCalendario();

        if ($eventos->isEmpty()) {
            return false;
        }

        foreach ($eventos as $evento) {
            if ($evento->getEstado()?->getId() !== PmsEventoEstado::CODIGO_BLOQUEO) {
                return false;
            }
        }

        return true;
    }
}