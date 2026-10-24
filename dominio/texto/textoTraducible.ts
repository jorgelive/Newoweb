/**
 * Qué es un texto traducible VACÍO (23/10/2026).
 *
 * Los textos traducibles son listas `[{ language, content }]` y las traducciones salen del
 * español (`#[AutoTranslate(sourceLanguage: 'es')]`). Si el español se BORRA, lo que quede en los
 * otros idiomas traduce un texto que ya no existe.
 *
 * Pasó: se puso «1» de prueba en un nombre público, se tradujo a seis idiomas, se borró el español
 * y la propuesta siguió diciendo «Incluye: 1» —el editor contaba entradas, y la guía, al ver el
 * español vacío, caía al inglés—.
 *
 * Por eso está aquí, una vez: el editor (`util`) decide con esto si un componente tiene nombre, y
 * la guía (`pax`) no cae a otro idioma cuando el español está vacío. El servidor aplica la misma
 * regla al traducir (`AutoTranslationService::translateAndCloneRows()`, `docs/Autotraduccion.md`).
 */

/** El idioma del que salen las traducciones. */
export const IDIOMA_ORIGEN = 'es';

export interface TextoEnIdioma {
    language?: string | null;
    content?: string | null;
}

/**
 * ¿No hay texto? Sí si el español EXISTE y está vacío —lo borró alguien—, o si ningún idioma
 * tiene texto. Un español que FALTA no lo vacía: un nombre escrito sólo en inglés es un nombre.
 */
export const textoVacio = (texto: readonly TextoEnIdioma[] | null | undefined): boolean => {
    const lista = Array.isArray(texto) ? texto : [];
    const origen = lista.find((t) => t.language === IDIOMA_ORIGEN);

    return origen ? (origen.content ?? '').trim() === '' : lista.every((t) => (t.content ?? '').trim() === '');
};
