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
 *  - Tarifas con proveedor visible (proveedorTituloSnapshot) → botón "ver más" con modal.
 *  - Resumen financiero: colapsado en el header; expandido divide header y menú de días.
 *
 * Inclusiones (dos vistas):
 *  1. Inline al final de cada día: por servicio con líneas en ese día → "Detalle de <servicio>"
 *     con las 4 secciones (incluye / no incluye / cortesía / opcional) filtradas por fecha.
 *  2. Fila de acción con botón sobre la card (entre el título de paquete y el de la card) →
 *     abre un modal con las inclusiones del servicio COMPLETO (todos los días).
 */
import { ref, onMounted, onBeforeUnmount, watch, nextTick, computed } from 'vue';
import { useRouter } from 'vue-router';
import { usePaxCotizacionStore } from '@/stores/cotizacion/paxCotizacionStore';
import { useMaestroStore } from '@/stores/maestroStore';
import type { PaxInclusionItem, PaxTarifaFinanciera, PaxClasePasajero } from '@/types/paxCotizacionModel';

const props = defineProps<{
  localizador: string;
  version: string | number;
}>();

const store = usePaxCotizacionStore();
const maestroStore = useMaestroStore();
const router = useRouter();

const isReady = ref(false);
const diaActivo = ref(1);
let observer: IntersectionObserver | null = null;

