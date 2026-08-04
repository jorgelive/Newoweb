<script setup lang="ts">
import { ref, computed, onMounted } from 'vue';
import { useRouter } from 'vue-router';
import AppSwitcher from '@/components/common/AppSwitcher.vue';
import { apiClient, getUrls } from '@/services/apiClient';
import { thumbUrl } from '@/services/imageThumb';
import {
  ESTADO_COTIZACION_CONFIG,
  type Cotizacion,
  type I18nContent,
  type ImagenSnapshot,
  type PrecioDesdeRango,
} from '@/types/cotizacionEditorModel';

/**
 * Tour dentro de un catálogo. Es una `Cotizacion` normal más las dos props
 * VIRTUALES que añade CotizacionCatalogoAdminProvider en el Get del catálogo
 * (grupo `catalogo:item:read`): no se persisten, las resuelve TourTarjetaResolver
 * — ver §6.b de docs/Cotizaciones.md.
 */
type TourCatalogo = Cotizacion & {
  imagenTarjeta?: ImagenSnapshot | null;
  numDias?: number | null;
};

interface CatalogoResumen {
  id?: string;
  '@id'?: string;
  localizador?: string;
  nombre?: string;
  tipoCliente?: string;
  idiomaCliente?: string;
  activo?: boolean;
  orden?: number;
  createdAt?: string;
  cotizaciones?: TourCatalogo[];
}

const router = useRouter();

const catalogos = ref<CatalogoResumen[]>([]);
const isLoading = ref(false);
const seleccionado = ref<CatalogoResumen | null>(null);
const isLoadingDetalle = ref(false);
const copiadoId = ref<string | null>(null);

const showCreateModal = ref(false);
const nuevoCatalogo = ref({ nombre: '', tipoCliente: 'economico' });

const TIPO_CLIENTE_CONFIG: Record<string, { label: string; icon: string; bg: string; text: string; border: string }> = {
  economico: { label: 'Económico', icon: 'fa-coins', bg: 'bg-emerald-50', text: 'text-emerald-600', border: 'border-emerald-200' },
  estandar:  { label: 'Estándar', icon: 'fa-star', bg: 'bg-sky-50', text: 'text-sky-600', border: 'border-sky-200' },
  superior:  { label: 'Superior', icon: 'fa-gem', bg: 'bg-violet-50', text: 'text-violet-600', border: 'border-violet-200' },
  lujo:      { label: 'Lujo', icon: 'fa-crown', bg: 'bg-amber-50', text: 'text-amber-600', border: 'border-amber-200' },
};

const getTipoUI = (tipo?: string) => TIPO_CLIENTE_CONFIG[tipo || ''] || TIPO_CLIENTE_CONFIG.economico;

const extractId = (item: CatalogoResumen): string => {
  const raw = item.id || item['@id'] || '';
  return String(raw).split('/').pop() || '';
};

const formatDate = (dateStr?: string): string => {
  if (!dateStr) return 'N/A';
  return new Date(dateStr).toLocaleDateString('es-PE', { day: '2-digit', month: 'short', year: 'numeric' });
};

const linkPublico = (cat: CatalogoResumen): string =>
  `${getUrls().pax}/catalogo/${cat.localizador}`;

const toursOrdenados = computed(() =>
  [...(seleccionado.value?.cotizaciones || [])].sort(
    (a, b) => ((a.orden || 0) - (b.orden || 0)) || ((a.version || 0) - (b.version || 0))
  )
);

// Idioma de visualización de la vista (títulos, resúmenes, rangos)
const idiomas = ref<{ id: string; nombre: string; bandera?: string }[]>([]);
const idiomaActivo = ref('es');
const idiomaDropdown = ref(false);

const fetchIdiomas = async () => {
  try {
    const res = await apiClient.get('/platform/maestro/idiomas?prioridad[gt]=0&order[prioridad]=desc');
    idiomas.value = res.data['hydra:member'] || res.data['member'] || [];
  } catch (e) {
    idiomas.value = [{ id: 'es', nombre: 'Español', bandera: '🇪🇸' }];
  }
};

/** Texto i18n en el idioma activo, con fallback es → primero. */
const t18 = (arr?: { language?: string; content?: string }[] | null): string => {
  if (!Array.isArray(arr) || !arr.length) return '';
  const m = arr.find(i => i.language === idiomaActivo.value)
      || arr.find(i => i.language === 'es')
      || arr[0];
  return m?.content || '';
};

/** Resumen HTML → texto plano corto para previews. */
const resumenPreview = (arr?: I18nContent[] | null): string =>
  t18(arr).replace(/<[^>]*>/g, ' ').replace(/\s+/g, ' ').trim();

