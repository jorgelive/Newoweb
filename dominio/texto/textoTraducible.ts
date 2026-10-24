/**
 * Qué es un texto traducible VACÍO (23/10/2026).
 *
 * Los textos traducibles son listas `[{ language, content, origenHash? }]` y las traducciones salen
 * del español (`#[AutoTranslate(sourceLanguage: 'es')]`). Si el español se BORRA, las traducciones
 * automáticas que quedan traducen un texto que ya no existe.
 *
 * Pasó: se puso «1» de prueba en un nombre público, se tradujo a seis idiomas, se borró el español
 * y la propuesta siguió diciendo «Incluye: 1» —el editor contaba entradas, y la guía, al ver el
 * español vacío, caía al inglés—.
 *
 * Por eso está aquí, una vez: el editor (`util`) decide con esto si un texto existe y qué queda al
 * borrar el español, y la guía (`pax`) no cae a otro idioma cuando lo único que queda son
 * traducciones de lo borrado. El servidor aplica la misma regla al traducir
 * (`AutoTranslationService::translateAndCloneRows()`, `docs/Autotraduccion.md`) — si cambia una,
 * cambia la otra.
 *
 * ⚠️ **Sólo se descartan las traducciones AUTOMÁTICAS** (con `origenHash`, salvo `'manual'`). Una
 * escrita a mano, o curada, no traduce el español: es un texto por derecho propio y se queda
 * (Fable, revisión del 23/10/2026).
 */

/** El idioma del que salen las traducciones. */
export const IDIOMA_ORIGEN = 'es';

export interface TextoEnIdioma {
    language?: string | null;
    content?: string | null;
    origenHash?: string | null;
}

/**
 * Las etiquetas que produce el editor enriquecido (`WysiwygEditor.vue`: negrita, cursiva,
 * subrayado, listas, párrafos y saltos) — y SÓLO ésas. Un título de texto plano escrito con
 * corchetes, «<Hotel por confirmar>», no es una etiqueta y no se quita (Fable, 23/10/2026).
 *
 * ⚠️ **Espejo de `AutoTranslationService::sinTexto()` en PHP**: misma lista de etiquetas, mismas
 * entidades de espacio y mismos espacios Unicode. Si cambia una, cambia la otra.
 */
const ETIQUETA_DE_FORMATO = /<\/?(?:a|b|br|div|em|font|h[1-6]|i|li|ol|p|span|strong|u|ul)\b[^>]*>/gi;
const ENTIDAD_DE_ESPACIO = /&nbsp;|&#160;|&#x0*a0;/gi;
const ESPACIOS = /[\s  -​　﻿]+/g;

/**
 * ¿Este contenido no dice nada? También el HTML sin texto: el editor enriquecido deja `<br>` o
 * `<p><br></p>` al vaciar un párrafo, no una cadena vacía.
 */
const sinTexto = (contenido: string | null | undefined): boolean =>
    (contenido ?? '')
        .replace(ETIQUETA_DE_FORMATO, '')
        .replace(ENTIDAD_DE_ESPACIO, ' ')
        .replace(ESPACIOS, '') === '';

/** Una traducción hecha por la máquina a partir del español. */
const esAutomatica = (t: TextoEnIdioma): boolean =>
    typeof t.origenHash === 'string' && t.origenHash !== '' && t.origenHash !== 'manual';

/**
 * ¿No hay texto?
 *
 * - Con el español presente y vacío —lo borró alguien—: vacío salvo que quede una traducción hecha
 *   A MANO con texto.
 * - Con el español presente y con texto: no está vacío.
 * - Sin español: vacío sólo si ningún idioma tiene texto (un nombre escrito sólo en inglés es un
 *   nombre).
 */
export const textoVacio = (texto: readonly TextoEnIdioma[] | null | undefined): boolean => {
    const lista = Array.isArray(texto) ? texto : [];
    const origen = lista.find((t) => t.language === IDIOMA_ORIGEN);

    if (origen && !sinTexto(origen.content)) {
        return false;
    }

    return lista.every((t) => t === origen || (origen !== undefined && esAutomatica(t)) || sinTexto(t.content));
};

/**
 * El texto sin las traducciones automáticas de un español borrado. Si el español tiene texto, o no
 * está, devuelve la misma lista. Deja el español (vacío) y lo escrito a mano.
 */
export const sinTraduccionesDeLoBorrado = <T extends TextoEnIdioma>(texto: T[]): T[] => {
    const origen = texto.find((t) => t.language === IDIOMA_ORIGEN);

    if (!origen || !sinTexto(origen.content)) {
        return texto;
    }

    return texto.filter((t) => !esAutomatica(t));
};
