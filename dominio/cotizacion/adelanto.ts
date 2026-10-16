/**
 * El adelanto que se pide para reservar una propuesta, y el resto.
 *
 * La regla de venta de la agencia —heredada de Oweb, donde salía bajo cada total— es: **para
 * reservar, un porcentaje del total (el 50 por defecto); el resto, el día del primer servicio.**
 * El porcentaje vive en `Cotizacion::$adelanto` (PHP) y aquí se convierte en dinero.
 *
 * Lo usan el editor de `util` (el `montoAdelanto` del resumen financiero) y el bloque «Cómo
 * reservar» de `pax`. Antes de existir esto el editor multiplicaba a mano y `pax` no lo enseñaba:
 * con dos sitios haciendo la cuenta, uno acabaría redondeando distinto que el otro y el cliente
 * leería un adelanto que no casa con lo que se le cobra.
 *
 * ⚠️ **El saldo es el total MENOS el adelanto ya redondeado**, no el otro porcentaje. Redondeados
 * por separado, 50 % de 139,99 da 70,00 + 70,00 = 140,00: un céntimo que no existe.
 *
 * Ver docs/Cotizaciones.md, «Cómo reservar».
 */

const r2 = (v: number): number => Math.round(v * 100) / 100;

/** El porcentaje dentro de 0–100: un dato raro no puede pedir más que el total ni un negativo. */
const acotar = (porcentaje: number): number =>
    Number.isFinite(porcentaje) ? Math.min(100, Math.max(0, porcentaje)) : 0;

/** Lo que se paga para reservar: `porcentaje` % de `total`, en céntimos. */
export function montoDeAdelanto(total: number, porcentaje: number): number {
    return r2(total * acotar(porcentaje) / 100);
}

/** Lo que queda para el día del primer servicio. Siempre suma exacto con el adelanto. */
export function saldoTrasAdelanto(total: number, porcentaje: number): number {
    return r2(r2(total) - montoDeAdelanto(total, porcentaje));
}

/**
 * Lo que falta pagar para completar el adelanto (0 si ya está), en céntimos.
 *
 * Lo leen el panel de pagos de `util` y «Cómo reservar» de `pax`: estuvo escrito dos veces, con
 * tolerancias distintas, y en el borde de medio céntimo una pantalla decía «cubierto» y la otra
 * «faltan 0,01» (revisión del 16/10/2026).
 */
export function faltaParaAdelanto(total: number, porcentaje: number, pagado: number): number {
    return Math.max(0, r2(montoDeAdelanto(total, porcentaje) - r2(pagado)));
}

/** ¿Lo pagado cubre el adelanto? Comparado en céntimos, nunca con decimales sueltos. */
export function adelantoCubierto(total: number, porcentaje: number, pagado: number): boolean {
    return faltaParaAdelanto(total, porcentaje, pagado) === 0;
}