/** Rangos de precio "Desde X" del tour de catálogo, cada uno con su título
 *  (en el idioma activo), moneda y valor. A diferencia de una cotización de
 *  grupo (ver FileDetalle.vue), un tour de catálogo NO tiene un total vendible:
 *  el pax por rango no está definido, así que lo informativo es el precio de
 *  cada rango (extranjero/peruano, adulto/niño), no un `totalVenta` de grupo. */
const rangosDesde = (tour: TourCatalogo): { titulo: string; moneda: string; valor: string }[] =>
  (tour.preciosDesde || []).map((r: PrecioDesdeRango) => ({
    titulo: t18(r.titulo),
    moneda: r.moneda,
    valor: r.valor,
  }));

const formatMonto = (valor: string | number | null | undefined): string => {
  const n = Number(valor);
  return Number.isFinite(n) ? n.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) : String(valor ?? '');
};

/**
 * Portada de la tarjeta: la MISMA imagen que verá el cliente en el catálogo
 * público. `imagenTarjeta` la resuelve el backend (override editorial, y si no
 * la primera imagen del itinerario) — ver CotizacionCatalogoAdminProvider y
 * TourTarjetaResolver. El `imagenPortada` suelto queda de respaldo por si la
 * respuesta viniera sin enriquecer.
 */
const portadaTour = (tour: TourCatalogo): string =>
  tour.imagenTarjeta?.imageUrl || tour.imagenPortada?.imageUrl || '';

/** "3 días" / "1 día" — null si el tour aún no tiene itinerario. */
const diasLabel = (tour: TourCatalogo): string | null =>
  tour.numDias ? `${tour.numDias} ${tour.numDias === 1 ? 'día' : 'días'}` : null;

/** Reordena el catálogo en el listado y persiste el nuevo orden. */
const moverCatalogo = async (idx: number, dir: -1 | 1) => {
  const destino = idx + dir;
  if (destino < 0 || destino >= catalogos.value.length) return;
  const lista = [...catalogos.value];
  [lista[idx], lista[destino]] = [lista[destino], lista[idx]];
  catalogos.value = lista;

  const cambios: Promise<any>[] = [];
  lista.forEach((c, i) => {
    if ((c.orden || 0) !== i) {
      c.orden = i;
      cambios.push(apiClient.patch(`/platform/sales/cotizacion_catalogos/${extractId(c)}`, { orden: i }));
    }
  });
  try {
    await Promise.all(cambios);
  } catch (e) {
    console.error('Error reordenando catálogos', e);
  }
};

/** Reordena el tour dentro del catálogo y persiste el nuevo orden. */
const moverTour = async (idx: number, dir: -1 | 1) => {
  const tours = [...toursOrdenados.value];
  const destino = idx + dir;
  if (destino < 0 || destino >= tours.length) return;
  [tours[idx], tours[destino]] = [tours[destino], tours[idx]];

  const cambios: Promise<any>[] = [];
  tours.forEach((t, i) => {
    if ((t.orden || 0) !== i) {
      t.orden = i;
      cambios.push(apiClient.patch(`/platform/sales/cotizacions/${t.id}`, { orden: i }));
    }
  });
  try {
    await Promise.all(cambios);
  } catch (e) {
    console.error('Error reordenando tours', e);
  }
};

// Edición de catálogo (nombre y modalidad)
const editCatalogo = ref<{ id: string; nombre: string; tipoCliente: string } | null>(null);

const abrirEdicion = (cat: CatalogoResumen) => {
  editCatalogo.value = {
    id: extractId(cat),
    nombre: cat.nombre || '',
    tipoCliente: cat.tipoCliente || 'economico',
  };
};

const cerrarEdicion = () => { editCatalogo.value = null; };

const handleEditSave = async () => {
  if (!editCatalogo.value || !editCatalogo.value.nombre.trim()) return;
  try {
    await apiClient.patch(`/platform/sales/cotizacion_catalogos/${editCatalogo.value.id}`, {
      nombre: editCatalogo.value.nombre.trim(),
      tipoCliente: editCatalogo.value.tipoCliente,
    });
    const cat = catalogos.value.find(c => extractId(c) === editCatalogo.value!.id);
    if (cat) { cat.nombre = editCatalogo.value.nombre.trim(); cat.tipoCliente = editCatalogo.value.tipoCliente; }
    if (seleccionado.value && extractId(seleccionado.value) === editCatalogo.value.id) {
      seleccionado.value.nombre = editCatalogo.value.nombre.trim();
      seleccionado.value.tipoCliente = editCatalogo.value.tipoCliente;
    }
    editCatalogo.value = null;
  } catch (e) {
    console.error('Error editando catálogo', e);
    alert('No se pudo guardar el catálogo.');
  }
};

