<?php

declare(strict_types=1);

namespace App\Pms\Service\Message;

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
        $milestones = [
            'start' => $this->reserva->getFechaLlegada(),
            'end'   => $this->reserva->getFechaSalida(),
            'booked_at' => $this->reserva->getPrimeraFechaReservaCanal() ?? $this->reserva->getCreatedAt(),
        ];

        if ($this->reserva->getHoraLlegadaCanalAggregate()) {
            $milestones['eta'] = $this->reserva->getHoraLlegadaCanalAggregate();
        }

        if ($this->isArchivable() && $this->reserva->getUltimaFechaModificacionCanal()) {
            $milestones['cancelled_at'] = $this->reserva->getUltimaFechaModificacionCanal();
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