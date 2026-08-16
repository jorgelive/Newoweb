<?php

declare(strict_types=1);

namespace App\Cotizacion\Dto;

/**
 * El prestador que finalmente aplica a un componente, ya resuelta la herencia.
 *
 * Existe para que la herencia devuelva un conjunto COHERENTE. Resolver campo a
 * campo —el título del componente, el teléfono de la tarifa— produciría un
 * prestador Frankenstein que no corresponde a ninguna empresa real. Aquí se elige
 * una fuente y se toma entera; `origen` deja constancia de cuál fue, que es lo que
 * el editor necesita para decir «heredado del día» en vez de fingir que se escribió
 * a mano.
 *
 * Ver CotizacionCotcomponente::resolverPrestador().
 */
final readonly class PrestadorResuelto
{
    /**
     * @param 'componente'|'servicio'|'proveedor' $origen  De dónde salió
     * @param array<int, array{content?: string, language?: string}> $titulo  I18n, cara pública
     * @param array<int, mixed> $imagenes
     */
    public function __construct(
        public string $origen,
        public ?string $maestroId,
        public ?string $nombre,
        public array $titulo = [],
        public ?string $url = null,
        public array $imagenes = [],
        public ?string $telefono = null,
        public ?string $direccion = null,
    ) {}

    /** Heredado = el componente no lo definió; lo puso el día o la tarifa. */
    public function esHeredado(): bool
    {
        return $this->origen !== 'componente';
    }
}
