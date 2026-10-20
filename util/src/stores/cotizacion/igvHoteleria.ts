/**
 * El aviso de IGV en los hoteles cuando viajan peruanos (19/10/2026).
 *
 * El hospedaje a extranjeros no domiciliados está exonerado de IGV (presentando pasaporte o TAM);
 * a los peruanos —y a los residentes— el hotel se lo cobra. Una cotización con hotel y un pasajero
 * peruano que no lo tenga en cuenta sale por debajo de lo que el hotel nos va a facturar, y no lo
 * denuncia nada: la tarifa es la del hotel, el cálculo cuadra y el margen se va en silencio.
 *
 * Sólo AVISA: no toca precios. La tasa depende del hotel (18 %, o 10,5 % si es MYPE de hospedaje),
 * así que el importe lo pone quien cotiza con la tarifa que dé el hotel.
 *
 * Señales de que viajan peruanos, cualquiera basta:
 * - pasajeros del manifiesto del expediente con país PE (`CotizacionFilepasajero::$pais`);
 * - alguna tarifa cotizada con procedencia «nacional» (el boleto nacional a Machu Picchu, por
 *   ejemplo), que es como se cotiza antes de tener manifiesto.
 */

/** `ComponenteTipoEnum::ALOJAMIENTO`. */
export const TIPO_ALOJAMIENTO = 'alojamiento';

const PAIS_PERU = 'PE';
const PROCEDENCIA_NACIONAL = 'nacional';

export interface PeruanosQueViajan {
    /** Pasajeros del manifiesto con país PE; 0 si todavía no hay manifiesto. */
    pasajeros: number;
    /** Hay al menos una tarifa de procedencia nacional en la cotización. */
    tarifasNacionales: boolean;
}

export const peruanosQueViajan = (
    paisesDelManifiesto: readonly (string | null | undefined)[],
    procedenciasDeTarifas: readonly (string | null | undefined)[],
): PeruanosQueViajan | null => {
    const pasajeros = paisesDelManifiesto.filter((p) => (p ?? '').trim().toUpperCase() === PAIS_PERU).length;
    const tarifasNacionales = procedenciasDeTarifas.some((p) => p === PROCEDENCIA_NACIONAL);

    return pasajeros > 0 || tarifasNacionales ? { pasajeros, tarifasNacionales } : null;
};

/** El aviso para un componente, o null si no toca: sólo hoteles, y sólo si viajan peruanos. */
export const avisoIgvDeHotel = (
    tipoDeComponente: string | null | undefined,
    peruanos: PeruanosQueViajan | null,
): string | null => {
    if (tipoDeComponente !== TIPO_ALOJAMIENTO || peruanos === null) {
        return null;
    }

    // De dónde sale cada señal, dicho: el manifiesto es el del EXPEDIENTE entero (no sólo los de
    // esta propuesta o subgrupo) y una sola tarifa nacional basta para todos los hoteles. Puede
    // avisar de más, nunca de menos; con la fuente a la vista, quien cotiza decide.
    const quien = peruanos.pasajeros > 0
        ? `${peruanos.pasajeros} ${peruanos.pasajeros === 1 ? 'pasajero peruano' : 'pasajeros peruanos'} en el expediente`
        : 'peruanos (hay tarifas de procedencia nacional en la cotización)';

    return `Viajan ${quien}: el hotel les cobra IGV (18 %, o 10,5 % si es MYPE). `
        + 'Cotiza su habitación con la tarifa con IGV que dé el hotel. '
        + 'Los extranjeros no domiciliados están exonerados presentando pasaporte o TAM.';
};
