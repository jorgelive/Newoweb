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
import type { CatalogoBloque, CatalogoFoto, CatalogoItem, CatalogoUnidadHermana } from '@/types/paxCatalogoUnidadModel';

const route = useRoute();
const store = usePaxCatalogoUnidadStore();
const maestroStore = useMaestroStore();

/**
 * `store.loading` NO cubre el arranque: nace en `false` con `catalogo` aún en
 * `null`, y esa combinación es indistinguible de "no encontrado". Como aquí se
 * espera primero a `cargarConfiguracion()`, la ventana no era un fotograma sino
 * toda esa petición: el visitante veía "Alojamiento no disponible" y después el
 * spinner. Mismo flag y mismo motivo que en HuespedGuiaView.
 */
const cargando = ref(true);

const cargar = async () => {
  const establecimiento = String(route.params.establecimiento || '').trim();
  const unidad = String(route.params.unidad || '').trim();
  if (!establecimiento || !unidad) {
    cargando.value = false;
    return;
  }

  cargando.value = true;
  try {
    await maestroStore.cargarConfiguracion();
    await store.cargar(establecimiento, unidad);
  } finally {
    cargando.value = false;
  }
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
/**
 * Las fotos del bloque `fotos` abren la página, y ese bloque NO se vuelve a
 * pintar más abajo: su contenido ES esta parrilla.
 *
 * Antes se agregaban las galerías de TODOS los bloques aquí arriba y además se
 * pintaba cada bloque como tarjeta. Como la tarjeta solo renderiza el texto del
 * ítem, un ítem de tipo álbum —cuyo contenido son las fotos— salía como una
 * tarjeta VACÍA bajo el título «Fotos», con sus imágenes ya mostradas arriba.
 */
const fotos = computed<CatalogoFoto[]>(() =>
    (store.catalogo?.bloques ?? [])
        .filter(b => b.tipo === 'fotos')
        .flatMap(b => b.items.flatMap(i => i.galeria ?? []))
);

/**
 * Galería DENTRO de una tarjeta. Solo en los ítems de tipo `album`: en los demás
 * las imágenes van embebidas en el texto por RichTextRenderer, y repetirlas
 * abajo las mostraría dos veces. Misma regla que en la guía del huésped.
 */
const galeriaDeItem = (item: CatalogoItem): CatalogoFoto[] =>
    item.tipo === 'album' ? (item.galeria ?? []) : [];

const lightboxVisible = ref(false);
const lightboxIndex = ref(0);

/**
 * Imágenes del lightbox. Es un `ref` y no un `computed` sobre la parrilla de
 * arriba porque hay DOS orígenes: esa parrilla y la galería propia de un ítem
 * dentro de un bloque. Con un computed fijo, tocar una foto de un álbum abría
 * la imagen equivocada — el índice era del otro array.
 */
const imagenesLightbox = ref<string[]>([]);

const abrirLightbox = (imagenes: CatalogoFoto[], index: number) => {
  imagenesLightbox.value = imagenes.map(f => thumbUrl(f.imageUrl, 'travel_cliente'));
  lightboxIndex.value = index;
  lightboxVisible.value = true;
};

/** Parrilla de apertura. */
const abrirFoto = (index: number) => abrirLightbox(fotos.value, index);

/** Galería de un ítem concreto dentro de un bloque. */
const abrirFotoDeItem = (item: CatalogoItem, index: number) => abrirLightbox(galeriaDeItem(item), index);

/* ─────────────────────────────────────────────────────────────
 * BLOQUES
 *
 * Ya NO se ordenan aquí. Antes había que empujar la sección `descriptivo` al
 * principio a mano, que era un parche sobre una jerarquía ajena: las secciones
 * venían de la guía, escrita para otra audiencia. Ahora el orden lo fija el
 * enum PmsCatalogoBloque y llega resuelto.
 * ───────────────────────────────────────────────────────────── */
const bloques = computed<CatalogoBloque[]>(() => store.catalogo?.bloques ?? []);

/** Los que se pintan como sección: todos menos `fotos`, que ya es la parrilla de apertura. */
const bloquesEnCuerpo = computed<CatalogoBloque[]>(() => bloques.value.filter(b => b.tipo !== 'fotos'));

/** Título del bloque, por i18n: el backend manda el tipo, no el texto. */
const tituloBloque = (tipo: string): string =>
    maestroStore.t(`cat_bloque_${tipo}`) || BLOQUE_FALLBACK[tipo] || '';

/** Respaldo en español si falta la semilla i18n. */
const BLOQUE_FALLBACK: Record<string, string> = {
  destacado: '',
  descripcion: 'El alojamiento',
  fotos: 'Fotos',
  servicios: 'Servicios y equipamiento',
  ubicacion: 'Ubicación',
  normas: 'Antes de reservar',
};

/** El bloque destacado encabeza sin título: es el gancho, no una sección más. */
const esDestacado = (b: CatalogoBloque): boolean => b.tipo === 'destacado';

/* ─────────────────────────────────────────────────────────────
 * OTRAS CASITAS
 * El backend ya descartó las que no tienen escaparate publicado, así que
 * cada tarjeta enlaza a una página que existe (ver hermanasPublicadas()).
 * ───────────────────────────────────────────────────────────── */
const hermanas = computed<CatalogoUnidadHermana[]>(() => store.catalogo?.unidadesHermanas ?? []);

/** El establecimiento es el mismo, así que sale del parámetro de la ruta actual. */
const rutaHermana = (h: CatalogoUnidadHermana) => ({
  name: 'catalogo_unidad',
  params: { establecimiento: String(route.params.establecimiento || ''), unidad: h.slug },
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
    <div v-if="cargando || store.loading" class="fixed inset-0 z-50 flex flex-col justify-center items-center bg-white">
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
              class="appearance-none bg-black/45 backdrop-blur-md border border-white/30 text-white font-black text-[11px] uppercase tracking-widest rounded-xl pl-4 pr-9 py-2.5 shadow-lg focus:outline-none focus:bg-white focus:text-[#376875] cursor-pointer transition-colors hover:bg-black/60"
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
        <!-- BLOQUES. El título lo pone el bloque (por i18n), no el contenido: los
             ítems que vienen de la guía traen títulos operativos («Entrada a la
             casa») que aquí no pintan nada. Regla superior para separarlos; el
             primero no la lleva porque ya lo separa la galería. -->
        <section
            v-for="(bloque, iBloque) in bloquesEnCuerpo"
            :key="bloque.tipo"
            class="mb-16"
            :class="iBloque > 0 ? 'pt-12 border-t border-slate-200' : ''"
        >
          <h2
              v-if="!esDestacado(bloque) && tituloBloque(bloque.tipo)"
              class="text-2xl md:text-3xl font-black text-gray-900 tracking-tight leading-tight mb-7"
          >
            {{ tituloBloque(bloque.tipo) }}
          </h2>

          <div class="space-y-8">
            <article
                v-for="item in bloque.items"
                :key="item['@id']"
                class="rounded-3xl border border-slate-100 bg-slate-50/60 p-6 md:p-8"
                :class="[
                  item.tipo === 'alert' ? 'border-amber-200 bg-amber-50/70' : '',
                  esDestacado(bloque) ? 'border-[#E07845]/25 bg-[#E07845]/5' : '',
                ]"
            >
              <!-- El título del ítem solo se pinta si aporta algo sobre el del
                   bloque: con un único ítem, repetiría el encabezado. -->
              <h3
                  v-if="bloque.items.length > 1 || esDestacado(bloque)"
                  class="font-black text-gray-900 text-lg mb-4 flex items-center gap-3"
              >
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

              <!-- Galería del propio ítem. Faltaba por completo: la vista solo
                   agregaba las fotos arriba, así que un álbum dentro de otro
                   bloque no se veía en ninguna parte. -->
              <div v-if="galeriaDeItem(item).length" class="mt-6 grid grid-cols-2 md:grid-cols-3 gap-3">
                <button
                    v-for="(foto, i) in galeriaDeItem(item)"
                    :key="i"
                    @click="abrirFotoDeItem(item, i)"
                    class="group relative aspect-4/3 rounded-2xl overflow-hidden bg-slate-100 ring-1 ring-black/5 cursor-zoom-in"
                >
                  <img
                      :src="thumbUrl(foto.imageUrl, 'travel_thumb_admin')"
                      :alt="maestroStore.traducir(foto.descripcion) || titulo"
                      loading="lazy"
                      class="w-full h-full object-cover transition-transform duration-500 group-hover:scale-105"
                  />
                </button>
              </div>

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

        <!-- ═══ OTRAS CASITAS ═══
             Sin esto el catálogo era un callejón sin salida: quien entraba a una
             casita no tenía forma de ver las demás salvo adivinando la URL. -->
        <section v-if="hermanas.length" class="mb-16 pt-12 border-t border-slate-200">
          <h2 class="text-2xl md:text-3xl font-black text-gray-900 tracking-tight mb-7">
            {{ maestroStore.t('cat_otras_unidades') || 'Otros alojamientos' }}
          </h2>

          <div class="grid grid-cols-2 md:grid-cols-3 gap-4">
            <RouterLink
                v-for="h in hermanas"
                :key="h.slug"
                :to="rutaHermana(h)"
                class="group block rounded-3xl overflow-hidden bg-slate-50 border border-slate-100 hover:border-[#376875]/30 hover:shadow-lg transition-all"
            >
              <div class="aspect-4/3 bg-slate-100 overflow-hidden">
                <img
                    v-if="h.imageUrl"
                    :src="thumbUrl(h.imageUrl, 'travel_thumb_admin')"
                    :alt="h.nombre"
                    loading="lazy"
                    class="w-full h-full object-cover transition-transform duration-700 group-hover:scale-110"
                />
                <div v-else class="w-full h-full flex items-center justify-center text-slate-300 text-2xl">
                  <i class="fas fa-house"></i>
                </div>
              </div>
              <div class="p-4">
                <p class="font-black text-gray-900 text-sm leading-tight truncate group-hover:text-[#376875] transition-colors">
                  {{ h.nombre }}
                </p>
                <p v-if="h.capacidad" class="text-[11px] font-bold text-slate-400 mt-1 flex items-center gap-1.5">
                  <i class="fas fa-user-group text-[#E07845]"></i>
                  {{ maestroStore.t('cat_hasta', { n: String(h.capacidad) }) || `Hasta ${h.capacidad} huéspedes` }}
                </p>
              </div>
            </RouterLink>
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
