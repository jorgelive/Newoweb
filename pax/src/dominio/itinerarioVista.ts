/**
 * Cómo se compone el itinerario que ve el cliente: segmentos → bloques → días.
 *
 * ── Por qué es un módulo y no un `computed` ─────────────────────────────────
 * Vivió dentro de `PaxCotizacionGuiaView.vue` —1.953 líneas— y allí **no lo podía importar
 * nadie**: ni un generador de PDF, ni un test, ni un proceso Node. Un `computed` enterrado en un
 * componente es lógica de negocio con una sola puerta, y la puerta es el navegador.
 *
 * Aquí no hay Vue, ni store, ni `window`: entra la cotización, salen los días. La vista lo sigue
 * llamando desde un `computed` de una línea, así que **la reactividad no cambia** — es la misma
 * función, invocada desde el mismo sitio.
 *
 * ── Es el primer inquilino de la capa compartida ────────────────────────────
 * `docs/NodeEnElStack.md` §4 fija la frontera: *Node calcula, PHP persiste*. Para llegar ahí hace
 * falta que las reglas se puedan importar desde fuera del navegador, y esto es el primer paso
 * medido: 209 líneas que dejan de estar encerradas.
 *
 * ⚠️ Cuando exista el paquete compartido entre `util` y `pax`, este archivo se muda tal cual —
 * por eso no importa nada de `@/stores` ni de `vue`. Mantenerlo así es lo que hace barata la
 * mudanza; el día que alguien le meta un `import { useX } from '@/stores/…'`, deja de poder salir.
 *
 * ── El espejo que sigue vivo ────────────────────────────────────────────────
 * ⚠️ `posicionDeServicio()` está también en `util/src/stores/cotizacion/cotizacionEditorStore.ts`.
 * Si cambia una, se cambian las dos, o el editor y la guía enseñan días distintos. Ese espejo es
 * justamente lo que se borra cuando este módulo pase al paquete compartido — hasta entonces,
 * sigue siendo trabajo a mano.
 */
import type { PaxCotServicio, PaxCotSegmento, PaxCotComponente } from '@/types/paxCotizacionModel';

// ── Helpers de fecha/hora ────────────────────────────────────────────────────
// Viven aquí y no en la vista porque son la aritmética sobre la que se apoyan las reglas de abajo.
// La vista los importa; no tiene su propia copia.

/** La parte `YYYY-MM-DD` de un ISO naive. */
export const dateOf = (iso: string): string => iso.substring(0, 10);

/** `HH:mm` de un ISO naive, o null si el string no trae hora. */
export const hhmm = (iso?: string | null): string | null =>
    (iso && iso.length >= 16) ? iso.substring(11, 16) : null;

export const addDays = (ymd: string, n: number): string => {
  const d = new Date(ymd + 'T00:00:00Z');
  d.setUTCDate(d.getUTCDate() + n);
  const p = (x: number) => String(x).padStart(2, '0');
  return `${d.getUTCFullYear()}-${p(d.getUTCMonth() + 1)}-${p(d.getUTCDate())}`;
};

export const diffDays = (a: string, b: string): number =>
    Math.round((new Date(b + 'T00:00:00Z').getTime() - new Date(a + 'T00:00:00Z').getTime()) / 86400000);

/**
 * ¿El componente lleva hora? Por el flag del snapshot.
 *
 * El fallback —«la hora es real si no es 00:00»— es para datos anteriores al flag. No se quita
 * hasta que no quede ninguna cotización guardada sin él: sin fallback, esas líneas perderían su
 * hora en silencio.
 */
export const compConHora = (c: PaxCotComponente): boolean => {
  if (typeof c?.sinHorario === 'boolean') return !c.sinHorario;
  const t = hhmm(c?.fechaHoraInicio);
  return !!t && t !== '00:00';
};

// ── La forma de la salida ────────────────────────────────────────────────────

