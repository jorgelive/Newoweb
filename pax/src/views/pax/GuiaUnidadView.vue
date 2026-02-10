<script setup lang="ts">
/* src/views/pax/GuiaUnidadView.vue */
import { ref, onMounted, watch, computed, nextTick } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { usePmsGuiaStore } from '@/stores/pmsGuiaStore';
import { useMaestroStore } from '@/stores/maestroStore';
import type { PmsGuiaSeccion } from '@/types/pms';
import GuiaUnidadItemDispatcher from '@/components/GuiaUnidad/GuiaUnidadItemDispatcher.vue';

const route = useRoute();
const router = useRouter();
const store = usePmsGuiaStore();
const maestroStore = useMaestroStore();

const seccionActiva = ref<PmsGuiaSeccion | null>(null);
const expandedItems = ref<Set<string>>(new Set());
const scrollContainer = ref<HTMLElement | null>(null);

// 🔥 CORRECCIÓN 1: Buscar en 'text_fixed' (Strings seguros)
const getFirstName = computed(() => {
  const nombre = store.helperContext?.data?.text_fixed?.guest_name || 'Viajero';
  return nombre.split(' ')[0];
});

// 🔥 CORRECCIÓN 2: Buscar en 'text_fixed'
const getUnitName = computed(() => {
  return store.helperContext?.data?.text_fixed?.unit_name || 'Unidad';
});

const itemsNormalizados = computed(() => {
  if (!seccionActiva.value || !seccionActiva.value.items) return [];
  return Array.isArray(seccionActiva.value.items) ? seccionActiva.value.items : [seccionActiva.value.items];
});

const esItemUnico = computed(() => itemsNormalizados.value.length === 1);

const scrollToTop = async () => {
  await nextTick(); await nextTick();
  if (scrollContainer.value) scrollContainer.value.scrollTop = 0;
  const el = document.getElementById('seccion-scroll-container');
  if (el) el.scrollTop = 0;
};

const onPanelAfterEnter = () => {
  if (scrollContainer.value) scrollContainer.value.scrollTop = 0;
  const el = document.getElementById('seccion-scroll-container');
  if (el) el.scrollTop = 0;
};

const toggleItem = (id: string) => {
  if (expandedItems.value.has(id)) expandedItems.value.delete(id);
  else expandedItems.value.add(id);
};

const isExpanded = (id: string) => expandedItems.value.has(id);

const cargarTodo = async (id: string) => {
  await store.cargarDatosCompletos(id);
  if (route.query.section && store.guia) {
    const s = store.guia.secciones.find(sec => sec.id === route.query.section);
    seccionActiva.value = s || null;
    if (seccionActiva.value) { expandedItems.value.clear(); await scrollToTop(); }
  }
};

const recargar = () => { const uuid = route.params.uuid as string; if (uuid) cargarTodo(uuid); };

const abrirSeccion = (seccion: PmsGuiaSeccion) => {
  router.push({ name: 'guia_unidad', params: { uuid: route.params.uuid }, query: { section: seccion.id } });
};

const cerrarSeccion = () => {
  if (window.history.state?.back) router.back();
  else router.replace({ name: 'guia_unidad', params: { uuid: route.params.uuid } });
};

// 🔥 CORRECCIÓN 3: Buscar en 'text_fixed'
const irAReserva = () => {
  const loc = store.helperContext?.data?.text_fixed?.booking_ref || (route.query.localizador as string | undefined);
  if (loc && loc !== 'DEMO') {
    router.push({ name: 'pms_reserva', params: { localizador: loc } });
  } else {
    router.back();
  }
};

watch(() => route.query.section, async (newId) => {
  if (newId && store.guia) {
    const s = store.guia.secciones.find(sec => sec.id === newId);
    seccionActiva.value = s || null;
    if (seccionActiva.value) { expandedItems.value.clear(); await scrollToTop(); }
  } else { seccionActiva.value = null; expandedItems.value.clear(); }
});

onMounted(() => { const uuid = route.params.uuid as string; if (uuid) cargarTodo(uuid); });
</script>

