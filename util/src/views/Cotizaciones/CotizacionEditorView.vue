<script setup lang="ts">
import { ref, onMounted, computed, watch, onUnmounted } from 'vue';
import { useRoute, useRouter, onBeforeRouteLeave } from 'vue-router';
import { useCotizacionEditorStore } from '@/stores/cotizacion/cotizacionEditorStore';
import SearchableSelect from '@/components/SearchableSelect.vue';
import WysiwygEditor from '@/components/WysiwygEditor.vue';
import ResumenClasificacion from '@/components/cotizacion/ResumenClasificacion.vue';

// 🔥 IMPORTS DEL DATEPICKER Y MÁSCARAS
import { VueDatePicker } from '@vuepic/vue-datepicker';
import '@vuepic/vue-datepicker/dist/main.css';
import IMask from 'imask';
import {
  ESTADO_COTIZACION_CONFIG,
  getModoItemConfig,
  getEstadoComponenteConfig,
  getEstadoOperativoConfig,
  getProcedenciaUI,
  getTipoNotaUI,
  getRolTarifaUI, Servicio, TarifaSnapshot, formatRangoEdad,
  MODALIDAD_CONFIG, CATEGORIA_CONFIG, enumOptions
} from '@/types/cotizacionEditorModel';

// 1. Importa el estado y lógica compartida
import { isSessionExpired, renewSession } from '@/services/sessionAuth';

// 2. Variables para el formulario de login (idénticas a las del chat)
const loginUsername = ref('');
const loginPassword = ref('');
const isLoggingIn = ref(false);

// 3. Función de re-login
const handleSessionRenewal = async () => {
  isLoggingIn.value = true;
  try {
    await renewSession({
      _username: loginUsername.value,
      _password: loginPassword.value
    });
    // Si tienes una función para recargar datos, ejecútala aquí
    // await store.inicializarEditor(...)
  } finally {
    isLoggingIn.value = false;
  }
};

defineProps<{
  fileId?: string;
  cotizacionId?: string;
}>();

const isReporteOpen = ref(false);

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
    // El estándar moderno solo requiere esto para mostrar el diálogo genérico
    e.preventDefault();
  }
};

const cambiarIdiomaCliente = (event: Event) => {
  const target = event.target as HTMLSelectElement;
  if (store.cotizacion) {
    store.cotizacion.idiomaCliente = target.value;
    store.cotizacion.idiomaEdicion = 'es';
  }
};
const toggleSobreescribirTraduccion = () => {
  if (store.cotizacion) {
    store.cotizacion.sobreescribirTraduccion = !store.cotizacion.sobreescribirTraduccion;
  }
};

const actualizarResumen = (texto: string) => {
  if (store.cotizacion) {
    store.setI18nText(store.cotizacion.resumen, store.cotizacion.idiomaEdicion, texto);
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
    router.push('/cotizacion');
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
  // 1. Si el acordeón del Pool en móvil está abierto, lo cerramos primero
  if (store.isSegmentEditorOpen && activeAccordion.value === 'pool') {
    activeAccordion.value = 'parrafos';
    next(false); // Aborta la navegación y solo actualiza la UI
    return;
  }

  // 2. Si el modal del Constructor de Storytelling está abierto
  if (store.isSegmentEditorOpen) {
    store.cerrarEditorSegmentos();
    next(false);
    return;
  }

  // 3. Si el modal del Reporte Financiero está abierto
  if (isReporteOpen.value) {
    isReporteOpen.value = false;
    next(false);
    return;
  }

  // 4. Si estamos en niveles profundos del panel lateral (Servicio > Componente > Tarifa)
  if (store.historialNavegacion.length > 0) {
    store.retrocederNivel();
    next(false);
    return;
  }

  // 5. Si estamos en la raíz del panel lateral en un dispositivo móvil
  if (store.isMobileOpen && window.innerWidth < 768) {
    store.cerrarInspectorMobile();
    next(false);
    return;
  }

  // 6. Si no hay nada abierto, evaluamos si hay cambios sin guardar antes de salir
  if (isDirty.value) {
    const confirmacion = window.confirm('Tienes cambios sin guardar. ¿Estás seguro de que deseas salir y perder los cambios?');
    if (confirmacion) {
      next(); // Permite salir
    } else {
      next(false); // Se queda en la página
    }
  } else {
    next(); // Sale de la página normalmente
  }
});

const handleVolver = () => {
  const fileId = route.params.fileId || store.fileActual?.id;
  if (fileId) {
    router.push(`/cotizacion/${fileId}`);
  } else {
    router.push('/cotizacion');
  }
};

const handleGuardar = async () => {
  await store.guardarCotizacion();
  isDirty.value = false;
};

// ============================================================================
// 🔥 1. MÁSCARA ESTRICTA PARA FECHA Y HORA
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
// 🔥 2. MÁSCARA ESTRICTA SÓLO FECHA
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

  return [...store.idiomasDisponibles].sort(
      (a, b) => (b.prioridad ?? 0) - (a.prioridad ?? 0)
  );
});

const cottarifasOrdenadas = computed(() => {
  if (!store.dataActiva?.cottarifas) return [];
  return [...store.dataActiva.cottarifas].sort((a: any, b: any) => (a.grupoTarifa ?? Infinity) - (b.grupoTarifa ?? Infinity));
});

const calcularVentaTarifa = (tarifa: TarifaSnapshot): number => {
  const costoTotal = (parseFloat(String(tarifa.montoCosto)) || 0) * (tarifa.esGrupal ? 1 : (tarifa.cantidad || 1));
  const tieneOverride = tarifa.comisionOverrideSnapshot != null && tarifa.comisionOverrideSnapshot !== '';
  const comisionPct = tieneOverride
      ? parseFloat(String(tarifa.comisionOverrideSnapshot))
      : (parseFloat(String(store.cotizacion?.comision ?? '0')) || 0);
  return costoTotal * (1 + comisionPct / 100);
};

const opcionesServicios = computed(() => {
  return store.catalogos.servicios
      .map((s: Servicio) => ({
        value: store.extractIdStr(s.id || s['@id']),
        label: s.nombreInterno || (s as any).nombre || 'Servicio sin nombre'
      }))
      .sort((a, b) => a.label.localeCompare(b.label, 'es'));
});

const opcionesComponentes = computed(() => {
  return store.catalogos.componentes
      .map(c => ({
        value: store.extractIdStr(c),
        label: c.nombre || (c as any).nombre || 'Insumo sin nombre'
      }))
      .sort((a, b) => a.label.localeCompare(b.label, 'es'));
});

const opcionesTarifas = computed(() => {
  return store.catalogos.tarifas
      .map(t => ({
        value: store.extractIdStr(t),
        label: store.getTarifaLabel(t, store.cotizacion?.idiomaEdicion || 'es')
      }))
      .sort((a, b) => a.label.localeCompare(b.label, 'es'));
});

const opcionesProveedores = computed(() => {
  return store.catalogos.proveedores
      .map(p => ({
        value: store.extractIdStr(p),
        label: p.nombreComercial || 'Sin nombre'
      }))
      .sort((a, b) => a.label.localeCompare(b.label, 'es'));
});

const handleNombreProveedorInput = (event: Event) => {
  const target = event.target as HTMLInputElement;
  const val = target.value;

  // Actualizamos el modelo
  if (store.dataActiva) {
    (store.dataActiva as any).proveedorNombreSnapshot = val;
  }

  // Ejecutamos la lógica de limpieza
  if (!val.trim() && !store.dataActiva?.proveedorMaestroId) {
    store.limpiarServicioProveedor();
  }
};

