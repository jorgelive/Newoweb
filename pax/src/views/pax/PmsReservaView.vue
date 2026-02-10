<script setup lang="ts">
/**
 * src/views/pax/PmsReservaView.vue
 */
import { ref, onMounted } from 'vue';
import { useRouter } from 'vue-router';
import { usePmsReservaStore } from '@/stores/pmsReservaStore';
import { useMaestroStore } from '@/stores/maestroStore';

const props = defineProps<{
  localizador?: string;
}>();

const pmsStore = usePmsReservaStore();
const maestroStore = useMaestroStore();
const router = useRouter();

const isReady = ref(false);

onMounted(async () => {
  try {
    // 1. Cargamos diccionarios e idiomas
    await maestroStore.cargarConfiguracion();

    // 2. Cargamos la reserva si existe el localizador
    if (props.localizador) {
      await pmsStore.cargarReserva(props.localizador);
    }
  } catch (error) {
    console.error("Error en carga inicial:", error);
  } finally {
    isReady.value = true;
  }
});

// --- HELPER PARA FORMATO DE OCUPACIÓN ---
const formatearOcupacion = (adultos: number, ninos: number) => {
  // 1. Texto Adultos
  const labelAdultos = adultos === 1
      ? (maestroStore.t('res_adulto') || 'Adulto')
      : (maestroStore.t('res_adultos') || 'Adultos');

  let texto = `${adultos} ${labelAdultos}`;

  // 2. Texto Niños (Solo si hay > 0)
  if (ninos && ninos > 0) {
    const labelNinos = ninos === 1
        ? (maestroStore.t('res_nino') || 'Niño')
        : (maestroStore.t('res_ninos') || 'Niños');

    texto += ` y ${ninos} ${labelNinos}`;
  }

  return texto;
};

// --- HELPER PARA FECHAS ---
const formatearFecha = (fechaStr: string) => {
  if (!fechaStr) return '--';
  const fecha = new Date(fechaStr);
  return fecha.toLocaleDateString(maestroStore.idiomaActual, {
    day: '2-digit',
    month: 'short',
    year: 'numeric'
  });
};

const verGuiaEvento = (eventoId: string | number) => {
  router.push({
    name: 'guia_unidad',
    params: { uuid: eventoId },
    query: { localizador: props.localizador }
  });
};
</script>

