<?php

declare(strict_types=1);

namespace App\Cotizacion\Documento;

use App\Cotizacion\Entity\CotizacionFilepasajero;

/**
 * Alguien del manifiesto a quien podría pertenecer un documento suelto, **y con cuánta certeza**.
 *
 * 🔑 **La certeza va en el dato, no en el orden de la lista.** Una lista ordenada «el mejor
 * primero» hace que el primero se elija por estar arriba; diciendo *por qué* es candidato, quien
 * decide sabe si está confirmando un hecho o aceptando una corazonada.
 */
final readonly class Candidato
{
    private function __construct(
        public CotizacionFilepasajero $pasajero,
        /** `numero` = su documento ya está guardado; `nombre` = sólo se parece el nombre. */
        public string $por,
        public string $motivo,
    ) {}

    /**
     * Casa por número de documento: es un **hecho**, no una opinión. El número es la clave natural
     * del documento y no se escribe de quince maneras.
     */
    public static function porNumero(CotizacionFilepasajero $pasajero, string $numero): self
    {
        return new self($pasajero, 'numero', sprintf('ya tiene guardado ese número (%s)', $numero));
    }

    /**
     * Casa por nombre: es una **sugerencia**.
     *
     * ⚠️ Es la que se equivoca en las familias —hermanos con los dos apellidos iguales—, que es
     * justo donde el reparto se tuerce. Nunca se aplica sola.
     */
    public static function porNombre(CotizacionFilepasajero $pasajero): self
    {
        return new self($pasajero, 'nombre', 'coincide el nombre, pero NO tiene ese documento guardado');
    }

    public function esSeguro(): bool
    {
        return $this->por === 'numero';
    }
}
