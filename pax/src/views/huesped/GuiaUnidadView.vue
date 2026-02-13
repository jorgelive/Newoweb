<script setup lang="ts">
/* src/views/huesped/GuiaUnidadView.vue */
import { ref, onMounted, watch, computed, nextTick } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { usePmsGuiaStore } from '@/stores/pmsGuiaStore';
import { useMaestroStore } from '@/stores/maestroStore';
import type { PmsGuiaSeccion } from '@/types/pms';
import GuiaUnidadItemDispatcher from '@/components/GuiaUnidad/GuiaUnidadItemDispatcher.vue';

// Definimos la prop que viene del Router
const props = defineProps<{
  mode: 'public' | 'guest';
}>();

const route = useRoute();
const router = useRouter();
const store = usePmsGuiaStore();
const maestroStore = useMaestroStore();

const seccionActiva = ref<PmsGuiaSeccion | null>(null);
const expandedItems = ref<Set<string>>(new Set());
const scrollContainer = ref<HTMLElement | null>(null);
const homeScroll = ref<HTMLElement | null>(null);

// --- LOGICA DE CARGA INTELIGENTE ---
const cargarTodo = async () => {
  let idTarget = '';

  // Detectamos el parámetro correcto según el modo
  if (props.mode === 'guest') {
    // Viene de /huesped/evento/:uuidEvento
    idTarget = route.params.uuidEvento as string;
  } else {
    // Viene de /huesped/unidad/:uuidUnidad
    idTarget = route.params.uuidUnidad as string;
  }

  if (idTarget) {
    // Pasamos ID + MODO al store
    await store.cargarDatosCompletos(idTarget, props.mode);

    // Manejo de Deeplink a sección
    if (route.query.section && store.guia) {
      const s = store.guia.secciones.find(sec => sec.id === route.query.section);
      seccionActiva.value = s || null;
      if (seccionActiva.value) {
        expandedItems.value.clear();
        setTimeout(smoothResetScroll, 100);
      }
    }
  } else {
    console.error("No se encontró UUID en la ruta para el modo:", props.mode);
  }
};

// --- COMPUTED PROPERTIES ---
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

const itemsNormalizados = computed(() => {
  if (!seccionActiva.value || !seccionActiva.value.items) return [];
  return Array.isArray(seccionActiva.value.items) ? seccionActiva.value.items : [seccionActiva.value.items];
});

const esItemUnico = computed(() => itemsNormalizados.value.length === 1);

// --- SCROLL HELPERS ---
const smoothResetScroll = async () => {
  await nextTick();
  const options: ScrollToOptions = { top: 0, behavior: 'smooth' };
  if (scrollContainer.value) scrollContainer.value.scrollTo(options);
  const panelEl = document.getElementById('seccion-scroll-container');
  if (panelEl) panelEl.scrollTo(options);
  if (homeScroll.value) homeScroll.value.scrollTo(options);
  window.scrollTo(options);
};

const onPanelAfterEnter = () => {
  const el = document.getElementById('seccion-scroll-container');
  if (el) el.scrollTop = 0;
};

// --- ACTIONS ---
const toggleItem = (id: string) => {
  if (expandedItems.value.has(id)) expandedItems.value.delete(id);
  else expandedItems.value.add(id);
};

const isExpanded = (id: string) => expandedItems.value.has(id);

const recargar = () => { cargarTodo(); };

const abrirSeccion = (seccion: PmsGuiaSeccion) => {
  // Mantenemos el mismo nombre de ruta y parámetros actuales
  router.push({
    name: route.name as string,
    params: route.params,
    query: { section: seccion.id }
  });
};

const cerrarSeccion = () => {
  if (window.history.state?.back) router.back();
  else router.replace({ name: route.name as string, params: route.params });
};

const irAReserva = () => {
  // Intentamos obtener localizador del contexto o query
  const loc = store.helperContext?.data?.text_fixed?.booking_ref;
  if (loc && loc !== 'DEMO') {
    router.push({ name: 'pms_reserva', params: { localizador: loc } });
  } else {
    router.back();
  }
};

