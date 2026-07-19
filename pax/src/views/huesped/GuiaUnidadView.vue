<script setup lang="ts">
/* src/views/huesped/GuiaUnidadView.vue */
import { ref, onMounted, watch, computed, nextTick } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { usePmsGuiaStore } from '@/stores/huesped/paxHuespedGuiaStore.ts';
import { useMaestroStore } from '@/stores/maestroStore';
import type { PmsGuiaSeccion, PmsGuiaItem } from '@/types/paxHuespedModel.ts';
import GuiaUnidadItemDispatcher from '@/components/GuiaUnidad/GuiaUnidadItemDispatcher.vue';

const props = defineProps<{
  mode: 'public' | 'guest';
}>();

const route = useRoute();
const router = useRouter();
const store = usePmsGuiaStore();
const maestroStore = useMaestroStore();

const homeScroll = ref<HTMLElement | null>(null);

const cargarTodo = async () => {
  let idTarget = '';
  if (props.mode === 'guest') {
    idTarget = route.params.uuidEvento as string;
  } else {
    idTarget = route.params.uuidUnidad as string;
  }

  if (idTarget) {
    await store.cargarDatosCompletos(idTarget, props.mode);
    if (route.query.section) setTimeout(smoothResetScroll, 100);
  }
};

const getFirstName = computed(() => {
  const nombre = store.helperContext?.data?.text_fixed?.guest_name || 'Viajero';
  return nombre.split(' ')[0];
});

const getUnitName = computed(() => {
  return store.helperContext?.data?.text_fixed?.unit_name || 'Unidad';
});

const heroImage = computed(() => {
  return store.guia?.unidad?.imageUrl || null;
});

/* ─────────────────────────────────────────────────────────────
 * CONFIG VISUAL DE SECCIONES (color / subtítulo por tipo)
 * ───────────────────────────────────────────────────────────── */
type SeccionColor = { color: string; bg: string };

// Color por tipo de sección; el resto cae a una paleta de respaldo.
const TIPO_COLOR: Record<string, SeccionColor> = {
  ingreso:     { color: '#376875', bg: '#376875' },
  descriptivo: { color: '#854F0B', bg: '#FAEEDA' },
  normas:      { color: '#3C3489', bg: '#EEEDFE' },
};

const PALETA_FALLBACK: SeccionColor[] = [
  { color: '#0F6E56', bg: '#E1F5EE' },
  { color: '#993C1D', bg: '#FAECE7' },
  { color: '#185FA5', bg: '#E6F1FB' },
  { color: '#993556', bg: '#FBEAF0' },
];

const colorDe = (seccion: PmsGuiaSeccion, index: number): SeccionColor => {
  if (seccion.tipo && TIPO_COLOR[seccion.tipo]) return TIPO_COLOR[seccion.tipo];
  return PALETA_FALLBACK[index % PALETA_FALLBACK.length];
};

// Subtítulo real y traducible (viene del CMS).
const subtituloDe = (seccion: PmsGuiaSeccion): string => store.traducir(seccion.subtitulo);

// Ícono del ítem: el del CMS, con respaldo según su 'tipo'.
const ICONO_TIPO: Record<string, string> = {
  card:  'fa-file-lines',
  album: 'fa-images',
  alert: 'fa-triangle-exclamation',
};
const iconoItem = (item: PmsGuiaItem): string =>
    item.icono || ICONO_TIPO[item.tipo] || 'fa-circle-info';

const normalizarItems = (seccion: PmsGuiaSeccion | null): PmsGuiaItem[] => {
  if (!seccion?.items) return [];
  return Array.isArray(seccion.items) ? seccion.items : [seccion.items];
};

/* ─────────────────────────────────────────────────────────────
 * SECCIÓN DESTACADA (ingreso) + RESTO + ATAJO DESCRIPTIVO (foto)
 * ───────────────────────────────────────────────────────────── */
const seccionDestacada = computed(() =>
    store.guia?.secciones.find(s => s.tipo === 'ingreso') ?? null
);

const seccionesRestantes = computed(() =>
    store.guia?.secciones.filter(s => s !== seccionDestacada.value) ?? []
);

