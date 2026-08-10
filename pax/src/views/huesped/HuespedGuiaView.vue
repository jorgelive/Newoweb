<script setup lang="ts">
/**
 * src/views/huesped/HuespedGuiaView.vue
 *
 * Guía de una estancia: `/huesped/reserva/:localizador/:unidad`.
 *
 * Hermana de CatalogoUnidadView, no la misma vista con un prop `mode`. Ese
 * diseño —un componente para las dos audiencias— fue justo el que dejó la guía
 * operativa a la vista de desconocidos con los datos tachados (docs/PmsGuiaHuesped.md §2).
 *
 * **Navegación de 3 niveles** (heredada de la GuiaUnidadView anterior, que era
 * lo que el huésped ya conocía):
 *
 *   Nivel 1  Portada    hero + ingreso destacado + rejilla de secciones
 *   Nivel 2  Sección    lista de sus ítems (o el ítem directo, si es el único)
 *   Nivel 3  Ítem       contenido completo: texto, widgets y galería
 *
 * Los niveles 2 y 3 son **capas que entran deslizándose**, no rutas nuevas: el
 * estado vive en la query (`?section=…&item=…`) para que el botón «atrás» del
 * móvil retroceda un nivel en vez de salirse de la guía.
 *
 * Aquí **no hay ni un `v-if` de permisos**: el backend poda el árbol y resuelve
 * los `{{ placeholders }}` antes de enviarlo. Si un código está en el payload,
 * es que se puede enseñar. `acceso.estado` solo elige el aviso de cabecera, y
 * `redesWifi` llega vacío mientras la ventana esté cerrada.
 */
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import VueEasyLightbox from 'vue-easy-lightbox';
import { usePaxHuespedGuiaStore } from '@/stores/huesped/paxHuespedGuiaStore';
import { useMaestroStore } from '@/stores/maestroStore';
import { thumbUrl } from '@/services/imageThumb';
import RichTextRenderer from '@/components/RichText/RichTextRenderer.vue';
import type { GuiaItem, GuiaSeccion } from '@/types/paxHuespedGuiaModel';

const props = defineProps<{
  localizador?: string;
  unidad?: string;
}>();

const route = useRoute();
const router = useRouter();
const store = usePaxHuespedGuiaStore();
const maestroStore = useMaestroStore();

const homeScroll = ref<HTMLElement | null>(null);

/**
 * `store.loading` no cubre el arranque: entre el mount y el fin de
 * `cargarConfiguracion()` sigue en false con `guia` aún null, y esa combinación
 * es idéntica a "no encontrada" — el huésped veía el error un instante antes
 * del spinner. Este flag mantiene el estado de carga durante TODO el ciclo.
 */
const cargando = ref(true);

const cargar = async () => {
  const localizador = String(props.localizador || route.params.localizador || '').trim().toUpperCase();
  if (!localizador) return;

  cargando.value = true;
  try {
    await maestroStore.cargarConfiguracion();
    await store.cargar(localizador, String(props.unidad || route.params.unidad || '').trim() || null);
  } finally {
    cargando.value = false;
  }
};

onMounted(cargar);
// Solo al cambiar de ESTANCIA. Observar `route.params` entero recargaría —con
// su spinner— en cada navegación de sección o de ítem.
watch(() => [props.localizador, props.unidad], cargar);
// Los códigos no se quedan en memoria al salir (el store tampoco persiste).
onBeforeUnmount(() => store.limpiar());

const guia = computed(() => store.guia);
const contexto = computed(() => guia.value?.contexto ?? {});
const secciones = computed<GuiaSeccion[]>(() => guia.value?.secciones ?? []);

const nombrePila = computed(() => (contexto.value.guest_name || '').split(' ')[0]);
const nombreUnidad = computed(() => contexto.value.unit_name || guia.value?.unidad?.nombre || '');
const heroImage = computed(() => thumbUrl(guia.value?.unidad?.imageUrl, 'travel_cliente'));

/* ─────────────────────────────────────────────────────────────
 * CONFIG VISUAL DE SECCIONES (color por tipo)
 * ───────────────────────────────────────────────────────────── */
type SeccionColor = { color: string; bg: string };

const TIPO_COLOR: Record<string, SeccionColor> = {
  ingreso:     { color: '#376875', bg: '#376875' },
  descriptivo: { color: '#854F0B', bg: '#FAEEDA' },
  normas:      { color: '#3C3489', bg: '#EEEDFE' },
};

const PALETA_FALLBACK: SeccionColor[] = [
  { color: '#0F6E56', bg: '#E1F5EE' },
  { color: '#993C1D', bg: '#FAECE7' },
  { color: '#185FA5', bg: '#E6F1FB' },
  { color: '#993556', bg: '#FBEAF0' },
];

const colorDe = (seccion: GuiaSeccion, index: number): SeccionColor =>
    (seccion.tipo && TIPO_COLOR[seccion.tipo]) || PALETA_FALLBACK[index % PALETA_FALLBACK.length];

const ICONO_TIPO: Record<string, string> = {
  card:  'fa-file-lines',
  album: 'fa-images',
  alert: 'fa-triangle-exclamation',
};
const iconoItem = (item: GuiaItem): string => item.icono || ICONO_TIPO[item.tipo] || 'fa-circle-info';

/**
 * La rejilla de fotos SOLO se pinta en los ítems de tipo `album`.
 *
 * En los demás tipos (`card`, `alert`…) las imágenes de `galeria` ya van
 * embebidas dentro del texto por RichTextRenderer, así que volver a pintarlas
 * abajo las mostraba dos veces. `galeria` sigue poblada en esos ítems porque es
 * de donde el editor toma las imágenes que inserta en línea; el tipo es lo que
 * decide si además existe una galería como bloque propio.
 */
