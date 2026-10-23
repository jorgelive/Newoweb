/**
 * Los «fantasmas» del editor: un servicio de varios días visto en los días que no son el suyo
 * (Jorge, 23/10/2026).
 *
 * En el editor un servicio vive en UN día —el de su `fechaInicioAbsoluta`—, y un hotel del 7 al
 * 10 o un Camino Inca de cuatro días dejaban los días siguientes con aspecto de vacíos. El cliente
 * sí los ve repartidos: la guía pinta cada párrafo en su fecha y repite las estadías cada noche.
 *
 * ── No es una regla nueva ───────────────────────────────────────────────────
 * Sale de `componerItinerario()` (`@dominio/cotizacion`), la MISMA función que reparte los días en
 * la guía del cliente y en PHP. Lo que aquí se añade es sólo la pregunta del editor: de cada día,
 * ¿qué pertenece a un servicio que empezó en otro? Escribir aquí el reparto —noches vs días, el
 * `+1` del seguro, los párrafos con fecha propia— sería la segunda copia de una regla, y la
 * primera vez que cambiara una el editor y el cliente dirían días distintos.
 *
 * ⚠️ **La unidad de conteo llega resuelta del servidor** (`getUnidadDeConteo()`). Un componente
 * añadido en esta sesión no la trae hasta guardarse y cuenta en noches, que es el defecto de la
 * función: un seguro recién puesto se ve un día más corto hasta guardar.
 */
import { componerItinerario, diffDays } from '@dominio/cotizacion/index.ts';
import type { ServicioMinimo } from '@dominio/cotizacion/index.ts';
import type { CotServicio, I18nContent } from '@/types/cotizacionEditorModel.ts';

export interface Fantasma {
    servicioId: string;
    /** Nombre interno del servicio. */
    nombre: string;
    /** «noche 2 de 3», «día 3 de 4». */
    etiqueta: string;
    /** Títulos de los párrafos que el cliente ve ESE día; vacío en una estadía repetida. */
    titulos: string[];
}

export interface OpcionesFantasmas {
    /** El día en que el editor pone el servicio (`getFechaLimpia(fechaInicioAbsoluta)`). */
    diaDelServicio: (servicio: CotServicio) => string;
    texto: (i18n: I18nContent[] | undefined) => string;
}

/** Lo que `componerItinerario` lee, sacado de la forma del editor. */
const aMinimo = (servicio: CotServicio): ServicioMinimo & { original: CotServicio } => ({
    id: String(servicio.id),
    orden: servicio.orden ?? 0,
    contenedorGenerico: servicio.contenedorGenerico === true,
    original: servicio,
    cotsegmentos: (servicio.cotsegmentos ?? []).map((seg) => ({
        id: String(seg.id),
        dia: seg.dia ?? 1,
        orden: seg.orden ?? 1,
        // Un segmento recién creado puede no tener fecha: el guardado le pone la del servicio, y
        // aquí se hace lo mismo para que no caiga en «día vacío».
        fechaAbsoluta: seg.fechaAbsoluta || String(servicio.fechaInicioAbsoluta ?? ''),
    })),
    cotcomponentes: (servicio.cotcomponentes ?? []).map((comp) => {
        // `cotsegmento` llega como IRI, como objeto o sólo como `cotsegmentoId`, según de dónde
        // venga el componente. La función compara por `cotsegmento.id`.
        const ref = comp.cotsegmentoId
            ?? (typeof comp.cotsegmento === 'string' ? comp.cotsegmento : comp.cotsegmento?.id)
            ?? null;
        const segId = ref ? String(ref).split('/').pop() ?? null : null;

        return {
            id: String(comp.id),
            cotsegmento: segId ? { id: segId } : null,
            fechaHoraInicio: comp.fechaHoraInicio,
            fechaHoraFin: comp.fechaHoraFin,
            sinHorario: comp.sinHorario,
            horaServicioCompleto: comp.horaServicioCompleto,
            unidadDeConteo: comp.unidadDeConteo ?? null,
            sustantivoUnidad: comp.sustantivoUnidad ?? null,
        };
    }),
});

/** Día (AAAA-MM-DD) → fantasmas de ese día, en el orden en que el cliente los lee. */
export const fantasmasPorDia = (servicios: CotServicio[], opciones: OpcionesFantasmas): Map<string, Fantasma[]> => {
    const minimos = servicios.map(aMinimo);
    const dias = componerItinerario({ cotservicios: minimos });

    // Hasta qué día llega cada servicio, para decir «día 3 de 4».
    const ultimoDia = new Map<string, string>();
    for (const dia of dias) {
        for (const b of dia.bloques) ultimoDia.set(b.servicio.id, dia.fecha);
    }

    const resultado = new Map<string, Fantasma[]>();

    for (const dia of dias) {
        const porServicio = new Map<string, Fantasma>();

        for (const bloque of dia.bloques) {
            const servicio = bloque.servicio.original;
            const inicio = opciones.diaDelServicio(servicio);
            if (dia.fecha === inicio) continue;

            const id = bloque.servicio.id;
            let fantasma = porServicio.get(id);

            if (!fantasma) {
                const etiqueta = bloque.esPeriodo && bloque.esRepeticion
                    ? `${bloque.sustantivoUnidad || (bloque.unidad === 'dias' ? 'día' : 'noche')} ${bloque.indiceUnidad} de ${bloque.totalUnidades}`
                    : `día ${diffDays(inicio, dia.fecha) + 1} de ${diffDays(inicio, ultimoDia.get(id) ?? dia.fecha) + 1}`;

                fantasma = {
                    servicioId: id,
                    nombre: opciones.texto(servicio.nombreInternoSnapshot) || 'Servicio',
                    etiqueta,
                    titulos: [],
                };
                porServicio.set(id, fantasma);
            }

            if (!bloque.esRepeticion) {
                const seg = servicio.cotsegmentos?.find((s) => String(s.id) === bloque.segmento.id);
                const titulo = opciones.texto(seg?.tituloSnapshot);
                if (titulo && !fantasma.titulos.includes(titulo)) fantasma.titulos.push(titulo);
            }
        }

        if (porServicio.size) resultado.set(dia.fecha, [...porServicio.values()]);
    }

    return resultado;
};
