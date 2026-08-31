<?php

declare(strict_types=1);

namespace App\Pms\Finanzas;

use App\Pms\Entity\PmsReserva;
use App\Pms\Service\Finance\TipoCambioDelDia;

/**
 * «Son unos S/ 360.93»: la equivalencia orientativa de un importe, o `null` si no procede.
 *
 * ── Por qué es un servicio y no un método privado ───────────────────────────
 * Estaba dentro de {@see PmsSituacionDeCobroResolver}, que era su único usuario. Al redactar el
 * bloque de los mensajes apareció el segundo, y con él la tentación de reescribir diez líneas —
 * que es exactamente como la misma regla acaba diciendo dos cosas. Se saca aquí antes de que eso
 * pase, no después.
 *
 * ── Las tres condiciones ────────────────────────────────────────────────────
 * `null` —o sea, no se pone equivalencia— si la moneda ya es PEN, si no consta que el huésped
 * pague desde Perú, o si no hay tipo de cambio del día.
 *
 * ⚠️ `PmsProcedenciaHuesped::pagaDesdePeru()` es TERNARIA y su `null` significa «no se sabe»:
 * ahí tampoco se convierte. Una equivalencia en soles a quien no paga en soles confunde más de
 * lo que ayuda.
 *
 * ⚠️ **Es presentación, no contabilidad.** No toca el saldo ni entra en ningún total: la
 * contabilidad es por moneda y no se convierte nunca (§12.2b de `docs/PmsBeds24ReservasSync.md`).
 */
final readonly class PmsEquivalenciaEnSoles
{
    public function __construct(
        private PmsProcedenciaHuesped $procedencia,
        private TipoCambioDelDia $tipoCambio,
    ) {
    }

    public function de(string $importe, string $moneda, ?PmsReserva $reserva): ?string
    {
        if ($moneda === 'PEN' || $reserva === null) {
            return null;
        }

        if ($this->procedencia->pagaDesdePeru($reserva) !== true) {
            return null;
        }

        // `TipoCambioDelDia` memoriza por fecha, así que llamarlo por cada importe y cada medio
        // no multiplica las consultas.
        $tc = $this->tipoCambio->venta();

        if ($tc === null || (float) $tc <= 0.0) {
            return null;
        }

        return number_format((float) $importe * (float) $tc, 2, '.', '');
    }
}
