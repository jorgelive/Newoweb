<script setup lang="ts">
import { ref, onMounted, computed } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { useCotizacionEditorStore } from '@/stores/cotizaciones/cotizacionEditorStore';
import SearchableSelect from '@/components/SearchableSelect.vue';
import WysiwygEditor from '@/components/WysiwygEditor.vue';

const route = useRoute();
const router = useRouter();
const store = useCotizacionEditorStore();

const idiomasOrdenados = computed(() => {
  if (!store.idiomasDisponibles) return [];
  return [...store.idiomasDisponibles].sort((a, b) => b.prioridad - a.prioridad);
});

const opcionesServicios = computed(() => {
  return store.catalogos.servicios
      .map(s => ({
        value: s.id || s['@id'],
        label: s.nombreInterno || s.nombre || 'Servicio sin nombre'
      }))
      .sort((a, b) => a.label.localeCompare(b.label, 'es'));
});

const opcionesComponentes = computed(() => {
  return store.catalogos.componentes
      .map(c => ({
        value: c.id || c['@id'],
        label: c.nombreInterno || c.nombre || 'Insumo sin nombre'
      }))
      .sort((a, b) => a.label.localeCompare(b.label, 'es'));
});

const opcionesTarifas = computed(() => {
  return store.catalogos.tarifas
      .map(t => ({
        value: t.id || t['@id'],
        label: store.getTarifaLabel(t, store.cotizacion?.idiomaEdicion || 'es')
      }))
      .sort((a, b) => a.label.localeCompare(b.label, 'es'));
});

const opcionesPlantillas = computed(() => {
  return store.catalogos.plantillasItinerario
      .map(p => ({
        value: p.id || p['@id'],
        label: p.nombreInterno || p.nombre || 'Plantilla sin nombre'
      }))
      .sort((a, b) => a.label.localeCompare(b.label, 'es'));
});

onMounted(() => {
  const fileId = route.params.fileId as string;
  const cotizacionId = route.params.cotizacionId as string;

  if (fileId && cotizacionId) {
    store.inicializarEditor(fileId, cotizacionId);
  } else {
    router.push('/cotizaciones');
  }
});

const formatFecha = (fecha?: string) => {
  if (!fecha) return '--';
  return new Date(fecha).toLocaleDateString('es-PE', { weekday: 'long', day: '2-digit', month: 'short', timeZone: 'UTC' });
};

const formatMoneda = (monto?: number | string, moneda?: string) => {
  const num = typeof monto === 'string' ? parseFloat(monto) : (monto ?? 0);
  return `${moneda === 'USD' ? '$' : 'S/'} ${num.toFixed(2)}`;
};

const formatRangoServicio = (servicio: any) => {
  if (!servicio.cotcomponentes || servicio.cotcomponentes.length === 0) return 'Sin logística programada';

  let minTime = Infinity;
  let maxTime = -Infinity;
  let minStr = '';
  let maxStr = '';

  servicio.cotcomponentes.forEach((c: any) => {
    if (c.fechaHoraInicio) {
      const t = new Date(c.fechaHoraInicio).getTime();
      if (t < minTime) { minTime = t; minStr = c.fechaHoraInicio; }
    }
    if (c.fechaHoraFin) {
      const t = new Date(c.fechaHoraFin).getTime();
      if (t > maxTime) { maxTime = t; maxStr = c.fechaHoraFin; }
    }
  });

  if (minTime === Infinity) return 'Horarios no definidos';

  const dMin = new Date(minStr);
  const dMax = new Date(maxStr);

  const fTime = (d: Date) => d.toLocaleTimeString('es-PE', { hour: '2-digit', minute: '2-digit', hour12: true });
  const fDate = (d: Date) => d.toLocaleDateString('es-PE', { day: '2-digit', month: 'short' }).replace('.', '');

  if (maxTime === -Infinity || maxTime <= minTime) return `${fDate(dMin)} • ${fTime(dMin)}`;
  if (dMin.toDateString() === dMax.toDateString()) return `${fDate(dMin)} • ${fTime(dMin)} - ${fTime(dMax)}`;

  return `${fDate(dMin)} ${fTime(dMin)}  —  ${fDate(dMax)} ${fTime(dMax)}`;
};

const formatDateTimeFromISO = (isoString?: string) => {
  if (!isoString) return '--';
  const date = new Date(isoString);
  if (isNaN(date.getTime())) return '--';
  return date.toLocaleString('es-PE', {
    day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit', hour12: true
  }).replace(',', ' -');
};

const dragStart = (e: DragEvent, segmentoMaestro: any) => {
  if (e.dataTransfer) {
    e.dataTransfer.setData('application/json', JSON.stringify(segmentoMaestro));
    e.dataTransfer.effectAllowed = 'copy';
  }
};

const plantillaSeleccionada = ref<string | null>(null);

const isComponenteSoloItems = (componente: any) => {
  return !componente.nombreSnapshot || componente.nombreSnapshot.length === 0;
};

const extractIdStrView = (val: any) => val ? String(val).split('/').pop() : '';

const getNombreMaestroRef = (comp: any) => {
  if (!comp || !comp.componenteMaestroId) return 'Insumo sin seleccionar';
  const targetId = extractIdStrView(comp.componenteMaestroId);
  if (!targetId) return 'Insumo sin seleccionar';

  const c = store.catalogos.allComponentes.find(cat => extractIdStrView(cat.id) === targetId || extractIdStrView(cat['@id']) === targetId);

  if (c && c.nombreInterno !== 'Sincronizando...') return c.nombreInterno || c.nombre || 'Insumo Genérico';

  if (c && c.nombreInterno === 'Sincronizando...') {
    const snapshotName = store.getI18nText(comp.nombreSnapshot, store.cotizacion?.idiomaEdicion || 'es');
    return snapshotName ? snapshotName : 'Sincronizando...';
  }

  store.fetchComponenteMaestroSilencioso(targetId as string);

  const snapshotName = store.getI18nText(comp.nombreSnapshot, store.cotizacion?.idiomaEdicion || 'es');
  return snapshotName ? snapshotName : 'Sincronizando...';
};

const filtroSegmentos = ref('');

const poolFiltrado = computed(() => {
  if (!filtroSegmentos.value) return store.catalogos.poolSegmentos;
  const q = filtroSegmentos.value.toLowerCase();
  return store.catalogos.poolSegmentos.filter((seg: any) => {
    const code = (seg.nombreInterno || '').toLowerCase();
    const title = store.getI18nText(seg.titulo, store.cotizacion?.idiomaEdicion || 'es').toLowerCase();
    return code.includes(q) || title.includes(q);
  });
});

const modalInsercion = ref({ isOpen: false, segmentoMaestro: null as any });
const opcionInsercion = ref<'append'|'insert'|'replace'>('append');
const targetSegmentoId = ref<string>('');
const isTotalsDrawerOpen = ref(false);

const prepararInsercion = async (seg: any) => {
  if (!store.dataActiva?.cotsegmentos?.length) {
    await store.procesarInsercionSegmento(seg, plantillaSeleccionada.value, 'append');
    return;
  }
  modalInsercion.value.segmentoMaestro = seg;
  opcionInsercion.value = 'append';
  targetSegmentoId.value = store.dataActiva.cotsegmentos[0].id;
  modalInsercion.value.isOpen = true;
};

const confirmarInsercion = async () => {
  if (modalInsercion.value.segmentoMaestro) {
    await store.procesarInsercionSegmento(
        modalInsercion.value.segmentoMaestro,
        plantillaSeleccionada.value,
        opcionInsercion.value,
        targetSegmentoId.value
    );
  }
  modalInsercion.value.isOpen = false;
  modalInsercion.value.segmentoMaestro = null;
};

