<script setup lang="ts">
/**
 * src/views/cotizacion/PaxCotizacionGuiaView.vue
 * Ruta: /file/:localizador/v/:version — guía visual día a día de una propuesta.
 *
 * Reglas de armado del itinerario (vista):
 *  - La hora de un segmento se deriva de sus componentes (min inicio / max fin con hora real).
 *  - Dentro del día: primero lo que tiene hora (cronológico), luego lo sin hora, al final las estadías.
 *  - Estadías (componentes sin hora que abarcan varios días, ej. hoteles) se repiten al final
 *    de cada día de su periodo [checkin .. checkout), con sus inclusiones solo el primer día.
 *  - Los números de día son calendario: si un día no tiene nada, se salta (Día 1, 2, 4...).
 *  - Tarifas con proveedor visible (prestadorTitulo) → botón "ver más" con modal.
 *  - Resumen financiero: colapsado en el header; expandido divide header y menú de días.
 *
 * Inclusiones (dos vistas):
 *  1. Inline al final de cada día: por servicio con líneas en ese día → "Detalle de <servicio>"
 *     con las 4 secciones (incluye / no incluye / cortesía / opcional) filtradas por fecha.
 *  2. Fila de acción con botón sobre la card (entre el título de paquete y el de la card) →
 *     abre un modal con las inclusiones del servicio COMPLETO (todos los días).
 */
import { ref, onMounted, onBeforeUnmount, watch, nextTick, computed } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { usePaxCotizacionStore } from '@/stores/cotizacion/paxCotizacionStore';
import { useMaestroStore } from '@/stores/maestroStore';
import type { PaxInclusionItem, PaxTarifaFinanciera, PaxClasePasajero, PaxCotServicio, PaxCotSegmento, PaxCotComponente, I18n } from '@/types/paxCotizacionModel';



const props = defineProps<{
  localizador: string;
  version: string | number;
}>();

const store = usePaxCotizacionStore();
const maestroStore = useMaestroStore();
const router = useRouter();
const route = useRoute();

// Modo catálogo: tour con fechas nominales — se muestra solo "Día N"
const esCatalogo = computed(() => route.meta.esCatalogo === true);

const isReady = ref(false);
const diaActivo = ref(1);
let observer: IntersectionObserver | null = null;

// ── Carga ────────────────────────────────────────────────────────────────────
const cargar = async () => {
  isReady.value = false;
  try {
    await maestroStore.cargarConfiguracion();
    if (esCatalogo.value) {
      await store.cargarVersionCatalogo(props.localizador, Number(props.version));
    } else {
      await store.cargarVersion(props.localizador, Number(props.version));
    }
  } catch (error) {
    console.error('Error en carga inicial:', error);
  } finally {
    // 🔑 Primero renderizar (isReady=true), recién entonces existen los [data-dia]
    isReady.value = true;
    await nextTick();
    montarObserver();
  }
};

onMounted(cargar);
watch(() => [props.localizador, props.version], cargar);
onBeforeUnmount(() => observer?.disconnect());

// ── Scroll-spy de días ───────────────────────────────────────────────────────
const montarObserver = () => {
  observer?.disconnect();
  observer = new IntersectionObserver(
      (entries) => {
        for (const e of entries) {
          if (e.isIntersecting) diaActivo.value = Number((e.target as HTMLElement).dataset.dia);
        }
      },
      { rootMargin: '-20% 0px -70% 0px' }
  );
  document.querySelectorAll<HTMLElement>('[data-dia]').forEach(el => observer!.observe(el));
};

// Al marcarse activo un día (scroll manual o click), centrar su chip en el nav
watch(diaActivo, async (n) => {
  await nextTick();
  navDias.value
      ?.querySelector<HTMLElement>(`[data-nav-dia="${n}"]`)
      ?.scrollIntoView({ behavior: 'smooth', block: 'nearest', inline: 'center' });
});

const irADia = (n: number) => {
  document.getElementById(`dia-${n}`)?.scrollIntoView({ behavior: 'smooth', block: 'start' });
};

const volverPortada = () => {
  router.push({
    name: esCatalogo.value ? 'catalogo_publico' : 'file_publica',
    params: { localizador: props.localizador },
  });
};

// ── Idioma (manual pisa al idiomaCliente) ────────────────────────────────────
const cambiarIdioma = (event: Event) => {
  maestroStore.setIdioma((event.target as HTMLSelectElement).value);
  localStorage.setItem('paxIdiomaManual', '1');
};

// ── Moneda ───────────────────────────────────────────────────────────────────
const monedaVista = ref<'PEN' | 'USD'>('USD');
watch(() => store.cotizacion?.monedaGlobal, (m) => { if (m === 'PEN') monedaVista.value = 'PEN'; }, { immediate: true });