const tieneGaleria = (item: GuiaItem): boolean => item.tipo === 'album' && !!item.galeria?.length;

/* ─────────────────────────────────────────────────────────────
 * SECCIÓN DESTACADA (ingreso) + RESTO + ATAJO DESCRIPTIVO (foto)
 * ───────────────────────────────────────────────────────────── */
const seccionDestacada = computed(() => secciones.value.find(s => s.tipo === 'ingreso') ?? null);
const seccionesRestantes = computed(() => secciones.value.filter(s => s !== seccionDestacada.value));
const seccionDescriptiva = computed(() => secciones.value.find(s => s.tipo === 'descriptivo') ?? null);

const abrirDescriptivo = () => {
  if (seccionDescriptiva.value) abrirSeccion(seccionDescriptiva.value);
};

/** Pasos del ingreso: los primeros N como botones directos + «mostrar más». */
const PREVIEW_INGRESO = 3;
const mostrarTodosIngreso = ref(false);

const itemsDestacadaTodos = computed<GuiaItem[]>(() => seccionDestacada.value?.items ?? []);
const itemsDestacadaVisibles = computed(() =>
    mostrarTodosIngreso.value ? itemsDestacadaTodos.value : itemsDestacadaTodos.value.slice(0, PREVIEW_INGRESO)
);
const itemsOcultosCount = computed(() => Math.max(0, itemsDestacadaTodos.value.length - PREVIEW_INGRESO));

/* ─────────────────────────────────────────────────────────────
 * NAVEGACIÓN DE 3 NIVELES: portada → sección → ítem
 * El nivel vive en la query, no en el componente: así el «atrás» del
 * navegador (y el gesto del móvil) retrocede un nivel en vez de salir.
 * ───────────────────────────────────────────────────────────── */
const seccionActiva = computed<GuiaSeccion | null>(() => {
  const id = route.query.section as string;
  return id ? (secciones.value.find(s => s.id === id) ?? null) : null;
});

const itemsSeccionActiva = computed<GuiaItem[]>(() => seccionActiva.value?.items ?? []);
const esItemUnico = computed(() => itemsSeccionActiva.value.length === 1);

const itemActivo = computed<GuiaItem | null>(() => {
  const id = route.query.item as string;
  return id ? (itemsSeccionActiva.value.find(it => it['@id'] === id) ?? null) : null;
});

const abrirSeccion = (seccion: GuiaSeccion) => {
  router.push({ query: { section: seccion.id } });
};

/* ─────────────────────────────────────────────────────────────
 * ÍTEM BLOQUEADO: globo de aviso al tocar el candado
 *
 * El backend ANUNCIA estos ítems (con candado) en vez de esconderlos, para que
 * el huésped sepa que las instrucciones existen y qué le falta para verlas
 * (docs/PmsGuiaHuesped.md §3).
 *
 * El ítem SÍ se abre: dentro, el texto se lee entero y solo los datos
 * protegidos aparecen como `[Disponible al confirmar]` en itálica, en su sitio
 * dentro de la frase. Eso enseña qué va a recibir y dónde, que es más útil que
 * una puerta cerrada. El globo cuelga del candado, no de la fila.
 * ───────────────────────────────────────────────────────────── */
const tooltipBloqueo = ref<{ x: number; y: number; texto: string } | null>(null);
let tooltipTimer: ReturnType<typeof setTimeout> | undefined;

/** Ancho del globo; se usa también para que no se salga por los lados. */
const TOOLTIP_ANCHO = 220;

/**
 * Qué le falta al huésped para abrir este ítem. Espejo de
 * PmsGuiaMensajes::bloqueo(), que resuelve lo mismo para los placeholders del
 * cuerpo: los dos lados tienen que contar la misma historia.
 */
const mensajeBloqueoItem = computed<string>(() => {
  const acceso = guia.value?.acceso;

  switch (acceso?.estado) {
    case 'pendiente':
      return acceso.liberaEn
          ? (maestroStore.t('gui_bloqueo_fecha', { fecha: fechaLarga(acceso.liberaEn) })
              || `Disponible el ${fechaLarga(acceso.liberaEn)}`)
          : (maestroStore.t('gui_bloqueo_pronto') || 'Disponible pronto');

    case 'expirada':
      return maestroStore.t('gui_bloqueo_expirada') || 'Ya no disponible: la estancia terminó';

    default:
      // `sin_pago` es el único con salida en manos del huésped.
      return maestroStore.t('gui_bloqueo_confirmar') || 'Disponible al confirmar tu reserva';
  }
});

const avisarBloqueo = (event: MouseEvent) => {
  tooltipBloqueo.value = {
    x: Math.min(Math.max(8, event.clientX - TOOLTIP_ANCHO / 2), window.innerWidth - TOOLTIP_ANCHO - 8),
    y: event.clientY + 14,
    texto: mensajeBloqueoItem.value,
  };

  clearTimeout(tooltipTimer);
  tooltipTimer = setTimeout(() => (tooltipBloqueo.value = null), 3200);
};

onBeforeUnmount(() => clearTimeout(tooltipTimer));

const abrirItem = (seccion: GuiaSeccion, item: GuiaItem) => {
  router.push({ query: { section: seccion.id, item: item['@id'] } });
};

/** Retrocede un nivel (ítem → sección → portada) respetando el historial. */
const volver = () => {
  if (window.history.state?.back) { router.back(); return; }
  if (route.query.item) {
    router.replace({ query: { section: route.query.section as string } });
  } else {
    router.replace({ query: {} });
  }
};

const volverAReserva = () => {
  router.push({
    name: 'pms_reserva',
    params: { localizador: props.localizador || route.params.localizador },
  });
};