const fetchCatalogos = async () => {
  isLoading.value = true;
  try {
    const res = await apiClient.get('/platform/sales/cotizacion_catalogos?order[orden]=asc&order[createdAt]=desc');
    catalogos.value = res.data['hydra:member'] || res.data['member'] || [];
  } catch (e) {
    console.error('Error cargando catálogos', e);
  } finally {
    isLoading.value = false;
  }
};

/** ¿Está desplegado este catálogo? Se consultaba repitiendo la comparación. */
const estaAbierto = (cat: CatalogoResumen): boolean =>
    !!seleccionado.value && extractId(seleccionado.value) === extractId(cat);

const seleccionar = async (cat: CatalogoResumen) => {
  if (seleccionado.value && extractId(seleccionado.value) === extractId(cat)) {
    seleccionado.value = null;
    return;
  }
  isLoadingDetalle.value = true;
  seleccionado.value = { ...cat, cotizaciones: [] };
  try {
    const res = await apiClient.get(`/platform/sales/cotizacion_catalogos/${extractId(cat)}`);
    seleccionado.value = res.data;
  } catch (e) {
    console.error('Error cargando detalle del catálogo', e);
  } finally {
    isLoadingDetalle.value = false;
  }
};

const handleCreate = async () => {
  if (!nuevoCatalogo.value.nombre.trim()) return;
  try {
    const res = await apiClient.post('/platform/sales/cotizacion_catalogos', {
      nombre: nuevoCatalogo.value.nombre.trim(),
      tipoCliente: nuevoCatalogo.value.tipoCliente,
    });
    showCreateModal.value = false;
    nuevoCatalogo.value = { nombre: '', tipoCliente: 'economico' };
    await fetchCatalogos();
    const creado = catalogos.value.find(c => extractId(c) === extractId(res.data));
    if (creado) await seleccionar(creado);
  } catch (e) {
    console.error('Error creando catálogo', e);
    alert('No se pudo crear el catálogo.');
  }
};

const toggleActivo = async (cat: CatalogoResumen) => {
  try {
    await apiClient.patch(`/platform/sales/cotizacion_catalogos/${extractId(cat)}`, { activo: !cat.activo });
    cat.activo = !cat.activo;
    if (seleccionado.value && extractId(seleccionado.value) === extractId(cat)) {
      seleccionado.value.activo = cat.activo;
    }
  } catch (e) {
    console.error('Error actualizando catálogo', e);
  }
};

const copiarLink = async (cat: CatalogoResumen) => {
  try {
    await navigator.clipboard.writeText(linkPublico(cat));
    copiadoId.value = extractId(cat);
    setTimeout(() => { copiadoId.value = null; }, 1500);
  } catch (e) {
    console.error('No se pudo copiar el enlace', e);
  }
};

const abrirTour = (cotizacionId?: string | null) => {
  if (!seleccionado.value || !cotizacionId) return;
  router.push(`/catalogo/${extractId(seleccionado.value)}/version/${cotizacionId}`);
};

const nuevoTour = () => {
  if (!seleccionado.value) return;
  router.push(`/catalogo/${extractId(seleccionado.value)}/version/nueva`);
};

const getEstadoUI = (estado?: string) =>
  ESTADO_COTIZACION_CONFIG[estado as keyof typeof ESTADO_COTIZACION_CONFIG] || {
    label: estado || 'Pendiente', bg: 'bg-slate-100', text: 'text-slate-500', border: 'border-slate-200', icon: 'fa-circle',
  };

onMounted(() => {
  fetchCatalogos();
  fetchIdiomas();
});
</script>

