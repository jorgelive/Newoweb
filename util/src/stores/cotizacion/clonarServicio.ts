/**
 * Clonar un servicio: en la misma propuesta o en otra, con copiar y pegar (23/10/2026).
 *
 * ── Por qué copiar y pegar, y no un endpoint ────────────────────────────────
 * El servidor ya sabe copiar un servicio (`CotizacionCotservicio::duplicar()`), pero meterlo en
 * OTRA propuesta desde allí la dejaría a medias: los totales y el «qué incluye» del cliente
 * (`clasificacionFinanciera*`) los calcula el editor al guardar, y una propuesta a la que el
 * servidor le añade un servicio seguiría enseñando el total de antes hasta que alguien la abriera
 * y guardara. Pegando en el editor, la copia entra por el guardado de siempre y todo eso se
 * recalcula solo. Y es UN mecanismo para los dos casos: la misma propuesta es «pegar aquí mismo».
 *
 * ── Qué hace la copia ───────────────────────────────────────────────────────
 * - **Ids nuevos para todo el árbol** —servicio, segmentos, componentes, tarifas, ítems y
 *   detalles— y **reapuntados por dentro**: el segmento de cada componente, `duplicadoDe`, el
 *   componente inyectado de un ítem. Se hace sustituyendo cada id viejo por el nuevo en el árbol
 *   entero, así que una referencia interna que se añada mañana queda cubierta sin tocar esto.
 *   Lo que apunta FUERA del árbol (el catálogo, la moneda) no está en el mapa y no se toca.
 * - **Se mueve de fecha por DELTA**, igual que `Cotizacion::desplazarA()`: todo se corre los
 *   mismos días, así que un traslado a las 05:30 y su tren a las 07:45 siguen separados lo mismo.
 * - **Los subgrupos sólo viajan dentro del mismo expediente**: cuelgan de él, y en otro serían
 *   IRIs de reservas ajenas. Fuera, la copia nace «para todo el grupo».
 */
import { addDurationToDate, parseNaiveAsUTC } from '@/utils/naiveDate';
import type { CotServicio } from '@/types/cotizacionEditorModel.ts';

/** Lo que guarda el portapapeles: el servicio y de dónde salió. */
export interface ServicioCopiado {
    servicio: CotServicio;
    /** Su nombre interno, para el botón de pegar. */
    nombre: string;
    origen: {
        cotizacionId: string | null;
        /** Decide si los subgrupos viajan con la copia. */
        fileId: string | null;
        propuesta: number | null;
    };
}

export interface OpcionesDeClonado {
    /** Día (AAAA-MM-DD) en el que tiene que empezar la copia. */
    fechaDestino: string;
    /** ¿Se pega en el mismo expediente del que salió? Si no, sin subgrupos. */
    mismoExpediente: boolean;
    /** Para tests: cómo se generan los ids. */
    nuevoId?: () => string;
}

const DIA_MS = 24 * 60 * 60 * 1000;

/** Días enteros de `desde` a `hasta`, sólo por la parte de fecha. */
export const diasEntre = (desde: string, hasta: string): number => {
    const a = parseNaiveAsUTC(desde.slice(0, 10));
    const b = parseNaiveAsUTC(hasta.slice(0, 10));

    return Number.isNaN(a) || Number.isNaN(b) ? 0 : Math.round((b - a) / DIA_MS);
};

/** Corre una fecha naive N días conservando su forma: con hora si la tenía, sin ella si no. */
const mover = (fecha: string | null | undefined, dias: number): string | null | undefined => {
    if (!fecha || dias === 0) return fecha;

    const movida = addDurationToDate(fecha, dias * 24);
    if (movida === '') return fecha;

    return fecha.length === 10 ? movida.slice(0, 10) : movida;
};

/** Los ids del árbol del servicio, que son los que la copia tiene que renovar. */
const idsDelArbol = (servicio: CotServicio): string[] => {
    const ids: (string | null | undefined)[] = [servicio.id];

    for (const seg of servicio.cotsegmentos ?? []) ids.push(seg.id);

    for (const comp of servicio.cotcomponentes ?? []) {
        ids.push(comp.id);
        for (const tarifa of comp.cottarifas ?? []) ids.push(tarifa.id);
        for (const item of comp.snapshotItems ?? []) ids.push(item.id);
        for (const detalle of comp.detallesOperativos ?? []) ids.push(detalle.id);
    }

    return ids.filter((id): id is string => typeof id === 'string' && id !== '');
};

/**
 * La copia del servicio, lista para meter en `cotservicios`. No toca el original.
 */
export const clonarServicio = (original: CotServicio, opciones: OpcionesDeClonado): CotServicio => {
    const nuevoId = opciones.nuevoId ?? (() => crypto.randomUUID());

    let texto = JSON.stringify(original);
    for (const viejo of new Set(idsDelArbol(original))) {
        texto = texto.split(viejo).join(nuevoId());
    }
    const copia = JSON.parse(texto) as CotServicio;

    const dias = diasEntre(original.fechaInicioAbsoluta ?? opciones.fechaDestino, opciones.fechaDestino);

    copia.fechaInicioAbsoluta = mover(copia.fechaInicioAbsoluta, dias) ?? opciones.fechaDestino;

    for (const seg of copia.cotsegmentos ?? []) {
        seg.fechaAbsoluta = mover(seg.fechaAbsoluta, dias) ?? seg.fechaAbsoluta;
    }

    for (const comp of copia.cotcomponentes ?? []) {
        comp.fechaHoraInicio = mover(comp.fechaHoraInicio, dias) ?? comp.fechaHoraInicio;
        comp.fechaHoraFin = mover(comp.fechaHoraFin, dias) ?? comp.fechaHoraFin;

        if (!opciones.mismoExpediente) {
            comp.grupos = [];
        }
    }

    return copia;
};
