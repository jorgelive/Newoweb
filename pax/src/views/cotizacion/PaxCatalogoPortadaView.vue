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

// Idioma manual pisa al idiomaCliente del catálogo (mismo criterio que la guía).
const cambiarIdioma = (event: Event) => {
  maestroStore.setIdioma((event.target as HTMLSelectElement).value);
  localStorage.setItem('paxIdiomaManual', '1');
};

const formatMonto = (valor: string): string => {
  const n = Number(valor);
  return Number.isFinite(n) ? n.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) : valor;
};

/** Rango principal (el primero) para el precio grande de la card. */
const rangoPrincipal = (tour: PaxTourResumen) => tour.preciosDesde?.[0] ?? null;

/**
 * Tinte del marcador cuando el tour no tiene foto. Rota por posición para que
 * una lista sin imágenes no parezca una pila de cajas rotas: cada tarjeta se
 * lee como distinta aunque ninguna tenga portada todavía.
 */
const TINTES = [
  'from-emerald-200 via-teal-300 to-teal-500',
  'from-sky-200 via-blue-300 to-indigo-400',
  'from-orange-100 via-amber-200 to-orange-300',
  'from-violet-200 via-purple-300 to-fuchsia-400',
];
const tinte = (i: number): string => TINTES[i % TINTES.length];

