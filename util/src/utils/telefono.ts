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
 * Formato internacional legible: "+51 984 123 456", "+1 415 555 2671".
 *
 * Solo se reformatea cuando libphonenumber considera el número VÁLIDO. Con
 * cualquier otra cosa (texto libre, números incompletos, campos con notas) se
 * devuelve el valor crudo: mostrar el dato tal cual siempre es preferible a
 * inventarle un prefijo. Sin esta guarda, un "012345" se mostraría como
 * "+51 012345", que es directamente falso.
 */
export function formatearTelefono(valor?: string | null): string {
    const crudo = (valor ?? '').trim();
    if (!crudo) return '';
    const tel = parsePhoneNumberFromString(crudo, crudo.startsWith('+') ? undefined : PAIS_POR_DEFECTO);
    return tel?.isValid() ? tel.formatInternational() : crudo;
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
    const tel = parsePhoneNumberFromString(crudo, crudo.startsWith('+') ? undefined : PAIS_POR_DEFECTO);
    if (tel?.isValid()) return tel.number.replace('+', '');
    // Sin número válido no arriesgamos abrir un chat con un destinatario erróneo.
    return null;
}
