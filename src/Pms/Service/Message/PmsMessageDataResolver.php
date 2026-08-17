<?php

declare(strict_types=1);

namespace App\Pms\Service\Message;

use App\Message\Contract\MessageDataResolverInterface;
use App\Pms\Entity\PmsChannel;
use App\Pms\Entity\PmsEventoBeds24Link;
use App\Pms\Entity\PmsEventoCalendario;
use App\Pms\Entity\PmsInformacionFinanciera;
use App\Pms\Entity\PmsReserva;
use DateTimeImmutable;
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

    /**
     * Un importe listo para leerse, con su moneda dentro y sumando todas las que haya.
     *
     * `US$ 65.97` con una sola; `US$ 65.97 + S/ 50.00` con dos. Nunca un número pelado: en una
     * plantilla, un importe sin moneda es una cifra que el huésped no puede comprobar.
     */
    private function importe(?PmsInformacionFinanciera $info, string $campo): string
    {
        if ($info === null) {
            return '0.00';
        }

        $partes = [];

        foreach ($info->getTotalesPorMoneda() as $fila) {
            $partes[] = trim(($fila['simbolo'] ?? $fila['moneda']) . ' ' . $fila[$campo]);
        }

        return $partes === [] ? '0.00' : implode(' + ', $partes);
    }

    /**
     * El código de moneda, **sólo si hay una**.
     *
     * Con dos, vacío: una plantilla que escriba «{balance} {currency}» produciría
     * «US$ 65.97 + S/ 50.00 USD». Preferible una cadena de menos que una mentira.
     */
    private function monedaUnica(?PmsInformacionFinanciera $info): string
    {
        $totales = $info?->getTotalesPorMoneda() ?? [];

        return count($totales) === 1
            ? (string) $totales[0]['moneda']
            : (count($totales) === 0 ? (string) ($info?->getMoneda()?->getId() ?? '') : '');
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
        return $reserva?->getTelefonoContacto();
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
        if (empty($targetBookId)) {
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

        // 🔥 OBTENEMOS EL ID DEL CANAL
        $sourceId = $reserva->getChannel() ? $reserva->getChannel()->getId() : PmsChannel::CODIGO_DIRECTO;

        return [
            'beds24_book_id' => $targetBookId,
            'beds24_config'  => $reserva->getEstablecimiento()?->getBeds24Config(),
            'source'         => $sourceId,
        ];
    }

    public function getMessageVariables(string $contextId): array
    {
        $reserva = $this->getReserva($contextId);
        if (!$reserva) {
            return [];
        }

        $canal = $reserva->getChannel();
        $pais = $reserva->getPais();
        $localizador = $reserva->getLocalizador();

        // Los importes salen de la CABECERA FINANCIERA, no de PmsReserva::getMontoTotal():
        // ese campo sólo se rellena en las OTA (y a veces incompleto), así que en una directa
        // la plantilla decía "su total es 0.00". Ver §12.0.2.
        $info = $this->entityManager->getRepository(PmsInformacionFinanciera::class)
            ->findOneBy(['reserva' => $reserva]);

        return [
            'guest_name'            => $reserva->getNombreCliente(),
            'guest_full_name'       => trim($reserva->getNombreCliente() . ' ' . $reserva->getApellidoCliente()),
            'locator'               => $localizador,
            'checkin_date'          => $reserva->getFechaLlegada()?->format('d/m/Y') ?? '',
            'checkout_date'         => $reserva->getFechaSalida()?->format('d/m/Y') ?? '',
            'nights'                => $reserva->getNoches(),
            'pax_total'             => $reserva->getPaxTotal(),
            // 💱 IMPORTES AUTOCONTENIDOS, con su moneda dentro.
            //
            // Con contabilidad por moneda (§12.2b) un importe suelto ya no significa nada: la
            // misma reserva puede deber en soles y en dólares. Estos marcadores pasan a ser
            // cadenas completas —«US$ 65.97 + S/ 50.00»— para que una plantilla no tenga que
            // concatenar la moneda por su cuenta.
            //
            // ⚠️ Por eso `currency` se queda VACÍO cuando hay más de una: una plantilla escrita
            // como «Debe {balance} {currency}» habría renderizado «Debe US$ 65.97 + S/ 50.00 USD».
            //
            // Auditado el 16/08/2026: de las 11 plantillas en base, **ninguna** usa hoy ninguno de
            // estos marcadores en ninguno de sus cuatro canales. El cambio de forma es seguro; si
            // algún día se usan, ya vienen listos para leerse tal cual.
            'total_amount'          => $this->importe($info, 'cargos'),
            'accommodation_amount'  => $info?->getTotalAlojamiento() ?? '0.00',
            'cleaning_fee'          => $info?->getTotalLimpieza() ?? '0.00',
            'service_fee'           => $info?->getTotalServicio() ?? '0.00',
            'paid_amount'           => $this->importe($info, 'pagos'),
            'balance'               => $this->importe($info, 'saldo'),
            'currency'              => $this->monedaUnica($info),
            'property_name'         => $reserva->getNombreHotel(),
            'room_name'             => $reserva->getNombreHabitacion(),
            'channel_name'          => $canal ? $canal->getNombre() : 'Directo',
            'guest_country'         => $pais ? $pais->getNombre() : '',
            'guide_url'             => rtrim($this->paxBookGuideUrl, '/') . '/' . $localizador,
            'guide_path'            => rtrim($this->paxBookGuideUrlNd, '/') . '/' . $localizador,
            'tours_catalog_url'     => rtrim($this->paxCatalogUrl, '/'),
            'tours_catalog_path'    => rtrim($this->paxCatalogUrlNd, '/'),
        ];
    }

    /**
     * Obtiene un conjunto de variables mixtas (URLs reales + Datos Dummy) para previsualizaciones
     * y para inyectar en el array obligatorio 'example' al crear plantillas en Meta.
     *
     * @return array<string, string|int|float> Diccionario de variables dummy seguras.
     */
    public function getPreviewMessageVariables(): array
    {
        $dummyLocator = 'PREVIEW-123456';
        $now = new DateTimeImmutable();
        $checkout = $now->modify('+4 days');

        return [
            'guest_name'            => 'John',
            'guest_full_name'       => 'John Doe',
            'locator'               => $dummyLocator,
            'checkin_date'          => $now->format('d/m/Y'),
            'checkout_date'         => $checkout->format('d/m/Y'),
            'nights'                => 4,
            'pax_total'             => 2,
            'total_amount'          => '150.00',
            'accommodation_amount'  => '120.00',
            'cleaning_fee'          => '15.00',
            'service_fee'           => '15.00',
            'paid_amount'           => '50.00',
            'balance'               => '100.00',
            'currency'              => 'USD',
            'property_name'         => 'Centro Cusco Inti',
            'room_name'             => 'Casita Principal',
            'channel_name'          => 'Booking.com',
            'guest_country'         => 'Perú',
            'guide_url'             => rtrim($this->paxBookGuideUrl, '/') . '/' . $dummyLocator,
            'guide_path'            => rtrim($this->paxBookGuideUrlNd, '/') . '/' . $dummyLocator,
            'tours_catalog_url'     => rtrim($this->paxCatalogUrl, '/'),
            'tours_catalog_path'    => rtrim($this->paxCatalogUrlNd, '/'),
        ];
    }
}