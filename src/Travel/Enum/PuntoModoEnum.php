<?php

declare(strict_types=1);

namespace App\Travel\Enum;

/**
 * CÓMO se resuelve un extremo geográfico de un segmento: ¿es un sitio fijo, o depende del viaje?
 *
 * Es la distinción que hace que el catálogo pueda ser genérico y la orden concreta. «Recojo e
 * inicio de excursión» no puede guardar una dirección —cada pasajero duerme en un hotel
 * distinto—, pero sí puede declarar que el punto ES el alojamiento; y «Retorno al centro de
 * Cusco» sí es siempre el mismo sitio.
 *
 * ⚠️ **`ALOJAMIENTO` es direccional según el lado en el que se use.** En el inicio significa
 * *dónde durmió*, y en el fin *dónde va a dormir*. Es lo que hace que un traslado
 * Cusco → Ollantaytambo salga bien con un solo caso de enum en vez de dos: el lado ya dice
 * hacia dónde mirar, y duplicarlo en `ALOJAMIENTO_ANTERIOR` / `ALOJAMIENTO_SIGUIENTE` habría
 * añadido la posibilidad de ponerlos al revés.
 *
 * ⚠️ **`SIN_DEFINIR` es el valor por defecto y tiene que serlo.** Un extremo que nace diciendo
 * «alojamiento» por comodidad es una mentira que nadie va a revisar: el proveedor recibiría un
 * hotel donde toca una estación de tren y la orden saldría plausible. Mientras no se declare,
 * la orden dice que falta el dato — que es la verdad.
 */
enum PuntoModoEnum: string
{
    case SIN_DEFINIR = 'sin_definir';
    case ALOJAMIENTO = 'alojamiento';
    case FIJO = 'fijo';

    public function esDeclarado(): bool
    {
        return $this !== self::SIN_DEFINIR;
    }

    /** ¿Necesita que además se le indique un {@see \App\Travel\Entity\TravelPunto}? */
    public function exigePunto(): bool
    {
        return $this === self::FIJO;
    }

    public function etiqueta(): string
    {
        return match ($this) {
            self::SIN_DEFINIR => 'Sin definir',
            self::ALOJAMIENTO => 'El alojamiento del pasajero',
            self::FIJO        => 'Un punto fijo',
        };
    }
}
