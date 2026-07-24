<script setup lang="ts">
import { onMounted, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { usePaxCotizacionStore } from '@/stores/cotizacion/paxCotizacionStore';
import { useMaestroStore } from '@/stores/maestroStore';
import { thumbUrl } from '@/services/imageThumb';
import type { PaxTourResumen } from '@/types/paxCotizacionModel';

const route = useRoute();
const router = useRouter();
const store = usePaxCotizacionStore();
const maestroStore = useMaestroStore();

const cargar = async () => {
  const localizador = String(route.params.localizador || '').trim().toUpperCase();
  if (!localizador) return;
  try {
    await store.cargarPortadaCatalogo(localizador);
    const idiomaCat = store.portadaCatalogo?.idiomaCliente;
    if (idiomaCat && !localStorage.getItem('paxIdiomaManual')) {
      maestroStore.setIdioma(idiomaCat);
    }
  } catch { /* el store expone error */ }
};

onMounted(cargar);
watch(() => route.params.localizador, cargar);

const verTour = (version: number) => {
  router.push(`/catalogo/${store.portadaCatalogo?.localizador}/v/${version}`);
};

const formatMonto = (valor: string): string => {
  const n = Number(valor);
  return Number.isFinite(n) ? n.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) : valor;
};

/** Rango principal (el primero) para el precio grande de la card. */
const rangoPrincipal = (tour: PaxTourResumen) => tour.preciosDesde?.[0] ?? null;
</script>

