<script setup lang="ts">
/**
 * src/views/cotizacion/PaxCotizacionGuiaView.vue
 * Ruta: /file/:localizador/v/:version — guía visual día a día de una propuesta.
 *
 * Mobile-first. Estructura:
 *   1. Header compacto (volver, chip versión, idioma)
 *   2. Day-nav sticky (chips Día 1..N con scroll-spy)
 *   3. Capítulos por día (cover, contenido prose, detalles de vuelo, notas)
 *   4. Incluye / No incluye por excursión (estilo checklist)
 *   5. Análisis por perfil de pasajero (versión cliente: solo venta)
 *   6. Total del viaje
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
    await nextTick();
    montarObserver();
  } catch (error) {
    console.error('Error en carga inicial:', error);
  } finally {
    isReady.value = true;
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
      { rootMargin: '-30% 0px -60% 0px' }
  );
  document.querySelectorAll<HTMLElement>('[data-dia]').forEach(el => observer!.observe(el));
};

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

// ── Helpers de formato ───────────────────────────────────────────────────────
const formatearFecha = (iso: string) =>
    new Date(iso.substring(0, 10) + 'T00:00:00').toLocaleDateString(maestroStore.idiomaActual, {
      weekday: 'long', day: 'numeric', month: 'long', timeZone: 'America/Lima',
    });

const fechaChip = (iso: string) =>
    new Date(iso.substring(0, 10) + 'T00:00:00').toLocaleDateString(maestroStore.idiomaActual, {
      day: '2-digit', month: 'short', timeZone: 'America/Lima',
    });

const portadaDe = (imgs: { imageUrl: string; isPortada: boolean }[]) =>
    imgs.find(i => i.isPortada)?.imageUrl ?? imgs[0]?.imageUrl ?? null;

// ── Badges de modalidad / categoría (espejo capado del reporte interno) ──────
const MODALIDAD_UI: Record<string, { icon: string; label: string }> = {
  privado:    { icon: '🔒', label: 'Privado' },
  compartido: { icon: '👥', label: 'Compartido' },
};
const CATEGORIA_UI: Record<string, { icon: string; label: string }> = {
  superior: { icon: '✨', label: 'Superior' },
  estandar: { icon: '🏷️', label: 'Estándar' },
  lujo:     { icon: '👑', label: 'Lujo' },
};

const modCatBadges = (modalidad?: string | null, categoria?: string | null) => {
  const b: { key: string; icon: string; label: string; cls: string }[] = [];
  if (modalidad && MODALIDAD_UI[modalidad]) {
    b.push({ key: 'mod', ...MODALIDAD_UI[modalidad], cls: 'bg-sky-50 text-sky-700 border-sky-200' });
  }
  if (categoria) {
    const c = CATEGORIA_UI[categoria] ?? { icon: '✨', label: categoria };
    b.push({ key: 'cat', ...c, cls: 'bg-purple-50 text-purple-700 border-purple-200' });
  }
  return b;
};

// ── Inclusiones por excursión (versión cliente: sin montos) ─────────────────
const seccionesInclusion = (srv: { incluidos: PaxInclusionItem[]; noIncluidos: PaxInclusionItem[]; cortesias: PaxInclusionItem[]; opcionales: PaxInclusionItem[] }) => ([
  { key: 'incluidos',   titulo: maestroStore.t('cot_incluye')    || 'Incluye',     icono: 'fa-check-circle text-emerald-500', lineas: srv.incluidos },
  { key: 'noIncluidos', titulo: maestroStore.t('cot_no_incluye') || 'No incluye',  icono: 'fa-times-circle text-red-400',     lineas: srv.noIncluidos },
  { key: 'cortesias',   titulo: maestroStore.t('cot_cortesia')   || 'Cortesía',    icono: 'fa-gift text-sky-500',             lineas: srv.cortesias },
  { key: 'opcionales',  titulo: maestroStore.t('cot_opcional')   || 'Opcional',    icono: 'fa-circle-question text-amber-500', lineas: srv.opcionales },
].filter(s => s.lineas.length > 0));

/** Chips de tarifa de una línea de inclusión (título + modalidad/categoría, sin precios) */
const chipsDeLinea = (l: PaxInclusionItem) => {
  const chips: { titulo: string; badges: ReturnType<typeof modCatBadges> }[] = [];
  if (l.tarifas.length) {
    for (const t of l.tarifas as PaxTarifaFinanciera[]) {
      const titulo = store.traducir(t.tarifaTitulo);
      const badges = modCatBadges(t.modalidad, t.categoria);
      if (titulo || badges.length) chips.push({ titulo, badges });
    }
  } else {
    const badges = modCatBadges(l.modalidad, l.categoria);
    const titulo = store.traducir(l.tarifaTitulo);
    if (titulo || badges.length) chips.push({ titulo, badges });
  }
  return chips;
};

