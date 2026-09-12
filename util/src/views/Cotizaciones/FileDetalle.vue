<script setup lang="ts">
import {ref, onMounted, onUnmounted, watch, computed, nextTick} from 'vue';
import { hoyNaive } from '@/utils/naiveDate.ts';
import { useRoute, useRouter, onBeforeRouteLeave } from 'vue-router';
import { useVolverAtras } from '@/composables/useVolverAtras';
// El gesto «atrás» del móvil cierra la capa de arriba en vez de sacarte de la pantalla.
import { useCapasEnHistorial } from '@/composables/useCapasEnHistorial';
import { useRefrescoDelAsistente } from '@/composables/useRefrescoDelAsistente';
import MaskedDateInput from '@/components/MaskedDateInput.vue';   // ajusta ruta
import SearchableSelect from '@/components/SearchableSelect.vue';
import ContactoDeIdentidad from '@/components/common/ContactoDeIdentidad.vue';
import FechaHoraPicker from '@/components/common/FechaHoraPicker.vue';
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
import { paraBuscar } from '@/utils/texto';

import {
  getArchivoLabel, ARCHIVO_TIPO_LABELS, ARCHIVO_TIPOS_DEL_PASAJERO, type ArchivoTipoValue,
  type PlanCargaZip, type DocumentoSuelto,
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
// LINKS VISTA CLIENTE  (pax + /file/ + localizador [+ /p/N])
// ============================================================================
const linkPublico = computed(() => {
  if (!file.value?.localizador) return '';
  return `${getUrls().pax}/file/${file.value.localizador}`;
});

/**
 * Descarga del contacto para la agenda del móvil (.vcf).
 *
 * Gemela de la del drawer de reservas, pero **con los datos de ESTE módulo**: el nombre de
 * agenda es `localizador · grupo` —un expediente no tiene fechas propias con las que
 * ordenarlo— y la nota lleva titular, país, idioma, estado y pasajeros, en el mismo orden
 * que la tarjeta. Lo arma `CotizacionFileVcardController`.
 *
 * Por `id` y no por localizador: es la clave que ya tiene la vista, y el endpoint es interno.
 */
const vcardUrl = computed(() => (fileId.value ? `${getUrls().api}/cotizacion/files/${fileId.value}/vcard` : ''));

const linkPublicoPropuesta = (propuesta?: number) => {
  if (!file.value?.localizador) return '';
  const base = `${getUrls().pax}/file/${file.value.localizador}`;
  return propuesta ? `${base}/p/${propuesta}` : base;
};

/**
 * Qué propuesta se acaba de copiar, para el ✓ de dos segundos.
 *
 * Es el NÚMERO y no un booleano porque la cabecera se repite por propuesta: con un flag
 * compartido, copiar la P1 ponía el visto también en la P2 y la P3.
 */
const propuestaCopiada = ref<number | null>(null);

/**
 * Copia el enlace de la vista cliente de UNA propuesta.
 *
 * Hermano de `copiarLink()`, que copia el del file entero. Existe porque lo que se le manda al
 * cliente casi nunca es «el expediente»: es una propuesta concreta, y hasta ahora había que
 * abrirla en otra pestaña y copiar de la barra de direcciones.
 *
 * El fallback no es decorativo: con el portapapeles bloqueado —http, o permiso denegado— el
 * `alert` enseña la URL entera para poder seleccionarla a mano. Mismo criterio que `copiarLink()`.
 */
const copiarLinkPropuesta = async (propuesta?: number) => {
  const url = linkPublicoPropuesta(propuesta);
  if (!url) return;

  try {
    await navigator.clipboard.writeText(url);
    propuestaCopiada.value = propuesta ?? 0;
    setTimeout(() => { propuestaCopiada.value = null; }, 2000);
  } catch {
    alert('No se pudo copiar. Copia manualmente: ' + url);
  }
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
const propuestaTemp = ref<number>(1);

/**
 * Publicar o dejar de publicar una propuesta.
 *
 * ⚠️ **Como máximo una publicada por propuesta**, y por eso publicar una despublica a sus hermanas
 * —sus históricos, la operativa—. Esa invariante es la que hace que el provider no tenga que
 * desempatar qué fila sirve: no puede haber dos.
 */
const alternarPublicado = async (cot: { '@id'?: string; propuesta?: number; publicado?: boolean }) => {
  const iri = cot['@id'];
  if (!iri) return;

  const publicar = !cot.publicado;

  if (publicar && !confirm(`¿Publicar la propuesta ${cot.propuesta}?\n\nEl cliente pasará a verla, y dejará de ver cualquier otra propuesta ${cot.propuesta} que estuviera publicada.`)) return;
  if (!publicar && !confirm(`¿Dejar de publicar la propuesta ${cot.propuesta}?\n\nEl cliente dejará de verla.`)) return;

  const ok = await fileStore.actualizarPublicado(iri, publicar);
  if (ok) await cargarFile();
  else alert(fileStore.error || 'No se pudo cambiar la publicación.');
};
const eliminandoItem = ref<string | null>(null);
const clonandoItem = ref<string | null>(null);

const iniciarEdicionVersion = (cot: ApiCotizacionVersion) => {
  editandoVersion.value = cot['@id'] || cot.id || '';
  propuestaTemp.value = cot.propuesta;
};

const guardarVersion = async (cot: ApiCotizacionVersion) => {
  const iri = cot['@id'] || `/platform/sales/cotizacions/${extractIdStr(cot.id)}`;
  const success = await fileStore.updateCotizacionPropuesta(iri, propuestaTemp.value);
  if (success) {
    cot.propuesta = propuestaTemp.value;
    editandoVersion.value = null;
  } else {
    alert(fileStore.error || 'Error al actualizar versión.');
  }
};

const eliminarVersion = async (cot: ApiCotizacionVersion) => {
  if (!confirm(`¿Eliminar la Propuesta ${cot.propuesta}? Esta acción no se puede deshacer.`)) return;
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
// ── Propuestas vivas y sus fotos del pasado ─────────────────────────────────
//
// Un histórico conserva a propósito el número de la versión de la que salió, así que si se
// listaran juntos habría dos tarjetas diciendo «V1» sin forma de saber cuál es la buena. Cuelgan
// de la suya, plegados.
/**
 * ⚠️ **La operativa va PEGADA a su confirmada, no suelta.**
 *
 * Comparte número con ella a propósito —es la misma propuesta, con lo que de verdad se va a
 * operar—, así que sin ordenarlas juntas salían dos tarjetas diciendo «P1» sin nada que dijera que
 * la segunda sale de la primera. Es el mismo problema que ya tenían los históricos; la diferencia
 * es que un histórico se pliega y **una operativa NO**: es la fila viva, donde ocurre todo lo
 * posterior. Se queda a la vista, sangrada y con el vínculo escrito.
 *
 * El servidor no promete ningún orden, así que se ordena aquí: propuesta descendente y, dentro de
 * cada una, la confirmada primero.
 */
const versionesVivas = computed<ApiCotizacionVersion[]>(() => {
  const vivas = (file.value?.cotizaciones ?? [])
    .filter((c: ApiCotizacionVersion) => c.estado !== 'historico');

  return [...vivas].sort((a, b) => {
    if ((b.propuesta ?? 0) !== (a.propuesta ?? 0)) return (b.propuesta ?? 0) - (a.propuesta ?? 0);

    // Dentro de la misma propuesta, la operativa detrás: es la derivada.
    return Number(a.estado === 'operativa') - Number(b.estado === 'operativa');
  });
});

/**
 * ¿Es ESTA la propuesta donde vive la operación?
 *
 * ⚠️ **Se mira el dato, no el estado.** Antes se deducía —la confirmada tenía la operación y
 * punto— y dos cambios del 02/09/2026 rompieron esa deducción por lados distintos: confirmar ya no
 * arma la operación, y la operativa se lleva las filas. Las dos dejan una confirmada vacía, y
 * significan cosas opuestas.
 *
 * `filasOperacionActivas` lo sirve `CotizacionFileItemProvider` contando sólo las ACTIVAS: una
 * confirmada que ya traspasó conserva sus 47 en `cancelado`, y contarlas diría que la operación
 * sigue ahí.
 */
const tieneOperacion = (cot: ApiCotizacionVersion): boolean =>
  (cot.filasOperacionActivas ?? 0) > 0;

/** La confirmada de la que sale esta operativa, si la tenemos a la vista. */
const confirmadaDe = (cot: ApiCotizacionVersion): ApiCotizacionVersion | undefined =>
  cot.estado !== 'operativa'
    ? undefined
    : (file.value?.cotizaciones ?? []).find(
        (c: ApiCotizacionVersion) => extractIdStr(c.id || c['@id']) === extractIdStr(cot.derivadaDeId ?? '')
      );

const historicosDe = (cot: ApiCotizacionVersion): ApiCotizacionVersion[] => {
  const id = extractIdStr(cot.id || cot['@id']);
  if (!id) return [];

  return (file.value?.cotizaciones ?? []).filter(
    (c: ApiCotizacionVersion) => c.estado === 'historico' && extractIdStr(c.derivadaDeId ?? '') === id
  );
};

/**
 * Cuánto se apartó una cifra del histórico respecto a la versión VIGENTE.
 *
 * `null` cuando no se puede comparar o cuando la diferencia es cero: un «+0.00» al lado de cada
 * columna es ruido que enseña a no mirar la columna. Sólo se pinta lo que cambió.
 *
 * ⚠️ El signo es **histórico − vigente**, o sea «cuánto tenía esta foto de más o de menos que lo
 * que está en vigor». Verde arriba, rojo abajo. Es una dirección, no un juicio: que una foto
 * vendiera menos no está mal, es que se subió el precio después.
 */
const diferenciaConVigente = (delHistorico?: string | number | null, delVigente?: string | number | null): number | null => {
  const a = Number(delHistorico ?? Number.NaN);
  const b = Number(delVigente ?? Number.NaN);

  if (!Number.isFinite(a) || !Number.isFinite(b)) return null;

  const d = a - b;

  // Medio céntimo: por debajo de eso es redondeo, no un cambio.
  return Math.abs(d) < 0.005 ? null : d;
};

/** «+50.00» / «−20.00». El menos es U+2212, que a este tamaño no se confunde con un guion. */
const formatoDiferencia = (d: number, decimales = 2): string =>
  `${d > 0 ? '+' : '−'}${Math.abs(d).toFixed(decimales)}`;

const historicosAbiertos = ref<Set<string>>(new Set());

const alternarHistoricos = (id: string): void => {
  const abiertos = new Set(historicosAbiertos.value);
  if (abiertos.has(id)) { abiertos.delete(id); } else { abiertos.add(id); }
  historicosAbiertos.value = abiertos;
};

const guardandoHistorico = ref<string | null>(null);
const abriendoOperativa = ref<string | null>(null);
const armandoOperacion = ref<string | null>(null);

/**
 * Arma el cuadro de operación de esta fila.
 *
 * ⚠️ **Ya no ocurre solo al confirmar** (02/09/2026): son dos actos distintos en momentos
 * distintos. Idempotente, así que también sirve para completar lo que falte tras añadir servicios.
 */
const generarOperacion = async (cot: ApiCotizacionVersion) => {
  const idStr = extractIdStr(cot.id || cot['@id']);
  if (!idStr) return;

  if (!confirm(
    `¿Armar la operación de la Propuesta ${cot.propuesta}?\n\n`
    + 'Crea una fila en La Biblia por cada componente. Si ya hay filas, sólo añade las que '
    + 'falten: no duplica ni revive lo que hayas cancelado a mano.'
  )) return;

  armandoOperacion.value = idStr;
  const ok = await fileStore.generarOperacion(idStr);

  if (ok) {
    await cargarFile();
  } else {
    alert(fileStore.error || 'No se pudo armar la operación.');
  }

  armandoOperacion.value = null;
};

/**
 * ¿Esta propuesta ya tiene abierta su operativa?
 *
 * ⚠️ Se busca por NÚMERO de propuesta, no por `derivadaDe`: la operativa comparte número con la
 * confirmada a propósito —es la misma propuesta, con lo que de verdad se va a operar—, y ése es el
 * eje por el que el backend también comprueba que no haya dos.
 */
const operativaDe = (cot: ApiCotizacionVersion): ApiCotizacionVersion | undefined =>
  (file.value?.cotizaciones ?? []).find(
    (c: ApiCotizacionVersion) => c.estado === 'operativa' && c.propuesta === cot.propuesta
  );

/**
 * Abre la propuesta operativa: lo que de verdad se va a operar.
 *
 * ⚠️ **Traspasa la operación.** Las filas de La Biblia dejan de colgar de la confirmada y pasan a
 * la operativa, en una sola transacción — nunca hay un instante con las dos vivas. La confirmada
 * queda congelada por convención, y sigue siendo lo que el cliente ve EN DINERO: el itinerario
 * pasa a ser el de la operativa en cuanto se publique. Ver docs/Cotizaciones.md §6.j.3 y §6.j.4.
 */
const abrirOperativa = async (cot: ApiCotizacionVersion) => {
  const idStr = extractIdStr(cot.id || cot['@id']);
  if (!idStr) return;

  if (!confirm(
    `¿Abrir la propuesta operativa de la Propuesta ${cot.propuesta}?\n\n`
    + 'Se crea una copia para trabajar la operación real: partir vuelos, ajustar cantidades, '
    + 'asignar subgrupos.\n\n'
    + 'Las órdenes de servicio pasan a colgar de ella y esta confirmada queda congelada. '
    + 'El cliente sigue viendo los precios que aprobó, y nace SIN publicar: no verá el itinerario '
    + 'nuevo hasta que lo publiques.'
  )) return;

  abriendoOperativa.value = idStr;
  const ok = await fileStore.abrirOperativa(idStr);

  if (ok) {
    await cargarFile();
  } else {
    alert(fileStore.error || 'No se pudo abrir la propuesta operativa.');
  }

  abriendoOperativa.value = null;
};

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
    `¿Guardar una foto de la Propuesta ${cot.propuesta} tal como está ahora?\n\n`
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

  if (!confirm(`¿Estás seguro de duplicar la Propuesta ${cot.propuesta}?\nSe creará una copia idéntica y segura como propuesta nueva.`)) return;

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

  planOperacionTitulo.value = `Propuesta ${cot.propuesta} · ${file.value.nombreGrupo ?? ''}`.trim();
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
const capas = useCapasEnHistorial();

const showDocModal = ref(false);
const isSubmittingPax = ref(false);
/** Lo pone el botón «Guardar y siguiente»: se consume en `guardarPasajero()`. */
const seguirTrasGuardar = ref(false);

// El mismo giro de media vuelta que el panel del expediente. Ver el bloque <style>.
const modoVistaPax = ref(true);
const girandoPax = ref(false);

/** Sólo la animación. No toca el historial: eso lo decide quien llama. */
const girarPax = (aVista: boolean) => {
    if (girandoPax.value) return;
    girandoPax.value = true;
    window.setTimeout(() => { modoVistaPax.value = aVista; girandoPax.value = false; }, 180);
};

/**
 * Pasar a editar y volver a leer.
 *
 * ⚠️ La edición es una capa ENCIMA de la lectura, no un interruptor. «Atrás» dentro de la edición
 * vuelve a leer, y el siguiente sale de la ficha: el camino por el que se entró, al revés. Y
 * volver a lectura se hace SIEMPRE por `capas.cerrar()` —nunca girando a mano— para que el gesto
 * y el botón acaben en el mismo sitio y no quede una entrada fantasma en el historial.
 */
const girarPanelPax = (aVista: boolean) => {
    if (aVista) capas.cerrar('pax-edicion');
    else { capas.abrir('pax-edicion', () => girarPax(true)); girarPax(false); }
};
const isSubmittingDoc = ref(false);

const paxForm = ref({
  nombre: '', apellido: '', pais: '', sexo: '', fechanacimiento: '', tipo: '', telefono: '', observaciones: '',
  // Una persona lleva DNI *y* pasaporte, con vencimientos distintos. Ver §6.l del doc.
  identificaciones: [] as Array<{ tipo: string; numero: string; vencimiento: string }>,
  /** IRIs de los grupos a los que pertenece. Quién lidera lo dice el TIPO, no una bandera aquí. */
  pertenencias: [] as Array<{ grupo: string }>
});

/**
 * Lo que se manda al guardar un pasajero.
 *
 * ⚠️ El formulario usa `''` para «vacío» —es lo que devuelve un `<input>`—, pero la API espera
 * `null`: una cadena vacía en una fecha o en un enum no es un valor que se pueda interpretar, y
 * hasta el 24/08/2026 salía como **500** al guardar a cualquiera sin fecha de nacimiento o sin
 * sexo. Alma Noriega, sin ir más lejos.
 *
 * El país es la excepción y por eso se omite en vez de anularse: su columna es `NOT NULL`, así que
 * vaciarlo no es una intención válida —y en un PATCH, lo que no se manda se queda como estaba—.
 */
const payloadDePax = () => {
  const f = paxForm.value;

  return {
    nombre: f.nombre,
    apellido: f.apellido,
    ...(f.pais ? { pais: f.pais } : {}),
    sexo: f.sexo || null,
    fechanacimiento: f.fechanacimiento || null,
    tipo: f.tipo || null,
    telefono: f.telefono || null,
    observaciones: f.observaciones || null,
    identificaciones: f.identificaciones.map(i => ({
      tipo: i.tipo,
      numero: i.numero,
      vencimiento: i.vencimiento || null,
    })),
    pertenencias: f.pertenencias,
  };
};

const docForm = ref({
  nombre: '', tipoArchivo: '', sobreescribirTraduccion: false, fileObject: null as File | null,
  // De quién es y —si es un boarding pass— de qué vuelo. Ver la tabla de alcances en
  // `CotizacionFilearchivo`: pasajero + grupo significa «lo suyo, para ese vuelo».
  //
  // ⚠️ `string | null`, no `string`: con `limpiable`, `SearchableSelect` emite **null** al vaciar
  // (`const vacio = props.multiple ? [] : null`). Todo lo que los lee usa truthiness, así que
  // funcionaba — pero el tipo decía lo que no era, y `defineEmits` sin tipar no lo delataba.
  pasajeroId: '' as string | null, grupoId: '' as string | null, vueloId: '' as string | null
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

/**
 * ¿Se está refrescando sobre algo ya pintado? Sirve para no esconder la pantalla.
 *
 * Es lo que distingue la PRIMERA carga —no hay nada que enseñar, el spinner es correcto— de las
 * doce recargas posteriores, que ocurren con el expediente delante.
 */
const refrescando = ref(false);

/**
 * Recarga el expediente entero.
 *
 * ⚠️ **Sólo tapa la pantalla si no hay nada pintado todavía.** Antes ponía `isLoading` siempre, y
 * el `v-if="isLoading"` del `<main>` sustituye TODO el contenido por un spinner. Con doce sitios
 * llamando aquí —y uno de ellos `guardarPasajero()`, que con «Guardar y siguiente» se dispara
 * **una vez por persona del manifiesto**— el expediente desaparecía y volvía en cada guardado.
 *
 * Y no es una recarga barata: `/platform/sales/cotizacion_files/{id}` mide **717 KB de media y
 * hasta 4,3 MB**, con 1,69 s de servidor y picos de 8,44 s (medido el 10/09/2026 en el log de
 * tiempos de nginx). Blanquear la pantalla durante eso, por persona, es la mitad del problema.
 *
 * La otra mitad —no recargar el expediente entero para un cambio de un pasajero— es de fondo y
 * está anotada con el trabajo del endpoint en `docs/Pendientes.md`: aquí se arregla lo que se ve,
 * no lo que pesa.
 *
 * El indicador de `refrescando` no es decorativo: sin él, un guardado sobre datos que tardan
 * cuatro segundos en volver se lee como que ya está, y alguien edita encima de lo viejo.
 */
const cargarFile = async () => {
  const primeraVez = !file.value?.['@id'];

  isLoading.value = primeraVez;
  refrescando.value = !primeraVez;
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
    refrescando.value = false;
    // Encendemos el guardián con un ligero delay tras pintar la UI
    setTimeout(() => {
      watchActivo = true;
      isDirty.value = false;
    }, 100);
  }
};

// Si el asistente toca algo del expediente, esta ficha se recarga sola en vez de pedir un
// recargón de la página — que aquí tiraría el pasajero a medio editar.
useRefrescoDelAsistente(() => { void cargarFile(); });

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

    return [
        { rotulo: 'Nombre Grupo', valor: f.nombreGrupo ?? '' },
        { rotulo: 'Titular', valor: f.pasajeroPrincipal ?? '' },
        // ⚠️ **El CONTACTO ya no sale de aquí.** `f.telefono` y `f.email` son la SEMILLA con la
        // que se creó la identidad de esa persona; a partir de ahí el dato bueno vive en la
        // identidad. Esta cara de lectura seguía pintando la semilla, así que enseñaba el número
        // viejo mientras los envíos salían al nuevo — el mismo fallo que el modo edición ya había
        // resuelto quitando su `<input>`. Lo pinta `ContactoDeIdentidad`, abajo del listado.
        { rotulo: 'País de Origen', valor: typeof f.pais === 'object' && f.pais ? (f.pais.nombre ?? '') : '' },
        // El rótulo del estado sale del mismo diccionario que el desplegable; si llegara uno que
        // no está —una migración a medias—, se enseña el valor crudo en vez de dejarlo en blanco.
        {
            rotulo: 'Estado',
            valor: (ESTADO_FILE_LABELS as Record<string, string>)[String(f.estado)] ?? String(f.estado ?? ''),
        },
        // El modo va aquí y no en una tarjeta aparte: es un DATO del expediente, del mismo rango
        // que el estado o el idioma, y tenerlo suelto arriba lo hacía parecer una sección.
        {
            rotulo: 'Modo',
            valor: FILE_MODO_CONFIG[f.modo || 'estandar']?.label ?? String(f.modo ?? ''),
        },
        {
            rotulo: 'Idioma',
            valor: idiomasDisponibles.value.find(i => i.id === (f.idiomaCliente || 'es'))?.nombre ?? (f.idiomaCliente || 'es'),
        },
    ];
});
const girandoFile = ref(false);

/** Sólo la animación. A los 180 ms la tarjeta está de canto: es cuando se cambia el contenido. */
const girarFile = (aVista: boolean) => {
    if (girandoFile.value) return;
    girandoFile.value = true;
    window.setTimeout(() => { modoVistaFile.value = aVista; girandoFile.value = false; }, 180);
};

/** Editar es una capa: «atrás» vuelve a lectura en vez de sacarte del expediente. */
const girarPanelFile = (aVista: boolean) => {
    if (aVista) capas.cerrar('file-edicion');
    else { capas.abrir('file-edicion', () => girarFile(true)); girarFile(false); }
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
  capas.abrir('pax', () => { showPaxModal.value = false; paxEditandoIri.value = null; });
};

/**
 * @param editar `true` sólo cuando se entra por la plumita. El resto —tocar la tarjeta, las
 *               flechas— abre LEYENDO: recorrer 131 fichas es lo que más se hace, y en un
 *               formulario los datos están repartidos entre campos que hay que interpretar.
 */
const abrirEdicionPax = (pax: ApiCotizacionFilepasajero, editar = false) => {
  // Saltar de una ficha a otra con las flechas NO apila: es la misma capa cambiando de contenido.
  if (!showPaxModal.value) {
    capas.abrir('pax', () => { showPaxModal.value = false; paxEditandoIri.value = null; });
  }
  modoVistaPax.value = !editar;
  subgruposPaxAbiertos.value = false;
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
    // ⚠️ Sin identidad, y a propósito: se manda la lista entera y el servidor casa cada entrada
    // por su `tipo` —`CotizacionFilepasajeroProcessor`—, así que reescribir un documento
    // actualiza la fila que ya estaba en vez de estrenar otra. Casar por IRI exigiría que la
    // identificación fuese un ApiResource propio, y no lo es —sólo existe colgando de su
    // pasajero—. Ojo: hasta el 24/08/2026 el servidor reemplazaba a ciegas y cada guardado de un
    // pasajero con documentos daba 500 contra el índice único `(pasajero, tipo)`.
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
 * La restricción es `(pasajero, tipo)` única en base: ofrecer un tipo repetido sólo consigue que
 * el servidor funda las dos entradas en una, después de que alguien haya escrito los dos números
 * y se pregunte cuál de los dos quedó.
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
/** Espejo de lo que devuelve `VuelosCargaController`. */
interface ResultadoVuelos {
  expediente: string;
  grupo: string | null;
  cambios: string[];
  avisos: string[];
  problemas: string[];
  hayCambios: boolean;
}

interface ResultadoPadron {
  expediente: string; ensayo: boolean; filasLeidas: number;
  pasajerosCreados: number; pasajerosActualizados: number; identificacionesCreadas: number;
  gruposCreados: number; pertenenciasCreadas: number; pertenenciasQuitadas: number;
  noEstanEnElArchivo: string[]; avisos: string[]; errores: string[];
}

const archivoPadron = ref<File | null>(null);
/* ── VUELOS: se pegan, no se suben ──────────────────────────────────────────
   El padrón llega en Excel porque lo llena el colegio; los vuelos llegan en un correo de la
   aerolínea, y pedir que alguien los pase a una hoja para volver a subirlos es trabajo inventado.
   Por eso aquí se PEGA el JSON.
   ⚠️ Ensayo siempre primero: el backend escribe dentro de una transacción y la deshace, así que
   el informe incluye lo que fallaría al guardar. */
const modalVuelos = ref(false);
const jsonVuelos = ref('');
const cargandoVuelos = ref(false);
const ensayoVuelos = ref<ResultadoVuelos | null>(null);

/**
 * Dos reservas COMPLETAS, comprobadas contra el importador real (ensayo: 0 problemas).
 *
 * La primera es un ida y vuelta directo con la llegada al día siguiente; la segunda, una conexión
 * de cuatro tramos. Entre las dos aparecen todas las claves que el importador entiende: `pnr`,
 * `emitido`, `notas` de la reserva, y por vuelo `numero`, `fecha`, `aerolinea`, `leg` y sus
 * `notas`. Falta a propósito `pnr_nuevo`, que RENOMBRA: en un ejemplo que se pega y se aplica,
 * eso es una trampa.
 *
 * 🔥 **Los cuatro tramos de la segunda están porque la lista REEMPLAZA.** Un PNR declara aquí
 * dónde viaja hoy, así que un ejemplo con la mitad de los tramos enseña a desvincular la vuelta
 * sin darse cuenta. Medido en el ensayo, declarando dos de cuatro: «AX3LLL deja de viajar en
 * CM749·22/09, CM337·22/09».
 *
 * ⚠️ **PNR inventados a propósito** (`AAAAAA`, `BBBBBB`). Los reales abren la reserva en la web de
 * la aerolínea con sólo un apellido, y esto es código que se lee en muchos sitios. Además, así
 * pegar el ejemplo y aplicarlo no toca nada: el importador avisa de que ese PNR no existe y se
 * salta la reserva, que es justo lo que debe pasar con un ejemplo.
 */
const EJEMPLO_VUELOS = `[
  {
    "pnr": "AAAAAA",
    "emitido": true,
    "notas": ["Equipaje de bodega 23 kg incluido"],
    "vuelos": [
      {
        "numero": "DM6771",
        "fecha": "2026-09-18",
        "aerolinea": "Arajet",
        "leg": {
          "origen": "LIM",
          "destino": "PUJ",
          "salida": "2026-09-18 03:00",
          "llegada": "2026-09-18 09:19"
        }
      },
      {
        "numero": "DM6770",
        "fecha": "2026-09-22",
        "aerolinea": "Arajet",
        "leg": {
          "origen": "PUJ",
          "destino": "LIM",
          "salida": "2026-09-22 20:22",
          "llegada": "2026-09-23 00:30"
        },
        "notas": ["Llega a Lima al día siguiente"]
      }
    ]
  },
  {
    "pnr": "BBBBBB",
    "emitido": false,
    "vuelos": [
      {
        "numero": "CM264",
        "fecha": "2026-09-18",
        "aerolinea": "Copa Airlines",
        "leg": {
          "origen": "LIM",
          "destino": "PTY",
          "salida": "2026-09-18 02:35",
          "llegada": "2026-09-18 06:12"
        }
      },
      {
        "numero": "CM177",
        "fecha": "2026-09-18",
        "aerolinea": "Copa Airlines",
        "leg": {
          "origen": "PTY",
          "destino": "PUJ",
          "salida": "2026-09-18 07:04",
          "llegada": "2026-09-18 10:44"
        }
      },
      {
        "numero": "CM749",
        "fecha": "2026-09-22",
        "aerolinea": "Copa Airlines",
        "leg": {
          "origen": "PUJ",
          "destino": "PTY",
          "salida": "2026-09-22 18:01",
          "llegada": "2026-09-22 19:40"
        }
      },
      {
        "numero": "CM337",
        "fecha": "2026-09-22",
        "aerolinea": "Copa Airlines",
        "leg": {
          "origen": "PTY",
          "destino": "LIM",
          "salida": "2026-09-22 21:20",
          "llegada": "2026-09-23 00:55"
        }
      }
    ]
  }
]`;

/**
 * Corregir UN vuelo, sin pasar por el JSON.
 *
 * 🔥 Son siete campos planos. La entidad nunca fue anidada —sólo el formato de carga, que va por
 * PNR porque así escribe la aerolínea— y lo que más se hace es exactamente esto: mover veinte
 * minutos un horario que la aerolínea reprogramó.
 */
const vueloEditando = ref<string | null>(null);
const guardandoVuelo = ref(false);
const vueloForm = ref({ numero: '', aerolinea: '', origen: '', destino: '', salida: '', llegada: '' });

/** `datetime-local` quiere `2026-09-18T03:00`; el backend manda ISO con segundos. */
const paraInput = (iso?: string | null): string => (iso ?? '').slice(0, 16);

const abrirVuelo = (v: { id?: string | null; numero?: string | null; aerolinea?: string | null;
                        origen?: string | null; destino?: string | null;
                        salida?: string | null; llegada?: string | null }) => {
  vueloEditando.value = String(v.id ?? '');
  vueloForm.value = {
    numero: v.numero ?? '',
    aerolinea: v.aerolinea ?? '',
    origen: v.origen ?? '',
    destino: v.destino ?? '',
    salida: paraInput(v.salida),
    llegada: paraInput(v.llegada),
  };
};

const guardarVuelo = async (v: { id?: string | null }) => {
  guardandoVuelo.value = true;
  const ok = await fileStore.editarVuelo(String(v.id ?? ''), { ...vueloForm.value });
  guardandoVuelo.value = false;

  if (!ok) { alert(fileStore.error || 'No se pudo guardar el vuelo.'); return; }

  vueloEditando.value = null;
  await cargarFile();
};

/** Baja el JSON de los vuelos que ya tiene el expediente, para editarlo y volver a cargarlo. */
const descargarVuelos = async () => {
  if (!file.value) return;

  const ok = await fileStore.descargarVuelos(
    extractIdStr(file.value.id || file.value['@id']) || '',
    String(file.value.localizador ?? ''),
  );

  if (!ok) alert(fileStore.error || 'No se pudieron descargar los vuelos.');
};

const abrirVuelos = () => {
  jsonVuelos.value = '';
  ensayoVuelos.value = null;
  modalVuelos.value = true;
};

const ensayarVuelos = async () => {
  if (!file.value || !jsonVuelos.value.trim()) return;

  cargandoVuelos.value = true;
  ensayoVuelos.value = await fileStore.cargarVuelos(
    extractIdStr(file.value.id || file.value['@id']) || '', jsonVuelos.value, true,
  );
  cargandoVuelos.value = false;

  if (!ensayoVuelos.value) { alert(fileStore.error || 'No se pudo leer el JSON.'); }
};

const aplicarVuelos = async () => {
  if (!file.value || !jsonVuelos.value.trim()) return;

  cargandoVuelos.value = true;
  const r = await fileStore.cargarVuelos(
    extractIdStr(file.value.id || file.value['@id']) || '', jsonVuelos.value, false,
  );
  cargandoVuelos.value = false;

  if (r && r.problemas.length === 0) {
    modalVuelos.value = false;
    await cargarFile();
  } else {
    ensayoVuelos.value = r;
    alert(fileStore.error || 'No se guardó nada: hay reservas con problemas.');
  }
};

/**
 * Copia al portapapeles y lo dice.
 *
 * 🔥 El manifiesto se lee para RELLENAR OTROS FORMULARIOS —el de la aerolínea, el del seguro, el
 * del hotel—, y hasta hoy eso era seleccionar con el ratón un número dentro de una línea con más
 * cosas. Con 131 personas y varios formularios cada una, teclear un DNI a mano es donde aparecen
 * los dígitos cambiados que nadie descubre hasta el mostrador.
 *
 * ⚠️ El aviso dura poco y es por campo, no un cartel global: lo que hace falta saber es **cuál**
 * se copió, porque hay tres números seguidos que se parecen.
 */
const copiado = ref<string | null>(null);

async function copiar(valor: string | null | undefined, marca: string): Promise<void> {
  if (!valor) return;

  try {
    await navigator.clipboard.writeText(String(valor).trim());
    copiado.value = marca;
    setTimeout(() => { if (copiado.value === marca) copiado.value = null; }, 1400);
  } catch {
    // Sin permiso de portapapeles —o sin HTTPS— no se avisa: el operador puede seleccionar a mano
    // y un error aquí interrumpiría algo que no ha pedido.
  }
}

/** La fecha como se teclea en los formularios: `dd/mm/aaaa`. */
const fechaLarga = (iso?: string | null): string =>
  (iso ?? '').slice(0, 10).split('-').reverse().join('/');

/** La bóveda arranca plegada: ver el comentario de su cabecera. */
const bovedaAbierta = ref(false);

/** Vuelos del expediente, ya ordenados por el backend (`OrderBy salida`). */
const vuelos = computed(() => file.value?.vuelos ?? []);

const horaDe = (iso?: string | null): string => (iso ?? '').slice(11, 16);
const diaDe = (iso?: string | null): string => {
  const d = (iso ?? '').slice(0, 10);
  return d ? `${d.slice(8, 10)}/${d.slice(5, 7)}` : '';
};

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

/**
 * El .xlsx de «quién ha subido sus documentos».
 *
 * No es el padrón: aquel lleva los DATOS —números, vencimientos— y se vuelve a subir. Éste lleva el
 * ESTADO de los tres escaneos y no se sube a ningún sitio; se ordena, se filtra y se convierte en
 * la lista de a quién hay que escribir hoy.
 *
 * ⚠️ Respeta los filtros de la pantalla, y por eso manda la LISTA de ids en vez de los filtros:
 * repetirlos en el servidor serían dos implementaciones de la misma pregunta, y la que se quedase
 * corta lo haría en silencio.
 */
/**
 * Baja el ZIP con los escaneos de identidad, para reenviárselo al alojamiento.
 *
 * Un hotel pide los documentos de los huéspedes antes de la llegada. Hasta ahora eso era bajar
 * los ficheros uno a uno de la bóveda y renombrarlos a mano.
 *
 * Respeta los filtros igual que la hoja, y por el mismo motivo: se manda la LISTA de ids resuelta
 * en vez de repetir los filtros en el servidor.
 *
 * ⚠️ Puede tardar: son decenas de imágenes a 2400 px. El botón se bloquea mientras tanto — sin
 * eso, dos clics son dos ZIP generándose a la vez sobre el mismo expediente.
 */
const descargandoEscaneos = ref(false);
const panelEscaneos = ref(false);

/**
 * Qué escaneos entran en el ZIP.
 *
 * ⚠️ **No es comodidad: son documentos de identidad de terceros.** Un hotel pide el documento; una
 * discoteca que exige mayoría de edad, sólo el que lleva la fecha de nacimiento; una aerolínea, el
 * pasaporte. Mandar los tres siempre es mandarle a alguien el DNI de una persona que sólo pidió el
 * pasaporte.
 *
 * Arrancan los tres marcados —que es lo que hacía antes— para que no cambie el resultado de quien
 * no se pare a elegir.
 */
const TIPOS_ESCANEO = [
  { valor: 'pasaporte', etiqueta: 'Pasaporte' },
  { valor: 'dni_anverso', etiqueta: 'DNI anverso' },
  { valor: 'dni_reverso', etiqueta: 'DNI reverso' },
];

const tiposEscaneo = ref<string[]>(TIPOS_ESCANEO.map(t => t.valor));

/**
 * Cuántos ESCANEOS hay de cada tipo, sobre la gente que se va a exportar.
 *
 * ⚠️ **Cuenta ficheros, no personas, y ésa es la corrección.** El botón decía «(124)» —las
 * personas del filtro— y el ZIP traía otra cosa: quien no ha subido su foto cuenta como persona y
 * no como fichero. Quien recibía el sobre contaba y no le cuadraba, y el que lo mandó no podía
 * saberlo hasta abrirlo.
 *
 * Se calcula sobre `filearchivos` del expediente, que ya está cargado: no hace falta preguntar al
 * servidor para saber qué vas a mandar antes de mandarlo.
 */
const escaneosPorTipo = computed<Record<string, number>>(() => {
  // ⚠️ `@id` y no `id`: el pasajero NO expone `id` en su grupo de lectura, sólo el IRI. Y en
  // minúsculas, para casar con `claveDeRelacion` del otro lado.
  const gente = new Set(
    (hayFiltros.value ? pasajerosFiltrados.value : pasajerosConsiderados.value)
      .map(p => extractIdStr(p['@id'] ?? p.id).toLowerCase()),
  );
  const mapa: Record<string, number> = {};

  for (const a of file.value?.filearchivos ?? []) {
    // ⚠️ `claveDeRelacion` y no `extractIdStr`: normaliza a minúsculas, y es lo que ya usa
    // `estadoDocumentalDe()` para esta misma comparación. Con `extractIdStr` a secas los dos lados
    // no casaban y el panel enseñaba **0 en los tres tipos** — sin error, sólo un cero que parecía
    // un expediente sin escaneos.
    const dueno = claveDeRelacion(a.pasajero);
    // Sin dueño no viaja: no se puede nombrar. Ver `PaqueteDeEscaneos`.
    if (!dueno || !gente.has(dueno)) continue;
    const tipo = String(a.tipoArchivo);
    mapa[tipo] = (mapa[tipo] ?? 0) + 1;
  }

  return mapa;
});

/** Los ficheros que llevará el ZIP con lo marcado ahora mismo. */
const totalEscaneosElegidos = computed(() =>
  tiposEscaneo.value.reduce((n, t) => n + (escaneosPorTipo.value[t] ?? 0), 0));

const alternarTipoEscaneo = (valor: string) => {
  tiposEscaneo.value = tiposEscaneo.value.includes(valor)
    ? tiposEscaneo.value.filter(v => v !== valor)
    : [...tiposEscaneo.value, valor];
};

const descargarEscaneos = async () => {
  const id = extractIdStr(file.value?.id || file.value?.['@id'] || '');
  if (!id) return;

  const ruta = `/cotizacion/user/manifiesto/escaneos/${id}`;
  // Con tipos elegidos hay que ir por POST aunque no haya filtro de gente: el GET no lleva cuerpo.
  // Por eso, sin filtros, se mandan los ids de todo el mundo.
  const todosLosTipos = tiposEscaneo.value.length === TIPOS_ESCANEO.length;
  const ids = (hayFiltros.value ? pasajerosFiltrados.value : (todosLosTipos ? [] : pasajerosConsiderados.value))
    .map(p => extractIdStr(p['@id'] ?? p.id)).filter(Boolean);

  if (!tiposEscaneo.value.length) { alert('Elige al menos un tipo de documento.'); return; }
  if (hayFiltros.value && !ids.length) return;

  descargandoEscaneos.value = true;
  panelEscaneos.value = false;
  try {
    const { data } = ids.length === 0
      ? await apiClient.get(ruta, { responseType: 'blob' })
      : await apiClient.post(ruta, { ids, tipos: tiposEscaneo.value }, { responseType: 'blob' });

    const url = URL.createObjectURL(data as Blob);
    const a = document.createElement('a');
    a.href = url;
    // El nombre lo pone el servidor en el Content-Disposition, pero un `download` vacío deja al
    // navegador inventándose «descarga.zip»: se repite aquí lo esencial.
    // También el nombre del fichero: se reenvía sin abrirlo, y «documentos-124» sobre un ZIP de
    // 117 es una promesa que alguien va a contar.
    a.download = `documentos-${totalEscaneosElegidos.value}.zip`;
    a.click();
    URL.revokeObjectURL(url);
  } catch {
    alert('No se pudo generar el paquete de documentos.');
  } finally {
    descargandoEscaneos.value = false;
  }
};

const descargarDocumentos = async () => {
  const id = extractIdStr(file.value?.id || file.value?.['@id'] || '');
  if (!id) return;

  const ruta = `/cotizacion/user/manifiesto/documentos/${id}`;

  if (!hayFiltros.value) {
    void bajarHoja(ruta, 'documentos.xlsx');
    return;
  }

  // ⚠️ `@id`, NO `id`: el pasajero no expone `id` en el grupo de lectura, sólo el IRI.
  const ids = pasajerosFiltrados.value.map(p => extractIdStr(p['@id'] ?? p.id)).filter(Boolean);
  if (!ids.length) return;

  descargandoPlantilla.value = true;
  try {
    const { data } = await apiClient.post(ruta, { ids }, { responseType: 'blob' });
    const url = URL.createObjectURL(data as Blob);
    const a = document.createElement('a');
    a.href = url;
    // ⚠️ El NOMBRE también lo dice. El título dentro de la hoja avisa de que es una selección,
    // pero un fichero adjunto se reenvía por su nombre y muchas veces sin abrirlo.
    a.download = `documentos-filtrado-${ids.length}.xlsx`;
    a.click();
    URL.revokeObjectURL(url);
  } catch {
    alert('No se pudo descargar el archivo.');
  } finally {
    descargandoPlantilla.value = false;
  }
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
        .filter(g => !esVuelo(g) && String(g.tipo) !== 'servicio')
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
        .filter(esVuelo)
        .map(g => ({
            id: String(g.id),
            tramo: tramoDe(g),
            nombre: g.nombre ?? '',
            clave: g.clave,
            emitido: g.emitido !== false,
            notas: g.notas ?? [],
            /* El itinerario ya no sale del texto del grupo: sale de los vuelos, que es lo que
               carga el JSON de la aerolínea. Se cruza por el PNR, que es el que los une. */
            tramos: vuelos.value
                .filter(v => (v.pnrs ?? []).includes(String(g.clave)))
                .map(v => ({
                    numero: v.numero,
                    ruta: `${v.origen} → ${v.destino}`,
                    dia: diaDe(v.salida),
                    sale: horaDe(v.salida),
                    llega: horaDe(v.llegada),
                    /* «+1 día» se DICE. Dejar que quien lee reste dos fechas es de lo que más se
                       equivoca uno mirando un itinerario nocturno. */
                    otroDia: diaDe(v.llegada) !== diaDe(v.salida),
                })),
        }));

/* Qué PNRs están desplegados en la ficha. Se abre con clic o toque —un `Set` y un botón, sin
   `hover`, que en un móvil no existe—. */
const pnrsAbiertos = ref<Set<string>>(new Set());

const alternarPnr = (clave: string) => {
    const abiertos = new Set(pnrsAbiertos.value);

    if (abiertos.has(clave)) {
        abiertos.delete(clave);
    } else {
        abiertos.add(clave);
    }

    pnrsAbiertos.value = abiertos;
};

/**
 * El orden del manifiesto: por JERARQUÍA, no por como vinieran en la hoja.
 *
 * Quien abre la lista busca primero a quien manda —el supervisor, los coordinadores— porque es
 * con quien se habla. Alfabético dentro de cada rango, que es como se busca a una persona
 * concreta.
 *
 * ⚠️ El `?? 90` importa: un pasajero **sin rol** —lo normal en un expediente que no es de grupo,
 * y en cualquier padrón cargado sin la columna «Rol»— no rompe nada, cae al final en bloque y
 * sigue ordenado alfabéticamente entre los suyos.
 */
const RANGO_ROL: Record<string, number> = {
    supervisor: 0, coordinador: 1, participante: 2, acompanante: 3, invitado: 4, no_participa: 5,
};

const porJerarquia = (a: ApiCotizacionFilepasajero, b: ApiCotizacionFilepasajero): number => {
    const ra = RANGO_ROL[String(a.tipo)] ?? 90;
    const rb = RANGO_ROL[String(b.tipo)] ?? 90;
    if (ra !== rb) return ra - rb;

    return `${a.apellido ?? ''} ${a.nombre ?? ''}`.localeCompare(`${b.apellido ?? ''} ${b.nombre ?? ''}`, 'es');
};

// ── Alerta de documentos ──────────────────────────────────────────────────
//
// ⚠️ Cuatro estados distintos, y ninguno se puede leer como los otros:
//   observado     → el escaneo no dice lo mismo que el manifiesto: alguien tiene que decidir
//   sin foto      → tiene el número tecleado y NADIE ha subido el escaneo: hay que escribirle
//   vencido       → hoy ya no sirve
//   vence pronto  → sirve hoy y no el día del viaje, o no lo aceptan en frontera
//   sin comprobar → NO es «vigente». No sabemos, y eso es exactamente lo que hay que mirar.
//
// El primero describe TRABAJO PENDIENTE y los otros tres describen el documento. Van juntos a
// propósito: quien abre estos filtros se pregunta «¿qué documentos me dan problema?», y tener que
// mirar en dos sitios para responder a una sola pregunta es lo que hace que no se mire ninguno.
const filtroDocumento = ref<string[]>([]);
const filtrosAbiertos = ref(false);

const estadoDocumentalDe = (pax: ApiCotizacionFilepasajero): Set<string> => {
    const hoy = new Date();
    const enUnAnio = new Date(hoy.getFullYear() + 1, hoy.getMonth(), hoy.getDate());
    // ⚠️ Componentes LOCALES, no `toISOString()`: éste da el día en UTC y desde las 19:00 de Lima
    // adelanta uno, así que un pasaporte que vence hoy salía «vencido» en esta lista mientras la
    // ficha de al lado —ya corregida— lo daba por vigente. Ver `dominio/fecha/naive.ts`.
    const iso = (d: Date) => {
        const p = (n: number) => String(n).padStart(2, '0');

        return `${d.getFullYear()}-${p(d.getMonth() + 1)}-${p(d.getDate())}`;
    };
    const estados = new Set<string>();

    const susEscaneos = new Set(
        (file.value?.filearchivos ?? [])
            .filter(a => claveDeRelacion(a.pasajero) === extractIdStr(pax.id ?? pax['@id']).toLowerCase())
            .map(a => String(a.tipoArchivo)),
    );

    for (const doc of pax.identificaciones ?? []) {
        // El veredicto del control va en la MISMA lista que los estados de vencimiento, y no en
        // un grupo aparte: quien mira estos filtros se pregunta «¿qué documentos me dan problema?»
        // y un documento observado es exactamente eso. Separarlos obligaría a mirar en dos sitios.
        if (doc.estadoValidacion === 'observado') { estados.add('observado'); }

        // 🔥 **Falta la FOTO, que no es lo mismo que faltar la validación.** Tiene el número
        // tecleado pero nadie ha subido el escaneo, así que no hay nada que comprobar — y sobre
        // todo: es a quien hay que **escribirle**, no a quien hay que revisar. Mezclarlo con
        // «observado» juntaría dos trabajos que hacen personas distintas en momentos distintos.
        //
        // ⚠️ `tipoDeEscaneo` lo calcula el backend: qué escaneo respalda este número. Deducirlo
        // aquí sería un cuarto sitio donde esa pareja tiene que decir lo mismo.
        const necesita = (doc as { tipoDeEscaneo?: string | null }).tipoDeEscaneo;
        if (necesita && !susEscaneos.has(necesita)) { estados.add('sin_escaneo'); }

        const vence = doc.vencimiento ? doc.vencimiento.split('T')[0] : '';
        if (!vence) { estados.add('sin_fecha'); continue; }
        if (vence < iso(hoy)) { estados.add('vencido'); continue; }
        if (vence < iso(enUnAnio)) { estados.add('pronto'); }
    }

    return estados;
};

const ETIQUETAS_DOCUMENTO: Record<string, { label: string; clase: string; punto: string }> = {
    // Primero, porque es la cola de trabajo: son los que esperan a que alguien decida algo. Los
    // otros tres describen el documento; éste describe lo que hay pendiente de hacer.
    observado:   { label: 'Observado',     clase: 'bg-amber-100 text-amber-800 border-amber-300',  punto: 'bg-amber-600' },
    sin_escaneo: { label: 'Sin foto',      clase: 'bg-violet-100 text-violet-700 border-violet-300', punto: 'bg-violet-500' },
    vencido:    { label: 'Vencido',       clase: 'bg-red-100 text-red-700 border-red-200',       punto: 'bg-red-500' },
    pronto:     { label: 'Vence < 1 año', clase: 'bg-orange-100 text-orange-700 border-orange-200', punto: 'bg-orange-500' },
    sin_fecha:  { label: 'Sin comprobar', clase: 'bg-amber-100 text-amber-800 border-amber-200',  punto: 'bg-amber-500' },
};

const conteoPorDocumento = computed<Record<string, number>>(() => {
    const mapa: Record<string, number> = {};
    for (const p of pasajerosConsiderados.value) {
        for (const e of estadoDocumentalDe(p)) mapa[e] = (mapa[e] ?? 0) + 1;
    }

    return mapa;
});

const filtroRol = ref<string[]>([]);
const filtroAerolinea = ref<string[]>([]);   // «tramo|NOMBRE», p. ej. «Nacional|JetSMART»

/** El único eje de vuelo. El TRAMO es un dato del grupo, no un tipo. */
const EJE_AEREO = 'reserva_aerea';

const esVuelo = (g: ApiFileGrupo): boolean => String(g.tipo) === EJE_AEREO;

/** El rótulo del tramo: «Nacional», «Cusco-Puno»… o «Vuelo» si el viaje tiene uno solo. */
const tramoDe = (g: ApiFileGrupo): string => (g.subeje ?? '').trim() || 'Vuelo';

/**
 * Las aerolíneas que hay, por TRAMO: «Nacional → JetSMART, Sky Airline».
 *
 * ⚠️ Los tramos salen de los datos, no de una lista: un multitramo Lima→Cusco→Puno→Lima genera
 * cuatro facetas sin que nadie las declare. Y la aerolínea sale del `nombre` del grupo: ocho
 * localizadores distintos son la misma Arajet, y lo que se quiere es «los de Arajet».
 */
const aerolineasPorEje = computed(() => {
    const porTramo = new Map<string, Set<string>>();

    for (const g of (file.value?.grupos ?? [])) {
        if (!esVuelo(g) || !g.nombre) continue;
        const tramo = tramoDe(g);
        if (!porTramo.has(tramo)) porTramo.set(tramo, new Set());
        porTramo.get(tramo)?.add(String(g.nombre));
    }

    return [...porTramo.entries()]
        .sort((a, b) => a[0].localeCompare(b[0], 'es'))
        .map(([tramo, nombres]) => ({ eje: tramo, label: tramo, nombres: [...nombres].sort() }));
});

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
const alternarDocumento = (valor: string) => { filtroDocumento.value = alternar(filtroDocumento.value, valor); };
const alternarAerolinea = (valor: string) => { filtroAerolinea.value = alternar(filtroAerolinea.value, valor); };

const pasajerosFiltrados = computed<ApiCotizacionFilepasajero[]>(() => {
    const texto = busquedaPax.value.trim().toLowerCase();

    // ⚠️ Y entre EJES, O dentro del mismo eje.
    //
    // Acumular todo con Y era un error mío: elegir dos habitaciones daba cero, porque nadie está
    // en dos a la vez. La pregunta real es «los del grupo 5 que estén en HA01 **o** HA02», que es
    // exactamente el comportamiento de cualquier filtro por facetas.
    // ⚠️ **Las NEGACIONES no siguen esa regla, y no es un descuido.**
    //
    // Un positivo dentro del mismo eje es una ALTERNATIVA —«HA01 o HA02»— porque nadie está en
    // dos habitaciones. Una negación es una RESTRICCIÓN: «que no vaya al Coco Bongo **y** que no
    // lleve traslado» son dos condiciones, las dos tienen que cumplirse. Meterlas en el mismo O
    // daría «que le falte alguno de los dos», que es casi todo el mundo.
    const porEje = new Map<string, string[]>();
    const negados: string[] = [];

    for (const iri of gruposFiltrados.value) {
        if (gruposNegados.value.has(iri)) {
            negados.push(extractIdStr(iri));
            continue;
        }

        const eje = String(grupoDeIri(iri)?.tipo ?? '');
        porEje.set(eje, [...(porEje.get(eje) ?? []), extractIdStr(iri)]);
    }

    return pasajerosConsiderados.value.filter((pax) => {
        const suyos = gruposDePax(pax);

        if (filtroRol.value.length && !filtroRol.value.includes(String(pax.tipo))) return false;

        if (porEje.size || negados.length) {
            const ids = new Set(suyos.map(g => extractIdStr(iriDeGrupoPlano(g))));

            for (const elegidos of porEje.values()) {
                if (!elegidos.some(id => ids.has(id))) return false;
            }

            // Y para cada negado: si lo tiene, fuera.
            for (const id of negados) {
                if (ids.has(id)) return false;
            }
        }

        if (filtroAerolinea.value.length) {
            const suyas = new Set(suyos.filter(g => esVuelo(g) && g.nombre).map(g => `${tramoDe(g)}|${g.nombre}`));
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

        if (filtroDocumento.value.length) {
            const suyos = estadoDocumentalDe(pax);
            if (!filtroDocumento.value.some(e => suyos.has(e))) return false;
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
    }).sort(porJerarquia);
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

/**
 * A quién se le puede colgar un archivo. Los 133 del padrón, ordenados como el manifiesto.
 *
 * ⚠️ `SearchableSelect` por lo mismo que los subgrupos: con 133 personas, una lista nativa en un
 * móvil es una pared. Aquí además se busca por documento, que es lo que trae escrito el fichero
 * que llega de la aerolínea.
 */
const pasajerosElegibles = computed(() =>
    (file.value?.filepasajeros ?? []).map(p => ({
        value: extractIdStr(p.id ?? p['@id']) ?? '',
        label: [p.nombre, p.apellido].filter(Boolean).join(' '),
        sublabel: (p.identificaciones ?? []).map(i => i.numero).filter(Boolean).join(' · ') || 'sin documento',
    })),
);

/**
 * ¿Hay vuelo en juego? Una sola definición, que gobierna **a la vez** si el selector se ve y si el
 * vuelo se guarda — ver el aviso en `alcanceDelDoc()`.
 *
 * Un vuelo ya puesto mantiene el selector abierto aunque el tipo deje de ser `boleto`: si no, el
 * dato se queda dentro sin que nadie pueda verlo ni quitarlo.
 */
const ofreceVuelo = computed(() =>
    Boolean(docForm.value.pasajeroId) && (docForm.value.tipoArchivo === 'boleto' || Boolean(docForm.value.vueloId)),
);

/**
 * Los vuelos de la persona elegida. `null` = no se acota.
 *
 * ⚠️ **Se cruza por el PNR, no por la relación.** El backend lo hace por
 * `pasajero → pertenencias → grupo → vuelos` (`CargaMasivaDeArchivos::vuelaEseVuelo()`), pero
 * `CotizacionFileGrupo::$vuelos` **no está serializado** —el `#[Groups]` que hay junto a él es de
 * `$notas`, no suyo— y publicarlo metería una `ManyToMany` entera en cada subgrupo. Desde el
 * navegador el camino es el que ya usa `vuelosDe()`: la `clave` del subgrupo aéreo es el PNR, y
 * cada vuelo trae los suyos en `pnrs`.
 *
 * ⚠️ Un PNR cubre ida y vuelta, así que esto acota a los suyos —ocho— pero **no** distingue cuál
 * de los dos sentidos: eso lo dice el número de vuelo en la etiqueta, que es como se elige.
 */
const vuelosDelElegido = computed<Set<string> | null>(() => {
    const elegido = docForm.value.pasajeroId;
    if (!elegido) return null;

    const pax = (file.value?.filepasajeros ?? []).find(p =>
        extractIdStr(p.id ?? p['@id']).toLowerCase() === String(elegido).toLowerCase());
    if (!pax) return null;

    const susPnrs = new Set(gruposDePax(pax).filter(esVuelo).map(g => String(g.clave)).filter(Boolean));
    const suyos = new Set(
        (file.value?.vuelos ?? [])
            .filter(v => (v.pnrs ?? []).some(pnr => susPnrs.has(String(pnr))))
            .map(v => extractIdStr(v.id).toLowerCase())
            .filter(Boolean),
    );

    // Sin vuelos atados todavía, acotar dejaría la lista vacía y bloquearía el trabajo. Mejor
    // ofrecerlos todos que impedir guardar por un dato que aún no ha llegado.
    return suyos.size ? suyos : null;
});

/**
 * De qué VUELO es el boarding pass.
 *
 * 🔥 **Ofrecía subgrupos de reserva aérea, y eso es justo lo que no servía.** La clave de un
 * subgrupo es el PNR, y un PNR cubre ida y vuelta: `DM6771` y `DM6770` caen en el mismo. Quien
 * vuela Cusco–Lima, Lima–Panamá y Panamá–Punta Cana ida y vuelta tiene ocho tarjetas y este
 * desplegable sólo sabía decir cuatro cosas.
 *
 * ⚠️ **Y ofrecía los VEINTICUATRO del expediente, no los ocho suyos.** El docblock de
 * `CotizacionFilearchivo::$vuelo` decía que ofrecía los suyos y era falso: nada comprobaba que
 * esa persona volara ese vuelo, ni aquí ni en la API — sólo lo hacía la carga por ZIP. Al
 * reasignar de una persona a otra el vuelo del anterior seguía preseleccionado, así que era
 * exactamente el caso de uso del formulario el que dejaba la pareja torcida.
 *
 * ⚠️ **El que ya está puesto NO se cae de la lista aunque no sea suyo.** Si desapareciera,
 * `SearchableSelect` enseñaría un hueco con un valor detrás que el operador no puede ver ni
 * quitar — que es peor que enseñarlo marcado.
 */
const vuelosElegibles = computed(() => {
    const suyos = vuelosDelElegido.value;
    const puesto = String(docForm.value.vueloId ?? '').toLowerCase();

    return (file.value?.vuelos ?? [])
        // Sin `@id`: JSON-LD lo añade en tiempo de ejecución pero no está en el esquema.
        .map(v => ({ v, id: extractIdStr(v.id) }))
        .filter(({ id }) => !suyos || suyos.has(id.toLowerCase()) || id.toLowerCase() === puesto)
        .map(({ v, id }) => {
            const ajeno = suyos !== null && !suyos.has(id.toLowerCase());

            return {
                value: id,
                label: [v.numero, [v.origen, v.destino].filter(Boolean).join(' → ')].filter(Boolean).join(' · '),
                sublabel: [diaDe(v.salida ?? v.fecha) || '', ajeno ? '⚠️ no lo vuela' : ''].filter(Boolean).join(' · '),
            };
        });
});

/**
 * De qué SUBGRUPO es: cualquiera, no sólo los de vuelo.
 *
 * Es la tercera fila de la tabla de alcances —el namelist que manda la aerolínea con el PNR, la
 * lista de una habitación— y el formulario nunca la ofreció, así que ese alcance existía en la
 * base y no había forma de usarlo desde la pantalla.
 *
 * ⚠️ Con su EJE delante («Vuelo · 54X6ZM», «Habitación · 12»): un expediente tiene 9 grupos, 66
 * habitaciones y 24 reservas aéreas, y una lista de claves sueltas no se lee.
 */
const subgruposElegibles = computed(() =>
    (file.value?.grupos ?? []).map(g => {
        const eje = GRUPO_TIPO_LABELS[String(g.tipo)]?.label ?? String(g.tipo ?? '');

        return {
            value: extractIdStr(g.id ?? g['@id']) ?? '',
            label: [eje, g.clave || g.nombre].filter(Boolean).join(' · '),
            sublabel: [g.subeje, `${contarEnGrupo(g)} pax`].filter(Boolean).join(' · '),
        };
    }),
);

// ── Carga masiva por ZIP ────────────────────────────────────────────────────
//
// 🔥 ~1 060 boarding passes en un expediente grande. De uno en uno no es una molestia: es
// inviable. El ZIP se nombra `DOCUMENTO-VUELO` y el servidor reparte.
//
// ⚠️ **Dos pasos, y el primero no guarda nada.** Con mil ficheros, aplicar a ciegas mete el
// boarding pass de uno en la ficha de otro y no se descubre hasta el gate.
const zipPlan = ref<PlanCargaZip | null>(null);
const zipCargando = ref(false);
const zipAplicando = ref(false);

/**
 * Icono y color por clase de archivo.
 *
 * El backend dice QUÉ es (`CotizacionFilearchivo::getTipoMedio()`, que lo saca de la extensión que
 * puso el `Namer` a partir del contenido); aquí sólo se elige cómo se pinta.
 */
const MEDIOS: Record<string, { icono: string; clase: string }> = {
    pdf: { icono: 'far fa-file-pdf', clase: 'bg-rose-100 text-rose-600' },
    imagen: { icono: 'far fa-image', clase: 'bg-sky-100 text-sky-600' },
    video: { icono: 'far fa-file-video', clase: 'bg-violet-100 text-violet-600' },
    audio: { icono: 'far fa-file-audio', clase: 'bg-amber-100 text-amber-600' },
    hoja: { icono: 'far fa-file-excel', clase: 'bg-emerald-100 text-emerald-600' },
    otro: { icono: 'far fa-file', clase: 'bg-slate-100 text-slate-500' },
};

const mediaDe = (tipo?: string | null) => MEDIOS[tipo ?? 'otro'] ?? MEDIOS.otro;

const zipCasan = computed(() => (zipPlan.value?.filas ?? []).filter(f => !f.problema));
const zipFallan = computed(() => (zipPlan.value?.filas ?? []).filter(f => f.problema));

/**
 * Cuántos pisan un boarding pass que ya estaba.
 *
 * 🔥 Los ZIP llegan dos y tres veces —uno corregido, otro con los que faltaban—. Sin sustituir,
 * cada pasada dejaría una copia más y el cliente llegaría al gate eligiendo entre dos.
 */
const zipReemplazan = computed(() => zipCasan.value.filter(f => f.reemplaza).length);

const elegirZip = async (evento: Event) => {
    const archivo = (evento.target as HTMLInputElement).files?.[0];
    if (!archivo || !file.value) return;

    zipCargando.value = true;
    zipPlan.value = await fileStore.planificarZip(String(extractIdStr(file.value.id ?? file.value['@id'])), archivo);
    zipCargando.value = false;

    if (!zipPlan.value) alert(fileStore.error || 'No se pudo leer el ZIP.');
    (evento.target as HTMLInputElement).value = '';
};

/** Tirar la carga: también en el servidor, o el extracto se queda ocupando disco. */
const descartarZip = async () => {
    const carpeta = zipPlan.value?.carpeta;
    zipPlan.value = null;

    if (carpeta && file.value) {
        await fileStore.descartarZip(String(extractIdStr(file.value.id ?? file.value['@id'])), carpeta);
    }
};

const aplicarZip = async () => {
    if (!zipPlan.value?.carpeta || !file.value) return;

    zipAplicando.value = true;
    const creados = await fileStore.aplicarZip(
        String(extractIdStr(file.value.id ?? file.value['@id'])),
        zipPlan.value.carpeta,
    );
    zipAplicando.value = false;

    if (creados === null) {
        alert(fileStore.error || 'No se pudo aplicar la carga.');
        return;
    }

    zipPlan.value = null;
    await cargarFile();
};

/**
 * Quién es cada pasajero, vuelo y subgrupo, indexado por id **en minúsculas**.
 *
 * ⚠️ **Esto era tres `find()` por archivo, y se pagaban dos veces por fila.** Con ~1 500 archivos,
 * 133 pasajeros, 99 subgrupos y 24 vuelos son ~43 ms de render en un portátil y ~200 ms en un
 * móvil — y no sólo al teclear: Vue no cachea las llamadas a función de la plantilla, así que se
 * repetía en **cualquier** cambio reactivo con la bóveda abierta. Medido; con el índice baja a
 * 0,7 ms.
 *
 * ⚠️ **Y las claves van en minúsculas a propósito.** Antes se casaba con `iri.endsWith(id)`, que
 * con UUIDs muerde: un id en otra caja —`0198E5F1…` frente a `0198e5f1…`— no casa, y **falla
 * hacia el lado que no se ve**: el archivo parece del expediente entero cuando es de alguien.
 */
const indiceDeDuenos = computed(() => {
    const nombra = <T,>(filas: T[], id: (f: T) => unknown, etiqueta: (f: T) => string) => {
        const mapa = new Map<string, string>();
        for (const fila of filas) {
            const clave = extractIdStr(id(fila)).toLowerCase();
            if (clave) mapa.set(clave, etiqueta(fila));
        }
        return mapa;
    };

    return {
        pasajeros: nombra(file.value?.filepasajeros ?? [], p => p.id ?? p['@id'],
            p => [p.nombre, p.apellido].filter(Boolean).join(' ')),
        vuelos: nombra(file.value?.vuelos ?? [], v => v.id,
            v => [v.numero, [v.origen, v.destino].filter(Boolean).join('→')].filter(Boolean).join(' ')),
        subgrupos: nombra(file.value?.grupos ?? [], g => g.id ?? g['@id'],
            g => g.clave || g.nombre || ''),
    };
});

/** El id que hay detrás de una relación, venga como IRI o como objeto embebido. */
const idDeRelacion = (rel: unknown): string => {
    if (!rel) return '';
    const iri = typeof rel === 'string' ? rel : (rel as { '@id'?: string })['@id'];
    return iri ? extractIdStr(iri) : '';
};

/** Igual, pero en la forma en que el índice guarda sus claves. */
const claveDeRelacion = (rel: unknown): string => idDeRelacion(rel).toLowerCase();

/**
 * De quién es un archivo, para la fila de la bóveda: «Ana Pérez · LA2695 LIM→PUJ».
 *
 * Vacío cuando cuelga del expediente entero, que es lo de siempre y no hace falta decirlo.
 *
 * ⚠️ El VUELO faltaba, y es el alcance que más se usa: los ~1 060 boarding passes que entran por
 * ZIP se guardan con pasajero + vuelo, así que la fila decía sólo el nombre y las ocho tarjetas de
 * una misma persona se leían idénticas.
 */
const duenoDelArchivo = (doc: ApiCotizacionFilearchivo): string => {
    const indice = indiceDeDuenos.value;

    return [
        indice.pasajeros.get(claveDeRelacion(doc.pasajero)),
        indice.vuelos.get(claveDeRelacion(doc.vuelo)),
        indice.subgrupos.get(claveDeRelacion(doc.grupo)),
    ].filter(Boolean).join(' · ');
};

/**
 * Cómo se pinta cada veredicto. Espejo de `ValidacionIdentificacionEnum::getColor()` en PHP —
 * **hay que tocar los dos** si se añade un estado.
 *
 * ⚠️ Los dos verdes NO son el mismo verde a propósito. `validado_mrz` lo respaldan dígitos de
 * control; `validado_ocr` son dos lecturas que coinciden, y ésas pueden equivocarse las dos si el
 * error venía del padrón original. Pintarlos igual borraría la única diferencia que importa.
 *
 * ⚠️ Y desde que se vio que el DNI peruano nuevo lleva banda TD1 en el anverso, **`validado_mrz`
 * ya no es sólo del pasaporte**.
 */
const SELLO: Record<string, { texto: string; clase: string; icono: string }> = {
    no_validado: { texto: 'sin validar', clase: 'bg-white text-slate-400 border-slate-200', icono: 'fa-circle-question' },
    observado: { texto: 'observado', clase: 'bg-amber-50 text-amber-700 border-amber-300', icono: 'fa-triangle-exclamation' },
    validado_ocr: { texto: 'validado OCR', clase: 'bg-sky-50 text-sky-700 border-sky-300', icono: 'fa-check' },
    validado_mrz: { texto: 'validado MRZ', clase: 'bg-emerald-50 text-emerald-700 border-emerald-300', icono: 'fa-shield-halved' },
};

/**
 * Las identificaciones que ya pasaron por el control.
 *
 * ⚠️ Se filtran las `no_validado` **sin nota**: son las que nadie ha mirado todavía, y pintar
 * «sin validar» en las 263 antes de la primera pasada llenaría el manifiesto de gris sin decir
 * nada. Las que sí traen nota —«no hay escaneo en la bóveda»— se quedan: eso sí es información.
 */
const identificacionesConVeredicto = (pax: ApiCotizacionFilepasajero) =>
    (pax.identificaciones ?? []).filter(i =>
        i.estadoValidacion && (i.estadoValidacion !== 'no_validado' || (i.notasValidacion ?? []).length > 0));

/** Cuántas piden que alguien decida. Es el número que va en el botón. */
const observadas = computed(() =>
    (file.value?.filepasajeros ?? []).flatMap(p => p.identificaciones ?? [])
        .filter(i => i.estadoValidacion === 'observado').length);

const validando = ref(false);

/**
 * El botón. Lanza la tanda sobre todo el manifiesto.
 *
 * ⚠️ **Se puede pulsar dos veces sin pagar dos veces**: el backend salta lo ya resuelto y cachea
 * la lectura de cada documento. Por eso no lleva confirmación — no hay nada que confirmar.
 *
 * ⚠️ Y **no corrige nada**: escribe el veredicto. Corregir el manifiesto es una decisión de quien
 * mira los dos valores, porque a veces el equivocado es el escaneo.
 */
const validarManifiesto = async () => {
    validando.value = true;
    // ⚠️ El processor devuelve el expediente ENTERO ya actualizado, así que se usa esa
    // respuesta en vez de volver a pedirlo: con 133 personas y 315 archivos eran dos descargas del
    // mismo payload grande por cada pulsación.
    const actualizado = await fileStore.validarManifiesto(String(extractIdStr(file.value?.id ?? file.value?.['@id'])));
    validando.value = false;

    if (actualizado) file.value = actualizado;
    else alert(fileStore.error || 'No se pudo validar el manifiesto.');
};

/* ══ VISOR DE DOCUMENTOS DE UNA PERSONA ═══════════════════════════════════
   El puente que faltaba entre «este dato no coincide» y «pues mira el papel».

   ⚠️ **No cuesta NADA abrirlo.** No hay llamada a la IA: los escaneos ya están en la bóveda y su
   lectura está cacheada en el propio archivo. Sólo paga un documento nuevo que nunca se haya
   leído, y de eso se encarga la tanda, no el visor. */

const paxDelVisor = ref<ApiCotizacionFilepasajero | null>(null);

/**
 * Abre el visor **como capa**, no como un `v-if` suelto.
 *
 * 🔥 **Sin esto, el gesto de atrás no cerraba el visor: salía del expediente.** Es el motivo de que
 * `useCapasEnHistorial` exista, y yo abrí dos diálogos nuevos sin registrarlos — así que el móvil
 * hacía lo único que podía hacer: retroceder en el historial de verdad. Un diálogo que no está en
 * la pila no existe para el botón atrás.
 */
const abrirVisor = (pax: ApiCotizacionFilepasajero) => {
    paxDelVisor.value = pax;
    capas.abrir('visor-doc', () => { paxDelVisor.value = null; });
};

const cerrarVisor = () => capas.cerrar('visor-doc');

/**
 * Los escaneos torcidos de una persona, **todos**, no sólo el que respalda un número.
 *
 * 🔥 **Esto vivía en el veredicto de la identificación y estaba mal.** Traía dos fallos: enderezar
 * obligaba a recalcular el veredicto —una llamada a la IA y una recarga del expediente por cada
 * clic— y sólo miraba el escaneo que valida ese número, así que girar el anverso hacía desaparecer
 * el aviso **con el reverso todavía torcido**.
 *
 * El giro es una propiedad del ARCHIVO. Se lee de `datosLeidos`, que ya viene cargado, así que
 * cuesta cero y se actualiza en cuanto cambia el archivo.
 */
const escaneosTorcidos = (pax: ApiCotizacionFilepasajero) => {
    const id = extractIdStr(pax.id ?? pax['@id']).toLowerCase();

    return (file.value?.filearchivos ?? [])
        .filter(doc => claveDeRelacion(doc.pasajero) === id && giroSugerido(doc) > 0)
        .map(doc => ({ etiqueta: getArchivoLabel(doc.tipoArchivo), grados: giroSugerido(doc) }));
};

/** ¿Tiene algo que enseñar? Un botón que abre un modal vacío es peor que no tenerlo. */
const tieneEscaneos = (pax: ApiCotizacionFilepasajero): boolean => {
    const id = extractIdStr(pax.id ?? pax['@id']).toLowerCase();

    return (file.value?.filearchivos ?? []).some(doc => claveDeRelacion(doc.pasajero) === id);
};

/**
 * Los escaneos de esa persona, ya ordenados como se miran: primero el que respalda un veredicto.
 *
 * ⚠️ Se filtra del `file` que ya está cargado —no hay petición nueva—: los archivos vienen enteros
 * en `file:item:read` y pedirlos otra vez por persona serían 135 peticiones para nada.
 */
const documentosDelVisor = computed(() => {
    const suyo = paxDelVisor.value;
    if (!suyo) return [];

    const id = extractIdStr(suyo.id ?? suyo['@id']).toLowerCase();

    return (file.value?.filearchivos ?? [])
        .filter(doc => claveDeRelacion(doc.pasajero) === id)
        .sort((a, b) => Number(b.tipoArchivo === 'pasaporte') - Number(a.tipoArchivo === 'pasaporte'));
});

const girando = ref<string | null>(null);
const revalidando = ref<string | null>(null);

/**
 * Vuelve a cotejar los documentos de UNA persona.
 *
 * ⚠️ **No relee el documento**: coteja la lectura que ya está contra lo que hay guardado AHORA. Es
 * justo lo que hace falta tras corregir un dato del manifiesto —comprobar si el aviso se fue— y
 * cuesta cero, porque la lectura está cacheada. Si el documento se giró, su lectura se tiró y ahí
 * sí se vuelve a leer: ~$0,0016.
 */
const revalidarPax = async (pax: ApiCotizacionFilepasajero) => {
    revalidando.value = String(pax.id);
    const ok = await fileStore.revalidarPasajero(String(extractIdStr(pax.id ?? pax['@id'])));
    revalidando.value = null;

    if (!ok) { alert(fileStore.error || 'No se pudo reprocesar.'); return; }

    await cargarFile();
};

/**
 * Cuántos grados dijo el modelo que hay que girar ESE escaneo. `0` = ya está derecho.
 *
 * Se lee de la lectura cacheada, así que no cuesta nada: viene de la misma pasada que sacó los
 * datos. Es lo que precarga el botón — así el caso normal es un clic y no adivinar el sentido.
 */
const giroSugerido = (doc: ApiCotizacionFilearchivo): number => {
    // ⚠️ **Del campo calculado por el backend, NO de `datosLeidos`.** Esto leía
    // `datosLeidos.rotacion`, una clave que el modelo dejó de devolver al cambiarle la pregunta:
    // 211 de 211 lecturas traían `bordeSuperior` y ninguna `rotacion`, así que toda la interfaz de
    // giro estaba invisible **sin dar un solo error**. El mapeo vive en PHP (`Orientacion`) y aquí
    // se lee un número: un espejo en TypeScript se volvería a desincronizar.
    const grados = Number((doc as { rotacionPendiente?: number }).rotacionPendiente ?? 0);

    return [90, 180, 270].includes(grados) ? grados : 0;
};

/**
 * Gira y recarga.
 *
 * ⚠️ **Avisa de que hay que revalidar.** Girar tira la lectura a propósito —un documento torcido
 * casi siempre se leyó mal, que es la razón de girarlo— así que su veredicto se queda sin respaldo
 * hasta la siguiente tanda. Callarlo dejaría un sello verde apoyado en una lectura que ya no
 * existe.
 */
const girarDoc = async (doc: ApiCotizacionFilearchivo, grados: number) => {
    girando.value = String(doc.id);
    const respuesta = await fileStore.girarDocumento(String(extractIdStr(doc.id)), grados);
    girando.value = null;

    if (!respuesta) { alert(fileStore.error || 'No se pudo girar.'); return; }

    // ⚠️ **No se recarga el expediente.** Antes sí, y de los ~20 s que costaba un giro, 16 eran
    // eso: 133 personas, sus identificaciones, 267 archivos y los vuelos, para reflejar el cambio
    // de UN fichero. Girar tarda 0,4 s en el servidor; el resto lo ponía la pantalla.
    //
    // Se parchea en sitio lo único que cambió: la marca de tiempo —que rompe la caché de la
    // imagen— y la orientación, que quita el aviso.
    const editable = doc as { updatedAt?: string; rotacionPendiente?: number; datosLeidos?: Record<string, unknown> | null };
    editable.updatedAt = String(respuesta.actualizado ?? Date.now());
    editable.rotacionPendiente = respuesta.rotacionPendiente ?? 0;
    if (editable.datosLeidos) editable.datosLeidos.bordeSuperior = respuesta.bordeSuperior;
};

/**
 * La URL del escaneo con una marca que cambia cuando el fichero cambia.
 *
 * ⚠️ Girar **reescribe el fichero en su sitio** —los píxeles son la verdad—, así que la URL es la
 * misma y el navegador sigue sirviendo la versión vieja de su caché: se gira, la petición va bien,
 * y en pantalla no pasa nada. `updatedAt` cambia en cada escritura de la entidad.
 */
const urlFresca = (doc: ApiCotizacionFilearchivo): string | undefined => {
    const url = doc.imageUrl;
    if (!url) return undefined;

    const marca = String(doc.updatedAt ?? doc.createdAt ?? '').replace(/\D/g, '');

    return marca ? `${url}${url.includes('?') ? '&' : '?'}v=${marca}` : url;
};

/** Las claves crudas del modelo, en castellano legible. */
const ETIQUETA_LEIDA: Record<string, string> = {
    paisEmisor: 'país emisor',
    bordeSuperior: 'cabecera',
    rotacion: 'falta girar',
    numero: 'número',
};

/** Lo que el modelo leyó de ese escaneo, para poder compararlo con el papel a la vista. */
const leidoDe = (doc: ApiCotizacionFilearchivo): Record<string, unknown> | null => {
    const datos = (doc as { datosLeidos?: Record<string, unknown> | null }).datosLeidos;

    return datos && typeof datos === 'object' ? datos : null;
};

/**
 * Un PDF no se puede pintar en un `<img>`: se enlaza y se dice que es un PDF.
 *
 * ⚠️ Se pregunta a `tipoMedio`, que lo calcula el backend desde la extensión REAL en disco —la que
 * puso el `Namer` a partir de lo que Symfony dedujo del contenido—, no del nombre que trajo el
 * cliente. Fiarse del nombre es lo que hacía que un vídeo se anunciara como PDF.
 */
const esImagen = (doc: ApiCotizacionFilearchivo): boolean => String(doc.tipoMedio) === 'imagen';

/* ══ PANEL DE RESOLUCIÓN: los documentos que no son de nadie ══════════════ */

const sueltos = ref<DocumentoSuelto[]>([]);
const cargandoSueltos = ref(false);
const resolviendo = ref<string | null>(null);
const leyendo = ref<string | null>(null);

/**
 * Lee un documento suelto para poder resolverlo.
 *
 * ⚠️ **A petición y de uno en uno.** Leer cuesta ~$0,0016 y 3,5 s; con cincuenta sueltos, hacerlo
 * al abrir el panel serían cincuenta llamadas y tres minutos que nadie pidió.
 */
const leerSuelto = async (doc: DocumentoSuelto) => {
    leyendo.value = doc.id;
    const ok = await fileStore.leerDocumentoSuelto(doc.id);
    leyendo.value = null;

    if (!ok) { alert(fileStore.error || 'No se pudo leer.'); return; }

    // Se recarga la lista: al leerlo pueden aparecerle candidatos, y también cambiarle los
    // candidatos a los demás si el número casa con alguien.
    await cargarSueltos();
};
const panelSueltos = ref(false);

const cargarSueltos = async () => {
    cargandoSueltos.value = true;
    sueltos.value = await fileStore.documentosSueltos(String(extractIdStr(file.value?.id ?? file.value?.['@id'])));
    cargandoSueltos.value = false;
};

const abrirPanelSueltos = async () => {
    panelSueltos.value = true;
    // Misma razón que el visor: sin registrarlo, «atrás» se lleva por delante el expediente.
    capas.abrir('sueltos', () => { panelSueltos.value = false; });
    await cargarSueltos();
};

/**
 * Resuelve UNO.
 *
 * ⚠️ **Se recarga la lista entera al terminar, no se quita la fila.** Crear una persona cambia los
 * candidatos de los DEMÁS documentos sueltos —el siguiente ya puede casar por nombre con la que
 * acaba de nacer—, así que una lista que sólo pierde su fila enseñaría candidatos caducados.
 */
const resolverSuelto = async (doc: DocumentoSuelto, accion: 'vincular' | 'crear', pasajeroId?: string) => {
    if (accion === 'crear' && !confirm(`Se creará una persona nueva en el manifiesto con los datos de «${doc.documento?.nombre || doc.nombre}». ¿Seguro?`)) return;

    resolviendo.value = doc.id;
    const ok = await fileStore.resolverDocumento(doc.id, accion, pasajeroId);
    resolviendo.value = null;

    if (!ok) { alert(fileStore.error || 'No se pudo resolver.'); return; }

    await Promise.all([cargarSueltos(), cargarFile()]);
};

/**
 * El buscador de la bóveda: con ~1 500 archivos, bajar a ojo hasta el de una persona no es
 * viable, y el nombre del fichero casi nunca es lo que se recuerda.
 *
 * Se busca sobre lo MISMO que se ve en la fila —nombre, tipo y dueño—, y por palabras sueltas
 * en cualquier orden: «ana dni» encuentra el DNI de Ana sin acertar el orden ni el texto exacto.
 */
const bovedaBusqueda = ref('');

/** La paja de cada archivo se calcula UNA vez por lista, no una por tecla. */
const bovedaIndexada = computed(() =>
    (file.value?.filearchivos ?? []).map(doc => ({
        doc,
        paja: paraBuscar([
            getDocNombre(doc),
            getArchivoLabel(doc.tipoArchivo),
            duenoDelArchivo(doc),
        ].filter(Boolean).join(' ')),
    })),
);

const bovedaDocs = computed(() => {
    // ⚠️ Sin tildes en los DOS lados: con un padrón peruano —Núñez, José, Rodríguez— «perez» sin
    // acento es como se teclea siempre, y una lista vacía se lee como «no está subido».
    const palabras = paraBuscar(bovedaBusqueda.value.trim()).split(/\s+/).filter(Boolean);
    if (!palabras.length) return file.value?.filearchivos ?? [];

    return bovedaIndexada.value
        .filter(({ paja }) => palabras.every(palabra => paja.includes(palabra)))
        .map(({ doc }) => doc);
});

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
    gruposNegados.value.delete(iri);
};

/**
 * Los subgrupos que se piden AL REVÉS: «los que NO van al Coco Bongo».
 *
 * ── Por qué hace falta ──────────────────────────────────────────────────────
 * La pregunta que llega del proveedor casi nunca es sólo «dame los del Coco Bongo»: es «los del
 * Coco Bongo **que no** lleven traslado», o «los que **no** están en ningún vuelo nacional». Sin
 * negación eso se resolvía descargando todo y descartando a mano, que es exactamente el trabajo
 * que el filtro existe para quitar.
 *
 * ── Y NO es lo mismo que un campo «no tiene» ────────────────────────────────
 * ⚠️ Esto contesta «no está en el subgrupo», que incluye a quien **todavía no se le ha
 * asignado**. Un campo de negación explícita —«se le ofreció y dijo que no»— es otra cosa y sigue
 * sin existir: la pertenencia sólo sabe decir «sí». Para armar lo que se le manda a un proveedor
 * esta negación basta; para saber a quién ya se le preguntó, no.
 */
const gruposNegados = ref<Set<string>>(new Set());

/** Pasa un subgrupo ya elegido de «los que están» a «los que NO están», y al revés. */
const alternarNegado = (iri: string) => {
    if (gruposNegados.value.has(iri)) gruposNegados.value.delete(iri);
    else gruposNegados.value.add(iri);
    // `Set` no es reactivo por mutación en un `ref`: se reasigna para que los computed despierten.
    gruposNegados.value = new Set(gruposNegados.value);
};

const limpiarFiltros = () => {
    gruposFiltrados.value = [];
    gruposNegados.value = new Set();
    filtroRol.value = [];
    filtroAerolinea.value = [];
    filtroDocumento.value = [];
    busquedaPax.value = '';
    incluirNoParticipa.value = false;
};

const hayFiltros = computed(() =>
    gruposFiltrados.value.length > 0 || filtroRol.value.length > 0 || filtroAerolinea.value.length > 0
    || filtroDocumento.value.length > 0 || busquedaPax.value.trim() !== '' || incluirNoParticipa.value);

// ── Subgrupos del expediente ───────────────────────────────────────────────
//
// Se agrupan por EJE para pintarlos, pero no anidan: una persona está a la vez en su salón, su
// grupo, su habitación y sus reservas. Ver docs/Cotizaciones.md §6.m.
/**
 * Los subgrupos por eje Y TRAMO: «Vuelo Nacional» y «Vuelo Cusco-Puno» son dos listas, no una de
 * veinte localizadores mezclados donde no se sabe cuál es de qué vuelo.
 *
 * ⚠️ Devuelve **secciones con su rótulo y su icono ya resueltos**, no un mapa indexado por la
 * clave. Al pasar de agrupar por `tipo` a agrupar por etiqueta, los consumidores seguían haciendo
 * `GRUPO_TIPO_LABELS[clave]` —que ahora es «Vuelo Nacional», no `reserva_aerea`— y devolvía
 * `undefined`: los iconos desaparecían y el resumen decía «9 Grupo · 66 Habitación» en vez de
 * «9 grupos · 66 habitaciones». Resolviéndolo aquí, donde SÍ se conoce el tipo, no hay forma de
 * que un consumidor se equivoque.
 */
interface SeccionDeGrupos {
    clave: string;
    label: string;
    plural: string;
    icon: string;
    lista: ApiFileGrupo[];
}

const seccionesDeGrupos = computed<SeccionDeGrupos[]>(() => {
    const mapa = new Map<string, SeccionDeGrupos>();

    for (const g of (file.value?.grupos ?? [])) {
        const cfg = GRUPO_TIPO_LABELS[String(g.tipo)];
        const clave = (g.etiquetaDeEje ?? '').trim() || cfg?.label || String(g.tipo ?? 'grupo');

        if (!mapa.has(clave)) {
            // El plural del eje con el tramo pegado: «vuelos Nacional» no; «Vuelo Nacional» sí,
            // porque el tramo ya lo singulariza. Sin tramo manda el plural del diccionario.
            const tramo = (g.subeje ?? '').trim();
            mapa.set(clave, {
                clave,
                label: clave,
                plural: tramo ? clave.toLowerCase() : (cfg?.plural ?? clave.toLowerCase()),
                icon: cfg?.icon ?? 'fa-tag',
                lista: [],
            });
        }
        mapa.get(clave)?.lista.push(g);
    }

    return [...mapa.values()];
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

const subgruposPaxAbiertos = ref(false);

/** Los subgrupos que tiene marcados la ficha abierta, en el orden de los ejes. */
const gruposElegidosEnFicha = computed<ApiFileGrupo[]>(() =>
    (file.value?.grupos ?? []).filter(perteneceA));

/** Los grupos de este eje a los que pertenece Y que traen itinerario. */
const detallesDe = (lista: ApiFileGrupo[]): ApiFileGrupo[] =>
    lista.filter(g => g.detalle && perteneceA(g));

/** El borrado de subgrupos, apagado por defecto. Ver el comentario de la sección. */
const modoGestionGrupos = ref(false);

/**
 * El subgrupo que se está corrigiendo, si hay alguno.
 *
 * ⚠️ Hasta ahora sólo se podía AÑADIR y BORRAR, y eso convertía cualquier errata en un borrado:
 * un vuelo de Arajet cargado bajo «Vuelo Nacional» —pasó— obligaba a eliminar el grupo, y con él
 * las pertenencias de todos los que iban dentro. Corregirlo es un `PATCH` que la API ya ofrecía.
 */
const grupoEditando = ref<string | null>(null);
const grupoForm = ref({ tipo: '', subeje: '', clave: '', nombre: '', detalle: '', emitido: true });
const guardandoGrupo = ref(false);

const editarGrupo = (g: ApiFileGrupo) => {
    grupoEditando.value = iriDeGrupoPlano(g);
    capas.abrir('grupo-edicion', () => { grupoEditando.value = null; });
    grupoForm.value = {
        tipo: String(g.tipo ?? 'grupo'),
        subeje: g.subeje ?? '',
        clave: g.clave ?? '',
        nombre: g.nombre ?? '',
        detalle: g.detalle ?? '',
        emitido: g.emitido !== false,
    };
};

const guardarGrupo = async () => {
    if (!grupoEditando.value || !grupoForm.value.clave.trim()) return;

    guardandoGrupo.value = true;
    const ok = await fileStore.actualizarGrupo(grupoEditando.value, {
        tipo: grupoForm.value.tipo,
        subeje: grupoForm.value.subeje,
        clave: grupoForm.value.clave,
        // Vacío se manda como `null` y no como '': es lo que BORRA el rótulo. Con '' el backend
        // lo normaliza igual, pero `null` dice la intención.
        nombre: grupoForm.value.nombre || null,
        detalle: grupoForm.value.detalle || null,
        emitido: grupoForm.value.emitido,
    });
    guardandoGrupo.value = false;

    if (!ok) { alert(fileStore.error || 'No se pudo guardar el subgrupo.'); return; }

    // Por la capa, como todos los cierres: así el «atrás» siguiente no consume una entrada
    // fantasma. Ver `useCapasEnHistorial`.
    capas.cerrar('grupo-edicion');
    await cargarFile();
};

/* ── Las cuatro secciones del expediente, plegadas por defecto ─────────────
   Un expediente de grupo son 131 personas, 16 vuelos y 109 subgrupos. Desplegado todo, llegar a
   lo de abajo son seis pantallas de scroll, y quien abre un expediente viene a UNA cosa. El
   resumen del rótulo es lo que evita tener que abrir para saber si es la que busca. */
const manifiestoAbierto = ref(false);
const vuelosAbiertos = ref(false);

/**
 * El aviso de gente sin asignar, plegado.
 *
 * ⚠️ **No es una lista de tareas.** Que alguien no esté en ningún vuelo puede ser lo correcto
 * —no viaja en avión—, así que desplegarlo por defecto convertía media pantalla de información en
 * algo que parece pendiente. El recuento va en el rótulo para que plegado no se lea como vacío.
 */
const sinAsignarAbierto = ref(false);

/** «24 en grupo · 2 en habitación · 3 en vuelo»: lo que decide si hace falta abrirlo. */
const resumenSinAsignar = computed(() =>
    (file.value?.subgruposIncompletos ?? [])
        .map(h => `${h.faltan.length} en ${String(h.ejeLabel).toLowerCase()}`)
        .join(' · '));
const cargaAbierta = ref(false);
const subgruposAbiertos = ref(false);

const resumenManifiesto = computed(() => {
    const n = file.value?.filepasajeros?.length ?? 0;
    return n === 0 ? 'sin pasajeros' : `${n} persona${n === 1 ? '' : 's'}`;
});

const resumenVuelos = computed(() => {
    const n = vuelos.value.length;
    if (n === 0) { return 'sin vuelos'; }

    /* Las reservas sin emitir se cuentan en el rótulo: es lo único de aquí que exige perseguir a
       alguien, y esconderlo tras un clic es como no tenerlo. */
    const sinEmitir = (file.value?.grupos ?? []).filter(g => g.emitido === false).length;

    return `${n} vuelo${n === 1 ? '' : 's'}${sinEmitir ? ` · ${sinEmitir} sin emitir` : ''}`;
});

const resumenCarga = computed(() => 'padrón en Excel · vuelos en JSON');

/** «9 grupos · 66 habitaciones · 23 reservas · 10 servicios», para no tener que abrir. */
const resumenSubgrupos = computed(() => {
    const partes = seccionesDeGrupos.value
        .map(s => `${s.lista.length} ${(s.lista.length === 1 ? s.label : s.plural).toLowerCase()}`);

    return partes.length ? partes.join(' · ') : 'ninguno';
});

const nuevoGrupo = ref({ tipo: 'grupo', subeje: '', clave: '', nombre: '', detalle: '' });
const creandoGrupo = ref(false);

/**
 * Los ejemplos del formulario, uno por eje.
 *
 * 🔥 **Enseñaban un vuelo pasara lo que pasara.** El `placeholder` del detalle era «Ida DM6771 ·
 * LIM → PUJ» aunque estuvieras creando una habitación, y el de la clave mezclaba los cuatro ejes
 * en una fila —`B · 5 · HA13 · JA2CWN`— para no tener que elegir. Un ejemplo que no corresponde
 * a lo que estás haciendo es peor que no ponerlo: da instrucciones para otra tarea.
 *
 * ⚠️ Y el sufijo dejó de llamarse siempre «Tramo»: es lo que se dice de un vuelo, pero de una
 * habitación se dice el hotel y de un servicio, la ciudad o la fecha. La palabra tiene que ser
 * la del eje que hay delante, o el campo parece pedir otra cosa.
 */
const EJEMPLOS_EJE: Record<string, {
  subeje: string; ejemploSubeje: string; clave: string; nombre: string; detalle: string;
  ayudaSubeje: string; ayudaClave: string; ayudaNombre: string;
}> = {
  grupo: {
    subeje: 'Matiz', ejemploSubeje: 'Salón · Bus', clave: 'B · 5',
    nombre: 'QUINTO B', detalle: 'Los que van al taller de danza\nProfesora responsable: *Ana Ríos*',
    ayudaSubeje: 'Parte la lista en secciones: verás «Grupo Salón» y «Grupo Bus» por separado.',
    ayudaClave: 'Identifica el grupo y no se repite. Se guarda en mayúsculas.',
    ayudaNombre: 'Sólo una etiqueta que se lee al lado de la clave.',
  },
  habitacion: {
    subeje: 'Hotel', ejemploSubeje: 'Sonesta · Terra', clave: 'HA13',
    nombre: 'DOBLE MATRIMONIAL', detalle: 'Piso 3, vista al patio\nCheck-in 15:00 · check-out 11:00',
    ayudaSubeje: 'Parte la lista por hotel: «Habitación Sonesta» aparte de «Habitación Terra».',
    ayudaClave: 'El número de habitación. Es lo que la identifica — «DOBLE» no.',
    ayudaNombre: 'El tipo de habitación, como etiqueta. No agrupa nada.',
  },
  reserva_aerea: {
    subeje: 'Tramo', ejemploSubeje: 'Nacional · Cusco-Puno', clave: 'JA2CWN',
    nombre: 'ARAJET · DOBLE',
    detalle: 'Ida DM6771 · LIM 18/09/2026 03:00 → PUJ 18/09/2026 09:19'
      + '\nRetorno DM6770 · PUJ 22/09/2026 20:22 → LIM 23/09/2026 00:30',
    ayudaSubeje: 'Parte la lista en secciones: «Vuelo Nacional» aparte de «Vuelo Internacional».',
    ayudaClave: 'El localizador (PNR). Uno por reserva: no agrupa aerolíneas.',
    // 🔥 La razón por la que existe toda esta ayuda. Ver el aviso de `aerolineasPorEje()`.
    ayudaNombre: '🔑 La AEROLÍNEA. Es lo único que junta los PNR en un botón de filtro: '
      + 'ocho reservas con «Arajet» escrito igual dan un botón «Arajet». Escrito distinto '
      + '—«ARAJET», «Arajet SA»— salen botones separados y ninguno trae a toda la gente.',
  },
  servicio: {
    subeje: 'Matiz', ejemploSubeje: 'Punta Cana · Día 3', clave: 'COCOBONGO',
    nombre: 'NOCHE EN COCO BONGO', detalle: 'Sólo mayores de 18\nTraslado incluido desde el hotel',
    ayudaSubeje: 'Parte la lista en secciones, si tienes muchos: «Servicio Punta Cana» aparte de «Servicio Lima».',
    ayudaClave: 'Identifica el servicio y no se repite. Se guarda en mayúsculas.',
    ayudaNombre: 'El nombre para leer. Escrito IGUAL en varios subgrupos, los junta en uno solo '
      + 'al filtrar — igual que la aerolínea en los vuelos.',
  },
};

const ejemploDe = computed(() => EJEMPLOS_EJE[nuevoGrupo.value.tipo] ?? EJEMPLOS_EJE.grupo);
const etiquetaSubeje = computed(() => ejemploDe.value.subeje);
const ejemploSubeje = computed(() => ejemploDe.value.ejemploSubeje);
const ejemploClave = computed(() => ejemploDe.value.clave);
const ejemploNombre = computed(() => ejemploDe.value.nombre);
const ejemploDetalle = computed(() => ejemploDe.value.detalle);

/**
 * Qué hace cada campo, dicho en el eje que se está usando.
 *
 * ⚠️ **La ayuda del NOMBRE es la que motivó esto.** Nadie podía adivinar que el botón «Arajet»
 * del filtro sale de que el `nombre` esté escrito igual en ocho subgrupos: no hay ningún campo
 * «aerolínea», ni maestro, ni normalización. Es una coincidencia de texto que hoy sale bien
 * porque alguien la escribió consistente, y el día que se teclee «Copa» en vez de «Copa
 * Airlines» aparecerá un botón nuevo sin que nada avise.
 *
 * Escribirlo no arregla el modelo —eso sería un maestro de aerolíneas o normalizar al guardar—,
 * pero convierte una trampa invisible en una regla que se puede seguir.
 */
/**
 * Qué ayuda está abierta. `null` = ninguna.
 *
 * ⚠️ **Con `title` no bastaba, y es un fallo de bulto en esta app.** El atributo `title` sólo se
 * ve al pasar el ratón por encima, y esto se usa **desde el móvil**: no hay hover, así que las
 * «i» no hacían absolutamente nada. Se tocan y despliegan el texto debajo del campo.
 *
 * El `title` se queda para quien esté en escritorio: ahí sigue siendo lo más cómodo.
 */
const ayudaAbierta = ref<string | null>(null);

const alternarAyuda = (campo: string) => {
    ayudaAbierta.value = ayudaAbierta.value === campo ? null : campo;
};

const ayudaSubeje = computed(() => ejemploDe.value.ayudaSubeje);
const ayudaClave = computed(() => ejemploDe.value.ayudaClave);
const ayudaNombre = computed(() => ejemploDe.value.ayudaNombre);

const agregarGrupo = async () => {
  if (!file.value || !nuevoGrupo.value.clave.trim()) return;

  creandoGrupo.value = true;
  const ok = await fileStore.crearGrupo(
    extractIdStr(file.value.id || file.value['@id']) || '',
    {
      tipo: nuevoGrupo.value.tipo,
      subeje: nuevoGrupo.value.subeje || '',
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
    // ⚠️ `hoyNaive()`, no `toISOString()`: éste da el día en UTC y desde las 19:00 de Lima ya es
    // mañana, así que un pasaporte que vence HOY se marcaba vencido esa misma tarde. Ver
    // `dominio/fecha/naive.ts`.
    const hoy = hoyNaive();

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

/**
 * ¿Lo que se está subiendo es un escaneo de identidad?
 *
 * ⚠️ **Espejo de `ArchivoTipoEnum::esEscaneoDeIdentidad()`**, vía la lista que ya existía aquí.
 * Si allí entra un tipo nuevo, entra también en `ARCHIVO_TIPOS_DEL_PASAJERO`.
 *
 * Sirve para dejar de exigir el nombre del documento: en un pasaporte no distingue nada —el tipo
 * ya lo dice— y era un campo obligatorio en el paso más repetitivo del expediente. Lo rellena el
 * servidor con la etiqueta del enum ({@see CotizacionFilearchivoMultipartProcessor}); aquí sólo
 * se deja de pedir y se enseña cómo va a quedar.
 */
const esDocDeIdentidad = computed(
  () => ARCHIVO_TIPOS_DEL_PASAJERO.includes(docForm.value.tipoArchivo as ArchivoTipoValue),
);

/**
 * Deja en la lista la versión recién guardada de un pasajero, sin volver a pedir el expediente.
 *
 * El GET del expediente pesa **717 KB de media y hasta 4,3 MB**, con picos de 8,44 s de servidor
 * (medido el 10/09/2026). Con «Guardar y siguiente» eso ocurría **una vez por persona del
 * manifiesto**: en un grupo de treinta, treinta viajes para cambiar un apellido.
 *
 * Se empareja por identidad y no por índice: la lista se reordena por grupo y por coordinador,
 * así que la posición no es estable entre pintados.
 *
 * ⚠️ **Por el UUID y no por `@id` a secas.** `abrirEdicionPax()` ya contempla que `@id` pueda
 * faltar y lo reconstruye desde `id`; si aquí sólo se mirara `@id`, un pasajero sin él no casaría,
 * `findIndex` daría -1 y la lista se quedaría con los datos VIEJOS sin dar ningún error — que es
 * peor que recargar de más. Devuelve `false` cuando no encuentra a quién sustituir, y quien llama
 * recarga.
 */
const sustituirPasajero = (guardado: ApiCotizacionFilepasajero): boolean => {
  const lista = file.value?.filepasajeros;
  if (!lista) return false;

  const clave = extractIdStr(guardado['@id'] || guardado.id);
  if (!clave) return false;

  const i = lista.findIndex(p => extractIdStr(p['@id'] || p.id) === clave);
  if (i === -1) return false;

  lista.splice(i, 1, guardado);

  return true;
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

  // Al EDITAR, lo guardado se sustituye en la lista y no se recarga el expediente.
  //
  // ⚠️ **Sólo al editar, y la diferencia importa.** Editar cambia los datos de alguien que ya
  // está en la lista; crear cambia LA LISTA —aparece una persona, y de ella dependen el orden,
  // los contadores y a quién salta «Guardar y siguiente»—. Por eso la creación sigue recargando.
  //
  // Es seguro porque el PATCH devuelve el pasajero con `file:item:read`, la misma forma con la
  // que viaja dentro del expediente: no hay ningún campo suyo que esté en `file:read` y no en
  // `file:item:read` (comprobado el 11/09/2026). Si algún día lo hubiera, la ficha se quedaría
  // con ese campo en blanco hasta la siguiente recarga — y no daría ningún error.
  let guardado: ApiCotizacionFilepasajero | null = null;

  if (paxEditandoIri.value) {
    // Modo edición
    guardado = await fileStore.updatePassenger(paxEditandoIri.value, payloadDePax());
    success = guardado !== null;
  } else {
    // Modo creación (igual que antes)
    const payload = {
      ...payloadDePax(),
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
      capas.cerrar('pax');
    }

    // Si no se pudo sustituir —no se encontró a quién— se recarga: mejor un viaje de más que una
    // ficha enseñando lo de antes.
    if (guardado === null || !sustituirPasajero(guardado)) {
      await cargarFile();
    }

    if (seguir) {
      paxEditandoIri.value = iriGuardado;
      await nextTick();
      if (haySiguiente.value) saltarA(1);
      else capas.cerrar('pax');
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
  docForm.value = { nombre: '', tipoArchivo: '', sobreescribirTraduccion: false, fileObject: null, pasajeroId: '', grupoId: '', vueloId: '' };
  showDocModal.value = true;
  capas.abrir('doc', () => { showDocModal.value = false; docEditandoIri.value = null; });
};

const abrirEdicionDoc = (doc: ApiCotizacionFilearchivo) => {
  docEditandoIri.value = doc['@id'] || `/platform/sales/cotizacion_filearchivos/${extractIdStr(doc.id)}`;
  docForm.value = {
    nombre: getDocNombre(doc, 'es'),   // siempre editamos la fuente en español
    tipoArchivo: doc.tipoArchivo || '',
    sobreescribirTraduccion: false,
    fileObject: null,
    // ⚠️ **Aquí SÍ se reasigna el dueño, y antes no se podía.** Decía «para eso se borra y se
    // vuelve a subir, que deja rastro», y el rastro salía carísimo: en una familia con el mismo
    // apellido el reparto se tuerce a menudo, y borrar el archivo obliga a pedirlo otra vez a
    // alguien que ya lo mandó. Lo que se corrige es a QUIÉN apunta, no el fichero.
    pasajeroId: idDeRelacion(doc.pasajero),
    grupoId: idDeRelacion(doc.grupo),
    vueloId: idDeRelacion(doc.vuelo),
  };
  showDocModal.value = true;
  capas.abrir('doc', () => { showDocModal.value = false; docEditandoIri.value = null; });
};

/**
 * Cambiar de persona SUELTA el vuelo del anterior.
 *
 * ⚠️ Es el caso de uso que motiva todo esto: se reasigna la tarjeta de Ana a Beatriz y el vuelo de
 * Ana seguía preseleccionado. Beatriz puede no volarlo, y nada lo comprobaba —ni aquí ni en la
 * API; sólo la carga por ZIP—. Se vacía y se vuelve a elegir de entre los suyos.
 *
 * ⚠️ **Cuelga del evento `change`, no de un `watch` sobre el valor.** Un `watch` también dispara
 * cuando `abrirEdicionDoc()` PRECARGA el formulario, así que borraría el vuelo bueno justo al
 * abrir la edición de un boarding pass — un fallo mudo, y del que más duele: el dato se pierde al
 * mirar. `change` sólo lo emite `SearchableSelect` cuando el operador elige o vacía.
 */
const cambiarPasajeroDelDoc = () => { docForm.value.vueloId = ''; };

const handleFileUpload = (e: Event) => {
  const target = e.target as HTMLInputElement;
  if (target.files && target.files[0]) docForm.value.fileObject = target.files[0];
};

/**
 * Los tres alcances tal y como los entiende {@see CotizacionFilearchivo}, resueltos en un solo
 * sitio porque son **excluyentes** y el formulario esconde el que no toca:
 *
 * - con pasajero, el subgrupo sobra —el archivo es de la persona— y se manda a null;
 * - el vuelo sólo significa algo colgando de un pasajero, así que se va con él.
 *
 * ⚠️ Sin esto, elegir persona sobre un archivo que era de un subgrupo dejaba el `grupo` viejo
 * puesto: la fila decía las dos cosas y no era de ninguna.
 */
const alcanceDelDoc = () => {
  const { pasajeroId, grupoId, vueloId } = docForm.value;
  return {
    pasajero: pasajeroId ? `/platform/sales/cotizacion_filepasajeros/${pasajeroId}` : null,
    grupo: !pasajeroId && grupoId ? `/platform/sales/cotizacion_file_grupos/${grupoId}` : null,
    // ⚠️ `vuelo`, no `grupo`: un boarding pass es de un VUELO. Ver la tabla de alcances.
    //
    // ⚠️ Y la condición es EXACTAMENTE la que decide si el selector se ve (`ofreceVuelo`). Cuando
    // no coincidían, un boarding pass al que se le cambiaba el tipo guardaba su vuelo con el
    // selector escondido: quedaba un pasaporte con vuelo, ilegible en la fila e imposible de
    // limpiar sin volver a ponerle `boleto`. Nada que se manda puede estar fuera de la pantalla.
    vuelo: ofreceVuelo.value && vueloId ? `/platform/sales/cotizacion_vuelos/${vueloId}` : null,
  };
};

const guardarDocumento = async () => {
  let success: boolean;

  if (docEditandoIri.value) {
    // Modo edición: metadata y dueño, sin archivo (PATCH JSON → array i18n)
    isSubmittingDoc.value = true;
    success = await fileStore.updateDocument(docEditandoIri.value, {
      // ⚠️ Lista VACÍA y no `null`. `CotizacionFilearchivo::setNombre(array)` no admite null, así
      // que un `nombre: null` lo rechaza el serializador con un 400 **antes** de llegar al
      // procesador. Era inalcanzable mientras el campo fue obligatorio; desde que un escaneo de
      // identidad puede guardarse sin nombre, vaciarlo es un camino normal — y con `[]` el
      // procesador hace su trabajo y le pone el nombre por convención.
      nombre: docForm.value.nombre
          ? [{ content: docForm.value.nombre.trim(), language: 'es' }]
          : [],
      tipoArchivo: docForm.value.tipoArchivo,
      sobreescribirTraduccion: docForm.value.sobreescribirTraduccion,
      ...alcanceDelDoc(),
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

    // ⚠️ Sólo si tienen valor: mandar la clave vacía haría que API Platform intentara resolver un
    // IRI en blanco. Vacío significa «del expediente entero», que es un alcance legítimo — por eso
    // aquí se OMITE lo que en el PATCH se manda como null.
    for (const [campo, iri] of Object.entries(alcanceDelDoc())) {
      if (iri) formData.append(campo, iri);
    }
    success = await fileStore.uploadDocument(formData);
  }

  if (success) {
    capas.cerrar('doc');
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

        <a v-if="file?.localizador" :href="linkPublicoPropuesta()" target="_blank" rel="noopener"
           class="px-4 py-2.5 bg-white border border-slate-200 text-slate-700 font-bold text-sm rounded-xl shadow-sm hover:bg-slate-50 transition-all flex items-center gap-2">
          <i class="fas fa-external-link-alt"></i> <span class="hidden md:inline">Vista Cliente</span>
        </a>

        <button @click="eliminarFile"
           class="px-4 py-2.5 bg-red-50 border border-red-200 text-red-600 font-bold text-sm rounded-xl shadow-sm hover:bg-red-100 transition-all flex items-center gap-2">
          <i class="fas fa-trash-alt"></i> <span class="hidden md:inline">Eliminar Expediente</span>
        </button>

        <button @click="nuevaVersion"
           class="px-4 py-2.5 bg-[#376875] hover:bg-[#2d5662] text-white font-bold text-sm rounded-xl shadow-md transition-all flex items-center gap-2 ml-auto">
          <i class="fas fa-rocket"></i> <span class="hidden md:inline">Crear Nueva Propuesta</span>
        </button>
      </div>
    </header>

    <!-- Sólo en la PRIMERA carga: después se refresca sin esconder el expediente. Ver `cargarFile()`. -->
    <main v-if="isLoading" class="flex-1 flex justify-center items-center">
      <i class="fas fa-spinner fa-spin text-4xl text-slate-300"></i>
    </main>

    <!-- La franja de «refrescando». Ocupa alto propio en vez de flotar sobre el contenido: una
         cinta superpuesta tapa la primera fila justo cuando se está mirando qué cambió. -->
    <div v-if="refrescando"
         class="shrink-0 bg-sky-50 border-b border-sky-200 px-4 py-1.5 flex items-center gap-2 text-[11px] font-bold text-sky-700">
      <i class="fas fa-circle-notch fa-spin"></i> Actualizando el expediente…
    </div>

    <!-- ⚠️ `v-if="!isLoading"` y NO `v-else`, aunque el hermano de arriba sea un `v-if`.
         Aquí había un `v-else` y funcionaba… hasta que se metió la franja de «refrescando» EN
         MEDIO: `v-else` se engancha al hermano inmediatamente anterior con `v-if`, que pasó a ser
         la franja. Resultado, justo lo contrario de lo que se quería: en la primera carga se
         pintaban el spinner y el contenido a la vez, y en cada refresco desaparecía el `<main>`
         entero. Ni `vue-tsc` ni `vue/valid-v-else` lo ven — hay un `v-if` delante, así que es
         válido. Con la condición escrita, insertar algo en medio deja de importar. -->
    <main v-if="!isLoading" class="flex-1 overflow-y-auto p-6 md:p-8">
      <!-- ⚠️ **En una columna, la barra lateral NO es una barra: es la primera sección.**
           Conservaba el ancho de columna que tiene en escritorio —340-380 px— así que la Bóveda
           quedaba metida hacia dentro mientras el Manifiesto y los Vuelos iban a sangre, y las dos
           mitades de la misma pantalla no alineaban. `lg:max-w-none` sólo actúa cuando de verdad
           hay dos columnas. -->
      <div class="max-w-6xl mx-auto w-full grid grid-cols-1 lg:grid-cols-[minmax(340px,380px)_1fr] gap-8 items-start pb-20">

        <aside class="w-full space-y-6 lg:sticky lg:top-0 min-w-0">

          <div class="panel-giratorio">
          <div class="bg-white rounded-3xl p-6 border border-slate-200 shadow-sm cara" :class="{ 'de-canto': girandoFile }">
            <div class="flex items-center justify-between mb-5 border-b border-slate-200 pb-3 gap-2">
              <h2 class="text-sm font-black text-slate-800 uppercase tracking-widest min-w-0">
                <i class="fas fa-folder-open mr-2 text-[#E07845]"></i> Datos del Expediente
              </h2>
              <span v-if="modoVistaFile" class="shrink-0 flex items-center gap-1.5">
                <!-- vCard: va en la cabecera de ESTA tarjeta y no en la barra de arriba porque
                     lo que descarga es justo lo que hay debajo —contacto, titular, país— y
                     porque la barra ya lleva las acciones del expediente entero (link público,
                     eliminar, nueva versión). Sin teléfono no se ofrece: un contacto sin
                     número no sirve para nada en una agenda. -->
                <a v-if="vcardUrl && file?.telefono" :href="vcardUrl" target="_blank"
                   title="Descargar contacto (vCard) con los datos del expediente"
                   class="flex items-center gap-1.5 bg-indigo-100 hover:bg-indigo-200 text-indigo-700 px-3 py-1.5 rounded-lg text-[10px] font-black uppercase tracking-widest transition-colors">
                  <i class="fas fa-address-card text-[9px]"></i> vCard
                </a>
                <button type="button" @click="girarPanelFile(false)"
                        class="flex items-center gap-1.5 bg-slate-100 hover:bg-slate-200 text-slate-600 px-3 py-1.5 rounded-lg text-[10px] font-black uppercase tracking-widest transition-colors">
                  <i class="fas fa-pencil-alt text-[9px]"></i> Editar
                </button>
              </span>
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

              <!-- ⚠️ **El MISMO componente que en edición, y por el mismo motivo.** La cara de
                   lectura pintaba `f.telefono`, que es la semilla: enseñaba el número viejo
                   mientras los envíos salían al nuevo. Aquí se resuelve por el hilo, que es donde
                   vive la verdad. Sin `v-model`: en lectura no se escribe, y sin los escuchas el
                   componente no ofrece el campo de alta. -->
              <div v-if="file">
                <dt class="text-[10px] font-bold text-slate-400 uppercase mb-1">Contacto</dt>
                <ContactoDeIdentidad context-type="cotizacion_file"
                                     :context-id="String(extractIdStr(file.id ?? file['@id']) ?? '')"
                                     :telefono="file.telefono ?? ''"
                                     :correo="file.email ?? ''" />
              </div>
            </dl>

            <form v-else @submit.prevent="guardarFile" class="space-y-4">
              <div>
                <label class="block text-[10px] font-bold text-slate-500 uppercase mb-1">Nombre Grupo</label>
                <input v-model="file.nombreGrupo" type="text" required class="w-full bg-slate-50 border border-slate-200 rounded-lg px-3 py-2 text-sm font-bold focus:ring-2 focus:ring-[#376875] outline-none">
              </div>
              <!-- ⚠️ El modo NO espera al «Guardar» del formulario: se aplica al elegirlo.
                   De él cuelga si hay padrón, si el precio es por persona y si se exige documento,
                   así que media pantalla cambia con él. Guardarlo junto al nombre del titular haría
                   que el resto del formulario se reconfigurara al pulsar Guardar, que es justo
                   cuando nadie lo está mirando. -->
              <div>
                <label class="block text-[10px] font-bold text-slate-500 uppercase mb-1">Modo del expediente</label>
                <select :value="file.modo || 'estandar'" @change="cambiarModo(($event.target as HTMLSelectElement).value)"
                        class="w-full bg-slate-50 border border-slate-200 rounded-lg px-3 py-2 text-sm font-bold outline-none focus:border-[#E07845]">
                  <option v-for="(cfg, valor) in FILE_MODO_CONFIG" :key="valor" :value="valor">{{ cfg.label }}</option>
                </select>
                <p class="text-[10px] font-bold text-slate-400 mt-1 leading-snug">
                  {{ FILE_MODO_CONFIG[file.modo || 'estandar']?.ayuda }}
                </p>
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
                <!-- ⚠️ `:telefono` + `@update:telefono` en vez de `v-model`, y con `?? ''`.
                     Ahí estaba el callejón sin salida. `ContactoDeIdentidad` decide si ofrece el
                     campo mirando `props.telefono !== undefined`, y la API **omite las claves
                     nulas**: un expediente creado sin teléfono ni correo mandaba los dos
                     `undefined`, el componente concluía «nadie me pasó nada» y pintaba sólo
                     lectura. No había forma de escribir el primero — y el botón «Editar» tampoco
                     servía, porque lleva al editor de identificadores y ése vive en el hilo, que
                     no se puede abrir sin un dato de contacto. Para poner el teléfono hacía falta
                     ya tener teléfono.
                     Con `?? ''` el valor llega SIEMPRE definido y el campo se ofrece. Es la misma
                     forma que ya usaba `OrganizacionFormulario`, donde nunca falló. -->
                <ContactoDeIdentidad
                    context-type="cotizacion_file"
                    :context-id="fileId"
                    :telefono="file.telefono ?? ''"
                    :correo="file.email ?? ''"
                    @update:telefono="file.telefono = $event"
                    @update:correo="file.email = $event"
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
            <!-- ⚠️ **Plegada por defecto.** Un expediente grande son ~1 500 archivos y esto vive en
                 la barra lateral, encima del resto: abierta empuja hacia abajo todo lo que se mira
                 a diario. El contador va en la cabecera para que plegada no se lea como vacía. -->
            <!-- `border-slate-200` explícito: `border-b` a secas usa el gris por defecto de Tailwind,
                 que aquí sale casi negro y compite con el título en vez de separarlo. -->
            <div class="flex items-center justify-between border-b border-slate-200 pb-3"
                 :class="bovedaAbierta ? 'mb-4' : ''">
              <button type="button" @click="bovedaAbierta = !bovedaAbierta"
                      class="flex items-center gap-2 min-w-0 flex-1 text-left group">
                <i class="fas fa-folder-open text-sky-500"></i>
                <h2 class="text-xs font-black text-slate-800 uppercase tracking-widest">Bóveda Digital</h2>
                <span class="text-[10px] font-bold text-slate-400">{{ (file?.filearchivos ?? []).length }}</span>
                <i class="fas text-[10px] text-slate-300 group-hover:text-slate-500 transition-colors"
                   :class="bovedaAbierta ? 'fa-chevron-up' : 'fa-chevron-down'"></i>
              </button>
              <div v-if="bovedaAbierta" class="flex items-center gap-1 shrink-0">
                <!-- Los documentos que no son de nadie. Va aquí y no en el manifiesto porque el
                     problema es del archivo —«¿de quién es esto?»— y no de la persona. -->
                <button @click="abrirPanelSueltos" class="bg-amber-100 text-amber-700 px-2 py-1 rounded text-[10px] font-bold hover:bg-amber-200">
                  <i class="fas fa-user-slash mr-0.5"></i> Sin dueño
                </button>
                <button @click="abrirDocModal" class="bg-sky-100 text-sky-700 px-2 py-1 rounded text-[10px] font-bold hover:bg-sky-200">+ Subir Doc</button>
              </div>
            </div>

            <template v-if="bovedaAbierta">
            <!-- ⚠️ **El buscador va lo primero, antes que el ZIP.** Un expediente de grupo son
                 ~1 500 archivos: subir uno es ocasional, encontrar uno es lo de cada día. Busca
                 sobre lo mismo que se ve en la fila, así que lo que se lee es lo que se teclea. -->
            <div class="relative mb-3">
              <i class="fas fa-magnifying-glass absolute left-3 top-1/2 -translate-y-1/2 text-slate-300 text-[10px]"></i>
              <input v-model="bovedaBusqueda" type="search"
                     placeholder="Buscar por persona, vuelo, tipo o nombre…"
                     class="w-full border border-slate-200 rounded-xl pl-8 pr-3 py-2 text-[11px] font-bold text-slate-700 placeholder:font-medium placeholder:text-slate-300 outline-none focus:border-sky-400">
            </div>

            <!-- ═══ CARGA MASIVA POR ZIP ═══
                 ~1 060 boarding passes en un grupo grande. El ZIP se nombra `DNI-VUELO` y el
                 servidor reparte — y valida que esa persona vuele ese vuelo, así que un
                 renombrado torcido sale marcado en vez de guardarse mal. -->
            <div class="mb-3 rounded-2xl border border-violet-200 bg-violet-50/60 p-3">
              <div class="flex items-center gap-2">
                <i class="fas fa-file-zipper text-violet-500"></i>
                <div class="min-w-0 flex-1">
                  <p class="text-[11px] font-black text-violet-900">Carga masiva de boarding passes</p>
                  <p class="text-[9px] font-bold text-violet-500 uppercase tracking-wider">
                    ZIP con ficheros «DNI-VUELO» · ej. 12345678-DM6771.pdf
                  </p>
                </div>
                <label class="shrink-0 px-3 py-1.5 rounded-lg bg-violet-600 hover:bg-violet-700 text-white text-[10px] font-black uppercase tracking-wider cursor-pointer transition-colors">
                  <i v-if="zipCargando" class="fas fa-spinner fa-spin mr-1"></i>
                  {{ zipCargando ? 'Leyendo…' : 'Subir ZIP' }}
                  <input type="file" accept=".zip,application/zip" class="hidden" @change="elegirZip" />
                </label>
              </div>

              <!-- El reparto, ANTES de guardar. Lo que casa arriba en verde y lo que no, abajo con
                   su motivo: eso es lo que se corrige renombrando y volviendo a subir. -->
              <div v-if="zipPlan" class="mt-3 pt-3 border-t border-violet-200">
                <p class="text-[11px] font-black text-violet-900 mb-2">
                  {{ zipCasan.length }} se asignan · {{ zipFallan.length }} quedan fuera
                  <span v-if="zipReemplazan" class="text-violet-600 font-bold">
                    · {{ zipReemplazan }} sustituyen a uno que ya estaba
                  </span>
                </p>

                <div class="max-h-48 overflow-y-auto space-y-1 mb-3">
                  <div v-for="fila in zipFallan" :key="fila.fichero"
                       class="flex items-start gap-2 text-[10px] bg-amber-50 border border-amber-200 rounded-lg px-2 py-1.5">
                    <i class="fas fa-triangle-exclamation text-amber-500 mt-0.5"></i>
                    <div class="min-w-0">
                      <p class="font-bold text-amber-900 truncate">{{ fila.fichero }}</p>
                      <p class="text-amber-700">{{ fila.problema }}</p>
                    </div>
                  </div>
                  <div v-for="fila in zipCasan" :key="fila.fichero"
                       class="flex items-start gap-2 text-[10px] bg-white border border-emerald-200 rounded-lg px-2 py-1.5">
                    <i class="fas fa-check text-emerald-500 mt-0.5"></i>
                    <div class="min-w-0">
                      <p class="font-bold text-slate-800 truncate">{{ fila.pasajero }}</p>
                      <p class="text-slate-500 truncate">{{ fila.vuelo }}</p>
                      <!-- Que se vea ANTES de guardar: el anterior se borra, y si el bueno era
                           aquél esto es la única oportunidad de pararlo. -->
                      <p v-if="fila.reemplaza" class="text-violet-600 font-bold">
                        <i class="fas fa-rotate mr-0.5"></i> sustituye al que ya tenía
                      </p>
                    </div>
                  </div>
                </div>

                <div class="flex gap-2">
                  <button type="button" @click="descartarZip"
                          class="px-3 py-1.5 text-[10px] font-black uppercase tracking-wider text-slate-500 border border-slate-200 rounded-lg hover:bg-white transition-colors">
                    Descartar
                  </button>
                  <button type="button" @click="aplicarZip" :disabled="zipAplicando || !zipCasan.length"
                          class="flex-1 px-3 py-1.5 text-[10px] font-black uppercase tracking-wider bg-emerald-600 hover:bg-emerald-700 disabled:opacity-40 text-white rounded-lg transition-colors">
                    <i v-if="zipAplicando" class="fas fa-spinner fa-spin mr-1"></i>
                    Guardar {{ zipCasan.length }}
                  </button>
                </div>
              </div>
            </div>

            <div v-if="!file.filearchivos?.length" class="bg-sky-50 border-2 border-dashed border-sky-200 rounded-2xl p-6 text-center text-sky-400">
              <i class="fas fa-cloud-upload-alt text-2xl mb-2 opacity-60"></i>
              <p class="text-[10px] font-bold uppercase tracking-widest">Bóveda vacía</p>
            </div>

            <!-- Bóveda con archivos pero ninguno casa: se dice cuántos hay detrás, para que no se
                 lea como vacía y alguien vuelva a subir lo que ya está. -->
            <div v-else-if="!bovedaDocs.length" class="bg-slate-50 border border-slate-200 rounded-2xl p-4 text-center">
              <p class="text-[10px] font-bold uppercase tracking-widest text-slate-400">Sin coincidencias</p>
              <button type="button" @click="bovedaBusqueda = ''"
                      class="mt-1 text-[10px] font-black text-sky-600 hover:text-sky-700">
                Ver los {{ (file?.filearchivos ?? []).length }} archivos
              </button>
            </div>

            <div v-else class="space-y-2">
              <p v-if="bovedaBusqueda.trim()" class="text-[9px] font-bold uppercase tracking-wider text-slate-400">
                {{ bovedaDocs.length }} de {{ (file?.filearchivos ?? []).length }}
              </p>
              <div v-for="doc in bovedaDocs" :key="doc.id" class="flex items-center gap-2 p-2 bg-slate-50 rounded-xl border border-slate-200 group relative">
                <a :href="doc.imageUrl || undefined" target="_blank" class="flex-1 flex items-center gap-3 min-w-0">
                  <!-- ⚠️ El icono sale del ARCHIVO, no está escrito a fuego. Estuvo puesto a
                       `fa-file-pdf` para todo, así que un vídeo o una foto de pasaporte se
                       anunciaban como PDF: en una bóveda de ~1 500 archivos, eso obliga a
                       abrirlos para saber qué son. -->
                  <div class="w-8 h-8 rounded flex items-center justify-center text-sm shrink-0"
                       :class="mediaDe(doc.tipoMedio).clase">
                    <i :class="mediaDe(doc.tipoMedio).icono"></i>
                  </div>
                  <div class="min-w-0">
                    <p class="text-[11px] font-black text-slate-800 truncate">{{ getDocNombre(doc) || getArchivoLabel(doc.tipoArchivo) }}</p>
                    <p class="text-[9px] font-bold text-slate-400 uppercase truncate">{{ getArchivoLabel(doc.tipoArchivo) }}</p>
                    <!-- ⚠️ De quién es, EN SU PROPIA LÍNEA. Compartiendo renglón con el tipo se
                         cortaba justo donde el nombre empieza a distinguir —«DNI · Edgar Joaq…»—,
                         y en una familia con apellidos repetidos eso es exactamente lo que hay
                         que leer para ver si está bien asignado. -->
                    <p v-if="duenoDelArchivo(doc)" class="text-[10px] font-bold text-slate-600 truncate">
                      <i class="fas fa-user text-[8px] text-slate-300 mr-1"></i>{{ duenoDelArchivo(doc) }}
                    </p>
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
            </template>
          </div>
        </aside>

        <section class="space-y-8 min-w-0">

          <div>
            <!-- ⚠️ Las cuatro secciones de abajo van PLEGADAS y con su resumen en el rótulo.
                 Un expediente de grupo son 131 personas, 16 vuelos y 109 subgrupos: desplegado todo,
                 llegar a lo de abajo son seis pantallas de scroll. Quien abre un expediente viene a
                 UNA cosa, y el rótulo ya le dice si está en la que busca. -->
            <div class="mb-8 bg-white rounded-3xl p-6 border border-slate-200 shadow-sm">
              <button type="button" @click="manifiestoAbierto = !manifiestoAbierto"
                      class="w-full flex items-center justify-between gap-3 border-b border-slate-200 pb-3 mb-4 text-left group">
                <h2 class="text-sm font-black text-slate-800 uppercase tracking-widest min-w-0">
                  <i class="fas fa-users mr-2 text-teal-500"></i> Manifiesto
                  <span class="text-slate-300 font-bold normal-case tracking-normal ml-1">{{ resumenManifiesto }}</span>
                </h2>
                <i class="fas fa-chevron-down text-slate-400 text-xs transition-transform shrink-0 group-hover:text-teal-500"
                   :class="manifiestoAbierto ? 'rotate-180' : ''"></i>
              </button>

              <!-- ⚠️ Fuera del botón que pliega: dentro, pulsarlo plegaría la sección justo
                   cuando llegan los resultados que se quieren ver. -->
              <div v-if="manifiestoAbierto" class="flex items-center gap-2 -mt-2 mb-4">
                <button type="button" @click="validarManifiesto" :disabled="validando"
                        class="inline-flex items-center gap-2 px-3 py-1.5 rounded-lg bg-teal-600 hover:bg-teal-700 disabled:opacity-50 text-white text-[10px] font-black uppercase tracking-wider transition-colors">
                  <i class="fas" :class="validando ? 'fa-spinner fa-spin' : 'fa-shield-halved'"></i>
                  {{ validando ? 'Leyendo documentos…' : 'Validar contra los escaneos' }}
                </button>

                <span v-if="observadas" class="inline-flex items-center gap-1 text-[10px] font-black text-amber-700">
                  <i class="fas fa-triangle-exclamation"></i> {{ observadas }} por revisar
                </span>

                <!-- Se dice lo que NO hace, porque un botón llamado «validar» invita a pensar que
                     arregla. Aquí lo que se corrige lo corrige una persona. -->
                <span class="text-[9px] text-slate-400">
                  escribe el veredicto, no corrige el manifiesto
                </span>
              </div>
              <div v-if="manifiestoAbierto">
              <div class="flex flex-wrap items-center justify-end gap-2 mb-4">
                <!-- Al lado de «Añadir Pax» y no entre los filtros: se baja para reclamar
                     documentos, que es una tarea del manifiesto entero, no el remate de una
                     búsqueda. Con filtros puestos se lleva sólo a los que se ven, y el rótulo lo
                     dice para que no haya que adivinarlo.

                     ⚠️ El icono es una FLECHA DE DESCARGA, no `fa-file-excel`: ése dibuja una X
                     sobre el papel y, con la papelera roja del expediente a dos dedos, se leía
                     como «borrar documentos». Un botón que parece destructivo no se pulsa. -->
                <button v-if="file.filepasajeros?.length" type="button" @click="descargarDocumentos"
                        :disabled="descargandoPlantilla"
                        class="border border-teal-200 bg-teal-50 text-teal-700 px-4 py-2 rounded-lg text-xs font-bold hover:bg-teal-100 disabled:opacity-40">
                  <!-- ⚠️ El nombre dice QUÉ trae, no de dónde sale. «Documentos cargados» se leía
                       como «los documentos», y lo que baja es una HOJA de control: quién ha subido
                       qué y a quién le falta. Junto a un botón que sí baja los documentos, el
                       nombre viejo era la confusión entera. -->
                  <i class="fas mr-1.5" :class="descargandoPlantilla ? 'fa-spinner fa-spin' : 'fa-file-arrow-down'"></i>
                  Hoja de control<span v-if="hayFiltros && pasajerosFiltrados.length"> ({{ pasajerosFiltrados.length }} pax)</span>
                </button>
                <!-- ⚠️ **Ya no dice «Enviar al hotel».** El ZIP se le manda a quien lo pida —un
                     hotel, la discoteca del Coco Bongo, una aerolínea— y el botón no debe decidir
                     a quién: el nombre lo estrechaba a un solo destinatario y hacía dudar de si
                     servía para los demás. -->
                <div v-if="file.filepasajeros?.length" class="relative">
                  <button type="button" @click="panelEscaneos = !panelEscaneos"
                          :disabled="descargandoEscaneos"
                          class="border border-teal-200 bg-teal-50 text-teal-700 px-4 py-2 rounded-lg text-xs font-bold hover:bg-teal-100 disabled:opacity-40">
                    <i class="fas mr-1.5" :class="descargandoEscaneos ? 'fa-spinner fa-spin' : 'fa-file-zipper'"></i>
                    <!-- El número del botón es de FICHEROS, no de personas: es lo que va a
                         bajar. Decía las personas del filtro y prometía de más. -->
                    Descargar escaneos<span v-if="totalEscaneosElegidos"> ({{ totalEscaneosElegidos }} ficheros)</span>
                    <i class="fas fa-chevron-down ml-1.5 text-[9px] opacity-60"></i>
                  </button>

                  <div v-if="panelEscaneos"
                       class="absolute right-0 z-20 mt-1 w-64 bg-white border border-slate-200 rounded-xl shadow-lg p-3 space-y-2">
                    <p class="text-[9px] font-black text-slate-400 uppercase tracking-widest">Qué documentos incluir</p>
                    <label v-for="t in TIPOS_ESCANEO" :key="t.valor"
                           class="flex items-center gap-2 text-[11px] font-bold text-slate-600 cursor-pointer">
                      <input type="checkbox" :checked="tiposEscaneo.includes(t.valor)"
                             @change="alternarTipoEscaneo(t.valor)"
                             class="rounded border-slate-300 text-teal-600 focus:ring-teal-500">
                      <span class="flex-1">{{ t.etiqueta }}</span>
                      <!-- El número REAL de ficheros de ese tipo, no de personas. Sin esto se
                           marcaba a ciegas y la cuenta sólo se veía al abrir el ZIP. -->
                      <span class="tabular-nums" :class="escaneosPorTipo[t.valor] ? 'text-slate-500' : 'text-slate-300'">
                        {{ escaneosPorTipo[t.valor] ?? 0 }}
                      </span>
                    </label>
                    <p class="text-[9px] text-slate-400 leading-tight pt-1 border-t border-slate-100">
                      Van con el nombre y el número de cada persona, más una hoja con los datos.
                      {{ hayFiltros ? 'Respeta los filtros de abajo.' : 'Sin filtros: el expediente entero.' }}
                    </p>
                    <button type="button" @click="descargarEscaneos" :disabled="!totalEscaneosElegidos"
                            class="w-full bg-teal-600 text-white py-2 rounded-lg text-[11px] font-bold hover:bg-teal-700 disabled:opacity-40">
                      Descargar {{ totalEscaneosElegidos }} documento{{ totalEscaneosElegidos === 1 ? '' : 's' }}
                    </button>
                  </div>
                </div>
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

                    <button type="button" @click="filtrosAbiertos = !filtrosAbiertos"
                            class="flex items-center gap-1.5 border rounded-lg px-3 py-2 text-[10px] font-black uppercase tracking-widest transition-colors"
                            :class="hayFiltros
                              ? 'bg-indigo-50 border-indigo-200 text-indigo-600'
                              : 'bg-white border-slate-200 text-slate-500 hover:border-slate-300'">
                      <i class="fas fa-sliders"></i> Filtros
                      <i class="fas fa-chevron-down text-[9px] transition-transform" :class="filtrosAbiertos ? 'rotate-180' : ''"></i>
                    </button>
                  </div>

                  <!-- ⚠️ El buscador y el contador se quedan SIEMPRE fuera del plegado. Son lo que se
                       usa cada vez; las facetas son para una pregunta concreta y cinco filas de
                       píldoras empujaban la lista fuera de la pantalla en un móvil. -->
                  <div v-if="filtrosAbiertos" class="mt-3 pt-3 border-t border-slate-200">
                    <div class="flex flex-wrap items-center gap-3">
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
                      <!-- «sin_rol» es la clave interna de quien no tiene rol —lo normal en un
                           expediente que no es de grupo—: se rotula, no se enseña cruda. -->
                      {{ PASAJERO_TIPO_CONFIG[String(rol)]?.label ?? 'Sin rol' }} <span class="opacity-60">{{ n }}</span>
                    </button>
                  </div>

                  <!-- Los tres estados NO son grados del mismo: «sin comprobar» no es «casi vigente»,
                       es que no sabemos, y es justo lo que hay que mirar antes de un viaje. -->
                  <div v-if="Object.keys(conteoPorDocumento).length" class="flex flex-wrap items-center gap-1.5 mt-2">
                    <span class="text-[9px] font-black text-slate-400 uppercase tracking-widest mr-1">
                      <i class="far fa-id-card mr-0.5"></i>Documentos
                    </span>
                    <button v-for="(cfg, clave) in ETIQUETAS_DOCUMENTO" :key="clave" v-show="conteoPorDocumento[clave]"
                            type="button" @click="alternarDocumento(String(clave))"
                            class="inline-flex items-center gap-1.5 rounded-lg border px-2 py-1 text-[10px] font-black transition-colors"
                            :class="filtroDocumento.includes(String(clave))
                              ? cfg.clase
                              : 'bg-white text-slate-500 border-slate-200 hover:border-slate-300'">
                      <span class="w-1.5 h-1.5 rounded-full" :class="cfg.punto"></span>
                      {{ cfg.label }} <span class="opacity-60">{{ conteoPorDocumento[clave] }}</span>
                    </button>
                  </div>

                  <div v-for="grupo in aerolineasPorEje" :key="grupo.eje" class="flex flex-wrap items-center gap-1.5 mt-2">
                    <span class="text-[9px] font-black text-slate-400 uppercase tracking-widest mr-1">
                      <i class="fas fa-plane-departure mr-0.5"></i>{{ grupo.label }}
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

                  <!-- ⚠️ La negación no se ve hasta que hay una etiqueta puesta, y por eso se
                       dice aquí. Antes había que descubrir por casualidad que la etiqueta se toca:
                       el subgrupo se añade desde el desplegable de arriba y sólo entonces aparece
                       algo que invertir. -->
                  <p v-if="gruposFiltrados.length" class="text-[9px] text-slate-400 mt-2 leading-tight">
                    Toca una etiqueta para invertirla: <span class="font-black text-rose-600">SIN</span>
                    = los que NO lo tienen. La «×» la quita.
                  </p>

                  <div v-if="gruposFiltrados.length" class="flex flex-wrap gap-1.5 mt-2">
                    <!-- ⚠️ La pastilla negada NO es la misma con otro color: lleva «SIN» delante.
                         El color solo no sobrevive a una captura de pantalla reenviada por
                         WhatsApp, y confundir «los del Coco Bongo» con «los que NO van» es
                         mandarle al proveedor la lista contraria. La palabra lo dice aunque la
                         pantalla esté en blanco y negro. -->
                    <span v-for="iri in gruposFiltrados" :key="iri"
                          class="inline-flex items-center gap-1 border rounded-lg pl-2.5 pr-1 py-1 text-[11px] font-black"
                          :class="gruposNegados.has(iri)
                            ? 'bg-rose-50 text-rose-700 border-rose-200'
                            : 'bg-indigo-50 text-indigo-700 border-indigo-200'">
                      <button type="button" @click="alternarNegado(iri)"
                              :title="gruposNegados.has(iri) ? 'Ahora excluye a los que lo tienen. Toca para volver a incluirlos.' : 'Toca para invertirlo: los que NO lo tienen'"
                              class="hover:opacity-70">
                        <span v-if="gruposNegados.has(iri)" class="mr-0.5">SIN</span>
                        {{ [grupoDeIri(iri)?.clave, grupoDeIri(iri)?.nombre].filter(Boolean).join(' · ') }}
                      </button>
                      <button type="button" @click="quitarFiltro(iri)" class="px-1 hover:text-red-500"><i class="fas fa-times text-[10px]"></i></button>
                    </span>
                  </div>

                  </div>

                  <div class="flex items-center justify-between mt-2 flex-wrap gap-2">
                    <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest">
                      Mostrando {{ pasajerosFiltrados.length }} de {{ pasajerosConsiderados.length }}
                      <span v-if="!incluirNoParticipa && totalNoParticipa" class="text-slate-300 normal-case font-bold">
                        · {{ totalNoParticipa }} no participa{{ totalNoParticipa > 1 ? 'n' : '' }}, fuera de la cuenta
                      </span>
                    </p>
                    <div class="flex items-center gap-3">
                      <!-- ⚠️ **Aquí había un «Exportar estos N» y se retiró: hacía LO MISMO que el
                           botón de arriba.** Su propio comentario decía «dos botones que hacen lo
                           mismo obligan a pensar cuál es cuál», y al añadir el ZIP quedaron tres
                           descargas repartidas por la pantalla —dos idénticas— sin forma de saber
                           cuál era cuál. El de arriba ya respeta los filtros y dice el recuento. -->
                      <button v-if="hayFiltros" type="button" @click="limpiarFiltros"
                              class="text-[10px] font-black uppercase tracking-widest text-indigo-500 hover:text-indigo-700">
                        Limpiar filtros
                      </button>
                    </div>
                  </div>
                </div>

                <p v-if="!pasajerosFiltrados.length" class="text-[11px] text-slate-400 italic border border-dashed border-slate-200 rounded-2xl px-4 py-6 text-center">
                  Nadie cumple estos filtros.
                </p>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <!-- 🔥 **La tarjeta YA NO se abre al tocarla, y es un cambio a peor que salió bien.**
                     Antes abría entera —«en un móvil apuntar a un icono de 28 px con el pulgar es
                     la parte incómoda»— y eso valía mientras dentro sólo hubiera texto. Al meterle
                     botones propios (ver documentos, reprocesar) la tarjeta pasó a tener dos
                     significados en el mismo píxel: tocar un botón disparaba **además** la
                     apertura de la ficha, que recarga el expediente entero. Un `.stop` en cada
                     botón lo taparía, pero el problema no es la propagación: es que una superficie
                     con botones dentro ya no puede ser ella misma un botón.

                     La lupa abre en lectura, la plumita entra directa a editar. -->
                <div v-for="(pax, idx) in pasajerosFiltrados" :key="pax['@id'] ?? pax.id"
                     class="bg-white p-3 rounded-2xl border border-slate-200 shadow-sm relative group hover:border-indigo-300 hover:shadow-md transition-all">
                  <div class="absolute top-3 right-3 flex items-center gap-1">
                    <button @click="abrirEdicionPax(pax)" title="Ver la ficha"
                            class="text-slate-300 hover:text-teal-500 transition-colors bg-slate-50 w-7 h-7 rounded-full flex items-center justify-center">
                      <i class="fas fa-magnifying-glass text-xs"></i>
                    </button>
                    <button @click="abrirEdicionPax(pax, true)" title="Editar"
                            class="text-slate-300 hover:text-indigo-500 transition-colors bg-slate-50 w-7 h-7 rounded-full flex items-center justify-center">
                      <i class="fas fa-pencil-alt text-xs"></i>
                    </button>
                    <button @click="eliminarPasajero(pax['@id'])" title="Eliminar"
                            class="text-slate-300 hover:text-red-500 transition-colors bg-slate-50 w-7 h-7 rounded-full flex items-center justify-center">
                      <i class="fas fa-trash-alt text-xs"></i>
                    </button>
                  </div>
                  <!-- ⚠️ **El nombre en su propia fila, y a dos líneas.** Iba en la misma columna que
                       todo lo demás, con un `pr-24` que apenas cubría los tres botones: un nombre
                       largo se metía DEBAJO de ellos —«Santiago Ariel Gon🔍✏️🗑ia»— y encima el
                       hueco de los botones estrechaba también las etiquetas y los documentos, que
                       no lo necesitan.

                       Ahora el hueco lo reserva sólo esta fila, el nombre parte en dos líneas si
                       hace falta, y **el resto baja debajo del número** a ancho completo. -->
                  <div class="flex items-start gap-2 pr-[6.5rem]">
                    <div class="w-8 h-8 shrink-0 rounded-full bg-indigo-100 text-indigo-600 font-black text-xs flex items-center justify-center border border-indigo-200">{{ idx + 1 }}</div>
                    <h3 class="text-sm font-black text-slate-800 leading-tight min-w-0 pt-1">{{ pax.nombre }} {{ pax.apellido }}</h3>
                  </div>

                  <div class="mt-2">
                    <div>
                      <div class="flex flex-wrap gap-1.5">
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

                      <!-- ⚠️ **El sello va AQUÍ, pegado al número, y no en una lista aparte.** Lo
                           que se valida es lo que alguien tecleó en el manifiesto, así que el
                           veredicto tiene que verse donde se mira el dato. En una pantalla aparte
                           habría que acordarse de ir a mirarla. -->
                      <div v-for="ident in identificacionesConVeredicto(pax)" :key="`v-${ident.id}`"
                           class="mt-1.5 text-[9px]">
                        <span class="inline-flex items-center gap-1 px-1.5 py-0.5 rounded-full font-black uppercase tracking-wider border"
                              :class="SELLO[ident.estadoValidacion!].clase">
                          <i class="fas text-[8px]" :class="SELLO[ident.estadoValidacion!].icono"></i>
                          {{ getDocIdLabel(ident.tipo) }} · {{ SELLO[ident.estadoValidacion!].texto }}
                        </span>

                        <!-- Los DOS valores juntos: en el caso más frecuente —un dedazo en el año,
                             2026 por 2036— verlos uno al lado del otro ES la resolución, sin abrir
                             el escaneo ni cambiar de pantalla. -->
                        <!-- ⚠️ **Cada valor lleva escrito de dónde sale.** Estaban sólo con color
                             —verde el documento, ámbar el manifiesto— y el color no dice cuál es
                             cuál: había que deducirlo, y quien deduce mal corrige el lado
                             equivocado. Un color es un refuerzo, nunca la etiqueta. -->
                        <span v-for="(d, j) in (ident.discrepancias ?? [])" :key="j"
                              class="ml-1 inline-flex flex-wrap items-center gap-1 text-amber-700 align-middle">
                          <span class="font-bold">{{ d.campo }}:</span>
                          <span class="inline-flex items-center gap-1">
                            <span class="text-[8px] font-black uppercase tracking-wider text-emerald-600">doc</span>
                            <span class="font-mono bg-emerald-50 border border-emerald-200 rounded px-1">{{ d.documento }}</span>
                          </span>
                          <span class="inline-flex items-center gap-1">
                            <span class="text-[8px] font-black uppercase tracking-wider text-amber-600">guardado</span>
                            <span class="font-mono bg-amber-50 border border-amber-200 rounded px-1">{{ d.manifiesto }}</span>
                          </span>
                        </span>

                        <span v-for="(n, j) in (ident.notasValidacion ?? [])" :key="`n-${j}`"
                              class="ml-1 text-slate-400 normal-case">{{ n }}</span>
                      </div>

                      <!-- ⚠️ Un aviso por CADA escaneo torcido, con cuál es. Antes salía uno solo,
                           colgado del veredicto del número, y girar el anverso lo hacía
                           desaparecer con el reverso todavía torcido. -->
                      <p v-for="(t, j) in escaneosTorcidos(pax)" :key="`t-${j}`"
                         class="mt-1 text-[9px] font-bold text-amber-600">
                        <i class="fas fa-rotate text-[8px] mr-1"></i>{{ t.etiqueta }}: falta girar {{ t.grados }}°
                      </p>

                      <!-- ⚠️ El puente que faltaba entre «este dato no coincide» y «pues mira el
                           papel». Sin esto había que ir a la bóveda, buscar entre ~1 500 archivos
                           el de esta persona y abrirlo — que es exactamente el trabajo que este
                           módulo venía a quitar.

                           No cuesta nada: los escaneos ya están y su lectura está cacheada. -->
                      <div v-if="tieneEscaneos(pax)" class="mt-1.5 flex items-center gap-1">
                        <button type="button" @click="abrirVisor(pax)"
                                class="inline-flex items-center gap-1 px-2 py-1 rounded-lg border border-slate-200 bg-white text-[9px] font-black uppercase tracking-wider text-slate-500 hover:border-sky-300 hover:text-sky-600 transition-colors">
                          <i class="far fa-images text-[9px]"></i> Ver sus documentos
                        </button>

                        <!-- ⚠️ Reprocesar SOLO a esta persona. Sin esto, corregir un dato obligaba
                             a relanzar la tanda entera del expediente para ver si el aviso se
                             había ido — 135 personas para comprobar una. -->
                        <button type="button" :disabled="revalidando === String(pax.id)"
                                @click="revalidarPax(pax)"
                                class="inline-flex items-center gap-1 px-2 py-1 rounded-lg border border-slate-200 bg-white text-[9px] font-black uppercase tracking-wider text-slate-500 hover:border-teal-300 hover:text-teal-600 transition-colors disabled:opacity-40"
                                title="Volver a cotejar sus documentos con lo que hay guardado ahora">
                          <i class="fas text-[9px]" :class="revalidando === String(pax.id) ? 'fa-spinner fa-spin' : 'fa-rotate'"></i>
                          Reprocesar
                        </button>
                      </div>
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
            </div>

            <!-- El itinerario del viaje. Se consulta, no se edita: se carga abajo. -->
            <div class="mb-8 bg-white rounded-3xl p-6 border border-slate-200 shadow-sm">
              <button type="button" @click="vuelosAbiertos = !vuelosAbiertos"
                      class="w-full flex items-center justify-between gap-3 border-b border-slate-200 pb-3 mb-4 text-left group">
                <h2 class="text-sm font-black text-slate-800 uppercase tracking-widest min-w-0">
                  <i class="fas fa-plane-departure mr-2 text-teal-500"></i> Vuelos
                  <span class="text-slate-300 font-bold normal-case tracking-normal ml-1">{{ resumenVuelos }}</span>
                </h2>
                <i class="fas fa-chevron-down text-slate-400 text-xs transition-transform shrink-0 group-hover:text-teal-500"
                   :class="vuelosAbiertos ? 'rotate-180' : ''"></i>
              </button>
              <div v-if="vuelosAbiertos">
                <!-- ⚠️ **La acción, donde se mira la lista.** Vivía sólo dentro de «Cargar datos»,
                     tres paneles más abajo y plegado: veías los 16 vuelos y no había forma de
                     tocarlos desde aquí, así que parecían de sólo lectura. Es el mismo botón, no
                     otro camino — abre el mismo modal de pegar JSON. -->
                <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
                  <p class="text-[10px] text-slate-400 font-bold">
                    <i class="fas fa-circle-info mr-1"></i>
                    Se cargan pegando el JSON de la aerolínea. El <span class="font-mono">PNR</span>
                    es lo que ata cada vuelo con su subgrupo y con la gente que va en él.
                  </p>
                  <button @click="abrirVuelos"
                          title="Pega el JSON de reservas que manda la aerolínea. Vuelve a pegar el mismo PNR para corregirlo."
                          class="flex items-center gap-2 bg-white border border-slate-200 text-slate-600 px-3 py-1.5 rounded-lg text-[11px] font-bold hover:bg-slate-50 shrink-0">
                    <i class="fas fa-plane-departure"></i>
                    {{ vuelos.length ? 'Cargar o corregir vuelos' : 'Cargar vuelos' }}
                  </button>
                </div>

                <!-- ── Los vuelos del viaje ─────────────────────────────────
                     En orden cronológico, que es como se mira un expediente: «qué pasa el día 23».
                     El PNR es el que agrupa a la gente, así que va delante de los pasajeros. -->
                <!-- ⚠️ **Fichas y no tabla.** La tabla llevaba `whitespace-nowrap` en cada
                     celda: en un móvil se desbordaba y **el destino se salía de la pantalla**, así
                     que un vuelo se leía «CUZ →» y ahí acababa. Una ficha se envuelve. -->
                <div v-if="vuelos.length" class="mb-4 space-y-2">
                  <div v-for="v in vuelos" :key="String(v.id)"
                       class="border border-slate-200 rounded-2xl px-3 py-2.5 hover:border-slate-300 transition-colors">
                    <!-- ── Ficha en lectura ── -->
                    <div v-if="vueloEditando !== v.id">
                      <div class="flex items-center gap-2 flex-wrap">
                        <span class="font-black text-slate-800 text-xs">{{ v.numero }}</span>
                        <span class="text-[11px] font-bold text-slate-500">{{ v.aerolinea }}</span>
                        <span class="text-[11px] font-bold text-slate-400">{{ diaDe(v.salida) }}</span>
                        <button type="button" @click="abrirVuelo(v)"
                                class="ml-auto shrink-0 w-6 h-6 rounded-lg border border-slate-200 text-slate-300 hover:text-indigo-500 hover:border-indigo-200 transition-colors"
                                title="Corregir este vuelo">
                          <i class="fas fa-pencil-alt text-[10px]"></i>
                        </button>
                      </div>

                      <p class="mt-1 font-mono text-[12px] text-slate-700 flex items-baseline gap-1.5 flex-wrap">
                        <span class="font-black">{{ horaDe(v.salida) }}</span>
                        <span class="font-bold">{{ v.origen }}</span>
                        <i class="fas fa-arrow-right text-[9px] text-slate-300"></i>
                        <span class="font-black">{{ horaDe(v.llegada) }}</span>
                        <span class="font-bold">{{ v.destino }}</span>
                        <!-- Un vuelo que llega al día siguiente no se avisa con una bandera: se ve. -->
                        <span v-if="diaDe(v.llegada) !== diaDe(v.salida)"
                              class="text-[10px] text-amber-600 font-bold">+1 día</span>
                      </p>

                      <div v-if="(v.pnrs || []).length" class="mt-1.5 flex flex-wrap gap-1">
                        <span v-for="pnr in (v.pnrs || [])" :key="pnr"
                              class="bg-slate-100 text-slate-600 rounded px-1.5 py-0.5 font-mono text-[10px]">
                          {{ pnr }}
                        </span>
                      </div>
                    </div>

                    <!-- ── Ficha en edición ──
                         🔥 Un vuelo son SIETE campos planos: la entidad nunca fue anidada, sólo
                         el JSON de carga, que va por PNR porque así escribe la aerolínea.
                         ⚠️ Aquí NO se tocan los PNR: quién viaja en este vuelo lo declara la
                         reserva. Esto corrige el HECHO —a qué hora sale—, no a quién le pasa. -->
                    <form v-else @submit.prevent="guardarVuelo(v)" class="space-y-2">
                      <div class="grid grid-cols-2 gap-2">
                        <label class="col-span-2">
                          <span class="text-[9px] font-black text-slate-400 uppercase">Número</span>
                          <input v-model="vueloForm.numero" required
                                 class="w-full border rounded-lg px-2 py-1.5 text-xs font-mono outline-none focus:border-indigo-500" />
                        </label>
                        <label class="col-span-2">
                          <span class="text-[9px] font-black text-slate-400 uppercase">Aerolínea</span>
                          <input v-model="vueloForm.aerolinea"
                                 class="w-full border rounded-lg px-2 py-1.5 text-xs outline-none focus:border-indigo-500" />
                        </label>
                        <label>
                          <span class="text-[9px] font-black text-slate-400 uppercase">Origen</span>
                          <input v-model="vueloForm.origen" maxlength="3" placeholder="LIM"
                                 class="w-full border rounded-lg px-2 py-1.5 text-xs font-mono uppercase outline-none focus:border-indigo-500" />
                        </label>
                        <label>
                          <span class="text-[9px] font-black text-slate-400 uppercase">Destino</span>
                          <input v-model="vueloForm.destino" maxlength="3" placeholder="PUJ"
                                 class="w-full border rounded-lg px-2 py-1.5 text-xs font-mono uppercase outline-none focus:border-indigo-500" />
                        </label>
                        <!-- ⚠️ La SALIDA fija también la fecha del vuelo, que es la mitad de su
                             identidad: no hay un campo «fecha» aparte a propósito, porque dos
                             campos para un mismo hecho acaban discrepando.

                             ⚠️ **`FechaHoraPicker` y NO `<input type="datetime-local">`**, que es
                             lo que había aquí y el propio componente advierte que no se use: el
                             nativo saca AM/PM en un equipo con el SO en inglés, cambia de aspecto
                             en cada navegador y no admite máscara al teclear. Aquí se trabaja
                             siempre en 24 h.

                             ⚠️ `borrable: false`: un vuelo sin hora de salida no existe, y la «x»
                             sólo servía para dejar el formulario en un estado que el backend
                             rechaza. En la v14 del picker eso vive dentro de `input-attrs`, y el
                             componente ya lo resuelve. -->
                        <div>
                          <span class="text-[9px] font-black text-slate-400 uppercase">Salida</span>
                          <FechaHoraPicker v-model="vueloForm.salida" :borrable="false" />
                        </div>
                        <div>
                          <span class="text-[9px] font-black text-slate-400 uppercase">Llegada</span>
                          <!-- ⚠️ El suelo es el DÍA de la salida, **nunca la fecha completa**:
                               `VueDatePicker` toma `min-date` también como límite de HORA y
                               reajusta el valor. Con la salida entera, un DM6770 que despega a
                               las 20:22 no dejaría poner la llegada a las 00:30 del día
                               siguiente — que es exactamente este vuelo. Es la misma trampa que
                               ya documenta `ReservaEditDrawer::soloDia()`. -->
                          <FechaHoraPicker v-model="vueloForm.llegada" :borrable="false"
                                           :min-date="(vueloForm.salida || '').slice(0, 10) || null" />
                        </div>
                      </div>

                      <p class="text-[9px] font-bold text-slate-400">
                        Los PNR se cambian cargando el JSON: aquí sólo se corrige el vuelo.
                      </p>

                      <div class="flex justify-end gap-2">
                        <button type="button" @click="vueloEditando = null"
                                class="px-3 py-1.5 text-[10px] font-bold text-slate-500 border rounded-lg">Cancelar</button>
                        <button type="submit" :disabled="guardandoVuelo"
                                class="px-3 py-1.5 bg-indigo-600 text-white text-[10px] font-bold rounded-lg hover:bg-indigo-700 disabled:opacity-50">
                          <i v-if="guardandoVuelo" class="fas fa-spinner fa-spin mr-1"></i>Guardar
                        </button>
                      </div>
                    </form>
                  </div>
                </div>

                <p v-else class="text-[11px] text-slate-400 italic border border-dashed border-slate-200 rounded-2xl px-4 py-3">
                  Sin vuelos todavía. Se cargan pegando el JSON que manda la aerolínea.
                </p>
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
            <div v-if="file.usaPadron" class="mb-8 bg-white rounded-3xl p-6 border border-slate-200 shadow-sm">
              <button type="button" @click="subgruposAbiertos = !subgruposAbiertos"
                      class="w-full flex items-center justify-between gap-3 border-b border-slate-200 pb-3 mb-4 text-left group">
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
                <!-- ⚠️ **El sufijo, para CUALQUIER eje.** Estaba sólo en el vuelo, con el
                     argumento de que «una habitación no se subdivide: el hotel es uno». En un
                     viaje con hotel en Lima, en Cusco y en Punta Cana eso deja de ser cierto: la
                     `HA13` del Sonesta y la `HA13` del Terra son dos habitaciones distintas y
                     chocaban. Lo mismo con los servicios, que ya son diez.
                     La columna `subeje` siempre fue genérica y entra en la clave única
                     (`uniq_file_grupo_tipo_clave`), así que aquí no había nada que cambiar salvo
                     dejar de esconder el campo.
                     ⚠️ Lo que NO se toca es `GrupoTipoEnum::admiteSubeje()`, que gobierna el
                     .xlsx: allí eje y sufijo viajan en UNA cadena —`#Vuelo Nacional`— y abrirlo
                     haría que `#Habitacion doble` entrara como tramo «doble» en vez de
                     denunciarse. Aquí son dos campos y no hay nada que adivinar. -->
                <div>
                  <label class="flex items-center gap-1 text-[9px] font-black text-slate-400 uppercase tracking-widest mb-1">
                    {{ etiquetaSubeje }}
                    <button type="button" @click="alternarAyuda('subeje')" :title="ayudaSubeje"
                            class="p-1 -m-1 hover:text-slate-500"
                            :class="ayudaAbierta === 'subeje' ? 'text-teal-600' : 'text-slate-300'">
                      <i class="fas fa-circle-info"></i>
                    </button>
                  </label>
                  <input v-model="nuevoGrupo.subeje" type="text" :placeholder="ejemploSubeje" maxlength="60"
                         @keyup.enter="agregarGrupo"
                         class="w-36 border rounded-lg px-3 py-2 text-sm outline-none focus:border-teal-500 placeholder:text-slate-300">
                  <p v-if="ayudaAbierta === 'subeje'" class="text-[9px] text-slate-500 mt-1 leading-tight max-w-56">{{ ayudaSubeje }}</p>
                </div>
                <!-- Aquí el `uppercase` del campo SÍ se queda, y no es lo mismo que en los
                     títulos: la clave se normaliza a mayúsculas al guardar —de ella depende la
                     unicidad, ver `CotizacionFileGrupo::$clave`—, así que el campo enseña
                     exactamente lo que se va a guardar. -->
                <div>
                  <label class="flex items-center gap-1 text-[9px] font-black text-slate-400 uppercase tracking-widest mb-1">
                    Clave
                    <button type="button" @click="alternarAyuda('clave')" :title="ayudaClave"
                            class="p-1 -m-1 hover:text-slate-500"
                            :class="ayudaAbierta === 'clave' ? 'text-teal-600' : 'text-slate-300'">
                      <i class="fas fa-circle-info"></i>
                    </button>
                  </label>
                  <input v-model="nuevoGrupo.clave" type="text" :placeholder="ejemploClave" maxlength="60"
                         @keyup.enter="agregarGrupo"
                         class="w-40 border rounded-lg px-3 py-2 text-sm font-bold uppercase outline-none focus:border-teal-500 placeholder:font-normal placeholder:normal-case placeholder:text-slate-300">
                  <p v-if="ayudaAbierta === 'clave'" class="text-[9px] text-slate-500 mt-1 leading-tight max-w-56">{{ ayudaClave }}</p>
                </div>
                <div class="flex-1 min-w-40">
                  <label class="flex items-center gap-1 text-[9px] font-black text-slate-400 uppercase tracking-widest mb-1">
                    Nombre (opcional)
                    <!-- La «i» del nombre va destacada: es el campo del que depende que el filtro
                         agrupe, y no hay nada en la pantalla que lo delate. -->
                    <button type="button" @click="alternarAyuda('nombre')" :title="ayudaNombre"
                            class="p-1 -m-1 text-teal-500 hover:text-teal-600">
                      <i class="fas fa-circle-info"></i>
                    </button>
                  </label>
                  <input v-model="nuevoGrupo.nombre" type="text" :placeholder="ejemploNombre" maxlength="150"
                         @keyup.enter="agregarGrupo"
                         class="w-full border rounded-lg px-3 py-2 text-sm outline-none focus:border-teal-500 placeholder:text-slate-300">
                  <p class="text-[9px] text-slate-400 mt-1 leading-tight">{{ ayudaNombre }}</p>
                </div>
                <!-- A lo ancho y en varias líneas: el itinerario no es un rótulo, es lo que se
                     consulta para comprobar un horario. Metido en «Nombre» convertiría la píldora
                     del pasajero en un párrafo. -->
                <div class="w-full">
                  <label class="block text-[9px] font-black text-slate-400 uppercase tracking-widest mb-1">
                    Detalle (opcional) — varias líneas, admite *negrita* y listas
                  </label>
                  <textarea v-model="nuevoGrupo.detalle" rows="2"
                            :placeholder="ejemploDetalle"
                            class="w-full border rounded-lg px-3 py-2 text-xs outline-none focus:border-teal-500 placeholder:text-slate-300"></textarea>
                </div>

                <!-- ⚠️ **A lo ancho y al final, no metido entre los campos.**
                     Estaba en la misma fila flexible que Eje/Sufijo/Clave/Nombre, y en escritorio
                     se leía como el último elemento de la fila. En una columna —el móvil— la fila
                     envuelve y el botón acababa **pegado al campo Nombre**, así que parecía que
                     añadía el nombre y no el subgrupo. Con `w-full` al final del bloque, lo que
                     tiene encima son TODOS los campos, que es lo que de verdad guarda.
                     El texto lo dice además: «Añadir subgrupo». -->
                <div class="w-full">
                  <button @click="agregarGrupo" :disabled="creandoGrupo || !nuevoGrupo.clave.trim()"
                          class="w-full bg-teal-600 text-white px-4 py-2.5 rounded-lg text-xs font-bold hover:bg-teal-700 shadow-sm disabled:opacity-40 disabled:cursor-not-allowed">
                    <i class="fas fa-spinner fa-spin mr-1" v-if="creandoGrupo"></i>
                    <span v-else><i class="fas fa-plus mr-1.5"></i>Añadir subgrupo</span>
                  </button>
                </div>
              </div>

              <p v-if="!file.grupos?.length" class="text-[11px] text-slate-400 italic border border-dashed border-slate-200 rounded-2xl px-4 py-3">
                Sin subgrupos. Se crean solos al cargar el padrón, o a mano aquí arriba.
              </p>

              <!-- ⚠️ La papelera de cada subgrupo va detrás de un interruptor, y no es ceremonia.
                   Con 66 habitaciones había 66 botones de borrar al alcance del pulgar, en la
                   misma píldora que se toca para leer el conteo. Un roce y se va un subgrupo con
                   sus pertenencias. En modo lectura la píldora no hace nada. -->
              <div v-if="subgruposAbiertos" class="space-y-3">
                <div class="flex items-center justify-end">
                  <button type="button" @click="modoGestionGrupos = !modoGestionGrupos"
                          class="text-[10px] font-black uppercase tracking-widest transition-colors"
                          :class="modoGestionGrupos ? 'text-red-500 hover:text-red-700' : 'text-slate-400 hover:text-indigo-600'">
                    <i class="fas mr-1" :class="modoGestionGrupos ? 'fa-lock-open' : 'fa-lock'"></i>
                    {{ modoGestionGrupos ? 'Terminar de borrar' : 'Borrar subgrupos' }}
                  </button>
                </div>

                <div v-for="sec in seccionesDeGrupos" :key="sec.clave">
                  <div class="flex items-center justify-between mb-1.5">
                    <p class="text-[9px] font-black text-slate-400 uppercase tracking-widest">
                      <i class="fas" :class="sec.icon"></i>
                      {{ sec.label }} ({{ sec.lista.length }})
                    </p>
                    <button v-if="sec.lista.length > TOPE_PILDORAS" type="button" @click="alternarEje(`lista-${sec.clave}`)"
                            class="text-[9px] font-black uppercase tracking-widest text-indigo-500 hover:text-indigo-700">
                      {{ ejeEstaAbierto(`lista-${sec.clave}`, sec.lista.length) ? 'Plegar' : `Ver las ${sec.lista.length}` }}
                    </button>
                  </div>
                  <div class="flex flex-wrap gap-2">
                    <span v-for="g in (ejeEstaAbierto(`lista-${sec.clave}`, sec.lista.length) ? sec.lista : sec.lista.slice(0, TOPE_PILDORAS))" :key="g.id"
                          class="inline-flex items-center gap-2 bg-white border border-slate-200 rounded-lg pl-3 py-1 shadow-sm"
                          :class="modoGestionGrupos ? 'pr-1 border-red-200' : 'pr-1'">
                      <span class="text-[11px] font-black text-slate-700">{{ g.clave }}</span>
                      <span v-if="g.nombre" class="text-[10px] font-medium text-slate-400">{{ g.nombre }}</span>
                      <!-- El conteo se calcula aquí y no se toma de `totalMiembros`: el del servidor
                           incluye a los «no participa», que conservan grupo y reservas aéreas. -->
                      <span class="text-[10px] font-bold text-slate-400">{{ contarEnGrupo(g) }} pax</span>
                      <!-- ⚠️ El lápiz va SIEMPRE, la papelera sólo en modo gestión. Corregir una
                           errata es lo corriente —un vuelo cargado en el tramo que no era— y
                           antes obligaba a BORRAR el grupo, llevándose las pertenencias de todos
                           los que iban dentro. Borrar sigue detrás del interruptor; editar no. -->
                      <button type="button" @click="editarGrupo(g)" title="Corregir este subgrupo"
                              class="w-5 h-5 rounded text-slate-300 hover:text-indigo-600 hover:bg-indigo-50 transition-colors">
                        <i class="fas fa-pencil-alt text-[9px]"></i>
                      </button>
                      <button v-if="modoGestionGrupos" @click="borrarGrupo(g)"
                              class="w-5 h-5 rounded text-red-300 hover:text-red-600 hover:bg-red-50 transition-colors">
                        <i class="fas fa-times text-[10px]"></i>
                      </button>
                    </span>
                    <span v-if="!ejeEstaAbierto(`lista-${sec.clave}`, sec.lista.length)" class="text-[10px] font-bold text-slate-300 italic py-1.5">
                      +{{ sec.lista.length - TOPE_PILDORAS }} más
                    </span>
                  </div>
                </div>
              </div>
            </div>

            <!-- ⚠️ TODO lo que entra al expediente, junto y AL FINAL.
                 Estaba partido —la hoja arriba, los vuelos en medio— y son la misma tarea con dos
                 orígenes: el colegio manda el Excel, la aerolínea manda el JSON. Va al final porque
                 es el andamio: se usa al montar el expediente, no al consultarlo. -->
            <div class="mb-8 bg-white rounded-3xl p-6 border border-slate-200 shadow-sm">
              <button type="button" @click="cargaAbierta = !cargaAbierta"
                      class="w-full flex items-center justify-between gap-3 border-b border-slate-200 pb-3 mb-4 text-left group">
                <h2 class="text-sm font-black text-slate-800 uppercase tracking-widest min-w-0">
                  <i class="fas fa-file-import mr-2 text-teal-500"></i> Cargar datos
                  <span class="text-slate-300 font-bold normal-case tracking-normal ml-1">{{ resumenCarga }}</span>
                </h2>
                <i class="fas fa-chevron-down text-slate-400 text-xs transition-transform shrink-0 group-hover:text-teal-500"
                   :class="cargaAbierta ? 'rotate-180' : ''"></i>
              </button>
              <div v-if="cargaAbierta">
              <!-- ── PADRÓN: plantilla y carga ────────────────────────────────
                   ⚠️ FUERA del modo grupo: cargar un namelist de dos personas es tan válido como
                   cargar 133, y esconderlo en modo normal obligaba a teclear a mano lo que ya está
                   en una hoja. Lo que sí es exclusivo de un grupo son los SUBGRUPOS, abajo. -->
              <div class="mb-8">
                <div class="flex items-center justify-between mb-4 gap-3 flex-wrap">
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
                    <!-- Los vuelos NO se suben: se pegan. Llegan en un correo de la aerolínea, y
                         pedir que alguien los pase a una hoja para volver a subirlos es trabajo
                         inventado. -->
                    <button @click="abrirVuelos"
                            title="Pega el JSON de reservas que manda la aerolínea"
                            class="flex items-center gap-2 bg-white border border-slate-200 text-slate-600 px-3 py-1.5 rounded-lg text-[11px] font-bold hover:bg-slate-50">
                      <i class="fas fa-plane-departure"></i>
                      Cargar vuelos
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
              </div>
            </div>



          <div>
          </div>
            <h2 class="text-sm font-black text-slate-800 uppercase tracking-widest border-b border-slate-200 pb-3 mb-4"><i class="fas fa-code-branch mr-2 text-[#E07845]"></i> Historial de Propuestas</h2>

            <!-- ⚠️ QUIÉN SE QUEDA FUERA al partir el grupo.
                 Va arriba y sin plegar a propósito: es un aviso sobre gente que NO puede
                 reclamarlo. Si el vuelo se parte en dos y alguien no está en ninguno, abre su
                 viaje y no ve ningún vuelo — y no echa de menos lo que no sabía que existía.
                 Ver CoberturaDeSubgrupos. -->
            <!-- ⚠️ **Plegado, con el recuento en el rótulo.** Desplegado ocupaba media pantalla
                 con cuatro listas de nombres, y **no todo el mundo tiene que estar en todos los
                 ejes**: quien no va en avión no está en ningún vuelo, y eso es correcto. Es
                 información para consultar, no una tarea pendiente, así que se enseña como lo
                 que es y se abre cuando alguien quiere leerla. -->
            <div v-if="file?.subgruposIncompletos?.length"
                 class="mb-5 rounded-2xl border border-amber-200 bg-amber-50 overflow-hidden">
              <button type="button" @click="sinAsignarAbierto = !sinAsignarAbierto"
                      class="w-full flex items-center justify-between gap-2 p-4 text-left">
                <span class="text-[11px] font-black uppercase tracking-widest text-amber-700 flex items-center gap-2 min-w-0">
                  <i class="fas fa-triangle-exclamation shrink-0"></i>
                  <span class="truncate">
                    Hay gente sin asignar
                    <span class="font-bold normal-case tracking-normal opacity-70">· {{ resumenSinAsignar }}</span>
                  </span>
                </span>
                <i class="fas fa-chevron-down text-amber-500 text-xs transition-transform shrink-0"
                   :class="sinAsignarAbierto ? 'rotate-180' : ''"></i>
              </button>
              <div v-if="sinAsignarAbierto" class="px-4 pb-4">
              <div v-for="hallazgo in file.subgruposIncompletos" :key="hallazgo.eje" class="text-xs text-amber-900 leading-relaxed mb-1.5 last:mb-0">
                <span class="font-black">{{ hallazgo.ejeLabel }}:</span>
                <span class="font-bold">{{ hallazgo.faltan.length }}</span>
                {{ hallazgo.faltan.length === 1 ? 'persona no está' : 'personas no están' }} en ningún subgrupo —
                <span class="text-amber-700">{{ hallazgo.faltan.slice(0, 6).join(', ') }}<span v-if="hallazgo.faltan.length > 6"> y {{ hallazgo.faltan.length - 6 }} más</span></span>
              </div>
              </div>
            </div>

            <div v-if="!file.cotizaciones || file.cotizaciones.length === 0" class="bg-white border-2 border-dashed border-slate-300 rounded-3xl p-12 text-center text-slate-400">
              <i class="fas fa-clipboard-list text-4xl mb-4 opacity-50"></i>
              <p class="text-sm font-bold uppercase tracking-widest">No hay cotizaciones</p>
              <p class="text-xs mt-2 font-medium">Haz clic en "Crear Nueva Propuesta" para arrancar el motor operativo.</p>
            </div>

            <div v-else v-for="cot in versionesVivas" :key="cot.id"
                 class="rounded-2xl p-4 sm:p-5 border shadow-sm hover:border-[#376875] transition-colors group mb-4"
                 :class="[
                   cot.estado === 'operativa'
                     ? 'border-orange-200 ml-4 sm:ml-8 border-l-4 border-l-orange-300 rounded-l-none'
                     : 'border-slate-200',
                   // ⚠️ El fondo lo decide TENER la operación, no el estado: son cosas distintas
                   // desde que confirmar ya no la arma. Ver tieneOperacion().
                   tieneOperacion(cot) ? 'bg-[#376875]/[0.04]' : 'bg-white',
                 ]">

              <!-- ⚠️ El vínculo, ESCRITO. La operativa comparte número con su confirmada, así que
                   sin esta línea eran dos tarjetas diciendo «P1» y parecía otra propuesta. La
                   sangría sola no basta: dice que cuelga de algo, no de qué. -->
              <div v-if="cot.estado === 'operativa'" class="flex items-center gap-2 -mt-1 mb-3 text-[11px] font-semibold uppercase tracking-wide text-orange-600">
                <i class="fas fa-turn-up fa-rotate-90 text-[10px]"></i>
                <span>Operativa de la Propuesta {{ cot.propuesta }}</span>
                <span v-if="confirmadaDe(cot)" class="text-slate-400 normal-case font-normal tracking-normal">
                  · deriva de la confirmada · el cliente sigue viendo sus precios
                </span>
              </div>

              <!-- 1. CABECERA: Propuesta, Estado y Botones -->
              <div class="flex flex-wrap sm:flex-nowrap items-start justify-between gap-3 mb-4">

                <!-- Izquierda: Propuesta y Estado -->
                <div class="flex items-center gap-3">
                  <!-- Badge de versión, editable -->
                  <div v-if="editandoVersion !== (cot['@id'] || cot.id)"
                       @click="iniciarEdicionVersion(cot)"
                       class="w-12 h-12 rounded-full bg-slate-100 flex items-center justify-center font-black text-slate-700 text-lg border-2 border-white shadow-sm group-hover:bg-[#376875] group-hover:text-white transition-colors cursor-pointer shrink-0"
                       title="Click para editar versión">
                    P{{ cot.propuesta }}
                  </div>
                  <div v-else class="flex items-center gap-1 shrink-0">
                    <input v-model.number="propuestaTemp" type="number" min="1"
                           class="w-14 h-12 text-center font-black rounded-full border-2 border-[#376875] outline-none"
                           @keyup.enter="guardarVersion(cot)" @keyup.esc="editandoVersion = null">
                    <button @click="guardarVersion(cot)" class="text-emerald-600 w-8 h-8 flex items-center justify-center bg-emerald-50 rounded-full"><i class="fas fa-check"></i></button>
                    <button @click="editandoVersion = null" class="text-slate-400 w-8 h-8 flex items-center justify-center bg-slate-100 rounded-full"><i class="fas fa-times"></i></button>
                  </div>

                  <div class="min-w-0">
                    <div class="flex items-center gap-2 leading-none flex-wrap">
                      <p class="text-sm sm:text-base font-black text-slate-800">
                        {{ t18(cot.titulo) || `Propuesta ${cot.propuesta}` }}
                      </p>
                      <span class="text-[9px] font-black bg-slate-100 text-slate-500 px-1.5 py-0.5 rounded border border-slate-200 uppercase shrink-0">{{ cot.estado || 'Pendiente' }}</span>
                      <span class="text-[9px] font-black bg-orange-50 text-orange-600 px-1.5 py-0.5 rounded border border-orange-100 uppercase shrink-0">{{ cot.monedaGlobal || 'USD' }}</span>

                      <!-- ⚠️ Dice dónde vive la operación, que ya NO se deduce del estado: desde
                           el 02/09/2026 confirmar no la arma y la operativa se la lleva. Las dos
                           dejan una confirmada vacía y significan cosas opuestas. -->
                      <span v-if="tieneOperacion(cot)"
                            class="text-[9px] font-black bg-[#376875] text-white px-1.5 py-0.5 rounded uppercase shrink-0 flex items-center gap-1"
                            :title="`${cot.filasOperacionActivas} servicios activos en La Biblia`">
                        <i class="fas fa-list-check text-[8px]"></i>
                        Operación · {{ cot.filasOperacionActivas }}
                      </span>

                      <!-- Sin operación PERO es donde debería vivir: es una llamada a armarla, no
                           un error. Se calla en las propuestas que todavía se venden. -->
                      <span v-else-if="cot.estado === 'operativa' || (cot.estado === 'confirmado' && !operativaDe(cot))"
                            class="text-[9px] font-black bg-amber-50 text-amber-700 px-1.5 py-0.5 rounded border border-amber-200 uppercase shrink-0"
                            title="Todavía no se ha armado la operación de esta propuesta">
                        Sin operación
                      </span>
                    </div>
                    <p v-if="cot.resumen" class="text-[10px] text-slate-400 font-medium mt-1 truncate max-w-35 sm:max-w-xs">
                      {{ resumenPreview(cot.resumen) }}
                    </p>
                  </div>
                </div>

                <!-- Derecha: Botones de Acción.
                     ⚠️ `flex-wrap`: son hasta OCHO botones y en un móvil se apretaban hasta
                     quedar ilegibles y difíciles de acertar. Fluyen a segunda línea en vez de
                     encogerse — un icono de 36 px es el mínimo para el dedo, y perder una fila de
                     alto cuesta menos que fallar el botón. -->
                <div class="flex flex-wrap items-center gap-2 w-full sm:w-auto justify-end mt-2 sm:mt-0">
                  <button @click="abrirMotor(cot)" class="px-4 py-2 bg-[#E07845] text-white text-xs font-bold rounded-xl shadow-sm hover:bg-[#c96636] transition-colors flex items-center gap-2">
                    Editar <i class="fas fa-arrow-right"></i>
                  </button>

                  <!-- ⚠️ Publicar es un eje PROPIO, no una consecuencia del estado. Antes había que
                       poner «enviada» para que el cliente la viera —o para verla uno mismo—, que es
                       mentir sobre un acto comercial para conseguir una visibilidad. -->
                  <button v-tooltip-tactil @click="alternarPublicado(cot)"
                          :class="cot.publicado
                            ? 'text-emerald-600 border-emerald-200 bg-emerald-50 hover:bg-emerald-100'
                            : 'text-slate-400 border-slate-200 hover:text-slate-600 hover:bg-slate-50'"
                          class="w-9 h-9 flex items-center justify-center rounded-xl border transition-colors"
                          :title="cot.publicado
                            ? `El cliente VE esta propuesta. Pulsa para dejar de publicarla.`
                            : `El cliente NO la ve. Pulsa para publicarla.`">
                    <i class="fas text-xs" :class="cot.publicado ? 'fa-eye' : 'fa-eye-slash'"></i>
                  </button>

                  <!-- La vista cliente se abre siempre: como operador ves también lo no publicado. -->
                  <a :href="linkPublicoPropuesta(cot.propuesta)" target="_blank" rel="noopener"
                     class="w-9 h-9 flex items-center justify-center rounded-xl border border-slate-200 text-slate-400 hover:text-emerald-500 hover:border-emerald-200 hover:bg-emerald-50 transition-colors"
                     :title="`Abrir vista cliente (P${cot.propuesta})`">
                    <i class="fas fa-external-link-alt text-xs"></i>
                  </a>

                  <!-- Copiar ese mismo enlace. Va PEGADO al de abrir porque son la misma cosa en
                       dos gestos: mirarla uno o mandársela al cliente — y mandársela era abrir la
                       pestaña y copiar de la barra de direcciones. -->
                  <button v-tooltip-tactil @click="copiarLinkPropuesta(cot.propuesta)"
                          :class="propuestaCopiada === cot.propuesta
                            ? 'text-emerald-600 border-emerald-200 bg-emerald-50'
                            : 'text-slate-400 border-slate-200 hover:text-emerald-500 hover:border-emerald-200 hover:bg-emerald-50'"
                          class="w-9 h-9 flex items-center justify-center rounded-xl border transition-colors"
                          :title="propuestaCopiada === cot.propuesta
                            ? 'Enlace copiado'
                            : `Copiar el enlace de la vista cliente (P${cot.propuesta})`">
                    <i class="text-xs" :class="propuestaCopiada === cot.propuesta ? 'fas fa-check' : 'far fa-copy'"></i>
                  </button>

                  <!-- Armar la operación. ⚠️ Mismo sitio que el plan —donde VIVE la operación—
                       porque son la misma cosa en dos momentos: éste la crea, aquél la revisa.
                       Confirmar ya no la crea (02/09/2026): ver GenerarOperacionProcessor. -->
                  <button v-tooltip-tactil v-if="cot.estado === 'operativa' || (cot.estado === 'confirmado' && !operativaDe(cot))"
                          @click="generarOperacion(cot)"
                          :disabled="armandoOperacion === extractIdStr(cot.id || cot['@id'])"
                          class="w-9 h-9 flex items-center justify-center rounded-xl border border-[#376875]/30 text-[#376875] hover:bg-[#376875] hover:text-white transition-colors disabled:opacity-50"
                          title="Armar la operación: una fila de La Biblia por componente">
                    <i class="fas fa-spinner fa-spin text-xs" v-if="armandoOperacion === extractIdStr(cot.id || cot['@id'])"></i>
                    <i class="fas fa-list-check text-xs" v-else></i>
                  </button>

                  <!-- Donde VIVE la operación, que no siempre es la confirmada.
                       ⚠️ Al abrir la operativa, las filas de La Biblia se mudan a ella y las de la
                       confirmada quedan canceladas. Este botón se quedaba en la confirmada, o sea
                       apuntando a un plan vacío, mientras la fila que sí tiene las 47 filas no lo
                       ofrecía. Se vio en pantalla, no en un test: a ojo el `v-if` parecía correcto
                       —y lo era, hasta que existió la operativa—. Ver docs/Cotizaciones.md §6.j.3. -->
                  <button v-tooltip-tactil v-if="cot.estado === 'operativa' || (cot.estado === 'confirmado' && !operativaDe(cot))"
                          @click="abrirPlanOperacion(cot)"
                          class="w-9 h-9 flex items-center justify-center rounded-xl border border-[#376875]/30 text-[#376875] hover:bg-[#376875] hover:text-white transition-colors"
                          title="Revisar los cambios de esta versión en el Centro de Operaciones">
                    <i class="fas fa-code-compare text-xs"></i>
                  </button>

                  <!-- Abrir la operativa: sólo en la confirmada, y sólo si no la tiene ya. Es
                       idempotente en el servidor —devuelve la que hay—, pero enseñar el botón
                       cuando ya existe invita a pulsarlo esperando otra cosa. -->
                  <button v-tooltip-tactil v-if="cot.estado === 'confirmado' && !operativaDe(cot)"
                          @click="abrirOperativa(cot)"
                          :disabled="abriendoOperativa === extractIdStr(cot.id || cot['@id'])"
                          class="w-9 h-9 flex items-center justify-center rounded-xl border border-orange-200 text-orange-500 hover:bg-orange-500 hover:text-white hover:border-orange-500 transition-colors disabled:opacity-50"
                          title="Abrir la propuesta operativa: lo que de verdad se va a operar">
                    <i class="fas fa-spinner fa-spin text-xs" v-if="abriendoOperativa === extractIdStr(cot.id || cot['@id'])"></i>
                    <i class="fas fa-route text-xs" v-else></i>
                  </button>

                  <!-- Guardar histórico vive AL LADO de clonar porque son la misma operación en
                       direcciones opuestas, y confundirlas es lo caro: clonar crea la versión
                       siguiente y deja ésta atrás —bien antes de vender—; esto congela una foto y
                       deja ésta viva con sus órdenes. Ver docs/Cotizaciones.md §6.j. -->
                  <button v-tooltip-tactil @click="guardarHistorico(cot)" :disabled="guardandoHistorico === extractIdStr(cot.id || cot['@id'])"
                          class="w-9 h-9 flex items-center justify-center rounded-xl border border-slate-200 text-slate-400 hover:text-violet-500 hover:border-violet-200 hover:bg-violet-50 transition-colors disabled:opacity-50"
                          title="Guardar una foto de cómo está ahora, antes de modificarla">
                    <i class="fas fa-spinner fa-spin text-xs" v-if="guardandoHistorico === extractIdStr(cot.id || cot['@id'])"></i>
                    <i class="fas fa-camera text-xs" v-else></i>
                  </button>

                  <button v-tooltip-tactil @click="clonarVersion(cot)" :disabled="clonandoItem === extractIdStr(cot.id || cot['@id'])"
                          class="w-9 h-9 flex items-center justify-center rounded-xl border border-slate-200 text-slate-400 hover:text-sky-500 hover:border-sky-200 hover:bg-sky-50 transition-colors disabled:opacity-50"
                          title="Clonar esta versión">
                    <i class="fas fa-spinner fa-spin text-xs" v-if="clonandoItem === extractIdStr(cot.id || cot['@id'])"></i>
                    <i class="fas fa-copy text-xs" v-else></i>
                  </button>

                  <button v-tooltip-tactil @click="eliminarVersion(cot)" :disabled="eliminandoItem === (cot['@id'] || cot.id)"
                          title="Eliminar esta propuesta"
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
                  <!-- ⚠️ El histórico enseña sus CIFRAS, no sólo su fecha.
                       Una foto del pasado se consulta para responder «¿cuánto habíamos cotizado
                       antes?», y con sólo «V1 · 11 jul» había que abrirla para saberlo — una por
                       una, y comparando de memoria. Con compra, venta y pax delante, la pregunta
                       se contesta desde la lista y sólo se abre la que interesa.
                       Mismos campos y mismo formato que la tarjeta de la versión viva de arriba:
                       si se leen distinto, no se pueden comparar. -->
                  <div v-for="h in historicosDe(cot)" :key="h.id"
                       class="bg-violet-50/60 border border-violet-100 rounded-lg px-3 py-2">
                    <div class="flex items-center justify-between gap-3">
                      <span class="text-[11px] font-bold text-violet-800 min-w-0 truncate">
                        <i class="fas fa-clock-rotate-left text-[9px] mr-1.5 text-violet-400"></i>
                        P{{ h.propuesta }} · {{ h.createdAt ? new Date(h.createdAt).toLocaleString('es-PE', { dateStyle: 'medium', timeStyle: 'short' }) : 'sin fecha' }}
                      </span>
                      <button @click="abrirMotor(h)"
                              class="text-[10px] font-black text-violet-600 hover:text-violet-900 underline underline-offset-2 shrink-0">
                        Ver
                      </button>
                    </div>

                    <!-- Mismas tres cifras que la tarjeta de la versión viva —pax, venta y
                         ganancia— y en el mismo orden: si se leen distinto no se pueden comparar,
                         y comparar es para lo único que está esta lista. El idioma no se repite,
                         que es de la versión y no de la foto.

                         Cada una lleva su DIFERENCIA con la vigente cuando la hay. Sin ella hay
                         que restar de cabeza tres pares de números por cada foto, que es
                         exactamente el trabajo que esta fila viene a quitar. -->
                    <div class="grid grid-cols-3 gap-2 mt-2 pt-2 border-t border-violet-100">
                      <div class="flex flex-col min-w-0">
                        <span class="text-[9px] font-bold text-violet-400 uppercase tracking-widest">
                          <i class="fas fa-users mr-1"></i>Pax
                        </span>
                        <span class="text-[11px] font-black text-violet-900 tabular-nums">
                          {{ h.numPax ?? '—' }}
                          <span v-if="diferenciaConVigente(h.numPax, cot.numPax) !== null"
                                :class="(diferenciaConVigente(h.numPax, cot.numPax) ?? 0) > 0 ? 'text-emerald-600' : 'text-rose-600'"
                                class="text-[9px] font-black ml-0.5">
                            {{ formatoDiferencia(diferenciaConVigente(h.numPax, cot.numPax) ?? 0, 0) }}
                          </span>
                        </span>
                      </div>
                      <div class="flex flex-col min-w-0 border-l border-violet-100 pl-2">
                        <span class="text-[9px] font-bold text-violet-400 uppercase tracking-widest">
                          <i class="fas fa-money-bill mr-1"></i>Venta
                        </span>
                        <span class="text-[11px] font-black text-violet-900 tabular-nums">
                          <span class="text-[9px] font-bold text-violet-400 mr-0.5">{{ h.monedaGlobal }}</span>{{ h.totalVenta ?? '0.00' }}
                          <span v-if="diferenciaConVigente(h.totalVenta, cot.totalVenta) !== null"
                                :class="(diferenciaConVigente(h.totalVenta, cot.totalVenta) ?? 0) > 0 ? 'text-emerald-600' : 'text-rose-600'"
                                class="block text-[9px] font-black">
                            {{ formatoDiferencia(diferenciaConVigente(h.totalVenta, cot.totalVenta) ?? 0) }}
                          </span>
                        </span>
                      </div>
                      <div class="flex flex-col min-w-0 border-l border-violet-100 pl-2">
                        <span class="text-[9px] font-bold text-violet-400 uppercase tracking-widest">
                          <i class="fas fa-chart-line mr-1"></i>Ganancia
                        </span>
                        <span class="text-[11px] font-black text-violet-900 tabular-nums">
                          <span class="text-[9px] font-bold text-violet-400 mr-0.5">{{ h.monedaGlobal }}</span>{{ h.ganancia ?? '0.00' }}
                          <span v-if="diferenciaConVigente(h.ganancia, cot.ganancia) !== null"
                                :class="(diferenciaConVigente(h.ganancia, cot.ganancia) ?? 0) > 0 ? 'text-emerald-600' : 'text-rose-600'"
                                class="block text-[9px] font-black">
                            {{ formatoDiferencia(diferenciaConVigente(h.ganancia, cot.ganancia) ?? 0) }}
                          </span>
                        </span>
                      </div>
                    </div>
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
            <button @click="capas.cerrar('pax')" class="w-8 h-8 rounded-lg text-indigo-200 hover:text-white hover:bg-indigo-500 ml-1">
              <i class="fas fa-times"></i>
            </button>
          </div>
        </div>
        <!-- ── Cara de lectura ─────────────────────────────────────────
             Recorrer 131 fichas con las flechas es lo que más se hace, y en un formulario los
             datos están repartidos entre campos que hay que interpretar. Aquí se leen de corrido. -->
        <div v-if="modoVistaPax && paxEnFoco" class="p-6 space-y-4 overflow-y-auto">
          <div>
            <p class="text-xl font-black text-slate-800 leading-tight flex items-start gap-2">
              <span class="flex-1">{{ paxEnFoco.nombre }} {{ paxEnFoco.apellido }}</span>
              <!-- Nombre y apellidos juntos: es como los pide cualquier formulario. -->
              <button type="button" @click="copiar(`${paxEnFoco.nombre ?? ''} ${paxEnFoco.apellido ?? ''}`, 'nombre')"
                      :title="copiado === 'nombre' ? 'Copiado' : 'Copiar nombre y apellidos'"
                      class="shrink-0 w-7 h-7 rounded-lg border text-xs transition-colors"
                      :class="copiado === 'nombre'
                        ? 'bg-emerald-50 border-emerald-200 text-emerald-600'
                        : 'border-slate-200 text-slate-300 hover:text-indigo-500 hover:border-indigo-200'">
                <i class="fas" :class="copiado === 'nombre' ? 'fa-check' : 'fa-copy'"></i>
              </button>
            </p>
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
            <!-- ⚠️ **La FECHA, no sólo la edad.** La edad se calcula y sirve para saber si es
                 menor; la fecha es la que piden la aerolínea, el seguro y migraciones — y es
                 además la mitad de la contraseña con la que el pasajero entra a su viaje. Faltaba
                 en la ficha, así que había que abrir el editor para leerla. -->
            <div class="col-span-2">
              <p class="text-[10px] font-bold text-slate-400 uppercase">Nacimiento</p>
              <p class="text-sm font-bold text-slate-700 flex items-center gap-2">
                <span>{{ fechaLarga(paxEnFoco.fechanacimiento) || '—' }}</span>
                <button v-if="paxEnFoco.fechanacimiento" type="button"
                        @click="copiar(fechaLarga(paxEnFoco.fechanacimiento), 'nacimiento')"
                        :title="copiado === 'nacimiento' ? 'Copiado' : 'Copiar fecha'"
                        class="w-6 h-6 rounded border text-[10px] transition-colors"
                        :class="copiado === 'nacimiento'
                          ? 'bg-emerald-50 border-emerald-200 text-emerald-600'
                          : 'border-slate-200 text-slate-300 hover:text-indigo-500 hover:border-indigo-200'">
                  <i class="fas" :class="copiado === 'nacimiento' ? 'fa-check' : 'fa-copy'"></i>
                </button>
              </p>
            </div>
          </div>

          <div v-if="documentosDe.length">
            <p class="text-[10px] font-bold text-slate-400 uppercase mb-1">Documentos</p>
            <!-- El vencido en rojo y con la palabra: un documento caducado no es un matiz de
                 formato, es alguien que no embarca. -->
            <div v-for="d in documentosDe" :key="d.id" class="flex items-baseline gap-2 text-sm">
              <span class="font-bold text-slate-700">{{ d.etiqueta }}</span>
              <span class="font-black text-slate-800">{{ d.numero }}</span>
              <!-- El NÚMERO solo, sin la etiqueta: es lo que se pega en el formulario. -->
              <button type="button" @click="copiar(d.numero, `doc-${d.id}`)"
                      :title="copiado === `doc-${d.id}` ? 'Copiado' : 'Copiar número'"
                      class="w-6 h-6 shrink-0 self-center rounded border text-[10px] transition-colors"
                      :class="copiado === `doc-${d.id}`
                        ? 'bg-emerald-50 border-emerald-200 text-emerald-600'
                        : 'border-slate-200 text-slate-300 hover:text-indigo-500 hover:border-indigo-200'">
                <i class="fas" :class="copiado === `doc-${d.id}` ? 'fa-check' : 'fa-copy'"></i>
              </button>
              <span v-if="d.vence" class="text-[11px] font-bold" :class="d.vencido ? 'text-red-600' : 'text-slate-400'">
                {{ d.vencido ? 'VENCIDO ' : 'vence ' }}{{ d.vence.split('-').reverse().join('/') }}
              </span>
              <span v-else class="text-[11px] font-bold text-amber-500">sin comprobar</span>
              <!-- La fecha de vencimiento también se copia: la piden los mismos formularios. -->
              <button v-if="d.vence" type="button"
                      @click="copiar(d.vence.split('-').reverse().join('/'), `ven-${d.id}`)"
                      :title="copiado === `ven-${d.id}` ? 'Copiado' : 'Copiar vencimiento'"
                      class="w-6 h-6 shrink-0 self-center rounded border text-[10px] transition-colors"
                      :class="copiado === `ven-${d.id}`
                        ? 'bg-emerald-50 border-emerald-200 text-emerald-600'
                        : 'border-slate-200 text-slate-300 hover:text-indigo-500 hover:border-indigo-200'">
                <i class="fas" :class="copiado === `ven-${d.id}` ? 'fa-check' : 'fa-copy'"></i>
              </button>
            </div>
          </div>

          <!-- ── VUELOS ─────────────────────────────────────────────────────
               Plegado por defecto: la mayoría de las veces se abre una ficha para ver quién es,
               no a qué hora vuela, y desplegar cuatro tramos por persona entierra el resto.
               Se abre con CLIC o TOQUE —un botón, sin `hover`, que en un móvil no existe—. -->
          <div v-if="vuelosDe(paxEnFoco).length">
            <p class="text-[10px] font-bold text-slate-400 uppercase mb-1">Vuelos</p>

            <div v-for="v in vuelosDe(paxEnFoco)" :key="v.id" class="mb-2 last:mb-0">
              <button type="button" @click="alternarPnr(String(v.clave))"
                      class="w-full text-left flex items-center gap-2 py-1 rounded-lg hover:bg-slate-50 active:bg-slate-100">
                <i class="fas text-[10px] text-slate-400 w-3"
                   :class="pnrsAbiertos.has(String(v.clave)) ? 'fa-chevron-down' : 'fa-chevron-right'"></i>
                <span class="text-sm min-w-0 flex flex-wrap items-baseline gap-x-1.5">
                  <span class="font-bold text-sky-600">{{ v.tramo }}</span>
                  <span class="text-slate-700 font-bold">{{ v.nombre }}</span>
                  <span class="text-slate-400 font-mono">· {{ v.clave }}</span>
                </span>
                <!-- Pagado no es emitido, y eso se ve sin abrir: es lo que hay que perseguir. -->
                <span v-if="!v.emitido"
                      class="ml-auto shrink-0 text-[9px] font-black uppercase tracking-wider bg-amber-100 text-amber-700 border border-amber-200 rounded px-1.5 py-0.5">
                  sin emitir
                </span>
              </button>

              <div v-if="pnrsAbiertos.has(String(v.clave))"
                   class="ml-5 mt-1 bg-slate-50 border border-slate-100 rounded-lg px-3 py-2 space-y-1">
                <p v-for="(t, i) in v.tramos" :key="i" class="text-[11px] leading-snug">
                  <span class="font-mono font-black text-slate-700">{{ t.numero }}</span>
                  <span class="text-slate-400 mx-1">{{ t.dia }}</span>
                  <span class="font-black text-slate-800">{{ t.sale }}</span>
                  <span class="text-slate-500"> {{ t.ruta }} </span>
                  <span class="font-black text-slate-800">{{ t.llega }}</span>
                  <span v-if="t.otroDia" class="text-amber-600 font-bold ml-1">+1 día</span>
                </p>

                <p v-if="!v.tramos.length" class="text-[11px] text-slate-400 italic">
                  Sin vuelos cargados en esta reserva.
                </p>

                <!-- Plazos y trámites: lo que antes vivía en la bandeja de correo de alguien. -->
                <p v-for="(n, i) in v.notas" :key="`n${i}`"
                   class="text-[10px] text-amber-700 leading-snug">
                  <i class="fas fa-circle-info mr-1 opacity-60"></i>{{ n }}
                </p>
              </div>
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
            <!-- ⚠️ **Nacimiento y sexo van ARRIBA**, con el nombre. Estaban al final, después de
                 los documentos y de los subgrupos, y son los dos datos que pide toda aerolínea y
                 todo control migratorio: quedaban a un scroll de distancia de lo que se teclea
                 cada vez. El orden de un formulario que se rellena 131 veces no es estética. -->
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
            <div class="col-span-2 @container">
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

              <!-- ⚠️ En el móvil esta fila NO cabe en una línea. Tipo (7rem) + vencimiento (8rem) +
                   papelera + huecos se comen ~300 px de los ~340 que tiene el panel, y al número
                   —que es el dato— le quedaban 40: un recuadro donde no se ve ni una cifra. Por
                   debajo de 26rem va en dos líneas (tipo y número arriba, vencimiento debajo) y a
                   partir de ahí se recompone en la línea de siempre.

                   ⚠️ La medida es `@container` y NO el breakpoint `sm:` a propósito: lo que aprieta
                   es el ancho del PANEL, no el de la ventana. Con `sm:` una tablet en horizontal
                   cumple el breakpoint y sigue teniendo el panel estrecho, o sea el mismo recuadro
                   inservible con otra excusa. -->
              <div v-for="(ident, idx) in paxForm.identificaciones" :key="idx"
                   class="grid grid-cols-[7rem_1fr_2.25rem] @[26rem]:flex gap-2 items-start mb-3 @[26rem]:mb-2">
                <select v-model="ident.tipo" required
                        class="col-start-1 row-start-1 @[26rem]:w-28 @[26rem]:shrink-0 border rounded-lg px-2 py-2 text-sm outline-none focus:border-indigo-500">
                  <option v-for="(label, valor) in DOCUMENTO_IDENTIDAD_LABELS" :key="valor" :value="valor">{{ label }}</option>
                </select>
                <input v-model="ident.numero" required type="text" placeholder="Número"
                       class="col-start-2 row-start-1 @[26rem]:flex-1 min-w-0 border rounded-lg px-3 py-2 text-sm outline-none focus:border-indigo-500">
                <div class="col-start-1 col-span-2 row-start-2 @[26rem]:w-32 @[26rem]:shrink-0">
                  <MaskedDateInput v-model="ident.vencimiento" placeholder="Vence" />
                </div>
                <button type="button" @click="paxForm.identificaciones.splice(idx, 1)"
                        class="col-start-3 row-start-1 shrink-0 w-9 h-9 rounded-lg text-slate-300 hover:text-red-500 hover:bg-red-50 transition-colors">
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
              <!-- ⚠️ PLEGADO por defecto, y enseñando SÓLO lo que ya tiene. Desplegado son 108
                   píldoras entre el teléfono y el botón de guardar: quien abre a corregir un
                   apellido tenía que recorrerlas todas para llegar abajo. Casi nadie cambia de
                   habitación al editar; los que sí, tocan «Cambiar». -->
              <button type="button" @click="subgruposPaxAbiertos = !subgruposPaxAbiertos"
                      class="w-full flex items-center justify-between gap-2 mb-1.5">
                <label class="block text-[10px] font-bold text-slate-500 uppercase cursor-pointer">Subgrupos</label>
                <span class="text-[10px] font-black uppercase tracking-widest text-indigo-500">
                  {{ subgruposPaxAbiertos ? 'Listo' : 'Cambiar' }}
                </span>
              </button>

              <div v-if="!subgruposPaxAbiertos" class="flex flex-wrap gap-1.5 mb-2">
                <span v-for="g in gruposElegidosEnFicha" :key="g.id"
                      class="inline-flex items-center gap-1 bg-teal-50 text-teal-700 border border-teal-200 rounded-lg px-2 py-1 text-[11px] font-black">
                  {{ g.clave }}<span v-if="g.nombre" class="font-medium opacity-70">{{ g.nombre }}</span>
                </span>
                <span v-if="!gruposElegidosEnFicha.length" class="text-[10px] font-bold text-slate-300 italic py-1">
                  Sin subgrupos asignados.
                </span>
              </div>

              <div v-for="sec in (subgruposPaxAbiertos ? seccionesDeGrupos : [])" :key="sec.clave" class="mb-2">
                <div class="flex items-center justify-between mb-1">
                  <p class="text-[9px] font-black text-slate-400 uppercase tracking-widest">
                    <i class="fas" :class="sec.icon"></i> {{ sec.label }}
                  </p>
                  <!-- El plegado sólo aparece si sobra: con nueve grupos el botón es ruido. -->
                  <button v-if="sec.lista.length > TOPE_PILDORAS" type="button" @click="alternarEje(sec.clave)"
                          class="text-[9px] font-black uppercase tracking-widest text-indigo-500 hover:text-indigo-700">
                    {{ ejeEstaAbierto(sec.clave, sec.lista.length) ? 'Plegar' : `Ver las ${sec.lista.length}` }}
                  </button>
                </div>

                <input v-if="ejeEstaAbierto(sec.clave, sec.lista.length) && sec.lista.length > TOPE_PILDORAS"
                       v-model="filtroEje[sec.clave]" type="text" placeholder="Filtrar…"
                       class="w-full mb-1.5 border rounded-lg px-2.5 py-1 text-[11px] outline-none focus:border-indigo-500">

                <div class="flex flex-wrap gap-1.5">
                  <span v-for="g in pildorasVisibles(sec.clave, sec.lista)" :key="g.id"
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
                  <span v-if="!pildorasVisibles(sec.clave, sec.lista).length"
                        class="text-[10px] font-bold text-slate-300 italic py-1">
                    {{ ejeEstaAbierto(sec.clave, sec.lista.length) ? 'Nada coincide con el filtro.' : 'Sin asignar.' }}
                  </span>
                </div>

                <!-- El itinerario, sólo de los grupos a los que PERTENECE: es para comprobar un
                     horario, no para elegir, y pintarlo de los 66 que no le tocan es ruido. -->
                <div v-for="g in detallesDe(sec.lista)" :key="`d-${g.id}`"
                     class="mt-1.5 bg-slate-50 border border-slate-200 rounded-lg px-2.5 py-1.5">
                  <p class="text-[9px] font-black text-slate-400 uppercase tracking-widest">{{ g.clave }}</p>
                  <!-- eslint-disable-next-line vue/no-v-html -- Lo escribe el operador y `formatoAHtml()` escapa ANTES de aplicar marcas. -->
                  <p v-html="formatoAHtml(g.detalle ?? '')"
                     class="text-[10px] font-medium text-slate-600 whitespace-pre-line leading-snug"></p>
                </div>
              </div>
            </div>
          </div>
          <div class="pt-4 border-t border-slate-100 flex justify-end gap-3">
            <button type="button" @click="paxEditandoIri ? girarPanelPax(true) : capas.cerrar('pax')"
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

  <!-- ── Corregir un subgrupo ──────────────────────────────────────────────
       En MODAL y no en línea: la sección lista hasta 66 habitaciones, así que un formulario al
       principio queda a media pantalla de la píldora que se tocó — se corrige a ciegas o hay que
       subir a buscarlo. El modal sale donde está la mirada. -->
  <Teleport to="body">
    <div v-if="grupoEditando" class="fixed inset-0 z-1000 bg-slate-900/60 backdrop-blur-sm flex items-center justify-center p-4">
      <div class="bg-white w-full max-w-lg rounded-3xl shadow-2xl overflow-hidden flex flex-col max-h-[calc(100dvh-2rem)]">
        <div class="bg-indigo-600 px-6 py-4 flex justify-between items-center text-white shrink-0">
          <h3 class="font-black text-sm uppercase tracking-widest">
            <i class="fas fa-pencil-alt mr-2"></i> Corregir subgrupo
          </h3>
          <button type="button" @click="capas.cerrar('grupo-edicion')" class="text-indigo-200 hover:text-white">
            <i class="fas fa-times"></i>
          </button>
        </div>

        <form @submit.prevent="guardarGrupo" class="p-6 space-y-4 overflow-y-auto">
          <div class="grid grid-cols-2 gap-4">
            <div>
              <label class="block text-[10px] font-bold text-slate-500 uppercase mb-1">Eje *</label>
              <select v-model="grupoForm.tipo" class="w-full border rounded-lg px-3 py-2 text-sm outline-none focus:border-indigo-500">
                <option v-for="(cfg, valor) in GRUPO_TIPO_LABELS" :key="valor" :value="valor">{{ cfg.label }}</option>
              </select>
            </div>
            <div v-if="grupoForm.tipo === 'reserva_aerea'">
              <label class="block text-[10px] font-bold text-slate-500 uppercase mb-1">Tramo</label>
              <input v-model="grupoForm.subeje" type="text" maxlength="60" placeholder="Nacional · Cusco-Puno"
                     class="w-full border rounded-lg px-3 py-2 text-sm outline-none focus:border-indigo-500 placeholder:text-slate-300">
            </div>
          </div>

          <div class="grid grid-cols-2 gap-4">
            <div>
              <label class="block text-[10px] font-bold text-slate-500 uppercase mb-1">Clave *</label>
              <input v-model="grupoForm.clave" type="text" maxlength="60" required
                     class="w-full border rounded-lg px-3 py-2 text-sm font-bold uppercase outline-none focus:border-indigo-500">
            </div>
            <div>
              <label class="flex items-center gap-1 text-[10px] font-bold text-slate-500 uppercase mb-1">
                Nombre
                <i class="fas fa-circle-info text-teal-500 cursor-help"
                   title="Escrito IGUAL en varios subgrupos, los junta en un solo botón al filtrar: ocho reservas con «Arajet» dan un botón «Arajet». Cambiarlo aquí lo separa del resto."></i>
              </label>
              <input v-model="grupoForm.nombre" type="text" maxlength="150" placeholder="ARAJET · DOBLE"
                     class="w-full border rounded-lg px-3 py-2 text-sm outline-none focus:border-indigo-500 placeholder:text-slate-300">
            </div>
          </div>

          <div>
            <label class="block text-[10px] font-bold text-slate-500 uppercase mb-1">
              Detalle — varias líneas, admite *negrita* y listas
            </label>
            <textarea v-model="grupoForm.detalle" rows="3"
                      placeholder="* Ida DM6771 · LIM 18/09 03:00 → PUJ 09:19"
                      class="w-full border rounded-lg px-3 py-2 text-xs outline-none focus:border-indigo-500 placeholder:text-slate-300"></textarea>
          </div>

          <!-- 🔥 **«Sin emitir» sólo lo ponía el cargador de JSON, y no había forma de quitarlo.**
               Es un estado que cambia solo con el tiempo —la aerolínea emite y ya está— así que
               dejarlo únicamente en la carga significaba volver a cargar el JSON entero para
               corregir un booleano, o que se quedara mintiendo para siempre.

               ⚠️ Vive en el SUBGRUPO y no en el vuelo: lo que se emite son los billetes de una
               reserva, y una reserva cubre ida y vuelta. Por eso el interruptor está aquí. -->
          <div v-if="String(grupoForm.tipo) === EJE_AEREO" class="rounded-xl border border-slate-200 p-3">
            <label class="flex items-start gap-3 cursor-pointer">
              <input v-model="grupoForm.emitido" type="checkbox" class="mt-0.5 w-4 h-4 accent-teal-600">
              <span class="min-w-0">
                <span class="block text-[11px] font-black text-slate-700 uppercase tracking-wide">Billetes emitidos</span>
                <span class="block text-[10px] text-slate-400 leading-snug">
                  Desmárcalo si la reserva está pagada y todavía sin billete. Es lo que cuenta el
                  «sin emitir» de la cabecera de Vuelos.
                </span>
              </span>
            </label>
          </div>

          <!-- ⚠️ Cambiar la clave RENOMBRA: las pertenencias apuntan al id, así que la gente se
               queda dentro. Pero el padrón casa por clave, así que la hoja hay que corregirla
               también o al reimportarla saldría un grupo nuevo. -->
          <p class="text-[10px] font-bold text-amber-700 bg-amber-50 border border-amber-200 rounded-lg px-3 py-2 leading-snug">
            <i class="fas fa-triangle-exclamation mr-1"></i>
            Cambiar la clave renombra el grupo y la gente se queda dentro, pero el padrón casa por
            clave: corrígela también en la hoja o al reimportarla se creará otro.
          </p>

          <div class="pt-4 border-t border-slate-100 flex justify-end gap-3">
            <button type="button" @click="capas.cerrar('grupo-edicion')"
                    class="px-4 py-2 text-xs font-bold text-slate-500 border rounded-lg">Cancelar</button>
            <button type="submit" :disabled="guardandoGrupo || !grupoForm.clave.trim()"
                    class="px-5 py-2 bg-indigo-600 text-white text-xs font-bold rounded-lg shadow-sm hover:bg-indigo-700 disabled:opacity-40 flex items-center gap-2">
              <i v-if="guardandoGrupo" class="fas fa-spinner fa-spin"></i> Guardar
            </button>
          </div>
        </form>
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
          <button @click="capas.cerrar('doc')" class="text-sky-200 hover:text-white"><i class="fas fa-times"></i></button>
        </div>
        <form @submit.prevent="guardarDocumento" class="p-6 space-y-4 overflow-y-auto">
          <div v-if="!docEditandoIri">
            <label class="block text-[10px] font-bold text-slate-500 uppercase mb-1">Archivo (PDF / Img) *</label>
            <input type="file" @change="handleFileUpload" required class="w-full text-sm text-slate-500 file:mr-4 file:py-2 file:px-4 file:rounded-full file:border-0 file:text-xs file:font-black file:bg-sky-50 file:text-sky-700 hover:file:bg-sky-100">
          </div>
          <div v-else class="bg-slate-50 border border-slate-200 rounded-lg p-3 text-[10px] font-bold text-slate-500 flex items-center gap-2">
            <i class="fas fa-info-circle"></i> El archivo no se puede reemplazar aquí. Elimina y sube uno nuevo si necesitas cambiarlo.
          </div>

          <!-- ⚠️ **El TIPO va antes que el NOMBRE, y el orden ES el arreglo.**
               El nombre depende del tipo: su marcador de posición y el aviso de «si lo dejas
               vacío se llamará…» los decide el tipo elegido. Debajo, el formulario pedía
               rellenar un campo cuya ayuda todavía no se podía leer. Y en un escaneo de
               identidad el nombre ni siquiera se pide, así que el primer campo obligatorio de
               verdad es el tipo. -->
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

          <div>
            <div class="flex items-center justify-between mb-1">
              <label class="block text-[10px] font-bold text-slate-500 uppercase">
                Nombre del documento<span v-if="!esDocDeIdentidad"> *</span>
              </label>
              <button type="button"
                      @click="docForm.sobreescribirTraduccion = !docForm.sobreescribirTraduccion"
                      :title="docForm.sobreescribirTraduccion ? 'Se regenerarán las traducciones al guardar' : 'Se conservan las traducciones existentes'"
                      class="w-8 h-8 flex items-center justify-center rounded-lg border transition-all"
                      :class="docForm.sobreescribirTraduccion ? 'bg-sky-100 border-sky-300 text-sky-600 shadow-inner' : 'bg-white border-slate-200 text-slate-300 hover:text-slate-500'">
                <i class="fas fa-language text-base"></i>
              </button>
            </div>
            <input v-model="docForm.nombre" :required="!esDocDeIdentidad" type="text"
                   :placeholder="esDocDeIdentidad ? ARCHIVO_TIPO_LABELS[docForm.tipoArchivo as ArchivoTipoValue] : 'Ej. Entrada Machupicchu'"
                   class="w-full border rounded-lg px-3 py-2 text-sm outline-none focus:border-sky-500">
            <p class="text-[9px] text-slate-400 mt-1">
              <template v-if="esDocDeIdentidad">
                Si lo dejas vacío se llamará «{{ ARCHIVO_TIPO_LABELS[docForm.tipoArchivo as ArchivoTipoValue] }}».
              </template>
              <template v-else>
                {{ docForm.sobreescribirTraduccion
                  ? 'Al guardar se regenerarán las traducciones automáticas.'
                  : 'Se traduce automáticamente; las traducciones existentes se conservan.' }}
              </template>
            </p>
          </div>

          <!-- ── DE QUIÉN ES ────────────────────────────────────────────────
               Vacío = del expediente entero, que es lo de siempre. Con pasajero, es suyo; con
               pasajero Y vuelo, es su boarding pass DE ESE VUELO — que con ocho vuelos por
               persona es la única forma de distinguirlos. Ver la tabla de alcances en
               `CotizacionFilearchivo`.

               ⚠️ **También al EDITAR, y ése es el cambio.** Estaba escondido en edición a
               propósito, con el argumento de que borrar y volver a subir «deja rastro»; pero el
               reparto se tuerce solo en las familias que comparten apellido, y borrar el archivo
               obliga a pedírselo otra vez a quien ya lo mandó. Reasignar corrige a quién apunta,
               no toca el fichero. -->
          <div class="grid grid-cols-1 gap-3 pt-3 border-t border-slate-100">
            <div>
              <label class="block text-[10px] font-bold text-slate-500 uppercase mb-1">
                ¿De quién es?
                <span class="normal-case text-slate-400 font-medium">— vacío: de todo el expediente</span>
              </label>
              <!-- `limpiable` porque desvincular es media reparación: un archivo que se coló en
                   la persona equivocada a veces no es de NADIE en concreto, y devolverlo al
                   expediente entero tiene que ser un clic, no borrarlo y volver a subirlo. -->
              <SearchableSelect
                  v-model="docForm.pasajeroId"
                  :options="pasajerosElegibles"
                  placeholder="Todo el expediente"
                  limpiable
                  @change="cambiarPasajeroDelDoc"
              />
            </div>

            <!-- El tercer alcance: del SUBGRUPO. El namelist que manda la aerolínea con el PNR,
                 la lista de una habitación. Existía en la base y la pantalla no lo ofrecía. -->
            <div v-if="!docForm.pasajeroId">
              <label class="block text-[10px] font-bold text-slate-500 uppercase mb-1">
                ¿O de qué subgrupo?
                <span class="normal-case text-slate-400 font-medium">— un vuelo, una habitación</span>
              </label>
              <SearchableSelect
                  v-model="docForm.grupoId"
                  :options="subgruposElegibles"
                  placeholder="De ningún subgrupo"
                  limpiable
              />
            </div>

            <div v-if="ofreceVuelo">
              <label class="block text-[10px] font-bold text-slate-500 uppercase mb-1">
                ¿De qué vuelo?
                <span class="normal-case text-slate-400 font-medium">— para distinguir sus boarding passes</span>
              </label>
              <SearchableSelect
                  v-model="docForm.vueloId"
                  :options="vuelosElegibles"
                  placeholder="Sin vuelo concreto"
                  limpiable
              />
            </div>

            <p v-if="ARCHIVO_TIPOS_DEL_PASAJERO.includes(docForm.tipoArchivo as never) && !docForm.pasajeroId"
               class="text-[10px] font-bold text-amber-600 flex items-start gap-1.5">
              <i class="fas fa-triangle-exclamation mt-0.5"></i>
              Un documento de identidad es de una persona: elige de quién, o quedará colgado del
              expediente y lo verá cualquiera del equipo.
            </p>
          </div>
          <div class="pt-4 border-t border-slate-100 flex justify-end gap-3">
            <button type="button" @click="capas.cerrar('doc')" class="px-4 py-2 text-xs font-bold text-slate-500 border rounded-lg">Cancelar</button>
            <button type="submit" :disabled="isSubmittingDoc" class="px-5 py-2 bg-sky-600 text-white text-xs font-bold rounded-lg shadow-sm hover:bg-sky-700 flex items-center gap-2">
              <i v-if="isSubmittingDoc" class="fas fa-spinner fa-spin"></i>
              {{ docEditandoIri ? 'Guardar Cambios' : 'Subir Documento' }}
            </button>
          </div>
        </form>
      </div>
    </div>
  </Teleport>


  <!-- ══ VISOR DE DOCUMENTOS DE UNA PERSONA ════════════════════════════════
       Se abre desde su fila del manifiesto, al lado del veredicto que hay que resolver.

       🔑 **El papel y lo que se leyó de él, uno al lado del otro.** El veredicto ya dice
       «vencimiento: 2036 ← 2026»; lo único que falta para cerrarlo es mirar el documento, y hasta
       ahora eso obligaba a ir a la bóveda y buscarlo entre ~1 500 archivos. -->
  <Teleport to="body">
    <div v-if="paxDelVisor" class="fixed inset-0 z-1000 bg-slate-900/70 backdrop-blur-sm flex items-center justify-center p-4"
         @click.self="cerrarVisor()">
      <div class="bg-white w-full max-w-4xl rounded-3xl shadow-2xl overflow-hidden flex flex-col max-h-[calc(100dvh-2rem)]">
        <div class="bg-slate-800 px-6 py-4 flex justify-between items-center text-white shrink-0">
          <div class="min-w-0">
            <h3 class="font-black text-sm uppercase tracking-widest truncate">
              <i class="far fa-images mr-2"></i>{{ paxDelVisor.nombre }} {{ paxDelVisor.apellido }}
            </h3>
            <p class="text-[10px] font-bold text-slate-400 mt-0.5">
              <span v-for="(ident, i) in (paxDelVisor.identificaciones ?? [])" :key="ident.id || i">
                <span v-if="i"> · </span>{{ getDocIdLabel(ident.tipo) }}: {{ ident.numero }}
              </span>
            </p>
          </div>
          <button @click="cerrarVisor()" class="text-slate-400 hover:text-white shrink-0"><i class="fas fa-times"></i></button>
        </div>

        <div class="p-6 overflow-y-auto space-y-4">
          <!-- Lo que hay que resolver, arriba y a la vista: si hay que bajar a buscarlo, se mira
               el documento sin saber qué se estaba comprobando. -->
          <div v-for="ident in identificacionesConVeredicto(paxDelVisor)" :key="`m-${ident.id}`"
               v-show="(ident.discrepancias ?? []).length || (ident.notasValidacion ?? []).length"
               class="rounded-2xl border border-amber-200 bg-amber-50 px-4 py-3">
            <p class="text-[10px] font-black uppercase tracking-wider text-amber-800">
              {{ getDocIdLabel(ident.tipo) }} · {{ SELLO[ident.estadoValidacion!].texto }}
            </p>
            <p v-for="(d, j) in (ident.discrepancias ?? [])" :key="j" class="text-[11px] mt-1 flex items-center gap-2">
              <span class="font-bold text-amber-900">{{ d.campo }}</span>
              <span class="font-mono bg-emerald-100 border border-emerald-300 rounded px-1.5">{{ d.documento }}</span>
              <span class="text-[9px] font-bold uppercase text-slate-400">dice el documento</span>
              <span class="font-mono bg-white border border-amber-300 rounded px-1.5">{{ d.manifiesto }}</span>
              <span class="text-[9px] font-bold uppercase text-slate-400">está guardado</span>
            </p>
            <p v-for="(n, j) in (ident.notasValidacion ?? [])" :key="`mn-${j}`" class="text-[10px] text-amber-700 mt-1">{{ n }}</p>
          </div>

          <div v-if="!documentosDelVisor.length" class="text-center text-slate-400 text-xs py-8">
            No hay escaneos suyos en la bóveda.
          </div>

          <div v-for="doc in documentosDelVisor" :key="doc.id" class="rounded-2xl border border-slate-200 overflow-hidden">
            <div class="px-4 py-2 bg-slate-50 border-b border-slate-200 flex items-center justify-between gap-2">
              <p class="text-[10px] font-black uppercase tracking-wider text-slate-600">
                {{ getArchivoLabel(doc.tipoArchivo) }}
              </p>
              <div class="flex items-center gap-2 shrink-0">
                <!-- ⚠️ Girar REESCRIBE el fichero. No es una preferencia de visualización: si el
                     ángulo viviera aparte, cualquiera que se olvide de aplicarlo lo vería
                     torcido —el gate, el lector de IA— y ninguno daría error. Ya pasó con el
                     EXIF. -->
                <template v-if="esImagen(doc)">
                  <!-- ⚠️ **«Falta girar», no «parece girado».** El número es una ACCIÓN —cuántos
                       grados en sentido horario hay que aplicar— y el rótulo lo describía como un
                       ESTADO. Un escaneo que se ve girado 90° necesita 270° para enderezarse, así
                       que «parece girado 270°» contradice al ojo y hace dudar del botón que está
                       bien. El valor siempre fue correcto; lo que mentía era la frase. -->
                  <span v-if="giroSugerido(doc)" class="text-[9px] font-black uppercase tracking-wider text-amber-600">
                    falta girar {{ giroSugerido(doc) }}°
                  </span>
                  <button v-for="g in [90, 180, 270]" :key="g" type="button"
                          :disabled="girando === String(doc.id)"
                          @click="girarDoc(doc, g)"
                          class="w-6 h-6 rounded border text-[9px] font-black transition-colors disabled:opacity-40"
                          :class="g === giroSugerido(doc)
                            ? 'border-amber-400 bg-amber-100 text-amber-700'
                            : 'border-slate-200 bg-white text-slate-400 hover:text-slate-700'"
                          :title="`Girar ${g}° en sentido horario y reescribir el fichero`">
                    {{ g }}
                  </button>
                </template>
                <a :href="doc.imageUrl || undefined" target="_blank" class="text-[10px] font-bold text-sky-600 hover:text-sky-700">
                  abrir <i class="fas fa-up-right-from-square text-[8px]"></i>
                </a>
              </div>
            </div>

            <!-- `loading="lazy"`: son escaneos de 2400 px y una familia puede tener seis. -->
            <!-- ⚠️ **La URL no cambia al girar, así que el navegador servía la imagen VIEJA.** El
                 fichero se reescribe en su sitio —a propósito, para que los píxeles sean la
                 verdad— y eso deja la caché mintiendo: se gira, la petición va bien, y en pantalla
                 sigue torcida. Se le cuelga la marca de tiempo, que cambia en cada escritura. -->
            <a v-if="esImagen(doc)" :href="urlFresca(doc)" target="_blank" class="block bg-slate-900/5">
              <img :src="urlFresca(doc)" :alt="getArchivoLabel(doc.tipoArchivo)" loading="lazy"
                   class="w-full max-h-[60vh] object-contain">
            </a>
            <a v-else :href="doc.imageUrl || undefined" target="_blank"
               class="flex items-center gap-3 px-4 py-6 hover:bg-slate-50 transition-colors">
              <div class="w-10 h-10 rounded flex items-center justify-center" :class="mediaDe(doc.tipoMedio).clase">
                <i :class="mediaDe(doc.tipoMedio).icono"></i>
              </div>
              <span class="text-[11px] font-bold text-slate-600">No se puede previsualizar aquí — ábrelo en otra pestaña</span>
            </a>

            <!-- Lo que el modelo sacó de ESTE escaneo. Con el papel arriba, comprobarlo es mirar. -->
            <div v-if="leidoDe(doc)" class="px-4 py-2 border-t border-slate-100 flex flex-wrap gap-x-3 gap-y-1">
              <span v-for="(valor, clave) in leidoDe(doc)" :key="clave"
                    v-show="valor && !String(clave).startsWith('mrz')"
                    class="text-[9px] text-slate-500">
                <span class="font-bold uppercase text-slate-400">{{ ETIQUETA_LEIDA[String(clave)] ?? clave }}</span> {{ valor }}
              </span>
            </div>
          </div>
        </div>
      </div>
    </div>
  </Teleport>

  <!-- ══ PANEL DE RESOLUCIÓN ═══════════════════════════════════════════════
       Los documentos que no son de nadie, con a quién podrían pertenecer.

       🔑 **La ambigüedad se ENSEÑA, no se resuelve sola.** Dos hermanos con los mismos apellidos
       no son un fallo del buscador: son el caso normal de una familia, y lo único que falta es
       que alguien señale cuál. Por eso se listan todos los candidatos con su motivo — quien
       decide tiene que saber si confirma un hecho o acepta una corazonada. -->
  <Teleport to="body">
    <div v-if="panelSueltos" class="fixed inset-0 z-1000 bg-slate-900/60 backdrop-blur-sm flex items-center justify-center p-4">
      <div class="bg-white w-full max-w-3xl rounded-3xl shadow-2xl overflow-hidden flex flex-col max-h-[calc(100dvh-2rem)]">
        <div class="bg-amber-600 px-6 py-4 flex justify-between items-center text-white shrink-0">
          <h3 class="font-black text-sm uppercase tracking-widest">
            <i class="fas fa-user-slash mr-2"></i> Documentos sin dueño
            <span v-if="sueltos.length" class="font-bold normal-case tracking-normal opacity-80">· {{ sueltos.length }}</span>
          </h3>
          <button @click="capas.cerrar('sueltos')" class="text-amber-100 hover:text-white"><i class="fas fa-times"></i></button>
        </div>

        <div class="p-6 overflow-y-auto space-y-3">
          <p v-if="cargandoSueltos" class="text-center text-slate-400 text-xs py-6">
            <i class="fas fa-spinner fa-spin mr-1"></i> Buscando…
          </p>

          <div v-else-if="!sueltos.length" class="bg-emerald-50 border border-emerald-200 rounded-2xl p-6 text-center">
            <i class="fas fa-check-circle text-emerald-400 text-2xl mb-2"></i>
            <p class="text-[11px] font-black text-emerald-800 uppercase tracking-widest">Todos tienen dueño</p>
          </div>

          <div v-for="doc in sueltos" :key="doc.id" class="border border-slate-200 rounded-2xl p-4">
            <div class="flex items-start justify-between gap-3">
              <div class="min-w-0">
                <p class="text-[11px] font-black text-slate-800 truncate">{{ doc.nombre }}</p>
                <p v-if="doc.documento" class="text-[10px] text-slate-500 mt-0.5">
                  <span class="font-mono font-bold">{{ doc.documento.numero }}</span>
                  · {{ doc.documento.nombre }}
                  <span v-if="doc.documento.nacimiento" class="text-slate-400"> · nac. {{ doc.documento.nacimiento }}</span>
                  <!-- Que se validó por aritmética o por parecido no es un detalle: es lo que
                       decide cuánto se puede confiar en lo que hay escrito arriba. -->
                  <span v-if="doc.documento.mrz" class="ml-1 text-emerald-600 font-bold"><i class="fas fa-shield-halved text-[8px]"></i> MRZ</span>
                </p>
                <!-- ⚠️ Aquí decía «pasa antes Validar contra los escaneos», y esa tanda **nunca
                     toca un archivo sin dueño**: recorre personas → sus documentos. La
                     instrucción mandaba a un sitio que no iba a hacer nada. -->
                <p v-else class="text-[10px] text-slate-400 italic mt-0.5">
                  todavía no se ha leído
                </p>
              </div>
              <a v-if="doc.url" :href="doc.url" target="_blank"
                 class="shrink-0 text-[10px] font-bold text-sky-600 hover:text-sky-700">ver <i class="fas fa-up-right-from-square text-[8px]"></i></a>
            </div>

            <!-- Sin leer no hay a quién proponer: primero se lee, y se dice lo que cuesta. -->
            <div v-if="!doc.leido" class="mt-3 pt-3 border-t border-slate-100">
              <button type="button" :disabled="leyendo === doc.id" @click="leerSuelto(doc)"
                      class="w-full px-3 py-2 rounded-xl bg-sky-600 hover:bg-sky-700 disabled:opacity-50 text-white text-[10px] font-black uppercase tracking-wider transition-colors">
                <i class="fas mr-1" :class="leyendo === doc.id ? 'fa-spinner fa-spin' : 'fa-eye'"></i>
                {{ leyendo === doc.id ? 'Leyendo…' : 'Leer el documento' }}
              </button>
              <p class="mt-1 text-center text-[9px] text-slate-400">unos 4 segundos · lo lee una sola vez</p>
            </div>

            <div v-else class="mt-3 pt-3 border-t border-slate-100 space-y-1.5">
              <button v-for="c in doc.candidatos" :key="c.id" type="button"
                      :disabled="resolviendo === doc.id"
                      @click="resolverSuelto(doc, 'vincular', c.id)"
                      class="w-full flex items-center justify-between gap-2 px-3 py-2 rounded-xl border text-left transition-colors disabled:opacity-50"
                      :class="c.seguro
                        ? 'border-emerald-300 bg-emerald-50 hover:bg-emerald-100'
                        : 'border-amber-300 bg-amber-50 hover:bg-amber-100'">
                <span class="min-w-0">
                  <span class="block text-[11px] font-black text-slate-800 truncate">{{ c.nombre }}</span>
                  <span class="block text-[9px]" :class="c.seguro ? 'text-emerald-700' : 'text-amber-700'">{{ c.motivo }}</span>
                </span>
                <i class="fas fa-link text-[10px] shrink-0" :class="c.seguro ? 'text-emerald-500' : 'text-amber-500'"></i>
              </button>

              <!-- Crear va SIEMPRE al final y en gris: es la única acción que añade una persona al
                   manifiesto, y dos fichas de la misma persona rompen todos los conteos. Que sea
                   el camino más largo es a propósito. -->
              <button type="button" :disabled="resolviendo === doc.id" @click="resolverSuelto(doc, 'crear')"
                      class="w-full px-3 py-2 rounded-xl border border-dashed border-slate-300 text-[10px] font-black uppercase tracking-wider text-slate-500 hover:bg-slate-50 disabled:opacity-50">
                <i class="fas fa-user-plus mr-1"></i>
                {{ doc.candidatos.length ? 'No es ninguno: crear ficha nueva' : 'Crear ficha nueva con estos datos' }}
              </button>
            </div>
          </div>
        </div>
      </div>
    </div>
  </Teleport>

  <PlanOperacionModal
      :cotizacion-id="planOperacionId"
      :titulo="planOperacionTitulo"
      @cerrar="planOperacionId = null"
  />

  <!-- ══ MODAL: cargar vuelos pegando el JSON ══════════════════════════════
       Se pega y no se sube porque el origen es un correo, no un archivo que alguien mantenga.
       Ensayo primero, siempre: el backend escribe en transacción y la deshace. -->
  <div v-if="modalVuelos" class="fixed inset-0 z-50 flex items-center justify-center p-4">
    <div class="absolute inset-0 bg-black/50 backdrop-blur-sm" @click="modalVuelos = false"></div>
    <div class="relative bg-white rounded-2xl shadow-2xl w-full max-w-3xl max-h-[90vh] flex flex-col">

      <div class="px-5 py-3 border-b border-slate-200 flex items-center justify-between">
        <h3 class="text-sm font-black text-slate-800 uppercase tracking-widest">
          <i class="fas fa-plane-departure mr-2 text-sky-500"></i> Cargar vuelos
        </h3>
        <button @click="modalVuelos = false" class="text-slate-400 hover:text-slate-700">
          <i class="fas fa-xmark"></i>
        </button>
      </div>

      <div class="p-5 overflow-y-auto">
        <!-- La ayuda va plegada: quien ya sabe el formato no la quiere delante cada vez, y quien
             no lo sabe necesita que el resumen de arriba ya le diga lo esencial. -->
        <details class="mb-3 text-[11px]">
          <summary class="cursor-pointer font-bold text-slate-600">
            Una lista de reservas, cada una con su <code class="font-mono">pnr</code> y sus vuelos
            <span class="text-slate-400">— ver detalle</span>
          </summary>
          <div class="mt-2 text-slate-500 leading-relaxed space-y-1">
            <p><b>Sólo se tocan los PNR que traes</b>, pero de cada uno se declara TODO: sus
              vuelos se <b>reemplazan</b> por los de la lista. Si mandas un PNR con la ida y te
              olvidas la vuelta, esa vuelta deja de ser suya. Ningún vuelo se borra de la base —se
              queda sin nadie y se avisa—, pero el pasajero deja de tenerlo.</p>
            <p><b>Esto NO crea PNR.</b> Un localizador que no exista ya en el expediente se avisa y
              se salta: <code class="font-mono">«BONT3N no existe en el expediente: no se crea.»</code>
              Los PNR los crea el <b>padrón</b> (el Excel), así que el orden es padrón primero y
              vuelos después. Crear uno desde aquí convertiría una errata de tecleo en un grupo sin
              pasajeros. Para renombrar uno provisional usa <code class="font-mono">pnr_nuevo</code>.</p>
            <p><b>Un vuelo es un <code class="font-mono">leg</code>.</b> Una conexión son dos vuelos,
              cada uno con su número; lo que los une es que comparten PNR.</p>
            <p><code class="font-mono">emitido: false</code> marca la reserva pagada y sin billete.</p>
            <p><b>Ensaya siempre antes.</b> El informe dice qué cambiaría y, sobre todo, de qué
              vuelos dejaría de viajar cada PNR.</p>
          </div>
        </details>

        <div class="flex items-center justify-between mb-1">
          <label class="text-[10px] font-black text-slate-500 uppercase tracking-widest">JSON</label>
          <div class="flex items-center gap-3">
            <!-- 🔥 Lo que de verdad se hace no es escribir el JSON de cero: es CORREGIR un horario
                 cuando la aerolínea reprograma. Descargar lo que hay, cambiar la línea y volver a
                 cargarlo es el camino corto — y de paso el formato se aprende leyendo el propio. -->
            <button v-if="(file?.vuelos ?? []).length" @click="descargarVuelos"
                    class="text-[10px] font-bold text-slate-500 hover:text-slate-700 hover:underline">
              <i class="fas fa-download mr-1"></i>Descargar lo que hay
            </button>
            <button @click="jsonVuelos = EJEMPLO_VUELOS" class="text-[10px] font-bold text-sky-600 hover:underline">
              <i class="fas fa-file-code mr-1"></i>Pegar un ejemplo
            </button>
          </div>
        </div>
        <!-- El placeholder ES el ejemplo completo: se ve la forma exacta antes de escribir nada, y
                     «Pegar un ejemplo» lo mete de verdad para editarlo encima. -->
        <textarea v-model="jsonVuelos" rows="16" spellcheck="false"
                  :placeholder="EJEMPLO_VUELOS"
                  class="w-full font-mono text-[11px] border border-slate-200 rounded-xl p-3 focus:ring-2 focus:ring-sky-200 focus:border-sky-400"></textarea>

        <!-- El informe del ensayo -->
        <div v-if="ensayoVuelos" class="mt-4 border border-slate-200 rounded-xl p-4 bg-slate-50/60">
          <p class="text-[10px] font-black uppercase tracking-widest mb-2"
             :class="ensayoVuelos.problemas.length ? 'text-red-600' : 'text-slate-500'">
            {{ ensayoVuelos.problemas.length ? 'No se puede cargar' : 'Ensayo' }}
            <span v-if="ensayoVuelos.expediente" class="text-slate-700">· {{ ensayoVuelos.expediente }}</span>
          </p>

          <p v-if="!ensayoVuelos.hayCambios && !ensayoVuelos.problemas.length"
             class="text-[11px] font-bold text-emerald-600">
            <i class="fas fa-check-circle mr-1"></i>Nada que cambiar: coincide con lo que ya había.
          </p>

          <ul v-if="ensayoVuelos.cambios.length" class="text-[11px] font-mono text-slate-700 space-y-0.5 mb-2">
            <li v-for="(c, i) in ensayoVuelos.cambios" :key="i">{{ c }}</li>
          </ul>

          <ul v-if="ensayoVuelos.avisos.length" class="text-[11px] text-amber-700 space-y-0.5 mb-2">
            <li v-for="(c, i) in ensayoVuelos.avisos" :key="i"><i class="fas fa-triangle-exclamation mr-1"></i>{{ c }}</li>
          </ul>

          <ul v-if="ensayoVuelos.problemas.length" class="text-[11px] text-red-600 font-bold space-y-0.5">
            <li v-for="(c, i) in ensayoVuelos.problemas" :key="i"><i class="fas fa-circle-xmark mr-1"></i>{{ c }}</li>
          </ul>
        </div>
      </div>

      <div class="px-5 py-3 border-t border-slate-200 flex items-center justify-end gap-2">
        <button @click="modalVuelos = false" class="px-4 py-2 text-xs font-bold text-slate-500 hover:text-slate-700">
          Cancelar
        </button>
        <button @click="ensayarVuelos" :disabled="cargandoVuelos || !jsonVuelos.trim()"
                class="bg-white border border-slate-200 text-slate-700 px-4 py-2 rounded-lg text-xs font-bold hover:bg-slate-50 disabled:opacity-40">
          <i class="fas mr-1" :class="cargandoVuelos ? 'fa-spinner fa-spin' : 'fa-eye'"></i>Ensayar
        </button>
        <!-- Aplicar sólo tras un ensayo con algo que hacer: obliga a ver el diff antes de escribir. -->
        <button @click="aplicarVuelos"
                :disabled="cargandoVuelos || !ensayoVuelos || !ensayoVuelos.hayCambios || ensayoVuelos.problemas.length > 0"
                class="bg-sky-600 text-white px-4 py-2 rounded-lg text-xs font-bold hover:bg-sky-700 disabled:opacity-40">
          <i class="fas fa-check mr-1"></i>Aplicar
        </button>
      </div>
    </div>
  </div>

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
