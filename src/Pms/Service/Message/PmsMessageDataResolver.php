<?php

declare(strict_types=1);

namespace App\Pms\Service\Message;

use App\Message\Contract\MessageDataResolverInterface;
use App\Pms\Entity\PmsChannel; // 🔥 IMPORTANTE
use App\Pms\Entity\PmsEventoBeds24Link;
use App\Pms\Entity\PmsEventoCalendario;
use App\Pms\Entity\PmsReserva;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

#[AutoconfigureTag('app.message_data_resolver')]
class PmsMessageDataResolver implements MessageDataResolverInterface
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        #[Autowire('%pax_book_guide_url%')]
        private readonly string $paxBookGuideUrl,
        #[Autowire('%pax_book_guide_url_nd%')]
        private readonly string $paxBookGuideUrlNd,
        #[Autowire('%pax_catalog_url%')]
        private readonly string $paxCatalogUrl,
        #[Autowire('%pax_catalog_url_nd%')]
        private readonly string $paxCatalogUrlNd
    ) {}

    public function supports(string $contextType): bool
    {
        return $contextType === 'pms_reserva';
    }

    private function getReserva(string $contextId): ?PmsReserva
    {
        return $this->entityManager->getRepository(PmsReserva::class)->find($contextId);
    }

    public function getContextName(string $contextId): ?string
    {
        $reserva = $this->getReserva($contextId);
        return $reserva ? trim($reserva->getNombreCliente() . ' ' . $reserva->getApellidoCliente()) : null;
    }

    public function getPhoneNumber(string $contextId): ?string
    {
        $reserva = $this->getReserva($contextId);
        return $reserva ? ($reserva->getTelefono() ?? $reserva->getTelefono2()) : null;
    }

    public function getMetadata(string $contextId): array
    {
        $reserva = $this->getReserva($contextId);
        if (!$reserva) {
            return [];
        }

        // 1. Intentamos obtener el ID Principal
        $targetBookId = $reserva->getBeds24MasterId();

        // 2. Sino lo buscamos en el link
        if (empty($targetBookId) ) {
            foreach ($reserva->getEventosCalendario() as $evento) {
                /** @var PmsEventoCalendario $evento */
                foreach ($evento->getBeds24Links() as $link) {
                    /** @var PmsEventoBeds24Link $link */
                    if ($link->isEsPrincipal()) {
                        $targetBookId = $link->getBeds24BookId();
                        break 2;
                    }
                }
            }
        }

        // 🔥 OBTENEMOS EL ID DEL CANAL (Ej: 'airbnb', 'booking', 'directo')
        $sourceId = $reserva->getChannel() ? $reserva->getChannel()->getId() : PmsChannel::CODIGO_DIRECTO;

        return [
            'beds24_book_id' => $targetBookId,
            'beds24_config'  => $reserva->getEstablecimiento()?->getBeds24Config(),
            'source'         => $sourceId, // 🔥 AÑADIDO PARA LA REGLA DE OTAs
        ];
    }

    // 🔥 CAMBIADO EL NOMBRE A getMessageVariables (Refactor)
    public function getMessageVariables(string $contextId): array
    {
        $reserva = $this->getReserva($contextId);
        if (!$reserva) {
            return [];
        }

        $canal = $reserva->getChannel();
        $pais = $reserva->getPais();
        $localizador = $reserva->getLocalizador();

        return [
            'guest_name'            => $reserva->getNombreCliente(),
            'guest_full_name'       => trim($reserva->getNombreCliente() . ' ' . $reserva->getApellidoCliente()),
            'locator'               => $localizador,
            'checkin_date'          => $reserva->getFechaLlegada()?->format('d/m/Y') ?? '',
            'checkout_date'         => $reserva->getFechaSalida()?->format('d/m/Y') ?? '',
            'nights'                => $reserva->getNoches(),
            'pax_total'             => $reserva->getPaxTotal(),
            'total_amount'          => $reserva->getMontoTotal() ?? '0.00',
            'property_name'         => $reserva->getNombreHotel(),
            'room_name'             => $reserva->getNombreHabitacion(),
            'channel_name'          => $canal ? $canal->getNombre() : 'Directo',
            'guest_country'         => $pais ? $pais->getNombre() : '',
            'url_guide'             => rtrim($this->paxBookGuideUrl, '/') . '/' . $localizador,
            'url_guide_nd'          => rtrim($this->paxBookGuideUrlNd, '/') . '/' . $localizador,
            'url_tours_catalog'     => rtrim($this->paxCatalogUrl, '/'),
            'url_tours_catalog_nd'  => rtrim($this->paxCatalogUrlNd, '/'),
        ];
    }
}