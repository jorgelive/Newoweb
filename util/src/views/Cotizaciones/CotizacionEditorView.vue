<script setup lang="ts">
import { ref, onMounted, computed, watch, onUnmounted } from 'vue';
import { useRoute, useRouter, onBeforeRouteLeave } from 'vue-router';
import { useCotizacionEditorStore } from '@/stores/cotizaciones/cotizacionEditorStore';
import SearchableSelect from '@/components/SearchableSelect.vue';
import WysiwygEditor from '@/components/WysiwygEditor.vue';

// 🔥 IMPORTS DEL DATEPICKER Y MÁSCARAS
import { VueDatePicker } from '@vuepic/vue-datepicker';
import '@vuepic/vue-datepicker/dist/main.css';
import IMask from 'imask';

defineProps<{
  fileId?: string;
  cotizacionId?: string;
}>();

const route = useRoute();
const router = useRouter();
const store = useCotizacionEditorStore();

// ============================================================================
// 🔥 GUARDIÁN DE CAMBIOS SIN GUARDAR
// ============================================================================
const isDirty = ref(false);
let watchActivo = false;

const onBeforeUnload = (e: BeforeUnloadEvent) => {
  if (isDirty.value) {
    e.preventDefault();
    e.returnValue = '';
  }
};

onMounted(() => {
  window.addEventListener('beforeunload', onBeforeUnload);

  const fileId = route.params.fileId as string;
  const cotizacionId = route.params.cotizacionId as string;

  if (fileId && cotizacionId) {
    store.inicializarEditor(fileId, cotizacionId).then(() => {
      setTimeout(() => {
        watchActivo = true;
        isDirty.value = false;
      }, 1000);
    });
  } else {
    router.push('/cotizaciones');
  }
});

onUnmounted(() => {
  window.removeEventListener('beforeunload', onBeforeUnload);
});

watch(() => store.cotizacion, () => {
  if (watchActivo) {
    isDirty.value = true;
  }
}, { deep: true });

onBeforeRouteLeave((to, from, next) => {
  if (isDirty.value) {
    const confirmacion = window.confirm('Tienes cambios sin guardar. ¿Estás seguro de que deseas salir y perder los cambios?');
    if (confirmacion) {
      next();
    } else {
      next(false);
    }
  } else {
    next();
  }
});

const handleVolver = () => {
  const fileId = route.params.fileId || store.fileActual?.id;
  if (fileId) {
    router.push(`/cotizaciones/${fileId}`);
  } else {
    router.push('/cotizaciones');
  }
};

const handleGuardar = async () => {
  await store.guardarCotizacion();
  isDirty.value = false;
};

// ============================================================================
// 🔥 1. MÁSCARA ESTRICTA PARA FECHA Y HORA (Componentes Logísticos / Vuelos)
// ============================================================================
const formatParaMascara = (isoString?: string) => {
  if (!isoString) return '';
  const d = new Date(isoString);
  if (isNaN(d.getTime())) return '';
  const pad = (n: number) => n.toString().padStart(2, '0');
  return `${pad(d.getDate())}/${pad(d.getMonth() + 1)}/${d.getFullYear()} ${pad(d.getHours())}:${pad(d.getMinutes())}`;
};

const procesarFechaMascara = (fechaTexto: string, tipo: 'inicio' | 'fin') => {
  if (fechaTexto.length === 16) {
    const [fecha, hora] = fechaTexto.split(' ');
    const [dia, mes, ano] = fecha.split('/');
    const isoString = `${ano}-${mes}-${dia}T${hora}:00`;

    if (tipo === 'inicio') {
      store.actualizarInicioManteniendoRango(isoString);
    } else {
      store.dataActiva.fechaHoraFin = isoString;
      store.onComponenteFechasChange(false);
    }
  }
};

const vStrictMask = {
  mounted(el: HTMLInputElement, binding: any) {
    const mask = IMask(el, {
      mask: 'd/m/Y H:M',
      lazy: false,
      blocks: {
        d: { mask: IMask.MaskedRange, from: 1, to: 31, maxLength: 2 },
        m: { mask: IMask.MaskedRange, from: 1, to: 12, maxLength: 2 },
        Y: { mask: IMask.MaskedRange, from: 2024, to: 2035, maxLength: 4 },
        H: { mask: IMask.MaskedRange, from: 0, to: 23, maxLength: 2 },
        M: { mask: IMask.MaskedRange, from: 0, to: 59, maxLength: 2 }
      }
    });

    mask.on('complete', () => {
      if(binding.value) binding.value(mask.value);
    });
  }
};

// ============================================================================
// 🔥 2. MÁSCARA ESTRICTA SÓLO FECHA (Alojamientos, Tickets, Alimentación)
// ============================================================================
const formatFechaCortaParaMascara = (isoString?: string) => {
  if (!isoString) return '';
  const d = new Date(isoString);
  if (isNaN(d.getTime())) return '';
  const pad = (n: number) => n.toString().padStart(2, '0');
  return `${pad(d.getDate())}/${pad(d.getMonth() + 1)}/${d.getFullYear()}`;
};

const procesarFechaCortaMascara = (fechaTexto: string, tipo: 'inicio' | 'fin') => {
  if (fechaTexto.length === 10) {
    const [dia, mes, ano] = fechaTexto.split('/');
    const isoString = `${ano}-${mes}-${dia}T00:00:00`;

    if (tipo === 'inicio') {
      store.actualizarInicioManteniendoRango(isoString);
    } else {
      store.dataActiva.fechaHoraFin = isoString;
      store.onComponenteFechasChange(false);
    }
  }
};

const vDateMask = {
  mounted(el: HTMLInputElement, binding: any) {
    const mask = IMask(el, {
      mask: 'd/m/Y',
      lazy: false,
      blocks: {
        d: { mask: IMask.MaskedRange, from: 1, to: 31, maxLength: 2 },
        m: { mask: IMask.MaskedRange, from: 1, to: 12, maxLength: 2 },
        Y: { mask: IMask.MaskedRange, from: 2024, to: 2035, maxLength: 4 }
      }
    });

    mask.on('complete', () => {
      if(binding.value) binding.value(mask.value);
    });
  }
};

// ============================================================================
// DATOS COMPUTADOS
// ============================================================================

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

