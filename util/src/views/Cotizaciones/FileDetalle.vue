<script setup lang="ts">
import {ref, onMounted, onUnmounted, watch, computed, nextTick} from 'vue';
import { useRoute, useRouter, onBeforeRouteLeave } from 'vue-router';
import { useVolverAtras } from '@/composables/useVolverAtras';
import MaskedDateInput from '@/components/MaskedDateInput.vue';   // ajusta ruta
import SearchableSelect from '@/components/SearchableSelect.vue';
import ContactoDeIdentidad from '@/components/common/ContactoDeIdentidad.vue';
import { uuidDe } from '@/services/hydra';
import { formatearTelefono } from '@/utils/telefono';
// El itinerario se escribe en el canónico de la casa —el mismo del chat, que normaliza el Markdown
// que la gente teclea— y se pinta con el mismo formateador, que escapa el HTML antes de marcar.
import { formatoAHtml } from '@/utils/formatoDeTexto';
import PlanOperacionModal from '@/components/operacion/PlanOperacionModal.vue';
import { apiClient } from '@/services/apiClient';
import { useCotizacionFileStore } from '@/stores/cotizacion/fileStore';
import { getUrls } from '@/services/apiClient';
import { ESTADO_FILE_LABELS } from '@/types/cotizacionEditorModel';

import type { ApiPais } from '@/types/maestroModel';

import {
  getArchivoLabel, ARCHIVO_TIPO_LABELS,
  getSexoLabel, SEXO_LABELS,
  getDocIdLabel, DOCUMENTO_IDENTIDAD_LABELS, GRUPO_TIPO_LABELS, PASAJERO_TIPO_CONFIG, FILE_MODO_CONFIG,
  type ApiFileGrupo,
  type ApiCotizacionFile,
  type ApiCotizacionFilepasajero,
  type ApiCotizacionFilearchivo,
  type ApiCotizacionVersion
} from '@/types/fileDetalleModel';

const linkCopiado = ref(false);

defineProps<{
  id?: string;
}>();

const route = useRoute();
const router = useRouter();
const volverAtras = useVolverAtras();
const fileStore = useCotizacionFileStore();

const isLoading = ref(true);
const file = ref<ApiCotizacionFile>({} as ApiCotizacionFile);
const isSavingFile = ref(false);

// ============================================================================
// 🔥 GUARDIÁN DE CAMBIOS SIN GUARDAR (DIRTY CHECK)
// ============================================================================
const isDirty = ref(false);
let watchActivo = false;

// ============================================================================
// CATÁLOGOS Y ENUMS
// ============================================================================
const catalogos = ref({
  paises: [] as ApiPais[],
});

// País como opciones {value,label} para el buscador
const paisOptions = computed(() =>
    catalogos.value.paises
        .filter(p => (p['@id'] || p.id) && p.nombre)
        .map(p => ({ value: (p['@id'] ?? p.id) as string, label: p.nombre as string }))
);

// ============================================================================
// IDIOMAS (revisión de traducciones AutoTranslate)
// ============================================================================
const idiomaActivo = ref('es');
const idiomaDocDropdown = ref(false);

/** Texto i18n en el idioma activo de la vista, con fallback es → primero. */
const t18 = (arr?: { language?: string; content?: string }[] | null): string => {
  if (!Array.isArray(arr) || !arr.length) return '';
  const m = arr.find(i => i.language === idiomaActivo.value)
      || arr.find(i => i.language === 'es')
      || arr[0];
  return m?.content || '';
};

/** Resumen HTML → texto plano corto para previews. */
const resumenPreview = (arr?: { language?: string; content?: string }[] | null): string =>
  t18(arr).replace(/<[^>]*>/g, ' ').replace(/\s+/g, ' ').trim();
const idiomaFileDropdown = ref(false);
const idiomasDisponibles = computed(() => fileStore.idiomasDisponibles);

// ============================================================================
// CONTACTO — ya no se pinta aquí
//
// El teléfono y el correo del expediente son la semilla de la identidad; se enseñan (y se
// editan) desde `ContactoDeIdentidad`, que los resuelve por el hilo. Aquí sólo queda el id
// que ese componente necesita.
// ============================================================================
const fileId = computed<string | null>(() => uuidDe(file.value ?? null));

// ============================================================================
// PAÍS DEL EXPEDIENTE
// ============================================================================
const paisFileIri = ref('');

// ============================================================================
// LINKS VISTA CLIENTE  (pax + /file/ + localizador [+ /v/N])
// ============================================================================
const linkPublico = computed(() => {
  if (!file.value?.localizador) return '';
  return `${getUrls().pax}/file/${file.value.localizador}`;
});

const linkPublicoVersion = (version?: number) => {
  if (!file.value?.localizador) return '';
  const base = `${getUrls().pax}/file/${file.value.localizador}`;
  return version ? `${base}/v/${version}` : base;
};

const copiarLink = async () => {
  if (!linkPublico.value) return;
  try {
    await navigator.clipboard.writeText(linkPublico.value);
    linkCopiado.value = true;
    setTimeout(() => { linkCopiado.value = false; }, 2000);
  } catch {
    alert('No se pudo copiar. Copia manualmente: ' + linkPublico.value);
  }
};

// ============================================================================
// HELPER NOMBRE DOCUMENTO  (formato AutoTranslate: [{content, language}])
// ============================================================================
const getDocNombre = (doc: ApiCotizacionFilearchivo | null | undefined, lang = idiomaActivo.value): string => {
  if (!doc?.nombre) return '';
  if (Array.isArray(doc.nombre)) {
    return doc.nombre.find((n) => n.language === lang)?.content
        || doc.nombre.find((n) => n.language === 'es')?.content
        || doc.nombre[0]?.content
        || '';
  }
  // Formato legacy: `nombre` como mapa `{es: '…', en: '…'}`. El backend ya no lo
  // emite (ahora es AutoTranslate), pero puede quedar en documentos antiguos, así
  // que el fallback se conserva — de ahí el estrechamiento manual.
  const legado = doc.nombre as unknown as Record<string, string> | string;
  return typeof legado === 'object' ? (legado[lang] || legado.es || '') : String(legado);
};

const editandoVersion = ref<string | null>(null);
const versionTemp = ref<number>(1);
const eliminandoItem = ref<string | null>(null);
const clonandoItem = ref<string | null>(null);

const iniciarEdicionVersion = (cot: ApiCotizacionVersion) => {
  editandoVersion.value = cot['@id'] || cot.id || '';
  versionTemp.value = cot.version;
};

const guardarVersion = async (cot: ApiCotizacionVersion) => {
  const iri = cot['@id'] || `/platform/sales/cotizacions/${extractIdStr(cot.id)}`;
  const success = await fileStore.updateCotizacionVersion(iri, versionTemp.value);
  if (success) {
    cot.version = versionTemp.value;
    editandoVersion.value = null;
  } else {
    alert(fileStore.error || 'Error al actualizar versión.');
  }
};

const eliminarVersion = async (cot: ApiCotizacionVersion) => {
  if (!confirm(`¿Eliminar la Versión ${cot.version}? Esta acción no se puede deshacer.`)) return;
  const iri = cot['@id'] || `/platform/sales/cotizacions/${extractIdStr(cot.id)}`;
  eliminandoItem.value = iri;
  const success = await fileStore.deleteCotizacion(iri);
  if (success) await cargarFile();
  else alert(fileStore.error || 'Error al eliminar la versión.');
  eliminandoItem.value = null;
};

/**
 * Clona una versión existente delegando la llamada al store.
 * Al completarse, refresca la vista del expediente para mostrar la nueva tarjeta.
 */
// ── Versiones vivas y sus fotos del pasado ─────────────────────────────────
//
// Un histórico conserva a propósito el número de la versión de la que salió, así que si se
// listaran juntos habría dos tarjetas diciendo «V1» sin forma de saber cuál es la buena. Cuelgan
// de la suya, plegados.
const versionesVivas = computed<ApiCotizacionVersion[]>(
  () => (file.value?.cotizaciones ?? []).filter((c: ApiCotizacionVersion) => c.estado !== 'historico')
);

const historicosDe = (cot: ApiCotizacionVersion): ApiCotizacionVersion[] => {
  const id = extractIdStr(cot.id || cot['@id']);
  if (!id) return [];

  return (file.value?.cotizaciones ?? []).filter(
    (c: ApiCotizacionVersion) => c.estado === 'historico' && extractIdStr(c.derivadaDeId ?? '') === id
  );
};

const historicosAbiertos = ref<Set<string>>(new Set());

const alternarHistoricos = (id: string): void => {
  const abiertos = new Set(historicosAbiertos.value);
  if (abiertos.has(id)) { abiertos.delete(id); } else { abiertos.add(id); }
  historicosAbiertos.value = abiertos;
};

const guardandoHistorico = ref<string | null>(null);

/**
 * Congela una foto ANTES de tocar la cotización.
 *
 * ⚠️ No es clonar. Clonar crea la versión SIGUIENTE y deja ésta atrás, y eso después de confirmar
 * obliga a reemitir todas las órdenes: cuelgan de los componentes de esta cotización, y la copia
 * nace con componentes nuevos. Aquí la copia es el pasado y ésta sigue siendo la misma para
 * Operaciones.
 */
const guardarHistorico = async (cot: ApiCotizacionVersion) => {
  const idStr = extractIdStr(cot.id || cot['@id']);
  if (!idStr) return;

  if (!confirm(
    `¿Guardar una foto de la Versión ${cot.version} tal como está ahora?\n\n`
    + 'Queda como histórico y esta versión sigue viva: sus órdenes de servicio no se mueven.'
  )) return;

  guardandoHistorico.value = idStr;
  const ok = await fileStore.guardarHistorico(idStr);

  if (ok) {
    historicosAbiertos.value = new Set([...historicosAbiertos.value, idStr]);
    await cargarFile();
  } else {
    alert(fileStore.error || 'No se pudo guardar el histórico.');
  }

  guardandoHistorico.value = null;
};

const clonarVersion = async (cot: ApiCotizacionVersion) => {
  const idStr = extractIdStr(cot.id || cot['@id']);

  if (!idStr) {
    console.error('No se encontró el ID de la cotización');
    return;
  }

  if (!confirm(`¿Estás seguro de duplicar la Versión ${cot.version}?\nSe creará una copia idéntica y segura con una nueva versión.`)) return;

  clonandoItem.value = idStr;

  const success = await fileStore.cloneCotizacion(idStr);

  if (success) {
    await cargarFile();
  } else {
    alert(fileStore.error || 'Ocurrió un error al intentar clonar la cotización.');
  }

  clonandoItem.value = null;
};

// ============================================================================
// REVISAR CAMBIOS DE OPERACIÓN
//
// La generación automática sólo se dispara en la TRANSICIÓN a `confirmado`, y ocurre
// una única vez: lo que se edite después no llega al Centro de Operaciones. El panel
// compara y deja aplicar sólo lo aprobado, campo a campo. Ver docs/Operacion.md §3.5.
// ============================================================================
const planOperacionId = ref<string | null>(null);
const planOperacionTitulo = ref<string>('');

const abrirPlanOperacion = (cot: ApiCotizacionVersion) => {
  const idStr = extractIdStr(cot.id || cot['@id']);
  if (!idStr) return;

  planOperacionTitulo.value = `Versión ${cot.version} · ${file.value.nombreGrupo ?? ''}`.trim();
  planOperacionId.value = idStr;
};

const eliminarFile = async () => {
  if (!confirm(`¿Eliminar TODO el expediente "${file.value.nombreGrupo}"? Se borrarán también todas sus versiones, pasajeros y documentos. Esta acción no se puede deshacer.`)) return;
  const iri = file.value['@id'] || `/platform/sales/cotizacion_files/${extractIdStr(file.value.id)}`;
  const success = await fileStore.deleteFile(iri);
  if (success) {
    router.push('/cotizacion');
  } else {
    alert(fileStore.error || 'Error al eliminar el expediente.');
  }
};

const onBeforeUnload = (e: BeforeUnloadEvent) => {
  if (isDirty.value) {
    e.preventDefault();
    e.returnValue = '';
  }
};

onMounted(() => {
  window.addEventListener('beforeunload', onBeforeUnload);
  fetchCatalogos();
  fileStore.fetchIdiomas();
  cargarFile();
});

onUnmounted(() => {
  window.removeEventListener('beforeunload', onBeforeUnload);
});

// Vigila el formulario base. Si cambia, marcamos como sucio.
watch(() => file.value, () => {
  if (watchActivo) {
    isDirty.value = true;
  }
}, { deep: true });

onBeforeRouteLeave((to, from, next) => {
  if (isDirty.value) {
    const confirmacion = window.confirm('Tienes cambios sin guardar en los Datos del Expediente. ¿Estás seguro de que deseas salir y perder los cambios?');
    if (confirmacion) {
      next();
    } else {
      next(false);
    }
  } else {
    next();
  }
});

const showPaxModal = ref(false);
const showDocModal = ref(false);
const isSubmittingPax = ref(false);
/** Lo pone el botón «Guardar y siguiente»: se consume en `guardarPasajero()`. */
const seguirTrasGuardar = ref(false);

// El mismo giro de media vuelta que el panel del expediente. Ver el bloque <style>.
const modoVistaPax = ref(true);
const girandoPax = ref(false);

const girarPanelPax = (aVista: boolean) => {
    if (girandoPax.value) return;
    girandoPax.value = true;
    window.setTimeout(() => { modoVistaPax.value = aVista; girandoPax.value = false; }, 180);
};
const isSubmittingDoc = ref(false);

const paxForm = ref({
  nombre: '', apellido: '', pais: '', sexo: '', fechanacimiento: '', tipo: '', telefono: '', observaciones: '',
  // Una persona lleva DNI *y* pasaporte, con vencimientos distintos. Ver §6.l del doc.
  identificaciones: [] as Array<{ tipo: string; numero: string; vencimiento: string }>,
  /** IRIs de los grupos a los que pertenece. Quién lidera lo dice el TIPO, no una bandera aquí. */
  pertenencias: [] as Array<{ grupo: string }>
});

const docForm = ref({
  nombre: '', tipoArchivo: '', sobreescribirTraduccion: false, fileObject: null as File | null
});

const extractIdStr = (val: unknown): string => val ? String(val).split('/').pop() ?? '' : '';

const fetchCatalogos = async () => {
  try {
    const paisesRes = await apiClient.get('/platform/maestro/paises?pagination=false');
    catalogos.value.paises = paisesRes.data['hydra:member'] || paisesRes.data['member'] || [];
  } catch (e) {
    console.error("Error cargando catálogos", e);
  }
};

const cargarFile = async () => {
  isLoading.value = true;
  watchActivo = false; // Apagamos el guardián mientras hidratamos para no disparar falsas alarmas
  try {
    const response = await apiClient.get(`/platform/sales/cotizacion_files/${route.params.id}`);
    file.value = response.data;
    const pais = file.value.pais;
    paisFileIri.value = pais ? (typeof pais === 'object' ? (pais['@id'] ?? '') : String(pais)) : '';
  } catch (error) {
    console.error("Error al cargar el File", error);
    router.push('/cotizacion');
  } finally {
    isLoading.value = false;
    // Encendemos el guardián con un ligero delay tras pintar la UI
    setTimeout(() => {
      watchActivo = true;
      isDirty.value = false;
    }, 100);
  }
};

const handleVolver = () => {
  volverAtras('/cotizacion');   // vuelve a donde estabas; el dashboard sólo si no hay historial
};

// ── El panel del expediente: se lee, y para editar se gira ────────────────
//
// ⚠️ El giro es de MEDIA vuelta, no de dos caras.
//
// Una tarjeta con anverso y reverso exige que las dos caras midan lo mismo —van superpuestas en
// absoluto— y aquí no se parecen: en lectura son seis líneas, en edición son seis campos, un
// desplegable de países y el panel de contacto. Girar 90°, cambiar el contenido con la tarjeta de
// canto, y volver, se ve igual y no pelea con la altura.
const modoVistaFile = ref(true);

