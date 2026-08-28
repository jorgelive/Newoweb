<?php

declare(strict_types=1);

namespace App\Pms\Finanzas;

use App\Finanzas\Entity\FinMedioCobro;

/**
 * Un medio de pago con **el importe que el huésped entrega por ese medio**.
 *
 * ── El join que no hacía nadie ──────────────────────────────────────────────
 * `consultar_cuenta` daba los importes y `consultar_medios_pago` daba los medios, y nadie
 * los cruzaba: el huésped recibía el total por un lado y una lista de bancos por otro, y
 * tenía que sumar el 5.5 % de cabeza para saber cuánto le cobrarían con tarjeta.
 *
 * Aquí el cálculo va **colapsado**: efectivo lleva el neto, tarjeta lleva el neto con su
 * recargo dentro. Es lo que evita que el resumen abrume — quien quiera el desglose abre el
 * detalle.
 */
final readonly class PmsMedioDeCobro
{
    public function __construct(
        public string $codigo,
        public string $etiqueta,
        /** Lo que se entrega por ESTE medio, ya con recargo si lo lleva. */
        public string $importe,
        public ?string $enSoles,
        /** `0.00` en los medios sin recargo. Va aparte para el detalle, no para el resumen. */
        public string $recargoPorcentaje,
        /**
         * Las fichas concretas de este medio: titular, banco, número, CCI.
         *
         * ⚠️ Es una LISTA porque un medio tiene varias: «Transferencia» son tres cuentas de
         * tres bancos. El catálogo devuelve una fila por cuenta y aquí se agrupan por TIPO,
         * que es como el huésped elige —«te transfiero» primero, «¿a qué banco?» después—.
         *
         * Sin agrupar, el resumen de una reserva real listaba **doce opciones**: exactamente
         * el abrumamiento que este formato viene a evitar. El resumen enseña los tipos; las
         * fichas son del detalle.
         *
         * Vacía en la tarjeta, que no tiene ficha: no hay número que teclear.
         *
         * @var list<FinMedioCobro>
         */
        public array $fichas,
    ) {
    }

    public function llevaRecargo(): bool
    {
        return (float) $this->recargoPorcentaje > 0.0;
    }
}
