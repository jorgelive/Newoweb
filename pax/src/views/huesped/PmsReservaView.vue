<script setup lang="ts">
/**
 * src/views/huesped/PmsReservaView.vue
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
    await maestroStore.cargarConfiguracion();
    if (props.localizador) {
      await pmsStore.cargarReserva(props.localizador);
    }
  } catch (error) {
    console.error("Error en carga inicial:", error);
  } finally {
    isReady.value = true;
  }
});

const formatearOcupacion = (adultos: number, ninos: number) => {
  const labelAdultos = adultos === 1
      ? (maestroStore.t('res_adulto') || 'Adulto')
      : (maestroStore.t('res_adultos') || 'Adultos');

  let texto = `${adultos} ${labelAdultos}`;

  if (ninos && ninos > 0) {
    const labelNinos = ninos === 1
        ? (maestroStore.t('res_nino') || 'Niño')
        : (maestroStore.t('res_ninos') || 'Niños');

    texto += ` y ${ninos} ${labelNinos}`;
  }

  return texto;
};

// --- HELPER PARA FECHAS (Forzando GMT-5 / Lima) ---
const formatearFecha = (fechaStr: string) => {
  if (!fechaStr) return '--';
  const fecha = new Date(fechaStr);

  return fecha.toLocaleDateString(maestroStore.idiomaActual, {
    day: '2-digit',
    month: 'short',
    year: 'numeric',
    timeZone: 'America/Lima' // 🔥 Forzamos zona horaria de Cusco
  });
};

// --- HELPER PARA HORAS (Forzando GMT-5 / Lima) ---
const formatearHora = (fechaStr: string) => {
  if (!fechaStr) return '';
  const fecha = new Date(fechaStr);

  // Formato: 03:00 PM (Siempre en hora Perú)
  return fecha.toLocaleTimeString(maestroStore.idiomaActual, {
    hour: '2-digit',
    minute: '2-digit',
    hour12: true,
    timeZone: 'America/Lima' // 🔥 Importante: Evita que el navegador del turista cambie la hora
  });
};

const verGuiaEvento = (eventoId: string | number) => {
  // 🔥 ACTUALIZADO: Apunta a la ruta segura de evento
  router.push({
    name: 'guia_evento',
    params: { uuidEvento: eventoId }, // Usamos el nombre de parámetro correcto
  });
};
</script>

<template>
  <div class="min-h-screen p-4 md:p-8 bg-[#F8FAFC] font-sans selection:bg-[#376875]/20 selection:text-[#376875]">

    <div v-if="!isReady || pmsStore.loading" class="flex flex-col items-center justify-center py-20 min-h-[60vh]">
      <div class="relative w-16 h-16 mb-6">
        <div class="absolute inset-0 rounded-full border-4 border-slate-100"></div>
        <div class="absolute inset-0 rounded-full border-4 border-[#E07845] border-t-transparent animate-spin"></div>
      </div>
      <p class="text-[#376875]/60 font-black animate-pulse uppercase tracking-[0.2em] text-xs">
        {{ maestroStore.t('res_buscando_reserva') || 'Buscando tu reserva...' }}
      </p>
    </div>

    <div v-else-if="pmsStore.reserva && pmsStore.reserva.nombreCliente" class="max-w-4xl mx-auto">

      <header class="bg-[#376875] p-6 md:p-10 rounded-[2.5rem] shadow-xl shadow-[#376875]/20 mb-8 relative overflow-hidden text-white">
        <div class="absolute inset-0 opacity-10" style="background-image: radial-gradient(#ffffff 1px, transparent 1px); background-size: 24px 24px;"></div>

        <div class="flex justify-end mb-6 relative z-20">
          <div class="relative">
            <select
                :value="maestroStore.idiomaActual"
                @change="maestroStore.setIdioma(($event.target as HTMLSelectElement).value)"
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

        <div class="relative z-10 flex flex-col md:flex-row justify-between items-start md:items-end gap-6">
          <div>
            <span class="inline-block px-3 py-1 rounded-lg bg-[#E07845] text-white text-[10px] font-black uppercase tracking-widest mb-2 shadow-sm">
              {{ maestroStore.t('res_localizador') || 'Booking Ref' }}: {{ pmsStore.reserva.localizador }}
            </span>
            <h1 class="text-3xl md:text-5xl font-black tracking-tight leading-tight">
              {{ maestroStore.t('res_hola') || '¡Hola' }}, <br/>
              <span class="text-white/90">{{ pmsStore.reserva.nombreCliente }}</span>
            </h1>
          </div>

          <div class="bg-white/10 backdrop-blur-sm p-4 rounded-2xl border border-white/10 min-w-[140px]">
            <p class="text-[9px] uppercase font-black text-white/60 tracking-wider mb-1">{{ maestroStore.t('res_total_estancia') || 'Total Estancia' }}</p>
            <p class="text-2xl font-black text-white">{{ pmsStore.reserva.numeroNoches }} <span class="text-sm font-bold text-white/80">{{ maestroStore.t('res_noches') || 'Noches' }}</span></p>
          </div>
        </div>
      </header>

      <div v-if="pmsStore.reserva.eventosCalendario?.length">
        <div class="flex items-center gap-4 mb-6 ml-2">
          <span class="h-px bg-[#376875]/20 flex-1"></span>
          <h2 class="text-[#376875]/60 font-black uppercase tracking-[0.2em] text-[11px]">
            {{ maestroStore.t('res_tus_unidades') || 'Tus Unidades' }}
          </h2>
          <span class="h-px bg-[#376875]/20 flex-1"></span>
        </div>

        <div v-for="evento in pmsStore.reserva.eventosCalendario" :key="evento.id" class="bg-white rounded-[2.5rem] shadow-xl shadow-slate-200/50 overflow-hidden border border-slate-100 mb-8 group hover:shadow-2xl hover:shadow-[#376875]/10 transition-all duration-500">

          <div class="h-56 md:h-64 bg-slate-100 relative overflow-hidden">
            <template v-if="evento.pmsUnidad?.imageUrl">
              <img
                  :src="evento.pmsUnidad.imageUrl"
                  :alt="evento.pmsUnidad.nombre"
                  class="w-full h-full object-cover transition-transform duration-[2s] group-hover:scale-105"
              >
              <div class="absolute inset-0 bg-gradient-to-t from-black/60 via-transparent to-transparent opacity-60"></div>

              <div class="absolute bottom-0 left-0 p-6 md:p-8 text-white">
                <h3 class="text-2xl md:text-3xl font-black leading-none drop-shadow-md">
                  {{ evento.pmsUnidad.nombre }}
                </h3>
                <p class="text-white/90 font-bold text-sm mt-2 flex items-center gap-2 drop-shadow-sm">
                  <i class="fas fa-user-friends text-[#E07845]"></i>
                  {{ formatearOcupacion(evento.cantidadAdultos, evento.cantidadNinos) }}
                </p>
              </div>
            </template>
            <div v-else class="w-full h-full flex flex-col items-center justify-center bg-[#376875]/5">
              <i class="fas fa-home text-4xl text-[#376875]/20 mb-2"></i>
              <span class="text-[#376875]/40 font-black text-xs uppercase tracking-widest">
                  {{ maestroStore.t('res_foto_unidad') || 'OpenPeru Unit' }}
              </span>
            </div>
          </div>

          <div class="p-6 md:p-8 bg-white relative">
            <div class="grid grid-cols-1 md:grid-cols-12 gap-4 items-stretch">

              <div class="md:col-span-5 md:order-2">
                <button @click="verGuiaEvento(evento.id)"
                        class="group/btn relative w-full h-full min-h-[100px] rounded-[1.5rem] flex flex-col justify-center px-6 py-5 transition-all active:scale-[0.98] shadow-lg shadow-orange-100 hover:shadow-orange-200 bg-[#E07845] hover:bg-[#D06535] overflow-hidden text-left">
                  <i class="fas fa-map-signs absolute -right-2 -bottom-4 text-6xl text-white/10 group-hover/btn:scale-110 group-hover/btn:rotate-12 transition-transform duration-500"></i>
                  <span class="relative z-10 flex items-center justify-between w-full mb-1">
                      <span class="text-[13px] font-black uppercase tracking-[0.15em] text-white">
                        {{ maestroStore.t('res_btn_instrucciones') || 'VER GUÍA' }}
                      </span>
                      <i class="fas fa-arrow-right text-white group-hover/btn:translate-x-1 transition-transform"></i>
                  </span>
                  <span class="relative z-10 block text-white/80 text-[11px] font-medium leading-tight max-w-[90%]">
                      {{ maestroStore.t('res_cta_sub') || 'Acceso, WiFi y Normas de la casa' }}
                  </span>
                </button>
              </div>

              <div class="md:col-span-7 md:order-1 bg-[#F1F5F9] rounded-[1.5rem] grid grid-cols-2 p-1 border border-slate-100">

                <div class="text-center p-4 rounded-[1.2rem] bg-white shadow-sm border border-slate-50 flex flex-col justify-center">
                  <p class="text-[9px] text-[#376875]/60 font-black uppercase tracking-widest mb-2">
                    <i class="fas fa-plane-arrival mr-1 text-[#E07845]"></i>
                    {{ maestroStore.t('res_checkin') || 'Check-in' }}
                  </p>
                  <p class="text-lg md:text-xl font-black text-gray-800 leading-none">
                    {{ formatearFecha(evento.inicio) }}
                  </p>
                  <p class="text-xs font-bold text-[#376875] mt-1.5 bg-[#376875]/5 rounded-md py-1 mx-4">
                    {{ formatearHora(evento.inicio) }}
                  </p>
                </div>

                <div class="text-center p-4 flex flex-col justify-center">
                  <p class="text-[9px] text-[#376875]/60 font-black uppercase tracking-widest mb-2">
                    {{ maestroStore.t('res_checkout') || 'Check-out' }}
                    <i class="fas fa-plane-departure ml-1 text-[#376875]"></i>
                  </p>
                  <p class="text-lg md:text-xl font-black text-gray-800 leading-none">
                    {{ formatearFecha(evento.fin) }}
                  </p>
                  <p class="text-xs font-bold text-[#376875] mt-1.5 bg-[#376875]/5 rounded-md py-1 mx-4">
                    {{ formatearHora(evento.fin) }}
                  </p>
                </div>
              </div>

            </div>
          </div>
        </div>
      </div>

      <div class="mt-12 text-center pb-8">
        <p class="text-[9px] text-[#376875]/40 uppercase tracking-[0.3em] font-black">
          {{ maestroStore.t('com_powered_by') || 'Powered by OpenPeru' }}
        </p>
      </div>

    </div>

    <div v-else class="max-w-md mx-auto text-center py-16 px-6 bg-white rounded-[2.5rem] shadow-xl shadow-slate-200/50 mt-10 border border-slate-50">
      <div class="w-20 h-20 bg-red-50 rounded-full flex items-center justify-center mx-auto mb-6">
        <i class="fas fa-search text-red-400 text-2xl"></i>
      </div>
      <h3 class="text-gray-900 font-black text-lg mb-2">
        {{ maestroStore.t('res_no_encontrada') || 'Reserva no encontrada' }}
      </h3>
      <p class="text-slate-500 text-sm mb-6 leading-relaxed">
        {{ pmsStore.error || 'No pudimos encontrar una reserva con el código proporcionado.' }}
      </p>
      <div class="bg-slate-50 py-3 px-6 rounded-xl inline-block border border-slate-100">
        <p class="text-slate-400 text-[10px] font-mono font-bold uppercase tracking-widest">ID: {{ localizador }}</p>
      </div>
    </div>
  </div>
</template>