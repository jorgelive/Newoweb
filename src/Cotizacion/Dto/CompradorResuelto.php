<?php

declare(strict_types=1);

namespace App\Cotizacion\Dto;

/**
 * A quién se le encarga la compra de un componente, ya resuelta la herencia.
 *
 * Mismo criterio que {@see PrestadorResuelto}: se elige UNA fuente y se toma entera, y
 * `origen` deja constancia de cuál ganó para que el editor pueda decir «heredado» en vez de
 * fingir que el campo está vacío.
 *
 * `origen` distingue el encargo real del caso normal: `prestador` significa que nadie
 * encargó nada y se le pide directamente a quien presta el servicio. Es lo que permite a la Orden de
 * Servicio salir bien sin que haya que llenar el campo en casi ningún componente.
 */
final readonly class CompradorResuelto
{
    /**
     * @param 'componente'|'prestador' $origen  De dónde salió
     */
    public function __construct(
        public string $origen,
        public ?string $maestroId,
        public ?string $nombre,
    ) {
    }

    /** Heredado = nadie encargó la compra; se le pide al propio prestador. */
    public function esHeredado(): bool
    {
        return $this->origen !== 'componente';
    }
}