/**
 * Cada cambio de nivel arranca arriba del todo.
 *
 * **La ventana también, y es lo que de verdad importa.** El contenedor es
 * `min-h-screen`, así que en móvil el `h-full` del nivel 1 no acota nada: quien
 * scrollea es la ventana y el contenedor crece con el contenido. Las capas de
 * los niveles 2 y 3 son `absolute inset-0`, o sea que se montan **arriba del
 * contenedor** — si la ventana sigue a media página, el huésped ve el hueco
 * blanco de debajo de una capa más corta que la portada. De ahí el
 * `window.scrollTo`, sin `smooth`: el salto tiene que ocurrir en el mismo
 * fotograma en que aparece la capa, o el blanco se ve igual durante la animación.
 */
const resetScroll = async () => {
  await nextTick();
  window.scrollTo({ top: 0, behavior: 'auto' });
  const opciones: ScrollToOptions = { top: 0, behavior: 'auto' };
  document.getElementById('nivel2-scroll')?.scrollTo(opciones);
  document.getElementById('nivel3-scroll')?.scrollTo(opciones);
  homeScroll.value?.scrollTo(opciones);
};
watch(() => [route.query.section, route.query.item], resetScroll);

/* ─────────────────────────────────────────────────────────────
 * GALERÍA DEL ÍTEM (lightbox)
 * ───────────────────────────────────────────────────────────── */
const lightboxVisible = ref(false);
const lightboxIndex = ref(0);
const lightboxImgs = ref<string[]>([]);

const abrirFoto = (item: GuiaItem, index: number) => {
  lightboxImgs.value = (item.galeria ?? []).map(f => thumbUrl(f.imageUrl, 'travel_cliente'));
  lightboxIndex.value = index;
  lightboxVisible.value = true;
};

/* ─────────────────────────────────────────────────────────────
 * AVISO DE ACCESO
 * El backend ya recortó lo que no toca; esto solo explica POR QUÉ falta.
 * Sin explicación, una guía sin códigos parece rota.
 * ───────────────────────────────────────────────────────────── */
const fechaLarga = (iso?: string | null): string => {
  if (!iso) return '';
  return new Date(iso).toLocaleString(maestroStore.idiomaActual, {
    day: 'numeric', month: 'long', hour: '2-digit', minute: '2-digit',
    timeZone: 'America/Lima',
  });
};

/**
 * Hay algo bloqueado en la guía (candadito en las tarjetas de sección).
 *
 * Cubre los tres estados con contenido restringido, no solo `pendiente`: con
 * cuatro niveles de visibilidad, `sin_pago` y `expirada` también dejan ítems
 * anunciados con candado.
 */
const accesoRestringido = computed(() => {
  const estado = guia.value?.acceso?.estado;
  return !!estado && estado !== 'activa' && estado !== 'publico';
});

const avisoAcceso = computed<{ icono: string; titulo: string; texto: string; clase: string } | null>(() => {
  const acceso = guia.value?.acceso;
  if (!acceso || acceso.estado === 'activa' || acceso.estado === 'publico') return null;

  switch (acceso.estado) {
    case 'pendiente':
      return {
        icono: 'fa-lock',
        clase: 'bg-orange-50 border-orange-100 text-orange-900',
        titulo: maestroStore.t('gui_aviso_previa') || 'Datos protegidos',
        texto: acceso.liberaEn
            ? (maestroStore.t('gui_acceso_pendiente_fecha', { fecha: fechaLarga(acceso.liberaEn) })
                || `Los códigos de acceso y el WiFi se muestran aquí a partir del ${fechaLarga(acceso.liberaEn)}.`)
            : (maestroStore.t('gui_acceso_pendiente') || 'Los códigos de acceso se mostrarán aquí poco antes de tu llegada.'),
      };
    case 'sin_pago':
      return {
        icono: 'fa-circle-exclamation',
        clase: 'bg-slate-50 border-slate-200 text-slate-700',
        titulo: maestroStore.t('gui_aviso_previa') || 'Datos protegidos',
        texto: maestroStore.t('gui_acceso_no_confirmada')
            || 'Tu reserva aún no está confirmada. Al confirmarse verás aquí las instrucciones de entrada.',
      };
    case 'expirada':
      return {
        icono: 'fa-flag-checkered',
        clase: 'bg-slate-50 border-slate-200 text-slate-500',
        titulo: maestroStore.t('gui_acceso_expirada_titulo') || 'Estancia finalizada',
        texto: maestroStore.t('gui_acceso_expirada')
            || 'Esta estancia ya terminó. La información de acceso ya no está disponible.',
      };
    default:
      return null;
  }
});

/* ─────────────────────────────────────────────────────────────
 * ANFITRIÓN
 * ───────────────────────────────────────────────────────────── */
const hostName = computed(() => contexto.value.host_name || '');
const hostPhone = computed(() => contexto.value.host_whatsapp || '');

const hostInitials = computed(() => {
  const partes = hostName.value.trim().split(/\s+/).filter(Boolean);
  return partes.length ? partes.slice(0, 2).map(p => p[0]).join('').toUpperCase() : 'AN';
});

const whatsappUrl = computed(() => {
  const numero = hostPhone.value.replace(/[^0-9]/g, '');
  if (!numero) return '';
  const msg = encodeURIComponent(
      maestroStore.t('gui_wa_mensaje') || `Hola, soy huésped de ${nombreUnidad.value}.`
  );
  return `https://wa.me/${numero}?text=${msg}`;
});

/** El idioma elegido a mano pisa al del establecimiento (mismo criterio que la guía de cotización). */
const cambiarIdioma = (event: Event) => {
  maestroStore.setIdioma((event.target as HTMLSelectElement).value);
  localStorage.setItem('paxIdiomaManual', '1');
};

