<?php

declare(strict_types=1);

namespace App\Pms\Finanzas;

/**
 * Las dos lecturas de un cobro con recargo: lo que abona la estancia y lo que pasa por la tarjeta.
 *
 * ── Por qué existe ──────────────────────────────────────────────────────────
 * La fórmula estaba escrita **tres veces**, y es dinero:
 *
 * ```
 * PmsPagoFinanciero::getMontoComision()      lo que persiste y sirve la API
 * RegistrarPagoSkill::desglose()             lo que el agente le redacta al huésped en el chat
 * util/src/types/pmsFinanzasModel.ts         lo que el formulario recalcula mientras se teclea
 * ```
 *
 * Las dos de PHP se unifican aquí. La de TypeScript **se queda**, y no por descuido: el formulario
 * tiene que recalcular en vivo con cada tecla y una ida y vuelta al servidor por pulsación es
 * peor que la copia. Es el hueco que `dominio/` viene a tapar; hasta entonces, espejo declarado —
 * ver `docs/NodeEnElStack.md`, «Qué se está calculando dos veces».
 *
 * ── ⚠️ Las dos direcciones no son la misma cuenta ───────────────────────────
 * Con un 5 % sobre 100: se cobran 105 y abonan 100. Pero si lo que se sabe son los 105 cobrados,
 * el neto **no es 105 − 5 %** (99,75): es `105 / 1,05` = 100. Restar el porcentaje al total es el
 * error clásico, y con importes grandes se lleva por delante varios soles de deuda que nadie
 * cuadra después.
 */
final class ComisionDeCobro
{
    /** El recargo: `neto × porcentaje / 100`. */
    public static function recargo(float $neto, float $porcentaje): string
    {
        return number_format($neto * $porcentaje / 100, 2, '.', '');
    }

    /** Lo que pasa por la tarjeta: neto + recargo. */
    public static function totalCobrado(float $neto, float $porcentaje): string
    {
        return number_format($neto * (1 + $porcentaje / 100), 2, '.', '');
    }

    /**
     * La inversa: del total cobrado al neto que abona la estancia.
     *
     * ⚠️ Una DIVISIÓN, no una resta. Ver el aviso de la cabecera.
     */
    public static function netoDesdeTotal(float $total, float $porcentaje): string
    {
        return number_format($total / (1 + $porcentaje / 100), 2, '.', '');
    }
}
