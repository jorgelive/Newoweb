<?php

declare(strict_types=1);

namespace App\Pms\Service\Message;

use App\Message\Contract\ConversationMilestoneInterface;
use App\Message\Contract\MessageContextInterface;
use App\Pms\Entity\PmsReserva;

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
        return $this->reserva->getIdioma() ? strtolower((string)$this->reserva->getIdioma()->getId()) : 'es';
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
        return $this->isArchivable() ? 'cancelled' : 'confirmed';
    }

    public function getMilestones(): array
    {
        $fechaLlegada = $this->reserva->getFechaLlegada();

        $milestones = [
            ConversationMilestoneInterface::START   => $fechaLlegada,
            ConversationMilestoneInterface::END     => $this->reserva->getFechaSalida(),
            ConversationMilestoneInterface::CREATED => $this->reserva->getPrimeraFechaReservaCanal() ?? $this->reserva->getCreatedAt(),
        ];

        // 🔥 Llegada Esperada (Expected Arrival)
        $expectedArrivalRaw = $this->reserva->getHoraLlegadaCanalAggregate();

        if ($expectedArrivalRaw) {
            if ($expectedArrivalRaw instanceof \DateTimeInterface) {
                $milestones[ConversationMilestoneInterface::EXPECTED_ARRIVAL] = $expectedArrivalRaw;
            } elseif ($fechaLlegada instanceof \DateTimeInterface) {
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

        if ($this->isArchivable() && $this->reserva->getUltimaFechaModificacionCanal()) {
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

    public function isArchivable(): bool
    {
        return $this->reserva->isTotalmenteCancelada();
    }
}