const seccionDescriptiva = computed(() =>
    store.guia?.secciones.find(s => s.tipo === 'descriptivo') ?? null
);

const abrirDescriptivo = () => {
  if (seccionDescriptiva.value) abrirSeccion(seccionDescriptiva.value);
};

// Preview de pasos del ingreso: primeros N + "mostrar más".
const PREVIEW_INGRESO = 3;
const mostrarTodosIngreso = ref(false);

const itemsDestacadaTodos = computed(() => normalizarItems(seccionDestacada.value));
const itemsDestacadaVisibles = computed(() =>
    mostrarTodosIngreso.value
        ? itemsDestacadaTodos.value
        : itemsDestacadaTodos.value.slice(0, PREVIEW_INGRESO)
);
const itemsOcultosCount = computed(() =>
    Math.max(0, itemsDestacadaTodos.value.length - PREVIEW_INGRESO)
);

/* ─────────────────────────────────────────────────────────────
 * ANFITRIÓN (tarjeta + WhatsApp) — solo si hay teléfono en el contexto
 * ───────────────────────────────────────────────────────────── */
const hostName = computed(() => store.helperContext?.data?.text_fixed?.host_name || '');
const hostPhone = computed(() => store.helperContext?.data?.text_fixed?.host_whatsapp || '');

const hostInitials = computed(() => {
  const parts = hostName.value.trim().split(/\s+/).filter(Boolean);
  if (!parts.length) return 'AN';
  return parts.slice(0, 2).map(p => p[0]).join('').toUpperCase();
});

const whatsappUrl = computed(() => {
  if (!hostPhone.value) return '';
  const num = hostPhone.value.replace(/[^0-9]/g, '');
  const msg = encodeURIComponent(
      maestroStore.t('gui_wa_mensaje') || `Hola, soy huésped de ${getUnitName.value}.`
  );
  return `https://wa.me/${num}?text=${msg}`;
});

/* ─────────────────────────────────────────────────────────────
 * NAVEGACIÓN DE 3 NIVELES: Home → Sección (nivel 2) → Ítem (nivel 3)
 * ───────────────────────────────────────────────────────────── */
const seccionActiva = computed<PmsGuiaSeccion | null>(() => {
  const id = route.query.section as string;
  if (!id || !store.guia) return null;
  return store.guia.secciones.find(s => s.id === id) ?? null;
});

const itemsSeccionActiva = computed(() => normalizarItems(seccionActiva.value));
const esItemUnico = computed(() => itemsSeccionActiva.value.length === 1);

const itemActivo = computed<PmsGuiaItem | null>(() => {
  const id = route.query.item as string;
  if (!id) return null;
  return itemsSeccionActiva.value.find(it => it['@id'] === id) ?? null;
});

const abrirSeccion = (seccion: PmsGuiaSeccion) => {
  router.push({ name: route.name as string, params: route.params, query: { section: seccion.id } });
};

const abrirItem = (seccion: PmsGuiaSeccion, item: PmsGuiaItem) => {
  router.push({
    name: route.name as string,
    params: route.params,
    query: { section: seccion.id, item: item['@id'] }
  });
};

// Retrocede un nivel (ítem → sección → home) respetando el historial.
const volver = () => {
  if (window.history.state?.back) { router.back(); return; }
  if (route.query.item) {
    router.replace({ name: route.name as string, params: route.params, query: { section: route.query.section as string } });
  } else {
    router.replace({ name: route.name as string, params: route.params });
  }
};

/* ─────────────────────────────────────────────────────────────
 * AVISOS / BLOQUEOS
 * ───────────────────────────────────────────────────────────── */
const showPendingWarning = computed(() => {
  return store.helperContext?.data?.config?.access_status === 'pending';
});

