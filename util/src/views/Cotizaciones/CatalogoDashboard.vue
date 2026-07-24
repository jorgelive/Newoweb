<script setup lang="ts">
import { ref, computed, onMounted } from 'vue';
import { useRouter } from 'vue-router';
import { apiClient, getUrls } from '@/services/apiClient';
import { ESTADO_COTIZACION_CONFIG } from '@/types/cotizacionEditorModel';

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
  cotizaciones?: any[];
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
const resumenPreview = (arr?: any[]): string =>
  t18(arr).replace(/<[^>]*>/g, ' ').replace(/\s+/g, ' ').trim();

/** Resumen legible de los rangos "Desde X" (título en es, moneda y valor). */
const resumenRangos = (tour: any): string => {
  const rangos = tour.preciosDesde || [];
  if (!rangos.length) return '—';
  return rangos.map((r: any) => {
    const titulo = t18(r.titulo);
    return `${titulo ? titulo + ' ' : ''}${r.moneda} ${r.valor}`;
  }).join(' · ');
};

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

const abrirTour = (cotizacionId: string) => {
  if (!seleccionado.value) return;
  router.push(`/catalogo/${extractId(seleccionado.value)}/version/${cotizacionId}`);
};

const nuevoTour = () => {
  if (!seleccionado.value) return;
  router.push(`/catalogo/${extractId(seleccionado.value)}/version/nueva`);
};

const getEstadoUI = (estado?: string) =>
  (ESTADO_COTIZACION_CONFIG as any)[estado || ''] || { label: estado || 'Pendiente' };

onMounted(() => {
  fetchCatalogos();
  fetchIdiomas();
});
</script>

<template>
  <div class="min-h-screen bg-[#F8FAFC] font-sans">

    <header class="bg-slate-900 text-white px-4 md:px-8 py-4 flex items-center justify-between shadow-md">
      <div class="flex items-center gap-3">
        <button @click="router.push('/cotizacion')" class="w-9 h-9 flex items-center justify-center bg-slate-800 hover:bg-slate-700 rounded-full transition-colors">
          <i class="fas fa-arrow-left text-sm"></i>
        </button>
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
             :class="seleccionado && extractId(seleccionado) === extractId(cat) ? 'border-[#376875]' : 'border-slate-200 hover:border-[#376875]/40'">

          <div @click="seleccionar(cat)" class="p-5 cursor-pointer flex flex-wrap items-center gap-3 justify-between">
            <div class="flex items-center gap-4 min-w-0">
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

            <div class="flex items-center gap-2 shrink-0" @click.stop>
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

              <button @click="toggleActivo(cat)"
                      :class="['relative inline-flex h-6 w-11 items-center rounded-full transition-colors focus:outline-none', cat.activo ? 'bg-teal-600' : 'bg-slate-300']"
                      :title="cat.activo ? 'Catálogo visible al público' : 'Catálogo oculto'">
                <span :class="cat.activo ? 'translate-x-6' : 'translate-x-1'"
                      class="inline-block h-4 w-4 transform rounded-full bg-white transition-transform" />
              </button>

              <i class="fas fa-chevron-down text-slate-300 ml-1 transition-transform"
                 :class="seleccionado && extractId(seleccionado) === extractId(cat) ? 'rotate-180' : ''"></i>
            </div>
          </div>

          <!-- Tours del catálogo seleccionado -->
          <div v-if="seleccionado && extractId(seleccionado) === extractId(cat)" class="border-t border-slate-100 bg-slate-50/60 p-5">

            <div class="flex items-center justify-between mb-4">
              <h4 class="text-[10px] font-black text-slate-500 uppercase tracking-widest"><i class="fas fa-route mr-1 text-[#E07845]"></i> Tours del Catálogo</h4>
              <button @click="nuevoTour"
                      class="flex items-center gap-2 px-3 py-2 bg-[#376875] hover:bg-[#2c5560] text-white rounded-lg text-[10px] font-black uppercase tracking-widest transition-colors shadow-sm">
                <i class="fas fa-plus"></i> Nuevo Tour
              </button>
            </div>

            <div v-if="isLoadingDetalle" class="text-center py-6 text-slate-400">
              <i class="fas fa-spinner fa-spin text-xl"></i>
            </div>

            <div v-else-if="!toursOrdenados.length" class="text-center py-6 border-2 border-dashed border-slate-200 rounded-xl">
              <p class="text-[11px] font-bold text-slate-400 uppercase tracking-widest">Sin tours aún — crea el primero</p>
            </div>

            <div v-else class="space-y-2">
              <div v-for="(tour, idx) in toursOrdenados" :key="tour.id"
                   @click="abrirTour(tour.id)"
                   class="bg-white border border-slate-200 rounded-xl p-4 flex flex-wrap items-center justify-between gap-3 cursor-pointer hover:border-[#376875]/50 transition-all shadow-sm group">
                <div class="flex items-center gap-3 min-w-0">
                  <div class="flex flex-col gap-0.5 shrink-0" @click.stop>
                    <button @click="moverTour(idx, -1)" :disabled="idx === 0"
                            class="w-6 h-6 rounded bg-slate-50 border border-slate-200 text-slate-500 flex items-center justify-center disabled:opacity-30 hover:bg-slate-100 transition-colors">
                      <i class="fas fa-chevron-up text-[9px]"></i>
                    </button>
                    <button @click="moverTour(idx, 1)" :disabled="idx === toursOrdenados.length - 1"
                            class="w-6 h-6 rounded bg-slate-50 border border-slate-200 text-slate-500 flex items-center justify-center disabled:opacity-30 hover:bg-slate-100 transition-colors">
                      <i class="fas fa-chevron-down text-[9px]"></i>
                    </button>
                  </div>
                  <span class="bg-slate-900 text-white px-2.5 py-1.5 rounded-lg text-[10px] font-black shrink-0">T{{ tour.version }}</span>
                  <div class="min-w-0">
                    <div class="flex items-center gap-2 flex-wrap">
                      <p class="text-xs font-black text-slate-700">{{ t18(tour.titulo) || `Tour ${tour.version}` }}</p>
                      <span class="text-[9px] font-black bg-slate-100 text-slate-500 px-1.5 py-0.5 rounded border border-slate-200 uppercase shrink-0">{{ getEstadoUI(tour.estado).label }}</span>
                    </div>
                    <p v-if="resumenPreview(tour.resumen)" class="text-[10px] font-medium text-slate-400 mt-0.5 truncate max-w-xs">{{ resumenPreview(tour.resumen) }}</p>
                    <p class="text-[10px] font-bold text-slate-400 mt-0.5">{{ tour.numPax }} pax base</p>
                  </div>
                </div>
                <div class="flex items-center gap-4 shrink-0">
                  <div class="text-right max-w-60">
                    <p class="text-[8px] font-black text-orange-400 uppercase tracking-widest">Desde</p>
                    <p class="text-[11px] font-black text-orange-600 leading-tight">{{ resumenRangos(tour) }}</p>
                  </div>
                  <div class="text-right">
                    <p class="text-[8px] font-black text-slate-400 uppercase tracking-widest">Venta Calc.</p>
                    <p class="text-sm font-black text-slate-600">{{ tour.monedaGlobal }} {{ tour.totalVenta }}</p>
                  </div>
                  <i class="fas fa-chevron-right text-slate-300 group-hover:text-[#376875] transition-colors"></i>
                </div>
              </div>
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