const n2 = (v: number) => (Math.round(v * 100) / 100).toLocaleString(maestroStore.idiomaActual, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
const fmtPEN = (v: number) => maestroStore.idiomaActual === 'es'
    ? `S/. ${n2(v)}`
    : new Intl.NumberFormat(maestroStore.idiomaActual, { style: 'currency', currency: 'PEN' }).format(Math.round(v * 100) / 100);
const mv = (soles: number, dolares: number) =>
    monedaVista.value === 'PEN' ? fmtPEN(soles) : `$ ${n2(dolares)}`;

// ── Helpers de fecha/hora ────────────────────────────────────────────────────
const dateOf = (iso: string) => iso.substring(0, 10);

/** Hora 'HH:mm' solo si es una hora real (≠ medianoche) */
/** 'HH:mm' de un ISO naive, o null si el string no trae hora */
const hhmm = (iso?: string | null): string | null =>
    (iso && iso.length >= 16) ? iso.substring(11, 16) : null;

/**
 * ¿El componente lleva hora? Ahora por el flag del snapshot.
 * Fallback legacy (data sin el flag aún): la hora es real si no es 00:00.
 */
const compConHora = (c: PaxCotComponente): boolean => {
  if (typeof c?.sinHorario === 'boolean') return !c.sinHorario;
  const t = hhmm(c?.fechaHoraInicio);
  return !!t && t !== '00:00';
};

const addDays = (ymd: string, n: number) => {
  const d = new Date(ymd + 'T00:00:00Z');
  d.setUTCDate(d.getUTCDate() + n);
  const p = (x: number) => String(x).padStart(2, '0');
  return `${d.getUTCFullYear()}-${p(d.getUTCMonth() + 1)}-${p(d.getUTCDate())}`;
};

const diffDays = (a: string, b: string) =>
    Math.round((new Date(b + 'T00:00:00Z').getTime() - new Date(a + 'T00:00:00Z').getTime()) / 86400000);

/** Día relativo del programa (1-based) a partir de la fecha nominal. */
const diaNumDe = (iso: string): number => {
  const base = store.itinerario[0]?.fecha;
  if (!base) return 1;
  return diffDays(base, iso.substring(0, 10)) + 1;
};

const formatearFecha = (ymd: string) => {
  // Catálogo: fechas nominales — nunca mostrar fecha absoluta
  if (esCatalogo.value) {
    return `${maestroStore.t('cot_dia') || 'Día'} ${diaNumDe(ymd)}`;
  }
  return new Date(ymd.substring(0, 10) + 'T00:00:00Z').toLocaleDateString(maestroStore.idiomaActual, {
    weekday: 'long', day: 'numeric', month: 'long', timeZone: 'UTC',
  });
};

const fechaChip = (iso: string) => {
  // Catálogo: fechas nominales — se referencia por día del programa
  if (esCatalogo.value) {
    return `${maestroStore.t('cot_dia') || 'Día'} ${diaNumDe(iso)}`;
  }
  return new Date(iso.substring(0, 10) + 'T00:00:00Z').toLocaleDateString(maestroStore.idiomaActual, {
    day: '2-digit', month: 'short', timeZone: 'UTC',
  });
};

// ── Itinerario de vista (segmentos → bloques por día) ────────────────────────
interface BloqueVista {
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

interface DiaVista {
  fecha: string;      // YYYY-MM-DD
  numeroDia: number;  // basado en calendario (salta días vacíos)
  bloques: BloqueVista[];
}

const itinerarioVista = computed<DiaVista[]>(() => {
  const cot = store.cotizacion;
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

    // 1) Agrupar los bloques del día por servicio
    const grupos = new Map<string, BloqueVista[]>();
    for (const b of bloques) {
      if (!grupos.has(b.servicio.id)) grupos.set(b.servicio.id, []);
      grupos.get(b.servicio.id)!.push(b);
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

    // 2) Metadatos por grupo: hora absoluta más temprana y orden mínimo del día.
    //    Si el servicio no tiene hora en sus segmentos pero sí una hora promovida
    //    (servicio completo), se usa esa para posicionarlo en la cronología.
    const metaGrupo = (gb: BloqueVista[]) => {
      const horas = gb.map(b => b.horaInicio).filter(Boolean) as string[];
      const promoInicio = promoPorServicio.get(gb[0]?.servicio.id)?.inicio ?? null;
      if (promoInicio) horas.push(promoInicio);
      const horaMin = horas.length ? [...horas].sort()[0] : null; // null = sin hora absoluta
      const ordenMin = Math.min(...gb.map(b => b.segmento.orden ?? 0));
      const esEstadia = gb.every(b => b.esEstadia);
      return { horaMin, ordenMin, esEstadia };
    };

    // 3) Ordenar los GRUPOS:
    //    con hora → primero (por su hora más temprana) · sin hora → luego · estadías → al final
    const gruposOrdenados = [...grupos.values()].sort((ga, gb) => {
      const ma = metaGrupo(ga), mb = metaGrupo(gb);
      const tier = (m: typeof ma) => (m.horaMin ? 0 : (m.esEstadia ? 2 : 1));
      const ta = tier(ma), tb = tier(mb);
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
      const srv = inclusionPorServicio.value.get(sid);
      const tieneLineas = !!srv &&
          (srv.incluidos.length + srv.noIncluidos.length + srv.cortesias.length + srv.opcionales.length) > 0;
      b.mostrarAccionInclusiones = !b.esRepeticion && !vistosServicio.has(sid) && tieneLineas;
      vistosServicio.add(sid);
    }
  }

  return dias;
});

const totalDiasViaje = computed(() =>
    itinerarioVista.value.length ? itinerarioVista.value[itinerarioVista.value.length - 1].numeroDia : 0);

// ── Horarios de componentes ──────────────────────────────────────────────────
const compsConHora = (b: BloqueVista) =>
    b.componentes
        .filter((c) => compConHora(c) && c.fechaHoraInicio)
        .sort((a, b2) => (a.fechaHoraInicio ?? '').localeCompare(b2.fechaHoraInicio ?? ''));


/**
 * ¿Manda el SEGMENTO sobre el componente? **Espejo de `ComponenteTipoEnum::mandaElSegmento()`** —
 * si cambia la regla, se tocan LOS TRES: aquí, `util` y el enum de PHP.
 *
 * ⚠️ Aquí manda por un motivo DISTINTO al del despacho, y conviene no confundirlos. En La Biblia
 * es porque el nombre operativo del componente ya no dice la dirección; aquí es porque su TÍTULO
 * PÚBLICO tampoco puede decirla y encima lo intenta: al fusionar ida y vuelta —«Vuelo Cusco ↔
 * Arequipa (ida o vuelta)»— el título quedó congelado en un sentido, «Vuelo desde la ciudad de
 * Cusco a la ciudad de Arequipa». En el tramo de vuelta el huésped leería la dirección al revés.
 *
 * El título del segmento sí la dice —«Vuelo Cusco – Arequipa», «Viaje en tren desde
 * Ollantaytambo»— porque hay uno por sentido.
 *
 * En los demás tipos el componente nombra lo comprado y su título es la prosa buena: se queda.
 */
const mandaElSegmento = (tipo?: string | null): boolean =>
    tipo === 'transporte' || tipo === 'tren' || tipo === 'vuelo';

/** El texto de la línea de horarios: el que de verdad identifica ese momento del día. */
const tituloDeComponente = (c: PaxCotComponente, segmento: { tituloSnapshot?: unknown }): string => {
  const delSegmento = store.traducir(segmento?.tituloSnapshot as never) || '';

  if (mandaElSegmento(c.tipo) && delSegmento !== '') {
    return delSegmento;
  }

  return store.traducir(c.tituloSnapshot) || delSegmento;
};

const horaRango = (c: PaxCotComponente) => {
  if (!compConHora(c)) return null;
  const hi = hhmm(c.fechaHoraInicio);
  const hf = hhmm(c.fechaHoraFin);
  return hf && hf !== hi ? `${hi} – ${hf}` : hi;
};

// ── Imágenes de segmento (galería) ───────────────────────────────────────────
const imagenesDe = (segmento: PaxCotSegmento): { imageUrl: string }[] =>
    (segmento.imagenesSnapshot ?? []).filter((i) => i.imageUrl);

/**
 * Galería definitiva de cada bloque, ya deduplicada contra todo lo que salió antes.
 *
 * ⚠️ **El segmento pone el relato; el prestador pone la cara.**
 *
 * Los segmentos de estancia son GENÉRICOS a propósito —«Piscina y playa», «Desayuno buffet»— y se
 * redactan una sola vez. Las fotos las aporta el **servicio del prestador** que resuelve la tarifa
 * elegida, así que el mismo segmento enseña la piscina del resort que de verdad se contrató.
 *
 * La aritmética es lo que hace que valga la pena: con las fotos dentro del segmento harían falta
 * N resorts × M actividades segmentos. Así hacen falta M, y añadir un resort no cuesta ninguno.
 *
 * Reglas, en este orden:
 *
 *  1. **Si el segmento trae sus propias fotos, mandan.** Los segmentos redactados para un sitio
 *     concreto —Paracas, Islas Ballestas— ya tienen galería y no hay nada que promover.
 *  2. **Si no, se concatenan las de TODOS sus componentes**: primero las del servicio del
 *     prestador (la habitación, la actividad) y después las de la empresa.
 *  3. **Ninguna foto se repite en toda la guía.** La galería del hotel cae en el primer segmento
 *     que la trae —el check-in— y no vuelve a salir en los días siguientes. Pero si la piscina
 *     tiene fotos PROPIAS, ésas sí aparecen: la deduplicación es **por foto, no por posición**,
 *     así que lo repetido se calla y lo distinto siempre se enseña.
 *
 * Se calcula de una vez sobre `itinerarioVista` —que ya viene ordenado por día— porque «la primera
 * vez que aparece» sólo tiene sentido recorriendo el itinerario entero en orden. Hacerlo dentro
 * del `v-for` daría un resultado distinto según qué día estuviera abierto.
 */
const galeriaPorBloque = computed<Map<string, { imageUrl: string }[]>>(() => {
  const salida = new Map<string, { imageUrl: string }[]>();
  const vistas = new Set<string>();

  for (const dia of itinerarioVista.value) {
    for (const bloque of dia.bloques) {
      const propias = imagenesDe(bloque.segmento);

      const delPrestador = propias.length
        ? []
        : bloque.componentes.flatMap((c) => [
            ...(c.prestadorServicioImagenes ?? []),
            ...(c.prestadorImagenes ?? []),
          ]);

      const nuevas = [...propias, ...delPrestador].filter(
        (i) => i.imageUrl && !vistas.has(i.imageUrl),
      );

      nuevas.forEach((i) => vistas.add(i.imageUrl));
      salida.set(bloque.key, nuevas);
    }
  }

  return salida;
});

const galeriaDe = (bloque: BloqueVista): { imageUrl: string }[] =>
    galeriaPorBloque.value.get(bloque.key) ?? [];

const desplazarGaleria = (ev: Event, dir: number) => {
  const wrap = (ev.currentTarget as HTMLElement).closest('[data-galeria]');
  const track = wrap?.querySelector('.galeria-track') as HTMLElement | null;
  track?.scrollBy({ left: dir * track.clientWidth, behavior: 'smooth' });
};

// ── Day-nav: flechas de desplazamiento ───────────────────────────────────────
const navDias = ref<HTMLElement | null>(null);
const desplazarNav = (dir: number) => navDias.value?.scrollBy({ left: dir * 160, behavior: 'smooth' });

// ── Expandir / colapsar (descripciones, inclusiones y finanzas) ──────────────
const descExpandida = ref(new Set<string>());
const incExpandida = ref(new Set<string>());
const finanzasAbiertas = ref(false);
const toggle = (set: Set<string>, key: string) => {
    if (set.has(key)) set.delete(key);
    else set.add(key);
};

// Modo de lectura del itinerario: 'detalle' muestra las descripciones completas;
// 'resumen' colapsa las narrativas y deja títulos, subtítulos y horas, en tipografía
// más compacta. Es global a toda la vista.
const modoResumen = ref(false);

/** ¿La descripción es lo bastante larga como para truncarla? */
const descEsLarga = (segmento: PaxCotSegmento) => (store.traducir(segmento.contenidoSnapshot) || '').length > 450;

// ── i18n helper (clave estable para lookups) ─────────────────────────────────
const contenidoEs = (i18n: I18n | undefined): string =>
    i18n?.find((c) => c.language === 'es')?.content ?? i18n?.[0]?.content ?? '';

// ── Prestadores visibles (modal "ver más") ───────────────────────────────────
interface PrestadorInfo {
  titulo: I18n;
  url: string | null;
  imagenes: { imageUrl: string }[];
  servicioTitulo: I18n;
  servicioImagenes: { imageUrl: string }[];
}

/**
 * Prestador por id de componente, leído del árbol VIVO.
 *
 * El backend ya decidió dos cosas antes de que esto llegue —si se puede nombrar y cuál es
 * su presentación actual según el catálogo maestro—, así que aquí no se comprueba nada
 * más: si hay título, se muestra. Ver `CotizacionCotcomponentePrestadorPublicNormalizer`
 * y `PrestadorVivoResolver`.
 */
const proveedorPorComponente = computed(() => {
  const m = new Map<string, PrestadorInfo>();
  for (const srv of store.cotizacion?.cotservicios ?? []) {
    for (const comp of srv.cotcomponentes ?? []) {
      if (!comp.prestadorTitulo?.length) continue;

      m.set(comp.id, {
        titulo: comp.prestadorTitulo,
        url: comp.prestadorUrl ?? null,
        imagenes: (comp.prestadorImagenes ?? []).filter((i) => i.imageUrl),
        servicioTitulo: comp.prestadorServicioTitulo ?? [],
        servicioImagenes: (comp.prestadorServicioImagenes ?? []).filter((i) => i.imageUrl),
      });
    }
  }
  return m;
});

const proveedorDeComponente = (componenteId?: string): PrestadorInfo | null =>
    componenteId ? proveedorPorComponente.value.get(componenteId) ?? null : null;

const modalProveedor = ref<PrestadorInfo | null>(null);
const abrirProveedor = (p: PrestadorInfo) => { modalProveedor.value = p; };

/**
 * Prestador de referencia de una línea NO INCLUIDA: el hotel o el vuelo que el
 * pasajero contrató por su cuenta.
 *
 * Se muestra en tono afirmativo y no como carencia — «Alojamiento · por su cuenta
 * — Casa Andina» en vez de un simple «no incluye alojamiento». Es la diferencia
 * entre una lista de lo que no compró y un itinerario completo donde algunas cosas
 * las gestiona él.
 *
 * El backend ya garantiza que sólo viaja en no incluidos
 * (CotizacionCotcomponentePrestadorPublicNormalizer y construirInclusiones); la
 * comprobación de `modo` aquí es defensa en profundidad, no la regla.
 */
const prestadorDeLinea = (l: PaxInclusionItem): PrestadorInfo | null => {
  if (l.modo !== 'no_incluido') return null;

  // La ficha ya no viaja en la línea: se busca por el id del componente, que es donde el
  // backend la inyecta resuelta contra el catálogo. Si la empresa ya no existe queda el
  // nombre histórico y se pinta sin tarjeta.
  const delComponente = proveedorDeComponente(l.componenteId);
  if (delComponente) return delComponente;

  if (!l.prestadorNombre) return null;

  return {
    titulo: [{ content: l.prestadorNombre, language: 'es' }],
    url: null,
    imagenes: [],
    servicioTitulo: [],
    servicioImagenes: [],
  };
};

const galeriaProveedor = (p: PrestadorInfo) => [...p.servicioImagenes, ...p.imagenes];

// ── Badges de clasificación (modalidad · categoría · procedencia · edad) ─────
const MODALIDAD_UI: Record<string, { icon: string; i18nKey: string; fallback: string }> = {
  privado:    { icon: '🔒', i18nKey: 'cot_privado',    fallback: 'Privado' },
  compartido: { icon: '👥', i18nKey: 'cot_compartido', fallback: 'Compartido' },
};
const CATEGORIA_UI: Record<string, { icon: string; i18nKey: string; fallback: string }> = {
  superior: { icon: '✨', i18nKey: 'cot_superior', fallback: 'Superior' },
  estandar: { icon: '🏷️', i18nKey: 'cot_estandar', fallback: 'Estándar' },
  lujo:     { icon: '👑', i18nKey: 'cot_lujo',     fallback: 'Lujo' },
};
const PROCEDENCIA_UI: Record<string, { icon: string; i18nKey: string; fallback: string }> = {
  nacional:   { icon: '🇵🇪', i18nKey: 'cot_nacional',   fallback: 'Nacional' },
  extranjero: { icon: '🌎', i18nKey: 'cot_extranjero', fallback: 'Extranjero' },
  can:        { icon: '🤝', i18nKey: 'cot_can',        fallback: 'CAN' },
};

/** Etiqueta del rango de edad de una tarifa. Sin restricción → '' (no se muestra). */
const rangoEdadBadge = (edadMin?: number | null, edadMax?: number | null): string => {
  const min = edadMin ?? 0;
  const max = edadMax ?? 120;
  const anios = maestroStore.t('cot_anios') || 'años';
  if (min <= 0 && max >= 120) return '';
  if (min > 0 && max < 120) return `${min} - ${max} ${anios}`;
  if (min > 0) return `${maestroStore.t('cot_desde') || 'A partir de'} ${min} ${anios}`;
  return `${maestroStore.t('cot_hasta') || 'Hasta'} ${max} ${anios}`;
};

interface ClasifBadgeInput {
  modalidad?: string | null;
  categoria?: string | null;
  procedencia?: string | null;
  edadMin?: number | null;
  edadMax?: number | null;
}
const modCatBadges = (o: ClasifBadgeInput) => {
  const b: { key: string; icon: string; label: string; cls: string }[] = [];
  if (o.modalidad && MODALIDAD_UI[o.modalidad]) {
    const m = MODALIDAD_UI[o.modalidad];
    b.push({ key: 'mod', icon: m.icon, label: maestroStore.t(m.i18nKey) || m.fallback, cls: 'bg-sky-50 text-sky-700 border-sky-200' });
  }
  if (o.categoria) {
    const c = CATEGORIA_UI[o.categoria];
    b.push({
      key: 'cat',
      icon: c?.icon ?? '✨',
      label: c ? (maestroStore.t(c.i18nKey) || c.fallback) : o.categoria,
      cls: 'bg-purple-50 text-purple-700 border-purple-200',
    });
  }
  if (o.procedencia) {
    const p = PROCEDENCIA_UI[o.procedencia];
    b.push({
      key: 'proc',
      icon: p?.icon ?? '🌐',
      label: p ? (maestroStore.t(p.i18nKey) || p.fallback) : o.procedencia,
      cls: 'bg-teal-50 text-teal-700 border-teal-200',
    });
  }
  const edad = rangoEdadBadge(o.edadMin, o.edadMax);
  if (edad) b.push({ key: 'edad', icon: '🎂', label: edad, cls: 'bg-orange-50 text-orange-700 border-orange-200' });
  return b;
};

// ── Inclusiones (versión cliente: sin montos) ────────────────────────────────
const inclusionPorServicio = computed(() => {
  const m = new Map<string, (typeof store.inclusiones)[number]>();
  for (const srv of store.inclusiones) m.set(srv.servicioId, srv);
  return m;
});

const seccionesInclusion = (srv: { incluidos: PaxInclusionItem[]; noIncluidos: PaxInclusionItem[]; cortesias: PaxInclusionItem[]; opcionales: PaxInclusionItem[] }) => ([
  { key: 'incluidos',   titulo: maestroStore.t('cot_incluye')    || 'Incluye',     icono: 'fa-check-circle text-emerald-500', lineas: srv.incluidos },
  { key: 'noIncluidos', titulo: maestroStore.t('cot_no_incluye') || 'No incluye',  icono: 'fa-times-circle text-red-400',     lineas: srv.noIncluidos },
  { key: 'cortesias',   titulo: maestroStore.t('cot_cortesia')   || 'Cortesía',    icono: 'fa-gift text-sky-500',             lineas: srv.cortesias },
  { key: 'opcionales',  titulo: maestroStore.t('cot_opcional')   || 'Opcional',    icono: 'fa-circle-question text-amber-500', lineas: srv.opcionales },
].filter(s => s.lineas.length > 0));

/**
 * Inclusiones agrupadas por día para el panel único al final de cada día.
 * Por cada servicio presente en el día (en orden de aparición) se toman sus líneas
 * cuya `fecha` cae en ese día. Todo tiene fecha, así que el reparto es exacto.
 * `largo` decide si el panel arranca semicolapsado con "mostrar más".
 */
type InclusionServicioDia = { servicioId: string; nombre: I18n; secciones: ReturnType<typeof seccionesInclusion> };
const inclusionesPorDia = computed(() => {
  const m = new Map<string, { servicios: InclusionServicioDia[]; largo: boolean }>();

  for (const dia of itinerarioVista.value) {
    const servicios: InclusionServicioDia[] = [];
    const vistos = new Set<string>();

    for (const b of dia.bloques) {
      const sid = b.servicio.id;
      if (vistos.has(sid)) continue;
      vistos.add(sid);

      const srv = inclusionPorServicio.value.get(sid);
      if (!srv) continue;

      const filtrar = (lineas: PaxInclusionItem[]) =>
          (lineas ?? []).filter((l: PaxInclusionItem) => dateOf(l.fecha) === dia.fecha);

      const secciones = seccionesInclusion({
        incluidos: filtrar(srv.incluidos),
        noIncluidos: filtrar(srv.noIncluidos),
        cortesias: filtrar(srv.cortesias),
        opcionales: filtrar(srv.opcionales),
      });
      if (!secciones.length) continue;

      servicios.push({ servicioId: sid, nombre: b.servicio.tituloSnapshot, secciones });
    }

    // Total de líneas del día → decide si el panel arranca semicolapsado
    const totalLineas = servicios.reduce(
        (n, s) => n + s.secciones.reduce((k, sec) => k + sec.lineas.length, 0), 0);
    m.set(dia.fecha, { servicios, largo: totalLineas > 3 });
  }
  return m;
});

/**
 * En qué línea de cada día se nombra a un proveedor, callando las repeticiones SEGUIDAS.
 *
 * ⚠️ **El proveedor se nombra una vez por estancia, no una vez por servicio ni por día.**
 *
 * Antes bastaba con marcar el prestador visible en un componente: como la estadía entera era
 * UN cotservicio, la pastilla salía una vez. Al armar cada día del resort como su propio
 * cotservicio —que es lo que permite intercalar una excursión entre el desayuno y la cena—
 * «una vez por servicio» pasó a ser **una vez por día**: el mismo hotel en el desayuno de los
 * siete días.
 *
 * ⚠️ La regla NO es «una vez en toda la guía», y la diferencia importa: con un viaje que
 * empieza y termina en el mismo hotel de Lima, la global deja la vuelta **sin nombre**. Se
 * callan sólo los días **consecutivos**, así que volver a un hotel lo vuelve a nombrar.
 *
 * Medido en la propuesta de Nune & Todd: nueve menciones para cinco proveedores, con Terra
 * Andina repetida tres veces seguidas.
 *
 * No sustituye a `prestadorVisible`, que decide si el proveedor se puede nombrar siquiera.
 * Esto sólo evita repetir lo que se acaba de decir.
 */
const lineaQueNombraProveedor = computed<Map<string, string>>(() => {
  const m = new Map<string, string>();
  let previos = new Set<string>();

  for (const dia of itinerarioVista.value) {
    const panel = inclusionesPorDia.value.get(dia.fecha);
    if (!panel) continue;

    const deHoy = new Set<string>();

    for (const srv of panel.servicios) {
      for (const sec of srv.secciones) {
        for (const l of sec.lineas) {
          const prov = proveedorDeComponente(l.componenteId);
          if (!prov || !l.componenteId) continue;

          const clave = store.traducir(prov.titulo);
          if (!clave) continue;

          // Se nombra si ayer no se nombró, y sólo en la primera línea del día.
          if (!previos.has(clave) && !deHoy.has(clave)) {
            m.set(`${dia.fecha}|${clave}`, l.componenteId);
          }

          deHoy.add(clave);
        }
      }
    }

    // Un día sin ninguna mención no rompe la racha: la estancia sigue siendo la misma.
    if (deHoy.size) previos = deHoy;
  }

  return m;
});

// ── Modal de inclusiones del servicio COMPLETO (todos los días) ──────────────
interface InclusionModal {
  servicioId: string;
  nombre: I18n;
  secciones: ReturnType<typeof seccionesInclusion>;
}
const modalInclusiones = ref<InclusionModal | null>(null);
const abrirInclusiones = (servicioId: string, nombre: I18n) => {
  const srv = inclusionPorServicio.value.get(servicioId);
  if (!srv) return;
  modalInclusiones.value = { servicioId, nombre, secciones: seccionesInclusion(srv) };
};

/**
 * Chips de tarifa de una línea (título + badges + proveedor si es visible).
 *
 * - Cotización: un chip por tarifa, agrupando idénticas con multiplicador ("Peruano ×2").
 * - Catálogo: un único chip solo con los atributos unánimes entre todas las
 *   tarifas (si todas son privadas → PRIVADO; si difieren, el atributo se
 *   omite) y nunca con multiplicador.
 */
interface ChipLinea { titulo: string; badges: ReturnType<typeof modCatBadges>; proveedor: PrestadorInfo | null; count: number }
const chipsDeLinea = (l: PaxInclusionItem): ChipLinea[] => {
  // El proveedor se lee del componente VIVO, no del snapshot: el backend lo resuelve
  // contra el catálogo maestro al servir, así que renombrar un hotel se ve al instante
  // sin re-guardar la propuesta. El único puente es `componenteId`; la clave natural que
  // había antes colisionaba en silencio y pintaba el proveedor equivocado.
  const proveedorCrudo = proveedorDeComponente(l.componenteId);

  // Sólo en su primera aparición: ver `primeraLineaPorProveedor`.
  const proveedorLinea =
      proveedorCrudo &&
      lineaQueNombraProveedor.value.get(`${dateOf(l.fecha)}|${store.traducir(proveedorCrudo.titulo)}`) === l.componenteId
          ? proveedorCrudo
          : null;

  // Datos crudos por tarifa (o la propia línea si no trae tarifas)
  // Si la línea no trae tarifas, la propia línea hace de fuente: ambas
  // comparten los campos de clasificación que se pintan en el chip.
  const origenes: (PaxTarifaFinanciera | PaxInclusionItem)[] = l.tarifas.length ? l.tarifas : [l];
  const fuentes = origenes
      .map((t) => ({
        titulo: store.traducir(t.tarifaTitulo),
        modalidad: (t.modalidad ?? null) as string | null,
        categoria: (t.categoria ?? null) as string | null,
        procedencia: (t.procedencia ?? null) as string | null,
        edadMin: (t.edadMin ?? null) as number | null,
        edadMax: (t.edadMax ?? null) as number | null,
        proveedor: proveedorLinea,
      }));

  if (esCatalogo.value) {
    const unanime = <T,>(vals: (T | null)[]): T | null =>
        vals.length > 0 && vals.every(v => v !== null && v === vals[0]) ? vals[0] : null;

    const titulo = unanime(fuentes.map(f => f.titulo || null)) ?? '';
    const badges = modCatBadges({
      modalidad: unanime(fuentes.map(f => f.modalidad)),
      categoria: unanime(fuentes.map(f => f.categoria)),
      procedencia: unanime(fuentes.map(f => f.procedencia)),
      edadMin: unanime(fuentes.map(f => f.edadMin)),
      edadMax: unanime(fuentes.map(f => f.edadMax)),
    });
    const proveedor = unanime(fuentes.map(f => f.proveedor));
    return (titulo || badges.length || proveedor) ? [{ titulo, badges, proveedor, count: 1 }] : [];
  }

  const grupos = new Map<string, ChipLinea>();
  for (const f of fuentes) {
    const badges = modCatBadges(f);
    if (!f.titulo && !badges.length && !f.proveedor) continue;
    const key = `${f.titulo}|${badges.map(b => b.key).join(',')}|${f.proveedor ? contenidoEs(f.proveedor.titulo) : ''}`;
    const previo = grupos.get(key);
    if (previo) previo.count++;
    else grupos.set(key, { titulo: f.titulo, badges, proveedor: f.proveedor, count: 1 });
  }
  return [...grupos.values()];
};

// ── Perfiles de pasajero (solo venta) ────────────────────────────────────────
const rangoEdadLabel = (clase: PaxClasePasajero) => {
  if (clase.edadMin <= 0 && clase.edadMax >= 120) return maestroStore.t('cot_sin_edad') || 'Sin restricción de edad';
  if (clase.edadMin > 0 && clase.edadMax < 120) return `${clase.edadMin} - ${clase.edadMax} ${maestroStore.t('cot_anios') || 'años'}`;
  if (clase.edadMin > 0) return `${maestroStore.t('cot_desde') || 'A partir de'} ${clase.edadMin} ${maestroStore.t('cot_anios') || 'años'}`;
  return `${maestroStore.t('cot_hasta') || 'Hasta'} ${clase.edadMax} ${maestroStore.t('cot_anios') || 'años'}`;
};

const clasesPasajeros = computed(() => store.cotizacion?.clasificacionFinancieraCliente?.clasesPasajeros ?? []);
const totalViaje = computed(() => {
  const cfc = store.cotizacion?.clasificacionFinancieraCliente;
  return cfc?.resumenGeneral
      ? { soles: cfc.resumenGeneral.incluido.ventaSoles, dolares: cfc.resumenGeneral.incluido.ventaDolares }
      : null;
});

/** Modo catálogo unitario: el precio funciona como menú por perfil ("peruano
 *  tal precio, extranjero tal precio"), no como cotización de un grupo. Oculta
 *  toda referencia a cantidad de pasajeros: el "2X" del perfil, el "× N pax ·
 *  total" y el "precio total del viaje". El precio unitario sí se sigue viendo. */
const ocultarTotales = computed(() => store.cotizacion?.totalesOcultos === true);

/** Etiqueta "N día(s)" con singular/plural correcto ("1 día", no "1 días"). */
const diasLabel = computed(() => {
  const n = totalDiasViaje.value;
  const palabra = n === 1 ? (maestroStore.t('cot_dia') || 'día') : (maestroStore.t('cot_dias') || 'días');
  return `${n} ${palabra}`;
});
/** Etiqueta "N pasajero(s)" con singular/plural correcto. */
const paxLabel = computed(() => {
  const n = store.cotizacion?.numPax ?? 0;
  const palabra = n === 1 ? (maestroStore.t('cot_pasajero') || 'pasajero') : (maestroStore.t('cot_pasajeros') || 'pasajeros');
  return `${n} ${palabra}`;
});

/** Rango con más pasajeros: su venta por pax es el precio que se destaca en el
 *  encabezado (más representativo que el total, que se ve grande). */
const claseDominante = computed(() =>
  clasesPasajeros.value.length
    ? [...clasesPasajeros.value].sort((a, b) => b.cantidad - a.cantidad)[0]
    : null
);

// ── Opciones alternativas (upgrades/downgrades con delta de venta) ───────────
// Agrupadas por escenario en el store; la etiqueta se compone aquí con el idioma
// actual: "Alternativa N" (grupo con estándar) u "Opción N" (sin estándar).
const gruposUpgrade = computed(() => store.gruposUpgrade);
const tipoCambio = computed(() => store.cotizacion?.clasificacionFinancieraCliente?.tipoCambio ?? 0);

const labelGrupoUpgrade = (g: { esOpcion: boolean; indice: number }): string =>
    `${g.esOpcion ? (maestroStore.t('cot_opcion') || 'Opción') : (maestroStore.t('cot_alternativa') || 'Alternativa')} ${g.indice}`;

/** Los deltas vienen en USD → convertir a la moneda en vista (valor absoluto formateado) */
const mvDelta = (deltaUsd: number) => {
  const abs = Math.abs(deltaUsd);
  return monedaVista.value === 'PEN' && tipoCambio.value
      ? `S/ ${n2(abs * tipoCambio.value)}`
      : `$ ${n2(abs)}`;
};

/**
 * ¿Se pinta la tarjeta de precio? Hay tarjeta si hay precio o si hay alternativas
 * (éstas son descriptivas y se muestran aunque el precio esté oculto). El hero la
 * consulta para reservar el aire del solape: sin tarjeta, no hay hueco que dejar.
 */
const hayPanelPrecio = computed(() => !!(store.precioVisible && totalViaje.value) || gruposUpgrade.value.length > 0);

const adelantoVista = computed(() => {
  const cot = store.cotizacion;
  if (!cot) return '';
  const a = Number(cot.adelanto);
  const tc = tipoCambio.value || 1;
  const [s, d] = cot.monedaGlobal === 'PEN' ? [a, a / tc] : [a * tc, a];
  return mv(s, d);
});
</script>

<template>
  <div class="min-h-screen bg-[#F8FAFC] font-sans selection:bg-[#376875]/20 selection:text-[#376875]">

    <!-- ═══ CARGANDO ═══ -->
    <div v-if="!isReady || store.loading" class="flex flex-col items-center justify-center py-20 min-h-[70vh]">
      <div class="relative w-16 h-16 mb-6">
        <div class="absolute inset-0 rounded-full border-4 border-slate-100"></div>
        <div class="absolute inset-0 rounded-full border-4 border-[#E07845] border-t-transparent animate-spin"></div>
      </div>
      <p class="text-[#376875]/60 font-black animate-pulse uppercase tracking-[0.2em] text-xs">
        {{ maestroStore.t('cot_cargando_guia') || 'Preparando tu itinerario...' }}
      </p>
    </div>

    <!-- ═══ NO ENCONTRADA ═══ -->
    <div v-else-if="!store.cotizacion" class="max-w-md mx-auto text-center py-16 px-6 bg-white rounded-[2.5rem] shadow-xl shadow-slate-200/50 mt-10 border border-slate-50">
      <div class="w-20 h-20 bg-red-50 rounded-full flex items-center justify-center mx-auto mb-6">
        <i class="fas fa-search text-red-400 text-2xl"></i>
      </div>
      <h3 class="text-gray-900 font-black text-lg mb-2">
        {{ maestroStore.t('cot_no_encontrada') || 'Propuesta no encontrada' }}
      </h3>
      <p class="text-slate-500 text-sm mb-6">{{ store.error }}</p>
      <button @click="volverPortada" class="bg-[#376875] hover:bg-[#2b525d] text-white font-black text-xs uppercase tracking-widest px-6 py-3 rounded-xl transition-colors">
        <i class="fas fa-arrow-left mr-2"></i> {{ maestroStore.t('cot_volver') || 'Volver' }}
      </button>
    </div>

    <!-- ═══ GUÍA ═══ -->
    <template v-else>

      <!-- Header compacto -->
      <header class="bg-[#376875] text-white relative overflow-hidden">
        <div class="absolute inset-0 opacity-10" style="background-image: radial-gradient(#ffffff 1px, transparent 1px); background-size: 24px 24px;"></div>
        <div class="max-w-3xl mx-auto px-4 py-5 md:py-8 relative z-10">
          <div class="flex items-center justify-between gap-3 mb-4">
            <button @click="volverPortada" class="flex items-center gap-2 text-white/80 hover:text-white text-xs font-black uppercase tracking-widest transition-colors">
              <i class="fas fa-arrow-left"></i>
              <span class="truncate max-w-35 sm:max-w-none">
                {{ esCatalogo ? (maestroStore.t('cot_volver_catalogo') || 'Volver al catálogo') : store.file?.nombreGrupo }}
              </span>
            </button>

            <div class="flex items-center gap-2 shrink-0">
              <span v-if="!esCatalogo" class="px-2.5 py-1 rounded-lg bg-[#E07845] text-white text-[10px] font-black uppercase tracking-widest shadow-sm">
                V{{ store.cotizacion.version }}
              </span>
              <div class="relative">
                <select
                    :value="maestroStore.idiomaActual"
                    @change="cambiarIdioma"
                    class="appearance-none bg-white/10 border border-white/20 font-black text-[10px] uppercase tracking-widest rounded-xl pl-3 pr-7 py-1.5 focus:outline-none cursor-pointer text-white hover:bg-white/20 transition-colors"
                >
                  <option v-for="lang in maestroStore.idiomas" :key="lang.id" :value="lang.id" class="text-gray-800">
                    {{ lang.bandera }} {{ lang.id.toUpperCase() }}
                  </option>
                </select>
                <i class="fas fa-chevron-down text-[8px] absolute right-2.5 top-1/2 -translate-y-1/2 pointer-events-none text-white/70"></i>
              </div>
            </div>
          </div>

          <div class="flex items-end justify-between gap-4">
            <div class="min-w-0">
              <h1 class="text-2xl md:text-4xl font-black tracking-tight leading-tight">
                {{ store.traducir(store.cotizacion?.titulo) || maestroStore.t('cot_tu_itinerario') || 'Tu itinerario' }}
              </h1>
              <p class="text-white/70 text-xs font-bold mt-1 uppercase tracking-widest">
                {{ diasLabel }}
                <template v-if="!ocultarTotales">· {{ store.cotizacion.numPax }} {{ maestroStore.t('cot_pax') || 'pax' }}</template>
              </p>
            </div>

            <!-- SELECTOR DE MONEDA (efecto cristal). Se queda en el hero y no dentro de
                 la tarjeta de precio: las filas de esa tarjeta son <button> y no pueden
                 anidar otro botón. -->
            <div v-if="store.precioVisible" class="flex items-center bg-white/15 backdrop-blur-md border border-white/30 rounded-xl p-0.5 gap-0.5 shrink-0 shadow-[0_4px_12px_rgb(0,0,0,0.05)]">
              <button
                  @click="monedaVista = 'PEN'"
                  :class="monedaVista === 'PEN' ? 'bg-white text-[#376875] shadow-sm font-extrabold' : 'text-white/80 hover:text-white font-bold'"
                  class="px-3.5 py-1.5 rounded-[10px] text-[10px] tracking-widest transition-all"
              >S/</button>
              <button
                  @click="monedaVista = 'USD'"
                  :class="monedaVista === 'USD' ? 'bg-white text-[#376875] shadow-sm font-extrabold' : 'text-white/80 hover:text-white font-bold'"
                  class="px-3.5 py-1.5 rounded-[10px] text-[10px] tracking-widest transition-all"
              >$</button>
            </div>
          </div>

          <!-- Aire para que la tarjeta de precio (que va después del header, con margen
               negativo) solape el borde inferior del hero sin pegarse a la línea de
               "N días · N pax". Hueco real = este alto + padding inferior del hero (20/32px)
               menos el margen negativo de la tarjeta (36/44px) → ~16px móvil, ~28px desktop. -->
          <div v-if="hayPanelPrecio" class="h-8 md:h-10"></div>
        </div>
      </header>

      <!-- ══ TARJETA DE PRECIO ══════════════════════════════════════════════════
           Un solo control, dos estados: colapsada muestra el agregado (precio por
           pasajero + total del viaje); expandida, el desglose por perfil de pasajero
           y las alternativas. Cuelga del hero con margen negativo.

           Terminología: aquí se habla de PERFILES/PRECIOS, nunca de "detalle" ni
           "resumen" — esas dos palabras son el modo de lectura del itinerario (abajo)
           y usarlas en los dos sitios hacía que parecieran el mismo interruptor. ══ -->
      <section v-if="hayPanelPrecio" class="relative z-20 max-w-3xl mx-auto px-4 -mt-9 md:-mt-11">
        <div class="bg-white rounded-3xl border border-slate-100 shadow-[0_12px_40px_rgb(55,104,117,0.15)] overflow-hidden">

          <!-- FILA RESUMEN (también actúa de disparador) -->
          <button
              @click="finanzasAbiertas = !finanzasAbiertas"
              class="w-full text-left px-5 pt-4 pb-3.5 focus:outline-none hover:bg-slate-50/60 transition-colors"
              :aria-expanded="finanzasAbiertas"
          >
            <template v-if="store.precioVisible && totalViaje">
              <div class="flex items-center justify-between gap-4">
                <span class="text-[10px] font-black text-slate-400 uppercase tracking-[0.2em] flex items-center gap-1.5">
                  <i class="fas fa-user-tag text-[#E07845] opacity-90 text-[10px]"></i>
                  <template v-if="finanzasAbiertas">{{ maestroStore.t('cot_precio_por_pasajero') || 'Precio por pasajero' }}</template>
                  <template v-else-if="claseDominante">{{ maestroStore.t('cot_por_pasajero') || 'Por pasajero' }}</template>
                  <template v-else>{{ maestroStore.t('cot_precio_total') || 'Precio total del viaje' }}</template>
                </span>

                <!-- Colapsada: el precio representativo. Expandida se calla, porque el
                     desglose de abajo ya lo dice perfil por perfil. -->
                <span v-if="!finanzasAbiertas"
                      class="text-2xl md:text-3xl font-extrabold tabular-nums tracking-tight leading-none text-[#376875] shrink-0">
                  <template v-if="claseDominante">
                    {{ mv(claseDominante.resumenPorModo.normal.ventaSoles, claseDominante.resumenPorModo.normal.ventaDolares) }}
                  </template>
                  <template v-else>{{ mv(totalViaje.soles, totalViaje.dolares) }}</template>
                </span>
              </div>

              <!-- Total del viaje: sólo colapsada (expandida tiene su propia barra) y
                   sólo si el tour se vende como grupo -- ver totalesOcultos. -->
              <div v-if="!finanzasAbiertas && claseDominante && !ocultarTotales"
                   class="mt-2 pt-2 border-t border-slate-200/60 flex justify-end">
                <span class="text-[10px] font-bold text-slate-400 uppercase tracking-wider tabular-nums flex items-center gap-1.5">
                  {{ maestroStore.t('cot_precio_total') || 'Total del viaje' }}:
                  <span class="text-slate-600 font-extrabold text-[11px]">{{ mv(totalViaje.soles, totalViaje.dolares) }}</span>
                </span>
              </div>
            </template>

            <!-- Sin precio visible: la tarjeta sigue existiendo para llegar a las alternativas -->
            <template v-else>
              <div class="flex items-center justify-between gap-4">
                <span class="text-[10px] font-black text-slate-400 uppercase tracking-[0.2em] flex items-center gap-1.5">
                  <i class="fas fa-shuffle text-[#E07845] opacity-90"></i>
                  {{ maestroStore.t('cot_opciones_alternativas') || 'Opciones alternativas' }}
                </span>
              </div>
            </template>
          </button>

          <!-- ── CONTENIDO EXPANDIDO ── -->
          <div v-if="finanzasAbiertas" class="px-4 md:px-5 pb-4 space-y-6">

            <div v-if="store.precioVisible && totalViaje">
              <!-- Perfiles de pasajero (venta unitaria; el total va en la barra de abajo) -->
              <div class="space-y-3">
                <div
                    v-for="clase in clasesPasajeros"
                    :key="clase.tipo"
                    class="bg-emerald-50/60 rounded-2xl border border-emerald-100 p-4 md:p-5"
                >
                  <div class="flex items-center justify-between gap-4">
                    <div>
                      <span class="inline-block px-3 py-1 rounded-lg bg-emerald-100 text-emerald-700 text-[11px] font-black uppercase tracking-widest mb-1.5">
                        <template v-if="!ocultarTotales">{{ clase.cantidad }}x </template>{{ clase.tipoPaxNombre }}
                      </span>
                      <p class="text-xs font-black text-[#376875] bg-[#376875]/6 border border-[#376875]/10 rounded-lg px-2.5 py-1 inline-block">
                        <i class="fas fa-user-clock mr-1 text-[#E07845]"></i>{{ rangoEdadLabel(clase) }}
                      </p>
                    </div>
                    <div class="text-right shrink-0">
                      <p class="text-[9px] font-black text-emerald-600 uppercase tracking-widest mb-1">
                        {{ maestroStore.t('cot_por_pasajero') || 'Por pasajero' }}
                      </p>
                      <p class="text-xl md:text-2xl font-black text-gray-800 tabular-nums leading-none">
                        {{ mv(clase.resumenPorModo.normal.ventaSoles, clase.resumenPorModo.normal.ventaDolares) }}
                      </p>
                      <p v-if="!ocultarTotales" class="text-[11px] font-black text-slate-500 mt-1 tabular-nums">
                        × {{ clase.cantidad }} {{ maestroStore.t('cot_pax') || 'pax' }}
                        <span class="text-slate-300">·</span>
                        {{ mv(clase.resumenPorModo.normal.ventaSoles * clase.cantidad, clase.resumenPorModo.normal.ventaDolares * clase.cantidad) }}
                      </p>
                    </div>
                  </div>

                  <!-- Cortesías del perfil (si las hay) -->
                  <p
                      v-if="clase.resumenPorModo.cortesia.ventaDolares > 0"
                      class="mt-3 text-[11px] font-bold text-sky-600 bg-sky-50 border border-sky-100 rounded-xl px-3 py-2 inline-block"
                  >
                    <i class="fas fa-gift mr-1"></i>
                    {{ maestroStore.t('cot_incluye_cortesias') || 'Incluye cortesías valorizadas en' }}
                    {{ mv(clase.resumenPorModo.cortesia.ventaSoles * clase.cantidad, clase.resumenPorModo.cortesia.ventaDolares * clase.cantidad) }}
                  </p>
                </div>
              </div>

              <!-- Total del viaje (suma de todos los rangos). Oculto en modo catálogo
                   unitario: allí el precio es un menú por perfil, no un total de grupo. -->
              <div v-if="totalViaje && !ocultarTotales" class="mt-3 flex items-center justify-between bg-[#376875] text-white rounded-2xl px-4 py-3.5 shadow-sm">
                <span class="text-[10px] font-black uppercase tracking-[0.2em] flex items-center gap-2">
                  <i class="fas fa-sack-dollar text-emerald-300"></i>
                  {{ maestroStore.t('cot_precio_total') || 'Precio total del viaje' }}
                </span>
                <span class="text-lg md:text-xl font-black tabular-nums">{{ mv(totalViaje.soles, totalViaje.dolares) }}</span>
              </div>
            </div>

            <!-- Opciones alternativas: independiente del precio. Nombre/tarifa/badges
                 son descriptivos, no dinero -- se muestran aunque precioOculto=true.
                 Solo el monto del delta se gatea por precioVisible. -->
            <div v-if="gruposUpgrade.length" class="pt-1">
              <h2 class="pl-1 text-[#376875]/70 font-black uppercase tracking-[0.2em] text-[11px] flex items-center gap-2 mb-4">
                <i class="fas fa-shuffle text-[#E07845]"></i>
                {{ maestroStore.t('cot_opciones_alternativas') || 'Opciones alternativas' }}
              </h2>
              <div v-for="grupo in gruposUpgrade" :key="(grupo.esOpcion ? 'o' : 'a') + grupo.indice" class="mb-5 last:mb-0">
                <div class="space-y-3">
                  <div
                      v-for="(up, ui) in grupo.opciones"
                      :key="ui"
                      class="bg-orange-50/50 rounded-2xl border border-orange-100 p-4 md:p-5"
                  >
                    <div class="flex items-start justify-between gap-4">
                      <div class="min-w-0">
                        <p class="text-sm font-black text-gray-800 leading-snug">
                          {{ store.traducir(up.componenteNombre) }}
                        </p>
                        <p class="text-[9px] font-black text-slate-400 uppercase tracking-widest mt-0.5">
                          {{ store.traducir(up.servicioNombre) }}
                        </p>
                        <div class="mt-1.5 flex flex-wrap items-center gap-1.5">
                          <span class="inline-flex items-center gap-1 text-[8px] font-black px-1.5 py-0.5 rounded-md uppercase tracking-wider"
                                :class="grupo.esOpcion ? 'bg-amber-100 text-amber-700' : 'bg-[#E07845]/10 text-[#E07845]'">
                            <i class="fas" :class="grupo.esOpcion ? 'fa-circle-question' : 'fa-shuffle'"></i>
                            {{ labelGrupoUpgrade(grupo) }}
                          </span>
                          <span v-if="store.traducir(up.tarifaTitulo)"
                                class="text-[10px] font-semibold text-slate-500 bg-white border border-slate-200/80 rounded-md px-1.5 py-0.5">
                            {{ store.traducir(up.tarifaTitulo) }}
                          </span>
                          <span
                              v-for="b in modCatBadges(up)"
                              :key="b.key"
                              class="inline-flex items-center gap-1 text-[8px] font-black px-1.5 py-0.5 rounded-md border uppercase tracking-wider"
                              :class="b.cls"
                          >
                            {{ b.icon }} {{ b.label }}
                          </span>
                        </div>

                        <!-- Estándar reemplazada: tachada + atenuada (solo datos públicos) -->
                        <p v-if="up.tieneEstandarEspejo && (store.traducir(up.estandarTitulo) || modCatBadges({ modalidad: up.estandarModalidad, categoria: up.estandarCategoria }).length)"
                           class="mt-1.5 flex flex-wrap items-center gap-1.5 text-[11px] text-slate-400">
                          <span class="text-[9px] font-black uppercase tracking-wider text-slate-400">
                            {{ maestroStore.t('cot_reemplaza') || 'Reemplaza' }}
                          </span>
                          <span v-if="store.traducir(up.estandarTitulo)" class="line-through">{{ store.traducir(up.estandarTitulo) }}</span>
                          <span
                              v-for="b in modCatBadges({ modalidad: up.estandarModalidad, categoria: up.estandarCategoria })"
                              :key="'std-' + b.key"
                              class="inline-flex items-center gap-1 text-[8px] font-black px-1.5 py-0.5 rounded-md border uppercase tracking-wider bg-slate-100 text-slate-400 border-slate-200 line-through"
                          >
                            {{ b.icon }} {{ b.label }}
                          </span>
                        </p>
                      </div>

                      <!-- Delta de venta: SOLO si el precio es visible. Es dinero,
                           igual que el resto del panel financiero. -->
                      <div v-if="store.precioVisible" class="text-right shrink-0">
                        <span
                            class="inline-flex flex-col items-end rounded-xl px-3 py-2"
                            :class="(up.deltaVentaPorPax ?? 0) < 0
                              ? 'bg-emerald-100 text-emerald-700'
                              : 'bg-[#E07845]/10 text-[#E07845]'"
                        >
                          <span class="text-[8px] font-black uppercase tracking-widest opacity-80">
                            <i class="fas mr-0.5" :class="(up.deltaVentaPorPax ?? 0) < 0 ? 'fa-arrow-trend-down' : 'fa-arrow-trend-up'"></i>
                            {{ (up.deltaVentaPorPax ?? 0) < 0
                              ? (maestroStore.t('cot_descuento') || 'Descuento')
                              : (maestroStore.t('cot_adicional') || 'Adicional') }}
                            · {{ maestroStore.t('cot_por_persona') || 'c/u' }}
                          </span>
                          <span class="text-xl md:text-2xl font-black tabular-nums leading-tight">{{ mvDelta(up.deltaVentaPorPax ?? 0) }}</span>
                        </span>
                        <p v-if="!ocultarTotales" class="text-[9px] font-bold text-slate-400 mt-1 tabular-nums">
                          {{ maestroStore.t('cot_total') || 'Total' }} {{ mvDelta(up.deltaVentaTotal ?? 0) }}
                        </p>
                      </div>
                    </div>

                    <!-- Nota de la alternativa -->
                    <p
                        v-if="up.notaRol?.length"
                        class="mt-2.5 text-[11px] font-medium text-slate-500 bg-white border border-slate-100 rounded-xl px-3 py-2 italic"
                    >
                      <i class="fas fa-circle-info mr-1 text-slate-400 not-italic"></i>
                      {{ store.traducir(up.notaRol) }}
                    </p>
                  </div>
                </div>
              </div>
            </div>

            <!-- Pie: pax/días (si hay precio) + adelanto -->
            <div class="flex flex-col sm:flex-row items-start sm:items-center gap-3"
                 :class="store.precioVisible && totalViaje ? 'justify-between' : 'justify-end'">
              <p v-if="store.precioVisible && totalViaje" class="text-emerald-700/70 text-[11px] font-bold">
                <template v-if="!ocultarTotales">{{ paxLabel }} · </template>{{ diasLabel }}
              </p>
              <div
                  v-if="store.precioVisible && Number(store.cotizacion.adelanto) > 0"
                  class="bg-slate-50 rounded-2xl border border-slate-200 px-4 py-2.5"
              >
                <p class="text-[9px] font-black text-slate-400 uppercase tracking-widest mb-0.5">
                  {{ maestroStore.t('cot_adelanto') || 'Adelanto' }}
                </p>
                <p class="text-base font-black text-gray-800 tabular-nums leading-none">
                  {{ adelantoVista }}
                </p>
              </div>
            </div>
          </div>

          <!-- ── PIE-DISPARADOR ── Repite el toggle al final: cuando el desglose está
               abierto, el control de arriba queda fuera de pantalla. -->
          <button
              @click="finanzasAbiertas = !finanzasAbiertas"
              class="w-full border-t border-dashed border-slate-200 px-5 py-3 text-[10px] font-black uppercase tracking-widest text-[#E07845] hover:bg-[#E07845]/5 transition-colors flex items-center justify-center gap-1.5"
              :aria-expanded="finanzasAbiertas"
          >
            <i class="fas text-[9px]" :class="finanzasAbiertas ? 'fa-arrow-up' : 'fa-arrow-down'"></i>
            {{ finanzasAbiertas
              ? (maestroStore.t('cot_ocultar_precios') || 'Toca para ocultar')
              : (maestroStore.t('cot_ver_precios_perfil') || (store.precioVisible && totalViaje
                  ? 'Toca para ver precios por perfil'
                  : 'Toca para ver opciones')) }}
          </button>
        </div>
      </section>

      <!-- Modo de lectura del ITINERARIO (no confundir con la tarjeta de precio):
           'Resumen' colapsa descripciones y fotos, dejando títulos, subtítulos y horas.
           Afecta a todos los días. -->
      <div class="max-w-3xl mx-auto px-4 mt-5 md:mt-6 mb-4 flex justify-center sm:justify-start">
        <div class="inline-flex items-center bg-slate-100 border border-slate-200 rounded-2xl p-1 gap-1">
          <button
              @click="modoResumen = false"
              :class="!modoResumen ? 'bg-[#376875] text-white shadow-md shadow-[#376875]/20' : 'text-[#376875]/60 hover:text-[#376875]'"
              class="inline-flex items-center gap-2 px-4 md:px-5 py-2 md:py-2.5 rounded-xl text-[11px] md:text-xs font-black uppercase tracking-widest transition-all"
          >
            <i class="fas fa-align-left" :class="!modoResumen ? 'text-[#E07845]' : 'text-[#E07845]/70'"></i>
            {{ maestroStore.t('cot_modo_detalle') || 'Detalle' }}
          </button>
          <button
              @click="modoResumen = true"
              :class="modoResumen ? 'bg-[#376875] text-white shadow-md shadow-[#376875]/20' : 'text-[#376875]/60 hover:text-[#376875]'"
              class="inline-flex items-center gap-2 px-4 md:px-5 py-2 md:py-2.5 rounded-xl text-[11px] md:text-xs font-black uppercase tracking-widest transition-all"
          >
            <i class="fas fa-list-ul" :class="modoResumen ? 'text-[#E07845]' : 'text-[#E07845]/70'"></i>
            {{ maestroStore.t('cot_modo_resumen') || 'Resumen' }}
          </button>
        </div>
      </div>

      <!-- Day-nav sticky con flechas -->
      <nav class="sticky top-0 z-30 bg-[#F8FAFC]/95 backdrop-blur-sm border-b border-slate-200/60 shadow-sm">
        <div class="max-w-3xl mx-auto px-2 py-2.5 flex items-center gap-1">
          <button
              @click="desplazarNav(-1)"
              class="shrink-0 w-7 h-7 rounded-lg bg-white border border-slate-200 text-[#376875]/60 hover:text-[#376875] hover:border-[#376875]/40 transition-colors flex items-center justify-center"
              :aria-label="maestroStore.t('cot_dias_anteriores') || 'Días anteriores'"
          >
            <i class="fas fa-chevron-left text-[10px]"></i>
          </button>

          <div ref="navDias" class="flex-1 flex gap-2 overflow-x-auto no-scrollbar px-1">
            <button
                v-for="dia in itinerarioVista"
                :key="dia.fecha"
                :data-nav-dia="dia.numeroDia"
                @click="irADia(dia.numeroDia)"
                class="shrink-0 px-3.5 py-1.5 rounded-xl text-[11px] font-black uppercase tracking-wider transition-all"
                :class="diaActivo === dia.numeroDia
                  ? 'bg-[#376875] text-white shadow-md shadow-[#376875]/20'
                  : 'bg-white text-[#376875]/60 border border-slate-200 hover:border-[#376875]/40'"
            >
              {{ maestroStore.t('cot_dia') || 'Día' }} {{ dia.numeroDia }}
            </button>
          </div>

          <button
              @click="desplazarNav(1)"
              class="shrink-0 w-7 h-7 rounded-lg bg-white border border-slate-200 text-[#376875]/60 hover:text-[#376875] hover:border-[#376875]/40 transition-colors flex items-center justify-center"
              :aria-label="maestroStore.t('cot_dias_siguientes') || 'Días siguientes'"
          >
            <i class="fas fa-chevron-right text-[10px]"></i>
          </button>
        </div>
      </nav>

      <main class="max-w-3xl mx-auto px-4 pb-16">

        <!-- ══ CAPÍTULOS POR DÍA ══ -->
        <section
            v-for="(dia, di) in itinerarioVista"
            :key="dia.fecha"
            :id="`dia-${dia.numeroDia}`"
            :data-dia="dia.numeroDia"
            class="pt-8 scroll-mt-16"
        >
          <!-- Título del día -->
          <div class="flex items-center gap-3 mb-5">
            <span class="w-12 h-12 rounded-2xl bg-[#376875] text-white flex flex-col items-center justify-center shrink-0 shadow-lg shadow-[#376875]/20">
              <span class="text-[8px] font-black uppercase leading-none opacity-70">{{ maestroStore.t('cot_dia') || 'Día' }}</span>
              <span class="text-lg font-black leading-none">{{ dia.numeroDia }}</span>
            </span>
            <div class="min-w-0">
              <h2 v-if="!esCatalogo" class="text-lg md:text-xl font-black text-gray-800 capitalize leading-tight">
                {{ formatearFecha(dia.fecha) }}
              </h2>
              <h2 v-else class="text-lg md:text-xl font-black text-gray-800 leading-tight">
                {{ maestroStore.t('cot_dia') || 'Día' }} {{ dia.numeroDia }}
              </h2>
              <p class="text-[10px] font-bold text-[#376875]/50 uppercase tracking-widest">
                {{ dia.bloques.length }} {{ dia.bloques.length === 1 ? (maestroStore.t('cot_actividad') || 'actividad') : (maestroStore.t('cot_actividades') || 'actividades') }}
              </p>
            </div>
          </div>

          <!-- Bloques del día -->
          <template v-for="item in dia.bloques" :key="item.key">

            <!-- Título grande del servicio (1er segmento de servicios multi-segmento) -->
            <h3
                v-if="item.mostrarTituloServicio && !item.esRepeticion"
                class="text-xl md:text-2xl font-black text-[#376875] leading-tight mb-3 mt-2 flex items-start gap-2.5"
            >
              <i class="fas fa-route text-[#E07845] text-sm mt-2 shrink-0"></i>
              <span>{{ store.traducir(item.servicio.tituloSnapshot) }}</span>
            </h3>

            <!-- Horario global de la excursión (componente promovido a "servicio
                 completo"): abarca toda la experiencia, no un segmento puntual. -->
            <div
                v-if="item.horaServicioInicio && !item.esRepeticion"
                class="mb-3 -mt-1 flex items-center gap-2"
            >
              <span class="inline-flex items-center gap-2 text-sm font-black text-white bg-[#E07845] rounded-xl px-3.5 py-2 tabular-nums whitespace-nowrap shadow-md shadow-[#E07845]/30">
                <i class="far fa-clock"></i>
                {{ item.horaServicioInicio }}<template v-if="item.horaServicioFin"> – {{ item.horaServicioFin }}</template>
              </span>
              <span class="text-[10px] font-black text-[#376875]/50 uppercase tracking-widest">
                {{ maestroStore.t('cot_horario_excursion') || 'Horario de la excursión' }}
              </span>
            </div>

            <!-- Fila de acción: botón que abre el modal con las inclusiones del servicio completo.
                 Va entre el <h3> (multi-segmento) y la card, o encima de la card (single) → simetría. -->
            <div
                v-if="item.mostrarAccionInclusiones"
                class="flex justify-end mb-3"
            >
              <button
                  @click="abrirInclusiones(item.servicio.id, item.servicio.tituloSnapshot)"
                  class="inline-flex items-center gap-2 text-[11px] font-black tracking-wide text-[#376875] bg-white border border-[#376875]/20 hover:border-[#376875]/50 hover:bg-[#376875]/5 rounded-xl px-3.5 py-2 shadow-sm transition-colors"
              >
                <i class="fas fa-list-check text-[#E07845]"></i>
                {{ item.totalSegmentosServicio > 1
                  ? (maestroStore.t('cot_inclusiones_tour') || '¿Qué incluye el tour?')
                  : (maestroStore.t('cot_inclusiones_servicio') || '¿Qué incluye este servicio?') }}
                <i class="fas fa-circle-arrow-right text-[10px] text-[#E07845]"></i>
              </button>
            </div>

            <!-- ── Card compacta: repetición de estadía (noche 2+) ── -->
            <article
                v-if="item.esRepeticion"
                class="bg-white rounded-2xl shadow-md shadow-slate-200/40 border border-slate-100 px-5 py-4 mb-6 flex items-center gap-4"
            >
              <span class="w-10 h-10 rounded-xl bg-[#376875]/6 text-[#376875] flex items-center justify-center shrink-0">
                <i class="fas fa-moon"></i>
              </span>
              <div class="min-w-0 flex-1">
                <p class="text-[9px] font-black text-[#376875]/50 uppercase tracking-widest">
                  {{ store.traducir(item.servicio.tituloSnapshot) }}
                  <span class="normal-case text-slate-400 font-bold">· {{ maestroStore.t('cot_noche') || 'Noche' }} {{ item.noche }}/{{ item.totalNoches }}</span>
                </p>
                <p class="font-black text-gray-800 text-sm leading-snug">
                  {{ store.traducir(item.segmento.tituloSnapshot) }}
                </p>
              </div>
            </article>

            <!-- ── Card completa ── -->
            <article
                v-else
                class="bg-white rounded-4xl shadow-xl shadow-slate-200/50 border border-slate-100 overflow-hidden mb-6"
            >
              <!-- Galería de imágenes (desplazable) — oculta en modo Resumen -->
              <div v-if="!modoResumen && galeriaDe(item).length" class="h-48 md:h-64 relative overflow-hidden" data-galeria>
                <div class="galeria-track flex h-full overflow-x-auto snap-x snap-mandatory no-scrollbar">
                  <img
                      v-for="(img, ii) in galeriaDe(item)"
                      :key="ii"
                      :src="img.imageUrl"
                      class="w-full h-full shrink-0 snap-center object-cover"
                      loading="lazy"
                      alt="imagen"/>
                </div>
                <div class="absolute inset-x-0 bottom-0 h-2/3 bg-linear-to-t from-black/60 via-transparent to-transparent pointer-events-none"></div>

                <!-- Flechas de galería -->
                <template v-if="imagenesDe(item.segmento).length > 1">
                  <button @click="desplazarGaleria($event, -1)" class="absolute left-2 top-1/2 -translate-y-1/2 w-8 h-8 rounded-full bg-black/30 hover:bg-black/50 text-white backdrop-blur-sm flex items-center justify-center transition-colors">
                    <i class="fas fa-chevron-left text-xs"></i>
                  </button>
                  <button @click="desplazarGaleria($event, 1)" class="absolute right-2 top-1/2 -translate-y-1/2 w-8 h-8 rounded-full bg-black/30 hover:bg-black/50 text-white backdrop-blur-sm flex items-center justify-center transition-colors">
                    <i class="fas fa-chevron-right text-xs"></i>
                  </button>
                  <span class="absolute top-3 right-3 text-[9px] font-black text-white bg-black/40 backdrop-blur-sm rounded-lg px-2 py-1 uppercase tracking-wider">
                    <i class="fas fa-images mr-1"></i>{{ imagenesDe(item.segmento).length }}
                  </span>
                </template>

                <div class="absolute bottom-0 left-0 p-5 md:p-6 pointer-events-none">
                  <p v-if="!item.mostrarTituloServicio" class="text-white/80 text-[10px] font-black uppercase tracking-widest mb-1 drop-shadow">
                    {{ store.traducir(item.servicio.tituloSnapshot) }}
                  </p>
                  <h4 class="text-white text-lg md:text-xl font-black leading-tight drop-shadow-md">
                    {{ store.traducir(item.segmento.tituloSnapshot) }}
                  </h4>
                </div>
              </div>

              <div :class="modoResumen ? 'p-4 md:p-5' : 'p-5 md:p-7'">
                <!-- Encabezado con título. Se muestra si no hubo galería o en modo
                     Resumen (donde la galería se oculta y el título toma su lugar). -->
                <div v-if="modoResumen || !imagenesDe(item.segmento).length" class="flex items-start justify-between gap-3">
                  <div class="min-w-0">
                    <p v-if="!item.mostrarTituloServicio" class="text-[#376875]/60 text-[10px] font-black uppercase tracking-widest mb-1">
                      {{ store.traducir(item.servicio.tituloSnapshot) }}
                    </p>
                    <h4
                        class="text-gray-800 font-black leading-tight"
                        :class="modoResumen ? 'text-sm md:text-base mb-0' : 'text-base md:text-lg mb-3'"
                    >
                      {{ store.traducir(item.segmento.tituloSnapshot) }}
                    </h4>
                  </div>
                  <!-- Rango horario del segmento (derivado de componentes) -->
                  <span
                      v-if="item.horaInicio"
                      class="shrink-0 inline-flex items-center font-black text-white bg-[#E07845] tabular-nums whitespace-nowrap shadow-md shadow-[#E07845]/30"
                      :class="modoResumen ? 'gap-1.5 text-xs rounded-lg px-2.5 py-1' : 'gap-2 text-sm rounded-xl px-3.5 py-2'"
                  >
                    <i class="far fa-clock" :class="modoResumen ? 'text-[10px]' : ''"></i>
                    {{ item.horaInicio }}<template v-if="item.horaFin && item.horaFin !== item.horaInicio"> – {{ item.horaFin }}</template>
                  </span>
                </div>
                <!-- Hora cuando sí hay galería -->
                <span
                    v-else-if="item.horaInicio"
                    class="inline-flex items-center gap-2 text-sm font-black text-white bg-[#E07845] rounded-xl px-3.5 py-2 tabular-nums mb-3 shadow-md shadow-[#E07845]/30"
                >
                  <i class="far fa-clock"></i>
                  {{ item.horaInicio }}<template v-if="item.horaFin && item.horaFin !== item.horaInicio"> – {{ item.horaFin }}</template>
                </span>

                <!-- Contenido narrativo (truncable) — oculto en modo Resumen -->
                <div v-if="!modoResumen" class="relative">
                  <!-- eslint-disable vue/no-v-html -- Contenido del catálogo maestro, redactado por el equipo. HTML a propósito. -->
                  <div
                      class="prose prose-sm max-w-none text-slate-600 prose-strong:text-[#376875] prose-a:text-[#E07845] prose-p:leading-relaxed transition-all"
                      :class="descEsLarga(item.segmento) && !descExpandida.has(item.key) ? 'max-h-36 overflow-hidden' : ''"
                      v-html="store.traducir(item.segmento.contenidoSnapshot)"
                  />
                  <!-- eslint-enable vue/no-v-html -->
                  <div
                      v-if="descEsLarga(item.segmento) && !descExpandida.has(item.key)"
                      class="absolute inset-x-0 bottom-0 h-14 bg-linear-to-t from-white to-transparent pointer-events-none"
                  ></div>
                </div>
                <button
                    v-if="!modoResumen && descEsLarga(item.segmento)"
                    @click="toggle(descExpandida, item.key)"
                    class="mt-1 text-[10px] font-black uppercase tracking-widest text-[#E07845] hover:text-[#D06535] transition-colors"
                >
                  <i class="fas mr-1" :class="descExpandida.has(item.key) ? 'fa-chevron-up' : 'fa-chevron-down'"></i>
                  {{ descExpandida.has(item.key) ? (maestroStore.t('cot_leer_menos') || 'Leer menos') : (maestroStore.t('cot_leer_mas') || 'Leer más') }}
                </button>

                <!-- Horarios de componentes (con hora real) -->
                <div v-if="compsConHora(item).length > 1" class="mt-4 bg-slate-50 border border-slate-100 rounded-2xl px-4 py-3 space-y-2">
                  <p
                      v-for="c in compsConHora(item)"
                      :key="c.id"
                      class="flex items-center gap-2.5 text-xs font-bold text-slate-500"
                  >
                    <i class="far fa-clock text-[#E07845] shrink-0"></i>
                    <span class="tabular-nums text-[#376875] font-black text-sm shrink-0 whitespace-nowrap">{{ horaRango(c) }}</span>
                    <span class="truncate">{{ tituloDeComponente(c, item.segmento) }}</span>
                  </p>
                </div>

                <!-- Detalles operativos para el cliente (vuelos, recojos) -->
                <template v-for="comp in item.componentes" :key="comp.id">
                  <div
                      v-for="det in comp.detallesParaCliente"
                      :key="det.id"
                      class="mt-4 flex items-start gap-3 bg-[#376875]/4 border border-[#376875]/10 rounded-2xl px-4 py-3"
                  >
                    <i class="fas fa-circle-info text-[#E07845] mt-0.5 shrink-0"></i>
                    <p class="text-sm font-bold text-[#376875] leading-snug">{{ store.traducir(det.detalle) }}</p>
                  </div>
                </template>

                <!-- Notas / recomendaciones -->
                <details
                    v-for="nota in item.segmento.notasSnapshot"
                    :key="nota.id"
                    class="mt-4 group/nota bg-amber-50/60 border border-amber-100 rounded-2xl overflow-hidden"
                >
                  <summary class="px-4 py-3 cursor-pointer list-none flex items-center justify-between gap-2 text-amber-800 font-black text-xs uppercase tracking-wider hover:bg-amber-50 transition-colors">
                    <span><i class="fas fa-lightbulb mr-2"></i>{{ store.traducir(nota.titulo) }}</span>
                    <i class="fas fa-chevron-down text-amber-400 transition-transform group-open/nota:rotate-180"></i>
                  </summary>
                  <!-- eslint-disable vue/no-v-html -- Contenido del catálogo maestro, redactado por el equipo. HTML a propósito. -->
                  <div
                      class="px-4 pb-4 prose prose-sm max-w-none text-amber-900/80 prose-p:my-1 prose-p:leading-relaxed"
                      v-html="store.traducir(nota.contenido)"
                  />
                  <!-- eslint-enable vue/no-v-html -->
                </details>
              </div>
            </article>
          </template>

          <!-- ══ INCLUSIONES DEL DÍA (panel único, elegante, semicolapsado) ══ -->
          <div
              v-if="inclusionesPorDia.get(dia.fecha)?.servicios.length"
              class="bg-white rounded-3xl shadow-md shadow-slate-200/40 border border-slate-100 p-5 md:p-7 mb-4"
          >
            <p class="text-sm font-black text-[#376875] tracking-tight flex items-center gap-2 mb-5">
              <i class="fas fa-list-check text-[#E07845]"></i>
              {{ maestroStore.t('cot_inclusiones_dia') || '¿Qué incluye este día?' }}
            </p>

            <div class="relative">
              <div
                  class="space-y-7 transition-all"
                  :class="inclusionesPorDia.get(dia.fecha)?.largo && !incExpandida.has(dia.fecha) ? 'max-h-32 overflow-hidden' : ''"
              >
                <!-- Un bloque por servicio del día, dentro del mismo panel -->
                <div v-for="inc in inclusionesPorDia.get(dia.fecha)?.servicios" :key="inc.servicioId">
                  <p class="text-[11px] font-black text-[#376875] uppercase tracking-[0.15em] flex items-center gap-2 mb-3">
                    <span class="w-1.5 h-1.5 rounded-full bg-[#E07845] shrink-0"></span>
                    {{ store.traducir(inc.nombre) }}
                  </p>

                  <div class="space-y-5">
                    <div v-for="sec in inc.secciones" :key="sec.key">
                      <p class="text-[10px] font-black text-slate-500 uppercase tracking-[0.2em] mb-2 pb-1 border-b border-slate-100">{{ sec.titulo }}</p>
                      <ul class="space-y-2">
                        <li v-for="(l, i) in sec.lineas" :key="i">
                          <p class="flex items-start gap-2">
                            <i class="fas mt-0.5 text-xs shrink-0" :class="sec.icono"></i>
                            <span class="text-[13px] font-semibold text-slate-700 leading-snug">
                              <span v-if="l.grupoOpcion != null"
                                    class="text-[9px] font-black text-amber-700 bg-amber-50 border border-amber-200 rounded px-1.5 py-0.5 mr-1 uppercase whitespace-nowrap align-middle">
                                {{ maestroStore.t('cot_opcion') || 'Opción' }} {{ l.grupoOpcion }}
                              </span>
                              {{ store.traducir(l.nombre) }}
                              <b v-if="l.cantidadComponente > 1" class="text-[#376875] font-black">×{{ l.cantidadComponente }}</b>
                              <span class="text-[10px] font-medium text-slate-400 ml-1.5 whitespace-nowrap capitalize">
                                · {{ fechaChip(l.fecha) }}
                              </span>
                            </span>
                          </p>

                          <!-- Chips: tarifa + badges + proveedor -->
                          <div
                              v-for="(chip, ci) in chipsDeLinea(l)"
                              :key="ci"
                              class="ml-6 mt-1 flex flex-wrap items-center gap-1.5"
                          >
                            <span
                                v-if="chip.titulo"
                                class="text-[10px] font-semibold text-slate-500 bg-slate-50 border border-slate-200/80 rounded-md px-1.5 py-0.5"
                            >
                              {{ chip.titulo }}<b v-if="chip.count > 1" class="text-[#376875] font-black ml-1">×{{ chip.count }}</b>
                            </span>
                            <span
                                v-for="b in chip.badges"
                                :key="b.key"
                                class="inline-flex items-center gap-1 text-[8px] font-black px-1.5 py-0.5 rounded-md border uppercase tracking-wider"
                                :class="b.cls"
                            >
                              {{ b.icon }} {{ b.label }}
                            </span>
                            <button
                                v-if="chip.proveedor"
                                @click="abrirProveedor(chip.proveedor)"
                                class="inline-flex items-center gap-1 text-[9px] font-black uppercase tracking-wider text-white bg-[#E07845] hover:bg-[#D06535] rounded-md px-2 py-0.5 shadow-sm shadow-[#E07845]/30 transition-colors"
                            >
                              <i class="fas fa-hotel text-[8px]"></i>
                              {{ store.traducir(chip.proveedor.titulo) }}
                              <i class="fas fa-circle-arrow-right text-[8px]"></i>
                            </button>
                          </div>

                          <!-- Referencia del pasajero (no incluidos). Tono afirmativo
                               y color propio: no es una carencia ni un proveedor
                               nuestro, es lo que él ya tiene reservado. -->
                          <div v-if="prestadorDeLinea(l)" class="ml-6 mt-1.5">
                            <button
                                @click="abrirProveedor(prestadorDeLinea(l)!)"
                                class="inline-flex items-center gap-1.5 text-[10px] font-bold text-[#376875] bg-[#376875]/6 hover:bg-[#376875]/12 border border-[#376875]/20 rounded-lg px-2 py-1 transition-colors"
                            >
                              <i class="fas fa-location-dot text-[9px] text-[#E07845]"></i>
                              <span class="text-slate-500 font-semibold">{{ maestroStore.t('cot_su_reserva') || 'Su reserva:' }}</span>
                              <b>{{ store.traducir(prestadorDeLinea(l)!.titulo) }}</b>
                              <i class="fas fa-circle-arrow-right text-[8px] opacity-60"></i>
                            </button>
                          </div>
                        </li>
                      </ul>
                    </div>
                  </div>
                </div>
              </div>

              <!-- Fade + "mostrar más" (uno solo para todo el panel del día) -->
              <button
                  v-if="inclusionesPorDia.get(dia.fecha)?.largo && !incExpandida.has(dia.fecha)"
                  @click="toggle(incExpandida, dia.fecha)"
                  class="absolute inset-x-0 bottom-0 h-16 bg-linear-to-t from-white via-white/90 to-transparent flex items-end justify-center pb-1"
              >
                <span class="text-[10px] font-black uppercase tracking-widest text-[#E07845] bg-white/90 border border-[#E07845]/20 rounded-full px-3.5 py-1.5 shadow-sm">
                  <i class="fas fa-chevron-down mr-1"></i>{{ maestroStore.t('cot_ver_todo') || 'Ver todo' }}
                </span>
              </button>
            </div>

            <div
                v-if="inclusionesPorDia.get(dia.fecha)?.largo && incExpandida.has(dia.fecha)"
                class="flex justify-center mt-5"
            >
              <button
                  @click="toggle(incExpandida, dia.fecha)"
                  class="text-[10px] font-black uppercase tracking-widest text-[#E07845] hover:text-[#D06535] border border-[#E07845]/20 rounded-full px-3.5 py-1.5 transition-colors"
              >
                <i class="fas fa-chevron-up mr-1"></i>{{ maestroStore.t('cot_ver_menos') || 'Ver menos' }}
              </button>
            </div>
          </div>

          <!-- Pie tipo libro -->
          <div class="flex justify-between gap-2 mb-2">
            <button
                v-if="di > 0"
                @click="irADia(itinerarioVista[di - 1].numeroDia)"
                class="text-[11px] font-black uppercase tracking-widest text-[#376875]/50 hover:text-[#376875] transition-colors"
            >
              ← {{ maestroStore.t('cot_dia') || 'Día' }} {{ itinerarioVista[di - 1].numeroDia }}
            </button>
            <span v-else></span>
            <button
                v-if="di < itinerarioVista.length - 1"
                @click="irADia(itinerarioVista[di + 1].numeroDia)"
                class="text-[11px] font-black uppercase tracking-widest text-[#E07845] hover:text-[#D06535] transition-colors"
            >
              {{ maestroStore.t('cot_dia') || 'Día' }} {{ itinerarioVista[di + 1].numeroDia }} →
            </button>
          </div>
        </section>

        <!-- Aviso de data retenida -->
        <p v-if="store.error" class="mt-8 text-center text-xs font-bold text-amber-600 bg-amber-50 rounded-xl py-3 px-4">
          <i class="fas fa-wifi mr-1"></i> {{ store.error }}
        </p>

        <div class="mt-14 text-center">
          <p class="text-[9px] text-[#376875]/40 uppercase tracking-[0.3em] font-black">
            {{ maestroStore.t('com_powered_by') || 'Powered by OpenPeru' }}
          </p>
        </div>
      </main>

      <!-- ═══ MODAL PROVEEDOR ═══ -->
      <div
          v-if="modalProveedor"
          class="fixed inset-0 z-50 flex items-end sm:items-center justify-center"
      >
        <div class="absolute inset-0 bg-black/50 backdrop-blur-sm" @click="modalProveedor = null"></div>

        <div class="relative bg-white w-full sm:max-w-lg sm:mx-6 rounded-t-4xl sm:rounded-4xl max-h-[85vh] overflow-y-auto shadow-2xl">
          <!-- Cabecera -->
          <div class="sticky top-0 bg-white/95 backdrop-blur-sm border-b border-slate-100 px-6 py-4 flex items-center justify-between gap-3 z-10">
            <h3 class="font-black text-[#376875] text-base leading-tight">
              <i class="fas fa-hotel text-[#E07845] mr-2"></i>{{ store.traducir(modalProveedor.titulo) }}
            </h3>
            <button
                @click="modalProveedor = null"
                class="w-8 h-8 rounded-full bg-slate-100 hover:bg-slate-200 text-slate-500 flex items-center justify-center transition-colors shrink-0"
            >
              <i class="fas fa-times text-sm"></i>
            </button>
          </div>

          <!-- Galería -->
          <div v-if="galeriaProveedor(modalProveedor).length" class="relative" data-galeria>
            <div class="galeria-track flex h-56 sm:h-64 overflow-x-auto snap-x snap-mandatory no-scrollbar">
              <img
                  v-for="(img, gi) in galeriaProveedor(modalProveedor)"
                  :key="gi"
                  :src="img.imageUrl"
                  class="w-full h-full shrink-0 snap-center object-cover"
                  loading="lazy"
                  alt="Imagen"/>
            </div>
            <template v-if="galeriaProveedor(modalProveedor).length > 1">
              <button @click="desplazarGaleria($event, -1)" class="absolute left-2 top-1/2 -translate-y-1/2 w-8 h-8 rounded-full bg-black/30 hover:bg-black/50 text-white backdrop-blur-sm flex items-center justify-center transition-colors">
                <i class="fas fa-chevron-left text-xs"></i>
              </button>
              <button @click="desplazarGaleria($event, 1)" class="absolute right-2 top-1/2 -translate-y-1/2 w-8 h-8 rounded-full bg-black/30 hover:bg-black/50 text-white backdrop-blur-sm flex items-center justify-center transition-colors">
                <i class="fas fa-chevron-right text-xs"></i>
              </button>
              <span class="absolute top-3 right-3 text-[9px] font-black text-white bg-black/40 backdrop-blur-sm rounded-lg px-2 py-1 uppercase tracking-wider">
                <i class="fas fa-images mr-1"></i>{{ galeriaProveedor(modalProveedor).length }}
              </span>
            </template>
          </div>

          <div class="px-6 py-5 space-y-4">
            <!-- Servicio del proveedor (ej. tipo de habitación) -->
            <div v-if="modalProveedor.servicioTitulo.length" class="flex items-start gap-3">
              <span class="w-9 h-9 rounded-xl bg-[#376875]/6 text-[#376875] flex items-center justify-center shrink-0">
                <i class="fas fa-bed text-sm"></i>
              </span>
              <div>
                <p class="text-[9px] font-black text-slate-400 uppercase tracking-widest">
                  {{ maestroStore.t('cot_servicio_reservado') || 'Servicio reservado' }}
                </p>
                <p class="font-bold text-gray-800 text-sm leading-snug">
                  {{ store.traducir(modalProveedor.servicioTitulo) }}
                </p>
              </div>
            </div>

            <!-- Sitio web -->
            <a
                v-if="modalProveedor.url"
                :href="modalProveedor.url"
                target="_blank"
                rel="noopener noreferrer"
                class="flex items-center justify-center gap-2 w-full bg-[#376875] hover:bg-[#2b525d] text-white font-black text-xs uppercase tracking-widest px-5 py-3.5 rounded-2xl transition-colors"
            >
              <i class="fas fa-globe"></i>
              {{ maestroStore.t('cot_visitar_sitio') || 'Visitar sitio web' }}
              <i class="fas fa-arrow-up-right-from-square text-[10px]"></i>
            </a>
          </div>
        </div>
      </div>

      <!-- ═══ MODAL INCLUSIONES (servicio completo, todos los días) ═══ -->
      <div
          v-if="modalInclusiones"
          class="fixed inset-0 z-50 flex items-end sm:items-center justify-center"
      >
        <div class="absolute inset-0 bg-black/50 backdrop-blur-sm" @click="modalInclusiones = null"></div>

        <div class="relative bg-white w-full sm:max-w-lg sm:mx-6 rounded-t-4xl sm:rounded-4xl max-h-[85vh] overflow-y-auto shadow-2xl">
          <!-- Cabecera -->
          <div class="sticky top-0 bg-white/95 backdrop-blur-sm border-b border-slate-100 px-6 py-4 flex items-center justify-between gap-3 z-10">
            <h3 class="font-black text-[#376875] text-base leading-tight">
              <i class="fas fa-list-check text-[#E07845] mr-2"></i>{{ store.traducir(modalInclusiones?.nombre) }}
            </h3>
            <button
                @click="modalInclusiones = null"
                class="w-8 h-8 rounded-full bg-slate-100 hover:bg-slate-200 text-slate-500 flex items-center justify-center transition-colors shrink-0"
            >
              <i class="fas fa-times text-sm"></i>
            </button>
          </div>

          <div class="px-6 py-5 space-y-5">
            <div v-for="sec in modalInclusiones?.secciones" :key="sec.key">
              <p class="text-[10px] font-black text-slate-500 uppercase tracking-[0.2em] mb-2 pb-1 border-b border-slate-100">{{ sec.titulo }}</p>
              <ul class="space-y-2">
                <li v-for="(l, i) in sec.lineas" :key="i">
                  <p class="flex items-start gap-2">
                    <i class="fas mt-0.5 text-xs shrink-0" :class="sec.icono"></i>
                    <span class="text-[13px] font-semibold text-slate-700 leading-snug">
                      <span v-if="l.grupoOpcion != null"
                            class="text-[9px] font-black text-amber-700 bg-amber-50 border border-amber-200 rounded px-1.5 py-0.5 mr-1 uppercase whitespace-nowrap align-middle">
                        {{ maestroStore.t('cot_opcion') || 'Opción' }} {{ l.grupoOpcion }}
                      </span>
                      {{ store.traducir(l.nombre) }}
                      <b v-if="l.cantidadComponente > 1" class="text-[#376875] font-black">×{{ l.cantidadComponente }}</b>
                      <span class="text-[10px] font-medium text-slate-400 ml-1.5 whitespace-nowrap capitalize">
                        · {{ fechaChip(l.fecha) }}
                      </span>
                    </span>
                  </p>

                  <!-- Chips: tarifa + badges + proveedor -->
                  <div
                      v-for="(chip, ci) in chipsDeLinea(l)"
                      :key="ci"
                      class="ml-6 mt-1 flex flex-wrap items-center gap-1.5"
                  >
                    <span
                        v-if="chip.titulo"
                        class="text-[10px] font-semibold text-slate-500 bg-slate-50 border border-slate-200/80 rounded-md px-1.5 py-0.5"
                    >
                      {{ chip.titulo }}<b v-if="chip.count > 1" class="text-[#376875] font-black ml-1">×{{ chip.count }}</b>
                    </span>
                    <span
                        v-for="b in chip.badges"
                        :key="b.key"
                        class="inline-flex items-center gap-1 text-[8px] font-black px-1.5 py-0.5 rounded-md border uppercase tracking-wider"
                        :class="b.cls"
                    >
                      {{ b.icon }} {{ b.label }}
                    </span>
                    <button
                        v-if="chip.proveedor"
                        @click="abrirProveedor(chip.proveedor)"
                        class="inline-flex items-center gap-1 text-[9px] font-black uppercase tracking-wider text-white bg-[#E07845] hover:bg-[#D06535] rounded-md px-2 py-0.5 shadow-sm shadow-[#E07845]/30 transition-colors"
                    >
                      <i class="fas fa-hotel text-[8px]"></i>
                      {{ store.traducir(chip.proveedor.titulo) }}
                      <i class="fas fa-circle-arrow-right text-[8px]"></i>
                    </button>
                  </div>
                </li>
              </ul>
            </div>
          </div>
        </div>
      </div>
    </template>
  </div>
</template>

<style scoped>
/* Oculta la scrollbar manteniendo el scroll horizontal */
.no-scrollbar::-webkit-scrollbar { display: none; }
.no-scrollbar { -ms-overflow-style: none; scrollbar-width: none; }

/* Flechita del trigger de precio/alternativas: parpadeo + blur cada 5s,
   para señalar que el botón es interactivo sin ser una animación continua. */
@keyframes arrow-pulse-blur {
  0%, 85%, 100% { opacity: 1; filter: blur(0); transform: translateY(0); }
  92% { opacity: 0.25; filter: blur(1.5px); transform: translateY(2px); }
}
.arrow-pulse-blur {
  animation: arrow-pulse-blur 5s ease-in-out infinite;
}
</style>