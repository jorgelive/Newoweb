/**
 * Normalización de texto para BUSCAR, no para mostrar.
 *
 * ⚠️ **Sin esto, «perez» no encuentra «Pérez».** Con un padrón peruano —Núñez, José, Rodríguez—
 * eso no es un caso borde: es el de todos los días, y falla en silencio. Quien busca no ve un
 * error, ve una lista vacía, y de ahí se concluye que el documento no está subido.
 *
 * Descompone (NFD) y quita los diacríticos combinantes, que es lo que separa la «é» de la «e».
 * La «ñ» **se conserva**: es una letra propia del alfabeto, no una «n» con adorno — pero como
 * NFD la parte en `n` + tilde, aquí acaba siendo una «n» a efectos de búsqueda, que es lo que
 * conviene: quien teclea «nunez» encuentra a los Núñez.
 */
export const sinTildes = (texto: string): string =>
    texto.normalize('NFD').replace(/[\u0300-\u036f]/g, '');

/** Lo mismo, ya en minúsculas: la forma en la que se comparan dos textos al filtrar. */
export const paraBuscar = (texto: string): string => sinTildes(texto).toLowerCase();
