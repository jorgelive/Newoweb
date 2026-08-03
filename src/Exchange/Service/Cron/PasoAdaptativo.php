<?php

declare(strict_types=1);

namespace App\Exchange\Service\Cron;

use DateInterval;
use DateTimeImmutable;

/**
 * Cálculo del paso del cursor en función de la lejanía.
 *
 * El paso se DUPLICA cada `$tramoDias` de distancia respecto a hoy, con techo en `$multMax`:
 * lo cercano se revisa fino y lo lejano se recorre a zancadas.
 *
 * Vive aparte (y no en un trait) para que cada job declare SUS constantes sin que PHP se queje:
 * desde 8.2 los traits admiten constantes, pero la clase que los usa no puede redefinirlas.
 */
final class PasoAdaptativo
{
    public static function calcular(
        DateTimeImmutable $desde,
        int $baseDias,
        int $tramoDias,
        int $multMax,
        ?DateTimeImmutable $hoy = null,
    ): DateInterval {
        $hoy ??= new DateTimeImmutable('today');

        // Un cursor en el pasado se trata como distancia 0: ahí está lo que acaba de ocurrir
        // (salidas de ayer, pagos de última hora) y merece el paso más fino.
        $dias = $desde <= $hoy ? 0 : (int) $hoy->diff($desde)->days;

        $exponente = intdiv($dias, max(1, $tramoDias));
        // Se acota el exponente ANTES de elevar: con un cursor disparatado, 2 ** 400 daría INF.
        $multiplicador = min($multMax, 2 ** min($exponente, 30));

        return new DateInterval('P' . max(1, $baseDias * (int) $multiplicador) . 'D');
    }
}
