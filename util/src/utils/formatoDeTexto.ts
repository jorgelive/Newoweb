// src/utils/formatoDeTexto.ts

import { escaparHtml } from './html';

/**
 * El formato canónico de los mensajes, pintado como HTML para el panel.
 *
 * 🔁 **Espejo TS ↔ PHP.** Las reglas son las de
 * `src/Message/Service/Formato/FormatoDeTexto.php`, que es quien degrada el mismo texto para
 * WhatsApp y Beds24 al enviarlo. Si tocas una marca o una regla de normalización aquí, tócala
 * también allí — o el operador verá en el panel un formato distinto del que recibe el huésped.
 *
 * El canónico es el de WhatsApp, más una marca propia:
 *
 *   *negrita*   _cursiva_   ~tachado~   __subrayado__ (nuestra; WhatsApp no tiene subrayado)
 *
 * El orden del pipeline importa y es el mismo que en PHP:
 * 1. Escapar HTML (el texto viene de fuera; un `<` no puede romper el marcado).
 * 2. Apartar las URLs (llevan `_` y `*` con todo el derecho; las marcas las mutilarían).
 * 3. Normalizar el Markdown que se le escapa al modelo (`**x**` → `*x*`, `# título`, `- lista`).
 * 4. Convertir las marcas a etiquetas, subrayado PRIMERO (`__x__` contiene `_x_`).
 * 5. Devolver las URLs como enlaces.
 */

/** Markdown de modelo → canónico WhatsApp. Idempotente sobre texto ya canónico. */
function normalizar(texto: string): string {
    return texto
        // [texto](url) → «texto: url»; la URL puede venir ya como marcador \x00N\x00.
        .replace(/\[([^\]\n]+)\]\((https?:\/\/[^\s)]+|\x00\d+\x00)\)/gu, '$1: $2')
        .replace(/^#{1,6}[ \t]+(.+?)[ \t]*$/gmu, '*$1*')
        .replace(/\*\*(?=\S)([^*\n]*\S)\*\*/gu, '*$1*')
        .replace(/~~(?=\S)([^~\n]*\S)~~/gu, '~$1~')
        .replace(/^[ \t]*[-*][ \t]+/gmu, '• ');
}

/**
 * Texto canónico → HTML seguro para pintar con `v-html` en el chat.
 */
export function formatoAHtml(texto: string): string {
    const urls: string[] = [];

    let salida = escaparHtml(texto)
        // Se apartan DESPUÉS de escapar: la URL guardada ya es inofensiva como atributo.
        .replace(/https?:\/\/[^\s<]+/gu, (crudo) => {
            // Lo pegado al final —«mira *url*», «visita url.»— es texto, no URL (espejo del
            // rtrim de FormatoDeTexto.php).
            const url = crudo.replace(/[*_~.,;:!?)]+$/u, '');
            const cola = crudo.slice(url.length);
            urls.push(url);
            return `\x00${urls.length - 1}\x00${cola}`;
        });

    salida = normalizar(salida)
        .replace(/__(?=\S)([^_\n]*\S)__/gu, '<u>$1</u>')
        .replace(/\*(?=\S)([^*\n]*\S)\*/gu, '<strong>$1</strong>')
        .replace(/_(?=\S)([^_\n]*\S)_/gu, '<em>$1</em>')
        .replace(/~(?=\S)([^~\n]*\S)~/gu, '<s>$1</s>');

    return salida.replace(/\x00(\d+)\x00/g, (_, indice: string) => {
        const url = urls[Number(indice)] ?? '';
        return `<a href="${url}" target="_blank" rel="noopener noreferrer" class="underline font-bold hover:opacity-70 transition-opacity">${url}</a>`;
    });
}

/**
 * Envuelve la selección de un textarea con una marca del canónico (la barra B/I/U/S del
 * compositor). Devuelve el texto nuevo y dónde dejar el cursor; el que llama actualiza el
 * modelo y repone la selección.
 */
export function envolverSeleccion(
    texto: string,
    inicio: number,
    fin: number,
    marca: string,
): { texto: string; inicio: number; fin: number } {
    const seleccion = texto.slice(inicio, fin);

    // Sin selección: se insertan las marcas y el cursor queda dentro, listo para escribir.
    if (seleccion === '') {
        return {
            texto: texto.slice(0, inicio) + marca + marca + texto.slice(fin),
            inicio: inicio + marca.length,
            fin: inicio + marca.length,
        };
    }

    // Si ya estaba marcada, la marca se quita en vez de anidarse: el botón alterna.
    if (seleccion.startsWith(marca) && seleccion.endsWith(marca) && seleccion.length >= marca.length * 2) {
        const desnuda = seleccion.slice(marca.length, seleccion.length - marca.length);
        return {
            texto: texto.slice(0, inicio) + desnuda + texto.slice(fin),
            inicio,
            fin: inicio + desnuda.length,
        };
    }

    return {
        texto: texto.slice(0, inicio) + marca + seleccion + marca + texto.slice(fin),
        inicio,
        fin: fin + marca.length * 2,
    };
}
