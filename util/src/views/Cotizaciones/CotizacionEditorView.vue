<script setup lang="ts">
import { ref, onMounted, computed, watch, onUnmounted, type DirectiveBinding } from 'vue';
import { useRoute, useRouter, onBeforeRouteLeave } from 'vue-router';
import { useVolverAtras } from '@/composables/useVolverAtras';
import { useCotizacionEditorStore } from '@/stores/cotizacion/cotizacionEditorStore';
import { useCotizacionFileStore } from '@/stores/cotizacion/fileStore';
import type { InformeCoherencia } from '@/types/operacionModel';
import { getUrls } from '@/services/apiClient';
import { thumbUrl } from '@/services/imageThumb';
import SearchableSelect from '@/components/SearchableSelect.vue';
import WysiwygEditor from '@/components/WysiwygEditor.vue';
import ResumenClasificacion from '@/components/cotizacion/ResumenClasificacion.vue';
import PlanOperacionModal from '@/components/operacion/PlanOperacionModal.vue';

// 🔥 IMPORTS DEL DATEPICKER Y MÁSCARAS
import { VueDatePicker } from '@vuepic/vue-datepicker';
import { usePantallaEstrecha } from '@/composables/usePantallaEstrecha';
import { mandaElSegmento } from '@/utils/componenteTipo';
import '@vuepic/vue-datepicker/dist/main.css';
import IMask from 'imask';
import {
  ESTADO_COTIZACION_CONFIG,
  getModoItemConfig,
  getEstadoComponenteConfig,
  getProcedenciaUI,
  getTipoNotaUI,
  getRolTarifaUI, Servicio, TarifaSnapshot, ImagenSnapshot, formatRangoEdad,
  CotServicio, CotSegmento, ComponenteCompleto, SnapshotItem, Segmento, OpcionUpgradeInterna, NotaSnapshot,
  MODALIDAD_CONFIG, CATEGORIA_CONFIG, enumOptions, clasificacionBadges, CLASIF_BADGE_CLASE,
  AudienciaDetalle, AUDIENCIA_DETALLE_CONFIG,
  type TarifaModalidadValue, type TarifaCategoriaValue
} from '@/types/cotizacionEditorModel';
// La etiqueta legible de cada Categoría Operativa vive con La Biblia, que es donde más se
// pinta. La LISTA de las que existen no sale de ahí sino de `catalogos.tiposComponente`,
// que es la que consulta `sinHorarioDeTipo()`: una sola fuente para qué hay, otra para
// cómo se rotula.
import { getTipoComponenteConfig } from '@/types/operacionModel';
import OrganizacionFormulario from '@/components/common/OrganizacionFormulario.vue';
import InfoTooltip from '@/components/common/InfoTooltip.vue';
import { proveedorVacio, type ProveedorWrite } from '@/types/organizacionModel';
import { usePermisosStore } from '@/stores/permisosStore';

defineProps<{
  fileId?: string;
  cotizacionId?: string;
}>();


/* El calendario se teletransporta al `body`: dentro del componente lo recorta el header
   pegajoso del editor, y en el móvil se centra porque no hay hueco donde desplegarlo. */
const { esEstrecha } = usePantallaEstrecha();

/**
 * ¿Está desplegado el bloque «Catálogo Maestro» de la ficha de servicio?
 *
 * Arranca abierto —es lo que se ve al entrar por primera vez— y **se recuerda**: quien lo
 * cierra es porque está en una tanda de prestadores y compradores, que viven al final de la
 * ficha, y cerrarlo de nuevo en cada uno de los 17 servicios sería peor que el scroll que
 * venía a evitar.
 *
 * `localStorage` y no el store: es una preferencia de quien mira, no un dato de la cotización.
 * Un `try` alrededor porque en modo privado de algunos navegadores escribir lanza, y perder la
 * preferencia no puede tumbar el editor.
 */
const CLAVE_CATALOGO = 'cotizacion.editor.catalogoAbierto';
const catalogoAbierto = ref(leerPreferencia());

function leerPreferencia(): boolean {
    try {
        return localStorage.getItem(CLAVE_CATALOGO) !== '0';
    } catch {
        return true;
    }
}

watch(catalogoAbierto, (abierto) => {
    try {
        localStorage.setItem(CLAVE_CATALOGO, abierto ? '1' : '0');
    } catch { /* sin persistencia: el editor sigue funcionando igual */ }
});

const isReporteOpen = ref(false);
const verEnSoles = ref(false);

const route = useRoute();
const router = useRouter();
const volverAtras = useVolverAtras();
const store = useCotizacionEditorStore();
const fileStore = useCotizacionFileStore();

// ============================================================================
// REVISAR CAMBIOS DE OPERACIÓN
//
// La generación automática sólo se dispara en la TRANSICIÓN a `confirmado`, y ocurre
// una vez: lo que se edite después no llega al Centro de Operaciones. Pero regenerar a
// ciegas tampoco vale —La Biblia guarda hora pactada, prestador y teléfono del recojo,
// que no están en la cotización—, así que se abre un panel con el diff y se aplica sólo
// lo aprobado. Ver docs/Operacion.md §3.5.
// ============================================================================
const planOperacionId = ref<string | null>(null);

/**
 * Chequeo de coherencia de ESTA cotización: ids puestos con su nombre vacío y demás huecos que
 * ninguna acción del editor produce.
 *
 * Va aquí y no sólo en un cron porque el momento en que aparecen es **al cargar catálogo**, y quien
 * lo carga está en esta pantalla.
 *
 * Dos pasos deliberados —mirar y luego decidir— en vez de un botón que repare de una: reparar
 * escribe en una cotización que puede estar enviada, y enseñar antes qué se va a tocar es lo que
 * convierte eso en una decisión en vez de en una sorpresa.
 */
const informeCoherencia = ref<InformeCoherencia | null>(null);
const revisandoCoherencia = ref(false);

const revisarCoherencia = async (reparar = false): Promise<void> => {
  const id = store.cotizacion?.id;
  if (!id || revisandoCoherencia.value) return;

  revisandoCoherencia.value = true;
  try {
    informeCoherencia.value = await fileStore.revisarCoherencia(id, reparar);
  } finally {
    revisandoCoherencia.value = false;
  }
};

const abrirPlanOperacion = () => {
  const id = String(store.cotizacion?.id ?? '').split('/').pop();
  if (id) planOperacionId.value = id;
};

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
  void permisos.cargar();

  const fileId = route.params.fileId as string;
  const cotizacionId = route.params.cotizacionId as string;

  if (fileId && cotizacionId) {
    store.inicializarEditor(fileId, cotizacionId, route.meta.modoCatalogo === true).then(() => {
      setTimeout(() => {
        watchActivo = true;
        isDirty.value = false;
      }, 1000);
    });
  } else {
    router.push(route.meta.modoCatalogo === true ? '/catalogo' : '/cotizacion');
  }
});

/**
 * Al detalle del expediente, que es donde se listan las fotos guardadas.
 *
 * No se abren desde aquí: cambiar de cotización sin salir del editor dejaría el `isDirty` y el
 * guarda de salida apuntando a la anterior.
 */
const irAExpediente = (): void => {
  const fileId = store.extractIdStr(store.fileActual?.id ?? '');
  if (fileId) router.push(`/cotizacion/${fileId}`);
};

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

  // 5. Flujo móvil de paneles: Detalle → Servicios → Cabecera
  if (window.innerWidth < 768 && nivelEditor.value !== 'cabecera') {
    nivelEditor.value = nivelEditor.value === 'detalle' ? 'servicios' : 'cabecera';
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
  // Vuelve a DONDE ESTABAS (La Biblia, el detalle del file, el dashboard…). Sólo si no hay
  // historial —entraste por un enlace directo— cae al destino fijo. Ver useVolverAtras.
  if (store.modoCatalogo) {
    volverAtras('/catalogo');
    return;
  }
  const fileId = route.params.fileId || store.fileActual?.id;
  volverAtras(fileId ? `/cotizacion/${fileId}` : '/cotizacion');
};

const handleGuardar = async (): Promise<boolean> => {
  // ⚠️ Se lee ANTES de guardar: al terminar, la cotización ya existe y no habría forma de saber
  // si este guardado la creó o sólo la actualizó.
  const eraNueva = route.params.cotizacionId === 'nueva';

  const guardado = await store.guardarCotizacion();

  // ⚠️ SÓLO SI DE VERDAD SE GUARDÓ. Antes se limpiaba siempre, así que tras un guardado
  // abortado —conflictos financieros, 422, red— el aviso de «tienes cambios sin guardar»
  // quedaba desarmado y el trabajo se perdía al navegar sin ninguna señal. El error se veía
  // una vez; la pérdida, nunca.
  if (guardado) {
    isDirty.value = false;

    // ⚠️ Tras CREAR hay que soltar la ruta «nueva», o la URL sigue apuntando a un formulario en
    // blanco sobre una cotización que ya existe.
    //
    // Sin esto: guardas —se crea la v1, correcta—, recargas la página y `inicializar()` ve
    // `cotizacionId === 'nueva'`, llama a `crearCotizacionVacia()` y **borra el árbol de la
    // pantalla**; encima calcula la versión como `maxVersion + 1`, así que el formulario vacío
    // aparece como v2. La v1 sigue guardada —se ve al salir al expediente— pero quien está
    // delante ve su trabajo desaparecer y una versión que no pidió.
    //
    // `replace` y no `push`: volver atrás a «nueva» llevaría al mismo sitio roto. Y como la vista
    // lee los parámetros sólo en `onMounted`, cambiar la URL no la remonta ni pierde el estado.
    if (eraNueva) {
      const id = store.extractIdStr(store.cotizacion?.id ?? '');

      if (id) {
        await router.replace({
          name: route.name ?? undefined,
          params: { ...route.params, cotizacionId: id },
          query: route.query,
        });
      }
    }
  }

  return guardado;
};

// URL de la vista pax (guía del cliente) de esta misma cotización/tour, para
// abrirla en una pestaña sin salir hasta el catálogo. En modo catálogo usa la
// ruta /catalogo/...; si no, /file/... Requiere localizador + versión.
const paxPreviewUrl = computed<string | null>(() => {
  const loc = store.fileActual?.localizador;
  const version = store.cotizacion?.version;
  if (!loc || !version) return null;
  const seg = store.modoCatalogo ? 'catalogo' : 'file';
  return `${getUrls().pax}/${seg}/${loc}/v/${version}`;
});

const abrirVistaPax = async () => {
  if (!paxPreviewUrl.value) return;
  // Guarda antes para que la guía refleje los cambios (el snapshot cliente se
  // regenera al guardar). Si hay cambios pendientes, guardamos primero.
  // Si el guardado no salió, no se abre la vista del cliente: enseñaría una versión vieja y
  // el operador creería que lo que ve es lo que hay.
  if (isDirty.value && !await handleGuardar()) {
    return;
  }

  window.open(paxPreviewUrl.value, '_blank', 'noopener');
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
      if (store.componenteActivo) store.componenteActivo.fechaHoraFin = isoString;
      store.onComponenteFechasChange();
    }
  }
};

const vStrictMask = {
  mounted(el: HTMLInputElement, binding: DirectiveBinding<(valor: string) => void>) {
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
      if (store.componenteActivo) store.componenteActivo.fechaHoraFin = isoString;
      store.onComponenteFechasChange();
    }
  }
};

