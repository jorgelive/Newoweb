<script setup lang="ts">
/**
 * Centro de Operaciones — La Biblia (cuadro de tráfico) y Órdenes de Servicio.
 *
 * Las filas provienen de OperacionServicio, el snapshot que genera
 * CotizacionConfirmadaEventListener al confirmar una cotización. Es un snapshot:
 * editar la cotización después NO actualiza estas filas. Ver docs/Operacion.md.
 *
 * El listener no filtra por tipo de componente: entra todo lo que tenga tarifa y
 * fecha, incluidos alojamientos, desayunos, cortesías y componentes cancelados. Por
 * eso la vista muestra tipo/modo/estado del componente y deja filtrar: primero se ve
 * qué está llegando, después se decide qué se deja de generar.
 */
import { ref, onMounted, onUnmounted, computed, watch } from 'vue';
import { useRouter, onBeforeRouteLeave } from 'vue-router';
import SearchableSelect from '@/components/SearchableSelect.vue';
import EditorCostoNegociado from '@/components/operacion/EditorCostoNegociado.vue';
import { useOperacionStore, type ExpedienteOpcion, type CotizacionOpcion, type BitacoraEstado, type PagoProveedor, type ExpedienteDetalle, type ProveedorOpcion , type DocumentoDeOrden } from '@/stores/operacion/operacionStore';
import AppSwitcher from '@/components/common/AppSwitcher.vue';
import FechaHoraPicker from '@/components/common/FechaHoraPicker.vue';
import { getUrls } from '@/services/apiClient';
import { mensajeDeErrorApi } from '@/utils/errorApi';
import { extractIdStr } from '@/utils/recurso';
import {
    getEstadoOsConfig,
    getEstadoReservaProveedorConfig,
    getEstadoOperacionConfig,
    getTipoComponenteConfig,
    getModoComponenteConfig,
    getEstadoComponenteConfig,
    ESTADO_RESERVA_PROVEEDOR_CONFIG,
    ESTADO_OPERACION_CONFIG,
    TIPOS_COMPONENTE,
    SIN_LUGAR,
    type FiltrosBiblia,
    type OperacionServicio,
    type OperacionOrdenServicio,
} from '@/types/operacionModel';

const operacionStore = useOperacionStore();
const router = useRouter();

const activeTab = ref<'biblia' | 'ordenes'>('biblia');

// ============================================================================
// FILTROS
//
// FechaHoraPicker trabaja con "YYYY-MM-DDTHH:mm"; la API espera "YYYY-MM-DD".
// El recorte se hace con slice y nunca con Date: no hay que aplicar zona horaria
// a una fecha de servicio (ver docs/UI_Componentes_Compartidos.md §1.3).
// ============================================================================
const hoyIso = (): string => {
    const d = new Date();
    return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
};

const sumarDias = (iso: string, dias: number): string => {
    const [a, m, d] = iso.split('-').map(Number);
    const fecha = new Date(a, m - 1, d + dias);
    return `${fecha.getFullYear()}-${String(fecha.getMonth() + 1).padStart(2, '0')}-${String(fecha.getDate()).padStart(2, '0')}`;
};

// El rango de arranque es de 7 días y no de un solo día a propósito: con "hoy" la
// primera pantalla salía vacía casi siempre —los servicios de un expediente confirmado
// caen semanas después— y eso se leía como "el panel no funciona" en vez de como "no
// hay nada hoy". El preset "Hoy" sigue a un clic para el tráfico del día.
const desde = ref<string>(`${hoyIso()}T00:00`);
const hasta = ref<string>(`${sumarDias(hoyIso(), 6)}T00:00`);
const tiposSeleccionados = ref<string[]>([]);
const lugaresSeleccionados = ref<string[]>([]);

/** Ancho de teléfono. Se lee una vez al arrancar: no se reacciona al giro, no hace falta. */
const esMovil = (): boolean => typeof window !== 'undefined' && window.innerWidth < 768;
const filtroEstadoReservaProveedor = ref<string>('');
const filtroEstadoOperacion = ref<string>('');
const mostrarFiltrosAvanzados = ref<boolean>(false);

/**
 * Los chips de lugar, plegables — **cerrados por defecto en móvil**.
 *
 * Son trece y ocupan cuatro líneas: en una pantalla de teléfono se comen media vista antes de
 * llegar al primer servicio, que es lo que se viene a mirar. En escritorio caben en una línea y no
 * estorban, así que ahí siguen abiertos.
 *
 * ⚠️ Si HAY lugares filtrados se abre igual, esté como esté el interruptor. Un filtro activo
 * escondido es la forma de leer un cuadro recortado creyéndolo entero — el mismo fallo que ya
 * costó las filas de tipo `contacto`.
 */
const mostrarLugares = ref<boolean>(!esMovil());

/**
 * Cómo se ordena el día: por ITINERARIO o por reloj.
 *
 * Por itinerario es el defecto porque es como se lee un viaje: el alojamiento y la comida —que no
 * tienen hora— quedan junto al servicio al que pertenecen, en vez de caer al final del día lejos
 * de su contexto. El reloj sigue estando, para cuando lo que importa es a qué hora sale cada cosa.
 */
const ordenPorHora = ref<boolean>(false);

/**
 * Si la fila ya está en una Orden de Servicio. `''` = todas.
 *
 * Se filtra EN LOCAL sobre lo ya cargado y no en el servidor: es un interruptor de lectura
 * —«qué me falta por encargar»— y pedir de nuevo por él haría esperar para esconder filas
 * que ya están en pantalla. Ojo: actúa sobre la página cargada, así que con el aviso de
 * «hay más servicios de los que caben» el recuento sigue siendo el del servidor.
 */
const filtroOs = ref<'' | 'sin' | 'con'>('');

// Expediente / cotización
const terminoExpediente = ref<string>('');
const resultadosExpediente = ref<ExpedienteOpcion[]>([]);
const expedienteSeleccionado = ref<ExpedienteOpcion | null>(null);
const cotizacionesDelExpediente = ref<CotizacionOpcion[]>([]);
const cotizacionSeleccionada = ref<string>('');
const buscandoExpediente = ref<boolean>(false);

// ── Persistencia de filtros en localStorage ──────────────────────────────────
// Entrar a la cotización y volver dejaba La Biblia en blanco (fechas de hoy, sin filtros). Se
// guardan las fechas y las selecciones para reconstruir el contexto. Va aquí, tras declararse
// todos los refs de filtro, y se restaura ANTES de la carga inicial de onMounted.
const FILTROS_STORAGE_KEY = 'biblia:filtros';

const restaurarFiltros = (): void => {
    try {
        const raw = localStorage.getItem(FILTROS_STORAGE_KEY);
        if (!raw) return;
        const f = JSON.parse(raw) as Record<string, unknown>;
        if (typeof f.desde === 'string') desde.value = f.desde;
        if (typeof f.hasta === 'string') hasta.value = f.hasta;
        // ⚠️ Si el CATÁLOGO de tipos cambió desde que se guardó, el filtro de tipos se descarta.
        //
        // Un filtro guardado no puede saber de un tipo que aún no existía, así que al añadir
        // `contacto` los servicios de ese tipo desaparecieron del cuadro — sin error, sin hueco y
        // sin nada que lo delatara: nueve filas en la base, ocho en pantalla. Se tarda en notar
        // porque un filtro que oculta de más se parece mucho a «no hay nada ese día».
        //
        // Se falla ABIERTO —se enseña de más, no de menos—: perder el filtro una vez es una
        // molestia; perder una fila es una orden que no se emite.
        //
        // Sólo para `tipos`, que es un enum del código y cambia una vez al año. Los lugares salen
        // de la base y se crean a menudo: reiniciar su filtro cada vez sería peor que el problema.
        const catalogoGuardado = Array.isArray(f.tiposCatalogo) ? (f.tiposCatalogo as string[]) : null;
        const catalogoActual = TIPOS_COMPONENTE.map((t) => t.value);
        const catalogoIgual = catalogoGuardado !== null
            && catalogoGuardado.length === catalogoActual.length
            && catalogoActual.every((t) => catalogoGuardado.includes(t));

        if (Array.isArray(f.tipos) && catalogoIgual) tiposSeleccionados.value = f.tipos as string[];
        if (Array.isArray(f.lugares)) lugaresSeleccionados.value = f.lugares as string[];
        if (typeof f.estadoReserva === 'string') filtroEstadoReservaProveedor.value = f.estadoReserva;
        if (typeof f.estadoOperacion === 'string') filtroEstadoOperacion.value = f.estadoOperacion;
        if (typeof f.ordenPorHora === 'boolean') ordenPorHora.value = f.ordenPorHora;
        if (f.filtroOs === '' || f.filtroOs === 'sin' || f.filtroOs === 'con') filtroOs.value = f.filtroOs;
        if (f.expediente && typeof f.expediente === 'object') expedienteSeleccionado.value = f.expediente as ExpedienteOpcion;
        if (typeof f.cotizacion === 'string') cotizacionSeleccionada.value = f.cotizacion;
    } catch { /* storage corrupto: se ignora y arranca con los defaults */ }
};

restaurarFiltros();

watch(
    [desde, hasta, tiposSeleccionados, lugaresSeleccionados, filtroEstadoReservaProveedor,
     filtroEstadoOperacion, filtroOs, expedienteSeleccionado, cotizacionSeleccionada, ordenPorHora],
    () => {
        try {
            localStorage.setItem(FILTROS_STORAGE_KEY, JSON.stringify({
                desde: desde.value, hasta: hasta.value,
                tipos: tiposSeleccionados.value, lugares: lugaresSeleccionados.value,
                // La foto del catálogo con la que se guardó: si cambia, el filtro de tipos
                // se descarta al restaurar. Ver `restaurarFiltros()`.
                tiposCatalogo: TIPOS_COMPONENTE.map((t) => t.value),
                estadoReserva: filtroEstadoReservaProveedor.value,
                estadoOperacion: filtroEstadoOperacion.value,
                ordenPorHora: ordenPorHora.value,
                filtroOs: filtroOs.value,
                expediente: expedienteSeleccionado.value,
                cotizacion: cotizacionSeleccionada.value,
            }));
        } catch { /* sin storage disponible: no pasa nada */ }
    },
    { deep: true },
);

const filtrosActivos = computed<FiltrosBiblia>(() => ({
    desde: desde.value ? desde.value.slice(0, 10) : undefined,
    hasta: hasta.value ? hasta.value.slice(0, 10) : undefined,
    fileId: expedienteSeleccionado.value?.id,
    cotizacionId: cotizacionSeleccionada.value || undefined,
    tipos: tiposSeleccionados.value.length ? tiposSeleccionados.value : undefined,
    lugares: lugaresSeleccionados.value.length ? lugaresSeleccionados.value : undefined,
    estadoReservaProveedor: filtroEstadoReservaProveedor.value || undefined,
    estadoOperacion: filtroEstadoOperacion.value || undefined,
}));

/** El backend tiene más servicios en este rango de los que cabe pintar. */
const hayServiciosOcultos = computed(() =>
    operacionStore.totalServicios > operacionStore.servicios.length
);

const hayFiltrosExtra = computed(() =>
    tiposSeleccionados.value.length > 0
    || !!filtroEstadoReservaProveedor.value
    || !!filtroEstadoOperacion.value
    || !!expedienteSeleccionado.value
);

// ============================================================================
// CARGA
// ============================================================================
/**
 * Mover el inicio ARRASTRA el fin, conservando el ancho de la ventana.
 *
 * Antes sólo se corregía el caso imposible (`hasta < desde`). El resultado era que adelantar
 * el inicio un mes dejaba el fin donde estaba y la consulta pasaba de una semana a cinco sin
 * que nadie lo pidiera. Un rango se mueve entero: es lo que uno espera al cambiar «desde».
 *
 * Y si el fin está vacío se copia el inicio, que es la lectura natural de «quiero ver ese día».
 */
const onCambiarDesde = (v: string): void => {
  const anterior = desde.value;
  desde.value = v;

  // 🔥 BORRAR la fecha es una acción legítima (la X del campo), y con `v` vacío el arrastre
  // hacía `new Date('')` → NaN, y salía a la API un `fechaServicio[before]=NaN-NaN-Na`.
  // El servidor lo aceptaba con un 200 y devolvía cualquier cosa, así que el síntoma era un
  // cuadro con datos que no correspondían al filtro. Visto en el access log al diagnosticar
  // otra cosa: nadie lo habría reportado, porque no falla — miente.
  if (!v) {
    cargarBiblia();   // no pide nada: `faltaAcotar` lo frena y la barra avisa
    return;
  }

  if (!hasta.value) {
    hasta.value = v;
  } else if (anterior && hasta.value >= anterior) {
    const dias = Math.round(
        (new Date(hasta.value.slice(0, 10)).getTime() - new Date(anterior.slice(0, 10)).getTime())
        / 86400000
    );
    hasta.value = `${sumarDias(v.slice(0, 10), dias)}T00:00`;
  } else if (hasta.value < v) {
    hasta.value = v;
  }

  cargarBiblia();
};

/**
 * Sin fechas sólo se busca si hay expediente. Es la única acotación que queda cuando no hay
 * rango: sin ninguna de las dos, la consulta trae la operación entera de la historia y el
 * cuadro deja de ser un cuadro de tráfico.
 */
const faltaAcotar = computed(() =>
    !expedienteSeleccionado.value && (!desde.value || !hasta.value));

const cargarBiblia = async () => {
    if (faltaAcotar.value) return;   // el aviso lo pinta la barra; aquí sólo no se pide

    seleccionados.value = [];
    await operacionStore.fetchServicios(filtrosActivos.value);
};

const cargarOrdenes = async () => {
    await operacionStore.fetchOrdenesServicio();
};

const cambiarTab = async (tab: 'biblia' | 'ordenes') => {
    activeTab.value = tab;
    if (tab === 'biblia') await cargarBiblia();
    else await cargarOrdenes();
};

const aplicarPreset = async (preset: 'hoy' | 'manana' | 'semana') => {
    const base = hoyIso();
    if (preset === 'hoy') {
        desde.value = `${base}T00:00`;
        hasta.value = `${base}T00:00`;
    } else if (preset === 'manana') {
        const m = sumarDias(base, 1);
        desde.value = `${m}T00:00`;
        hasta.value = `${m}T00:00`;
    } else {
        desde.value = `${base}T00:00`;
        hasta.value = `${sumarDias(base, 6)}T00:00`;
    }
    await cargarBiblia();
};

const limpiarFiltros = async () => {
    tiposSeleccionados.value = [];
    lugaresSeleccionados.value = [];
    filtroEstadoReservaProveedor.value = '';
    filtroEstadoOperacion.value = '';
    expedienteSeleccionado.value = null;
    cotizacionSeleccionada.value = '';
    cotizacionesDelExpediente.value = [];
    terminoExpediente.value = '';
    resultadosExpediente.value = [];
    await cargarBiblia();
};

const alternarTipo = async (tipo: string) => {
    const i = tiposSeleccionados.value.indexOf(tipo);
    if (i === -1) tiposSeleccionados.value.push(tipo);
    else tiposSeleccionados.value.splice(i, 1);
    await cargarBiblia();
};

const alternarLugar = async (id: string) => {
    const i = lugaresSeleccionados.value.indexOf(id);
    if (i === -1) lugaresSeleccionados.value.push(id);
    else lugaresSeleccionados.value.splice(i, 1);
    await cargarBiblia();
};

// ── Buscador de expediente (debounce manual, sin dependencias) ──────────────
let temporizadorBusqueda: ReturnType<typeof setTimeout> | null = null;

watch(terminoExpediente, (termino) => {
    if (temporizadorBusqueda) clearTimeout(temporizadorBusqueda);
    if (termino.trim().length < 2) {
        resultadosExpediente.value = [];
        return;
    }
    buscandoExpediente.value = true;
    temporizadorBusqueda = setTimeout(async () => {
        resultadosExpediente.value = await operacionStore.buscarExpedientes(termino);
        buscandoExpediente.value = false;
    }, 300);
});

const elegirExpediente = async (exp: ExpedienteOpcion) => {
    expedienteSeleccionado.value = exp;
    terminoExpediente.value = '';
    resultadosExpediente.value = [];
    cotizacionSeleccionada.value = '';
    cotizacionesDelExpediente.value = await operacionStore.fetchCotizacionesDeExpediente(exp.id);
    await cargarBiblia();
};

const quitarExpediente = async () => {
    expedienteSeleccionado.value = null;
    cotizacionSeleccionada.value = '';
    cotizacionesDelExpediente.value = [];
    await cargarBiblia();
};

// ============================================================================
// AGRUPACIÓN POR DÍA
//
// El backend ya ordena por fechaServicio y horaRecojo. Aquí sólo se parte en
// días y se empujan al final los servicios sin hora, ordenados por prioridad
// operativa (guiado/transporte antes que tickets): un cuadro de tráfico se lee
// de arriba abajo por hora, y lo que no tiene hora estorba en medio.
// ============================================================================
/** La hora con la que se ordena: la misma que pinta la fila. Vacío = no tiene hora ninguna. */
const horaDeOrden = (s: OperacionServicio): string => s.horaRecojo || s.horaComponente || '';

interface GrupoDia {
    fecha: string;
    servicios: OperacionServicio[];
}

const serviciosPorDia = computed<GrupoDia[]>(() => {
    const mapa = new Map<string, OperacionServicio[]>();

    for (const s of operacionStore.servicios) {
        // El toggle de OS filtra AQUÍ y no en el servidor: es lectura sobre lo ya cargado.
        if (filtroOs.value === 'sin' && s.ordenServicio) continue;
        if (filtroOs.value === 'con' && !s.ordenServicio) continue;

        const fecha = (s.fechaServicio ?? '').slice(0, 10) || 'sin-fecha';
        if (!mapa.has(fecha)) mapa.set(fecha, []);
        mapa.get(fecha)!.push(s);
    }

    return [...mapa.entries()]
        .sort(([a], [b]) => a.localeCompare(b))
        .map(([fecha, servicios]) => ({
            fecha,
            servicios: [...servicios].sort((a, b) => {
                // ── Por ITINERARIO (defecto) ────────────────────────────────
                // Es como se lee un viaje. Un cuadro ordenado sólo por reloj parte el relato: el
                // alojamiento y la comida, que no tienen hora, caen al final del día lejos del
                // servicio al que pertenecen.
                if (!ordenPorHora.value) {
                    const oa = a.ordenItinerario ?? Number.MAX_SAFE_INTEGER;
                    const ob = b.ordenItinerario ?? Number.MAX_SAFE_INTEGER;
                    if (oa !== ob) return oa - ob;
                }

                // ── Por RELOJ ───────────────────────────────────────────────
                // ⚠️ La MISMA hora que se pinta en la fila: la de recojo si la hay, y si no la
                // vendida. Ordenar sólo por `horaRecojo` mandaba al fondo del día a todo el que
                // no tuviera un recojo pactado —la mayoría— aunque su hora estuviera ahí
                // delante: el cuadro enseñaba «10:00» y lo colocaba después de las 08:30.
                const ha = horaDeOrden(a);
                const hb = horaDeOrden(b);
                if (ha && hb) return ha.localeCompare(hb);
                if (ha) return -1;
                if (hb) return 1;
                return (a.prioridadOperativa ?? 9) - (b.prioridadOperativa ?? 9);
            }),
        }));
});