<template>
  <div class="min-h-screen bg-[#F8FAFC] font-sans selection:bg-[#376875]/20 selection:text-[#376875]">

    <!-- Cargando -->
    <div v-if="store.loading && !store.portadaCatalogo" class="min-h-screen flex flex-col items-center justify-center text-[#376875]/60">
      <i class="fas fa-compass fa-spin text-4xl mb-4"></i>
      <p class="font-black uppercase tracking-widest text-xs">{{ maestroStore.t('cat_cargando') || 'Cargando catálogo...' }}</p>
    </div>

    <!-- Error / no encontrado -->
    <div v-else-if="store.error && !store.portadaCatalogo" class="min-h-screen flex flex-col items-center justify-center p-8 text-center">
      <i class="fas fa-map-marked-alt text-5xl text-slate-300 mb-4"></i>
      <h2 class="text-xl font-black text-slate-700">{{ maestroStore.t('cat_no_encontrado') || 'Catálogo no disponible' }}</h2>
      <p class="text-sm text-slate-400 font-medium mt-2 max-w-md">{{ store.error }}</p>
    </div>

    <template v-else-if="store.portadaCatalogo">
      <!-- Hero del catálogo -->
      <header class="bg-[#376875] text-white relative overflow-hidden">
        <i class="fas fa-mountain absolute -right-8 -bottom-10 text-[10rem] opacity-10"></i>
        <div class="max-w-3xl mx-auto px-6 py-12 md:py-16 relative z-10">
          <p class="text-[10px] font-black uppercase tracking-[0.3em] text-white/60 mb-3">
            {{ maestroStore.t('cat_titulo_hero') || 'Catálogo de Experiencias' }}
          </p>
          <h1 class="text-3xl md:text-4xl font-black tracking-tight leading-tight">{{ store.portadaCatalogo.nombre }}</h1>
          <p class="text-white/70 text-sm font-medium mt-3">
            {{ store.tours.length }} {{ store.tours.length === 1 ? (maestroStore.t('cat_tour') || 'experiencia') : (maestroStore.t('cat_tours') || 'experiencias') }}
            · {{ maestroStore.t('cat_ref') || 'Ref' }}: {{ store.portadaCatalogo.localizador }}
          </p>
        </div>
      </header>

      <!-- Cards de tours -->
      <main class="max-w-3xl mx-auto px-4 md:px-6 py-8 md:py-12 pb-20">
        <article v-for="tour in store.tours" :key="tour.version"
                 class="bg-white rounded-4xl border border-slate-200 shadow-lg shadow-slate-300/40 mb-8 overflow-hidden group hover:shadow-xl hover:shadow-[#376875]/10 hover:border-slate-300 transition-all duration-500">

          <!-- Portada -->
          <div class="relative aspect-[16/8] bg-slate-100 overflow-hidden">
            <img v-if="tour.imagenPortada?.imageUrl"
                 :src="thumbUrl(tour.imagenPortada.imageUrl, 'travel_cliente')"
                 :alt="store.traducir(tour.titulo)"
                 class="w-full h-full object-cover group-hover:scale-105 transition-transform duration-700" loading="lazy">
            <div v-else class="w-full h-full bg-gradient-to-br from-[#376875] to-[#1f4550] flex items-center justify-center">
              <i class="fas fa-mountain text-5xl text-white/20"></i>
            </div>

            <span v-if="tour.numDias" class="absolute top-4 left-4 bg-white/95 backdrop-blur text-[#376875] text-[10px] font-black uppercase tracking-widest px-3 py-1.5 rounded-full shadow">
              <i class="fas fa-route mr-1 text-[#E07845]"></i>
              {{ tour.numDias }} {{ tour.numDias === 1 ? (maestroStore.t('cat_dia') || 'día') : (maestroStore.t('cat_dias') || 'días') }}
            </span>
          </div>

          <div class="p-6 md:p-8">
            <!-- Título + precio "Desde" principal -->
            <div class="flex items-start justify-between gap-4 mb-3">
              <h2 class="text-xl md:text-2xl font-black text-slate-800 tracking-tight leading-tight min-w-0">
                {{ store.traducir(tour.titulo) || `Tour ${tour.version}` }}
              </h2>
              <div v-if="!tour.precioOculto && rangoPrincipal(tour)" class="text-right shrink-0">
                <p class="text-[9px] text-[#376875]/50 font-black uppercase tracking-widest">
                  {{ maestroStore.t('cat_desde') || 'Desde' }}
                </p>
                <p class="text-lg md:text-xl font-black text-[#E07845] leading-tight whitespace-nowrap tabular-nums">
                  {{ rangoPrincipal(tour)!.moneda }} {{ formatMonto(rangoPrincipal(tour)!.valor) }}
                </p>
              </div>
            </div>

            <!-- Resumen comercial i18n -->
            <div v-if="store.traducir(tour.resumen)"
                 class="prose prose-sm max-w-none text-slate-600 mb-5 prose-strong:text-[#376875] prose-a:text-[#E07845]"
                 v-html="store.traducir(tour.resumen)" />

            <!-- Rangos de precio por perfil -->
            <div v-if="!tour.precioOculto && tour.preciosDesde?.length > 1" class="flex flex-wrap gap-2 mb-6">
              <span v-for="(rango, i) in tour.preciosDesde" :key="i"
                    class="text-[11px] font-bold bg-slate-50 border border-slate-200 text-slate-600 px-3 py-1.5 rounded-full">
                {{ store.traducir(rango.titulo) }}
                <span class="font-black text-[#376875] ml-1 tabular-nums">{{ rango.moneda }} {{ formatMonto(rango.valor) }}</span>
              </span>
            </div>

            <!-- CTA -->
            <button @click="verTour(tour.version)"
                    class="group/btn relative w-full rounded-3xl flex items-center justify-between gap-4 px-6 py-5 transition-all active:scale-[0.98] shadow-lg shadow-orange-100 hover:shadow-orange-200 bg-[#E07845] hover:bg-[#D06535] overflow-hidden text-left">
              <i class="fas fa-map-signs absolute -right-3 -bottom-4 text-6xl text-white/10 group-hover/btn:scale-110 group-hover/btn:rotate-12 transition-transform duration-500"></i>
              <span class="relative z-10 min-w-0">
                <span class="block text-[14px] font-black uppercase tracking-[0.15em] text-white">
                  {{ maestroStore.t('cat_btn_ver_tour') || 'Ver programa' }}
                </span>
                <span class="block text-white/80 text-[12px] font-medium leading-tight mt-1">
                  {{ maestroStore.t('cat_cta_sub') || 'Día a día, incluye y precios' }}
                </span>
              </span>
              <i class="fas fa-arrow-right text-white relative z-10 shrink-0 group-hover/btn:translate-x-1 transition-transform"></i>
            </button>
          </div>
        </article>
      </main>
    </template>
  </div>
</template>
