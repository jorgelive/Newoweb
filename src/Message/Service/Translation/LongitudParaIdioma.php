<?php

declare(strict_types=1);

namespace App\Message\Service\Translation;

/**
 * ¿Tiene un texto material suficiente para decidir su idioma?
 *
 * ⚠️ **Había dos reglas y no coincidían, y el hueco entre ellas era justo un caso real.** El
 * detector local se callaba con menos de 15 bytes (`strlen < 15`) y `MessageTranslator` sólo
 * cambiaba el idioma del hilo con más de 15 caracteres o más de 2 palabras. «Parfait merci !»
 * mide exactamente 15: el detector sí opinaba, y opinaba chamorro.
 *
 * Una sola regla para los dos sitios, y más exigente: **al menos tres palabras y quince
 * caracteres**. Los detectores fallan precisamente en lo corto —«ok», «gracias», «Parfait
 * merci !»—, y en lo corto equivocarse sale caro (se le cambia el idioma a la conversación) y
 * acertar no aporta nada (ya se sabía en qué idioma se hablaba).
 *
 * Las palabras se cuentan como secuencias de letras Unicode: `str_word_count()` parte «réfléchir»
 * por los acentos y contaba de más.
 */
final class LongitudParaIdioma
{
    public const int PALABRAS_MINIMAS = 3;
    public const int CARACTERES_MINIMOS = 15;

    public static function basta(string $texto): bool
    {
        $limpio = trim(strip_tags($texto));

        return mb_strlen($limpio) >= self::CARACTERES_MINIMOS
            && (int) preg_match_all('/\p{L}+/u', $limpio) >= self::PALABRAS_MINIMAS;
    }
}