const opcionesPlantillas = computed(() => {
  return store.catalogos.plantillasItinerario
      .map(p => ({
        value: store.extractIdStr(p),
        label: p.nombreInterno || (p as any).nombre || 'Plantilla sin nombre'
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

  if (c && c.nombre !== 'Sincronizando...') return c.nombre || (c as any).nombre || 'Insumo Genérico';

  if (c && c.nombre === 'Sincronizando...') {
    const snapshotName = store.getI18nText(comp.nombreSnapshot as any, store.cotizacion?.idiomaEdicion || 'es');
    return snapshotName ? snapshotName : 'Sincronizando...';
  }

  store.fetchComponenteMaestroSilencioso(targetId as string);

  const snapshotName = store.getI18nText(comp.nombreSnapshot as any, store.cotizacion?.idiomaEdicion || 'es');
  return snapshotName ? snapshotName : 'Sincronizando...';
};

const filtroSegmentos = ref('');
// ESTADO DEL ACORDEÓN (Móvil) Y EDITORES
const activeAccordion = ref<'pool' | 'parrafos'>('parrafos');
const expandirEditores = ref(false);

const isActualizandoTextos = ref(false);

const handleActualizarTextos = async () => {
  isActualizandoTextos.value = true;
  await store.actualizarTextosSegmentos();
  isActualizandoTextos.value = false;
};

watch(() => store.isSegmentEditorOpen, (open) => {
  if (open) {
    activeAccordion.value = store.dataActiva?.cotsegmentos?.length ? 'parrafos' : 'pool';
  }
});

const poolFiltrado = computed(() => {
  if (!filtroSegmentos.value) return store.catalogos.poolSegmentos;
  const q = filtroSegmentos.value.toLowerCase();
  return store.catalogos.poolSegmentos.filter((seg: any) => {
    const code = (seg.nombreInterno || '').toLowerCase();
    const title = store.getI18nText(seg.titulo as any, store.cotizacion?.idiomaEdicion || 'es').toLowerCase();
    return code.includes(q) || title.includes(q);
  });
});

// ============================================================================
// 🔥 ORDENAMIENTO DE SEGMENTOS AGRUPADOS (Vista Storytelling)
// ============================================================================
const segmentosOrdenadosVisualmente = computed(() => {
  if (!store.dataActiva?.cotsegmentos) return [];
  return [...store.dataActiva.cotsegmentos].sort((a, b) => {
    if (a.dia !== b.dia) return a.dia - b.dia;
    return (a.orden || 0) - (b.orden || 0);
  });
});

const dragSegId = ref<string | null>(null);
const dragOverSegId = ref<string | null>(null);
let segLongPressTimer: ReturnType<typeof setTimeout> | null = null;
let segPointerIsDown = false;
let segDragActivated = false;
let segPointerStartY = 0;

const reordenarSegmentosVisual = (fromId: string, toId: string) => {
  if (!store.dataActiva?.id) return;
  store.reordenarSegmentos(store.dataActiva.id, fromId, toId);
};

const onSegmentPointerDown = (e: PointerEvent, seg: any) => {
  segPointerIsDown = true;
  segDragActivated = false;
  segPointerStartY = e.clientY;
  (e.currentTarget as HTMLElement).setPointerCapture?.(e.pointerId);

  if (e.pointerType === 'touch') {
    segLongPressTimer = setTimeout(() => {
      if (segPointerIsDown) {
        segDragActivated = true;
        dragSegId.value = seg.id;
        if (navigator.vibrate) navigator.vibrate(15);
      }
    }, LONG_PRESS_MS);
  } else {
    segDragActivated = true;
    dragSegId.value = seg.id;
  }
};

const onSegmentPointerMove = (e: PointerEvent) => {
  if (!segPointerIsDown) return;

  if (!segDragActivated) {
    if (Math.abs(e.clientY - segPointerStartY) > MOVE_CANCEL_THRESHOLD && segLongPressTimer) {
      clearTimeout(segLongPressTimer);
      segLongPressTimer = null;
    }
    return;
  }

  e.preventDefault();
  const el = document.elementFromPoint(e.clientX, e.clientY)?.closest('[data-segment-id]') as HTMLElement | null;
  if (el && dragSegId.value) {
    const overId = el.getAttribute('data-segment-id');
    if (overId && overId !== dragSegId.value) {
      dragOverSegId.value = overId;
      reordenarSegmentosVisual(dragSegId.value, overId); // <--- Actualizado aquí
    }
  }
};

const handleAplicarPlantilla = async () => {
  if(plantillaSeleccionada.value && puedeAplicarPlantilla.value) {
    await store.aplicarPlantilla(plantillaSeleccionada.value);
    activeAccordion.value = 'parrafos'; // Cambia al acordeón de párrafos
  }
};

const onSegmentPointerUp = () => {
  segPointerIsDown = false;
  segDragActivated = false;
  dragSegId.value = null;
  dragOverSegId.value = null;
  if (segLongPressTimer) { clearTimeout(segLongPressTimer); segLongPressTimer = null; }
};


// ============================================================================
// 🔥 REORDENAMIENTO DE ITEMS (Inclusiones / Upsells)
// ============================================================================
const dragItemId = ref<string | null>(null);
const dragOverItemId = ref<string | null>(null);
let longPressTimer: ReturnType<typeof setTimeout> | null = null;
let pointerIsDown = false;
let dragActivated = false;
let pointerStartY = 0;
const LONG_PRESS_MS = 320;
const MOVE_CANCEL_THRESHOLD = 10;

const tooltipDetalleActivo = ref<string | null>(null);
let detalleLongPressTimer: ReturnType<typeof setTimeout> | null = null;

const onDetallePointerDown = (e: PointerEvent, bloqueId: string) => {
  if (e.pointerType !== 'touch') return;
  detalleLongPressTimer = setTimeout(() => {
    tooltipDetalleActivo.value = bloqueId;
    if (navigator.vibrate) navigator.vibrate(10);
  }, 320);
};
const onDetallePointerUp = () => {
  if (detalleLongPressTimer) { clearTimeout(detalleLongPressTimer); detalleLongPressTimer = null; }
  setTimeout(() => { tooltipDetalleActivo.value = null; }, 1600);
};

const reordenarSnapshotItems = (fromId: string, toId: string) => {
  if (!store.dataActiva?.snapshotItems || fromId === toId) return;
  const items = store.dataActiva.snapshotItems;
  const fromIdx = items.findIndex((i: any) => i.id === fromId);
  const toIdx = items.findIndex((i: any) => i.id === toId);
  if (fromIdx === -1 || toIdx === -1) return;
  const [moved] = items.splice(fromIdx, 1);
  items.splice(toIdx, 0, moved);
};

const onItemPointerDown = (e: PointerEvent, item: any) => {
  pointerIsDown = true;
  dragActivated = false;
  pointerStartY = e.clientY;
  (e.currentTarget as HTMLElement).setPointerCapture?.(e.pointerId);

  if (e.pointerType === 'touch') {
    longPressTimer = setTimeout(() => {
      if (pointerIsDown) {
        dragActivated = true;
        dragItemId.value = item.id;
        if (navigator.vibrate) navigator.vibrate(15);
      }
    }, LONG_PRESS_MS);
  } else {
    // 🔥 Mouse: click y arrastre inmediato
    dragActivated = true;
    dragItemId.value = item.id;
  }
};

const onItemPointerMove = (e: PointerEvent) => {
  if (!pointerIsDown) return;

  if (!dragActivated) {
    if (Math.abs(e.clientY - pointerStartY) > MOVE_CANCEL_THRESHOLD && longPressTimer) {
      clearTimeout(longPressTimer);
      longPressTimer = null;
    }
    return;
  }

  e.preventDefault();
  const el = document.elementFromPoint(e.clientX, e.clientY)?.closest('[data-item-id]') as HTMLElement | null;
  if (el && dragItemId.value) {
    const overId = el.getAttribute('data-item-id');
    if (overId && overId !== dragItemId.value) {
      dragOverItemId.value = overId;
      reordenarSnapshotItems(dragItemId.value, overId);
    }
  }
};

const onItemPointerUp = () => {
  pointerIsDown = false;
  dragActivated = false;
  dragItemId.value = null;
  dragOverItemId.value = null;
  if (longPressTimer) { clearTimeout(longPressTimer); longPressTimer = null; }
};

const modalInsercion = ref({ isOpen: false, segmentoMaestro: null as any });
const modalNota = ref({ isOpen: false, nota: null as any });
const opcionInsercion = ref<'append'|'insert'|'replace'>('append');
const targetSegmentoId = ref<string>('');
const isTotalsDrawerOpen = ref(false);

const abrirModalNota = (nota: any) => {
  modalNota.value = { isOpen: true, nota };
};

const agruparNotasPorTipo = (notas: any[]): Map<string, any[]> => {
  const mapa = new Map<string, any[]>();
  if (!notas || !Array.isArray(notas)) return mapa;
  notas.forEach((nota) => {
    const tipo = nota.tipo || 'OTROS';
    if (!mapa.has(tipo)) mapa.set(tipo, []);
    mapa.get(tipo)!.push(nota);
  });
  return mapa;
};

const prepararInsercion = async (seg: any) => {
  if (!store.dataActiva?.cotsegmentos?.length) {
    await store.procesarInsercionSegmento(seg, plantillaSeleccionada.value, 'append');
    activeAccordion.value = 'parrafos'; // Cambia al acordeón de párrafos
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
  activeAccordion.value = 'parrafos'; // Cambia al acordeón de párrafos
};

const isProveedorOpen = ref(false);

const finPickerKey = ref(0);

const onInicioChange = (val: string | null) => {
  store.actualizarInicioManteniendoRango(val || '');
  finPickerKey.value++;
};

const detallesOperativosAbierto = ref(true);

const tooltipPoolActivo = ref<string | null>(null);
let poolLongPressTimer: ReturnType<typeof setTimeout> | null = null;
const onPoolPointerDown = (e: PointerEvent, id: string) => {
  if (e.pointerType !== 'touch') return;
  poolLongPressTimer = setTimeout(() => {
    tooltipPoolActivo.value = id;
    if (navigator.vibrate) navigator.vibrate(10);
  }, 320);
};
const onPoolPointerUp = () => {
  if (poolLongPressTimer) { clearTimeout(poolLongPressTimer); poolLongPressTimer = null; }
  setTimeout(() => { tooltipPoolActivo.value = null; }, 1600);
};

const puedeAplicarPlantilla = computed(() => !store.dataActiva?.cotsegmentos?.length);

watch(isProveedorOpen, (newVal) => {
  if (newVal && store.dataActiva?.proveedorMaestroId && store.catalogos.proveedorServicios.length === 0) {
    store.fetchProveedorServiciosDeProveedor(store.dataActiva.proveedorMaestroId);
  }
});

const normalizarUrl = (raw: string): string => {
  const val = raw.trim();
  if (!val) return '';
  if (!/^https?:\/\//i.test(val)) return `https://${val}`;
  return val;
};

const esUrlValida = (raw: string | null | undefined): boolean => {
  if (!raw) return true; // vacío no es error, el campo es opcional
  try {
    new URL(raw);
    return true;
  } catch {
    return false;
  }
};

const onUrlBlur = (campo: 'proveedorUrlSnapshot' | 'proveedorServicioUrlSnapshot') => {
  const valor = store.dataActiva?.[campo];
  if (valor) store.dataActiva[campo] = normalizarUrl(valor);
};

</script>

<template>
  <div class="h-screen bg-slate-50 flex flex-col font-sans overflow-hidden relative">

    <header class="bg-slate-900 text-white px-4 md:px-6 py-3 flex items-center justify-between z-20 shadow-md shrink-0">
      <div class="flex items-center gap-3">
        <button @click="handleVolver" class="w-8 md:w-10 h-10 flex items-center justify-center bg-slate-800 hover:bg-slate-700 rounded-full transition-colors">
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

      <div class="flex gap-2 md:gap-3 items-center" v-if="store.cotizacion">
        <div class="flex items-center bg-slate-800 rounded-lg p-1 gap-1">
          <button @click="store.cotizacion.idiomaEdicion = 'es'"
                  :class="store.cotizacion.idiomaEdicion === 'es' ? 'bg-[#376875] text-white shadow' : 'text-slate-400 hover:text-white'"
                  class="px-2 md:px-3 py-1 rounded text-[9px] md:text-[10px] font-black tracking-widest transition-all whitespace-nowrap">
            ES<span class="hidden md:inline"> (INTERNO)</span>
          </button>
          <button v-if="store.cotizacion.idiomaCliente && store.cotizacion.idiomaCliente !== 'es'"
                  @click="store.cotizacion.idiomaEdicion = store.cotizacion.idiomaCliente"
                  :class="store.cotizacion.idiomaEdicion === store.cotizacion.idiomaCliente ? 'bg-[#E07845] text-white shadow' : 'text-slate-400 hover:text-white'"
                  class="px-2 md:px-3 py-1 rounded text-[9px] md:text-[10px] font-black tracking-widest uppercase transition-all whitespace-nowrap">
            {{ store.cotizacion.idiomaCliente }}<span class="hidden md:inline"> (CLIENTE)</span>
          </button>
        </div>
        <button @click="store.abrirNivel('resumen')" class="md:hidden px-3 py-2 bg-slate-800 text-slate-300 rounded-lg text-[10px] font-bold shadow-sm border border-slate-700 whitespace-nowrap">Totales</button>
        <button @click="handleGuardar"
                :class="store.isMobileOpen ? 'hidden md:flex' : 'flex'"
                class="items-center gap-2 px-4 md:px-5 py-2 bg-[#E07845] hover:bg-[#c96636] rounded-lg text-xs font-bold transition-colors">
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

                      <span v-if="store.getI18nText(servicio.itinerarioNombreSnapshot as any, 'es') !== 'Sin plantilla'">
                        {{ store.getI18nText(servicio.itinerarioNombreSnapshot as any, store.cotizacion.idiomaEdicion) }}
                      </span>

                      <ul v-else-if="servicio.cotsegmentos && servicio.cotsegmentos.length > 0" class="flex flex-col gap-0 leading-[1.15] mt-1">
                        <li v-for="seg in [...servicio.cotsegmentos].sort((a, b) => (a.orden || 0) - (b.orden || 0))" :key="seg.id" class="text-[16px] text-slate-800 tracking-tight">
                          <span v-if="servicio.cotsegmentos.length > 1">- </span>{{ store.getI18nText(seg.nombreSnapshot as any, store.cotizacion.idiomaEdicion) }}
                        </li>
                      </ul>

                      <span v-else>
                        {{ store.getI18nText(servicio.nombreSnapshot as any, store.cotizacion.idiomaEdicion) }}
                      </span>
                    </div>

                    <p class="text-[11px] font-bold text-slate-500 mt-1.5" v-if="store.getI18nText(servicio.itinerarioNombreSnapshot as any, 'es') !== 'Sin plantilla'">
                      <i class="fas fa-map-signs mr-1"></i> Plantilla Aplicada
                    </p>
                    <p class="text-[11px] font-bold text-slate-500 mt-1.5" v-else-if="servicio.cotsegmentos && servicio.cotsegmentos.length > 0">
                      <i class="fas fa-layer-group mr-1"></i> Storytelling a medida ({{ servicio.cotsegmentos.length }} párrafos)
                    </p>
                    <p class="text-[11px] font-bold text-slate-500 mt-1.5" v-else>
                      <i class="fas fa-pen-nib mr-1"></i> Sin Storytelling
                    </p>

                    <div class="flex flex-wrap items-center gap-2 mt-4">
                        <span class="text-[9px] font-black bg-teal-600 text-white px-2 py-1.5 rounded uppercase tracking-widest shadow-sm">
                            <i class="far fa-clock mr-1 text-teal-200"></i> Programación
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
                        <i class="fas fa-feather-alt mr-1 text-teal-500"></i> STORYTELLING ACTIVO
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
            'bg-white flex flex-col transition-transform duration-300 ease-in-out border-slate-200 shrink-0',
            'fixed inset-0 z-50 md:z-10 w-full',
            store.isMobileOpen ? 'translate-y-0' : 'translate-y-full',
            'md:relative md:w-105 md:border-l md:translate-y-0 md:transform-none',
            store.inspectorActivo === 'tarifa' ? 'bg-white text-slate-800' : 'bg-white text-slate-800'
        ]">

        <div v-if="store.inspectorActivo === 'resumen'" class="flex-1 flex flex-col min-h-0">
          <div class="px-6 py-5 border-b border-slate-100 flex justify-between items-center bg-slate-50">
            <h2 class="text-xs font-black text-slate-500 uppercase tracking-widest">Cabecera de Cotización</h2>
            <button @click="store.cerrarInspectorMobile" class="md:hidden text-slate-400 hover:text-red-500"><i class="fas fa-times text-lg"></i></button>
          </div>
          <div class="p-6 flex-1 overflow-y-auto space-y-6 pb-32">

            <div class="bg-teal-50 border border-teal-100 rounded-xl p-4 flex items-center justify-between shadow-sm">
              <div>
                <h3 class="text-[10px] font-black text-teal-700 uppercase tracking-widest"><i class="fas fa-user-secret mr-1"></i> Anonimato Logístico</h3>
                <p class="text-[9px] text-teal-500 mt-1 font-medium leading-tight pr-4">Ocultar todos los proveedores y servicios logísticos al generar vistas públicas o vouchers.</p>
              </div>
              <button @click="store.cotizacion.proveedorOculto = !store.cotizacion.proveedorOculto"
                      :class="[
                           'relative inline-flex h-6 w-11 items-center rounded-full transition-colors focus:outline-none shrink-0',
                           store.cotizacion.proveedorOculto ? 'bg-teal-600' : 'bg-slate-300'
                       ]">
                 <span :class="store.cotizacion.proveedorOculto ? 'translate-x-6' : 'translate-x-1'"
                       class="inline-block h-4 w-4 transform rounded-full bg-white transition-transform" />
              </button>
            </div>

            <div class="bg-[#376875] text-white rounded-3xl p-6 shadow-xl relative overflow-hidden">
              <i class="fas fa-chart-pie absolute -right-6 -bottom-6 text-8xl opacity-10"></i>
              <div class="relative z-10">
                <p class="text-[10px] font-bold text-emerald-400 uppercase tracking-widest mb-1">Venta Total Sugerida</p>
                <p class="text-4xl font-black tracking-tight">{{ formatMoneda(store.resumenFinanciero?.totalVentaBruta, store.cotizacion?.monedaGlobal) }}</p>
                <div class="mt-4 pt-4 border-t border-slate-800/30 flex justify-between items-end">
                  <div>
                    <p class="text-[9px] text-slate-300 uppercase font-bold">Costo Neto</p>
                    <p class="text-lg font-bold text-white">{{ formatMoneda(store.resumenFinanciero?.totalCostoNeto, store.cotizacion?.monedaGlobal) }}</p>
                  </div>
                  <div class="text-right">
                    <p class="text-[9px] text-emerald-400 uppercase font-bold">Margen Bruto</p>
                    <p class="text-lg font-bold text-emerald-300">+{{ formatMoneda(store.resumenFinanciero?.ganancia, store.cotizacion?.monedaGlobal) }}</p>
                  </div>
                </div>
              </div>
            </div>
            <button @click="isReporteOpen = true"
                    class="w-full py-3 bg-slate-800 hover:bg-slate-900 text-white rounded-xl text-xs font-black uppercase tracking-widest shadow-sm">
              <i class="fas fa-file-invoice-dollar mr-2"></i> Reporte financiero completo
            </button>

            <div class="space-y-3">
              <h3 class="text-[10px] font-black text-slate-400 uppercase tracking-widest ml-1"><i class="fas fa-users mr-1"></i> Análisis por Perfil de Pasajero</h3>

              <div v-for="clase in store.resumenFinanciero?.clasesPasajeros" :key="clase.tipo"
                   class="bg-white border border-slate-200 rounded-2xl p-4 shadow-sm group hover:border-teal-300 transition-all"
                   :class="clase.tipo.includes('anomalo') ? 'border-red-300' : ''">
                <div class="flex justify-between items-start mb-3">
                  <div>
                    <span :class="clase.tipo.includes('anomalo') ? 'bg-red-100 text-red-700' : 'bg-teal-100 text-teal-700'" class="px-2 py-0.5 rounded text-[10px] font-black uppercase">
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
                    <p class="text-sm font-black text-slate-800">{{ formatMoneda(clase.resumen.ventaDolares / (clase.cantidad || 1), store.cotizacion?.monedaGlobal) }}</p>
                  </div>
                </div>

                <div class="grid grid-cols-2 gap-2 mt-2 pt-2 border-t border-slate-50">
                  <div class="bg-slate-50 p-2 rounded-lg text-center">
                    <p class="text-[8px] text-slate-400 font-bold uppercase">Costo Total</p>
                    <p class="text-[11px] font-black text-slate-600">{{ formatMoneda(clase.resumen.montoDolares, store.cotizacion?.monedaGlobal) }}</p>
                  </div>
                  <div class="bg-emerald-50 p-2 rounded-lg text-center">
                    <p class="text-[8px] text-emerald-600 font-bold uppercase">Utilidad</p>
                    <p class="text-[11px] font-black text-emerald-700">{{ formatMoneda(clase.resumen.gananciaDolares, store.cotizacion?.monedaGlobal) }}</p>
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
                    <option v-for="(cfg, valor) in ESTADO_COTIZACION_CONFIG" :key="valor" :value="valor">
                      {{ cfg.label }}
                    </option>
                  </select>
                </div>
                <div>
                  <span class="block text-xs font-bold text-slate-500 uppercase mb-1">Idioma</span>
                  <select
                      :value="store.cotizacion.idiomaCliente"
                      @change="cambiarIdiomaCliente"
                      class="w-full font-black text-slate-800 bg-white px-3 py-2 rounded-lg border border-slate-200 outline-none focus:ring-2 focus:ring-[#376875] text-sm appearance-none shadow-sm"
                  >
                    <option v-for="lang in idiomasOrdenados" :key="lang.id" :value="lang.id">
                      {{ lang.nombre }}
                    </option>
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
                <button @click="toggleSobreescribirTraduccion"
                        :class="store.cotizacion?.sobreescribirTraduccion ? 'bg-orange-100 text-orange-600 border-orange-300' : 'bg-slate-50 text-slate-400 border-slate-200 hover:bg-slate-100'"
                        class="p-1 px-2 border rounded-lg transition-colors shadow-sm text-[10px] font-bold flex items-center gap-1"
                        title="Traducir automáticamente a otros idiomas al guardar">
                  <i class="fas fa-language"></i>
                  <span v-if="store.cotizacion?.sobreescribirTraduccion">Auto-Traducir ACTIVO</span>
                </button>
              </div>
              <WysiwygEditor
                  :model-value="store.getI18nText(store.cotizacion?.resumen as any, store.cotizacion?.idiomaEdicion || 'es')"
                  @update:model-value="actualizarResumen"
              />
            </div>
          </div>
        </div>

        <div v-else-if="store.inspectorActivo === 'servicio'" class="flex-1 flex flex-col min-h-0">
          <div class="px-5 py-1 border-b border-emerald-100 flex items-center gap-3 bg-emerald-50 shrink-0">
            <button @click="store.retrocederNivel" class="w-8 h-8 rounded-full hover:bg-emerald-100 text-slate-500 flex items-center justify-center transition-colors shrink-0"><i class="fas fa-arrow-left"></i></button>

            <div class="flex-1 min-w-0">
              <p class="text-[9px] font-black text-[#E07845] uppercase tracking-widest truncate flex items-center gap-1.5">
                <span class="w-2 h-2 rounded-full bg-emerald-400 shrink-0"></span>
                Edición de Servicio
              </p>
              <h2 class="text-sm font-black truncate">
                {{ store.getI18nText(store.dataActiva?.nombrePublicoSnapshot as any, store.cotizacion.idiomaEdicion) || store.getI18nText(store.dataActiva?.nombreSnapshot as any, store.cotizacion.idiomaEdicion) }}
              </h2>
              <p v-if="store.serviciosOrdenados.length > 1" class="text-[11px] font-bold text-emerald-600/70 mt-0.5">
                Servicio {{ store.serviciosOrdenados.findIndex(s => s.id === store.dataActiva.id) + 1 }} de {{ store.serviciosOrdenados.length }}
              </p>
            </div>

            <div v-if="store.serviciosOrdenados.length > 1" class="flex flex-col gap-1 shrink-0 self-center">
              <button @click="store.irAServicioAdyacente(-1)"
                      :disabled="store.serviciosOrdenados.findIndex(s => s.id === store.dataActiva.id) === 0"
                      class="w-9 h-9 rounded-lg bg-white border border-emerald-200 text-emerald-600 flex items-center justify-center shadow-sm disabled:opacity-30 disabled:cursor-not-allowed active:scale-95 transition-all">
                <i class="fas fa-chevron-up text-xs"></i>
              </button>
              <button @click="store.irAServicioAdyacente(1)"
                      :disabled="store.serviciosOrdenados.findIndex(s => s.id === store.dataActiva.id) === store.serviciosOrdenados.length - 1"
                      class="w-9 h-9 rounded-lg bg-white border border-emerald-200 text-emerald-600 flex items-center justify-center shadow-sm disabled:opacity-30 disabled:cursor-not-allowed active:scale-95 transition-all">
                <i class="fas fa-chevron-down text-xs"></i>
              </button>
            </div>
          </div>
          <div class="p-5 flex-1 overflow-y-auto space-y-6 pb-28">
            <div class="bg-slate-50 border border-slate-200 p-4 rounded-xl space-y-4">
              <div>
                <label class="block text-[10px] font-black text-[#E07845] uppercase tracking-widest mb-2"><i class="fas fa-book mr-1"></i> Catálogo Maestro</label>

                <div v-if="store.dataActiva.servicioMaestroId && store.dataActiva.cotcomponentes?.length > 0"
                     class="w-full bg-slate-100 border border-slate-200 text-slate-500 rounded-lg px-3 py-2.5 text-sm font-bold flex justify-between items-center cursor-not-allowed shadow-inner">
                  <span>{{ store.getI18nText(store.dataActiva.nombreSnapshot as any, store.cotizacion.idiomaEdicion) || 'Servicio Bloqueado' }}</span>
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
                  <input :value="store.getI18nText(store.dataActiva.nombrePublicoSnapshot as any, store.cotizacion?.idiomaEdicion || 'es')"
                         @input="e => { if(store.cotizacion) store.setI18nText(store.dataActiva.nombrePublicoSnapshot, store.cotizacion.idiomaEdicion, (e.target as HTMLInputElement).value) }"
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

            <div class="bg-teal-50 border border-teal-100 rounded-xl p-4">
              <div class="flex items-start justify-between gap-2 mb-2">
                <div>
                  <h3 class="text-[10px] font-black text-teal-700 uppercase tracking-widest"><i class="fas fa-align-left mr-1"></i> Storytelling</h3>
                  <p class="text-[10px] text-teal-500 mt-1 font-medium">{{ store.getI18nText(store.dataActiva.itinerarioNombreSnapshot as any, store.cotizacion.idiomaEdicion) }}</p>
                </div>
                <button @click="store.dataActiva.servicioMaestroId && store.abrirEditorSegmentos()"
                        :disabled="!store.dataActiva.servicioMaestroId"
                        :class="!store.dataActiva.servicioMaestroId ? 'bg-slate-300 text-slate-500 cursor-not-allowed shadow-none' : 'bg-teal-600 hover:bg-teal-700 text-white'"
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
                     class="bg-white border-2 rounded-xl p-4 shadow-sm cursor-pointer relative group overflow-hidden transition-all flex flex-col min-h-35"
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
                      <span class="flex items-center gap-1.5">
                        <i v-if="store.isComponenteConAlerta(comp)" class="fas fa-exclamation-triangle text-red-500" title="Tarifas no cuadran"></i>
                        {{ getNombreMaestroRef(comp) }}
                      </span>
                      <span v-if="!store.requiereHoraExacta(store.getTipoComponente(comp.componenteMaestroId))" class="text-[9px] font-black text-slate-400 uppercase tracking-widest mt-1">
                         <i class="fas fa-infinity text-[8px] mr-0.5"></i> Horario Libre / Final del día
                      </span>
                    </h4>
                    <div class="flex flex-col items-end gap-1 shrink-0">
                      <span class="text-[10px] font-black px-2 py-1 rounded border shadow-sm whitespace-nowrap flex items-center gap-1"
                            :class="[getModoItemConfig(comp.modo).bg, getModoItemConfig(comp.modo).text, getModoItemConfig(comp.modo).border]">
                            <i class="fas text-[9px]" :class="getModoItemConfig(comp.modo).icon"></i>
                            {{ getModoItemConfig(comp.modo).label.toUpperCase() }}
                      </span>
                      <span class="text-[9px] font-black px-2 py-0.5 rounded border shadow-sm whitespace-nowrap flex items-center gap-1"
                            :class="[getEstadoComponenteConfig(comp.estado).bg, getEstadoComponenteConfig(comp.estado).text, getEstadoComponenteConfig(comp.estado).border]">
                        <i class="fas text-[8px]" :class="getEstadoComponenteConfig(comp.estado).icon"></i>
                        {{ getEstadoComponenteConfig(comp.estado).label.toUpperCase() }}
                      </span>
                    </div>
                  </div>

                  <div class="flex flex-col gap-1.5 mb-3">
                    <span class="bg-sky-50 border border-sky-100 text-sky-800 px-2.5 py-1.5 rounded-lg text-[10px] font-black shadow-sm flex items-center gap-2 w-max">
                      <i class="far fa-calendar-alt text-sky-500"></i>
                      {{ store.requiereHoraExacta(store.getTipoComponente(comp.componenteMaestroId)) ? 'INICIO: ' + formatDateTimeFromISO(comp.fechaHoraInicio) : 'FECHA: ' + formatDateOnlyFromISO(comp.fechaHoraInicio) }}
                    </span>

                    <div v-if="comp.cantidad && comp.cantidad !== 1"
                         class="flex items-center gap-2 pl-4">
                      <div class="w-px h-3 bg-slate-300"></div>
                      <span class="text-[9px] font-black text-orange-600 bg-orange-50 border border-orange-200 px-2 py-0.5 rounded-full flex items-center gap-1 shadow-sm">
                        <i class="fas fa-moon text-[8px]"></i> {{ comp.cantidad }} noches
                      </span>
                    </div>

                    <span v-if="store.requiereHoraExacta(store.getTipoComponente(comp.componenteMaestroId)) || store.calcularPernoctes(comp.fechaHoraInicio, comp.fechaHoraFin) > 1" class="bg-slate-100 border border-slate-200 text-slate-700 px-2.5 py-1.5 rounded-lg text-[10px] font-black shadow-sm flex items-center gap-2 w-max">
                      <i class="far fa-flag text-slate-400"></i>
                      {{ store.requiereHoraExacta(store.getTipoComponente(comp.componenteMaestroId)) ? 'FIN: ' + formatDateTimeFromISO(comp.fechaHoraFin) : 'HASTA: ' + formatDateOnlyFromISO(comp.fechaHoraFin) }}
                    </span>

                    <span v-if="store.isComponenteBloqueado(comp)" class="mt-1 text-[9px] font-bold text-teal-500 flex items-center gap-1">
                      <i class="fas fa-link"></i> Insumo Autogenerado (Vinculado)
                    </span>
                  </div>

                  <div v-if="comp.detallesOperativos?.length" class="flex flex-wrap gap-1.5 mb-3">
                    <div v-for="bloque in comp.detallesOperativos" :key="bloque.id"
                         class="relative"
                         @click.stop
                         @mouseenter="tooltipDetalleActivo = bloque.id"
                         @mouseleave="tooltipDetalleActivo = null"
                         @pointerdown.stop="onDetallePointerDown($event, bloque.id)"
                         @pointerup.stop="onDetallePointerUp"
                         @pointercancel.stop="onDetallePointerUp">
    <span class="text-[9px] font-black px-2 py-1 rounded-lg border shadow-sm flex items-center gap-1 cursor-help select-none"
          :class="bloque.tipo === 'operativa' ? 'bg-slate-100 text-slate-600 border-slate-200' : 'bg-sky-50 text-sky-700 border-sky-100'">
      <i class="fas" :class="bloque.tipo === 'operativa' ? 'fa-cogs' : 'fa-info-circle'"></i>
      {{ bloque.tipo === 'operativa' ? 'Operativa' : 'Detalle' }}
    </span>
                      <div v-if="tooltipDetalleActivo === bloque.id"
                           class="absolute z-30 bottom-full left-0 mb-2 w-52 bg-slate-900 text-white text-[10px] font-medium p-2.5 rounded-lg shadow-xl leading-snug">
                        {{ store.getI18nText(bloque.detalle as any, store.cotizacion.idiomaEdicion) || 'Sin contenido' }}
                      </div>
                    </div>
                  </div>

                  <div v-if="comp.cottarifas?.length" class="mt-auto pt-3 border-t border-slate-100 grid grid-cols-1 gap-2">
                    <div v-for="tarifa in comp.cottarifas" :key="tarifa.id"
                         class="flex items-center justify-between bg-slate-50 hover:bg-orange-50 p-2 rounded-lg border border-slate-200 transition-colors">
                      <div class="flex flex-col min-w-0 pr-2">
                        <!-- 🔥 CAMBIO: Renderizar el nombre interno o el título público -->
                        <span class="text-[10px] font-black text-slate-700 uppercase truncate leading-none mb-1">
                          {{ tarifa.nombreInternoSnapshot || store.getI18nText(tarifa.tituloSnapshot as any, store.cotizacion.idiomaEdicion) || 'Tarifa Manual' }}
                        </span>

                        <span class="text-[9px] font-bold text-slate-400 flex items-center gap-1 leading-none">
                          <i :class="tarifa.esGrupal ? 'fas fa-users text-orange-400' : 'fas fa-user text-sky-400'"></i>
                          {{ tarifa.esGrupal ? '1 GRUPO' : `${tarifa.cantidad} Pax` }}
                        </span>
                      </div>
                      <div class="text-right shrink-0">
                        <span class="text-[11px] font-black" :class="comp.modo === 'no_incluido' ? 'text-slate-400 line-through' : 'text-orange-600'">
                          {{ formatMoneda(tarifa.montoCosto * (tarifa.esGrupal ? 1 : tarifa.cantidad), tarifa.moneda) }}
                        </span>
                      </div>
                    </div>
                  </div>
                  <div v-else class="mt-auto pt-3 border-t border-slate-100 text-center bg-slate-50 rounded-lg border border-dashed p-2">
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
          <div class="px-5 py-2 border-b border-sky-200 flex items-center gap-3 bg-sky-600 text-white shrink-0">
            <button @click="store.retrocederNivel" class="w-8 h-8 rounded-full hover:bg-sky-500 flex items-center justify-center transition-colors shrink-0"><i class="fas fa-arrow-left"></i></button>

            <div class="flex-1 min-w-0">
              <p class="text-[11px] font-black text-sky-200 uppercase tracking-widest truncate flex items-center gap-1">
                <i class="fas fa-route"></i>
                {{ store.getI18nText(store.servicioActualDeComponente?.nombrePublicoSnapshot as any, store.cotizacion.idiomaEdicion) || 'Servicio' }}
              </p>
              <h2 class="text-sm font-black truncate">{{ getNombreMaestroRef(store.dataActiva) }}</h2>
              <p v-if="store.componentesHermanos.length > 1" class="text-[11px] font-bold text-sky-200 mt-0.5">
                Componente {{ store.componentesHermanos.findIndex(c => c.id === store.dataActiva.id) + 1 }} de {{ store.componentesHermanos.length }}
              </p>
            </div>

            <div v-if="store.componentesHermanos.length > 1" class="flex flex-col gap-1 shrink-0">
              <button @click="store.irAComponenteAdyacente(-1)"
                      :disabled="store.componentesHermanos.findIndex(c => c.id === store.dataActiva.id) === 0"
                      class="w-9 h-9 rounded-lg bg-sky-500/60 hover:bg-sky-400 text-white flex items-center justify-center shadow-sm disabled:opacity-30 disabled:cursor-not-allowed active:scale-95 transition-all">
                <i class="fas fa-chevron-up text-xs"></i>
              </button>
              <button @click="store.irAComponenteAdyacente(1)"
                      :disabled="store.componentesHermanos.findIndex(c => c.id === store.dataActiva.id) === store.componentesHermanos.length - 1"
                      class="w-9 h-9 rounded-lg bg-sky-500/60 hover:bg-sky-400 text-white flex items-center justify-center shadow-sm disabled:opacity-30 disabled:cursor-not-allowed active:scale-95 transition-all">
                <i class="fas fa-chevron-down text-xs"></i>
              </button>
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
              <div v-else class="flex flex-col gap-2 bg-teal-50/60 p-4 rounded-xl border border-teal-100 shadow-sm mt-1">
                <div class="flex items-center justify-between">
                  <div class="flex items-center gap-3">
                    <div class="w-8 h-8 rounded-full bg-teal-100 text-teal-600 flex items-center justify-center shadow-inner">
                      <i class="fas fa-link text-sm"></i>
                    </div>
                    <div class="flex flex-col">
                      <span class="text-[9px] font-black text-teal-500 uppercase tracking-widest">Insumo Maestro (Inyectado / Bloqueado)</span>
                      <span class="text-sm font-black text-teal-900 mt-0.5">{{ getNombreMaestroRef(store.dataActiva) }}</span>
                    </div>
                  </div>
                </div>
              </div>
            </div>

            <div class="grid grid-cols-2 gap-4">
              <div class="col-span-2">
                <label class="block text-[10px] font-black text-slate-500 uppercase mb-1.5 ml-1">Nombre Público *</label>

                <div class="flex gap-2" v-if="!isComponenteSoloItems(store.dataActiva)">
                  <input :value="store.getI18nText(store.dataActiva.nombreSnapshot as any, store.cotizacion?.idiomaEdicion || 'es')"
                         @input="e => { if(store.cotizacion) store.setI18nText(store.dataActiva.nombreSnapshot, store.cotizacion.idiomaEdicion, (e.target as HTMLInputElement).value) }"
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
                      @update:model-value="onInicioChange"
                      :is-24="true"
                      :enable-time-picker="store.requiereHoraExacta(store.getTipoComponente(store.dataActiva.componenteMaestroId))"
                      :format="store.requiereHoraExacta(store.getTipoComponente(store.dataActiva.componenteMaestroId)) ? 'dd/MM/yyyy HH:mm' : 'dd/MM/yyyy'"
                      model-type="yyyy-MM-dd'T'HH:mm:ss"
                      auto-apply
                  >
                    <template #dp-input="{ value, onEnter, onTab, onClear }">
                      <input v-if="store.requiereHoraExacta(store.getTipoComponente(store.dataActiva.componenteMaestroId))"
                             type="text"
                             class="w-full bg-white border border-slate-300 rounded-lg pl-2 pr-2 py-2 text-[10px] font-bold text-slate-700 tabular-nums tracking-tight outline-none shadow-sm focus:ring-2 focus:ring-sky-500 cursor-text"
                             :value="formatParaMascara(store.dataActiva.fechaHoraInicio)"
                             v-strict-mask="(val: string) => procesarFechaMascara(val, 'inicio')"
                             @keydown.enter="onEnter"
                             @keydown.tab="onTab"
                             placeholder="DD/MM/AAAA HH:MM"
                      />
                      <input v-else
                             type="text"
                             class="w-full bg-white border border-slate-300 rounded-lg pl-2 pr-2 py-2 text-[10px] font-bold text-slate-700 tabular-nums tracking-tight outline-none shadow-sm focus:ring-2 focus:ring-sky-500 cursor-text"
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
                      :key="finPickerKey"
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
                             class="w-full bg-white border border-slate-300 rounded-lg pl-2 pr-2 py-2 text-[10px] font-bold text-slate-700 tabular-nums tracking-tight outline-none shadow-sm focus:ring-2 focus:ring-sky-500 cursor-text"
                             :value="formatParaMascara(store.dataActiva.fechaHoraFin)"
                             v-strict-mask="(val: string) => procesarFechaMascara(val, 'fin')"
                             @keydown.enter="onEnter"
                             @keydown.tab="onTab"
                             placeholder="DD/MM/AAAA HH:MM"
                      />
                      <input v-else
                             type="text"
                             class="w-full bg-white border border-slate-300 rounded-lg pl-2 pr-2 py-2 text-[10px] font-bold text-slate-700 tabular-nums tracking-tight outline-none shadow-sm focus:ring-2 focus:ring-sky-500 cursor-text"
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
                <input v-model="store.dataActiva.cantidad" type="number" readonly class="w-full bg-slate-100 text-slate-400 border border-slate-200 rounded-xl px-4 py-3 text-xs font-black text-center outline-none shadow-inner cursor-not-allowed">
              </div>

              <div class="col-span-2 grid grid-cols-1 gap-3">
                <div>
                  <label class="block text-[10px] font-black text-slate-500 uppercase mb-1.5 ml-1">Modo Comercial</label>
                  <div class="relative">
                    <select v-model="store.dataActiva.modo"
                            class="w-full appearance-none rounded-xl px-4 py-2.5 pr-9 text-xs font-black uppercase tracking-wide outline-none shadow-sm border cursor-pointer transition-colors"
                            :class="[getModoItemConfig(store.dataActiva.modo).bg, getModoItemConfig(store.dataActiva.modo).text, getModoItemConfig(store.dataActiva.modo).border]">
                      <option value="incluido">Incluido</option>
                      <option value="no_incluido">No incluido</option>
                      <option value="cortesia">Cortesía</option>
                      <option value="reemplazado">Reemplazado</option>
                    </select>
                    <i class="fas absolute right-3 top-1/2 -translate-y-1/2 pointer-events-none text-xs"
                       :class="[getModoItemConfig(store.dataActiva.modo).icon, getModoItemConfig(store.dataActiva.modo).text]"></i>
                  </div>
                </div>

                <div>
                  <label class="block text-[10px] font-black text-slate-500 uppercase mb-1.5 ml-1">Estado del Servicio</label>
                  <div class="relative">
                    <select v-model="store.dataActiva.estado"
                            class="w-full appearance-none rounded-xl px-4 py-2.5 pr-9 text-xs font-black uppercase tracking-wide outline-none shadow-sm border cursor-pointer transition-colors"
                            :class="[getEstadoComponenteConfig(store.dataActiva.estado).bg, getEstadoComponenteConfig(store.dataActiva.estado).text, getEstadoComponenteConfig(store.dataActiva.estado).border]">
                      <option value="pendiente">Pendiente</option>
                      <option value="confirmado">Confirmado</option>
                      <option value="reconfirmado">Reconfirmado</option>
                      <option value="cancelado">Cancelado</option>
                    </select>
                    <i class="fas absolute right-3 top-1/2 -translate-y-1/2 pointer-events-none text-xs"
                       :class="[getEstadoComponenteConfig(store.dataActiva.estado).icon, getEstadoComponenteConfig(store.dataActiva.estado).text]"></i>
                  </div>
                </div>
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
                     :data-item-id="item.id"
                     class="flex flex-col gap-1 bg-white p-2.5 rounded-xl border shadow-sm transition-all"
                     :class="[
                    item.tieneUpsell ? 'border-l-4 border-l-orange-400' : 'border-slate-200',
                    dragItemId === item.id ? 'opacity-40 scale-[0.98]' : '',
                    dragOverItemId === item.id && dragItemId !== item.id ? 'ring-2 ring-sky-400' : ''
                  ]">

                  <div class="flex gap-3 items-center">
                    <div class="text-slate-300 hover:text-slate-500 cursor-grab active:cursor-grabbing select-none px-1"
                         style="touch-action: none;"
                         @pointerdown="onItemPointerDown($event, item)"
                         @pointermove="onItemPointerMove"
                         @pointerup="onItemPointerUp"
                         @pointercancel="onItemPointerUp">
                      <i class="fas fa-grip-vertical"></i>
                    </div>

                    <input type="checkbox" v-model="item.incluido"
                           @change="store.toggleUpsellComponent(item, store.dataActiva)"
                           class="w-4 h-4 text-sky-600 rounded border-slate-300 focus:ring-sky-500 cursor-pointer">

                    <input :value="store.getI18nText(item.nombreSnapshot as any, store.cotizacion?.idiomaEdicion || 'es')"
                           @input="e => { if(store.cotizacion) store.setI18nText(item.nombreSnapshot, store.cotizacion.idiomaEdicion, (e.target as HTMLInputElement).value) }"
                           class="text-xs font-bold text-slate-700 w-full outline-none bg-transparent"
                           :class="(!item.incluido && item.modo === 'no_incluido') ? 'line-through text-slate-400' : (!item.incluido && item.modo === 'opcional') ? 'text-slate-500 italic' : ''"
                           placeholder="Descripción de la inclusión...">

                    <span v-if="item.modo === 'opcional'" class="text-[8px] font-black bg-slate-100 text-slate-500 px-1.5 py-0.5 rounded uppercase">Opcional</span>
                    <span v-if="item.tieneUpsell" class="text-[8px] font-black bg-orange-100 text-orange-600 px-1.5 py-0.5 rounded uppercase shrink-0 whitespace-nowrap"><i class="fas fa-arrow-up"></i> Upsell</span>

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
              <button @click="detallesOperativosAbierto = !detallesOperativosAbierto"
                      class="w-full flex items-center justify-between text-[10px] font-black text-sky-700 uppercase tracking-widest mb-3">
                <span class="flex items-center gap-1.5">
                  <i class="fas fa-chevron-right transition-transform" :class="detallesOperativosAbierto ? 'rotate-90' : ''"></i>
                  <i class="fas fa-clipboard-list"></i> Detalles Operativos
                </span>
                <span class="flex items-center gap-2">
                  <button @click.stop="store.dataActiva.sobreescribirTraduccion = !store.dataActiva.sobreescribirTraduccion"
                          :class="store.dataActiva.sobreescribirTraduccion ? 'bg-orange-100 text-orange-600 border-orange-300' : 'bg-slate-50 text-slate-400 border-slate-200 hover:bg-slate-100'"
                          class="px-2 py-1 border rounded-lg transition-colors shadow-sm" title="Forzar traducción de todo el componente al guardar">
                    <i class="fas fa-language text-xs"></i>
                  </button>
                  <span @click.stop="store.agregarDetalleOperativo(store.dataActiva.id)"
                        class="bg-sky-100 text-sky-700 px-3 py-1.5 rounded-lg shadow-sm text-xs font-bold border border-sky-200 hover:bg-sky-200 transition-colors normal-case tracking-normal cursor-pointer">
                    + Añadir Detalle
                  </span>
                </span>
              </button>

              <div v-show="detallesOperativosAbierto" class="space-y-2">
                <div v-if="!store.dataActiva.detallesOperativos?.length" class="text-[10px] font-bold text-slate-400 uppercase text-center py-2 border border-dashed border-slate-200 rounded-lg">
                  Sin detalles operativos
                </div>
                <div v-else v-for="bloque in store.dataActiva.detallesOperativos" :key="bloque.id"
                     class="flex gap-2 items-start bg-white p-2.5 rounded-xl border border-slate-200 shadow-sm">
                  <select v-model="bloque.tipo" class="shrink-0 w-32 bg-slate-50 border border-slate-200 rounded-lg px-2 py-2 text-[10px] font-bold text-slate-600 outline-none">
                    <option value="cliente">Detalles</option>
                    <option value="operativa">Operativa</option>
                  </select>
                  <textarea :value="store.getI18nText(bloque.detalle as any, store.cotizacion?.idiomaEdicion || 'es')"
                            @input="e => { if(store.cotizacion) store.setI18nText(bloque.detalle, store.cotizacion.idiomaEdicion, (e.target as HTMLTextAreaElement).value) }"
                            rows="2"
                            class="flex-1 bg-transparent text-xs font-bold text-slate-700 outline-none resize-none"
                            placeholder="Contenido..."></textarea>
                  <button @click="store.eliminarDetalleOperativo(store.dataActiva.id, bloque.id)" class="text-slate-300 hover:text-red-500 transition-colors px-1 shrink-0">
                    <i class="fas fa-times text-sm"></i>
                  </button>
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
                <div v-for="(tarifa, idx) in cottarifasOrdenadas" :key="tarifa.id || idx" @click="store.abrirNivel('tarifa', tarifa)"
                     class="bg-white border-2 border-orange-200 rounded-xl p-4 shadow-sm cursor-pointer hover:border-orange-400 relative group overflow-hidden transition-all">
                  <div class="absolute left-0 top-0 bottom-0 w-1.5" :class="getRolTarifaUI(tarifa.rolSnapshot).bg.replace('bg-', 'bg-').replace('-50','-400')"></div>

                  <button @click.stop="store.eliminarTarifa(store.dataActiva.id, tarifa.id)" class="absolute right-3 top-3 text-slate-300 hover:text-red-500 transition-colors z-10 p-1 bg-slate-50 w-6 h-6 rounded-full flex items-center justify-center">
                    <i class="fas fa-trash-alt text-xs"></i>
                  </button>

                  <div class="flex justify-between items-start pr-8">
                    <div>
                      <span class="text-[10px] font-black text-slate-500 uppercase mb-0.5 block">
                        {{ tarifa.nombreInternoSnapshot || 'Tarifa Manual' }}
                      </span>
                      <div class="flex gap-2 mt-1 flex-wrap">
                        <span class="text-[9px] font-bold bg-slate-100 text-slate-500 px-1.5 py-0.5 rounded border border-slate-200 flex items-center gap-1">
                           <i :class="tarifa.esGrupal ? 'fas fa-users text-orange-400' : 'fas fa-user text-sky-400'"></i>
                           {{ tarifa.esGrupal ? 'Costo Grupal (Fijo)' : `${tarifa.cantidad} Pax` }}
                        </span>
                        <span class="text-[9px] font-black px-1.5 py-0.5 rounded border uppercase flex items-center gap-1"
                              :class="[getRolTarifaUI(tarifa.rolSnapshot).bg, getRolTarifaUI(tarifa.rolSnapshot).text, getRolTarifaUI(tarifa.rolSnapshot).border]">
                          <i class="fas" :class="getRolTarifaUI(tarifa.rolSnapshot).icon"></i>
                          {{ getRolTarifaUI(tarifa.rolSnapshot).label }}
                        </span>

                        <span v-if="tarifa.grupoTarifa != null" class="text-[9px] font-black bg-teal-50 text-teal-600 px-1.5 py-0.5 rounded border border-teal-100 uppercase">
                          Grupo {{ tarifa.grupoTarifa }}
                        </span>

                        <span v-if="tarifa.proveedorOculto" class="text-[9px] font-black bg-slate-200 text-slate-600 px-1.5 py-0.5 rounded uppercase flex items-center gap-1">
                            <i class="fas fa-user-secret"></i> Oculto
                        </span>
                      </div>
                    </div>
                    <div class="text-right shrink-0">
                      <span class="font-black text-orange-600 text-base block">{{ formatMoneda(tarifa.montoCosto * (tarifa.esGrupal ? 1 : tarifa.cantidad), tarifa.moneda) }}</span>
                      <p class="text-xs font-black text-emerald-600 mt-0.5 flex items-center justify-end gap-1">
                        <i class="fas fa-tag text-[9px]"></i>
                        {{ formatMoneda(calcularVentaTarifa(tarifa), tarifa.moneda) }}
                        <span class="text-slate-400 font-bold normal-case">
                          ({{ tarifa.comisionOverrideSnapshot ? `${tarifa.comisionOverrideSnapshot}%` : 'global' }})
                        </span>
                      </p>
                    </div>
                  </div>

                  <div v-if="tarifa.proveedorNombreSnapshot" class="mt-3 pt-3 border-t border-slate-100 flex flex-wrap items-center gap-2">
                    <span class="text-[10px] font-bold text-slate-600 bg-slate-50 px-2 py-1 rounded-lg border border-slate-200 flex items-center gap-1.5">
                      <i class="fas fa-truck-loading text-slate-400"></i> {{ tarifa.proveedorNombreSnapshot }}
                    </span>

                    <span v-if="tarifa.estadoOperativoSnapshot && tarifa.estadoOperativoSnapshot !== 'sin-solicitar'"
                          class="text-[9px] font-black px-2 py-1 rounded-lg border shadow-sm flex items-center gap-1"
                          :class="[getEstadoOperativoConfig(tarifa.estadoOperativoSnapshot).bg, getEstadoOperativoConfig(tarifa.estadoOperativoSnapshot).text, getEstadoOperativoConfig(tarifa.estadoOperativoSnapshot).border]">
                      <i class="fas" :class="getEstadoOperativoConfig(tarifa.estadoOperativoSnapshot).icon"></i>
                      {{ getEstadoOperativoConfig(tarifa.estadoOperativoSnapshot).label }}
                    </span>

                    <span v-if="tarifa.fechaLimitePago"
                          class="text-[9px] font-black px-2 py-1 rounded-lg border shadow-sm flex items-center gap-1"
                          :class="new Date(tarifa.fechaLimitePago) < new Date() ? 'bg-red-50 text-red-700 border-red-200' : 'bg-slate-50 text-slate-600 border-slate-200'">
                      <i class="far fa-calendar-alt"></i> {{ formatDateOnlyFromISO(tarifa.fechaLimitePago) }}
                    </span>
                  </div>

                </div>
              </div>
            </div>
          </div>
        </div>





        <div v-else-if="store.inspectorActivo === 'tarifa'" class="flex-1 flex flex-col min-h-0 bg-white">
          <div class="px-5 py-1 border-b border-orange-200 flex items-center gap-3 bg-orange-50 shrink-0 shadow-sm z-10">
            <button @click="store.retrocederNivel" class="w-8 h-8 rounded-full hover:bg-orange-200 text-orange-600 flex items-center justify-center transition-colors shrink-0"><i class="fas fa-arrow-left"></i></button>

            <div class="flex-1 min-w-0">
              <p class="text-[11px] font-black text-orange-400 uppercase tracking-widest truncate flex items-center gap-1">
                <i class="fas fa-box-open"></i> {{ getNombreMaestroRef(store.componenteActualDeTarifa) }}
              </p>
              <h2 class="text-sm font-black text-slate-800 truncate">{{ store.getI18nText(store.dataActiva?.nombreSnapshot as any, store.cotizacion.idiomaEdicion) }}</h2>
              <p v-if="store.tarifasHermanas.length > 1" class="text-[11px] font-bold text-slate-400 mt-0.5">
                Tarifa {{ store.tarifasHermanas.findIndex(t => t.id === store.dataActiva.id) + 1 }} de {{ store.tarifasHermanas.length }}
              </p>
            </div>

            <div v-if="store.tarifasHermanas.length > 1" class="flex flex-col gap-1 shrink-0">
              <button @click="store.irATarifaAdyacente(-1)"
                      :disabled="store.tarifasHermanas.findIndex(t => t.id === store.dataActiva.id) === 0"
                      class="w-9 h-9 rounded-lg bg-white border border-orange-200 text-orange-600 flex items-center justify-center shadow-sm disabled:opacity-30 disabled:cursor-not-allowed active:scale-95 transition-all">
                <i class="fas fa-chevron-up text-xs"></i>
              </button>
              <button @click="store.irATarifaAdyacente(1)"
                      :disabled="store.tarifasHermanas.findIndex(t => t.id === store.dataActiva.id) === store.tarifasHermanas.length - 1"
                      class="w-9 h-9 rounded-lg bg-white border border-orange-200 text-orange-600 flex items-center justify-center shadow-sm disabled:opacity-30 disabled:cursor-not-allowed active:scale-95 transition-all">
                <i class="fas fa-chevron-down text-xs"></i>
              </button>
            </div>
          </div>

          <div class="p-5 flex-1 overflow-y-auto space-y-6 pb-28 bg-slate-50/50">

            <div class="bg-white border border-slate-200 shadow-sm p-4 rounded-xl">
              <label class="block text-[10px] font-black text-orange-500 uppercase tracking-widest mb-2"><i class="fas fa-tags mr-1"></i> Tarifa Maestra</label>

              <div class="flex gap-2 items-center">
                <SearchableSelect
                    v-model="store.dataActiva.tarifaMaestraId"
                    :options="opcionesTarifas"
                    placeholder="Precio manual..."
                    :darkMode="false"
                    @update:model-value="val => store.onTarifaMaestraChange(val)"
                    class="flex-1"
                />
                <button v-if="store.dataActiva.tarifaMaestraId"
                        @click="store.dataActiva.tarifaMaestraId = null"
                        class="w-9 h-9 shrink-0 bg-red-50 text-red-500 rounded-lg border border-red-100 hover:bg-red-200 transition-colors flex items-center justify-center shadow-sm"
                        title="Desvincular tarifa maestra">
                  <i class="fas fa-times"></i>
                </button>
              </div>

              <div v-if="store.dataActiva.tarifaMaestraId" class="mt-3 pt-3 border-t border-slate-100 flex flex-wrap gap-2">
                <template v-for="catT in [store.catalogos.tarifas.find(t => store.extractIdStr(t) === store.extractIdStr(store.dataActiva.tarifaMaestraId))]">
              <span v-if="catT" class="text-[9px] font-bold text-slate-600 bg-slate-100 px-2 py-1 rounded border border-slate-200 uppercase">
                <span class="mr-1">{{ getProcedenciaUI((catT as any).procedencia).icon }}</span>
                  {{ getProcedenciaUI((catT as any).procedencia).label }}
                </span>
                  <span v-if="catT && formatRangoEdad((catT as any).edadMinima, (catT as any).edadMaxima)" class="text-[9px] font-bold text-slate-600 bg-slate-100 px-2 py-1 rounded border border-slate-200 uppercase">
                <i class="fas fa-birthday-cake text-orange-500 mr-1"></i>
                {{ formatRangoEdad((catT as any).edadMinima, (catT as any).edadMaxima) }}
              </span>
                </template>
              </div>
            </div>

            <div class="grid grid-cols-3 gap-4 items-start">
              <div>
                <label class="block text-[10px] font-black text-slate-500 uppercase mb-1 ml-1">Cant (Pax) *</label>
                <input v-model="store.dataActiva.cantidad"
                       type="number"
                       :readonly="store.dataActiva.esGrupal"
                       :class="store.dataActiva.esGrupal ? 'bg-slate-100 text-slate-400 cursor-not-allowed border-slate-200' : 'bg-white text-slate-800 border-slate-300 focus:ring-2 focus:ring-orange-500'"
                       class="w-full rounded-xl px-4 py-2 text-sm font-bold text-center outline-none shadow-sm border">
                <p v-if="store.dataActiva.esGrupal" class="text-[9px] text-orange-500 mt-1 ml-1">Precio por grupo fijo</p>

                <label class="block text-[10px] font-black text-slate-500 uppercase mb-1 ml-1 mt-3">Comisión Propia (%)</label>
                <input v-model.number="store.dataActiva.comisionOverrideSnapshot" type="number" step="0.1" placeholder="Usa la global"
                       class="w-full bg-amber-50 border border-amber-300 text-amber-700 rounded-xl px-4 py-2 text-sm font-black text-center outline-none focus:ring-2 focus:ring-amber-500 shadow-sm">
                <p class="text-[10px] text-slate-600 mt-1 ml-1">Vacío = usa la global.</p>
              </div>

              <div class="col-span-2 bg-white border border-slate-200 rounded-2xl p-4 shadow-sm">
                <div class="flex justify-between items-center">
                  <div>
                    <label class="block text-[10px] font-black text-slate-500 uppercase mb-1.5">Moneda</label>
                    <select v-model="store.dataActiva.moneda" class="bg-transparent text-slate-800 font-bold text-xs outline-none border-b border-slate-300 pb-1 appearance-none focus:border-orange-500 transition-colors">
                      <option value="USD">USD</option>
                      <option value="PEN">PEN</option>
                    </select>
                  </div>
                  <div>
                    <label class="block text-[10px] font-black text-slate-500 uppercase mb-1.5 text-right">Costo Unitario</label>
                    <input v-model.number="store.dataActiva.montoCosto" type="number" step="0.01" class="w-28 bg-slate-50 border border-slate-300 text-orange-600 rounded-xl px-3 py-2 text-lg font-black text-right focus:border-orange-500 outline-none shadow-inner">
                  </div>
                </div>
                <div class="flex justify-end items-baseline gap-1.5 mt-3 pt-3 border-t border-slate-100">
                  <span class="text-[9px] text-slate-500 font-bold uppercase">Subtotal Neto:</span>
                  <span class="text-orange-600 text-sm font-black">{{ formatMoneda(store.dataActiva.montoCosto * store.dataActiva.cantidad, store.dataActiva.moneda) }}</span>
                </div>
              </div>
            </div>

            <div class="grid grid-cols-2 gap-4">
              <div class="col-span-2 mt-2">
                <label class="block text-[10px] font-black text-slate-500 uppercase mb-1.5 ml-1">Nombre Interno (Operativo) *</label>
                <input v-model="store.dataActiva.nombreInternoSnapshot"
                       type="text" class="w-full bg-slate-50 border border-slate-300 text-slate-800 rounded-xl px-4 py-3 text-sm font-bold focus:ring-2 focus:ring-orange-500 outline-none shadow-inner mb-4"
                       placeholder="Ej: Adulto Extranjero...">

                <label class="block text-[10px] font-black text-slate-500 uppercase mb-1.5 ml-1">Nombre para cliente *</label>
                <div class="flex gap-2">
                  <input :value="store.getI18nText(store.dataActiva.tituloSnapshot as any, store.cotizacion?.idiomaEdicion || 'es')"
                         @input="e => { if(store.cotizacion) store.setI18nText(store.dataActiva.tituloSnapshot, store.cotizacion.idiomaEdicion, (e.target as HTMLInputElement).value) }"
                         type="text" class="flex-1 bg-white border border-slate-300 text-slate-800 rounded-xl px-4 py-3 text-sm font-bold focus:ring-2 focus:ring-orange-500 outline-none shadow-sm">

                  <button @click="store.dataActiva.sobreescribirTraduccion = !store.dataActiva.sobreescribirTraduccion"
                          :class="store.dataActiva.sobreescribirTraduccion ? 'bg-orange-100 text-orange-600 border-orange-300' : 'bg-slate-50 text-slate-400 border-slate-200 hover:bg-slate-100'"
                          class="px-4 border rounded-xl transition-colors shadow-sm" title="Forzar traducción">
                    <i class="fas fa-language"></i>
                  </button>
                </div>
              </div>

              <div class="col-span-2 bg-white border border-slate-200 p-4 rounded-2xl mb-2 shadow-sm">
                <div>
                  <p class="text-xs font-black text-slate-800 flex items-center gap-2">
                    <i class="fas fa-calculator text-emerald-500"></i> Modalidad de Cálculo
                  </p>
                  <p class="text-[10px] text-slate-500 mt-1">
                    {{ store.dataActiva.tarifaMaestraId ? 'Bloqueado por Catálogo Maestro' : 'Define si el costo es por persona o por el total' }}
                  </p>
                </div>

                <div class="flex gap-4 mt-4">
                  <button type="button"
                          @click="!store.dataActiva.tarifaMaestraId && (store.dataActiva.esGrupal = false)"
                          :disabled="!!store.dataActiva.tarifaMaestraId"
                          :class="[
                          !store.dataActiva.esGrupal ? 'bg-orange-50 border-orange-300 text-orange-600 shadow-sm' : 'bg-slate-50 border-slate-200 text-slate-400',
                          store.dataActiva.tarifaMaestraId ? 'cursor-not-allowed opacity-60' : 'cursor-pointer hover:border-orange-300'
                      ]"
                          class="flex-1 text-center p-2 rounded-xl border transition-all">
                    <i class="fas fa-user text-xs mb-1"></i>
                    <span class="text-[8px] font-black uppercase">Unitario (Pax)</span>
                  </button>
                  <button type="button"
                          @click="!store.dataActiva.tarifaMaestraId && (store.dataActiva.esGrupal = true)"
                          :disabled="!!store.dataActiva.tarifaMaestraId"
                          :class="[
                          store.dataActiva.esGrupal ? 'bg-orange-50 border-orange-300 text-orange-600 shadow-sm' : 'bg-slate-50 border-slate-200 text-slate-400',
                          store.dataActiva.tarifaMaestraId ? 'cursor-not-allowed opacity-60' : 'cursor-pointer hover:border-orange-300'
                      ]"
                          class="flex-1 text-center p-2 rounded-xl border transition-all">
                    <i class="fas fa-users text-xs mb-1"></i>
                    <span class="text-[8px] font-black uppercase">Grupal (Flat)</span>
                  </button>
                </div>
              </div>

              <div class="col-span-2 bg-white border border-slate-200 p-4 rounded-2xl mb-2 shadow-sm">
                <p class="text-xs font-black text-slate-800 flex items-center gap-2 mb-3">
                  <i class="fas fa-sliders-h text-emerald-500"></i> Restricciones de Tarifa
                </p>
                <div class="grid grid-cols-2 gap-3">
                  <div>
                    <label class="block text-[9px] font-bold text-slate-500 uppercase mb-1 ml-1">Modalidad</label>
                    <select v-model="store.dataActiva.modalidadSnapshot"
                            class="w-full bg-white border border-slate-300 rounded-lg px-3 py-2 text-xs font-bold text-slate-700 outline-none focus:ring-2 focus:ring-emerald-500 shadow-sm">
                      <option :value="null">Sin modalidad</option>
                      <option v-for="opt in enumOptions(MODALIDAD_CONFIG)" :key="opt.value" :value="opt.value">
                        {{ opt.icon }} {{ opt.label }}
                      </option>
                    </select>
                  </div>
                  <div>
                    <label class="block text-[9px] font-bold text-slate-500 uppercase mb-1 ml-1">Categoría</label>
                    <select v-model="store.dataActiva.categoriaSnapshot"
                            class="w-full bg-white border border-slate-300 rounded-lg px-3 py-2 text-xs font-bold text-slate-700 outline-none focus:ring-2 focus:ring-emerald-500 shadow-sm">
                      <option :value="null">Sin categoria</option>
                      <option v-for="opt in enumOptions(CATEGORIA_CONFIG)" :key="opt.value" :value="opt.value">
                        {{ opt.icon }} {{ opt.label }}
                      </option>
                    </select>
                  </div>
                </div>
              </div>

              <div class="col-span-2 bg-white border border-teal-200 rounded-2xl p-4 shadow-sm">
                <div class="flex items-center justify-between mb-3">
                  <p class="text-xs font-black text-slate-800 flex items-center gap-2">
                    <i class="fas fa-layer-group text-teal-500"></i> Rol y Agrupamiento
                  </p>
                  <span class="text-[9px] font-black px-2 py-1 rounded border uppercase"
                        :class="[getRolTarifaUI(store.dataActiva.rolSnapshot).bg, getRolTarifaUI(store.dataActiva.rolSnapshot).text, getRolTarifaUI(store.dataActiva.rolSnapshot).border]">
                <i class="fas" :class="getRolTarifaUI(store.dataActiva.rolSnapshot).icon"></i>
                {{ getRolTarifaUI(store.dataActiva.rolSnapshot).label }}
              </span>
                </div>

                <div v-if="store.dataActiva.rolSnapshot === 'operativo'" class="mb-3 bg-slate-100 border border-slate-200 rounded-lg px-3 py-2.5 flex items-start gap-2">
                  <i class="fas fa-lock text-slate-400 mt-0.5"></i>
                  <span class="text-[10px] font-bold text-slate-500 leading-tight">Rol Operativo — heredado del catálogo maestro. No se elige a mano ni participa del selector de opciones del cliente.</span>
                </div>

                <div v-else class="flex gap-2 mb-3 items-end">
                  <div class="flex-1">
                    <label class="block text-[9px] font-bold text-slate-500 uppercase mb-1 ml-1">Rol Comercial</label>
                    <div class="flex gap-2">
                      <button @click="store.dataActiva.grupoTarifa != null && store.marcarTarifaComoEstandar(store.dataActiva.id)"
                              :disabled="store.dataActiva.grupoTarifa == null"
                              :class="[
                              store.dataActiva.rolSnapshot === 'estandar' ? 'bg-blue-600 text-white border-blue-600' : 'bg-slate-50 text-slate-500 border-slate-200 hover:bg-blue-50',
                              store.dataActiva.grupoTarifa == null ? 'opacity-40 cursor-not-allowed' : ''
                          ]"
                              class="flex-1 py-2 rounded-lg border text-[10px] font-black uppercase transition-colors">
                        <i class="fas fa-star mr-1"></i> Estándar
                      </button>
                      <button @click="store.dataActiva.rolSnapshot = 'alternativa'"
                              :class="store.dataActiva.rolSnapshot === 'alternativa' ? 'bg-teal-600 text-white border-teal-600' : 'bg-slate-50 text-slate-500 border-slate-200 hover:bg-teal-50'"
                              class="flex-1 py-2 rounded-lg border text-[10px] font-black uppercase transition-colors">
                        <i class="fas fa-right-left mr-1"></i> Alternativa
                      </button>
                    </div>
                  </div>

                  <div class="w-24 shrink-0">
                    <label class="block text-[9px] font-bold text-slate-500 uppercase mb-1 ml-1">Grupo</label>
                    <input v-model.number="store.dataActiva.grupoTarifa" type="number" min="1" placeholder="Ej: 1"
                           class="w-full bg-white border border-slate-300 rounded-lg px-2 py-2 text-sm font-black text-center outline-none focus:ring-2 focus:ring-teal-500 shadow-sm">
                  </div>
                </div>

                <p v-if="store.dataActiva.rolSnapshot !== 'operativo' && store.dataActiva.grupoTarifa == null" class="text-[9px] text-amber-600 bg-amber-50 border border-amber-200 rounded-lg px-2 py-1.5 mb-3">
                  <i class="fas fa-exclamation-triangle mr-1"></i> Sin grupo asignado — no se puede marcar como estándar hasta definir un grupo.
                </p>

                <div class="mt-3">
                  <label class="block text-[9px] font-bold text-slate-500 uppercase mb-1 ml-1">Nota Aclaratoria (horarios, condiciones...)</label>
                  <textarea :value="store.getI18nText(store.dataActiva.notaRol as any, store.cotizacion?.idiomaEdicion || 'es')"
                            @input="e => { if(store.cotizacion) store.setI18nText(store.dataActiva.notaRol, store.cotizacion.idiomaEdicion, (e.target as HTMLTextAreaElement).value) }"
                            rows="2"
                            class="w-full bg-white border border-slate-300 text-slate-800 rounded-lg px-3 py-2 text-xs font-bold outline-none focus:ring-2 focus:ring-teal-500 shadow-sm resize-none"
                            placeholder="Ej: Sale 06:00am, llega 10:00am..."></textarea>
                </div>
              </div>

              <div class="col-span-2 bg-white border border-orange-200 rounded-2xl mt-2 relative overflow-hidden transition-all duration-300 shadow-sm">
                <div class="absolute left-0 top-0 bottom-0 w-1 bg-orange-400 z-10"></div>

                <div @click="isProveedorOpen = !isProveedorOpen" class="p-4 pl-5 cursor-pointer flex items-center justify-between hover:bg-orange-50/50 transition-colors relative">
                  <div>
                    <label class="text-[10px] font-black text-orange-500 uppercase tracking-widest cursor-pointer mb-0.5 flex items-center gap-1.5">
                      <i class="fas fa-truck-loading"></i> Datos del Proveedor

                      <span v-if="store.dataActiva.estadoOperativoSnapshot && store.dataActiva.estadoOperativoSnapshot !== 'sin-solicitar'"
                            class="inline-flex items-center gap-1 text-[8px] px-1.5 py-0.5 rounded border uppercase font-black tracking-tighter ml-2"
                            :class="[
                          getEstadoOperativoConfig(store.dataActiva.estadoOperativoSnapshot).bg,
                          getEstadoOperativoConfig(store.dataActiva.estadoOperativoSnapshot).text,
                          getEstadoOperativoConfig(store.dataActiva.estadoOperativoSnapshot).border
                        ]">
                    <i class="fas" :class="getEstadoOperativoConfig(store.dataActiva.estadoOperativoSnapshot).icon"></i>
                    {{ getEstadoOperativoConfig(store.dataActiva.estadoOperativoSnapshot).label }}
                  </span>
                    </label>
                    <p class="text-sm font-bold flex items-center gap-2" :class="store.dataActiva.proveedorNombreSnapshot ? 'text-slate-800' : 'text-slate-400 italic'">
                      {{ store.dataActiva.proveedorNombreSnapshot || 'Sin proveedor asignado' }}
                      <i v-if="store.dataActiva.vencimientoPagoSnapshot" class="fas fa-bell text-orange-500 text-xs" title="Tiene alerta de pago"></i>
                    </p>
                  </div>

                  <div class="flex items-center gap-3">
                <span v-if="store.dataActiva.proveedorMaestroId" class="text-[8px] bg-emerald-100 text-emerald-600 px-2 py-0.5 rounded border border-emerald-200 uppercase font-black hidden sm:inline-block">
                  Catálogo
                </span>
                    <span v-else-if="store.dataActiva.proveedorNombreSnapshot" class="text-[8px] bg-sky-100 text-sky-600 px-2 py-0.5 rounded border border-sky-200 uppercase font-black hidden sm:inline-block">
                  Libre
                </span>
                    <div class="w-6 h-6 rounded-full bg-slate-100 flex items-center justify-center text-slate-500 shrink-0 border border-slate-200">
                      <i class="fas transition-transform" :class="isProveedorOpen ? 'fa-chevron-up' : 'fa-chevron-down'"></i>
                    </div>
                  </div>
                </div>

                <div v-show="isProveedorOpen" class="p-4 pt-2 border-t border-slate-100 bg-slate-50/50">

                  <fieldset class="border border-slate-200 bg-white rounded-xl p-4 mb-5 shadow-sm">
                    <legend class="text-[10px] font-black text-slate-500 uppercase px-2 bg-white rounded border border-slate-100">1. Proveedor Logístico</legend>

                    <div class="flex items-center justify-between mb-4 bg-orange-50/50 p-2 rounded-lg border border-orange-100">
                      <label class="flex items-center gap-2 cursor-pointer w-full">
                        <input type="checkbox" v-model="store.dataActiva.proveedorOculto" class="w-4 h-4 text-orange-600 border-slate-300 rounded focus:ring-orange-500">
                        <span class="text-[10px] font-bold text-slate-700 uppercase">Ocultar este proveedor al cliente</span>
                      </label>
                    </div>

                    <div class="flex gap-2 items-center">
                      <SearchableSelect
                          v-model="store.dataActiva.proveedorMaestroId"
                          :options="opcionesProveedores"
                          placeholder="Seleccionar proveedor del catálogo..."
                          :darkMode="false"
                          @change="val => store.onProveedorChange(val)"
                          class="flex-1"
                      />
                      <button v-if="store.dataActiva.proveedorMaestroId"
                              @click="store.onProveedorChange(null)"
                              class="w-9 h-9 shrink-0 bg-red-50 text-red-500 rounded-lg border border-red-100 hover:bg-red-200 transition-colors flex items-center justify-center shadow-sm"
                              title="Deseleccionar Proveedor">
                        <i class="fas fa-times"></i>
                      </button>
                    </div>

                    <div class="mt-4">
                      <label class="block text-[9px] font-bold text-slate-500 uppercase mb-1 ml-1">
                        Título Público del Proveedor
                      </label>
                      <div class="flex gap-2">
                        <input :value="store.getI18nText(store.dataActiva.proveedorTituloSnapshot as any, store.cotizacion?.idiomaEdicion || 'es')"
                               @input="e => { if(store.cotizacion) store.setI18nText(store.dataActiva.proveedorTituloSnapshot, store.cotizacion.idiomaEdicion, (e.target as HTMLInputElement).value) }"
                               type="text"
                               class="flex-1 bg-white border border-slate-300 text-slate-800 rounded-lg px-3 py-2 text-xs font-bold focus:ring-2 focus:ring-orange-500 outline-none shadow-sm"
                               placeholder="Nombre del proveedor de cara al cliente..." />
                        <button @click="store.dataActiva.sobreescribirTraduccion = !store.dataActiva.sobreescribirTraduccion"
                                :class="store.dataActiva.sobreescribirTraduccion ? 'bg-orange-100 text-orange-600 border-orange-300' : 'bg-slate-50 text-slate-400 border-slate-200'"
                                class="px-3 border rounded-lg transition-colors shadow-sm" title="Forzar traducción">
                          <i class="fas fa-language"></i>
                        </button>
                      </div>

                      <div class="flex items-center gap-2 mt-3">
                        <i class="fas fa-building text-slate-400 text-xs w-4 shrink-0 text-center" title="URL a nivel Proveedor"></i>
                        <div class="flex-1">
                          <input v-model="store.dataActiva.proveedorUrlSnapshot"
                                 @blur="onUrlBlur('proveedorUrlSnapshot')"
                                 type="url"
                                 :class="!esUrlValida(store.dataActiva.proveedorUrlSnapshot) ? 'border-red-400 focus:ring-red-500 text-red-600' : 'border-slate-300 text-sky-600 focus:ring-orange-500'"
                                 class="w-full bg-white border rounded-lg px-3 py-2 text-xs focus:ring-2 outline-none shadow-sm"
                                 placeholder="URL del proveedor (sitio, microsite, doc)..." />
                          <p v-if="!esUrlValida(store.dataActiva.proveedorUrlSnapshot)" class="text-[9px] text-red-500 mt-1 ml-1">
                            <i class="fas fa-exclamation-circle mr-1"></i> URL inválida.
                          </p>
                        </div>
                      </div>
                    </div>

                    <div class="mt-4 pt-3 border-t border-slate-100">
                      <label class="block text-[9px] font-bold text-slate-500 uppercase mb-1 ml-1">Nombre en Snapshot (Histórico)</label>
                      <input
                          :value="store.dataActiva?.proveedorNombreSnapshot"
                          @input="handleNombreProveedorInput"
                          type="text"
                          class="w-full bg-white border border-slate-300 text-slate-800 rounded-lg px-3 py-2 text-xs font-bold focus:ring-2 focus:ring-orange-500 outline-none shadow-sm"
                          placeholder="Nombre del proveedor o servicio libre..."
                      />
                      <p class="text-[9px] text-slate-400 mt-1 ml-1 flex items-center gap-1">
                        <i class="fas fa-info-circle"></i> Fija la identidad para el historial financiero.
                      </p>
                    </div>
                  </fieldset>

                  <fieldset class="border border-slate-200 bg-white rounded-xl p-4 mb-4 shadow-sm">
                    <legend class="text-[10px] font-black text-slate-500 uppercase px-2 bg-white rounded border border-slate-100">2. Servicio Contratado</legend>

                    <label class="text-[9px] font-bold text-slate-500 uppercase mb-2 ml-1 flex items-center gap-1">
                      <i class="fas fa-concierge-bell text-teal-500"></i> Buscar en el catálogo del proveedor (Opcional)
                    </label>

                    <div class="flex gap-2 items-center">
                      <SearchableSelect
                          v-if="store.dataActiva.proveedorMaestroId"
                          v-model="store.dataActiva.proveedorServicioMaestroId"
                          :options="store.catalogos.proveedorServicios.map(ps => ({ value: ps.id, label: ps.nombre }))"
                          placeholder="Buscar servicio del proveedor..."
                          :darkMode="false"
                          @change="val => store.onProveedorServicioChange(val)"
                          class="flex-1"
                      />

                      <div v-else class="flex-1 bg-slate-50 border border-slate-200 text-slate-400 rounded-lg px-3 py-2.5 text-xs font-bold flex items-center gap-2 shadow-inner">
                        <i class="fas fa-info-circle"></i> Selecciona un proveedor arriba para ver sus servicios
                      </div>

                      <button v-if="store.dataActiva.proveedorServicioMaestroId"
                              @click="store.onProveedorServicioChange(null)"
                              class="w-9 h-9 shrink-0 bg-red-50 text-red-500 rounded-lg border border-red-100 hover:bg-red-100 transition-colors flex items-center justify-center shadow-sm"
                              title="Quitar servicio">
                        <i class="fas fa-times"></i>
                      </button>
                      <div v-else class="w-full bg-slate-50 border border-slate-200 text-slate-400 rounded-lg px-3 py-2.5 text-xs font-bold flex items-center gap-2 shadow-inner">
                        <i class="fas fa-info-circle"></i> Selecciona un proveedor arriba para ver sus servicios
                      </div>

                    </div>


                    <div class="mt-4">
                      <label class="block text-[9px] font-bold text-slate-500 uppercase mb-1 ml-1">
                        Nombre Interno del Servicio
                      </label>
                      <input v-model="store.dataActiva.proveedorServicioNombreSnapshot"
                             type="text"
                             class="w-full bg-white border border-slate-300 text-slate-800 rounded-lg px-3 py-2 text-xs font-bold focus:ring-2 focus:ring-teal-500 outline-none shadow-sm"
                             placeholder="Ej: Habitación Matrimonial Standard..." />
                    </div>

                    <div class="mt-3">
                      <label class="block text-[9px] font-bold text-slate-500 uppercase mb-1 ml-1">
                        Título Público del Servicio
                      </label>
                      <div class="flex gap-2">
                        <input :value="store.getI18nText(store.dataActiva.proveedorServicioTituloSnapshot as any, store.cotizacion?.idiomaEdicion || 'es')"
                               @input="e => { if(store.cotizacion) store.setI18nText(store.dataActiva.proveedorServicioTituloSnapshot, store.cotizacion.idiomaEdicion, (e.target as HTMLInputElement).value) }"
                               type="text"
                               class="flex-1 bg-white border border-slate-300 text-slate-800 rounded-lg px-3 py-2 text-xs font-bold focus:ring-2 focus:ring-teal-500 outline-none shadow-sm"
                               placeholder="Título público del servicio..." />
                        <button @click="store.dataActiva.sobreescribirTraduccion = !store.dataActiva.sobreescribirTraduccion"
                                :class="store.dataActiva.sobreescribirTraduccion ? 'bg-orange-100 text-orange-600 border-orange-300' : 'bg-slate-50 text-slate-400 border-slate-200'"
                                class="px-3 border rounded-lg transition-colors shadow-sm" title="Forzar traducción">
                          <i class="fas fa-language"></i>
                        </button>
                      </div>
                    </div>

                    <div class="flex items-center gap-2 mt-3">
                      <i class="fas fa-door-open text-teal-400 text-xs w-4 shrink-0 text-center" title="URL a nivel Servicio del Proveedor"></i>
                      <div class="flex-1">
                        <input v-model="store.dataActiva.proveedorServicioUrlSnapshot"
                               @blur="onUrlBlur('proveedorServicioUrlSnapshot')"
                               type="url"
                               :class="!esUrlValida(store.dataActiva.proveedorServicioUrlSnapshot) ? 'border-red-400 focus:ring-red-500 text-red-600' : 'border-slate-300 text-sky-600 focus:ring-teal-500'"
                               class="w-full bg-white border rounded-lg px-3 py-2 text-xs focus:ring-2 outline-none shadow-sm"
                               placeholder="URL del servicio (ej: ficha de la habitación)..." />
                        <p v-if="!esUrlValida(store.dataActiva.proveedorServicioUrlSnapshot)" class="text-[9px] text-red-500 mt-1 ml-1">
                          <i class="fas fa-exclamation-circle mr-1"></i> URL inválida.
                        </p>
                      </div>
                    </div>
                  </fieldset>


                  <template v-if="store.dataActiva.proveedorNombreSnapshot">
                    <div class="mt-5 pt-4 border-t border-slate-200">
                      <label class="text-[9px] font-bold text-slate-500 uppercase mb-1 ml-1 flex items-center justify-between">
                        <span>Nombre para la Reserva (Email)</span>
                        <i class="fas fa-paper-plane text-slate-400"></i>
                      </label>
                      <input v-model="store.dataActiva.nombreParaProveedorSnapshot"
                             type="text"
                             class="w-full bg-emerald-50/50 border border-emerald-200 text-emerald-700 rounded-lg px-3 py-2 text-xs font-bold focus:ring-2 focus:ring-emerald-500 outline-none shadow-sm"
                             placeholder="Ej: Cena Buffet Tunupa / 2 Pax..." />
                      <p class="text-[9px] text-slate-400 mt-1 ml-1 flex items-start gap-1">
                        <i class="fas fa-exclamation-circle mt-0.5 text-emerald-500"></i>
                        Este es el texto exacto del requerimiento automático.
                      </p>
                    </div>

                    <div class="mt-4 grid grid-cols-1 md:grid-cols-3 gap-4">

                      <div>
                        <label class="text-[9px] font-bold text-slate-500 uppercase mb-1 ml-1 flex items-center gap-1">
                          <i class="fas fa-tasks text-sky-500"></i> Estado de Reserva
                        </label>
                        <div class="relative">
                          <select v-model="store.dataActiva.estadoOperativoSnapshot"
                                  class="w-full appearance-none rounded-lg px-3 py-2 pr-8 text-xs font-black outline-none shadow-sm border cursor-pointer transition-colors"
                                  :class="[getEstadoOperativoConfig(store.dataActiva.estadoOperativoSnapshot).bg, getEstadoOperativoConfig(store.dataActiva.estadoOperativoSnapshot).text, getEstadoOperativoConfig(store.dataActiva.estadoOperativoSnapshot).border]">
                            <option value="sin-solicitar">Sin Solicitar</option>
                            <option value="solicitado">Solicitado</option>
                            <option value="confirmado">Confirmado</option>
                            <option value="reconfirmado">Reconfirmado</option>
                            <option value="pendiente-pago">Pendiente Pago</option>
                          </select>
                          <i class="fas absolute right-2.5 top-1/2 -translate-y-1/2 pointer-events-none text-[10px]"
                             :class="[getEstadoOperativoConfig(store.dataActiva.estadoOperativoSnapshot).icon, getEstadoOperativoConfig(store.dataActiva.estadoOperativoSnapshot).text]"></i>
                        </div>
                      </div>


                      <div>
                        <label class="text-[9px] font-bold text-slate-500 uppercase mb-1 ml-1 flex items-center justify-between">
                          <span>Día de Vencimiento</span>
                          <i class="far fa-calendar-alt text-red-500"></i>
                        </label>
                        <input v-model="store.dataActiva.fechaLimitePago"
                               type="date"
                               class="w-full bg-white border border-slate-300 text-red-600 rounded-lg px-3 py-2 text-xs font-bold focus:ring-2 focus:ring-red-500 outline-none shadow-sm" />
                      </div>

                      <div>
                        <label class="text-[9px] font-bold text-slate-500 uppercase mb-1 ml-1 flex items-center justify-between">
                          <span>Nota de Pago</span>
                          <i class="fas fa-sticky-note text-amber-500"></i>
                        </label>
                        <input v-model="store.dataActiva.condicionesPagoSnapshot"
                               type="text"
                               class="w-full bg-white border border-slate-300 text-amber-600 rounded-lg px-3 py-2 text-xs font-bold focus:ring-2 focus:ring-amber-500 outline-none shadow-sm placeholder-slate-300"
                               placeholder="Ej: Depósito BCP / 15 días antes..." />
                      </div>
                    </div>
                  </template>
                  <div v-else class="mt-5 pt-4 border-t border-slate-200 text-center py-6 text-slate-400">
                    <i class="fas fa-user-slash text-2xl mb-2 opacity-40"></i>
                    <p class="text-[10px] font-bold uppercase tracking-widest">Asigna un proveedor para gestionar la reserva</p>
                  </div>


                </div>
              </div>


            </div>
          </div>
        </div>

        <div v-if="store.inspectorActivo !== 'resumen' && store.cotizacion"
             @click="isTotalsDrawerOpen = true"
             class="absolute bottom-0 w-full bg-slate-900 border-t border-slate-700/50 px-6 py-4 flex justify-between items-center shrink-0 shadow-[0_-10px_20px_-5px_rgba(0,0,0,0.4)] z-40 cursor-pointer hover:bg-slate-800 active:bg-slate-950 transition-colors">

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

          <div class="flex items-center gap-3">
            <div class="px-4 flex flex-col items-end">
              <span class="text-[8px] font-black text-emerald-400 uppercase tracking-widest leading-none mb-0.5">Venta Sugerida</span>
              <span class="text-xl font-black text-emerald-400 leading-none">{{ formatMoneda(store.ventaSugerida, store.cotizacion.monedaGlobal) }}</span>
            </div>

            <button @click.stop="handleGuardar"
                    class="md:hidden w-9 h-9 rounded-full bg-[#E07845] hover:bg-[#c96636] active:scale-95 flex items-center justify-center shadow-md transition-all shrink-0">
              <i class="fas fa-save text-sm text-white"></i>
            </button>
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
      <button @click="router.push('/cotizacion')" class="mt-8 px-6 py-3 bg-slate-900 hover:bg-slate-800 text-white rounded-xl font-bold shadow-md transition-all">
        <i class="fas fa-arrow-left mr-2"></i> Volver al Dashboard
      </button>
    </div>

  </div>

  <Teleport to="body">
    <Transition name="fade-scale">
      <div v-if="store.isSegmentEditorOpen && store.cotizacion" class="fixed inset-0 z-1000 bg-slate-900/60 backdrop-blur-sm flex items-center justify-center md:p-8">
        <div class="bg-[#F8FAFC] w-full h-full md:max-w-6xl md:max-h-[90vh] md:rounded-3xl shadow-2xl flex flex-col overflow-hidden border border-slate-200">
          <header class="bg-teal-600 text-white px-6 py-4 flex justify-between items-center">
            <div>
              <h2 class="font-black text-lg flex items-center gap-2"><i class="fas fa-book-open"></i> Constructor de Storytelling</h2>
              <p class="text-[11px] font-bold text-teal-200 uppercase tracking-widest mt-1">Servicio: {{ store.getI18nText(store.dataActiva?.nombreSnapshot as any, store.cotizacion.idiomaEdicion) }}</p>
            </div>
            <button @click="store.cerrarEditorSegmentos()" class="w-8 h-8 rounded-full bg-teal-500 hover:bg-teal-400 flex items-center justify-center transition-colors"><i class="fas fa-times"></i></button>
          </header>

          <div class="flex flex-1 overflow-hidden flex-col md:flex-row">

            <aside class="w-full md:w-1/3 bg-white border-b md:border-b-0 md:border-r border-slate-200 flex flex-col shadow-sm z-20 shrink-0 transition-all duration-300"
                   :class="activeAccordion === 'pool' ? 'flex-1 min-h-0' : 'h-auto'">

              <div class="md:hidden flex justify-between items-center px-4 py-4 bg-teal-50 hover:bg-teal-100 cursor-pointer transition-colors border-b border-teal-200"
                   @click="activeAccordion = 'pool'">
                <span class="text-xs font-black text-teal-700 uppercase tracking-widest"><i class="fas fa-layer-group mr-2"></i> Pool de Segmentos / Plantillas</span>
                <i class="fas text-teal-600 transition-transform" :class="activeAccordion === 'pool' ? 'fa-chevron-up' : 'fa-chevron-down'"></i>
              </div>

              <div class="flex-1 flex flex-col min-h-0 overflow-hidden" :class="{'hidden md:flex': activeAccordion !== 'pool'}">
                <div class="p-3 md:p-5 border-b border-slate-100 bg-slate-50 shrink-0">
                  <label class="block text-[10px] font-black text-teal-600 uppercase tracking-widest mb-2">1. Cargar Plantilla</label>
                  <div class="flex gap-2">
                    <SearchableSelect
                        v-model="plantillaSeleccionada"
                        :options="opcionesPlantillas"
                        placeholder="Elegir itinerario..."
                    />
                    <button @click="handleAplicarPlantilla"
                            :disabled="store.isLoading || !puedeAplicarPlantilla"
                            :title="!puedeAplicarPlantilla ? 'Ya hay párrafos en este servicio. Vacía el panel para aplicar una plantilla.' : ''"
                            class="bg-teal-600 text-white px-3 md:px-4 py-2 rounded-lg text-xs font-bold transition-colors shadow-sm flex items-center gap-2"
                            :class="(store.isLoading || !puedeAplicarPlantilla) ? 'opacity-50 cursor-not-allowed' : 'hover:bg-teal-700'">
                      <i v-if="store.isLoading" class="fas fa-spinner fa-spin"></i> Aplicar
                    </button>
                  </div>
                </div>

                <div class="p-3 md:p-5 flex-1 overflow-y-auto bg-white flex flex-col">
                  <label class="block text-[10px] font-black text-slate-400 uppercase tracking-widest mb-2 md:mb-3">2. Pool de Segmentos Libres</label>
                  <div class="mb-3 md:mb-4 shrink-0">
                    <input v-model="filtroSegmentos" type="text" placeholder="🔍 Buscar por ID o Título..."
                           class="w-full bg-slate-50 border border-slate-200 rounded-lg px-3 py-2 text-xs font-bold text-slate-700 focus:ring-2 focus:ring-teal-500 outline-none shadow-inner">
                  </div>
                  <div class="space-y-2 md:space-y-3 overflow-y-auto flex-1 pb-2">
                    <div v-for="(seg, idx) in poolFiltrado"
                         :key="store.extractIdStr(seg) || idx"
                         class="relative bg-white border-2 border-dashed border-slate-200 p-2 md:p-3 rounded-xl hover:border-teal-300 hover:bg-teal-50 transition-all flex gap-3 shadow-sm group items-center md:items-start"
                         @mouseenter="tooltipPoolActivo = store.extractIdStr(seg) || String(idx)"
                         @mouseleave="tooltipPoolActivo = null"
                         @pointerdown="onPoolPointerDown($event, store.extractIdStr(seg) || String(idx))"
                         @pointerup="onPoolPointerUp"
                         @pointercancel="onPoolPointerUp">
                      <div class="flex-1 min-w-0">
                        <div class="text-[9px] font-black text-teal-500 uppercase tracking-widest mb-0.5 truncate">{{ seg.nombreInterno || 'SIN CÓDIGO' }}</div>
                        <h4 class="text-xs font-bold text-slate-700 leading-tight mb-1 truncate md:whitespace-normal">{{ store.getI18nText(seg.titulo as any, store.cotizacion?.idiomaEdicion || 'es') }}</h4>
                        <div class="text-[10px] text-slate-500 line-clamp-1 md:line-clamp-2 prose-sm prose-p:my-0" v-html="store.getI18nText(seg.contenido as any, store.cotizacion?.idiomaEdicion || 'es')"></div>
                      </div>
                      <button @click="prepararInsercion(seg)" class="text-teal-600 hover:bg-teal-200 bg-teal-50 md:bg-transparent md:hover:bg-teal-50 px-3 md:px-2 py-2 md:py-1 h-fit rounded-lg transition-colors shrink-0 md:opacity-0 group-hover:opacity-100 border md:border-none border-teal-100"><i class="fas fa-plus"></i></button>
                    </div>
                  </div>
                </div>
              </div>
            </aside>

            <main class="w-full md:flex-1 bg-[#F8FAFC] flex flex-col shrink-0 transition-all duration-300"
                  :class="activeAccordion === 'parrafos' ? 'flex-1 min-h-0' : 'h-auto'">

              <div class="md:hidden flex justify-between items-center px-4 py-4 bg-slate-200 hover:bg-slate-300 cursor-pointer transition-colors border-b border-slate-300"
                   @click="activeAccordion = 'parrafos'">
                <span class="text-xs font-black text-slate-700 uppercase tracking-widest"><i class="fas fa-stream mr-2"></i> Párrafos de la Cotización</span>
                <i class="fas text-slate-600 transition-transform" :class="activeAccordion === 'parrafos' ? 'fa-chevron-up' : 'fa-chevron-down'"></i>
              </div>

              <div class="flex-1 overflow-y-auto p-4 md:p-8" :class="{'hidden md:block': activeAccordion !== 'parrafos'}">
                <div class="max-w-3xl mx-auto pb-20 relative">

                  <div class="flex flex-col md:flex-row md:items-center justify-between gap-4 mb-6">
                    <h3 class="text-sm font-black text-slate-700 uppercase tracking-widest hidden md:flex items-center"><i class="fas fa-stream mr-2"></i> Párrafos en la Cotización</h3>
                    <div class="flex items-center gap-3 bg-white px-3 py-2 rounded-xl border border-slate-200 shadow-sm w-fit ml-auto">

                      <button @click="handleActualizarTextos"
                              :disabled="isActualizandoTextos"
                              class="flex items-center gap-2 text-[10px] font-black text-teal-600 uppercase tracking-widest hover:text-teal-700 transition-colors pr-3 border-r border-slate-200 disabled:opacity-50"
                              title="Actualizar textos, notas y fotos desde el catálogo maestro">
                        <i class="fas fa-sync-alt" :class="{'fa-spin': isActualizandoTextos}"></i> Actualizar
                      </button>
                      <label class="text-[10px] font-black text-slate-600 uppercase tracking-widest cursor-pointer select-none" @click="expandirEditores = !expandirEditores">Expandir Textos</label>
                      <button @click="expandirEditores = !expandirEditores"
                              :class="expandirEditores ? 'bg-teal-500' : 'bg-slate-300'"
                              class="relative inline-flex h-5 w-9 items-center rounded-full transition-colors focus:outline-none shadow-inner">
                        <span :class="expandirEditores ? 'translate-x-4' : 'translate-x-1'"
                              class="inline-block h-3 w-3 transform rounded-full bg-white transition-transform shadow-sm"></span>
                      </button>
                    </div>
                  </div>

                  <div v-if="!store.dataActiva?.cotsegmentos?.length" class="border-2 border-dashed border-slate-300 rounded-3xl p-12 text-center text-slate-400 flex flex-col items-center">
                    <i class="fas fa-align-center text-4xl mb-4 opacity-50"></i>
                    <p class="text-sm font-bold uppercase tracking-widest">El servicio no tiene textos</p>
                  </div>

                  <div v-else class="space-y-0 relative">
                    <div class="absolute left-3.75 top-4 bottom-4 w-0.5 bg-slate-200 z-0 hidden md:block"></div>

                    <template v-for="(cotSeg, idx) in segmentosOrdenadosVisualmente" :key="cotSeg.id">
                      <div v-if="idx === 0 || cotSeg.dia !== segmentosOrdenadosVisualmente[idx-1].dia" class="mb-4 mt-6 first:mt-2 text-teal-700 font-black text-sm border-b border-teal-200 pb-1 flex items-center justify-between">
                        <span><i class="far fa-calendar-alt mr-1"></i> DÍA RELATIVO {{ cotSeg.dia }}</span>
                        <span class="text-[10px] font-bold text-teal-600 bg-teal-50 px-2 py-0.5 rounded">{{ formatFecha(cotSeg.fechaAbsoluta) }}</span>
                      </div>

                      <div :data-segment-id="cotSeg.id"
                           class="relative z-10 flex gap-2 md:gap-4 items-start group mb-4 transition-all"
                           :class="[
                             dragSegId === cotSeg.id ? 'opacity-40 scale-[0.98]' : '',
                             dragOverSegId === cotSeg.id && dragSegId !== cotSeg.id ? 'ring-2 ring-teal-400 rounded-2xl' : ''
                           ]">

                        <div class="flex flex-col items-center gap-1 mt-1 shrink-0 bg-white border border-slate-200 rounded-lg p-1 shadow-sm">
                          <div class="w-6 h-6 md:w-8 md:h-8 rounded-full bg-teal-50 text-teal-600 flex items-center justify-center font-black text-[10px] md:text-xs">{{ cotSeg.orden }}</div>
                          <div class="text-slate-300 hover:text-teal-500 cursor-grab active:cursor-grabbing select-none px-2 py-1"
                               style="touch-action: none;"
                               @pointerdown="onSegmentPointerDown($event, cotSeg)"
                               @pointermove="onSegmentPointerMove"
                               @pointerup="onSegmentPointerUp"
                               @pointercancel="onSegmentPointerUp">
                            <i class="fas fa-grip-vertical"></i>
                          </div>
                        </div>

                        <div class="flex-1 bg-white border border-slate-200 shadow-sm rounded-2xl overflow-hidden min-w-0">

                          <div class="bg-slate-50 px-3 md:px-4 py-3 border-b border-slate-200 flex flex-col lg:flex-row justify-between items-start lg:items-center gap-3">
                            <div class="flex items-center gap-2 bg-white border border-slate-200 rounded-lg px-2 py-1 shadow-sm shrink-0">
                              <label class="text-[9px] md:text-[10px] font-black text-teal-600 uppercase tracking-widest whitespace-nowrap">Día Relativo</label>
                              <input type="number" min="1"
                                     v-model="cotSeg.dia"
                                     @change="store.onSegmentoDiaChange(store.dataActiva.id, cotSeg.id, cotSeg.dia)"
                                     class="w-12 bg-slate-50 border border-slate-300 rounded px-1 py-1 text-xs font-black text-center outline-none focus:ring-2 focus:ring-teal-500 text-slate-800">
                            </div>

                            <div class="flex items-center gap-2 w-full lg:w-auto min-w-0">
                              <input :value="store.getI18nText(cotSeg.nombreSnapshot, store.cotizacion?.idiomaEdicion || 'es')"
                                     @input="e => { if(store.cotizacion) store.setI18nText(cotSeg.nombreSnapshot, store.cotizacion.idiomaEdicion, (e.target as HTMLInputElement).value) }"
                                     class="bg-transparent text-[11px] md:text-xs font-black text-slate-700 uppercase outline-none flex-1 w-full truncate" placeholder="Título..." />

                              <button @click="cotSeg.sobreescribirTraduccion = !cotSeg.sobreescribirTraduccion"
                                      class="transition-colors px-2 py-1.5 rounded text-[10px] font-bold border flex items-center gap-1 shadow-sm shrink-0"
                                      :class="cotSeg.sobreescribirTraduccion ? 'bg-orange-100 text-orange-600 border-orange-300' : 'bg-white text-slate-400 border-slate-200 hover:bg-slate-100'" title="Forzar traducción del párrafo al guardar">
                                <i class="fas fa-language"></i> <span class="hidden xl:inline" v-if="cotSeg.sobreescribirTraduccion">Auto-Traducir</span>
                              </button>

                              <button @click="store.removerCotSegmento(cotSeg.id)" class="bg-white border border-slate-200 text-slate-400 hover:text-red-500 hover:border-red-200 transition-colors ml-1 p-1.5 rounded shadow-sm shrink-0">
                                <i class="fas fa-trash-alt text-sm"></i>
                              </button>
                            </div>
                          </div>

                          <div v-show="expandirEditores" class="p-3 md:p-4 bg-white">
                            <WysiwygEditor
                                :model-value="store.getI18nText(cotSeg.contenidoSnapshot, store.cotizacion?.idiomaEdicion || 'es')"
                                @update:model-value="(event) => { if(store.cotizacion) store.setI18nText(cotSeg.contenidoSnapshot, store.cotizacion.idiomaEdicion, event) }"
                            />

                            <div v-if="(cotSeg.notasSnapshot && cotSeg.notasSnapshot.length > 0) || (cotSeg.imagenesSnapshot && cotSeg.imagenesSnapshot.length > 0)" class="mt-6 pt-4 md:mt-8 md:pt-6 border-t border-slate-200 grid grid-cols-1 md:grid-cols-2 gap-4 md:gap-6">
                              <div v-if="cotSeg.notasSnapshot && cotSeg.notasSnapshot.length > 0">
                                <h4 class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-3"><i class="fas fa-clipboard-list mr-1"></i> Recomendaciones</h4>
                                <div class="flex flex-col gap-3">
                                  <div v-for="[tipo, notasGrupo] in agruparNotasPorTipo(cotSeg.notasSnapshot)" :key="tipo">
                                    <div class="flex items-center gap-1.5 text-[9px] font-black text-slate-400 uppercase tracking-widest mb-1.5">
                                      <i class="fas" :class="getTipoNotaUI(tipo).icon"></i> {{ tipo }}
                                    </div>
                                    <div class="flex flex-wrap gap-2">
                                      <div v-for="nota in notasGrupo" :key="nota.id"
                                           @click="abrirModalNota(nota)"
                                           class="bg-white border border-slate-200 rounded-lg shadow-sm flex items-stretch overflow-hidden hover:border-teal-400 transition-all cursor-pointer group max-w-full">
                                        <div :class="[getTipoNotaUI(tipo).bg, getTipoNotaUI(tipo).text]" class="px-2 py-1 md:px-2.5 md:py-1.5 flex items-center justify-center">
                                          <i class="fas text-[10px] md:text-xs" :class="getTipoNotaUI(tipo).icon"></i>
                                        </div>
                                        <div class="px-2 py-1 md:px-2.5 md:py-1.5 flex-1 min-w-0 flex flex-col justify-center">
                                          <span class="text-[9px] md:text-[10px] font-bold text-slate-700 block truncate w-full max-w-30 md:max-w-40">
                                            {{ store.getI18nText(nota.titulo as any, store.cotizacion?.idiomaEdicion || 'es') || nota.nombreInterno }}
                                          </span>
                                        </div>
                                        <button @click.stop="cotSeg.notasSnapshot.splice(cotSeg.notasSnapshot.indexOf(nota), 1)"
                                                class="px-2 bg-slate-50 border-l border-slate-100 text-slate-300 hover:text-red-500 hover:bg-red-50 transition-colors">
                                          <i class="fas fa-times text-[10px]"></i>
                                        </button>
                                      </div>
                                    </div>
                                  </div>
                                </div>
                              </div>

                              <div v-if="cotSeg.imagenesSnapshot && cotSeg.imagenesSnapshot.length > 0">
                                <h4 class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-3"><i class="fas fa-images mr-1"></i> Galería</h4>
                                <div class="flex gap-2 overflow-x-auto pb-2 custom-scrollbar">
                                  <div v-for="(img, iIdx) in cotSeg.imagenesSnapshot" :key="iIdx" class="relative w-14 h-14 md:w-16 md:h-16 rounded-xl overflow-hidden border border-slate-200 shrink-0 group shadow-sm">
                                    <img :src="img.imageUrl || '/images/placeholder.jpg'" class="w-full h-full object-cover transition-transform group-hover:scale-110"  alt="image"/>
                                    <button @click="cotSeg.imagenesSnapshot.splice(iIdx, 1)" class="absolute top-1 right-1 bg-white/90 hover:bg-red-500 hover:text-white w-4 h-4 md:w-5 md:h-5 rounded-full flex items-center justify-center text-[9px] md:text-[10px] text-slate-600 transition-colors md:opacity-0 group-hover:opacity-100 shadow-sm" title="Quitar imagen">
                                      <i class="fas fa-times"></i>
                                    </button>
                                  </div>
                                </div>
                              </div>
                            </div>
                          </div>
                        </div>
                      </div>
                    </template>
                  </div>
                </div>
              </div>
            </main>

          </div>
        </div>
      </div>
    </Transition>

    <Transition name="slide-up">
      <div v-if="isTotalsDrawerOpen" class="fixed inset-0 z-1200 flex flex-col justify-end bg-slate-900/60 backdrop-blur-sm md:items-end md:justify-start" @click.self="isTotalsDrawerOpen = false">

        <div class="bg-slate-50 w-full md:w-105 md:h-screen rounded-t-3xl md:rounded-none shadow-2xl flex flex-col max-h-[85vh] md:max-h-full overflow-hidden relative transition-transform">

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
            <button @click="isReporteOpen = true"
                    class="w-full py-3 bg-slate-800 hover:bg-slate-900 text-white rounded-xl text-xs font-black uppercase tracking-widest shadow-sm">
              <i class="fas fa-file-invoice-dollar mr-2"></i> Reporte financiero completo
            </button>

            <div class="space-y-3 pt-2">
              <h3 class="text-[9px] font-black text-slate-400 uppercase tracking-widest ml-1"><i class="fas fa-users mr-1"></i> Análisis por Perfil</h3>

              <div v-for="clase in store.resumenFinanciero?.clasesPasajeros" :key="clase.tipo"
                   class="bg-white border border-slate-200 rounded-2xl p-4 shadow-sm"
                   :class="clase.tipo.includes('anomalo') ? 'border-red-300' : ''">
                <div class="flex justify-between items-start mb-3">
                  <div>
                    <span :class="clase.tipo.includes('anomalo') ? 'bg-red-100 text-red-700' : 'bg-teal-100 text-teal-700'" class="px-2 py-0.5 rounded text-[10px] font-black uppercase">
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
      <div v-if="modalNota.isOpen" class="fixed inset-0 z-1300 bg-slate-900/60 backdrop-blur-sm flex items-center justify-center p-4" @click.self="modalNota.isOpen = false">
        <div class="bg-white w-full max-w-lg rounded-2xl shadow-2xl overflow-hidden border border-slate-200 flex flex-col max-h-[85vh]">
          <div :class="[getTipoNotaUI(modalNota.nota?.tipo).bg, getTipoNotaUI(modalNota.nota?.tipo).text]" class="px-5 py-4 flex justify-between items-center border-b border-black/5 shrink-0">
            <h3 class="font-black text-sm uppercase tracking-widest flex items-center gap-2">
              <i class="fas" :class="getTipoNotaUI(modalNota.nota?.tipo).icon"></i>
              {{ modalNota.nota?.tipo }}
            </h3>
            <button @click="modalNota.isOpen = false" class="hover:opacity-70 transition-opacity"><i class="fas fa-times"></i></button>
          </div>
          <div class="p-6 overflow-y-auto flex-1">
            <h4 class="text-lg font-black text-slate-800 mb-4 leading-tight">
              {{ store.getI18nText(modalNota.nota?.titulo as any, store.cotizacion?.idiomaEdicion || 'es') || modalNota.nota?.nombreInterno }}
            </h4>
            <div class="prose prose-sm max-w-none text-slate-600 leading-relaxed"
                 v-html="store.getI18nText(modalNota.nota?.contenido as any, store.cotizacion?.idiomaEdicion || 'es')">
            </div>
          </div>
          <div class="bg-slate-50 px-5 py-3 border-t border-slate-100 flex justify-end shrink-0">
            <button @click="modalNota.isOpen = false" class="px-5 py-2 bg-slate-800 hover:bg-slate-900 text-white text-xs font-bold rounded-lg shadow-sm transition-colors">Cerrar</button>
          </div>
        </div>
      </div>
    </Transition>

    <Transition name="fade-scale">
      <div v-if="modalInsercion.isOpen" class="fixed inset-0 z-1400 bg-slate-900/60 backdrop-blur-sm flex items-center justify-center p-4" @click.self="modalInsercion.isOpen = false">
        <div class="bg-white w-full max-w-md rounded-2xl shadow-2xl overflow-hidden border border-slate-200">
          <div class="bg-teal-600 text-white px-5 py-4 flex justify-between items-center">
            <h3 class="font-black text-sm uppercase tracking-widest"><i class="fas fa-arrows-alt-v mr-2"></i>¿Dónde ubicar el segmento?</h3>
            <button @click="modalInsercion.isOpen = false" class="hover:opacity-70"><i class="fas fa-times"></i></button>
          </div>
          <div class="p-5 space-y-4">
            <p class="text-xs font-bold text-slate-500">
              Insertando: <span class="text-teal-600">{{ store.getI18nText(modalInsercion.segmentoMaestro?.titulo as any, store.cotizacion?.idiomaEdicion || 'es') || modalInsercion.segmentoMaestro?.nombreInterno }}</span>
            </p>

            <div class="space-y-2">
              <label class="flex items-center gap-2 p-3 rounded-xl border cursor-pointer" :class="opcionInsercion === 'append' ? 'border-teal-400 bg-teal-50' : 'border-slate-200'">
                <input type="radio" value="append" v-model="opcionInsercion" class="accent-teal-600">
                <span class="text-xs font-bold text-slate-700">Agregar al final del itinerario</span>
              </label>
              <label class="flex items-center gap-2 p-3 rounded-xl border cursor-pointer" :class="opcionInsercion === 'insert' ? 'border-teal-400 bg-teal-50' : 'border-slate-200'">
                <input type="radio" value="insert" v-model="opcionInsercion" class="accent-teal-600">
                <span class="text-xs font-bold text-slate-700">Insertar después de un párrafo existente</span>
              </label>
              <label class="flex items-center gap-2 p-3 rounded-xl border cursor-pointer" :class="opcionInsercion === 'replace' ? 'border-teal-400 bg-teal-50' : 'border-slate-200'">
                <input type="radio" value="replace" v-model="opcionInsercion" class="accent-teal-600">
                <span class="text-xs font-bold text-slate-700">Reemplazar un párrafo existente</span>
              </label>
            </div>

            <div v-if="opcionInsercion !== 'append'">
              <label class="block text-[10px] font-black text-slate-500 uppercase mb-1.5 ml-1">
                {{ opcionInsercion === 'insert' ? 'Insertar después de:' : 'Párrafo a reemplazar:' }}
              </label>
              <select v-model="targetSegmentoId" class="w-full bg-slate-50 border border-slate-300 rounded-lg px-3 py-2 text-xs font-bold outline-none focus:ring-2 focus:ring-teal-500">
                <option v-for="(cotSeg, idx) in store.dataActiva?.cotsegmentos || []" :key="cotSeg.id" :value="cotSeg.id">
                  {{ idx + 1 }}. {{ store.getI18nText(cotSeg.nombreSnapshot as any, store.cotizacion?.idiomaEdicion || 'es') || 'Sin título' }}
                </option>
              </select>
            </div>
          </div>
          <div class="bg-slate-50 px-5 py-3 border-t border-slate-100 flex justify-end gap-2">
            <button @click="modalInsercion.isOpen = false" class="px-4 py-2 text-xs font-bold text-slate-500 hover:text-slate-700">Cancelar</button>
            <button @click="confirmarInsercion" class="px-5 py-2 bg-teal-600 hover:bg-teal-700 text-white text-xs font-bold rounded-lg shadow-sm">Confirmar</button>
          </div>
        </div>
      </div>
    </Transition>

    <Transition name="fade-scale">
      <div v-if="isReporteOpen" class="fixed inset-0 z-1500 bg-slate-900/60 backdrop-blur-sm flex items-center justify-center p-4 md:p-8"
           @click.self="isReporteOpen = false">
        <div class="bg-white w-full max-w-6xl h-full max-h-[90vh] rounded-3xl shadow-2xl flex flex-col overflow-hidden border border-slate-200">
          <header class="bg-slate-900 text-white px-6 py-4 flex justify-between items-center shrink-0">
            <h2 class="font-black text-lg"><i class="fas fa-file-invoice-dollar mr-2"></i> Reporte financiero</h2>
            <button @click="isReporteOpen = false" class="w-8 h-8 rounded-full bg-slate-800 hover:bg-slate-700 flex items-center justify-center"><i class="fas fa-times"></i></button>
          </header>
          <div class="flex-1 overflow-y-auto p-6 md:p-8 bg-[#F8FAFC]">
            <ResumenClasificacion />
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


</style>

<style>
:root {
  --dp-border-radius: 0.5rem;
  --dp-primary-color: #0d9488; /* Teal 600 */
  --dp-font-family: inherit;
  --dp-font-size: 0.75rem;
}
</style>