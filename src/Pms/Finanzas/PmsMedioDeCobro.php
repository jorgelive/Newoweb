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

    /**
     * Los medios agrupados **por importe**, que es lo único que los distingue para el huésped.
     *
     * ── El abrumamiento vuelve si se listan uno a uno ───────────────────────────
     * Se agrupó por TIPO —de doce filas del catálogo a cinco medios— y seguía sobrando:
     * «Western Union 164.10 · Efectivo 164.10 · Yape 164.10 · Plin 164.10 · Transferencia
     * 164.10 · Tarjeta 173.13» son seis líneas que dicen **dos cifras**. Repetir el mismo
     * número cinco veces es el mismo ruido en otro formato.
     *
     * Lo que de verdad hay que decidir no es «¿por dónde pago?» sino «¿me cuesta lo mismo?».
     * Y sólo hay dos respuestas: el precio normal, y el de tarjeta con su recargo:
     *
     *   Efectivo, Yape, Plin o transferencia ...... 164.10
     *   Tarjeta / Enlace (incluye 5.5%) ........... 173.13
     *
     * Es exactamente la forma del mensaje que el equipo ya escribía a mano.
     *
     * ⚠️ **Vive aquí, y no en `PmsSituacionDeCobro`, desde el 30/08/2026.** Con el saldo tras
     * el adelanto ({@see PmsTramoDeCobro}) hay DOS sitios que agrupan la misma lista, y la
     * segunda copia de una función de presentación es por donde se separan: una enseñaría el
     * recargo y la otra no, en el mismo mensaje.
     *
     * ⚠️ El orden se conserva —`ofrecibles()` ya viene ordenado y la tarjeta va al final—
     * porque abrir por la opción cara empuja a pagar de más a quien podía transferir.
     *
     * ⚠️ Viajan los **códigos** además de las etiquetas. La etiqueta sale de
     * `FinMedioCobroTipo::label()` y está en español; `pax` tiene los suyos traducidos en
     * `UiI18n` (`res_medio_yape`, `res_medio_efectivo`…) y resuelve por código. Mandar sólo
     * la etiqueta era enseñarle «Transferencia bancaria» a un huésped que lee en inglés.
     *
     * @param  list<self> $medios
     * @return list<array{importe: string, enSoles: string|null, recargoPorcentaje: string|null, codigos: list<string>, etiquetas: list<string>}>
     */
    public static function agruparPorImporte(array $medios): array
    {
        $grupos = [];

        foreach ($medios as $medio) {
            // La clave incluye el recargo: dos medios con el mismo importe pero uno con
            // comisión no son el mismo caso, aunque hoy no pueda pasar.
            $clave = $medio->importe . '|' . $medio->recargoPorcentaje;

            if (!isset($grupos[$clave])) {
                $grupos[$clave] = [
                    'importe' => $medio->importe,
                    'enSoles' => $medio->enSoles,
                    'recargoPorcentaje' => $medio->llevaRecargo() ? $medio->recargoPorcentaje : null,
                    'codigos' => [],
                    'etiquetas' => [],
                ];
            }

            $grupos[$clave]['codigos'][] = $medio->codigo;
            $grupos[$clave]['etiquetas'][] = $medio->etiqueta;
        }

        return array_values($grupos);
    }
}
