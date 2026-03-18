<?php

declare(strict_types=1);

namespace App\Pms\Service\Message;

use App\Message\Contract\ConversationMilestoneInterface;
use App\Message\Contract\MessageContextInterface;
use App\Pms\Entity\PmsReserva;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Patrón Adaptador: Envuelve una entidad PmsReserva para que cumpla
 * con el contrato genérico que el módulo de Mensajes espera.
 */
class PmsReservaMessageContext implements MessageContextInterface
{
    public function __construct(
        private readonly PmsReserva $reserva
    ) {}

    // =========================================================================
    // IDENTIFICADORES Y CONTACTO BASE
    // =========================================================================

    public function getContextType(): string { return 'pms_reserva'; }

    public function getContextId(): string { return (string) $this->reserva->getId(); }

    public function getContextLanguage(): string {
        return $this->reserva->getIdioma()?->getId() ?? 'en';
    }

    public function getContextName(): ?string
    {
        return trim($this->reserva->getNombreCliente() . ' ' . $this->reserva->getApellidoCliente());
    }

    public function getContextPhone(): ?string
    {
        return $this->reserva->getTelefono() ?? $this->reserva->getTelefono2();
    }

    // =========================================================================
    // DICCIONARIO AGNÓSTICO PARA EL JSON
    // =========================================================================

    public function getOrigin(): ?string
    {
        return $this->reserva->getChannel()?->getId() ?? 'directo';
    }

    public function getStatusTag(): ?string
    {
        return $this->isCancelled() ? 'cancelled' : 'confirmed';
    }

    public function getMilestones(): array
    {
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

    public function getItems(): array
    {
        $unidadesString = $this->reserva->getUnidadesAggregate();
        if (!$unidadesString) {
            return [];
        }
        return array_map('trim', explode(',', $unidadesString));
    }

    public function getFinancialTotal(): ?float
    {
        return (float) $this->reserva->getMontoTotal();
    }

    public function isFinancialCleared(): bool
    {
        return false;
    }

    // =========================================================================
    // REGLAS DE NEGOCIO DEL CHAT
    // =========================================================================

    public function isCancelled(): bool
    {
        return $this->reserva->isTotalmenteCancelada();
    }
}