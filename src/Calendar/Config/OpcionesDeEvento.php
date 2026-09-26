<?php

declare(strict_types=1);

namespace App\Calendar\Config;

use App\Dto\Lee;

/**
 * El bloque `event` de los calendarios de tarifas (y el `event.url` del de estancias legacy).
 *
 * ⚠️ **`formatoTitulo` no lleva valor por defecto aquí, a propósito.** No es el mismo en todos:
 * `tarifa_ranges_raw` usa `'{currency} {price} | {minStay}'` —que además IGNORA, ver su
 * `formatTitle()`— y los SPA `'{currency} {price} · {minStay}N'`. Ponerlo en el DTO obligaría a
 * elegir uno; el default se queda en el provider que lo aplica.
 */
final class OpcionesDeEvento
{
    /**
     * @param list<string>|null $tooltip Rutas de getters, una por línea. `null` = el tooltip que
     *        arma cada provider por defecto.
     */
    public function __construct(
        public readonly bool $incluirMoneda = true,
        public readonly ?string $formatoTitulo = null,
        public readonly int $decimalesPrecio = 2,
        public readonly ?array $tooltip = null,
        public readonly ?EnlacesDeEvento $enlaces = null,
    ) {}

    public static function desde(mixed $valor): self
    {
        if (!is_array($valor)) {
            return new self();
        }

        // Una lista vacía de líneas es «no configurado», como el `!empty()` de antes.
        $tooltip = $valor['tooltip'] ?? null;

        return new self(
            incluirMoneda: Lee::booleano($valor['includeCurrency'] ?? null) ?? true,
            formatoTitulo: Lee::texto($valor['titleFormat'] ?? null),
            decimalesPrecio: Lee::entero($valor['priceDecimals'] ?? null) ?? 2,
            tooltip: is_array($tooltip) && $tooltip !== [] ? Lee::listaDeTextos($tooltip) : null,
            enlaces: is_array($valor['url'] ?? null) ? EnlacesDeEvento::fromArray($valor['url']) : null,
        );
    }
}