const DIAS = ['Domingo', 'Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes', 'Sábado'];
const MESES = ['ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'sep', 'oct', 'nov', 'dic'];

const etiquetaDia = (iso: string): string => {
    if (iso === 'sin-fecha') return 'Sin fecha';
    const [a, m, d] = iso.split('-').map(Number);
    const fecha = new Date(a, m - 1, d);
    const sufijo = iso === hoyIso() ? ' · HOY' : '';
    return `${DIAS[fecha.getDay()]} ${d} ${MESES[m - 1]}${sufijo}`;
};

// ============================================================================
// EDICIÓN EN LÍNEA
// ============================================================================
const guardando = ref<string | null>(null);

const guardarCampo = async (servicio: OperacionServicio, payload: Record<string, unknown>) => {
    if (!servicio.id) return;
    guardando.value = servicio.id;
    try {
        await operacionStore.actualizarServicio(servicio.id, payload);
    } catch {
        // El store ya registra el error; se recarga para no dejar la fila mintiendo.
        await cargarBiblia();
    } finally {
        guardando.value = null;
    }
};

/**
 * La hora se edita con un input de texto y no con <input type="time">: el nativo
 * cambia a AM/PM según el idioma del sistema y aquí se trabaja siempre en 24 h,
 * el mismo motivo por el que las fechas usan FechaHoraPicker.
 */
const PATRON_HORA = /^([01]\d|2[0-3]):([0-5]\d)$/;

/**
 * Dónde recoge y dónde deja: el override del operador sobre lo que dice el catálogo.
 *
 * El campo va VACÍO cuando manda el catálogo, y su marcador de posición es el valor derivado —el
 * mismo patrón que la hora de recojo frente a la vendida. Así se ve de un vistazo qué filas se
 * tocaron a mano (texto negro) y cuáles siguen el maestro (texto gris), y vaciarlo devuelve el
 * control al catálogo en vez de dejar un hueco.
 */
const puntosDe = (servicio: OperacionServicio) => operacionStore.puntosDerivados[servicio.id ?? ''] ?? null;

const editarPunto = async (servicio: OperacionServicio, lado: 'recojo' | 'entrega', evento: Event) => {
    const input = evento.target as HTMLInputElement;
    const valor = input.value.trim();
    const campo = lado === 'recojo' ? 'puntoRecojo' : 'puntoEntrega';

    // Vacío = null y no cadena vacía: `''` seguiría contando como override y taparía el derivado
    // para siempre con un valor que no dice nada. La entidad lo limpia también, por si acaso.
    await guardarCampo(servicio, { [campo]: valor === '' ? null : valor });
};

const editarHora = async (servicio: OperacionServicio, evento: Event) => {
    const input = evento.target as HTMLInputElement;
    const valor = input.value.trim();

    if (valor === '') {
        await guardarCampo(servicio, { horaRecojo: null });
        return;
    }
    if (!PATRON_HORA.test(valor)) {
        input.value = servicio.horaRecojo ?? '';
        return;
    }
    await guardarCampo(servicio, { horaRecojo: valor });
};

/**
 * Los tres papeles se editan sobre su columna REAL, nunca sobre la cotizada.
 *
 * Es lo que hace que la sincronización con la cotización deje de ser un problema: el snapshot
 * escribe sólo la cotizada y el operador sólo la real, así que nadie pisa a nadie y no hay
 * conflicto que resolver. Lo que vale es `…Efectivo…`, que el backend ya calcula.
 *
 * ⚠️ **Se guarda id + nombre, no el texto tecleado.** La Orden de Servicio agrupa por
 * comprador: «Futurismo» y «Futurismo Jonathan» serían dos órdenes distintas. Por eso el input
 * va contra un `<datalist>` del catálogo y, si lo escrito no casa con ninguna empresa, se
 * revierte en vez de guardar un nombre suelto.
 */
const resolverEmpresa = (texto: string): ProveedorOpcion | null =>
    operacionStore.proveedores.find(p => p.nombreComercial.toLowerCase() === texto.toLowerCase()) ?? null;

/** Vacío = «vuelve a lo cotizado», que es distinto de «no hay nadie». */
const editarPapel = async (
    servicio: OperacionServicio,
    evento: Event,
    campoId: 'prestadorOverrideMaestroId' | 'compradorOverrideMaestroId',
    campoNombre: 'prestadorOverrideNombre' | 'compradorOverrideNombre',
    efectivoActual: string,
) => {
    const input = evento.target as HTMLInputElement;
    const texto = input.value.trim();

    if (texto === efectivoActual) return;

    if (texto === '') {
        await guardarCampo(servicio, { [campoId]: null, [campoNombre]: null });
        return;
    }

    const empresa = resolverEmpresa(texto);

    if (!empresa) {
        // Ni se guarda a medias ni se inventa: se devuelve lo que había y se dice por qué.
        input.value = efectivoActual;
        avisoPapel.value = `«${texto}» no está en el catálogo de organizaciones. Dala de alta primero.`;
        window.setTimeout(() => { avisoPapel.value = null; }, 6000);
        return;
    }

    await guardarCampo(servicio, { [campoId]: empresa.id, [campoNombre]: empresa.nombreComercial });
};

const avisoPapel = ref<string | null>(null);

/** COMPRADOR: a quién se le manda el encargo. Es por quien agrupa la Orden de Servicio. */
const editarProveedor = (servicio: OperacionServicio, evento: Event) =>
    editarPapel(servicio, evento, 'compradorOverrideMaestroId', 'compradorOverrideNombre', servicio.compradorEfectivoNombre ?? '');

/**
 * PRESTADOR: quién opera y dónde se recoge. Es el dato que el cuadro de tráfico lee
 * de un vistazo, y por eso manda en la celda por encima del comprador.
 *
 * En una fila de referencia es el ÚNICO de los dos que existe: el hotel que reservó
 * el pasajero. Ver docs/Operacion.md §3.3.b.
 */
const editarPrestador = (servicio: OperacionServicio, evento: Event) =>
    editarPapel(servicio, evento, 'prestadorOverrideMaestroId', 'prestadorOverrideNombre', servicio.prestadorEfectivoNombre ?? '');

/** El servicio contratado (el tipo de habitación). Texto libre: no es una empresa. */
const editarServicioPrestador = async (servicio: OperacionServicio, evento: Event) => {
    const valor = (evento.target as HTMLInputElement).value.trim();
    if (valor === (servicio.prestadorServicioEfectivoNombre ?? '')) return;
    await guardarCampo(servicio, { prestadorServicioOverrideNombre: valor || null });
};

/** Lo que dijo la cotización, para enseñarlo al lado cuando operaciones puso otra cosa. */
const cotizadoDe = (s: OperacionServicio): string | null => {
    if (!s.papelIntervenido) return null;

    const partes: string[] = [];
    if (s.prestadorOverrideNombre && s.prestadorNombre) partes.push(s.prestadorNombre);
    if (s.compradorOverrideNombre && s.compradorNombre) partes.push(s.compradorNombre);

    return partes.length ? `Cotizado: ${partes.join(' · ')}` : null;
};

/**
 * El proveedor comercial se oculta SÓLO cuando es redundante: cuando ya dice
 * exactamente lo mismo que el prestador. En la mayoría de filas ambos heredan de la
 * misma tarifa y repetirlo en cada línea convertiría el dato en ruido.
 *
 * ⚠️ Vacío NO es redundante, es pendiente. La primera versión pedía además que el campo
 * no estuviera vacío, y como salía de la misma tarifa que el prestador, cuando esa tarifa
 * no tenía proveedor **los dos** nacían nulos: el input no se pintaba nunca y el campo
 * quedaba fuera del alcance del operador. Justo el caso más común, y justo el que hace
 * falta para agrupar una Orden de Servicio.
 *
 * Con el comprador eso se da mucho menos: la cascada cae al proveedor del componente, así
 * que el campo llega lleno salvo que no haya proveedor en absoluto.
 */
const mostrarComprador = (s: OperacionServicio): boolean => {
    if (s.soloReferencia) return false;   // no se compra: no hay proveedor que fijar

    const comprador = (s.compradorEfectivoNombre ?? '').trim();
    return comprador === '' || comprador !== (s.prestadorEfectivoNombre ?? '').trim();
};

/** Teléfono en formato marcable: el operador llama desde el propio cuadro. */
const telHref = (telefono?: string | null): string => `tel:${(telefono ?? '').replace(/[^\d+]/g, '')}`;

/**
 * El contacto del recojo NO sale de la fila: sale del catálogo, resuelto en lote al cargar
 * el cuadro (`operacionStore.resolverContactoDeProveedores`). Se lee por aquí, y no
 * `servicio.prestadorTelefono` a pelo, porque ese campo hoy llega siempre vacío —el
 * snapshot dejó de congelarlo— y sólo sirve como respaldo de proveedores sin maestro.
 */
const telefonoDe = (s: OperacionServicio): string | null => operacionStore.contactoDePrestador(s).telefono;
const direccionDe = (s: OperacionServicio): string | null => operacionStore.contactoDePrestador(s).direccion;
// Mono-segmento sin plantilla: el nombre interno del segmento manda sobre el genérico del servicio.
const nombreSegmentoDe = (s: OperacionServicio): string | null => operacionStore.nombreSegmentoDeServicio(s);
// Nombre interno del componente (Guía, Transporte…): identifica la fila dentro de un servicio.
const nombreComponenteDe = (s: OperacionServicio): string | null => operacionStore.nombreComponenteDeServicio(s);

// ============================================================================
// COSTO REAL — lo que de verdad se pagó, frente a lo que decía la cotización
//
// `costoCotizado` viene del snapshot y lo gobierna la cotización: la reconciliación
// puede cambiarlo. `costoNegociado` es del operador y **nadie más lo toca** —
// ni el snapshot ni la reconciliación—, porque es el único sitio donde vive lo que
// el proveedor acabó cobrando. El delta entre ambos es el margen operativo.
//
// La MONEDA también se edita, desde 2026-08-17. Antes no, con el argumento de que
// mezclar divisas en la misma columna era invitar al error — pero el importe dejó de
// vivir en la cabecera de la orden y ahora se suma POR MONEDA sin convertir, así que
// mezclarlas es correcto: se cotiza en dólares y se cierra en soles con el mismo
// proveedor. Ver docs/Operacion.md §5.4.
//
// El editor en sí vive en `EditorCostoNegociado`, con estado local por fila. Tres copias de
// este bloque compartiendo dos Record globales fueron la causa del bug del importe replicado.
// ============================================================================



/** Diferencia real − cotizado. Positiva = costó más de lo previsto. */
const deltaOperativo = (s: OperacionServicio): number | null => {
    const real = Number(s.costoNegociado ?? 0);
    if (real === 0) return null;   // sin registrar: no hay delta que enseñar
    return real - Number(s.costoCotizado ?? 0);
};

const importe = (v?: string | null): string => Number(v ?? 0).toFixed(2);

// ============================================================================
// SELECCIÓN Y GENERACIÓN DE ORDEN DE SERVICIO
// ============================================================================
const seleccionados = ref<string[]>([]);

const alternarSeleccion = (id?: string | null) => {
    if (!id) return;
    const i = seleccionados.value.indexOf(id);
    if (i === -1) seleccionados.value.push(id);
    else seleccionados.value.splice(i, 1);
};

const serviciosSeleccionados = computed(() =>
    operacionStore.servicios.filter(s => s.id && seleccionados.value.includes(s.id))
);

/**
 * ¿Se le puede pedir a un proveedor? Espejo de `OperacionServicio::esComprable()`.
 *
 * Es más estrecho que `!soloReferencia`: excluye también lo cancelado y lo reemplazado,
 * que conservan tarifa y por tanto pasaban todas las demás comprobaciones. La entidad
 * lo vuelve a validar al asignar la OS; esto sólo evita ofrecer la casilla.
 */
const esComprable = (s: OperacionServicio): boolean =>
    !s.soloReferencia
    && s.estadoComponente !== 'cancelado'
    && s.modoComponente !== 'reemplazado';

/**
 * Una OS es una solicitud a UN proveedor sobre UN expediente: agrupar servicios de
 * expedientes o proveedores distintos produciría un documento que nadie puede firmar.
 */
const conflictoSeleccion = computed<string | null>(() => {
    const sel = serviciosSeleccionados.value;
    if (sel.length === 0) return null;

    // Sólo se compra lo que se opera y se compra. Lo calcula el backend
    // (OperacionServicio::esComprable) y lo vuelve a defender al asignar la OS; aquí
    // sólo se evita que el operador llegue hasta el modal para recibir un 422.
    if (sel.some(s => s.soloReferencia)) {
        return 'Hay servicios de referencia (no incluidos o sin tarifa) en la selección: no se compran a ningún proveedor.';
    }
    // Un componente cancelado o reemplazado conserva su tarifa, así que pasaba todas las
    // demás comprobaciones: la fila sale atenuada, pero atenuar no impide marcarla.
    if (sel.some(s => s.estadoComponente === 'cancelado')) {
        return 'Hay servicios cancelados en la cotización: pedirlos sería comprar algo que el cliente no quiere.';
    }
    if (sel.some(s => s.modoComponente === 'reemplazado')) {
        return 'Hay servicios reemplazados por otros en la cotización.';
    }

    const files = new Set(sel.map(s => s.file?.id ?? ''));
    if (files.size > 1) return 'Los servicios seleccionados son de expedientes distintos.';

    // Se agrupa por COMPRADOR, no por proveedor: la orden va a quien la ejecuta. Dos
    // servicios de proveedores distintos que compra Futurismo caben en la misma orden;
    // agrupar por proveedor los partía en dos sin motivo.
    // Por el id EFECTIVO, no por el texto: un nombre tecleado no agrupa con la ficha del
    // catálogo aunque se lea igual, y el efectivo es el que respeta lo que decidió operaciones.
    const compradores = new Set(sel.map(s => s.compradorEfectivoMaestroId ?? ''));
    if (compradores.size > 1) return 'Los servicios seleccionados tienen compradores distintos.';

    // 🔓 Las monedas distintas YA NO bloquean. Bloqueaban porque el total vivía en la
    // cabecera de la orden y mezclar dejaba una suma sin sentido; ahora el importe vive en
    // cada ítem con su moneda y la pantalla suma por moneda. Un proveedor que cobra unos
    // servicios en soles y otros en dólares es una sola gestión, no dos órdenes.

    if (sel.some(s => s.ordenServicio)) return 'Algún servicio ya pertenece a una Orden de Servicio.';

    return null;
});

const mostrarModalOs = ref<boolean>(false);
const guardandoOs = ref<boolean>(false);
const errorOs = ref<string | null>(null);
/** El catálogo, en la forma que pide el selector. */
const opcionesProveedores = computed(() =>
    operacionStore.proveedores.map(p => ({ value: p.id, label: p.nombreComercial })));

/**
 * Al elegir destinatario se guardan las DOS cosas: el id, que es lo que permite enviarle la
 * orden, y el nombre, que es lo que queda escrito si algún día se borra del catálogo.
 */
const onDestinatarioChange = (id: unknown): void => {
    const elegido = operacionStore.proveedores.find(p => p.id === String(id ?? ''));
    formOs.value.compradorNombre = elegido?.nombreComercial ?? '';
};

const formOs = ref({ numeroOs: '', compradorMaestroId: '' as string | null, compradorNombre: '' });

/**
 * Lo que suma la selección, POR MONEDA. Nunca un solo número.
 *
 * Sumar monedas distintas es el error que la conversión disimula: aquí no se convierte
 * —eso es criterio de la casa— así que salen tantas líneas como monedas haya.
 */
const totalesPorMoneda = computed<{ moneda: string; total: number }[]>(() => {
    const mapa = new Map<string, number>();

    for (const s of serviciosSeleccionados.value) {
        const m = s.monedaCotizada?.id ?? '—';
        mapa.set(m, (mapa.get(m) ?? 0) + Number(s.costoCotizado ?? 0));
    }

    return [...mapa.entries()]
        .map(([moneda, total]) => ({ moneda, total }))
        .sort((a, b) => a.moneda.localeCompare(b.moneda));
});

const abrirModalOs = () => {
    const sel = serviciosSeleccionados.value;
    if (sel.length === 0 || conflictoSeleccion.value) return;

    const hoy = hoyIso().replace(/-/g, '');

    formOs.value = {
        // Sugerencia: numeroOs es unique y no tiene generador en el backend.
        numeroOs: `OS-${hoy}-${String(Math.floor(Math.random() * 900) + 100)}`,
        // El EFECTIVO, que es por el que agrupa `conflictoSeleccion`. Con el cotizado, una
        // fila con override proponía la empresa de la cotización mientras la comprobación de
        // «todos el mismo comprador» miraba otra: el modal se contradecía consigo mismo.
        compradorMaestroId: sel[0].compradorEfectivoMaestroId ?? '',
        compradorNombre: sel[0].compradorEfectivoNombre ?? '',
    };
    errorOs.value = null;
    mostrarModalOs.value = true;
    void operacionStore.fetchProveedores();   // idempotente: sólo pega la primera vez
};

/**
 * Crea la orden. `emitir` decide si además **sale al proveedor**.
 *
 * ── Por qué ahora se puede elegir ───────────────────────────────────────────
 * Esto mandaba `soloBorrador: false` fijo, con el motivo escrito al lado: «el borrador existe
 * para componer, y aquí ya está compuesta». Era cierto **mientras no hubiera forma de emitir
 * después** —el estado vivía en un desplegable enterrado en el modal de edición—, así que crear
 * y emitir tenían que ser el mismo gesto.
 *
 * Con el botón «Emitir» en la tarjeta esa razón desapareció, y lo que quedaba era lo malo:
 * componer una orden obligaba a mandársela al proveedor en el acto, sin poder repasar el
 * destinatario, el número ni los importes negociados.
 */
/**
 * Las órdenes a las que se puede sumar lo que está marcado.
 *
 * Sólo BORRADORES —una emitida es un documento que el proveedor ya tiene— y sólo del mismo
 * expediente y el mismo comprador que la selección. Se filtra aquí para no ofrecer una opción que
 * el servidor va a rechazar: un desplegable con órdenes imposibles es un desplegable que se elige
 * mal. El servidor lo vuelve a comprobar de todos modos; esto es cortesía, no la guarda.
 */
const ordenesParaAgregar = computed(() => {
    const sel = serviciosSeleccionados.value;
    if (sel.length === 0 || conflictoSeleccion.value) return [];

    const fileId = sel[0].file;
    const comprador = sel[0].compradorEfectivoMaestroId ?? sel[0].compradorMaestroId ?? null;

    return operacionStore.ordenesServicio.filter((o) =>
        o.estadoOs === 'borrador'
        && o.file === fileId
        && (o.compradorMaestroId ?? null) === comprador
    );
});

const mostrarModalAgregar = ref(false);
const errorAgregar = ref<string | null>(null);
const agregandoA = ref<string | null>(null);

const agregarAOrden = async (ordenId?: string | null) => {
    if (!ordenId) return;
    const ids = serviciosSeleccionados.value.map(s => s.id!).filter(Boolean);
    if (ids.length === 0) return;

    agregandoA.value = ordenId;
    errorAgregar.value = null;
    try {
        await operacionStore.agregarAOrden(ordenId, ids);
        mostrarModalAgregar.value = false;
        seleccionados.value = [];
        await cargarBiblia();
    } catch (e) {
        // El servidor explica QUÉ falló —ya emitida, otro comprador, ya en otra orden—.
        errorAgregar.value = mensajeDeErrorApi(e, 'No se pudo agregar a la orden.');
    } finally {
        agregandoA.value = null;
    }
};

const confirmarOs = async (emitir: boolean) => {
    const sel = serviciosSeleccionados.value;
    if (sel.length === 0) return;

    guardandoOs.value = true;
    errorOs.value = null;
    try {
        await operacionStore.emitirOrdenServicio({
            servicioIds: sel.map(s => s.id!).filter(Boolean),
            numeroOs: formOs.value.numeroOs,
            compradorMaestroId: formOs.value.compradorMaestroId || null,
            compradorNombre: formOs.value.compradorNombre || null,
            soloBorrador: !emitir,
        });
        mostrarModalOs.value = false;
        seleccionados.value = [];
        await cargarBiblia();
    } catch (e) {
        // El servidor explica QUÉ falló —expedientes distintos, un servicio ya en otra orden,
        // algo que no se compra—. Repetir un mensaje genérico encima de eso lo tapaba.
        errorOs.value = mensajeDeErrorApi(e, 'No se pudo emitir la Orden de Servicio.');
    } finally {
        guardandoOs.value = false;
    }
};

// ══ FICHA DEL SERVICIO (móvil) ═════════════════════════════════════════════
//
// En el teléfono la tabla se queda en tres columnas —selector, hora y servicio— y todo lo demás
// se edita aquí. La alternativa era comprimir nueve columnas en 360 px, que es como se llega a
// una fila que no se puede leer ni tocar sin equivocarse.
//
// ⚠️ **Aquí se edita sobre un BORRADOR y se guarda al confirmar**, al revés que en la tabla, donde
// cada campo se manda al perder el foco. En un teléfono no hay sitio para poner el aviso de
// guardado al lado del campo, así que el operador no sabía si su cambio había entrado. Con el
// borrador, «Guardar» es la respuesta a esa pregunta.

const servicioFicha = ref<OperacionServicio | null>(null);
const guardandoFicha = ref(false);
const errorFicha = ref<string | null>(null);

interface BorradorFicha {
    horaRecojo: string;
    puntoRecojo: string;
    puntoEntrega: string;
    estadoReservaProveedor: string;
    estadoOperacion: string;
}

const borradorFicha = ref<BorradorFicha>({
    horaRecojo: '', puntoRecojo: '', puntoEntrega: '',
    estadoReservaProveedor: '', estadoOperacion: '',
});

/** Se abre SÓLO en móvil: en escritorio la tabla ya es editable y abrir una ficha estorbaría. */
const abrirFicha = (servicio: OperacionServicio) => {
    if (!esMovil()) return;

    servicioFicha.value = servicio;
    errorFicha.value = null;
    borradorFicha.value = {
        horaRecojo: servicio.horaRecojo ?? '',
        puntoRecojo: servicio.puntoRecojo ?? '',
        puntoEntrega: servicio.puntoEntrega ?? '',
        estadoReservaProveedor: servicio.estadoReservaProveedor ?? 'sin-solicitar',
        estadoOperacion: servicio.estadoOperacion ?? 'pendiente',
    };
};

/** Sólo lo que CAMBIÓ. Mandar el resto reescribiría campos que nadie tocó. */
const cambiosDeFicha = (): Record<string, unknown> => {
    const s = servicioFicha.value;
    if (!s) return {};

    const cambios: Record<string, unknown> = {};
    const b = borradorFicha.value;

    const texto = (v: string): string | null => (v.trim() === '' ? null : v.trim());

    if (texto(b.horaRecojo) !== (s.horaRecojo ?? null)) cambios.horaRecojo = texto(b.horaRecojo);
    if (texto(b.puntoRecojo) !== (s.puntoRecojo ?? null)) cambios.puntoRecojo = texto(b.puntoRecojo);
    if (texto(b.puntoEntrega) !== (s.puntoEntrega ?? null)) cambios.puntoEntrega = texto(b.puntoEntrega);
    if (b.estadoReservaProveedor !== s.estadoReservaProveedor) cambios.estadoReservaProveedor = b.estadoReservaProveedor;
    if (b.estadoOperacion !== s.estadoOperacion) cambios.estadoOperacion = b.estadoOperacion;

    return cambios;
};

const hayCambiosEnFicha = computed(() => Object.keys(cambiosDeFicha()).length > 0);

const guardarFicha = async () => {
    const s = servicioFicha.value;
    const cambios = cambiosDeFicha();
    if (!s?.id || Object.keys(cambios).length === 0) { servicioFicha.value = null; return; }

    // La hora, si se escribe, tiene que ser una hora. En la tabla esto revierte el input en
    // silencio; aquí se dice, porque hay sitio para decirlo.
    if (typeof cambios.horaRecojo === 'string' && !PATRON_HORA.test(cambios.horaRecojo)) {
        errorFicha.value = 'La hora de recojo va en formato 24 h, por ejemplo 06:15.';
        return;
    }

    guardandoFicha.value = true;
    errorFicha.value = null;
    try {
        await operacionStore.actualizarServicio(s.id, cambios);
        servicioFicha.value = null;
        await cargarBiblia();
    } catch (e) {
        errorFicha.value = mensajeDeErrorApi(e, 'No se pudo guardar.');
    } finally {
        guardandoFicha.value = false;
    }
};

// ══ ENVIAR LA ORDEN AL PROVEEDOR ═══════════════════════════════════════════
//
// ── Por qué se previsualiza ────────────────────────────────────────────────
// Porque es IRREVERSIBLE: un correo mandado no se retira. El documento sale de los ítems
// congelados y va a donde diga la identidad del proveedor, así que hay dos cosas que sólo se
// ven mirando —que las líneas son las pactadas y que va a la dirección correcta— y las dos se
// comprueban en dos segundos.
//
// ── Y por qué está separado de emitir ──────────────────────────────────────
// Emitir congela el contenido; enviar se lo pone delante a alguien de fuera. Separados,
// **reenviar no necesita nada nuevo**: es este mismo botón otra vez, que es lo normal cuando el
// proveedor perdió el correo o cambió de contacto.
const ordenAEnviar = ref<OperacionOrdenServicio | null>(null);
const documento = ref<DocumentoDeOrden | null>(null);
const canalElegido = ref<string>('');
const cargandoDocumento = ref(false);
const enviandoOrden = ref(false);
const errorEnvio = ref<string | null>(null);

const abrirEnvio = async (orden: OperacionOrdenServicio): Promise<void> => {
    if (!orden.id) return;

    ordenAEnviar.value = orden;
    documento.value = null;
    canalElegido.value = '';
    errorEnvio.value = null;
    cargandoDocumento.value = true;

    const r = await operacionStore.documentoDeOrden(orden.id);

    cargandoDocumento.value = false;

    if ('error' in r) {
        errorEnvio.value = r.error;

        return;
    }

    documento.value = r;
    // Se preselecciona el primero disponible: con un solo canal, elegirlo es fricción sin
    // ganancia. Con varios, el operador lo cambia.
    canalElegido.value = r.canales.find(c => c.disponible)?.id ?? '';
};

const confirmarEnvio = async (): Promise<void> => {
    const orden = ordenAEnviar.value;
    if (!orden?.id || !canalElegido.value) return;

    enviandoOrden.value = true;
    errorEnvio.value = null;

    const motivo = await operacionStore.enviarOrdenAlProveedor(orden.id, canalElegido.value);

    enviandoOrden.value = false;

    if (motivo !== null) {
        errorEnvio.value = motivo;

        return;
    }

    ordenAEnviar.value = null;
    // La bitácora de esa orden cambió: si está abierta, que lo refleje.
    if (ordenActiva.value?.id === orden.id) await operacionStore.fetchMensajesPorOrden(orden.id);
};

// ══ ELIMINAR UN BORRADOR ═══════════════════════════════════════════════════
//
// Devuelve los servicios al pool, y eso NO lo hace este código:
// `operacion_servicio.orden_servicio_id` es `ON DELETE SET NULL`, así que se sueltan solos y
// quedan libres para entrar en otra orden. Los pagos y la bitácora sí caen en cascada — son de
// la orden, no del servicio.
//
// Confirmación en dos toques y no `window.confirm`: el mismo patrón que el catálogo de
// proveedores, y así el aviso puede decir la consecuencia («los servicios vuelven al pool») en
// vez de un «¿seguro?» pelado.
/**
 * Qué se puede hacer con una orden según dónde esté.
 *
 * ── Por qué botones y no el `<select>` que había ────────────────────────────
 * El estado se movía con un desplegable dentro del formulario de edición, y ese control no
 * distingue entre corregir un número de OS y **mandarle un documento a un proveedor**: las dos
 * cosas salían del mismo gesto, sin confirmación y a un desliz de distancia. Un `<select>`
 * además ofrece todos los destinos aunque el backend vaya a rechazar la mitad.
 *
 * Aquí sólo aparece lo que cabe desde el estado actual, y cada acción confirma antes.
 *
 * ⚠️ Es un ESPEJO de `OperacionOrdenEmision::validarTransicion()`, que es quien manda: una
 * anulada no vuelve atrás y una emitida no regresa a borrador. Esto sólo evita ofrecer botones
 * que iban a dar 422.
 */
const accionesDe = (estado?: string | null): Array<{ id: string; destino: string; etiqueta: string; icono: string; tono: 'normal' | 'grave' }> => {
    switch (estado) {
        case 'borrador':
            return [{ id: 'emitir', destino: 'emitida', etiqueta: 'Emitir', icono: 'fa-paper-plane', tono: 'normal' }];
        case 'emitida':
            return [
                { id: 'confirmar', destino: 'confirmada', etiqueta: 'Confirmar', icono: 'fa-circle-check', tono: 'normal' },
                { id: 'anular', destino: 'cancelada', etiqueta: 'Anular', icono: 'fa-ban', tono: 'grave' },
            ];
        case 'confirmada':
            return [
                { id: 'completar', destino: 'completada', etiqueta: 'Completar', icono: 'fa-flag-checkered', tono: 'normal' },
                { id: 'anular', destino: 'cancelada', etiqueta: 'Anular', icono: 'fa-ban', tono: 'grave' },
            ];
        case 'completada':
            return [{ id: 'anular', destino: 'cancelada', etiqueta: 'Anular', icono: 'fa-ban', tono: 'grave' }];
        // Cancelada es terminal: no vuelve. Se emite otra que la reemplace.
        default:
            return [];
    }
};

/** Lo que se le dice al operador ANTES de hacerlo. Cada una tiene su consecuencia propia. */
const consecuenciaDe = (accion: string): string => ({
    emitir: '¿Emitir? Se congela el contenido y ya no vuelve a borrador',
    confirmar: '¿Confirmar?',
    completar: '¿Completar?',
    anular: '¿Anular? No vuelve atrás, y sus servicios regresan al pool',
}[accion] ?? '¿Seguro?');

/** `orden.id + ':' + accion`, para que confirmar una no arme las demás. */
const confirmandoAccion = ref<string | null>(null);
const ejecutandoAccion = ref<string | null>(null);

const ejecutarAccion = async (
    orden: OperacionOrdenServicio,
    accion: { id: string; destino: string },
): Promise<void> => {
    if (!orden.id) return;

    const clave = `${orden.id}:${accion.id}`;
    errorOrden.value = null;

    if (confirmandoAccion.value !== clave) {
        confirmandoAccion.value = clave;

        return;
    }

    ejecutandoAccion.value = clave;

    try {
        await operacionStore.cambiarEstadoOrden(orden.id, accion.destino);
        await cargarBiblia();
    } catch (e) {
        errorOrden.value = { id: orden.id, motivo: mensajeDeErrorApi(e, 'No se pudo cambiar el estado de la orden.') };
    } finally {
        ejecutandoAccion.value = null;
        confirmandoAccion.value = null;
    }
};

const confirmandoOrden = ref<string | null>(null);
const borrandoOrden = ref<string | null>(null);
/**
 * El motivo del rechazo, PEGADO a su orden.
 *
 * No vale `errorOs`: ése vive dentro del modal de alta y en la lista no se ve. Y no vale un
 * aviso global arriba, porque con varias órdenes en pantalla no se sabría de cuál habla.
 */
const errorOrden = ref<{ id: string; motivo: string } | null>(null);

const eliminarOrden = async (orden: OperacionOrdenServicio): Promise<void> => {
    if (!orden.id) return;

    errorOrden.value = null;

    if (confirmandoOrden.value !== orden.id) {
        confirmandoOrden.value = orden.id;

        return;
    }

    borrandoOrden.value = orden.id;

    const motivo = await operacionStore.eliminarOrdenServicio(orden.id);

    borrandoOrden.value = null;
    confirmandoOrden.value = null;

    if (motivo !== null) {
        // El backend redacta el porqué —«está emitida: no se borra, anúlala»— y se pinta tal cual.
        errorOrden.value = { id: orden.id, motivo };

        return;
    }

    // La orden ya salió de la colección dentro del store. Esto es lo OTRO que cambió: sus
    // servicios volvieron al pool, así que La Biblia tiene que releerse o seguiría
    // enseñándolos comprometidos con una orden que ya no existe.
    await cargarBiblia();
};

// ============================================================================
// BITÁCORA DE MENSAJES DE UNA OS
// ============================================================================
const ordenActiva = ref<OperacionOrdenServicio | null>(null);

/** Qué orden tiene el detalle abierto. Sólo una a la vez. */
const ordenExpandida = ref<string | null>(null);

/** Los servicios de la orden desplegada. Se piden al abrirla, no antes. */

/**
 * Los servicios vienen EMBEBIDOS en la orden, no se piden aparte.
 *
 * La primera versión los pedía con `?ordenServicio=<iri>`: el endpoint devolvía 200 y una
 * colección vacía, así que el detalle decía «0 servicio(s)» con tres dentro. En vez de
 * perseguir el filtro se llevaron los campos al grupo `operacion:read`, que es lo que usa la
 * orden al serializar su colección. Sale más simple: una petición menos, ninguna dependencia
 * de un filtro, y el detalle abre instantáneo porque el dato ya está en memoria.
 *
 * El payload aguanta: son ~8 campos por servicio y el listado de órdenes es corto por
 * naturaleza —una orden por proveedor y expediente—.
 */
const alternarDetalle = (orden: OperacionOrdenServicio) => {
    ordenExpandida.value = ordenExpandida.value === orden.id ? null : (orden.id ?? null);
};

/** Los servicios de la orden abierta, tal como vinieron en el listado. */
const serviciosDeOrdenAbierta = computed<OperacionServicio[]>(() => {
    const orden = operacionStore.ordenesServicio.find(o => o.id === ordenExpandida.value);
    return (orden?.operacionServicios ?? []) as unknown as OperacionServicio[];
});

/**
 * Ajustar lo NEGOCIADO sin salir de la orden.
 *
 * Antes había que volver a La Biblia, encontrar la fila y editarla allí — ir y venir entre
 * dos pantallas justo cuando estás cerrando el precio con el proveedor, que es el momento en
 * que tienes la orden delante.
 *
 * Toca `costoNegociado` y `monedaNegociada`, que son campos DEL OPERADOR: la reconciliación no
 * los pisa jamás. El cotizado no se toca, que es la referencia con la que se concilia.
 */
/**
 * «4 noches · » o «» — la cantidad del componente en palabras, y sólo si aporta.
 *
 * Un `1` no se muestra: «1 noche» es ruido para un traslado o una entrada, donde la unidad es
 * implícita. La palabra depende del tipo: un alojamiento son noches, lo demás son «uds».
 */
const nochesTexto = (cantidad: number | undefined, tipo: string | null | undefined): string => {
    const n = cantidad ?? 1;
    if (n <= 1) return '';
    const palabra = tipo === 'alojamiento' ? 'noches' : 'uds';
    return `${n} ${palabra} · `;
};

/** «hace 3 h», «hace 2 días», «hace un momento» — legible, no una fecha ISO. */
const desdeHace = (iso: string | null | undefined): string => {
    if (!iso) return '';
    const ms = Date.now() - new Date(iso).getTime();
    const min = Math.floor(ms / 60000);
    if (min < 1) return 'hace un momento';
    if (min < 60) return `hace ${min} min`;
    const h = Math.floor(min / 60);
    if (h < 24) return `hace ${h} h`;
    const d = Math.floor(h / 24);
    return `hace ${d} día${d !== 1 ? 's' : ''}`;
};

const fechaHora = (iso: string): string =>
    new Date(iso).toLocaleString('es-PE', { day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit' });

// ── EL BOTÓN «ATRÁS» CIERRA EL MODAL, NO SALE DE LA VISTA ─────────────────────
//
// Sin esto, con un modal abierto el gesto de volver del móvil disparaba la navegación del
// router y mandaba al menú, perdiendo dónde estabas. Cada modal empuja una entrada de
// history al abrirse; «atrás» (popstate) la consume cerrando el modal. Cerrar con la X hace
// lo mismo por código (`history.back()`), así la entrada nunca queda colgando.
const hayModalAbierto = computed(() => !!(
    expedienteAbierto.value || pagosOrden.value || bitacoraServicio.value ||
    ordenEditando.value || ordenActiva.value || mostrarModalOs.value
));

const cerrarTodosLosModales = (): void => {
    expedienteAbierto.value = null;
    pagosOrden.value = null;
    bitacoraServicio.value = null;
    ordenEditando.value = null;
    ordenActiva.value = null;
    mostrarModalOs.value = false;
};

let modalEnHistory = false;

// El watch de `hayModalAbierto` se registra en onMounted, NO aquí. Su fuente agrega refs de
// modales que se declaran más abajo (expedienteAbierto, pagosOrden…), y `watch()` evalúa la
// fuente al crearse: en el setup las leería antes de su declaración → TDZ («Cannot access 'xe'
// before initialization»). En onMounted ya están todas inicializadas. Un modal no puede estar
// abierto antes del montaje, así que no se pierde ningún disparo.

/** Cierra el modal activo consumiendo su entrada de history, para que «atrás» no sobre. */
const cerrarModal = (): void => {
    if (modalEnHistory) {
        history.back();          // dispara popstate → cierra
    } else {
        cerrarTodosLosModales();
    }
};

const onPopstateModal = (): void => {
    modalEnHistory = false;
    if (hayModalAbierto.value) cerrarTodosLosModales();
};

// ── MODAL DE EXPEDIENTE (namelist + documentos + salto a cotización) ─────────
const expedienteAbierto = ref<{ fileId: string; nombre: string; cotizacionId: string | null; fileIdParaRuta: string; localizador: string | null; version: number | null } | null>(null);
const localizadorCopiado = ref(false);

// «HJDLDB-v1»: localizador del expediente + versión de la cotización. Es el identificador que
// el operador copia y comparte.
const localizadorVersion = computed(() => {
    const e = expedienteAbierto.value;
    if (!e?.localizador) return '';
    return e.version != null ? `${e.localizador}-v${e.version}` : e.localizador;
});

// Enlace a la vista del cliente (app pax, otro dominio). Abre en otra pestaña.
const linkPax = computed(() => {
    const e = expedienteAbierto.value;
    if (!e?.localizador || e.version == null) return '';
    return `${getUrls().pax}/file/${e.localizador}/v/${e.version}`;
});

const copiarLocalizador = async (): Promise<void> => {
    if (!localizadorVersion.value) return;
    try {
        await navigator.clipboard.writeText(localizadorVersion.value);
        localizadorCopiado.value = true;
        setTimeout(() => { localizadorCopiado.value = false; }, 1500);
    } catch { /* clipboard no disponible: sin feedback, no rompe nada */ }
};
const expedienteDetalle = ref<ExpedienteDetalle | null>(null);
const cargandoExpediente = ref(false);
const panelDocumentos = ref(true);
const panelNamelist = ref(true);

const abrirExpediente = async (servicio: OperacionServicio): Promise<void> => {
    const fileId = servicio.file?.id;
    if (!fileId) return;

    expedienteAbierto.value = {
        fileId,
        fileIdParaRuta: fileId,
        nombre: servicio.file?.nombreGrupo || 'Expediente',
        cotizacionId: (servicio as { cotizacionId?: string }).cotizacionId ?? null,
        localizador: (servicio.file as { localizador?: string | null } | undefined)?.localizador ?? null,
        version: (servicio as { cotizacionVersion?: number | null }).cotizacionVersion ?? null,
    };
    expedienteDetalle.value = null;
    cargandoExpediente.value = true;
    try {
        expedienteDetalle.value = await operacionStore.fetchExpedienteDetalle(fileId);
    } finally {
        cargandoExpediente.value = false;
    }
};

/** Salta al editor de la cotización de la que cuelga el servicio clicado. */
const irACotizacion = (): void => {
    const e = expedienteAbierto.value;
    if (!e?.cotizacionId) return;
    router.push({ name: 'cotizaciones_editor', params: { fileId: e.fileIdParaRuta, cotizacionId: e.cotizacionId } });
};

/** Abre el expediente completo (datos del cliente, versiones, bóveda de documentos). */
const irAExpediente = (): void => {
    const e = expedienteAbierto.value;
    if (!e?.fileIdParaRuta) return;
    router.push({ name: 'file_detalle', params: { id: e.fileIdParaRuta } });
};

// ── Subir documento al expediente desde el modal ─────────────────────────────
const docInputRef = ref<HTMLInputElement | null>(null);
const subiendoDoc = ref(false);

const onSubirDocumento = async (event: Event): Promise<void> => {
    const input = event.target as HTMLInputElement;
    const archivo = input.files?.[0];
    const e = expedienteAbierto.value;
    if (!archivo || !e?.fileId) return;

    subiendoDoc.value = true;
    try {
        const ok = await operacionStore.subirDocumentoExpediente(e.fileId, archivo);
        if (ok) {
            // Refresca la lista del modal para que el documento recién subido aparezca.
            expedienteDetalle.value = await operacionStore.fetchExpedienteDetalle(e.fileId);
        }
    } finally {
        subiendoDoc.value = false;
        if (docInputRef.value) docInputRef.value.value = ''; // permite resubir el mismo archivo
    }
};

/** Nombre completo de un pasajero del namelist, con lo que haya. */
const nombrePasajero = (p: Record<string, unknown>): string =>
    [p.nombre, p.apellido].filter(Boolean).join(' ') || 'Sin nombre';

// ── PAGOS A CUENTA AL PROVEEDOR ──────────────────────────────────────────────
const pagosOrden = ref<OperacionOrdenServicio | null>(null);
const pagos = ref<PagoProveedor[]>([]);
const cargandoPagos = ref(false);
const guardandoPago = ref(false);
const errorPago = ref<string | null>(null);
const formPago = ref({ monto: '', moneda: '', fecha: hoyIso(), medioPago: '', notas: '' });

/** Las monedas que la orden tiene, para no dejar pagar en una divisa que no le corresponde. */
const monedasDeOrden = computed<string[]>(() =>
    (pagosOrden.value?.totalesPorMoneda ?? [])
        .map(t => t.moneda ?? '')
        .filter((m): m is string => m !== '' && m !== '—'));

/**
 * El icono del medio, del catálogo del backend.
 *
 * El respaldo cubre la carrera real: el historial puede pintarse antes de que responda
 * `cargarMediosPago()`. No cubre «pago sin medio», que no existe — la columna es NOT NULL.
 */
const iconoMedioPago = (id: string): string =>
    operacionStore.mediosPago.find(m => m.id === id)?.icono ?? 'fa-money-check-dollar';

const abrirPagos = async (orden: OperacionOrdenServicio): Promise<void> => {
    pagosOrden.value = orden;
    pagos.value = [];
    errorPago.value = null;
    const primera = (orden.totalesPorMoneda ?? []).map(t => t.moneda ?? '').filter(m => m && m !== '—')[0] ?? '';
    // El medio NO se preselecciona: cómo se pagó es un hecho, y un valor por defecto lo
    // convierte en lo que había puesto al abrir el formulario. Mismo criterio que el cobro
    // rápido del panel financiero de la reserva.
    formPago.value = { monto: '', moneda: primera, fecha: hoyIso(), medioPago: '', notas: '' };
    void operacionStore.cargarMediosPago();

    if (!orden.id) return;
    cargandoPagos.value = true;
    try {
        pagos.value = await operacionStore.fetchPagos(orden.id);
    } finally {
        cargandoPagos.value = false;
    }
};

const registrarPago = async (): Promise<void> => {
    const orden = pagosOrden.value;
    if (!orden?.id) return;

    const monto = formPago.value.monto.trim().replace(',', '.');
    if (!/^\d+(\.\d{1,2})?$/.test(monto) || Number(monto) <= 0) {
        errorPago.value = 'El monto tiene que ser un número mayor que cero.';
        return;
    }
    if (!formPago.value.moneda) {
        errorPago.value = 'Elige la moneda del pago.';
        return;
    }
    if (!formPago.value.medioPago) {
        errorPago.value = 'Elige por qué medio se pagó.';
        return;
    }

    guardandoPago.value = true;
    errorPago.value = null;
    try {
        const motivo = await operacionStore.crearPago({
            ordenServicio: `/platform/ops/operacion_orden_servicios/${orden.id}`,
            monto: Number(monto).toFixed(2),
            moneda: `/platform/maestro/monedas/${formPago.value.moneda}`,
            fecha: formPago.value.fecha,
            medioPago: formPago.value.medioPago,
            notas: formPago.value.notas.trim() || null,
        });

        // El motivo lo redacta el backend —«esta orden se maneja en USD…»— y se pinta tal cual.
        if (motivo !== null) { errorPago.value = motivo; return; }

        // Recargar los pagos y la orden (su saldo lo recalcula el servidor).
        pagos.value = await operacionStore.fetchPagos(orden.id);
        await operacionStore.refrescarOrden(orden.id);
        pagosOrden.value = operacionStore.ordenesServicio.find(o => o.id === orden.id) ?? pagosOrden.value;
        // Se conservan moneda y medio: quien registra tres abonos seguidos los hace casi
        // siempre igual, y volver a elegirlos cada vez es fricción sin ganancia.
        formPago.value = {
            monto: '',
            moneda: formPago.value.moneda,
            fecha: hoyIso(),
            medioPago: formPago.value.medioPago,
            notas: '',
        };
    } finally {
        guardandoPago.value = false;
    }
};

const borrarPago = async (pago: PagoProveedor): Promise<void> => {
    const orden = pagosOrden.value;
    if (!pago.id || !orden?.id) return;

    const ok = await operacionStore.eliminarPago(pago.id);
    if (!ok) { errorPago.value = 'No se pudo eliminar el pago.'; return; }

    pagos.value = await operacionStore.fetchPagos(orden.id);
    await operacionStore.refrescarOrden(orden.id);
    pagosOrden.value = operacionStore.ordenesServicio.find(o => o.id === orden.id) ?? pagosOrden.value;
};

// ── HISTORIAL DE ESTADOS (bitácora) ──────────────────────────────────────────
const bitacoraServicio = ref<OperacionServicio | null>(null);
const bitacora = ref<BitacoraEstado[]>([]);
const cargandoBitacora = ref(false);

const abrirBitacora = async (servicio: OperacionServicio): Promise<void> => {
    bitacoraServicio.value = servicio;
    bitacora.value = [];
    const id = idDe(servicio);
    if (!id) return;

    cargandoBitacora.value = true;
    try {
        bitacora.value = await operacionStore.fetchBitacoraEstado(id);
    } finally {
        cargandoBitacora.value = false;
    }
};

/** El label legible de un valor de estado, sea de reserva u operación. */
const etiquetaEstado = (campo: string, valor: string): string => {
    const cfg = campo === 'reserva'
        ? ESTADO_RESERVA_PROVEEDOR_CONFIG[valor as keyof typeof ESTADO_RESERVA_PROVEEDOR_CONFIG]
        : ESTADO_OPERACION_CONFIG[valor as keyof typeof ESTADO_OPERACION_CONFIG];
    return cfg?.label ?? valor;
};

/** El id de una fila, venga de donde venga: La Biblia trae `id`, la orden `servicioId`. */
const idDe = (s: OperacionServicio): string =>
    s.id ?? (s as { servicioId?: string }).servicioId ?? '';

/**
 * Referencias a los editores por fila, para reabrir el que falló al guardar.
 *
 * El estado de edición ya NO vive aquí: cada `EditorCostoNegociado` lleva el suyo. Lo único
 * que el padre necesita es poder avisar a uno concreto de que su PATCH falló.
 */
const editores = ref<Record<string, InstanceType<typeof EditorCostoNegociado> | null>>({});
const registrarEditor = (id: string, el: unknown): void => {
    editores.value[id] = el as InstanceType<typeof EditorCostoNegociado> | null;
};

/**
 * Guarda el costo negociado de una fila. Lo dispara el `@guardar` del editor, que ya trae el
 * importe validado y la moneda; aquí sólo se resuelve el id, se compone el IRI y se hace el
 * PATCH. Si falla, se le dice al editor que reabra con lo escrito.
 */
const onGuardarCosto = async (
    servicio: OperacionServicio,
    payload: { costoNegociado: string; monedaNegociada: string },
): Promise<void> => {
    const id = idDe(servicio);
    if (!id) return;   // el editor sólo se pinta sobre filas con id, pero por si acaso

    try {
        await operacionStore.actualizarServicio(id, {
            costoNegociado: payload.costoNegociado,
            monedaNegociada: `/platform/maestro/monedas/${payload.monedaNegociada}`,
        });

        // En Órdenes se refresca SÓLO la orden tocada —no la lista entera— para que
        // `totalesPorMoneda` (que calcula el servidor) se ponga al día sin parpadear toda la
        // pantalla. La Biblia no lo necesita: `actualizarServicio` reemplaza la fila viva.
        if (activeTab.value === 'ordenes' && ordenExpandida.value) {
            await operacionStore.refrescarOrden(ordenExpandida.value);
        }
    } catch {
        editores.value[id]?.marcarError('No se pudo guardar. Revisa la conexión.');
    }
};

// ── EDICIÓN DE LA CABECERA ───────────────────────────────────────────────────
const ordenEditando = ref<OperacionOrdenServicio | null>(null);
const formEdicion = ref({ numeroOs: '', compradorMaestroId: '' as string | null, compradorNombre: '', estadoOs: 'borrador' });
const guardandoEdicion = ref(false);
const errorEdicion = ref<string | null>(null);

const abrirEdicion = (orden: OperacionOrdenServicio) => {
    ordenEditando.value = orden;
    formEdicion.value = {
        numeroOs: orden.numeroOs ?? '',
        compradorMaestroId: orden.compradorMaestroId ?? '',
        compradorNombre: orden.compradorNombre ?? '',
        estadoOs: orden.estadoOs ?? 'borrador',
    };
    errorEdicion.value = null;
    void operacionStore.fetchProveedores();
};

const onDestinatarioEdicion = (id: unknown): void => {
    const elegido = operacionStore.proveedores.find(p => p.id === String(id ?? ''));
    formEdicion.value.compradorNombre = elegido?.nombreComercial ?? '';
};

/**
 * Reemite: anula la orden y deja la sucesora **ya creada, en borrador**.
 *
 * ⚠️ **En una sola llamada, y ese detalle es el punto.** Si sólo se anulara, las filas quedarían
 * sueltas en La Biblia y el trabajo se perdería en cuanto el operador cerrara la pestaña o se
 * distrajera: nadie recuerda al día siguiente qué siete filas iban juntas. Aquí el servidor
 * anula, valida y vuelve a enlazar dentro de la misma transacción, así que en ningún momento
 * hay filas huérfanas.
 *
 * La sucesora nace **en borrador y no emitida**: reemitir no es mandar otro papel a ciegas, es
 * rehacerlo para revisarlo. Mientras es borrador sigue siendo una vista viva, así que refleja
 * lo que La Biblia diga ahora — que es justo lo que había cambiado.
 *
 * La anulada conserva sus líneas congeladas: el histórico no se toca. Y `reemplazaAId` deja
 * escrita la cadena «OS-014 → OS-021», que es lo que se consulta cuando un proveedor reclama.
 */
/**
 * Aplica los cambios menores: la orden se actualiza y **sigue emitida**.
 *
 * Botón aparte del de reemitir a propósito. Confirmar la hora que dio el proveedor y rehacer un
 * documento porque cambió la fecha son dos gestos con consecuencias muy distintas, y darles el
 * mismo botón invita a usar el destructivo por costumbre.
 */
const aplicarMenores = async (orden: OperacionOrdenServicio) => {
    if (!orden.id) return;

    try {
        await operacionStore.aplicarCambiosMenores(orden.id);
        await cargarBiblia();
    } catch (e) {
        avisoPapel.value = mensajeDeErrorApi(e, 'No se pudo actualizar la orden.');
        window.setTimeout(() => { avisoPapel.value = null; }, 8000);
    }
};

const anularParaReemitir = async (orden: OperacionOrdenServicio) => {
    const servicioIds = (orden.operacionServicios ?? [])
        .map(s => extractIdStr(s))
        .filter(Boolean);

    if (!orden.id || servicioIds.length === 0) return;

    const hoy = hoyIso().replace(/-/g, '');

    try {
        await operacionStore.emitirOrdenServicio({
            servicioIds,
            numeroOs: `OS-${hoy}-${String(Math.floor(Math.random() * 900) + 100)}`,
            compradorMaestroId: orden.compradorMaestroId ?? null,
            compradorNombre: orden.compradorNombre ?? null,
            reemplazaAId: orden.id,
            // Nace en borrador: se revisa y se emite aparte.
            soloBorrador: true,
        });
        await cargarBiblia();
    } catch (e) {
        avisoPapel.value = mensajeDeErrorApi(e, 'No se pudo reemitir la orden.');
        window.setTimeout(() => { avisoPapel.value = null; }, 8000);
    }
};

const guardarEdicion = async () => {
    const orden = ordenEditando.value;
    if (!orden?.id) return;

    guardandoEdicion.value = true;
    errorEdicion.value = null;
    try {
        await operacionStore.actualizarOrdenServicio(orden.id, {
            numeroOs: formEdicion.value.numeroOs,
            compradorMaestroId: formEdicion.value.compradorMaestroId || null,
            compradorNombre: formEdicion.value.compradorNombre || null,
        });

        // El estado ya no se toca aquí: este formulario corrige la cabecera —número y
        // destinatario— y nada más. Mover el estado es emitir o anular, y eso tiene sus
        // botones con confirmación. Emitir sigue congelando el contenido, así que el orden
        // sigue importando: primero se corrige la cabecera, después se emite.

        ordenEditando.value = null;
        await cargarBiblia();
    } catch (e) {
        errorEdicion.value = mensajeDeErrorApi(e, 'No se pudo guardar. Comprueba que el número de OS no esté repetido.');
    } finally {
        guardandoEdicion.value = false;
    }
};
const cuerpoMensaje = ref<string>('');
const enviandoMensaje = ref<boolean>(false);

const abrirMensajes = async (orden: OperacionOrdenServicio) => {
    ordenActiva.value = orden;
    cuerpoMensaje.value = '';
    if (orden.id) await operacionStore.fetchMensajesPorOrden(orden.id);
};

const enviarMensaje = async () => {
    const texto = cuerpoMensaje.value.trim();
    if (!texto || !ordenActiva.value?.id) return;

    enviandoMensaje.value = true;
    try {
        await operacionStore.registrarMensaje({
            ordenServicio: `/platform/ops/operacion_orden_servicios/${ordenActiva.value.id}`,
            tipo: 'email',
            cuerpoHtml: texto,
        });
        cuerpoMensaje.value = '';
    } finally {
        enviandoMensaje.value = false;
    }
};

const formatearFecha = (iso?: string | null): string => {
    if (!iso) return '';
    return `${iso.slice(8, 10)}/${iso.slice(5, 7)} ${iso.slice(11, 16)}`;
};

onMounted(async () => {
    // El vocabulario primero: sin él los chips no existen y el operador no puede filtrar.
    // Las monedas van en paralelo: hacen falta para el selector de la moneda negociada.
    //
    // Y el catálogo de organizaciones, que antes sólo se pedía al abrir el modal de la Orden:
    // ahora alimenta el `<datalist>` de prestador y comprador de CADA fila, así que tiene que
    // estar antes de que el operador pueda escribir. Es idempotente.
    await Promise.all([
        operacionStore.fetchLugares(),
        operacionStore.fetchMonedas(),
        operacionStore.fetchProveedores(),
    ]);
    await cargarBiblia();
});

onMounted(() => {
    // Empuja una entrada de history al abrir cualquier modal, para que «atrás» lo cierre.
    // Aquí y no en el setup: ver la nota junto a la declaración de `hayModalAbierto`.
    watch(hayModalAbierto, (abierto) => {
        if (abierto && !modalEnHistory) {
            history.pushState({ modalOperacion: true }, '');
            modalEnHistory = true;
        }
    });
});

onMounted(() => window.addEventListener('popstate', onPopstateModal));
onUnmounted(() => window.removeEventListener('popstate', onPopstateModal));

// Si se navega fuera con un modal abierto (un enlace, «abrir cotización»), se limpia la
// entrada fantasma para no dejar un «atrás» que no hace nada al volver.
onBeforeRouteLeave(() => { if (modalEnHistory) { modalEnHistory = false; } });
</script>

<template>
    <div class="h-screen bg-[#F8FAFC] flex flex-col font-sans overflow-hidden">

        <!-- UNA sola lista para todas las filas del cuadro. Con un selector por fila el DOM
             crecería a 99 opciones × cientos de filas; el `<datalist>` se declara una vez y
             cada input lo referencia por id. Es lo que hace que el papel quede ENLAZADO al
             catálogo sin cambiar la ergonomía de un cuadro tan denso. -->
        <datalist id="catalogo-organizaciones">
            <option v-for="p in operacionStore.proveedores" :key="p.id" :value="p.nombreComercial" />
        </datalist>

        <!-- Lo escrito no estaba en el catálogo: se revirtió y se dice por qué. -->
        <div
            v-if="avisoPapel"
            class="fixed bottom-4 left-1/2 -translate-x-1/2 z-50 px-4 py-2 rounded-lg bg-amber-50 border border-amber-300 text-amber-800 text-xs font-bold shadow-lg"
        >
            <i class="fas fa-triangle-exclamation mr-1.5"></i>{{ avisoPapel }}
        </div>

        <!-- ================================================================
             HEADER
             ================================================================ -->
        <!-- En móvil la cabecera va en DOS filas: con el título y las pestañas en
             la misma, el título se partía en varias líneas de dos palabras. Desde
             `md` vuelve a ser una sola fila. -->
        <header class="bg-slate-900 text-white px-4 md:px-6 py-3 flex flex-col md:flex-row md:items-center md:justify-between gap-2 md:gap-3 z-20 shadow-md shrink-0">
            <div class="flex items-center gap-3 min-w-0">
                <AppSwitcher />
                <div class="min-w-0">
                    <h1 class="font-black text-base md:text-xl tracking-tight leading-none truncate">
                        Centro de Operaciones
                    </h1>
                    <p class="text-[10px] md:text-[11px] font-bold text-slate-400 uppercase tracking-widest mt-1 truncate">
                        Tráfico · Órdenes de Servicio
                    </p>
                </div>
            </div>

            <!-- Tabs como segmented control -->
            <div class="flex items-center bg-slate-800 rounded-lg p-1 gap-1 shrink-0 self-start md:self-auto">
                <button
                    @click="cambiarTab('biblia')"
                    :class="activeTab === 'biblia' ? 'bg-[#376875] text-white shadow' : 'text-slate-400 hover:text-white'"
                    class="px-3 md:px-4 py-1.5 rounded text-[10px] md:text-xs font-black tracking-widest transition-all whitespace-nowrap"
                >
                    <i class="fas fa-car-side mr-1"></i>
                    <span class="hidden sm:inline">La </span>Biblia
                </button>
                <button
                    @click="cambiarTab('ordenes')"
                    :class="activeTab === 'ordenes' ? 'bg-[#E07845] text-white shadow' : 'text-slate-400 hover:text-white'"
                    class="px-3 md:px-4 py-1.5 rounded text-[10px] md:text-xs font-black tracking-widest transition-all whitespace-nowrap"
                >
                    <i class="fas fa-file-invoice mr-1"></i>
                    Órdenes
                </button>
            </div>
        </header>

        <!-- ================================================================
             CONTENIDO
             ================================================================ -->
        <main class="flex-1 overflow-y-auto">

            <!-- PESTAÑA: LA BIBLIA ---------------------------------------->
            <section v-if="activeTab === 'biblia'" class="flex flex-col min-h-full">

                <!-- Barra de filtros pegajosa -->
                <div class="sticky top-0 z-10 bg-[#F8FAFC]/95 backdrop-blur-sm border-b border-slate-200 px-3 md:px-6 py-2 md:py-3 shrink-0">

                    <!-- Fila 1: rango + presets + acciones -->
                    <div class="flex flex-wrap items-center gap-2">
                        <!-- Las dos SIEMPRE en la misma fila: son un rango, y partido en dos
                             renglones deja de leerse como tal. Caben de sobra. -->
                        <div class="grid grid-cols-2 gap-2 flex-1 min-w-[15rem] md:flex-none md:w-[23rem]">
                            <label class="flex flex-col gap-0.5">
                                <span class="text-[9px] font-black text-slate-400 uppercase tracking-widest">Desde</span>
                                <FechaHoraPicker
                                    :model-value="desde"
                                    solo-fecha
                                    @update:model-value="onCambiarDesde"
                                />
                            </label>
                            <label class="flex flex-col gap-0.5">
                                <span class="text-[9px] font-black text-slate-400 uppercase tracking-widest">Hasta</span>
                                <FechaHoraPicker
                                    :model-value="hasta"
                                    solo-fecha
                                    :min-date="desde"
                                    @update:model-value="(v: string) => { hasta = v; cargarBiblia(); }"
                                />
                            </label>
                        </div>

                        <div class="flex items-center gap-1 self-end">
                            <button
                                v-for="p in [{ k: 'hoy', l: 'Hoy' }, { k: 'manana', l: 'Mañana' }, { k: 'semana', l: '7 días' }]"
                                :key="p.k"
                                @click="aplicarPreset(p.k as 'hoy' | 'manana' | 'semana')"
                                class="px-2.5 py-2 bg-white hover:bg-slate-100 border border-slate-200 rounded-lg text-[10px] font-black uppercase tracking-widest text-slate-600 transition-colors shadow-sm"
                            >
                                {{ p.l }}
                            </button>
                        </div>

                        <div class="flex items-center gap-2 ml-auto self-end">
                            <!-- El contador vive aquí y no en su propia franja: una línea sólo
                                 para «0 servicios» es cuadro de tráfico que no se ve. -->
                            <span class="text-[10px] font-black text-slate-400 uppercase tracking-widest tabular-nums hidden sm:inline">
                                {{ operacionStore.servicios.length }}
                            </span>
                            <button
                                @click="mostrarFiltrosAvanzados = !mostrarFiltrosAvanzados"
                                :class="hayFiltrosExtra ? 'bg-[#376875] text-white border-[#376875]' : 'bg-white text-slate-600 border-slate-200 hover:bg-slate-100'"
                                class="flex items-center gap-2 px-3 py-2 border rounded-lg text-[10px] font-black uppercase tracking-widest transition-colors shadow-sm"
                            >
                                <i class="fas fa-filter"></i>
                                <span class="hidden sm:inline">Filtros</span>
                                <span v-if="hayFiltrosExtra" class="bg-white/25 px-1.5 rounded">•</span>
                            </button>
                            <button
                                @click="cargarBiblia"
                                :disabled="operacionStore.isLoading"
                                class="flex items-center gap-2 px-4 py-2 bg-[#376875] hover:bg-[#2d5660] disabled:opacity-50 text-white rounded-lg text-[10px] font-black uppercase tracking-widest transition-colors shadow-sm"
                            >
                                <i class="fas fa-rotate" :class="{ 'fa-spin': operacionStore.isLoading }"></i>
                                <span class="hidden sm:inline">Actualizar</span>
                            </button>
                        </div>
                    </div>

                    <!--
                        Fila 1.5: lugares / centros de operación.

                        FUERA del panel plegable a propósito. El operador ajusta el rango de
                        fechas y aprieta «Lima» de un clic; si estuviera dentro de "Filtros"
                        habría que abrir el panel antes de cada uso, que es justo el paso que
                        este filtro existe para eliminar.
                    -->
                    <div v-if="operacionStore.lugares.length" class="mt-2 flex flex-wrap items-center gap-1.5">
                        <!-- Cómo se ordena el día. Por itinerario es el defecto: es como se lee
                             un viaje, y deja el alojamiento y la comida —que no tienen hora—
                             junto al servicio al que pertenecen en vez de al final del día. -->
                        <button
                            @click="ordenPorHora = !ordenPorHora"
                            class="px-2.5 py-1 border rounded-lg text-[10px] font-black uppercase tracking-wider transition-colors flex items-center gap-1.5 mr-2"
                            :class="ordenPorHora ? 'bg-[#376875] text-white border-[#376875]'
                                : 'bg-white text-slate-500 border-slate-200 hover:border-slate-400'"
                            :title="ordenPorHora ? 'Ordenado por hora. Toca para volver al orden del itinerario.'
                                : 'Ordenado como el itinerario. Toca para ordenar por hora.'"
                        >
                            <i class="fas" :class="ordenPorHora ? 'fa-clock' : 'fa-list-ol'"></i>
                            {{ ordenPorHora ? 'Por hora' : 'Itinerario' }}
                        </button>

                        <!-- El rótulo ES el interruptor: un botón aparte para plegar cuatro
                             líneas de chips sería un control más que explicar. El contador dice
                             cuántos hay activos cuando está cerrado, que es lo único que no se
                             puede ver de un vistazo. -->
                        <button
                            @click="mostrarLugares = !mostrarLugares"
                            class="text-[9px] font-black uppercase tracking-widest mr-1 flex items-center gap-1 transition-colors"
                            :class="lugaresSeleccionados.length ? 'text-[#376875]' : 'text-slate-400 hover:text-slate-600'"
                            :title="mostrarLugares ? 'Ocultar lugares' : 'Mostrar lugares'"
                        >
                            <i class="fas fa-map-marker-alt"></i>
                            Lugar
                            <span v-if="lugaresSeleccionados.length" class="bg-[#376875] text-white rounded-full px-1.5">
                                {{ lugaresSeleccionados.length }}
                            </span>
                            <i class="fas text-[8px]" :class="mostrarLugares ? 'fa-chevron-up' : 'fa-chevron-down'"></i>
                        </button>

                        <template v-if="mostrarLugares || lugaresSeleccionados.length">
                        <button
                            v-for="l in operacionStore.lugares"
                            :key="l.id"
                            @click="alternarLugar(l.id)"
                            :class="lugaresSeleccionados.includes(l.id) ? 'bg-[#376875] text-white border-[#376875]'
                                : 'bg-white text-slate-500 border-slate-200 hover:border-slate-400'"
                            class="px-2.5 py-1 border rounded-lg text-[10px] font-black uppercase tracking-wider transition-colors"
                        >
                            {{ l.nombre }}
                        </button>

                        <!--
                            Los componentes tecleados a mano en la cotización no tienen maestro,
                            así que nunca llevan etiqueta. Sin este chip desaparecerían del cuadro
                            al filtrar por lugar sin ningún aviso — y con ellos, de la orden de
                            servicio. Ver docs/Operacion.md.
                        -->
                        <button
                            @click="alternarLugar(SIN_LUGAR)"
                            :class="lugaresSeleccionados.includes(SIN_LUGAR) ? 'bg-amber-500 text-white border-amber-500'
                                : 'bg-white text-amber-600 border-amber-200 hover:border-amber-400'"
                            class="px-2.5 py-1 border rounded-lg text-[10px] font-black uppercase tracking-wider transition-colors"
                            title="Servicios sin etiqueta de lugar (componentes manuales o sin catalogar)"
                        >
                            <i class="fas fa-circle-question mr-1"></i>Sin etiqueta
                        </button>
                        </template>
                    </div>

                    <!-- Fila 2: filtros avanzados -->
                    <!-- EXPEDIENTE, fuera de «Filtros» y en la barra fija.
                         Es lo único que permite consultar SIN rango de fechas, así que
                         esconderlo tras un desplegable era esconder la salida. -->
                    <div class="mt-1.5 flex flex-wrap items-center gap-2">
                            <div class="relative flex flex-col gap-1 flex-1 min-w-[11rem]">

                                <div v-if="expedienteSeleccionado" class="flex items-center gap-2 bg-white border border-slate-200 rounded-lg px-3 py-2 shadow-sm">
                                    <i class="fas fa-folder-open text-[#376875] text-xs"></i>
                                    <span class="text-sm font-bold text-slate-700 truncate">{{ expedienteSeleccionado.nombreGrupo }}</span>
                                    <button @click="quitarExpediente" class="ml-auto text-slate-400 hover:text-rose-600">
                                        <i class="fas fa-xmark"></i>
                                    </button>
                                </div>

                                <template v-else>
                                    <input
                                        v-model="terminoExpediente"
                                        type="text"
                                        placeholder="Buscar por nombre de grupo…"
                                        class="bg-white border border-slate-200 rounded-lg px-3 py-2 text-sm font-medium text-slate-700 outline-none focus:ring-2 focus:ring-[#376875] shadow-sm"
                                    />
                                    <div
                                        v-if="resultadosExpediente.length || buscandoExpediente"
                                        class="absolute top-full left-0 right-0 mt-1 bg-white border border-slate-200 rounded-lg shadow-lg z-20 max-h-56 overflow-y-auto"
                                    >
                                        <p v-if="buscandoExpediente" class="px-3 py-2 text-xs text-slate-400">
                                            <i class="fas fa-spinner fa-spin mr-1"></i> Buscando…
                                        </p>
                                        <button
                                            v-for="exp in resultadosExpediente"
                                            :key="exp.id"
                                            @click="elegirExpediente(exp)"
                                            class="w-full text-left px-3 py-2 hover:bg-slate-50 border-b border-slate-100 last:border-0"
                                        >
                                            <p class="text-sm font-bold text-slate-700">{{ exp.nombreGrupo }}</p>
                                            <p v-if="exp.pasajeroPrincipal" class="text-[10px] text-slate-400">{{ exp.pasajeroPrincipal }}</p>
                                        </button>
                                    </div>
                                </template>
                            </div>

                        <!-- Ya está / falta por encargar. En local sobre lo cargado.
                             Comparte fila con el expediente: en móvil cada bloque suelto de la
                             barra es una franja menos de cuadro, que es lo que se viene a ver. -->
                        <div class="flex items-center gap-0.5 shrink-0 bg-white border border-slate-200 rounded-lg p-0.5 shadow-sm">
                            <button v-for="o in [{ k: '', l: 'Todas' }, { k: 'sin', l: 'Sin OS' }, { k: 'con', l: 'En OS' }]"
                                    :key="o.k"
                                    @click="filtroOs = o.k as '' | 'sin' | 'con'"
                                    :class="filtroOs === o.k ? 'bg-[#376875] text-white' : 'text-slate-500 hover:bg-slate-100'"
                                    class="px-2 py-1 rounded text-[9px] font-black uppercase tracking-widest transition-colors whitespace-nowrap">
                                {{ o.l }}
                            </button>
                        </div>
                    </div>

                    <!-- Sin rango y sin expediente no se consulta: sería la operación entera. -->
                    <p v-if="faltaAcotar" class="mt-1.5 text-[10px] font-bold text-amber-700 flex items-center gap-1.5">
                        <i class="fas fa-triangle-exclamation"></i>
                        Pon fechas, o elige un expediente para verlo entero sin ellas.
                    </p>

                    <div v-if="mostrarFiltrosAvanzados" class="mt-3 pt-3 border-t border-slate-200 flex flex-col gap-3">

                        <!-- Expediente y cotización -->
                        <div class="flex flex-wrap items-end gap-2">
                            <label v-if="cotizacionesDelExpediente.length" class="flex flex-col gap-1 min-w-[14rem]">
                                <span class="text-[9px] font-black text-slate-400 uppercase tracking-widest">Cotización</span>
                                <select
                                    v-model="cotizacionSeleccionada"
                                    @change="cargarBiblia"
                                    class="bg-white border border-slate-200 rounded-lg px-3 py-2 text-sm font-medium text-slate-700 outline-none focus:ring-2 focus:ring-[#376875] shadow-sm"
                                >
                                    <option value="">Todas las versiones</option>
                                    <option v-for="c in cotizacionesDelExpediente" :key="c.id" :value="c.id">
                                        v{{ c.version ?? '?' }} · {{ c.titulo || 'Sin título' }} ({{ c.estado }})
                                    </option>
                                </select>
                            </label>

                            <label class="flex flex-col gap-1">
                                <span class="text-[9px] font-black text-slate-400 uppercase tracking-widest">Reserva</span>
                                <select
                                    v-model="filtroEstadoReservaProveedor"
                                    @change="cargarBiblia"
                                    class="bg-white border border-slate-200 rounded-lg px-3 py-2 text-sm font-medium text-slate-700 outline-none focus:ring-2 focus:ring-[#376875] shadow-sm"
                                >
                                    <option value="">Cualquiera</option>
                                    <option v-for="(cfg, k) in ESTADO_RESERVA_PROVEEDOR_CONFIG" :key="k" :value="k">{{ cfg.label }}</option>
                                </select>
                            </label>

                            <label class="flex flex-col gap-1">
                                <span class="text-[9px] font-black text-slate-400 uppercase tracking-widest">Operación</span>
                                <select
                                    v-model="filtroEstadoOperacion"
                                    @change="cargarBiblia"
                                    class="bg-white border border-slate-200 rounded-lg px-3 py-2 text-sm font-medium text-slate-700 outline-none focus:ring-2 focus:ring-[#376875] shadow-sm"
                                >
                                    <option value="">Cualquiera</option>
                                    <option v-for="(cfg, k) in ESTADO_OPERACION_CONFIG" :key="k" :value="k">{{ cfg.label }}</option>
                                </select>
                            </label>

                            <button
                                @click="limpiarFiltros"
                                class="px-3 py-2 text-[10px] font-black uppercase tracking-widest text-slate-500 hover:text-rose-600 transition-colors"
                            >
                                Limpiar
                            </button>
                        </div>

                        <!-- Chips de tipo -->
                        <div class="flex flex-wrap gap-1.5">
                            <button
                                v-for="t in TIPOS_COMPONENTE"
                                :key="t.value"
                                @click="alternarTipo(t.value)"
                                :class="tiposSeleccionados.includes(t.value) ? 'bg-slate-900 text-white border-slate-900'
                                    : 'bg-white text-slate-500 border-slate-200 hover:border-slate-400'"
                                class="flex items-center gap-1.5 px-2.5 py-1 border rounded-lg text-[10px] font-black uppercase tracking-wider transition-colors"
                            >
                                <i :class="[t.icon, 'text-[10px]']"></i>
                                {{ t.label }}
                            </button>
                        </div>
                    </div>

                    <!-- Fila 3: sólo si hay algo que decir. Con el cuadro vacío y sin
                         seleccionar, esta franja era espacio en blanco fijo. -->
                    <div v-if="seleccionados.length || hayServiciosOcultos" class="mt-2 flex items-center gap-3">
                        <span class="text-[10px] font-black text-slate-400 uppercase tracking-widest sm:hidden">
                            {{ operacionStore.servicios.length }} servicio{{ operacionStore.servicios.length !== 1 ? 's' : '' }}
                        </span>

                        <!-- La página trae 200 como mucho y aquí no se pagina. Sin este
                             aviso, un rango amplio recortaba el cuadro en silencio: el
                             operador leía el día entero creyendo que estaba entero. -->
                        <span
                            v-if="hayServiciosOcultos"
                            class="text-[10px] font-black text-amber-700 bg-amber-50 border border-amber-200 rounded px-2 py-0.5 uppercase tracking-widest"
                            :title="`Hay ${operacionStore.totalServicios} servicios en este rango y sólo caben 200 por página. Acota las fechas o usa los filtros.`"
                        >
                            <i class="fas fa-triangle-exclamation mr-1"></i>
                            {{ operacionStore.totalServicios - operacionStore.servicios.length }} sin mostrar
                        </span>

                        <template v-if="seleccionados.length">
                            <span class="text-[10px] font-black text-[#376875] uppercase tracking-widest">
                                {{ seleccionados.length }} seleccionado{{ seleccionados.length !== 1 ? 's' : '' }}
                            </span>
                            <span v-if="conflictoSeleccion" class="text-[10px] font-bold text-rose-600">
                                <i class="fas fa-triangle-exclamation mr-1"></i>{{ conflictoSeleccion }}
                            </span>
                            <button
                                @click="abrirModalOs"
                                :disabled="!!conflictoSeleccion"
                                class="ml-auto flex items-center gap-2 px-4 py-1.5 bg-[#E07845] hover:bg-[#c96636] disabled:opacity-40 disabled:cursor-not-allowed text-white rounded-lg text-[10px] font-black uppercase tracking-widest transition-colors shadow-sm"
                            >
                                <i class="fas fa-file-invoice"></i>
                                Generar OS
                            </button>
                            <!-- Componer una orden no siempre ocurre de una sentada: se marcan
                                 los del martes y al revisar el miércoles aparecen dos más del
                                 mismo proveedor. Sólo sale si hay borradores compatibles. -->
                            <button
                                v-if="ordenesParaAgregar.length"
                                @click="mostrarModalAgregar = true; errorAgregar = null"
                                class="flex items-center gap-2 px-4 py-1.5 bg-white border-2 border-[#376875] text-[#376875] hover:bg-[#376875] hover:text-white rounded-lg text-[10px] font-black uppercase tracking-widest transition-colors shadow-sm"
                            >
                                <i class="fas fa-plus"></i>
                                Agregar a OS ({{ ordenesParaAgregar.length }})
                            </button>
                            <button
                                @click="seleccionados = []"
                                class="text-[10px] font-black uppercase tracking-widest text-slate-400 hover:text-slate-700"
                            >
                                Quitar
                            </button>
                        </template>
                    </div>
                </div>

                <!-- Spinner -->
                <div v-if="operacionStore.isLoading" class="flex-1 flex items-center justify-center py-16">
                    <div class="text-center">
                        <i class="fas fa-spinner fa-spin text-3xl text-[#376875] mb-3"></i>
                        <p class="text-[10px] font-black uppercase tracking-widest text-slate-400">Sincronizando logística...</p>
                    </div>
                </div>

                <!-- Empty state -->
                <div v-else-if="operacionStore.servicios.length === 0" class="flex-1 flex items-center justify-center py-16">
                    <div class="text-center">
                        <div class="w-16 h-16 bg-slate-100 rounded-2xl flex items-center justify-center mx-auto mb-4 shadow-inner">
                            <i class="fas fa-car-side text-2xl text-slate-300"></i>
                        </div>
                        <p class="font-black text-slate-500 uppercase tracking-widest text-xs mb-1">Sin logística</p>
                        <p class="text-sm text-slate-400">No hay servicios programados con estos filtros.</p>
                        <!-- Las dos causas reales de un panel vacío, en orden de frecuencia.
                             Se enuncian aquí porque ninguna es visible desde esta pantalla:
                             las filas nacen al confirmar una cotización, y sólo entonces. -->
                        <div class="mt-4 mx-auto max-w-sm text-left bg-white border border-slate-200 rounded-xl p-3 shadow-sm">
                            <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-2">Si esperabas ver algo</p>
                            <ul class="text-xs text-slate-500 space-y-1.5 leading-snug">
                                <li>
                                    <i class="fas fa-calendar-day text-slate-300 mr-1.5"></i>
                                    Amplía el rango: los servicios de un expediente suelen caer semanas después de venderlo.
                                </li>
                                <li>
                                    <i class="fas fa-file-signature text-slate-300 mr-1.5"></i>
                                    Comprueba que la cotización esté en estado <strong class="text-slate-600">Confirmado</strong>.
                                    Sólo esa transición genera operación; si la editaste después de confirmarla, usa
                                    <strong class="text-slate-600">«Enviar a Operaciones»</strong> en la cotización.
                                </li>
                            </ul>
                        </div>
                    </div>
                </div>

                <!-- Tabla agrupada por día -->
                <div v-else class="px-4 md:px-6 py-4 flex flex-col gap-5">
                    <div v-for="grupo in serviciosPorDia" :key="grupo.fecha">

                        <h2 class="flex items-center gap-2 mb-2 px-1">
                            <i class="fas fa-calendar-day text-[#376875] text-xs"></i>
                            <span class="text-xs font-black text-slate-700 uppercase tracking-widest">{{ etiquetaDia(grupo.fecha) }}</span>
                            <span class="text-[10px] font-bold text-slate-400">({{ grupo.servicios.length }})</span>
                            <span class="flex-1 h-px bg-slate-200"></span>
                        </h2>

                        <div class="bg-white rounded-2xl border border-slate-200 shadow-sm overflow-hidden">
                            <div class="overflow-x-auto">
                                <table class="w-full text-left border-collapse">
                                    <thead>
                                        <tr class="bg-slate-50 border-b border-slate-200">
                                            <th class="px-3 py-3 w-8"></th>
                                            <th class="px-3 py-3 text-[10px] font-black text-slate-500 uppercase tracking-widest">Hora</th>
                                            <th class="px-3 py-3 text-[10px] font-black text-slate-500 uppercase tracking-widest">Servicio</th>
                                            <th class="hidden md:table-cell px-3 py-3 text-[10px] font-black text-slate-500 uppercase tracking-widest">Expediente</th>
                                            <th class="hidden md:table-cell px-3 py-3 text-[10px] font-black text-slate-500 uppercase tracking-widest">Pax</th>
                                            <th class="hidden md:table-cell px-3 py-3 text-[10px] font-black text-slate-500 uppercase tracking-widest" title="Quién opera el servicio y dónde se recoge. Debajo, a quién se le compra cuando no es el mismo.">Prestador</th>
                                            <th class="hidden md:table-cell px-3 py-3 text-[10px] font-black text-slate-500 uppercase tracking-widest text-right" title="Cotizado (de la cotización) frente a real (lo que se pagó). El delta es el margen operativo.">Costo</th>
                                            <th class="hidden md:table-cell px-3 py-3 text-[10px] font-black text-slate-500 uppercase tracking-widest">Reserva</th>
                                            <th class="hidden md:table-cell px-3 py-3 text-[10px] font-black text-slate-500 uppercase tracking-widest">Operación</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-slate-100">
                                        <tr
                                            v-for="servicio in grupo.servicios"
                                            :key="servicio.id"
                                            :class="[ seleccionados.includes(servicio.id ?? '') ? 'bg-[#376875]/5' : '',
                                                servicio.estadoComponente === 'cancelado' || servicio.modoComponente === 'reemplazado' ? 'opacity-55' : '',
                                            ]"
                                            class="hover:bg-slate-50/80 transition-colors md:cursor-default cursor-pointer"
                                            @click="abrirFicha(servicio)"
                                        >
                                            <!-- Selección: las filas de referencia no se
                                                 marcan porque no pueden ir a una OS. -->
                                            <td class="px-3 py-3 align-top">
                                                <input
                                                    v-if="esComprable(servicio)"
                                                    type="checkbox"
                                                    :checked="seleccionados.includes(servicio.id ?? '')"
                                                    @change="alternarSeleccion(servicio.id)"
                                                    @click.stop
                                                    class="mt-1 w-4 h-4 accent-[#376875] cursor-pointer"
                                                />
                                                <i
                                                    v-else
                                                    class="fas text-slate-300 text-xs mt-1.5 block"
                                                    :class="servicio.soloReferencia ? 'fa-eye' : 'fa-ban'"
                                                    :title="servicio.soloReferencia
                                                        ? 'Sólo referencia: no se compra a ningún proveedor'
                                                        : 'Cancelado o reemplazado en la cotización: no se compra'"
                                                ></i>
                                            </td>

                                            <!-- Hora de RECOJO (editable) y, debajo, la vendida como
                                                 referencia. Si no hay recojo puesto, el placeholder es
                                                 la vendida: vale como fallback hasta que se fije otra.
                                                 Ver docs/Operacion.md §3.15. -->
                                            <td class="px-3 py-3 whitespace-nowrap align-top">
                                                <input
                                                    :value="servicio.horaRecojo ?? ''"
                                                    @change="editarHora(servicio, $event)"
                                                    @click.stop
                                                    :placeholder="servicio.horaComponente || '--:--'"
                                                    maxlength="5"
                                                    class="w-[3.8rem] text-xs font-black text-slate-900 bg-slate-100 px-1.5 py-1 rounded-lg border border-slate-200 tabular-nums text-center outline-none focus:ring-2 focus:ring-[#376875] focus:bg-white"
                                                    :class="{ 'text-slate-400': !servicio.horaRecojo }"
                                                    title="Hora de recojo. Vacía = se usa la hora con la que se vendió."
                                                />
                                                <p v-if="servicio.horaComponente && servicio.horaRecojo && servicio.horaRecojo !== servicio.horaComponente"
                                                   class="text-[8px] font-bold text-slate-400 text-center mt-0.5 tabular-nums"
                                                   title="Hora con la que se vendió al cliente">
                                                    vend. {{ servicio.horaComponente }}
                                                </p>

                                                <!-- ── SÓLO MÓVIL: los estados, debajo de la hora ──
                                                     Sus columnas se ocultan a partir de `md`, y sin
                                                     esto el operador no vería en el teléfono si algo
                                                     está pendiente. Debajo del reloj hay hueco muerto
                                                     y es donde la vista ya está mirando. -->
                                                <div class="md:hidden mt-1.5 flex flex-col items-center gap-1">
                                                    <span class="text-[8px] font-black uppercase tracking-wider px-1.5 py-0.5 rounded border whitespace-nowrap"
                                                          :class="[getEstadoReservaProveedorConfig(servicio.estadoReservaProveedor).bg,
                                                                   getEstadoReservaProveedorConfig(servicio.estadoReservaProveedor).text,
                                                                   getEstadoReservaProveedorConfig(servicio.estadoReservaProveedor).border]">
                                                        {{ getEstadoReservaProveedorConfig(servicio.estadoReservaProveedor).label }}
                                                    </span>
                                                    <span v-if="servicio.ordenServicio"
                                                          class="text-[8px] font-black uppercase tracking-wider px-1.5 py-0.5 rounded bg-[#376875] text-white"
                                                          title="Ya está en una Orden de Servicio">
                                                        <i class="fas fa-file-invoice"></i>
                                                    </span>
                                                </div>
                                            </td>

                                            <!-- Servicio -->
                                            <td class="px-3 py-3 align-top">
                                                <div class="flex items-start gap-2">
                                                    <i
                                                        :class="[getTipoComponenteConfig(servicio.tipoComponente).icon, getTipoComponenteConfig(servicio.tipoComponente).text, 'mt-0.5 text-sm w-4 text-center']"
                                                        :title="getTipoComponenteConfig(servicio.tipoComponente).label"
                                                    ></i>
                                                    <div class="min-w-0">
                                                        <!-- El SERVICIO manda y la tarifa es el detalle, no al revés.
                                                             `descripcionServicio` es el nombre interno de la tarifa
                                                             («Auto a Miraflores Noche»), pensado para negociar con el
                                                             proveedor; `contextoServicio` es el nombre del servicio, que
                                                             ubica la fila de un vistazo. En un servicio mono-segmento sin
                                                             plantilla ese nombre es genérico («Alojamiento»): manda el
                                                             nombre interno del segmento, resuelto en vivo. -->
                                                        <!-- TÍTULO: el COMPONENTE identifica la fila (Guía, Transporte,
                                                             Ingreso a Catedral…). Viene del maestro, resuelto en el mismo
                                                             batch que los lugares. Su icono de tipo va a la izquierda.
                                                             Fallback al nombre de contexto si aún no se resolvió. -->
                                                        <p v-if="nombreComponenteDe(servicio) || nombreSegmentoDe(servicio) || servicio.contextoServicio"
                                                           class="text-sm font-black text-slate-800 leading-tight">
                                                            {{ nombreComponenteDe(servicio) || nombreSegmentoDe(servicio) || servicio.contextoServicio }}
                                                        </p>
                                                        <!-- La TARIFA, pegada al componente: lo que la fila IDENTIFICA (el
                                                             componente) y lo que se NEGOCIA por ello van juntos. El servicio
                                                             es contexto y baja más abajo, no entre estos dos. -->
                                                        <p v-if="servicio.descripcionServicio" class="text-[11px] font-bold text-slate-500 leading-tight mt-1">
                                                            <i class="fas fa-tag text-[8px] mr-1 text-slate-300"></i>{{ servicio.descripcionServicio }}
                                                        </p>
                                                        <!-- Nombre interno de la tarifa, sólo si difiere: es con el que la
                                                             buscas en el tarifario. -->
                                                        <p v-if="servicio.tarifaNombre && servicio.tarifaNombre !== servicio.descripcionServicio"
                                                           class="text-[10px] font-bold text-slate-400 leading-tight mt-0.5"
                                                           title="Nombre interno de la tarifa">
                                                            <i class="fas fa-tag text-[8px] mr-1 text-slate-300"></i>{{ servicio.tarifaNombre }}
                                                        </p>

                                                        <!-- DÓNDE RECOJO / DÓNDE DEJO.
                                                             Editable, y vacío significa «lo que diga el catálogo»: el
                                                             marcador de posición enseña qué saldría entonces. Sólo se
                                                             pintan cuando el servicio recoge a alguien — un ticket o una
                                                             comida no, y ponerles el campo invitaría a rellenarlo.

                                                             ⚠️ Pero si YA hay override escrito, el campo sale igual —
                                                             aunque el endpoint del derivado esté caído o el tipo no
                                                             aplique—. Si no, ese dato seguiría mandando en la emisión
                                                             y el operador no lo vería ni podría vaciarlo.
                                                             Ver docs/Operacion.md §12. -->
                                                        <div v-if="puntosDe(servicio)?.aplica || servicio.puntoRecojo || servicio.puntoEntrega" class="mt-1.5 space-y-1">
                                                            <div class="flex items-center gap-1">
                                                                <i class="fas fa-location-dot text-[9px] text-slate-300 w-3 text-center"
                                                                   title="Dónde se recoge"></i>
                                                                <input
                                                                    :value="servicio.puntoRecojo ?? ''"
                                                                    @change="editarPunto(servicio, 'recojo', $event)"
                                                                    @click.stop
                                                                    :placeholder="puntosDe(servicio)?.recojo || 'sin declarar en el catálogo'"
                                                                    maxlength="255"
                                                                    class="w-full text-[10px] font-bold bg-slate-50 px-1.5 py-0.5 rounded border border-slate-200 outline-none focus:ring-2 focus:ring-[#376875] focus:bg-white placeholder:text-slate-500 placeholder:italic"
                                                                    :class="servicio.puntoRecojo ? 'text-slate-900' : 'text-slate-500'"
                                                                    title="Dónde se recoge. Vacío = lo que diga el catálogo."
                                                                />
                                                            </div>
                                                            <div v-if="puntosDe(servicio)?.tieneEntrega || servicio.puntoEntrega" class="flex items-center gap-1">
                                                                <i class="fas fa-flag-checkered text-[9px] text-slate-300 w-3 text-center"
                                                                   title="Dónde se deja"></i>
                                                                <input
                                                                    :value="servicio.puntoEntrega ?? ''"
                                                                    @change="editarPunto(servicio, 'entrega', $event)"
                                                                    @click.stop
                                                                    :placeholder="puntosDe(servicio)?.entrega || 'sin declarar en el catálogo'"
                                                                    maxlength="255"
                                                                    class="w-full text-[10px] font-bold bg-slate-50 px-1.5 py-0.5 rounded border border-slate-200 outline-none focus:ring-2 focus:ring-[#376875] focus:bg-white placeholder:text-slate-500 placeholder:italic"
                                                                    :class="servicio.puntoEntrega ? 'text-slate-900' : 'text-slate-500'"
                                                                    title="Dónde se deja. Vacío = lo que diga el catálogo."
                                                                />
                                                            </div>
                                                            <!-- Los avisos dicen POR QUÉ falta y dónde se arregla. Sin
                                                                 ellos, un campo gris y vacío no distingue «no aplica» de
                                                                 «nadie lo declaró». -->
                                                            <p v-for="aviso in (puntosDe(servicio)?.avisos || [])" :key="aviso"
                                                               class="text-[9px] font-bold text-amber-700 leading-tight pl-4">
                                                                <i class="fas fa-triangle-exclamation mr-1"></i>{{ aviso }}
                                                            </p>
                                                        </div>

                                                        <!-- Badges de clasificación: sólo cuando dicen algo -->
                                                        <div class="flex flex-wrap gap-1 mt-1">
                                                            <!-- Lugares del catálogo. Resueltos EN LOTE al cargar
                                                                 el cuadro (una petición, no una por fila): la fila
                                                                 no tiene relación con Travel, sólo el uuid del
                                                                 componente maestro. -->
                                                            <span
                                                                v-for="lugar in operacionStore.lugaresDeServicio(servicio)"
                                                                :key="lugar"
                                                                class="px-1.5 py-0.5 inline-flex items-center gap-1 text-[9px] font-black rounded border bg-sky-50 text-sky-700 border-sky-200"
                                                            >
                                                                <i class="fas fa-map-marker-alt text-[8px]"></i> {{ lugar }}
                                                            </span>

                                                            <!-- La fila está para informar al guía y al
                                                                 transportista, no para comprarla. Se marca en
                                                                 vez de atenuarse: atenuarla diría lo contrario
                                                                 de lo que se quiere — el hotel del pasajero es
                                                                 justo lo que hay que mirar para el recojo. -->
                                                            <span
                                                                v-if="servicio.soloReferencia"
                                                                class="px-1.5 py-0.5 inline-flex items-center gap-1 text-[9px] font-black rounded border bg-indigo-50 text-indigo-600 border-indigo-200"
                                                                title="Referencia operativa: no se compra a ningún proveedor y no entra en Órdenes de Servicio"
                                                            >
                                                                <i class="fas fa-eye text-[8px]"></i> Referencia
                                                            </span>
                                                            <span
                                                                v-if="getModoComponenteConfig(servicio.modoComponente) && servicio.modoComponente !== 'incluido'"
                                                                :class="['px-1.5 py-0.5 inline-flex items-center gap-1 text-[9px] font-black rounded border', getModoComponenteConfig(servicio.modoComponente)!.bg, getModoComponenteConfig(servicio.modoComponente)!.text, getModoComponenteConfig(servicio.modoComponente)!.border]"
                                                            >
                                                                <i :class="['fas text-[8px]', getModoComponenteConfig(servicio.modoComponente)!.icon]"></i>
                                                                {{ getModoComponenteConfig(servicio.modoComponente)!.label }}
                                                            </span>
                                                            <span
                                                                v-if="servicio.estadoComponente === 'cancelado'"
                                                                :class="['px-1.5 py-0.5 inline-flex items-center gap-1 text-[9px] font-black rounded border', getEstadoComponenteConfig(servicio.estadoComponente)!.bg, getEstadoComponenteConfig(servicio.estadoComponente)!.text, getEstadoComponenteConfig(servicio.estadoComponente)!.border]"
                                                            >
                                                                <i :class="['fas text-[8px]', getEstadoComponenteConfig(servicio.estadoComponente)!.icon]"></i>
                                                                Cancelado en cotización
                                                            </span>
                                                            <span
                                                                v-if="servicio.ordenServicio"
                                                                class="px-1.5 py-0.5 inline-flex items-center gap-1 text-[9px] font-black rounded border bg-[#E07845]/10 text-[#E07845] border-[#E07845]/30"
                                                            >
                                                                <i class="fas fa-file-invoice text-[8px]"></i> En OS
                                                            </span>
                                                        </div>

                                                        <!-- Servicio como CONTEXTO: de qué tour/servicio es la fila. Baja
                                                             aquí (no entre componente y tarifa) porque es lo que ubica, no
                                                             lo que identifica. Segmento en mono-segmento, si no el servicio. -->
                                                        <p v-if="nombreComponenteDe(servicio) && (nombreSegmentoDe(servicio) || servicio.contextoServicio)"
                                                           class="text-[10px] font-bold text-slate-400 leading-snug mt-1.5">
                                                            <i class="fas fa-map-signs text-[8px] mr-1 text-slate-300"></i>{{ nombreSegmentoDe(servicio) || servicio.contextoServicio }}
                                                        </p>

                                                        <!-- Contexto compacto en móvil -->
                                                        <p class="text-[10px] font-bold text-slate-400 mt-1 lg:hidden">
                                                            <button v-if="servicio.file?.id" @click="abrirExpediente(servicio)" class="hover:underline decoration-dotted text-[#376875]">
                                                                <i class="fas fa-folder-open mr-1"></i>{{ servicio.file?.nombreGrupo || 'Sin expediente' }}
                                                            </button>
                                                            <template v-else><i class="fas fa-folder-open mr-1"></i>Sin expediente</template>
                                                        </p>
                                                        <!-- En móvil la columna del prestador está oculta, así que
                                                             el nombre se repite aquí. El TELÉFONO no: sigue vivo en
                                                             la columna de prestador (donde es el dato del recojo) y
                                                             aquí sólo alargaba la tarjeta. -->
                                                        <p class="text-[10px] font-bold text-slate-400 mt-0.5 md:hidden">
                                                            <i class="fas fa-user mr-1"></i>{{ servicio.prestadorNombre || (servicio.soloReferencia ? 'Referencia' : 'Por asignar') }}
                                                        </p>

                                                        <!-- 🔥 EL COSTO NEGOCIADO, EN MÓVIL.
                                                             La columna «Costo» es ``: sólo
                                                             existe a partir de 1280px, así que en el teléfono
                                                             —la herramienta principal— este campo no se había
                                                             podido tocar nunca. Se negocia de pie y con el móvil
                                                             en la mano; era justo donde tenía que estar. -->
                                                        <div v-if="!servicio.soloReferencia" class="mt-1.5 flex items-center gap-1.5 xl:hidden">
                                                            <span class="text-[9px] font-black text-slate-300 uppercase tracking-wider shrink-0">Negociado</span>
                                                            <EditorCostoNegociado
                                                                :ref="(el) => registrarEditor(idDe(servicio), el)"
                                                                denso
                                                                :costo-cotizado="servicio.costoCotizado"
                                                                :desglose="servicio.desgloseCotizado"
                                                                :moneda-cotizada="servicio.monedaCotizada?.id ?? ''"
                                                                :costo-negociado="servicio.costoNegociado"
                                                                :moneda-negociada="servicio.monedaNegociada?.id ?? null"
                                                                :monedas="operacionStore.monedas"
                                                                @guardar="(pl) => onGuardarCosto(servicio, pl)"
                                                            />
                                                        </div>
                                                    </div>
                                                </div>
                                            </td>

                                            <!-- Expediente: clic → modal con namelist, documentos y
                                                 salto a la cotización. Ver §3.17. -->
                                            <td class="hidden md:table-cell px-3 py-3 align-top">
                                                <button v-if="servicio.file?.id" @click="abrirExpediente(servicio)"
                                                        class="text-left max-w-[12rem] group/exp">
                                                    <p class="text-sm font-bold text-[#376875] truncate group-hover/exp:underline decoration-dotted">
                                                        <i class="fas fa-folder-open text-[10px] mr-1 text-slate-300"></i>{{ servicio.file?.nombreGrupo || '—' }}
                                                    </p>
                                                    <p v-if="servicio.file?.pasajeroPrincipal" class="text-[10px] font-bold text-slate-400 truncate">
                                                        {{ servicio.file.pasajeroPrincipal }}
                                                    </p>
                                                </button>
                                                <span v-else class="text-sm font-bold text-slate-400">—</span>
                                            </td>

                                            <!-- Pax -->
                                            <td class="hidden md:table-cell px-3 py-3 whitespace-nowrap align-top">
                                                <span class="text-xs font-black text-slate-600 bg-slate-100 px-2 py-1 rounded-lg border border-slate-200">
                                                    <i class="fas fa-users text-slate-400 mr-1"></i>{{ servicio.cantidadPax }}
                                                </span>
                                            </td>

                                            <!-- Prestador (quién opera / dónde se recoge) y, debajo, el
                                                 proveedor comercial sólo si difiere. Ver docs/Operacion.md §3.3.b -->
                                            <td class="hidden md:table-cell px-3 py-3 align-top">
                                                <input
                                                    :value="servicio.prestadorEfectivoNombre ?? ''"
                                                    @change="editarPrestador(servicio, $event)"
                                                    list="catalogo-organizaciones"
                                                    :placeholder="servicio.soloReferencia ? 'Referencia' : 'Por asignar'"
                                                    :class="[ 'w-full max-w-[11rem] text-sm font-bold bg-transparent px-2 py-1 rounded-lg border outline-none focus:ring-2 focus:ring-[#376875] focus:bg-white placeholder:text-slate-300 placeholder:font-medium',
                                                        servicio.prestadorOverrideNombre
                                                            ? 'text-[#376875] border-[#376875]/30'
                                                            : 'text-slate-700 border-transparent hover:border-slate-200',
                                                    ]"
                                                />

                                                <!-- Lo que dijo la cotización, informativo. Sólo cuando
                                                     operaciones puso otra cosa: si coinciden, repetirlo
                                                     en cada fila convierte el dato en ruido. -->
                                                <p v-if="cotizadoDe(servicio)" class="ml-2 text-[9px] font-medium text-slate-400 italic">
                                                    {{ cotizadoDe(servicio) }}
                                                </p>

                                                <!-- El servicio contratado: el tipo de habitación. Texto
                                                     libre porque no es una empresa. -->
                                                <input
                                                    v-if="servicio.prestadorServicioEfectivoNombre || servicio.prestadorOverrideNombre"
                                                    :value="servicio.prestadorServicioEfectivoNombre ?? ''"
                                                    @change="editarServicioPrestador(servicio, $event)"
                                                    placeholder="Servicio contratado"
                                                    class="mt-0.5 ml-2 w-full max-w-[11rem] text-[10px] font-medium text-slate-500 bg-transparent px-1 py-0.5 rounded border border-transparent hover:border-slate-200 outline-none focus:ring-1 focus:ring-[#376875] focus:bg-white placeholder:text-slate-300"
                                                />

                                                <!-- Los datos del recojo: es para lo que existe la fila
                                                     de referencia. El teléfono se marca desde aquí. -->
                                                <a
                                                    v-if="telefonoDe(servicio)"
                                                    :href="telHref(telefonoDe(servicio))"
                                                    class="mt-1 ml-2 flex items-center gap-1.5 text-[10px] font-bold text-slate-500 hover:text-[#376875] transition-colors"
                                                >
                                                    <i class="fas fa-phone text-slate-300 text-[9px]"></i>
                                                    <span class="tabular-nums">{{ telefonoDe(servicio) }}</span>
                                                </a>
                                                <!-- En el `title` va `?? ''` y no el valor a secas: el `v-if`
                                                     no estrecha el tipo dentro de las demás bindings. -->
                                                <p
                                                    v-if="direccionDe(servicio)"
                                                    class="mt-0.5 ml-2 flex items-start gap-1.5 text-[10px] font-medium text-slate-400 max-w-[11rem]"
                                                    :title="direccionDe(servicio) ?? ''"
                                                >
                                                    <i class="fas fa-location-dot text-slate-300 text-[9px] mt-0.5 shrink-0"></i>
                                                    <span class="truncate">{{ direccionDe(servicio) }}</span>
                                                </p>

                                                <!-- A quién se le compra. Se edita porque es lo que agrupa
                                                     la OS: sin poder corregirlo aquí, dos filas del mismo
                                                     proveedor escrito distinto no se pueden juntar nunca. -->
                                                <label v-if="mostrarComprador(servicio)" class="mt-1.5 flex items-center gap-1.5">
                                                    <span class="text-[9px] font-black text-slate-300 uppercase tracking-wider shrink-0" title="Organizacion comercial: a quién se le compra">
                                                        Compra
                                                    </span>
                                                    <input
                                                        :value="servicio.compradorEfectivoNombre ?? ''"
                                                        @change="editarProveedor(servicio, $event)"
                                                        list="catalogo-organizaciones"
                                                        placeholder="Sin definir"
                                                        :class="[ 'w-full max-w-[8rem] text-[10px] font-bold bg-transparent px-1 py-0.5 rounded border outline-none focus:ring-1 focus:ring-[#376875] focus:bg-white focus:text-slate-700 placeholder:text-slate-300 placeholder:font-medium',
                                                            servicio.compradorOverrideNombre
                                                                ? 'text-[#376875] border-[#376875]/30'
                                                                : 'text-slate-400 border-transparent hover:border-slate-200',
                                                        ]"
                                                    />
                                                </label>
                                            </td>

                                            <!-- Costo: cotizado (solo lectura) vs real (editable) -->
                                            <td class="hidden md:table-cell px-3 py-3 align-top text-right whitespace-nowrap">
                                                <p class="text-[10px] font-bold text-slate-400 tabular-nums">
                                                    <span class="text-slate-300 mr-1">{{ servicio.monedaCotizada?.id || '' }}</span>{{ importe(servicio.costoCotizado) }}
                                                </p>

                                                <!-- Una fila de referencia no se compra: no hay costo real que
                                                     registrar, y ofrecer el campo invitaría a inventarlo. -->
                                                <template v-if="!servicio.soloReferencia">
                                                    <!-- Lo NEGOCIADO: importe y moneda, los dos editables.
                                                         La moneda también, porque se puede cotizar en dólares
                                                         y cerrar en soles con el mismo proveedor; heredarla
                                                         del cotizador obligaba a que coincidieran. -->
                                                    <div class="mt-1 flex items-center justify-end">
                                                        <EditorCostoNegociado
                                                            :ref="(el) => registrarEditor(idDe(servicio), el)"
                                                            :costo-cotizado="servicio.costoCotizado"
                                                            :desglose="servicio.desgloseCotizado"
                                                            :moneda-cotizada="servicio.monedaCotizada?.id ?? ''"
                                                            :costo-negociado="servicio.costoNegociado"
                                                            :moneda-negociada="servicio.monedaNegociada?.id ?? null"
                                                            :monedas="operacionStore.monedas"
                                                            @guardar="(pl) => onGuardarCosto(servicio, pl)"
                                                        />
                                                    </div>
                                                    <p
                                                        v-if="deltaOperativo(servicio) !== null && deltaOperativo(servicio) !== 0"
                                                        class="mt-0.5 text-[10px] font-black tabular-nums"
                                                        :class="deltaOperativo(servicio)! > 0 ? 'text-rose-600' : 'text-emerald-600'"
                                                        :title="deltaOperativo(servicio)! > 0 ? 'Costó más de lo cotizado' : 'Costó menos de lo cotizado'"
                                                    >
                                                        {{ deltaOperativo(servicio)! > 0 ? '+' : '−' }}{{ Math.abs(deltaOperativo(servicio)!).toFixed(2) }}
                                                    </p>
                                                </template>
                                                <p v-else class="mt-1 text-[10px] font-bold text-slate-300">no se compra</p>
                                            </td>

                                            <!-- Estado reserva editable -->
                                            <td class="hidden md:table-cell px-3 py-3 whitespace-nowrap align-top">
                                                <select
                                                    :value="servicio.estadoReservaProveedor"
                                                    @change="guardarCampo(servicio, { estadoReservaProveedor: ($event.target as HTMLSelectElement).value })"
                                                    :disabled="guardando === servicio.id"
                                                    :class="['px-2 py-1 text-[10px] font-black rounded-lg border cursor-pointer outline-none appearance-none', getEstadoReservaProveedorConfig(servicio.estadoReservaProveedor).bg, getEstadoReservaProveedorConfig(servicio.estadoReservaProveedor).text, getEstadoReservaProveedorConfig(servicio.estadoReservaProveedor).border]"
                                                >
                                                    <option v-for="(cfg, k) in ESTADO_RESERVA_PROVEEDOR_CONFIG" :key="k" :value="k">{{ cfg.label }}</option>
                                                </select>

                                                <!-- Desde cuándo en ESTE estado + acceso al historial.
                                                     El «desde» es un campo directo del servicio; la
                                                     bitácora completa se pide al abrir. Ver §3.14. -->
                                                <button
                                                    v-if="servicio.estadoReservaProveedorDesde"
                                                    @click="abrirBitacora(servicio)"
                                                    class="mt-1 flex items-center gap-1 text-[9px] font-bold text-slate-400 hover:text-[#376875] transition-colors"
                                                    title="Ver el historial de estados"
                                                >
                                                    <i class="fas fa-clock-rotate-left text-[8px]"></i>
                                                    {{ desdeHace(servicio.estadoReservaProveedorDesde) }}
                                                </button>
                                            </td>

                                            <!-- Estado operación editable -->
                                            <td class="hidden md:table-cell px-3 py-3 whitespace-nowrap align-top">
                                                <select
                                                    :value="servicio.estadoOperacion"
                                                    @change="guardarCampo(servicio, { estadoOperacion: ($event.target as HTMLSelectElement).value })"
                                                    :disabled="guardando === servicio.id"
                                                    :class="['px-2 py-1 text-[10px] font-black rounded-lg border cursor-pointer outline-none appearance-none', getEstadoOperacionConfig(servicio.estadoOperacion).bg, getEstadoOperacionConfig(servicio.estadoOperacion).text, getEstadoOperacionConfig(servicio.estadoOperacion).border]"
                                                >
                                                    <option v-for="(cfg, k) in ESTADO_OPERACION_CONFIG" :key="k" :value="k">{{ cfg.label }}</option>
                                                </select>
                                            </td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            </section>

            <!-- PESTAÑA: ÓRDENES DE SERVICIO -------------------------------->
            <section v-else-if="activeTab === 'ordenes'" class="flex flex-col min-h-full">

                <div class="sticky top-0 z-10 bg-[#F8FAFC]/95 backdrop-blur-sm border-b border-slate-200 px-4 md:px-6 py-3 flex items-center gap-3 shrink-0">
                    <div class="flex items-center gap-2">
                        <i class="fas fa-list-check text-[#E07845]"></i>
                        <span class="text-[10px] font-black text-slate-500 uppercase tracking-widest hidden sm:inline">Órdenes Vigentes</span>
                    </div>
                    <button
                        @click="cargarOrdenes"
                        :disabled="operacionStore.isLoading"
                        class="ml-auto flex items-center gap-2 px-4 py-2 bg-[#E07845] hover:bg-[#c96636] disabled:opacity-50 text-white rounded-xl text-[10px] font-black uppercase tracking-widest transition-colors shadow-sm"
                    >
                        <i class="fas fa-rotate" :class="{ 'fa-spin': operacionStore.isLoading }"></i>
                        <span class="hidden sm:inline">Actualizar</span>
                    </button>
                </div>

                <div v-if="operacionStore.isLoading" class="flex-1 flex items-center justify-center py-16">
                    <div class="text-center">
                        <i class="fas fa-spinner fa-spin text-3xl text-[#E07845] mb-3"></i>
                        <p class="text-[10px] font-black uppercase tracking-widest text-slate-400">Cargando órdenes...</p>
                    </div>
                </div>

                <div v-else-if="operacionStore.ordenesServicio.length === 0" class="flex-1 flex items-center justify-center py-16">
                    <div class="text-center">
                        <div class="w-16 h-16 bg-slate-100 rounded-2xl flex items-center justify-center mx-auto mb-4 shadow-inner">
                            <i class="fas fa-file-invoice text-2xl text-slate-300"></i>
                        </div>
                        <p class="font-black text-slate-500 uppercase tracking-widest text-xs mb-1">Sin órdenes</p>
                        <p class="text-sm text-slate-400">Selecciona servicios en La Biblia y pulsa «Generar OS».</p>
                    </div>
                </div>

                <!--
                  TARJETAS, no tabla. Eran cinco columnas y en móvil se cortaban por la mitad:
                  el estado y las acciones quedaban fuera de la pantalla y no había forma de
                  llegar a ellos. Aquí cada orden fluye en dos filas y cabe entera.
                -->
                <div v-else class="px-3 md:px-6 py-3 md:py-4 flex flex-col gap-2">
                    <div
                        v-for="orden in operacionStore.ordenesServicio"
                        :key="orden.id"
                        class="bg-white rounded-xl border border-slate-200 shadow-sm overflow-hidden"
                    >
                        <!-- Fila 1: identidad y estado -->
                        <div class="px-3 py-2.5 flex items-center justify-between gap-2 border-b border-slate-100">
                            <button @click="alternarDetalle(orden)"
                                    class="flex items-center gap-2 min-w-0 text-left">
                                <i class="fas text-[10px] text-slate-300 shrink-0"
                                   :class="ordenExpandida === orden.id ? 'fa-chevron-down' : 'fa-chevron-right'"></i>
                                <span class="text-sm font-black text-[#376875] truncate">{{ orden.numeroOs }}</span>
                            </button>

                            <span
                                :class="['px-2 py-1 inline-flex items-center gap-1 text-[9px] font-black rounded-lg border shrink-0', getEstadoOsConfig(orden.estadoOs).bg, getEstadoOsConfig(orden.estadoOs).text, getEstadoOsConfig(orden.estadoOs).border]"
                            >
                                <i :class="['fas text-[9px]', getEstadoOsConfig(orden.estadoOs).icon]"></i>
                                {{ getEstadoOsConfig(orden.estadoOs).label }}
                            </span>
                        </div>

                        <!-- CAMBIO MENOR: el proveedor confirmó la hora. No obliga a reemitir
                             —es el final normal del flujo, no un descuido— así que va en azul y
                             con su propio botón: darle el mismo que a reemitir invitaría a usar
                             el destructivo por costumbre. -->
                        <div v-if="orden.cambiosMenores?.length" class="px-3 py-2 bg-sky-50 border-b border-sky-200">
                            <p class="text-[10px] font-black text-sky-800 uppercase tracking-wider flex items-center gap-1.5">
                                <i class="fas fa-circle-info"></i>
                                Confirmación del proveedor
                            </p>
                            <ul class="mt-1 space-y-0.5">
                                <li v-for="(d, i) in orden.cambiosMenores" :key="i" class="text-[10px] text-sky-700 leading-snug">· {{ d }}</li>
                            </ul>
                            <button
                                v-if="orden.id"
                                @click="aplicarMenores(orden)"
                                class="mt-1.5 px-2 py-1 text-[10px] font-black text-sky-900 bg-sky-100 hover:bg-sky-200 border border-sky-300 rounded-lg transition-colors"
                            >
                                Actualizar y avisar
                            </button>
                            <p class="mt-1 text-[9px] text-sky-600 leading-snug">
                                La orden sigue vigente. Se confirma la hora al cliente y al proveedor.
                            </p>
                        </div>

                        <!-- SUCIA: La Biblia se movió después de emitir. No se corrige sola —un
                             documento mandado no cambia solo—: se avisa para que una persona
                             decida y reemita. El detalle va debajo porque «algo cambió» sin
                             decir QUÉ obliga a ir a buscarlo. -->
                        <div v-if="orden.divergencias?.length" class="px-3 py-2 bg-amber-50 border-b border-amber-200">
                            <p class="text-[10px] font-black text-amber-800 uppercase tracking-wider flex items-center gap-1.5">
                                <i class="fas fa-triangle-exclamation"></i>
                                Ya no coincide con La Biblia
                            </p>
                            <ul class="mt-1 space-y-0.5">
                                <li v-for="(d, i) in orden.divergencias" :key="i" class="text-[10px] text-amber-700 leading-snug">· {{ d }}</li>
                            </ul>
                            <button
                                v-if="orden.id"
                                @click="anularParaReemitir(orden)"
                                class="mt-1.5 px-2 py-1 text-[10px] font-black text-amber-900 bg-amber-100 hover:bg-amber-200 border border-amber-300 rounded-lg transition-colors"
                            >
                                Reemitir
                            </button>
                            <p class="mt-1 text-[9px] text-amber-600 leading-snug">
                                Se anula ésta y se crea la sucesora en borrador con los datos de hoy. Nada se pierde.
                            </p>
                        </div>

                        <!-- Fila 2: destinatario e importes por moneda -->
                        <div class="px-3 py-2.5 flex items-start justify-between gap-3">
                            <div class="min-w-0">
                                <p class="text-[9px] font-black text-slate-400 uppercase tracking-widest">Destinatario</p>
                                <p class="text-sm font-bold text-slate-800 leading-snug">
                                    {{ orden.compradorNombre || 'No definido' }}
                                </p>

                                <!-- ⚠️ SALDADA no es un estado de la orden: es un hecho sobre el
                                     dinero, y por eso va aquí y no en el chip de `estadoOs`.
                                     Aquél dice en qué punto está el PAPELEO —emitida,
                                     confirmada, completada— y son dos ejes que no se mueven
                                     juntos: una orden puede estar completada y sin pagar, o
                                     pagada por adelantado y todavía sin confirmar. Ver
                                     `OperacionOrdenServicio::isSaldada()`.

                                     Con varias monedas es lo único que contesta de un vistazo:
                                     las líneas de la derecha dicen «pagado» por moneda, no si
                                     la orden entera está saldada. -->
                                <p v-if="orden.saldada"
                                   class="mt-1 inline-flex items-center gap-1 px-1.5 py-0.5 rounded bg-emerald-50 text-emerald-700 text-[9px] font-black uppercase tracking-widest">
                                    <i class="fas fa-circle-check text-[9px]"></i> Saldada
                                </p>
                            </div>

                            <!-- Una línea por moneda, sin convertir. Ver §5.4. -->
                            <div class="shrink-0 text-right">
                                <div v-for="t in (orden.totalesPorMoneda ?? [])" :key="t.moneda" class="leading-tight mb-0.5">
                                    <span class="text-[10px] font-bold text-slate-400 mr-1">{{ t.moneda }}</span>
                                    <span class="text-sm font-black text-slate-800 tabular-nums">{{ importe(t.real) }}</span>
                                    <!-- Con pagos: se ve el SALDO, que es lo que de verdad falta abonar. -->
                                    <p v-if="Number(t.pagado ?? 0) > 0"
                                       class="text-[9px] font-black tabular-nums"
                                       :class="Number(t.saldo ?? 0) <= 0 ? 'text-emerald-600' : 'text-[#E07845]'">
                                        {{ Number(t.saldo ?? 0) <= 0 ? 'pagado' : `saldo ${importe(t.saldo)}` }}
                                    </p>
                                    <p v-else-if="t.real !== t.cotizado" class="text-[9px] font-bold text-slate-400 tabular-nums">
                                        cotizado {{ importe(t.cotizado) }}
                                    </p>
                                </div>
                                <p v-if="!(orden.totalesPorMoneda ?? []).length" class="text-[10px] font-bold text-slate-300">
                                    Sin importes
                                </p>
                            </div>
                        </div>

                        <!-- Detalle: los servicios que agrupa. Estaba sin forma de verse. -->
                        <div v-if="ordenExpandida === orden.id" class="px-3 pb-2.5 border-t border-slate-100 pt-2">
                            <p class="text-[9px] font-black text-slate-400 uppercase tracking-widest mb-1.5">
                                {{ serviciosDeOrdenAbierta.length }} servicio(s)
                            </p>
                                <div class="flex flex-col gap-1.5">
                                    <div v-for="s in serviciosDeOrdenAbierta" :key="s.id ?? ''"
                                         class="flex items-start justify-between gap-2 bg-slate-50 rounded-lg px-2 py-1.5">
                                        <!-- Aquí manda `descripcionServicio`, al contrario que en La
                                             Biblia. Es el nombre con el que el PROVEEDOR conoce el
                                             servicio —sale de `nombreParaProveedor` si está puesto— y
                                             la orden es el documento que se le manda a él. El día del
                                             itinerario queda debajo: sirve para ubicarlo, no para
                                             pedirlo. Ver docs/Operacion.md §3.9 bis. -->
                                        <div class="min-w-0">
                                            <p class="text-[11px] font-black text-slate-800 leading-snug">
                                                {{ s.descripcionServicio }}
                                            </p>
                                            <!-- El nombre INTERNO de la tarifa, sólo cuando aporta algo.
                                                 Si coincide con el de arriba es que la tarifa no tiene
                                                 `nombreParaProveedor` y `descripcionServicio` ya cayó a
                                                 él: repetirlo seria la misma linea dos veces. -->
                                            <p v-if="s.tarifaNombre && s.tarifaNombre !== s.descripcionServicio"
                                               class="text-[10px] font-bold text-slate-500 leading-snug"
                                               title="Nombre interno de la tarifa: con éste la buscas en el tarifario">
                                                <i class="fas fa-tag text-[8px] mr-1 text-slate-300"></i>{{ s.tarifaNombre }}
                                            </p>
                                            <p v-if="s.contextoServicio" class="text-[10px] text-slate-400 leading-snug">
                                                {{ s.contextoServicio }}
                                            </p>
                                            <p class="text-[10px] text-slate-400 leading-snug">
                                                {{ (s.fechaServicio ?? '').slice(0, 10) }} · {{ nochesTexto(s.cantidadComponente, s.tipoComponente) }}{{ s.cantidadPax }} pax
                                            </p>
                                        </div>
                                        <!-- Lo NEGOCIADO, editable aquí mismo: es el momento en que
                                             tienes al proveedor al teléfono y la orden delante. El
                                             cotizado queda debajo como referencia y no se toca. -->
                                        <div class="shrink-0">
                                            <EditorCostoNegociado
                                                :ref="(el) => registrarEditor(idDe(s), el)"
                                                denso
                                                :costo-cotizado="s.costoCotizado"
                                                :desglose="s.desgloseCotizado"
                                                :moneda-cotizada="s.monedaCotizada?.id ?? ''"
                                                :costo-negociado="s.costoNegociado"
                                                :moneda-negociada="s.monedaNegociada?.id ?? null"
                                                :monedas="operacionStore.monedas"
                                                @guardar="(pl) => onGuardarCosto(s, pl)"
                                            />
                                        </div>
                                    </div>
                                </div>
                                <p class="text-[10px] text-slate-400 leading-snug mt-1.5">
                                    <i class="fas fa-circle-info mr-1"></i>
                                    Toca el importe para editarlo. Vacío = todavía sin negociar, vale el
                                    cotizado. Estos campos son tuyos: una resincronización de la cotización
                                    nunca los pisa.
                                </p>
                        </div>

                        <div class="px-3 py-2 bg-slate-50 border-t border-slate-100 flex justify-end gap-2 flex-wrap">
                            <!-- ⚠️ ELIMINAR sólo en BORRADOR. Una orden que ya salió al
                                 proveedor se ANULA —eso también devuelve sus servicios al pool—
                                 pero deja constancia de que existió. Lo impide igualmente
                                 `OperacionOrdenBorradoListener`; esto sólo evita ofrecer un
                                 botón que va a responder 403. -->
                            <button
                                v-if="orden.estadoOs === 'borrador'"
                                @click="eliminarOrden(orden)"
                                :disabled="borrandoOrden === orden.id"
                                :title="confirmandoOrden === orden.id
                                    ? 'Pulsa otra vez para confirmar'
                                    : 'Elimina el borrador y devuelve sus servicios al pool'"
                                class="mr-auto inline-flex items-center gap-1.5 px-3 py-1.5 text-[10px] font-black uppercase tracking-widest rounded-lg border transition-all shadow-sm disabled:opacity-40"
                                :class="confirmandoOrden === orden.id
                                    ? 'bg-rose-600 text-white border-rose-600'
                                    : 'bg-white hover:bg-rose-50 text-rose-500 border-slate-200 hover:border-rose-200'"
                            >
                                <i class="fas text-[9px]" :class="borrandoOrden === orden.id ? 'fa-circle-notch fa-spin' : 'fa-trash-alt'"></i>
                                {{ confirmandoOrden === orden.id ? '¿Seguro? Los servicios vuelven al pool' : 'Eliminar' }}
                            </button>

                            <!-- Las acciones de ESTADO: sólo las que caben desde donde está, y
                                 cada una confirma antes. Ver `accionesDe()`. -->
                            <button
                                v-for="accion in accionesDe(orden.estadoOs)"
                                :key="accion.id"
                                @click="ejecutarAccion(orden, accion)"
                                :disabled="ejecutandoAccion === `${orden.id}:${accion.id}`"
                                class="inline-flex items-center gap-1.5 px-3 py-1.5 text-[10px] font-black uppercase tracking-widest rounded-lg border transition-all shadow-sm disabled:opacity-40"
                                :class="confirmandoAccion === `${orden.id}:${accion.id}`
                                    ? (accion.tono === 'grave' ? 'bg-rose-600 text-white border-rose-600' : 'bg-[#376875] text-white border-[#376875]')
                                    : (accion.tono === 'grave'
                                        ? 'bg-white hover:bg-rose-50 text-rose-500 border-slate-200 hover:border-rose-200'
                                        : 'bg-white hover:bg-[#376875] hover:text-white hover:border-[#376875] text-slate-600 border-slate-200')"
                            >
                                <i class="fas text-[9px]"
                                   :class="ejecutandoAccion === `${orden.id}:${accion.id}` ? 'fa-circle-notch fa-spin' : accion.icono"></i>
                                {{ confirmandoAccion === `${orden.id}:${accion.id}` ? consecuenciaDe(accion.id) : accion.etiqueta }}
                            </button>

                            <!-- ENVIAR: separado de emitir a propósito. Emitir congela el
                                 contenido; esto se lo pone delante al proveedor, y no se
                                 retira. Por eso es el mismo botón para enviar y para
                                 REENVIAR — que es lo normal cuando se perdió el correo. -->
                            <button
                                v-if="orden.estadoOs === 'emitida' || orden.estadoOs === 'confirmada' || orden.estadoOs === 'completada'"
                                @click="abrirEnvio(orden)"
                                class="inline-flex items-center gap-1.5 px-3 py-1.5 bg-white hover:bg-emerald-600 hover:text-white hover:border-emerald-600 text-emerald-700 text-[10px] font-black uppercase tracking-widest rounded-lg border border-slate-200 transition-all shadow-sm"
                                title="Previsualiza y manda la orden al proveedor. Se puede reenviar las veces que haga falta."
                            >
                                <i class="fas fa-paper-plane text-[9px]"></i> Enviar
                            </button>

                            <!-- Reemitir: anula y deja la sucesora ya creada, en borrador. Vivía
                                 sólo dentro del aviso de divergencias con La Biblia, así que no
                                 se podía reemitir por cualquier otro motivo —un cambio de precio
                                 pactado, un error en el documento— sin pasar por el desplegable. -->
                            <button
                                v-if="orden.estadoOs === 'emitida' || orden.estadoOs === 'confirmada'"
                                @click="anularParaReemitir(orden)"
                                class="inline-flex items-center gap-1.5 px-3 py-1.5 bg-white hover:bg-amber-500 hover:text-white hover:border-amber-500 text-amber-600 text-[10px] font-black uppercase tracking-widest rounded-lg border border-slate-200 transition-all shadow-sm"
                                title="Anula ésta y crea la sucesora en borrador con los datos de hoy. Nada se pierde."
                            >
                                <i class="fas fa-rotate text-[9px]"></i> Reemitir
                            </button>

                            <button
                                @click="abrirEdicion(orden)"
                                class="inline-flex items-center gap-1.5 px-3 py-1.5 bg-white hover:bg-[#376875] hover:text-white hover:border-[#376875] text-slate-600 text-[10px] font-black uppercase tracking-widest rounded-lg border border-slate-200 transition-all shadow-sm"
                            >
                                <i class="fas fa-pen text-[9px]"></i> Editar
                            </button>
                            <button
                                @click="abrirPagos(orden)"
                                class="inline-flex items-center gap-1.5 px-3 py-1.5 bg-white hover:bg-emerald-600 hover:text-white hover:border-emerald-600 text-slate-600 text-[10px] font-black uppercase tracking-widest rounded-lg border border-slate-200 transition-all shadow-sm"
                            >
                                <i class="fas fa-hand-holding-dollar text-[9px]"></i> Pagos
                            </button>
                            <button
                                @click="abrirMensajes(orden)"
                                class="inline-flex items-center gap-1.5 px-3 py-1.5 bg-white hover:bg-[#376875] hover:text-white hover:border-[#376875] text-slate-600 text-[10px] font-black uppercase tracking-widest rounded-lg border border-slate-200 transition-all shadow-sm"
                            >
                                <i class="fas fa-message text-[9px]"></i> Bitácora
                            </button>

                            <p v-if="errorOrden?.id === orden.id"
                               class="w-full text-[11px] font-bold text-rose-600 leading-snug text-left">
                                <i class="fas fa-triangle-exclamation mr-1"></i>{{ errorOrden?.motivo }}
                            </p>
                        </div>
                    </div>
                </div>
            </section>

        </main>

        <!-- ================================================================
             MODAL: EXPEDIENTE (namelist + documentos + salto a cotización)

             Dos paneles colapsables. Mobile-first: hoja inferior en móvil, tarjeta en ancho.
             El botón de cotización salta al editor de la versión de la que cuelga el servicio
             clicado, que es lo que pidió el operador.
             ================================================================ -->
        <div v-if="expedienteAbierto" class="fixed inset-0 z-50 flex items-end sm:items-center justify-center bg-slate-900/50" @click.self="cerrarModal()">
            <div class="bg-white rounded-t-2xl sm:rounded-2xl shadow-xl w-full sm:max-w-lg max-h-[88vh] flex flex-col overflow-hidden">
                <header class="bg-slate-900 text-white px-5 py-3 flex items-center gap-2 shrink-0">
                    <i class="fas fa-folder-open text-[#E07845]"></i>
                    <div class="min-w-0">
                        <h3 class="font-black text-sm tracking-tight leading-tight truncate">{{ expedienteAbierto.nombre }}</h3>
                        <p v-if="expedienteDetalle?.pasajeroPrincipal" class="text-[10px] text-slate-400 truncate">{{ expedienteDetalle.pasajeroPrincipal }}</p>
                        <!-- Localizador + versión: copiable, y enlace a la vista del cliente (app pax,
                             otro dominio → otra pestaña). -->
                        <div v-if="expedienteAbierto.localizador" class="flex items-center gap-2 mt-1">
                            <button @click="copiarLocalizador"
                                    class="inline-flex items-center gap-1 text-[10px] font-black text-white bg-white/10 hover:bg-white/20 rounded px-1.5 py-0.5 tracking-wider transition-colors"
                                    :title="localizadorCopiado ? 'Copiado' : 'Copiar localizador'">
                                {{ localizadorVersion }}
                                <i class="fas text-[9px]" :class="localizadorCopiado ? 'fa-check text-emerald-300' : 'fa-copy text-slate-300'"></i>
                            </button>
                            <a v-if="linkPax" :href="linkPax" target="_blank" rel="noopener"
                               class="inline-flex items-center gap-1 text-[10px] font-bold text-sky-300 hover:text-sky-200"
                               title="Abrir la vista del cliente en otra pestaña">
                                <i class="fas fa-arrow-up-right-from-square text-[9px]"></i> Vista cliente
                            </a>
                        </div>
                    </div>
                    <button @click="cerrarModal()" class="ml-auto text-slate-400 hover:text-white shrink-0">
                        <i class="fas fa-xmark"></i>
                    </button>
                </header>

                <div class="overflow-y-auto">
                    <p v-if="cargandoExpediente" class="text-xs text-slate-400 text-center py-8">
                        <i class="fas fa-spinner fa-spin mr-1"></i> Cargando…
                    </p>
                    <template v-else>
                        <!-- Panel: NAMELIST -->
                        <div class="border-b border-slate-100">
                            <button @click="panelNamelist = !panelNamelist"
                                    class="w-full px-4 py-3 flex items-center justify-between hover:bg-slate-50 transition-colors">
                                <span class="text-[10px] font-black text-slate-500 uppercase tracking-widest">
                                    <i class="fas fa-users mr-1 text-slate-300"></i>
                                    Namelist ({{ (expedienteDetalle?.filepasajeros ?? []).length }})
                                </span>
                                <i class="fas text-[10px] text-slate-300" :class="panelNamelist ? 'fa-chevron-up' : 'fa-chevron-down'"></i>
                            </button>
                            <div v-if="panelNamelist" class="px-4 pb-3">
                                <p v-if="!(expedienteDetalle?.filepasajeros ?? []).length" class="text-[11px] text-slate-400 py-2">
                                    Sin pasajeros cargados.
                                </p>
                                <div v-else class="flex flex-col divide-y divide-slate-100">
                                    <div v-for="(pax, i) in (expedienteDetalle?.filepasajeros ?? [])" :key="i" class="py-2">
                                        <p class="text-sm font-bold text-slate-800">{{ nombrePasajero(pax) }}</p>
                                        <p class="text-[10px] text-slate-400">
                                            <span v-if="pax.numerodocumento">{{ pax.tipodocumento || 'Doc' }}: {{ pax.numerodocumento }}</span>
                                            <span v-if="pax.pais && typeof pax.pais === 'object'"> · {{ (pax.pais as { nombre?: string }).nombre }}</span>
                                        </p>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Panel: DOCUMENTOS -->
                        <div>
                            <button @click="panelDocumentos = !panelDocumentos"
                                    class="w-full px-4 py-3 flex items-center justify-between hover:bg-slate-50 transition-colors">
                                <span class="text-[10px] font-black text-slate-500 uppercase tracking-widest">
                                    <i class="fas fa-paperclip mr-1 text-slate-300"></i>
                                    Documentos ({{ (expedienteDetalle?.filedocumentos ?? []).length }})
                                </span>
                                <i class="fas text-[10px] text-slate-300" :class="panelDocumentos ? 'fa-chevron-up' : 'fa-chevron-down'"></i>
                            </button>
                            <div v-if="panelDocumentos" class="px-4 pb-3">
                                <!-- Subir documento (voucher, confirmación de reserva…) sin salir del
                                     cuadro: se generan justo al operar. Reutiliza el endpoint multipart
                                     de FileDetalle; el tipo sale del nombre del archivo. -->
                                <button @click="docInputRef?.click()" :disabled="subiendoDoc"
                                        class="w-full mb-2 py-2 flex items-center justify-center gap-2 text-[11px] font-black uppercase tracking-widest text-[#376875] bg-[#376875]/8 hover:bg-[#376875]/15 disabled:opacity-50 rounded-lg border border-dashed border-[#376875]/30 transition-colors">
                                    <i class="fas" :class="subiendoDoc ? 'fa-spinner fa-spin' : 'fa-cloud-arrow-up'"></i>
                                    {{ subiendoDoc ? 'Subiendo…' : 'Subir documento' }}
                                </button>
                                <input ref="docInputRef" type="file" class="hidden" @change="onSubirDocumento" />

                                <p v-if="!(expedienteDetalle?.filedocumentos ?? []).length" class="text-[11px] text-slate-400 py-2">
                                    Sin archivos cargados.
                                </p>
                                <div v-else class="grid grid-cols-1 gap-2">
                                    <a v-for="(doc, i) in (expedienteDetalle?.filedocumentos ?? [])" :key="i"
                                       :href="(doc.imageUrl as string) || '#'" target="_blank" rel="noopener"
                                       class="flex items-center gap-3 bg-slate-50 hover:bg-slate-100 rounded-lg px-3 py-2 transition-colors">
                                        <i class="fas fa-file-lines text-[#376875]"></i>
                                        <div class="min-w-0 flex-1">
                                            <p class="text-sm font-bold text-slate-700 truncate">{{ (doc.tipodocumento as string) || 'Documento' }}</p>
                                            <p v-if="doc.vencimiento" class="text-[10px] text-slate-400">vence {{ String(doc.vencimiento).slice(0, 10) }}</p>
                                        </div>
                                        <i class="fas fa-arrow-up-right-from-square text-[10px] text-slate-300"></i>
                                    </a>
                                </div>
                            </div>
                        </div>
                    </template>
                </div>

                <!-- Accesos: al expediente completo y a la cotización del servicio -->
                <div class="px-4 py-3 border-t border-slate-200 bg-slate-50 shrink-0 flex gap-2">
                    <button @click="irAExpediente"
                            class="flex-1 py-2.5 bg-white hover:bg-slate-100 border border-slate-300 text-[#376875] rounded-lg text-xs font-black uppercase tracking-widest shadow-sm transition-colors">
                        <i class="fas fa-folder-open mr-1"></i>
                        Expediente
                    </button>
                    <button @click="irACotizacion" :disabled="!expedienteAbierto.cotizacionId"
                            class="flex-1 py-2.5 bg-[#E07845] hover:bg-[#c96636] disabled:opacity-40 text-white rounded-lg text-xs font-black uppercase tracking-widest shadow-sm transition-colors">
                        <i class="fas fa-file-invoice-dollar mr-1"></i>
                        Cotización
                    </button>
                </div>
            </div>
        </div>

        <!-- ================================================================
             MODAL: ENVIAR LA ORDEN AL PROVEEDOR

             Se previsualiza porque es IRREVERSIBLE. El cuerpo se pinta en `<pre>` para que se
             vea EXACTAMENTE como va a salir: un `<div>` colapsaría los saltos de línea y el
             operador aprobaría un texto distinto del que se manda.
             ================================================================ -->
        <div v-if="ordenAEnviar" class="fixed inset-0 z-50 flex items-end sm:items-center justify-center bg-slate-900/50" @click.self="ordenAEnviar = null">
            <div class="bg-white rounded-t-2xl sm:rounded-2xl shadow-xl w-full sm:max-w-2xl max-h-[90vh] flex flex-col overflow-hidden">
                <header class="bg-slate-900 text-white px-5 py-3 flex items-center gap-2 shrink-0">
                    <i class="fas fa-paper-plane text-emerald-400"></i>
                    <div class="min-w-0">
                        <h3 class="font-black text-sm tracking-tight leading-tight">Enviar al proveedor</h3>
                        <p class="text-[10px] text-slate-400 truncate">{{ documento?.destinatario || ordenAEnviar.compradorNombre || ordenAEnviar.numeroOs }}</p>
                    </div>
                    <button @click="ordenAEnviar = null" class="ml-auto text-slate-400 hover:text-white shrink-0">
                        <i class="fas fa-xmark"></i>
                    </button>
                </header>

                <div class="overflow-y-auto px-5 py-4 space-y-4">
                    <p v-if="cargandoDocumento" class="text-xs text-slate-400 text-center py-6">
                        <i class="fas fa-spinner fa-spin mr-1"></i> Preparando el documento…
                    </p>

                    <template v-else-if="documento">
                        <div>
                            <p class="text-[9px] font-black text-slate-400 uppercase tracking-widest mb-1">Asunto</p>
                            <p class="text-sm font-bold text-slate-800">{{ documento.asunto }}</p>
                        </div>

                        <div>
                            <p class="text-[9px] font-black text-slate-400 uppercase tracking-widest mb-1">
                                Lo que se le manda · {{ documento.lineas }} línea(s)
                            </p>
                            <pre class="text-xs text-slate-700 bg-slate-50 border border-slate-200 rounded-xl p-3 whitespace-pre-wrap font-sans leading-relaxed">{{ documento.cuerpo }}</pre>
                            <p class="text-[10px] text-slate-400 mt-1 leading-snug">
                                Sale de las líneas congeladas al emitir, y no lleva importes: lo que se paga
                                se lleva aparte.
                            </p>
                        </div>

                        <div v-if="documento.enlace">
                            <p class="text-[9px] font-black text-slate-400 uppercase tracking-widest mb-1">Enlace que recibe</p>
                            <a :href="documento.enlace" target="_blank" rel="noopener noreferrer"
                               class="text-xs font-bold text-[#376875] hover:underline break-all">{{ documento.enlace }}</a>
                            <p class="text-[10px] text-slate-400 mt-1 leading-snug">
                                Abre la orden sin necesidad de cuenta, con botón para descargar el PDF.
                            </p>
                        </div>

                        <!-- ⚠️ Fuera de la ventana de 24 h, Meta sólo admite plantillas aprobadas
                             y una orden con varias líneas no cabe en una. Se dice ANTES de elegir
                             canal, no después de leer todo el documento. -->
                        <p v-if="!documento.ventanaWhatsappAbierta"
                           class="text-[11px] font-bold text-amber-700 bg-amber-50 border border-amber-200 rounded-lg px-3 py-2 leading-snug">
                            <i class="fas fa-triangle-exclamation mr-1"></i>
                            La ventana de 24 h de WhatsApp está cerrada: Meta sólo deja mandar plantillas
                            aprobadas. Usa el correo, o espera a que el proveedor escriba.
                        </p>

                        <div>
                            <p class="text-[9px] font-black text-slate-400 uppercase tracking-widest mb-1.5">Por dónde</p>
                            <div class="flex flex-wrap gap-2">
                                <!-- Los no disponibles se enseñan con su motivo en vez de esconderse:
                                     «no aparece WhatsApp» obliga a adivinar; «sin datos o vetado» no. -->
                                <button v-for="c in documento.canales" :key="c.id"
                                        @click="c.disponible && (canalElegido = c.id)"
                                        :disabled="!c.disponible"
                                        :title="c.motivo ?? ''"
                                        class="px-3 py-2 rounded-lg border text-[11px] font-black uppercase tracking-widest transition-all disabled:opacity-40 disabled:cursor-not-allowed"
                                        :class="canalElegido === c.id
                                            ? 'bg-[#376875] text-white border-[#376875]'
                                            : 'bg-white text-slate-600 border-slate-200 hover:border-[#376875]'">
                                    {{ c.nombre }}
                                    <span v-if="!c.disponible" class="block text-[9px] font-bold normal-case tracking-normal opacity-70">
                                        {{ c.motivo === 'sin_datos_o_vetado' ? 'sin datos' : 'no aplica' }}
                                    </span>
                                </button>
                            </div>
                        </div>
                    </template>

                    <p v-if="errorEnvio" class="text-[11px] font-bold text-rose-600 leading-snug">
                        <i class="fas fa-triangle-exclamation mr-1"></i>{{ errorEnvio }}
                    </p>
                </div>

                <footer class="px-5 py-3 bg-slate-50 border-t border-slate-200 flex items-center justify-end gap-2 shrink-0">
                    <button @click="ordenAEnviar = null"
                            class="px-4 py-2 text-xs font-black uppercase tracking-widest text-slate-500 hover:text-slate-800">
                        Cancelar
                    </button>
                    <button @click="confirmarEnvio"
                            :disabled="enviandoOrden || !canalElegido || !documento || documento.lineas === 0"
                            class="px-5 py-2 bg-emerald-600 hover:bg-emerald-700 disabled:opacity-40 text-white text-xs font-black uppercase tracking-widest rounded-lg shadow-sm">
                        <i :class="enviandoOrden ? 'fas fa-spinner fa-spin' : 'fas fa-paper-plane'" class="mr-1"></i>
                        Enviar
                    </button>
                </footer>
            </div>
        </div>

        <!-- ================================================================
             MODAL: PAGOS A CUENTA AL PROVEEDOR

             Mobile-first: hoja inferior en móvil, tarjeta centrada en ancho. Saldo por moneda
             arriba, historial de pagos, y el alta abajo. La moneda del alta se limita a las de
             la orden — no se convierte, así que un pago sólo tiene sentido en una de ellas.
             ================================================================ -->
        <div v-if="pagosOrden" class="fixed inset-0 z-50 flex items-end sm:items-center justify-center bg-slate-900/50" @click.self="cerrarModal()">
            <div class="bg-white rounded-t-2xl sm:rounded-2xl shadow-xl w-full sm:max-w-md max-h-[88vh] flex flex-col overflow-hidden">
                <header class="bg-slate-900 text-white px-5 py-3 flex items-center gap-2 shrink-0">
                    <i class="fas fa-hand-holding-dollar text-emerald-400"></i>
                    <div class="min-w-0">
                        <h3 class="font-black text-sm tracking-tight leading-tight">Pagos al proveedor</h3>
                        <p class="text-[10px] text-slate-400 truncate">{{ pagosOrden.compradorNombre || pagosOrden.numeroOs }}</p>
                    </div>
                    <button @click="cerrarModal()" class="ml-auto text-slate-400 hover:text-white shrink-0">
                        <i class="fas fa-xmark"></i>
                    </button>
                </header>

                <div class="overflow-y-auto">
                    <!-- SALDO por moneda: negociado − pagado -->
                    <div class="px-4 py-3 bg-slate-50 border-b border-slate-100 flex flex-col gap-1">
                        <div v-for="t in (pagosOrden.totalesPorMoneda ?? [])" :key="t.moneda"
                             class="flex items-center justify-between gap-3 text-sm">
                            <span class="text-[10px] font-black text-slate-400 uppercase tracking-widest">{{ t.moneda }}</span>
                            <div class="flex items-baseline gap-3 tabular-nums">
                                <span class="text-[10px] text-slate-400">neg. {{ importe(t.real) }}</span>
                                <span class="text-[10px] text-slate-400">pag. {{ importe(t.pagado ?? '0') }}</span>
                                <span class="font-black" :class="Number(t.saldo ?? 0) <= 0 ? 'text-emerald-600' : 'text-[#E07845]'">
                                    {{ Number(t.saldo ?? 0) <= 0 ? 'saldado' : importe(t.saldo) }}
                                </span>
                            </div>
                        </div>
                    </div>

                    <!-- Historial de pagos -->
                    <div class="px-4 py-3">
                        <p v-if="cargandoPagos" class="text-xs text-slate-400 text-center py-4">
                            <i class="fas fa-spinner fa-spin mr-1"></i> Cargando…
                        </p>
                        <p v-else-if="!pagos.length" class="text-xs text-slate-400 text-center py-4">
                            Sin pagos registrados todavía.
                        </p>
                        <div v-else class="flex flex-col gap-2">
                            <div v-for="pago in pagos" :key="pago.id ?? ''"
                                 class="flex items-start justify-between gap-2 bg-slate-50 rounded-lg px-3 py-2">
                                <div class="min-w-0">
                                    <p class="text-sm font-black text-slate-800 tabular-nums">
                                        <span class="text-[10px] font-bold text-slate-400 mr-1">{{ pago.moneda?.id }}</span>{{ importe(pago.monto) }}
                                    </p>
                                    <p class="text-[10px] text-slate-400 flex items-center gap-1 flex-wrap">
                                        <span class="font-bold text-slate-500 flex items-center gap-1">
                                            <i class="fas text-[9px]" :class="iconoMedioPago(pago.medioPago)"></i>
                                            {{ pago.medioPagoLabel }}
                                        </span>
                                        · {{ (pago.fecha ?? '').slice(0, 10) }}<span v-if="pago.usuarioNombre"> · {{ pago.usuarioNombre }}</span>
                                    </p>
                                    <p v-if="pago.notas" class="text-[10px] text-slate-500 leading-snug mt-0.5">{{ pago.notas }}</p>
                                </div>
                                <button @click="borrarPago(pago)"
                                        class="shrink-0 w-7 h-7 rounded bg-white hover:bg-rose-500 hover:text-white text-slate-400 border border-slate-200 flex items-center justify-center transition-all"
                                        title="Eliminar pago">
                                    <i class="fas fa-trash-alt text-[10px]"></i>
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- ALTA de pago -->
                <div class="px-4 py-3 border-t border-slate-200 bg-white shrink-0 flex flex-col gap-2">
                    <p class="text-[9px] font-black text-slate-400 uppercase tracking-widest">Registrar pago</p>
                    <div class="flex gap-2">
                        <select v-model="formPago.moneda"
                                class="text-xs font-black text-slate-600 bg-white border border-slate-200 rounded-lg px-2 py-2 outline-none focus:ring-2 focus:ring-emerald-500">
                            <option v-for="m in monedasDeOrden" :key="m" :value="m">{{ m }}</option>
                        </select>
                        <input v-model="formPago.monto" inputmode="decimal" placeholder="Monto"
                               class="flex-1 min-w-0 text-sm font-black text-slate-800 tabular-nums text-right bg-white border border-slate-200 rounded-lg px-3 py-2 outline-none focus:ring-2 focus:ring-emerald-500" />
                        <input v-model="formPago.fecha" type="date"
                               class="text-xs font-bold text-slate-600 bg-white border border-slate-200 rounded-lg px-2 py-2 outline-none focus:ring-2 focus:ring-emerald-500" />
                    </div>
                    <!-- Sin opción preseleccionada: cómo se pagó es un hecho, y un valor por
                         defecto lo convierte en «lo que estaba puesto al abrir». -->
                    <select v-model="formPago.medioPago"
                            class="text-xs font-bold text-slate-600 bg-white border border-slate-200 rounded-lg px-2 py-2 outline-none focus:ring-2 focus:ring-emerald-500">
                        <option value="" disabled>¿Por qué medio se pagó?</option>
                        <option v-for="m in operacionStore.mediosPago" :key="m.id" :value="m.id">{{ m.label }}</option>
                    </select>
                    <input v-model="formPago.notas" placeholder="Observaciones (opcional): nº operación, banco…"
                           class="text-sm text-slate-700 bg-white border border-slate-200 rounded-lg px-3 py-2 outline-none focus:ring-2 focus:ring-emerald-500" />
                    <p v-if="errorPago" class="text-[11px] font-bold text-rose-600">
                        <i class="fas fa-triangle-exclamation mr-1"></i>{{ errorPago }}
                    </p>
                    <button @click="registrarPago" :disabled="guardandoPago"
                            class="w-full py-2.5 bg-emerald-600 hover:bg-emerald-700 disabled:opacity-50 text-white rounded-lg text-xs font-black uppercase tracking-widest shadow-sm transition-colors">
                        <i v-if="guardandoPago" class="fas fa-spinner fa-spin mr-1"></i>
                        Registrar pago
                    </button>
                </div>
            </div>
        </div>

        <!-- ================================================================
             MODAL: HISTORIAL DE ESTADOS (bitácora)

             El «desde hace X» de la fila responde «¿cuánto lleva así?»; esto responde «¿por
             dónde pasó y quién lo movió?». Se abre desde el reloj bajo el estado.
             ================================================================ -->
        <div v-if="bitacoraServicio" class="fixed inset-0 z-50 flex items-end sm:items-center justify-center bg-slate-900/50" @click.self="cerrarModal()">
            <div class="bg-white rounded-t-2xl sm:rounded-2xl shadow-xl w-full sm:max-w-md max-h-[80vh] flex flex-col overflow-hidden">
                <header class="bg-slate-900 text-white px-5 py-3 flex items-center gap-2 shrink-0">
                    <i class="fas fa-clock-rotate-left text-[#E07845]"></i>
                    <div class="min-w-0">
                        <h3 class="font-black text-sm tracking-tight leading-tight">Historial de estados</h3>
                        <p class="text-[10px] text-slate-400 truncate">{{ bitacoraServicio.descripcionServicio }}</p>
                    </div>
                    <button @click="cerrarModal()" class="ml-auto text-slate-400 hover:text-white shrink-0">
                        <i class="fas fa-xmark"></i>
                    </button>
                </header>

                <div class="p-4 overflow-y-auto">
                    <p v-if="cargandoBitacora" class="text-xs text-slate-400 text-center py-6">
                        <i class="fas fa-spinner fa-spin mr-1"></i> Cargando…
                    </p>
                    <p v-else-if="!bitacora.length" class="text-xs text-slate-400 text-center py-6">
                        Sin cambios registrados todavía. La historia se registra desde ahora.
                    </p>
                    <ol v-else class="relative border-l-2 border-slate-200 ml-2">
                        <li v-for="(b, i) in bitacora" :key="i" class="ml-4 pb-4 last:pb-0 relative">
                            <span class="absolute -left-[1.35rem] top-1 w-3 h-3 rounded-full border-2 border-white"
                                  :class="i === 0 ? 'bg-[#E07845]' : 'bg-slate-300'"></span>
                            <p class="text-[9px] font-black uppercase tracking-widest"
                               :class="b.campo === 'reserva' ? 'text-[#376875]' : 'text-slate-400'">
                                {{ b.campo === 'reserva' ? 'Reserva' : 'Operación' }}
                            </p>
                            <p class="text-sm font-bold text-slate-800 leading-snug">
                                <span v-if="b.valorAnterior" class="text-slate-400 font-medium">{{ etiquetaEstado(b.campo, b.valorAnterior) }} → </span>
                                {{ etiquetaEstado(b.campo, b.valorNuevo) }}
                            </p>
                            <p class="text-[10px] text-slate-400">
                                {{ fechaHora(b.createdAt) }}
                                <span v-if="b.usuarioNombre"> · {{ b.usuarioNombre }}</span>
                            </p>
                        </li>
                    </ol>
                </div>
            </div>
        </div>

        <!-- ================================================================
             MODAL: EDITAR LA CABECERA DE UNA ORDEN

             Una orden se creaba y ya no se podía tocar: ni corregir el número, ni cambiar el
             destinatario, ni moverla de estado. Lo único a mano era la bitácora, que no edita
             nada. El importe NO se edita aquí — sale de los ítems (§5.4).
             ================================================================ -->
        <div v-if="ordenEditando" class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/50 px-4">
            <div class="bg-white rounded-2xl shadow-xl w-full max-w-md overflow-hidden">
                <header class="bg-slate-900 text-white px-5 py-3 flex items-center gap-2">
                    <i class="fas fa-pen text-[#E07845]"></i>
                    <h3 class="font-black text-sm tracking-tight">Editar Orden de Servicio</h3>
                </header>

                <div class="p-5 flex flex-col gap-3">
                    <label class="flex flex-col gap-1">
                        <span class="text-[9px] font-black text-slate-400 uppercase tracking-widest">Número de OS</span>
                        <input
                            v-model="formEdicion.numeroOs"
                            class="bg-white border border-slate-200 rounded-lg px-3 py-2 text-sm font-bold text-slate-700 outline-none focus:ring-2 focus:ring-[#376875]"
                        />
                    </label>

                    <label class="flex flex-col gap-1">
                        <span class="text-[9px] font-black text-slate-400 uppercase tracking-widest">
                            Destinatario <span class="text-slate-300 normal-case font-bold">(a quién se le manda)</span>
                        </span>
                        <SearchableSelect
                            v-model="formEdicion.compradorMaestroId"
                            :options="opcionesProveedores"
                            placeholder="Buscar proveedor..."
                            @update:model-value="onDestinatarioEdicion"
                        />
                        <span v-if="!formEdicion.compradorMaestroId && formEdicion.compradorNombre"
                              class="text-[10px] font-bold text-amber-600 flex items-start gap-1">
                            <i class="fas fa-triangle-exclamation mt-0.5"></i>
                            <span>Va a nombre de <b>{{ formEdicion.compradorNombre }}</b>, que no está en el catálogo.</span>
                        </span>
                    </label>

                    <!-- ⚠️ El estado se VE aquí y se MUEVE desde los botones de la tarjeta.
                         Era un `<select>` y ese control no distingue entre corregir un dato y
                         mandarle un documento a un proveedor: emitir y anular salían del mismo
                         gesto con el que se arregla un número de OS, sin confirmación y a un
                         desliz de distancia. Cada acción tiene ahora su botón y su confirmación,
                         y el listado ofrece sólo las que caben desde el estado actual. -->
                    <div class="flex flex-col gap-1">
                        <span class="text-[9px] font-black text-slate-400 uppercase tracking-widest">Estado</span>
                        <div class="flex items-center gap-2 bg-slate-50 border border-slate-200 rounded-lg px-3 py-2">
                            <span class="text-sm font-bold text-slate-700">{{ getEstadoOsConfig(formEdicion.estadoOs).label }}</span>
                            <span class="ml-auto text-[10px] text-slate-400">se cambia desde los botones de la orden</span>
                        </div>
                    </div>

                    <!-- El importe no se toca aquí a propósito: vive en cada servicio, con su
                         moneda. Decirlo evita que alguien lo busque y lo eche de menos. -->
                    <p class="text-[10px] text-slate-400 leading-snug bg-slate-50 border border-slate-200 rounded-lg px-3 py-2">
                        <i class="fas fa-circle-info mr-1"></i>
                        Los importes se ajustan servicio por servicio en La Biblia, en la columna de
                        costo real. Aquí no hay un total que editar.
                    </p>

                    <p v-if="errorEdicion" class="text-xs font-bold text-rose-600">
                        <i class="fas fa-triangle-exclamation mr-1"></i>{{ errorEdicion }}
                    </p>
                </div>

                <footer class="px-5 py-3 bg-slate-50 border-t border-slate-200 flex justify-end gap-2">
                    <button
                        @click="ordenEditando = null"
                        class="px-4 py-2 text-xs font-black uppercase tracking-widest text-slate-500 hover:text-slate-800"
                    >
                        Cancelar
                    </button>
                    <button
                        @click="guardarEdicion"
                        :disabled="guardandoEdicion || !formEdicion.numeroOs.trim()"
                        class="px-5 py-2 bg-[#E07845] hover:bg-[#c96636] disabled:opacity-50 text-white rounded-lg text-xs font-black uppercase tracking-widest shadow-sm transition-colors"
                    >
                        <i v-if="guardandoEdicion" class="fas fa-spinner fa-spin mr-1"></i>
                        Guardar
                    </button>
                </footer>
            </div>
        </div>

        <!-- ================================================================
             MODAL: GENERAR ORDEN DE SERVICIO
             ================================================================ -->
        <div v-if="mostrarModalOs" class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/50 px-4">
            <div class="bg-white rounded-2xl shadow-xl w-full max-w-md overflow-hidden">
                <header class="bg-slate-900 text-white px-5 py-3 flex items-center gap-2">
                    <i class="fas fa-file-invoice text-[#E07845]"></i>
                    <h3 class="font-black text-sm tracking-tight">Generar Orden de Servicio</h3>
                </header>

                <div class="p-5 flex flex-col gap-3">
                    <p class="text-xs text-slate-500">
                        Se agruparán <strong class="text-slate-800">{{ seleccionados.length }}</strong> servicios
                        del expediente <strong class="text-slate-800">{{ serviciosSeleccionados[0]?.file?.nombreGrupo }}</strong>.
                    </p>

                    <!--
                      EL DESGLOSE. El total salía solo, sin decir de dónde, y con un número
                      que no cuadra con nada visible sólo queda confiar o rehacerlo a mano.
                      Cada línea es un COMPONENTE —que es la unidad de la orden— con lo que
                      aporta.
                    -->
                    <div class="rounded-lg border border-slate-200 bg-slate-50 divide-y divide-slate-200 max-h-40 overflow-y-auto">
                        <div v-for="s in serviciosSeleccionados" :key="s.id ?? ''"
                             class="flex items-start justify-between gap-2 px-3 py-2">
                            <div class="min-w-0">
                                <p class="text-[11px] font-bold text-slate-700 leading-snug">
                                    {{ s.contextoServicio || s.descripcionServicio }}
                                </p>
                                <p v-if="s.contextoServicio" class="text-[10px] text-slate-400 leading-snug truncate">
                                    {{ s.descripcionServicio }}
                                </p>
                                <p class="text-[10px] text-slate-400">
                                    {{ (s.fechaServicio ?? '').slice(0, 10) }} · {{ nochesTexto(s.cantidadComponente, s.tipoComponente) }}{{ s.cantidadPax }} pax
                                </p>
                            </div>
                            <span class="text-xs font-black text-slate-700 tabular-nums shrink-0">
                                <!-- La moneda va SIEMPRE pegada al número: «12.00» a secas no
                                     dice si son soles o dólares, y en una orden que ahora puede
                                     mezclarlas es la mitad del dato. -->
                                <span class="text-[10px] font-bold text-slate-400 mr-1">{{ s.monedaCotizada?.id || '?' }}</span>{{ importe(s.costoCotizado) }}
                            </span>
                        </div>
                    </div>

                    <label class="flex flex-col gap-1">
                        <span class="text-[9px] font-black text-slate-400 uppercase tracking-widest">Número de OS</span>
                        <input
                            v-model="formOs.numeroOs"
                            class="bg-white border border-slate-200 rounded-lg px-3 py-2 text-sm font-bold text-slate-700 outline-none focus:ring-2 focus:ring-[#376875]"
                        />
                    </label>

                    <!--
                      Selector del catálogo, no texto libre. La OS existe para MANDARSE a
                      alguien: escrito a mano, «Gabrie Aime» y «Gabriel Aimé» son dos
                      proveedores distintos para cualquier filtro, y no hay a quién enviarla
                      porque detrás no queda un id, sólo una cadena.
                    -->
                    <label class="flex flex-col gap-1">
                        <span class="text-[9px] font-black text-slate-400 uppercase tracking-widest">
                            Destinatario <span class="text-slate-300 normal-case font-bold">(a quién se le manda)</span>
                        </span>
                        <SearchableSelect
                            v-model="formOs.compradorMaestroId"
                            :options="opcionesProveedores"
                            placeholder="Buscar proveedor..."
                            @update:model-value="onDestinatarioChange"
                        />
                        <span v-if="!formOs.compradorMaestroId && formOs.compradorNombre"
                              class="text-[10px] font-bold text-amber-600 flex items-start gap-1 mt-0.5">
                            <i class="fas fa-triangle-exclamation mt-0.5"></i>
                            <span>
                                Estos servicios están a nombre de <b>{{ formOs.compradorNombre }}</b>, que no
                                está en el catálogo. Elige uno para poder enviarle la orden.
                            </span>
                        </span>
                    </label>

                    <!--
                      SIN campo de total. El importe no se fija aquí: vive en cada ítem con su
                      moneda —cotizado (referencial) y negociado (editable)—. Esto es sólo el
                      resumen de lo que dice el cotizador, POR MONEDA y sin convertir, para que
                      se vea de dónde sale antes de crear la orden.
                    -->
                    <div class="rounded-lg bg-slate-800 text-white px-3 py-2 flex flex-col gap-1">
                        <span class="text-[9px] font-black text-slate-400 uppercase tracking-widest">
                            Referencial del cotizador
                        </span>
                        <div v-for="t in totalesPorMoneda" :key="t.moneda"
                             class="flex items-baseline justify-between gap-3">
                            <span class="text-[10px] font-bold text-slate-400">{{ t.moneda }}</span>
                            <span class="text-base font-black tabular-nums">{{ importe(t.total.toFixed(2)) }}</span>
                        </div>
                        <span class="text-[10px] text-slate-400 leading-snug mt-0.5">
                            Se ajusta servicio por servicio en el cuadro, en la columna de costo real.
                        </span>
                    </div>

                    <p v-if="errorOs" class="text-xs font-bold text-rose-600">
                        <i class="fas fa-triangle-exclamation mr-1"></i>{{ errorOs }}
                    </p>
                </div>

                <footer class="px-5 py-3 bg-slate-50 border-t border-slate-200 flex justify-end gap-2">
                    <button
                        @click="mostrarModalOs = false"
                        class="px-4 py-2 text-xs font-black uppercase tracking-widest text-slate-500 hover:text-slate-800"
                    >
                        Cancelar
                    </button>
                    <!-- Dos caminos, y el que sale al proveedor NO es el destacado.
                         Componer y mandar son decisiones distintas: se puede querer repasar el
                         destinatario o los importes antes de que el documento exista para
                         alguien de fuera. El borrador se puede emitir después desde su tarjeta. -->
                    <button
                        @click="confirmarOs(false)"
                        :disabled="guardandoOs"
                        title="La crea en borrador: se puede repasar y emitir después desde su tarjeta"
                        class="px-4 py-2 bg-white hover:bg-slate-100 border border-slate-200 disabled:opacity-50 text-slate-600 text-xs font-black uppercase tracking-widest rounded-lg shadow-sm"
                    >
                        <i v-if="guardandoOs" class="fas fa-spinner fa-spin mr-1"></i>
                        Crear borrador
                    </button>
                    <button
                        @click="confirmarOs(true)"
                        :disabled="guardandoOs"
                        title="La crea y la emite: congela el contenido y ya no vuelve a borrador"
                        class="px-5 py-2 bg-[#E07845] hover:bg-[#c96636] disabled:opacity-50 text-white text-xs font-black uppercase tracking-widest rounded-lg shadow-sm"
                    >
                        <i v-if="guardandoOs" class="fas fa-spinner fa-spin mr-1"></i>
                        Crear y emitir
                    </button>
                </footer>
            </div>
        </div>

        <!-- ================================================================
             MODAL: BITÁCORA DE MENSAJES
             ================================================================ -->
        <div v-if="ordenActiva" class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/50 px-4">
            <div class="bg-white rounded-2xl shadow-xl w-full max-w-lg overflow-hidden flex flex-col max-h-[80vh]">
                <header class="bg-slate-900 text-white px-5 py-3 flex items-center gap-2">
                    <i class="fas fa-message text-[#376875]"></i>
                    <h3 class="font-black text-sm tracking-tight">
                        Bitácora · {{ ordenActiva.numeroOs }}
                    </h3>
                    <button @click="ordenActiva = null" class="ml-auto text-slate-400 hover:text-white">
                        <i class="fas fa-xmark"></i>
                    </button>
                </header>

                <div class="flex-1 overflow-y-auto p-5 flex flex-col gap-3">
                    <p v-if="operacionStore.mensajesActivos.length === 0" class="text-xs text-slate-400 text-center py-6">
                        Todavía no se ha enviado nada a este proveedor.
                    </p>
                    <article
                        v-for="mensaje in operacionStore.mensajesActivos"
                        :key="mensaje.id ?? mensaje.createdAt"
                        class="border border-slate-200 rounded-xl p-3 bg-slate-50"
                    >
                        <p class="text-[10px] font-black uppercase tracking-widest text-slate-400 mb-1">
                            {{ mensaje.tipo }} · {{ formatearFecha(mensaje.createdAt) }}
                        </p>
                        <!--
                          Interpolación y no `v-html`, aunque el campo se llame `cuerpoHtml`:
                          lo que se guarda es el texto plano del textarea (ver enviarMensaje),
                          y el `whitespace-pre-wrap` de al lado ya delataba la intención. Con
                          `v-html` un `<` tecleado por el operador rompía el marcado, y pegar
                          algo desde el correo podía meter etiquetas en la bitácora.
                        -->
                        <div class="text-sm text-slate-700 whitespace-pre-wrap">{{ mensaje.cuerpoHtml }}</div>
                    </article>
                </div>

                <footer class="px-5 py-3 bg-slate-50 border-t border-slate-200 flex flex-col gap-2">
                    <textarea
                        v-model="cuerpoMensaje"
                        rows="3"
                        placeholder="Escribe la solicitud al proveedor…"
                        class="w-full bg-white border border-slate-200 rounded-lg px-3 py-2 text-sm text-slate-700 outline-none focus:ring-2 focus:ring-[#376875] resize-none"
                    ></textarea>
                    <div class="flex justify-end gap-2">
                        <button
                            @click="ordenActiva = null"
                            class="px-4 py-2 text-xs font-black uppercase tracking-widest text-slate-500 hover:text-slate-800"
                        >
                            Cerrar
                        </button>
                        <button
                            @click="enviarMensaje"
                            :disabled="enviandoMensaje || !cuerpoMensaje.trim()"
                            class="px-5 py-2 bg-[#376875] hover:bg-[#2d5660] disabled:opacity-40 text-white text-xs font-black uppercase tracking-widest rounded-lg shadow-sm"
                        >
                            <i v-if="enviandoMensaje" class="fas fa-spinner fa-spin mr-1"></i>
                            Registrar
                        </button>
                    </div>
                </footer>
            </div>
        </div>
    </div>

    <!-- ══ AGREGAR A UNA ORDEN EXISTENTE ═══════════════════════════════════════
         Lista sólo borradores compatibles. Cada fila enseña el NÚMERO y el
         COMPRADOR: sin el comprador delante, elegir entre «OS-014» y «OS-015» es
         adivinar, y equivocarse manda el encargo al proveedor que no era. -->
    <Transition name="fade-scale">
      <div v-if="mostrarModalAgregar" class="fixed inset-0 z-1400 bg-slate-900/60 backdrop-blur-sm flex items-center justify-center p-4"
           @click.self="mostrarModalAgregar = false">
        <div class="bg-white w-full max-w-lg rounded-2xl shadow-2xl overflow-hidden border border-slate-200">
          <div class="bg-[#376875] text-white px-5 py-4 flex justify-between items-center">
            <h3 class="font-black text-sm uppercase tracking-widest">
              <i class="fas fa-plus mr-2"></i>Agregar a una orden
            </h3>
            <button @click="mostrarModalAgregar = false" class="hover:opacity-70"><i class="fas fa-times"></i></button>
          </div>

          <div class="p-5 space-y-3">
            <p class="text-xs font-bold text-slate-500">
              Se agregarán <span class="text-[#376875]">{{ seleccionados.length }} servicio{{ seleccionados.length !== 1 ? 's' : '' }}</span>.
              Sólo aparecen las órdenes en borrador del mismo expediente y comprador.
            </p>

            <p v-if="errorAgregar" class="text-xs font-bold text-rose-600 bg-rose-50 border border-rose-200 rounded-lg px-3 py-2">
              <i class="fas fa-triangle-exclamation mr-1"></i>{{ errorAgregar }}
            </p>

            <div class="space-y-2 max-h-72 overflow-y-auto">
              <button
                v-for="o in ordenesParaAgregar" :key="o.id"
                @click="agregarAOrden(o.id)"
                :disabled="!!agregandoA"
                class="w-full text-left px-4 py-3 rounded-xl border-2 border-slate-200 hover:border-[#376875] hover:bg-[#376875]/5 disabled:opacity-40 transition-colors"
              >
                <div class="flex items-center justify-between gap-3">
                  <div class="min-w-0">
                    <p class="font-black text-sm text-slate-900">{{ o.numeroOs }}</p>
                    <p class="text-[11px] font-bold text-slate-500 truncate">
                      <i class="fas fa-handshake text-[9px] mr-1 text-slate-300"></i>{{ o.compradorNombre || 'Sin comprador' }}
                    </p>
                  </div>
                  <span class="shrink-0 text-[10px] font-black text-slate-400 uppercase tracking-widest">
                    <i v-if="agregandoA === o.id" class="fas fa-spinner fa-spin mr-1"></i>
                    {{ (o.operacionServicios?.length ?? 0) }} línea{{ (o.operacionServicios?.length ?? 0) !== 1 ? 's' : '' }}
                  </span>
                </div>
              </button>
            </div>
          </div>

          <div class="bg-slate-50 px-5 py-3 border-t border-slate-100 flex justify-end">
            <button @click="mostrarModalAgregar = false" class="px-4 py-2 text-xs font-bold text-slate-500 hover:text-slate-700">Cancelar</button>
          </div>
        </div>
      </div>
    </Transition>

    <!-- ══ FICHA DEL SERVICIO — sólo móvil ═════════════════════════════════════
         A pantalla completa: en 360 px un modal centrado deja media pantalla de
         fondo inútil y el teclado tapa el resto. Guardar y cancelar van ABAJO y
         fijos, que es donde llega el pulgar y donde no los tapa el teclado. -->
    <Transition name="fade-scale">
      <div v-if="servicioFicha" class="fixed inset-0 z-1400 bg-white flex flex-col md:hidden">
        <header class="bg-[#376875] text-white px-4 py-3 flex items-center gap-3 shrink-0">
          <button @click="servicioFicha = null" class="w-8 h-8 rounded-full bg-white/15 flex items-center justify-center">
            <i class="fas fa-times text-sm"></i>
          </button>
          <div class="min-w-0">
            <p class="font-black text-sm truncate">{{ nombreComponenteDe(servicioFicha) || servicioFicha.contextoServicio }}</p>
            <p class="text-[10px] font-bold text-white/70 truncate">{{ servicioFicha.descripcionServicio }}</p>
          </div>
        </header>

        <div class="flex-1 overflow-y-auto p-4 space-y-5">
          <!-- Contexto de sólo lectura: lo que identifica la fila y no se edita aquí. -->
          <div class="bg-slate-50 border border-slate-200 rounded-xl p-3 space-y-1.5">
            <p class="text-[11px] font-bold text-slate-500">
              <i class="far fa-calendar w-4 text-slate-400"></i>
              {{ servicioFicha.fechaServicio ? etiquetaDia(servicioFicha.fechaServicio.slice(0, 10)) : 'Sin fecha' }}
              <span v-if="servicioFicha.horaComponente" class="ml-1 tabular-nums">· vendida {{ servicioFicha.horaComponente }}</span>
            </p>
            <p class="text-[11px] font-bold text-slate-500 truncate">
              <i class="fas fa-folder w-4 text-slate-400"></i>{{ servicioFicha.file?.nombreGrupo || '—' }}
            </p>
            <p class="text-[11px] font-bold text-slate-500 truncate">
              <i class="fas fa-truck w-4 text-slate-400"></i>{{ servicioFicha.prestadorEfectivoNombre || '—' }}
              <span class="ml-1 text-slate-400">· {{ servicioFicha.cantidadPax }} pax</span>
            </p>
          </div>

          <div>
            <label class="block text-[10px] font-black text-slate-500 uppercase tracking-widest mb-1.5">Hora de recojo</label>
            <input
              v-model="borradorFicha.horaRecojo"
              :placeholder="servicioFicha.horaComponente || '--:--'"
              maxlength="5" inputmode="numeric"
              class="w-full text-sm font-black bg-slate-50 px-3 py-2.5 rounded-xl border border-slate-200 tabular-nums outline-none focus:ring-2 focus:ring-[#376875] focus:bg-white placeholder:text-slate-400 placeholder:font-bold"
            />
            <p class="text-[10px] font-bold text-slate-400 mt-1">Vacío = se usa la hora con la que se vendió.</p>
          </div>

          <div>
            <label class="block text-[10px] font-black text-slate-500 uppercase tracking-widest mb-1.5">Dónde se recoge</label>
            <input
              v-model="borradorFicha.puntoRecojo"
              :placeholder="puntosDe(servicioFicha)?.recojo || 'sin declarar en el catálogo'"
              maxlength="255"
              class="w-full text-xs font-bold bg-slate-50 px-3 py-2.5 rounded-xl border border-slate-200 outline-none focus:ring-2 focus:ring-[#376875] focus:bg-white placeholder:text-slate-500 placeholder:italic"
            />
          </div>

          <div v-if="puntosDe(servicioFicha)?.tieneEntrega || servicioFicha.puntoEntrega">
            <label class="block text-[10px] font-black text-slate-500 uppercase tracking-widest mb-1.5">Dónde se deja</label>
            <input
              v-model="borradorFicha.puntoEntrega"
              :placeholder="puntosDe(servicioFicha)?.entrega || 'sin declarar en el catálogo'"
              maxlength="255"
              class="w-full text-xs font-bold bg-slate-50 px-3 py-2.5 rounded-xl border border-slate-200 outline-none focus:ring-2 focus:ring-[#376875] focus:bg-white placeholder:text-slate-500 placeholder:italic"
            />
          </div>

          <p class="text-[10px] font-bold text-slate-400 -mt-3">Vacío = lo que diga el catálogo.</p>

          <div>
            <label class="block text-[10px] font-black text-slate-500 uppercase tracking-widest mb-1.5">Reserva con el proveedor</label>
            <select v-model="borradorFicha.estadoReservaProveedor"
                    class="w-full text-xs font-black px-3 py-2.5 rounded-xl border outline-none focus:ring-2 focus:ring-[#376875]"
                    :class="[getEstadoReservaProveedorConfig(borradorFicha.estadoReservaProveedor).bg,
                             getEstadoReservaProveedorConfig(borradorFicha.estadoReservaProveedor).text,
                             getEstadoReservaProveedorConfig(borradorFicha.estadoReservaProveedor).border]">
              <option v-for="(cfg, k) in ESTADO_RESERVA_PROVEEDOR_CONFIG" :key="k" :value="k">{{ cfg.label }}</option>
            </select>
          </div>

          <div>
            <label class="block text-[10px] font-black text-slate-500 uppercase tracking-widest mb-1.5">Operación</label>
            <select v-model="borradorFicha.estadoOperacion"
                    class="w-full text-xs font-black px-3 py-2.5 rounded-xl border outline-none focus:ring-2 focus:ring-[#376875]"
                    :class="[getEstadoOperacionConfig(borradorFicha.estadoOperacion).bg,
                             getEstadoOperacionConfig(borradorFicha.estadoOperacion).text,
                             getEstadoOperacionConfig(borradorFicha.estadoOperacion).border]">
              <option v-for="(cfg, k) in ESTADO_OPERACION_CONFIG" :key="k" :value="k">{{ cfg.label }}</option>
            </select>
          </div>

          <!-- El costo negociado trae su propio commit, así que se empotra tal cual en vez de
               duplicarlo en el borrador: dos formas de guardar el mismo importe acabarían
               discrepando. Una fila de referencia no se compra y no lo enseña. -->
          <div v-if="!servicioFicha.soloReferencia">
            <label class="block text-[10px] font-black text-slate-500 uppercase tracking-widest mb-1.5">Costo negociado</label>
            <EditorCostoNegociado
              :costo-cotizado="servicioFicha.costoCotizado"
              :desglose="servicioFicha.desgloseCotizado"
              :moneda-cotizada="servicioFicha.monedaCotizada?.id ?? ''"
              :costo-negociado="servicioFicha.costoNegociado"
              :moneda-negociada="servicioFicha.monedaNegociada?.id ?? null"
              :monedas="operacionStore.monedas"
              @guardar="(pl) => onGuardarCosto(servicioFicha!, pl)"
            />
          </div>

          <p v-if="errorFicha" class="text-xs font-bold text-rose-600 bg-rose-50 border border-rose-200 rounded-xl px-3 py-2">
            <i class="fas fa-triangle-exclamation mr-1"></i>{{ errorFicha }}
          </p>
        </div>

        <!-- Abajo y fijos: es donde llega el pulgar y donde no los tapa el teclado. -->
        <footer class="shrink-0 border-t border-slate-200 bg-white px-4 py-3 flex items-center gap-3">
          <button @click="servicioFicha = null" :disabled="guardandoFicha"
                  class="px-4 py-3 text-xs font-black uppercase tracking-widest text-slate-500 disabled:opacity-40">
            Cancelar
          </button>
          <button @click="guardarFicha" :disabled="guardandoFicha || !hayCambiosEnFicha"
                  class="flex-1 px-4 py-3 bg-[#E07845] hover:bg-[#c96636] disabled:opacity-40 text-white rounded-xl text-xs font-black uppercase tracking-widest shadow-sm">
            <i v-if="guardandoFicha" class="fas fa-spinner fa-spin mr-1"></i>
            {{ hayCambiosEnFicha ? 'Guardar cambios' : 'Sin cambios' }}
          </button>
        </footer>
      </div>
    </Transition>
</template>
