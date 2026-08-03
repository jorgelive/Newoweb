/**
 * Utilidades de las respuestas de API Platform (JSON-LD / Hydra).
 *
 * Cada store se había escrito su propia versión de lo mismo — `extractData()` en
 * el de chat, `miembrosHydra()` en el de cotizaciones, y la expresión suelta
 * `res.data['hydra:member'] || res.data['member'] || []` repetida por todas
 * partes, siempre sobre un parámetro `any`. Aquí está una sola vez y tipada.
 *
 * Ojo: NO sirve para `/fullcalendar/load/*`, que devuelve un array pelado (o
 * envuelto en `{data}`) y no habla Hydra — para eso está `coleccionFeed()` en
 * `types/calendarFeedModel.ts`.
 */

/**
 * Identidad mínima de un recurso: la IRI (`@id`) y/o el `id`. Es lo único que
 * hace falta para compararlos, y evita castear a `any` cada vez que hay que
 * saber si dos referencias apuntan al mismo registro.
 */
export interface RecursoHydra {
    id?: string | null;
    '@id'?: string;
    /** Las tarifas locales del editor arrastran aquí el id del maestro. */
    tarifaId?: string | null;
}

interface VistaHydra {
    'hydra:next'?: string;
    next?: string;
}

interface RespuestaHydra<T> {
    'hydra:member'?: T[];
    member?: T[];
    'hydra:view'?: VistaHydra;
    view?: VistaHydra;
}

/**
 * Colección de una respuesta Hydra. Contempla las dos claves que devuelve API
 * Platform según la versión/formato (`hydra:member` y `member`) y el array
 * pelado, que es lo que responden algunos endpoints propios.
 */
export function miembrosHydra<T>(data: unknown): T[] {
    if (Array.isArray(data)) return data as T[];
    const respuesta = data as RespuestaHydra<T> | null | undefined;
    return respuesta?.['hydra:member'] || respuesta?.member || [];
}

/** ¿La colección tiene página siguiente? (paginación Hydra). */
export function hayPaginaSiguiente(data: unknown): boolean {
    const vista = (data as RespuestaHydra<unknown> | null | undefined);
    const view = vista?.['hydra:view'] || vista?.view;
    return !!(view && (view['hydra:next'] || view.next));
}

/**
 * UUID de un recurso, venga como IRI, como id suelto o como objeto.
 *
 * Existe porque la misma entidad llega con formas distintas según el grupo de
 * serialización (a veces IRI, a veces objeto embebido), y compararlas por
 * referencia o por `@id` a secas falla en silencio.
 */
export function uuidDe(entidad: unknown): string | null {
    if (!entidad) return null;
    if (typeof entidad === 'string') return entidad.split('/').pop() || null;

    const recurso = entidad as RecursoHydra;
    if (recurso.id) return String(recurso.id);
    if (recurso['@id']) return String(recurso['@id']).split('/').pop() || null;
    return null;
}

/** ¿Dos referencias apuntan al mismo registro? Compara por UUID, no por referencia. */
export function mismaEntidad(a: unknown, b: unknown): boolean {
    const ua = uuidDe(a);
    return !!ua && ua === uuidDe(b);
}