/** Lo que se lee en la cara de lectura, en el mismo orden que el formulario. */
const datosDelFile = computed(() => {
    const f = file.value ?? {};
    const contacto = [f.telefono ? formatearTelefono(f.telefono) : '', f.email ?? ''].filter(Boolean).join(' · ');

    return [
        { rotulo: 'Nombre Grupo', valor: f.nombreGrupo ?? '' },
        { rotulo: 'Titular', valor: f.pasajeroPrincipal ?? '' },
        { rotulo: 'Contacto', valor: contacto },
        { rotulo: 'País de Origen', valor: typeof f.pais === 'object' && f.pais ? (f.pais.nombre ?? '') : '' },
        // El rótulo del estado sale del mismo diccionario que el desplegable; si llegara uno que
        // no está —una migración a medias—, se enseña el valor crudo en vez de dejarlo en blanco.
        {
            rotulo: 'Estado',
            valor: (ESTADO_FILE_LABELS as Record<string, string>)[String(f.estado)] ?? String(f.estado ?? ''),
        },
        {
            rotulo: 'Idioma',
            valor: idiomasDisponibles.value.find(i => i.id === (f.idiomaCliente || 'es'))?.nombre ?? (f.idiomaCliente || 'es'),
        },
    ];
});
const girandoFile = ref(false);

const girarPanelFile = (aVista: boolean) => {
    if (girandoFile.value) return;
    girandoFile.value = true;
    // A los 180 ms la tarjeta está de canto: es cuando se cambia lo que hay dentro.
    window.setTimeout(() => { modoVistaFile.value = aVista; girandoFile.value = false; }, 180);
};

/** Cancelar DESCARTA: enseñar en modo lectura lo que se tecleó y no se guardó sería mentir. */
const cancelarEdicionFile = async () => {
    if (isDirty.value && !confirm('Se pierden los cambios sin guardar. ¿Continuar?')) return;
    if (isDirty.value) await cargarFile();
    girarPanelFile(true);
};

const guardarFile = async () => {
  isSavingFile.value = true;

  // 1. Preparamos el payload con los campos que quieres actualizar
  const payload = {
    nombreGrupo: file.value.nombreGrupo,
    pasajeroPrincipal: file.value.pasajeroPrincipal,
    email: file.value.email,
    telefono: file.value.telefono || null,
    pais: paisFileIri.value || null,
    estado: file.value.estado,
    idiomaCliente: file.value.idiomaCliente || 'es'
  };

  try {
    // 2. Usamos la acción del store que SÍ usa PATCH y el header correcto
    const iri = extractIdStr(file.value.id || file.value['@id']);
    const success = await fileStore.updateFile(`/platform/sales/cotizacion_files/${iri}`, payload);

    if (success) {
      isDirty.value = false;
      girarPanelFile(true);
      alert('Expediente actualizado correctamente.');
    } else {
      alert(fileStore.error || 'Error al guardar el expediente.');
    }
  } catch {
    alert('Error de red al actualizar.');
  } finally {
    isSavingFile.value = false;
  }
};

const nuevaVersion = () => {
  router.push(`/cotizacion/${extractIdStr(file.value.id || file.value['@id'])}/version/nueva`);
};

const abrirMotor = (cotizacion: ApiCotizacionVersion) => {
  const fileId = extractIdStr(file.value.id || file.value['@id']);
  const cotId = extractIdStr(cotizacion.id || cotizacion['@id']);
  router.push(`/cotizacion/${fileId}/version/${cotId}`);
};

// ==========================================
// LÓGICA DE PASAJEROS
// ==========================================

const paxEditandoIri = ref<string | null>(null);
const abrirPaxModal = () => {
  paxEditandoIri.value = null; // modo creación
  paxForm.value = { nombre: '', apellido: '', pais: '', sexo: '', fechanacimiento: '', tipo: '', telefono: '', observaciones: '', identificaciones: [], pertenencias: [] };
  // Uno nuevo no tiene nada que leer: se abre escribiendo.
  modoVistaPax.value = false;
  showPaxModal.value = true;
};

/**
 * @param editar `true` sólo cuando se entra por la plumita. El resto —tocar la tarjeta, las
 *               flechas— abre LEYENDO: recorrer 131 fichas es lo que más se hace, y en un
 *               formulario los datos están repartidos entre campos que hay que interpretar.
 */
const abrirEdicionPax = (pax: ApiCotizacionFilepasajero, editar = false) => {
  modoVistaPax.value = !editar;
  paxEditandoIri.value = pax['@id'] || `/platform/sales/cotizacion_filepasajeros/${extractIdStr(pax.id)}`;
  paxForm.value = {
    nombre: pax.nombre || '',
    apellido: pax.apellido || '',
    pais: typeof pax.pais === 'object' && pax.pais ? (pax.pais['@id'] || pax.pais.id || '') : (pax.pais || ''),
    sexo: pax.sexo || '',
    fechanacimiento: pax.fechanacimiento ? pax.fechanacimiento.split('T')[0] : '',
    tipo: pax.tipo || '',
    telefono: pax.telefono || '',
    observaciones: pax.observaciones || '',
    // ⚠️ Sin identidad: al guardar se manda la lista entera y `orphanRemoval` reemplaza. Para
    // dos o tres filas es predecible; casar por IRI exigiría que la identificación fuese un
    // ApiResource propio, y no lo es —sólo existe colgando de su pasajero—.
    identificaciones: (pax.identificaciones ?? []).map(i => ({
      tipo: i.tipo ?? '',
      numero: i.numero ?? '',
      vencimiento: i.vencimiento ? i.vencimiento.split('T')[0] : '',
    })),
    pertenencias: (pax.pertenencias ?? []).map(p => ({
      grupo: typeof p.grupo === 'string'
        ? p.grupo
        : (p.grupo ? `/platform/sales/cotizacion_file_grupos/${extractIdStr(p.grupo.id)}` : ''),
    })).filter(p => p.grupo)
  };
  showPaxModal.value = true;
};

/**
 * Los tipos que este pasajero todavía no tiene.
 *
 * La restricción es `(pasajero, tipo)` única en base: ofrecer un tipo repetido sólo consigue un
 * 422 al guardar, después de que alguien haya escrito el número.
 */
const tiposIdDisponibles = computed(() => {
  const usados = new Set(paxForm.value.identificaciones.map(i => i.tipo));
  return Object.entries(DOCUMENTO_IDENTIDAD_LABELS).filter(([valor]) => !usados.has(valor));
});

const agregarIdentificacion = () => {
  const libre = tiposIdDisponibles.value[0];
  if (!libre) return;
  paxForm.value.identificaciones.push({ tipo: libre[0], numero: '', vencimiento: '' });
};

const iriDeGrupo = (g: ApiFileGrupo): string =>
  g['@id'] || `/platform/sales/cotizacion_file_grupos/${extractIdStr(g.id)}`;

const perteneceA = (g: ApiFileGrupo): boolean =>
  paxForm.value.pertenencias.some(p => p.grupo === iriDeGrupo(g));

const alternarPertenencia = (g: ApiFileGrupo): void => {
  const iri = iriDeGrupo(g);
  const i = paxForm.value.pertenencias.findIndex(p => p.grupo === iri);
  if (i >= 0) { paxForm.value.pertenencias.splice(i, 1); }
  else { paxForm.value.pertenencias.push({ grupo: iri }); }
};


const cambiarModo = async (modo: 'estandar' | 'grupo' | string) => {
  if (!file.value) return;
  const iri = file.value['@id'] || `/platform/sales/cotizacion_files/${extractIdStr(file.value.id)}`;
  if (await fileStore.updateFile(iri, { modo: modo as 'estandar' | 'grupo' })) { await cargarFile(); }
  else { alert(fileStore.error || 'No se pudo cambiar el modo.'); }
};

// ── Padrón: plantilla e importación ────────────────────────────────────────
//
// La plantilla se GENERA en el servidor desde los enums, no es un archivo guardado: un tipo de
// documento o un eje nuevo aparece en ella el mismo día que en el código. Una plantilla
// desactualizada es peor que ninguna — la rellenan igual y el dato se pierde al importar.
const descargandoPlantilla = ref(false);

// ── Carga del padrón: siempre ensayo antes de escribir ─────────────────────
interface ResultadoPadron {
  expediente: string; ensayo: boolean; filasLeidas: number;
  pasajerosCreados: number; pasajerosActualizados: number; identificacionesCreadas: number;
  gruposCreados: number; pertenenciasCreadas: number; pertenenciasQuitadas: number;
  noEstanEnElArchivo: string[]; avisos: string[]; errores: string[];
}

const archivoPadron = ref<File | null>(null);
const cargandoPadron = ref(false);
const ensayoPadron = ref<ResultadoPadron | null>(null);
const inputPadron = ref<HTMLInputElement | null>(null);

const elegirPadron = async (e: Event) => {
  const archivo = (e.target as HTMLInputElement).files?.[0] ?? null;
  if (!archivo || !file.value) return;

  archivoPadron.value = archivo;
  ensayoPadron.value = null;
  cargandoPadron.value = true;

  ensayoPadron.value = await fileStore.cargarPadron(
    extractIdStr(file.value.id || file.value['@id']) || '', archivo, true,
  );
  cargandoPadron.value = false;

  if (!ensayoPadron.value) { alert(fileStore.error || 'No se pudo leer el archivo.'); }
};

const aplicarPadron = async () => {
  if (!archivoPadron.value || !file.value) return;

  cargandoPadron.value = true;
  const r = await fileStore.cargarPadron(
    extractIdStr(file.value.id || file.value['@id']) || '', archivoPadron.value, false,
  );
  cargandoPadron.value = false;

  if (r && r.errores.length === 0) {
    cancelarPadron();
    await cargarFile();
  } else {
    ensayoPadron.value = r;
    alert(fileStore.error || 'No se guardó nada: hay filas con problemas.');
  }
};

const cancelarPadron = () => {
  archivoPadron.value = null;
  ensayoPadron.value = null;
  if (inputPadron.value) { inputPadron.value.value = ''; }
};


