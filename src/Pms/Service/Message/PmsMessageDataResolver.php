<?php

declare(strict_types=1);

namespace App\Pms\Service\Message;

use App\Message\Contract\MessageDataResolverInterface;
use App\Pms\Entity\PmsReserva;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * PmsMessageDataResolver
 *
 * Implementación concreta encargada de buscar datos frescos de reservas
 * en la base de datos justo antes de que se despache un mensaje.
 * Registrado automáticamente mediante la etiqueta autoconfigure.
 */
#[AutoconfigureTag('app.message_data_resolver')]
class PmsMessageDataResolver implements MessageDataResolverInterface
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager
    ) {}

    /**
     * @inheritDoc
     */
    public function supports(string $contextType): bool
    {
        return $contextType === 'pms_reserva';
    }

    /**
     * Búsqueda interna optimizada para evitar duplicación de código.
     */
    private function getReserva(string $contextId): ?PmsReserva
    {
        return $this->entityManager->getRepository(PmsReserva::class)->find($contextId);
    }

    /**
     * @inheritDoc
     */
    public function getContextName(string $contextId): ?string
    {
        $reserva = $this->getReserva($contextId);
        return $reserva ? trim($reserva->getNombreCliente() . ' ' . $reserva->getApellidoCliente()) : null;
    }

    /**
     * @inheritDoc
     */
    public function getPhoneNumber(string $contextId): ?string
    {
        $reserva = $this->getReserva($contextId);
        return $reserva ? ($reserva->getTelefono() ?? $reserva->getTelefono2()) : null;
    }

    /**
     * @inheritDoc
     */
    public function getMetadata(string $contextId): array
    {
        $reserva = $this->getReserva($contextId);
        if (!$reserva) {
            return [];
        }

        return [
            'beds24_book_id' => $reserva->getBeds24BookIdPrincipal() ?? $reserva->getBeds24MasterId(),
        ];
    }

    /**
     * @inheritDoc
     */
    public function getTemplateVariables(string $contextId): array
    {
        $reserva = $this->getReserva($contextId);
        if (!$reserva) {
            return [];
        }

        $canal = $reserva->getChannel();
        $pais = $reserva->getPais();

        return [
            'guest_name'      => $reserva->getNombreCliente(),
            'guest_full_name' => trim($reserva->getNombreCliente() . ' ' . $reserva->getApellidoCliente()),
            'locator'         => $reserva->getLocalizador(),
            'checkin_date'    => $reserva->getFechaLlegada()?->format('d/m/Y') ?? '',
            'checkout_date'   => $reserva->getFechaSalida()?->format('d/m/Y') ?? '',
            'checkin_time'    => $reserva->getHoraLlegadaCanal() ?? '15:00',
            'nights'          => $reserva->getNoches(),
            'pax_total'       => $reserva->getPaxTotal(),
            'total_amount'    => $reserva->getMontoTotal() ?? '0.00',
            'property_name'   => $reserva->getNombreHotel(),
            'room_name'       => $reserva->getNombreHabitacion(),
            'channel_name'    => $canal ? $canal->getNombre() : 'Directo',
            'guest_country'   => $pais ? $pais->getNombre() : '',
        ];
    }
}