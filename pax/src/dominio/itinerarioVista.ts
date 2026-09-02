/**
 * Cómo se compone el itinerario que ve el cliente: segmentos → bloques → días.
 *
 * ── Por qué es un módulo y no un `computed` ─────────────────────────────────
 * Vivió dentro de `PaxCotizacionGuiaView.vue` —1.953 líneas— y allí **no lo podía importar
 * nadie**: ni un generador de PDF, ni un test, ni un proceso Node. Un `computed` enterrado en un
 * componente es lógica de negocio con una sola puerta, y la puerta es el navegador.
 *
 * Aquí no hay Vue, ni store, ni `window`: entra la cotización, salen los días.
 *
 * ── Qué NO hace, y es deliberado ────────────────────────────────────────────
 * ⚠️ **No decide nada de pantalla.** Devuelve *hechos de estructura* —«este bloque es el primero
 * de su servicio en el día»— y cada consumidor decide qué pinta con ellos. La versión anterior
 * devolvía `mostrarTituloServicio` y `mostrarAccionInclusiones`, que son decisiones del panel del
 * huésped: el día que `util` lo importara, las habría recibido vacías y el paso siguiente habría
 * sido un `modo: 'editor' | 'guia'` — **el primer `if` por consumidor dentro del único módulo
 * compartido**. Un flag de presentación en un módulo compartido es una bomba de relojería.
 *
 * ── Genérico sobre los tres nodos, y por qué ────────────────────────────────
 * No declara su entrada como la serialización pública de `pax`: declara **los doce campos que
 * lee** y se hace genérico sobre el resto. Así `pax` y `util` lo llaman con sus propios tipos y
 * los recuperan intactos en la salida —`bloque.servicio.tituloSnapshot` sigue tipado en cada
 * app—, sin fusionar dos formas que la API mantiene separadas a propósito.
 *
 * ⚠️ **Los tipos de `pax` y `util` NO se fusionan.** `pax` es subconjunto estricto de `util` en
 * las tres entidades, así que fusionarlos compilaría; y aun así no se hace, porque los campos de
 * diferencia son los que la API decide no mandarle al cliente. El compilador de `pax` es la única
 * comprobación automática de esa frontera.
 *
 * ── Corre fuera del navegador ───────────────────────────────────────────────
 * Sin `vue`, sin `@/stores`, sin DOM. Es la condición para que pueda mudarse a la capa compartida
 * (`docs/NodeEnElStack.md` §9) y para que PHP pueda invocarlo por Node.
 *
 * ⚠️ **Sólo sintaxis borrable.** Producción es Node 22, que ejecuta TypeScript pero rechaza lo que
 * no se puede borrar: un `enum` muere con `ERR_UNSUPPORTED_TYPESCRIPT_SYNTAX` — y Vite lo compila
 * sin quejarse, así que el fallo saldría en el servidor y no aquí.
 *
 * ── El espejo que sigue vivo ────────────────────────────────────────────────
 * ⚠️ `posicionDeServicio()` está también en `util/src/stores/cotizacion/cotizacionEditorStore.ts`
 * y en `src/Operacion/Entity/OperacionServicio.php`. Los tres cambian juntos hasta que `util`
 * importe este módulo y el de PHP se desnormalice. Ver `docs/PlanProcesamientoCompartido.md` §4.
 */

// ── El contrato de entrada: lo que el módulo LEE, y nada más ─────────────────

/** Lo que se lee de un componente. Todo opcional salvo el id: el módulo tolera lo que falte. */
export interface ComponenteMinimo {
  id: string;
  cotsegmento?: { id: string } | null;
  fechaHoraInicio?: string | null;
  fechaHoraFin?: string | null;
  sinHorario?: boolean | null;
  horaServicioCompleto?: boolean | null;
  ordenNarrativo?: number | null;
}

/** Lo que se lee de un segmento. */
export interface SegmentoMinimo {
  id: string;
  dia: number;
  orden: number;
  fechaAbsoluta: string;
}

/** Lo que se lee de un servicio. */
export interface ServicioMinimo {
  id: string;
  orden?: number | null;
  cotsegmentos?: SegmentoMinimo[] | null;
  cotcomponentes?: ComponenteMinimo[] | null;
}

/**
 * Los tipos reales de segmento y componente se **derivan** del servicio que entra, en vez de ser
 * dos parámetros más. Así basta con llamar `componerItinerario(cot)` y cada app recupera sus
 * propios tipos sin escribir ninguno.
 */
type SegmentoDe<S extends ServicioMinimo> = NonNullable<S['cotsegmentos']>[number];
type ComponenteDe<S extends ServicioMinimo> = NonNullable<S['cotcomponentes']>[number];

// ── Helpers de fecha/hora ────────────────────────────────────────────────────
// Viven aquí porque son la aritmética sobre la que se apoyan las reglas de abajo. La vista los
// importa; no tiene su propia copia.

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
 * El respaldo —«la hora es real si no es 00:00»— es para datos anteriores al flag. No se quita
 * hasta que no quede ninguna cotización guardada sin él: sin respaldo, esas líneas perderían su
 * hora en silencio.
 */
