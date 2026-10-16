<?php

declare(strict_types=1);

namespace App\Cotizacion\Dto;

use Symfony\Component\Serializer\Annotation\Groups;

/**
 * El enlace de pago con tarjeta que «Cómo reservar» le ofrece al cliente: a dónde ir y cuánto se le
 * cobrará, con la comisión de la pasarela incluida (la paga el cliente, como en alojamiento).
 *
 * 🪞 Espejo: `pax/src/types/paxCotizacionModel.ts` (del esquema generado).
 */
final readonly class ComoReservarEnlace
{
    public function __construct(
        /** La página de pago de pax (`/pago/{token}`). */
        #[Groups(['pax_cotizacion:read'])]
        public string $url,

        /** Lo que se cobrará en la tarjeta: el neto más la comisión. */
        #[Groups(['pax_cotizacion:read'])]
        public string $montoTotal,

        /** Lo que abona el viaje: sin la comisión. */
        #[Groups(['pax_cotizacion:read'])]
        public string $montoNeto,

        #[Groups(['pax_cotizacion:read'])]
        public string $moneda,

        /** El % de comisión de la pasarela que se suma; «0.00» si no lleva. */
        #[Groups(['pax_cotizacion:read'])]
        public string $recargoPorcentaje,
    ) {
    }
}