<template>
  <div class="min-h-screen bg-[#F8FAFC] font-sans">

    <header class="bg-slate-900 text-white px-4 md:px-8 py-4 flex items-center justify-between shadow-md">
      <div class="flex items-center gap-3">
        <AppSwitcher />
        <div>
          <h1 class="font-black text-lg md:text-xl tracking-tight leading-none">Catálogos de Tours</h1>
          <p class="text-[10px] md:text-[11px] font-bold text-slate-400 uppercase tracking-widest mt-1">Producto Pre-armado por Segmento</p>
        </div>
      </div>
      <div class="flex items-center gap-2">
        <div v-if="idiomas.length > 1" class="relative">
          <div v-if="idiomaDropdown" class="fixed inset-0 z-40" @click="idiomaDropdown = false"></div>
          <button type="button" @click="idiomaDropdown = !idiomaDropdown"
              title="Idioma de visualización de títulos y resúmenes"
              class="relative z-50 flex items-center gap-1.5 bg-slate-800 hover:bg-slate-700 border border-slate-700 rounded-lg px-3 py-2.5 text-xs font-bold text-slate-200 transition-colors">
            <span>{{ idiomas.find(i => i.id === idiomaActivo)?.bandera ?? '🌐' }}</span>
            <span class="uppercase tracking-wider">{{ idiomaActivo }}</span>
            <i class="fas fa-chevron-down text-[8px] transition-transform duration-200" :class="idiomaDropdown ? 'rotate-180' : ''"></i>
          </button>
          <div v-if="idiomaDropdown" class="absolute right-0 top-full mt-1 bg-white rounded-xl shadow-xl border border-slate-100 overflow-hidden min-w-37.5 z-50">
            <button v-for="idi in idiomas" :key="idi.id" type="button"
                @click="idiomaActivo = idi.id; idiomaDropdown = false"
                class="flex items-center gap-2.5 w-full px-3 py-2.5 text-left text-xs font-bold transition-colors hover:bg-slate-50"
                :class="idiomaActivo === idi.id ? 'bg-sky-50 text-sky-700' : 'text-slate-700'">
              <span class="text-sm">{{ idi.bandera }}</span>
              <span class="flex-1">{{ idi.nombre }}</span>
              <i v-if="idiomaActivo === idi.id" class="fas fa-check text-sky-500 text-[10px]"></i>
            </button>
          </div>
        </div>

        <button @click="showCreateModal = true"
                class="flex items-center gap-2 px-4 md:px-5 py-2.5 bg-[#E07845] hover:bg-[#c96636] rounded-lg text-xs font-bold transition-colors shadow-sm">
          <i class="fas fa-plus"></i> <span class="hidden sm:inline">Nuevo Catálogo</span>
        </button>
      </div>
    </header>

    <main class="max-w-6xl mx-auto p-4 md:p-8">

      <div v-if="isLoading" class="text-center py-20 text-slate-400">
        <i class="fas fa-spinner fa-spin text-3xl mb-3 text-[#376875]"></i>
        <p class="font-black tracking-widest uppercase text-xs">Cargando catálogos...</p>
      </div>

      <div v-else-if="!catalogos.length" class="text-center py-20">
        <i class="fas fa-book-open text-5xl text-slate-300 mb-4"></i>
        <h2 class="text-xl font-black text-slate-600">Aún no hay catálogos</h2>
        <p class="text-sm text-slate-400 font-medium mt-1">Crea tu primer catálogo de tours para un segmento de cliente.</p>
      </div>

      <div v-else class="space-y-4">
        <div v-for="(cat, catIdx) in catalogos" :key="extractId(cat)"
             class="bg-white border-2 rounded-2xl shadow-sm overflow-hidden transition-all"
             :class="estaAbierto(cat) ? 'border-[#376875]' : 'border-slate-200 hover:border-[#376875]/40'">

          <div @click="seleccionar(cat)" class="p-5 cursor-pointer flex flex-wrap items-center gap-3 justify-between">
            <!-- flex-1 para que el nombre se recorte antes de empujar la barra de acciones,
                 pero con suelo (min-w-52): sin él encoge hasta 0 y el contenido se DESBORDA
                 por debajo de los botones — el contenedor nunca envuelve porque el ítem
                 flexible siempre "cabe". El mínimo obliga a envolver antes de solaparse. -->
            <div class="flex items-center gap-4 flex-1 min-w-52">
              <div class="flex flex-col gap-0.5 shrink-0" @click.stop>
                <button @click="moverCatalogo(catIdx, -1)" :disabled="catIdx === 0"
                        class="w-6 h-6 rounded bg-slate-50 border border-slate-200 text-slate-500 flex items-center justify-center disabled:opacity-30 hover:bg-slate-100 transition-colors">
                  <i class="fas fa-chevron-up text-[9px]"></i>
                </button>
                <button @click="moverCatalogo(catIdx, 1)" :disabled="catIdx === catalogos.length - 1"
                        class="w-6 h-6 rounded bg-slate-50 border border-slate-200 text-slate-500 flex items-center justify-center disabled:opacity-30 hover:bg-slate-100 transition-colors">
                  <i class="fas fa-chevron-down text-[9px]"></i>
                </button>
              </div>
              <div class="w-11 h-11 rounded-xl flex items-center justify-center shrink-0 border shadow-sm"
                   :class="[getTipoUI(cat.tipoCliente).bg, getTipoUI(cat.tipoCliente).border]">
                <i class="fas text-lg" :class="[getTipoUI(cat.tipoCliente).icon, getTipoUI(cat.tipoCliente).text]"></i>
              </div>
              <div class="min-w-0">
                <h3 class="font-black text-base text-slate-800 leading-tight truncate">{{ cat.nombre }}</h3>
                <div class="flex flex-wrap items-center gap-2 mt-1.5">
                  <span class="text-[9px] font-black px-2 py-0.5 rounded border uppercase tracking-widest"
                        :class="[getTipoUI(cat.tipoCliente).bg, getTipoUI(cat.tipoCliente).text, getTipoUI(cat.tipoCliente).border]">
                    {{ getTipoUI(cat.tipoCliente).label }}
                  </span>
                  <span class="text-[10px] font-bold text-slate-400">{{ formatDate(cat.createdAt) }}</span>
                </div>
              </div>
            </div>

            <!-- ml-auto: si aun así envuelve (móvil), la barra se pega a la derecha en
                 lugar de quedar suelta a la izquierda con el hueco al costado. -->
            <div class="flex flex-wrap items-center justify-end gap-2 shrink-0 ml-auto" @click.stop>
              <button @click="abrirEdicion(cat)"
                      class="w-9 h-9 flex items-center justify-center bg-slate-50 hover:bg-slate-100 border border-slate-200 rounded-lg text-slate-500 transition-colors shadow-sm"
                      title="Editar nombre y modalidad">
                <i class="fas fa-pen text-xs"></i>
              </button>
              <button @click="copiarLink(cat)"
                      class="flex items-center gap-2 px-3 py-2 bg-slate-50 hover:bg-slate-100 border border-slate-200 rounded-lg text-[10px] font-black text-slate-600 tracking-widest transition-colors shadow-sm"
                      :title="linkPublico(cat)">
                <i class="fas" :class="copiadoId === extractId(cat) ? 'fa-check text-emerald-500' : 'fa-link text-[#E07845]'"></i>
                {{ copiadoId === extractId(cat) ? 'COPIADO' : cat.localizador }}
              </button>
              <a :href="linkPublico(cat)" target="_blank" rel="noopener"
                 class="w-9 h-9 flex items-center justify-center bg-slate-50 hover:bg-slate-100 border border-slate-200 rounded-lg text-slate-500 transition-colors shadow-sm"
                 title="Abrir catálogo público">
                <i class="fas fa-external-link-alt text-xs"></i>
              </a>

              <!-- El icono lleva su propio tamaño: hereda el 9px de la etiqueta, y cuando
                   ésta se oculta el ojo quedaba diminuto y sin peso frente al toggle. -->
              <span class="flex items-center gap-1.5 text-[9px] font-black uppercase tracking-widest"
                    :class="cat.activo ? 'text-teal-600' : 'text-slate-400'"
                    :title="cat.activo ? 'Catálogo visible al público' : 'Catálogo oculto'">
                <i class="fas text-sm" :class="cat.activo ? 'fa-eye' : 'fa-eye-slash'"></i>
                <span class="hidden lg:inline">{{ cat.activo ? 'Visible' : 'Oculto' }}</span>
              </span>
              <button @click="toggleActivo(cat)"
                      :class="['relative inline-flex h-6 w-11 items-center rounded-full transition-colors focus:outline-none', cat.activo ? 'bg-teal-600' : 'bg-slate-300']"
                      :title="cat.activo ? 'Catálogo visible al público' : 'Catálogo oculto'">
                <span :class="cat.activo ? 'translate-x-6' : 'translate-x-1'"
                      class="inline-block h-4 w-4 transform rounded-full bg-white transition-transform" />
              </button>

              <!-- Desplegar es una ACCIÓN con su botón, no un misterio del clic en la
                   fila: el chevron vivía dentro de esta barra, que corta la propagación
                   con `@click.stop`, así que era justo la zona donde uno lo intenta y
                   NO pasaba nada. Lleva etiqueta en pantallas anchas para que se lea
                   qué hace, y el clic en el resto de la fila sigue funcionando. -->
              <button type="button" @click.stop="seleccionar(cat)"
                      :aria-expanded="estaAbierto(cat)"
                      :title="estaAbierto(cat) ? 'Ocultar los tours' : 'Ver los tours de este catálogo'"
                      class="h-9 pl-3 pr-2.5 ml-1 flex items-center gap-2 rounded-lg border transition-colors shadow-sm text-[10px] font-black uppercase tracking-widest"
                      :class="estaAbierto(cat)
                        ? 'bg-[#376875] border-[#376875] text-white hover:bg-[#2c5560]'
                        : 'bg-slate-50 hover:bg-slate-100 border-slate-200 text-slate-600'">
                <span class="hidden sm:inline">{{ estaAbierto(cat) ? 'Contraer' : 'Expandir' }}</span>
                <!-- Plegado, la flecha da un salto corto cada 2,6 s: llama la atención sin
                     el ruido de un parpadeo, que en una lista de tarjetas cansa enseguida.
                     Al expandir se cambia la animación por el giro de 180° — las dos usan
                     `transform` y no pueden convivir. -->
                <i class="fas fa-chevron-down text-xs transition-transform duration-200"
                   :class="estaAbierto(cat) ? 'rotate-180' : 'flecha-llamada'"></i>
              </button>
            </div>
          </div>

          <!-- Tours del catálogo seleccionado -->
          <div v-if="estaAbierto(cat)" class="border-t border-slate-100 bg-slate-50/60 p-5">

            <div class="flex items-center justify-between gap-3 mb-4">
              <div class="min-w-0">
                <h4 class="text-[10px] font-black text-slate-500 uppercase tracking-widest"><i class="fas fa-route mr-1 text-[#E07845]"></i> Tours del Catálogo</h4>
                <p class="text-[10px] font-bold text-slate-400 mt-0.5">
                  {{ toursOrdenados.length }} {{ toursOrdenados.length === 1 ? 'experiencia' : 'experiencias' }} · Ref: {{ cat.localizador }}
                </p>
              </div>
              <button @click="nuevoTour"
                      class="flex items-center gap-2 px-3 py-2 bg-[#376875] hover:bg-[#2c5560] text-white rounded-lg text-[10px] font-black uppercase tracking-widest transition-colors shadow-sm shrink-0">
                <i class="fas fa-plus"></i> Nuevo Tour
              </button>
            </div>

            <div v-if="isLoadingDetalle" class="text-center py-6 text-slate-400">
              <i class="fas fa-spinner fa-spin text-xl"></i>
            </div>

            <div v-else-if="!toursOrdenados.length" class="text-center py-6 border-2 border-dashed border-slate-200 rounded-xl">
              <p class="text-[11px] font-bold text-slate-400 uppercase tracking-widest">Sin tours aún — crea el primero</p>
            </div>

            <!-- Tarjetas con el mismo lenguaje visual que el catálogo público (foto,
                 días, título flotante, "desde"): así se revisa de un vistazo cómo va a
                 verse el producto, sin abrir el enlace del cliente. Lo que añade el panel
                 son los controles internos: orden, versión, estado y pax base. -->
            <div v-else class="grid gap-5 sm:grid-cols-2 xl:grid-cols-3">
              <article v-for="(tour, idx) in toursOrdenados" :key="tour.id"
                       class="group bg-white border border-slate-200 rounded-3xl overflow-hidden shadow-sm hover:shadow-xl hover:shadow-[#376875]/10 hover:border-[#376875]/40 transition-all duration-300 flex flex-col">

                <!-- Portada -->
                <div @click="abrirTour(tour.id)"
                     class="relative aspect-video bg-slate-100 overflow-hidden cursor-pointer shrink-0">
                  <img v-if="portadaTour(tour)" :src="thumbUrl(portadaTour(tour), 'travel_thumb_admin')"
                       :alt="t18(tour.titulo)" loading="lazy"
                       class="w-full h-full object-cover group-hover:scale-105 transition-transform duration-700">
                  <div v-else class="w-full h-full bg-linear-to-br from-[#376875] to-[#1f4550] flex flex-col items-center justify-center gap-1.5">
                    <i class="fas fa-mountain text-3xl text-white/25"></i>
                    <span class="text-[8px] font-black uppercase tracking-widest text-white/40">Itinerario sin fotos</span>
                  </div>

                  <div class="absolute inset-0 bg-linear-to-t from-black/80 via-black/15 to-transparent pointer-events-none"></div>

                  <!-- Versión y duración -->
                  <div class="absolute top-3 left-3 flex items-center gap-1.5">
                    <span class="bg-slate-900/85 backdrop-blur text-white text-[9px] font-black px-2 py-1 rounded-lg tracking-widest">T{{ tour.version }}</span>
                    <span v-if="diasLabel(tour)" class="bg-white/95 backdrop-blur text-[#376875] text-[9px] font-black uppercase tracking-widest px-2 py-1 rounded-lg">
                      <i class="fas fa-route mr-1 text-[#E07845]"></i>{{ diasLabel(tour) }}
                    </span>
                  </div>

                  <!-- Reordenar: sobre la foto para no robarle sitio al pie -->
                  <div class="absolute top-3 right-3 flex flex-col gap-1 opacity-0 group-hover:opacity-100 focus-within:opacity-100 transition-opacity" @click.stop>
                    <button @click="moverTour(idx, -1)" :disabled="idx === 0" title="Subir en el catálogo"
                            class="w-7 h-7 rounded-lg bg-white/90 backdrop-blur border border-white/60 text-slate-600 flex items-center justify-center disabled:opacity-30 hover:bg-white transition-colors shadow-sm">
                      <i class="fas fa-chevron-up text-[9px]"></i>
                    </button>
                    <button @click="moverTour(idx, 1)" :disabled="idx === toursOrdenados.length - 1" title="Bajar en el catálogo"
                            class="w-7 h-7 rounded-lg bg-white/90 backdrop-blur border border-white/60 text-slate-600 flex items-center justify-center disabled:opacity-30 hover:bg-white transition-colors shadow-sm">
                      <i class="fas fa-chevron-down text-[9px]"></i>
                    </button>
                  </div>

                  <!-- Título flotante -->
                  <div class="absolute bottom-0 left-0 right-0 p-3.5 pointer-events-none">
                    <h5 class="text-sm font-black text-white leading-tight line-clamp-2 drop-shadow-md">
                      {{ t18(tour.titulo) || `Tour ${tour.version}` }}
                    </h5>
                    <p v-if="resumenPreview(tour.resumen)" class="text-[10px] font-medium text-white/70 truncate mt-1">
                      {{ resumenPreview(tour.resumen) }}
                    </p>
                  </div>
                </div>

                <!-- Pie: metadatos internos + precio de exhibición -->
                <div class="p-4 flex flex-col gap-3 grow">
                  <div class="flex items-center gap-2 flex-wrap">
                    <span class="text-[9px] font-black px-2 py-0.5 rounded border uppercase tracking-widest"
                          :class="[getEstadoUI(tour.estado).bg, getEstadoUI(tour.estado).text, getEstadoUI(tour.estado).border]">
                      <i class="fas mr-1" :class="getEstadoUI(tour.estado).icon"></i>{{ getEstadoUI(tour.estado).label }}
                    </span>
                    <span class="text-[10px] font-bold text-slate-400">
                      <i class="fas fa-user-group mr-1 text-slate-300"></i>{{ tour.numPax }} pax base
                    </span>
                  </div>

                  <div class="flex items-end justify-between gap-3 mt-auto">
                    <div class="min-w-0">
                      <p class="text-[8px] font-black text-slate-400 uppercase tracking-widest">Desde</p>
                      <template v-if="rangosDesde(tour).length">
                        <p class="text-lg font-black text-[#E07845] leading-none tabular-nums whitespace-nowrap">
                          {{ rangosDesde(tour)[0].moneda }} {{ formatMonto(rangosDesde(tour)[0].valor) }}
                        </p>
                        <p v-if="rangosDesde(tour)[0].titulo" class="text-[9px] font-bold text-slate-400 truncate mt-1">
                          {{ rangosDesde(tour)[0].titulo }}
                        </p>
                      </template>
                      <p v-else class="text-[13px] font-black text-slate-300 leading-none mt-0.5">Sin rangos</p>
                    </div>

                    <button @click="abrirTour(tour.id)"
                            class="shrink-0 flex items-center gap-2 px-3.5 py-2 rounded-xl bg-slate-50 hover:bg-[#376875] border border-slate-200 hover:border-[#376875] text-[10px] font-black uppercase tracking-widest text-slate-600 hover:text-white transition-colors">
                      Abrir <i class="fas fa-arrow-right text-[9px] group-hover:translate-x-0.5 transition-transform"></i>
                    </button>
                  </div>

                  <!-- Resto de rangos por perfil -->
                  <div v-if="rangosDesde(tour).length > 1" class="flex flex-wrap gap-1.5 pt-2.5 border-t border-slate-100">
                    <span v-for="(r, i) in rangosDesde(tour).slice(1)" :key="i"
                          class="text-[9px] font-bold bg-slate-50 border border-slate-200 text-slate-500 px-2 py-0.5 rounded-full">
                      {{ r.titulo }}
                      <span class="font-black text-[#376875] ml-0.5 tabular-nums">{{ r.moneda }} {{ formatMonto(r.valor) }}</span>
                    </span>
                  </div>
                </div>
              </article>
            </div>
          </div>
        </div>
      </div>
    </main>

    <!-- Modal crear catálogo -->
    <div v-if="showCreateModal" class="fixed inset-0 z-50 bg-slate-900/60 backdrop-blur-sm flex items-center justify-center p-4">
      <div class="bg-white w-full max-w-md rounded-3xl shadow-2xl overflow-hidden">
        <header class="bg-slate-900 text-white px-6 py-4 flex justify-between items-center">
          <h2 class="font-black text-base"><i class="fas fa-book-open mr-2 text-[#E07845]"></i> Nuevo Catálogo</h2>
          <button @click="showCreateModal = false" class="w-8 h-8 rounded-full bg-slate-800 hover:bg-slate-700 flex items-center justify-center transition-colors">
            <i class="fas fa-times"></i>
          </button>
        </header>
        <div class="p-6 space-y-5">
          <div>
            <label class="block text-[10px] font-black text-slate-500 uppercase mb-1.5 ml-1">Nombre del Catálogo *</label>
            <input v-model="nuevoCatalogo.nombre" type="text" placeholder="Ej: Sur del Perú Premium 2027"
                   @keyup.enter="handleCreate"
                   class="w-full bg-white border border-slate-300 rounded-xl px-4 py-3 text-sm font-bold outline-none focus:ring-2 focus:ring-[#376875] shadow-sm">
          </div>
          <div>
            <label class="block text-[10px] font-black text-slate-500 uppercase mb-1.5 ml-1">Tipo de Cliente</label>
            <div class="grid grid-cols-2 gap-3">
              <button v-for="(cfg, tipo) in TIPO_CLIENTE_CONFIG" :key="tipo"
                      @click="nuevoCatalogo.tipoCliente = tipo"
                      :class="nuevoCatalogo.tipoCliente === tipo
                        ? [cfg.bg, cfg.text, cfg.border, 'shadow-sm']
                        : 'bg-slate-50 text-slate-400 border-slate-200 hover:bg-slate-100'"
                      class="text-center p-3 rounded-xl border-2 transition-all">
                <i class="fas mb-1 block text-lg" :class="cfg.icon"></i>
                <span class="text-[10px] font-black uppercase tracking-widest">{{ cfg.label }}</span>
              </button>
            </div>
          </div>
          <button @click="handleCreate"
                  :disabled="!nuevoCatalogo.nombre.trim()"
                  :class="!nuevoCatalogo.nombre.trim() ? 'opacity-50 cursor-not-allowed' : 'hover:bg-[#c96636]'"
                  class="w-full py-3.5 bg-[#E07845] text-white rounded-xl text-xs font-black uppercase tracking-widest transition-colors shadow-md">
            <i class="fas fa-plus-circle mr-2"></i> Crear Catálogo
          </button>
        </div>
      </div>
    </div>

    <!-- Modal editar catálogo -->
    <div v-if="editCatalogo" class="fixed inset-0 z-50 bg-slate-900/60 backdrop-blur-sm flex items-center justify-center p-4">
      <div class="bg-white w-full max-w-md rounded-3xl shadow-2xl overflow-hidden">
        <header class="bg-slate-900 text-white px-6 py-4 flex justify-between items-center">
          <h2 class="font-black text-base"><i class="fas fa-pen mr-2 text-[#E07845]"></i> Editar Catálogo</h2>
          <button @click="cerrarEdicion" class="w-8 h-8 rounded-full bg-slate-800 hover:bg-slate-700 flex items-center justify-center transition-colors">
            <i class="fas fa-times"></i>
          </button>
        </header>
        <div class="p-6 space-y-5">
          <div>
            <label class="block text-[10px] font-black text-slate-500 uppercase mb-1.5 ml-1">Nombre del Catálogo *</label>
            <input v-model="editCatalogo.nombre" type="text"
                   @keyup.enter="handleEditSave"
                   class="w-full bg-white border border-slate-300 rounded-xl px-4 py-3 text-sm font-bold outline-none focus:ring-2 focus:ring-[#376875] shadow-sm">
          </div>
          <div>
            <label class="block text-[10px] font-black text-slate-500 uppercase mb-1.5 ml-1">Tipo de Cliente</label>
            <div class="grid grid-cols-2 gap-3">
              <button v-for="(cfg, tipo) in TIPO_CLIENTE_CONFIG" :key="tipo"
                      @click="editCatalogo.tipoCliente = tipo"
                      :class="editCatalogo.tipoCliente === tipo
                        ? [cfg.bg, cfg.text, cfg.border, 'shadow-sm']
                        : 'bg-slate-50 text-slate-400 border-slate-200 hover:bg-slate-100'"
                      class="text-center p-3 rounded-xl border-2 transition-all">
                <i class="fas mb-1 block text-lg" :class="cfg.icon"></i>
                <span class="text-[10px] font-black uppercase tracking-widest">{{ cfg.label }}</span>
              </button>
            </div>
          </div>
          <button @click="handleEditSave"
                  :disabled="!editCatalogo.nombre.trim()"
                  :class="!editCatalogo.nombre.trim() ? 'opacity-50 cursor-not-allowed' : 'hover:bg-[#c96636]'"
                  class="w-full py-3.5 bg-[#E07845] text-white rounded-xl text-xs font-black uppercase tracking-widest transition-colors shadow-md">
            <i class="fas fa-save mr-2"></i> Guardar Cambios
          </button>
        </div>
      </div>
    </div>
  </div>
</template>

<style scoped>
/*
  Salto discreto de la flecha «Expandir»: la mayor parte del ciclo está quieta y
  solo baja 3 px al final, así que se percibe como un guiño, no como un aviso.
  Se detiene sola en cuanto la tarjeta se despliega (la clase deja de aplicarse).
*/
@keyframes flechaLlamada {
    0%, 80%, 100% { transform: translateY(0); }
    88%           { transform: translateY(3px); }
    94%           { transform: translateY(0); }
}

.flecha-llamada {
    animation: flechaLlamada 2.6s ease-in-out infinite;
}

/* Quien pide menos movimiento en el sistema no debería ver nada moverse solo. */
@media (prefers-reduced-motion: reduce) {
    .flecha-llamada { animation: none; }
}
</style>