// --- WATCHERS & MOUNT ---
watch(() => route.query.section, async (newId) => {
  if (newId && store.guia) {
    const s = store.guia.secciones.find(sec => sec.id === newId);
    seccionActiva.value = s || null;
    if (seccionActiva.value) {
      expandedItems.value.clear();
      await smoothResetScroll();
    }
  } else {
    seccionActiva.value = null;
    expandedItems.value.clear();
    await smoothResetScroll();
  }
});

// Detectar cambios en params (por si navegan de evento A a evento B directamente)
watch(() => route.params, () => {
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
      <div class="bg-white p-8 rounded-[2rem] shadow-xl text-center max-w-sm mx-auto border border-red-50">
        <div class="w-16 h-16 bg-red-50 rounded-full flex items-center justify-center mx-auto mb-4 text-red-500 text-2xl"><i class="fas fa-exclamation-triangle"></i></div>
        <h3 class="font-black text-gray-900 mb-2">{{ maestroStore.t('gui_error_carga') || 'Error' }}</h3>
        <p class="text-gray-500 text-sm mb-6">{{ store.error }}</p>
        <button @click="recargar" class="w-full py-3 bg-[#376875] text-white rounded-xl font-bold text-xs uppercase tracking-widest shadow-lg">{{ maestroStore.t('gui_btn_reintentar') || 'Reintentar' }}</button>
      </div>
    </div>

    <div v-else-if="store.guia" class="relative w-full max-w-[480px] mx-auto min-h-screen bg-white shadow-2xl overflow-hidden md:my-8 md:min-h-[850px] md:h-[90vh] md:rounded-[3rem] md:border-[8px] md:border-[#376875]">

      <header class="absolute top-0 left-0 right-0 z-20 px-8 pt-14 pb-6 bg-gradient-to-b from-white via-white/95 to-transparent backdrop-blur-[2px]">
        <div class="flex justify-between items-start gap-3">
          <div v-if="(store.helperContext?.data?.text_fixed?.booking_ref && store.helperContext?.data?.text_fixed?.booking_ref !== 'DEMO')" class="shrink-0 pt-1 mr-2">
            <button @click="irAReserva" class="w-10 h-10 rounded-xl bg-[#376875]/5 text-[#376875] flex items-center justify-center hover:bg-[#376875] hover:text-white transition-all shadow-sm active:scale-90"><i class="fas fa-arrow-left text-sm"></i></button>
          </div>
          <div class="flex flex-col flex-1 min-w-0">
            <span class="text-[10px] font-black tracking-[0.2em] text-[#E07845] uppercase mb-1">{{ maestroStore.t('gui_header_tag') || 'Guía Digital' }}</span>
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

        <div class="mb-8 relative rounded-[2.5rem] shadow-xl shadow-[#376875]/20 overflow-hidden group h-64 md:h-72">
          <div v-if="heroImage" class="absolute inset-0 bg-cover bg-center transition-transform duration-[2s] group-hover:scale-110" :style="{ backgroundImage: `url(${heroImage})` }"></div>
          <div v-else class="absolute inset-0 bg-[#0F172A]"></div>
          <div class="absolute inset-0 bg-gradient-to-t from-black/80 via-black/20 to-transparent"></div>
          <div class="absolute bottom-0 left-0 right-0 p-8 z-10 text-white">
            <h2 class="text-2xl font-black mb-2 tracking-tight drop-shadow-md">{{ maestroStore.t('gui_hola') || '¡Hola' }}, {{ getFirstName }}!</h2>
            <p class="text-indigo-100 text-sm leading-relaxed font-medium drop-shadow-sm">
              {{ maestroStore.t('gui_bienvenido_a') || 'Bienvenido a' }}
              <span class="text-[#E07845] font-black bg-white/90 px-2 py-0.5 rounded-md backdrop-blur-sm shadow-sm">{{ getUnitName }}</span>.
            </p>
          </div>
        </div>

        <div class="grid grid-cols-2 gap-4">
          <button v-for="seccion in store.guia.secciones" :key="seccion.id" @click="abrirSeccion(seccion)" class="group flex flex-col items-center justify-center p-6 bg-white border border-[#376875]/10 rounded-[2rem] shadow-sm hover:shadow-xl hover:border-[#376875]/30 hover:-translate-y-1 active:scale-[0.98] transition-all duration-300 aspect-square relative overflow-hidden">
            <span class="absolute inset-0 bg-gradient-to-br from-[#376875]/0 via-[#376875]/0 to-[#376875]/10 opacity-0 group-hover:opacity-100 transition-opacity duration-500"></span>
            <span class="relative w-14 h-14 mb-4 rounded-2xl bg-[#E07845]/10 text-[#E07845] flex items-center justify-center text-xl group-hover:bg-[#376875] group-hover:text-white transition-all duration-300 shadow-sm group-hover:shadow-lg group-hover:shadow-[#376875]/40">
              <i :class="['fas', seccion.icono || 'fa-star']"></i>
            </span>
            <span class="relative text-[11px] font-black text-gray-600 text-center uppercase tracking-wider leading-tight group-hover:text-[#376875] transition-colors">{{ store.traducir(seccion.titulo) }}</span>
          </button>
        </div>

        <div class="mt-12 text-center pb-4"><p class="text-[9px] text-[#376875]/60 uppercase tracking-[0.3em] font-black">{{ maestroStore.t('com_powered_by') || 'Powered by OpenPeru' }}</p></div>
      </div>

      <transition enter-active-class="transition duration-300 ease-out" enter-from-class="translate-x-full" enter-to-class="translate-x-0" leave-active-class="transition duration-300 ease-in" leave-from-class="translate-x-0" leave-to-class="translate-x-full" @after-enter="onPanelAfterEnter">
        <div v-if="seccionActiva" :key="seccionActiva.id" class="absolute inset-0 bg-[#F8FAFC] z-40 flex flex-col h-full shadow-2xl">
          <div class="flex-none px-6 py-5 bg-white border-b border-gray-100 flex items-center gap-4 sticky top-0 z-50">
            <button @click="cerrarSeccion" class="w-10 h-10 rounded-xl bg-gray-50 text-gray-600 flex items-center justify-center hover:bg-gray-100 active:scale-90 transition-all"><i class="fas fa-arrow-left text-sm"></i></button>
            <h2 class="text-lg font-black text-gray-900 truncate flex-1 tracking-tight">{{ store.traducir(seccionActiva.titulo) }}</h2>
          </div>

          <div ref="scrollContainer" id="seccion-scroll-container" class="flex-1 overflow-y-auto p-5 pb-24 scrollbar-hide scroll-smooth">
            <div v-if="esItemUnico" class="bg-white rounded-[1.5rem] shadow-sm border border-gray-100 overflow-hidden p-6 animate-fadeIn">
              <GuiaUnidadItemDispatcher :item="itemsNormalizados[0]" :context="store.helperContext" :store="store" :maestro="maestroStore" />
            </div>

            <div v-else class="space-y-4">
              <div v-for="(item) in itemsNormalizados" :key="item['@id']" class="bg-white rounded-[1.5rem] shadow-sm border border-gray-100 overflow-hidden transition-all duration-300">
                <button @click="toggleItem(item['@id'])" class="group w-full flex items-center justify-between p-6 text-left focus:outline-none bg-white hover:bg-[#376875]/5 transition-colors">
                  <span class="font-bold text-gray-900 group-hover:text-[#376875] text-lg leading-tight pr-4 transition-colors">{{ store.traducir(item.titulo) }}</span>
                  <span class="w-8 h-8 shrink-0 flex items-center justify-center rounded-full transition-all duration-300" :class="isExpanded(item['@id']) ? 'bg-[#E07845] text-white rotate-180 shadow-md shadow-orange-200' : 'bg-[#E07845]/10 text-[#E07845] group-hover:bg-[#E07845]/20'">
                    <i class="fas fa-chevron-down text-sm"></i>
                  </span>
                </button>
                <div v-show="isExpanded(item['@id'])" class="border-t border-gray-100 bg-white">
                  <div class="p-6 pt-2 animate-fadeIn">
                    <GuiaUnidadItemDispatcher :item="item" :context="store.helperContext" :store="store" :maestro="maestroStore" />
                  </div>
                </div>
              </div>
            </div>

            <div class="mt-8 text-center">
              <button @click="cerrarSeccion" class="text-[10px] font-black text-gray-400 hover:text-[#376875] uppercase tracking-[0.2em] px-4 py-2 hover:bg-white rounded-full transition-colors">{{ maestroStore.t('gui_btn_volver_menu') || 'Volver al menú' }}</button>
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