<?php

declare(strict_types=1);

namespace App\Pms\Enum;

/**
 * Qué se le pide, que no es lo mismo que cuánto.
 *
 * ⚠️ El corte entre ADELANTO y TOTAL es **el día de check-in, incluido**: desde la mañana
 * del día de llegada se pide el total, no el adelanto. Un adelanto pierde sentido cuando el
 * huésped ya está entrando.
 *
 * Es una regla de NEGOCIO decidida el 28/08/2026, no algo que el código hiciera antes:
 * `PmsPrepagoCalculador::pendiente()` nunca ha mirado fechas.
 */
enum PmsQueSePide
{
    case ADELANTO;
    case TOTAL;
    case NADA;
}