const vDateMask = {
  mounted(el: HTMLInputElement, binding: DirectiveBinding<(valor: string) => void>) {
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

/** Id del servicio abierto ('' si no hay ninguno): evita el doble `!` en el template. */
const servicioActivoId = computed<string>(() => store.servicioActivo?.id ?? '');

/**
 * ¿El servicio entero se va al final del día?
 *
 * Sólo si **ninguno** de sus componentes incluidos tiene hora: basta uno con hora para que el
 * bloque se coloque por ella. Es la misma regla que ordena la guía del huésped —el escalón 0 son
 * los servicios con alguna hora, el 1 los que no— y por eso la etiqueta tiene que mirar al
 * servicio y no al componente que la enseña.
 *
 * ⚠️ Mira los INCLUIDOS: un «no incluido» es referencia para el cliente y no se despacha, así que
 * su hora no debería colocar el bloque.
 */
const servicioActivoSinHora = computed<boolean>(() => {
  const comps = store.servicioActivo?.cotcomponentes ?? [];

  return !comps.some((c) => c.modo !== 'no_incluido' && !c.sinHorario && !!c.fechaHoraInicio);
});

const cottarifasOrdenadas = computed<TarifaSnapshot[]>(() => {
  const cottarifas = store.componenteActivo?.cottarifas;
  if (!cottarifas) return [];
  return [...cottarifas].sort((a, b) => (a.grupoTarifa ?? Infinity) - (b.grupoTarifa ?? Infinity));
});

const calcularVentaTarifa = (tarifa: TarifaSnapshot): number => {
  const costoTotal = (parseFloat(String(tarifa.montoCosto)) || 0) * (tarifa.esGrupal ? 1 : (tarifa.cantidad || 1));
  const tieneOverride = tarifa.comisionOverrideSnapshot != null && tarifa.comisionOverrideSnapshot !== '';
  const comisionPct = tieneOverride
      ? parseFloat(String(tarifa.comisionOverrideSnapshot))
      : (parseFloat(String(store.cotizacion?.comision ?? '0')) || 0);
  return costoTotal * (1 + comisionPct / 100);
};

/**
 * Etiqueta de un maestro en los desplegables. El fallback a `nombre` es
 * histórico: algunos endpoints antiguos lo devolvían en vez de `nombreInterno`.
 */
const etiquetaMaestro = (
  m: { nombreInterno?: string | null; nombre?: string | null },
  fallback: string,
): string => m.nombreInterno || m.nombre || fallback;

const opcionesServicios = computed(() => {
  return store.catalogos.servicios
      .map((s: Servicio) => ({
        value: store.extractIdStr(s.id || s['@id']),
        label: etiquetaMaestro(s, 'Servicio sin nombre')
      }))
      .sort((a, b) => a.label.localeCompare(b.label, 'es'));
});

/**
 * El nombre operativo que trae el MAESTRO del componente activo, si lo hay.
 *
 * Se usa para dos cosas: enseñarlo como pista bajo el campo, y **reponerlo si el operador vacía
 * el campo** (`reponerNombreDelCatalogoSiQuedoVacio`). Lo segundo hace falta porque el resolutor
 * ya no consulta el catálogo: un campo vacío cae al título público, no al maestro.
 */
const nombreMaestroDelComponenteActivo = computed<string | null>(() => {
  const comp = store.componenteActivo;
  if (!comp?.componenteMaestroId) return null;

  const maestroId = store.extractIdStr(comp.componenteMaestroId);
  const maestro = store.catalogos.allComponentes.find(c => store.extractIdStr(c) === maestroId);
  const nombre = maestro?.nombreInterno ?? '';

  return nombre && nombre !== 'Sincronizando...' ? nombre : null;
});

/**
 * Vaciar el campo = volver al nombre del catálogo, no dejarlo sin nombre.
 *
 * El resolutor de La Biblia ya no consulta el maestro —la ruta del nombre es una sola— así que un
 * campo vacío no «hereda»: cae al título público. Reponer aquí es lo que mantiene cierta la
 * promesa que el operador lee bajo el campo, y deja el snapshot siempre con valor.
 *
 * En `blur` y no en `input`: en `input` no se podría ni borrar para reescribir.
 */
/**
 * Enciende o apaga «Nombrarlo al cliente», pidiendo confirmación **sólo al encender**.
 *
 * Revelar quién opera un servicio es una decisión comercial: le dice al cliente a quién podría
 * contratar directamente el año que viene. Apagarlo no tiene ese riesgo, así que la fricción va
 * únicamente en la dirección que la merece — poner el mismo diálogo en las dos enseña a darle a
 * «Aceptar» sin leer, que es como se pierde la protección entera.
 *
 * El nombre sale del catálogo vivo, no del snapshot: es el que el cliente va a leer.
 */
const alternarNombrarPrestador = (e: Event): void => {
  const comp = store.componenteActivo;
  const marcado = (e.target as HTMLInputElement).checked;

  if (!comp) return;

  if (!marcado) {
    comp.prestadorVisible = false;

    return;
  }

  const quien = comp.prestadorNombreSnapshot || 'este proveedor';

  if (window.confirm(`El cliente verá que «${quien}» opera este servicio. ¿Lo nombramos?`)) {
    comp.prestadorVisible = true;

    return;
  }

  // Rechazado: el input ya se pintó marcado, así que hay que devolverlo. Reasignar la misma
  // propiedad no basta —Vue no ve cambio— y por eso se toca el DOM directamente.
  (e.target as HTMLInputElement).checked = false;
  comp.prestadorVisible = false;
};

const reponerNombreDelCatalogoSiQuedoVacio = (): void => {
  const comp = store.componenteActivo;
  if (!comp || (comp.nombreInternoSnapshot ?? '').trim() !== '') return;

  comp.nombreInternoSnapshot = nombreMaestroDelComponenteActivo.value || null;
};

const opcionesComponentes = computed(() => {
  return store.catalogos.componentes
      .map(c => ({
        value: store.extractIdStr(c),
        label: c.nombreInterno || 'Insumo sin nombre'
      }))
      .sort((a, b) => a.label.localeCompare(b.label, 'es'));
});

/**
 * Ubicaciones para el selector de un componente MANUAL.
 *
 * Del vocabulario entero de `TravelLugar`, sin filtrar por nada: quien teclea un traslado a un
 * fundo elige la ciudad desde la que se opera, y acotar la lista a «los lugares que ya usa el
 * catálogo» escondería justo la que hace falta.
 */
const opcionesLugares = computed(() =>
    store.catalogos.lugares
        .map(l => ({ value: l.id, label: l.nombre }))
        .sort((a, b) => a.label.localeCompare(b.label, 'es'))
);

const opcionesTarifas = computed(() => {
  return store.catalogos.tarifas
      .map(t => ({
        value: store.extractIdStr(t),
        label: store.getTarifaLabel(t),
        sublabel: store.getTarifaSublabel(t),
      }))
      .sort((a, b) => a.label.localeCompare(b.label, 'es'));
});

// ── Filtro BLANDO del selector de TARIFAS MAESTRAS por prestador ─────────────
//
// Si el componente (o su día) fija prestador, el buscador de tarifas arranca
// mostrando sólo las de ese proveedor. Evita el error más caro de cotizar: colgar
// de un componente la tarifa de otro proveedor, que no se nota hasta que hay que
// pedirle el servicio a alguien que nunca la cotizó.
//
// Es ayuda, nunca validación — las mismas tres reglas que el filtro de proveedores:
//  · Se desactiva con un clic. Prestador ≠ proveedor comercial (compras al
//    consolidador lo que opera otro), y un filtro duro haría esa tarifa
//    inseleccionable.
//  · Si ninguna tarifa del catálogo es de ese prestador, no se filtra nada:
//    dejar la lista vacía sería dejar al operador sin salida.
//  · La tarifa YA asignada nunca se esconde, aunque no case (ver abajo).
const verTodasLasTarifas = ref(false);

/** Ids de las tarifas del catálogo que pertenecen al prestador. `null` = no filtrar. */
const idsTarifasDelPrestador = computed<Set<string> | null>(() => {
  const esperado = prestadorParaFiltro.value;
  if (verTodasLasTarifas.value || !esperado?.maestroId) return null;

  // El proveedor ya no es de la tarifa sino del COMPONENTE maestro, así que el filtro
  // resuelve a través de él: se toman los componentes de ese proveedor y se juntan sus
  // tarifas. Antes miraba `tarifa.proveedor`, que ya no existe.
  const ids = new Set(
      store.catalogos.allComponentes
          .filter(c => 'proveedor' in c && store.extractIdStr(c.proveedor) === esperado.maestroId)
          .flatMap(c => ('tarifas' in c ? c.tarifas ?? [] : []))
          .map(t => store.extractIdStr(t))
  );

  return ids.size ? ids : null;
});

const opcionesTarifasFiltradas = computed(() => {
  const ids = idsTarifasDelPrestador.value;
  if (!ids) return opcionesTarifas.value;

  // 🔥 La tarifa ya seleccionada se conserva SIEMPRE en la lista. SearchableSelect
  // deriva la etiqueta visible de `options.find(o => o.value === modelValue)`: si la
  // opción elegida desaparece, el campo pinta el placeholder como si no hubiera nada
  // asignado. El dato seguiría ahí, pero la pantalla estaría mintiendo.
  const seleccionada = store.extractIdStr(store.tarifaActiva?.tarifaMaestraId);

  return opcionesTarifas.value.filter(
      o => (o.value !== '' && ids.has(String(o.value))) || o.value === seleccionada
  );
});

/**
 * La ficha del catálogo detrás de la tarifa activa, para pintar procedencia y rango
 * de edad junto al selector.
 *
 * Estaba resuelto en la plantilla con un `v-for` sobre un array de un solo elemento
 * —el truco clásico para tener una variable local en el template—. Funcionaba, pero
 * el `find()` se re-ejecutaba en cada render y sin `key` Vue no podía reutilizar los
 * nodos. Como computed se cachea y se lee de un vistazo.
 */
/**
 * «Sin restricción» no decía de QUÉ, y era la pregunta obvia al verlo.
 *
 * La procedencia es la NACIONALIDAD del pasajero: hay tarifas que sólo valen para peruanos
 * (precio local), otras para extranjeros, otras para la CAN. Sin valor = vale para todos, que
 * es el caso normal — y decirlo en positivo evita leerlo como si faltara un dato.
 */
/** Modalidad y categoría de una ficha de tarifa, listas para pintar. */
const getModalidadTarifaUI = (t: { modalidad?: string | null } | null) => {
  const v = t?.modalidad as TarifaModalidadValue | null | undefined;
  return v ? (MODALIDAD_CONFIG[v] ?? null) : null;
};

const getCategoriaTarifaUI = (t: { categoria?: string | null } | null) => {
  const v = t?.categoria as TarifaCategoriaValue | null | undefined;
  return v ? (CATEGORIA_CONFIG[v] ?? null) : null;
};

const etiquetaProcedencia = (procedencia?: string | null): string =>
    procedencia ? getProcedenciaUI(procedencia).label : 'Toda nacionalidad';

const ayudaProcedencia = (procedencia?: string | null): string =>
    procedencia
        ? `Tarifa sólo para pasajeros de procedencia: ${getProcedenciaUI(procedencia).label}`
        : 'Sin restricción de nacionalidad: vale para cualquier pasajero';

const tarifaMaestraDeActiva = computed(() => {
  const buscada = store.extractIdStr(store.tarifaActiva?.tarifaMaestraId);
  if (!buscada) return null;

  // 🔥 Se busca en TODO el catálogo, no sólo en `catalogos.tarifas`.
  //
  // Ése tiene las tarifas del componente maestro que está abierto, y la tarifa asignada
  // puede ser de otro —el filtro por prestador es blando y se salta a propósito—. Al
  // abrir una cotización guardada eso dejaba las pastillas en blanco: el dato estaba
  // guardado, pero la ficha del catálogo no aparecía y la pantalla no pintaba nada.
  type FichaTarifa = (typeof store.catalogos.tarifas)[number];

  const enElActual = store.catalogos.tarifas.find(t => store.extractIdStr(t) === buscada);
  if (enElActual) return enElActual;

  for (const c of store.catalogos.allComponentes) {
    const tarifas = ('tarifas' in c ? c.tarifas ?? [] : []) as FichaTarifa[];
    const hallada = tarifas.find(t => store.extractIdStr(t) === buscada);
    if (hallada) return hallada;
  }

  return null;
});

/** El proveedor de la tarifa elegida, resuelto a través de su componente maestro. */
const proveedorDeTarifaActiva = computed(() =>
    store.getProveedorDeTarifa(store.tarifaActiva?.tarifaMaestraId));

/**
 * ⚠️ Lo que la tarifa elegida dice y NO coincide con lo que ya tiene la línea.
 *
 * **Sólo avisa, nunca bloquea.** Hay razones legítimas —le compras al consolidador lo que
 * opera otro— y un candado dejaría esa tarifa inseleccionable. Pero es también el error más
 * caro de cotizar cuando es un despiste, porque no se nota hasta que hay que pedirle el
 * servicio a alguien que nunca lo cotizó. Por eso se avisa y se deja pasar.
 *
 * Compara los TRES papeles, no sólo el prestador: el comprador decide a nombre de quién sale
 * la Orden de Servicio, así que mezclarlo sin querer manda el encargo a otra empresa.
 *
 * Devuelve la lista de desajustes; vacía significa que encaja o que no hay con qué comparar
 * —la primera tarifa siembra la línea, así que nunca desajusta consigo misma—.
 */
const desajustesDeTarifa = computed<string[]>(() => {
  const papeles = store.getPapelesDeTarifa(store.tarifaActiva?.tarifaMaestraId);
  const linea = store.componenteEnEdicion;

  if (!papeles || !linea) return [];

  const distinto = (deLaTarifa: string | null, deLaLinea: string | null | undefined): boolean =>
      !!deLaTarifa && !!store.extractIdStr(deLaLinea ?? null) && store.extractIdStr(deLaLinea ?? null) !== deLaTarifa;

  const avisos: string[] = [];

  if (distinto(papeles.prestadorMaestroId, linea.prestadorMaestroId)) {
    avisos.push(`prestador ${papeles.prestadorNombreSnapshot || 'distinto'}, y la línea tiene ${linea.prestadorNombreSnapshot || 'otro'}`);
  }

  if (distinto(papeles.prestadorServicioMaestroId, linea.prestadorServicioMaestroId)) {
    avisos.push(`servicio ${papeles.prestadorServicioNombreSnapshot || 'distinto'}, y la línea tiene ${linea.prestadorServicioNombreSnapshot || 'otro'}`);
  }

  if (distinto(papeles.compradorMaestroId, linea.compradorMaestroId)) {
    avisos.push(`comprador ${papeles.compradorNombreSnapshot || 'distinto'}, y la línea tiene ${linea.compradorNombreSnapshot || 'otro'} — la Orden saldría a ese nombre`);
  }

  return avisos;
});

/** El aviso ya redactado, o null si no hay nada que avisar. */
const tarifaDeOtroProveedor = computed<string | null>(() =>
    desajustesDeTarifa.value.length ? desajustesDeTarifa.value.join('; ') : null);

const filtroTarifasActivo = computed(() =>
    !!idsTarifasDelPrestador.value
    && opcionesTarifasFiltradas.value.length < opcionesTarifas.value.length
);

const opcionesProveedores = computed(() => {
  return store.catalogos.proveedores
      .map(p => ({
        value: store.extractIdStr(p),
        label: p.nombreComercial || 'Sin nombre'
      }))
      .sort((a, b) => a.label.localeCompare(b.label, 'es'));
});


const prestadorParaFiltro = computed(() => store.prestadorEsperadoDeTarifaActiva);

// Sólo para pintar: quien decide es el #[IsGranted] del endpoint. Ver el store.
const permisos = usePermisosStore();

/** Alta de prestador sin salir del editor. */
const altaPrestadorAbierta = ref(false);
const guardandoPrestador = ref(false);
const formPrestador = ref<ProveedorWrite>(proveedorVacio());

const abrirAltaPrestador = (): void => {
  formPrestador.value = proveedorVacio();
  altaPrestadorAbierta.value = true;
};

const guardarAltaPrestador = async (): Promise<void> => {
  guardandoPrestador.value = true;
  const ok = await store.crearPrestadorYAsignar(formPrestador.value);
  guardandoPrestador.value = false;

  if (ok) altaPrestadorAbierta.value = false;
};

/** A quién se le encarga la compra, ya resuelta la cascada `componente → prestador`. */
const compradorResuelto = computed(() => {
  const comp = store.componenteEnEdicion;

  return comp ? store.resolverComprador(comp) : null;
});

const prestadorComponenteResuelto = computed(() => {
  const comp = store.componenteActivo;
  if (!comp) return null;

  const servicio = store.servicioActualDeComponente;
  if (!servicio) return null;

  // Ya no hace falta elegir tarifa de referencia: el tercer peldaño de la cascada lee
  // el proveedor del propio componente. Ver resolverPrestador().
  const p = store.resolverPrestador(comp);

  return p ? { nombre: p.nombre, maestroId: p.maestroId } : null;
});

const opcionesPlantillas = computed(() => {
  return store.catalogos.plantillasItinerario
      .map(p => ({
        value: store.extractIdStr(p),
        label: etiquetaMaestro(p, 'Plantilla sin nombre')
      }))
      .sort((a, b) => a.label.localeCompare(b.label, 'es'));
});

const formatFecha = (fecha?: string | null) => {
  if (!fecha) return '--';
  return new Date(fecha).toLocaleDateString('es-PE', { weekday: 'long', day: '2-digit', month: 'short', timeZone: 'UTC' });
};

const formatMoneda = (monto?: number | string, moneda?: string) => {
  const num = typeof monto === 'string' ? parseFloat(monto) : (monto ?? 0);
  return `${moneda === 'USD' ? '$' : 'S/'} ${num.toFixed(2)}`;
};

const formatMonedaPanel = (monto?: number | string, moneda?: string) => {
  const num = typeof monto === 'string' ? parseFloat(monto) : (monto ?? 0);
  const monedaBase = moneda ?? store.cotizacion?.monedaGlobal ?? 'USD';
  // El resumen financiero siempre normaliza montos a USD.
  // Hay que convertir cuando la cotización es en PEN, o cuando el switch está activo.
  const convertirASoles = monedaBase === 'PEN' || (verEnSoles.value && monedaBase !== 'PEN');
  if (convertirASoles) {
    const tc = parseFloat(String(store.cotizacion?.tipoCambio || 1));
    return `S/ ${(num * tc).toFixed(2)}`;
  }
  return formatMoneda(num, monedaBase);
};

// Dónde recoge y dónde deja el servicio, listo para pintar.
//
// El dato lo calcula el backend (`CotizacionPuntosDelServicio`); aquí sólo se redacta la línea.
// `aplica: false` significa que el servicio no recoge ni deja a nadie —un alojamiento, un ticket,
// una comida— y entonces NO se pinta nada: un aviso en la mitad de la cotización haría que el
// aviso dejara de significar algo.
const puntosDeServicio = (servicio: CotServicio): { texto: string; completo: boolean } | null => {
  const p = store.puntosDeServicio(servicio.id);
  if (!p || !p.aplica) return null;

  const desde = p.inicio.texto ?? 'sin punto de recojo';
  const hasta = p.tieneFin ? (p.fin.texto ?? 'sin punto de entrega') : 'sólo presentación';

  return { texto: `${desde} → ${hasta}`, completo: p.completo };
};

const formatRangoServicio = (servicio: CotServicio) => {
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

  servicio.cotcomponentes.forEach((c) => {
    const reqHora = !c.sinHorario;

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

// ── Qué cuelga de cada segmento, y qué se lleva su papelera ─────────────────
//
// El dato ya está en memoria: los componentes cuelgan del SERVICIO y apuntan al segmento con un
// ManyToOne opcional, así que basta filtrar. Se usa el helper del store y no un `===` a mano
// porque el vínculo llega unas veces como IRI y otras como id plano.
/**
 * Cómo se rotula un componente en las listas y cabeceras del editor.
 *
 * ⚠️ **En un traslado, un tren o un vuelo, el nombre del componente NO distingue la ida de la
 * vuelta**, y ésa es justo la pregunta del operador cuando va a ponerles hora. El catálogo carga
 * esos tres tipos como **un componente por ruta, no por sentido** —«Transporte Aeropuerto Lima ↔
 * Miraflores (ida o vuelta)»—, así que las dos líneas de una escala se llamaban exactamente igual
 * y había que abrirlas y deducirlo por la hora ya puesta. Quien sí dice el sentido es el segmento.
 *
 * `mandaElSegmento()` es la misma regla que ya aplicaban la guía del pasajero y La Biblia; el
 * editor era la única pantalla de las cuatro que no la tenía. Ver `@/utils/componenteTipo`.
 *
 * ⚠️ **No sustituye al nombre del maestro en todas partes.** Donde el rótulo dice «Insumo Maestro»
 * o cuelga una tarifa, lo pertinente sigue siendo el componente: la tarifa es del componente
 * bidireccional y es la misma para los dos sentidos.
 */
const etiquetaDeComponente = (comp: ComponenteCompleto | null | undefined): string => {
  if (!comp) return getNombreMaestroRef(comp);

  if (!mandaElSegmento(comp.tipo)) return getNombreMaestroRef(comp);

  // ⚠️ Por el store: `servicioActivo` es null dentro del inspector del componente, así que un
  // resolutor local que lo usara se apagaba justo en la pantalla que lo necesita.
  const seg = store.segmentoDeComponente(comp);
  const delSegmento = store.getI18nText(seg?.tituloSnapshot, store.cotizacion?.idiomaEdicion || 'es');

  return delSegmento || getNombreMaestroRef(comp);
};

/**
 * La tarjeta de identificadores tiene dos caras, y cuál va delante lo decide el TIPO.
 *
 * Delante va **lo que de verdad lee alguien**: el título que ve el cliente y el nombre con el que
 * se despacha al proveedor. Detrás, lo secundario. En transporte, tren y vuelo eso significa el
 * SEGMENTO delante y el componente detrás; en el resto, al revés.
 *
 * ⚠️ **Es el mismo intercambio que hace el backend**, no una decisión de pantalla:
 * `OperacionOrdenServicioItem::getSecundarioParaProveedor()` pone atrás «el que NO subió». Si esa
 * regla cambia, esta tarjeta enseñaría delante lo que la orden manda detrás.
 *
 * ⚠️ **Y lo de atrás NO es basura técnica.** El nombre interno del componente se imprime en la
 * orden como segunda línea del encargo: la cara trasera es la del proveedor, no un cajón de
 * referencia. Por eso se rotula por quién lo lee y no como «datos internos».
 */
const traseraAbierta = ref(false);

/**
 * ¿Cuelga de un párrafo? Sin él no hay dos caras que intercambiar.
 *
 * ⚠️ **Un componente MANUAL de tipo transporte existe y no tiene párrafo** —medidos 2 en
 * producción el 31/08/2026, más 3 de ticket variable—. El tipo decía «manda el segmento» y la
 * tarjeta abría por una cara que sólo podía disculparse: «no cuelga de ningún párrafo, voltea».
 * Un clic obligatorio para llegar a lo único que había.
 */
const tieneParrafo = computed(() => store.segmentoDeComponente(store.componenteActivo) !== null);

/**
 * ¿Se están viendo los campos del COMPONENTE, estén delante o detrás?
 *
 * ⚠️ **Sin párrafo manda siempre el componente, sea del tipo que sea.** Es la misma cascada del
 * backend, que no pregunta sólo por el tipo:
 *
 *     if ($this->mandaElSegmento() && $segmento !== '') { return $segmento; }
 *     return $componente ?: …
 *
 * La segunda condición es la que faltaba aquí. `mandaElSegmento()` dice quién manda **cuando hay
 * los dos**; no promete que el segmento exista.
 */
/**
 * ¿El título público del COMPONENTE llega al cliente?
 *
 * No cuando manda el párrafo: el itinerario y el «qué incluye» toman el suyo. Pero **sí en cuanto
 * no hay párrafo**, aunque el tipo sea transporte — y ahí volver a marcarlo obligatorio no es un
 * detalle: sin él la línea saldría sin nombre en la propuesta.
 */
const elComponenteSePublica = computed(
    () => !tieneParrafo.value || !mandaElSegmento(store.componenteActivo?.tipo)
);

const mostrandoComponente = computed(() => {
  if (!tieneParrafo.value) return true;

  const mandaSeg = mandaElSegmento(store.componenteActivo?.tipo);

  return mandaSeg ? traseraAbierta.value : !traseraAbierta.value;
});

// Cambiar de componente vuelve a la cara de delante: la trasera es una consulta puntual, y
// dejarla pegada haría que el siguiente se abriera por su cara secundaria sin motivo.
watch(() => store.componenteActivo?.id, () => { traseraAbierta.value = false; });

const componentesDeSegmento = (segmentoId: string): ComponenteCompleto[] =>
  (store.servicioActivo?.cotcomponentes ?? []).filter(
    (c: ComponenteCompleto) => store.idSegmentoDeComponente(c) === segmentoId
  );

const segmentoConfirmandoBorrado = ref<string | null>(null);
let tiempoConfirmarBorrado: ReturnType<typeof setTimeout> | undefined;

/**
 * La papelera del segmento se lleva MUCHO más de lo que aparenta.
 *
 * `removerCotSegmento()` quita también todos sus componentes, y al guardar el `orphanRemoval` los
 * borra de verdad: con ellos se van sus tarifas y, por la FK en cascada de `OperacionServicio`,
 * su fila del cuadro de tráfico con su historial de estados. La orden ya emitida sobrevive
 * —guarda el id como texto, no como FK—, pero lo vivo no.
 *
 * Por eso pide una segunda pulsación **sólo cuando hay algo que perder**: montar un itinerario
 * es borrar muchos segmentos vacíos seguidos, y cobrar dos clics por cada uno enseñaría a
 * pulsar dos veces sin leer, que es justo lo contrario de lo que se busca.
 */
const pedirBorrarSegmento = (segmentoId: string): void => {
  const cuantos = componentesDeSegmento(segmentoId).length;

  if (cuantos === 0 || segmentoConfirmandoBorrado.value === segmentoId) {
    clearTimeout(tiempoConfirmarBorrado);
    segmentoConfirmandoBorrado.value = null;
    store.removerCotSegmento(segmentoId);
    return;
  }

  segmentoConfirmandoBorrado.value = segmentoId;
  clearTimeout(tiempoConfirmarBorrado);
  tiempoConfirmarBorrado = setTimeout(() => { segmentoConfirmandoBorrado.value = null; }, 4000);
};

/**
 * Un contenedor lo es porque TIENE ítems, no porque le falte el nombre.
 *
 * ⚠️ Antes era sólo «no tiene nombre», y eso cerraba un bucle sin salida: el componente nace con
 * `tituloSnapshot: []`, así que salía marcado como contenedor y su input de nombre aparecía
 * deshabilitado — **para poder escribir el nombre hacía falta que ya tuviera nombre**. La única
 * salida era elegir un insumo maestro, que además pisa el `tipo` con el suyo, y como el
 * desplegable sólo ofrece el pool del servicio, todo componente hecho a mano acababa siendo
 * «pool».
 *
 * Con los ítems en la condición, un componente recién creado —sin nombre y sin ítems— es
 * simplemente un componente vacío, y se le puede escribir el nombre.
 */
const isComponenteSoloItems = (componente: ComponenteCompleto) => {
  const sinNombre = !componente.tituloSnapshot || componente.tituloSnapshot.length === 0;

  return sinNombre && (componente.snapshotItems?.length ?? 0) > 0;
};

const extractIdStrView = (val: unknown): string => val ? String(val).split('/').pop() ?? '' : '';

const getNombreMaestroRef = (comp: ComponenteCompleto | null | undefined): string => {
  if (!comp) return 'Insumo sin seleccionar';

  // ── Sin maestro manda SU nombre ──────────────────────────────────────────
  // Un componente hecho a mano no tiene maestro y no va a tenerlo: rotularlo «Insumo sin
  // seleccionar» decía que faltaba un paso que no existe, y dejaba la cabecera contradiciendo al
  // nombre que el operador acababa de escribir dos líneas más abajo. Sólo cuando tampoco hay
  // nombre propio queda el aviso, que ahí sí describe lo que pasa.
  const targetId = extractIdStrView(comp.componenteMaestroId);

  if (!targetId) {
    return store.getI18nText(comp.tituloSnapshot, store.cotizacion?.idiomaEdicion || 'es')
      || 'Insumo sin seleccionar';
  }

  const c = store.catalogos.allComponentes.find((cat) => store.extractIdStr(cat) === targetId);

  if (c && c.nombreInterno !== 'Sincronizando...') return c.nombreInterno || 'Insumo Genérico';

  if (c && c.nombreInterno === 'Sincronizando...') {
    const snapshotName = store.getI18nText(comp.tituloSnapshot, store.cotizacion?.idiomaEdicion || 'es');
    return snapshotName ? snapshotName : 'Sincronizando...';
  }

  store.fetchComponenteMaestroSilencioso(targetId as string);

  const snapshotName = store.getI18nText(comp.tituloSnapshot, store.cotizacion?.idiomaEdicion || 'es');
  return snapshotName ? snapshotName : 'Sincronizando...';
};

// Nombre de tarifa / estándar de una alternativa: interno primero (genérico pero
// siempre presente), luego el título público.
const tarifaLabelAlt = (o: OpcionUpgradeInterna) =>
    o.tarifaNombreInterno || store.getI18nText(o.tarifaTitulo, store.cotizacion?.idiomaEdicion || 'es');
const estandarLabelAlt = (o: OpcionUpgradeInterna) =>
    o.estandarNombreInterno || store.getI18nText(o.estandarTitulo, store.cotizacion?.idiomaEdicion || 'es');

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
    activeAccordion.value = store.servicioActivo?.cotsegmentos?.length ? 'parrafos' : 'pool';
  }
});

const poolFiltrado = computed(() => {
  if (!filtroSegmentos.value) return store.catalogos.poolSegmentos;
  const q = filtroSegmentos.value.toLowerCase();
  return store.catalogos.poolSegmentos.filter((seg) => {
    const code = (seg.nombreInterno || '').toLowerCase();
    const title = store.getI18nText(seg.titulo, store.cotizacion?.idiomaEdicion || 'es').toLowerCase();
    return code.includes(q) || title.includes(q);
  });
});

// ============================================================================
// 🔥 ORDENAMIENTO DE SEGMENTOS AGRUPADOS (Vista Storytelling)
// ============================================================================
/**
 * Cómo se nombra un párrafo en los desplegables que sirven para COLOCARLO.
 *
 * El operativo primero: es el que identifica el tramo. El título es prosa de cliente y dos
 * párrafos seguidos pueden titularse casi igual —lo que se busca aquí es cuál es cuál, no cómo
 * suena—. Cae al título para los párrafos escritos a mano, que no tienen nombre interno.
 */
const etiquetaDeParrafo = (cotSeg: CotSegmento): string => {
  const lang = store.cotizacion?.idiomaEdicion || 'es';

  // ⚠️ El operativo en `es` y no en `lang`: no lleva `#[AutoTranslate]`, así que en una
  // cotización que se edita en inglés `getI18nText(..., 'en')` devolvía '' y el desplegable caía
  // al título — justo el dato que este helper dice no querer usar.
  return store.getI18nText(cotSeg.nombreInternoSnapshot, 'es')
      || store.getI18nText(cotSeg.tituloSnapshot, lang)
      || 'Sin título';
};

const segmentosOrdenadosVisualmente = computed(() => {
  const segmentos = store.servicioActivo?.cotsegmentos;
  if (!segmentos) return [];
  return [...segmentos].sort((a, b) => {
    if (a.dia !== b.dia) return a.dia - b.dia;
    return (a.orden || 0) - (b.orden || 0);
  });
});

/**
 * Modo REORDENAR: las fichas se colapsan a su título y aparece el asa de arrastre.
 *
 * Es un modo y no un asa siempre visible por dos razones. La primera es que **reordenar y editar
 * son tareas distintas**: con la ficha entera delante, el gesto de arrastrar compite con el de
 * abrir. La segunda es que colapsar deja el día entero en pantalla, y no se puede ordenar lo que
 * no se ve junto.
 */
/**
 * La hora que coloca al servicio, o null si no tiene ninguna. **Espejo de
 * `getHoraClaveServicio()` en el store**, que es la que decide el orden — aquí sólo se enseña.
 *
 * Se muestra en el modo reordenar porque es **el dato que el arrastre puede contradecir**, y una
 * contradicción hay que verla al hacerla, no descubrirla tres pantallas después.
 */
const horaClaveDeServicio = (srv: CotServicio): string | null => {
  const horas = (srv.cotcomponentes ?? [])
      .filter(c => !c.sinHorario && !!c.fechaHoraInicio)
      .map(c => (c.fechaHoraInicio as string).slice(11, 16))
      .sort();

  return horas[0] ?? null;
};

/** La fecha del día en modo reordenar, o null. Uno cada vez: ordenar dos a la vez no es una tarea. */
const diaEnReorden = ref<string | null>(null);
const dragServicioId = ref<string | null>(null);
const dragOverServicioId = ref<string | null>(null);

const onServicioDragStart = (id: string) => { dragServicioId.value = id || null; };

const onServicioDrop = (destinoId: string) => {
  const origen = dragServicioId.value;
  dragServicioId.value = null;
  dragOverServicioId.value = null;

  // ⚠️ `reordenarServicios` devuelve false cuando el destino está en OTRO día. No es un error
  // que haya que anunciar: es un gesto que no significa nada, y avisar de cada uno enseñaría a
  // ignorar los avisos. Simplemente no pasa nada.
  if (origen && destinoId && origen !== destinoId && store.reordenarServicios(origen, destinoId)) {
    isDirty.value = true;
  }
};

const soltarOrdenDelDia = (fecha: string) => {
  store.soltarOrdenDelDia(fecha);
  isDirty.value = true;
};

const dragSegId = ref<string | null>(null);
const dragOverSegId = ref<string | null>(null);
let segLongPressTimer: ReturnType<typeof setTimeout> | null = null;
let segPointerIsDown = false;
let segDragActivated = false;
let segPointerStartY = 0;

const reordenarSegmentosVisual = (fromId: string, toId: string) => {
  const servicioId = store.servicioActivo?.id;
  if (!servicioId) return;
  store.reordenarSegmentos(servicioId, fromId, toId);
};

const onSegmentPointerDown = (e: PointerEvent, seg: CotSegmento) => {
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
  const items = store.componenteActivo?.snapshotItems;
  if (!items || fromId === toId) return;
  const fromIdx = items.findIndex((i) => i.id === fromId);
  const toIdx = items.findIndex((i) => i.id === toId);
  if (fromIdx === -1 || toIdx === -1) return;
  const [moved] = items.splice(fromIdx, 1);
  items.splice(toIdx, 0, moved);
};

const onItemPointerDown = (e: PointerEvent, item: SnapshotItem) => {
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

const modalInsercion = ref<{ isOpen: boolean; segmentoMaestro: Segmento | null }>({ isOpen: false, segmentoMaestro: null });
const modalNota = ref<{ isOpen: boolean; nota: NotaSnapshot | null }>({ isOpen: false, nota: null });
const opcionInsercion = ref<'append'|'insert'|'insertBefore'|'replace'>('append');
const targetSegmentoId = ref<string>('');
const isTotalsDrawerOpen = ref(false);

const abrirModalNota = (nota: NotaSnapshot) => {
  modalNota.value = { isOpen: true, nota };
};

const agruparNotasPorTipo = (notas: NotaSnapshot[]): Map<string, NotaSnapshot[]> => {
  const mapa = new Map<string, NotaSnapshot[]>();
  if (!notas || !Array.isArray(notas)) return mapa;
  notas.forEach((nota) => {
    const tipo = nota.tipo || 'OTROS';
    if (!mapa.has(tipo)) mapa.set(tipo, []);
    mapa.get(tipo)!.push(nota);
  });
  return mapa;
};

const prepararInsercion = async (seg: Segmento) => {
  const segmentos = store.servicioActivo?.cotsegmentos;
  if (!segmentos?.length) {
    await store.procesarInsercionSegmento(seg, plantillaSeleccionada.value, 'append');
    activeAccordion.value = 'parrafos'; // Cambia al acordeón de párrafos
    return;
  }
  modalInsercion.value.segmentoMaestro = seg;
  opcionInsercion.value = 'append';
  targetSegmentoId.value = segmentos[0].id;
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

const puedeAplicarPlantilla = computed(() => !store.servicioActivo?.cotsegmentos?.length);

/**
 * Los servicios del prestador de ESTE componente, y sólo ésos.
 *
 * ⚠️ **El filtro se repite aquí a propósito, y no es desconfianza gratuita.** El backend ya
 * filtra (`UuidRelacionFilter` sobre `organizacion`), pero entre el 19 y el 31/08/2026 no lo
 * hacía —el apaño que lo cubría escuchaba un parámetro mal escrito— y nada en el editor lo
 * denunciaba: la lista llegaba entera y se pintaba entera. Un desplegable que promete «los
 * servicios de esta empresa» debe poder cumplirlo mirando el dato, no confiando en que la
 * pregunta se hizo bien.
 *
 * `proveedorId` es el que trae cada servicio, no el que se pidió; ver el store.
 */
const opcionesServiciosPrestador = computed(() => {
  const prestador = store.extractIdStr(store.componenteEnEdicion?.prestadorMaestroId ?? '');
  // 🔥 La opción ya elegida NUNCA se esconde. `SearchableSelect` deriva su etiqueta de
  // `options.find(o => o.value === modelValue)`: si desaparece de la lista, el campo pinta el
  // placeholder como si no hubiera nada asignado, el operador vuelve a elegir y machaca el
  // dato. Misma regla que los filtros blandos de tarifa (docs/Cotizaciones.md §6.c).
  const elegido = store.extractIdStr(store.componenteEnEdicion?.prestadorServicioMaestroId ?? '');

  return store.catalogos.proveedorServicios
      .filter((ps) => ps.id === elegido || !prestador || !ps.proveedorId || ps.proveedorId === prestador)
      .map((ps) => ({ value: ps.id, label: ps.nombre }));
});

/**
 * Al abrir el desplegable, recargar si la lista no es de este prestador.
 *
 * Antes la condición era `length === 0`, y por eso una lista cargada para OTRO componente se
 * quedaba pegada: no estaba vacía, así que no se recargaba, y el desplegable enseñaba los
 * servicios del prestador anterior. Con el filtro de arriba ese caso ya no engaña —saldría
 * vacío— pero seguiría sin traer lo que toca.
 */
watch(isProveedorOpen, (newVal) => {
  const proveedorId = store.componenteEnEdicion?.prestadorMaestroId;
  if (!newVal || !proveedorId) return;

  const cargada = store.catalogos.proveedorServicios;
  const esDeOtro = cargada.length > 0
      && cargada.every((ps) => ps.proveedorId && ps.proveedorId !== store.extractIdStr(proveedorId));

  if (cargada.length === 0 || esDeOtro) {
    store.fetchProveedorServiciosDeProveedor(proveedorId);
  }
});

// Todas las imágenes de los segmentos del tour, en orden de itinerario
const imagenesDelTour = computed<ImagenSnapshot[]>(() => {
  const imgs: ImagenSnapshot[] = [];
  (store.cotizacion?.cotservicios || []).forEach((s) =>
    (s.cotsegmentos || []).forEach((seg) =>
      (seg.imagenesSnapshot || []).forEach((img) => imgs.push(img))));
  return imgs;
});

const esPortadaSeleccionada = (img: ImagenSnapshot): boolean => {
  const sel = store.cotizacion?.imagenPortada;
  return !!sel && (sel.imageUrl || sel.imageName) === (img.imageUrl || img.imageName);
};

const seleccionarPortada = (img: ImagenSnapshot) => {
  if (!store.cotizacion) return;
  store.cotizacion.imagenPortada = esPortadaSeleccionada(img) ? null : img;
};

const agregarRangoPrecio = () => {
  if (!store.cotizacion) return;
  if (!store.cotizacion.preciosDesde) store.cotizacion.preciosDesde = [];
  store.cotizacion.preciosDesde.push({ titulo: [], moneda: 'USD', valor: '' });
};

// Miller-columns navigation: 'cabecera' → 'servicios' → 'detalle'
const nivelEditor = ref<'cabecera' | 'servicios' | 'detalle'>('cabecera');
watch(() => store.inspectorActivo, (val) => {
  nivelEditor.value = val === 'resumen' ? 'servicios' : 'detalle';
});
// abrirNivel puede reasignar el mismo inspectorActivo (mismo nivel, otro data);
// el watch no dispara sin cambio de valor, así que se escucha la acción directamente.
store.$onAction(({ name, args }) => {
  if (name === 'abrirNivel') {
    nivelEditor.value = args[0] === 'resumen' ? 'servicios' : 'detalle';
  }
});

</script>

<template>
  <!-- ⚠️ `data-sin-recarga`: aquí NO se tira para recargar. El gesto es una recarga de
             página, y a media edición eso se lleva por delante lo que no esté guardado — y
             justo arriba del formulario, que es donde el gesto se arma, «arrastrar hacia
             abajo» es el movimiento más natural del mundo. Ver `GestoDeRecarga`. -->
  <div class="h-screen bg-slate-50 flex flex-col font-sans overflow-hidden relative" data-sin-recarga>

    <!-- ⚠️ `min-w-0` en el bloque del título y `shrink-0` en el de acciones, y no es cosmético:
         sin `min-w-0` flexbox se niega a encoger un hijo por debajo del ancho de su contenido, así
         que el `truncate` del nombre del expediente NO recortaba y el título empujaba los botones
         fuera de la pantalla. En móvil, «Guardar» quedaba cortado.

         🔥 Y DOS FILAS EN MÓVIL, porque arreglar aquello destapó lo contrario: los botones no se
         encogen (todos llevan `whitespace-nowrap`, y deben llevarlo), así que en una pantalla de
         390px ocupaban ~330 y al título le quedaban 40 — dos letras y unos puntos suspensivos.
         Es aritmética, no estilo: con estas acciones no cabe un título legible en la misma fila.
         En `md:` vuelve a una sola, que ahí sobra sitio. -->
    <header class="bg-slate-900 text-white px-4 md:px-6 py-2.5 md:py-3 flex flex-col md:flex-row md:items-center md:justify-between gap-2 z-20 shadow-md shrink-0">
      <div class="flex items-center gap-3 min-w-0 flex-1">
        <button @click="handleVolver" class="w-8 md:w-10 h-10 flex items-center justify-center bg-slate-800 hover:bg-slate-700 rounded-full transition-colors">
          <i class="fas fa-arrow-left text-sm"></i>
        </button>
        <div class="overflow-hidden">
          <h1 class="font-black text-base md:text-xl tracking-tight leading-none truncate">
            {{ store.fileActual?.nombreGrupo || 'Cargando Expediente...' }}
          </h1>
          <p class="text-[10px] md:text-[11px] font-bold text-slate-400 uppercase tracking-widest mt-0.5 truncate">
            {{ store.modoCatalogo ? 'Catálogo de Tours' : 'Motor Operativo' }}
            <span v-if="store.cotizacion">• {{ store.modoCatalogo ? 'Tour' : 'V' }}{{ store.cotizacion.version ?? 1 }}</span>

            <!-- ⚠️ Estás EDITANDO una foto del pasado. Va en la cabecera y en violeta porque
                 todo lo demás de esta pantalla se ve exactamente igual que en la versión viva:
                 sin este aviso se puede trabajar media hora sobre algo que no operará nadie. -->
            <span v-if="store.cotizacion?.estado === 'historico'"
                  class="ml-1.5 inline-flex items-center gap-1 bg-violet-500/20 text-violet-300 px-1.5 py-0.5 rounded normal-case tracking-normal">
              <i class="fas fa-clock-rotate-left text-[8px]"></i> Histórico — sólo lectura de hecho
            </span>

            <!-- Y al revés: desde la viva, cuántas fotos hay detrás. Lleva a la lista del
                 expediente, que es donde se abren. -->
            <button v-else-if="(store.cotizacion?.totalHistoricos ?? 0) > 0"
                    @click="irAExpediente"
                    class="ml-1.5 inline-flex items-center gap-1 bg-slate-700 hover:bg-slate-600 text-slate-300 px-1.5 py-0.5 rounded normal-case tracking-normal transition-colors"
                    title="Ver las fotos guardadas de esta versión">
              <i class="fas fa-camera text-[8px]"></i>
              {{ store.cotizacion?.totalHistoricos }} histórico{{ store.cotizacion?.totalHistoricos === 1 ? '' : 's' }}
            </button>
          </p>
        </div>
      </div>

      <div class="flex gap-2 md:gap-3 items-center shrink-0 justify-end" v-if="store.cotizacion">
        <div class="flex items-center bg-slate-800 rounded-lg p-1 gap-1 shrink-0">
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
        <button @click="nivelEditor = 'cabecera'" class="md:hidden px-3 py-2 bg-slate-800 text-slate-300 rounded-lg text-[10px] font-bold shadow-sm border border-slate-700 whitespace-nowrap">
          <i class="fas fa-chart-pie mr-1"></i> Resumen
        </button>
        <button v-if="paxPreviewUrl" @click="abrirVistaPax"
                title="Abrir la vista del cliente (guía pax) en otra pestaña"
                class="flex items-center gap-2 px-3 md:px-4 py-2 bg-slate-800 hover:bg-slate-700 text-slate-200 rounded-lg text-xs font-bold border border-slate-700 transition-colors whitespace-nowrap">
          <i class="fas fa-eye"></i> <span class="hidden md:inline">Vista Cliente</span>
        </button>
        <!-- La acción primaria: siempre visible y siempre con su nombre. Un icono suelto de
             disquete no se lee como «guardar» en un móvil.

             ⚠️ **El aviso de «estoy trabajando» vive AQUÍ desde que la pantalla no se desmonta.**
             Antes lo daba el spinner de página completa, con el efecto de borrar el scroll; al
             quitarlo, guardar se quedó sin ninguna señal y parecía que no hacía nada. El botón es
             el sitio correcto: está donde el operador acaba de pulsar y no tapa su trabajo. -->
        <button @click="handleGuardar"
                :disabled="store.isLoading"
                :title="store.isLoading ? 'Guardando…' : 'Guardar los cambios'"
                class="flex items-center gap-2 px-3 md:px-5 py-2 rounded-lg text-xs font-bold transition-colors shrink-0 whitespace-nowrap"
                :class="store.isLoading
                    ? 'bg-[#E07845]/60 cursor-wait'
                    : 'bg-[#E07845] hover:bg-[#c96636]'">
          <i class="fas" :class="store.isLoading ? 'fa-spinner fa-spin' : 'fa-save'"></i>
          <span>{{ store.isLoading ? 'Guardando…' : 'Guardar' }}</span>
        </button>
      </div>
    </header>

    <!-- La barra de actividad. Dos razones para que exista además del botón: las operaciones
         largas —aplicar plantilla, actualizar textos— se lanzan desde DENTRO del modal, donde el
         botón Guardar no se ve; y en el móvil la cabecera puede quedar fuera de vista. Tres
         píxeles que no desplazan nada. -->
    <div v-if="store.isLoading" class="h-[3px] bg-[#E07845]/20 overflow-hidden shrink-0" role="status" aria-label="Trabajando">
      <div class="h-full w-1/3 bg-[#E07845] barra-actividad"></div>
    </div>

    <!-- ⚠️ `isCargaInicial`, NO `isLoading`. Este `v-if` tiene debajo un `v-else` con el editor
         ENTERO, así que mientras esté activo Vue desmonta y remonta todo el árbol: los scroll
         vuelven a cero. Con `isLoading` eso pasaba también al Aplicar plantilla y al Actualizar
         textos —operaciones que ocurren DENTRO del modal abierto—, y había que volver a buscar el
         servicio en el que se estaba trabajando. En un programa de veinte días, rehacer el camino
         en cada retoque.

         `isLoading` sigue existiendo y sigue siendo el candado que consulta `guardarCotizacion()`;
         lo que ya no hace es tirar la pantalla. El aviso de esas operaciones lo dan los botones,
         que tienen su propio spinner y se deshabilitan. -->
    <div v-if="store.isCargaInicial" class="flex-1 flex items-center justify-center bg-[#F8FAFC]">
      <div class="text-center text-slate-400">
        <i class="fas fa-spinner fa-spin text-4xl mb-4 text-[#376875]"></i>
        <p class="font-black tracking-widest uppercase text-xs">Sincronizando con Servidor...</p>
      </div>
    </div>

    <div v-else-if="store.cotizacion" class="flex flex-1 overflow-hidden">

      <!-- ═══ PANEL 2: Servicios (order-2 en desktop) ══════════════════════ -->
      <div :class="[
          'flex-col overflow-hidden bg-[#F8FAFC] flex-1 md:order-2',
          nivelEditor === 'servicios' ? 'flex' : 'hidden md:flex'
      ]">
        <div class="md:hidden flex items-center gap-3 px-4 py-3 border-b border-slate-200 bg-white shrink-0">
          <button @click="nivelEditor = 'cabecera'"
                  class="w-8 h-8 flex items-center justify-center bg-slate-100 hover:bg-slate-200 rounded-full transition-colors text-slate-600">
            <i class="fas fa-arrow-left text-sm"></i>
          </button>
          <span class="font-black text-sm text-slate-800">Servicios del Itinerario</span>
        </div>
        <!-- ⚠️ SIN padding arriba, y es lo que hace que la cabecera de día funcione.
             Con `p-4 md:p-8`, el sticky `top-0` descansaba por DEBAJO de ese padding y en esa
             franja —entre la barra oscura y la cabecera— el contenido pasaba por delante: se veía
             el título del servicio colándose por encima del día. Ponerle fondo opaco no lo
             arreglaba, porque el hueco está por encima de la banda, no detrás.
             El aire de arriba lo pone el propio `py-4` del sticky. -->
        <div class="flex-1 overflow-y-auto px-4 md:px-8 pb-4 md:pb-8">
        <div class="max-w-4xl mx-auto pb-32">

          <div v-for="dia in store.itinerarioDinamico" :key="dia.fechaAbsoluta" class="mb-10">

            <!-- ⚠️ Fondo OPACO y sangrado a los lados, no translúcido.
                 Con `/95` + blur, la tarjeta que pasaba por debajo se transparentaba y su título
                 asomaba medio cortado justo bajo la cabecera. Y la banda tiene que cubrir también
                 el padding del contenedor (`p-4 md:p-8`): si se queda dentro, el contenido se ve
                 pasar por los costados. El `z-20` la deja por encima de las tarjetas, que llevan
                 `z-10` en sus adornos. -->
            <!-- ⚠️ `flex-wrap` y el `hr` sólo en pantalla ancha. Con el `hr` en `flex-1` los
                 botones quedaban EMPUJADOS fuera del ancho en un móvil: la fila no podía
                 encogerse porque la línea reclamaba todo el espacio sobrante. Ahora los botones
                 bajan a su propio renglón cuando no caben, y la línea decorativa —que sólo
                 rellena— aparece a partir de `md`. -->
            <div class="flex flex-wrap items-center gap-x-3 gap-y-2 sticky top-0 bg-[#F8FAFC] py-4 -mx-4 px-4 md:-mx-8 md:px-8 z-20 mb-6 border-b border-slate-200/50">
              <div class="bg-slate-900 text-white px-4 py-2 rounded-xl font-black text-sm uppercase tracking-widest shadow-lg border border-slate-700 shrink-0">
                Día {{ dia.diaNumero }}
              </div>
              <div class="flex flex-col min-w-0">
                <span class="text-[11px] font-black text-[#E07845] uppercase tracking-tighter leading-none mb-1">Cronología Operativa</span>
                <div class="text-sm font-black text-slate-800 uppercase tracking-tight truncate">
                  {{ store.modoCatalogo ? 'Programa del Tour' : formatFecha(dia.fechaAbsoluta) }}
                </div>
              </div>
              <hr class="hidden md:block flex-1 border-slate-300 ml-4">
              <!-- Los dos botones viajan juntos y se van a la derecha: `ml-auto` los empuja al
                   final de su renglón, caiga éste donde caiga. -->
              <div class="flex items-center gap-2 ml-auto shrink-0">
              <!-- ⚠️ POR DÍA de verdad: guarda la fecha, no un booleano. La primera versión era
                   un `ref(false)` global con un comentario que decía «por día» — pulsarlo
                   colapsaba TODOS los días a la vez. El comentario describía la intención y el
                   código hacía otra cosa. -->
              <button @click.stop="diaEnReorden = diaEnReorden === dia.fechaAbsoluta ? null : dia.fechaAbsoluta"
                      :title="diaEnReorden === dia.fechaAbsoluta ? 'Volver a editar' : 'Reordenar los servicios de este día'"
                      class="shrink-0 px-2.5 py-1.5 rounded-lg text-[9px] font-black uppercase tracking-widest border transition-colors"
                      :class="diaEnReorden === dia.fechaAbsoluta
                        ? 'bg-[#376875] text-white border-[#376875]'
                        : 'bg-white text-slate-500 border-slate-200 hover:border-[#376875]/50'">
                <i class="fas fa-arrows-up-down mr-1"></i>{{ diaEnReorden === dia.fechaAbsoluta ? 'Listo' : 'Ordenar' }}
              </button>
              <!-- Salida explícita: una vez fijado a mano, un cambio de hora deja de recolocar
                   nada, y sin esto la única forma de volver sería adivinar los números. -->
              <button v-if="store.diaOrdenadoAMano(dia.fechaAbsoluta)"
                      @click.stop="soltarOrdenDelDia(dia.fechaAbsoluta)"
                      title="Devolver este día a su orden automático"
                      class="shrink-0 px-2.5 py-1.5 rounded-lg text-[9px] font-black uppercase tracking-widest border border-amber-300 bg-amber-50 text-amber-700 hover:bg-amber-100 transition-colors">
                <i class="fas fa-rotate-left mr-1"></i>Automático
              </button>
              </div>
            </div>

            <div class="space-y-4">
              <!-- ── MODO REORDENAR ─────────────────────────────────────────────
                   La ficha se reduce a su título y un asa. Es HTML5 drag-and-drop y no el
                   long-press de los segmentos porque aquí no compite con el clic: en este modo la
                   ficha no abre nada, así que arrastrar es el único gesto posible y no hace falta
                   distinguirlo de nada.

                   ⚠️ Sólo se puede soltar sobre una ficha DEL MISMO DÍA. No hay guarda visual
                   porque no hace falta: cada día es un contenedor `v-for` distinto, y el store
                   rechaza el movimiento igualmente si algo se cuela. -->
              <template v-if="diaEnReorden === dia.fechaAbsoluta">
                <div v-for="servicio in dia.cotservicios" :key="`ord-${servicio.id}`"
                     draggable="true"
                     @dragstart="onServicioDragStart(servicio.id ?? '')"
                     @dragover.prevent="dragOverServicioId = servicio.id ?? null"
                     @dragleave="dragOverServicioId = null"
                     @drop.prevent="onServicioDrop(servicio.id ?? '')"
                     class="bg-white border-2 rounded-xl px-4 py-3 flex items-center gap-3 cursor-grab active:cursor-grabbing transition-colors"
                     :class="[
                       dragOverServicioId === servicio.id ? 'border-[#376875] bg-[#376875]/5' : 'border-slate-200',
                       dragServicioId === servicio.id ? 'opacity-40' : ''
                     ]">
                  <i class="fas fa-grip-vertical text-slate-300 shrink-0"></i>
                  <span class="font-black text-sm text-slate-800 truncate flex-1">
                    <!-- `es` fijo: el operativo no se traduce (ver §2.b). Con el idioma de edición,
                         reordenar una cotización en inglés era arrastrar fichas que ponían todas
                         «Sin nombre». -->
                    {{ store.getI18nText(servicio.nombreInternoSnapshot, 'es') || 'Sin nombre' }}
                  </span>
                  <!-- La hora es el dato que el arrastre puede contradecir: se enseña justo al
                       lado para que la contradicción se vea al hacerla, no al descubrirla. -->
                  <span v-if="horaClaveDeServicio(servicio)" class="text-[11px] font-black text-slate-400 tabular-nums shrink-0">
                    {{ horaClaveDeServicio(servicio) }}
                  </span>
                  <span v-else class="text-[10px] font-bold text-slate-300 uppercase tracking-widest shrink-0">sin hora</span>
                </div>
              </template>

              <div v-else v-for="servicio in dia.cotservicios" :key="servicio.id"
                   @click="store.abrirNivel('servicio', servicio)"
                   class="bg-white border-2 rounded-2xl p-5 shadow-sm transition-all cursor-pointer group relative overflow-hidden"
                   :class="[
                     store.servicioActivo?.id === servicio.id ? 'border-[#376875] shadow-md' : 'border-slate-200 hover:border-[#376875]/50',
                     store.isServicioConAlerta(servicio) ? 'border-red-400 bg-red-50/10' : ''
                   ]">

                <button @click.stop="store.eliminarServicio(servicio.id!)" class="absolute right-4 top-4 text-slate-400 hover:text-red-500 transition-colors z-10 bg-slate-100 w-8 h-8 rounded-full flex items-center justify-center shadow-sm">
                  <i class="fas fa-trash-alt text-sm"></i>
                </button>

                <div class="flex items-start justify-between gap-4">
                  <div class="pr-10 w-full">

                    <p v-if="!store.modoCatalogo" class="text-[10px] font-black text-slate-600 uppercase flex items-center gap-1.5 mb-2 bg-slate-100 w-max px-2 py-1 rounded border border-slate-200">
                      <i class="far fa-calendar-check text-[#E07845]"></i> FECHA BASE: {{ formatFecha(servicio.fechaInicioAbsoluta) }}
                    </p>

                    <!-- Los tres nombres, ordenados por a quién sirven:
                         1) OPERATIVO (nombreInternoSnapshot) grande — el que tú usas y editas.
                         2) CLIENTE (tituloSnapshot) debajo — lo que ve el pasajero.
                         3) PLANTILLA (itinerarioNombreInternoSnapshot) como etiqueta de procedencia. -->
                    <!-- Mono-segmento sin plantilla: el nombre del servicio es genérico y su público
                         se descarta en la vista del cliente; el segmento es el que dice qué cosa es. -->
                    <div class="font-black text-lg text-slate-900 leading-tight">
                      <i v-if="store.isServicioConAlerta(servicio)" class="fas fa-exclamation-triangle text-red-500 mr-2" title="Faltan cuadrar tarifas"></i>
                      <template v-if="store.esMonoSegmentoSinPlantilla(servicio)">{{ store.getI18nText(servicio.cotsegmentos?.[0]?.tituloSnapshot, store.cotizacion.idiomaEdicion) || 'Sin nombre' }}</template>
                      <template v-else>{{ store.getI18nText(servicio.nombreInternoSnapshot, 'es') || store.getI18nText(servicio.tituloSnapshot, store.cotizacion.idiomaEdicion) || 'Sin nombre' }}</template>
                    </div>

                    <!-- Mono-segmento: el nombre interno lo pone el segmento (no el servicio). -->
                    <p v-if="store.esMonoSegmentoSinPlantilla(servicio)"
                       class="text-[11px] font-bold text-slate-500 mt-1 leading-snug">
                      <i class="fas fa-tag mr-1 text-slate-300"></i> {{ store.segmentoUnicoMaestro(servicio)?.nombreInterno || store.getI18nText(servicio.cotsegmentos?.[0]?.tituloSnapshot, 'es') }}
                    </p>
                    <!-- El nombre del cliente, sólo si aporta algo distinto del operativo. -->
                    <p v-else-if="store.getI18nText(servicio.tituloSnapshot, store.cotizacion.idiomaEdicion) && store.getI18nText(servicio.tituloSnapshot, store.cotizacion.idiomaEdicion) !== store.getI18nText(servicio.nombreInternoSnapshot, 'es')"
                       class="text-[11px] font-bold text-slate-500 mt-1 leading-snug">
                      <i class="fas fa-user mr-1 text-slate-300"></i> Cliente: {{ store.getI18nText(servicio.tituloSnapshot, store.cotizacion.idiomaEdicion) }}
                    </p>

                    <p class="text-[11px] font-bold text-slate-500 mt-1" v-if="store.getI18nText(servicio.itinerarioNombreInternoSnapshot, 'es') !== 'Sin plantilla'">
                      <i class="fas fa-map-signs mr-1 text-slate-300"></i> Plantilla: {{ store.getI18nText(servicio.itinerarioNombreInternoSnapshot, 'es') }}
                    </p>
                    <p class="text-[11px] font-bold text-slate-500 mt-1" v-else-if="servicio.cotsegmentos && servicio.cotsegmentos.length > 0">
                      <i class="fas fa-layer-group mr-1 text-slate-300"></i> Storytelling a medida ({{ servicio.cotsegmentos.length }} párrafos)
                    </p>
                    <p class="text-[11px] font-bold text-slate-500 mt-1" v-else>
                      <i class="fas fa-pen-nib mr-1 text-slate-300"></i> Sin Storytelling
                    </p>

                    <!-- Dónde recoge y dónde deja: lo primero que pregunta el proveedor al
                         recibir la orden, y lo que delata que esta cotización se ha desviado de
                         su plantilla (un segmento final cambiado, uno que falta). Sale de los
                         segmentos maestros de ESTA cotización, no los del catálogo. -->
                    <p v-if="puntosDeServicio(servicio)"
                       class="text-[11px] font-bold mt-1 leading-snug"
                       :class="puntosDeServicio(servicio)!.completo ? 'text-slate-500' : 'text-amber-700'"
                       :title="puntosDeServicio(servicio)!.completo ? 'Recojo y entrega declarados' : (store.puntosDeServicio(servicio.id)?.faltantes || []).join(' · ')">
                      <i class="fas fa-route mr-1"
                         :class="puntosDeServicio(servicio)!.completo ? 'text-slate-300' : 'text-amber-500'"></i>
                      {{ puntosDeServicio(servicio)!.texto }}
                      <i v-if="!puntosDeServicio(servicio)!.completo" class="fas fa-exclamation-circle ml-1 text-amber-500"></i>
                    </p>

                    <div class="flex flex-wrap items-center gap-2 mt-4">
                        <span class="text-[9px] font-black bg-teal-600 text-white px-2 py-1.5 rounded uppercase tracking-widest shadow-sm">
                            <i class="far fa-clock mr-1 text-teal-200"></i> Horario
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
        </div>

        <div @click="isTotalsDrawerOpen = true"
             class="md:hidden relative bg-slate-900 border-t border-slate-700/50 px-6 py-4 flex justify-between items-center shrink-0 shadow-[0_-10px_20px_-5px_rgba(0,0,0,0.4)] cursor-pointer active:bg-slate-950 transition-colors">

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

          <div class="px-4 flex flex-col items-end">
            <span class="text-[8px] font-black text-emerald-400 uppercase tracking-widest leading-none mb-0.5">Venta Sugerida</span>
            <span class="text-xl font-black text-emerald-400 leading-none">{{ formatMoneda(store.ventaSugerida, store.cotizacion.monedaGlobal) }}</span>
          </div>
        </div>
      </div>

      <!-- ═══ PANEL 1: Cabecera de Cotización (md:order-1, siempre visible en desktop amplio) ═══ -->
      <aside :class="[
          'flex-col overflow-hidden bg-white border-slate-200 md:order-1 md:border-r shrink-0 w-full md:w-88',
          nivelEditor === 'cabecera' ? 'flex' : 'hidden',
          store.inspectorActivo === 'resumen' ? 'md:flex' : 'md:hidden xl:flex'
      ]">

        <div class="flex-1 flex flex-col min-h-0">
          <div class="px-6 py-4 border-b border-slate-100 flex justify-between items-center bg-slate-50 shrink-0">
            <h2 class="text-xs font-black text-slate-500 uppercase tracking-widest">{{ store.modoCatalogo ? 'Cabecera del Tour' : 'Cabecera de Cotización' }}</h2>
            <button @click="nivelEditor = 'servicios'"
                    class="md:hidden flex items-center gap-1.5 px-3 py-2 bg-[#376875] hover:bg-[#2c5560] text-white rounded-lg text-[10px] font-black uppercase tracking-widest shadow-sm transition-colors">
              Ver Servicios <i class="fas fa-arrow-right"></i>
            </button>
          </div>
          <div class="p-6 flex-1 overflow-y-auto flex flex-col gap-6 pb-32">

            <div v-if="!store.modoCatalogo" class="order-1 shrink-0 bg-[#376875] text-white rounded-3xl p-6 shadow-xl relative overflow-hidden">
              <i class="fas fa-chart-pie absolute -right-6 -bottom-6 text-8xl opacity-10"></i>
              <div class="relative z-10">
                <p class="text-[10px] font-bold text-emerald-400 uppercase tracking-widest mb-1">Venta Total Sugerida</p>
                <p class="text-4xl font-black tracking-tight">{{ formatMonedaPanel(store.resumenFinanciero?.totalVentaBruta) }}</p>
                <div class="mt-4 pt-4 border-t border-slate-800/30 flex justify-between items-end">
                  <div>
                    <p class="text-[9px] text-slate-300 uppercase font-bold">Costo Neto</p>
                    <p class="text-lg font-bold text-white">{{ formatMonedaPanel(store.resumenFinanciero?.totalCostoNeto) }}</p>
                  </div>
                  <div class="text-right">
                    <p class="text-[9px] text-emerald-400 uppercase font-bold">Margen Bruto</p>
                    <p class="text-lg font-bold text-emerald-300">+{{ formatMonedaPanel(store.resumenFinanciero?.ganancia) }}</p>
                  </div>
                </div>
              </div>
            </div>

            <div v-if="store.modoCatalogo" class="order-2 shrink-0 bg-orange-50 border border-orange-200 rounded-2xl p-4 shadow-sm">
              <div class="flex items-center justify-between mb-1">
                <h3 class="text-[10px] font-black text-orange-600 uppercase tracking-widest"><i class="fas fa-tags mr-1"></i> Precios de Exhibición (Desde)</h3>
                <button @click="agregarRangoPrecio"
                        class="bg-orange-500 hover:bg-orange-600 text-white px-3 py-1.5 rounded-lg text-[10px] font-bold shadow-sm transition-colors">+ Rango</button>
              </div>
              <p class="text-[9px] text-orange-400 font-medium mb-3 leading-tight">Rangos comerciales por perfil (Peruano, Extranjero, Niño...). El título es traducible; el cálculo financiero real se conserva para producto.</p>

              <div v-if="!store.cotizacion.preciosDesde?.length" class="text-center py-3 border border-dashed border-orange-200 rounded-xl">
                <span class="text-[9px] font-black text-orange-300 uppercase tracking-widest">Sin rangos — agrega el primero</span>
              </div>

              <div v-else class="space-y-2">
                <div v-for="(rango, idx) in store.cotizacion.preciosDesde" :key="idx"
                     class="bg-white border border-orange-100 rounded-xl p-2.5 flex gap-2 items-center shadow-sm">
                  <input :value="store.getI18nText(rango.titulo, store.cotizacion.idiomaEdicion)"
                         @input="e => store.setI18nText(rango.titulo, store.cotizacion!.idiomaEdicion, (e.target as HTMLInputElement).value)"
                         type="text" placeholder="Perfil (ej: Peruano)"
                         class="flex-1 min-w-0 bg-transparent text-xs font-bold text-slate-700 outline-none border-b border-slate-200 focus:border-orange-400 pb-1">
                  <select v-model="rango.moneda"
                          class="bg-slate-50 border border-slate-200 rounded-lg px-2 py-1.5 text-[10px] font-black text-slate-600 outline-none shrink-0">
                    <option v-for="m in store.catalogos.monedas" :key="m.id" :value="m.id">{{ m.id }}</option>
                  </select>
                  <input :value="rango.valor"
                         @input="e => rango.valor = (e.target as HTMLInputElement).value"
                         type="number" step="0.01" placeholder="0.00"
                         class="w-20 bg-slate-50 border border-slate-200 rounded-lg px-2 py-1.5 text-xs font-black text-right text-orange-600 outline-none focus:ring-1 focus:ring-orange-400 shrink-0">
                  <button @click="store.cotizacion.preciosDesde.splice(idx, 1)"
                          class="text-slate-300 hover:text-red-500 transition-colors px-1 shrink-0">
                    <i class="fas fa-times text-sm"></i>
                  </button>
                </div>
              </div>
            </div>

            <!-- ⚠️ Estuvo detrás de `v-if="modoCatalogo"` y era una condición de más: el precio
                 unitario sin total de grupo lo necesita también un EXPEDIENTE cuando no todos
                 llevan lo mismo. En el padrón de Punta Cana hay 13 combinaciones de servicios
                 entre 133 personas, así que un «precio total del viaje» no describe a nadie.
                 Ver docs/Cotizaciones.md §6.o. -->
            <div class="order-5 shrink-0 bg-orange-50 border border-orange-200 rounded-2xl p-4 flex items-center justify-between shadow-sm">
              <div>
                <h3 class="text-[10px] font-black text-orange-600 uppercase tracking-widest"><i class="fas fa-user-tag mr-1"></i> Ocultar Total de Grupo</h3>
                <p class="text-[9px] text-orange-400 mt-1 font-medium leading-tight pr-4">
                  Suprime en la vista del cliente el «2X» del perfil, el «× N pax · total» y la barra «Precio total del viaje». El precio por pasajero se sigue viendo.
                  <template v-if="store.modoCatalogo">Actívalo salvo que el tour se venda como salida de grupo fijo.</template>
                  <template v-else>Útil en un grupo donde no todos llevan los mismos servicios: ahí el total no describe a nadie.</template>
                </p>
              </div>
              <button @click="store.cotizacion.totalesOcultos = !store.cotizacion.totalesOcultos"
                      :class="[
                           'relative inline-flex h-6 w-11 items-center rounded-full transition-colors focus:outline-none shrink-0',
                           store.cotizacion.totalesOcultos ? 'bg-orange-500' : 'bg-slate-300'
                       ]">
                 <span :class="store.cotizacion.totalesOcultos ? 'translate-x-6' : 'translate-x-1'"
                       class="inline-block h-4 w-4 transform rounded-full bg-white transition-transform" />
              </button>
            </div>

            <div v-if="store.modoCatalogo" class="order-4 shrink-0 bg-teal-50 border border-teal-200 rounded-2xl p-4 shadow-sm">
              <h3 class="text-[10px] font-black text-teal-700 uppercase tracking-widest mb-1"><i class="fas fa-image mr-1"></i> Portada del Tour</h3>
              <p class="text-[9px] text-teal-500 font-medium mb-3 leading-tight">
                {{ store.cotizacion.imagenPortada ? 'Portada fija elegida manualmente. Click para volver a automática.' : 'Automática: primera portada del itinerario. Click en una imagen para fijarla.' }}
              </p>
              <div v-if="!imagenesDelTour.length" class="text-center py-3 border border-dashed border-teal-200 rounded-xl">
                <span class="text-[9px] font-black text-teal-300 uppercase tracking-widest">Los segmentos del tour aún no tienen imágenes</span>
              </div>
              <div v-else class="grid grid-cols-3 gap-2">
                <button v-for="(img, i) in imagenesDelTour" :key="i" @click="seleccionarPortada(img)"
                        class="relative aspect-video rounded-lg overflow-hidden border-2 transition-all"
                        :class="esPortadaSeleccionada(img) ? 'border-teal-500 ring-2 ring-teal-300' : 'border-transparent hover:border-teal-300'">
                  <img :src="thumbUrl(img.imageUrl, 'travel_thumb_admin')" class="w-full h-full object-cover" loading="lazy" alt="Imagen">
                  <span v-if="img.isPortada" class="absolute top-1 left-1 bg-amber-400 text-white text-[8px] font-black px-1 rounded" title="Portada de su segmento"><i class="fas fa-star"></i></span>
                  <span v-if="esPortadaSeleccionada(img)" class="absolute inset-0 bg-teal-600/30 flex items-center justify-center"><i class="fas fa-check-circle text-white text-lg"></i></span>
                </button>
              </div>
            </div>

            <div class="space-y-6 shrink-0" :class="store.modoCatalogo ? 'order-3' : 'order-2'">
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
                    <p class="text-sm font-black text-slate-800">{{ formatMonedaPanel(clase.resumen.ventaDolares / (clase.cantidad || 1)) }}</p>
                  </div>
                </div>

                <div class="grid grid-cols-2 gap-2 mt-2 pt-2 border-t border-slate-50">
                  <div class="bg-slate-50 p-2 rounded-lg text-center">
                    <p class="text-[8px] text-slate-400 font-bold uppercase">Costo Total</p>
                    <p class="text-[11px] font-black text-slate-600">{{ formatMonedaPanel(clase.resumen.montoDolares) }}</p>
                  </div>
                  <div class="bg-emerald-50 p-2 rounded-lg text-center">
                    <p class="text-[8px] text-emerald-600 font-bold uppercase">Utilidad</p>
                    <p class="text-[11px] font-black text-emerald-700">{{ formatMonedaPanel(clase.resumen.gananciaDolares) }}</p>
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

            <!-- Opciones y alternativas agrupadas (req 2): "Alternativa 1/2" u "Opción N" -->
            <div v-if="store.gruposUpgrade.length" class="space-y-3">
              <h3 class="text-[10px] font-black text-slate-400 uppercase tracking-widest ml-1"><i class="fas fa-right-left mr-1"></i> Opciones y Alternativas</h3>
              <div v-for="grupo in store.gruposUpgrade" :key="grupo.label"
                   class="bg-white border rounded-2xl p-4 shadow-sm"
                   :class="grupo.esOpcion ? 'border-amber-200' : 'border-purple-200'">
                <p class="text-[10px] font-black uppercase tracking-widest mb-2 flex items-center gap-1.5"
                   :class="grupo.esOpcion ? 'text-amber-600' : 'text-purple-600'">
                  <i class="fas" :class="grupo.esOpcion ? 'fa-circle-question' : 'fa-right-left'"></i> {{ grupo.label }}
                </p>
                <div v-for="(o, i) in grupo.opciones" :key="i" class="flex justify-between items-start gap-2 py-1.5 border-t border-slate-50 first:border-0">
                  <div class="min-w-0">
                    <p class="text-[12px] font-black leading-tight">
                      <template v-if="o.componenteNombreInterno">
                        <span class="text-slate-800">{{ o.componenteNombreInterno }}</span>
                        <span v-if="tarifaLabelAlt(o)" class="text-slate-400 font-bold"> · {{ tarifaLabelAlt(o) }}</span>
                      </template>
                      <span v-else class="text-slate-800">{{ tarifaLabelAlt(o) || 'Insumo Logístico' }}</span>
                    </p>
                    <p v-if="clasificacionBadges(o).length" class="mt-0.5 flex flex-wrap items-center gap-1">
                      <span v-for="b in clasificacionBadges(o)" :key="b.type"
                            class="inline-flex items-center gap-1 text-[9px] font-black px-1.5 py-0.5 rounded border uppercase"
                            :class="CLASIF_BADGE_CLASE[b.type]">
                        {{ b.icon }} {{ b.label }}
                      </span>
                    </p>
                    <!-- Estándar reemplazada: tachada + atenuada, con su modalidad/categoría -->
                    <p class="text-[11px] text-slate-500 leading-tight flex flex-wrap items-center gap-1 mt-1">
                      <span class="text-[9px] font-black uppercase tracking-wide text-slate-400">Reemplaza</span>
                      <template v-if="o.tieneEstandarEspejo">
                        <span class="line-through">{{ estandarLabelAlt(o) || 'Estándar' }}</span>
                        <span v-for="b in clasificacionBadges({ modalidad: o.estandarModalidad, categoria: o.estandarCategoria })" :key="b.type"
                              class="inline-flex items-center gap-1 text-[9px] font-black px-1.5 py-0.5 rounded border uppercase bg-slate-100 text-slate-400 border-slate-200 line-through">
                          {{ b.icon }} {{ b.label }}
                        </span>
                      </template>
                      <span v-else class="italic line-through">vs. estándar del bloque</span>
                    </p>
                  </div>
                  <span class="text-[12px] font-black whitespace-nowrap shrink-0"
                        :class="o.deltaVentaPorPax >= 0 ? 'text-purple-700' : 'text-emerald-700'">
                    {{ o.deltaVentaPorPax >= 0 ? '+' : '−' }}{{ formatMonedaPanel(Math.abs(o.deltaVentaPorPax)) }}/pax
                  </span>
                </div>
              </div>
            </div>
            </div>

            <div class="space-y-6 shrink-0" :class="store.modoCatalogo ? 'order-1' : 'order-5'">
            <div class="grid grid-cols-2 gap-4">
              <div class="col-span-2 grid grid-cols-2 gap-4 bg-slate-50 border border-slate-200 rounded-2xl p-4">
                <div>
                  <span class="block text-xs font-bold text-slate-500 uppercase mb-1">{{ store.modoCatalogo ? 'Estado del Tour' : 'Estado Versión' }}</span>
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

                <!-- Forzar operación: sólo con la versión ya guardada como confirmada.
                     La Biblia se genera en la TRANSICIÓN a confirmado y una sola vez, así
                     que todo lo editado después no llega al Centro de Operaciones si no se
                     regenera a mano. En catálogo no aplica (fechas nominales, sin expediente). -->
                <div v-if="!store.modoCatalogo && store.cotizacion.estado === 'confirmado'" class="col-span-2">
                  <button type="button" @click="abrirPlanOperacion"
                          class="w-full flex items-center justify-center gap-2 px-3 py-2 bg-white border border-[#376875]/30 text-[#376875] hover:bg-[#376875] hover:text-white font-bold text-xs rounded-lg shadow-sm transition-colors">
                    <i class="fas fa-code-compare"></i>
                    Revisar cambios de operación
                  </button>
                  <p class="text-[10px] text-slate-400 mt-1.5 leading-snug">
                    Compara esta versión con el Centro de Operaciones y aplica sólo lo que
                    apruebes. Guarda primero: se compara con lo que hay en la base de datos,
                    no con lo que ves en pantalla.
                  </p>
                </div>

                <!-- ── Coherencia: los huecos que no dan error ──────────────────
                     Un id puesto con su nombre vacío no rompe nada: sólo deja de aparecer algo, y
                     eso es indistinguible de «este componente no tiene proveedor». Por eso se
                     busca a propósito en vez de esperar a notarlo. -->
                <div v-if="!store.modoCatalogo && store.cotizacion.id" class="col-span-2">
                  <button type="button" @click="revisarCoherencia(false)" :disabled="revisandoCoherencia"
                          class="w-full flex items-center justify-center gap-2 px-3 py-2 bg-white border border-slate-300 text-slate-600 hover:bg-slate-50 rounded-xl text-[11px] font-black uppercase tracking-wide transition-colors disabled:opacity-50">
                    <i :class="revisandoCoherencia ? 'fas fa-circle-notch fa-spin' : 'fas fa-stethoscope'"></i>
                    {{ revisandoCoherencia ? 'Revisando…' : 'Revisar coherencia de datos' }}
                  </button>

                  <p v-if="!informeCoherencia" class="text-[10px] text-slate-400 mt-1.5 leading-snug">
                    Busca datos a medias —un hotel asignado sin su nombre, una habitación sin
                    título— que no dan error pero dejan huecos en la Orden o en la vista del cliente.
                  </p>

                  <template v-else>
                    <!-- Cuando no hay nada, se dice. Un panel que sólo habla ante problemas deja
                         la duda de si llegó a mirar. -->
                    <p v-if="!informeCoherencia.reparables.length && !informeCoherencia.avisos.length"
                       class="mt-2 flex items-center gap-1.5 text-[11px] font-bold text-emerald-600">
                      <i class="fas fa-circle-check"></i>
                      {{ informeCoherencia.reparado ? 'Reparado: ya está todo coherente.' : 'Todo coherente en esta cotización.' }}
                    </p>

                    <div v-if="informeCoherencia.reparables.length" class="mt-2 rounded-xl border border-amber-200 bg-amber-50 p-3">
                      <p class="text-[10px] font-black text-amber-700 uppercase tracking-wide">
                        {{ informeCoherencia.reparado ? 'Reparado' : 'Se puede reparar' }}
                      </p>
                      <ul class="mt-1.5 space-y-1.5">
                        <li v-for="h in informeCoherencia.reparables" :key="h.clave">
                          <span class="text-[11px] font-black text-amber-800">{{ h.filas }}</span>
                          <span class="text-[11px] font-bold text-amber-800 ml-1">{{ h.titulo }}</span>
                          <span class="block text-[10px] text-amber-600 leading-snug">{{ h.detalle }}</span>
                        </li>
                      </ul>
                      <!-- El botón de reparar sólo aparece DESPUÉS de enseñar qué se va a tocar. -->
                      <button v-if="!informeCoherencia.reparado" type="button"
                              @click="revisarCoherencia(true)" :disabled="revisandoCoherencia"
                              class="mt-2.5 w-full flex items-center justify-center gap-2 px-3 py-2 bg-amber-600 hover:bg-amber-700 text-white rounded-lg text-[11px] font-black uppercase tracking-wide transition-colors disabled:opacity-50">
                        <i class="fas fa-wrench"></i> Reparar
                      </button>
                      <p v-if="informeCoherencia.reparado" class="text-[10px] text-amber-600 mt-1.5 leading-snug">
                        Recarga la cotización para verlo: la pantalla sigue con los datos de antes.
                      </p>
                    </div>

                    <!-- Lo que es una decisión de alguien: se enseña y NO se ofrece arreglar. -->
                    <div v-if="informeCoherencia.avisos.length" class="mt-2 rounded-xl border border-slate-200 bg-slate-50 p-3">
                      <p class="text-[10px] font-black text-slate-500 uppercase tracking-wide">Para mirar a mano</p>
                      <ul class="mt-1.5 space-y-1.5">
                        <li v-for="h in informeCoherencia.avisos" :key="h.clave">
                          <span class="text-[11px] font-black text-slate-700">{{ h.filas }}</span>
                          <span class="text-[11px] font-bold text-slate-700 ml-1">{{ h.titulo }}</span>
                          <span class="block text-[10px] text-slate-500 leading-snug">{{ h.detalle }}</span>
                        </li>
                      </ul>
                    </div>
                  </template>
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

              <div class="col-span-2 bg-slate-50 border border-slate-200 rounded-2xl p-4 flex items-center justify-between gap-4">
                <div>
                  <span class="block text-[10px] font-black text-slate-500 uppercase mb-1.5">Moneda Base</span>
                  <select v-model="store.cotizacion.monedaGlobal"
                          class="font-black text-slate-800 bg-white px-3 py-2 rounded-lg border border-slate-200 outline-none focus:ring-2 focus:ring-[#376875] text-sm appearance-none shadow-sm">
                    <option v-for="m in store.catalogos.monedas" :key="m.id" :value="m.id">
                      {{ m.id }} — {{ m.nombre }}
                    </option>
                  </select>
                </div>
                <div v-if="store.cotizacion?.monedaGlobal !== 'PEN'" class="flex flex-col items-end gap-2 shrink-0">
                  <span class="text-[10px] font-black text-slate-500 uppercase">Ver en Soles</span>
                  <button @click="verEnSoles = !verEnSoles"
                          :class="['relative inline-flex h-6 w-11 items-center rounded-full transition-colors focus:outline-none', verEnSoles ? 'bg-[#376875]' : 'bg-slate-300']">
                    <span :class="verEnSoles ? 'translate-x-6' : 'translate-x-1'"
                          class="inline-block h-4 w-4 transform rounded-full bg-white transition-transform" />
                  </button>
                </div>
              </div>
            </div>

            <div>
              <div class="flex items-center justify-between mb-1.5 ml-1">
                <label class="block text-[10px] font-black text-slate-500 uppercase">
                  Título Comercial ({{ store.cotizacion.idiomaEdicion.toUpperCase() }}) <span class="text-slate-300 normal-case">— opcional</span>
                </label>
                <button @click="toggleSobreescribirTraduccion"
                        :class="store.cotizacion?.sobreescribirTraduccion ? 'bg-orange-100 text-orange-600 border-orange-300' : 'bg-slate-50 text-slate-400 border-slate-200 hover:bg-slate-100'"
                        class="p-1 px-2 border rounded-lg transition-colors shadow-sm text-[10px] font-bold flex items-center gap-1"
                        title="Traducir automáticamente título y resumen a otros idiomas al guardar">
                  <i class="fas fa-language"></i>
                </button>
              </div>
              <input :value="store.getI18nText(store.cotizacion.titulo, store.cotizacion.idiomaEdicion)"
                     @input="e => { if (!store.cotizacion!.titulo) store.cotizacion!.titulo = []; store.setI18nText(store.cotizacion!.titulo, store.cotizacion!.idiomaEdicion, (e.target as HTMLInputElement).value) }"
                     type="text" placeholder="Ej: Cusco — Experiencia Mística"
                     class="w-full bg-white border border-slate-300 rounded-xl px-4 py-3 text-sm font-bold outline-none focus:ring-2 focus:ring-[#376875] shadow-sm">
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
                  :model-value="store.getI18nText(store.cotizacion?.resumen, store.cotizacion?.idiomaEdicion || 'es')"
                  @update:model-value="actualizarResumen"
              />
            </div>
            </div>
          </div>
        </div>
      </aside>

      <!-- ═══ PANEL 3: Detalle (servicio / componente / tarifa, md:order-3) ═══ -->
      <aside :class="[
          'flex-col overflow-hidden bg-white border-slate-200 md:order-3 shrink-0 w-full md:w-105 md:border-l relative',
          store.inspectorActivo === 'resumen' ? 'hidden' : (nivelEditor === 'detalle' ? 'flex' : 'hidden md:flex')
      ]">

        <div v-if="store.servicioActivo" class="flex-1 flex flex-col min-h-0">
          <div class="px-5 py-1 border-b border-emerald-100 flex items-center gap-3 bg-emerald-50 shrink-0">
            <button @click="store.retrocederNivel" class="w-8 h-8 rounded-full hover:bg-emerald-100 text-slate-500 flex items-center justify-center transition-colors shrink-0"><i class="fas fa-arrow-left"></i></button>

            <div class="flex-1 min-w-0">
              <p class="text-[9px] font-black text-[#E07845] uppercase tracking-widest truncate flex items-center gap-1.5">
                <span class="w-2 h-2 rounded-full bg-emerald-400 shrink-0"></span>
                Edición de Servicio
              </p>
              <h2 class="text-sm font-black truncate">
                {{ store.getI18nText(store.servicioActivo?.nombreInternoSnapshot, 'es') || store.getI18nText(store.servicioActivo?.tituloSnapshot, store.cotizacion.idiomaEdicion) }}
              </h2>
              <p v-if="store.serviciosOrdenados.length > 1" class="text-[11px] font-bold text-emerald-600/70 mt-0.5">
                Servicio {{ store.serviciosOrdenados.findIndex(s => s.id === store.servicioActivo?.id) + 1 }} de {{ store.serviciosOrdenados.length }}
              </p>
            </div>

            <div v-if="store.serviciosOrdenados.length > 1" class="flex flex-col gap-1 shrink-0 self-center">
              <button @click="store.irAServicioAdyacente(-1)"
                      :disabled="store.serviciosOrdenados.findIndex(s => s.id === store.servicioActivo?.id) === 0"
                      class="w-9 h-9 rounded-lg bg-white border border-emerald-200 text-emerald-600 flex items-center justify-center shadow-sm disabled:opacity-30 disabled:cursor-not-allowed active:scale-95 transition-all">
                <i class="fas fa-chevron-up text-xs"></i>
              </button>
              <button @click="store.irAServicioAdyacente(1)"
                      :disabled="store.serviciosOrdenados.findIndex(s => s.id === store.servicioActivo?.id) === store.serviciosOrdenados.length - 1"
                      class="w-9 h-9 rounded-lg bg-white border border-emerald-200 text-emerald-600 flex items-center justify-center shadow-sm disabled:opacity-30 disabled:cursor-not-allowed active:scale-95 transition-all">
                <i class="fas fa-chevron-down text-xs"></i>
              </button>
            </div>
          </div>
          <div class="p-5 flex-1 overflow-y-auto space-y-6 pb-28">
            <div class="bg-slate-50 border border-slate-200 p-4 rounded-xl space-y-4">
              <!-- ⚠️ Colapsable, y arranca ABIERTO.
                   Editar prestadores y compradores es lo que más se repite en una jornada, y
                   viven al final de la ficha: con este bloque desplegado había que pasar por
                   encima del catálogo, los dos nombres y la fecha cada vez que se cambiaba de
                   servicio — 17 veces en la cotización de la captura.
                   La preferencia se recuerda (`localStorage`) porque quien lo cierra lo cierra
                   para toda la sesión de trabajo, no para un servicio. -->
              <button type="button" @click="catalogoAbierto = !catalogoAbierto"
                      class="w-full flex items-center gap-2 text-left">
                <label class="block text-[10px] font-black text-[#E07845] uppercase tracking-widest cursor-pointer">
                  <i class="fas fa-book mr-1"></i> Catálogo Maestro
                </label>
                <!-- Cerrado, la cabecera tiene que decir QUÉ hay dentro: si no, hay que abrirlo
                     para saber de qué servicio se trata, y el atajo deja de serlo. -->
                <span v-if="!catalogoAbierto && store.servicioActivo"
                      class="text-[10px] font-bold text-slate-400 truncate min-w-0 flex-1">
                  {{ store.getI18nText(store.servicioActivo.nombreInternoSnapshot, 'es') }}
                  <span v-if="store.servicioActivo.fechaInicioAbsoluta" class="text-slate-300">· {{ store.servicioActivo.fechaInicioAbsoluta }}</span>
                </span>
                <i class="fas ml-auto text-slate-400 text-xs shrink-0"
                   :class="catalogoAbierto ? 'fa-chevron-up' : 'fa-chevron-down'"></i>
              </button>

              <template v-if="catalogoAbierto">
              <div>
                <SearchableSelect
                    v-model="store.servicioActivo.servicioMaestroId"
                    :options="opcionesServicios"
                    placeholder="Buscar servicio..."
                    @change="val => store.onServicioMaestroChange(val)"
                    @search="val => store.buscarServiciosAsincrono(val)"
                    :min-chars-busqueda="3"
                />
              </div>
              <!-- Servicio mono-segmento sin plantilla: el nombre del servicio es genérico y su
                   público se descarta para el cliente (pax sólo lo pinta con >1 segmento). El
                   segmento es el que dice qué cosa es → se muestra en read-only, sin persistir. -->
              <template v-if="store.esMonoSegmentoSinPlantilla(store.servicioActivo)">
                <div>
                  <label class="block text-[10px] font-black text-slate-500 uppercase mb-1.5 ml-1">
                    Nombre operativo <span class="text-slate-300 normal-case font-bold">(lo pone el segmento)</span>
                  </label>
                  <div class="bg-slate-50 border border-dashed border-slate-300 rounded-lg px-3 py-2 text-sm font-bold text-slate-600">
                    {{ store.segmentoUnicoMaestro(store.servicioActivo)?.nombreInterno || store.getI18nText(store.servicioActivo.cotsegmentos?.[0]?.tituloSnapshot, 'es') || '—' }}
                  </div>
                </div>
                <div>
                  <label class="block text-[10px] font-black text-slate-500 uppercase mb-1.5 ml-1">
                    Nombre Público <span class="text-slate-300 normal-case font-bold">(lo pone el segmento)</span>
                  </label>
                  <div class="bg-slate-50 border border-dashed border-slate-300 rounded-lg px-3 py-2 text-sm font-bold text-slate-600">
                    {{ store.getI18nText(store.servicioActivo.cotsegmentos?.[0]?.tituloSnapshot, store.cotizacion?.idiomaEdicion || 'es') || '—' }}
                  </div>
                  <p class="text-[9px] text-slate-400 mt-1 ml-1">
                    Este servicio es un solo segmento sin plantilla: su nombre lo pone el segmento, y el
                    del servicio no se usa. Para cambiarlo, edita el párrafo o el segmento en el catálogo.
                    Al aplicar una plantilla o añadir otro segmento, vuelven los campos editables.
                  </p>
                </div>
              </template>
              <template v-else>
              <div>
                <label class="block text-[10px] font-black text-slate-500 uppercase mb-1.5 ml-1">
                  Nombre operativo <span class="text-slate-300 normal-case font-bold">(interno / proveedor · no lo ve el cliente)</span>
                </label>
                <div class="flex gap-2">
                  <input :value="store.getI18nText(store.servicioActivo.nombreInternoSnapshot, 'es')"
                         @input="e => { if(store.servicioActivo) store.setI18nText(store.servicioActivo.nombreInternoSnapshot, 'es', (e.target as HTMLInputElement).value) }"
                         type="text" placeholder="Cómo lo nombras tú y lo pides al proveedor"
                         class="flex-1 bg-white border border-slate-300 rounded-lg px-3 py-2 text-sm font-bold focus:ring-2 focus:ring-[#376875] outline-none shadow-sm">
                  <!-- Copia el nombre público al operativo. Para los servicios genéricos —«Vuelo»,
                       «Alojamiento»— el detalle está en el público («Vuelo Lima Cusco») y el operador
                       se quedaba sin él. Un toque lo iguala; sigue siendo editable después. -->
                  <button v-if="store.getI18nText(store.servicioActivo.tituloSnapshot, 'es') && store.getI18nText(store.servicioActivo.tituloSnapshot, 'es') !== store.getI18nText(store.servicioActivo.nombreInternoSnapshot, 'es')"
                          @click="store.servicioActivo && store.setI18nText(store.servicioActivo.nombreInternoSnapshot, 'es', store.getI18nText(store.servicioActivo.tituloSnapshot, 'es'))"
                          class="px-3 shrink-0 bg-slate-100 hover:bg-[#376875] hover:text-white text-slate-500 border border-slate-200 rounded-lg transition-colors shadow-sm"
                          title="Copiar el nombre público aquí">
                    <i class="fas fa-arrow-up"></i>
                  </button>
                </div>
                <p class="text-[9px] text-slate-400 mt-1 ml-1">
                  Nace del nombre del servicio; al aplicar una plantilla toma el suyo. La flecha copia
                  el nombre público, útil en servicios genéricos («Vuelo» → «Vuelo Lima Cusco»). Un idioma.
                </p>
              </div>
              <div>
                <label class="block text-[10px] font-black text-slate-500 uppercase mb-1.5 ml-1">Nombre Público *</label>
                <div class="flex gap-2">
                  <input :value="store.getI18nText(store.servicioActivo.tituloSnapshot, store.cotizacion?.idiomaEdicion || 'es')"
                         @input="e => { if(store.cotizacion && store.servicioActivo) store.setI18nText(store.servicioActivo.tituloSnapshot, store.cotizacion.idiomaEdicion, (e.target as HTMLInputElement).value) }"
                         type="text" class="flex-1 bg-white border border-slate-300 rounded-lg px-3 py-2 text-sm font-bold focus:ring-2 focus:ring-[#376875] outline-none shadow-sm">

                  <button @click="store.servicioActivo.sobreescribirTraduccion = !store.servicioActivo.sobreescribirTraduccion"
                          :class="store.servicioActivo.sobreescribirTraduccion ? 'bg-orange-100 text-orange-600 border-orange-300' : 'bg-slate-50 text-slate-400 border-slate-200 hover:bg-slate-100'"
                          class="px-3 border rounded-lg transition-colors shadow-sm" title="Forzar traducción de este título al guardar">
                    <i class="fas fa-language"></i>
                  </button>
                </div>
              </div>
              </template>
              <div>
                <label class="block text-[10px] font-black text-slate-500 uppercase mb-1.5 ml-1"><i class="far fa-calendar-alt mr-1"></i> Fecha Ejecución (Milestone)</label>
                <input v-model="store.servicioActivo.fechaInicioAbsoluta" @change="store.onServicioFechaChange" type="date" class="w-full bg-white border border-slate-300 rounded-lg px-3 py-2 text-sm font-bold focus:ring-2 focus:ring-[#376875] outline-none shadow-sm">
              </div>
              </template>

            </div>

            <div class="bg-teal-50 border border-teal-100 rounded-xl p-4">
              <div class="flex items-start justify-between gap-2 mb-2">
                <div>
                  <h3 class="text-[10px] font-black text-teal-700 uppercase tracking-widest"><i class="fas fa-align-left mr-1"></i> Storytelling</h3>
                  <p class="text-[10px] text-teal-500 mt-1 font-medium">{{ store.getI18nText(store.servicioActivo.itinerarioNombreInternoSnapshot, 'es') }}</p>
                </div>
                <button @click="store.servicioActivo.servicioMaestroId && store.abrirEditorSegmentos()"
                        :disabled="!store.servicioActivo.servicioMaestroId"
                        :class="!store.servicioActivo.servicioMaestroId ? 'bg-slate-300 text-slate-500 cursor-not-allowed shadow-none' : 'bg-teal-600 hover:bg-teal-700 text-white'"
                        class="px-3 py-2 rounded-lg text-[10px] font-bold shadow-sm whitespace-nowrap transition-colors">
                  <i class="fas fa-pencil-alt mr-1"></i> Configurar
                </button>
              </div>
            </div>

            <div class="border-t border-slate-100 pt-5">
              <h3 class="text-[10px] font-black text-sky-600 uppercase tracking-widest mb-3 flex items-center justify-between">
                <span>Componentes Logísticos</span>
                <!-- ⚠️ UN botón, no dos.
                     Estuvieron «+ Añadir Extra» y «Manual» separados, y era una decisión mal
                     colocada: los dos creaban exactamente lo mismo y la diferencia sólo se veía
                     DESPUÉS, al abrir la ficha. De catálogo o a mano se elige dentro, donde se
                     puede cambiar de opinión sin borrar y volver a crear. -->
                <button @click="store.agregarComponente(servicioActivoId)" class="bg-sky-100 text-sky-700 px-3 py-1.5 rounded-lg text-xs md:text-sm font-bold shadow-sm border border-sky-200 hover:bg-sky-200 transition-colors">+ Añadir Extra</button>
              </h3>
              <div class="space-y-3">

                <div v-for="comp in store.servicioActivo.cotcomponentes" :key="comp.id"
                     @click="store.abrirNivel('componente', comp)"
                     class="bg-white border-2 rounded-xl p-4 shadow-sm cursor-pointer relative group overflow-hidden transition-all flex flex-col min-h-35"
                     :class="[
                        store.isComponenteConAlerta(comp) ? 'border-red-400 bg-red-50/20' :
                        (!!comp.sinHorario ? 'border-dashed border-slate-300 hover:border-slate-400 bg-slate-50/50' : 'border-slate-200 hover:border-sky-300')
                     ]">

                  <div class="absolute left-0 top-0 bottom-0 w-1.5"
                       :class="store.isComponenteConAlerta(comp) ? 'bg-red-400' : (!!comp.sinHorario ? 'bg-slate-300' : 'bg-sky-400')"></div>

                  <button v-if="!store.isComponenteBloqueado(comp)" @click.stop="store.eliminarComponente(servicioActivoId, comp.id)" class="absolute right-3 top-3 text-slate-300 hover:text-red-500 transition-colors z-10 bg-slate-50 w-7 h-7 rounded-full flex justify-center items-center">
                    <i class="fas fa-trash-alt text-sm"></i>
                  </button>

                  <div class="flex justify-between items-start mb-3">
                    <h4 class="font-black text-sm text-slate-800 leading-tight pr-8 flex flex-col">
                      <span class="flex items-center gap-1.5">
                        <i v-if="store.isComponenteConAlerta(comp)" class="fas fa-exclamation-triangle text-red-500" title="Tarifas no cuadran"></i>
                        {{ etiquetaDeComponente(comp) }}
                      </span>
                      <!-- ⚠️ «Horario libre» es del COMPONENTE; «al final del día» es del
                           SERVICIO, y estaban pegados en la misma etiqueta. Un check-in sin hora
                           junto a un almuerzo de las 12:30 decía «final del día» cuando su
                           servicio ordena a mediodía: la mitad de la frase era falsa. Ahora la
                           coletilla sólo aparece cuando NINGÚN componente del servicio tiene
                           hora, que es cuando el bloque entero sí se va al final. -->
                      <span v-if="!!comp.sinHorario" class="text-[9px] font-black text-slate-400 uppercase tracking-widest mt-1">
                         <i class="fas fa-infinity text-[8px] mr-0.5"></i> Horario Libre<span v-if="servicioActivoSinHora"> / Final del día</span>
                      </span>
                    </h4>
                    <!-- ⚠️ `pr-9` reserva el hueco de la papelera.
                         La papelera es `absolute right-3 top-3` y estos badges van en flujo normal
                         pegados a la derecha, así que el primero le quedaba DEBAJO: en un móvil
                         «INCLUIDO» aparecía cortado y con el icono encima. El `pr` es el ancho del
                         botón (28 px) más su separación; no se toca la papelera porque su posición
                         absoluta es lo que la mantiene fija al pasar la tarjeta a columna.

                         ⚠️ **Y sólo cuando la papelera EXISTE.** Un componente bloqueado —los
                         inyectados desde un párrafo, «Insumo Autogenerado»— no la lleva, así que
                         el hueco se quedaba reservado para un botón que no está y las pastillas
                         flotaban a 36 px del borde. Misma condición que el botón, para que las dos
                         no puedan discrepar. -->
                    <div class="flex flex-col items-end gap-1 shrink-0"
                         :class="{ 'pr-9': !store.isComponenteBloqueado(comp) }">
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
                      {{ !comp.sinHorario ? 'INICIO: ' + formatDateTimeFromISO(comp.fechaHoraInicio) : 'FECHA: ' + formatDateOnlyFromISO(comp.fechaHoraInicio) }}
                    </span>

                    <!--
                      Prestador: quién OPERA el componente, que no siempre es a quién se le
                      compra. Sólo aparece si está resuelto — un componente sin prestador es
                      lo normal (se hereda del día) y una pastilla vacía en cada tarjeta sería
                      ruido. Sale del snapshot, no consulta el catálogo.
                    -->
                    <span v-if="comp.prestadorNombreSnapshot"
                          class="bg-emerald-50 border border-emerald-100 text-emerald-800 px-2.5 py-1.5 rounded-lg text-[10px] font-black shadow-sm flex items-center gap-2 w-max max-w-full"
                          title="PRESTADOR: quién opera el servicio en destino (no necesariamente a quién se le compra)">
                      <i class="fas fa-truck-field text-emerald-500 shrink-0"></i>
                      <span class="text-emerald-500 shrink-0">PRESTADOR</span>
                      <span class="truncate">{{ comp.prestadorNombreSnapshot }}</span>
                    </span>

                    <!--
                      Comprador: a quién se le ENCARGA la compra, que casi nunca es quien
                      presta. Sólo aparece cuando existe, y por eso mismo importa verlo: el
                      campo está vacío en la mayoría de componentes —se le compra directo al
                      prestador— así que una pastilla aquí significa que hay un intermediario.

                      Y es el dato del que sale la ORDEN DE SERVICIO: se emite a nombre del
                      comprador, no del prestador. Sin esto había que abrir el componente para
                      saber a quién se le va a pedir.
                    -->
                    <span v-if="comp.compradorNombreSnapshot"
                          class="bg-violet-50 border border-violet-100 text-violet-800 px-2.5 py-1.5 rounded-lg text-[10px] font-black shadow-sm flex items-center gap-2 w-max max-w-full"
                          title="COMPRADOR: a quién se le encarga la compra. La Orden de Servicio sale a su nombre.">
                      <i class="fas fa-cart-shopping text-violet-500 shrink-0"></i>
                      <span class="text-violet-500 shrink-0">COMPRADOR</span>
                      <span class="truncate">{{ comp.compradorNombreSnapshot }}</span>
                    </span>

                    <div v-if="comp.cantidad && comp.cantidad !== 1"
                         class="flex items-center gap-2 pl-4">
                      <div class="w-px h-3 bg-slate-300"></div>
                      <span class="text-[9px] font-black text-orange-600 bg-orange-50 border border-orange-200 px-2 py-0.5 rounded-full flex items-center gap-1 shadow-sm">
                        <i class="fas fa-moon text-[8px]"></i> {{ comp.cantidad }} noches
                      </span>
                    </div>

                    <span v-if="!comp.sinHorario || store.calcularPernoctes(comp.fechaHoraInicio, comp.fechaHoraFin) > 1" class="bg-slate-100 border border-slate-200 text-slate-700 px-2.5 py-1.5 rounded-lg text-[10px] font-black shadow-sm flex items-center gap-2 w-max">
                      <i class="far fa-flag text-slate-400"></i>
                      {{ !comp.sinHorario ? 'FIN: ' + formatDateTimeFromISO(comp.fechaHoraFin) : 'HASTA: ' + formatDateOnlyFromISO(comp.fechaHoraFin) }}
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
    <span class="text-[9px] font-black px-2 py-1 rounded-lg border shadow-sm flex items-center gap-1.5 cursor-help select-none"
          :class="bloque.audiencias?.includes('cliente') ? 'bg-sky-50 text-sky-700 border-sky-100' : 'bg-slate-100 text-slate-600 border-slate-200'">
      <i v-for="a in bloque.audiencias" :key="a" class="fas" :class="AUDIENCIA_DETALLE_CONFIG[a]?.icon"
         :title="AUDIENCIA_DETALLE_CONFIG[a]?.documento"></i>
      {{ (bloque.audiencias ?? []).map(a => AUDIENCIA_DETALLE_CONFIG[a]?.label).filter(Boolean).join(' · ') || 'Sin audiencia' }}
    </span>
                      <div v-if="tooltipDetalleActivo === bloque.id"
                           class="absolute z-30 bottom-full left-0 mb-2 w-52 bg-slate-900 text-white text-[10px] font-medium p-2.5 rounded-lg shadow-xl leading-snug">
                        {{ store.getI18nText(bloque.detalle, store.cotizacion.idiomaEdicion) || 'Sin contenido' }}
                      </div>
                    </div>
                  </div>

                  <div v-if="comp.cottarifas?.length" class="mt-auto pt-3 border-t border-slate-100 grid grid-cols-1 gap-2">
                    <div v-for="tarifa in comp.cottarifas" :key="tarifa.id"
                         class="flex items-center justify-between bg-slate-50 hover:bg-orange-50 p-2 rounded-lg border border-slate-200 transition-colors">
                      <div class="flex flex-col min-w-0 pr-2">
                        <!-- 🔥 CAMBIO: Renderizar el nombre interno o el título público -->
                        <span class="text-[10px] font-black text-slate-700 uppercase truncate leading-none mb-1">
                          {{ tarifa.nombreInternoSnapshot || store.getI18nText(tarifa.tituloSnapshot, store.cotizacion.idiomaEdicion) || 'Tarifa Manual' }}
                        </span>

                        <span class="text-[9px] font-bold text-slate-400 flex items-center gap-1 leading-none">
                          <i :class="tarifa.esGrupal ? 'fas fa-users text-orange-400' : 'fas fa-user text-sky-400'"></i>
                          {{ tarifa.esGrupal ? '1 GRUPO' : `${tarifa.cantidad} Pax` }}
                        </span>

                        <!--
                          A quién se le compra esta tarifa. Sólo si existe: una tarifa sin
                          proveedor asignado es lo normal en el catálogo maestro y una línea
                          vacía o un «sin proveedor» en cada ficha sería ruido. El dato ya
                          viaja en el snapshot, no hace falta pedir nada al catálogo.
                        -->
                        <!--
                          Icono y color DISTINTOS a los del prestador a propósito: son dos
                          cosas que suelen coincidir pero no son la misma. Aquí «compra»
                          (carrito, violeta) = a quién se le paga; en la tarjeta del
                          componente «prestador» (camión, verde) = quién opera. Se usa la
                          misma palabra COMPRA que ya emplea el cuadro de tráfico, para que
                          el vocabulario sea uno solo en toda la aplicación.
                        -->
                        <span v-if="store.componenteActualDeTarifa?.prestadorNombreSnapshot"
                              class="text-[9px] font-bold text-violet-600 flex items-center gap-1 leading-none mt-1 truncate"
                              title="COMPRA: a quién se le compra esta tarifa (no necesariamente quién la opera)">
                          <i class="fas fa-cart-shopping text-[8px] shrink-0"></i>
                          <span class="text-violet-400 shrink-0">COMPRA</span>
                          <span class="truncate">{{ store.componenteActualDeTarifa?.prestadorNombreSnapshot }}</span>
                        </span>
                      </div>
                      <div class="text-right shrink-0">
                        <span class="text-[11px] font-black" :class="comp.modo === 'no_incluido' ? 'text-slate-400 line-through' : 'text-orange-600'">
                          {{ formatMoneda(Number(tarifa.montoCosto) * (tarifa.esGrupal ? 1 : tarifa.cantidad), tarifa.moneda) }}
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

        <div v-else-if="store.componenteActivo" class="flex-1 flex flex-col min-h-0 bg-sky-50/50">
          <div class="px-5 py-2 border-b border-sky-200 flex items-center gap-3 bg-sky-600 text-white shrink-0">
            <button @click="store.retrocederNivel" class="w-8 h-8 rounded-full hover:bg-sky-500 flex items-center justify-center transition-colors shrink-0"><i class="fas fa-arrow-left"></i></button>

            <div class="flex-1 min-w-0">
              <p class="text-[11px] font-black text-sky-200 uppercase tracking-widest truncate flex items-center gap-1">
                <i class="fas fa-route"></i>
                {{ store.getI18nText(store.servicioActualDeComponente?.tituloSnapshot, store.cotizacion.idiomaEdicion) || 'Servicio' }}
              </p>
              <h2 class="text-sm font-black truncate">{{ etiquetaDeComponente(store.componenteActivo) }}</h2>
              <p v-if="store.componentesHermanos.length > 1 || etiquetaDeComponente(store.componenteActivo) !== getNombreMaestroRef(store.componenteActivo)"
                 class="text-[11px] font-bold text-sky-200 mt-0.5 truncate">
                <span v-if="store.componentesHermanos.length > 1">
                  Componente {{ store.componentesHermanos.findIndex(c => c.id === store.componenteActivo?.id) + 1 }} de {{ store.componentesHermanos.length }}
                </span>
                <!-- El insumo, en pequeño: la cabecera ya no lo lleva arriba, pero saber de qué
                     componente del catálogo cuelga la línea sigue haciendo falta. -->
                <span v-if="etiquetaDeComponente(store.componenteActivo) !== getNombreMaestroRef(store.componenteActivo)">
                  <span v-if="store.componentesHermanos.length > 1"> · </span>
                  <i class="fas fa-box-open text-[9px]"></i> {{ getNombreMaestroRef(store.componenteActivo) }}
                </span>
              </p>
            </div>

            <div v-if="store.componentesHermanos.length > 1" class="flex flex-col gap-1 shrink-0">
              <button @click="store.irAComponenteAdyacente(-1)"
                      :disabled="store.componentesHermanos.findIndex(c => c.id === store.componenteActivo?.id) === 0"
                      class="w-9 h-9 rounded-lg bg-sky-500/60 hover:bg-sky-400 text-white flex items-center justify-center shadow-sm disabled:opacity-30 disabled:cursor-not-allowed active:scale-95 transition-all">
                <i class="fas fa-chevron-up text-xs"></i>
              </button>
              <button @click="store.irAComponenteAdyacente(1)"
                      :disabled="store.componentesHermanos.findIndex(c => c.id === store.componenteActivo?.id) === store.componentesHermanos.length - 1"
                      class="w-9 h-9 rounded-lg bg-sky-500/60 hover:bg-sky-400 text-white flex items-center justify-center shadow-sm disabled:opacity-30 disabled:cursor-not-allowed active:scale-95 transition-all">
                <i class="fas fa-chevron-down text-xs"></i>
              </button>
            </div>

          </div>
          <div class="p-5 flex-1 overflow-y-auto space-y-6 pb-28">
            <div class="bg-white border border-sky-200 p-4 rounded-xl shadow-sm">

              <!-- ── De dónde sale este componente ───────────────────────────
                   La decisión vive AQUÍ y no en el botón de alta: así se cambia de opinión sin
                   borrar y volver a crear, y se ve qué implica cada lado — con catálogo el
                   nombre y el tipo los pone el maestro; a mano se escriben.

                   Oculto si el componente vino inyectado desde un segmento: ahí no se elige
                   nada, y ofrecer el conmutador sería mentir. -->
              <div v-if="!store.isComponenteBloqueado(store.componenteActivo)"
                   class="flex bg-slate-100 rounded-xl p-1 mb-3 gap-1">
                <button type="button"
                        @click="store.componenteActivo.esManual = false"
                        class="flex-1 px-3 py-2 rounded-lg text-[11px] font-black uppercase tracking-wider transition-colors"
                        :class="!store.componenteActivo.esManual ? 'bg-white text-sky-700 shadow-sm' : 'text-slate-400 hover:text-slate-600'">
                  <i class="fas fa-box-open mr-1"></i> Del catálogo
                </button>
                <button type="button"
                        @click="store.marcarComponenteManual()"
                        class="flex-1 px-3 py-2 rounded-lg text-[11px] font-black uppercase tracking-wider transition-colors"
                        :class="store.componenteActivo.esManual ? 'bg-white text-slate-700 shadow-sm' : 'text-slate-400 hover:text-slate-600'">
                  <i class="fas fa-pen-nib mr-1"></i> A mano
                </button>
              </div>

              <p v-if="store.componenteActivo.esManual" class="text-[11px] font-bold text-slate-500 leading-snug">
                Fuera del catálogo: el nombre y la categoría se escriben abajo.
              </p>

              <template v-else>
              <label class="block text-[10px] font-black text-sky-600 uppercase tracking-widest mb-2"><i class="fas fa-box-open mr-1"></i> Insumo Maestro</label>

              <SearchableSelect
                  v-if="!store.isComponenteBloqueado(store.componenteActivo)"
                  v-model="store.componenteActivo.componenteMaestroId"
                  :options="opcionesComponentes"
                  placeholder="Buscar insumo..."
                  limpiable
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
                      <span class="text-sm font-black text-teal-900 mt-0.5">{{ getNombreMaestroRef(store.componenteActivo) }}</span>
                    </div>
                  </div>
                </div>
              </div>
              </template>

              <!-- ── Categoría Operativa, SÓLO en modo manual ─────────────────
                   Con catálogo el tipo lo pone el maestro y ahí tiene que seguir mandando: un
                   componente que dijera una cosa y su maestro otra no lo denunciaría nada después.
                   Y en modo catálogo SIN insumo elegido tampoco sale, porque elegirlo lo pisaría
                   acto seguido: ofrecer un campo que se va a sobreescribir es peor que no tenerlo.

                   ⚠️ No es cosmético. De este campo cuelga si el componente aporta punto de
                   recojo y entrega (`ComponenteTipoEnum::puntosDeServicio()`): `transporte` da
                   los dos, `extras` —con el que nace— no da ninguno **y no da error**. -->
              <div v-if="store.componenteActivo.esManual" class="mt-4">
                <label class="block text-[10px] font-black text-sky-600 uppercase tracking-widest mb-2">
                  <i class="fas fa-tag mr-1"></i> Categoría Operativa
                </label>
                <select :value="store.componenteActivo.tipo || 'extras'"
                        @change="e => store.onTipoManualChange((e.target as HTMLSelectElement).value)"
                        class="w-full bg-white border border-slate-300 rounded-xl px-4 py-3 text-sm font-bold outline-none shadow-sm focus:ring-2 focus:ring-sky-500">
                  <option v-for="t in store.catalogos.tiposComponente" :key="t.id" :value="t.id">
                    {{ getTipoComponenteConfig(t.id).label }}
                  </option>
                </select>
                <p class="text-[10px] font-bold text-slate-400 mt-1.5 ml-1">
                  Decide si el componente lleva hora y si aporta punto de recojo y entrega.
                </p>
              </div>

              <!-- ── Ubicaciones, SÓLO en modo manual ─────────────────────────
                   Con maestro las pone el catálogo y se resuelven en vivo: re-etiquetar allí se
                   refleja aquí sin tocar la cotización. Ofrecer el campo también en ese modo
                   crearía una segunda respuesta a la misma pregunta, y cuál gana dependería de
                   qué pantalla la haga — por eso vincular un insumo las borra, aquí y en el
                   backend (`CotizacionCotcomponente::setComponenteMaestroId()`).

                   De esto cuelga que la fila salga etiquetada en el cuadro de tráfico y que la
                   encuentre el filtro por lugar. Sin ubicación cae en «Sin etiqueta», que es
                   donde caían TODOS los manuales hasta ahora. -->
              <div v-if="store.componenteActivo.esManual" class="mt-4">
                <label class="block text-[10px] font-black text-sky-600 uppercase tracking-widest mb-2">
                  <i class="fas fa-location-dot mr-1"></i> Ubicaciones
                </label>
                <SearchableSelect
                    v-model="store.componenteActivo.lugaresManuales"
                    :options="opcionesLugares"
                    placeholder="Buscar ubicación..."
                    multiple
                    limpiable
                />
                <p class="text-[10px] font-bold text-slate-400 mt-1.5 ml-1">
                  Desde dónde se opera. Etiqueta la fila en el cuadro de tráfico y la hace
                  aparecer al filtrar por lugar.
                </p>
              </div>
            </div>

            <div class="grid grid-cols-2 gap-4">

              <!-- ══ TARJETA DE IDENTIFICADORES, con dos caras ══════════════
                   Delante lo que lee alguien de verdad; detrás lo secundario. Cuál es cuál lo
                   decide el tipo, igual que en la orden (ver `mostrandoComponente`).

                   ⚠️ **No es un flip 3D.** `transform: rotateY()` crea contexto de apilamiento y
                   rompe el posicionamiento de lo que lleve dentro —aquí hay inputs y, dos bloques
                   más abajo, un VueDatePicker teleportado—. Se intercambia el contenido con una
                   transición: mismo gesto, sin ese riesgo. Misma familia de trampa que la «x» de
                   `clearable`, ver docs/UI_Componentes_Compartidos.md §1.5. -->
              <div class="col-span-2 bg-white border border-slate-200 rounded-xl shadow-sm overflow-hidden">
                <div class="flex items-center justify-between gap-2 px-4 py-2 bg-slate-50 border-b border-slate-200">
                  <span class="text-[9px] font-black uppercase tracking-widest"
                        :class="traseraAbierta ? 'text-slate-400' : 'text-sky-600'">
                    <i class="fas" :class="traseraAbierta ? 'fa-rotate-left' : 'fa-eye'"></i>
                    {{ traseraAbierta ? 'Lo secundario' : 'Lo que se lee: cliente y proveedor' }}
                  </span>
                  <!-- Sin párrafo no hay segunda cara: el botón desaparece en vez de llevar a
                       una disculpa. Se dice por qué, para que no parezca que falta algo. -->
                  <button v-if="tieneParrafo" type="button" @click="traseraAbierta = !traseraAbierta"
                          class="shrink-0 flex items-center gap-1.5 px-2.5 py-1 rounded-lg border border-slate-300 bg-white text-[10px] font-black uppercase tracking-wider text-slate-500 hover:bg-slate-100 hover:text-slate-700 transition-colors shadow-sm">
                    <i class="fas fa-repeat text-[9px]"></i>
                    {{ traseraAbierta ? 'Volver' : 'Voltear' }}
                  </button>
                  <span v-else class="shrink-0 text-[9px] font-bold text-slate-400 normal-case">
                    sin párrafo: lo nombra él mismo
                  </span>
                </div>

                <!-- ── Cara del SEGMENTO: sólo lectura, porque el dato vive en el párrafo ──
                     Editable aquí sería una segunda puerta al mismo campo, y la de verdad está
                     en el segmento. -->
                <transition name="fade-cara" mode="out-in">
                <div v-if="!mostrandoComponente" key="seg" class="p-4 grid gap-3">
                    <div>
                      <label class="block text-[10px] font-black text-slate-500 uppercase mb-1 ml-1">
                        Título del párrafo <span class="text-slate-400 normal-case font-bold">— lo que ve el pasajero</span>
                      </label>
                      <p class="px-4 py-3 bg-slate-50 border border-slate-200 rounded-xl text-sm font-bold text-slate-700">
                        {{ store.getI18nText(store.segmentoDeComponente(store.componenteActivo)?.tituloSnapshot, store.cotizacion?.idiomaEdicion || 'es') || '—' }}
                      </p>
                    </div>
                    <div>
                      <label class="block text-[10px] font-black text-slate-500 uppercase mb-1 ml-1">
                        Nombre interno del párrafo <span class="text-slate-400 normal-case font-bold">— encabeza la orden</span>
                      </label>
                      <p class="px-4 py-3 bg-slate-50 border border-slate-200 rounded-xl text-sm font-bold text-slate-700">
                        {{ store.getI18nText(store.segmentoDeComponente(store.componenteActivo)?.nombreInternoSnapshot, store.cotizacion?.idiomaEdicion || 'es') || '—' }}
                      </p>
                    </div>
                    <p class="text-[10px] font-bold text-slate-400 leading-snug ml-1">
                      Se editan en el párrafo, no aquí: es el mismo dato y una segunda puerta acabaría
                      con dos versiones. Voltea la tarjeta para el nombre del insumo.
                    </p>
                </div>

                <div v-else key="comp" class="p-4 grid gap-3">
              <!-- ── El nombre INTERNO, en TODOS los componentes ─────────────
                   Éste es el que mandan la orden y el cuadro de tráfico; el de abajo es lo único
                   que ve el pasajero.

                   ⚠️ Estuvo limitado a los manuales, con el argumento de que «los de catálogo ya
                   tienen el del maestro». Es cierto y no basta: dejaba **sin forma de ajustar el
                   nombre operativo en ESTE expediente**. La única salida era renombrar el maestro,
                   que cambia el catálogo entero por un caso puntual — y encima ese cambio no llega
                   solo a La Biblia: hay que aprobarlo por reconciliación.

                   ⚠️ **Dejarlo en blanco lo DEVUELVE al del catálogo, no lo vacía.** Desde que
                   la ruta del nombre es única, el resolutor no consulta el maestro: si esto queda
                   vacío cae al título PÚBLICO —«Transporte» a secas— y la fila pierde su nombre
                   operativo. Así que el vaciado se trata como «revertir»: al salir del campo se
                   repone el del catálogo, y el snapshot nunca se queda sin valor.

                   Es la única forma de que la promesa de abajo sea verdad. Estuvo tres horas
                   siendo mentira —la pantalla decía «en blanco usa el del catálogo» mientras el
                   resolutor devolvía el título público—, que es justo el fallo que esta tanda
                   venía a matar: no falla, devuelve otra cosa plausible.

                   Ver `BibliaSnapshotService::resolverNombreComponente()` y docs §2.b. -->
              <div>
                <label class="block text-[10px] font-black text-slate-500 uppercase mb-1.5 ml-1">
                  Nombre interno <span class="text-slate-400 normal-case font-bold">— para la orden y La Biblia</span>
                </label>
                <input :value="store.componenteActivo.nombreInternoSnapshot ?? ''"
                       @input="e => { if (store.componenteActivo) store.componenteActivo.nombreInternoSnapshot = (e.target as HTMLInputElement).value || null }"
                       @blur="reponerNombreDelCatalogoSiQuedoVacio"
                       type="text" maxlength="255"
                       :placeholder="nombreMaestroDelComponenteActivo || 'Traslado a La Olla de Juanita (ida)'"
                       class="w-full bg-white border border-slate-300 rounded-xl px-4 py-3 text-sm font-bold outline-none shadow-sm focus:ring-2 focus:ring-sky-500 placeholder:text-slate-300 placeholder:font-medium">
                <p v-if="nombreMaestroDelComponenteActivo" class="text-[10px] text-slate-400 font-bold mt-1 ml-1">
                  Bórralo para volver al del catálogo: «{{ nombreMaestroDelComponenteActivo }}»
                </p>
              </div>

              <div>
                <!-- ⚠️ El asterisco cae en transporte, tren y vuelo: ahí este texto NO se publica.
                     Las dos superficies del cliente —el itinerario y el «qué incluye»— toman el
                     título del párrafo, así que marcarlo obligatorio pedía pulir algo que no se
                     enseña. Sigue guardándose: es el último recurso de La Biblia si el nombre
                     interno quedara vacío. -->
                <label class="block text-[10px] font-black text-slate-500 uppercase mb-1.5 ml-1">
                  Nombre Público <span v-if="elComponenteSePublica">*</span>
                  <span v-else class="text-slate-400 normal-case font-bold">— aquí no se publica: manda el párrafo</span>
                </label>

                <div class="flex gap-2" v-if="!isComponenteSoloItems(store.componenteActivo)">
                  <input :value="store.getI18nText(store.componenteActivo.tituloSnapshot, store.cotizacion?.idiomaEdicion || 'es')"
                         @input="e => { if(store.cotizacion && store.componenteActivo) store.setI18nText(store.componenteActivo.tituloSnapshot, store.cotizacion.idiomaEdicion, (e.target as HTMLInputElement).value) }"
                         type="text" class="flex-1 bg-white border border-slate-300 rounded-xl px-4 py-3 text-sm font-bold outline-none shadow-sm focus:ring-2 focus:ring-sky-500">

                  <button @click="store.componenteActivo.sobreescribirTraduccion = !store.componenteActivo.sobreescribirTraduccion"
                          :class="store.componenteActivo.sobreescribirTraduccion ? 'bg-orange-100 text-orange-600 border-orange-300' : 'bg-slate-50 text-slate-400 border-slate-200 hover:bg-slate-100'"
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

                  <!-- De qué insumo del catálogo cuelga la línea. Sólo lectura: elegirlo se hace
                       arriba, y aquí sólo hace falta saber cuál es. -->
                  <p v-if="nombreMaestroDelComponenteActivo" class="text-[10px] font-bold text-slate-400 leading-snug ml-1">
                    <i class="fas fa-box-open mr-1"></i> Insumo: {{ nombreMaestroDelComponenteActivo }}
                  </p>
                </div>
                </transition>
              </div>
              <!-- ══ fin de la tarjeta de identificadores ══ -->

              <div class="col-span-2 grid grid-cols-2 gap-4 p-4 bg-white border border-slate-200 rounded-xl shadow-sm">

                <div>
                  <label class="block text-[10px] font-black text-slate-500 uppercase mb-1.5 ml-1">Inicio Exacto *</label>
                  <!-- ⚠️ Sin «x». Un componente sin inicio o sin fin no existe —la etiqueta ya lo
                       marca obligatorio— y ese botón sólo servía para dejar el formulario en un
                       estado que el backend rechaza, con el error a dos pantallas de distancia.
                       Es la misma decisión que `borrable=false` en FechaHoraPicker, sólo que aquí
                       se usa VueDatePicker directo y `clearable` viene a true por defecto.

                       ⚠️ Y va DENTRO de `input-attrs`: en vue-datepicker 14 `clearable` dejó de
                       ser prop de primer nivel. Suelto no falla —Vue lo pasa como atributo— y la
                       «x» se queda. Ver docs/UI_Componentes_Compartidos.md §1.5. -->
                  <VueDatePicker
                      teleport="body"
                      :teleport-center="esEstrecha"
                      :input-attrs="{ clearable: false }"
                      :model-value="store.componenteActivo.fechaHoraInicio"
                      @update:model-value="onInicioChange"
                      :is-24="true"
                      :enable-time-picker="!store.componenteActivo.sinHorario"
                      :format="!store.componenteActivo.sinHorario ? 'dd/MM/yyyy HH:mm' : 'dd/MM/yyyy'"
                      model-type="yyyy-MM-dd'T'HH:mm:ss"
                      auto-apply
                  >
                    <template #dp-input="{ onEnter, onTab }">
                      <input v-if="!store.componenteActivo.sinHorario"
                             type="text"
                             class="w-full bg-white border border-slate-300 rounded-lg pl-2 pr-2 py-2 text-[10px] font-bold text-slate-700 tabular-nums tracking-tight outline-none shadow-sm focus:ring-2 focus:ring-sky-500 cursor-text"
                             :value="formatParaMascara(store.componenteActivo.fechaHoraInicio)"
                             v-strict-mask="(val: string) => procesarFechaMascara(val, 'inicio')"
                             @keydown.enter="onEnter"
                             @keydown.tab="onTab"
                             placeholder="DD/MM/AAAA HH:MM"
                      />
                      <input v-else
                             type="text"
                             class="w-full bg-white border border-slate-300 rounded-lg pl-2 pr-2 py-2 text-[10px] font-bold text-slate-700 tabular-nums tracking-tight outline-none shadow-sm focus:ring-2 focus:ring-sky-500 cursor-text"
                             :value="formatFechaCortaParaMascara(store.componenteActivo.fechaHoraInicio)"
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
                  <!-- Sin «x», por lo mismo que el de inicio. -->
                  <VueDatePicker
                      teleport="body"
                      :teleport-center="esEstrecha"
                      :input-attrs="{ clearable: false }"
                      :key="finPickerKey"
                      v-model="store.componenteActivo.fechaHoraFin"
                      @update:model-value="store.onComponenteFechasChange()"
                      :is-24="true"
                      :enable-time-picker="!store.componenteActivo.sinHorario"
                      :format="!store.componenteActivo.sinHorario ? 'dd/MM/yyyy HH:mm' : 'dd/MM/yyyy'"
                      model-type="yyyy-MM-dd'T'HH:mm:ss"
                      auto-apply
                  >
                    <template #dp-input="{ onEnter, onTab }">
                      <input v-if="!store.componenteActivo.sinHorario"
                             type="text"
                             class="w-full bg-white border border-slate-300 rounded-lg pl-2 pr-2 py-2 text-[10px] font-bold text-slate-700 tabular-nums tracking-tight outline-none shadow-sm focus:ring-2 focus:ring-sky-500 cursor-text"
                             :value="formatParaMascara(store.componenteActivo.fechaHoraFin)"
                             v-strict-mask="(val: string) => procesarFechaMascara(val, 'fin')"
                             @keydown.enter="onEnter"
                             @keydown.tab="onTab"
                             placeholder="DD/MM/AAAA HH:MM"
                      />
                      <input v-else
                             type="text"
                             class="w-full bg-white border border-slate-300 rounded-lg pl-2 pr-2 py-2 text-[10px] font-bold text-slate-700 tabular-nums tracking-tight outline-none shadow-sm focus:ring-2 focus:ring-sky-500 cursor-text"
                             :value="formatFechaCortaParaMascara(store.componenteActivo.fechaHoraFin)"
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
                <input v-model="store.componenteActivo.cantidad" type="number" readonly class="w-full bg-slate-100 text-slate-400 border border-slate-200 rounded-xl px-4 py-3 text-xs font-black text-center outline-none shadow-inner cursor-not-allowed">
              </div>

              <div class="col-span-2 grid grid-cols-1 gap-3">
                <div>
                  <label class="block text-[10px] font-black text-slate-500 uppercase mb-1.5 ml-1">Modo Comercial</label>
                  <div class="relative">
                    <select v-model="store.componenteActivo.modo"
                            @change="store.onCambioModoComponente(store.componenteActivo)"
                            class="w-full appearance-none rounded-xl px-4 py-2.5 pr-9 text-xs font-black uppercase tracking-wide outline-none shadow-sm border cursor-pointer transition-colors"
                            :class="[getModoItemConfig(store.componenteActivo.modo).bg, getModoItemConfig(store.componenteActivo.modo).text, getModoItemConfig(store.componenteActivo.modo).border]">
                      <option value="incluido">Incluido</option>
                      <option value="no_incluido">No incluido</option>
                      <option value="cortesia">Cortesía</option>
                      <option value="reemplazado">Reemplazado</option>
                    </select>
                    <i class="fas absolute right-3 top-1/2 -translate-y-1/2 pointer-events-none text-xs"
                       :class="[getModoItemConfig(store.componenteActivo.modo).icon, getModoItemConfig(store.componenteActivo.modo).text]"></i>
                  </div>
                </div>

                <!-- Sólo dice si el componente sigue en pie. La confirmación del
                     proveedor NO se registra aquí: vive en el estado de reserva de La
                     Biblia, que es donde se gestiona. Este selector llegó a ofrecer
                     «Confirmado» y «Reconfirmado» sin que nadie los leyera. -->
                <div>
                  <label class="block text-[10px] font-black text-slate-500 uppercase mb-1.5 ml-1">Estado en la cotización</label>
                  <div class="relative">
                    <select v-model="store.componenteActivo.estado"
                            class="w-full appearance-none rounded-xl px-4 py-2.5 pr-9 text-xs font-black uppercase tracking-wide outline-none shadow-sm border cursor-pointer transition-colors"
                            :class="[getEstadoComponenteConfig(store.componenteActivo.estado).bg, getEstadoComponenteConfig(store.componenteActivo.estado).text, getEstadoComponenteConfig(store.componenteActivo.estado).border]">
                      <option value="activo">Activo</option>
                      <option value="cancelado">Cancelado</option>
                    </select>
                    <i class="fas absolute right-3 top-1/2 -translate-y-1/2 pointer-events-none text-xs"
                       :class="[getEstadoComponenteConfig(store.componenteActivo.estado).icon, getEstadoComponenteConfig(store.componenteActivo.estado).text]"></i>
                  </div>
                  <p class="text-[9px] text-slate-400 mt-1 ml-1 leading-snug">
                    Cancelado deja de sumar costo y desaparece de la propuesta. La confirmación
                    del proveedor se registra en Operaciones.
                  </p>
                </div>

                <!-- ══ PRESTADOR ══════════════════════════════════════════════
                     Quién presta el servicio, frente al proveedor de la tarifa (a
                     quién se le compra). Es opcional: vacío hereda del día y, en
                     último término, del proveedor de la tarifa — por eso en el caso
                     normal no hay que tocarlo.

                     Colapsado por defecto salvo en `no_incluido`, donde es lo único
                     que puede identificar el servicio: ahí no hay tarifa de la que
                     heredar y es además lo que se le muestra al cliente. -->
                <details :open="store.componenteActivo.modo === 'no_incluido'"
                         class="group/prest border border-slate-200 rounded-xl overflow-hidden bg-white">
                  <summary class="px-3 py-2.5 cursor-pointer list-none flex items-center justify-between gap-2 hover:bg-slate-50 transition-colors">
                    <span class="text-[10px] font-black text-slate-500 uppercase tracking-widest flex items-center gap-1.5">
                      <i class="fas fa-hotel text-indigo-500"></i> Prestador
                    </span>
                    <span class="flex items-center gap-2 min-w-0">
                      <!-- Si se le nombra o no es lo que más se consulta, y estaba escondido
                           tras el desplegable. Va en la cabecera. -->
                      <i v-if="prestadorComponenteResuelto"
                         class="fas text-[9px] shrink-0"
                         :class="store.componenteActivo.prestadorVisible
                             ? 'fa-eye text-emerald-500'
                             : 'fa-eye-slash text-slate-300'"
                         :title="store.componenteActivo.prestadorVisible
                             ? 'Se le nombra al cliente'
                             : 'Sólo operativo: no se le nombra al cliente'"></i>
                      <span class="text-[11px] font-bold truncate max-w-[10rem]"
                            :class="prestadorComponenteResuelto ? 'text-slate-700' : 'text-slate-300 italic'">
                        {{ prestadorComponenteResuelto?.nombre || 'Sin definir' }}
                      </span>
                      <i class="fas fa-chevron-down text-[9px] text-slate-400 transition-transform group-open/prest:rotate-180"></i>
                    </span>
                  </summary>

                  <div class="px-3 pb-3 pt-1 border-t border-slate-100 bg-slate-50/60">
                    <p class="text-[9px] text-slate-400 leading-snug mb-2">
                      Quién opera el servicio, no a quién se le compra. Si lo dejas vacío se
                      hereda del día y, si tampoco, del proveedor de la tarifa. Viaja a
                      Operaciones con su teléfono y dirección aunque no se le nombre al cliente.
                    </p>

                    <div class="flex gap-2 items-center">
                      <SearchableSelect
                          v-model="store.componenteActivo.prestadorMaestroId"
                          :options="opcionesProveedores"
                          placeholder="Buscar en el catálogo..."
                          :darkMode="false"
                          @change="val => store.onPrestadorComponenteChange(val)"
                          @search="val => store.buscarProveedoresAsincrono(val)"
                          :min-chars-busqueda="2"
                          class="flex-1"
                      />
                      <!-- Alta sin salir del editor. Es lo que hace que el prestador pueda
                           quedar SIEMPRE contra el maestro: si dar de alta cuesta lo mismo
                           que escribir texto suelto, nadie escribe texto suelto. -->
                      <button @click="abrirAltaPrestador"
                              :disabled="!permisos.puede('ROLE_MAESTROS_WRITE')"
                              :title="permisos.motivo('ROLE_MAESTROS_WRITE', 'dar de alta empresas en el catálogo')"
                              class="w-9 h-9 shrink-0 rounded-lg border transition-colors flex items-center justify-center shadow-sm"
                              :class="permisos.puede('ROLE_MAESTROS_WRITE')
                                  ? 'bg-emerald-50 text-emerald-600 border-emerald-200 hover:bg-emerald-100'
                                  : 'bg-slate-50 text-slate-300 border-slate-200 cursor-not-allowed'">
                        <i class="fas fa-plus"></i>
                      </button>
                      <button v-if="store.componenteActivo.prestadorMaestroId"
                              @click="store.onPrestadorComponenteChange(null)"
                              class="w-9 h-9 shrink-0 bg-red-50 text-red-500 rounded-lg border border-red-100 hover:bg-red-200 transition-colors flex items-center justify-center shadow-sm"
                              title="Quitar prestador">
                        <i class="fas fa-times"></i>
                      </button>
                    </div>

                    <!-- El servicio cuelga del prestador: el desplegable se carga filtrado
                         por él, y cambiar de empresa lo invalida. Sólo se guarda el enlace
                         y el nombre; título y fotos salen del catálogo al servir. -->
                    <div v-if="store.componenteActivo.prestadorMaestroId" class="flex gap-2 items-center mt-2">
                      <SearchableSelect
                          v-model="store.componenteActivo.prestadorServicioMaestroId"
                          :options="opcionesServiciosPrestador"
                          placeholder="Servicio del prestador (ej. tipo de habitación)…"
                          :darkMode="false"
                          @change="val => store.onProveedorServicioChange(val)"
                          class="flex-1"
                      />
                      <button v-if="store.componenteActivo.prestadorServicioMaestroId"
                              @click="store.onProveedorServicioChange(null)"
                              class="w-9 h-9 shrink-0 bg-red-50 text-red-500 rounded-lg border border-red-100 hover:bg-red-200 transition-colors flex items-center justify-center shadow-sm"
                              title="Quitar servicio">
                        <i class="fas fa-times"></i>
                      </button>
                    </div>

                    <!-- La decisión editorial, separada del hecho operativo. Antes se
                         deducía del modo y por eso reclasificar el componente cambiaba la
                         propuesta en silencio; ahora es un valor que se guarda. Se siembra
                         al asignar y aquí se puede contradecir. -->
                    <label class="mt-2.5 flex items-start gap-2 cursor-pointer">
                      <!-- `:checked` + `@change` y no `v-model`: encender esto REVELA al proveedor
                           a un cliente, y eso se confirma. Apagar no pregunta —ocultar nunca hace
                           daño— así que la fricción cae sólo del lado que la merece. -->
                      <input :checked="store.componenteActivo.prestadorVisible" type="checkbox"
                             @change="alternarNombrarPrestador($event)"
                             class="mt-0.5 w-3.5 h-3.5 accent-indigo-500 cursor-pointer shrink-0" />
                      <span class="min-w-0">
                        <span class="block text-[10px] font-black text-slate-600 uppercase tracking-wide">
                          Nombrarlo al cliente
                        </span>
                        <span class="block text-[9px] text-slate-400 leading-snug">
                          En un no incluido la referencia completa el itinerario; en uno incluido
                          revela quién opera lo que sí vendes.
                        </span>
                      </span>
                    </label>

                    <!-- El aviso de «marcado sin título» vive ahora en la ficha de la
                         empresa (OrganizacionFormulario), que es donde se puede arreglar. -->

                    <!-- El contacto ya no se copia aquí: sale del catálogo cuando se
                         despacha y cuando se manda la orden, así que el número que se marca
                         es el que contesta hoy. Se edita en la ficha de la empresa. -->
                    <p v-if="store.componenteActivo.prestadorMaestroId"
                       class="text-[9px] text-slate-400 mt-2 leading-snug">
                      <i class="fas fa-circle-info"></i>
                      Teléfono, correo y dirección salen de la ficha de la empresa.
                    </p>

                  </div>
                </details>

                <!-- COMPRADOR — a quién se le manda el encargo. No es el proveedor: la
                     tarifa puede ser de un consorcio al que nadie escribe. Sin cara
                     pública: esto no llega jamás a la vista del cliente. -->
                <details class="group/compra border border-slate-200 rounded-xl overflow-hidden bg-white mt-2">
                  <summary class="px-3 py-2.5 cursor-pointer list-none flex items-center justify-between gap-2 hover:bg-slate-50 transition-colors">
                    <span class="text-[10px] font-black text-slate-500 uppercase tracking-widest flex items-center gap-1.5">
                      <i class="fas fa-cart-shopping text-amber-500"></i> Comprador
                      <span v-if="compradorResuelto?.origen === 'prestador'"
                            class="text-[8px] bg-slate-100 text-slate-500 border border-slate-200 rounded px-1.5 py-0.5 normal-case font-bold">
                        al prestador
                      </span>
                    </span>
                    <span class="flex items-center gap-2 min-w-0">
                      <span class="text-[11px] font-bold truncate max-w-[10rem]"
                            :class="compradorResuelto ? 'text-slate-700' : 'text-slate-300 italic'">
                        {{ compradorResuelto?.nombre || 'Sin definir' }}
                      </span>
                      <i class="fas fa-chevron-down text-[9px] text-slate-400 transition-transform group-open/compra:rotate-180"></i>
                    </span>
                  </summary>

                  <div class="px-3 pb-3 pt-1 border-t border-slate-100 bg-slate-50/60">
                    <p class="text-[9px] text-slate-400 leading-snug mb-2">
                      A quién se le encarga <b>ejecutar</b> la compra, que no siempre es
                      quien pone el precio: le encargas a Futurismo que compre las entradas
                      o que contrate el hotel. Vacío = se le pide al proveedor, que es lo
                      normal. <b class="text-amber-600">El cliente no lo ve nunca.</b>
                    </p>

                    <div class="flex gap-2 items-center">
                      <SearchableSelect
                          v-model="store.componenteActivo.compradorMaestroId"
                          :options="opcionesProveedores"
                          placeholder="Buscar en el catálogo..."
                          :darkMode="false"
                          @change="val => store.onCompradorChange(val)"
                          @search="val => store.buscarProveedoresAsincrono(val)"
                          :min-chars-busqueda="2"
                          class="flex-1"
                      />
                      <button v-if="store.componenteActivo.compradorMaestroId"
                              @click="store.onCompradorChange(null)"
                              class="w-9 h-9 shrink-0 bg-red-50 text-red-500 rounded-lg border border-red-100 hover:bg-red-200 transition-colors flex items-center justify-center shadow-sm"
                              title="Quitar comprador">
                        <i class="fas fa-times"></i>
                      </button>
                    </div>
                  </div>
                </details>
              </div>
            </div>

            <div class="border-t border-sky-100 pt-5">
              <div class="flex items-center justify-between mb-3">
                <h3 class="text-[10px] font-black text-orange-600 uppercase tracking-widest">
                  <span>Tarifas / Costos</span>
                </h3>
                <span v-if="store.isComponenteConAlerta(store.componenteActivo)" class="bg-red-100 text-red-600 px-2 py-1 rounded text-[9px] font-bold border border-red-200">
                    <i class="fas fa-exclamation-circle mr-1"></i> Faltan Pax
                </span>
                <button @click="store.agregarTarifa(store.componenteActivo.id)" class="bg-orange-500 hover:bg-orange-600 text-white px-3 py-1.5 rounded-lg shadow-sm text-xs md:text-sm font-bold transition-colors">+ Añadir Tarifa</button>
              </div>
              <div class="space-y-3">
                <div v-for="(tarifa, idx) in cottarifasOrdenadas" :key="tarifa.id || idx" @click="store.abrirNivel('tarifa', tarifa)"
                     class="bg-white border-2 border-orange-200 rounded-xl p-4 shadow-sm cursor-pointer hover:border-orange-400 relative group overflow-hidden transition-all">
                  <div class="absolute left-0 top-0 bottom-0 w-1.5" :class="getRolTarifaUI(tarifa.rolSnapshot).bg.replace('bg-', 'bg-').replace('-50','-400')"></div>

                  <button @click.stop="store.eliminarTarifa(store.componenteActivo.id, tarifa.id)" class="absolute right-3 top-3 text-slate-300 hover:text-red-500 transition-colors z-10 p-1 bg-slate-50 w-6 h-6 rounded-full flex items-center justify-center">
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

                      </div>
                    </div>
                    <div class="text-right shrink-0">
                      <span class="font-black text-orange-600 text-base block">{{ formatMoneda(Number(tarifa.montoCosto) * (tarifa.esGrupal ? 1 : tarifa.cantidad), tarifa.moneda) }}</span>
                      <p class="text-xs font-black text-emerald-600 mt-0.5 flex items-center justify-end gap-1">
                        <i class="fas fa-tag text-[9px]"></i>
                        {{ formatMoneda(calcularVentaTarifa(tarifa), tarifa.moneda) }}
                        <span class="text-slate-400 font-bold normal-case">
                          ({{ tarifa.comisionOverrideSnapshot ? `${tarifa.comisionOverrideSnapshot}%` : 'global' }})
                        </span>
                      </p>
                    </div>
                  </div>

                  <div v-if="store.componenteActualDeTarifa?.prestadorNombreSnapshot" class="mt-3 pt-3 border-t border-slate-100 flex flex-wrap items-center gap-2">
                    <span class="text-[10px] font-bold text-slate-600 bg-slate-50 px-2 py-1 rounded-lg border border-slate-200 flex items-center gap-1.5">
                      <i class="fas fa-truck-loading text-slate-400"></i> {{ store.componenteActualDeTarifa.prestadorNombreSnapshot }}
                    </span>
                  </div>

                </div>
              </div>
            </div>


            <div class="border-t border-sky-100 pt-5 mt-4">
              <h3 class="text-[10px] font-black text-sky-700 uppercase tracking-widest mb-3 flex items-center justify-between">
                <span><i class="fas fa-list-check mr-1"></i> Inclusiones / Upsells</span>
                <button @click="store.agregarSnapshotItem(store.componenteActivo.id)" class="bg-sky-100 text-sky-700 px-3 py-1.5 rounded-lg shadow-sm text-xs md:text-sm font-bold border border-sky-200 hover:bg-sky-200 transition-colors">+ Añadir Ítem</button>
              </h3>

              <div class="space-y-2">
                <div v-if="!store.componenteActivo.snapshotItems?.length" class="text-[10px] font-bold text-slate-400 uppercase text-center py-2 border border-dashed border-slate-200 rounded-lg">
                  No hay ítems registrados
                </div>
                <div v-else v-for="item in store.componenteActivo.snapshotItems" :key="item.id"
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
                           @change="store.toggleUpsellComponent(item, store.componenteActivo)"
                           class="w-4 h-4 text-sky-600 rounded border-slate-300 focus:ring-sky-500 cursor-pointer">

                    <input :value="store.getI18nText(item.tituloSnapshot, store.cotizacion?.idiomaEdicion || 'es')"
                           @input="e => { if(store.cotizacion) store.setI18nText(item.tituloSnapshot, store.cotizacion.idiomaEdicion, (e.target as HTMLInputElement).value) }"
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

                    <button @click="store.eliminarSnapshotItem(store.componenteActivo.id, item.id)" class="text-slate-300 hover:text-red-500 transition-colors px-1">
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
                  <button @click.stop="store.componenteActivo.sobreescribirTraduccion = !store.componenteActivo.sobreescribirTraduccion"
                          :class="store.componenteActivo.sobreescribirTraduccion ? 'bg-orange-100 text-orange-600 border-orange-300' : 'bg-slate-50 text-slate-400 border-slate-200 hover:bg-slate-100'"
                          class="px-2 py-1 border rounded-lg transition-colors shadow-sm" title="Forzar traducción de todo el componente al guardar">
                    <i class="fas fa-language text-xs"></i>
                  </button>
                  <span @click.stop="store.agregarDetalleOperativo(store.componenteActivo.id)"
                        class="bg-sky-100 text-sky-700 px-3 py-1.5 rounded-lg shadow-sm text-xs font-bold border border-sky-200 hover:bg-sky-200 transition-colors normal-case tracking-normal cursor-pointer">
                    + Añadir Detalle
                  </span>
                </span>
              </button>

              <div v-show="detallesOperativosAbierto" class="space-y-2">
                <div v-if="!store.componenteActivo.detallesOperativos?.length" class="text-[10px] font-bold text-slate-400 uppercase text-center py-2 border border-dashed border-slate-200 rounded-lg">
                  Sin detalles operativos
                </div>
                <div v-else v-for="bloque in store.componenteActivo.detallesOperativos" :key="bloque.id"
                     class="flex gap-2 items-start bg-white p-2.5 rounded-xl border border-slate-200 shadow-sm">
                  <!--
                    Banderas, no un desplegable: el mismo texto suele ir a más de un sitio. Con el
                    `tipo` viejo había que escribirlo dos veces, y en producción los 15 componentes
                    que tenían los dos bloques los tenían idénticos palabra por palabra.
                    La última audiencia no se puede quitar — para eso está la papelera de al lado.
                  -->
                  <div class="shrink-0 w-32 flex flex-col gap-1">
                    <button v-for="(cfg, aud) in AUDIENCIA_DETALLE_CONFIG" :key="aud"
                            type="button"
                            :title="cfg.documento"
                            @click="store.componenteActivo && store.alternarAudienciaDetalle(store.componenteActivo.id, bloque.id, aud as AudienciaDetalle)"
                            class="flex items-center gap-1.5 px-2 py-1 rounded-lg border text-[10px] font-bold transition-colors"
                            :class="bloque.audiencias?.includes(aud as AudienciaDetalle)
                              ? 'bg-sky-100 text-sky-700 border-sky-200'
                              : 'bg-slate-50 text-slate-400 border-slate-200 hover:bg-slate-100'">
                      <i class="fas w-3 text-center" :class="cfg.icon"></i>
                      {{ cfg.label }}
                    </button>
                  </div>
                  <textarea :value="store.getI18nText(bloque.detalle, store.cotizacion?.idiomaEdicion || 'es')"
                            @input="e => { if(store.cotizacion) store.setI18nText(bloque.detalle, store.cotizacion.idiomaEdicion, (e.target as HTMLTextAreaElement).value) }"
                            rows="2"
                            class="flex-1 bg-transparent text-xs font-bold text-slate-700 outline-none resize-none"
                            placeholder="Contenido..."></textarea>
                  <button @click="store.eliminarDetalleOperativo(store.componenteActivo.id, bloque.id)" class="text-slate-300 hover:text-red-500 transition-colors px-1 shrink-0">
                    <i class="fas fa-times text-sm"></i>
                  </button>
                </div>
              </div>
            </div>

          </div>
        </div>





        <div v-else-if="store.tarifaActiva" class="flex-1 flex flex-col min-h-0 bg-white">
          <div class="px-5 py-1 border-b border-orange-200 flex items-center gap-3 bg-orange-50 shrink-0 shadow-sm z-10">
            <button @click="store.retrocederNivel" class="w-8 h-8 rounded-full hover:bg-orange-200 text-orange-600 flex items-center justify-center transition-colors shrink-0"><i class="fas fa-arrow-left"></i></button>

            <div class="flex-1 min-w-0">
              <p class="text-[11px] font-black text-orange-400 uppercase tracking-widest truncate flex items-center gap-1">
                <i class="fas fa-box-open"></i> {{ getNombreMaestroRef(store.componenteActualDeTarifa) }}
              </p>
              <h2 class="text-sm font-black text-slate-800 truncate">{{ store.getI18nText(store.tarifaActiva?.tituloSnapshot, store.cotizacion.idiomaEdicion) }}</h2>
              <p v-if="store.tarifasHermanas.length > 1" class="text-[11px] font-bold text-slate-400 mt-0.5">
                Tarifa {{ store.tarifasHermanas.findIndex(t => t.id === store.tarifaActiva?.id) + 1 }} de {{ store.tarifasHermanas.length }}
              </p>
            </div>

            <div v-if="store.tarifasHermanas.length > 1" class="flex flex-col gap-1 shrink-0">
              <button @click="store.irATarifaAdyacente(-1)"
                      :disabled="store.tarifasHermanas.findIndex(t => t.id === store.tarifaActiva?.id) === 0"
                      class="w-9 h-9 rounded-lg bg-white border border-orange-200 text-orange-600 flex items-center justify-center shadow-sm disabled:opacity-30 disabled:cursor-not-allowed active:scale-95 transition-all">
                <i class="fas fa-chevron-up text-xs"></i>
              </button>
              <button @click="store.irATarifaAdyacente(1)"
                      :disabled="store.tarifasHermanas.findIndex(t => t.id === store.tarifaActiva?.id) === store.tarifasHermanas.length - 1"
                      class="w-9 h-9 rounded-lg bg-white border border-orange-200 text-orange-600 flex items-center justify-center shadow-sm disabled:opacity-30 disabled:cursor-not-allowed active:scale-95 transition-all">
                <i class="fas fa-chevron-down text-xs"></i>
              </button>
            </div>
          </div>

          <div class="p-5 flex-1 overflow-y-auto space-y-6 pb-28 bg-slate-50/50">

            <div class="bg-white border border-slate-200 shadow-sm p-4 rounded-xl">
              <label class="block text-[10px] font-black text-orange-500 uppercase tracking-widest mb-2"><i class="fas fa-tags mr-1"></i> Tarifa Maestra</label>

              <!-- Filtro blando por el prestador del componente/día: se anuncia, dice
                   por quién filtra y se quita con un clic. Nunca impide elegir otra. -->
              <div v-if="filtroTarifasActivo"
                   class="flex items-center gap-2 mb-2 text-[10px] font-bold text-indigo-600 bg-indigo-50 border border-indigo-100 rounded-lg px-2 py-1.5">
                <i class="fas fa-filter"></i>
                <span class="flex-1 min-w-0 truncate">
                  Solo tarifas de {{ prestadorParaFiltro?.nombre || 'el prestador' }}
                </span>
                <button @click="verTodasLasTarifas = true"
                        class="shrink-0 underline hover:text-indigo-800 uppercase tracking-wide">
                  Ver todas
                </button>
              </div>
              <div v-else-if="verTodasLasTarifas && prestadorParaFiltro?.maestroId"
                   class="flex items-center gap-2 mb-2 text-[10px] font-bold text-slate-400">
                <button @click="verTodasLasTarifas = false" class="underline hover:text-indigo-600 uppercase tracking-wide">
                  <i class="fas fa-filter mr-1"></i> Volver a filtrar por el prestador
                </button>
              </div>

              <div class="flex gap-2 items-center">
                <SearchableSelect
                    v-model="store.tarifaActiva.tarifaMaestraId"
                    :options="opcionesTarifasFiltradas"
                    placeholder="Precio manual..."
                    :darkMode="false"
                    @update:model-value="val => store.onTarifaMaestraChange(val)"
                    class="flex-1"
                />
                <button v-if="store.tarifaActiva.tarifaMaestraId"
                        @click="store.tarifaActiva.tarifaMaestraId = null"
                        class="w-9 h-9 shrink-0 bg-red-50 text-red-500 rounded-lg border border-red-100 hover:bg-red-200 transition-colors flex items-center justify-center shadow-sm"
                        title="Desvincular tarifa maestra">
                  <i class="fas fa-times"></i>
                </button>
              </div>

              <!--
                LAS PASTILLAS de la tarifa elegida. Compactas a propósito: son de un vistazo,
                no un párrafo. La PRIMERA es el proveedor —el dato que decide si es la tarifa
                que toca— y se pone en rojo cuando no es el prestador del componente.
              -->
              <div v-if="store.tarifaActiva.tarifaMaestraId" class="mt-3 pt-3 border-t border-slate-100 flex flex-wrap gap-1.5">

                <!-- Avisa, no bloquea: comprarle al consolidador lo que opera otro es legítimo,
                     y un candado dejaría esa tarifa inseleccionable. El porqué va en el tooltip
                     para no gastar tres líneas en el caso raro. -->
                <span v-if="proveedorDeTarifaActiva?.nombre"
                      class="text-[9px] font-bold px-2 py-1 rounded border uppercase max-w-[14rem] truncate"
                      :class="tarifaDeOtroProveedor
                          ? 'bg-red-50 text-red-700 border-red-300'
                          : 'bg-slate-100 text-slate-600 border-slate-200'"
                      :title="tarifaDeOtroProveedor
                          ? `Esta tarifa trae ${tarifaDeOtroProveedor}. Puede ser correcto —a veces se le compra a uno lo que opera otro—, pero compruébalo. No se cambia nada: manda lo que ya tiene la línea.`
                          : `Tarifa de ${proveedorDeTarifaActiva.nombre}`">
                  <i class="mr-1" :class="tarifaDeOtroProveedor ? 'fas fa-triangle-exclamation' : 'fas fa-truck-loading text-slate-400'"></i>
                  {{ proveedorDeTarifaActiva.nombre }}
                </span>

                <template v-if="tarifaMaestraDeActiva">
                  <span v-if="getModalidadTarifaUI(tarifaMaestraDeActiva)"
                        class="text-[9px] font-bold text-slate-600 bg-slate-100 px-2 py-1 rounded border border-slate-200 uppercase">
                    <span class="mr-1">{{ getModalidadTarifaUI(tarifaMaestraDeActiva)!.icon }}</span>
                    {{ getModalidadTarifaUI(tarifaMaestraDeActiva)!.label }}
                  </span>

                  <span v-if="getCategoriaTarifaUI(tarifaMaestraDeActiva)"
                        class="text-[9px] font-bold text-slate-600 bg-slate-100 px-2 py-1 rounded border border-slate-200 uppercase">
                    <span class="mr-1">{{ getCategoriaTarifaUI(tarifaMaestraDeActiva)!.icon }}</span>
                    {{ getCategoriaTarifaUI(tarifaMaestraDeActiva)!.label }}
                  </span>

                  <span class="text-[9px] font-bold text-slate-600 bg-slate-100 px-2 py-1 rounded border border-slate-200 uppercase"
                        :title="ayudaProcedencia(tarifaMaestraDeActiva.procedencia)">
                    <span class="mr-1">{{ getProcedenciaUI(tarifaMaestraDeActiva.procedencia).icon }}</span>
                    {{ etiquetaProcedencia(tarifaMaestraDeActiva.procedencia) }}
                  </span>

                  <span v-if="formatRangoEdad(tarifaMaestraDeActiva.edadMinima, tarifaMaestraDeActiva.edadMaxima)"
                        class="text-[9px] font-bold text-slate-600 bg-slate-100 px-2 py-1 rounded border border-slate-200 uppercase">
                    <i class="fas fa-birthday-cake text-orange-500 mr-1"></i>
                    {{ formatRangoEdad(tarifaMaestraDeActiva.edadMinima, tarifaMaestraDeActiva.edadMaxima) }}
                  </span>
                </template>
              </div>
            </div>

            <div class="grid grid-cols-3 gap-4 items-start">
              <div>
                <label class="block text-[10px] font-black text-slate-500 uppercase mb-1 ml-1">Cant (Pax) *</label>
                <!-- ⚠️ En grupal se enseña «1 grupo», no la cantidad.
                     El valor se CONSERVA en el modelo —volver a unitario lo recupera— pero
                     enseñarlo mientras no se usa es lo que hacía parecer que algo fallaba: el
                     campo decía 2, el subtotal ya no lo multiplicaba, y no había forma de saber
                     cuál de los dos mentía. -->
                <input v-if="!store.tarifaActiva.esGrupal"
                       v-model="store.tarifaActiva.cantidad"
                       type="number"
                       class="w-full rounded-xl px-4 py-2 text-sm font-bold text-center outline-none shadow-sm border bg-white text-slate-800 border-slate-300 focus:ring-2 focus:ring-orange-500">
                <div v-else
                     class="w-full rounded-xl px-4 py-2 text-sm font-black text-center shadow-sm border bg-orange-50 border-orange-200 text-orange-600 flex items-center justify-center gap-1.5">
                  <i class="fas fa-users text-xs"></i> 1 grupo
                </div>
                <p v-if="store.tarifaActiva.esGrupal" class="text-[9px] text-orange-500 mt-1 ml-1">Precio por grupo fijo: no se multiplica por pax.</p>

                <label class="block text-[10px] font-black text-slate-500 uppercase mb-1 ml-1 mt-3">Comisión Propia (%)</label>
                <input v-model.number="store.tarifaActiva.comisionOverrideSnapshot" type="number" step="0.1" placeholder="Usa la global"
                       class="w-full bg-amber-50 border border-amber-300 text-amber-700 rounded-xl px-4 py-2 text-sm font-black text-center outline-none focus:ring-2 focus:ring-amber-500 shadow-sm">
                <p class="text-[10px] text-slate-600 mt-1 ml-1">Vacío = usa la global.</p>
              </div>

              <div class="col-span-2 bg-white border border-slate-200 rounded-2xl p-4 shadow-sm">
                <div class="flex justify-between items-center">
                  <div>
                    <label class="block text-[10px] font-black text-slate-500 uppercase mb-1.5">Moneda</label>
                    <select v-model="store.tarifaActiva.moneda" class="bg-transparent text-slate-800 font-bold text-xs outline-none border-b border-slate-300 pb-1 appearance-none focus:border-orange-500 transition-colors">
                      <option v-for="m in store.catalogos.monedas" :key="m.id" :value="m.id">{{ m.id }}</option>
                    </select>
                  </div>
                  <div>
                    <label class="block text-[10px] font-black text-slate-500 uppercase mb-1.5 text-right">Costo Unitario</label>
                    <input v-model.number="store.tarifaActiva.montoCosto" type="number" step="0.01" class="w-28 bg-slate-50 border border-slate-300 text-orange-600 rounded-xl px-3 py-2 text-lg font-black text-right focus:border-orange-500 outline-none shadow-inner">
                  </div>
                </div>
                <!-- ⚠️ En GRUPAL el costo NO se multiplica por los pax: el monto ya es el total.
                     Aquí faltaba el `esGrupal` y la ficha decía S/ 160 (80 × 2) mientras la tarjeta
                     de la lista y el cálculo que se guarda decían S/ 80 —los dos sí lo tienen en
                     cuenta—. Se guardaba bien, pero mientras editabas leías un número que no era. -->
                <div class="flex justify-end items-baseline gap-1.5 mt-3 pt-3 border-t border-slate-100">
                  <span class="text-[9px] text-slate-500 font-bold uppercase">Subtotal Neto:</span>
                  <span class="text-orange-600 text-sm font-black">
                    {{ formatMoneda(Number(store.tarifaActiva.montoCosto) * (store.tarifaActiva.esGrupal ? 1 : store.tarifaActiva.cantidad), store.tarifaActiva.moneda) }}
                  </span>
                </div>
              </div>
            </div>

            <div class="grid grid-cols-2 gap-4">
              <div class="col-span-2 mt-2">
                <label class="block text-[10px] font-black text-slate-500 uppercase mb-1.5 ml-1">Nombre Interno (Operativo) *</label>
                <input v-model="store.tarifaActiva.nombreInternoSnapshot"
                       type="text" class="w-full bg-slate-50 border border-slate-300 text-slate-800 rounded-xl px-4 py-3 text-sm font-bold focus:ring-2 focus:ring-orange-500 outline-none shadow-inner mb-4"
                       placeholder="Ej: Adulto Extranjero...">

                <label class="block text-[10px] font-black text-slate-500 uppercase mb-1.5 ml-1">Nombre para cliente *</label>
                <div class="flex gap-2">
                  <input :value="store.getI18nText(store.tarifaActiva.tituloSnapshot, store.cotizacion?.idiomaEdicion || 'es')"
                         @input="e => { if(store.cotizacion && store.tarifaActiva) store.setI18nText(store.tarifaActiva.tituloSnapshot, store.cotizacion.idiomaEdicion, (e.target as HTMLInputElement).value) }"
                         type="text" class="flex-1 bg-white border border-slate-300 text-slate-800 rounded-xl px-4 py-3 text-sm font-bold focus:ring-2 focus:ring-orange-500 outline-none shadow-sm">

                  <button @click="store.tarifaActiva.sobreescribirTraduccion = !store.tarifaActiva.sobreescribirTraduccion"
                          :class="store.tarifaActiva.sobreescribirTraduccion ? 'bg-orange-100 text-orange-600 border-orange-300' : 'bg-slate-50 text-slate-400 border-slate-200 hover:bg-slate-100'"
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
                    {{ store.tarifaActiva.tarifaMaestraId ? 'Bloqueado por Catálogo Maestro' : 'Define si el costo es por persona o por el total' }}
                  </p>
                </div>

                <div class="flex gap-4 mt-4">
                  <button type="button"
                          @click="!store.tarifaActiva.tarifaMaestraId && (store.tarifaActiva.esGrupal = false)"
                          :disabled="!!store.tarifaActiva.tarifaMaestraId"
                          :class="[
                          !store.tarifaActiva.esGrupal ? 'bg-orange-50 border-orange-300 text-orange-600 shadow-sm' : 'bg-slate-50 border-slate-200 text-slate-400',
                          store.tarifaActiva.tarifaMaestraId ? 'cursor-not-allowed opacity-60' : 'cursor-pointer hover:border-orange-300'
                      ]"
                          class="flex-1 text-center p-2 rounded-xl border transition-all">
                    <i class="fas fa-user text-xs mb-1"></i>
                    <span class="text-[8px] font-black uppercase">Unitario (Pax)</span>
                  </button>
                  <button type="button"
                          @click="!store.tarifaActiva.tarifaMaestraId && (store.tarifaActiva.esGrupal = true)"
                          :disabled="!!store.tarifaActiva.tarifaMaestraId"
                          :class="[
                          store.tarifaActiva.esGrupal ? 'bg-orange-50 border-orange-300 text-orange-600 shadow-sm' : 'bg-slate-50 border-slate-200 text-slate-400',
                          store.tarifaActiva.tarifaMaestraId ? 'cursor-not-allowed opacity-60' : 'cursor-pointer hover:border-orange-300'
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
                    <select v-model="store.tarifaActiva.modalidadSnapshot"
                            class="w-full bg-white border border-slate-300 rounded-lg px-3 py-2 text-xs font-bold text-slate-700 outline-none focus:ring-2 focus:ring-emerald-500 shadow-sm">
                      <option :value="null">Sin modalidad</option>
                      <option v-for="opt in enumOptions(MODALIDAD_CONFIG)" :key="opt.value" :value="opt.value">
                        {{ opt.icon }} {{ opt.label }}
                      </option>
                    </select>
                  </div>
                  <div>
                    <label class="block text-[9px] font-bold text-slate-500 uppercase mb-1 ml-1">Categoría</label>
                    <select v-model="store.tarifaActiva.categoriaSnapshot"
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
                        :class="[getRolTarifaUI(store.tarifaActiva.rolSnapshot).bg, getRolTarifaUI(store.tarifaActiva.rolSnapshot).text, getRolTarifaUI(store.tarifaActiva.rolSnapshot).border]">
                <i class="fas" :class="getRolTarifaUI(store.tarifaActiva.rolSnapshot).icon"></i>
                {{ getRolTarifaUI(store.tarifaActiva.rolSnapshot).label }}
              </span>
                </div>

                <!-- req 1: el rol sólo aplica cuando el componente está "Incluido"; en los
                     demás modos manda el modo, así que las tarifas "Alternativa" ya pasaron
                     a "Estándar" apenas el componente dejó de estar incluido. -->
                <div v-if="store.tarifaActiva.rolSnapshot !== 'operativo' && (store.componenteActualDeTarifa?.modo || 'incluido') !== 'incluido'"
                     class="mb-3 bg-amber-50 border border-amber-200 rounded-lg px-3 py-2.5 flex items-start gap-2">
                  <i class="fas fa-triangle-exclamation text-amber-500 mt-0.5"></i>
                  <span class="text-[10px] font-bold text-amber-700 leading-tight">
                    El rol no aplica: el componente está en modo
                    "{{ getModoItemConfig(store.componenteActualDeTarifa?.modo).label }}", así que manda el modo.
                  </span>
                </div>

                <div v-if="store.tarifaActiva.rolSnapshot === 'operativo'" class="mb-3 bg-slate-100 border border-slate-200 rounded-lg px-3 py-2.5 flex items-start gap-2">
                  <i class="fas fa-lock text-slate-400 mt-0.5"></i>
                  <span class="text-[10px] font-bold text-slate-500 leading-tight">Rol Operativo — heredado del catálogo maestro. No se elige a mano ni participa del selector de opciones del cliente.</span>
                </div>

                <div v-else class="flex gap-2 mb-3 items-end"
                     :class="(store.componenteActualDeTarifa?.modo || 'incluido') !== 'incluido' ? 'opacity-60' : ''">
                  <div class="flex-1">
                    <label class="block text-[9px] font-bold text-slate-500 uppercase mb-1 ml-1">Rol Comercial</label>
                    <div class="flex gap-2">
                      <button @click="store.tarifaActiva.grupoTarifa != null && store.marcarTarifaComoEstandar(store.tarifaActiva.id)"
                              :disabled="store.tarifaActiva.grupoTarifa == null"
                              :class="[
                              store.tarifaActiva.rolSnapshot === 'estandar' ? 'bg-blue-600 text-white border-blue-600' : 'bg-slate-50 text-slate-500 border-slate-200 hover:bg-blue-50',
                              store.tarifaActiva.grupoTarifa == null ? 'opacity-40 cursor-not-allowed' : ''
                          ]"
                              class="flex-1 py-2 rounded-lg border text-[10px] font-black uppercase transition-colors">
                        <i class="fas fa-star mr-1"></i> Estándar
                      </button>
                      <button @click="(store.componenteActualDeTarifa?.modo || 'incluido') === 'incluido' && (store.tarifaActiva.rolSnapshot = 'alternativa')"
                              :disabled="(store.componenteActualDeTarifa?.modo || 'incluido') !== 'incluido'"
                              :class="[
                              store.tarifaActiva.rolSnapshot === 'alternativa' ? 'bg-teal-600 text-white border-teal-600' : 'bg-slate-50 text-slate-500 border-slate-200 hover:bg-teal-50',
                              (store.componenteActualDeTarifa?.modo || 'incluido') !== 'incluido' ? 'opacity-40 cursor-not-allowed' : ''
                          ]"
                              class="flex-1 py-2 rounded-lg border text-[10px] font-black uppercase transition-colors">
                        <i class="fas fa-right-left mr-1"></i> Alternativa
                      </button>
                    </div>
                  </div>

                  <div class="w-24 shrink-0">
                    <label class="block text-[9px] font-bold text-slate-500 uppercase mb-1 ml-1">Grupo</label>
                    <input v-model.number="store.tarifaActiva.grupoTarifa" type="number" min="1" placeholder="Ej: 1"
                           class="w-full bg-white border border-slate-300 rounded-lg px-2 py-2 text-sm font-black text-center outline-none focus:ring-2 focus:ring-teal-500 shadow-sm">
                  </div>
                </div>

                <p v-if="store.tarifaActiva.rolSnapshot !== 'operativo' && store.tarifaActiva.grupoTarifa == null" class="text-[9px] text-amber-600 bg-amber-50 border border-amber-200 rounded-lg px-2 py-1.5 mb-3">
                  <i class="fas fa-exclamation-triangle mr-1"></i> Sin grupo asignado — no se puede marcar como estándar hasta definir un grupo.
                </p>

                <div class="mt-3">
                  <label class="block text-[9px] font-bold text-slate-500 uppercase mb-1 ml-1">Nota Aclaratoria (horarios, condiciones...)</label>
                  <textarea :value="store.getI18nText(store.tarifaActiva.notaRol, store.cotizacion?.idiomaEdicion || 'es')"
                            @input="e => { if(store.cotizacion && store.tarifaActiva) store.setI18nText(store.tarifaActiva.notaRol, store.cotizacion.idiomaEdicion, (e.target as HTMLTextAreaElement).value) }"
                            rows="2"
                            class="w-full bg-white border border-slate-300 text-slate-800 rounded-lg px-3 py-2 text-xs font-bold outline-none focus:ring-2 focus:ring-teal-500 shadow-sm resize-none"
                            placeholder="Ej: Sale 06:00am, llega 10:00am..."></textarea>
                </div>
              </div>

              <!-- El PROVEEDOR se edita en el inspector del COMPONENTE, junto al prestador
                   y al comprador: los tres son hechos suyos. Aquí queda lo único que de
                   verdad es por línea de precio — cómo llama ÉL a esta tarifa. -->
              <div class="col-span-2 bg-white border border-emerald-200 rounded-2xl mt-2 p-4 shadow-sm">
                <label class="text-[9px] font-bold text-slate-500 uppercase mb-1 ml-1 flex items-center justify-between">
                  <span>Nombre para la Reserva (Email)</span>
                  <i class="fas fa-paper-plane text-slate-400"></i>
                </label>
                <input v-model="store.tarifaActiva.nombreParaProveedorSnapshot"
                       type="text"
                       class="w-full bg-emerald-50/50 border border-emerald-200 text-emerald-700 rounded-lg px-3 py-2 text-xs font-bold focus:ring-2 focus:ring-emerald-500 outline-none shadow-sm"
                       placeholder="Ej: Del Origen Al Presente de Lima" />
                <p class="text-[9px] text-slate-400 mt-1 ml-1 flex items-start gap-1">
                  <i class="fas fa-exclamation-circle mt-0.5 text-emerald-500"></i>
                  Cómo llama el proveedor a ESTA tarifa. Es el texto que sale en el
                  requerimiento; vacío = la llama igual que nosotros.
                </p>
                <p v-if="store.componenteActualDeTarifa?.prestadorNombreSnapshot"
                   class="text-[9px] text-slate-400 mt-2 pt-2 border-t border-slate-100">
                  <i class="fas fa-truck-loading mr-1"></i>
                  Prestador del componente:
                  <b class="text-slate-600">{{ store.componenteActualDeTarifa.prestadorNombreSnapshot }}</b>
                </p>
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
              <!-- `es` fijo, misma razón: el operativo no está traducido y esta cabecera se quedaba en
                   «Servicio:» a secas en cuanto la cotización no se editaba en español. -->
              <p class="text-[11px] font-bold text-teal-200 uppercase tracking-widest mt-1">Servicio: {{ store.getI18nText(store.servicioActivo?.nombreInternoSnapshot, 'es') }}</p>
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
                        <h4 class="text-xs font-bold text-slate-700 leading-tight mb-1 truncate md:whitespace-normal">{{ store.getI18nText(seg.titulo, store.cotizacion?.idiomaEdicion || 'es') }}</h4>
                        <!-- eslint-disable-next-line vue/no-v-html -- Texto enriquecido del catálogo maestro, redactado por el equipo. HTML a propósito, no viene del huésped. -->
                        <div class="text-[10px] text-slate-500 line-clamp-1 md:line-clamp-2 prose-sm prose-p:my-0" v-html="store.getI18nText(seg.contenido, store.cotizacion?.idiomaEdicion || 'es')"></div>
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

                  <div v-if="!store.servicioActivo?.cotsegmentos?.length" class="border-2 border-dashed border-slate-300 rounded-3xl p-12 text-center text-slate-400 flex flex-col items-center">
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
                                     @change="store.onSegmentoDiaChange(servicioActivoId, cotSeg.id, cotSeg.dia)"
                                     class="w-12 bg-slate-50 border border-slate-300 rounded px-1 py-1 text-xs font-black text-center outline-none focus:ring-2 focus:ring-teal-500 text-slate-800">
                            </div>

                            <!-- ⚠️ Este campo va SIN `uppercase`, a diferencia de los rótulos.
                                 Era sólo CSS —el valor se guarda tal cual— y eso es justo el
                                 problema: escribiendo no había forma de ver si el título quedaba
                                 «La Olla de Juanita» o «la olla de juanita». Se veía mayúsculo en
                                 pantalla y se guardaba como se tecleó, así que nadie podía
                                 corregir lo que no veía. La versal se queda donde el texto NO se
                                 edita. -->
                            <div class="flex items-center gap-2 w-full lg:w-auto min-w-0">
                              <!-- ⚠️ El NOMBRE OPERATIVO encima del título, igual que en el pool de la
                                   izquierda. Sólo con el título no se sabe qué tramo es éste: el título es
                                   prosa de cliente y dos párrafos seguidos pueden titularse casi igual
                                   («Check-out», «Check-in»). El operativo es lo que identifica, y es además
                                   el `nombreSegmento` que lee La Biblia (`BibliaSnapshotService`), así que
                                   verlo aquí es ver lo que le va a llegar a tráfico.

                                   Va en `es` FIJO: no lleva `#[AutoTranslate]` —es interno, regla de
                                   `docs/Cotizaciones.md` §2.b— así que pedirlo en el idioma de edición
                                   devuelve cadena vacía en cuanto la cotización no se edita en español.

                                   Y va de SÓLO LECTURA: «Actualizar» lo reescribe desde el maestro
                                   (`actualizarTextosSegmentos`). Un input aquí prometería una edición que
                                   el siguiente refresco se lleva sin avisar. Se cambia en el maestro. -->
                              <div class="flex-1 w-full min-w-0 flex flex-col">
                                <span class="text-[9px] font-black uppercase tracking-widest leading-tight truncate"
                                      :class="store.getI18nText(cotSeg.nombreInternoSnapshot, 'es') ? 'text-teal-500' : 'text-slate-300'"
                                      :title="store.getI18nText(cotSeg.nombreInternoSnapshot, 'es') || 'Este párrafo no cuelga de ningún segmento del catálogo'">
                                  {{ store.getI18nText(cotSeg.nombreInternoSnapshot, 'es') || 'Sin nombre operativo' }}
                                </span>
                                <input :value="store.getI18nText(cotSeg.tituloSnapshot, store.cotizacion?.idiomaEdicion || 'es')"
                                       @input="e => { if(store.cotizacion) store.setI18nText(cotSeg.tituloSnapshot, store.cotizacion.idiomaEdicion, (e.target as HTMLInputElement).value) }"
                                       class="bg-transparent text-[11px] md:text-xs font-black text-slate-700 outline-none w-full truncate" placeholder="Título del párrafo..." />
                              </div>

                              <button @click="cotSeg.sobreescribirTraduccion = !cotSeg.sobreescribirTraduccion"
                                      class="transition-colors px-2 py-1.5 rounded text-[10px] font-bold border flex items-center gap-1 shadow-sm shrink-0"
                                      :class="cotSeg.sobreescribirTraduccion ? 'bg-orange-100 text-orange-600 border-orange-300' : 'bg-white text-slate-400 border-slate-200 hover:bg-slate-100'" title="Forzar traducción del párrafo al guardar">
                                <i class="fas fa-language"></i> <span class="hidden xl:inline" v-if="cotSeg.sobreescribirTraduccion">Auto-Traducir</span>
                              </button>

                              <button @click="pedirBorrarSegmento(cotSeg.id)"
                                      class="border transition-colors ml-1 p-1.5 rounded shadow-sm shrink-0 flex items-center gap-1.5 text-[10px] font-black uppercase tracking-wider"
                                      :class="segmentoConfirmandoBorrado === cotSeg.id
                                        ? 'bg-red-500 border-red-500 text-white'
                                        : 'bg-white border-slate-200 text-slate-400 hover:text-red-500 hover:border-red-200'"
                                      :title="componentesDeSegmento(cotSeg.id).length
                                        ? `Se lleva ${componentesDeSegmento(cotSeg.id).length} componente(s) y sus tarifas`
                                        : 'Borrar el párrafo'">
                                <i class="fas fa-trash-alt text-sm"></i>
                                <span v-if="segmentoConfirmandoBorrado === cotSeg.id">
                                  ¿Borrar? se lleva {{ componentesDeSegmento(cotSeg.id).length }}
                                </span>
                              </button>
                            </div>
                          </div>

                          <!-- ⚠️ Sólo SIN maestro. Un párrafo del catálogo saca sus puntos del
                               `TravelSegmento`, y ofrecer aquí un segundo sitio para lo mismo es
                               justo lo que se quiso evitar. Escrito a mano no hay primera
                               superficie: éste es el único sitio donde se puede decir, y sin él la
                               única salida era el override de la orden YA EMITIDA. -->
                          <div v-if="!cotSeg.segmentoMaestroId" v-show="expandirEditores"
                               class="px-3 md:px-4 pt-3 grid grid-cols-1 sm:grid-cols-2 gap-2 bg-white">
                            <div>
                              <label class="block text-[9px] font-black text-slate-400 uppercase tracking-widest mb-1">
                                <i class="fas fa-location-dot mr-1 text-teal-500"></i>Dónde empieza
                              </label>
                              <input v-model="cotSeg.inicioTexto" type="text" maxlength="180"
                                     placeholder="Hotel del pasajero · La Olla de Juanita…"
                                     class="w-full bg-slate-50 border border-slate-200 rounded-lg px-3 py-2 text-xs outline-none focus:border-teal-500 placeholder:text-slate-300">
                            </div>
                            <div>
                              <label class="block text-[9px] font-black text-slate-400 uppercase tracking-widest mb-1">
                                <i class="fas fa-flag-checkered mr-1 text-teal-500"></i>Dónde acaba
                              </label>
                              <input v-model="cotSeg.finTexto" type="text" maxlength="180"
                                     placeholder="Se deja en… (vacío si no aplica)"
                                     class="w-full bg-slate-50 border border-slate-200 rounded-lg px-3 py-2 text-xs outline-none focus:border-teal-500 placeholder:text-slate-300">
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
                                            {{ store.getI18nText(nota.titulo, store.cotizacion?.idiomaEdicion || 'es') || nota.nombreInterno }}
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

                          <!-- ── Qué cuelga de este párrafo ────────────────────────────
                               Visible siempre, no en hover: esto se usa en el móvil y ahí no hay
                               hover. Y va al pie de la tarjeta porque la pregunta que responde
                               —«¿qué me llevo si lo borro?»— se hace justo antes de pulsar. -->
                          <div class="px-3 md:px-4 py-2 bg-slate-50 border-t border-slate-200 flex items-start gap-2 text-[10px] font-bold leading-snug">
                            <template v-if="componentesDeSegmento(cotSeg.id).length">
                              <span class="shrink-0 bg-white border border-slate-300 text-slate-600 rounded px-1.5 py-0.5 font-black">
                                <i class="fas fa-cubes mr-1 text-slate-400"></i>{{ componentesDeSegmento(cotSeg.id).length }}
                              </span>
                              <span class="text-slate-500 min-w-0">
                                {{ componentesDeSegmento(cotSeg.id).map(c => getNombreMaestroRef(c)).join(' · ') }}
                              </span>
                              <InfoTooltip lado="derecha" ancho="max-w-72" clase-icono="text-slate-400 hover:text-slate-600 shrink-0 ml-auto">
                                Borrar este párrafo se lleva también esos
                                <b>{{ componentesDeSegmento(cotSeg.id).length }} componente(s)</b>,
                                sus tarifas y sus filas del cuadro de tráfico con el historial de
                                estados.
                                <br><br>
                                La orden ya emitida no se toca: guarda su propia copia.
                              </InfoTooltip>
                            </template>
                            <span v-else class="text-slate-400 italic">Sin componentes vinculados</span>
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
                <p class="text-3xl font-black tracking-tight">{{ formatMonedaPanel(store.resumenFinanciero?.totalVentaBruta) }}</p>
                <div class="mt-3 pt-3 border-t border-slate-800/30 flex justify-between items-end">
                  <div>
                    <p class="text-[8px] text-slate-300 uppercase font-bold">Costo Neto</p>
                    <p class="text-base font-bold text-white">{{ formatMonedaPanel(store.resumenFinanciero?.totalCostoNeto) }}</p>
                  </div>
                  <div class="text-right">
                    <p class="text-[8px] text-emerald-400 uppercase font-bold">Margen Bruto</p>
                    <p class="text-base font-bold text-emerald-300">+{{ formatMonedaPanel(store.resumenFinanciero?.ganancia) }}</p>
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
                    <p class="text-xs font-black text-slate-800">{{ formatMonedaPanel(clase.resumen.ventaDolares / (clase.cantidad || 1)) }}</p>
                  </div>
                </div>

                <div class="grid grid-cols-2 gap-2 mt-2 pt-2 border-t border-slate-50">
                  <div class="bg-slate-50 p-2 rounded-lg text-center">
                    <p class="text-[7px] text-slate-400 font-bold uppercase">Costo Total</p>
                    <p class="text-[10px] font-black text-slate-600">{{ formatMonedaPanel(clase.resumen.montoDolares) }}</p>
                  </div>
                  <div class="bg-emerald-50 p-2 rounded-lg text-center">
                    <p class="text-[7px] text-emerald-600 font-bold uppercase">Utilidad</p>
                    <p class="text-[10px] font-black text-emerald-700">{{ formatMonedaPanel(clase.resumen.gananciaDolares) }}</p>
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

            <!-- Opciones y alternativas agrupadas (req 2): "Alternativa 1/2" u "Opción N" -->
            <div v-if="store.gruposUpgrade.length" class="space-y-3 pt-2">
              <h3 class="text-[9px] font-black text-slate-400 uppercase tracking-widest ml-1"><i class="fas fa-right-left mr-1"></i> Opciones y Alternativas</h3>
              <div v-for="grupo in store.gruposUpgrade" :key="grupo.label"
                   class="bg-white border rounded-2xl p-3 shadow-sm"
                   :class="grupo.esOpcion ? 'border-amber-200' : 'border-purple-200'">
                <p class="text-[9px] font-black uppercase tracking-widest mb-2 flex items-center gap-1.5"
                   :class="grupo.esOpcion ? 'text-amber-600' : 'text-purple-600'">
                  <i class="fas" :class="grupo.esOpcion ? 'fa-circle-question' : 'fa-right-left'"></i> {{ grupo.label }}
                </p>
                <div v-for="(o, i) in grupo.opciones" :key="i" class="flex justify-between items-start gap-2 py-1.5 border-t border-slate-50 first:border-0">
                  <div class="min-w-0">
                    <p class="text-[11px] font-black leading-tight">
                      <template v-if="o.componenteNombreInterno">
                        <span class="text-slate-800">{{ o.componenteNombreInterno }}</span>
                        <span v-if="tarifaLabelAlt(o)" class="text-slate-400 font-bold"> · {{ tarifaLabelAlt(o) }}</span>
                      </template>
                      <span v-else class="text-slate-800">{{ tarifaLabelAlt(o) || 'Insumo Logístico' }}</span>
                    </p>
                    <p v-if="clasificacionBadges(o).length" class="mt-0.5 flex flex-wrap items-center gap-1">
                      <span v-for="b in clasificacionBadges(o)" :key="b.type"
                            class="inline-flex items-center gap-1 text-[8px] font-black px-1.5 py-0.5 rounded border uppercase"
                            :class="CLASIF_BADGE_CLASE[b.type]">
                        {{ b.icon }} {{ b.label }}
                      </span>
                    </p>
                    <!-- Estándar reemplazada: tachada + atenuada, con su modalidad/categoría -->
                    <p class="text-[10px] text-slate-500 leading-tight flex flex-wrap items-center gap-1 mt-1">
                      <span class="text-[9px] font-black uppercase tracking-wide text-slate-400">Reemplaza</span>
                      <template v-if="o.tieneEstandarEspejo">
                        <span class="line-through">{{ estandarLabelAlt(o) || 'Estándar' }}</span>
                        <span v-for="b in clasificacionBadges({ modalidad: o.estandarModalidad, categoria: o.estandarCategoria })" :key="b.type"
                              class="inline-flex items-center gap-1 text-[9px] font-black px-1.5 py-0.5 rounded border uppercase bg-slate-100 text-slate-400 border-slate-200 line-through">
                          {{ b.icon }} {{ b.label }}
                        </span>
                      </template>
                      <span v-else class="italic line-through">vs. estándar del bloque</span>
                    </p>
                  </div>
                  <span class="text-[11px] font-black whitespace-nowrap shrink-0"
                        :class="o.deltaVentaPorPax >= 0 ? 'text-purple-700' : 'text-emerald-700'">
                    {{ o.deltaVentaPorPax >= 0 ? '+' : '−' }}{{ formatMonedaPanel(Math.abs(o.deltaVentaPorPax)) }}/pax
                  </span>
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
              {{ store.getI18nText(modalNota.nota?.titulo, store.cotizacion?.idiomaEdicion || 'es') || modalNota.nota?.nombreInterno }}
            </h4>
            <!-- eslint-disable-next-line vue/no-v-html -- Texto enriquecido del catálogo maestro, redactado por el equipo. HTML a propósito, no viene del huésped. -->
            <div class="prose prose-sm max-w-none text-slate-600 leading-relaxed" v-html="store.getI18nText(modalNota.nota?.contenido, store.cotizacion?.idiomaEdicion || 'es')"></div>
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
              Insertando: <span class="text-teal-600">{{ store.getI18nText(modalInsercion.segmentoMaestro?.titulo, store.cotizacion?.idiomaEdicion || 'es') || modalInsercion.segmentoMaestro?.nombreInterno }}</span>
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
              <!-- ANTES de un párrafo. Sin esta opción, el primer puesto de un día era
                   inalcanzable: el segmento nuevo hereda el día del destino, así que para
                   ponerlo al principio del día 2 había que colgarlo detrás del último del
                   día 1 — y entonces se quedaba en el día 1. Arrastrar entre días no se puede. -->
              <label class="flex items-center gap-2 p-3 rounded-xl border cursor-pointer" :class="opcionInsercion === 'insertBefore' ? 'border-teal-400 bg-teal-50' : 'border-slate-200'">
                <input type="radio" value="insertBefore" v-model="opcionInsercion" class="accent-teal-600">
                <span class="text-xs font-bold text-slate-700">Insertar antes de un párrafo existente</span>
              </label>
              <label class="flex items-center gap-2 p-3 rounded-xl border cursor-pointer" :class="opcionInsercion === 'replace' ? 'border-teal-400 bg-teal-50' : 'border-slate-200'">
                <input type="radio" value="replace" v-model="opcionInsercion" class="accent-teal-600">
                <span class="text-xs font-bold text-slate-700">Reemplazar un párrafo existente</span>
              </label>
            </div>

            <div v-if="opcionInsercion !== 'append'">
              <label class="block text-[10px] font-black text-slate-500 uppercase mb-1.5 ml-1">
                {{ opcionInsercion === 'insert' ? 'Insertar después de:' : (opcionInsercion === 'insertBefore' ? 'Insertar antes de:' : 'Párrafo a reemplazar:') }}
              </label>
              <select v-model="targetSegmentoId" class="w-full bg-slate-50 border border-slate-300 rounded-lg px-3 py-2 text-xs font-bold outline-none focus:ring-2 focus:ring-teal-500">
                <!-- ⚠️ El NOMBRE INTERNO, no el título. Este desplegable se usa para COLOCAR un
                     párrafo entre otros, y para eso hace falta reconocer el tramo —«Traslado a la
                     estación de Ollantaytambo»—, no leer su prosa comercial —«El valle que guarda
                     el secreto»—, que en dos segmentos seguidos puede sonar casi igual. El título
                     queda de respaldo para los párrafos escritos a mano, que no tienen interno. -->
                <option v-for="(cotSeg, idx) in store.servicioActivo?.cotsegmentos || []" :key="cotSeg.id" :value="cotSeg.id">
                  {{ (idx as number) + 1 }}. [Día {{ cotSeg.dia || 1 }}] {{ etiquetaDeParrafo(cotSeg) }}
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

  <PlanOperacionModal
      :cotizacion-id="planOperacionId"
      :titulo="store.cotizacion ? `Versión ${store.cotizacion.version ?? '?'}` : undefined"
      @cerrar="planOperacionId = null"
  />

    <!-- ALTA DE PRESTADOR — mismo formulario que el catálogo, en un componente compartido
         para que no acaben divergiendo. En modo compacto: aquí sólo hace falta lo
         imprescindible para poder comprarle y nombrarlo. -->
    <div v-if="altaPrestadorAbierta"
         class="fixed inset-0 z-50 flex items-center justify-center p-4">
      <div class="absolute inset-0 bg-black/50 backdrop-blur-sm" @click="altaPrestadorAbierta = false"></div>

      <div class="relative bg-white rounded-2xl shadow-xl w-full max-w-md max-h-[85vh] flex flex-col">
        <div class="px-4 py-3 border-b border-slate-200 flex items-center gap-2 shrink-0">
          <h2 class="text-sm font-black text-slate-800 uppercase tracking-widest">
            <i class="fas fa-hotel text-indigo-500 mr-1"></i> Nueva empresa
          </h2>
          <button @click="altaPrestadorAbierta = false" class="ml-auto text-slate-400 hover:text-slate-700">
            <i class="fas fa-xmark"></i>
          </button>
        </div>

        <div class="flex-1 overflow-y-auto p-4">
          <p class="text-[10px] text-slate-400 leading-snug mb-3">
            Entra al catálogo y queda asignada como prestador de este componente. También
            las de un solo uso: así se le puede volver a comprar y aparece en los filtros.
          </p>

          <OrganizacionFormulario v-model="formPrestador" compacto />
        </div>

        <div class="px-4 py-3 border-t border-slate-200 flex gap-2 shrink-0">
          <button @click="altaPrestadorAbierta = false"
                  class="px-3 py-2 text-[10px] font-black uppercase tracking-widest text-slate-500 hover:text-slate-700">
            Cancelar
          </button>
          <button @click="guardarAltaPrestador"
                  :disabled="!formPrestador.nombreComercial.trim() || guardandoPrestador"
                  class="ml-auto px-4 py-2 bg-[#E07845] hover:bg-[#c96837] disabled:opacity-40 disabled:cursor-not-allowed rounded-lg text-white text-[10px] font-black uppercase tracking-widest transition-colors">
            <i class="fas" :class="guardandoPrestador ? 'fa-circle-notch fa-spin' : 'fa-check'"></i>
            Crear y asignar
          </button>
        </div>
      </div>
    </div>
