/**
 * La superficie pública del dominio de cotizaciones.
 *
 * ⚠️ **Las apps importan de aquí, nunca de un archivo de dentro.** Un import profundo
 * —`@dominio/cotizacion/itinerarioVista`— ata al consumidor a la organización interna, y entonces
 * mover un archivo rompe a alguien. Con una sola puerta, dentro se reorganiza libremente.
 *
 * Lo vigila una regla de ESLint en las dos apps.
 */
export {
    componerItinerario,
    posicionDeServicio,
    dateOf,
    hhmm,
    addDays,
    diffDays,
    compConHora,
} from './itinerarioVista.ts';

export type {
    BloqueVista,
    DiaVista,
    ServicioMinimo,
    SegmentoMinimo,
    ComponenteMinimo,
} from './itinerarioVista.ts';
