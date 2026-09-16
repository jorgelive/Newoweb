<?php

declare(strict_types=1);

namespace App\Cotizacion\Documento;

/**
 * Las palabras que distinguen a una persona, ya normalizadas.
 *
 * ⚠️ **Extraído de {@see Cotejo} el 16/09/2026, no reescrito.** Lo necesitaba también el control del
 * E-Ticket, y dos normalizaciones de nombre que tienen que decir lo mismo es exactamente el tipo de
 * mapeo que ya se duplicó antes en este módulo —la pareja escaneo↔número llegó a estar en tres
 * sitios— y que el sistema paga discrepando consigo mismo.
 *
 * 🔥 **Esto usaba `strtr()` con dos cadenas, que opera BYTE A BYTE.** Con nombres acentuados
 * destrozaba la palabra sin dar error: `«José Pérez Núñez»` salía como `JOSO` y `REZ`.
 * `Transliterator` cubre cualquier alfabeto, no sólo la lista de acentos que uno recuerde.
 *
 * ⚠️ **Y la transliteración no es cosmética aquí: `ACUÑA` y `ACUNA` son la misma persona.** El
 * formulario dominicano no admite la eñe, así que el pasajero escribe `ACUNA` y su pasaporte dice
 * `ACUÑA`. Sin normalizar, ese caso sale como nombre que no coincide — y en un grupo peruano eso es
 * mucha gente.
 */
final readonly class PalabrasDelNombre
{
    /**
     * @return list<string>
     */
    public static function de(string $texto): array
    {
        static $translit = null;
        $translit ??= \Transliterator::create('Any-Latin; Latin-ASCII; Upper');

        $limpio = $translit?->transliterate($texto) ?: mb_strtoupper($texto);
        $soloLetras = (string) preg_replace('/[^A-Z ]/', ' ', $limpio);

        // Las partículas no distinguen a nadie: «DE», «DEL» y «LA» aparecen en media lista.
        return array_values(array_unique(array_diff(
            array_filter(explode(' ', $soloLetras), static fn (string $p): bool => strlen($p) > 2),
            ['DEL', 'LOS', 'LAS', 'VAN', 'VON'],
        )));
    }
}