</template>

<style scoped>
::-webkit-scrollbar { width: 6px; }
::-webkit-scrollbar-track { background: transparent; }
::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 10px; }

.fade-scale-enter-active, .fade-scale-leave-active { transition: all 0.15s cubic-bezier(0.4, 0, 0.2, 1); }
.fade-scale-enter-from, .fade-scale-leave-to { opacity: 0; transform: scale(0.95); }

/* El volteo de la tarjeta de identificadores.
   ⚠️ Un desplazamiento lateral corto, NO un `rotateY`: el 3D crea contexto de apilamiento y
   rompería el posicionamiento de los inputs y del date picker que viven en ese panel. El gesto
   se lee igual y no arrastra ese riesgo. `mode="out-in"` evita que las dos caras se solapen y
   den un salto de altura. */
/* La barra de actividad: un tramo que recorre la anchura. No es una barra de progreso —no
   sabemos cuánto falta— y por eso no llena: sólo dice «esto sigue vivo». */
@keyframes barra-actividad { 0% { transform: translateX(-100%); } 100% { transform: translateX(400%); } }
.barra-actividad { animation: barra-actividad 1.1s ease-in-out infinite; }

.fade-cara-enter-active, .fade-cara-leave-active { transition: opacity 0.14s ease, transform 0.14s ease; }
.fade-cara-enter-from { opacity: 0; transform: translateX(8px); }
.fade-cara-leave-to { opacity: 0; transform: translateX(-8px); }
</style>

<style>
:root {
  --dp-border-radius: 0.5rem;
  --dp-primary-color: #0d9488; /* Teal 600 */
  --dp-font-family: inherit;
  --dp-font-size: 0.75rem;
}
</style>