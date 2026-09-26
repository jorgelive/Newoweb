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
 * ── ⚠️ La transliteración NO es una comodidad: es obligatoria dos veces ────
 * Se planteó que la eñe y las tildes deberían compararse **exactas** cuando se trata de un DNI o un
 * pasaporte, y suena razonable —el nombre oficial lleva su eñe—. Medido, no se puede:
 *
 * 1. **La MRZ de un pasaporte es ASCII por especificación** (ICAO 9303): `Ñ→N`, tildes fuera,
 *    siempre. No es nuestro lector el que las pierde, es el documento. Comprobado en producción:
 *    ```
 *    manifiesto : Jairo Jesús Medrano Huaicho
 *    MRZ        : P<PERMEDRANO<HUAICHO<<JAIRO<JESUS<<<
 *    ```
 *    Exigir exactitud ahí **acusa al manifiesto, que es el que está bien**. De 127 pasaportes
 *    leídos, ése es el ÚNICO que difiere sólo en diacríticos.
 *
 * 2. **El E-Ticket y los sistemas de las aerolíneas tampoco los admiten.** El pasajero escribe
 *    `ACUNA` porque el formulario no le deja escribir otra cosa.
 *
 * Así que los dos lados que se comparan pierden los diacríticos por diseño ajeno, y una comparación
 * exacta no mediría el nombre: mediría de qué zona del documento salió la lectura.
 *
 * 🔑 **Dónde SÍ debe ser exacto es al ESCRIBIR el manifiesto**, que es de donde salen los papeles
 * oficiales. Pero eso no lo puede arbitrar un escaneo cuya única fuente verificada es ASCII: es
 * calidad del dato al cargarlo, no un veredicto de control.
 */
final readonly class PalabrasDelNombre
{
    /**
     * @return list<string>
     */
    public static function de(string $texto): array
    {
        /** @var \Transliterator|null $translit */
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
