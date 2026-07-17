<script setup lang="ts">
/**
 * src/views/cotizacion/PaxFilePortadaView.vue
 * Ruta: /file/:localizador? — portada del expediente con las propuestas activas.
 * Sin localizador muestra el buscador.
 */
import { ref, onMounted, watch } from 'vue';
import { useRouter } from 'vue-router';
import { usePaxCotizacionStore } from '@/stores/cotizacion/paxCotizacionStore';
import { useMaestroStore } from '@/stores/maestroStore';

const props = defineProps<{
  localizador?: string;
}>();

const store = usePaxCotizacionStore();
const maestroStore = useMaestroStore();
const router = useRouter();

const isReady = ref(false);

// --- BUSCADOR ---
const codigoBusqueda = ref('');

const buscarFile = () => {
  const loc = codigoBusqueda.value.trim().toUpperCase();
  if (loc) {
    router.push({ name: 'file_publica', params: { localizador: loc } });
  }
};

const cargar = async () => {
  isReady.value = false;
  try {
    await maestroStore.cargarConfiguracion();
    if (props.localizador) {
      await store.cargarPortada(props.localizador);
    }
  } catch (error) {
    console.error('Error en carga inicial:', error);
  } finally {
    isReady.value = true;
  }
};

onMounted(cargar);
// 🔥 Recarga al cambiar el localizador (el buscador hace push sobre la misma ruta)
watch(() => props.localizador, cargar);

const verGuia = (version: number) => {
  router.push({
    name: 'cotizacion_guia',
    params: { localizador: props.localizador, version },
  });
};

// --- HELPERS DE FORMATO ---
const formatearFecha = (iso?: string | null) => {
  if (!iso) return '';
  return new Date(iso.substring(0, 10) + 'T00:00:00').toLocaleDateString(maestroStore.idiomaActual, {
    day: '2-digit',
    month: 'short',
    year: 'numeric',
    timeZone: 'America/Lima',
  });
};

const formatearMonto = (monto: string | null, moneda: string) => {
  if (monto === null) return '';
  return new Intl.NumberFormat(maestroStore.idiomaActual, {
    style: 'currency',
    currency: moneda,
  }).format(Number(monto));
};
</script>