const mensajeError = computed(() => {
  switch (store.error) {
    case 'ERR_THROTTLE':
      return maestroStore.t('gui_error_throttle') || 'Demasiados intentos. Vuelve a abrir el enlace en unos minutos.';
    case 'ERR_404':
      return maestroStore.t('gui_error_no_disponible') || 'Este enlace ya no está activo o la estancia no tiene guía publicada.';
    default:
      return maestroStore.t('gui_error_carga') || 'No pudimos cargar tu guía. Revisa tu conexión e inténtalo de nuevo.';
  }
});
</script>

<template>
  <div class="min-h-screen bg-[#EEECE6] font-sans selection:bg-[#376875]/20 selection:text-[#376875]">

    <!-- ═══ CARGANDO ═══ -->
    <div v-if="cargando || store.loading" class="fixed inset-0 z-50 flex flex-col justify-center items-center bg-white/90 backdrop-blur-md">
      <div class="relative w-16 h-16">
        <div class="absolute inset-0 rounded-full border-4 border-gray-100"></div>
        <div class="absolute inset-0 rounded-full border-4 border-[#376875] border-t-transparent animate-spin"></div>
      </div>
      <p class="mt-4 text-[10px] font-black uppercase tracking-[0.3em] text-gray-400 animate-pulse">
        {{ maestroStore.t('gui_cargando') || 'Cargando...' }}
      </p>
    </div>

    <!-- ═══ SIN GUÍA ═══ -->
    <div v-else-if="store.error || !guia" class="min-h-screen flex items-center justify-center p-6">
      <div class="bg-white p-8 rounded-4xl shadow-xl text-center max-w-sm mx-auto border border-slate-50">
        <div class="w-16 h-16 bg-slate-50 rounded-full flex items-center justify-center mx-auto mb-4 text-slate-300 text-2xl">
          <i class="fas fa-map-location-dot"></i>
        </div>
        <h3 class="font-black text-gray-900 mb-2">{{ maestroStore.t('gui_error_titulo') || 'Guía no disponible' }}</h3>
        <p class="text-gray-500 text-sm mb-6">{{ mensajeError }}</p>
        <button @click="volverAReserva"
                class="w-full py-3 bg-[#376875] text-white rounded-xl font-bold text-xs uppercase tracking-widest shadow-lg">
          <i class="fas fa-arrow-left mr-2"></i>{{ maestroStore.t('gui_btn_volver_menu') || 'Volver a mi reserva' }}
        </button>
      </div>
    </div>

    <!-- Marco tipo móvil: la guía se consulta en el teléfono, y en escritorio
         mantener el mismo ancho evita rehacer tres niveles de navegación. -->
    <div v-else class="relative w-full max-w-120 mx-auto min-h-screen bg-[#FBFAF7] shadow-2xl shadow-[#376875]/15 overflow-hidden md:my-8 md:min-h-212.5 md:h-[90vh] md:rounded-[3rem] md:border-8 md:border-[#376875]">

      <!-- ═══ CABECERA ═══ -->
      <header class="absolute top-0 left-0 right-0 z-20 px-8 pt-14 pb-6 bg-linear-to-b from-[#FBFAF7] via-[#FBFAF7]/95 to-transparent backdrop-blur-[2px]">
        <div class="flex justify-between items-start gap-3">
          <div class="shrink-0 pt-1 mr-2">
            <button @click="volverAReserva"
                    :title="maestroStore.t('gui_btn_volver_menu') || 'Volver a mi reserva'"
                    class="w-10 h-10 rounded-xl bg-[#376875]/5 text-[#376875] flex items-center justify-center hover:bg-[#376875] hover:text-white transition-all shadow-sm active:scale-90">
              <i class="fas fa-arrow-left text-sm"></i>
            </button>
          </div>
          <div class="flex flex-col flex-1 min-w-0">
            <span class="text-[10px] font-bold tracking-[0.2em] text-[#E07845] uppercase mb-1">
              {{ maestroStore.t('gui_header_tag') || 'Guía Digital' }}
            </span>
            <h1 class="text-2xl font-black text-gray-900 leading-[1.1] truncate">
              {{ maestroStore.traducir(guia.titulo) || nombreUnidad }}
            </h1>
          </div>
          <div class="relative shrink-0">
            <select :value="maestroStore.idiomaActual" @change="cambiarIdioma"
                    class="appearance-none bg-gray-100 font-bold text-[10px] uppercase tracking-wide rounded-xl py-2 pl-3 pr-8 focus:outline-none focus:ring-2 focus:ring-[#376875] cursor-pointer text-gray-600 border-0 hover:bg-gray-200 transition-colors">
              <option v-for="lang in maestroStore.idiomas" :key="lang.id" :value="lang.id">
                {{ lang.bandera }} {{ lang.id.toUpperCase() }}
              </option>
            </select>
            <div class="absolute right-2.5 top-1/2 -translate-y-1/2 pointer-events-none text-gray-400">
              <i class="fas fa-chevron-down text-[8px]"></i>
            </div>
          </div>
        </div>
      </header>

      <!-- ═══ NIVEL 1: PORTADA ═══ -->
      <div ref="homeScroll" class="pt-36 pb-24 px-6 h-full overflow-y-auto scrollbar-hide scroll-smooth">

        <!-- Hero (tap → sección descriptiva) -->
        <div class="mb-5 relative rounded-[2rem] shadow-xl shadow-[#376875]/25 overflow-hidden group h-64 md:h-72 ring-1 ring-black/5"
             :class="seccionDescriptiva ? 'cursor-pointer' : ''"
             @click="abrirDescriptivo">
          <div v-if="heroImage" class="absolute inset-0 bg-cover bg-center transition-transform duration-[2s] group-hover:scale-110"
               :style="{ backgroundImage: `url(${heroImage})` }"></div>
          <div v-else class="absolute inset-0 bg-linear-to-br from-[#376875] via-[#2B525D] to-[#12333C]"></div>
          <div class="absolute inset-0 bg-linear-to-t from-black/75 via-black/15 to-transparent"></div>

          <span v-if="seccionDescriptiva"
                class="absolute top-4 right-4 z-10 bg-black/40 backdrop-blur-sm text-white text-[11px] font-bold px-3 py-1.5 rounded-full border border-white/20 flex items-center gap-1.5">
            <i class="fas fa-images"></i> {{ maestroStore.t('gui_ver_fotos') || 'Ver fotos' }}
          </span>

          <div class="absolute bottom-0 left-0 right-0 p-7 z-10 text-white">
            <p v-if="nombrePila" class="text-[11px] font-bold uppercase tracking-[0.3em] text-[#FFB48A] mb-2 drop-shadow-sm">
              {{ maestroStore.t('gui_hola') || '¡Hola' }}, {{ nombrePila }} <i class="fas fa-hand-sparkles ml-1"></i>
            </p>
            <h2 class="text-3xl font-black tracking-tight leading-none drop-shadow-md">{{ nombreUnidad }}</h2>
            <p class="text-white/70 text-[13px] font-medium mt-2 drop-shadow-sm">
              {{ maestroStore.t('gui_bienvenido_a') || 'Bienvenido a' }} {{ contexto.hotel_name || nombreUnidad }}
            </p>
          </div>
        </div>

        <!-- Aviso de ventana de acceso -->
        <div v-if="avisoAcceso" class="mb-5 px-5 py-4 border rounded-2xl flex items-center gap-4 shadow-sm" :class="avisoAcceso.clase">
          <div class="w-10 h-10 bg-white rounded-full flex items-center justify-center shadow-sm shrink-0">
            <i class="fas text-sm" :class="avisoAcceso.icono"></i>
          </div>
          <div class="flex-1 min-w-0">
            <h3 class="text-sm font-black leading-tight mb-0.5">{{ avisoAcceso.titulo }}</h3>
            <p class="text-xs leading-snug opacity-80">{{ avisoAcceso.texto }}</p>
          </div>
        </div>

        <!-- Fechas de la estancia -->
        <div v-if="contexto.check_in || contexto.check_out"
             class="mb-5 bg-white border border-slate-200/60 rounded-3xl shadow-sm shadow-slate-900/3 grid grid-cols-2 divide-x divide-slate-100">
          <div class="px-5 py-4.5" :class="{ 'opacity-40': !contexto.check_in }">
            <p class="text-[9px] font-bold text-slate-400 uppercase tracking-[0.2em] mb-2">
              <i class="fas fa-plane-arrival mr-1.5 text-[#E07845]"></i>{{ maestroStore.t('res_checkin') || 'Check-in' }}
            </p>
            <p class="text-[22px] font-bold text-[#376875] tabular-nums leading-none">{{ contexto.check_in || '—' }}</p>
            <p v-if="contexto.start_date" class="text-[13px] font-semibold text-slate-500 mt-2">{{ contexto.start_date }}</p>
          </div>
          <div class="px-5 py-4.5 text-right" :class="{ 'opacity-40': !contexto.check_out }">
            <p class="text-[9px] font-bold text-slate-400 uppercase tracking-[0.2em] mb-2">
              {{ maestroStore.t('res_checkout') || 'Check-out' }}<i class="fas fa-plane-departure ml-1.5 text-[#E07845]"></i>
            </p>
            <p class="text-[22px] font-bold text-[#376875] tabular-nums leading-none">{{ contexto.check_out || '—' }}</p>
            <p v-if="contexto.end_date" class="text-[13px] font-semibold text-slate-500 mt-2">{{ contexto.end_date }}</p>
          </div>
        </div>

        <!-- Tarjeta de anfitrión -->
        <a v-if="whatsappUrl" :href="whatsappUrl" target="_blank" rel="noopener"
           class="mb-5 flex items-center gap-3.5 bg-white border border-slate-200/60 rounded-3xl p-3.5 shadow-sm shadow-slate-900/3 hover:shadow-md hover:border-[#0F6E56]/30 transition-all active:scale-[0.99]">
          <div class="w-11 h-11 rounded-full bg-linear-to-br from-[#E07845] to-[#C25B2C] text-white flex items-center justify-center font-black text-sm shrink-0 shadow-md shadow-orange-200">
            {{ hostInitials }}
          </div>
          <div class="flex-1 min-w-0">
            <p class="text-[10px] text-slate-400 font-bold uppercase tracking-[0.15em]">{{ maestroStore.t('gui_anfitrion') || 'Tu anfitrión' }}</p>
            <p class="text-sm font-black text-gray-900 truncate">
              {{ hostName || maestroStore.t('gui_anfitrion_generico') || 'A un mensaje de ti' }}
            </p>
          </div>
          <span class="flex items-center gap-2 bg-[#E1F5EE] text-[#0F6E56] text-xs font-black px-3 py-2 rounded-xl shrink-0">
            <i class="fab fa-whatsapp text-base"></i> {{ maestroStore.t('gui_escribir') || 'Escribir' }}
          </span>
        </a>

        <!-- Sección destacada: INGRESO (pasos como botones directos al nivel 3) -->
        <div v-if="seccionDestacada" class="relative overflow-hidden bg-linear-to-br from-[#376875] to-[#22454F] rounded-[1.75rem] p-4 shadow-xl shadow-[#376875]/30 ring-1 ring-white/10">
          <i class="fas fa-key absolute -right-6 -bottom-8 text-[9rem] text-white/5 rotate-12 pointer-events-none"></i>
          <button @click="abrirSeccion(seccionDestacada)" class="relative z-10 w-full flex items-center gap-4 text-left group px-1 pt-1">
            <span class="w-12 h-12 rounded-2xl bg-white/10 text-white flex items-center justify-center text-2xl shrink-0">
              <i :class="['fas', seccionDestacada.icono || 'fa-key']"></i>
            </span>
            <span class="flex-1 min-w-0 flex flex-col">
              <span class="text-white font-black text-lg leading-tight">{{ maestroStore.traducir(seccionDestacada.titulo) }}</span>
              <span v-if="maestroStore.traducir(seccionDestacada.subtitulo)" class="text-white/60 text-xs mt-0.5 truncate">
                {{ maestroStore.traducir(seccionDestacada.subtitulo) }}
              </span>
            </span>
            <span class="w-9 h-9 rounded-full bg-[#E07845] text-white flex items-center justify-center shadow-md shadow-orange-900/30 group-hover:translate-x-0.5 transition-transform shrink-0">
              <i class="fas fa-arrow-right text-sm"></i>
            </span>
          </button>

          <div v-if="itemsDestacadaVisibles.length" class="relative z-10 mt-4 pt-4 border-t border-white/10 space-y-2">
            <button v-for="(item, i) in itemsDestacadaVisibles" :key="item['@id']"
                    @click="abrirItem(seccionDestacada, item)"
                    class="w-full flex items-center gap-3 bg-white/8 hover:bg-white/18 border border-white/10 rounded-2xl px-3.5 py-3 text-left transition-colors active:scale-[0.99]">
              <span class="w-7 h-7 rounded-full bg-[#E07845] text-white text-[11px] font-black flex items-center justify-center shrink-0 shadow-sm shadow-orange-900/30">{{ i + 1 }}</span>
              <span class="flex-1 text-white/90 text-sm font-medium truncate">{{ maestroStore.traducir(item.titulo) }}</span>
              <!-- `.stop`: el candado explica, la fila abre. Es un <span> y no un
                   <button> porque ya está dentro de uno (anidarlos es HTML inválido). -->
              <span v-if="item.bloqueado" role="button" tabindex="0" :title="mensajeBloqueoItem"
                    @click.stop="avisarBloqueo($event)"
                    class="shrink-0 w-6 h-6 flex items-center justify-center text-white/50 hover:text-white transition-colors">
                <i class="fas fa-lock text-xs"></i>
              </span>
              <i v-else class="fas fa-chevron-right shrink-0 text-white/40 text-xs"></i>
            </button>

            <button v-if="itemsOcultosCount > 0" @click="mostrarTodosIngreso = !mostrarTodosIngreso"
                    class="w-full flex items-center justify-center gap-2 text-white/70 hover:text-white text-xs font-bold py-2 transition-colors">
              <span v-if="!mostrarTodosIngreso">{{ maestroStore.t('gui_mostrar_mas') || 'Mostrar más' }} ({{ itemsOcultosCount }})</span>
              <span v-else>{{ maestroStore.t('gui_mostrar_menos') || 'Mostrar menos' }}</span>
              <i class="fas fa-chevron-down text-[10px] transition-transform" :class="mostrarTodosIngreso ? 'rotate-180' : ''"></i>
            </button>
          </div>
        </div>

        <!-- Rejilla del resto de secciones -->
        <p class="mt-8 mb-3.5 px-1 text-[10px] font-bold text-[#376875]/50 uppercase tracking-[0.3em]">
          {{ maestroStore.t('gui_explora') || 'Explora tu guía' }}
        </p>
        <div class="grid grid-cols-2 gap-3.5">
          <button v-for="(seccion, index) in seccionesRestantes" :key="seccion.id"
                  @click="abrirSeccion(seccion)"
                  class="group relative flex flex-col min-h-37.5 p-5 bg-white border border-slate-200/60 rounded-3xl shadow-sm shadow-slate-900/3 overflow-hidden hover:shadow-lg hover:shadow-[#376875]/10 hover:border-[#376875]/25 hover:-translate-y-0.5 active:scale-[0.98] transition-all duration-300 text-left">
            <!-- Halo del color de la sección detrás del icono: da profundidad sin
                 convertir la tarjeta en un bloque de color. -->
            <span class="pointer-events-none absolute -top-8 -left-8 w-28 h-28 rounded-full blur-2xl opacity-70 transition-opacity group-hover:opacity-100"
                  :style="{ backgroundColor: colorDe(seccion, index).bg }"></span>

            <span class="relative flex items-start justify-between">
              <span class="relative w-12 h-12 rounded-2xl flex items-center justify-center text-xl bg-white shadow-sm ring-1 ring-black/5 transition-transform group-hover:scale-105"
                    :style="{ color: colorDe(seccion, index).color }">
                <i :class="['fas', seccion.icono || 'fa-star']"></i>
                <span v-if="accesoRestringido"
                      class="absolute -top-1.5 -right-1.5 w-4 h-4 rounded-full bg-white text-slate-400 text-[8px] flex items-center justify-center shadow-sm border border-slate-100">
                  <i class="fas fa-lock"></i>
                </span>
              </span>
              <span class="w-7 h-7 rounded-full border flex items-center justify-center transition-all group-hover:translate-x-0.5"
                    :style="{ borderColor: colorDe(seccion, index).color + '33', color: colorDe(seccion, index).color }">
                <i class="fas fa-arrow-right text-[10px]"></i>
              </span>
            </span>
            <span class="relative mt-auto pt-4 flex flex-col gap-1">
              <span class="text-[15px] font-bold text-[#1E3A42] leading-snug tracking-tight">{{ maestroStore.traducir(seccion.titulo) }}</span>
              <span v-if="maestroStore.traducir(seccion.subtitulo)" class="text-xs text-slate-400 font-medium leading-snug line-clamp-2">
                {{ maestroStore.traducir(seccion.subtitulo) }}
              </span>
            </span>
          </button>
        </div>

        <div class="mt-12 text-center pb-4">
          <p class="text-[9px] text-[#376875]/60 uppercase tracking-[0.3em] font-black">
            {{ maestroStore.t('com_powered_by') || 'Powered by OpenPeru' }}
          </p>
        </div>
      </div>

      <!-- ═══ NIVEL 2: ÍTEMS DE LA SECCIÓN ═══ -->
      <transition enter-active-class="transition duration-300 ease-out" enter-from-class="translate-x-full" enter-to-class="translate-x-0"
                  leave-active-class="transition duration-300 ease-in" leave-from-class="translate-x-0" leave-to-class="translate-x-full">
        <div v-if="seccionActiva" class="absolute inset-0 bg-[#F4F2ED] z-40 flex flex-col h-full shadow-2xl">
          <div class="flex-none px-6 py-5 bg-white/85 backdrop-blur-md border-b border-slate-200/60 flex items-center gap-4 sticky top-0 z-10">
            <button @click="volver" class="w-10 h-10 rounded-full bg-white border border-slate-200/70 text-[#376875] shadow-sm flex items-center justify-center hover:bg-[#376875] hover:text-white active:scale-90 transition-all">
              <i class="fas fa-arrow-left text-sm"></i>
            </button>
            <h2 class="text-lg font-black text-gray-900 truncate flex-1 tracking-tight">
              {{ maestroStore.traducir(seccionActiva.titulo) }}
            </h2>
          </div>

          <div id="nivel2-scroll" class="flex-1 overflow-y-auto p-5 pb-24 scrollbar-hide scroll-smooth">
            <!-- Un solo ítem: se salta el paso intermedio y se muestra el contenido -->
            <div v-if="esItemUnico" class="bg-white rounded-3xl shadow-sm shadow-slate-900/3 border border-slate-200/60 p-6 md:p-7 animate-fadeIn">
              <!-- eslint-disable-next-line vue/no-v-for-template-key -->
              <template v-for="item in [itemsSeccionActiva[0]]" :key="item['@id']">
                <RichTextRenderer :content="maestroStore.traducir(item.descripcion)" :wifi-data="guia.redesWifi" :medios-pago="guia.mediosPago" />
                <div v-if="tieneGaleria(item)" class="mt-5 grid grid-cols-2 gap-3">
                  <button v-for="(foto, i) in item.galeria" :key="i" @click="abrirFoto(item, i)"
                          class="aspect-4/3 rounded-2xl overflow-hidden ring-1 ring-black/5 cursor-zoom-in">
                    <img :src="thumbUrl(foto.imageUrl, 'travel_thumb_admin')"
                         :alt="maestroStore.traducir(foto.descripcion)" loading="lazy"
                         class="w-full h-full object-cover hover:scale-105 transition-transform duration-500">
                  </button>
                </div>
                <a v-if="item.urlBoton && maestroStore.traducir(item.labelBoton)" :href="item.urlBoton"
                   target="_blank" rel="noopener noreferrer"
                   class="mt-5 inline-flex items-center gap-2.5 bg-[#376875] hover:bg-[#2B525D] text-white font-bold text-[13px] px-6 py-3 rounded-full shadow-md shadow-[#376875]/20 active:scale-[0.98] transition-all">
                  {{ maestroStore.traducir(item.labelBoton) }}
                  <i class="fas fa-arrow-right text-[10px]"></i>
                </a>
              </template>
            </div>

            <!-- Varios ítems: lista -->
            <div v-else class="space-y-3">
              <button v-for="item in itemsSeccionActiva" :key="item['@id']"
                      @click="abrirItem(seccionActiva, item)"
                      class="group w-full flex items-center gap-4 bg-white rounded-2xl border border-slate-200/60 shadow-sm shadow-slate-900/3 hover:shadow-md hover:border-[#376875]/25 active:scale-[0.99] transition-all p-4 text-left animate-fadeIn">
                <span v-if="item.galeria?.length"
                      class="w-14 h-14 rounded-2xl bg-cover bg-center shrink-0 ring-1 ring-black/5"
                      :style="{ backgroundImage: `url(${thumbUrl(item.galeria[0].imageUrl, 'travel_thumb_admin')})` }"></span>
                <span v-else class="w-12 h-12 rounded-2xl bg-[#376875]/8 text-[#376875] text-lg flex items-center justify-center shrink-0">
                  <i :class="['fas', iconoItem(item)]"></i>
                </span>
                <span class="flex-1 min-w-0 flex flex-col">
                  <span class="font-bold text-gray-800 leading-tight group-hover:text-[#376875] transition-colors">
                    {{ maestroStore.traducir(item.titulo) }}
                  </span>
                  <!-- El contador anuncia una galería, así que solo aplica a los
                       álbumes; la miniatura de al lado sí se mantiene en todos
                       los tipos, porque ahí es una pista visual de la fila. -->
                  <span v-if="tieneGaleria(item)" class="text-[11px] text-slate-400 font-medium mt-0.5 flex items-center gap-1">
                    <i class="fas fa-images"></i> {{ item.galeria.length }} {{ maestroStore.t('gui_fotos') || 'fotos' }}
                  </span>
                </span>
                <!-- Ver nota del candado en la sección de ingreso de la portada. -->
                <span v-if="item.bloqueado" role="button" tabindex="0" :title="mensajeBloqueoItem"
                      @click.stop="avisarBloqueo($event)"
                      class="shrink-0 w-6 h-6 flex items-center justify-center text-slate-300 hover:text-[#376875] transition-colors">
                  <i class="fas fa-lock"></i>
                </span>
                <i v-else class="fas fa-chevron-right shrink-0 text-slate-300 group-hover:text-[#E07845] group-hover:translate-x-0.5 transition-all"></i>
              </button>
            </div>

            <div class="mt-8 text-center">
              <button @click="volver"
                      class="text-[10px] font-black text-gray-400 hover:text-[#376875] uppercase tracking-[0.2em] px-4 py-2 hover:bg-white rounded-full transition-colors">
                {{ maestroStore.t('gui_btn_volver_menu') || 'Volver al menú' }}
              </button>
            </div>
          </div>
        </div>
      </transition>

      <!-- ═══ NIVEL 3: DETALLE DEL ÍTEM ═══ -->
      <transition enter-active-class="transition duration-300 ease-out" enter-from-class="translate-x-full" enter-to-class="translate-x-0"
                  leave-active-class="transition duration-300 ease-in" leave-from-class="translate-x-0" leave-to-class="translate-x-full">
        <div v-if="itemActivo" class="absolute inset-0 bg-[#F4F2ED] z-50 flex flex-col h-full shadow-2xl">
          <div class="flex-none px-6 py-5 bg-white/85 backdrop-blur-md border-b border-slate-200/60 flex items-center gap-4 sticky top-0 z-10">
            <button @click="volver" class="w-10 h-10 rounded-full bg-white border border-slate-200/70 text-[#376875] shadow-sm flex items-center justify-center hover:bg-[#376875] hover:text-white active:scale-90 transition-all">
              <i class="fas fa-arrow-left text-sm"></i>
            </button>
            <h2 class="text-lg font-black text-gray-900 truncate flex-1 tracking-tight">
              {{ maestroStore.traducir(itemActivo.titulo) }}
            </h2>
            <!-- Ítem `llegada` con la ventana cerrada: el cuerpo anuncia la fecha
                 (nunca el dato real); el candado lo hace evidente. -->
            <span v-if="itemActivo.bloqueado"
                  class="shrink-0 inline-flex items-center gap-1 text-[9px] font-black uppercase tracking-widest bg-slate-100 text-slate-500 px-2 py-1 rounded-lg">
              <i class="fas fa-lock text-[8px]"></i>
              {{ maestroStore.t('gui_bloqueado') || 'Aún no disponible' }}
            </span>
          </div>

          <div id="nivel3-scroll" class="flex-1 overflow-y-auto p-5 pb-24 scrollbar-hide scroll-smooth">
            <div class="bg-white rounded-3xl shadow-sm shadow-slate-900/3 border border-slate-200/60 p-6 md:p-7 animate-fadeIn"
                 :class="itemActivo.tipo === 'alert' ? 'border-amber-200 bg-amber-50/60' : ''">
              <RichTextRenderer :content="maestroStore.traducir(itemActivo.descripcion)" :wifi-data="guia.redesWifi" :medios-pago="guia.mediosPago" />

              <div v-if="tieneGaleria(itemActivo)" class="mt-6">
                <div class="flex items-center gap-3 mb-4">
                  <span class="h-px bg-[#376875]/10 flex-1"></span>
                  <span class="text-[10px] font-bold text-[#376875]/60 uppercase tracking-[0.2em]">
                    {{ maestroStore.t('gui_galeria') || 'Galería' }}
                  </span>
                  <span class="h-px bg-[#376875]/10 flex-1"></span>
                </div>
                <div class="grid grid-cols-2 gap-3">
                  <button v-for="(foto, i) in itemActivo.galeria" :key="i" @click="abrirFoto(itemActivo, i)"
                          class="aspect-4/3 rounded-2xl overflow-hidden ring-1 ring-black/5 cursor-zoom-in">
                    <img :src="thumbUrl(foto.imageUrl, 'travel_thumb_admin')"
                         :alt="maestroStore.traducir(foto.descripcion)" loading="lazy"
                         class="w-full h-full object-cover hover:scale-105 transition-transform duration-500">
                  </button>
                </div>
              </div>

              <a v-if="itemActivo.urlBoton && maestroStore.traducir(itemActivo.labelBoton)"
                 :href="itemActivo.urlBoton" target="_blank" rel="noopener noreferrer"
                 class="mt-6 inline-flex items-center gap-2.5 bg-[#376875] hover:bg-[#2B525D] text-white font-bold text-[13px] px-6 py-3 rounded-full shadow-md shadow-[#376875]/20 active:scale-[0.98] transition-all">
                {{ maestroStore.traducir(itemActivo.labelBoton) }}
                <i class="fas fa-arrow-right text-[10px]"></i>
              </a>
            </div>
          </div>
        </div>
      </transition>

      <!-- Globo de "aún no disponible". `fixed` a propósito: se ancla al punto
           del tap, y el overflow-hidden del marco no lo recorta (solo un
           transform en un ancestro lo haría, y aquí no hay ninguno). -->
      <transition enter-active-class="transition duration-150 ease-out" enter-from-class="opacity-0 scale-95"
                  leave-active-class="transition duration-150 ease-in" leave-to-class="opacity-0 scale-95">
        <div v-if="tooltipBloqueo" @click="tooltipBloqueo = null"
             class="fixed z-[60] w-[220px] bg-[#1E3A42] text-white rounded-2xl shadow-2xl px-4 py-3 flex items-start gap-2.5 cursor-pointer"
             :style="{ left: tooltipBloqueo.x + 'px', top: tooltipBloqueo.y + 'px' }">
          <i class="fas fa-lock text-[#FFB48A] text-xs mt-0.5 shrink-0"></i>
          <span class="text-[12px] font-semibold leading-snug">{{ tooltipBloqueo.texto }}</span>
        </div>
      </transition>

      <VueEasyLightbox :visible="lightboxVisible" :imgs="lightboxImgs" :index="lightboxIndex"
                       :move-disabled="true" @hide="lightboxVisible = false" />
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