export const compConHora = (c: ComponenteMinimo): boolean => {
  if (typeof c?.sinHorario === 'boolean') return !c.sinHorario;
  const t = hhmm(c?.fechaHoraInicio);
  return !!t && t !== '00:00';
};

// ── La forma de la salida ────────────────────────────────────────────────────

export interface BloqueVista<S extends ServicioMinimo> {
  key: string;
  servicio: S;
  segmento: SegmentoDe<S>;
  componentes: ComponenteDe<S>[];
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
  /**
   * ¿Es el primer bloque de su servicio en este día?
   *
   * Es un **hecho de estructura**, no una decisión de pantalla. De él cuelgan cosas distintas en
   * cada consumidor: la guía pinta aquí el título grande del servicio y el botón de inclusiones;
   * otro consumidor hará otra cosa, o ninguna.
   */
  esPrimeroDelServicioEnElDia: boolean;
}

export interface DiaVista<S extends ServicioMinimo> {
  fecha: string;      // YYYY-MM-DD
  numeroDia: number;  // basado en calendario (salta días vacíos)
  bloques: BloqueVista<S>[];
}

/**
 * Compone los días del itinerario.
 *
 * @param cot La cotización. Sólo se lee `cotservicios`.
 */
export function componerItinerario<S extends ServicioMinimo>(
  cot: { cotservicios?: S[] | null } | null | undefined,
): DiaVista<S>[] {
  if (!cot?.cotservicios?.length) return [];

  const porFecha = new Map<string, BloqueVista<S>[]>();
  const push = (fecha: string, b: BloqueVista<S>) => {
    if (!porFecha.has(fecha)) porFecha.set(fecha, []);
    porFecha.get(fecha)!.push(b);
  };

  for (const servicio of cot.cotservicios) {
    const segs = [...(servicio.cotsegmentos ?? [])].sort((a, b) => (a.dia - b.dia) || (a.orden - b.orden)) as SegmentoDe<S>[];

    for (const segmento of segs) {
      const comps = (servicio.cotcomponentes ?? []).filter((c) => c.cotsegmento?.id === segmento.id) as ComponenteDe<S>[];

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
          esPrimeroDelServicioEnElDia: false,
        });
      });
    }
  }

  const fechasOrdenadas = [...porFecha.keys()].sort();
  if (!fechasOrdenadas.length) return [];
  const fechaBase = fechasOrdenadas[0];

  return fechasOrdenadas.map((fecha) => {
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
    const grupos = new Map<string, BloqueVista<S>[]>();
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
     * `util`** y de `posicionDelServicio()` en `OperacionServicio.php` — los tres cambian juntos.
     *
     * ⚠️ Antes era `min(segmento.orden)`: un número pensado para ordenar DENTRO de un servicio,
     * usado para comparar ENTRE servicios. Cada plantilla empieza por su segmento 1, así que
     * valía 1 para todas y el desempate lo decidía el orden de inserción — de ahí la sensación de
     * que los servicios sin hora flotaban.
     *
     * Ahora manda el `orden` del servicio si alguien lo puso a mano (0 = automático), y si no la
     * naturaleza de lo que es: llegar y moverse abre la jornada, dormir la cierra.
     */
    const posicionDeServicio = (servicio: S, bloquesDelGrupo: BloqueVista<S>[]): number => {
      if ((servicio.orden ?? 0) > 0) {
        return servicio.orden as number;
      }

      // ⚠️ `ordenNarrativo` llega SERIALIZADO en cada componente. Escribir la tabla de tipos
      // aquí sería la segunda copia de una regla que ya vive en `ComponenteTipoEnum`.
      const naturales = bloquesDelGrupo
        .flatMap(b => b.componentes)
        .map(c => c?.ordenNarrativo ?? 30);

      return naturales.length ? Math.min(...naturales) : 30;
    };

    // 2) Metadatos por grupo: hora absoluta más temprana y orden mínimo del día.
    //    Si el servicio no tiene hora en sus segmentos pero sí una hora promovida
    //    (servicio completo), se usa esa para posicionarlo en la cronología.
    const metaGrupo = (gb: BloqueVista<S>[]) => {
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
    const ordenados: BloqueVista<S>[] = [];
    for (const g of gruposOrdenados) {
      g.sort((a, b) => (a.segmento.orden ?? 0) - (b.segmento.orden ?? 0));
      ordenados.push(...g);
    }

    // 5) Marcar el primer bloque de cada servicio en el día, y adjuntarle el horario global.
    const vistos = new Set<string>();
    for (const b of ordenados) {
      b.esPrimeroDelServicioEnElDia = !vistos.has(b.servicio.id);
      vistos.add(b.servicio.id);

      const promo = promoPorServicio.get(b.servicio.id);
      if (promo && b.esPrimeroDelServicioEnElDia) {
        b.horaServicioInicio = promo.inicio;
        b.horaServicioFin = promo.fin;
      }
    }

    return { fecha, numeroDia: diffDays(fechaBase, fecha) + 1, bloques: ordenados };
  });
}