<template>
  <div class="min-h-screen bg-[#F8FAFC] font-sans selection:bg-indigo-100 selection:text-indigo-800">
    <div v-if="store.loading || maestroStore.loading" class="fixed inset-0 z-50 flex flex-col justify-center items-center bg-white/90 backdrop-blur-md">
      <div class="relative w-16 h-16">
        <div class="absolute inset-0 rounded-full border-4 border-gray-100"></div>
        <div class="absolute inset-0 rounded-full border-4 border-indigo-600 border-t-transparent animate-spin"></div>
      </div>
      <p class="mt-4 text-[10px] font-black uppercase tracking-[0.3em] text-gray-400 animate-pulse">{{ maestroStore.t('gui_cargando') || 'Cargando...' }}</p>
    </div>

    <div v-else-if="store.error" class="min-h-screen flex items-center justify-center p-6">
      <div class="bg-white p-8 rounded-[2rem] shadow-xl text-center max-w-sm mx-auto border border-red-50">
        <div class="w-16 h-16 bg-red-50 rounded-full flex items-center justify-center mx-auto mb-4 text-red-500 text-2xl"><i class="fas fa-exclamation-triangle"></i></div>
        <h3 class="font-black text-gray-900 mb-2">{{ maestroStore.t('gui_error_carga') || 'Error' }}</h3>
        <p class="text-gray-500 text-sm mb-6">{{ store.error }}</p>
        <button @click="recargar" class="w-full py-3 bg-gray-900 text-white rounded-xl font-bold text-xs uppercase tracking-widest shadow-lg">{{ maestroStore.t('gui_btn_reintentar') || 'Reintentar' }}</button>
      </div>
    </div>

    <div v-else-if="store.guia" class="relative w-full max-w-[480px] mx-auto min-h-screen bg-white shadow-2xl overflow-hidden md:my-8 md:min-h-[850px] md:h-[90vh] md:rounded-[3rem] md:border-[8px] md:border-gray-900">
      <header class="absolute top-0 left-0 right-0 z-20 px-8 pt-14 pb-6 bg-gradient-to-b from-white via-white/95 to-transparent backdrop-blur-[2px]">
        <div class="flex justify-between items-start gap-3">
          <div v-if="(store.helperContext?.data?.text_fixed?.booking_ref && store.helperContext?.data?.text_fixed?.booking_ref !== 'DEMO') || route.query.localizador" class="shrink-0 pt-1 mr-2">
            <button @click="irAReserva" class="w-10 h-10 rounded-xl bg-indigo-50 text-indigo-600 flex items-center justify-center hover:bg-indigo-600 hover:text-white transition-all shadow-sm active:scale-90"><i class="fas fa-arrow-left text-sm"></i></button>
          </div>
          <div class="flex flex-col flex-1 min-w-0">
            <span class="text-[10px] font-black tracking-[0.2em] text-indigo-500 uppercase mb-1">{{ maestroStore.t('gui_header_tag') || 'Guía Digital' }}</span>
            <h1 class="text-2xl font-black text-gray-900 leading-[1.1] truncate">{{ store.traducir(store.guia.titulo) }}</h1>
          </div>
          <div class="relative shrink-0">
            <select :value="maestroStore.idiomaActual" @change="maestroStore.setIdioma(($event.target as HTMLSelectElement).value)" class="appearance-none bg-gray-100 font-bold text-[10px] uppercase tracking-wide rounded-xl py-2 pl-3 pr-8 focus:outline-none focus:ring-2 focus:ring-indigo-500 cursor-pointer text-gray-600 border-0 hover:bg-gray-200 transition-colors">
              <option v-for="lang in maestroStore.idiomas" :key="lang.id" :value="lang.id">{{ lang.bandera }} {{ lang.id.toUpperCase() }}</option>
            </select>
            <div class="absolute right-2.5 top-1/2 -translate-y-1/2 pointer-events-none text-gray-400"><i class="fas fa-chevron-down text-[8px]"></i></div>
          </div>
        </div>
      </header>

      <div class="pt-36 pb-24 px-6 h-full overflow-y-auto scrollbar-hide bg-white">
        <div class="mb-8 p-8 bg-[#1E1B4B] rounded-[2.5rem] text-white shadow-xl shadow-indigo-900/20 relative overflow-hidden group">
          <div class="absolute -top-12 -right-12 w-48 h-48 bg-indigo-500 rounded-full blur-[60px] opacity-40 group-hover:opacity-60 transition-opacity duration-1000"></div>
          <div class="relative z-10">
            <h2 class="text-xl font-black mb-2 tracking-tight">{{ maestroStore.t('gui_hola') || '¡Hola' }}, {{ getFirstName }}!</h2>
            <p class="text-indigo-200 text-sm leading-relaxed font-medium">{{ maestroStore.t('gui_bienvenido_a') || 'Bienvenido a' }} <span class="text-white font-bold">{{ getUnitName }}</span>.</p>
          </div>
        </div>

        <div class="grid grid-cols-2 gap-4">
          <button v-for="seccion in store.guia.secciones" :key="seccion.id" @click="abrirSeccion(seccion)" class="group flex flex-col items-center justify-center p-6 bg-white border border-gray-100 rounded-[2rem] shadow-sm hover:shadow-xl hover:border-indigo-100 hover:-translate-y-1 active:scale-[0.98] transition-all duration-300 aspect-square">
            <span class="w-14 h-14 mb-4 rounded-2xl bg-gray-50 text-gray-400 flex items-center justify-center text-xl group-hover:bg-indigo-600 group-hover:text-white transition-all duration-300 shadow-inner group-hover:shadow-lg group-hover:shadow-indigo-300"><i :class="['fas', seccion.icono || 'fa-star']"></i></span>
            <span class="text-[11px] font-black text-gray-600 text-center uppercase tracking-wider leading-tight group-hover:text-indigo-900 transition-colors">{{ store.traducir(seccion.titulo) }}</span>
          </button>
        </div>
        <div class="mt-12 text-center pb-4"><p class="text-[9px] text-gray-300 uppercase tracking-[0.3em] font-black">{{ maestroStore.t('com_powered_by') || 'Powered by OpenPeru' }}</p></div>
      </div>

      <transition enter-active-class="transition duration-300 ease-out" enter-from-class="translate-x-full" enter-to-class="translate-x-0" leave-active-class="transition duration-300 ease-in" leave-from-class="translate-x-0" leave-to-class="translate-x-full" @after-enter="onPanelAfterEnter">
        <div v-if="seccionActiva" :key="seccionActiva.id" class="absolute inset-0 bg-[#F8FAFC] z-40 flex flex-col h-full shadow-2xl">
          <div class="flex-none px-6 py-5 bg-white border-b border-gray-100 flex items-center gap-4 sticky top-0 z-50">
            <button @click="cerrarSeccion" class="w-10 h-10 rounded-xl bg-gray-50 text-gray-600 flex items-center justify-center hover:bg-gray-100 active:scale-90 transition-all"><i class="fas fa-arrow-left text-sm"></i></button>
            <h2 class="text-lg font-black text-gray-900 truncate flex-1 tracking-tight">{{ store.traducir(seccionActiva.titulo) }}</h2>
          </div>
          <div ref="scrollContainer" id="seccion-scroll-container" class="flex-1 overflow-y-auto p-5 pb-24 scrollbar-hide">
            <div v-if="esItemUnico" class="bg-white rounded-[1.5rem] shadow-sm border border-gray-100 overflow-hidden p-6 animate-fadeIn">
              <GuiaUnidadItemDispatcher :item="itemsNormalizados[0]" :context="store.helperContext" :store="store" :maestro="maestroStore" />
            </div>
            <div v-else class="space-y-4">
              <div v-for="(item) in itemsNormalizados" :key="item['@id']" class="bg-white rounded-[1.5rem] shadow-sm border border-gray-100 overflow-hidden transition-all duration-300">
                <button @click="toggleItem(item['@id'])" class="w-full flex items-center justify-between p-6 text-left focus:outline-none bg-white hover:bg-slate-50 transition-colors">
                  <span class="font-bold text-gray-900 text-lg leading-tight pr-4">{{ store.traducir(item.titulo) }}</span>
                  <span class="w-8 h-8 shrink-0 flex items-center justify-center rounded-full bg-slate-100 text-slate-400 transition-transform duration-300" :class="{ 'rotate-180': isExpanded(item['@id']) }"><i class="fas fa-chevron-down text-sm"></i></span>
                </button>
                <div v-show="isExpanded(item['@id'])" class="border-t border-gray-100 bg-white">
                  <div class="p-6 pt-2 animate-fadeIn">
                    <GuiaUnidadItemDispatcher :item="item" :context="store.helperContext" :store="store" :maestro="maestroStore" />
                  </div>
                </div>
              </div>
            </div>
            <div class="mt-8 text-center">
              <button @click="cerrarSeccion" class="text-[10px] font-black text-gray-400 hover:text-indigo-600 uppercase tracking-[0.2em] px-4 py-2 hover:bg-white rounded-full transition-colors">{{ maestroStore.t('gui_btn_volver_menu') || 'Volver al menú' }}</button>
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
.animate-fadeIn { animation: fadeIn 0.3s ease-out forwards; }
@keyframes fadeIn { from { opacity: 0; transform: translateY(-5px); } to { opacity: 1; transform: translateY(0); } }
</style>