/** Texto plano del resumen para el recorte de 2 líneas de la tarjeta. */
const resumenPlano = (tour: PaxTourResumen): string =>
  store.traducir(tour.resumen).replace(/<[^>]*>/g, ' ').replace(/\s+/g, ' ').trim();
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
        <div class="max-w-3xl mx-auto px-6 py-6 md:py-8 relative z-10">
          <div class="flex items-start justify-between gap-4">
            <div class="min-w-0">
              <p class="text-[10px] font-black uppercase tracking-[0.3em] text-white/60 mb-2">
                {{ maestroStore.t('cat_titulo_hero') || 'Catálogo de Experiencias' }}
              </p>
              <h1 class="text-2xl md:text-3xl font-black tracking-tight leading-tight">{{ store.portadaCatalogo.nombre }}</h1>
              <p class="text-white/70 text-sm font-medium mt-2">
                {{ store.tours.length }} {{ store.tours.length === 1 ? (maestroStore.t('cat_tour') || 'experiencia') : (maestroStore.t('cat_tours') || 'experiencias') }}
                · {{ maestroStore.t('cat_ref') || 'Ref' }}: {{ store.portadaCatalogo.localizador }}
              </p>
            </div>

            <div v-if="maestroStore.idiomas.length > 1" class="relative shrink-0">
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
      </header>

      <!-- Cards de tours: la tarjeta ENTERA es el enlace al programa. El botón
           naranja a todo ancho se retiró — con una tarjeta por experiencia, repetir
           un CTA gigante en cada una competía con la foto y alargaba el scroll; el
           precio "Desde" con la flecha ya dice a dónde se va. -->
      <main class="max-w-3xl mx-auto px-4 md:px-6 py-8 md:py-10 pb-20 space-y-6 md:space-y-7">
        <article v-for="(tour, idx) in store.tours" :key="tour.version"
                 @click="verTour(tour.version)"
                 class="group bg-white rounded-3xl border border-slate-200/70 shadow-[0_10px_30px_rgb(15,23,42,0.06)] hover:shadow-[0_18px_45px_rgb(55,104,117,0.15)] hover:border-slate-300 hover:-translate-y-0.5 overflow-hidden cursor-pointer transition-all duration-300 active:scale-[0.995]">

          <!-- Portada -->
          <div class="relative aspect-video overflow-hidden bg-slate-100">
            <img v-if="tour.imagenPortada?.imageUrl"
                 :src="thumbUrl(tour.imagenPortada.imageUrl, 'travel_cliente')"
                 :alt="store.traducir(tour.titulo)"
                 class="w-full h-full object-cover group-hover:scale-105 transition-transform duration-700" loading="lazy">
            <div v-else class="w-full h-full bg-linear-to-br" :class="tinte(idx)"></div>

            <!-- Degradado para legibilidad del título flotante -->
            <div class="absolute inset-0 bg-linear-to-t from-black/85 via-black/20 to-transparent"></div>

            <span v-if="tour.numDias" class="absolute top-4 left-4 bg-white/95 backdrop-blur text-[#376875] text-[10px] font-black uppercase tracking-widest px-3 py-1.5 rounded-full shadow-sm">
              <i class="fas fa-route mr-1.5 text-[#E07845]"></i>
              {{ tour.numDias }} {{ tour.numDias === 1 ? (maestroStore.t('cat_dia') || 'día') : (maestroStore.t('cat_dias') || 'días') }}
            </span>

            <!-- Título flotante: sin caja, directo sobre el degradado -->
            <h2 class="absolute bottom-0 left-0 right-0 px-5 pb-4 md:px-6 md:pb-5 text-xl md:text-3xl font-black text-white tracking-tight leading-tight line-clamp-2 drop-shadow-lg">
              {{ store.traducir(tour.titulo) || `Tour ${tour.version}` }}
            </h2>
          </div>

          <!-- Cuerpo opcional. Resumen y rangos son campos que la mayoría de los tours
               no tiene (`resumen` nace como []), así que van en un contenedor único: si
               falta uno, el otro conserva el mismo aire respecto a la foto, y si faltan
               los dos el pie queda pegado a la portada — que es el caso del diseño. -->
          <div v-if="resumenPlano(tour) || (!tour.precioOculto && tour.preciosDesde?.length > 1)"
               class="px-5 md:px-6 pt-4 space-y-3">
            <!-- Resumen comercial: dos líneas, en gris, sin robar peso al pie -->
            <p v-if="resumenPlano(tour)" class="text-[13px] leading-relaxed text-slate-500 line-clamp-2">
              {{ resumenPlano(tour) }}
            </p>

            <!-- Rangos por perfil, cuando hay más de uno -->
            <div v-if="!tour.precioOculto && tour.preciosDesde?.length > 1" class="flex flex-wrap gap-1.5">
              <span v-for="(rango, i) in tour.preciosDesde" :key="i"
                    class="text-[11px] font-bold bg-slate-50 border border-slate-200 text-slate-500 px-2.5 py-1 rounded-full">
                {{ store.traducir(rango.titulo) }}
                <span class="font-black text-[#376875] ml-1 tabular-nums">{{ rango.moneda }} {{ formatMonto(rango.valor) }}</span>
              </span>
            </div>
          </div>

          <!-- Pie: la acción a la izquierda, el precio "desde" a la derecha -->
          <div class="px-5 md:px-6 py-4 flex items-center justify-between gap-4">
            <span class="text-[13px] md:text-sm font-medium text-slate-500 group-hover:text-[#376875] transition-colors min-w-0 truncate">
              {{ maestroStore.t('cat_ver_detalles') || 'Ver detalles de la experiencia' }}
            </span>

            <span v-if="!tour.precioOculto && rangoPrincipal(tour)" class="text-right shrink-0">
              <span class="block text-[9px] font-black text-slate-400 uppercase tracking-[0.2em]">
                {{ maestroStore.t('cat_desde') || 'Desde' }}
              </span>
              <span class="flex items-center gap-2 text-lg md:text-xl font-black text-[#E07845] leading-tight whitespace-nowrap tabular-nums">
                {{ rangoPrincipal(tour)!.moneda }} {{ formatMonto(rangoPrincipal(tour)!.valor) }}
                <i class="fas fa-arrow-right text-base group-hover:translate-x-1 transition-transform"></i>
              </span>
            </span>

            <!-- Sin precio visible: la flecha sigue marcando que la tarjeta lleva a algún sitio -->
            <span v-else class="shrink-0 flex items-center gap-2 text-[11px] font-black text-[#E07845] uppercase tracking-widest">
              {{ maestroStore.t('cat_btn_ver_tour') || 'Ver programa' }}
              <i class="fas fa-arrow-right group-hover:translate-x-1 transition-transform"></i>
            </span>
          </div>
        </article>
      </main>
    </template>
  </div>
</template>
