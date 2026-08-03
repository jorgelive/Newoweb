/**
 * src/utils/telefono.ts
 *
 * Formateo de teléfonos con `libphonenumber-js` (metadata "min" por defecto).
 *
 * ¿Por qué existe?
 * Los contactos llegan de cualquier origen (OTAs, formularios web, carga manual)
 * y por tanto de cualquier país, con formatos inconsistentes: unos traen prefijo
 * internacional, otros solo el número nacional. Antes había dos formateadores
 * distintos escritos a mano —uno en el drawer de reservas y otro en el detalle de
 * expedientes— y ambos asumían Perú con reglas fijas, así que un número europeo o
 * estadounidense se mostraba mal.
 *
 * Este módulo es la única fuente de verdad para *mostrar* un teléfono. No
 * normaliza lo que se persiste: el valor guardado sigue siendo el que escribió el
 * usuario / envió el canal.
 */

import { parsePhoneNumberFromString, type CountryCode } from 'libphonenumber-js';

/**
 * País asumido cuando el número llega SIN prefijo internacional (caso típico de
 * las reservas directas peruanas: "984123456"). Si el número ya trae "+", el
 * prefijo manda y este valor se ignora.
 */
const PAIS_POR_DEFECTO: CountryCode = 'PE';

/**
 * Interpreta un teléfono probando dos lecturas, en este orden:
 *
 *   1. NACIONAL del país por defecto — "984123456" → +51 984 123 456.
 *   2. INTERNACIONAL al que le falta el "+" — "5561998210624" → +55 61 99821 0624.
 *
 * El paso 2 existe porque las OTAs entregan el número con código de país pero sin
 * el "+": Booking.com mandó "5561998210624" (Brasil) y, al leerlo como peruano,
 * libphonenumber lo daba por inválido — se mostraba crudo y desaparecía el botón
 * de WhatsApp. Un número peruano de 9 dígitos sigue resolviéndose en el paso 1, así
 * que el orden preserva el comportamiento anterior y solo rescata lo que fallaba.
 *
 * Devuelve `undefined` si ninguna lectura da un número válido: ante texto libre o
 * números incompletos preferimos no inventar nada.
 */
function interpretar(crudo: string) {
    if (crudo.startsWith('+')) {
        const tel = parsePhoneNumberFromString(crudo);
        return tel?.isValid() ? tel : undefined;
    }

    const nacional = parsePhoneNumberFromString(crudo, PAIS_POR_DEFECTO);
    if (nacional?.isValid()) return nacional;

    // Solo tiene sentido si son dígitos: evita convertir notas en teléfonos.
    if (!/^\d{8,15}$/.test(crudo.replace(/[\s()-]/g, ''))) return undefined;

    const internacional = parsePhoneNumberFromString('+' + crudo.replace(/[\s()-]/g, ''));
    return internacional?.isValid() ? internacional : undefined;
}

/**
 * Formato internacional legible: "+51 984 123 456", "+1 415 555 2671".
 *
 * Con cualquier cosa que no sea un número válido (texto libre, números
 * incompletos, campos con notas) se devuelve el valor crudo: mostrar el dato tal
 * cual siempre es preferible a inventarle un prefijo. Sin esta guarda, un "012345"
 * se mostraría como "+51 012345", que es directamente falso.
 */
export function formatearTelefono(valor?: string | null): string {
    const crudo = (valor ?? '').trim();
    if (!crudo) return '';
    return interpretar(crudo)?.formatInternational() ?? crudo;
}

/**
 * Número en E.164 SIN el "+" (ej. "51984123456"), que es lo que esperan
 * `wa.me` / `api.whatsapp.com` para abrir una conversación.
 *
 * Devuelve `null` si no hay un número utilizable, para que quien llame pueda
 * ocultar la acción en vez de abrir un WhatsApp roto.
 */
export function telefonoParaWhatsapp(valor?: string | null): string | null {
    const crudo = (valor ?? '').trim();
    if (!crudo) return null;
    // Sin número válido no arriesgamos abrir un chat con un destinatario erróneo.
    return interpretar(crudo)?.number.replace('+', '') ?? null;
}