<template>
  <div class="min-h-screen p-4 md:p-8 bg-[#F8FAFC] font-sans selection:bg-[#376875]/20 selection:text-[#376875]">

    <!-- ═══ BUSCADOR: sin localizador en la URL ═══ -->
    <div v-if="!localizador" class="max-w-md mx-auto text-center py-16 px-6 bg-white rounded-[2.5rem] shadow-xl shadow-slate-200/50 mt-10 border border-slate-50">
      <div class="w-20 h-20 bg-[#376875]/5 rounded-full flex items-center justify-center mx-auto mb-6">
        <i class="fas fa-route text-[#376875] text-2xl"></i>
      </div>
      <h3 class="text-gray-900 font-black text-lg mb-2">
        {{ maestroStore.t('cot_buscar_titulo') || 'Encuentra tu propuesta de viaje' }}
      </h3>
      <p class="text-slate-500 text-sm mb-6 leading-relaxed">
        {{ maestroStore.t('cot_buscar_sub') || 'Ingresa el código que te enviamos por correo' }}
      </p>
      <form @submit.prevent="buscarFile" class="flex gap-2">
        <input
            v-model="codigoBusqueda"
            :placeholder="maestroStore.t('cot_buscar_placeholder') || 'Ej. 2KVBMX'"
            maxlength="10"
            autocomplete="off"
            class="flex-1 min-w-0 bg-slate-50 border border-slate-200 rounded-xl px-4 py-3 font-mono font-black uppercase tracking-widest text-center text-gray-800 focus:outline-none focus:border-[#E07845] focus:bg-white transition-colors"
        />
        <button
            type="submit"
            :disabled="!codigoBusqueda.trim()"
            class="bg-[#E07845] hover:bg-[#D06535] disabled:opacity-40 disabled:cursor-not-allowed text-white font-black px-6 rounded-xl transition-all active:scale-[0.97] shadow-lg shadow-orange-100"
        >
          <i class="fas fa-arrow-right"></i>
        </button>
      </form>
    </div>

    <!-- ═══ CARGANDO ═══ -->
    <div v-else-if="!isReady || store.loading" class="flex flex-col items-center justify-center py-20 min-h-[60vh]">
      <div class="relative w-16 h-16 mb-6">
        <div class="absolute inset-0 rounded-full border-4 border-slate-100"></div>
        <div class="absolute inset-0 rounded-full border-4 border-[#E07845] border-t-transparent animate-spin"></div>
      </div>
      <p class="text-[#376875]/60 font-black animate-pulse uppercase tracking-[0.2em] text-xs">
        {{ maestroStore.t('cot_buscando') || 'Buscando tu propuesta...' }}
      </p>
    </div>

    <!-- ═══ EXPEDIENTE ENCONTRADO ═══ -->
    <div v-else-if="store.portada" class="max-w-4xl mx-auto">

      <!-- SECCIÓN 1: Encabezado del expediente -->
      <header class="bg-[#376875] p-6 md:p-10 rounded-[2.5rem] shadow-xl shadow-[#376875]/20 mb-8 relative overflow-hidden text-white">
        <div class="absolute inset-0 opacity-10" style="background-image: radial-gradient(#ffffff 1px, transparent 1px); background-size: 24px 24px;"></div>

        <!-- Selector de idioma -->
        <div class="flex justify-end mb-6 relative z-20">
          <div class="relative">
            <select
                :value="maestroStore.idiomaActual"
                @change="maestroStore.setIdioma(($event.target as HTMLSelectElement).value); localStorage.setItem('paxIdiomaManual', '1')"
                class="appearance-none bg-white/10 border border-white/20 font-black text-[10px] uppercase tracking-widest rounded-xl pl-4 pr-8 py-2 focus:outline-none focus:bg-white focus:text-[#376875] cursor-pointer text-white transition-colors hover:bg-white/20"
            >
              <option v-for="lang in maestroStore.idiomas" :key="lang.id" :value="lang.id" class="text-gray-800">
                {{ lang.bandera }} {{ lang.id.toUpperCase() }}
              </option>
            </select>
            <div class="absolute right-3 top-1/2 -translate-y-1/2 pointer-events-none text-white/70">
              <i class="fas fa-chevron-down text-[8px]"></i>
            </div>
          </div>
        </div>

        <div class="relative z-10">
          <span class="inline-block px-3 py-1 rounded-lg bg-[#E07845] text-white text-[10px] font-black uppercase tracking-widest mb-2 shadow-sm">
            {{ maestroStore.t('cot_localizador') || 'Trip Ref' }}: {{ store.portada.localizador }}
          </span>
          <h1 class="text-3xl md:text-5xl font-black tracking-tight leading-tight">
            {{ store.portada.nombreGrupo }}
          </h1>
          <p v-if="store.portada.pasajeroPrincipal" class="text-white/80 font-bold mt-2 flex items-center gap-2">
            <i class="fas fa-user text-[#E07845]"></i>
            {{ store.portada.pasajeroPrincipal }}
          </p>
        </div>
      </header>

      <!-- SECCIÓN 2: Propuestas activas (puede haber varias) -->
      <div v-if="store.versiones.length">
        <div class="flex items-center gap-4 mb-6 ml-2">
          <span class="h-px bg-[#376875]/20 flex-1"></span>
          <h2 class="text-[#376875]/60 font-black uppercase tracking-[0.2em] text-[11px]">
            {{ maestroStore.t('cot_tus_propuestas') || 'Tus Propuestas' }}
          </h2>
          <span class="h-px bg-[#376875]/20 flex-1"></span>
        </div>

        <article
            v-for="v in store.versiones"
            :key="v.version"
            class="bg-white rounded-[2.5rem] shadow-xl shadow-slate-200/50 border border-slate-100 mb-8 overflow-hidden group hover:shadow-2xl hover:shadow-[#376875]/10 transition-all duration-500"
        >
          <div class="p-6 md:p-8">
            <!-- Chip + fechas -->
            <div class="flex flex-wrap items-center justify-between gap-3 mb-4">
              <span class="inline-block px-3 py-1 rounded-lg bg-[#376875] text-white text-[10px] font-black uppercase tracking-widest">
                {{ maestroStore.t('cot_propuesta') || 'Propuesta' }} V{{ v.version }}
              </span>
              <span v-if="v.fechaInicio" class="text-[#376875]/70 text-xs font-bold flex items-center gap-2">
                <i class="fas fa-calendar-alt text-[#E07845]"></i>
                {{ formatearFecha(v.fechaInicio) }}
                <span class="text-[#376875]/40">·</span>
                <i class="fas fa-user-friends text-[#E07845]"></i>
                {{ v.numPax }} pax
              </span>
            </div>

            <!-- Resumen comercial i18n (HTML) para ayudar a elegir -->
            <div
                v-if="store.traducir(v.resumen)"
                class="prose prose-sm max-w-none text-slate-600 mb-6 prose-strong:text-[#376875] prose-a:text-[#E07845]"
                v-html="store.traducir(v.resumen)"
            />

            <!-- Precio + CTA -->
            <div class="grid grid-cols-1 md:grid-cols-12 gap-4 items-stretch">

              <div class="md:col-span-7 bg-[#F1F5F9] rounded-[1.5rem] p-5 border border-slate-100 flex flex-col justify-center">
                <template v-if="!v.precioOculto && v.totalVenta">
                  <p class="text-[9px] text-[#376875]/60 font-black uppercase tracking-widest mb-1">
                    {{ maestroStore.t('cot_precio_total') || 'Precio total del viaje' }}
                  </p>
                  <p class="text-2xl md:text-3xl font-black text-gray-800 leading-none">
                    {{ formatearMonto(v.totalVenta, v.monedaGlobal) }}
                  </p>
                  <p v-if="v.adelanto && Number(v.adelanto) > 0" class="text-xs font-bold text-[#376875] mt-2">
                    {{ maestroStore.t('cot_adelanto') || 'Adelanto' }}: {{ formatearMonto(v.adelanto, v.monedaGlobal) }}
                  </p>
                </template>
                <p v-else class="text-sm font-bold text-[#376875]/60 italic">
                  {{ maestroStore.t('cot_precio_consultar') || 'Consulta el precio con tu asesor' }}
                </p>
              </div>

              <div class="md:col-span-5">
                <button
                    @click="verGuia(v.version)"
                    class="group/btn relative w-full h-full min-h-[100px] rounded-[1.5rem] flex flex-col justify-center px-6 py-5 transition-all active:scale-[0.98] shadow-lg shadow-orange-100 hover:shadow-orange-200 bg-[#E07845] hover:bg-[#D06535] overflow-hidden text-left"
                >
                  <i class="fas fa-map-signs absolute -right-2 -bottom-4 text-6xl text-white/10 group-hover/btn:scale-110 group-hover/btn:rotate-12 transition-transform duration-500"></i>
                  <span class="relative z-10 flex items-center justify-between w-full mb-1">
                    <span class="text-[13px] font-black uppercase tracking-[0.15em] text-white">
                      {{ maestroStore.t('cot_btn_ver_itinerario') || 'VER ITINERARIO' }}
                    </span>
                    <i class="fas fa-arrow-right text-white group-hover/btn:translate-x-1 transition-transform"></i>
                  </span>
                  <span class="relative z-10 block text-white/80 text-[11px] font-medium leading-tight max-w-[90%]">
                    {{ maestroStore.t('cot_cta_sub') || 'Día a día, incluye y precios' }}
                  </span>
                </button>
              </div>
            </div>

            <p v-if="v.fechaExpiracion" class="text-[10px] text-[#376875]/40 font-bold uppercase tracking-widest mt-4 text-right">
              <i class="fas fa-hourglass-half mr-1"></i>
              {{ maestroStore.t('cot_valida_hasta') || 'Válida hasta' }} {{ formatearFecha(v.fechaExpiracion) }}
            </p>
          </div>
        </article>
      </div>

      <!-- Documentos públicos -->
      <div v-if="store.documentos.length" class="bg-white rounded-[2.5rem] shadow-xl shadow-slate-200/50 border border-slate-100 p-6 md:p-8 mb-8">
        <h2 class="text-[#376875]/60 font-black uppercase tracking-[0.2em] text-[11px] mb-4">
          {{ maestroStore.t('cot_documentos') || 'Documentos' }}
        </h2>
        <div class="flex flex-wrap gap-3">
          <a
              v-for="doc in store.documentos"
              :key="doc.id"
              :href="doc.imageUrl ?? '#'"
              target="_blank"
              class="flex items-center gap-2 bg-slate-50 hover:bg-[#376875]/5 border border-slate-100 rounded-xl px-4 py-3 text-sm font-bold text-[#376875] transition-colors"
          >
            <i class="fas fa-file-pdf text-[#E07845]"></i>
            {{ doc.tipodocumento }}
          </a>
        </div>
      </div>

      <!-- Aviso de data retenida (offline / fallo de servidor) -->
      <p v-if="store.error" class="text-center text-xs font-bold text-amber-600 bg-amber-50 rounded-xl py-3 px-4 mb-8">
        <i class="fas fa-wifi mr-1"></i> {{ store.error }}
      </p>

      <div class="mt-12 text-center pb-8">
        <p class="text-[9px] text-[#376875]/40 uppercase tracking-[0.3em] font-black">
          {{ maestroStore.t('com_powered_by') || 'Powered by OpenPeru' }}
        </p>
      </div>
    </div>

    <!-- ═══ NO ENCONTRADO ═══ -->
    <div v-else class="max-w-md mx-auto text-center py-16 px-6 bg-white rounded-[2.5rem] shadow-xl shadow-slate-200/50 mt-10 border border-slate-50">
      <div class="w-20 h-20 bg-red-50 rounded-full flex items-center justify-center mx-auto mb-6">
        <i class="fas fa-search text-red-400 text-2xl"></i>
      </div>
      <h3 class="text-gray-900 font-black text-lg mb-2">
        {{ maestroStore.t('cot_no_encontrada') || 'Propuesta no encontrada' }}
      </h3>
      <p class="text-slate-500 text-sm mb-6 leading-relaxed">
        {{ store.error || 'No pudimos encontrar una propuesta con el código proporcionado.' }}
      </p>
      <div class="bg-slate-50 py-3 px-6 rounded-xl inline-block border border-slate-100 mb-6">
        <p class="text-slate-400 text-[10px] font-mono font-bold uppercase tracking-widest">ID: {{ localizador }}</p>
      </div>

      <!-- Reintentar con otro código -->
      <form @submit.prevent="buscarFile" class="flex gap-2">
        <input
            v-model="codigoBusqueda"
            :placeholder="maestroStore.t('cot_buscar_placeholder') || 'Ej. 2KVBMX'"
            maxlength="10"
            autocomplete="off"
            class="flex-1 min-w-0 bg-slate-50 border border-slate-200 rounded-xl px-4 py-3 font-mono font-black uppercase tracking-widest text-center text-gray-800 focus:outline-none focus:border-[#E07845] focus:bg-white transition-colors"
        />
        <button
            type="submit"
            :disabled="!codigoBusqueda.trim()"
            class="bg-[#E07845] hover:bg-[#D06535] disabled:opacity-40 text-white font-black px-6 rounded-xl transition-all active:scale-[0.97]"
        >
          <i class="fas fa-arrow-right"></i>
        </button>
      </form>
    </div>
  </div>
</template>