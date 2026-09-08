/**
 * El tope antifraude de la pasarela: la MISMA regla que aplica el servidor.
 *
 * 🪞 **Espejo de `FinEnlacePagoService::comprobarTope()`. Al tocar una, tocar la otra.**
 *
 * Vive aquí y no en cada app por lo que este mismo cambio vino a arreglar: el recargo de tarjeta
 * estaba tecleado en `pax` con un comentario que juraba ser «el único sitio», y había tres copias.
 * Poner esta función en las dos apps habría sido repetir el error el mismo día de corregirlo.
 */

/** Lo que sirve `GET /config/front`. Los valores son CADENAS: son importes y porcentajes. */
export interface ConfigDelFront {
    recargoTarjetaPorcentaje: string;
    /** Tope por divisa. Una divisa ausente **no tiene tope conocido**: no se inventa uno. */
    limitePorCargo: Record<string, string>;
}

/**
 * Lo que se cobraría de verdad: el neto más el recargo.
 *
 * ⚠️ Redondeo a dos decimales por multiplicación entera, no con `toFixed` sobre el float: es lo
 * que hace que `2900 * 1.055` dé `3059.5` y no `3059.4999999999995`.
 */
export function totalConRecargo(neto: number, recargoPorcentaje: string): number {
    const pct = Number(recargoPorcentaje);

    if (!Number.isFinite(neto) || !Number.isFinite(pct)) return neto;

    return Math.round(neto * (1 + pct / 100) * 100) / 100;
}

/**
 * ¿Este importe pasa el tope? Devuelve el aviso, o `null` si pasa.
 *
 * 🔥 **Se compara el TOTAL, con recargo**, que es lo que la pasarela ve. Un neto de 2 900 USD se
 * cobra como 3 059,50: por debajo del tope y aun así rechazado. Compararlo contra el neto dejaría
 * pasar exactamente los casos que fallan — que son los del filo, los únicos que se cuelan.
 *
 * ⚠️ Sin tope configurado para esa divisa, **no se bloquea**: es un límite del proveedor, no una
 * política nuestra, e inventarlo impediría cobros que la pasarela sí acepta.
 */
export function avisoDeTope(
    neto: number,
    moneda: string,
    config: ConfigDelFront,
    conRecargo = true,
): string | null {
    const tope = config.limitePorCargo[moneda];

    if (!tope || !Number.isFinite(neto) || neto <= 0) return null;

    const total = conRecargo ? totalConRecargo(neto, config.recargoTarjetaPorcentaje) : neto;

    if (total <= Number(tope)) return null;

    return `Se cobrarían ${total.toFixed(2)} ${moneda} con el recargo, y la pasarela no acepta `
        + `más de ${tope} ${moneda} por operación. Parte el cobro o usa otro medio.`;
}
