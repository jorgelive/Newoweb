<?php

declare(strict_types=1);

namespace App\Pms\Service\Message;

use App\Message\Contract\MessageContextInterface;
use App\Pms\Entity\PmsReserva;
use DateTimeInterface;

/**
 * Patrón Adaptador: Envuelve una entidad PmsReserva para que cumpla
 * con el contrato genérico que el módulo de Mensajes espera.
 */
class PmsReservaMessageContext implements MessageContextInterface
{
    public function __construct(
        private readonly PmsReserva $reserva
    ) {}

    public function getContextType(): string { return 'pms_reserva'; }
    public function getContextId(): string { return (string) $this->reserva->getId(); }
    public function getContextLanguage(): string { return $this->reserva->getIdioma() ? strtolower((string)$this->reserva->getIdioma()->getId()) : 'es'; }

    public function getContextName(): ?string
    {
        return trim($this->reserva->getNombreCliente() . ' ' . $this->reserva->getApellidoCliente());
    }

    public function getContextPhone(): ?string
    {
        return $this->reserva->getTelefono() ?? $this->reserva->getTelefono2();
    }

    public function getMilestone(string $milestoneName): ?DateTimeInterface
    {
        return match ($milestoneName) {
            'start'   => $this->reserva->getFechaLlegada(),
            'end'     => $this->reserva->getFechaSalida(),
            'created' => $this->reserva->getCreatedAt(),
            default   => null,
        };
    }

    public function getSegmentationAttributes(): array
    {
        return [
            'source'    => $this->reserva->getChannel()?->getId(),
            'agency_id' => null, // Placeholder para futuras entidades de agencias B2B
        ];
    }
}