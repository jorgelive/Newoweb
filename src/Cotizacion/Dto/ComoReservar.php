<?php

declare(strict_types=1);

namespace App\Cotizacion\Dto;

use Symfony\Component\Serializer\Annotation\Groups;

/**
 * Cómo se reserva una propuesta: el adelanto, cuándo se paga el resto y por dónde.
 *
 * Es lo que en Oweb salía bajo cada total («reserve con el 50 %») y se perdió al pasar a este
 * cotizador (16/10/2026). Lo arma {@see \App\Cotizacion\Service\Publico\ComoReservarResolver} y
 * lo pinta el bloque «Cómo reservar» de `pax`.
 *
 * ⚠️ **No lleva importes.** El dinero ya viaja en `clasificacionFinancieraCliente`, en las dos
 * monedas y redactado cuando el precio va oculto; aquí sólo el porcentaje, y el importe lo
 * calcula `pax` con `montoDeAdelanto()` de `dominio/cotizacion/adelanto.ts`, la misma función que
 * usa el editor. Mandarlo también desde aquí serían dos cálculos del mismo número.
 *
 * 🪞 Espejo: `pax/src/types/paxCotizacionModel.ts` (`PaxComoReservar`).
 */
final readonly class ComoReservar
{
    /**
     * @param list<array<string, mixed>> $medios Fichas de {@see \App\Finanzas\Entity\FinMedioCobro::fichaParaCliente()},
     *                                           ya filtradas por procedencia y por los días que faltan.
     * @param array<string, string> $politicaDevoluciones URL de la política en la web, por idioma (`es`, `en`).
     */
    public function __construct(
        /** 0–100. El 50 es el de siempre; 100 es pagar todo para reservar. */
        #[Groups(['pax_cotizacion:read'])]
        public float $porcentajeAdelanto,

        /** Día del primer servicio (`Y-m-d`, hora de pared): cuándo se paga el resto. */
        #[Groups(['pax_cotizacion:read'])]
        public ?string $fechaPrimerServicio,

        /** `true` desde Perú, `false` desde fuera, `null` sin saberlo. Ver `FinAudienciaCobro::desdePeru()`. */
        #[Groups(['pax_cotizacion:read'])]
        public ?bool $desdePeru,

        /**
         * ¿Se le ofrece la tarjeta? Al de fuera sí; al de Perú no, porque tiene Yape, Plin y
         * transferencia sin comisión. Sin país, también: es mejor una opción de más.
         */
        #[Groups(['pax_cotizacion:read'])]
        public bool $conTarjeta,

        #[Groups(['pax_cotizacion:read'])]
        public array $medios,

        #[Groups(['pax_cotizacion:read'])]
        public array $politicaDevoluciones,
    ) {
    }
}
