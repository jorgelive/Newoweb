<?php

declare(strict_types=1);

namespace App\Pms\Service\Message;

use App\Message\Contract\ConversationMilestoneInterface;
use App\Message\Contract\MessageContextInterface;
use App\Pms\Entity\PmsEventoEstado;
use App\Pms\Entity\PmsReserva;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Patrón Adaptador: Envuelve una entidad PmsReserva para que cumpla
 * con el contrato genérico que el módulo de Mensajes espera.
 * * Actúa como puente traduciendo la complejidad del PMS (reservas, estados, fechas)
 * a un contexto plano y agnóstico consumible por el motor de plantillas.
 */
class PmsReservaMessageContext implements MessageContextInterface
{
    public function __construct(
        private readonly PmsReserva $reserva
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
        return $this->reserva->getTelefono() ?? $this->reserva->getTelefono2();
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
     * Genera el diccionario agnóstico de hitos cronológicos (Fechas clave).
     * Estos hitos son el núcleo matemático con el que el MessageRuleEngine calcula los offsets de envío.
     *
     * @return array<string, \DateTimeInterface>
     */
    public function getMilestones(): array
    {
        // 🔥 CORTAFUEGOS ANTI-SPAM PARA INQUIRIES Y BLOQUEOS
        // Si es una consulta abierta (inquiry) o un bloqueo de calendario, vaciamos intencionalmente los hitos.
        // Al retornar un array vacío, el MessageRuleEngine carecerá de un START válido,
        // lo que matemáticamente imposibilita la creación y programación de plantillas automáticas.
        if ($this->isAbiertoOrBloqueo()) {
            return [];
        }

        //TODO: Refactor: poner todo en UTC para mensajes
        $tzLima = new DateTimeZone('America/Lima');

        // 🔹 HORAS (Manejando tanto si Doctrine devuelve DateTime como si devuelve String)
        $horaCheckInRaw  = $this->reserva->getEstablecimiento()?->getHoraCheckIn();
        $horaCheckOutRaw = $this->reserva->getEstablecimiento()?->getHoraCheckOut();

        $horaCheckInStr  = $horaCheckInRaw instanceof \DateTimeInterface ? $horaCheckInRaw->format('H:i:s') : (string) ($horaCheckInRaw ?: '14:00:00');
        $horaCheckOutStr = $horaCheckOutRaw instanceof \DateTimeInterface ? $horaCheckOutRaw->format('H:i:s') : (string) ($horaCheckOutRaw ?: '10:00:00');

        // 🔹 PARSEO
        [$hIn, $mIn, $sIn]    = array_map('intval', explode(':', $horaCheckInStr));
        [$hOut, $mOut, $sOut] = array_map('intval', explode(':', $horaCheckOutStr));

        // 🔹 START / END
        $start = clone $this->reserva->getFechaLlegada();
        if ($start) {
            $start->setTime($hIn, $mIn, $sIn);
        }

        $end = clone $this->reserva->getFechaSalida();
        if ($end) {
            $end->setTime($hOut, $mOut, $sOut);
        }

        // 🔹 CREATED (caso mixto)
        if ($this->reserva->getPrimeraFechaReservaCanal() !== null) {
            $fechaCanal = $this->reserva->getPrimeraFechaReservaCanal();

            // 1. Extraemos los números limpios y FORZAMOS a PHP a entender que son UTC absolutos
            $createdUtc = new \DateTimeImmutable(
                $fechaCanal->format('Y-m-d H:i:s'),
                new \DateTimeZone('UTC')
            );

            // 2. Ahora SÍ hacemos la conversión matemática a Lima (esto restará las 5 horas)
            $createdLima = $createdUtc->setTimezone($tzLima);

            // 3. Lo convertimos de nuevo a un formato "naive" (sin zona atada)
            $created = new \DateTimeImmutable($createdLima->format('Y-m-d H:i:s'));
        } else {
            // Ya está en naive (NO tocar). Manejo estricto de tipos para evitar el error del IDE.
            $createdAt = $this->reserva->getCreatedAt();

            if ($createdAt instanceof \DateTime) {
                $created = \DateTimeImmutable::createFromMutable($createdAt);
            } else {
                // Si ya es DateTimeImmutable, o si es null, lo pasamos directo
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
                $fechaLlegada = clone $this->reserva->getFechaLlegada();
                if ($fechaLlegada instanceof \DateTimeInterface) {
                    try {
                        $fechaString = $fechaLlegada->format('Y-m-d');
                        $horaLimpia = trim((string) $expectedArrivalRaw);

                        $expectedArrivalCompleto = new \DateTimeImmutable("$fechaString $horaLimpia");
                        $milestones[ConversationMilestoneInterface::EXPECTED_ARRIVAL] = $expectedArrivalCompleto;
                    } catch (\Exception $e) {
                        // Fallback silencioso
                    }
                }
            }
        }

        if ($this->isCancelled() && $this->reserva->getUltimaFechaModificacionCanal()) {
            $milestones[ConversationMilestoneInterface::CANCELLED] = $this->reserva->getUltimaFechaModificacionCanal();
        }

        return $milestones;
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
     */
    public function getFinancialTotal(): ?float
    {
        return (float) $this->reserva->getMontoTotal();
    }

    /**
     * Indica si la reserva ya ha sido pagada en su totalidad.
     */
    public function isFinancialCleared(): bool
    {
        return false;
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
}