const dropSegmento = (e: DragEvent) => {
  if (e.dataTransfer) {
    const data = e.dataTransfer.getData('application/json');
    if (data) prepararInsercion(JSON.parse(data));
  }
};
</script>

<template>
  <div class="h-screen bg-slate-50 flex flex-col font-sans overflow-hidden">

    <header class="bg-slate-900 text-white px-4 md:px-6 py-3 flex items-center justify-between z-20 shadow-md flex-shrink-0">
      <div class="flex items-center gap-3">
        <button @click="router.push(`/cotizaciones/${store.fileActual?.id || ''}`)" class="w-8 h-8 md:w-10 h-10 flex items-center justify-center bg-slate-800 hover:bg-slate-700 rounded-full transition-colors">
          <i class="fas fa-arrow-left text-sm"></i>
        </button>
        <div class="overflow-hidden">
          <h1 class="font-black text-base md:text-xl tracking-tight leading-none truncate">
            {{ store.fileActual?.nombreGrupo || 'Cargando Expediente...' }}
          </h1>
          <p class="text-[10px] md:text-[11px] font-bold text-slate-400 uppercase tracking-widest mt-1">
            Motor Operativo <span v-if="store.cotizacion">• V{{ store.cotizacion.version ?? 1 }}</span>
          </p>
        </div>
      </div>

      <div class="flex gap-2 md:gap-3" v-if="store.cotizacion">
        <div class="hidden md:flex items-center bg-slate-800 rounded-lg p-1 gap-1">
          <button @click="store.cotizacion.idiomaEdicion = 'es'"
                  :class="store.cotizacion.idiomaEdicion === 'es' ? 'bg-[#376875] text-white shadow' : 'text-slate-400 hover:text-white'"
                  class="px-3 py-1 rounded text-[10px] font-black tracking-widest transition-all">
            ES (INTERNO)
          </button>
          <button v-if="store.cotizacion.idiomaCliente !== 'es'"
                  @click="store.cotizacion.idiomaEdicion = store.cotizacion.idiomaCliente"
                  :class="store.cotizacion.idiomaEdicion === store.cotizacion.idiomaCliente ? 'bg-[#E07845] text-white shadow' : 'text-slate-400 hover:text-white'"
                  class="px-3 py-1 rounded text-[10px] font-black tracking-widest uppercase transition-all">
            {{ store.cotizacion.idiomaCliente }} (CLIENTE)
          </button>
        </div>
        <button @click="store.abrirNivel('resumen')" class="md:hidden px-4 py-2 bg-slate-800 text-slate-300 rounded-lg text-xs font-bold shadow-sm border border-slate-700">Totales</button>
        <button @click="store.guardarCotizacion()" class="px-4 md:px-5 py-2 bg-[#E07845] hover:bg-[#c96636] rounded-lg text-xs font-bold transition-colors flex items-center gap-2">
          <i class="fas fa-save"></i> <span class="hidden sm:inline">Guardar</span>
        </button>
      </div>
    </header>

    <div v-if="store.isLoading" class="flex-1 flex items-center justify-center bg-[#F8FAFC]">
      <div class="text-center text-slate-400">
        <i class="fas fa-spinner fa-spin text-4xl mb-4 text-[#376875]"></i>
        <p class="font-black tracking-widest uppercase text-xs">Sincronizando con Servidor...</p>
      </div>
    </div>

    <div v-else-if="store.cotizacion" class="flex flex-1 overflow-hidden relative">

      <main class="flex-1 overflow-y-auto p-4 md:p-8 bg-[#F8FAFC]">
        <div class="max-w-4xl mx-auto pb-32">

          <div v-for="dia in store.itinerarioDinamico" :key="dia.fechaAbsoluta" class="mb-10">

            <div class="flex items-center gap-3 sticky top-0 bg-[#F8FAFC]/95 backdrop-blur-sm py-4 z-10 mb-6 border-b border-slate-200/50">
              <div class="bg-slate-900 text-white px-4 py-2 rounded-xl font-black text-sm uppercase tracking-widest shadow-lg border border-slate-700">
                Día {{ dia.diaNumero }}
              </div>
              <div class="flex flex-col">
                <span class="text-[11px] font-black text-[#E07845] uppercase tracking-tighter leading-none mb-1">Cronología Operativa</span>
                <div class="text-sm font-black text-slate-800 uppercase tracking-tight">
                  {{ formatFecha(dia.fechaAbsoluta) }}
                </div>
              </div>
              <hr class="flex-1 border-slate-300 ml-4">
            </div>

            <div class="space-y-4">
              <div v-for="servicio in dia.cotservicios" :key="servicio.id"
                   @click="store.abrirNivel('servicio', servicio)"
                   class="bg-white border-2 rounded-2xl p-5 shadow-sm transition-all cursor-pointer group relative overflow-hidden"
                   :class="[
                     store.inspectorActivo === 'servicio' && store.dataActiva?.id === servicio.id ? 'border-[#376875] shadow-md' : 'border-slate-200 hover:border-[#376875]/50',
                     store.isServicioConAlerta(servicio) ? 'border-red-400 bg-red-50/10' : ''
                   ]">

                <button @click.stop="store.eliminarServicio(servicio.id)" class="absolute right-4 top-4 text-slate-400 hover:text-red-500 transition-colors z-10 bg-slate-100 w-8 h-8 rounded-full flex items-center justify-center shadow-sm">
                  <i class="fas fa-trash-alt text-sm"></i>
                </button>

                <div class="flex items-start justify-between gap-4">
                  <div class="pr-10 w-full">

                    <p class="text-[10px] font-black text-slate-600 uppercase flex items-center gap-1.5 mb-2 bg-slate-100 w-max px-2 py-1 rounded border border-slate-200">
                      <i class="far fa-calendar-check text-[#E07845]"></i> FECHA BASE: {{ formatFecha(servicio.fechaInicioAbsoluta) }}
                    </p>

                    <h3 class="font-black text-lg text-slate-900 leading-tight">
                      <i v-if="store.isServicioConAlerta(servicio)" class="fas fa-exclamation-triangle text-red-500 mr-2" title="Faltan cuadrar tarifas"></i>
                      {{ store.getI18nText(servicio.nombreSnapshot, store.cotizacion.idiomaEdicion) }}
                    </h3>

                    <p class="text-[11px] font-bold text-slate-500 mt-1"><i class="fas fa-map-signs mr-1"></i> {{ store.getI18nText(servicio.itinerarioNombreSnapshot, store.cotizacion.idiomaEdicion) }}</p>

                    <div class="flex flex-wrap items-center gap-2 mt-4">
                        <span class="text-[9px] font-black bg-indigo-600 text-white px-2 py-1.5 rounded uppercase tracking-widest shadow-sm">
                            <i class="far fa-clock mr-1 text-indigo-200"></i> Programación
                        </span>
                      <span class="text-[11px] font-bold text-slate-700 bg-slate-100 px-3 py-1 rounded-md border border-slate-200 shadow-sm whitespace-nowrap">
                            {{ formatRangoServicio(servicio) }}
                        </span>
                    </div>

                    <div class="flex flex-wrap gap-3 mt-4 pt-4 border-t border-slate-100">
                      <p class="text-[10px] font-bold text-slate-600 bg-slate-50 px-2 py-1.5 rounded-lg border border-slate-200">
                        <i class="fas fa-box-open mr-1 text-[#E07845]"></i> {{ servicio.cotcomponentes?.length ?? 0 }} COMPONENTES
                      </p>
                      <p v-if="servicio.cotsegmentos?.length" class="text-[10px] font-bold text-slate-600 bg-slate-50 px-2 py-1.5 rounded-lg border border-slate-200">
                        <i class="fas fa-feather-alt mr-1 text-indigo-500"></i> STORYTELLING ACTIVO
                      </p>
                    </div>
                  </div>
                </div>
              </div>
            </div>
          </div>

          <button @click="store.agregarServicio()" class="w-full py-6 border-2 border-dashed border-slate-300 rounded-3xl text-slate-500 font-black text-xs uppercase tracking-widest hover:border-[#376875] hover:text-[#376875] hover:bg-white transition-all shadow-sm">
            <i class="fas fa-plus-circle mr-2 text-lg"></i> Inyectar nuevo hito al itinerario
          </button>

        </div>
      </main>

      <aside :class="[
            'bg-white flex flex-col transition-transform duration-300 ease-in-out border-slate-200 flex-shrink-0',
            'fixed inset-0 z-50 md:z-10 w-full',
            store.isMobileOpen ? 'translate-y-0' : 'translate-y-full',
            'md:relative md:w-[420px] md:border-l md:translate-y-0 md:transform-none',
            store.inspectorActivo === 'tarifa' ? 'bg-slate-900 text-white' : 'bg-white text-slate-800'
        ]">

        <div v-if="store.inspectorActivo === 'resumen'" class="flex-1 flex flex-col min-h-0">
          <div class="px-6 py-5 border-b border-slate-100 flex justify-between items-center bg-slate-50">
            <h2 class="text-xs font-black text-slate-500 uppercase tracking-widest">Cabecera de Cotización</h2>
            <button @click="store.cerrarInspectorMobile" class="md:hidden text-slate-400 hover:text-red-500"><i class="fas fa-times text-lg"></i></button>
          </div>
          <div class="p-6 flex-1 overflow-y-auto space-y-6 pb-32">
            <div class="bg-[#376875] text-white rounded-3xl p-6 shadow-xl relative overflow-hidden">
              <i class="fas fa-chart-pie absolute -right-6 -bottom-6 text-8xl opacity-10"></i>
              <div class="relative z-10">
                <p class="text-[10px] font-bold text-emerald-400 uppercase tracking-widest mb-1">Venta Total Sugerida</p>
                <p class="text-4xl font-black tracking-tight">{{ formatMoneda(store.resumenFinanciero?.totalVentaBruta, store.cotizacion.monedaGlobal) }}</p>
                <div class="mt-4 pt-4 border-t border-slate-800/30 flex justify-between items-end">
                  <div>
                    <p class="text-[9px] text-slate-300 uppercase font-bold">Costo Neto</p>
                    <p class="text-lg font-bold text-white">{{ formatMoneda(store.resumenFinanciero?.totalCostoNeto, store.cotizacion.monedaGlobal) }}</p>
                  </div>
                  <div class="text-right">
                    <p class="text-[9px] text-emerald-400 uppercase font-bold">Margen Bruto</p>
                    <p class="text-lg font-bold text-emerald-300">+{{ formatMoneda(store.resumenFinanciero?.ganancia, store.cotizacion.monedaGlobal) }}</p>
                  </div>
                </div>
              </div>
            </div>

            <div class="space-y-3">
              <h3 class="text-[10px] font-black text-slate-400 uppercase tracking-widest ml-1"><i class="fas fa-users mr-1"></i> Análisis por Perfil de Pasajero</h3>

              <div v-for="clase in store.resumenFinanciero?.clasesPasajeros" :key="clase.tipo"
                   class="bg-white border border-slate-200 rounded-2xl p-4 shadow-sm group hover:border-indigo-300 transition-all"
                   :class="clase.tipo.includes('anomalo') ? 'border-red-300' : ''">
                <div class="flex justify-between items-start mb-3">
                  <div>
                    <span :class="clase.tipo.includes('anomalo') ? 'bg-red-100 text-red-700' : 'bg-indigo-100 text-indigo-700'" class="px-2 py-0.5 rounded text-[10px] font-black uppercase">
                      {{ clase.cantidad }}x {{ clase.tipoPaxNombre }}
                    </span>
                    <p class="text-[11px] font-bold text-slate-500 mt-1">Rango: {{ clase.edadMin }} a {{ clase.edadMax }} años</p>
                  </div>
                  <div class="text-right">
                    <p class="text-[9px] text-slate-400 font-bold uppercase">Venta Unit.</p>
                    <p class="text-sm font-black text-slate-800">{{ formatMoneda(clase.resumen.ventaDolares / (clase.cantidad || 1), store.cotizacion.monedaGlobal) }}</p>
                  </div>
                </div>

                <div class="grid grid-cols-2 gap-2 mt-2 pt-2 border-t border-slate-50">
                  <div class="bg-slate-50 p-2 rounded-lg text-center">
                    <p class="text-[8px] text-slate-400 font-bold uppercase">Costo Total</p>
                    <p class="text-[11px] font-black text-slate-600">{{ formatMoneda(clase.resumen.montoDolares, store.cotizacion.monedaGlobal) }}</p>
                  </div>
                  <div class="bg-emerald-50 p-2 rounded-lg text-center">
                    <p class="text-[8px] text-emerald-600 font-bold uppercase">Utilidad</p>
                    <p class="text-[11px] font-black text-emerald-700">{{ formatMoneda(clase.resumen.gananciaDolares, store.cotizacion.monedaGlobal) }}</p>
                  </div>
                </div>

                <div v-if="clase.tipo.includes('anomalo') && clase.conflictos?.length > 0" class="mt-3 pt-3 border-t border-red-100">
                  <p class="text-[9px] font-black text-red-500 uppercase tracking-widest mb-1.5"><i class="fas fa-search"></i> Origen del conflicto:</p>
                  <ul class="space-y-1">
                    <li v-for="(conflicto, idx) in clase.conflictos" :key="idx" class="text-[10px] font-bold text-red-700 bg-red-50 p-1.5 rounded border border-red-100 flex items-start gap-1.5 leading-tight">
                      <i class="fas fa-exclamation-triangle mt-0.5 opacity-70 text-[9px]"></i>
                      <span>{{ conflicto }}</span>
                    </li>
                  </ul>
                </div>
              </div>
            </div>

            <div class="grid grid-cols-2 gap-4">
              <div class="col-span-2 grid grid-cols-2 gap-4 bg-slate-50 border border-slate-200 rounded-2xl p-4">
                <div>
                  <span class="block text-xs font-bold text-slate-500 uppercase mb-1">Estado Versión</span>
                  <select v-model="store.cotizacion.estado" class="w-full font-black text-slate-800 bg-white px-3 py-2 rounded-lg border border-slate-200 outline-none focus:ring-2 focus:ring-[#376875] text-sm appearance-none shadow-sm">
                    <option value="Pendiente">Pendiente</option>
                    <option value="Archivado">Archivado</option>
                    <option value="Confirmado">Confirmado</option>
                    <option value="Operado">Operado</option>
                    <option value="Cancelado">Cancelado</option>
                  </select>
                </div>
                <div>
                  <span class="block text-xs font-bold text-slate-500 uppercase mb-1">Idioma</span>
                  <select v-model="store.cotizacion.idiomaCliente" @change="store.cotizacion.idiomaEdicion = 'es'" class="w-full font-black text-slate-800 bg-white px-3 py-2 rounded-lg border border-slate-200 outline-none focus:ring-2 focus:ring-[#376875] text-sm appearance-none shadow-sm">
                    <option v-for="lang in idiomasOrdenados" :key="lang.id" :value="lang.id">{{ lang.nombre }}</option>
                  </select>
                </div>
              </div>
              <div>
                <label class="block text-[10px] font-black text-slate-500 uppercase mb-1.5 ml-1">Num Pax (Base) *</label>
                <input :value="store.cotizacion.numPax"
                       @change="e => store.updateNumPaxGlobal((e.target as HTMLInputElement).value)"
                       type="number"
                       class="w-full bg-white border border-slate-300 rounded-xl px-4 py-3 text-sm font-bold text-center outline-none focus:ring-2 focus:ring-[#376875] shadow-sm">
              </div>
              <div>
                <label class="block text-[10px] font-black text-slate-500 uppercase mb-1.5 ml-1">Comisión (%)</label>
                <input v-model="store.cotizacion.comision" type="number" step="0.1" class="w-full bg-white border border-slate-300 rounded-xl px-4 py-3 text-sm font-bold text-right text-emerald-600 outline-none focus:ring-2 focus:ring-[#376875] shadow-sm">
              </div>

              <div class="col-span-2">
                <label class="block text-[10px] font-black text-slate-500 uppercase mb-1.5 ml-1"><i class="fas fa-exchange-alt mr-1"></i> T. Cambio (Sugerido)</label>
                <div class="relative">
                  <input v-model="store.cotizacion.tipoCambio" type="number" step="0.0001"
                         class="w-full bg-slate-50 border border-slate-300 rounded-xl px-4 py-3 text-sm font-black text-center outline-none focus:ring-2 focus:ring-orange-500 shadow-inner">
                  <div class="absolute right-3 top-1/2 -translate-y-1/2 text-[9px] font-black text-slate-400 uppercase tracking-tighter">PEN/USD</div>
                </div>
              </div>
            </div>

            <div>
              <div class="flex items-center justify-between mb-1.5 ml-1">
                <label class="block text-[10px] font-black text-slate-500 uppercase">Resumen ({{ store.cotizacion.idiomaEdicion.toUpperCase() }})</label>
                <button @click="store.cotizacion.sobreescribirTraduccion = !store.cotizacion.sobreescribirTraduccion"
                        :class="store.cotizacion.sobreescribirTraduccion ? 'bg-orange-100 text-orange-600 border-orange-300' : 'bg-slate-50 text-slate-400 border-slate-200 hover:bg-slate-100'"
                        class="p-1 px-2 border rounded-lg transition-colors shadow-sm text-[10px] font-bold flex items-center gap-1" title="Traducir automáticamente a otros idiomas al guardar">
                  <i class="fas fa-language"></i> <span v-if="store.cotizacion.sobreescribirTraduccion">Auto-Traducir ACTIVO</span>
                </button>
              </div>
              <WysiwygEditor
                  :model-value="store.getI18nText(store.cotizacion.resumenI18n, store.cotizacion.idiomaEdicion)"
                  @update:model-value="store.setI18nText(store.cotizacion.resumenI18n, store.cotizacion.idiomaEdicion, $event)"
              />
            </div>
          </div>
        </div>

        <div v-else-if="store.inspectorActivo === 'servicio'" class="flex-1 flex flex-col min-h-0">
          <div class="px-5 py-4 border-b border-slate-100 flex items-center gap-3 bg-slate-50 flex-shrink-0">
            <button @click="store.retrocederNivel" class="w-8 h-8 rounded-full hover:bg-slate-200 text-slate-500 flex items-center justify-center transition-colors"><i class="fas fa-arrow-left"></i></button>
            <div class="flex-1 min-w-0">
              <p class="text-[9px] font-black text-[#E07845] uppercase tracking-widest truncate">Edición de Servicio</p>
              <h2 class="text-sm font-black truncate">{{ store.getI18nText(store.dataActiva?.nombreSnapshot, store.cotizacion.idiomaEdicion) }}</h2>
            </div>
          </div>
          <div class="p-5 flex-1 overflow-y-auto space-y-6 pb-28">
            <div class="bg-slate-50 border border-slate-200 p-4 rounded-xl space-y-4">
              <div>
                <label class="block text-[10px] font-black text-[#E07845] uppercase tracking-widest mb-2"><i class="fas fa-book mr-1"></i> Catálogo Maestro</label>

                <div v-if="store.dataActiva.servicioMaestroId && store.dataActiva.cotcomponentes?.length > 0"
                     class="w-full bg-slate-100 border border-slate-200 text-slate-500 rounded-lg px-3 py-2.5 text-sm font-bold flex justify-between items-center cursor-not-allowed shadow-inner">
                  <span>{{ store.getI18nText(store.dataActiva.nombreSnapshot, store.cotizacion.idiomaEdicion) || 'Servicio Bloqueado' }}</span>
                  <i class="fas fa-lock text-orange-400"></i>
                </div>

                <SearchableSelect
                    v-else
                    v-model="store.dataActiva.servicioMaestroId"
                    :options="opcionesServicios"
                    placeholder="Buscar servicio..."
                    @change="val => store.onServicioMaestroChange(val)"
                />
              </div>
              <div>
                <label class="block text-[10px] font-black text-slate-500 uppercase mb-1.5 ml-1">Nombre Público *</label>
                <div class="flex gap-2">
                  <input :value="store.getI18nText(store.dataActiva.nombreSnapshot, store.cotizacion.idiomaEdicion)"
                         @input="e => store.setI18nText(store.dataActiva.nombreSnapshot, store.cotizacion.idiomaEdicion, (e.target as HTMLInputElement).value)"
                         type="text" class="flex-1 bg-white border border-slate-300 rounded-lg px-3 py-2 text-sm font-bold focus:ring-2 focus:ring-[#376875] outline-none shadow-sm">

                  <button @click="store.dataActiva.sobreescribirTraduccion = !store.dataActiva.sobreescribirTraduccion"
                          :class="store.dataActiva.sobreescribirTraduccion ? 'bg-orange-100 text-orange-600 border-orange-300' : 'bg-slate-50 text-slate-400 border-slate-200 hover:bg-slate-100'"
                          class="px-3 border rounded-lg transition-colors shadow-sm" title="Forzar traducción de este título al guardar">
                    <i class="fas fa-language"></i>
                  </button>
                </div>
              </div>
              <div>
                <label class="block text-[10px] font-black text-slate-500 uppercase mb-1.5 ml-1"><i class="far fa-calendar-alt mr-1"></i> Fecha Ejecución (Milestone)</label>
                <input v-model="store.dataActiva.fechaInicioAbsoluta" @change="store.onServicioFechaChange" type="date" class="w-full bg-white border border-slate-300 rounded-lg px-3 py-2 text-sm font-bold focus:ring-2 focus:ring-[#376875] outline-none shadow-sm">
              </div>
            </div>

            <div class="bg-indigo-50 border border-indigo-100 rounded-xl p-4">
              <div class="flex items-start justify-between gap-2 mb-2">
                <div>
                  <h3 class="text-[10px] font-black text-indigo-700 uppercase tracking-widest"><i class="fas fa-align-left mr-1"></i> Storytelling</h3>
                  <p class="text-[10px] text-indigo-500 mt-1 font-medium">{{ store.getI18nText(store.dataActiva.itinerarioNombreSnapshot, store.cotizacion.idiomaEdicion) }}</p>
                </div>
                <button @click="store.abrirEditorSegmentos" class="bg-indigo-600 hover:bg-indigo-700 text-white px-3 py-2 rounded-lg text-[10px] font-bold shadow-sm whitespace-nowrap">
                  <i class="fas fa-pencil-alt mr-1"></i> Configurar
                </button>
              </div>
            </div>

            <div class="border-t border-slate-100 pt-5">
              <h3 class="text-[10px] font-black text-sky-600 uppercase tracking-widest mb-3 flex items-center justify-between">
                <span>Componentes Logísticos</span>
                <button @click="store.agregarComponente(store.dataActiva.id)" class="bg-sky-100 text-sky-700 px-3 py-1.5 rounded-lg text-xs md:text-sm font-bold shadow-sm border border-sky-200 hover:bg-sky-200 transition-colors">+ Añadir Extra</button>
              </h3>
              <div class="space-y-3">
                <div v-for="comp in store.dataActiva.cotcomponentes" :key="comp.id" @click="store.abrirNivel('componente', comp)"
                     class="bg-white border-2 border-slate-200 rounded-xl p-4 shadow-sm cursor-pointer hover:border-sky-300 relative group overflow-hidden transition-all flex flex-col justify-between h-full"
                     :class="store.isComponenteConAlerta(comp) ? 'border-red-400 bg-red-50/20' : ''">
                  <div class="absolute left-0 top-0 bottom-0 w-1.5" :class="store.isComponenteConAlerta(comp) ? 'bg-red-400' : 'bg-sky-400'"></div>

                  <button v-if="!comp.cotsegmentoId && !comp.cotsegmento" @click.stop="store.eliminarComponente(store.dataActiva.id, comp.id)" class="absolute right-3 top-3 text-slate-300 hover:text-red-500 transition-colors z-10 bg-slate-50 w-7 h-7 rounded-full flex justify-center items-center">
                    <i class="fas fa-trash-alt text-sm"></i>
                  </button>

                  <h4 class="font-black text-sm text-slate-800 leading-tight pr-8 mb-3">
                    <i v-if="store.isComponenteConAlerta(comp)" class="fas fa-exclamation-triangle text-red-500 mr-1" title="Tarifas no cuadran"></i>
                    {{ getNombreMaestroRef(comp) }}
                  </h4>

                  <div class="flex flex-col gap-1.5">
                    <span class="bg-sky-50 border border-sky-100 text-sky-800 px-2.5 py-1.5 rounded-lg text-[10px] font-black shadow-sm flex items-center gap-2 w-max">
                      <i class="far fa-calendar-alt text-sky-500"></i> INICIO: {{ formatDateTimeFromISO(comp.fechaHoraInicio) }}
                    </span>
                    <span class="bg-slate-100 border border-slate-200 text-slate-700 px-2.5 py-1.5 rounded-lg text-[10px] font-black shadow-sm flex items-center gap-2 w-max">
                      <i class="far fa-flag text-slate-400"></i> FIN: {{ formatDateTimeFromISO(comp.fechaHoraFin) }}
                    </span>
                    <span v-if="comp.cotsegmentoId || comp.cotsegmento" class="mt-1 text-[9px] font-bold text-indigo-400 flex items-center gap-1">
                      <i class="fas fa-link"></i> Componente Matriz
                    </span>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>

        <div v-else-if="store.inspectorActivo === 'componente'" class="flex-1 flex flex-col min-h-0 bg-sky-50/50">
          <div class="px-5 py-4 border-b border-sky-200 flex items-center gap-3 bg-sky-600 text-white flex-shrink-0">
            <button @click="store.retrocederNivel" class="w-8 h-8 rounded-full hover:bg-sky-500 flex items-center justify-center transition-colors"><i class="fas fa-arrow-left"></i></button>
            <div class="flex-1 min-w-0">
              <p class="text-[9px] font-black text-sky-200 uppercase tracking-widest truncate">Componente Logístico</p>
              <h2 class="text-sm font-black truncate">{{ getNombreMaestroRef(store.dataActiva) }}</h2>
            </div>
          </div>
          <div class="p-5 flex-1 overflow-y-auto space-y-6 pb-28">
            <div class="bg-white border border-sky-200 p-4 rounded-xl shadow-sm">
              <label class="block text-[10px] font-black text-sky-600 uppercase tracking-widest mb-2"><i class="fas fa-box-open mr-1"></i> Insumo Maestro</label>

              <SearchableSelect
                  v-if="!store.dataActiva.cotsegmentoId && !store.dataActiva.cotsegmento"
                  v-model="store.dataActiva.componenteMaestroId"
                  :options="opcionesComponentes"
                  placeholder="Buscar insumo..."
                  @change="val => store.onComponenteMaestroChange(val)"
              />
              <div v-else class="flex flex-col gap-2 bg-indigo-50/60 p-4 rounded-xl border border-indigo-100 shadow-sm mt-1">
                <div class="flex items-center justify-between">
                  <div class="flex items-center gap-3">
                    <div class="w-8 h-8 rounded-full bg-indigo-100 text-indigo-500 flex items-center justify-center shadow-inner">
                      <i class="fas fa-link text-sm"></i>
                    </div>
                    <div class="flex flex-col">
                      <span class="text-[9px] font-black text-indigo-400 uppercase tracking-widest">Insumo Maestro (Interno)</span>
                      <span class="text-sm font-black text-indigo-900 mt-0.5">{{ getNombreMaestroRef(store.dataActiva) }}</span>
                    </div>
                  </div>
                </div>
              </div>
            </div>

            <div class="grid grid-cols-2 gap-4">
              <div class="col-span-2">
                <label class="block text-[10px] font-black text-slate-500 uppercase mb-1.5 ml-1">Nombre Público *</label>

                <div class="flex gap-2" v-if="!isComponenteSoloItems(store.dataActiva)">
                  <input :value="store.getI18nText(store.dataActiva.nombreSnapshot, store.cotizacion.idiomaEdicion)"
                         @input="e => store.setI18nText(store.dataActiva.nombreSnapshot, store.cotizacion.idiomaEdicion, (e.target as HTMLInputElement).value)"
                         type="text" class="flex-1 bg-white border border-slate-300 rounded-xl px-4 py-3 text-sm font-bold outline-none shadow-sm focus:ring-2 focus:ring-sky-500">

                  <button @click="store.dataActiva.sobreescribirTraduccion = !store.dataActiva.sobreescribirTraduccion"
                          :class="store.dataActiva.sobreescribirTraduccion ? 'bg-orange-100 text-orange-600 border-orange-300' : 'bg-slate-50 text-slate-400 border-slate-200 hover:bg-slate-100'"
                          class="px-4 border rounded-xl transition-colors shadow-sm" title="Forzar traducción de este componente">
                    <i class="fas fa-language"></i>
                  </button>
                </div>

                <div v-else class="relative">
                  <input value="Componente Contenedor (Solo ítems)"
                         type="text" disabled
                         class="w-full bg-slate-100 text-slate-400 border border-slate-200 rounded-xl px-4 py-3 text-sm font-bold outline-none cursor-not-allowed">
                </div>
              </div>

              <div class="col-span-2 grid grid-cols-2 gap-3 p-3 bg-white border border-slate-200 rounded-xl shadow-sm">
                <div>
                  <label class="block text-[9px] font-black text-slate-500 uppercase mb-1">Inicio Exacto *</label>
                  <input v-model="store.dataActiva.fechaHoraInicio" @change="store.onComponenteFechasChange(true)" type="datetime-local" class="w-full bg-slate-50 border border-slate-200 rounded-lg px-2 py-2 text-xs font-bold outline-none focus:border-sky-500 focus:ring-1 focus:ring-sky-500">
                </div>
                <div>
                  <label class="block text-[9px] font-black text-slate-500 uppercase mb-1">Fin Exacto *</label>
                  <input v-model="store.dataActiva.fechaHoraFin" @change="store.onComponenteFechasChange(false)" type="datetime-local" class="w-full bg-slate-50 border border-slate-200 rounded-lg px-2 py-2 text-xs font-bold outline-none focus:border-sky-500 focus:ring-1 focus:ring-sky-500">
                </div>
              </div>

              <div>
                <label class="block text-[10px] font-black text-slate-500 uppercase mb-1.5 ml-1">Cantidad / Noches</label>
                <input v-model="store.dataActiva.cantidad" type="number" readonly class="w-full bg-slate-100 text-slate-400 border border-slate-200 rounded-xl px-4 py-3 text-sm font-black text-center outline-none shadow-inner cursor-not-allowed">
              </div>

              <div>
                <label class="block text-[10px] font-black text-slate-500 uppercase mb-1.5 ml-1">Modo Comercial</label>
                <select v-model="store.dataActiva.modo" class="w-full bg-white text-slate-800 border border-slate-300 rounded-xl px-4 py-3 text-sm font-bold outline-none appearance-none shadow-sm focus:ring-2 focus:ring-sky-500">
                  <option value="incluido">Incluido (Suma Costo)</option>
                  <option value="opcional">Opcional (No Suma)</option>
                  <option value="no_incluido">No Incluido (Se Tacha)</option>
                  <option value="cortesia">Cortesía (Suma 0)</option>
                </select>
              </div>
            </div>

            <div class="border-t border-sky-100 pt-5 mt-4">
              <h3 class="text-[10px] font-black text-sky-700 uppercase tracking-widest mb-3 flex items-center justify-between">
                <span><i class="fas fa-list-check mr-1"></i> Inclusiones / Upsells</span>
                <button @click="store.agregarSnapshotItem(store.dataActiva.id)" class="bg-sky-100 text-sky-700 px-3 py-1.5 rounded-lg shadow-sm text-xs md:text-sm font-bold border border-sky-200 hover:bg-sky-200 transition-colors">+ Añadir Ítem</button>
              </h3>

              <div class="space-y-2">
                <div v-if="!store.dataActiva.snapshotItems?.length" class="text-[10px] font-bold text-slate-400 uppercase text-center py-2 border border-dashed border-slate-200 rounded-lg">
                  No hay ítems registrados
                </div>
                <div v-else v-for="item in store.dataActiva.snapshotItems" :key="item.id"
                     class="flex flex-col gap-1 bg-white p-2.5 rounded-xl border border-slate-200 shadow-sm transition-all"
                     :class="item.tieneUpsell ? 'border-l-4 border-l-orange-400' : ''">

                  <div class="flex gap-3 items-center">
                    <input type="checkbox" v-model="item.incluido"
                           @change="store.toggleUpsellComponent(item, store.dataActiva)"
                           class="w-4 h-4 text-sky-600 rounded border-slate-300 focus:ring-sky-500 cursor-pointer">

                    <input :value="store.getI18nText(item.nombreSnapshot, store.cotizacion.idiomaEdicion)"
                           @input="e => store.setI18nText(item.nombreSnapshot, store.cotizacion.idiomaEdicion, (e.target as HTMLInputElement).value)"
                           class="text-xs font-bold text-slate-700 w-full outline-none bg-transparent"
                           :class="(!item.incluido && item.modo === 'no_incluido') ? 'line-through text-slate-400' : (!item.incluido && item.modo === 'opcional') ? 'text-slate-500 italic' : ''"
                           placeholder="Descripción de la inclusión...">

                    <span v-if="item.modo === 'opcional'" class="text-[8px] font-black bg-slate-100 text-slate-500 px-1.5 py-0.5 rounded uppercase">Opcional</span>
                    <span v-if="item.tieneUpsell" class="text-[8px] font-black bg-orange-100 text-orange-600 px-1.5 py-0.5 rounded uppercase flex-shrink-0 whitespace-nowrap"><i class="fas fa-arrow-up"></i> Upsell</span>

                    <button @click="item.sobreescribirTraduccion = !item.sobreescribirTraduccion"
                            class="transition-colors px-1"
                            :class="item.sobreescribirTraduccion ? 'text-orange-500' : 'text-slate-300 hover:text-slate-500'" title="Forzar traducción del ítem">
                      <i class="fas fa-language text-sm"></i>
                    </button>

                    <button @click="store.eliminarSnapshotItem(store.dataActiva.id, item.id)" class="text-slate-300 hover:text-red-500 transition-colors px-1">
                      <i class="fas fa-times text-sm"></i>
                    </button>
                  </div>
                </div>
              </div>
            </div>

            <div class="border-t border-sky-100 pt-5">
              <div class="flex items-center justify-between mb-3">
                <h3 class="text-[10px] font-black text-orange-600 uppercase tracking-widest">
                  <span>Tarifas / Costos</span>
                </h3>
                <span v-if="store.isComponenteConAlerta(store.dataActiva)" class="bg-red-100 text-red-600 px-2 py-1 rounded text-[9px] font-bold border border-red-200">
                      <i class="fas fa-exclamation-circle mr-1"></i> Faltan Pax
                  </span>
                <button @click="store.agregarTarifa(store.dataActiva.id)" class="bg-orange-500 hover:bg-orange-600 text-white px-3 py-1.5 rounded-lg shadow-sm text-xs md:text-sm font-bold transition-colors">+ Añadir Tarifa</button>
              </div>
              <div class="space-y-3">
                <div v-for="tarifa in store.dataActiva.cottarifas" :key="tarifa.id" @click="store.abrirNivel('tarifa', tarifa)"
                     class="bg-white border-2 border-orange-200 rounded-xl p-4 shadow-sm cursor-pointer hover:border-orange-400 relative group overflow-hidden transition-all">
                  <div class="absolute left-0 top-0 bottom-0 w-1.5 bg-orange-400"></div>

                  <button @click.stop="store.eliminarTarifa(store.dataActiva.id, tarifa.id)" class="absolute right-3 top-3 text-slate-300 hover:text-red-500 transition-colors z-10 p-1 bg-slate-50 w-6 h-6 rounded-full flex items-center justify-center">
                    <i class="fas fa-trash-alt text-xs"></i>
                  </button>

                  <div class="flex justify-between items-center pr-6">
                    <div>
                      <h4 class="font-bold text-sm text-slate-800">{{ store.getI18nText(tarifa.nombreSnapshot, store.cotizacion.idiomaEdicion) }}</h4>
                      <div class="flex gap-2 mt-1">
      <span class="text-[9px] font-bold bg-slate-100 text-slate-500 px-1.5 py-0.5 rounded border border-slate-200 flex items-center gap-1">
         <i :class="tarifa.esGrupal ? 'fas fa-users' : 'fas fa-user'"></i>
         {{ tarifa.esGrupal ? 'Costo Grupal' : `${tarifa.cantidad} Pax` }}
      </span>
                        <span v-if="tarifa.esGrupal" class="text-[9px] font-black bg-orange-100 text-orange-600 px-1.5 py-0.5 rounded uppercase">Fijo</span>
                      </div>
                    </div>
                    <span class="font-black text-orange-600 text-lg">{{ formatMoneda(tarifa.montoCosto * (tarifa.esGrupal ? 1 : tarifa.cantidad), tarifa.moneda) }}</span>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>

        <div v-else-if="store.inspectorActivo === 'tarifa'" class="flex-1 flex flex-col min-h-0 bg-slate-900">
          <div class="px-5 py-4 border-b border-slate-700 flex items-center gap-3 bg-slate-800 flex-shrink-0">
            <button @click="store.retrocederNivel" class="w-8 h-8 rounded-full hover:bg-slate-700 text-slate-400 flex items-center justify-center transition-colors"><i class="fas fa-arrow-left"></i></button>
            <div class="flex-1 min-w-0">
              <p class="text-[9px] font-black text-emerald-400 uppercase tracking-widest truncate">Costo y Operativa</p>
              <h2 class="text-sm font-black text-white truncate">{{ store.getI18nText(store.dataActiva?.nombreSnapshot, store.cotizacion.idiomaEdicion) }}</h2>
            </div>
          </div>
          <div class="p-5 flex-1 overflow-y-auto space-y-6 pb-28">
            <div class="bg-slate-800 border border-slate-700 p-4 rounded-xl">
              <label class="block text-[10px] font-black text-orange-400 uppercase tracking-widest mb-2"><i class="fas fa-tags mr-1"></i> Tarifa Maestra</label>
              <SearchableSelect
                  v-model="store.dataActiva.tarifaMaestraId"
                  :options="opcionesTarifas"
                  placeholder="Precio manual..."
                  :darkMode="true"
                  @change="val => store.onTarifaMaestraChange(val)"
              />

              <div v-if="store.dataActiva.tarifaMaestraId" class="mt-3 pt-3 border-t border-slate-700 flex flex-wrap gap-2">
                <template v-for="catT in [store.catalogos.tarifas.find(t => store.extractIdStr(t.id || t['@id']) === store.extractIdStr(store.dataActiva.tarifaMaestraId))]">
                    <span v-if="catT" class="text-[9px] font-bold text-slate-300 bg-slate-700 px-2 py-1 rounded border border-slate-600 uppercase">
                      <i class="fas fa-globe-americas text-emerald-400 mr-1"></i>
                      {{ catT.procedencia ? catT.procedencia : 'Sin restricción origen' }}
                    </span>
                  <span v-if="catT && (catT.edadMinima !== undefined || catT.edadMaxima !== undefined)" class="text-[9px] font-bold text-slate-300 bg-slate-700 px-2 py-1 rounded border border-slate-600 uppercase">
                      <i class="fas fa-birthday-cake text-orange-400 mr-1"></i>
                      {{ catT.edadMinima !== undefined ? catT.edadMinima : 0 }} - {{ catT.edadMaxima !== undefined ? catT.edadMaxima : 120 }} años
                    </span>
                </template>
              </div>

            </div>
            <div class="grid grid-cols-2 gap-4">
              <div class="col-span-2">
                <label class="block text-[10px] font-black text-slate-400 uppercase mb-1.5 ml-1">Nombre en Recibo *</label>
                <div class="flex gap-2">
                  <input :value="store.getI18nText(store.dataActiva.nombreSnapshot, store.cotizacion.idiomaEdicion)"
                         @input="e => store.setI18nText(store.dataActiva.nombreSnapshot, store.cotizacion.idiomaEdicion, (e.target as HTMLInputElement).value)"
                         type="text" class="flex-1 bg-slate-800 border border-slate-600 text-white rounded-xl px-4 py-3 text-sm font-bold focus:ring-2 focus:ring-orange-500 outline-none">

                  <button @click="store.dataActiva.sobreescribirTraduccion = !store.dataActiva.sobreescribirTraduccion"
                          :class="store.dataActiva.sobreescribirTraduccion ? 'bg-orange-500/20 text-orange-400 border-orange-500/50' : 'bg-slate-800 text-slate-500 border-slate-600 hover:text-slate-300'"
                          class="px-4 border rounded-xl transition-colors" title="Forzar traducción">
                    <i class="fas fa-language"></i>
                  </button>
                </div>
              </div>

              <div class="col-span-2 bg-slate-800/50 border border-slate-700 p-4 rounded-2xl mb-2">
                <div class="flex items-center justify-between">
                  <div>
                    <p class="text-xs font-black text-white flex items-center gap-2">
                      <i class="fas fa-calculator text-emerald-400"></i> Modalidad de Cálculo
                    </p>
                    <p class="text-[10px] text-slate-400 mt-1">
                      {{ store.dataActiva.tarifaMaestraId ? 'Bloqueado por Catálogo Maestro' : 'Define si el costo es por persona o por el total' }}
                    </p>
                  </div>

                  <button @click="!store.dataActiva.tarifaMaestraId && (store.dataActiva.esGrupal = !store.dataActiva.esGrupal)"
                          :disabled="!!store.dataActiva.tarifaMaestraId"
                          :class="[
                'relative inline-flex h-6 w-11 items-center rounded-full transition-colors focus:outline-none',
                store.dataActiva.esGrupal ? 'bg-orange-500' : 'bg-slate-600',
                store.dataActiva.tarifaMaestraId ? 'opacity-50 cursor-not-allowed' : 'cursor-pointer'
            ]">
      <span :class="store.dataActiva.esGrupal ? 'translate-x-6' : 'translate-x-1'"
            class="inline-block h-4 w-4 transform rounded-full bg-white transition-transform" />
                  </button>
                </div>

                <div class="flex gap-4 mt-4">
                  <div :class="store.dataActiva.esGrupal ? 'opacity-50' : 'opacity-100'" class="flex-1 text-center p-2 rounded-xl border border-dashed border-slate-600">
                    <i class="fas fa-user text-xs mb-1"></i>
                    <p class="text-[8px] font-black uppercase">Unitario (Pax)</p>
                  </div>
                  <div :class="!store.dataActiva.esGrupal ? 'opacity-50' : 'opacity-100'" class="flex-1 text-center p-2 rounded-xl border border-orange-500/50 bg-orange-500/10 text-orange-400">
                    <i class="fas fa-users text-xs mb-1"></i>
                    <p class="text-[8px] font-black uppercase">Grupal (Flat)</p>
                  </div>
                </div>
              </div>

              <div>
                <label class="block text-[10px] font-black text-slate-400 uppercase mb-1.5 ml-1">Cant (Pax) *</label>
                <input v-model="store.dataActiva.cantidad"
                       type="number"
                       :readonly="store.dataActiva.esGrupal"
                       :class="store.dataActiva.esGrupal ? 'bg-slate-700 text-slate-500 cursor-not-allowed border-slate-700' : 'bg-slate-800 text-white border-slate-600 focus:ring-2 focus:ring-orange-500'"
                       class="w-full rounded-xl px-4 py-3 text-sm font-bold text-center outline-none shadow-sm border">
                <p v-if="store.dataActiva.esGrupal" class="text-[9px] text-orange-400 mt-1 ml-1">Precio por grupo fijo</p>
              </div>

              <div class="col-span-2 bg-slate-800 border border-slate-700 rounded-2xl p-4 flex justify-between items-center shadow-sm">
                <div>
                  <label class="block text-[10px] font-black text-slate-400 uppercase mb-1.5">Moneda</label>
                  <select v-model="store.dataActiva.moneda" class="bg-transparent text-white font-bold text-xs outline-none border-b border-slate-600 pb-1 appearance-none">
                    <option value="USD" class="text-slate-800">USD</option>
                    <option value="PEN" class="text-slate-800">PEN</option>
                  </select>
                </div>
                <div>
                  <label class="block text-[10px] font-black text-slate-400 uppercase mb-1.5 text-right">Costo Unitario</label>
                  <input v-model.number="store.dataActiva.montoCosto" type="number" step="0.01" class="w-32 bg-slate-900 border border-slate-600 text-orange-400 rounded-xl px-3 py-2 text-xl font-black text-right focus:border-orange-500 outline-none">
                </div>
              </div>
              <div class="col-span-2 text-right">
                <p class="text-[10px] text-slate-400 font-bold uppercase">Subtotal Neto: <span class="text-orange-400 text-sm">{{ formatMoneda(store.dataActiva.montoCosto * store.dataActiva.cantidad, store.dataActiva.moneda) }}</span></p>
              </div>
            </div>
          </div>
        </div>

        <div v-if="store.inspectorActivo !== 'resumen' && store.cotizacion"
             @click="isTotalsDrawerOpen = true"
             class="absolute bottom-0 w-full bg-slate-900 border-t border-slate-700/50 px-5 py-4 flex justify-between items-center flex-shrink-0 shadow-[0_-10px_20px_-5px_rgba(0,0,0,0.4)] z-40 cursor-pointer hover:bg-slate-800 active:bg-slate-950 transition-colors">

          <div class="absolute -top-3 left-1/2 -translate-x-1/2 bg-slate-900 px-4 py-0.5 rounded-t-lg border-t border-x border-slate-700/50 text-slate-400 shadow-sm flex flex-col items-center justify-center">
            <i class="fas fa-chevron-up text-[10px]"></i>
          </div>

          <div class="flex items-center gap-3">
            <div class="w-8 h-8 rounded-full bg-slate-800 border border-slate-700 flex items-center justify-center text-slate-300">
              <i class="fas fa-chart-pie text-xs"></i>
            </div>
            <div class="flex flex-col">
              <span class="text-[8px] font-black text-slate-400 uppercase tracking-widest leading-none mb-0.5">Costo Neto Total</span>
              <span class="text-base font-black text-white leading-none">{{ formatMoneda(store.totalCostoNeto, store.cotizacion.monedaGlobal) }}</span>
            </div>
          </div>
          <div class="flex flex-col items-end">
            <span class="text-[8px] font-black text-emerald-400 uppercase tracking-widest leading-none mb-0.5">Venta Sugerida</span>
            <span class="text-xl font-black text-emerald-400 leading-none">{{ formatMoneda(store.ventaSugerida, store.cotizacion.monedaGlobal) }}</span>
          </div>
        </div>

        <Transition name="slide-up">
          <div v-if="isTotalsDrawerOpen" class="fixed inset-0 z-[300] flex flex-col justify-end bg-slate-900/60 backdrop-blur-sm md:items-end md:justify-start" @click.self="isTotalsDrawerOpen = false">

            <div class="bg-slate-50 w-full md:w-[420px] md:h-screen rounded-t-3xl md:rounded-none shadow-2xl flex flex-col max-h-[85vh] md:max-h-full overflow-hidden relative transition-transform">

              <div class="flex justify-between items-center px-6 py-4 bg-white border-b border-slate-200 z-10 sticky top-0 shadow-sm">
                <h3 class="font-black text-slate-800 text-sm uppercase tracking-widest flex items-center gap-2">
                  <i class="fas fa-search-dollar text-[#376875]"></i> Desglose Financiero
                </h3>
                <button @click="isTotalsDrawerOpen = false" class="w-8 h-8 flex items-center justify-center bg-slate-100 hover:bg-red-100 rounded-full text-slate-500 hover:text-red-500 transition-colors">
                  <i class="fas fa-times"></i>
                </button>
              </div>

              <div class="p-5 overflow-y-auto space-y-4 flex-1 pb-10">

                <div class="bg-[#376875] text-white rounded-2xl p-5 shadow-md relative overflow-hidden">
                  <i class="fas fa-chart-pie absolute -right-6 -bottom-6 text-7xl opacity-10"></i>
                  <div class="relative z-10">
                    <p class="text-[9px] font-bold text-emerald-400 uppercase tracking-widest mb-1">Venta Total Sugerida</p>
                    <p class="text-3xl font-black tracking-tight">{{ formatMoneda(store.resumenFinanciero?.totalVentaBruta, store.cotizacion?.monedaGlobal) }}</p>
                    <div class="mt-3 pt-3 border-t border-slate-800/30 flex justify-between items-end">
                      <div>
                        <p class="text-[8px] text-slate-300 uppercase font-bold">Costo Neto</p>
                        <p class="text-base font-bold text-white">{{ formatMoneda(store.resumenFinanciero?.totalCostoNeto, store.cotizacion?.monedaGlobal) }}</p>
                      </div>
                      <div class="text-right">
                        <p class="text-[8px] text-emerald-400 uppercase font-bold">Margen Bruto</p>
                        <p class="text-base font-bold text-emerald-300">+{{ formatMoneda(store.resumenFinanciero?.ganancia, store.cotizacion?.monedaGlobal) }}</p>
                      </div>
                    </div>
                  </div>
                </div>

                <div class="space-y-3 pt-2">
                  <h3 class="text-[9px] font-black text-slate-400 uppercase tracking-widest ml-1"><i class="fas fa-users mr-1"></i> Análisis por Perfil</h3>

                  <div v-for="clase in store.resumenFinanciero?.clasesPasajeros" :key="clase.tipo"
                       class="bg-white border border-slate-200 rounded-2xl p-4 shadow-sm"
                       :class="clase.tipo.includes('anomalo') ? 'border-red-300' : ''">
                    <div class="flex justify-between items-start mb-3">
                      <div>
                        <span :class="clase.tipo.includes('anomalo') ? 'bg-red-100 text-red-700' : 'bg-indigo-100 text-indigo-700'" class="px-2 py-0.5 rounded text-[10px] font-black uppercase">
                          {{ clase.cantidad }}x {{ clase.tipoPaxNombre }}
                        </span>
                        <p class="text-[10px] font-bold text-slate-500 mt-1">Rango: {{ clase.edadMin }} a {{ clase.edadMax }} años</p>
                      </div>
                      <div class="text-right">
                        <p class="text-[8px] text-slate-400 font-bold uppercase">Venta Unit.</p>
                        <p class="text-xs font-black text-slate-800">{{ formatMoneda(clase.resumen.ventaDolares / (clase.cantidad || 1), store.cotizacion?.monedaGlobal) }}</p>
                      </div>
                    </div>

                    <div class="grid grid-cols-2 gap-2 mt-2 pt-2 border-t border-slate-50">
                      <div class="bg-slate-50 p-2 rounded-lg text-center">
                        <p class="text-[7px] text-slate-400 font-bold uppercase">Costo Total</p>
                        <p class="text-[10px] font-black text-slate-600">{{ formatMoneda(clase.resumen.montoDolares, store.cotizacion?.monedaGlobal) }}</p>
                      </div>
                      <div class="bg-emerald-50 p-2 rounded-lg text-center">
                        <p class="text-[7px] text-emerald-600 font-bold uppercase">Utilidad</p>
                        <p class="text-[10px] font-black text-emerald-700">{{ formatMoneda(clase.resumen.gananciaDolares, store.cotizacion?.monedaGlobal) }}</p>
                      </div>
                    </div>

                    <div v-if="clase.tipo.includes('anomalo') && clase.conflictos?.length > 0" class="mt-3 pt-3 border-t border-red-100">
                      <p class="text-[9px] font-black text-red-500 uppercase tracking-widest mb-1.5"><i class="fas fa-search"></i> Origen del conflicto:</p>
                      <ul class="space-y-1">
                        <li v-for="(conflicto, idx) in clase.conflictos" :key="idx" class="text-[10px] font-bold text-red-700 bg-red-50 p-1.5 rounded border border-red-100 flex items-start gap-1.5 leading-tight">
                          <i class="fas fa-exclamation-triangle mt-0.5 opacity-70 text-[9px]"></i>
                          <span>{{ conflicto }}</span>
                        </li>
                      </ul>
                    </div>

                  </div>
                </div>

              </div>
            </div>
          </div>
        </Transition>

      </aside>
    </div>

  </div>
</template>

<style scoped>
::-webkit-scrollbar { width: 6px; }
::-webkit-scrollbar-track { background: transparent; }
::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 10px; }
.fade-scale-enter-active, .fade-scale-leave-active { transition: all 0.3s ease; }
.fade-scale-enter-from, .fade-scale-leave-to { opacity: 0; transform: scale(0.95); }

/* Animación para el panel deslizante inferior */
.slide-up-enter-active, .slide-up-leave-active { transition: transform 0.3s cubic-bezier(0.16, 1, 0.3, 1); }
.slide-up-enter-from, .slide-up-leave-to { transform: translateY(100%); }
</style>