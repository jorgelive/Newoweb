import type { RecursoHydra } from '@/types/cotizacionEditorModel';

/**
 * El id «pelado» de cualquier cosa que identifique a un recurso.
 *
 * Acepta una IRI (`/platform/ops/…/{uuid}`), un id suelto o el propio objeto —mira `@id`, `id`
 * y `tarifaId`, en ese orden—. Hace falta porque API Platform devuelve unas veces la ficha
 * entera y otras sólo la IRI, y quien consume no siempre sabe cuál le tocó.
 *
 * Vivía dentro de `cotizacionEditorStore`, donde no lo encontraba nadie más: Operación acabó
 * necesitándolo para sacar los ids de los servicios de una orden, que llegan como stubs.
 */
export const extractIdStr = (val: unknown): string => {
    if (!val) return '';

    if (typeof val === 'object') {
        const obj = val as RecursoHydra;
        const raw = obj['@id'] ?? obj.id ?? obj.tarifaId;
        if (raw) return String(raw).split('/').pop() || '';
    }

    return String(val).split('/').pop() || '';
};
