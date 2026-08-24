<script setup lang="ts">
import {ref, onMounted, onUnmounted, watch, computed} from 'vue';
import { useRoute, useRouter, onBeforeRouteLeave } from 'vue-router';
import { useVolverAtras } from '@/composables/useVolverAtras';
import MaskedDateInput from '@/components/MaskedDateInput.vue';   // ajusta ruta
import SearchableSelect from '@/components/SearchableSelect.vue';
import ContactoDeIdentidad from '@/components/common/ContactoDeIdentidad.vue';
import { uuidDe } from '@/services/hydra';
import { formatearTelefono } from '@/utils/telefono';
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
  showPaxModal.value = true;
};

const abrirEdicionPax = (pax: ApiCotizacionFilepasajero) => {
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


const descargarPlantilla = async () => {
  descargandoPlantilla.value = true;
  try {
    const { data } = await apiClient.get('/cotizacion/user/padron/plantilla', { responseType: 'blob' });
    const url = URL.createObjectURL(data as Blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = 'padron-plantilla.xlsx';
    a.click();
    URL.revokeObjectURL(url);
  } catch {
    alert('No se pudo descargar la plantilla.');
  } finally {
    descargandoPlantilla.value = false;
  }
};

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

const nuevoGrupo = ref({ tipo: 'grupo', clave: '', nombre: '' });
const creandoGrupo = ref(false);

const agregarGrupo = async () => {
  if (!file.value || !nuevoGrupo.value.clave.trim()) return;

  creandoGrupo.value = true;
  const ok = await fileStore.crearGrupo(
    extractIdStr(file.value.id || file.value['@id']) || '',
    { tipo: nuevoGrupo.value.tipo, clave: nuevoGrupo.value.clave, nombre: nuevoGrupo.value.nombre || null },
  );
  creandoGrupo.value = false;

  if (ok) {
    nuevoGrupo.value.clave = '';
    nuevoGrupo.value.nombre = '';
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

const guardarPasajero = async () => {
  // SearchableSelect no dispara la validación nativa del form: validamos a mano.
  // validate() pinta el error dentro del componente y devuelve si es válido.
  if (paisSelectRef.value && !paisSelectRef.value.validate()) {
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
    showPaxModal.value = false;
    paxEditandoIri.value = null;
    await cargarFile();
  } else {
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

          <div class="bg-white rounded-3xl p-6 border border-slate-200 shadow-sm">
            <h2 class="text-sm font-black text-slate-800 uppercase tracking-widest mb-5 border-b pb-3"><i class="fas fa-folder-open mr-2 text-[#E07845]"></i> Datos del Expediente</h2>
            <form @submit.prevent="guardarFile" class="space-y-4">
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
              <button type="submit" :disabled="isSavingFile" class="w-full py-3 bg-slate-800 hover:bg-slate-900 text-white font-bold rounded-xl shadow mt-2">
                <i v-if="isSavingFile" class="fas fa-spinner fa-spin mr-1"></i> Guardar Cambios
              </button>
            </form>
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

            <!-- ── Subgrupos ─────────────────────────────────────────────────
                 Ejes cruzados, no un árbol: en un padrón real 9 de cada 10 grupos aparecen en más
                 de un salón, así que una persona pertenece a varios a la vez. Se definen aquí y se
                 asignan en la ficha de cada pasajero. -->
            <div v-if="file.usaPadron" class="mb-8">
              <div class="flex items-center justify-between mb-4 gap-3 flex-wrap">
                <h2 class="text-sm font-black text-slate-800 uppercase tracking-widest">
                  <i class="fas fa-layer-group mr-2 text-teal-500"></i> Subgrupos
                </h2>

                <!-- La plantilla vive junto a la carga porque es donde se necesita: quien va a
                     subir un padrón es quien no sabe qué columnas lleva. -->
                <button @click="descargarPlantilla" :disabled="descargandoPlantilla"
                        title="Plantilla del padrón: sirve para dos pasajeros con un pasaporte y para 133 con cinco agrupaciones"
                        class="flex items-center gap-2 bg-white border border-slate-200 text-slate-600 px-3 py-1.5 rounded-lg text-[11px] font-bold shadow-sm hover:bg-slate-50 transition-colors disabled:opacity-50">
                  <i class="fas" :class="descargandoPlantilla ? 'fa-spinner fa-spin' : 'fa-file-arrow-down'"></i>
                  Descargar plantilla del padrón
                </button>
              </div>

              <div class="flex flex-wrap gap-2 items-end bg-slate-50 border border-slate-200 rounded-2xl p-3 mb-4">
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
                  <input v-model="nuevoGrupo.nombre" type="text" placeholder="Arajet JA2CWN (Lima–Punta Cana)" maxlength="150"
                         @keyup.enter="agregarGrupo"
                         class="w-full border rounded-lg px-3 py-2 text-sm outline-none focus:border-teal-500 placeholder:text-slate-300">
                </div>
                <button @click="agregarGrupo" :disabled="creandoGrupo || !nuevoGrupo.clave.trim()"
                        class="bg-teal-600 text-white px-4 py-2 rounded-lg text-xs font-bold hover:bg-teal-700 shadow-sm disabled:opacity-40 disabled:cursor-not-allowed">
                  <i class="fas fa-spinner fa-spin" v-if="creandoGrupo"></i>
                  <span v-else>+ Añadir</span>
                </button>
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
                      <i class="fas fa-check mr-1"></i> Cargar de verdad
                    </button>
                    <button @click="cancelarPadron" class="px-4 py-2 text-xs font-bold text-slate-500 border rounded-lg hover:bg-slate-50">
                      Cancelar
                    </button>
                  </div>
                </div>
              </div>

              <p v-if="!file.grupos?.length" class="text-[11px] text-slate-400 italic border border-dashed border-slate-200 rounded-2xl px-4 py-3">
                Sin subgrupos. Se crean solos al cargar el padrón, o a mano aquí arriba.
              </p>

              <div v-else class="space-y-3">
                <div v-for="(lista, tipo) in gruposPorTipo" :key="tipo">
                  <p class="text-[9px] font-black text-slate-400 uppercase tracking-widest mb-1.5">
                    <i class="fas" :class="GRUPO_TIPO_LABELS[tipo]?.icon"></i>
                    {{ GRUPO_TIPO_LABELS[tipo]?.label || tipo }} ({{ lista.length }})
                  </p>
                  <div class="flex flex-wrap gap-2">
                    <span v-for="g in lista" :key="g.id"
                          class="inline-flex items-center gap-2 bg-white border border-slate-200 rounded-lg pl-3 pr-1 py-1 shadow-sm group">
                      <span class="text-[11px] font-black text-slate-700">{{ g.clave }}</span>
                      <span class="text-[10px] font-bold text-slate-400">{{ g.totalMiembros ?? 0 }} pax</span>
                      <button @click="borrarGrupo(g)"
                              class="w-5 h-5 rounded text-slate-300 hover:text-red-500 hover:bg-red-50 transition-colors">
                        <i class="fas fa-times text-[10px]"></i>
                      </button>
                    </span>
                  </div>
                </div>
              </div>
            </div>


            <div class="flex items-center justify-between mb-4">
              <h2 class="text-sm font-black text-slate-800 uppercase tracking-widest"><i class="fas fa-users mr-2 text-indigo-500"></i> Manifiesto de Pasajeros</h2>
              <button @click="abrirPaxModal" class="bg-indigo-600 text-white px-4 py-2 rounded-lg text-xs font-bold hover:bg-indigo-700 shadow-sm">+ Añadir Pax</button>
            </div>

            <div v-if="!file.filepasajeros?.length" class="bg-indigo-50 border-2 border-dashed border-indigo-200 rounded-3xl p-8 text-center text-indigo-400">
              <i class="fas fa-user-plus text-3xl mb-3 opacity-50"></i>
              <p class="text-xs font-bold uppercase tracking-widest">Sin pasajeros registrados</p>
            </div>

            <div v-else class="grid grid-cols-1 sm:grid-cols-2 gap-4">
              <div v-for="(pax, idx) in file.filepasajeros" :key="pax.id" class="bg-white p-4 rounded-2xl border border-slate-200 shadow-sm relative group">
                <div class="absolute top-3 right-3 flex items-center gap-1">
                  <button @click="abrirEdicionPax(pax)" class="text-slate-300 hover:text-indigo-500 transition-colors bg-slate-50 w-7 h-7 rounded-full flex items-center justify-center">
                    <i class="fas fa-pencil-alt text-xs"></i>
                  </button>
                  <button @click="eliminarPasajero(pax['@id'])" class="text-slate-300 hover:text-red-500 transition-colors bg-slate-50 w-7 h-7 rounded-full flex items-center justify-center">
                    <i class="fas fa-trash-alt text-xs"></i>
                  </button>
                </div>
                <div class="flex items-start gap-3 pr-16">
                  <div class="w-8 h-8 rounded-full bg-indigo-100 text-indigo-600 font-black text-xs flex items-center justify-center border border-indigo-200">{{ idx + 1 }}</div>
                  <div>
                    <h3 class="text-sm font-black text-slate-800 leading-tight">{{ pax.nombre }} {{ pax.apellido }}</h3>
                    <div class="flex flex-wrap gap-1 mt-2">
                      <span class="text-[9px] font-bold bg-slate-100 text-slate-600 px-1.5 py-0.5 rounded border border-slate-200 uppercase">{{ pax.tipopaxperurail === 1 ? 'Adulto' : 'Niño' }} PR</span>
                      <span v-if="pax.edad" class="text-[9px] font-bold bg-indigo-50 text-indigo-600 px-1.5 py-0.5 rounded border border-indigo-100 uppercase">{{ pax.edad }} Años</span>
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
                  </div>
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
      <div class="bg-white w-full max-w-lg rounded-3xl shadow-2xl overflow-visible">
        <div class="bg-indigo-600 px-6 py-4 flex justify-between items-center text-white rounded-t-3xl">
          <h3 class="font-black text-sm uppercase tracking-widest">{{ paxEditandoIri ? 'Editar Pasajero' : 'Nuevo Pasajero' }}</h3>
          <button @click="showPaxModal = false" class="text-indigo-200 hover:text-white"><i class="fas fa-times"></i></button>
        </div>
        <form @submit.prevent="guardarPasajero" class="p-6 space-y-4">
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
            <div class="col-span-2">
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
                <p class="text-[9px] font-black text-slate-400 uppercase tracking-widest mb-1">
                  <i class="fas" :class="GRUPO_TIPO_LABELS[tipo]?.icon"></i> {{ GRUPO_TIPO_LABELS[tipo]?.label || tipo }}
                </p>
                <div class="flex flex-wrap gap-1.5">
                  <span v-for="g in lista" :key="g.id"
                        class="inline-flex items-center rounded-lg border text-[11px] font-black transition-colors overflow-hidden"
                        :class="perteneceA(g) ? 'bg-teal-50 text-teal-700 border-teal-300' : 'bg-white text-slate-400 border-slate-200'">
                    <button type="button" @click="alternarPertenencia(g)" class="px-2.5 py-1 hover:bg-black/5">
                      {{ g.clave }}
                    </button>
                  </span>
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
            <button type="button" @click="showPaxModal = false" class="px-4 py-2 text-xs font-bold text-slate-500 border rounded-lg">Cancelar</button>
            <button type="submit" :disabled="isSubmittingPax" class="px-5 py-2 bg-indigo-600 text-white text-xs font-bold rounded-lg shadow-sm hover:bg-indigo-700 flex items-center gap-2">
              <i v-if="isSubmittingPax" class="fas fa-spinner fa-spin"></i> Guardar Pasajero
            </button>
          </div>
        </form>
      </div>
    </div>
  </Teleport>

  <Teleport to="body">
    <div v-if="showDocModal" class="fixed inset-0 z-1000 bg-slate-900/60 backdrop-blur-sm flex items-center justify-center p-4">
      <div class="bg-white w-full max-w-md rounded-3xl shadow-2xl overflow-hidden">

        <div class="bg-sky-600 px-6 py-4 flex justify-between items-center text-white">
          <h3 class="font-black text-sm uppercase tracking-widest">
            <i class="fas fa-upload mr-2" v-if="!docEditandoIri"></i>
            <i class="fas fa-pencil-alt mr-2" v-else></i>
            {{ docEditandoIri ? 'Editar Documento' : 'Subir a Bóveda' }}
          </h3>
          <button @click="showDocModal = false; docEditandoIri = null" class="text-sky-200 hover:text-white"><i class="fas fa-times"></i></button>
        </div>
        <form @submit.prevent="guardarDocumento" class="p-6 space-y-4">
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