// ── Anclar inclusiones a su servicio dentro del itinerario ──────────────────
/** Inclusiones indexadas por servicioId */
const inclusionPorServicio = computed(() => {
  const m = new Map<string, (typeof store.inclusiones)[number]>();
  for (const srv of store.inclusiones) m.set(srv.servicioId, srv);
  return m;
});

/** Último segmento (en orden del itinerario) de cada servicio: ahí se muestra su bloque */
const ultimoSegmentoPorServicio = computed(() => {
  const m = new Map<string, string>();
  for (const dia of store.itinerario) {
    for (const item of dia.segmentos) {
      m.set(item.servicio.id, item.segmento.id);
    }
  }
  return m;
});

/** Devuelve el bloque de inclusiones si este item es el último segmento de su servicio */
const inclusionDeItem = (item: { servicio: { id: string }; segmento: { id: string } }) => {
  if (ultimoSegmentoPorServicio.value.get(item.servicio.id) !== item.segmento.id) return null;
  const srv = inclusionPorServicio.value.get(item.servicio.id);
  return srv && seccionesInclusion(srv).length ? srv : null;
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
              <span class="truncate max-w-[140px] sm:max-w-none">{{ store.file?.nombreGrupo }}</span>
            </button>

            <div class="flex items-center gap-2 flex-shrink-0">
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

          <h1 class="text-2xl md:text-4xl font-black tracking-tight leading-tight">
            {{ maestroStore.t('cot_tu_itinerario') || 'Tu itinerario' }}
          </h1>
          <p class="text-white/70 text-xs font-bold mt-1 uppercase tracking-widest">
            {{ store.itinerario.length }} {{ maestroStore.t('cot_dias') || 'días' }}
            · {{ store.cotizacion.numPax }} pax
          </p>
        </div>
      </header>

      <!-- Day-nav sticky -->
      <nav class="sticky top-0 z-30 bg-[#F8FAFC]/95 backdrop-blur-sm border-b border-slate-200/60 shadow-sm">
        <div class="max-w-3xl mx-auto px-4 py-2.5 flex gap-2 overflow-x-auto no-scrollbar">
          <button
              v-for="dia in store.itinerario"
              :key="dia.fecha"
              @click="irADia(dia.numeroDia)"
              class="flex-shrink-0 px-3.5 py-1.5 rounded-xl text-[11px] font-black uppercase tracking-wider transition-all"
              :class="diaActivo === dia.numeroDia
                ? 'bg-[#376875] text-white shadow-md shadow-[#376875]/20'
                : 'bg-white text-[#376875]/60 border border-slate-200 hover:border-[#376875]/40'"
          >
            {{ maestroStore.t('cot_dia') || 'Día' }} {{ dia.numeroDia }}
          </button>
        </div>
      </nav>

      <main class="max-w-3xl mx-auto px-4 pb-16">

        <!-- ══ CAPÍTULOS POR DÍA ══ -->
        <section
            v-for="dia in store.itinerario"
            :key="dia.fecha"
            :id="`dia-${dia.numeroDia}`"
            :data-dia="dia.numeroDia"
            class="pt-8 scroll-mt-16"
        >
          <!-- Título del día -->
          <div class="flex items-center gap-3 mb-5">
            <span class="w-12 h-12 rounded-2xl bg-[#376875] text-white flex flex-col items-center justify-center flex-shrink-0 shadow-lg shadow-[#376875]/20">
              <span class="text-[8px] font-black uppercase leading-none opacity-70">{{ maestroStore.t('cot_dia') || 'Día' }}</span>
              <span class="text-lg font-black leading-none">{{ dia.numeroDia }}</span>
            </span>
            <div class="min-w-0">
              <h2 class="text-lg md:text-xl font-black text-gray-800 capitalize leading-tight">
                {{ formatearFecha(dia.fecha) }}
              </h2>
              <p class="text-[10px] font-bold text-[#376875]/50 uppercase tracking-widest">
                {{ dia.segmentos.length }} {{ dia.segmentos.length === 1 ? (maestroStore.t('cot_actividad') || 'actividad') : (maestroStore.t('cot_actividades') || 'actividades') }}
              </p>
            </div>
          </div>

          <!-- Segmentos del día -->
          <article
              v-for="item in dia.segmentos"
              :key="item.segmento.id"
              class="bg-white rounded-[2rem] shadow-xl shadow-slate-200/50 border border-slate-100 overflow-hidden mb-6"
          >
            <!-- Cover -->
            <div v-if="portadaDe(item.segmento.imagenesSnapshot)" class="h-48 md:h-64 relative overflow-hidden">
              <img
                  :src="portadaDe(item.segmento.imagenesSnapshot)!"
                  class="w-full h-full object-cover"
                  loading="lazy"
              />
              <div class="absolute inset-0 bg-gradient-to-t from-black/60 via-transparent to-transparent"></div>
              <div class="absolute bottom-0 left-0 p-5 md:p-6">
                <p class="text-white/80 text-[10px] font-black uppercase tracking-widest mb-1 drop-shadow">
                  {{ store.traducir(item.servicio.nombrePublicoSnapshot) }}
                </p>
                <h3 class="text-white text-xl md:text-2xl font-black leading-tight drop-shadow-md">
                  {{ store.traducir(item.segmento.nombreSnapshot) }}
                </h3>
              </div>
            </div>

            <div class="p-5 md:p-7">
              <!-- Título (solo si no hubo cover) -->
              <template v-if="!portadaDe(item.segmento.imagenesSnapshot)">
                <p class="text-[#376875]/60 text-[10px] font-black uppercase tracking-widest mb-1">
                  {{ store.traducir(item.servicio.nombrePublicoSnapshot) }}
                </p>
                <h3 class="text-gray-800 text-lg md:text-xl font-black leading-tight mb-3">
                  {{ store.traducir(item.segmento.nombreSnapshot) }}
                </h3>
              </template>

              <!-- Contenido narrativo -->
              <div
                  class="prose prose-sm max-w-none text-slate-600 prose-strong:text-[#376875] prose-a:text-[#E07845] prose-p:leading-relaxed"
                  v-html="store.traducir(item.segmento.contenidoSnapshot)"
              />

              <!-- Detalles operativos para el cliente (vuelos, recojos) -->
              <template v-for="comp in item.componentes" :key="comp.id">
                <div
                    v-for="det in comp.detallesParaCliente"
                    :key="det.id"
                    class="mt-4 flex items-start gap-3 bg-[#376875]/[0.04] border border-[#376875]/10 rounded-2xl px-4 py-3"
                >
                  <i class="fas fa-circle-info text-[#E07845] mt-0.5 flex-shrink-0"></i>
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

              <!-- ── Incluye / No incluye de la excursión (se ancla al último segmento del servicio) ── -->
              <div
                  v-if="inclusionDeItem(item)"
                  class="mt-6 pt-5 border-t border-dashed border-slate-200 space-y-5"
              >
                <p class="text-[10px] font-black text-[#376875]/50 uppercase tracking-[0.2em] flex items-center gap-2">
                  <i class="fas fa-list-check"></i>
                  {{ maestroStore.t('cot_detalle_servicio') || 'Detalle del servicio' }}
                </p>

                <div v-for="sec in seccionesInclusion(inclusionDeItem(item)!)" :key="sec.key">
                  <p class="text-[10px] font-black text-slate-400 uppercase tracking-[0.2em] mb-2.5">{{ sec.titulo }}</p>
                  <ul class="space-y-2.5">
                    <li v-for="(l, i) in sec.lineas" :key="i">
                      <!-- Línea principal: icono + nombre + xN + fecha chip -->
                      <p class="flex items-start gap-2.5">
                        <i class="fas mt-1 flex-shrink-0" :class="sec.icono"></i>
                        <span class="text-[15px] font-bold text-gray-800 leading-snug">
                          {{ store.traducir(l.nombre) }}
                          <b v-if="l.cantidadComponente > 1" class="text-[#376875]">x {{ l.cantidadComponente }}</b>
                          <span class="inline-block text-[10px] font-bold text-slate-400 bg-slate-50 border border-slate-200 rounded-md px-2 py-0.5 ml-1.5 align-middle whitespace-nowrap capitalize">
                            {{ fechaChip(l.fecha) }}
                          </span>
                        </span>
                      </p>

                      <!-- Chips: título de tarifa + modalidad/categoría (sin montos) -->
                      <div
                          v-for="(chip, ci) in chipsDeLinea(l)"
                          :key="ci"
                          class="ml-7 mt-1.5 flex flex-wrap items-center gap-1.5"
                      >
                        <span
                            v-if="chip.titulo"
                            class="text-[11px] font-bold text-slate-600 bg-slate-50 border border-slate-200 rounded-lg px-2 py-1"
                        >
                          {{ chip.titulo }}
                        </span>
                        <span
                            v-for="b in chip.badges"
                            :key="b.key"
                            class="inline-flex items-center gap-1 text-[9px] font-black px-2 py-1 rounded-lg border uppercase tracking-wider"
                            :class="b.cls"
                        >
                          {{ b.icon }} {{ b.label }}
                        </span>
                      </div>
                    </li>
                  </ul>
                </div>
              </div>
            </div>
          </article>

          <!-- Pie tipo libro -->
          <div class="flex justify-between gap-2 mb-2">
            <button
                v-if="dia.numeroDia > 1"
                @click="irADia(dia.numeroDia - 1)"
                class="text-[11px] font-black uppercase tracking-widest text-[#376875]/50 hover:text-[#376875] transition-colors"
            >
              ← {{ maestroStore.t('cot_dia') || 'Día' }} {{ dia.numeroDia - 1 }}
            </button>
            <span v-else></span>
            <button
                v-if="dia.numeroDia < store.itinerario.length"
                @click="irADia(dia.numeroDia + 1)"
                class="text-[11px] font-black uppercase tracking-widest text-[#E07845] hover:text-[#D06535] transition-colors"
            >
              {{ maestroStore.t('cot_dia') || 'Día' }} {{ dia.numeroDia + 1 }} →
            </button>
          </div>
        </section>

        <!-- ══ ANÁLISIS POR PERFIL DE PASAJERO (versión cliente) ══ -->
        <section v-if="store.precioVisible && clasesPasajeros.length" class="pt-12">
          <div class="flex items-center justify-between gap-3 mb-6">
            <h2 class="text-[#376875]/60 font-black uppercase tracking-[0.2em] text-[11px] flex items-center gap-2">
              <i class="fas fa-users"></i>
              {{ maestroStore.t('cot_perfil_pasajero') || 'Análisis por perfil de pasajero' }}
            </h2>

            <!-- Switch de moneda -->
            <div class="flex items-center bg-white border border-slate-200 rounded-xl p-1 gap-1 shadow-sm flex-shrink-0">
              <button
                  @click="monedaVista = 'PEN'"
                  :class="monedaVista === 'PEN' ? 'bg-[#376875] text-white shadow' : 'text-slate-400 hover:text-slate-600'"
                  class="px-2.5 py-1.5 rounded-lg text-[10px] font-black tracking-widest transition-all"
              >S/</button>
              <button
                  @click="monedaVista = 'USD'"
                  :class="monedaVista === 'USD' ? 'bg-[#376875] text-white shadow' : 'text-slate-400 hover:text-slate-600'"
                  class="px-2.5 py-1.5 rounded-lg text-[10px] font-black tracking-widest transition-all"
              >$</button>
            </div>
          </div>

          <div class="space-y-4">
            <div
                v-for="clase in clasesPasajeros"
                :key="clase.tipo"
                class="bg-white rounded-[2rem] shadow-xl shadow-slate-200/50 border border-slate-100 p-5 md:p-7"
            >
              <div class="flex items-start justify-between gap-4">
                <div>
                  <span class="inline-block px-3 py-1 rounded-lg bg-emerald-50 text-emerald-700 text-[11px] font-black uppercase tracking-widest mb-2">
                    {{ clase.cantidad }}x {{ clase.tipoPaxNombre }}
                  </span>
                  <p class="text-sm font-bold text-gray-700">{{ rangoEdadLabel(clase) }}</p>
                </div>
                <div class="text-right flex-shrink-0">
                  <p class="text-[9px] font-black text-slate-400 uppercase tracking-widest mb-1">
                    {{ maestroStore.t('cot_venta_unit') || 'Venta unit.' }}
                  </p>
                  <p class="text-2xl md:text-3xl font-black text-gray-800 tabular-nums leading-none">
                    {{ mv(clase.resumenPorModo.normal.ventaSoles, clase.resumenPorModo.normal.ventaDolares) }}
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
                {{ mv(clase.resumenPorModo.cortesia.ventaSoles, clase.resumenPorModo.cortesia.ventaDolares) }}
              </p>
            </div>
          </div>
        </section>

        <!-- ══ TOTAL DEL VIAJE ══ -->
        <section v-if="store.precioVisible && totalViaje" class="pt-10">
          <div class="bg-[#376875] rounded-[2.5rem] shadow-xl shadow-[#376875]/20 p-6 md:p-10 text-white relative overflow-hidden">
            <div class="absolute inset-0 opacity-10" style="background-image: radial-gradient(#ffffff 1px, transparent 1px); background-size: 24px 24px;"></div>
            <div class="relative z-10 flex flex-col sm:flex-row items-start sm:items-center justify-between gap-4">
              <div>
                <p class="text-[10px] font-black text-white/60 uppercase tracking-[0.2em] mb-1">
                  {{ maestroStore.t('cot_precio_total') || 'Precio total del viaje' }}
                </p>
                <p class="text-3xl md:text-5xl font-black tabular-nums leading-none">
                  {{ mv(totalViaje.soles, totalViaje.dolares) }}
                </p>
                <p class="text-white/60 text-[11px] font-bold mt-2">
                  {{ store.cotizacion.numPax }} {{ maestroStore.t('cot_pasajeros') || 'pasajeros' }}
                  · {{ store.itinerario.length }} {{ maestroStore.t('cot_dias') || 'días' }}
                </p>
              </div>

              <div
                  v-if="Number(store.cotizacion.adelanto) > 0"
                  class="bg-white/10 backdrop-blur-sm rounded-2xl border border-white/10 px-5 py-4"
              >
                <p class="text-[9px] font-black text-white/60 uppercase tracking-widest mb-1">
                  {{ maestroStore.t('cot_adelanto') || 'Adelanto' }}
                </p>
                <p class="text-xl font-black tabular-nums">
                  {{ store.cotizacion.monedaGlobal }} {{ store.cotizacion.adelanto }}
                </p>
              </div>
            </div>
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
    </template>
  </div>
</template>

<style scoped>
/* Oculta la scrollbar del day-nav manteniendo el scroll horizontal */
.no-scrollbar::-webkit-scrollbar { display: none; }
.no-scrollbar { -ms-overflow-style: none; scrollbar-width: none; }
</style>