const opcionesProveedores = computed(() => {
  return store.catalogos.proveedores
      .map(p => ({
        value: p.id || p['@id'],
        label: p.nombreComercial || 'Sin nombre'
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

  let minTimeExact = Infinity;
  let maxTimeExact = -Infinity;
  let minStrExact = '';
  let maxStrExact = '';

  let minDateFallback = Infinity;
  let maxDateFallback = -Infinity;
  let minStrFallback = '';
  let maxStrFallback = '';

  let tieneHorasValidas = false;

  servicio.cotcomponentes.forEach((c: any) => {
    const maestroTipo = store.getTipoComponente(c.componenteMaestroId);
    const reqHora = store.requiereHoraExacta(maestroTipo);

    if (c.fechaHoraInicio) {
      const t = new Date(c.fechaHoraInicio).getTime();
      if (t < minDateFallback) { minDateFallback = t; minStrFallback = c.fechaHoraInicio; }

      if (reqHora && !c.fechaHoraInicio.includes('T00:00:00')) {
        if (t < minTimeExact) { minTimeExact = t; minStrExact = c.fechaHoraInicio; tieneHorasValidas = true; }
      }
    }
    if (c.fechaHoraFin) {
      const t = new Date(c.fechaHoraFin).getTime();
      if (t > maxDateFallback) { maxDateFallback = t; maxStrFallback = c.fechaHoraFin; }

      if (reqHora && !c.fechaHoraFin.includes('T00:00:00')) {
        if (t > maxTimeExact) { maxTimeExact = t; maxStrExact = c.fechaHoraFin; }
      }
    }
  });

  const fTime = (d: Date) => d.toLocaleTimeString('es-PE', { hour: '2-digit', minute: '2-digit', hour12: false });
  const fDate = (d: Date) => d.toLocaleDateString('es-PE', { day: '2-digit', month: 'short' }).replace('.', '');

  if (!tieneHorasValidas) {
    if (minDateFallback === Infinity) return 'Horarios no definidos';
    const dMinF = new Date(minStrFallback);
    const dMaxF = new Date(maxStrFallback);

    if (maxDateFallback === -Infinity || dMinF.toDateString() === dMaxF.toDateString()) {
      return `${fDate(dMinF)}`;
    }
    return `${fDate(dMinF)}  —  ${fDate(dMaxF)}`;
  }

  const dMin = new Date(minStrExact);
  const dMax = new Date(maxStrExact);

  if (maxTimeExact === -Infinity || maxTimeExact <= minTimeExact) return `${fDate(dMin)} • ${fTime(dMin)}`;
  if (dMin.toDateString() === dMax.toDateString()) return `${fDate(dMin)} • ${fTime(dMin)} - ${fTime(dMax)}`;

  return `${fDate(dMin)} ${fTime(dMin)}  —  ${fDate(dMax)} ${fTime(dMax)}`;
};

const formatDateTimeFromISO = (isoString?: string) => {
  if (!isoString) return '--';
  const date = new Date(isoString);
  if (isNaN(date.getTime())) return '--';

  return date.toLocaleString('es-PE', {
    day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit', hour12: false
  }).replace(',', ' -');
};

const formatDateOnlyFromISO = (isoString?: string) => {
  if (!isoString) return '--';
  const date = new Date(isoString);
  if (isNaN(date.getTime())) return '--';
  const pad = (n: number) => n.toString().padStart(2, '0');
  return `${pad(date.getDate())}/${pad(date.getMonth() + 1)}/${date.getFullYear()}`;
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

  const c = store.catalogos.allComponentes.find((cat: any) => extractIdStrView(cat.id) === targetId || extractIdStrView(cat['@id']) === targetId);

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
const modalNota = ref({ isOpen: false, nota: null as any });
const opcionInsercion = ref<'append'|'insert'|'replace'>('append');
const targetSegmentoId = ref<string>('');
const isTotalsDrawerOpen = ref(false);

const abrirModalNota = (nota: any) => {
  modalNota.value = { isOpen: true, nota };
};

const agruparNotasPorTipo = (notas: any[]) => {
  if (!notas || !Array.isArray(notas)) return {};
  return notas.reduce((acc, nota) => {
    const tipo = nota.tipo || 'OTROS';
    if (!acc[tipo]) acc[tipo] = [];
    acc[tipo].push(nota);
    return acc;
  }, {} as Record<string, any[]>);
};

const getTipoNotaUI = (tipo: string | number) => {
  const t = String(tipo).toLowerCase();
  if (t.includes('alerta') || t.includes('peligro')) return { icon: 'fa-exclamation-triangle', bg: 'bg-red-100', text: 'text-red-700', border: 'border-red-200' };
  if (t.includes('politica') || t.includes('regla')) return { icon: 'fa-gavel', bg: 'bg-slate-200', text: 'text-slate-700', border: 'border-slate-300' };
  if (t.includes('tip') || t.includes('operativo')) return { icon: 'fa-lightbulb', bg: 'bg-amber-100', text: 'text-amber-700', border: 'border-amber-200' };
  if (t.includes('intro')) return { icon: 'fa-book-open', bg: 'bg-indigo-100', text: 'text-indigo-700', border: 'border-indigo-200' };
  return { icon: 'fa-info-circle', bg: 'bg-sky-100', text: 'text-sky-700', border: 'border-sky-200' };
};

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

const isProveedorOpen = ref(false);

const dropSegmento = (e: DragEvent) => {
  if (e.dataTransfer) {
    const data = e.dataTransfer.getData('application/json');
    if (data) prepararInsercion(JSON.parse(data));
  }
};
</script>

<template>
  <div class="h-screen bg-slate-50 flex flex-col font-sans overflow-hidden relative">

    <header class="bg-slate-900 text-white px-4 md:px-6 py-3 flex items-center justify-between z-20 shadow-md flex-shrink-0">
      <div class="flex items-center gap-3">
        <button @click="handleVolver" class="w-8 h-8 md:w-10 h-10 flex items-center justify-center bg-slate-800 hover:bg-slate-700 rounded-full transition-colors">
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
        <button @click="handleGuardar" class="px-4 md:px-5 py-2 bg-[#E07845] hover:bg-[#c96636] rounded-lg text-xs font-bold transition-colors flex items-center gap-2">
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

                    <div class="font-black text-lg text-slate-900 leading-tight">
                      <i v-if="store.isServicioConAlerta(servicio)" class="fas fa-exclamation-triangle text-red-500 mr-2" title="Faltan cuadrar tarifas"></i>

                      <span v-if="store.getI18nText(servicio.itinerarioNombreSnapshot, 'es') !== 'Sin plantilla'">
                        {{ store.getI18nText(servicio.itinerarioNombreSnapshot, store.cotizacion.idiomaEdicion) }}
                      </span>

                      <ul v-else-if="servicio.cotsegmentos && servicio.cotsegmentos.length > 0" class="flex flex-col gap-0 leading-[1.15] mt-1">
                        <li v-for="seg in [...servicio.cotsegmentos].sort((a, b) => (a.orden || 0) - (b.orden || 0))" :key="seg.id" class="text-[16px] text-slate-800 tracking-tight">
                          <span v-if="servicio.cotsegmentos.length > 1">- </span>{{ store.getI18nText(seg.nombreSnapshot, store.cotizacion.idiomaEdicion) }}
                        </li>
                      </ul>

                      <span v-else>
                        {{ store.getI18nText(servicio.nombreSnapshot, store.cotizacion.idiomaEdicion) }}
                      </span>
                    </div>

                    <p class="text-[11px] font-bold text-slate-500 mt-1.5" v-if="store.getI18nText(servicio.itinerarioNombreSnapshot, 'es') !== 'Sin plantilla'">
                      <i class="fas fa-map-signs mr-1"></i> Plantilla Aplicada
                    </p>
                    <p class="text-[11px] font-bold text-slate-500 mt-1.5" v-else-if="servicio.cotsegmentos && servicio.cotsegmentos.length > 0">
                      <i class="fas fa-layer-group mr-1"></i> Storytelling a medida ({{ servicio.cotsegmentos.length }} párrafos)
                    </p>
                    <p class="text-[11px] font-bold text-slate-500 mt-1.5" v-else>
                      <i class="fas fa-pen-nib mr-1"></i> Sin Storytelling
                    </p>

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

                    <p v-if="clase.edadMin > 0 || clase.edadMax < 120" class="text-[11px] font-bold text-slate-500 mt-1">
                      <span v-if="clase.edadMin > 0 && clase.edadMax < 120">Rango: {{ clase.edadMin }} a {{ clase.edadMax }} años</span>
                      <span v-else-if="clase.edadMin > 0">A partir de {{ clase.edadMin }} años</span>
                      <span v-else>Hasta los {{ clase.edadMax }} años</span>
                    </p>
                    <p v-else class="text-[11px] font-bold text-slate-400 mt-1">Sin restricción de edad</p>

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
                <button @click="store.dataActiva.servicioMaestroId && store.abrirEditorSegmentos()"
                        :disabled="!store.dataActiva.servicioMaestroId"
                        :class="!store.dataActiva.servicioMaestroId ? 'bg-slate-300 text-slate-500 cursor-not-allowed shadow-none' : 'bg-indigo-600 hover:bg-indigo-700 text-white'"
                        class="px-3 py-2 rounded-lg text-[10px] font-bold shadow-sm whitespace-nowrap transition-colors">
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

                <div v-for="comp in store.dataActiva.cotcomponentes" :key="comp.id"
                     @click="store.abrirNivel('componente', comp)"
                     class="bg-white border-2 rounded-xl p-4 shadow-sm cursor-pointer relative group overflow-hidden transition-all flex flex-col min-h-[140px]"
                     :class="[
                        store.isComponenteConAlerta(comp) ? 'border-red-400 bg-red-50/20' :
                        (!store.requiereHoraExacta(store.getTipoComponente(comp.componenteMaestroId)) ? 'border-dashed border-slate-300 hover:border-slate-400 bg-slate-50/50' : 'border-slate-200 hover:border-sky-300')
                     ]">

                  <div class="absolute left-0 top-0 bottom-0 w-1.5"
                       :class="store.isComponenteConAlerta(comp) ? 'bg-red-400' : (!store.requiereHoraExacta(store.getTipoComponente(comp.componenteMaestroId)) ? 'bg-slate-300' : 'bg-sky-400')"></div>

                  <button v-if="!store.isComponenteBloqueado(comp)" @click.stop="store.eliminarComponente(store.dataActiva.id, comp.id)" class="absolute right-3 top-3 text-slate-300 hover:text-red-500 transition-colors z-10 bg-slate-50 w-7 h-7 rounded-full flex justify-center items-center">
                    <i class="fas fa-trash-alt text-sm"></i>
                  </button>

                  <div class="flex justify-between items-start mb-3">
                    <h4 class="font-black text-sm text-slate-800 leading-tight pr-8 flex flex-col">
                      <div class="flex items-center gap-1.5">
                        <i v-if="store.isComponenteConAlerta(comp)" class="fas fa-exclamation-triangle text-red-500" title="Tarifas no cuadran"></i>
                        {{ getNombreMaestroRef(comp) }}
                      </div>
                      <span v-if="!store.requiereHoraExacta(store.getTipoComponente(comp.componenteMaestroId))" class="text-[9px] font-black text-slate-400 uppercase tracking-widest mt-1">
                         <i class="fas fa-infinity text-[8px] mr-0.5"></i> Horario Libre / Final del día
                      </span>
                    </h4>
                    <span class="text-[10px] font-black px-2 py-1 rounded bg-slate-100 text-slate-500 border border-slate-200 shadow-sm whitespace-nowrap">
                      {{ comp.modo ? comp.modo.toUpperCase() : 'INCLUIDO' }}
                    </span>
                  </div>

                  <div class="flex flex-col gap-1.5 mb-3">
                    <span class="bg-sky-50 border border-sky-100 text-sky-800 px-2.5 py-1.5 rounded-lg text-[10px] font-black shadow-sm flex items-center gap-2 w-max">
                      <i class="far fa-calendar-alt text-sky-500"></i>
                      {{ store.requiereHoraExacta(store.getTipoComponente(comp.componenteMaestroId)) ? 'INICIO: ' + formatDateTimeFromISO(comp.fechaHoraInicio) : 'FECHA: ' + formatDateOnlyFromISO(comp.fechaHoraInicio) }}
                    </span>
                    <span v-if="store.requiereHoraExacta(store.getTipoComponente(comp.componenteMaestroId)) || store.calcularPernoctes(comp.fechaHoraInicio, comp.fechaHoraFin) > 1" class="bg-slate-100 border border-slate-200 text-slate-700 px-2.5 py-1.5 rounded-lg text-[10px] font-black shadow-sm flex items-center gap-2 w-max">
                      <i class="far fa-flag text-slate-400"></i>
                      {{ store.requiereHoraExacta(store.getTipoComponente(comp.componenteMaestroId)) ? 'FIN: ' + formatDateTimeFromISO(comp.fechaHoraFin) : 'HASTA: ' + formatDateOnlyFromISO(comp.fechaHoraFin) }}
                    </span>

                    <span v-if="store.isComponenteBloqueado(comp)" class="mt-1 text-[9px] font-bold text-indigo-400 flex items-center gap-1">
                      <i class="fas fa-link"></i> Insumo Autogenerado (Vinculado)
                    </span>
                  </div>

                  <div v-if="comp.cottarifas?.length" class="mt-auto pt-3 border-t border-slate-100 grid grid-cols-1 gap-2">
                    <div v-for="tarifa in comp.cottarifas" :key="tarifa.id"
                         class="flex items-center justify-between bg-slate-50 hover:bg-orange-50 p-2 rounded-lg border border-slate-200 transition-colors">
                      <div class="flex flex-col min-w-0 pr-2">
                        <span class="text-[9px] font-black text-slate-500 uppercase truncate leading-none mb-1">
                          {{ store.getI18nText(tarifa.nombreSnapshot, store.cotizacion.idiomaEdicion) }}
                        </span>
                        <span class="text-[9px] font-bold text-slate-400 flex items-center gap-1 leading-none">
                          <i :class="tarifa.esGrupal ? 'fas fa-users text-orange-400' : 'fas fa-user text-sky-400'"></i>
                          {{ tarifa.esGrupal ? '1 GRUPO' : `${tarifa.cantidad} PAX` }}
                        </span>
                      </div>
                      <div class="text-right flex-shrink-0">
                        <span class="text-[11px] font-black" :class="comp.modo === 'no_incluido' ? 'text-slate-400 line-through' : 'text-orange-600'">
                          {{ formatMoneda(tarifa.montoCosto * (tarifa.esGrupal ? 1 : tarifa.cantidad), tarifa.moneda) }}
                        </span>
                      </div>
                    </div>
                  </div>
                  <div v-else class="mt-auto pt-3 border-t border-slate-100 text-center bg-slate-50 rounded-lg border border-dashed border-slate-200 p-2">
                    <span class="text-[9px] font-black text-red-400 uppercase tracking-widest flex items-center justify-center gap-1">
                      <i class="fas fa-exclamation-circle"></i> Sin tarifas asignadas
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
                  v-if="!store.isComponenteBloqueado(store.dataActiva)"
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
                      <span class="text-[9px] font-black text-indigo-400 uppercase tracking-widest">Insumo Maestro (Inyectado / Bloqueado)</span>
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

              <div class="col-span-2 grid grid-cols-2 gap-4 p-4 bg-white border border-slate-200 rounded-xl shadow-sm">

                <div>
                  <label class="block text-[10px] font-black text-slate-500 uppercase mb-1.5 ml-1">Inicio Exacto *</label>
                  <VueDatePicker
                      :model-value="store.dataActiva.fechaHoraInicio"
                      @update:model-value="val => store.actualizarInicioManteniendoRango(val || '')"
                      :is-24="true"
                      :enable-time-picker="store.requiereHoraExacta(store.getTipoComponente(store.dataActiva.componenteMaestroId))"
                      :format="store.requiereHoraExacta(store.getTipoComponente(store.dataActiva.componenteMaestroId)) ? 'dd/MM/yyyy HH:mm' : 'dd/MM/yyyy'"
                      model-type="yyyy-MM-dd'T'HH:mm:ss"
                      auto-apply
                  >
                    <template #dp-input="{ value, onEnter, onTab, onClear }">
                      <input v-if="store.requiereHoraExacta(store.getTipoComponente(store.dataActiva.componenteMaestroId))"
                             type="text"
                             class="w-full bg-white border border-slate-300 rounded-lg pl-3 pr-8 py-2 text-xs font-bold text-slate-700 outline-none shadow-sm focus:ring-2 focus:ring-sky-500 cursor-text"
                             :value="formatParaMascara(store.dataActiva.fechaHoraInicio)"
                             v-strict-mask="(val: string) => procesarFechaMascara(val, 'inicio')"
                             @keydown.enter="onEnter"
                             @keydown.tab="onTab"
                             placeholder="DD/MM/AAAA HH:MM"
                      />
                      <input v-else
                             type="text"
                             class="w-full bg-white border border-slate-300 rounded-lg pl-3 pr-8 py-2 text-xs font-bold text-slate-700 outline-none shadow-sm focus:ring-2 focus:ring-sky-500 cursor-text"
                             :value="formatFechaCortaParaMascara(store.dataActiva.fechaHoraInicio)"
                             v-date-mask="(val: string) => procesarFechaCortaMascara(val, 'inicio')"
                             @keydown.enter="onEnter"
                             @keydown.tab="onTab"
                             placeholder="DD/MM/AAAA"
                      />
                    </template>
                  </VueDatePicker>
                </div>

                <div>
                  <label class="block text-[10px] font-black text-slate-500 uppercase mb-1.5 ml-1">Fin Exacto *</label>
                  <VueDatePicker
                      v-model="store.dataActiva.fechaHoraFin"
                      @update:model-value="store.onComponenteFechasChange(false)"
                      :is-24="true"
                      :enable-time-picker="store.requiereHoraExacta(store.getTipoComponente(store.dataActiva.componenteMaestroId))"
                      :format="store.requiereHoraExacta(store.getTipoComponente(store.dataActiva.componenteMaestroId)) ? 'dd/MM/yyyy HH:mm' : 'dd/MM/yyyy'"
                      model-type="yyyy-MM-dd'T'HH:mm:ss"
                      auto-apply
                  >
                    <template #dp-input="{ value, onEnter, onTab, onClear }">
                      <input v-if="store.requiereHoraExacta(store.getTipoComponente(store.dataActiva.componenteMaestroId))"
                             type="text"
                             class="w-full bg-white border border-slate-300 rounded-lg pl-3 pr-8 py-2 text-xs font-bold text-slate-700 outline-none shadow-sm focus:ring-2 focus:ring-sky-500 cursor-text"
                             :value="formatParaMascara(store.dataActiva.fechaHoraFin)"
                             v-strict-mask="(val: string) => procesarFechaMascara(val, 'fin')"
                             @keydown.enter="onEnter"
                             @keydown.tab="onTab"
                             placeholder="DD/MM/AAAA HH:MM"
                      />
                      <input v-else
                             type="text"
                             class="w-full bg-white border border-slate-300 rounded-lg pl-3 pr-8 py-2 text-xs font-bold text-slate-700 outline-none shadow-sm focus:ring-2 focus:ring-sky-500 cursor-text"
                             :value="formatFechaCortaParaMascara(store.dataActiva.fechaHoraFin)"
                             v-date-mask="(val: string) => procesarFechaCortaMascara(val, 'fin')"
                             @keydown.enter="onEnter"
                             @keydown.tab="onTab"
                             placeholder="DD/MM/AAAA"
                      />
                    </template>
                  </VueDatePicker>
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
                  <span v-if="catT && (catT.edadMinima > 0 || catT.edadMaxima < 120)" class="text-[9px] font-bold text-slate-300 bg-slate-700 px-2 py-1 rounded border border-slate-600 uppercase">
                    <i class="fas fa-birthday-cake text-orange-400 mr-1"></i>
                    {{ catT.edadMinima || 0 }} - {{ catT.edadMaxima || 120 }} años
                  </span>
                </template>
              </div>
            </div>

            <div class="grid grid-cols-2 gap-4">
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

              <div class="col-span-2 mt-2">
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

              <div class="col-span-2 bg-slate-800/50 border border-slate-700 rounded-2xl mt-4 relative overflow-hidden transition-all duration-300 shadow-sm">
                <div class="absolute left-0 top-0 bottom-0 w-1 bg-sky-500 z-10"></div>

                <div @click="isProveedorOpen = !isProveedorOpen" class="p-4 pl-5 cursor-pointer flex items-center justify-between hover:bg-slate-700/50 transition-colors relative">
                  <div>
                    <label class="block text-[10px] font-black text-sky-400 uppercase tracking-widest cursor-pointer mb-0.5 flex items-center gap-1.5">
                      <i class="fas fa-truck-loading"></i> Operador Logístico

                      <span v-if="store.dataActiva.estadoOperativoSnapshot && store.dataActiva.estadoOperativoSnapshot !== 'Sin Solicitar'"
                            class="text-[8px] px-1.5 py-0.5 rounded border uppercase font-black tracking-tighter"
                            :class="{
                                'bg-amber-500/20 text-amber-400 border-amber-500/30': store.dataActiva.estadoOperativoSnapshot === 'Solicitado',
                                'bg-emerald-500/20 text-emerald-400 border-emerald-500/30': store.dataActiva.estadoOperativoSnapshot === 'Confirmado' || store.dataActiva.estadoOperativoSnapshot === 'Reconfirmado',
                                'bg-red-500/20 text-red-400 border-red-500/30': store.dataActiva.estadoOperativoSnapshot === 'Pendiente Pago'
                            }">
                        {{ store.dataActiva.estadoOperativoSnapshot }}
                      </span>
                    </label>
                    <p class="text-sm font-bold flex items-center gap-2" :class="store.dataActiva.proveedorNombreSnapshot ? 'text-white' : 'text-slate-500 italic'">
                      {{ store.dataActiva.proveedorNombreSnapshot || 'Sin proveedor asignado' }}
                      <i v-if="store.dataActiva.vencimientoPagoSnapshot" class="fas fa-bell text-orange-400 text-xs" title="Tiene alerta de pago"></i>
                    </p>
                  </div>

                  <div class="flex items-center gap-3">
                    <span v-if="store.dataActiva.proveedorMaestroId" class="text-[8px] bg-emerald-500/20 text-emerald-400 px-2 py-0.5 rounded border border-emerald-500/30 uppercase font-black hidden sm:inline-block">
                      Catálogo
                    </span>
                    <span v-else-if="store.dataActiva.proveedorNombreSnapshot" class="text-[8px] bg-sky-500/20 text-sky-400 px-2 py-0.5 rounded border border-sky-500/30 uppercase font-black hidden sm:inline-block">
                      Libre
                    </span>
                    <div class="w-6 h-6 rounded-full bg-slate-700 flex items-center justify-center text-slate-400 flex-shrink-0">
                      <i class="fas transition-transform" :class="isProveedorOpen ? 'fa-chevron-up' : 'fa-chevron-down'"></i>
                    </div>
                  </div>
                </div>

                <div v-show="isProveedorOpen" class="p-4 pt-2 border-t border-slate-700/50">

                  <div>
                    <SearchableSelect
                        v-model="store.dataActiva.proveedorMaestroId"
                        :options="opcionesProveedores"
                        placeholder="Seleccionar proveedor para operar..."
                        :darkMode="true"
                        @change="val => store.onProveedorChange(val)"
                    />
                  </div>

                  <div class="mt-4">
                    <label class="block text-[9px] font-bold text-slate-500 uppercase mb-1 ml-1">Nombre en Snapshot (Histórico)</label>
                    <input v-model="store.dataActiva.proveedorNombreSnapshot"
                           type="text"
                           class="w-full bg-slate-900 border border-slate-700 text-slate-300 rounded-lg px-3 py-2 text-xs focus:ring-1 focus:ring-sky-500 outline-none"
                           placeholder="Nombre del proveedor o servicio libre..." />
                    <p class="text-[9px] text-slate-500 mt-1 ml-1 flex items-center gap-1">
                      <i class="fas fa-info-circle"></i> Fija la identidad para el historial financiero.
                    </p>
                  </div>

                  <div class="mt-4 pt-4 border-t border-slate-700/50">
                    <label class="block text-[9px] font-bold text-slate-500 uppercase mb-1 ml-1 flex items-center justify-between">
                      <span>Nombre para la Reserva (Email)</span>
                      <i class="fas fa-paper-plane text-slate-600"></i>
                    </label>
                    <input v-model="store.dataActiva.nombreParaProveedorSnapshot"
                           type="text"
                           class="w-full bg-slate-900 border border-slate-700 text-emerald-400 rounded-lg px-3 py-2 text-xs font-bold focus:ring-1 focus:ring-emerald-500 outline-none"
                           placeholder="Ej: Cena Buffet Tunupa..." />
                    <p class="text-[9px] text-slate-500 mt-1 ml-1 flex items-start gap-1">
                      <i class="fas fa-exclamation-circle mt-0.5 text-orange-400"></i>
                      Este es el texto exacto del requerimiento automático.
                    </p>
                  </div>

                  <div class="mt-5 pt-5 border-t border-slate-700/50 grid grid-cols-1 md:grid-cols-3 gap-4">

                    <div>
                      <label class="block text-[9px] font-bold text-slate-500 uppercase mb-1 ml-1 flex items-center gap-1">
                        <i class="fas fa-tasks text-sky-400"></i> Estado de Reserva
                      </label>
                      <select v-model="store.dataActiva.estadoOperativoSnapshot" class="w-full bg-slate-900 border border-slate-700 text-white font-bold rounded-lg px-3 py-2 text-xs focus:ring-1 focus:ring-sky-500 outline-none appearance-none cursor-pointer">
                        <option value="Sin Solicitar">Sin Solicitar</option>
                        <option value="Solicitado">Solicitado</option>
                        <option value="Confirmado">Confirmado</option>
                        <option value="Reconfirmado">Reconfirmado</option>
                        <option value="Pendiente Pago">Pendiente Pago</option>
                      </select>
                    </div>

                    <div>
                      <label class="block text-[9px] font-bold text-slate-500 uppercase mb-1 ml-1 flex items-center justify-between">
                        <span>Día de Vencimiento</span>
                        <i class="far fa-calendar-alt text-red-400"></i>
                      </label>
                      <input v-model="store.dataActiva.fechaLimitePago"
                             type="date"
                             class="w-full bg-slate-900 border border-slate-700 text-red-400 rounded-lg px-3 py-2 text-xs font-bold focus:ring-1 focus:ring-red-500 outline-none [color-scheme:dark]" />
                    </div>

                    <div>
                      <label class="block text-[9px] font-bold text-slate-500 uppercase mb-1 ml-1 flex items-center justify-between">
                        <span>Nota de Pago</span>
                        <i class="fas fa-sticky-note text-amber-400"></i>
                      </label>
                      <input v-model="store.dataActiva.condicionesPagoSnapshot"
                             type="text"
                             class="w-full bg-slate-900 border border-slate-700 text-amber-400 rounded-lg px-3 py-2 text-xs font-bold focus:ring-1 focus:ring-amber-500 outline-none placeholder-slate-600"
                             placeholder="Ej: Depósito BCP / 15 días antes..." />
                    </div>

                  </div>

                </div>
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
      </aside>
    </div>

    <div v-else class="flex-1 flex flex-col items-center justify-center bg-[#F8FAFC] p-8 text-center">
      <i class="fas fa-unlink text-6xl text-slate-300 mb-6"></i>
      <h2 class="text-2xl font-black text-slate-700 tracking-tight">Enlace Incompleto</h2>
      <p class="text-slate-500 mt-2 font-medium max-w-md">
        El motor operativo necesita saber exactamente qué Expediente y qué Versión cargar. Revisa que la URL contenga los identificadores correctos.
      </p>
      <button @click="router.push('/cotizaciones')" class="mt-8 px-6 py-3 bg-slate-900 hover:bg-slate-800 text-white rounded-xl font-bold shadow-md transition-all">
        <i class="fas fa-arrow-left mr-2"></i> Volver al Dashboard
      </button>
    </div>

  </div>

  <Teleport to="body">

    <Transition name="fade-scale">
      <div v-if="store.isSegmentEditorOpen && store.cotizacion" class="fixed inset-0 z-[1000] bg-slate-900/60 backdrop-blur-sm flex items-center justify-center p-4 md:p-8">
        <div class="bg-[#F8FAFC] w-full max-w-6xl h-full max-h-[90vh] rounded-3xl shadow-2xl flex flex-col overflow-hidden border border-slate-200">
          <header class="bg-indigo-600 text-white px-6 py-4 flex justify-between items-center">
            <div>
              <h2 class="font-black text-lg flex items-center gap-2"><i class="fas fa-book-open"></i> Constructor de Storytelling</h2>
              <p class="text-[11px] font-bold text-indigo-200 uppercase tracking-widest mt-1">Servicio: {{ store.getI18nText(store.dataActiva?.nombreSnapshot, store.cotizacion.idiomaEdicion) }}</p>
            </div>
            <button @click="store.cerrarEditorSegmentos()" class="w-8 h-8 rounded-full bg-indigo-500 hover:bg-indigo-400 flex items-center justify-center transition-colors"><i class="fas fa-times"></i></button>
          </header>

          <div class="flex flex-1 overflow-hidden flex-col md:flex-row">
            <aside class="w-full md:w-1/3 bg-white border-b md:border-r border-slate-200 flex flex-col h-[40vh] md:h-full shadow-sm z-10 flex-shrink-0">

              <div class="p-3 md:p-5 border-b border-slate-100 bg-slate-50">
                <label class="block text-[10px] font-black text-indigo-600 uppercase tracking-widest mb-2">1. Cargar Plantilla</label>
                <div class="flex gap-2">
                  <SearchableSelect
                      v-model="plantillaSeleccionada"
                      :options="opcionesPlantillas"
                      placeholder="Elegir itinerario..."
                  />
                  <button @click="plantillaSeleccionada && store.aplicarPlantilla(plantillaSeleccionada)"
                          :disabled="store.isLoading"
                          class="bg-indigo-600 text-white px-3 md:px-4 py-2 rounded-lg text-xs font-bold transition-colors shadow-sm flex items-center gap-2"
                          :class="store.isLoading ? 'opacity-50 cursor-not-allowed' : 'hover:bg-indigo-700'">
                    <i v-if="store.isLoading" class="fas fa-spinner fa-spin"></i>
                    Aplicar
                  </button>
                </div>
              </div>

              <div class="p-3 md:p-5 flex-1 overflow-y-auto bg-white flex flex-col">
                <label class="block text-[10px] font-black text-slate-400 uppercase tracking-widest mb-2 md:mb-3">2. Pool de Segmentos Libres</label>

                <div class="mb-3 md:mb-4 flex-shrink-0">
                  <input v-model="filtroSegmentos" type="text" placeholder="🔍 Buscar por ID o Título..."
                         class="w-full bg-slate-50 border border-slate-200 rounded-lg px-3 py-2 text-xs font-bold text-slate-700 focus:ring-2 focus:ring-indigo-500 outline-none shadow-inner">
                </div>

                <div class="space-y-2 md:space-y-3 overflow-y-auto flex-1 pb-2">
                  <div v-for="seg in poolFiltrado" :key="seg.id" draggable="true" @dragstart="dragStart($event, seg)"
                       class="bg-white border-2 border-dashed border-slate-200 p-2 md:p-3 rounded-xl cursor-grab hover:border-indigo-300 hover:bg-indigo-50 transition-all flex gap-3 shadow-sm group items-center md:items-start">

                    <i class="fas fa-grip-vertical text-slate-300 mt-1 hidden md:block"></i>

                    <div class="flex-1 min-w-0">
                      <div class="text-[9px] font-black text-indigo-500 uppercase tracking-widest mb-0.5 truncate">{{ seg.nombreInterno || 'SIN CÓDIGO' }}</div>
                      <h4 class="text-xs font-bold text-slate-700 leading-tight mb-1 truncate md:whitespace-normal">{{ store.getI18nText(seg.titulo, store.cotizacion.idiomaEdicion) }}</h4>
                      <div class="text-[10px] text-slate-500 line-clamp-1 md:line-clamp-2 prose-sm prose-p:my-0" v-html="store.getI18nText(seg.contenido, store.cotizacion.idiomaEdicion)"></div>
                    </div>

                    <button @click="prepararInsercion(seg)" class="text-indigo-600 hover:bg-indigo-200 bg-indigo-50 md:bg-transparent md:hover:bg-indigo-50 px-3 md:px-2 py-2 md:py-1 h-fit rounded-lg transition-colors flex-shrink-0 md:opacity-0 group-hover:opacity-100 border md:border-none border-indigo-100"><i class="fas fa-plus"></i></button>
                  </div>
                </div>
              </div>
            </aside>
            <main class="flex-1 overflow-y-auto p-6 md:p-8 bg-[#F8FAFC]" @dragover.prevent @dragenter.prevent @drop="dropSegmento">
              <div class="max-w-3xl mx-auto space-y-6 pb-20">
                <h3 class="text-sm font-black text-slate-700 uppercase tracking-widest"><i class="fas fa-stream mr-2"></i> Párrafos en la Cotización</h3>

                <div v-if="!store.dataActiva?.cotsegmentos?.length" class="border-2 border-dashed border-slate-300 rounded-3xl p-12 text-center text-slate-400 flex flex-col items-center">
                  <i class="fas fa-align-center text-4xl mb-4 opacity-50"></i>
                  <p class="text-sm font-bold uppercase tracking-widest">El servicio no tiene textos</p>
                </div>

                <div v-else class="space-y-4 relative">
                  <div class="absolute left-[15px] top-4 bottom-4 w-0.5 bg-slate-200 z-0"></div>
                  <div v-for="(cotSeg, idx) in store.dataActiva.cotsegmentos" :key="cotSeg.id" class="relative z-10 flex gap-4 items-start group">
                    <div class="w-8 h-8 rounded-full bg-white border-4 border-indigo-100 text-indigo-600 flex items-center justify-center font-black text-xs shadow-sm flex-shrink-0 mt-1">{{ idx + 1 }}</div>

                    <div class="flex-1 bg-white border border-slate-200 shadow-sm rounded-2xl overflow-hidden">

                      <div class="bg-slate-50 px-4 py-3 border-b border-slate-200 flex flex-col md:flex-row justify-between items-start md:items-center gap-3">
                        <div class="flex items-center gap-2 bg-white border border-slate-200 rounded-lg px-2 py-1 shadow-sm">
                          <label class="text-[10px] font-black text-indigo-600 uppercase tracking-widest whitespace-nowrap">Día Relativo</label>
                          <input type="number" min="1"
                                 v-model="cotSeg.dia"
                                 @change="store.onSegmentoDiaChange(store.dataActiva.id, cotSeg.id, cotSeg.dia)"
                                 class="w-12 md:w-16 bg-slate-50 border border-slate-300 rounded px-1 md:px-2 py-1 text-xs md:text-sm font-black text-center outline-none focus:ring-2 focus:ring-indigo-500 text-slate-800">
                          <div class="flex flex-col border-l border-slate-200 pl-2">
                            <span class="text-[9px] text-slate-400 font-bold uppercase leading-none">Fecha Real</span>
                            <span class="text-[11px] text-indigo-500 font-black tracking-tight leading-none mt-0.5">{{ formatFecha(cotSeg.fechaAbsoluta) }}</span>
                          </div>
                        </div>

                        <div class="flex items-center gap-2 w-full md:w-auto">
                          <input :value="store.getI18nText(cotSeg.nombreSnapshot, store.cotizacion.idiomaEdicion)"
                                 @input="e => store.setI18nText(cotSeg.nombreSnapshot, store.cotizacion.idiomaEdicion, (e.target as HTMLInputElement).value)"
                                 class="bg-transparent text-xs font-black text-slate-700 uppercase outline-none flex-1 min-w-[150px]" placeholder="Título..." />

                          <button @click="cotSeg.sobreescribirTraduccion = !cotSeg.sobreescribirTraduccion"
                                  class="transition-colors px-2 py-1.5 rounded text-[10px] font-bold border flex items-center gap-1 shadow-sm"
                                  :class="cotSeg.sobreescribirTraduccion ? 'bg-orange-100 text-orange-600 border-orange-300' : 'bg-white text-slate-400 border-slate-200 hover:bg-slate-100'" title="Forzar traducción del párrafo al guardar">
                            <i class="fas fa-language"></i> <span class="hidden md:inline" v-if="cotSeg.sobreescribirTraduccion">Auto-Traducir</span>
                          </button>

                          <button @click="store.removerCotSegmento(cotSeg.id)" class="bg-white border border-slate-200 text-slate-400 hover:text-red-500 hover:border-red-200 transition-colors ml-1 p-1.5 rounded shadow-sm">
                            <i class="fas fa-trash-alt text-sm"></i>
                          </button>
                        </div>
                      </div>

                      <div class="p-4 bg-white">
                        <WysiwygEditor
                            :model-value="store.getI18nText(cotSeg.contenidoSnapshot, store.cotizacion.idiomaEdicion)"
                            @update:model-value="store.setI18nText(cotSeg.contenidoSnapshot, store.cotizacion.idiomaEdicion, $event)"
                        />

                        <div v-if="(cotSeg.notasSnapshot && cotSeg.notasSnapshot.length > 0) || (cotSeg.imagenesSnapshot && cotSeg.imagenesSnapshot.length > 0)" class="mt-8 pt-6 border-t border-slate-200 grid grid-cols-1 md:grid-cols-2 gap-6">
                          <div v-if="cotSeg.notasSnapshot && cotSeg.notasSnapshot.length > 0">
                            <h4 class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-4"><i class="fas fa-clipboard-list mr-1"></i> Recomendaciones del Segmento</h4>
                            <div class="flex flex-col gap-4">
                              <div v-for="(notasGrupo, tipo) in agruparNotasPorTipo(cotSeg.notasSnapshot)" :key="tipo">
                                <div class="flex items-center gap-1.5 text-[9px] font-black text-slate-400 uppercase tracking-widest mb-2">
                                  <i class="fas" :class="getTipoNotaUI(tipo).icon"></i> {{ tipo }}
                                </div>
                                <div class="flex flex-wrap gap-2">
                                  <div v-for="nota in notasGrupo" :key="nota.id"
                                       @click="abrirModalNota(nota)"
                                       class="bg-white border border-slate-200 rounded-lg shadow-sm flex items-stretch overflow-hidden hover:border-indigo-400 transition-all cursor-pointer group max-w-full">
                                    <div :class="[getTipoNotaUI(tipo).bg, getTipoNotaUI(tipo).text]" class="px-2.5 py-1.5 flex items-center justify-center">
                                      <i class="fas text-xs" :class="getTipoNotaUI(tipo).icon"></i>
                                    </div>
                                    <div class="px-2.5 py-1.5 flex-1 min-w-0 flex flex-col justify-center">
                                    <span class="text-[10px] font-bold text-slate-700 block truncate w-full max-w-[160px]">
                                      {{ store.getI18nText(nota.titulo, store.cotizacion.idiomaEdicion) || nota.nombreInterno }}
                                    </span>
                                    </div>
                                    <button @click.stop="cotSeg.notasSnapshot.splice(cotSeg.notasSnapshot.indexOf(nota), 1)"
                                            class="px-2.5 bg-slate-50 border-l border-slate-100 text-slate-300 hover:text-red-500 hover:bg-red-50 transition-colors">
                                      <i class="fas fa-times text-[10px]"></i>
                                    </button>
                                  </div>
                                </div>
                              </div>
                            </div>
                          </div>

                          <div v-if="cotSeg.imagenesSnapshot && cotSeg.imagenesSnapshot.length > 0">
                            <h4 class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-4"><i class="fas fa-images mr-1"></i> Galería Adjunta</h4>
                            <div class="flex gap-2 overflow-x-auto pb-2 custom-scrollbar">
                              <div v-for="(img, iIdx) in cotSeg.imagenesSnapshot" :key="iIdx" class="relative w-16 h-16 rounded-xl overflow-hidden border border-slate-200 flex-shrink-0 group shadow-sm">
                                <img :src="img.imageUrl || '/images/placeholder.jpg'" class="w-full h-full object-cover transition-transform group-hover:scale-110" />
                                <button @click="cotSeg.imagenesSnapshot.splice(iIdx, 1)" class="absolute top-1 right-1 bg-white/90 hover:bg-red-500 hover:text-white w-5 h-5 rounded-full flex items-center justify-center text-[10px] text-slate-600 transition-colors opacity-0 group-hover:opacity-100 shadow-sm" title="Quitar imagen">
                                  <i class="fas fa-times"></i>
                                </button>
                              </div>
                            </div>
                          </div>
                        </div>

                      </div>
                    </div>
                  </div>
                </div>
              </div>
            </main>
          </div>
        </div>
      </div>
    </Transition>

    <Transition name="fade-scale">
      <div v-if="modalInsercion.isOpen" class="fixed inset-0 z-[1100] bg-slate-900/60 backdrop-blur-sm flex items-center justify-center p-4">
        <div class="bg-white w-full max-w-md rounded-2xl shadow-2xl overflow-hidden border border-slate-200">
          <div class="bg-indigo-600 px-5 py-4 text-white flex justify-between items-center">
            <h3 class="font-black text-sm uppercase tracking-widest"><i class="fas fa-layer-group mr-2"></i> Inyectar Párrafo</h3>
            <button @click="modalInsercion.isOpen = false" class="text-indigo-200 hover:text-white"><i class="fas fa-times"></i></button>
          </div>
          <div class="p-5 space-y-4">

            <div class="bg-slate-50 p-3 rounded-lg border border-slate-200 mb-4">
              <span class="text-[9px] font-black text-indigo-500 uppercase tracking-widest block">{{ modalInsercion.segmentoMaestro?.nombreInterno || 'SIN CÓDIGO' }}</span>
              <span class="text-xs font-bold text-slate-700">{{ store.getI18nText(modalInsercion.segmentoMaestro?.titulo, store.cotizacion.idiomaEdicion) }}</span>
            </div>

            <label class="flex items-center gap-3 p-3 border rounded-xl cursor-pointer hover:bg-slate-50 transition-colors" :class="opcionInsercion === 'append' ? 'border-indigo-500 bg-indigo-50/50 shadow-sm' : 'border-slate-200'">
              <input type="radio" v-model="opcionInsercion" value="append" class="text-indigo-600 focus:ring-indigo-500">
              <div class="flex-1 text-sm font-bold text-slate-700">Añadir al final del documento</div>
            </label>

            <label class="flex items-center gap-3 p-3 border rounded-xl cursor-pointer hover:bg-slate-50 transition-colors" :class="opcionInsercion === 'insert' ? 'border-indigo-500 bg-indigo-50/50 shadow-sm' : 'border-slate-200'">
              <input type="radio" v-model="opcionInsercion" value="insert" class="text-indigo-600 focus:ring-indigo-500">
              <div class="flex-1 text-sm font-bold text-slate-700">Insertar en una posición (Desplaza abajo)</div>
            </label>

            <label class="flex items-center gap-3 p-3 border rounded-xl cursor-pointer hover:bg-slate-50 transition-colors" :class="opcionInsercion === 'replace' ? 'border-orange-500 bg-orange-50/50 shadow-sm' : 'border-slate-200'">
              <input type="radio" v-model="opcionInsercion" value="replace" class="text-orange-500 focus:ring-orange-500">
              <div class="flex-1 text-sm font-bold text-slate-700">Reemplazar un párrafo (Purga la logística)</div>
            </label>

            <Transition name="fade-scale">
              <div v-if="opcionInsercion !== 'append'" class="bg-slate-50 p-4 rounded-xl border border-slate-200 mt-2">
                <label class="block text-[10px] font-black text-slate-500 uppercase tracking-widest mb-2">
                  Selecciona el segmento objetivo:
                </label>
                <select v-model="targetSegmentoId" class="w-full bg-white border border-slate-300 text-slate-700 text-xs font-bold rounded-lg px-3 py-2 outline-none focus:ring-2 focus:ring-indigo-500 shadow-sm">
                  <option v-for="(seg, idx) in store.dataActiva?.cotsegmentos" :key="seg.id" :value="seg.id">
                    Posición #{{ idx + 1 }} - {{ store.getI18nText(seg.nombreSnapshot, store.cotizacion.idiomaEdicion) }}
                  </option>
                </select>
                <p v-if="opcionInsercion === 'replace'" class="text-[9px] font-bold text-orange-500 mt-2 flex items-center gap-1">
                  <i class="fas fa-exclamation-triangle"></i> ¡Cuidado! Los trenes y guías del Párrafo #{{ store.dataActiva?.cotsegmentos?.findIndex((s: any) => s.id === targetSegmentoId) + 1 }} serán reemplazados.
                </p>
              </div>
            </Transition>

          </div>
          <div class="bg-slate-100 px-5 py-3 border-t border-slate-200 flex justify-end gap-3">
            <button @click="modalInsercion.isOpen = false" class="px-4 py-2 text-xs font-bold text-slate-500 hover:text-slate-700 bg-white border border-slate-300 rounded-lg shadow-sm transition-colors">Cancelar</button>
            <button @click="confirmarInsercion" class="px-5 py-2 bg-indigo-600 hover:bg-indigo-700 text-white text-xs font-bold rounded-lg shadow-sm transition-colors flex items-center gap-2">
              <i class="fas fa-check"></i> Ejecutar
            </button>
          </div>
        </div>
      </div>
    </Transition>

    <Transition name="slide-up">
      <div v-if="isTotalsDrawerOpen" class="fixed inset-0 z-[1200] flex flex-col justify-end bg-slate-900/60 backdrop-blur-sm md:items-end md:justify-start" @click.self="isTotalsDrawerOpen = false">

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

                    <p v-if="clase.edadMin > 0 || clase.edadMax < 120" class="text-[10px] font-bold text-slate-500 mt-1">
                      <span v-if="clase.edadMin > 0 && clase.edadMax < 120">Rango: {{ clase.edadMin }} a {{ clase.edadMax }} años</span>
                      <span v-else-if="clase.edadMin > 0">A partir de {{ clase.edadMin }} años</span>
                      <span v-else>Hasta los {{ clase.edadMax }} años</span>
                    </p>
                    <p v-else class="text-[10px] font-bold text-slate-400 mt-1">Sin restricción de edad</p>

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

    <Transition name="fade-scale">
      <div v-if="modalNota.isOpen" class="fixed inset-0 z-[1300] bg-slate-900/60 backdrop-blur-sm flex items-center justify-center p-4" @click.self="modalNota.isOpen = false">
        <div class="bg-white w-full max-w-lg rounded-2xl shadow-2xl overflow-hidden border border-slate-200 flex flex-col max-h-[85vh]">
          <div :class="[getTipoNotaUI(modalNota.nota?.tipo).bg, getTipoNotaUI(modalNota.nota?.text)]" class="px-5 py-4 flex justify-between items-center border-b border-black/5 flex-shrink-0">
            <h3 class="font-black text-sm uppercase tracking-widest flex items-center gap-2">
              <i class="fas" :class="getTipoNotaUI(modalNota.nota?.tipo).icon"></i>
              {{ modalNota.nota?.tipo }}
            </h3>
            <button @click="modalNota.isOpen = false" class="hover:opacity-70 transition-opacity"><i class="fas fa-times"></i></button>
          </div>
          <div class="p-6 overflow-y-auto flex-1">
            <h4 class="text-lg font-black text-slate-800 mb-4 leading-tight">
              {{ store.getI18nText(modalNota.nota?.titulo, store.cotizacion.idiomaEdicion) || modalNota.nota?.nombreInterno }}
            </h4>
            <div class="prose prose-sm max-w-none text-slate-600 leading-relaxed"
                 v-html="store.getI18nText(modalNota.nota?.contenido, store.cotizacion.idiomaEdicion)">
            </div>
          </div>
          <div class="bg-slate-50 px-5 py-3 border-t border-slate-100 flex justify-end flex-shrink-0">
            <button @click="modalNota.isOpen = false" class="px-5 py-2 bg-slate-800 hover:bg-slate-900 text-white text-xs font-bold rounded-lg shadow-sm transition-colors">Cerrar</button>
          </div>
        </div>
      </div>
    </Transition>

  </Teleport>
</template>

<style scoped>
::-webkit-scrollbar { width: 6px; }
::-webkit-scrollbar-track { background: transparent; }
::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 10px; }
.fade-scale-enter-active, .fade-scale-leave-active { transition: all 0.3s ease; }
.fade-scale-enter-from, .fade-scale-leave-to { opacity: 0; transform: scale(0.95); }
.slide-up-enter-active, .slide-up-leave-active { transition: transform 0.3s cubic-bezier(0.16, 1, 0.3, 1); }
.slide-up-enter-from, .slide-up-leave-to { transform: translateY(100%); }

:deep(.dp__main) {
  font-family: inherit;
}
</style>

<style>
:root {
  --dp-border-radius: 0.5rem;
  --dp-primary-color: #0ea5e9;
  --dp-font-family: inherit;
  --dp-font-size: 0.75rem;
}
</style>