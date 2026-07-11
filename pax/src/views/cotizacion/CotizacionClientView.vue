<script setup lang="ts">
import { ref, onMounted, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { useCotizacionViewStore } from '@/stores/cotizacionViewStore';
import { useMaestroStore } from '@/stores/maestroStore';

const props = defineProps<{
  localizadorProp?: string; // Por si lo pasas desde el router directamente
}>();

const route = useRoute();
const router = useRouter();
const viewStore = useCotizacionViewStore();
const maestroStore = useMaestroStore();

const inputLocalizador = ref<string>('');

onMounted(async () => {
  // Aseguramos que la configuración global/idiomas esté lista
  await maestroStore.cargarConfiguracion();

  // Verificamos de dónde sacar el localizador: Prop > Ruta (param o query)
  const locatorToFetch = props.localizadorProp || route.params.localizador || route.query.locator;

  if (locatorToFetch) {
    inputLocalizador.value = String(locatorToFetch);
    await procesarBusqueda();
  }
});

/**
 * Desencadena la búsqueda utilizando el valor actual del input.
 * Si es exitoso, actualiza la URL silenciosamente para que se pueda compartir.
 */
const procesarBusqueda = async () => {
  if (!inputLocalizador.value) return;

  const exito = await viewStore.cargarCotizacionPorLocalizador(inputLocalizador.value);

  if (exito && route.params.localizador !== inputLocalizador.value) {
    // Reemplaza la URL actual para que el turista la pueda guardar en favoritos
    router.replace({
      name: route.name, // Asegúrate de tener un name configurado en tu router
      params: { localizador: inputLocalizador.value }
    });
  }
};

/**
 * Obtiene el texto I18n de un array de snapshots dependiendo del idioma activo.
 * @param {Array<{language: string, content: string}>} snapshotArray El arreglo de traducciones.
 * @returns {string} El texto traducido o fallback en español.
 */
const getTranslatedContent = (snapshotArray: any[]): string => {
  if (!snapshotArray || !Array.isArray(snapshotArray)) return '';
  const lang = maestroStore.idiomaActual || 'es';
  const match = snapshotArray.find(item => item.language === lang);
  return match ? match.content : (snapshotArray.find(item => item.language === 'es')?.content || '');
};

// Formateadores de fecha usando el helper del maestro o nativos forzando Cusco
const formatearFecha = (fechaStr: string) => {
  if (!fechaStr) return '--';
  const fecha = new Date(fechaStr);
  return fecha.toLocaleDateString(maestroStore.idiomaActual, {
    day: '2-digit', month: 'long', year: 'numeric', timeZone: 'America/Lima'
  });
};
</script>

<template>
  <div class="min-h-screen bg-[#F8FAFC] font-sans selection:bg-[#E07845]/20 selection:text-[#E07845]">

    <div v-if="viewStore.isLoading" class="flex flex-col items-center justify-center min-h-screen">
      <div class="relative w-20 h-20 mb-6">
        <div class="absolute inset-0 rounded-full border-4 border-slate-200"></div>
        <div class="absolute inset-0 rounded-full border-4 border-[#376875] border-t-transparent animate-spin"></div>
      </div>
      <p class="text-[#376875] font-black uppercase tracking-[0.2em] text-sm animate-pulse">
        Preparando tu itinerario...
      </p>
    </div>

    <div v-else-if="!viewStore.isReady" class="flex items-center justify-center min-h-screen p-4">
      <div class="bg-white max-w-lg w-full rounded-[2.5rem] shadow-2xl p-8 md:p-12 text-center border border-slate-100">
        <div class="w-20 h-20 bg-orange-50 text-[#E07845] rounded-[1.5rem] flex items-center justify-center mx-auto mb-8 shadow-sm">
          <i class="fas fa-compass text-3xl"></i>
        </div>

        <h1 class="text-3xl font-black text-gray-800 mb-3 tracking-tight">Tu viaje empieza aquí</h1>
        <p class="text-slate-500 mb-10 text-sm leading-relaxed">
          Ingresa el localizador (Booking Ref) que tu asesor te ha proporcionado para revisar tu propuesta de viaje a medida.
        </p>

        <form @submit.prevent="procesarBusqueda" class="space-y-6">
          <div class="relative">
            <input
                v-model="inputLocalizador"
                type="text"
                placeholder="Ej. XYZ-123"
                class="w-full bg-[#F8FAFC] border-2 border-transparent focus:border-[#376875]/20 rounded-2xl py-4 px-6 text-center text-xl font-black tracking-[0.2em] uppercase text-gray-700 outline-none transition-all placeholder:text-slate-300 placeholder:font-normal placeholder:tracking-normal"
                required
            />
            <div v-if="viewStore.error" class="absolute -bottom-6 left-0 right-0 text-xs font-bold text-red-500 text-center animate-bounce">
              {{ viewStore.error }}
            </div>
          </div>

          <button
              type="submit"
              :disabled="!inputLocalizador"
              class="w-full bg-[#376875] hover:bg-[#2c535d] text-white font-black uppercase tracking-widest text-sm py-4 rounded-2xl transition-all active:scale-[0.98] disabled:opacity-50 disabled:cursor-not-allowed shadow-lg shadow-[#376875]/30"
          >
            Acceder a mi viaje
          </button>
        </form>
      </div>
    </div>

    <div v-else class="max-w-5xl mx-auto p-4 md:p-8">

      <header class="bg-[#376875] p-8 md:p-12 rounded-[2.5rem] shadow-2xl shadow-[#376875]/20 mb-10 relative overflow-hidden text-white">
        <div class="absolute inset-0 opacity-10" style="background-image: radial-gradient(#ffffff 1px, transparent 1px); background-size: 24px 24px;"></div>

        <div class="relative z-10 flex flex-col md:flex-row justify-between items-start md:items-end gap-6">
          <div>
            <span class="inline-block px-3 py-1 rounded-lg bg-[#E07845] text-white text-[10px] font-black uppercase tracking-widest mb-3 shadow-sm">
              Propuesta Comercial #{{ viewStore.fileData?.localizador }}
            </span>
            <h1 class="text-3xl md:text-5xl font-black tracking-tight leading-tight">
              Hola, <br/>
              <span class="text-white/90">{{ viewStore.fileData?.pasajeroPrincipal || viewStore.fileData?.nombreGrupo }}</span>
            </h1>
            <p class="text-white/70 mt-3 font-medium text-sm flex items-center gap-2">
              <i class="fas fa-users"></i> Para {{ viewStore.cotizacionActiva?.numPax }} Pasajero(s)
            </p>
          </div>

          <div class="bg-white/10 backdrop-blur-md p-5 rounded-[1.5rem] border border-white/10 min-w-[180px]">
            <p class="text-[10px] uppercase font-black text-white/60 tracking-wider mb-1">Inversión Total</p>
            <p class="text-3xl font-black text-white drop-shadow-sm">
              {{ viewStore.precioVentaPublico }}
            </p>
          </div>
        </div>
      </header>

      <main class="space-y-12">
        <section v-for="(servicio, index) in viewStore.serviciosActivos" :key="servicio.id || index" class="relative">

          <div class="flex items-center gap-4 mb-6">
            <div class="bg-[#E07845] text-white py-1 px-4 rounded-xl font-black text-xs uppercase tracking-widest shadow-md">
              Día {{ index + 1 }}
            </div>
            <h2 class="text-xl md:text-2xl font-black text-gray-800">
              {{ formatearFecha(servicio.fechaInicioAbsoluta) }}
            </h2>
            <div class="h-px bg-slate-200 flex-1 ml-4"></div>
          </div>

          <div class="pl-4 md:pl-8 border-l-2 border-slate-200 space-y-8 ml-4">
            <div v-for="(segmento, sIndex) in servicio.cotsegmentos" :key="segmento.id || sIndex" class="bg-white rounded-[2rem] shadow-lg shadow-slate-200/40 border border-slate-100 p-6 md:p-8 transition-transform hover:-translate-y-1 duration-300">

              <h3 class="text-lg md:text-xl font-bold text-[#376875] mb-4 flex items-start gap-3">
                <i class="fas fa-map-marker-alt mt-1 text-[#E07845]"></i>
                <span>{{ getTranslatedContent(segmento.nombreSnapshot || []) }}</span>
              </h3>

              <div
                  class="prose prose-sm md:prose-base max-w-none text-slate-600 mb-6 prose-p:leading-relaxed prose-strong:text-gray-800 prose-a:text-[#E07845]"
                  v-html="getTranslatedContent(segmento.contenidoSnapshot || [])"
              ></div>

              <div v-if="segmento.imagenesSnapshot && segmento.imagenesSnapshot.length > 0" class="flex gap-4 overflow-x-auto pb-4 snap-x snap-mandatory hide-scrollbar">
                <img
                    v-for="img in segmento.imagenesSnapshot"
                    :key="img['@id']"
                    :src="img.imageUrl"
                    :alt="img.imageName"
                    class="h-40 md:h-48 w-auto rounded-2xl object-cover snap-center flex-shrink-0 shadow-sm border border-slate-100"
                />
              </div>

              <div v-if="segmento.notasSnapshot && segmento.notasSnapshot.length > 0" class="mt-6 space-y-3">
                <div v-for="nota in segmento.notasSnapshot" :key="nota.id" class="bg-orange-50/80 border border-orange-100 rounded-2xl p-4 flex gap-4 items-start">
                  <i class="fas fa-info-circle text-[#E07845] mt-1 text-lg"></i>
                  <div>
                    <h4 class="font-bold text-gray-800 text-sm mb-1">{{ getTranslatedContent(nota.titulo || []) }}</h4>
                    <div class="text-xs text-slate-600" v-html="getTranslatedContent(nota.contenido || [])"></div>
                  </div>
                </div>
              </div>

            </div>
          </div>
        </section>
      </main>

      <footer class="mt-16 text-center border-t border-slate-200 pt-8 pb-12">
        <p class="text-[10px] text-slate-400 font-black uppercase tracking-[0.3em]">
          Itinerario sujeto a disponibilidad • Creado por OpenPeru
        </p>
      </footer>

    </div>
  </div>
</template>

<style scoped>
.hide-scrollbar::-webkit-scrollbar {
  display: none;
}
.hide-scrollbar {
  -ms-overflow-style: none;
  scrollbar-width: none;
}
/* Tipografía global y fluida para componentes ricos */
.prose p { margin-bottom: 1rem; }
.prose p:last-child { margin-bottom: 0; }
</style>