export interface BloqueVista {
  key: string;
  servicio: PaxCotServicio;
  segmento: PaxCotSegmento;
  componentes: PaxCotComponente[];
  horaInicio: string | null;       // derivada del primer componente con hora
  horaFin: string | null;          // derivada del último componente con hora
  // Horario global de la excursión (componente promovido a "servicio completo").
  // Se adjunta al 1er bloque del servicio en el día; representa el span de toda
  // la experiencia, no el del segmento donde el componente está anclado.
  horaServicioInicio: string | null;
  horaServicioFin: string | null;
  esEstadia: boolean;              // alojamiento / periodo multi-día sin horas
  esRepeticion: boolean;           // repetición de la estadía en días siguientes
  noche: number;                   // 1..totalNoches (solo estadías)
  totalNoches: number;
  totalSegmentosServicio: number;
  mostrarTituloServicio: boolean;  // título grande: 1er segmento de servicio multi-segmento en el día
  mostrarAccionInclusiones: boolean; // fila de acción (botón modal): 1er bloque del servicio en el día
}

export interface DiaVista {
  fecha: string;      // YYYY-MM-DD
  numeroDia: number;  // basado en calendario (salta días vacíos)
  bloques: BloqueVista[];
}

/** Lo mínimo que hace falta de una cotización para armar el itinerario. */
export interface CotizacionParaItinerario {
  cotservicios?: PaxCotServicio[] | null;
}

/**
 * Compone los días del itinerario.
 *
 * @param cot La cotización (sólo se lee `cotservicios`).
 * @param serviciosConInclusiones Ids de servicio que tienen alguna línea de inclusiones. Entra
 *   como dato y no se calcula aquí a propósito: las inclusiones son otra vista de la cotización
 *   —con su propio filtrado por fecha— y meterla dentro ataría este módulo a esa forma. Lo único
 *   que se necesita de ella es un sí/no por servicio, para decidir si el bloque enseña el botón.
 */