const unlockDateFormatted = computed(() => {
  const dateStr = store.helperContext?.data?.config?.unlock_at;
  if (!dateStr) return '--';

  const cleanDateStr = dateStr.replace('T', ' ');
  const partes = cleanDateStr.split(' ');
  if (partes.length < 2) return cleanDateStr;

  const fecha = partes[0];
  const horaCompleta = partes[1];

  const [anio, mes, dia] = fecha.split('-');
  const [hora, minutos] = horaCompleta.split(':');

  const date = new Date(
      parseInt(anio),
      parseInt(mes) - 1,
      parseInt(dia),
      parseInt(hora),
      parseInt(minutos)
  );

  return date.toLocaleString(maestroStore.idiomaActual, {
    day: '2-digit',
    month: 'long',
    hour: '2-digit',
    minute: '2-digit',
    hour12: false
  });
});

const smoothResetScroll = async () => {
  await nextTick();
  const options: ScrollToOptions = { top: 0, behavior: 'smooth' };
  document.getElementById('nivel2-scroll')?.scrollTo(options);
  document.getElementById('nivel3-scroll')?.scrollTo(options);
  if (homeScroll.value) homeScroll.value.scrollTo(options);
  window.scrollTo(options);
};

const recargar = () => { cargarTodo(); };

const irAReserva = () => {
  const loc = store.helperContext?.data?.text_fixed?.booking_ref;
  if (loc && loc !== 'DEMO') {
    router.push({ name: 'pms_reserva', params: { localizador: loc } });
  } else {
    router.back();
  }
};

watch(() => [route.query.section, route.query.item], () => {
  smoothResetScroll();
});

// 🔥 Solo recargamos cuando cambia la UNIDAD/EVENTO (no en cada navegación de
// sección o ítem). Antes se observaba route.params completo, que genera un
// objeto nuevo en cada navegación → recarga y spinner innecesarios.
const targetId = computed(() =>
    props.mode === 'guest' ? route.params.uuidEvento : route.params.uuidUnidad
);
watch(targetId, () => {
  cargarTodo();
});

onMounted(() => {
  cargarTodo();
});
</script>