// ── Carga ────────────────────────────────────────────────────────────────────
const cargar = async () => {
  isReady.value = false;
  try {
    await maestroStore.cargarConfiguracion();
    await store.cargarVersion(props.localizador, Number(props.version));
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
  router.push({ name: 'file_publica', params: { localizador: props.localizador } });
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
const mv = (soles: number, dolares: number) =>
    monedaVista.value === 'PEN' ? `S/ ${n2(soles)}` : `$ ${n2(dolares)}`;

// ── Helpers de fecha/hora ────────────────────────────────────────────────────
const dateOf = (iso: string) => iso.substring(0, 10);

/** Hora 'HH:mm' solo si es una hora real (≠ medianoche) */
const horaDe = (iso?: string | null): string | null => {
  if (!iso || iso.length < 16) return null;
  const t = iso.substring(11, 16);
  return t && t !== '00:00' ? t : null;
};

const addDays = (ymd: string, n: number) => {
  const d = new Date(ymd + 'T00:00:00');
  d.setDate(d.getDate() + n);
  const p = (x: number) => String(x).padStart(2, '0');
  return `${d.getFullYear()}-${p(d.getMonth() + 1)}-${p(d.getDate())}`;
};

const diffDays = (a: string, b: string) =>
    Math.round((new Date(b + 'T00:00:00').getTime() - new Date(a + 'T00:00:00').getTime()) / 86400000);

const formatearFecha = (ymd: string) =>
    new Date(ymd.substring(0, 10) + 'T00:00:00').toLocaleDateString(maestroStore.idiomaActual, {
      weekday: 'long', day: 'numeric', month: 'long', timeZone: 'America/Lima',
    });

const fechaChip = (iso: string) =>
    new Date(iso.substring(0, 10) + 'T00:00:00').toLocaleDateString(maestroStore.idiomaActual, {
      day: '2-digit', month: 'short', timeZone: 'America/Lima',
    });

// ── Itinerario de vista (segmentos → bloques por día) ────────────────────────
interface BloqueVista {
  key: string;
  servicio: any;
  segmento: any;
  componentes: any[];
  horaInicio: string | null;       // derivada del primer componente con hora
  horaFin: string | null;          // derivada del último componente con hora
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
  const cot: any = store.cotizacion;
  if (!cot?.cotservicios?.length) return [];

  const porFecha = new Map<string, BloqueVista[]>();
  const push = (fecha: string, b: BloqueVista) => {
    if (!porFecha.has(fecha)) porFecha.set(fecha, []);
    porFecha.get(fecha)!.push(b);
  };

  for (const servicio of cot.cotservicios) {
    const segs = [...(servicio.cotsegmentos ?? [])].sort((a: any, b: any) => (a.dia - b.dia) || (a.orden - b.orden));

    for (const segmento of segs) {
      const comps = (servicio.cotcomponentes ?? []).filter((c: any) => c.cotsegmento?.id === segmento.id);

      // Hora dinámica del segmento: min inicio / max fin de componentes con hora real
      const inicios = comps.map((c: any) => (horaDe(c.fechaHoraInicio) ? c.fechaHoraInicio : null)).filter(Boolean) as string[];
      const fines   = comps.map((c: any) => (horaDe(c.fechaHoraFin)    ? c.fechaHoraFin    : null)).filter(Boolean) as string[];
      const horaInicio = inicios.length ? inicios.sort()[0].substring(11, 16) : null;
      const horaFin    = fines.length   ? fines.sort()[fines.length - 1].substring(11, 16) : null;

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
    // Orden del día: con hora (cronológico) → sin hora → estadías
    bloques.sort((a, b) => {
      const ka = a.horaInicio ? 0 : (a.esEstadia ? 2 : 1);
      const kb = b.horaInicio ? 0 : (b.esEstadia ? 2 : 1);
      if (ka !== kb) return ka - kb;
      if (a.horaInicio && b.horaInicio) return a.horaInicio.localeCompare(b.horaInicio);
      return 0;
    });
    // Título de servicio grande en el 1er segmento (por día) de servicios multi-segmento
    const vistos = new Set<string>();
    for (const b of bloques) {
      b.mostrarTituloServicio = b.totalSegmentosServicio > 1 && !vistos.has(b.servicio.id);
      vistos.add(b.servicio.id);
    }
    return { fecha, numeroDia: diffDays(fechaBase, fecha) + 1, bloques };
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
        .filter((c: any) => horaDe(c.fechaHoraInicio))
        .sort((a: any, b2: any) => a.fechaHoraInicio.localeCompare(b2.fechaHoraInicio));

const horaRango = (c: any) => {
  const hi = horaDe(c.fechaHoraInicio);
  const hf = horaDe(c.fechaHoraFin);
  // Si inicio y fin coinciden, mostrar una sola hora
  return hf && hf !== hi ? `${hi} – ${hf}` : hi;
};

// ── Imágenes de segmento (galería) ───────────────────────────────────────────
const imagenesDe = (segmento: any): { imageUrl: string }[] =>
    (segmento.imagenesSnapshot ?? []).filter((i: any) => i.imageUrl);

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
const toggle = (set: Set<string>, key: string) => { set.has(key) ? set.delete(key) : set.add(key); };

/** ¿La descripción es lo bastante larga como para truncarla? */
const descEsLarga = (segmento: any) => (store.traducir(segmento.contenidoSnapshot) || '').length > 450;

// ── i18n helper (clave estable para lookups) ─────────────────────────────────
const contenidoEs = (i18n: any[] | undefined): string =>
    i18n?.find((c: any) => c.language === 'es')?.content ?? i18n?.[0]?.content ?? '';

// ── Proveedores visibles (modal "ver más") ───────────────────────────────────
interface ProveedorInfo {
  titulo: any[];
  url: string | null;
  imagenes: { imageUrl: string }[];
  servicioTitulo: any[];
  servicioImagenes: { imageUrl: string }[];
}

const proveedorPorTarifa = computed(() => {
  const m = new Map<string, ProveedorInfo>();
  const cot: any = store.cotizacion;
  for (const srv of cot?.cotservicios ?? []) {
    for (const comp of srv.cotcomponentes ?? []) {
      for (const t of comp.cottarifas ?? []) {
        if (t.proveedorTituloSnapshot?.length && !t.proveedorOculto) {
          m.set(`${srv.id}::${contenidoEs(t.tituloSnapshot)}`, {
            titulo: t.proveedorTituloSnapshot,
            url: t.proveedorUrlSnapshot ?? null,
            imagenes: (t.proveedorImagenesSnapshot ?? []).filter((i: any) => i.imageUrl),
            servicioTitulo: t.proveedorServicioTituloSnapshot ?? [],
            servicioImagenes: (t.proveedorServicioImagenesSnapshot ?? []).filter((i: any) => i.imageUrl),
          });
        }
      }
    }
  }
  return m;
});

const modalProveedor = ref<ProveedorInfo | null>(null);
const abrirProveedor = (p: ProveedorInfo) => { modalProveedor.value = p; };

const galeriaProveedor = (p: ProveedorInfo) => [...p.servicioImagenes, ...p.imagenes];

// ── Badges de modalidad / categoría (traducibles) ────────────────────────────
const MODALIDAD_UI: Record<string, { icon: string; i18nKey: string; fallback: string }> = {
  privado:    { icon: '🔒', i18nKey: 'cot_privado',    fallback: 'Privado' },
  compartido: { icon: '👥', i18nKey: 'cot_compartido', fallback: 'Compartido' },
};
const CATEGORIA_UI: Record<string, { icon: string; i18nKey: string; fallback: string }> = {
  superior: { icon: '✨', i18nKey: 'cot_superior', fallback: 'Superior' },
  estandar: { icon: '🏷️', i18nKey: 'cot_estandar', fallback: 'Estándar' },
  lujo:     { icon: '👑', i18nKey: 'cot_lujo',     fallback: 'Lujo' },
};

const modCatBadges = (modalidad?: string | null, categoria?: string | null) => {
  const b: { key: string; icon: string; label: string; cls: string }[] = [];
  if (modalidad && MODALIDAD_UI[modalidad]) {
    const m = MODALIDAD_UI[modalidad];
    b.push({ key: 'mod', icon: m.icon, label: maestroStore.t(m.i18nKey) || m.fallback, cls: 'bg-sky-50 text-sky-700 border-sky-200' });
  }
  if (categoria) {
    const c = CATEGORIA_UI[categoria];
    b.push({
      key: 'cat',
      icon: c?.icon ?? '✨',
      label: c ? (maestroStore.t(c.i18nKey) || c.fallback) : categoria,
      cls: 'bg-purple-50 text-purple-700 border-purple-200',
    });
  }
  return b;
};

// ── Inclusiones (versión cliente: sin montos) ────────────────────────────────
const inclusionPorServicio = computed(() => {
  const m = new Map<string, any>();
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
type InclusionServicioDia = { servicioId: string; nombre: any; secciones: ReturnType<typeof seccionesInclusion> };
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

      servicios.push({ servicioId: sid, nombre: b.servicio.nombrePublicoSnapshot, secciones });
    }

    // Total de líneas del día → decide si el panel arranca semicolapsado
    const totalLineas = servicios.reduce(
        (n, s) => n + s.secciones.reduce((k, sec) => k + sec.lineas.length, 0), 0);
    m.set(dia.fecha, { servicios, largo: totalLineas > 3 });
  }
  return m;
});

// ── Modal de inclusiones del servicio COMPLETO (todos los días) ──────────────
interface InclusionModal {
  servicioId: string;
  nombre: any;
  secciones: ReturnType<typeof seccionesInclusion>;
}
const modalInclusiones = ref<InclusionModal | null>(null);
const abrirInclusiones = (servicioId: string, nombre: any) => {
  const srv = inclusionPorServicio.value.get(servicioId);
  if (!srv) return;
  modalInclusiones.value = { servicioId, nombre, secciones: seccionesInclusion(srv) };
};

/** Chips de tarifa de una línea (título + badges + proveedor si es visible) */
const chipsDeLinea = (l: PaxInclusionItem, servicioId: string) => {
  const chips: { titulo: string; badges: ReturnType<typeof modCatBadges>; proveedor: ProveedorInfo | null }[] = [];
  const conProveedor = (tarifaTitulo: any) =>
      proveedorPorTarifa.value.get(`${servicioId}::${contenidoEs(tarifaTitulo)}`) ?? null;

  if (l.tarifas.length) {
    for (const t of l.tarifas as PaxTarifaFinanciera[]) {
      const titulo = store.traducir(t.tarifaTitulo);
      const badges = modCatBadges(t.modalidad, t.categoria);
      const proveedor = conProveedor(t.tarifaTitulo);
      if (titulo || badges.length || proveedor) chips.push({ titulo, badges, proveedor });
    }
  } else {
    const badges = modCatBadges(l.modalidad, l.categoria);
    const titulo = store.traducir(l.tarifaTitulo);
    const proveedor = conProveedor(l.tarifaTitulo);
    if (titulo || badges.length || proveedor) chips.push({ titulo, badges, proveedor });
  }
  return chips;
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
  return cfc ? { soles: cfc.resumenGeneral.incluido.ventaSoles, dolares: cfc.resumenGeneral.incluido.ventaDolares } : null;
});

// ── Opciones alternativas (upgrades/downgrades con delta de venta) ───────────
const upgrades = computed(() => store.cotizacion?.clasificacionFinancieraCliente?.opcionesUpgrade ?? []);
const tipoCambio = computed(() => store.cotizacion?.clasificacionFinancieraCliente?.tipoCambio ?? 0);

/** Los deltas vienen en USD → convertir a la moneda en vista (valor absoluto formateado) */
const mvDelta = (deltaUsd: number) => {
  const abs = Math.abs(deltaUsd);
  return monedaVista.value === 'PEN' && tipoCambio.value
      ? `S/ ${n2(abs * tipoCambio.value)}`
      : `$ ${n2(abs)}`;
};
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
              <span class="truncate max-w-35 sm:max-w-none">{{ store.file?.nombreGrupo }}</span>
            </button>

            <div class="flex items-center gap-2 shrink-0">
              <span class="px-2.5 py-1 rounded-lg bg-[#E07845] text-white text-[10px] font-black uppercase tracking-widest shadow-sm">
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
                {{ maestroStore.t('cot_tu_itinerario') || 'Tu itinerario' }}
              </h1>
              <p class="text-white/70 text-xs font-bold mt-1 uppercase tracking-widest">
                {{ totalDiasViaje }} {{ maestroStore.t('cot_dias') || 'días' }}
                · {{ store.cotizacion.numPax }} {{ maestroStore.t('cot_pax') || 'pax' }}
              </p>
            </div>

            <!-- Resumen financiero colapsado: vive en el header -->
            <button
                v-if="store.precioVisible && totalViaje"
                @click="finanzasAbiertas = !finanzasAbiertas"
                class="shrink-0 bg-white/5 hover:bg-white/10 backdrop-blur-sm border rounded-2xl px-4 py-2.5 text-right transition-all"
                :class="finanzasAbiertas ? 'border-emerald-300/60 bg-white/20' : 'border-white/20'"
            >
              <span class="text-[8px] font-black text-white/60 uppercase tracking-widest flex items-center justify-end gap-1.5">
                <i class="fas fa-sack-dollar text-emerald-300"></i>
                {{ maestroStore.t('cot_precio_total') || 'Precio total del viaje' }}
              </span>
              <span class="text-lg md:text-2xl font-black tabular-nums leading-tight flex items-center justify-end gap-2">
                {{ mv(totalViaje.soles, totalViaje.dolares) }}
                <i
                    class="fas fa-chevron-down text-xs text-emerald-300 transition-transform"
                    :class="finanzasAbiertas ? 'rotate-180' : ''"
                ></i>
              </span>
            </button>
          </div>
        </div>
      </header>

      <!-- ══ RESUMEN FINANCIERO EXPANDIDO: divide el header y el menú de días ══ -->
      <section
          v-if="finanzasAbiertas && store.precioVisible"
          class="bg-emerald-50 border-b border-emerald-200 shadow-inner"
      >
        <div class="max-w-3xl mx-auto px-4 py-6">

          <!-- Switch de moneda -->
          <div class="flex items-center justify-between gap-3 mb-4">
            <h2 class="text-emerald-700/80 font-black uppercase tracking-[0.2em] text-[11px] flex items-center gap-2">
              <i class="fas fa-users"></i>
              {{ maestroStore.t('cot_perfil_pasajero') || 'Análisis por perfil de pasajero' }}
            </h2>
            <div class="flex items-center bg-white border border-emerald-200 rounded-xl p-1 gap-1 shadow-sm shrink-0">
              <button
                  @click="monedaVista = 'PEN'"
                  :class="monedaVista === 'PEN' ? 'bg-emerald-600 text-white shadow' : 'text-slate-400 hover:text-slate-600'"
                  class="px-2.5 py-1.5 rounded-lg text-[10px] font-black tracking-widest transition-all"
              >S/</button>
              <button
                  @click="monedaVista = 'USD'"
                  :class="monedaVista === 'USD' ? 'bg-emerald-600 text-white shadow' : 'text-slate-400 hover:text-slate-600'"
                  class="px-2.5 py-1.5 rounded-lg text-[10px] font-black tracking-widest transition-all"
              >$</button>
            </div>
          </div>

          <!-- Perfiles de pasajero (venta unitaria; el total está en el header) -->
          <div class="space-y-3">
            <div
                v-for="clase in clasesPasajeros"
                :key="clase.tipo"
                class="bg-white rounded-2xl border border-emerald-100 shadow-sm p-4 md:p-5"
            >
              <div class="flex items-center justify-between gap-4">
                <div>
                  <span class="inline-block px-3 py-1 rounded-lg bg-emerald-100 text-emerald-700 text-[11px] font-black uppercase tracking-widest mb-1.5">
                    {{ clase.cantidad }}x {{ clase.tipoPaxNombre }}
                  </span>
                  <p class="text-xs font-black text-[#376875] bg-[#376875]/6 border border-[#376875]/10 rounded-lg px-2.5 py-1 inline-block">
                    <i class="fas fa-user-clock mr-1 text-[#E07845]"></i>{{ rangoEdadLabel(clase) }}
                  </p>
                </div>
                <div class="text-right shrink-0">
                  <p class="text-[9px] font-black text-slate-400 uppercase tracking-widest mb-1">
                    {{ maestroStore.t('cot_venta_unit') || 'Venta unit.' }}
                  </p>
                  <p class="text-xl md:text-2xl font-black text-gray-800 tabular-nums leading-none">
                    {{ mv(clase.resumenPorModo.normal.ventaSoles, clase.resumenPorModo.normal.ventaDolares) }}
                  </p>
                  <p class="text-[10px] font-bold text-slate-400 mt-1 tabular-nums">
                    × {{ clase.cantidad }} {{ maestroStore.t('cot_pax') || 'pax' }}
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

          <!-- ── Opciones alternativas (upgrades / downgrades) ── -->
          <div v-if="upgrades.length" class="mt-6">
            <h2 class="text-emerald-700/80 font-black uppercase tracking-[0.2em] text-[11px] flex items-center gap-2 mb-3">
              <i class="fas fa-shuffle"></i>
              {{ maestroStore.t('cot_opciones_alternativas') || 'Opciones alternativas' }}
            </h2>

            <div class="space-y-3">
              <div
                  v-for="(up, ui) in upgrades"
                  :key="ui"
                  class="bg-white rounded-2xl border border-emerald-100 shadow-sm p-4 md:p-5"
              >
                <div class="flex items-start justify-between gap-4">
                  <div class="min-w-0">
                    <p class="text-[9px] font-black text-slate-400 uppercase tracking-widest mb-0.5">
                      {{ store.traducir(up.servicioNombre) }}
                    </p>
                    <p class="text-sm font-black text-gray-800 leading-snug">
                      {{ store.traducir(up.componenteNombre) }}
                    </p>
                    <div class="mt-1.5 flex flex-wrap items-center gap-1.5">
                      <span class="text-[10px] font-semibold text-slate-500 bg-slate-50 border border-slate-200/80 rounded-md px-1.5 py-0.5">
                        {{ store.traducir(up.tarifaTitulo) }}
                      </span>
                      <span
                          v-for="b in modCatBadges(up.modalidad, up.categoria)"
                          :key="b.key"
                          class="inline-flex items-center gap-1 text-[8px] font-black px-1.5 py-0.5 rounded-md border uppercase tracking-wider"
                          :class="b.cls"
                      >
                        {{ b.icon }} {{ b.label }}
                      </span>
                    </div>
                  </div>

                  <!-- Delta de venta (negativo = descuento, positivo = adicional) -->
                  <div class="text-right shrink-0">
                    <span
                        class="inline-flex flex-col items-end rounded-xl px-3 py-1.5"
                        :class="up.deltaVentaTotal < 0
                          ? 'bg-emerald-100 text-emerald-700'
                          : 'bg-[#E07845]/10 text-[#E07845]'"
                    >
                      <span class="text-[8px] font-black uppercase tracking-widest opacity-80">
                        <i class="fas mr-0.5" :class="up.deltaVentaTotal < 0 ? 'fa-arrow-trend-down' : 'fa-arrow-trend-up'"></i>
                        {{ up.deltaVentaTotal < 0
                          ? (maestroStore.t('cot_descuento') || 'Descuento')
                          : (maestroStore.t('cot_adicional') || 'Adicional') }}
                      </span>
                      <span class="text-sm font-black tabular-nums leading-tight">{{ mvDelta(up.deltaVentaTotal) }}</span>
                    </span>
                    <p class="text-[9px] font-bold text-slate-400 mt-1 tabular-nums">
                      {{ mvDelta(up.deltaVentaPorPax) }} {{ maestroStore.t('cot_por_persona') || 'c/u' }}
                    </p>
                  </div>
                </div>

                <!-- Nota de la alternativa -->
                <p
                    v-if="up.notaRol?.length"
                    class="mt-2.5 text-[11px] font-medium text-slate-500 bg-slate-50 border border-slate-100 rounded-xl px-3 py-2 italic"
                >
                  <i class="fas fa-circle-info mr-1 text-slate-400 not-italic"></i>
                  {{ store.traducir(up.notaRol) }}
                </p>
              </div>
            </div>
          </div>

          <!-- Pie: pax/días + adelanto + cerrar -->
          <div class="mt-4 flex flex-col sm:flex-row items-start sm:items-center justify-between gap-3">
            <p class="text-emerald-700/70 text-[11px] font-bold">
              {{ store.cotizacion.numPax }} {{ maestroStore.t('cot_pasajeros') || 'pasajeros' }}
              · {{ totalDiasViaje }} {{ maestroStore.t('cot_dias') || 'días' }}
            </p>
            <div class="flex items-center gap-3">
              <div
                  v-if="Number(store.cotizacion.adelanto) > 0"
                  class="bg-white rounded-2xl border border-emerald-100 shadow-sm px-4 py-2.5"
              >
                <p class="text-[9px] font-black text-slate-400 uppercase tracking-widest mb-0.5">
                  {{ maestroStore.t('cot_adelanto') || 'Adelanto' }}
                </p>
                <p class="text-base font-black text-gray-800 tabular-nums leading-none">
                  {{ store.cotizacion.monedaGlobal }} {{ store.cotizacion.adelanto }}
                </p>
              </div>
              <button
                  @click="finanzasAbiertas = false"
                  class="w-9 h-9 rounded-full bg-white border border-emerald-200 text-emerald-600 hover:bg-emerald-100 flex items-center justify-center shadow-sm transition-colors"
                  :aria-label="maestroStore.t('cot_cerrar') || 'Cerrar'"
              >
                <i class="fas fa-chevron-up text-sm"></i>
              </button>
            </div>
          </div>
        </div>
      </section>

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
              <h2 class="text-lg md:text-xl font-black text-gray-800 capitalize leading-tight">
                {{ formatearFecha(dia.fecha) }}
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
              <span>{{ store.traducir(item.servicio.nombrePublicoSnapshot) }}</span>
            </h3>

            <!-- Fila de acción: botón que abre el modal con las inclusiones del servicio completo.
                 Va entre el <h3> (multi-segmento) y la card, o encima de la card (single) → simetría. -->
            <div
                v-if="item.mostrarAccionInclusiones"
                class="flex justify-end mb-3"
            >
              <button
                  @click="abrirInclusiones(item.servicio.id, item.servicio.nombrePublicoSnapshot)"
                  class="inline-flex items-center gap-2 text-[11px] font-black uppercase tracking-wider text-[#376875] bg-white border border-[#376875]/20 hover:border-[#376875]/50 hover:bg-[#376875]/5 rounded-xl px-3.5 py-2 shadow-sm transition-colors"
              >
                <i class="fas fa-list-check text-[#E07845]"></i>
                {{ item.totalSegmentosServicio > 1
                  ? (maestroStore.t('cot_inclusiones_tour') || 'Inclusiones del tour')
                  : (maestroStore.t('cot_inclusiones_servicio') || 'Inclusiones del servicio') }}
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
                  {{ store.traducir(item.servicio.nombrePublicoSnapshot) }}
                  <span class="normal-case text-slate-400 font-bold">· {{ maestroStore.t('cot_noche') || 'Noche' }} {{ item.noche }}/{{ item.totalNoches }}</span>
                </p>
                <p class="font-black text-gray-800 text-sm leading-snug">
                  {{ store.traducir(item.segmento.nombreSnapshot) }}
                </p>
              </div>
            </article>

            <!-- ── Card completa ── -->
            <article
                v-else
                class="bg-white rounded-4xl shadow-xl shadow-slate-200/50 border border-slate-100 overflow-hidden mb-6"
            >
              <!-- Galería de imágenes (desplazable) -->
              <div v-if="imagenesDe(item.segmento).length" class="h-48 md:h-64 relative overflow-hidden" data-galeria>
                <div class="galeria-track flex h-full overflow-x-auto snap-x snap-mandatory no-scrollbar">
                  <img
                      v-for="(img, ii) in imagenesDe(item.segmento)"
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
                    {{ store.traducir(item.servicio.nombrePublicoSnapshot) }}
                  </p>
                  <h4 class="text-white text-lg md:text-xl font-black leading-tight drop-shadow-md">
                    {{ store.traducir(item.segmento.nombreSnapshot) }}
                  </h4>
                </div>
              </div>

              <div class="p-5 md:p-7">
                <!-- Encabezado (solo si no hubo galería) -->
                <div v-if="!imagenesDe(item.segmento).length" class="flex items-start justify-between gap-3">
                  <div class="min-w-0">
                    <p v-if="!item.mostrarTituloServicio" class="text-[#376875]/60 text-[10px] font-black uppercase tracking-widest mb-1">
                      {{ store.traducir(item.servicio.nombrePublicoSnapshot) }}
                    </p>
                    <h4 class="text-gray-800 text-base md:text-lg font-black leading-tight mb-3">
                      {{ store.traducir(item.segmento.nombreSnapshot) }}
                    </h4>
                  </div>
                  <!-- Rango horario del segmento (derivado de componentes) -->
                  <span
                      v-if="item.horaInicio"
                      class="shrink-0 inline-flex items-center gap-2 text-sm font-black text-white bg-[#E07845] rounded-xl px-3.5 py-2 tabular-nums whitespace-nowrap shadow-md shadow-[#E07845]/30"
                  >
                    <i class="far fa-clock"></i>
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

                <!-- Contenido narrativo (truncable) -->
                <div class="relative">
                  <div
                      class="prose prose-sm max-w-none text-slate-600 prose-strong:text-[#376875] prose-a:text-[#E07845] prose-p:leading-relaxed transition-all"
                      :class="descEsLarga(item.segmento) && !descExpandida.has(item.key) ? 'max-h-36 overflow-hidden' : ''"
                      v-html="store.traducir(item.segmento.contenidoSnapshot)"
                  />
                  <div
                      v-if="descEsLarga(item.segmento) && !descExpandida.has(item.key)"
                      class="absolute inset-x-0 bottom-0 h-14 bg-linear-to-t from-white to-transparent pointer-events-none"
                  ></div>
                </div>
                <button
                    v-if="descEsLarga(item.segmento)"
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
                    <span class="truncate">{{ store.traducir(c.nombreSnapshot) || store.traducir(item.segmento.nombreSnapshot) }}</span>
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
                  <div
                      class="px-4 pb-4 prose prose-sm max-w-none text-amber-900/80 prose-p:my-1 prose-p:leading-relaxed"
                      v-html="store.traducir(nota.contenido)"
                  />
                </details>
              </div>
            </article>
          </template>

          <!-- ══ INCLUSIONES DEL DÍA (panel único, elegante, semicolapsado) ══ -->
          <div
              v-if="inclusionesPorDia.get(dia.fecha)?.servicios.length"
              class="bg-white rounded-3xl shadow-md shadow-slate-200/40 border border-slate-100 p-5 md:p-7 mb-4"
          >
            <p class="text-xs font-black text-[#376875] uppercase tracking-[0.15em] flex items-center gap-2 mb-5">
              <i class="fas fa-list-check text-[#E07845]"></i>
              {{ maestroStore.t('cot_inclusiones_dia') || 'Inclusiones del día' }}
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
                              {{ store.traducir(l.nombre) }}
                              <b v-if="l.cantidadComponente > 1" class="text-[#376875] font-black">×{{ l.cantidadComponente }}</b>
                              <span class="text-[10px] font-medium text-slate-400 ml-1.5 whitespace-nowrap capitalize">
                                · {{ fechaChip(l.fecha) }}
                              </span>
                            </span>
                          </p>

                          <!-- Chips: tarifa + badges + proveedor -->
                          <div
                              v-for="(chip, ci) in chipsDeLinea(l, inc.servicioId)"
                              :key="ci"
                              class="ml-6 mt-1 flex flex-wrap items-center gap-1.5"
                          >
                            <span
                                v-if="chip.titulo"
                                class="text-[10px] font-semibold text-slate-500 bg-slate-50 border border-slate-200/80 rounded-md px-1.5 py-0.5"
                            >
                              {{ chip.titulo }}
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
                      {{ store.traducir(l.nombre) }}
                      <b v-if="l.cantidadComponente > 1" class="text-[#376875] font-black">×{{ l.cantidadComponente }}</b>
                      <span class="text-[10px] font-medium text-slate-400 ml-1.5 whitespace-nowrap capitalize">
                        · {{ fechaChip(l.fecha) }}
                      </span>
                    </span>
                  </p>

                  <!-- Chips: tarifa + badges + proveedor -->
                  <div
                      v-for="(chip, ci) in chipsDeLinea(l, modalInclusiones?.servicioId ?? '')"
                      :key="ci"
                      class="ml-6 mt-1 flex flex-wrap items-center gap-1.5"
                  >
                    <span
                        v-if="chip.titulo"
                        class="text-[10px] font-semibold text-slate-500 bg-slate-50 border border-slate-200/80 rounded-md px-1.5 py-0.5"
                    >
                      {{ chip.titulo }}
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
</style>