const bajarHoja = async (ruta: string, nombre: string) => {
  descargandoPlantilla.value = true;
  try {
    const { data } = await apiClient.get(ruta, { responseType: 'blob' });
    const url = URL.createObjectURL(data as Blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = nombre;
    a.click();
    URL.revokeObjectURL(url);
  } catch {
    alert('No se pudo descargar el archivo.');
  } finally {
    descargandoPlantilla.value = false;
  }
};

/** La plantilla en blanco, con ejemplos e instrucciones. */
const descargarPlantilla = () => bajarHoja('/cotizacion/user/padron/plantilla', 'padron-plantilla.xlsx');

/**
 * La misma plantilla YA RELLENA con lo que hay cargado.
 *
 * ⚠️ Trae la columna `Id`, y ahí está la gracia: al volver a subirla, cada fila regresa a SU
 * persona aunque le hayas cambiado el nombre y el documento a la vez. Es lo que hace que completar
 * un padrón a medias no duplique a nadie.
 */
const descargarCargado = () => {
  const id = extractIdStr(file.value?.id || file.value?.['@id'] || '');
  if (id) { void bajarHoja(`/cotizacion/user/padron/exportar/${id}`, 'padron-cargado.xlsx'); }
};

// ── Filtros del manifiesto ─────────────────────────────────────────────────
//
// Con 133 personas y 108 subgrupos, la lista completa no sirve para nada: lo que se hace de
// verdad es «los de JetSmart», «los de la habitación HA50», «los del grupo 5». Los filtros se
// ACUMULAN (Y lógico, no O) porque la pregunta real es siempre una intersección: quién del grupo
// 5 va en el vuelo de las 07:15.
const busquedaPax = ref('');
const gruposFiltrados = ref<string[]>([]);

/**
 * ⚠️ Los «No participa» salen fuera por defecto.
 *
 * Están en el padrón porque el colegio los apuntó y luego se cayeron, y **no se borran** —la
 * lista tiene que seguir contando lo que pasó—. Pero para operar estorban: se cuentan solos al
 * mirar cuánta gente va.
 */
const incluirNoParticipa = ref(false);

const iriDeGrupoPlano = (g: ApiFileGrupo): string =>
    g['@id'] || `/platform/sales/cotizacion_file_grupos/${extractIdStr(g.id)}`;

/**
 * Las etiquetas de una persona: clave + nombre de cada subgrupo suyo.
 *
 * La lectura de la API trae el grupo YA EMBEBIDO dentro de la pertenencia, así que no hay que
 * buscarlo: con 133 personas y 108 grupos, cruzar las dos listas serían 14 000 comparaciones en
 * cada tecla del buscador. El `string` es la forma de ESCRITURA, y se resuelve por si acaso.
 */
const gruposDePax = (pax: ApiCotizacionFilepasajero): ApiFileGrupo[] =>
    (pax.pertenencias ?? []).flatMap((p) => {
        if (p.grupo && typeof p.grupo === 'object') return [p.grupo as ApiFileGrupo];

        const g = (file.value?.grupos ?? []).find(x => extractIdStr(iriDeGrupoPlano(x)) === extractIdStr(p.grupo));

        return g ? [g] : [];
    });

/**
 * La base de TODOS los conteos: quién cuenta como que va.
 *
 * ⚠️ No es lo mismo que «los que están en el padrón». Los «no participa» siguen ahí —se apuntaron
 * y luego se cayeron, y no se borran— pero **conservan su grupo y sus reservas aéreas**, así que
 * sumaban en los totales. Medido sobre Punta Cana 2026, con dos personas caídas ya hay cinco
 * conteos mintiendo: `JA2CWN` decía 25 y vuelan 24, `PV7PFM` 10 por 9, `BBBBB` 44 por 43,
 * `X9SYVZ` 9 por 8 y el grupo 6, 12 por 11.
 *
 * Cualquier número que se enseñe sale de aquí, NO de `filepasajeros`.
 */
const pasajerosConsiderados = computed<ApiCotizacionFilepasajero[]>(() =>
    (file.value?.filepasajeros ?? []).filter(p => incluirNoParticipa.value || p.tipo !== 'no_participa'));

const totalNoParticipa = computed(() =>
    (file.value?.filepasajeros ?? []).filter(p => p.tipo === 'no_participa').length);

/**
 * Cuánta gente que cuenta hay en cada subgrupo, en UNA pasada.
 *
 * Contar grupo a grupo eran 108 × 133 recorridos cada vez que se repinta la lista; así es un
 * recorrido de 133 y una consulta al mapa.
 */
const conteoPorGrupo = computed<Map<string, number>>(() => {
    const mapa = new Map<string, number>();
    for (const pax of pasajerosConsiderados.value) {
        for (const g of gruposDePax(pax)) {
            const id = extractIdStr(iriDeGrupoPlano(g));
            mapa.set(id, (mapa.get(id) ?? 0) + 1);
        }
    }

    return mapa;
});

const contarEnGrupo = (g: ApiFileGrupo): number =>
    conteoPorGrupo.value.get(extractIdStr(iriDeGrupoPlano(g))) ?? 0;

/**
 * Los ejes de pertenencia que NO son vuelo ni servicio: su grupo y su habitación.
 *
 * ⚠️ El grupo va arriba del todo, junto al rol. Es la unidad con la que se opera —«que suba el
 * grupo 5 al bus», «el coordinador del 3 pregunta por…»— y estaba sólo dentro de la ficha, a dos
 * toques. Se ordena con el grupo primero: la habitación se consulta al llegar al hotel, el grupo
 * todos los días.
 */
const PRIORIDAD_EJE: Record<string, number> = { grupo: 0, habitacion: 1 };

const ejesDePax = (pax: ApiCotizacionFilepasajero) =>
    gruposDePax(pax)
        .filter(g => !EJES_AEREOS.includes(String(g.tipo)) && String(g.tipo) !== 'servicio')
        .sort((a, b) => (PRIORIDAD_EJE[String(a.tipo)] ?? 9) - (PRIORIDAD_EJE[String(b.tipo)] ?? 9))
        .map(g => ({
            id: String(g.id),
            icono: GRUPO_TIPO_LABELS[String(g.tipo)]?.icon ?? 'fa-tag',
            // El grupo se lee «Grupo 5» y la habitación «HA13»: el nombre de la habitación es
            // «DOBLE», que no la identifica. Manda la clave, y el nombre sólo si aporta.
            texto: String(g.tipo) === 'grupo' ? (g.nombre || `Grupo ${g.clave}`) : String(g.clave),
            destacado: String(g.tipo) === 'grupo',
        }));

/** Lo que lleva contratado esta persona: los ejes binarios. */
const serviciosDe = (pax: ApiCotizacionFilepasajero) =>
    gruposDePax(pax).filter(g => String(g.tipo) === 'servicio');

/** Los vuelos de una persona, para pintarlos en su ficha: tramo, aerolínea y localizador. */
const vuelosDe = (pax: ApiCotizacionFilepasajero) =>
    gruposDePax(pax)
        .filter(g => EJES_AEREOS.includes(String(g.tipo)))
        .map(g => ({
            id: String(g.id),
            tramo: (GRUPO_TIPO_LABELS[String(g.tipo)]?.label ?? '').replace('Reserva aérea', '').trim(),
            nombre: g.nombre ?? '',
            clave: g.clave,
            detalle: g.detalle ?? '',
        }));

const filtroRol = ref<string[]>([]);
const filtroAerolinea = ref<string[]>([]);   // «eje|NOMBRE», p. ej. «reserva_aerea_nacional|JetSMART»

/** Los ejes que llevan vuelo, tal como los tenga ESTE expediente. */
const EJES_AEREOS = ['reserva_aerea', 'reserva_aerea_nacional', 'reserva_aerea_internacional'];

/**
 * Las aerolíneas que hay, por eje: «Nacional → JetSMART, Sky Airline».
 *
 * Sale del `nombre` de los grupos, no de una lista nuestra: ocho localizadores distintos son la
 * misma Arajet, y lo que se quiere ver es «los de Arajet», no ocho códigos uno a uno.
 */
const aerolineasPorEje = computed(() =>
    EJES_AEREOS
        .map(eje => ({
            eje,
            label: GRUPO_TIPO_LABELS[eje]?.label ?? eje,
            nombres: [...new Set(
                (file.value?.grupos ?? [])
                    .filter(g => String(g.tipo) === eje && g.nombre)
                    .map(g => String(g.nombre)),
            )].sort(),
        }))
        .filter(x => x.nombres.length > 0),
);

/** Cuántos hay de cada rol, sobre los que cuentan. */
const conteoPorRol = computed<Record<string, number>>(() => {
    const mapa: Record<string, number> = {};
    for (const p of pasajerosConsiderados.value) {
        const t = String(p.tipo ?? 'sin_rol');
        mapa[t] = (mapa[t] ?? 0) + 1;
    }

    return mapa;
});

// En la plantilla un `ref` llega ya desenvuelto, así que no se le puede pasar el `Ref`: cada
// faceta tiene su propio interruptor sobre su `ref`.
const alternar = (lista: string[], valor: string): string[] =>
    lista.includes(valor) ? lista.filter(x => x !== valor) : [...lista, valor];

const alternarRol = (valor: string) => { filtroRol.value = alternar(filtroRol.value, valor); };
const alternarAerolinea = (valor: string) => { filtroAerolinea.value = alternar(filtroAerolinea.value, valor); };

const pasajerosFiltrados = computed<ApiCotizacionFilepasajero[]>(() => {
    const texto = busquedaPax.value.trim().toLowerCase();

    // ⚠️ Y entre EJES, O dentro del mismo eje.
    //
    // Acumular todo con Y era un error mío: elegir dos habitaciones daba cero, porque nadie está
    // en dos a la vez. La pregunta real es «los del grupo 5 que estén en HA01 **o** HA02», que es
    // exactamente el comportamiento de cualquier filtro por facetas.
    const porEje = new Map<string, string[]>();
    for (const iri of gruposFiltrados.value) {
        const eje = String(grupoDeIri(iri)?.tipo ?? '');
        porEje.set(eje, [...(porEje.get(eje) ?? []), extractIdStr(iri)]);
    }

    return pasajerosConsiderados.value.filter((pax) => {
        const suyos = gruposDePax(pax);

        if (filtroRol.value.length && !filtroRol.value.includes(String(pax.tipo))) return false;

        if (porEje.size) {
            const ids = new Set(suyos.map(g => extractIdStr(iriDeGrupoPlano(g))));
            for (const elegidos of porEje.values()) {
                if (!elegidos.some(id => ids.has(id))) return false;
            }
        }

        if (filtroAerolinea.value.length) {
            const suyas = new Set(suyos.filter(g => g.nombre).map(g => `${String(g.tipo)}|${g.nombre}`));
            // Y entre ejes también aquí: «nacional JetSMART» + «internacional Copa» son dos
            // condiciones, no dos alternativas.
            const porEjeAereo = new Map<string, string[]>();
            for (const clave of filtroAerolinea.value) {
                const eje = clave.split('|')[0];
                porEjeAereo.set(eje, [...(porEjeAereo.get(eje) ?? []), clave]);
            }
            for (const alternativas of porEjeAereo.values()) {
                if (!alternativas.some(c => suyas.has(c))) return false;
            }
        }

        if (!texto) return true;

        // La búsqueda mira también las etiquetas: «jetsmart» encuentra a los de esa aerolínea
        // aunque la persona no la lleve escrita en ningún campo suyo.
        const paja = [
            pax.nombre, pax.apellido,
            ...(pax.identificaciones ?? []).map(d => d.numero),
            ...suyos.flatMap(g => [g.clave, g.nombre]),
        ].filter(Boolean).join(' ').toLowerCase();

        return paja.includes(texto);
    });
});

/** Los subgrupos elegibles, agrupados por eje para el desplegable. */
// ⚠️ `SearchableSelect` y no un `<select>` nativo: con 108 subgrupos, en un móvil la lista nativa
// es una pared de 108 filas que hay que recorrer con el dedo. Éste teclea y filtra, y busca
// también en la segunda línea —el eje—, así que «habitación» acota de golpe.
const gruposElegibles = computed(() =>
    (file.value?.grupos ?? [])
        .filter(g => !gruposFiltrados.value.includes(iriDeGrupoPlano(g)))
        .map(g => ({
            value: iriDeGrupoPlano(g),
            label: [g.clave, g.nombre].filter(Boolean).join(' · '),
            sublabel: `${GRUPO_TIPO_LABELS[String(g.tipo)]?.label ?? String(g.tipo)} · ${contarEnGrupo(g)} pax`,
        })),
);

/** El desplegable se vacía en cuanto elige: es un «añadir», no una selección que se queda. */
const grupoPorAnadir = ref<string | number | null>(null);
watch(grupoPorAnadir, (iri) => {
    if (typeof iri === 'string' && iri) anadirFiltro(iri);
    grupoPorAnadir.value = null;
});

const grupoDeIri = (iri: string): ApiFileGrupo | undefined =>
    (file.value?.grupos ?? []).find(g => iriDeGrupoPlano(g) === iri);

const anadirFiltro = (iri: string) => {
    if (iri && !gruposFiltrados.value.includes(iri)) gruposFiltrados.value.push(iri);
};

const quitarFiltro = (iri: string) => {
    gruposFiltrados.value = gruposFiltrados.value.filter(x => x !== iri);
};

const limpiarFiltros = () => {
    gruposFiltrados.value = [];
    filtroRol.value = [];
    filtroAerolinea.value = [];
    busquedaPax.value = '';
    incluirNoParticipa.value = false;
};

const hayFiltros = computed(() =>
    gruposFiltrados.value.length > 0 || filtroRol.value.length > 0 || filtroAerolinea.value.length > 0
    || busquedaPax.value.trim() !== '' || incluirNoParticipa.value);

// ── Subgrupos del expediente ───────────────────────────────────────────────
//
// Se agrupan por EJE para pintarlos, pero no anidan: una persona está a la vez en su salón, su
// grupo, su habitación y sus reservas. Ver docs/Cotizaciones.md §6.m.
const gruposPorTipo = computed(() => {
  const mapa: Record<string, ApiFileGrupo[]> = {};
  for (const g of (file.value?.grupos ?? [])) {
    const tipo = String(g.tipo ?? 'grupo');
    (mapa[tipo] ??= []).push(g);
  }
  return mapa;
});

/**
 * Cuántas píldoras se pintan antes de plegar el eje.
 *
 * ⚠️ No es capricho de diseño: el hotel numera 66 habitaciones y las aerolíneas dan una veintena
 * de localizadores. Desplegados, el eje «Grupo» —que son nueve y es el que más se usa— queda a
 * tres pantallas de scroll, y la ficha del pasajero deja de ser utilizable justo en el expediente
 * grande, que es donde hace falta.
 */
const TOPE_PILDORAS = 12;

const ejesAbiertos = ref<Record<string, boolean>>({});
const filtroEje = ref<Record<string, string>>({});

const ejeEstaAbierto = (tipo: string, total: number): boolean =>
    total <= TOPE_PILDORAS || ejesAbiertos.value[tipo] === true;

const alternarEje = (tipo: string) => {
    ejesAbiertos.value[tipo] = !ejesAbiertos.value[tipo];
    if (!ejesAbiertos.value[tipo]) filtroEje.value[tipo] = '';
};

/**
 * Qué píldoras se ven de un eje.
 *
 * Plegado enseña **sólo a las que pertenece**, que es la información que se venía a leer; abierto,
 * todas, con un filtro de texto porque elegir entre 66 a ojo es peor que teclear «HA5».
 */
const pildorasVisibles = (tipo: string, lista: ApiFileGrupo[]): ApiFileGrupo[] => {
    if (!ejeEstaAbierto(tipo, lista.length)) return lista.filter(perteneceA);

    const filtro = (filtroEje.value[tipo] ?? '').trim().toUpperCase();
    if (!filtro) return lista;

    return lista.filter(g =>
        `${g.clave ?? ''} ${g.nombre ?? ''}`.toUpperCase().includes(filtro),
    );
};

/** Los grupos de este eje a los que pertenece Y que traen itinerario. */
const detallesDe = (lista: ApiFileGrupo[]): ApiFileGrupo[] =>
    lista.filter(g => g.detalle && perteneceA(g));

/** El borrado de subgrupos, apagado por defecto. Ver el comentario de la sección. */
const modoGestionGrupos = ref(false);

const subgruposAbiertos = ref(false);

/** «9 grupos · 66 habitaciones · 23 reservas · 10 servicios», para no tener que abrir. */
const resumenSubgrupos = computed(() => {
    const partes = Object.entries(gruposPorTipo.value)
        .map(([tipo, lista]) => {
            const cfg = GRUPO_TIPO_LABELS[tipo];

            return `${lista.length} ${(lista.length === 1 ? cfg?.label : cfg?.plural)?.toLowerCase() ?? tipo}`;
        });

    return partes.length ? partes.join(' · ') : 'ninguno';
});

const nuevoGrupo = ref({ tipo: 'grupo', clave: '', nombre: '', detalle: '' });
const creandoGrupo = ref(false);

const agregarGrupo = async () => {
  if (!file.value || !nuevoGrupo.value.clave.trim()) return;

  creandoGrupo.value = true;
  const ok = await fileStore.crearGrupo(
    extractIdStr(file.value.id || file.value['@id']) || '',
    {
      tipo: nuevoGrupo.value.tipo,
      clave: nuevoGrupo.value.clave,
      nombre: nuevoGrupo.value.nombre || null,
      detalle: nuevoGrupo.value.detalle || null,
    },
  );
  creandoGrupo.value = false;

  if (ok) {
    nuevoGrupo.value.clave = '';
    nuevoGrupo.value.nombre = '';
    nuevoGrupo.value.detalle = '';
    await cargarFile();
  } else {
    alert(fileStore.error || 'No se pudo crear el subgrupo.');
  }
};

const borrarGrupo = async (grupo: ApiFileGrupo) => {
  const miembros = grupo.totalMiembros ?? 0;
  const aviso = miembros
    ? `Se quitará de ${miembros} pasajero(s). Ellos NO se borran: sólo dejan de pertenecer.`
    : 'No tiene miembros.';
  if (!confirm(`¿Eliminar «${grupo.etiqueta}»?\n\n${aviso}`)) return;

  const iri = grupo['@id'] || `/platform/sales/cotizacion_file_grupos/${extractIdStr(grupo.id)}`;
  if (await fileStore.eliminarGrupo(iri)) { await cargarFile(); }
  else { alert(fileStore.error || 'No se pudo eliminar.'); }
};

const paisSelectRef = ref<{ validate: () => boolean } | null>(null);

/**
 * Moverse de una ficha a la siguiente sin cerrar.
 *
 * ⚠️ Se navega sobre `pasajerosFiltrados`, NO sobre todos. Es lo que hace útil el salto: filtras
 * «Copa internacional» y repasas a esos 18 seguidos, en vez de abrir y cerrar 18 veces buscándolos
 * en la lista. Si al guardar alguien deja de cumplir el filtro, desaparece del recorrido — que es
 * lo correcto: ya no es uno de los que estabas repasando.
 */
const indiceEnFiltrados = computed(() =>
    pasajerosFiltrados.value.findIndex(p =>
        (p['@id'] || `/platform/sales/cotizacion_filepasajeros/${extractIdStr(p.id)}`) === paxEditandoIri.value));

/** El pasajero que se está mirando, tal como está GUARDADO (no el formulario). */
const paxEnFoco = computed<ApiCotizacionFilepasajero | undefined>(() =>
    pasajerosFiltrados.value[indiceEnFiltrados.value]);

/** Un documento vencido no es un matiz: es alguien que no embarca. Se marca. */
const documentosDe = computed(() => {
    const hoy = new Date().toISOString().slice(0, 10);

    return (paxEnFoco.value?.identificaciones ?? []).map((d) => {
        const vence = d.vencimiento ? d.vencimiento.split('T')[0] : '';

        return {
            id: String(d.id ?? d.numero ?? ''),
            etiqueta: getDocIdLabel(d.tipo),
            numero: d.numero ?? '',
            vence,
            vencido: vence !== '' && vence < hoy,
        };
    });
});

const hayAnterior = computed(() => indiceEnFiltrados.value > 0);
const haySiguiente = computed(() =>
    indiceEnFiltrados.value >= 0 && indiceEnFiltrados.value < pasajerosFiltrados.value.length - 1);

const saltarA = (delta: number) => {
    const destino = pasajerosFiltrados.value[indiceEnFiltrados.value + delta];
    if (destino) abrirEdicionPax(destino);
};

const guardarPasajero = async () => {
  // SearchableSelect no dispara la validación nativa del form: validamos a mano.
  // validate() pinta el error dentro del componente y devuelve si es válido.
  if (paisSelectRef.value && !paisSelectRef.value.validate()) {
    // Se limpia la intención: si no, el «guardar y siguiente» que falló la validación seguiría
    // armado y el SIGUIENTE guardado normal saltaría de ficha sin que nadie lo pidiera.
    seguirTrasGuardar.value = false;

    return;
  }

  isSubmittingPax.value = true;

  let success: boolean;

  if (paxEditandoIri.value) {
    // Modo edición
    success = await fileStore.updatePassenger(paxEditandoIri.value, paxForm.value);
  } else {
    // Modo creación (igual que antes)
    const payload = {
      ...paxForm.value,
      file: `/platform/sales/cotizacion_files/${extractIdStr(file.value.id || file.value['@id'])}`
    };
    success = await fileStore.addPassenger(payload);
  }

  if (success) {
    const iriGuardado = paxEditandoIri.value;
    const seguir = seguirTrasGuardar.value;
    seguirTrasGuardar.value = false;

    // El orden importa: primero se recarga, y sobre la lista NUEVA se decide a quién saltar. Al
    // revés se saltaría usando los datos viejos y el índice podría no ser el que se ve.
    if (!seguir) {
      showPaxModal.value = false;
      paxEditandoIri.value = null;
    }
    await cargarFile();

    if (seguir) {
      paxEditandoIri.value = iriGuardado;
      await nextTick();
      if (haySiguiente.value) saltarA(1);
      else showPaxModal.value = false;
    }
  } else {
    seguirTrasGuardar.value = false;
    alert(fileStore.error || (paxEditandoIri.value ? 'Error al actualizar pasajero' : 'Error al registrar pasajero'));
  }
  isSubmittingPax.value = false;
};

const eliminarPasajero = async (iri?: string): Promise<void> => {
  if (!iri) return;
  if (!confirm('¿Eliminar pasajero?')) return;
  const success = await fileStore.deletePassenger(iri);
  if (success) await cargarFile();
  else alert("Error al eliminar pasajero");
};

// ==========================================
// LÓGICA DE DOCUMENTOS
// ==========================================

const docEditandoIri = ref<string | null>(null);

const abrirDocModal = () => {
  docEditandoIri.value = null; // modo creación
  docForm.value = { nombre: '', tipoArchivo: '', sobreescribirTraduccion: false, fileObject: null };
  showDocModal.value = true;
};

const abrirEdicionDoc = (doc: ApiCotizacionFilearchivo) => {
  docEditandoIri.value = doc['@id'] || `/platform/sales/cotizacion_filearchivos/${extractIdStr(doc.id)}`;
  docForm.value = {
    nombre: getDocNombre(doc, 'es'),   // siempre editamos la fuente en español
    tipoArchivo: doc.tipoArchivo || '',
    sobreescribirTraduccion: false,
    fileObject: null
  };
  showDocModal.value = true;
};

const handleFileUpload = (e: Event) => {
  const target = e.target as HTMLInputElement;
  if (target.files && target.files[0]) docForm.value.fileObject = target.files[0];
};

const guardarDocumento = async () => {
  let success: boolean;

  if (docEditandoIri.value) {
    // Modo edición: solo metadata, sin archivo (PATCH JSON → array i18n)
    isSubmittingDoc.value = true;
    success = await fileStore.updateDocument(docEditandoIri.value, {
      nombre: docForm.value.nombre
          ? [{ content: docForm.value.nombre.trim(), language: 'es' }]
          : null,
      tipoArchivo: docForm.value.tipoArchivo,
      sobreescribirTraduccion: docForm.value.sobreescribirTraduccion
    });
  } else {
    // Modo creación: exige archivo (POST multipart)
    if (!docForm.value.fileObject || !docForm.value.tipoArchivo) {
      alert("Faltan datos o el archivo");
      return;
    }
    isSubmittingDoc.value = true;
    const formData = new FormData();
    formData.append('documento', docForm.value.fileObject);
    // ⚠️ nombre es json/array (I18nContent[]): se envía con notación de índice,
    //     nunca como string plano (rompe AbstractItemNormalizer).
    if (docForm.value.nombre) {
      formData.append('nombre[0][content]', docForm.value.nombre.trim());
      formData.append('nombre[0][language]', 'es');
    }
    formData.append('tipoArchivo', docForm.value.tipoArchivo);
    formData.append('sobreescribirTraduccion', docForm.value.sobreescribirTraduccion ? 'true' : 'false');
    formData.append('file', `/platform/sales/cotizacion_files/${extractIdStr(file.value.id || file.value['@id'])}`);
    success = await fileStore.uploadDocument(formData);
  }

  if (success) {
    showDocModal.value = false;
    docEditandoIri.value = null;
    await cargarFile();
  } else {
    alert(fileStore.error || (docEditandoIri.value ? 'Error al actualizar documento' : 'Error al subir el documento'));
  }
  isSubmittingDoc.value = false;
};

const eliminarDocumento = async (iri?: string) => {
  if (!iri) return;
  if(!confirm('¿Eliminar este documento de la bóveda?')) return;
  const success = await fileStore.deleteDocument(iri);
  if (success) await cargarFile();
  else alert("Error al eliminar documento");
};
</script>

<template>
  <div class="h-screen bg-slate-50 flex flex-col font-sans overflow-hidden">

    <header class="shrink-0 bg-white border-b border-slate-200 px-6 py-4 flex flex-col gap-3 z-30 shadow-sm">
      <div class="flex items-center justify-between gap-4">
        <div class="flex items-center gap-4 min-w-0">
          <button @click="handleVolver" class="w-10 h-10 shrink-0 flex items-center justify-center bg-slate-50 hover:bg-slate-100 rounded-xl text-slate-500 transition-colors">
            <i class="fas fa-arrow-left"></i>
          </button>
          <div class="min-w-0">
            <h1 class="font-black text-2xl text-slate-800 tracking-tight leading-none mb-1 truncate">Detalle del Expediente</h1>
            <p class="text-xs font-bold text-slate-400 uppercase tracking-widest">{{ file?.localizador || 'Sin Localizador' }}</p>
          </div>
        </div>

        <div v-if="idiomasDisponibles.length > 1" class="relative shrink-0">
          <div v-if="idiomaDocDropdown" class="fixed inset-0 z-40" @click="idiomaDocDropdown = false"></div>
          <button type="button" @click="idiomaDocDropdown = !idiomaDocDropdown"
              title="Idioma de visualización: títulos, resúmenes y documentos"
              class="relative z-50 flex items-center gap-1.5 bg-white hover:bg-slate-50 border border-slate-200 rounded-xl px-3 py-2.5 text-xs font-bold text-slate-600 transition-colors shadow-sm">
            <span>{{ idiomasDisponibles.find(i => i.id === idiomaActivo)?.bandera ?? '🌐' }}</span>
            <span class="uppercase tracking-wider">{{ idiomaActivo }}</span>
            <i class="fas fa-chevron-down text-[8px] transition-transform duration-200" :class="idiomaDocDropdown ? 'rotate-180' : ''"></i>
          </button>
          <div v-if="idiomaDocDropdown" class="absolute right-0 top-full mt-1 bg-white rounded-xl shadow-xl border border-slate-100 overflow-hidden min-w-[150px] z-50">
            <button v-for="idi in idiomasDisponibles" :key="idi.id" type="button"
                @click="idiomaActivo = idi.id; idiomaDocDropdown = false"
                class="flex items-center gap-2.5 w-full px-3 py-2.5 text-left text-xs font-bold transition-colors hover:bg-slate-50"
                :class="idiomaActivo === idi.id ? 'bg-sky-50 text-sky-700' : 'text-slate-700'">
              <span class="text-sm">{{ idi.bandera }}</span>
              <span class="flex-1">{{ idi.nombre }}</span>
              <i v-if="idiomaActivo === idi.id" class="fas fa-check text-sky-500 text-[10px]"></i>
            </button>
          </div>
        </div>
      </div>

      <div class="flex items-center gap-2 flex-wrap">
        <button v-if="file?.localizador" @click="copiarLink"
           class="px-4 py-2.5 border font-bold text-sm rounded-xl shadow-sm transition-all flex items-center gap-2"
           :class="linkCopiado ? 'bg-emerald-50 border-emerald-200 text-emerald-700' : 'bg-white border-slate-200 text-slate-700 hover:bg-slate-50'">
          <i :class="linkCopiado ? 'fas fa-check' : 'far fa-copy'"></i>
          <span class="hidden md:inline">{{ linkCopiado ? 'Copiado' : 'Copiar Link' }}</span>
        </button>

        <a v-if="file?.localizador" :href="linkPublicoVersion()" target="_blank" rel="noopener"
           class="px-4 py-2.5 bg-white border border-slate-200 text-slate-700 font-bold text-sm rounded-xl shadow-sm hover:bg-slate-50 transition-all flex items-center gap-2">
          <i class="fas fa-external-link-alt"></i> <span class="hidden md:inline">Vista Cliente</span>
        </a>

        <button @click="eliminarFile"
           class="px-4 py-2.5 bg-red-50 border border-red-200 text-red-600 font-bold text-sm rounded-xl shadow-sm hover:bg-red-100 transition-all flex items-center gap-2">
          <i class="fas fa-trash-alt"></i> <span class="hidden md:inline">Eliminar Expediente</span>
        </button>

        <button @click="nuevaVersion"
           class="px-4 py-2.5 bg-[#376875] hover:bg-[#2d5662] text-white font-bold text-sm rounded-xl shadow-md transition-all flex items-center gap-2 ml-auto">
          <i class="fas fa-rocket"></i> <span class="hidden md:inline">Crear Nueva Versión</span>
        </button>
      </div>
    </header>

    <main v-if="isLoading" class="flex-1 flex justify-center items-center">
      <i class="fas fa-spinner fa-spin text-4xl text-slate-300"></i>
    </main>

    <main v-else class="flex-1 overflow-y-auto p-6 md:p-8">
      <div class="max-w-6xl mx-auto w-full grid grid-cols-1 lg:grid-cols-[minmax(340px,380px)_1fr] gap-8 items-start pb-20">

        <aside class="space-y-6 lg:sticky lg:top-0 min-w-0">

          <div class="panel-giratorio">
          <div class="bg-white rounded-3xl p-6 border border-slate-200 shadow-sm cara" :class="{ 'de-canto': girandoFile }">
            <div class="flex items-center justify-between mb-5 border-b pb-3 gap-2">
              <h2 class="text-sm font-black text-slate-800 uppercase tracking-widest min-w-0">
                <i class="fas fa-folder-open mr-2 text-[#E07845]"></i> Datos del Expediente
              </h2>
              <button v-if="modoVistaFile" type="button" @click="girarPanelFile(false)"
                      class="shrink-0 flex items-center gap-1.5 bg-slate-100 hover:bg-slate-200 text-slate-600 px-3 py-1.5 rounded-lg text-[10px] font-black uppercase tracking-widest transition-colors">
                <i class="fas fa-pencil-alt text-[9px]"></i> Editar
              </button>
              <span v-else-if="isDirty" class="shrink-0 text-[9px] font-black text-amber-600 uppercase tracking-widest">
                <i class="fas fa-circle text-[6px] mr-1"></i> Sin guardar
              </span>
            </div>

            <!-- ── Cara de lectura ───────────────────────────────────────── -->
            <dl v-if="modoVistaFile" class="space-y-3">
              <div v-for="d in datosDelFile" :key="d.rotulo">
                <dt class="text-[10px] font-bold text-slate-400 uppercase">{{ d.rotulo }}</dt>
                <dd class="text-sm font-bold text-slate-800 break-words" :class="!d.valor ? 'text-slate-300 italic font-medium' : ''">
                  {{ d.valor || '— sin definir' }}
                </dd>
              </div>
            </dl>

            <form v-else @submit.prevent="guardarFile" class="space-y-4">
              <div>
                <label class="block text-[10px] font-bold text-slate-500 uppercase mb-1">Nombre Grupo</label>
                <input v-model="file.nombreGrupo" type="text" required class="w-full bg-slate-50 border border-slate-200 rounded-lg px-3 py-2 text-sm font-bold focus:ring-2 focus:ring-[#376875] outline-none">
              </div>
              <div>
                <label class="block text-[10px] font-bold text-slate-500 uppercase mb-1">Titular</label>
                <input v-model="file.pasajeroPrincipal" type="text" class="w-full bg-slate-50 border border-slate-200 rounded-lg px-3 py-2 text-sm font-bold focus:ring-2 focus:ring-[#376875] outline-none">
              </div>
              <!-- ⚠️ CONTACTO: se ENSEÑA, no se edita.
                   El teléfono y el correo del expediente son la SEMILLA con la que se creó la
                   identidad de esa persona; a partir de ahí el dato bueno vive en las
                   identidades, que es donde se corrige, se retira y se marca cuál se usa.
                   Con el `<input>` puesto, el operador cambiaba el número, veía su cambio
                   guardado, y los mensajes seguían saliendo al viejo — porque el envío lee la
                   identidad. Ver `ContactoDeIdentidad` y docs/Mensajeria.md §24. -->
              <div>
                <label class="block text-[10px] font-bold text-slate-500 uppercase mb-1">Contacto</label>
                <!-- Los `v-model` son la SEMILLA: mientras no haya identidad el campo se
                     sigue escribiendo aquí y se guarda con el expediente. Sin ellos, un
                     expediente antiguo sin correo se quedaba sin sitio donde ponerlo — y sin
                     dato de contacto tampoco se puede abrir el hilo, que es donde se editan
                     los identificadores. Callejón sin salida. -->
                <ContactoDeIdentidad
                    context-type="cotizacion_file"
                    :context-id="fileId"
                    v-model:telefono="file.telefono"
                    v-model:correo="file.email"
                />
              </div>
              <div>
                <label class="block text-[10px] font-bold text-slate-500 uppercase mb-1">País de Origen</label>
                <SearchableSelect
                    v-model="paisFileIri"
                    :options="paisOptions"
                    placeholder="Buscar país..."
                />
              </div>
              <div>
                <label class="block text-[10px] font-bold text-slate-500 uppercase mb-1">Estado</label>
                <select v-model="file.estado" class="w-full bg-slate-50 border border-slate-200 rounded-lg px-3 py-2 text-sm font-bold focus:ring-2 focus:ring-[#376875] outline-none">
                  <option v-for="(label, valor) in ESTADO_FILE_LABELS" :key="valor" :value="valor">
                    {{ label }}
                  </option>
                </select>
              </div>
              <div v-if="idiomasDisponibles.length">
                <label class="block text-[10px] font-bold text-slate-500 uppercase mb-1.5">Idioma Predeterminado</label>
                <div class="relative">
                  <div v-if="idiomaFileDropdown" class="fixed inset-0 z-40" @click="idiomaFileDropdown = false"></div>
                  <button type="button" @click="idiomaFileDropdown = !idiomaFileDropdown"
                      class="relative z-50 w-full flex items-center gap-2 bg-slate-50 hover:bg-slate-100 border border-slate-200 rounded-lg px-3 py-2 text-sm font-bold text-slate-700 transition-colors">
                    <span class="text-base leading-none">{{ idiomasDisponibles.find(i => i.id === (file.idiomaCliente || 'es'))?.bandera ?? '🌐' }}</span>
                    <span class="flex-1 text-left">{{ idiomasDisponibles.find(i => i.id === (file.idiomaCliente || 'es'))?.nombre ?? (file.idiomaCliente || 'es') }}</span>
                    <i class="fas fa-chevron-down text-[9px] text-slate-400 transition-transform duration-200" :class="idiomaFileDropdown ? 'rotate-180' : ''"></i>
                  </button>
                  <div v-if="idiomaFileDropdown" class="absolute left-0 right-0 top-full mt-1 bg-white rounded-xl shadow-xl border border-slate-100 overflow-hidden z-50">
                    <button v-for="idi in idiomasDisponibles" :key="idi.id" type="button"
                        @click="file.idiomaCliente = idi.id; idiomaFileDropdown = false"
                        class="flex items-center gap-3 w-full px-3 py-2.5 text-left text-sm font-bold transition-colors hover:bg-slate-50"
                        :class="(file.idiomaCliente || 'es') === idi.id ? 'bg-[#376875]/5 text-[#376875]' : 'text-slate-700'">
                      <span class="text-base leading-none">{{ idi.bandera }}</span>
                      <span class="flex-1">{{ idi.nombre }}</span>
                      <i v-if="(file.idiomaCliente || 'es') === idi.id" class="fas fa-check text-[#376875] text-[10px]"></i>
                    </button>
                  </div>
                </div>
              </div>
              <div class="flex gap-2 mt-2">
                <button type="button" @click="cancelarEdicionFile"
                        class="px-4 py-3 border border-slate-200 text-slate-500 font-bold rounded-xl text-sm hover:bg-slate-50">
                  Cancelar
                </button>
                <button type="submit" :disabled="isSavingFile" class="flex-1 py-3 bg-slate-800 hover:bg-slate-900 text-white font-bold rounded-xl shadow">
                  <i v-if="isSavingFile" class="fas fa-spinner fa-spin mr-1"></i> Guardar Cambios
                </button>
              </div>
            </form>
          </div>
          </div>

          <div class="bg-white rounded-3xl p-6 border border-slate-200 shadow-sm">
            <div class="flex items-center justify-between mb-4 border-b pb-3">
              <h2 class="text-xs font-black text-slate-800 uppercase tracking-widest"><i class="fas fa-folder-open mr-1 text-sky-500"></i> Bóveda Digital</h2>
              <button @click="abrirDocModal" class="bg-sky-100 text-sky-700 px-2 py-1 rounded text-[10px] font-bold hover:bg-sky-200 shrink-0">+ Subir Doc</button>
            </div>

            <div v-if="!file.filearchivos?.length" class="bg-sky-50 border-2 border-dashed border-sky-200 rounded-2xl p-6 text-center text-sky-400">
              <i class="fas fa-cloud-upload-alt text-2xl mb-2 opacity-60"></i>
              <p class="text-[10px] font-bold uppercase tracking-widest">Bóveda vacía</p>
            </div>

            <div v-else class="space-y-2">
              <div v-for="doc in file.filearchivos" :key="doc.id" class="flex items-center gap-2 p-2 bg-slate-50 rounded-xl border border-slate-200 group relative">
                <a :href="doc.imageUrl || undefined" target="_blank" class="flex-1 flex items-center gap-3 min-w-0">
                  <div class="w-8 h-8 rounded bg-sky-100 text-sky-600 flex items-center justify-center text-sm shrink-0"><i class="far fa-file-pdf"></i></div>
                  <div class="min-w-0">
                    <p class="text-[11px] font-black text-slate-800 truncate">{{ getDocNombre(doc) || getArchivoLabel(doc.tipoArchivo) }}</p>
                    <p class="text-[9px] font-bold text-slate-400 uppercase truncate">{{ getArchivoLabel(doc.tipoArchivo) }}</p>
                  </div>
                </a>
                <button @click="abrirEdicionDoc(doc)" class="w-6 h-6 shrink-0 rounded-full bg-white border border-slate-200 text-slate-300 hover:text-indigo-500 hover:border-indigo-200 flex items-center justify-center transition-colors">
                  <i class="fas fa-pencil-alt text-xs"></i>
                </button>
                <button @click="eliminarDocumento(doc['@id'])" class="w-6 h-6 shrink-0 rounded-full bg-white border border-slate-200 text-slate-300 hover:text-red-500 hover:border-red-200 flex items-center justify-center transition-colors">
                  <i class="fas fa-times text-xs"></i>
                </button>
              </div>
            </div>
          </div>
        </aside>

        <section class="space-y-8 min-w-0">

          <div>
            <!-- ── Qué clase de expediente es ────────────────────────────────
                 UNA decisión de la que cuelga todo lo demás: padrón, precio por persona y acceso
                 identificado. Va en el EXPEDIENTE y no en la versión porque es propiedad del
                 negocio: la v2 de un viaje de colegio sigue siendo un viaje de colegio. -->
            <div class="mb-8 bg-white border border-slate-200 rounded-2xl p-4 shadow-sm flex flex-wrap items-center gap-3 justify-between">
              <div class="min-w-0">
                <h2 class="text-sm font-black text-slate-800 uppercase tracking-widest">
                  <i class="fas mr-2 text-slate-400" :class="FILE_MODO_CONFIG[file.modo || 'estandar']?.icon"></i>
                  Modo del expediente
                </h2>
                <p class="text-[10px] font-bold text-slate-400 mt-1 leading-snug max-w-lg">
                  {{ FILE_MODO_CONFIG[file.modo || 'estandar']?.ayuda }}
                </p>
              </div>
              <select :value="file.modo || 'estandar'" @change="cambiarModo(($event.target as HTMLSelectElement).value)"
                      class="border rounded-lg px-3 py-2 text-sm font-bold outline-none focus:border-teal-500 shrink-0">
                <option v-for="(cfg, valor) in FILE_MODO_CONFIG" :key="valor" :value="valor">{{ cfg.label }}</option>
              </select>
            </div>

            <div class="flex items-center justify-between mb-4">
              <h2 class="text-sm font-black text-slate-800 uppercase tracking-widest"><i class="fas fa-users mr-2 text-indigo-500"></i> Manifiesto de Pasajeros</h2>
              <button @click="abrirPaxModal" class="bg-indigo-600 text-white px-4 py-2 rounded-lg text-xs font-bold hover:bg-indigo-700 shadow-sm">+ Añadir Pax</button>
            </div>

            <div v-if="!file.filepasajeros?.length" class="bg-indigo-50 border-2 border-dashed border-indigo-200 rounded-3xl p-8 text-center text-indigo-400">
              <i class="fas fa-user-plus text-3xl mb-3 opacity-50"></i>
              <p class="text-xs font-bold uppercase tracking-widest">Sin pasajeros registrados</p>
            </div>

            <!-- ── Filtros ───────────────────────────────────────────────────
                 Se ACUMULAN: la pregunta real es una intersección —quién del grupo 5 va en el
                 vuelo de las 07:15—, no una lista de candidatos. -->
            <div v-else>
              <div class="mb-3 bg-slate-50 border border-slate-200 rounded-2xl p-3">
                <div class="flex flex-wrap items-center gap-2">
                  <div class="relative flex-1 min-w-52">
                    <i class="fas fa-search absolute left-3 top-1/2 -translate-y-1/2 text-slate-300 text-xs"></i>
                    <input v-model="busquedaPax" type="search" placeholder="Nombre, documento, JetSmart, HA50…"
                           class="w-full border rounded-lg pl-8 pr-3 py-2 text-xs outline-none focus:border-indigo-500 placeholder:text-slate-300">
                  </div>

                  <div class="w-full sm:w-56">
                    <SearchableSelect
                        v-model="grupoPorAnadir"
                        :options="gruposElegibles"
                        placeholder="+ Añadir subgrupo…"
                    />
                  </div>

                  <!-- El número al lado no es adorno: sin él, «ver no participa» es una casilla que
                       no se sabe si hace algo. Con «(2)» se entiende de qué se está hablando, y con
                       «(0)» ni se enseña. -->
                  <label v-if="totalNoParticipa"
                         class="flex items-center gap-1.5 text-[10px] font-bold text-slate-500 uppercase tracking-widest cursor-pointer">
                    <input v-model="incluirNoParticipa" type="checkbox" class="accent-indigo-600">
                    Ver «no participa» ({{ totalNoParticipa }})
                  </label>
                </div>

                <!-- Rol y aerolínea son FACETAS: se marcan varias y suman (O), porque nadie es
                     participante y coordinador a la vez ni vuela en dos aerolíneas el mismo tramo.
                     Entre facetas distintas manda la Y. Sólo salen si hay más de una opción. -->
                <div v-if="Object.keys(conteoPorRol).length > 1" class="flex flex-wrap items-center gap-1.5 mt-2">
                  <span class="text-[9px] font-black text-slate-400 uppercase tracking-widest mr-1">Rol</span>
                  <button v-for="(n, rol) in conteoPorRol" :key="rol" type="button"
                          @click="alternarRol(String(rol))"
                          class="inline-flex items-center gap-1.5 rounded-lg border px-2 py-1 text-[10px] font-black transition-colors"
                          :class="filtroRol.includes(String(rol))
                            ? PASAJERO_TIPO_CONFIG[String(rol)]?.clase ?? 'bg-slate-100 text-slate-600 border-slate-200'
                            : 'bg-white text-slate-500 border-slate-200 hover:border-slate-300'">
                    <!-- El punto lleva el color aunque la píldora esté apagada: es lo que enseña
                         a reconocer el color antes de haberlo usado nunca. -->
                    <span class="w-1.5 h-1.5 rounded-full" :class="PASAJERO_TIPO_CONFIG[String(rol)]?.punto ?? 'bg-slate-400'"></span>
                    {{ PASAJERO_TIPO_CONFIG[String(rol)]?.label ?? rol }} <span class="opacity-60">{{ n }}</span>
                  </button>
                </div>

                <div v-for="grupo in aerolineasPorEje" :key="grupo.eje" class="flex flex-wrap items-center gap-1.5 mt-2">
                  <span class="text-[9px] font-black text-slate-400 uppercase tracking-widest mr-1">
                    <i class="fas fa-plane-departure mr-0.5"></i>{{ grupo.label.replace('Reserva aérea', '').trim() || 'Vuelo' }}
                  </span>
                  <button v-for="nombre in grupo.nombres" :key="nombre" type="button"
                          @click="alternarAerolinea(`${grupo.eje}|${nombre}`)"
                          class="rounded-lg border px-2 py-1 text-[10px] font-black transition-colors"
                          :class="filtroAerolinea.includes(`${grupo.eje}|${nombre}`)
                            ? 'bg-sky-600 text-white border-sky-600'
                            : 'bg-white text-slate-500 border-slate-200 hover:border-sky-300'">
                    {{ nombre }}
                  </button>
                </div>

                <div v-if="gruposFiltrados.length" class="flex flex-wrap gap-1.5 mt-2">
                  <span v-for="iri in gruposFiltrados" :key="iri"
                        class="inline-flex items-center gap-1 bg-indigo-50 text-indigo-700 border border-indigo-200 rounded-lg pl-2.5 pr-1 py-1 text-[11px] font-black">
                    {{ [grupoDeIri(iri)?.clave, grupoDeIri(iri)?.nombre].filter(Boolean).join(' · ') }}
                    <button type="button" @click="quitarFiltro(iri)" class="px-1 hover:text-red-500"><i class="fas fa-times text-[10px]"></i></button>
                  </span>
                </div>

                <div class="flex items-center justify-between mt-2">
                  <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest">
                    Mostrando {{ pasajerosFiltrados.length }} de {{ pasajerosConsiderados.length }}
                    <span v-if="!incluirNoParticipa && totalNoParticipa" class="text-slate-300 normal-case font-bold">
                      · {{ totalNoParticipa }} no participa{{ totalNoParticipa > 1 ? 'n' : '' }}, fuera de la cuenta
                    </span>
                  </p>
                  <button v-if="hayFiltros" type="button" @click="limpiarFiltros"
                          class="text-[10px] font-black uppercase tracking-widest text-indigo-500 hover:text-indigo-700">
                    Limpiar filtros
                  </button>
                </div>
              </div>

              <p v-if="!pasajerosFiltrados.length" class="text-[11px] text-slate-400 italic border border-dashed border-slate-200 rounded-2xl px-4 py-6 text-center">
                Nadie cumple estos filtros.
              </p>

              <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
              <!-- La tarjeta ENTERA abre la ficha en lectura: en un móvil el blanco es la mitad de
                   la tarjeta y apuntar a un icono de 28 px con el pulgar es la parte incómoda.
                   La plumita entra directa a editar; los dos botones paran la propagación para no
                   disparar además la apertura de la tarjeta. -->
              <div v-for="(pax, idx) in pasajerosFiltrados" :key="pax.id"
                   @click="abrirEdicionPax(pax)"
                   class="bg-white p-4 rounded-2xl border border-slate-200 shadow-sm relative group cursor-pointer hover:border-indigo-300 hover:shadow-md transition-all">
                <div class="absolute top-3 right-3 flex items-center gap-1">
                  <button @click.stop="abrirEdicionPax(pax, true)" title="Editar"
                          class="text-slate-300 hover:text-indigo-500 transition-colors bg-slate-50 w-7 h-7 rounded-full flex items-center justify-center">
                    <i class="fas fa-pencil-alt text-xs"></i>
                  </button>
                  <button @click.stop="eliminarPasajero(pax['@id'])" title="Eliminar"
                          class="text-slate-300 hover:text-red-500 transition-colors bg-slate-50 w-7 h-7 rounded-full flex items-center justify-center">
                    <i class="fas fa-trash-alt text-xs"></i>
                  </button>
                </div>
                <div class="flex items-start gap-3 pr-16">
                  <div class="w-8 h-8 rounded-full bg-indigo-100 text-indigo-600 font-black text-xs flex items-center justify-center border border-indigo-200">{{ idx + 1 }}</div>
                  <div>
                    <h3 class="text-sm font-black text-slate-800 leading-tight">{{ pax.nombre }} {{ pax.apellido }}</h3>
                    <div class="flex flex-wrap gap-1 mt-2">
                      <!-- ⚠️ El ROL primero. «Adulto PR» es la tarifa de PeruRail —adulto o niño para
                           el tren— y no dice si es coordinador, supervisor o participante, que es
                           lo que se busca al mirar la lista. Cada rol con su color: en 131 fichas,
                           encontrar a los 9 coordinadores leyendo texto es imposible. -->
                      <span v-if="pax.tipo" class="text-[9px] font-black px-1.5 py-0.5 rounded border uppercase tracking-wide"
                            :class="PASAJERO_TIPO_CONFIG[String(pax.tipo)]?.clase ?? 'bg-slate-100 text-slate-600 border-slate-200'">
                        {{ PASAJERO_TIPO_CONFIG[String(pax.tipo)]?.label ?? pax.tipo }}
                      </span>
                      <span class="text-[9px] font-bold bg-slate-100 text-slate-600 px-1.5 py-0.5 rounded border border-slate-200 uppercase">{{ pax.tipopaxperurail === 1 ? 'Adulto' : 'Niño' }} PR</span>
                      <span v-if="pax.edad" class="text-[9px] font-bold bg-indigo-50 text-indigo-600 px-1.5 py-0.5 rounded border border-indigo-100 uppercase">{{ pax.edad }} Años</span>
                      <!-- El grupo, a primera vista. Es la unidad con la que se opera todos los
                           días y estaba sólo dentro de la ficha, a dos toques. -->
                      <span v-for="e in ejesDePax(pax)" :key="e.id"
                            class="text-[9px] font-black px-1.5 py-0.5 rounded border uppercase tracking-wide"
                            :class="e.destacado
                              ? 'bg-teal-600 text-white border-teal-600'
                              : 'bg-white text-slate-500 border-slate-200'">
                        <i class="fas mr-0.5 text-[8px]" :class="e.icono"></i>{{ e.texto }}
                      </span>
                    </div>
                    <p class="text-[9px] text-slate-400 font-bold uppercase mt-2">
                      <i class="fas fa-globe-americas"></i> {{ pax.pais?.nombre }} ({{ getSexoLabel(pax.sexo) }})<br>
                      <span v-if="pax.telefono" class="block text-[10px] font-bold text-slate-400">
                        <i class="fas fa-phone text-[9px] mr-1"></i>{{ formatearTelefono(pax.telefono) }}
                      </span>
                      <i class="far fa-id-card mt-1"></i>
                      <span v-for="(ident, i) in (pax.identificaciones ?? [])" :key="ident.id || i">
                        <span v-if="i"> · </span>{{ getDocIdLabel(ident.tipo) }}: {{ ident.numero }}
                      </span>
                    </p>
                    <!-- El vuelo, en la propia ficha: era el dato que había que ir a buscar abriendo
                         a cada persona, y es justo el que se mira para armar el aeropuerto. -->
                    <p v-for="v in vuelosDe(pax)" :key="v.id" class="text-[9px] font-bold text-sky-600 mt-1">
                      <i class="fas fa-plane-departure text-[8px] mr-1"></i>{{ v.tramo }}
                      <span class="text-slate-500">{{ v.nombre }}</span>
                      <span class="text-slate-300"> · </span>{{ v.clave }}
                    </p>
                  </div>
                </div>
              </div>
              </div>
            </div>
          </div>

            <!-- ── PADRÓN: plantilla y carga ────────────────────────────────
                 ⚠️ FUERA del modo grupo: cargar un namelist de dos personas es tan válido como
                 cargar 133, y esconderlo en modo normal obligaba a teclear a mano lo que ya está
                 en una hoja. Lo que sí es exclusivo de un grupo son los SUBGRUPOS, abajo. -->
            <div class="mb-8">
              <div class="flex items-center justify-between mb-4 gap-3 flex-wrap">
                <h2 class="text-sm font-black text-slate-800 uppercase tracking-widest">
                  <i class="fas fa-file-import mr-2 text-teal-500"></i> Cargar pasajeros desde una hoja
                </h2>
                <div class="flex items-center gap-2 flex-wrap">
                  <button @click="descargarPlantilla" :disabled="descargandoPlantilla"
                          title="En blanco, con hoja de instrucciones y tablas de países, sexo y roles"
                          class="flex items-center gap-2 bg-white border border-slate-200 text-slate-600 px-3 py-1.5 rounded-lg text-[11px] font-bold shadow-sm hover:bg-slate-50 transition-colors disabled:opacity-50">
                    <i class="fas" :class="descargandoPlantilla ? 'fa-spinner fa-spin' : 'fa-file-arrow-down'"></i>
                    Plantilla en blanco
                  </button>
                  <!-- La descarga con datos es lo que hace cómodo completar un padrón a medias:
                       trae el `Id` de cada persona, así que al resubirla nadie se duplica aunque
                       le hayas corregido el nombre o el pasaporte. -->
                  <button v-if="file.filepasajeros?.length" @click="descargarCargado" :disabled="descargandoPlantilla"
                          title="La misma hoja, ya rellena con los pasajeros cargados. Complétala y vuelve a subirla."
                          class="flex items-center gap-2 bg-teal-50 border border-teal-200 text-teal-700 px-3 py-1.5 rounded-lg text-[11px] font-bold shadow-sm hover:bg-teal-100 transition-colors disabled:opacity-50">
                    <i class="fas fa-file-pen"></i>
                    Descargar con lo cargado ({{ file.filepasajeros.length }})
                  </button>
                </div>
              </div>
              <!-- ── Cargar el padrón ──────────────────────────────────────
                   Siempre ENSAYO primero, y el informe dice en qué expediente va a escribir: cargar
                   133 personas en el que no toca es un error caro y silencioso. -->
              <div class="mb-4 border border-dashed border-teal-300 bg-teal-50/50 rounded-2xl p-4">
                <div class="flex flex-wrap items-center justify-between gap-3">
                  <div class="min-w-0">
                    <p class="text-[11px] font-black text-teal-700 uppercase tracking-widest">
                      <i class="fas fa-file-import mr-1"></i> Cargar padrón
                    </p>
                    <p class="text-[10px] font-bold text-teal-600/70 mt-0.5">
                      Crea pasajeros, documentos y subgrupos de una vez. Se ensaya antes de guardar.
                    </p>
                  </div>
                  <input ref="inputPadron" type="file" accept=".xlsx,.xls" class="hidden" @change="elegirPadron">
                  <button @click="inputPadron?.click()" :disabled="cargandoPadron"
                          class="bg-teal-600 text-white px-4 py-2 rounded-lg text-xs font-bold hover:bg-teal-700 shadow-sm disabled:opacity-50 shrink-0">
                    <i class="fas mr-1" :class="cargandoPadron ? 'fa-spinner fa-spin' : 'fa-upload'"></i>
                    Elegir archivo…
                  </button>
                </div>

                <!-- El informe del ensayo -->
                <div v-if="ensayoPadron" class="mt-4 bg-white border border-slate-200 rounded-xl p-4">
                  <p class="text-[10px] font-black uppercase tracking-widest mb-2"
                     :class="ensayoPadron.errores.length ? 'text-red-600' : 'text-slate-500'">
                    {{ ensayoPadron.errores.length ? 'No se puede cargar' : 'Ensayo' }} ·
                    <span class="text-slate-700">{{ ensayoPadron.expediente }}</span>
                  </p>

                  <div v-if="!ensayoPadron.errores.length" class="grid grid-cols-2 sm:grid-cols-3 gap-2 mb-3">
                    <p v-for="[etiqueta, valor] in [
                         ['Filas leídas', ensayoPadron.filasLeidas],
                         ['Pasajeros nuevos', ensayoPadron.pasajerosCreados],
                         ['Actualizados', ensayoPadron.pasajerosActualizados],
                         ['Documentos', ensayoPadron.identificacionesCreadas],
                         ['Subgrupos nuevos', ensayoPadron.gruposCreados],
                         ['Pertenencias', ensayoPadron.pertenenciasCreadas],
                       ]" :key="etiqueta" class="text-[11px] font-bold text-slate-500">
                      {{ etiqueta }}: <span class="text-slate-800 font-black tabular-nums">{{ valor }}</span>
                    </p>
                  </div>

                  <p v-if="ensayoPadron.pertenenciasQuitadas" class="text-[10px] font-bold text-amber-600 mb-2">
                    <i class="fas fa-arrow-right-from-bracket mr-1"></i>
                    {{ ensayoPadron.pertenenciasQuitadas }} pertenencia(s) se quitarán: el archivo dice que ya no participan.
                  </p>

                  <p v-for="e in ensayoPadron.errores" :key="e" class="text-[11px] font-bold text-red-600 leading-snug mb-1">
                    <i class="fas fa-circle-exclamation mr-1"></i>{{ e }}
                  </p>
                  <p v-for="a in ensayoPadron.avisos" :key="a" class="text-[10px] font-bold text-slate-400 leading-snug mb-1">
                    <i class="fas fa-circle-info mr-1"></i>{{ a }}
                  </p>
                  <p v-if="ensayoPadron.noEstanEnElArchivo.length" class="text-[10px] font-bold text-amber-600 leading-snug mb-1">
                    <i class="fas fa-user-slash mr-1"></i>
                    {{ ensayoPadron.noEstanEnElArchivo.length }} persona(s) están aquí y no en el archivo.
                    <b>No se borran</b>: {{ ensayoPadron.noEstanEnElArchivo.slice(0, 5).join(', ') }}{{ ensayoPadron.noEstanEnElArchivo.length > 5 ? '…' : '' }}
                  </p>

                  <div class="flex gap-2 mt-3 pt-3 border-t border-slate-100">
                    <button @click="aplicarPadron" :disabled="cargandoPadron || ensayoPadron.errores.length > 0"
                            class="bg-teal-600 text-white px-4 py-2 rounded-lg text-xs font-bold hover:bg-teal-700 disabled:opacity-40 disabled:cursor-not-allowed">
                      <i class="fas fa-check mr-1"></i> Procesar carga
                    </button>
                    <button @click="cancelarPadron" class="px-4 py-2 text-xs font-bold text-slate-500 border rounded-lg hover:bg-slate-50">
                      Cancelar
                    </button>
                  </div>
                </div>
              </div>

            </div>

            <!-- ── Subgrupos ─────────────────────────────────────────────────
                 Ejes cruzados, no un árbol: en un padrón real 9 de cada 10 grupos aparecen en más
                 de un salón, así que una persona pertenece a varios a la vez. Se definen aquí y se
                 asignan en la ficha de cada pasajero. -->
            <!-- ⚠️ PLEGADA por defecto, y el resumen basta.
                 Los subgrupos se crean solos al cargar el padrón y casi nunca se tocan a mano: son
                 el andamio, no la obra. Desplegados ocupaban tres pantallas de scroll entre el
                 manifiesto y lo que viene después, y quien baja a esta zona va a otra cosa. El
                 rótulo dice de un vistazo lo que hay dentro, que es lo único que se consulta a
                 diario. -->
            <div v-if="file.usaPadron" class="mb-8">
              <button type="button" @click="subgruposAbiertos = !subgruposAbiertos"
                      class="w-full flex items-center justify-between gap-3 mb-4 text-left group">
                <h2 class="text-sm font-black text-slate-800 uppercase tracking-widest min-w-0">
                  <i class="fas fa-layer-group mr-2 text-teal-500"></i> Subgrupos
                  <span class="text-slate-300 font-bold normal-case tracking-normal ml-1">{{ resumenSubgrupos }}</span>
                </h2>
                <i class="fas fa-chevron-down text-slate-400 text-xs transition-transform shrink-0 group-hover:text-teal-500"
                   :class="subgruposAbiertos ? 'rotate-180' : ''"></i>
              </button>

              <div v-if="subgruposAbiertos" class="flex flex-wrap gap-2 items-end bg-slate-50 border border-slate-200 rounded-2xl p-3 mb-4">
                <div>
                  <label class="block text-[9px] font-black text-slate-400 uppercase tracking-widest mb-1">Eje</label>
                  <select v-model="nuevoGrupo.tipo" class="border rounded-lg px-2 py-2 text-sm outline-none focus:border-teal-500">
                    <option v-for="(cfg, valor) in GRUPO_TIPO_LABELS" :key="valor" :value="valor">{{ cfg.label }}</option>
                  </select>
                </div>
                <div>
                  <label class="block text-[9px] font-black text-slate-400 uppercase tracking-widest mb-1">Clave</label>
                  <input v-model="nuevoGrupo.clave" type="text" placeholder="B · 5 · HA13 · JA2CWN" maxlength="60"
                         @keyup.enter="agregarGrupo"
                         class="w-40 border rounded-lg px-3 py-2 text-sm font-bold uppercase outline-none focus:border-teal-500 placeholder:font-normal placeholder:normal-case placeholder:text-slate-300">
                </div>
                <div class="flex-1 min-w-40">
                  <label class="block text-[9px] font-black text-slate-400 uppercase tracking-widest mb-1">Nombre (opcional)</label>
                  <input v-model="nuevoGrupo.nombre" type="text" placeholder="ARAJET · DOBLE" maxlength="150"
                         @keyup.enter="agregarGrupo"
                         class="w-full border rounded-lg px-3 py-2 text-sm outline-none focus:border-teal-500 placeholder:text-slate-300">
                </div>
                <button @click="agregarGrupo" :disabled="creandoGrupo || !nuevoGrupo.clave.trim()"
                        class="bg-teal-600 text-white px-4 py-2 rounded-lg text-xs font-bold hover:bg-teal-700 shadow-sm disabled:opacity-40 disabled:cursor-not-allowed">
                  <i class="fas fa-spinner fa-spin" v-if="creandoGrupo"></i>
                  <span v-else>+ Añadir</span>
                </button>

                <!-- A lo ancho y en varias líneas: el itinerario no es un rótulo, es lo que se
                     consulta para comprobar un horario. Metido en «Nombre» convertiría la píldora
                     del pasajero en un párrafo. -->
                <div class="w-full">
                  <label class="block text-[9px] font-black text-slate-400 uppercase tracking-widest mb-1">
                    Detalle (opcional) — varias líneas, admite *negrita* y listas
                  </label>
                  <textarea v-model="nuevoGrupo.detalle" rows="2"
                            placeholder="Ida DM6771 · LIM 18/09/2026 03:00 → PUJ 18/09/2026 09:19&#10;Retorno DM6770 · PUJ 22/09/2026 20:22 → LIM 23/09/2026 00:30"
                            class="w-full border rounded-lg px-3 py-2 text-xs outline-none focus:border-teal-500 placeholder:text-slate-300"></textarea>
                </div>
              </div>

              <p v-if="!file.grupos?.length" class="text-[11px] text-slate-400 italic border border-dashed border-slate-200 rounded-2xl px-4 py-3">
                Sin subgrupos. Se crean solos al cargar el padrón, o a mano aquí arriba.
              </p>

              <!-- ⚠️ La papelera de cada subgrupo va detrás de un interruptor, y no es ceremonia.
                   Con 66 habitaciones había 66 botones de borrar al alcance del pulgar, en la
                   misma píldora que se toca para leer el conteo. Un roce y se va un subgrupo con
                   sus pertenencias. En modo lectura la píldora no hace nada. -->
              <div v-else class="space-y-3">
                <div class="flex items-center justify-end">
                  <button type="button" @click="modoGestionGrupos = !modoGestionGrupos"
                          class="text-[10px] font-black uppercase tracking-widest transition-colors"
                          :class="modoGestionGrupos ? 'text-red-500 hover:text-red-700' : 'text-slate-400 hover:text-indigo-600'">
                    <i class="fas mr-1" :class="modoGestionGrupos ? 'fa-lock-open' : 'fa-lock'"></i>
                    {{ modoGestionGrupos ? 'Terminar de borrar' : 'Borrar subgrupos' }}
                  </button>
                </div>

                <div v-for="(lista, tipo) in gruposPorTipo" :key="tipo">
                  <div class="flex items-center justify-between mb-1.5">
                    <p class="text-[9px] font-black text-slate-400 uppercase tracking-widest">
                      <i class="fas" :class="GRUPO_TIPO_LABELS[tipo]?.icon"></i>
                      {{ GRUPO_TIPO_LABELS[tipo]?.label || tipo }} ({{ lista.length }})
                    </p>
                    <button v-if="lista.length > TOPE_PILDORAS" type="button" @click="alternarEje(`lista-${tipo}`)"
                            class="text-[9px] font-black uppercase tracking-widest text-indigo-500 hover:text-indigo-700">
                      {{ ejeEstaAbierto(`lista-${tipo}`, lista.length) ? 'Plegar' : `Ver las ${lista.length}` }}
                    </button>
                  </div>
                  <div class="flex flex-wrap gap-2">
                    <span v-for="g in (ejeEstaAbierto(`lista-${tipo}`, lista.length) ? lista : lista.slice(0, TOPE_PILDORAS))" :key="g.id"
                          class="inline-flex items-center gap-2 bg-white border border-slate-200 rounded-lg pl-3 py-1 shadow-sm"
                          :class="modoGestionGrupos ? 'pr-1 border-red-200' : 'pr-3'">
                      <span class="text-[11px] font-black text-slate-700">{{ g.clave }}</span>
                      <span v-if="g.nombre" class="text-[10px] font-medium text-slate-400">{{ g.nombre }}</span>
                      <!-- El conteo se calcula aquí y no se toma de `totalMiembros`: el del servidor
                           incluye a los «no participa», que conservan grupo y reservas aéreas. -->
                      <span class="text-[10px] font-bold text-slate-400">{{ contarEnGrupo(g) }} pax</span>
                      <button v-if="modoGestionGrupos" @click="borrarGrupo(g)"
                              class="w-5 h-5 rounded text-red-300 hover:text-red-600 hover:bg-red-50 transition-colors">
                        <i class="fas fa-times text-[10px]"></i>
                      </button>
                    </span>
                    <span v-if="!ejeEstaAbierto(`lista-${tipo}`, lista.length)" class="text-[10px] font-bold text-slate-300 italic py-1.5">
                      +{{ lista.length - TOPE_PILDORAS }} más
                    </span>
                  </div>
                </div>
              </div>
            </div>


          <div>
            <h2 class="text-sm font-black text-slate-800 uppercase tracking-widest mb-4"><i class="fas fa-code-branch mr-2 text-[#E07845]"></i> Historial de Versiones</h2>

            <div v-if="!file.cotizaciones || file.cotizaciones.length === 0" class="bg-white border-2 border-dashed border-slate-300 rounded-3xl p-12 text-center text-slate-400">
              <i class="fas fa-clipboard-list text-4xl mb-4 opacity-50"></i>
              <p class="text-sm font-bold uppercase tracking-widest">No hay cotizaciones</p>
              <p class="text-xs mt-2 font-medium">Haz clic en "Crear Nueva Versión" para arrancar el motor operativo.</p>
            </div>

            <div v-else v-for="cot in versionesVivas" :key="cot.id" class="bg-white rounded-2xl p-4 sm:p-5 border border-slate-200 shadow-sm hover:border-[#376875] transition-colors group mb-4">

              <!-- 1. CABECERA: Versión, Estado y Botones -->
              <div class="flex flex-wrap sm:flex-nowrap items-start justify-between gap-3 mb-4">

                <!-- Izquierda: Versión y Estado -->
                <div class="flex items-center gap-3">
                  <!-- Badge de versión, editable -->
                  <div v-if="editandoVersion !== (cot['@id'] || cot.id)"
                       @click="iniciarEdicionVersion(cot)"
                       class="w-12 h-12 rounded-full bg-slate-100 flex items-center justify-center font-black text-slate-700 text-lg border-2 border-white shadow-sm group-hover:bg-[#376875] group-hover:text-white transition-colors cursor-pointer shrink-0"
                       title="Click para editar versión">
                    V{{ cot.version }}
                  </div>
                  <div v-else class="flex items-center gap-1 shrink-0">
                    <input v-model.number="versionTemp" type="number" min="1"
                           class="w-14 h-12 text-center font-black rounded-full border-2 border-[#376875] outline-none"
                           @keyup.enter="guardarVersion(cot)" @keyup.esc="editandoVersion = null">
                    <button @click="guardarVersion(cot)" class="text-emerald-600 w-8 h-8 flex items-center justify-center bg-emerald-50 rounded-full"><i class="fas fa-check"></i></button>
                    <button @click="editandoVersion = null" class="text-slate-400 w-8 h-8 flex items-center justify-center bg-slate-100 rounded-full"><i class="fas fa-times"></i></button>
                  </div>

                  <div class="min-w-0">
                    <div class="flex items-center gap-2 leading-none flex-wrap">
                      <p class="text-sm sm:text-base font-black text-slate-800">
                        {{ t18(cot.titulo) || `Versión ${cot.version}` }}
                      </p>
                      <span class="text-[9px] font-black bg-slate-100 text-slate-500 px-1.5 py-0.5 rounded border border-slate-200 uppercase shrink-0">{{ cot.estado || 'Pendiente' }}</span>
                      <span class="text-[9px] font-black bg-orange-50 text-orange-600 px-1.5 py-0.5 rounded border border-orange-100 uppercase shrink-0">{{ cot.monedaGlobal || 'USD' }}</span>
                    </div>
                    <p v-if="cot.resumen" class="text-[10px] text-slate-400 font-medium mt-1 truncate max-w-35 sm:max-w-xs">
                      {{ resumenPreview(cot.resumen) }}
                    </p>
                  </div>
                </div>

                <!-- Derecha: Botones de Acción -->
                <div class="flex items-center gap-2 w-full sm:w-auto justify-end mt-2 sm:mt-0">
                  <button @click="abrirMotor(cot)" class="px-4 py-2 bg-[#E07845] text-white text-xs font-bold rounded-xl shadow-sm hover:bg-[#c96636] transition-colors flex items-center gap-2">
                    Editar <i class="fas fa-arrow-right"></i>
                  </button>

                  <a :href="linkPublicoVersion(cot.version)" target="_blank" rel="noopener"
                     class="w-9 h-9 flex items-center justify-center rounded-xl border border-slate-200 text-slate-400 hover:text-emerald-500 hover:border-emerald-200 hover:bg-emerald-50 transition-colors"
                     :title="`Abrir vista cliente (V${cot.version})`">
                    <i class="fas fa-external-link-alt text-xs"></i>
                  </a>

                  <!-- Sólo en la versión confirmada: es la única que genera operación.
                       El backend lo vuelve a validar (422 si no está confirmada). -->
                  <button v-if="cot.estado === 'confirmado'"
                          @click="abrirPlanOperacion(cot)"
                          class="w-9 h-9 flex items-center justify-center rounded-xl border border-[#376875]/30 text-[#376875] hover:bg-[#376875] hover:text-white transition-colors"
                          title="Revisar los cambios de esta versión en el Centro de Operaciones">
                    <i class="fas fa-code-compare text-xs"></i>
                  </button>

                  <!-- Guardar histórico vive AL LADO de clonar porque son la misma operación en
                       direcciones opuestas, y confundirlas es lo caro: clonar crea la versión
                       siguiente y deja ésta atrás —bien antes de vender—; esto congela una foto y
                       deja ésta viva con sus órdenes. Ver docs/Cotizaciones.md §6.j. -->
                  <button @click="guardarHistorico(cot)" :disabled="guardandoHistorico === extractIdStr(cot.id || cot['@id'])"
                          class="w-9 h-9 flex items-center justify-center rounded-xl border border-slate-200 text-slate-400 hover:text-violet-500 hover:border-violet-200 hover:bg-violet-50 transition-colors disabled:opacity-50"
                          title="Guardar una foto de cómo está ahora, antes de modificarla">
                    <i class="fas fa-spinner fa-spin text-xs" v-if="guardandoHistorico === extractIdStr(cot.id || cot['@id'])"></i>
                    <i class="fas fa-camera text-xs" v-else></i>
                  </button>

                  <button @click="clonarVersion(cot)" :disabled="clonandoItem === extractIdStr(cot.id || cot['@id'])"
                          class="w-9 h-9 flex items-center justify-center rounded-xl border border-slate-200 text-slate-400 hover:text-sky-500 hover:border-sky-200 hover:bg-sky-50 transition-colors disabled:opacity-50"
                          title="Clonar esta versión">
                    <i class="fas fa-spinner fa-spin text-xs" v-if="clonandoItem === extractIdStr(cot.id || cot['@id'])"></i>
                    <i class="fas fa-copy text-xs" v-else></i>
                  </button>

                  <button @click="eliminarVersion(cot)" :disabled="eliminandoItem === (cot['@id'] || cot.id)"
                          class="w-9 h-9 flex items-center justify-center rounded-xl border border-slate-200 text-slate-400 hover:text-red-500 hover:border-red-200 hover:bg-red-50 transition-colors disabled:opacity-50">
                    <i class="fas fa-spinner fa-spin text-xs" v-if="eliminandoItem === (cot['@id'] || cot.id)"></i>
                    <i class="fas fa-trash-alt text-xs" v-else></i>
                  </button>
                </div>
              </div>

              <!-- 2. PANEL DE MÉTRICAS (Grid separada) -->
              <div class="grid grid-cols-4 gap-2 sm:gap-4 bg-slate-50 border border-slate-100 rounded-xl p-3 sm:p-4 mt-2">

                <!-- Pax -->
                <div class="flex flex-col">
                  <span class="text-[9px] font-bold text-slate-400 uppercase tracking-widest mb-1">
                    <i class="fas fa-users mr-1"></i> Pax
                  </span>
                  <span class="text-xs sm:text-sm font-black text-slate-700">
                    {{ cot.numPax ?? '—' }}
                  </span>
                </div>

                <!-- Venta -->
                <div class="flex flex-col border-l border-slate-200 pl-3 sm:pl-4">
                  <span class="text-[9px] font-bold text-slate-400 uppercase tracking-widest mb-1">
                    <i class="fas fa-money-bill mr-1"></i> Venta
                  </span>
                  <span class="text-xs sm:text-sm font-black text-slate-800">
                    <span class="text-[9px] font-bold text-slate-400 mr-0.5">{{ cot.monedaGlobal }}</span>
                    {{ cot.totalVenta ?? '0.00' }}
                  </span>
                </div>

                <!-- Ganancia -->
                <div class="flex flex-col border-l border-slate-200 pl-3 sm:pl-4">
                  <span class="text-[9px] font-bold text-emerald-600/70 uppercase tracking-widest mb-1">
                    <i class="fas fa-chart-line mr-1"></i> Ganancia
                  </span>
                  <span class="text-xs sm:text-sm font-black text-emerald-600">
                    <span class="text-[9px] font-bold text-emerald-600/60 mr-0.5">{{ cot.monedaGlobal }}</span>
                    {{ cot.ganancia ?? '0.00' }}
                  </span>
                </div>

                <!-- Idioma -->
                <div class="flex flex-col border-l border-slate-200 pl-3 sm:pl-4">
                  <span class="text-[9px] font-bold text-slate-400 uppercase tracking-widest mb-1">
                    <i class="fas fa-language mr-1"></i> Idioma
                  </span>
                  <span class="text-xs sm:text-sm font-black text-slate-700">
                    {{ idiomasDisponibles.find(i => i.id === cot.idiomaCliente)?.bandera ?? '🌐' }}
                    <span class="text-[10px] uppercase text-slate-500">{{ cot.idiomaCliente || 'es' }}</span>
                  </span>
                </div>

              </div>

              <!-- ── Las fotos del pasado de esta versión ────────────────────
                   Plegadas: lo normal es que no interesen, y desplegadas empujarían las versiones
                   vivas fuera de la pantalla. Se identifican por FECHA y no por número, porque
                   comparten el de su versión a propósito. -->
              <div v-if="historicosDe(cot).length" class="mt-3 pt-3 border-t border-slate-100">
                <button @click="alternarHistoricos(extractIdStr(cot.id || cot['@id']) || '')"
                        class="flex items-center gap-2 text-[10px] font-black uppercase tracking-widest text-violet-500 hover:text-violet-700 transition-colors">
                  <i class="fas fa-camera"></i>
                  {{ historicosDe(cot).length }} histórico{{ historicosDe(cot).length === 1 ? '' : 's' }}
                  <i class="fas text-[8px]" :class="historicosAbiertos.has(extractIdStr(cot.id || cot['@id']) || '') ? 'fa-chevron-up' : 'fa-chevron-down'"></i>
                </button>

                <div v-if="historicosAbiertos.has(extractIdStr(cot.id || cot['@id']) || '')" class="mt-2 space-y-1.5">
                  <div v-for="h in historicosDe(cot)" :key="h.id"
                       class="flex items-center justify-between gap-3 bg-violet-50/60 border border-violet-100 rounded-lg px-3 py-2">
                    <span class="text-[11px] font-bold text-violet-800 min-w-0 truncate">
                      <i class="fas fa-clock-rotate-left text-[9px] mr-1.5 text-violet-400"></i>
                      V{{ h.version }} · {{ h.createdAt ? new Date(h.createdAt).toLocaleString('es-PE', { dateStyle: 'medium', timeStyle: 'short' }) : 'sin fecha' }}
                    </span>
                    <button @click="abrirMotor(h)"
                            class="text-[10px] font-black text-violet-600 hover:text-violet-900 underline underline-offset-2 shrink-0">
                      Ver
                    </button>
                  </div>
                </div>
              </div>


            </div>

          </div>

        </section>
      </div>
    </main>
  </div>

  <Teleport to="body">
    <div v-if="showPaxModal" class="fixed inset-0 z-1000 bg-slate-900/60 backdrop-blur-sm flex items-center justify-center p-4">
      <!-- `overflow-visible` seguía haciendo falta: el desplegable de SearchableSelect se
           teletransporta a `body` con `fixed`, así que no lo recorta nada. Lo que se añade es el
           tope en `dvh` —que sí encoge con el teclado del móvil— y el scroll del cuerpo. -->
      <div class="panel-giratorio w-full max-w-lg">
      <div class="bg-white w-full rounded-3xl shadow-2xl overflow-visible flex flex-col max-h-[calc(100dvh-2rem)] cara"
           :class="{ 'de-canto': girandoPax }">
        <div class="bg-indigo-600 px-6 py-4 flex justify-between items-center text-white rounded-t-3xl shrink-0 gap-3">
          <div class="min-w-0">
            <h3 class="font-black text-sm uppercase tracking-widest truncate">
              {{ paxEditandoIri ? (paxForm.nombre || 'Editar Pasajero') : 'Nuevo Pasajero' }}
            </h3>
            <p v-if="indiceEnFiltrados >= 0" class="text-[10px] font-bold text-indigo-200 uppercase tracking-widest">
              {{ indiceEnFiltrados + 1 }} de {{ pasajerosFiltrados.length }}
            </p>
          </div>
          <div class="flex items-center gap-1 shrink-0">
            <!-- Recorrer sin cerrar: se repasa a los 18 de «Copa internacional» seguidos, en vez
                 de abrir y cerrar 18 veces buscándolos en la lista. -->
            <button v-if="indiceEnFiltrados >= 0" type="button" @click="saltarA(-1)" :disabled="!hayAnterior"
                    title="Anterior" class="w-8 h-8 rounded-lg text-indigo-100 hover:bg-indigo-500 disabled:opacity-30 disabled:hover:bg-transparent">
              <i class="fas fa-chevron-left text-xs"></i>
            </button>
            <button v-if="indiceEnFiltrados >= 0" type="button" @click="saltarA(1)" :disabled="!haySiguiente"
                    title="Siguiente" class="w-8 h-8 rounded-lg text-indigo-100 hover:bg-indigo-500 disabled:opacity-30 disabled:hover:bg-transparent">
              <i class="fas fa-chevron-right text-xs"></i>
            </button>
            <button v-if="modoVistaPax && paxEditandoIri" type="button" @click="girarPanelPax(false)"
                    title="Editar" class="w-8 h-8 rounded-lg text-indigo-100 hover:bg-indigo-500 ml-1">
              <i class="fas fa-pencil-alt text-xs"></i>
            </button>
            <button @click="showPaxModal = false" class="w-8 h-8 rounded-lg text-indigo-200 hover:text-white hover:bg-indigo-500 ml-1">
              <i class="fas fa-times"></i>
            </button>
          </div>
        </div>
        <!-- ── Cara de lectura ─────────────────────────────────────────
             Recorrer 131 fichas con las flechas es lo que más se hace, y en un formulario los
             datos están repartidos entre campos que hay que interpretar. Aquí se leen de corrido. -->
        <div v-if="modoVistaPax && paxEnFoco" class="p-6 space-y-4 overflow-y-auto">
          <div>
            <p class="text-xl font-black text-slate-800 leading-tight">{{ paxEnFoco.nombre }} {{ paxEnFoco.apellido }}</p>
            <p class="mt-1.5 flex flex-wrap items-center gap-2">
              <span class="text-[10px] font-black px-2 py-0.5 rounded border uppercase tracking-wide"
                    :class="PASAJERO_TIPO_CONFIG[String(paxEnFoco.tipo)]?.clase ?? 'bg-slate-100 text-slate-600 border-slate-200'">
                {{ PASAJERO_TIPO_CONFIG[String(paxEnFoco.tipo)]?.label ?? 'Sin rol' }}
              </span>
              <span v-for="e in ejesDePax(paxEnFoco)" :key="e.id"
                    class="text-[10px] font-black px-2 py-0.5 rounded border uppercase tracking-wide"
                    :class="e.destacado
                      ? 'bg-teal-600 text-white border-teal-600'
                      : 'bg-white text-slate-500 border-slate-200'">
                <i class="fas mr-1 text-[9px]" :class="e.icono"></i>{{ e.texto }}
              </span>
              <span class="text-[11px] font-bold text-slate-400 uppercase tracking-widest">
                <span v-if="paxEnFoco.edad">{{ paxEnFoco.edad }} años</span>
                <span v-if="paxEnFoco.sexo" class="text-slate-300"> · {{ getSexoLabel(paxEnFoco.sexo) }}</span>
              </span>
            </p>
          </div>

          <div class="grid grid-cols-2 gap-3">
            <div>
              <p class="text-[10px] font-bold text-slate-400 uppercase">Nacionalidad</p>
              <p class="text-sm font-bold text-slate-700">{{ paxEnFoco.pais?.nombre || '—' }}</p>
            </div>
            <div>
              <p class="text-[10px] font-bold text-slate-400 uppercase">Teléfono</p>
              <p class="text-sm font-bold text-slate-700">{{ paxEnFoco.telefono ? formatearTelefono(paxEnFoco.telefono) : '—' }}</p>
            </div>
          </div>

          <div v-if="documentosDe.length">
            <p class="text-[10px] font-bold text-slate-400 uppercase mb-1">Documentos</p>
            <!-- El vencido en rojo y con la palabra: un documento caducado no es un matiz de
                 formato, es alguien que no embarca. -->
            <div v-for="d in documentosDe" :key="d.id" class="flex items-baseline gap-2 text-sm">
              <span class="font-bold text-slate-700">{{ d.etiqueta }}</span>
              <span class="font-black text-slate-800">{{ d.numero }}</span>
              <span v-if="d.vence" class="text-[11px] font-bold" :class="d.vencido ? 'text-red-600' : 'text-slate-400'">
                {{ d.vencido ? 'VENCIDO ' : 'vence ' }}{{ d.vence.split('-').reverse().join('/') }}
              </span>
              <span v-else class="text-[11px] font-bold text-amber-500">sin comprobar</span>
            </div>
          </div>

          <!-- Aquí sí va el ITINERARIO. En la lista basta la aerolínea y el localizador —es para
               reconocer—, pero abierta una persona lo que se consulta es a qué hora sale. -->
          <div v-if="vuelosDe(paxEnFoco).length">
            <p class="text-[10px] font-bold text-slate-400 uppercase mb-1">Vuelos</p>
            <div v-for="v in vuelosDe(paxEnFoco)" :key="v.id" class="mb-2 last:mb-0">
              <p class="text-sm">
                <span class="font-bold text-sky-600">{{ v.tramo }}</span>
                <span class="text-slate-700 font-bold"> {{ v.nombre }}</span>
                <span class="text-slate-400"> · {{ v.clave }}</span>
              </p>
              <!-- eslint-disable-next-line vue/no-v-html -- Lo escribe el operador y `formatoAHtml()` escapa ANTES de aplicar marcas. -->
              <p v-if="v.detalle" v-html="formatoAHtml(v.detalle)"
                 class="text-[11px] font-medium text-slate-500 whitespace-pre-line leading-snug bg-slate-50 border border-slate-200 rounded-lg px-2.5 py-1.5 mt-1"></p>
            </div>
          </div>

          <!-- Sólo los SERVICIOS: el grupo y la habitación ya están arriba junto al rol, y los
               vuelos tienen su bloque. Repetirlos aquí llenaba la ficha de lo mismo tres veces. -->
          <div v-if="serviciosDe(paxEnFoco).length">
            <p class="text-[10px] font-bold text-slate-400 uppercase mb-1">Lleva</p>
            <div class="flex flex-wrap gap-1.5">
              <span v-for="g in serviciosDe(paxEnFoco)" :key="g.id"
                    class="inline-flex items-center gap-1 bg-emerald-50 text-emerald-700 border border-emerald-200 rounded-lg px-2 py-1 text-[11px] font-black">
                <i class="fas fa-check text-[9px] opacity-60"></i>{{ g.clave }}
              </span>
            </div>
          </div>

          <div v-if="paxEnFoco.observaciones">
            <p class="text-[10px] font-bold text-slate-400 uppercase mb-1">Observaciones</p>
            <p class="text-sm font-medium text-slate-600">{{ paxEnFoco.observaciones }}</p>
          </div>

          <div class="pt-4 border-t border-slate-100 flex justify-end">
            <button type="button" @click="girarPanelPax(false)"
                    class="px-5 py-2 bg-indigo-600 text-white text-xs font-bold rounded-lg shadow-sm hover:bg-indigo-700 flex items-center gap-2">
              <i class="fas fa-pencil-alt text-[10px]"></i> Editar
            </button>
          </div>
        </div>

        <form v-else @submit.prevent="guardarPasajero" class="p-6 space-y-4 overflow-y-auto">
          <div class="grid grid-cols-2 gap-4">
            <div>
              <label class="block text-[10px] font-bold text-slate-500 uppercase mb-1">Nombres *</label>
              <input v-model="paxForm.nombre" required type="text" class="w-full border rounded-lg px-3 py-2 text-sm outline-none focus:border-indigo-500">
            </div>
            <div>
              <label class="block text-[10px] font-bold text-slate-500 uppercase mb-1">Apellidos *</label>
              <input v-model="paxForm.apellido" required type="text" class="w-full border rounded-lg px-3 py-2 text-sm outline-none focus:border-indigo-500">
            </div>
            <div class="col-span-2">
              <label class="block text-[10px] font-bold text-slate-500 uppercase mb-1">Nacionalidad *</label>
              <SearchableSelect
                  ref="paisSelectRef"
                  v-model="paxForm.pais"
                  :options="paisOptions"
                  placeholder="Buscar país..."
                  required
                  error-message="La nacionalidad es obligatoria."
              />
            </div>
            <!-- ── Documentos de identidad ───────────────────────────────────
                 Una lista y no dos campos: una persona lleva DNI *y* pasaporte, con vencimientos
                 distintos, y los menores además necesitan autorización para salir del país. Los
                 tipos ya usados no se vuelven a ofrecer: la unicidad es `(pasajero, tipo)` en
                 base, así que repetir sólo consigue un 422 después de escribir el número. -->
            <div class="col-span-2">
              <div class="flex items-center justify-between mb-1">
                <label class="text-[10px] font-bold text-slate-500 uppercase">Documentos de identidad</label>
                <button type="button" @click="agregarIdentificacion" :disabled="!tiposIdDisponibles.length"
                        class="text-[10px] font-black text-indigo-600 hover:text-indigo-800 disabled:text-slate-300 disabled:cursor-not-allowed">
                  + Añadir
                </button>
              </div>

              <p v-if="!paxForm.identificaciones.length" class="text-[10px] text-slate-400 italic border border-dashed border-slate-200 rounded-lg px-3 py-2">
                Sin documentos. Añade al menos el que se use para viajar.
              </p>

              <div v-for="(ident, idx) in paxForm.identificaciones" :key="idx" class="flex gap-2 items-start mb-2">
                <select v-model="ident.tipo" required class="w-28 shrink-0 border rounded-lg px-2 py-2 text-sm outline-none focus:border-indigo-500">
                  <option v-for="(label, valor) in DOCUMENTO_IDENTIDAD_LABELS" :key="valor" :value="valor">{{ label }}</option>
                </select>
                <input v-model="ident.numero" required type="text" placeholder="Número"
                       class="flex-1 min-w-0 border rounded-lg px-3 py-2 text-sm outline-none focus:border-indigo-500">
                <div class="w-32 shrink-0">
                  <MaskedDateInput v-model="ident.vencimiento" placeholder="Vence" />
                </div>
                <button type="button" @click="paxForm.identificaciones.splice(idx, 1)"
                        class="shrink-0 w-9 h-9 rounded-lg text-slate-300 hover:text-red-500 hover:bg-red-50 transition-colors">
                  <i class="fas fa-times"></i>
                </button>
              </div>

              <p v-if="paxForm.identificaciones.some(i => !i.vencimiento)" class="text-[9px] font-bold text-amber-600 mt-1">
                <i class="fas fa-triangle-exclamation mr-1"></i>Sin fecha de vencimiento no se puede comprobar nada: cuentan como «sin comprobar», no como vigentes.
              </p>
            </div>

            <!-- ── Qué es dentro del grupo ───────────────────────────────────
                 De aquí cuelga qué ve al consultar su viaje Y si aparece ante los demás. Son dos
                 ejes: el invitado no es «el que menos ve», es el que NO SE VE — sus gratuidades
                 las paga la agencia y el colegio no las mira. Ver docs §6.p. -->
            <!-- Sólo en modo grupo: en un expediente estándar el rol no gobierna nada —el enlace es
                 público y nadie entra con documento— así que sería un desplegable que pide una
                 decisión sin consecuencia. Un namelist de dos personas se rellena con nombre y
                 documento, y ya. -->
            <div v-if="file?.usaPadron" class="col-span-2">
              <label class="block text-[10px] font-bold text-slate-500 uppercase mb-1.5">Rol en el grupo</label>
              <select v-model="paxForm.tipo" class="w-full border rounded-lg px-3 py-2 text-sm outline-none focus:border-indigo-500">
                <option value="">— sin definir (ve sólo lo suyo) —</option>
                <option v-for="(cfg, valor) in PASAJERO_TIPO_CONFIG" :key="valor" :value="valor">{{ cfg.label }}</option>
              </select>
              <p v-if="paxForm.tipo" class="text-[10px] font-bold mt-1"
                 :class="PASAJERO_TIPO_CONFIG[paxForm.tipo]?.expuesto ? 'text-slate-400' : 'text-amber-600'">
                <i class="fas" :class="PASAJERO_TIPO_CONFIG[paxForm.tipo]?.expuesto ? 'fa-eye' : 'fa-eye-slash'"></i>
                Ve: {{ PASAJERO_TIPO_CONFIG[paxForm.tipo]?.alcance }}.
                <template v-if="!PASAJERO_TIPO_CONFIG[paxForm.tipo]?.expuesto">
                  <b>No aparece para nadie</b> salvo la agencia — es una gratuidad.
                </template>
              </p>
            </div>

            <div>
              <label class="block text-[10px] font-bold text-slate-500 uppercase mb-1.5">Teléfono</label>
              <!-- El backend lo guarda en E.164 sin «+» con el mismo `PhoneSanitizer` que el
                   expediente, usando el país del pasajero. Aquí se pinta con `formatearTelefono`,
                   el mismo espejo que usan reservas y chat: un número escrito de dos formas deja
                   de encontrarse al buscar. -->
              <input v-model="paxForm.telefono" type="tel" maxlength="40"
                     :placeholder="'+51 987 654 321'"
                     class="w-full border rounded-lg px-3 py-2 text-sm outline-none focus:border-indigo-500 placeholder:text-slate-300">
              <p v-if="paxEditandoIri && paxForm.telefono" class="text-[10px] font-bold text-slate-400 mt-1">
                Se guarda como {{ formatearTelefono(paxForm.telefono) }}
              </p>
            </div>

            <div>
              <label class="block text-[10px] font-bold text-slate-500 uppercase mb-1.5">Observaciones</label>
              <input v-model="paxForm.observaciones" type="text" maxlength="500"
                     placeholder="FALTA PASAPORTE · reemplaza a…"
                     class="w-full border rounded-lg px-3 py-2 text-sm outline-none focus:border-indigo-500 placeholder:text-slate-300">
            </div>

            <!-- ── A qué subgrupos pertenece ─────────────────────────────────
                 Se marcan varios de ejes distintos a la vez: alguien está en el salón B, el grupo
                 5, la habitación HA13 y dos reservas aéreas. La corona marca de cuál es JEFE, y
                 eso va por grupo — se lidera uno, no en general. -->
            <div v-if="file?.grupos?.length" class="col-span-2">
              <label class="block text-[10px] font-bold text-slate-500 uppercase mb-1.5">Subgrupos</label>
              <div v-for="(lista, tipo) in gruposPorTipo" :key="tipo" class="mb-2">
                <div class="flex items-center justify-between mb-1">
                  <p class="text-[9px] font-black text-slate-400 uppercase tracking-widest">
                    <i class="fas" :class="GRUPO_TIPO_LABELS[tipo]?.icon"></i> {{ GRUPO_TIPO_LABELS[tipo]?.label || tipo }}
                  </p>
                  <!-- El plegado sólo aparece si sobra: con nueve grupos el botón es ruido. -->
                  <button v-if="lista.length > TOPE_PILDORAS" type="button" @click="alternarEje(tipo)"
                          class="text-[9px] font-black uppercase tracking-widest text-indigo-500 hover:text-indigo-700">
                    {{ ejeEstaAbierto(tipo, lista.length) ? 'Plegar' : `Ver las ${lista.length}` }}
                  </button>
                </div>

                <input v-if="ejeEstaAbierto(tipo, lista.length) && lista.length > TOPE_PILDORAS"
                       v-model="filtroEje[tipo]" type="text" placeholder="Filtrar…"
                       class="w-full mb-1.5 border rounded-lg px-2.5 py-1 text-[11px] outline-none focus:border-indigo-500">

                <div class="flex flex-wrap gap-1.5">
                  <span v-for="g in pildorasVisibles(tipo, lista)" :key="g.id"
                        class="inline-flex items-center rounded-lg border text-[11px] font-black transition-colors overflow-hidden"
                        :class="perteneceA(g) ? 'bg-teal-50 text-teal-700 border-teal-300' : 'bg-white text-slate-400 border-slate-200'">
                    <!-- ⚠️ Aerolínea y código, y NADA MÁS. La píldora existe para ELEGIR entre
                         veinte localizadores de un vistazo; el itinerario se consulta después y va
                         debajo. Metido aquí —o en el `title`— convierte la lista en un párrafo. -->
                    <button type="button" @click="alternarPertenencia(g)" class="px-2.5 py-1 hover:bg-black/5">
                      {{ g.clave }}
                      <span v-if="g.nombre" class="ml-1 font-medium opacity-60">{{ g.nombre }}</span>
                    </button>
                  </span>
                  <span v-if="!pildorasVisibles(tipo, lista).length"
                        class="text-[10px] font-bold text-slate-300 italic py-1">
                    {{ ejeEstaAbierto(tipo, lista.length) ? 'Nada coincide con el filtro.' : 'Sin asignar.' }}
                  </span>
                </div>

                <!-- El itinerario, sólo de los grupos a los que PERTENECE: es para comprobar un
                     horario, no para elegir, y pintarlo de los 66 que no le tocan es ruido. -->
                <div v-for="g in detallesDe(lista)" :key="`d-${g.id}`"
                     class="mt-1.5 bg-slate-50 border border-slate-200 rounded-lg px-2.5 py-1.5">
                  <p class="text-[9px] font-black text-slate-400 uppercase tracking-widest">{{ g.clave }}</p>
                  <!-- eslint-disable-next-line vue/no-v-html -- Lo escribe el operador y `formatoAHtml()` escapa ANTES de aplicar marcas. -->
                  <p v-html="formatoAHtml(g.detalle ?? '')"
                     class="text-[10px] font-medium text-slate-600 whitespace-pre-line leading-snug"></p>
                </div>
              </div>
            </div>
            <div>
              <label class="block text-[10px] font-bold text-slate-500 uppercase mb-1">Nacimiento</label>
              <MaskedDateInput v-model="paxForm.fechanacimiento" />
            </div>
            <div>
              <label class="block text-[10px] font-bold text-slate-500 uppercase mb-1">Sexo *</label>
              <select v-model="paxForm.sexo" required class="w-full border rounded-lg px-3 py-2 text-sm outline-none focus:border-indigo-500">
                <option v-for="(label, valor) in SEXO_LABELS" :key="valor" :value="valor">{{ label }}</option>
              </select>
            </div>
          </div>
          <div class="pt-4 border-t border-slate-100 flex justify-end gap-3">
            <button type="button" @click="paxEditandoIri ? girarPanelPax(true) : (showPaxModal = false)"
                    class="px-4 py-2 text-xs font-bold text-slate-500 border rounded-lg">Cancelar</button>
            <button v-if="haySiguiente" type="submit" @click="seguirTrasGuardar = true" :disabled="isSubmittingPax"
                    class="px-4 py-2 bg-white border border-indigo-200 text-indigo-600 text-xs font-bold rounded-lg hover:bg-indigo-50 flex items-center gap-2">
              Guardar y siguiente <i class="fas fa-chevron-right text-[10px]"></i>
            </button>
            <button type="submit" :disabled="isSubmittingPax" class="px-5 py-2 bg-indigo-600 text-white text-xs font-bold rounded-lg shadow-sm hover:bg-indigo-700 flex items-center gap-2">
              <i v-if="isSubmittingPax" class="fas fa-spinner fa-spin"></i> Guardar Pasajero
            </button>
          </div>
        </form>
      </div>
      </div>
    </div>
  </Teleport>

  <Teleport to="body">
    <div v-if="showDocModal" class="fixed inset-0 z-1000 bg-slate-900/60 backdrop-blur-sm flex items-center justify-center p-4">
      <div class="bg-white w-full max-w-md rounded-3xl shadow-2xl overflow-hidden flex flex-col max-h-[calc(100dvh-2rem)]">

        <div class="bg-sky-600 px-6 py-4 flex justify-between items-center text-white shrink-0">
          <h3 class="font-black text-sm uppercase tracking-widest">
            <i class="fas fa-upload mr-2" v-if="!docEditandoIri"></i>
            <i class="fas fa-pencil-alt mr-2" v-else></i>
            {{ docEditandoIri ? 'Editar Documento' : 'Subir a Bóveda' }}
          </h3>
          <button @click="showDocModal = false; docEditandoIri = null" class="text-sky-200 hover:text-white"><i class="fas fa-times"></i></button>
        </div>
        <form @submit.prevent="guardarDocumento" class="p-6 space-y-4 overflow-y-auto">
          <div v-if="!docEditandoIri">
            <label class="block text-[10px] font-bold text-slate-500 uppercase mb-1">Archivo (PDF / Img) *</label>
            <input type="file" @change="handleFileUpload" required class="w-full text-sm text-slate-500 file:mr-4 file:py-2 file:px-4 file:rounded-full file:border-0 file:text-xs file:font-black file:bg-sky-50 file:text-sky-700 hover:file:bg-sky-100">
          </div>
          <div v-else class="bg-slate-50 border border-slate-200 rounded-lg p-3 text-[10px] font-bold text-slate-500 flex items-center gap-2">
            <i class="fas fa-info-circle"></i> El archivo no se puede reemplazar aquí. Elimina y sube uno nuevo si necesitas cambiarlo.
          </div>

          <div>
            <div class="flex items-center justify-between mb-1">
              <label class="block text-[10px] font-bold text-slate-500 uppercase">Nombre del documento *</label>
              <button type="button"
                      @click="docForm.sobreescribirTraduccion = !docForm.sobreescribirTraduccion"
                      :title="docForm.sobreescribirTraduccion ? 'Se regenerarán las traducciones al guardar' : 'Se conservan las traducciones existentes'"
                      class="w-8 h-8 flex items-center justify-center rounded-lg border transition-all"
                      :class="docForm.sobreescribirTraduccion ? 'bg-sky-100 border-sky-300 text-sky-600 shadow-inner' : 'bg-white border-slate-200 text-slate-300 hover:text-slate-500'">
                <i class="fas fa-language text-base"></i>
              </button>
            </div>
            <input v-model="docForm.nombre" required type="text" placeholder="Ej. Entrada Machupicchu"
                   class="w-full border rounded-lg px-3 py-2 text-sm outline-none focus:border-sky-500">
            <p class="text-[9px] text-slate-400 mt-1">
              {{ docForm.sobreescribirTraduccion
                ? 'Al guardar se regenerarán las traducciones automáticas.'
                : 'Se traduce automáticamente; las traducciones existentes se conservan.' }}
            </p>
          </div>

          <div>
            <label class="block text-[10px] font-bold text-slate-500 uppercase mb-1">Tipo de archivo *</label>
            <select v-model="docForm.tipoArchivo" required class="w-full border rounded-lg px-3 py-2 text-sm outline-none focus:border-sky-500">
              <option v-for="(label, valor) in ARCHIVO_TIPO_LABELS" :key="valor" :value="valor">{{ label }}</option>
            </select>
            <!-- Aquí hubo un «Vencimiento (Opcional)» que decía «útil para alertar sobre Pasaportes
                 o Visas vencidas»: un campo de IDENTIDAD en una entidad de ARCHIVOS. Nadie lo llenó
                 nunca —0 de 7 filas en producción— y el vencimiento de un documento de identidad va
                 en el pasajero, no en un adjunto del expediente. -->
          </div>
          <div class="pt-4 border-t border-slate-100 flex justify-end gap-3">
            <button type="button" @click="showDocModal = false; docEditandoIri = null" class="px-4 py-2 text-xs font-bold text-slate-500 border rounded-lg">Cancelar</button>
            <button type="submit" :disabled="isSubmittingDoc" class="px-5 py-2 bg-sky-600 text-white text-xs font-bold rounded-lg shadow-sm hover:bg-sky-700 flex items-center gap-2">
              <i v-if="isSubmittingDoc" class="fas fa-spinner fa-spin"></i>
              {{ docEditandoIri ? 'Guardar Cambios' : 'Subir Documento' }}
            </button>
          </div>
        </form>
      </div>
    </div>
  </Teleport>


  <PlanOperacionModal
      :cotizacion-id="planOperacionId"
      :titulo="planOperacionTitulo"
      @cerrar="planOperacionId = null"
  />

</template>

<style scoped>
/*
 * El giro del panel del expediente.
 *
 * ⚠️ Media vuelta, no dos caras. Una tarjeta con anverso y reverso obliga a que las dos midan lo
 * mismo —van superpuestas en absoluto— y aquí no se parecen en nada: seis líneas en lectura
 * contra seis campos, un buscador de países y el panel de contacto en edición. Se gira 90°, se
 * cambia el contenido con la tarjeta de canto, y se vuelve. Se ve igual y no pelea con la altura.
 */
.panel-giratorio {
  perspective: 1400px;
}

.cara {
  transition: transform 0.18s ease-in-out;
  transform-origin: center;
  backface-visibility: hidden;
}

.cara.de-canto {
  transform: rotateY(90deg);
}

/* Quien pide menos movimiento no quiere una tarjeta girando: se cambia y ya. */
@media (prefers-reduced-motion: reduce) {
  .cara { transition: none; }
  .cara.de-canto { transform: none; }
}
</style>