<template>
  <div class="min-h-screen bg-[#F8FAFC] font-sans selection:bg-[#376875]/20 selection:text-[#376875]">

    <div v-if="store.loading || maestroStore.loading" class="fixed inset-0 z-50 flex flex-col justify-center items-center bg-white/90 backdrop-blur-md">
      <div class="relative w-16 h-16">
        <div class="absolute inset-0 rounded-full border-4 border-gray-100"></div>
        <div class="absolute inset-0 rounded-full border-4 border-[#376875] border-t-transparent animate-spin"></div>
      </div>
      <p class="mt-4 text-[10px] font-black uppercase tracking-[0.3em] text-gray-400 animate-pulse">{{ maestroStore.t('gui_cargando') || 'Cargando...' }}</p>
    </div>

    <div v-else-if="store.error" class="min-h-screen flex items-center justify-center p-6">
      <div class="bg-white p-8 rounded-4xl shadow-xl text-center max-w-sm mx-auto border border-red-50">
        <div class="w-16 h-16 bg-red-50 rounded-full flex items-center justify-center mx-auto mb-4 text-red-500 text-2xl"><i class="fas fa-exclamation-triangle"></i></div>
        <h3 class="font-black text-gray-900 mb-2">{{ maestroStore.t('gui_error_carga') || 'Error' }}</h3>
        <p class="text-gray-500 text-sm mb-6">{{ store.error }}</p>
        <button @click="recargar" class="w-full py-3 bg-[#376875] text-white rounded-xl font-bold text-xs uppercase tracking-widest shadow-lg">{{ maestroStore.t('gui_btn_reintentar') || 'Reintentar' }}</button>
      </div>
    </div>

    <div v-else-if="store.guia" class="relative w-full max-w-120 mx-auto min-h-screen bg-white shadow-2xl overflow-hidden md:my-8 md:min-h-212.5 md:h-[90vh] md:rounded-[3rem] md:border-8 md:border-[#376875]">

      <header class="absolute top-0 left-0 right-0 z-20 px-8 pt-14 pb-6 bg-linear-to-b from-white via-white/95 to-transparent backdrop-blur-[2px]">
        <div class="flex justify-between items-start gap-3">
          <div v-if="(store.helperContext?.data?.text_fixed?.booking_ref && store.helperContext?.data?.text_fixed?.booking_ref !== 'DEMO')" class="shrink-0 pt-1 mr-2">
            <button @click="irAReserva" class="w-10 h-10 rounded-xl bg-[#376875]/5 text-[#376875] flex items-center justify-center hover:bg-[#376875] hover:text-white transition-all shadow-sm active:scale-90"><i class="fas fa-arrow-left text-sm"></i></button>
          </div>
          <div class="flex flex-col flex-1 min-w-0">
            <span class="text-[10px] font-bold tracking-[0.2em] text-[#E07845] uppercase mb-1">{{ maestroStore.t('gui_header_tag') || 'Guía Digital' }}</span>
            <h1 class="text-2xl font-black text-gray-900 leading-[1.1] truncate">{{ store.traducir(store.guia.titulo) }}</h1>
          </div>
          <div class="relative shrink-0">
            <select :value="maestroStore.idiomaActual" @change="maestroStore.setIdioma(($event.target as HTMLSelectElement).value)" class="appearance-none bg-gray-100 font-bold text-[10px] uppercase tracking-wide rounded-xl py-2 pl-3 pr-8 focus:outline-none focus:ring-2 focus:ring-[#376875] cursor-pointer text-gray-600 border-0 hover:bg-gray-200 transition-colors">
              <option v-for="lang in maestroStore.idiomas" :key="lang.id" :value="lang.id">{{ lang.bandera }} {{ lang.id.toUpperCase() }}</option>
            </select>
            <div class="absolute right-2.5 top-1/2 -translate-y-1/2 pointer-events-none text-gray-400"><i class="fas fa-chevron-down text-[8px]"></i></div>
          </div>
        </div>
      </header>

      <div ref="homeScroll" class="pt-36 pb-24 px-6 h-full overflow-y-auto scrollbar-hide scroll-smooth bg-white">

        <!-- ═══ HERO (tap → sección descriptiva) ═══ -->
        <div
            class="mb-6 relative rounded-[2.5rem] shadow-xl shadow-[#376875]/20 overflow-hidden group h-60 md:h-72"
            :class="seccionDescriptiva ? 'cursor-pointer' : ''"
            @click="abrirDescriptivo"
        >
          <div v-if="heroImage" class="absolute inset-0 bg-cover bg-center transition-transform duration-[2s] group-hover:scale-110" :style="{ backgroundImage: `url(${heroImage})` }"></div>
          <div v-else class="absolute inset-0 bg-[#0F172A]"></div>
          <div class="absolute inset-0 bg-linear-to-t from-black/80 via-black/20 to-transparent"></div>

          <span
              v-if="seccionDescriptiva"
              class="absolute top-4 right-4 z-10 bg-black/40 backdrop-blur-sm text-white text-[11px] font-bold px-3 py-1.5 rounded-full border border-white/20 flex items-center gap-1.5"
          >
            <i class="fas fa-images"></i> {{ maestroStore.t('gui_ver_fotos') || 'Ver fotos' }}
          </span>

          <div class="absolute bottom-0 left-0 right-0 p-8 z-10 text-white">
            <h2 class="text-2xl font-black mb-1.5 tracking-tight drop-shadow-md">{{ maestroStore.t('gui_hola') || '¡Hola' }}, {{ getFirstName }}!</h2>
            <p class="text-white/90 text-sm leading-relaxed font-medium drop-shadow-sm flex items-center gap-2">
              <i class="fas fa-hand-sparkles text-[#E07845]"></i>
              {{ maestroStore.t('gui_bienvenido_a') || 'Bienvenido a' }}
              <span class="text-[#E07845] font-black bg-white/90 px-2 py-0.5 rounded-md shadow-sm">{{ getUnitName }}</span>
            </p>
          </div>
        </div>

        <!-- ═══ AVISO PENDIENTE (claves bloqueadas) ═══ -->
        <div v-if="showPendingWarning" class="mb-6 px-5 py-4 bg-orange-50 border border-orange-100 rounded-2xl flex items-center gap-4 shadow-sm animate-fadeIn">
          <div class="w-10 h-10 bg-white text-[#E07845] rounded-full flex items-center justify-center shadow-sm shrink-0">
            <i class="fas fa-lock text-sm"></i>
          </div>
          <div class="flex-1">
            <h3 class="text-sm font-black text-orange-900 leading-tight mb-0.5">
              {{ maestroStore.t('gui_aviso_previa') || 'Datos protegidos' }}
            </h3>
            <p class="text-xs text-orange-800/80 leading-snug">
              {{ maestroStore.t('gui_info_restringida', { date: unlockDateFormatted }) || `Claves de acceso disponibles el ${unlockDateFormatted}.` }}
            </p>
          </div>
        </div>

        <!-- ═══ TARJETA DE ANFITRIÓN + WHATSAPP ═══ -->
        <a
            v-if="hostPhone"
            :href="whatsappUrl"
            target="_blank"
            rel="noopener"
            class="mb-6 flex items-center gap-3 bg-white border border-slate-100 rounded-2xl p-3 shadow-sm hover:shadow-md hover:border-[#0F6E56]/30 transition-all active:scale-[0.99]"
        >
          <div class="w-11 h-11 rounded-full bg-[#E07845] text-white flex items-center justify-center font-black text-sm shrink-0">
            {{ hostInitials }}
          </div>
          <div class="flex-1 min-w-0">
            <p class="text-[11px] text-slate-400 font-bold">{{ maestroStore.t('gui_anfitrion') || 'Tu anfitrión' }}</p>
            <p class="text-sm font-black text-gray-900 truncate">{{ hostName || maestroStore.t('gui_anfitrion_generico') || 'A un mensaje de ti' }}</p>
          </div>
          <span class="flex items-center gap-2 bg-[#E1F5EE] text-[#0F6E56] text-xs font-black px-3 py-2 rounded-xl shrink-0">
            <i class="fab fa-whatsapp text-base"></i> {{ maestroStore.t('gui_escribir') || 'Escribir' }}
          </span>
        </a>

        <!-- ═══ SECCIÓN DESTACADA: INGRESO (pasos = botones directos) ═══ -->
        <div v-if="seccionDestacada" class="mb-4 bg-[#376875] rounded-[1.75rem] p-4 shadow-lg shadow-[#376875]/20">

          <!-- Cabecera: abre la sección completa (nivel 2) -->
          <button @click="abrirSeccion(seccionDestacada)" class="w-full flex items-center gap-4 text-left group px-1 pt-1">
            <span class="w-12 h-12 rounded-2xl bg-white/10 text-white flex items-center justify-center text-2xl shrink-0">
              <i :class="['fas', seccionDestacada.icono || 'fa-key']"></i>
            </span>
            <div class="flex-1 min-w-0">
              <h3 class="text-white font-black text-lg leading-tight">{{ store.traducir(seccionDestacada.titulo) }}</h3>
              <p v-if="subtituloDe(seccionDestacada)" class="text-white/60 text-xs mt-0.5 truncate">{{ subtituloDe(seccionDestacada) }}</p>
            </div>
            <span class="w-9 h-9 rounded-full bg-[#E07845] text-white flex items-center justify-center shadow-md shadow-orange-900/30 group-hover:translate-x-0.5 transition-transform shrink-0">
              <i class="fas fa-arrow-right text-sm"></i>
            </span>
          </button>

          <!-- Pasos como botones (acceso directo a cada ítem, nivel 3) -->
          <div v-if="itemsDestacadaVisibles.length" class="mt-4 pt-4 border-t border-white/10 space-y-2">
            <button
                v-for="(item, i) in itemsDestacadaVisibles"
                :key="item['@id']"
                @click="abrirItem(seccionDestacada, item)"
                class="w-full flex items-center gap-3 bg-white/10 hover:bg-white/20 rounded-xl px-3 py-2.5 text-left transition-colors active:scale-[0.99]"
            >
              <span class="w-6 h-6 rounded-full bg-white/15 text-white text-[11px] font-black flex items-center justify-center shrink-0">{{ i + 1 }}</span>
              <span class="flex-1 text-white/90 text-sm font-medium truncate">{{ store.traducir(item.titulo) }}</span>
              <i class="fas fa-chevron-right text-white/40 text-xs shrink-0"></i>
            </button>

            <button
                v-if="itemsOcultosCount > 0"
                @click="mostrarTodosIngreso = !mostrarTodosIngreso"
                class="w-full flex items-center justify-center gap-2 text-white/70 hover:text-white text-xs font-bold py-2 transition-colors"
            >
              <span v-if="!mostrarTodosIngreso">{{ maestroStore.t('gui_mostrar_mas') || 'Mostrar más' }} ({{ itemsOcultosCount }})</span>
              <span v-else>{{ maestroStore.t('gui_mostrar_menos') || 'Mostrar menos' }}</span>
              <i class="fas fa-chevron-down text-[10px] transition-transform" :class="mostrarTodosIngreso ? 'rotate-180' : ''"></i>
            </button>
          </div>
        </div>

        <!-- ═══ GRID DE SECCIONES RESTANTES ═══ -->
        <div class="grid grid-cols-2 gap-3">
          <button
              v-for="(seccion, index) in seccionesRestantes"
              :key="seccion.id"
              @click="abrirSeccion(seccion)"
              class="group relative flex flex-col justify-between min-h-30 p-4 bg-white border border-slate-100 rounded-2xl shadow-sm hover:shadow-md hover:border-slate-200 hover:-translate-y-0.5 active:scale-[0.98] transition-all duration-300 text-left"
          >
            <div class="flex items-start justify-between">
              <span
                  class="relative w-11 h-11 rounded-xl flex items-center justify-center text-lg transition-transform group-hover:scale-105"
                  :style="{ backgroundColor: colorDe(seccion, index).bg, color: colorDe(seccion, index).color }"
              >
                <i :class="['fas', seccion.icono || 'fa-star']"></i>
                <span v-if="showPendingWarning" class="absolute -top-1.5 -right-1.5 w-4 h-4 rounded-full bg-white text-slate-400 text-[8px] flex items-center justify-center shadow-sm border border-slate-100">
                  <i class="fas fa-lock"></i>
                </span>
              </span>
              <i class="fas fa-chevron-right text-slate-300 text-xs mt-1.5 group-hover:text-slate-400 group-hover:translate-x-0.5 transition-all"></i>
            </div>

            <div class="mt-3">
              <p class="text-[13px] font-black text-gray-800 leading-tight">{{ store.traducir(seccion.titulo) }}</p>
              <p v-if="subtituloDe(seccion)" class="text-[11px] text-slate-400 font-medium mt-0.5 leading-tight">{{ subtituloDe(seccion) }}</p>
            </div>
          </button>
        </div>

        <div class="mt-12 text-center pb-4"><p class="text-[9px] text-[#376875]/60 uppercase tracking-[0.3em] font-black">{{ maestroStore.t('com_powered_by') || 'Powered by OpenPeru' }}</p></div>
      </div>

      <!-- ═══ NIVEL 2: LISTA DE ÍTEMS DE LA SECCIÓN ═══ -->
      <transition enter-active-class="transition duration-300 ease-out" enter-from-class="translate-x-full" enter-to-class="translate-x-0" leave-active-class="transition duration-300 ease-in" leave-from-class="translate-x-0" leave-to-class="translate-x-full">
        <div v-if="seccionActiva" class="absolute inset-0 bg-[#F8FAFC] z-40 flex flex-col h-full shadow-2xl">
          <div class="flex-none px-6 py-5 bg-white border-b border-gray-100 flex items-center gap-4 sticky top-0 z-10">
            <button @click="volver" class="w-10 h-10 rounded-xl bg-gray-50 text-gray-600 flex items-center justify-center hover:bg-gray-100 active:scale-90 transition-all"><i class="fas fa-arrow-left text-sm"></i></button>
            <h2 class="text-lg font-black text-gray-900 truncate flex-1 tracking-tight">{{ store.traducir(seccionActiva.titulo) }}</h2>
          </div>

          <div id="nivel2-scroll" class="flex-1 overflow-y-auto p-5 pb-24 scrollbar-hide scroll-smooth">
            <!-- Sección de un solo ítem → mostramos el contenido directo -->
            <div v-if="esItemUnico" class="bg-white rounded-3xl shadow-sm border border-gray-100 overflow-hidden p-6 animate-fadeIn">
              <GuiaUnidadItemDispatcher :item="itemsSeccionActiva[0]" :context="store.helperContext" :store="store" :maestro="maestroStore" />
            </div>

            <!-- Varios ítems → lista visual (nivel 2) -->
            <div v-else class="space-y-3">
              <button
                  v-for="item in itemsSeccionActiva"
                  :key="item['@id']"
                  @click="abrirItem(seccionActiva, item)"
                  class="group w-full flex items-center gap-4 bg-white rounded-2xl border border-gray-100 shadow-sm hover:shadow-md hover:border-gray-200 active:scale-[0.99] transition-all p-3.5 text-left animate-fadeIn"
              >
                <span
                    v-if="item.galeria && item.galeria.length"
                    class="w-14 h-14 rounded-xl bg-cover bg-center shrink-0 border border-black/5"
                    :style="{ backgroundImage: `url(${item.galeria[0].imageUrl})` }"
                ></span>
                <span v-else class="w-11 h-11 rounded-xl bg-[#376875]/8 text-[#376875] text-lg flex items-center justify-center shrink-0">
                  <i :class="['fas', iconoItem(item)]"></i>
                </span>
                <div class="flex-1 min-w-0">
                  <p class="font-bold text-gray-800 leading-tight group-hover:text-[#376875] transition-colors">{{ store.traducir(item.titulo) }}</p>
                  <p v-if="item.galeria && item.galeria.length" class="text-[11px] text-slate-400 font-medium mt-0.5 flex items-center gap-1">
                    <i class="fas fa-images"></i> {{ item.galeria.length }} {{ maestroStore.t('gui_fotos') || 'fotos' }}
                  </p>
                </div>
                <i class="fas fa-chevron-right text-slate-300 group-hover:text-[#E07845] group-hover:translate-x-0.5 transition-all"></i>
              </button>
            </div>

            <div class="mt-8 text-center">
              <button @click="volver" class="text-[10px] font-black text-gray-400 hover:text-[#376875] uppercase tracking-[0.2em] px-4 py-2 hover:bg-white rounded-full transition-colors">{{ maestroStore.t('gui_btn_volver_menu') || 'Volver al menú' }}</button>
            </div>
          </div>
        </div>
      </transition>

      <!-- ═══ NIVEL 3: DETALLE DEL ÍTEM ═══ -->
      <transition enter-active-class="transition duration-300 ease-out" enter-from-class="translate-x-full" enter-to-class="translate-x-0" leave-active-class="transition duration-300 ease-in" leave-from-class="translate-x-0" leave-to-class="translate-x-full">
        <div v-if="itemActivo" class="absolute inset-0 bg-[#F8FAFC] z-50 flex flex-col h-full shadow-2xl">
          <div class="flex-none px-6 py-5 bg-white border-b border-gray-100 flex items-center gap-4 sticky top-0 z-10">
            <button @click="volver" class="w-10 h-10 rounded-xl bg-gray-50 text-gray-600 flex items-center justify-center hover:bg-gray-100 active:scale-90 transition-all"><i class="fas fa-arrow-left text-sm"></i></button>
            <h2 class="text-lg font-black text-gray-900 truncate flex-1 tracking-tight">{{ store.traducir(itemActivo.titulo) }}</h2>
          </div>

          <div id="nivel3-scroll" class="flex-1 overflow-y-auto p-5 pb-24 scrollbar-hide scroll-smooth">
            <div class="bg-white rounded-3xl shadow-sm border border-gray-100 overflow-hidden p-6 animate-fadeIn">
              <GuiaUnidadItemDispatcher :item="itemActivo" :context="store.helperContext" :store="store" :maestro="maestroStore" />
            </div>
          </div>
        </div>
      </transition>

    </div>
  </div>
</template>

<style scoped>
.scrollbar-hide::-webkit-scrollbar { display: none; }
.scrollbar-hide { -ms-overflow-style: none; scrollbar-width: none; }
.scroll-smooth { scroll-behavior: smooth; }
.animate-fadeIn { animation: fadeIn 0.3s ease-out forwards; }
@keyframes fadeIn { from { opacity: 0; transform: translateY(-5px); } to { opacity: 1; transform: translateY(0); } }
</style>