<template>
  <div class="min-h-screen p-4 md:p-8 bg-[#f1f5f9] font-sans">

    <div v-if="!isReady || pmsStore.loading" class="flex flex-col items-center justify-center py-20">
      <div class="animate-spin rounded-full h-12 w-12 border-b-4 border-blue-600 mb-4"></div>
      <p class="text-slate-500 font-bold animate-pulse uppercase tracking-widest text-xs">
        {{ maestroStore.t('res_buscando_reserva') || 'Buscando tu reserva...' }}
      </p>
    </div>

    <div v-else-if="pmsStore.reserva && pmsStore.reserva.nombreCliente" class="max-w-4xl mx-auto">

      <header class="bg-white p-6 md:p-8 rounded-[2rem] shadow-2xl mb-6 md:mb-8 relative overflow-hidden border-b-[6px] border-blue-600">
        <div class="absolute inset-0 opacity-[0.06]" style="background-image: radial-gradient(#0f172a 1px, transparent 1px); background-size: 22px 22px;"></div>

        <div class="flex justify-end mb-4 relative z-20">
          <select
              :value="maestroStore.idiomaActual"
              @change="maestroStore.setIdioma(($event.target as HTMLSelectElement).value)"
              class="appearance-none bg-slate-100 border-none font-black text-[10px] uppercase tracking-widest rounded-xl px-4 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500 cursor-pointer text-slate-600"
          >
            <option v-for="lang in maestroStore.idiomas" :key="lang.id" :value="lang.id">
              {{ lang.bandera }} {{ lang.id.toUpperCase() }}
            </option>
          </select>
        </div>

        <div class="relative z-10 flex flex-col md:flex-row justify-between items-start md:items-center gap-4">
          <div>
            <h1 class="text-3xl md:text-4xl font-black text-slate-900 tracking-tighter">
              {{ maestroStore.t('res_hola') || '¡Hola' }}, {{ pmsStore.reserva.nombreCliente }}!
            </h1>
            <div class="flex items-center gap-2 mt-2">
              <span class="bg-blue-600 text-white px-3 py-1 rounded-full text-[10px] font-black uppercase tracking-widest">
                {{ maestroStore.t('res_localizador') || 'Localizador' }}: {{ pmsStore.reserva.localizador }}
              </span>
            </div>
          </div>
          <div class="bg-slate-50 p-3 rounded-2xl border border-slate-100">
            <p class="text-[10px] uppercase font-black text-slate-400">{{ maestroStore.t('res_total_estancia') || 'Total Estancia' }}</p>
            <p class="text-lg font-black text-slate-800">{{ pmsStore.reserva.numeroNoches }} {{ maestroStore.t('res_noches') || 'Noches' }}</p>
          </div>
        </div>
      </header>

      <div v-if="pmsStore.reserva.eventosCalendario?.length">
        <h2 class="text-slate-400 font-black uppercase tracking-[0.2em] text-[11px] mb-5 ml-2">
          {{ maestroStore.t('res_tus_unidades') || 'Tus Unidades Reservadas' }}
        </h2>

        <div v-for="evento in pmsStore.reserva.eventosCalendario" :key="evento.id" class="bg-white rounded-[2.5rem] shadow-2xl overflow-hidden border border-slate-200 mb-8">

          <div class="h-48 bg-slate-200 flex items-center justify-center relative">
            <span class="text-slate-400 font-black text-xs uppercase tracking-widest">
                {{ maestroStore.t('res_foto_unidad') || 'Foto Unidad' }}
            </span>
          </div>

          <div class="p-6 md:p-10">
            <div class="flex justify-between items-start mb-6">
              <div>
                <h3 class="text-2xl font-black text-slate-800">
                  {{ evento.pmsUnidad.nombre }}
                </h3>

                <p class="text-slate-400 font-bold text-sm mt-1">
                  <i class="fas fa-user-friends mr-1 opacity-70"></i>
                  {{ formatearOcupacion(evento.cantidadAdultos, evento.cantidadNinos) }}
                </p>

              </div>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-12 gap-4">

              <div class="md:col-span-5 md:order-2">
                <button @click="verGuiaEvento(evento.id)"
                        class="relative w-full h-full rounded-[1.75rem] flex items-center px-6 py-6 transition-all active:scale-[0.98] shadow-lg shadow-orange-200 group overflow-hidden"
                        style="background: linear-gradient(135deg, #f97316 0%, #fb923c 45%, #f59e0b 100%);">

                  <span class="absolute inset-0 bg-white/10 opacity-0 group-hover:opacity-100 transition-opacity"></span>

                  <span class="text-left w-full">
                      <span class="block text-[14px] font-black uppercase tracking-[0.2em] text-white mb-1">
                        {{ maestroStore.t('res_btn_instrucciones') || 'INSTRUCCIONES' }}
                      </span>

                    <span class="block text-white/90 text-[11px] font-bold leading-tight">
                        {{ maestroStore.t('res_cta_sub') || 'Cómo llegar · Códigos · Check-in' }}
                      </span>
                  </span>

                  <span class="absolute right-6 opacity-60 group-hover:translate-x-1 transition-transform">
                    <i class="fas fa-chevron-right text-white text-lg"></i>
                  </span>
                </button>
              </div>

              <div class="md:col-span-7 md:order-1 bg-[#0f172a] rounded-[1.75rem] grid grid-cols-2 text-white p-6">
                <div class="text-center border-r border-slate-800">
                  <p class="text-[10px] text-blue-400 font-black uppercase tracking-widest mb-1">
                    {{ maestroStore.t('res_checkin') || 'Check-in' }}
                  </p>
                  <p class="text-xl font-bold">{{ formatearFecha(evento.inicio) }}</p>
                </div>
                <div class="text-center">
                  <p class="text-[10px] text-blue-400 font-black uppercase tracking-widest mb-1">
                    {{ maestroStore.t('res_checkout') || 'Check-out' }}
                  </p>
                  <p class="text-xl font-bold">{{ formatearFecha(evento.fin) }}</p>
                </div>
              </div>

            </div>
          </div>
        </div>
      </div>

      <div class="mt-12 text-center pb-8">
        <p class="text-[10px] text-slate-300 uppercase tracking-[0.3em] font-black">
          {{ maestroStore.t('com_powered_by') || 'Powered by OpenPeru' }}
        </p>
      </div>

    </div>

    <div v-else class="max-w-4xl mx-auto text-center py-20 bg-white rounded-[2rem] shadow-xl border border-slate-100">
      <div class="w-20 h-20 bg-red-50 rounded-full flex items-center justify-center mx-auto mb-6">
        <i class="fas fa-search text-red-300 text-2xl"></i>
      </div>
      <p class="text-slate-600 font-black uppercase tracking-widest">
        {{ pmsStore.error || 'Reserva no encontrada' }}
      </p>
      <p class="text-slate-400 text-xs mt-2 font-mono">ID: {{ localizador }}</p>
    </div>
  </div>
</template>