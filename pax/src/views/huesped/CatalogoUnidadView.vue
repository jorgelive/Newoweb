<script setup lang="ts">
/**
 * src/views/huesped/CatalogoUnidadView.vue
 *
 * Escaparate público de una unidad: `.pe/casita/casita-1`.
 *
 * Vista SEPARADA de GuiaUnidadView a propósito. Antes un mismo componente
 * servía las dos audiencias con un prop `mode: 'public' | 'guest'`, y el
 * resultado era una guía operativa enseñada a desconocidos con los datos
 * tachados: mal escaparate y mal control de acceso a la vez. Aquí el contrato
 * es otro —solo llegan ítems públicos, ver PmsUnidadCatalogoProvider— así que
 * el diseño puede ser comercial sin condicionales de seguridad por medio.
 *
 * El backend no manda nada bloqueado, de modo que esta vista no tiene ni un
 * `v-if` de permisos: si algo está en el payload, se pinta.
 */
import { computed, onMounted, ref, watch } from 'vue';
import { useRoute } from 'vue-router';
import VueEasyLightbox from 'vue-easy-lightbox';
import { usePaxCatalogoUnidadStore } from '@/stores/huesped/paxCatalogoUnidadStore';
import { useMaestroStore } from '@/stores/maestroStore';
import { thumbUrl } from '@/services/imageThumb';
import RichTextRenderer from '@/components/RichText/RichTextRenderer.vue';
import type { CatalogoFoto, CatalogoSeccion } from '@/types/paxCatalogoUnidadModel';

const route = useRoute();
const store = usePaxCatalogoUnidadStore();
const maestroStore = useMaestroStore();

const cargar = async () => {
  const establecimiento = String(route.params.establecimiento || '').trim();
  const unidad = String(route.params.unidad || '').trim();
  if (!establecimiento || !unidad) return;

  await maestroStore.cargarConfiguracion();
  await store.cargar(establecimiento, unidad);
};

onMounted(cargar);
watch(() => [route.params.establecimiento, route.params.unidad], cargar);

const unidad = computed(() => store.catalogo?.unidad ?? null);
const establecimiento = computed(() => unidad.value?.establecimiento ?? null);

const heroImage = computed(() => thumbUrl(unidad.value?.imageUrl, 'travel_cliente'));

const titulo = computed(() =>
    maestroStore.traducir(store.catalogo?.titulo) || unidad.value?.nombre || ''
);

const ubicacion = computed(() => {
  const partes = [establecimiento.value?.nombreComercial, establecimiento.value?.ciudad];
  return partes.filter(Boolean).join(' · ');
});

/* ─────────────────────────────────────────────────────────────
 * GALERÍA AGREGADA
 * El escaparate abre con fotos, no con texto: se juntan las de todos los
 * ítems públicos en una sola parrilla. En la guía del huésped la galería
 * vive dentro de cada ítem porque allí la foto ilustra una instrucción;
 * aquí la foto ES el argumento de venta.
 * ───────────────────────────────────────────────────────────── */
const fotos = computed<CatalogoFoto[]>(() =>
    (store.catalogo?.secciones ?? []).flatMap(s => s.items.flatMap(i => i.galeria ?? []))
);

const lightboxVisible = ref(false);
const lightboxIndex = ref(0);

const imagenesLightbox = computed(() =>
    fotos.value.map(f => thumbUrl(f.imageUrl, 'travel_cliente'))
);

const abrirFoto = (index: number) => {
  lightboxIndex.value = index;
  lightboxVisible.value = true;
};

/* ─────────────────────────────────────────────────────────────
 * SECCIONES
 * ───────────────────────────────────────────────────────────── */

/** La sección descriptiva encabeza: es la que cuenta qué es el sitio. */
const seccionesOrdenadas = computed<CatalogoSeccion[]>(() => {
  const secciones = [...(store.catalogo?.secciones ?? [])];
  return secciones.sort((a, b) => {
    const peso = (s: CatalogoSeccion) => (s.tipo === 'descriptivo' ? 0 : 1);
    return peso(a) - peso(b);
  });
});

/* ─────────────────────────────────────────────────────────────
 * CONSULTA POR WHATSAPP
 * ───────────────────────────────────────────────────────────── */
const whatsappUrl = computed(() => {
  const tel = establecimiento.value?.telefonoPrincipal;
  if (!tel) return '';

  const numero = tel.replace(/[^0-9]/g, '');
  if (!numero) return '';

  const mensaje = encodeURIComponent(
      maestroStore.t('cat_wa_mensaje', { unidad: titulo.value })
      || `Hola, me interesa ${titulo.value}. ¿Tienen disponibilidad?`
  );

  return `https://wa.me/${numero}?text=${mensaje}`;
});

const cambiarIdioma = (event: Event) => {
  maestroStore.setIdioma((event.target as HTMLSelectElement).value);
  localStorage.setItem('paxIdiomaManual', '1');
};
</script>

