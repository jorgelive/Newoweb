<?php

declare(strict_types=1);

namespace App\Pms\Finanzas;

/**
 * Un TRAMO del cobro: un importe y los medios con los que se puede pagar **ese** importe.
 *
 * ── Por qué existe ──────────────────────────────────────────────────────────
 * Cuando se pide un adelanto, el dinero se parte en dos momentos que **no se pagan igual**:
 * el adelanto va por adelantado (Yape, transferencia, tarjeta) y el resto se paga en la puerta
 * (efectivo o tarjeta). Hasta el 30/08/2026 {@see PmsSituacionDeCobro} sólo sabía del primero:
 * `medios` se resolvía una vez, para `importes[0]`, y el segundo tramo no existía como dato.
 *
 * El mensaje que el equipo mandaba a mano **sí** lo llevaba —«SALDO (a tu llegada): Efectivo
 * 71,83 · Tarjeta 75,78»—, así que la cifra y su recargo se estaban calculando fuera, a mano y
 * sin quedar en ningún sitio. Es exactamente la forma de derivar que este módulo vino a cerrar.
 *
 * ── Un tramo no es «el saldo» ───────────────────────────────────────────────
 * `PmsTotalesPorMoneda::$saldo` es contabilidad: cargos menos pagos, por moneda, sin convertir.
 * Un tramo es una PETICIÓN: cuánto se le pide a esta persona en este momento y cómo puede
 * dárnoslo. Que hoy el saldo tras el adelanto salga de una resta no los hace lo mismo.
 */
final readonly class PmsTramoDeCobro
{
    /**
     * @param PmsImporteMoneda      $importe Lo que se pide en este tramo, en UNA moneda.
     * @param list<PmsMedioDeCobro> $medios  Con qué se puede pagar ESTE tramo, ya con recargo.
     */
    public function __construct(
        public PmsImporteMoneda $importe,
        public array $medios,
    ) {
    }

    /**
     * Los medios agrupados por importe. Misma agrupación que en el resumen —y la MISMA
     * función—: son dos vistas del mismo dato, no dos formatos parecidos.
     *
     * @return list<array{importe: string, enSoles: string|null, recargoPorcentaje: string|null, codigos: list<string>, etiquetas: list<string>}>
     */
    public function mediosPorImporte(): array
    {
        return PmsMedioDeCobro::agruparPorImporte($this->medios);
    }
}
