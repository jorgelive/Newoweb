<?php

declare(strict_types=1);

namespace App\Front\Tours\Dto;

/** Un rango «Desde X» de exhibición (`Cotizacion::$preciosDesde`), ya traducido. */
final readonly class PrecioDesdeWeb
{
    public function __construct(
        public ?string $etiqueta,
        /** Texto decimal, como todo el dinero del proyecto. */
        public string $valor,
        public string $moneda,
    ) {
    }

    /** «S/ 69» / «US$ 119.50»: sin decimales cuando son cero, que en una tarjeta sobran. */
    public function formateado(): string
    {
        $simbolo = match (strtoupper($this->moneda)) {
            'PEN' => 'S/',
            'USD' => 'US$',
            'EUR' => '€',
            default => strtoupper($this->moneda),
        };
        $numero = (float) $this->valor;
        $decimales = fmod($numero, 1.0) === 0.0 ? 0 : 2;

        return $simbolo . ' ' . number_format($numero, $decimales, '.', ',');
    }
}