export function componerItinerario(
    cot: CotizacionParaItinerario | null | undefined,
    serviciosConInclusiones: ReadonlySet<string> = new Set(),
): DiaVista[] {
  if (!cot?.cotservicios?.length) return [];

  const porFecha = new Map<string, BloqueVista[]>();
  const push = (fecha: string, b: BloqueVista) => {
    if (!porFecha.has(fecha)) porFecha.set(fecha, []);
    porFecha.get(fecha)!.push(b);
  };

  for (const servicio of cot.cotservicios) {
    const segs = [...(servicio.cotsegmentos ?? [])].sort((a, b) => (a.dia - b.dia) || (a.orden - b.orden));

    for (const segmento of segs) {
      const comps = (servicio.cotcomponentes ?? []).filter((c) => c.cotsegmento?.id === segmento.id);

      // Hora dinámica del segmento: min inicio / max fin de componentes con hora real.
      // Se excluyen los componentes promovidos a "servicio completo": su hora no
      // pertenece a este segmento sino a toda la excursión (se muestra aparte).
      const conHora = comps.filter((c) => compConHora(c) && !c.horaServicioCompleto);
      const inicios = conHora.map((c) => c.fechaHoraInicio).filter(Boolean) as string[];
      const fines   = conHora.map((c) => c.fechaHoraFin).filter(Boolean) as string[];
      const horaInicio = inicios.length ? hhmm(inicios.sort()[0]) : null;
      const horaFin    = fines.length   ? hhmm(fines.sort()[fines.length - 1]) : null;

      const base = dateOf(segmento.fechaAbsoluta);

      // Estadía: sin horas reales y con componentes que terminan en fecha posterior (hoteles)
      let finPeriodo = base;
      for (const c of comps) {
        if (c.fechaHoraFin && dateOf(c.fechaHoraFin) > finPeriodo) finPeriodo = dateOf(c.fechaHoraFin);
      }
      const esEstadia = !horaInicio && !horaFin && finPeriodo > base;
      const totalNoches = esEstadia ? diffDays(base, finPeriodo) : 1;

      // Estadías: se pintan cada día del periodo [checkin .. checkout)
      const fechas = esEstadia
          ? Array.from({ length: totalNoches }, (_, i) => addDays(base, i))
          : [base];

      fechas.forEach((fecha, rep) => {
        push(fecha, {
          key: `${segmento.id}-${fecha}`,
          servicio, segmento, componentes: comps,
          horaInicio, horaFin,
          horaServicioInicio: null, horaServicioFin: null,
          esEstadia, esRepeticion: rep > 0,
          noche: rep + 1, totalNoches,
          totalSegmentosServicio: segs.length,
          mostrarTituloServicio: false,
          mostrarAccionInclusiones: false,
        });
      });
    }
  }

  const fechasOrdenadas = [...porFecha.keys()].sort();
  if (!fechasOrdenadas.length) return [];
  const fechaBase = fechasOrdenadas[0];

  const dias: DiaVista[] = fechasOrdenadas.map((fecha) => {
    const bloques = porFecha.get(fecha)!;

    // 1) Agrupar los bloques del día por servicio.
    //
    // ⚠️ Las REPETICIONES de una estadía van en su propio grupo, y por eso flotan a donde
    // estarían si el hotel fuera un servicio suelto: al final del día.
    //
    // Sin esto, la noche 2 de un hotel que vive DENTRO de un servicio con actividades —el
    // Skylodge, tres noches— se pintaba en la posición que le daba su `orden`, que era la de la
    // PRIMERA noche. Al cliente le salía «sigues alojado en la cápsula» encabezando un día que
    // empieza con un descenso en tirolesa a las 09:00: el número era correcto el día 1 y lo
    // arrastraba a los siguientes.
    //
    // La primera noche sí conserva su sitio en el relato —llegar y dormir allí es parte de lo que
    // se compró—; las repeticiones son un «sigues aquí», que es nota de cierre y no de apertura.
    const grupos = new Map<string, BloqueVista[]>();
    for (const b of bloques) {
      const clave = b.esRepeticion ? `${b.servicio.id}::repeticion` : b.servicio.id;
      if (!grupos.has(clave)) grupos.set(clave, []);
      grupos.get(clave)!.push(b);
    }

    // Horario global de la excursión: por cada servicio del día, la hora de su
    // componente promovido a "servicio completo" (si existe). Se calcula ANTES de
    // ordenar porque esa hora es la que representa al servicio en la cronología: su
    // segmento ancla no la expone en `horaInicio` (a propósito, para no estirar el
    // segmento donde se apoya).
    const promoPorServicio = new Map<string, { inicio: string; fin: string | null }>();
    for (const b of bloques) {
      if (promoPorServicio.has(b.servicio.id)) continue;
      for (const c of b.componentes) {
        if (!c?.horaServicioCompleto) continue;
        const pi = hhmm(c.fechaHoraInicio);
        if (!pi || pi === '00:00') continue;
        const pf = hhmm(c.fechaHoraFin);
        promoPorServicio.set(b.servicio.id, { inicio: pi, fin: (pf && pf !== '00:00' && pf !== pi) ? pf : null });
        break;
      }
    }

    /**
     * Dónde va un servicio cuando el reloj no lo decide. **Espejo de `posicionDeServicio()` en
     * `util/src/stores/cotizacion/cotizacionEditorStore.ts`** — si cambia una, se cambian las dos,
     * o el editor y la guía vuelven a enseñar días distintos.
     *
     * ⚠️ Antes era `min(segmento.orden)`: un número pensado para ordenar DENTRO de un servicio,
     * usado para comparar ENTRE servicios. Cada plantilla empieza por su segmento 1, así que
     * valía 1 para todas y el desempate lo decidía el orden de inserción — de ahí la sensación de
     * que los servicios sin hora flotaban.
     *
     * Ahora manda el `orden` del servicio si alguien lo puso a mano (0 = automático), y si no la
     * naturaleza de lo que es: llegar y moverse abre la jornada, dormir la cierra.
     */
    const posicionDeServicio = (servicio: PaxCotServicio, bloquesDelGrupo: BloqueVista[]): number => {
      if ((servicio.orden ?? 0) > 0) {
        return servicio.orden as number;
      }

      // ⚠️ `ordenNarrativo` llega SERIALIZADO en cada componente. Escribir la tabla de tipos
      // aquí sería la segunda copia de una regla que ya vive en `ComponenteTipoEnum` — lo que
      // el docblock de `componentesOrdenados` en el store prohíbe explícitamente.
      const naturales = bloquesDelGrupo
          .flatMap(b => b.componentes)
          .map(c => c?.ordenNarrativo ?? 30);

      return naturales.length ? Math.min(...naturales) : 30;
    };

    // 2) Metadatos por grupo: hora absoluta más temprana y orden mínimo del día.
    //    Si el servicio no tiene hora en sus segmentos pero sí una hora promovida
    //    (servicio completo), se usa esa para posicionarlo en la cronología.
    const metaGrupo = (gb: BloqueVista[]) => {
      const horas = gb.map(b => b.horaInicio).filter(Boolean) as string[];
      const promoInicio = promoPorServicio.get(gb[0]?.servicio.id)?.inicio ?? null;
      if (promoInicio) horas.push(promoInicio);
      const horaMin = horas.length ? [...horas].sort()[0] : null; // null = sin hora absoluta
      const ordenMin = posicionDeServicio(gb[0]!.servicio, gb);
      const esEstadia = gb.every(b => b.esEstadia);
      return { horaMin, ordenMin, esEstadia };
    };

    // 3) Ordenar los GRUPOS:
    //    con hora → primero (por su hora más temprana) · sin hora → luego · estadías → al final
    // ⚠️ **Un día ordenado a mano se ordena SÓLO por su `orden`**, igual que en el editor. Si la
    // hora siguiera mandando, el operador colocaría el día y el huésped lo leería en otro orden.
    //
    // Las ESTADÍAS repetidas se quedan al final igualmente: son la nota de cierre, no una parada
    // del relato, y no es eso lo que nadie está colocando cuando arrastra.
    const diaAMano = [...grupos.values()].some(g => (g[0]?.servicio.orden ?? 0) > 0);

    const gruposOrdenados = [...grupos.values()].sort((ga, gb) => {
      const ma = metaGrupo(ga), mb = metaGrupo(gb);
      const tier = (m: typeof ma) => (m.horaMin ? 0 : (m.esEstadia ? 2 : 1));
      const ta = tier(ma), tb = tier(mb);

      if (diaAMano) {
        // La estadía sigue cerrando el día; lo demás va por el orden que puso la persona.
        if (ta === 2 || tb === 2) return (ta === 2 ? 1 : 0) - (tb === 2 ? 1 : 0);
        return ma.ordenMin - mb.ordenMin;
      }

      if (ta !== tb) return ta - tb;
      if (ma.horaMin && mb.horaMin) return ma.horaMin.localeCompare(mb.horaMin);
      return ma.ordenMin - mb.ordenMin; // desempate estable entre grupos sin hora
    });

    // 4) Dentro de cada grupo, los segmentos por su campo `orden`
    const ordenados: BloqueVista[] = [];
    for (const g of gruposOrdenados) {
      g.sort((a, b) => (a.segmento.orden ?? 0) - (b.segmento.orden ?? 0));
      ordenados.push(...g);
    }

    // Título grande en el 1er segmento (por día) de servicios multi-segmento;
    // y adjuntamos el horario global al primer bloque de cada servicio en el día.
    const vistos = new Set<string>();
    const vistosPromo = new Set<string>();
    for (const b of ordenados) {
      b.mostrarTituloServicio = b.totalSegmentosServicio > 1 && !vistos.has(b.servicio.id);
      vistos.add(b.servicio.id);

      const promo = promoPorServicio.get(b.servicio.id);
      if (promo && !vistosPromo.has(b.servicio.id)) {
        b.horaServicioInicio = promo.inicio;
        b.horaServicioFin = promo.fin;
        vistosPromo.add(b.servicio.id);
      }
    }

    return { fecha, numeroDia: diffDays(fechaBase, fecha) + 1, bloques: ordenados };
  });

  // Fila de acción (botón "Incluye / No incluye"): primer bloque de cada servicio por día,
  // solo si ese servicio tiene inclusiones y no es una repetición de estadía.
  for (const dia of dias) {
    const vistosServicio = new Set<string>();
    for (const b of dia.bloques) {
      const sid = b.servicio.id;
      b.mostrarAccionInclusiones = !b.esRepeticion && !vistosServicio.has(sid) && serviciosConInclusiones.has(sid);
      vistosServicio.add(sid);
    }
  }

  return dias;
}