<template>
  <div class="min-h-screen bg-white font-sans selection:bg-[#E07845]/20 selection:text-[#376875]">

    <!-- ═══ CARGANDO ═══ -->
    <div v-if="store.loading" class="fixed inset-0 z-50 flex flex-col justify-center items-center bg-white">
      <div class="relative w-16 h-16">
        <div class="absolute inset-0 rounded-full border-4 border-slate-100"></div>
        <div class="absolute inset-0 rounded-full border-4 border-[#E07845] border-t-transparent animate-spin"></div>
      </div>
    </div>

    <!-- ═══ SIN PÁGINA ═══ -->
    <div v-else-if="store.error || !store.catalogo" class="min-h-screen flex items-center justify-center p-6">
      <div class="text-center max-w-sm">
        <div class="w-20 h-20 bg-slate-50 rounded-full flex items-center justify-center mx-auto mb-6 text-slate-300 text-3xl">
          <i class="fas fa-house-circle-xmark"></i>
        </div>
        <h1 class="text-gray-900 font-black text-xl mb-2">
          {{ maestroStore.t('cat_no_encontrado') || 'Alojamiento no disponible' }}
        </h1>
        <p class="text-slate-500 text-sm leading-relaxed">
          {{ maestroStore.t('cat_no_encontrado_sub') || 'Este enlace ya no está activo o el alojamiento no tiene información publicada.' }}
        </p>
      </div>
    </div>

    <template v-else>
      <!-- ═══ HERO ═══ -->
      <header class="relative h-[70vh] min-h-100 w-full overflow-hidden">
        <div
            v-if="heroImage"
            class="absolute inset-0 bg-cover bg-center scale-105"
            :style="{ backgroundImage: `url(${heroImage})` }"
        ></div>
        <div v-else class="absolute inset-0 bg-linear-to-br from-[#376875] to-[#0F172A]"></div>
        <div class="absolute inset-0 bg-linear-to-t from-black/85 via-black/25 to-black/40"></div>

        <!-- Selector de idioma -->
        <div class="absolute top-6 right-6 z-20">
          <select
              :value="maestroStore.idiomaActual"
              @change="cambiarIdioma"
              class="appearance-none bg-white/15 backdrop-blur-md border border-white/25 text-white font-black text-[10px] uppercase tracking-widest rounded-xl pl-4 pr-9 py-2.5 focus:outline-none focus:bg-white focus:text-[#376875] cursor-pointer transition-colors hover:bg-white/25"
          >
            <option v-for="lang in maestroStore.idiomas" :key="lang.id" :value="lang.id" class="text-gray-800">
              {{ lang.bandera }} {{ lang.id.toUpperCase() }}
            </option>
          </select>
          <div class="absolute right-3.5 top-1/2 -translate-y-1/2 pointer-events-none text-white/70">
            <i class="fas fa-chevron-down text-[8px]"></i>
          </div>
        </div>

        <div class="absolute bottom-0 left-0 right-0 p-7 md:p-14 text-white max-w-5xl mx-auto">
          <p v-if="ubicacion" class="text-[11px] font-black uppercase tracking-[0.25em] text-[#E07845] mb-3 flex items-center gap-2">
            <i class="fas fa-location-dot"></i> {{ ubicacion }}
          </p>

          <h1 class="text-4xl md:text-6xl font-black tracking-tight leading-[1.05] drop-shadow-lg mb-5">
            {{ titulo }}
          </h1>

          <div class="flex flex-wrap items-center gap-2.5">
            <span
                v-if="unidad?.capacidad"
                class="bg-white/15 backdrop-blur-md border border-white/20 rounded-full px-4 py-2 text-xs font-bold flex items-center gap-2"
            >
              <i class="fas fa-user-group text-[#E07845]"></i>
              {{ maestroStore.t('cat_hasta', { n: String(unidad.capacidad) }) || `Hasta ${unidad.capacidad} huéspedes` }}
            </span>
            <span
                v-if="fotos.length"
                class="bg-white/15 backdrop-blur-md border border-white/20 rounded-full px-4 py-2 text-xs font-bold flex items-center gap-2"
            >
              <i class="fas fa-images text-[#E07845]"></i>
              {{ fotos.length }} {{ maestroStore.t('cat_fotos') || 'fotos' }}
            </span>
          </div>
        </div>
      </header>

      <main class="max-w-5xl mx-auto px-5 md:px-8 pb-32">

        <!-- ═══ GALERÍA ═══ -->
        <section v-if="fotos.length" class="-mt-10 relative z-10 mb-16">
          <div class="grid grid-cols-2 md:grid-cols-3 gap-3">
            <button
                v-for="(foto, idx) in fotos"
                :key="idx"
                @click="abrirFoto(idx)"
                class="group relative aspect-4/3 rounded-2xl overflow-hidden bg-slate-100 shadow-lg shadow-slate-300/30 ring-1 ring-black/5 cursor-zoom-in"
                :class="idx === 0 ? 'col-span-2 row-span-2 aspect-square md:aspect-4/3' : ''"
            >
              <img
                  :src="thumbUrl(foto.imageUrl, idx === 0 ? 'travel_cliente' : 'travel_thumb_admin')"
                  :alt="maestroStore.traducir(foto.descripcion) || titulo"
                  loading="lazy"
                  class="w-full h-full object-cover transition-transform duration-700 group-hover:scale-110"
              />
              <div
                  v-if="foto.descripcion?.length"
                  class="absolute inset-0 bg-linear-to-t from-black/75 via-transparent to-transparent opacity-0 group-hover:opacity-100 transition-opacity duration-300 flex items-end p-4"
              >
                <span class="text-white text-xs font-bold text-left line-clamp-2 drop-shadow">
                  {{ maestroStore.traducir(foto.descripcion) }}
                </span>
              </div>
            </button>
          </div>
        </section>

        <!-- ═══ SECCIONES ═══ -->
        <section
            v-for="seccion in seccionesOrdenadas"
            :key="seccion.id"
            class="mb-16"
        >
          <div class="flex items-center gap-4 mb-7">
            <span
                v-if="seccion.icono"
                class="w-12 h-12 rounded-2xl bg-[#376875]/8 text-[#376875] flex items-center justify-center text-xl shrink-0"
            >
              <i :class="['fas', seccion.icono]"></i>
            </span>
            <div class="min-w-0">
              <h2 class="text-2xl md:text-3xl font-black text-gray-900 tracking-tight leading-tight">
                {{ maestroStore.traducir(seccion.titulo) }}
              </h2>
              <p v-if="maestroStore.traducir(seccion.subtitulo)" class="text-slate-500 text-sm font-medium mt-1">
                {{ maestroStore.traducir(seccion.subtitulo) }}
              </p>
            </div>
          </div>

          <div class="space-y-8">
            <article
                v-for="item in seccion.items"
                :key="item['@id']"
                class="rounded-3xl border border-slate-100 bg-slate-50/60 p-6 md:p-8"
                :class="item.tipo === 'alert' ? 'border-amber-200 bg-amber-50/70' : ''"
            >
              <h3 class="font-black text-gray-900 text-lg mb-4 flex items-center gap-3">
                <i
                    v-if="item.icono || item.tipo === 'alert'"
                    :class="['fas', item.icono || 'fa-triangle-exclamation', item.tipo === 'alert' ? 'text-amber-500' : 'text-[#E07845]']"
                ></i>
                {{ maestroStore.traducir(item.titulo) }}
              </h3>

              <!-- Sin wifiData: en el catálogo no hay estancia ni credenciales
                   que servir. El renderer solo expande los bloques de
                   maquetación ({{ video: }}, {{ img: }}, {{ map: }}). -->
              <RichTextRenderer :content="maestroStore.traducir(item.descripcion)" />

              <a
                  v-if="item.urlBoton && maestroStore.traducir(item.labelBoton)"
                  :href="item.urlBoton"
                  target="_blank"
                  rel="noopener noreferrer"
                  class="mt-6 inline-flex items-center gap-2 bg-white border border-slate-200 text-[#376875] font-black text-sm px-5 py-3 rounded-xl shadow-sm hover:shadow-md hover:border-[#376875]/30 transition-all"
              >
                {{ maestroStore.traducir(item.labelBoton) }}
                <i class="fas fa-arrow-right text-xs"></i>
              </a>
            </article>
          </div>
        </section>

        <div class="text-center pt-4">
          <p class="text-[9px] text-[#376875]/40 uppercase tracking-[0.3em] font-black">
            {{ maestroStore.t('com_powered_by') || 'Powered by OpenPeru' }}
          </p>
        </div>
      </main>

      <!-- ═══ CTA FIJO ═══ -->
      <div
          v-if="whatsappUrl"
          class="fixed bottom-0 left-0 right-0 z-40 p-4 bg-linear-to-t from-white via-white to-transparent pt-10"
      >
        <a
            :href="whatsappUrl"
            target="_blank"
            rel="noopener"
            class="max-w-md mx-auto flex items-center justify-center gap-3 bg-[#E07845] hover:bg-[#D06535] text-white font-black py-4 rounded-2xl shadow-xl shadow-orange-300/40 transition-all active:scale-[0.98]"
        >
          <i class="fab fa-whatsapp text-xl"></i>
          {{ maestroStore.t('cat_consultar') || 'Consultar disponibilidad' }}
        </a>
      </div>

      <VueEasyLightbox
          :visible="lightboxVisible"
          :imgs="imagenesLightbox"
          :index="lightboxIndex"
          :move-disabled="true"
          @hide="lightboxVisible = false"
      />
    </template>
  </div>
</template>
