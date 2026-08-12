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
import { ref, onMounted, computed, watch } from 'vue';
import { useRouter } from 'vue-router';
import { useOperacionStore, type ExpedienteOpcion, type CotizacionOpcion } from '@/stores/operacion/operacionStore';
import AppSwitcher from '@/components/common/AppSwitcher.vue';
import FechaHoraPicker from '@/components/common/FechaHoraPicker.vue';
import {
    getEstadoOsConfig,
    getEstadoReservaConfig,
    getEstadoOperacionConfig,
    getTipoComponenteConfig,
    getModoComponenteConfig,
    getEstadoComponenteConfig,
    ESTADO_RESERVA_CONFIG,
    ESTADO_OPERACION_CONFIG,
    TIPOS_COMPONENTE,
    type FiltrosBiblia,
    type OperacionServicio,
    type OperacionOrdenServicio,
} from '@/types/operacionModel';

const router = useRouter();
const operacionStore = useOperacionStore();

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
const filtroEstadoReserva = ref<string>('');
const filtroEstadoOperacion = ref<string>('');
const mostrarFiltrosAvanzados = ref<boolean>(false);

// Expediente / cotización
const terminoExpediente = ref<string>('');
const resultadosExpediente = ref<ExpedienteOpcion[]>([]);
const expedienteSeleccionado = ref<ExpedienteOpcion | null>(null);
const cotizacionesDelExpediente = ref<CotizacionOpcion[]>([]);
const cotizacionSeleccionada = ref<string>('');
const buscandoExpediente = ref<boolean>(false);

const filtrosActivos = computed<FiltrosBiblia>(() => ({
    desde: desde.value ? desde.value.slice(0, 10) : undefined,
    hasta: hasta.value ? hasta.value.slice(0, 10) : undefined,
    fileId: expedienteSeleccionado.value?.id,
    cotizacionId: cotizacionSeleccionada.value || undefined,
    tipos: tiposSeleccionados.value.length ? tiposSeleccionados.value : undefined,
    estadoReserva: filtroEstadoReserva.value || undefined,
    estadoOperacion: filtroEstadoOperacion.value || undefined,
}));

/** El backend tiene más servicios en este rango de los que cabe pintar. */
const hayServiciosOcultos = computed(() =>
    operacionStore.totalServicios > operacionStore.servicios.length
);

const hayFiltrosExtra = computed(() =>
    tiposSeleccionados.value.length > 0
    || !!filtroEstadoReserva.value
    || !!filtroEstadoOperacion.value
    || !!expedienteSeleccionado.value
);

// ============================================================================
// CARGA
// ============================================================================
const cargarBiblia = async () => {
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
    filtroEstadoReserva.value = '';
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
// El backend ya ordena por fechaServicio y horaRecojoReal. Aquí sólo se parte en
// días y se empujan al final los servicios sin hora, ordenados por prioridad
// operativa (guiado/transporte antes que tickets): un cuadro de tráfico se lee
// de arriba abajo por hora, y lo que no tiene hora estorba en medio.
// ============================================================================
interface GrupoDia {
    fecha: string;
    servicios: OperacionServicio[];
}

const serviciosPorDia = computed<GrupoDia[]>(() => {
    const mapa = new Map<string, OperacionServicio[]>();

    for (const s of operacionStore.servicios) {
        const fecha = (s.fechaServicio ?? '').slice(0, 10) || 'sin-fecha';
        if (!mapa.has(fecha)) mapa.set(fecha, []);
        mapa.get(fecha)!.push(s);
    }

    return [...mapa.entries()]
        .sort(([a], [b]) => a.localeCompare(b))
        .map(([fecha, servicios]) => ({
            fecha,
            servicios: [...servicios].sort((a, b) => {
                const ha = a.horaRecojoReal || '';
                const hb = b.horaRecojoReal || '';
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

const editarHora = async (servicio: OperacionServicio, evento: Event) => {
    const input = evento.target as HTMLInputElement;
    const valor = input.value.trim();

    if (valor === '') {
        await guardarCampo(servicio, { horaRecojoReal: null });
        return;
    }
    if (!PATRON_HORA.test(valor)) {
        input.value = servicio.horaRecojoReal ?? '';
        return;
    }
    await guardarCampo(servicio, { horaRecojoReal: valor });
};

/**
 * Proveedor COMERCIAL: a quién se le compra. Sólo importa para la Orden de Servicio
 * —`conflictoSeleccion` agrupa por él— así que se edita en segundo plano.
 */
const editarProveedor = async (servicio: OperacionServicio, evento: Event) => {
    const valor = (evento.target as HTMLInputElement).value.trim();
    if (valor === (servicio.proveedorNombreManual ?? '')) return;
    await guardarCampo(servicio, { proveedorNombreManual: valor || null });
};

/**
 * PRESTADOR: quién opera y dónde se recoge. Es el dato que el cuadro de tráfico lee
 * de un vistazo, y por eso manda en la celda por encima del proveedor comercial.
 *
 * Viene resuelto del snapshot (componente → día → proveedor de la tarifa), así que en
 * el caso normal ya trae el mismo nombre que el comercial y no hay nada que escribir.
 * En una fila de referencia es el ÚNICO de los dos que existe: el hotel que reservó
 * el pasajero. Ver docs/Operacion.md §3.3.b.
 */
const editarPrestador = async (servicio: OperacionServicio, evento: Event) => {
    const valor = (evento.target as HTMLInputElement).value.trim();
    if (valor === (servicio.prestadorNombre ?? '')) return;
    await guardarCampo(servicio, { prestadorNombre: valor || null });
};

/**
 * El proveedor comercial se oculta SÓLO cuando es redundante: cuando ya dice
 * exactamente lo mismo que el prestador. En la mayoría de filas ambos heredan de la
 * misma tarifa y repetirlo en cada línea convertiría el dato en ruido.
 *
 * ⚠️ Vacío NO es redundante, es pendiente. La primera versión de esta función pedía
 * además que el comercial no estuviera vacío, y como los dos campos salen de la misma
 * tarifa, cuando esa tarifa no tiene proveedor **los dos** nacen nulos: el input no se
 * pintaba nunca y el campo quedaba fuera del alcance del operador. Justo el caso más
 * común, y justo el que hace falta para poder agrupar una Orden de Servicio.
 */
const mostrarComercial = (s: OperacionServicio): boolean => {
    if (s.soloReferencia) return false;   // no se compra: no hay proveedor que fijar

    const comercial = (s.proveedorNombreManual ?? '').trim();
    return comercial === '' || comercial !== (s.prestadorNombre ?? '').trim();
};

/** Teléfono en formato marcable: el operador llama desde el propio cuadro. */
const telHref = (telefono?: string | null): string => `tel:${(telefono ?? '').replace(/[^\d+]/g, '')}`;

// ============================================================================
// COSTO REAL — lo que de verdad se pagó, frente a lo que decía la cotización
//
// `costoCotizado` viene del snapshot y lo gobierna la cotización: la reconciliación
// puede cambiarlo. `costoRealOperativo` es del operador y **nadie más lo toca** —
// ni el snapshot ni la reconciliación—, porque es el único sitio donde vive lo que
// el proveedor acabó cobrando. El delta entre ambos es el margen operativo.
//
// La moneda no se edita aquí: nace igual a la cotizada y cambiarla desde una celda
// de tabla, sin conversión ni tipo de cambio, sería invitar a mezclar divisas en la
// misma columna. Si hay que cambiarla, es un caso raro que merece su propio flujo.
// ============================================================================
const PATRON_IMPORTE = /^\d{1,10}([.,]\d{1,2})?$/;

const editarCostoReal = async (servicio: OperacionServicio, evento: Event) => {
    const input = evento.target as HTMLInputElement;
    const valor = input.value.trim().replace(',', '.');

    if (valor === '') {
        await guardarCampo(servicio, { costoRealOperativo: '0.00' });
        return;
    }
    if (!PATRON_IMPORTE.test(input.value.trim())) {
        input.value = servicio.costoRealOperativo ?? '';
        return;
    }
    await guardarCampo(servicio, { costoRealOperativo: Number(valor).toFixed(2) });
};

/** Diferencia real − cotizado. Positiva = costó más de lo previsto. */
const deltaOperativo = (s: OperacionServicio): number | null => {
    const real = Number(s.costoRealOperativo ?? 0);
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

    const proveedores = new Set(sel.map(s => s.proveedorNombreManual ?? ''));
    if (proveedores.size > 1) return 'Los servicios seleccionados son de proveedores distintos.';

    const monedas = new Set(sel.map(s => s.monedaCotizada?.id ?? ''));
    if (monedas.size > 1) return 'Los servicios seleccionados están en monedas distintas.';

    if (sel.some(s => s.ordenServicio)) return 'Algún servicio ya pertenece a una Orden de Servicio.';

    return null;
});

const mostrarModalOs = ref<boolean>(false);
const guardandoOs = ref<boolean>(false);
const errorOs = ref<string | null>(null);
const formOs = ref({ numeroOs: '', proveedorNombreManual: '', totalOs: '0.00', monedaId: '' });

const abrirModalOs = () => {
    const sel = serviciosSeleccionados.value;
    if (sel.length === 0 || conflictoSeleccion.value) return;

    const total = sel.reduce((acc, s) => acc + Number(s.costoCotizado ?? 0), 0);
    const hoy = hoyIso().replace(/-/g, '');

    formOs.value = {
        // Sugerencia: numeroOs es unique y no tiene generador en el backend.
        numeroOs: `OS-${hoy}-${String(Math.floor(Math.random() * 900) + 100)}`,
        proveedorNombreManual: sel[0].proveedorNombreManual ?? '',
        totalOs: total.toFixed(2),
        monedaId: sel[0].monedaCotizada?.id ?? '',
    };
    errorOs.value = null;
    mostrarModalOs.value = true;
};

const confirmarOs = async () => {
    const sel = serviciosSeleccionados.value;
    if (sel.length === 0) return;

    const fileId = sel[0].file?.id;
    if (!fileId || !formOs.value.monedaId) {
        errorOs.value = 'Faltan el expediente o la moneda del servicio; revisa el snapshot.';
        return;
    }

    guardandoOs.value = true;
    errorOs.value = null;
    try {
        await operacionStore.crearOrdenServicio(
            {
                numeroOs: formOs.value.numeroOs,
                file: `/platform/sales/cotizacion_files/${fileId}`,
                proveedorMaestroId: sel[0].proveedorMaestroId ?? null,
                proveedorNombreManual: formOs.value.proveedorNombreManual || null,
                estadoOs: 'borrador',
                monedaOs: `/platform/maestro/monedas/${formOs.value.monedaId}`,
                totalOs: formOs.value.totalOs,
            },
            sel.map(s => s.id!).filter(Boolean)
        );
        mostrarModalOs.value = false;
        seleccionados.value = [];
        await cargarBiblia();
    } catch {
        errorOs.value = 'No se pudo crear la Orden de Servicio. Revisa que el número no esté repetido.';
    } finally {
        guardandoOs.value = false;
    }
};

// ============================================================================
// BITÁCORA DE MENSAJES DE UNA OS
// ============================================================================
const ordenActiva = ref<OperacionOrdenServicio | null>(null);
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

onMounted(cargarBiblia);
</script>

<template>
    <div class="h-screen bg-[#F8FAFC] flex flex-col font-sans overflow-hidden">

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
                <div class="sticky top-0 z-10 bg-[#F8FAFC]/95 backdrop-blur-sm border-b border-slate-200 px-4 md:px-6 py-3 shrink-0">

                    <!-- Fila 1: rango + presets + acciones -->
                    <div class="flex flex-wrap items-center gap-2">
                        <div class="grid gap-2 shrink-0" style="grid-template-columns: repeat(auto-fit, minmax(11rem, 1fr))">
                            <label class="flex flex-col gap-1">
                                <span class="text-[9px] font-black text-slate-400 uppercase tracking-widest">Desde</span>
                                <FechaHoraPicker
                                    :model-value="desde"
                                    solo-fecha
                                    @update:model-value="(v: string) => { desde = v; if (hasta < v) hasta = v; cargarBiblia(); }"
                                />
                            </label>
                            <label class="flex flex-col gap-1">
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

                    <!-- Fila 2: filtros avanzados -->
                    <div v-if="mostrarFiltrosAvanzados" class="mt-3 pt-3 border-t border-slate-200 flex flex-col gap-3">

                        <!-- Expediente y cotización -->
                        <div class="flex flex-wrap items-end gap-2">
                            <div class="relative flex flex-col gap-1 min-w-[16rem]">
                                <span class="text-[9px] font-black text-slate-400 uppercase tracking-widest">Expediente</span>

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
                                    v-model="filtroEstadoReserva"
                                    @change="cargarBiblia"
                                    class="bg-white border border-slate-200 rounded-lg px-3 py-2 text-sm font-medium text-slate-700 outline-none focus:ring-2 focus:ring-[#376875] shadow-sm"
                                >
                                    <option value="">Cualquiera</option>
                                    <option v-for="(cfg, k) in ESTADO_RESERVA_CONFIG" :key="k" :value="k">{{ cfg.label }}</option>
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
                                :class="tiposSeleccionados.includes(t.value)
                                    ? 'bg-slate-900 text-white border-slate-900'
                                    : 'bg-white text-slate-500 border-slate-200 hover:border-slate-400'"
                                class="flex items-center gap-1.5 px-2.5 py-1 border rounded-lg text-[10px] font-black uppercase tracking-wider transition-colors"
                            >
                                <i :class="[t.icon, 'text-[10px]']"></i>
                                {{ t.label }}
                            </button>
                        </div>
                    </div>

                    <!-- Fila 3: contador y selección -->
                    <div class="mt-2 flex items-center gap-3">
                        <span class="text-[10px] font-black text-slate-400 uppercase tracking-widest">
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
                                            <th class="px-3 py-3 text-[10px] font-black text-slate-500 uppercase tracking-widest hidden lg:table-cell">Expediente</th>
                                            <th class="px-3 py-3 text-[10px] font-black text-slate-500 uppercase tracking-widest hidden sm:table-cell">Pax</th>
                                            <th class="px-3 py-3 text-[10px] font-black text-slate-500 uppercase tracking-widest hidden md:table-cell" title="Quién opera el servicio y dónde se recoge. Debajo, a quién se le compra cuando no es el mismo.">Prestador</th>
                                            <th class="px-3 py-3 text-[10px] font-black text-slate-500 uppercase tracking-widest hidden xl:table-cell text-right" title="Cotizado (de la cotización) frente a real (lo que se pagó). El delta es el margen operativo.">Costo</th>
                                            <th class="px-3 py-3 text-[10px] font-black text-slate-500 uppercase tracking-widest">Reserva</th>
                                            <th class="px-3 py-3 text-[10px] font-black text-slate-500 uppercase tracking-widest hidden sm:table-cell">Operación</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-slate-100">
                                        <tr
                                            v-for="servicio in grupo.servicios"
                                            :key="servicio.id"
                                            :class="[
                                                seleccionados.includes(servicio.id ?? '') ? 'bg-[#376875]/5' : '',
                                                servicio.estadoComponente === 'cancelado' || servicio.modoComponente === 'reemplazado' ? 'opacity-55' : '',
                                            ]"
                                            class="hover:bg-slate-50/80 transition-colors"
                                        >
                                            <!-- Selección: las filas de referencia no se
                                                 marcan porque no pueden ir a una OS. -->
                                            <td class="px-3 py-3 align-top">
                                                <input
                                                    v-if="esComprable(servicio)"
                                                    type="checkbox"
                                                    :checked="seleccionados.includes(servicio.id ?? '')"
                                                    @change="alternarSeleccion(servicio.id)"
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

                                            <!-- Hora editable -->
                                            <td class="px-3 py-3 whitespace-nowrap align-top">
                                                <input
                                                    :value="servicio.horaRecojoReal ?? ''"
                                                    @change="editarHora(servicio, $event)"
                                                    placeholder="--:--"
                                                    maxlength="5"
                                                    class="w-[4.5rem] text-sm font-black text-slate-900 bg-slate-100 px-2 py-1 rounded-lg border border-slate-200 tabular-nums text-center outline-none focus:ring-2 focus:ring-[#376875] focus:bg-white"
                                                />
                                            </td>

                                            <!-- Servicio -->
                                            <td class="px-3 py-3 align-top">
                                                <div class="flex items-start gap-2">
                                                    <i
                                                        :class="[getTipoComponenteConfig(servicio.tipoComponente).icon, getTipoComponenteConfig(servicio.tipoComponente).text, 'mt-0.5 text-sm w-4 text-center']"
                                                        :title="getTipoComponenteConfig(servicio.tipoComponente).label"
                                                    ></i>
                                                    <div class="min-w-0">
                                                        <p class="text-sm font-black text-slate-800 leading-tight">
                                                            {{ servicio.descripcionServicio }}
                                                        </p>
                                                        <p v-if="servicio.contextoServicio" class="text-[10px] font-bold text-slate-400 mt-0.5 truncate">
                                                            <i class="fas fa-diagram-project mr-1"></i>{{ servicio.contextoServicio }}
                                                        </p>

                                                        <!-- Badges de clasificación: sólo cuando dicen algo -->
                                                        <div class="flex flex-wrap gap-1 mt-1">
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

                                                        <!-- Contexto compacto en móvil -->
                                                        <p class="text-[10px] font-bold text-slate-400 mt-1 lg:hidden">
                                                            <i class="fas fa-folder-open mr-1"></i>{{ servicio.file?.nombreGrupo || 'Sin expediente' }}
                                                        </p>
                                                        <!-- En móvil la columna del prestador está oculta, así
                                                             que el nombre y el teléfono del recojo se repiten
                                                             aquí: son el dato que se consulta a pie de calle. -->
                                                        <p class="text-[10px] font-bold text-slate-400 mt-0.5 md:hidden">
                                                            <i class="fas fa-user mr-1"></i>{{ servicio.prestadorNombre || (servicio.soloReferencia ? 'Referencia' : 'Por asignar') }}
                                                        </p>
                                                        <a
                                                            v-if="servicio.prestadorTelefono"
                                                            :href="telHref(servicio.prestadorTelefono)"
                                                            class="text-[10px] font-bold text-[#376875] mt-0.5 md:hidden flex items-center gap-1"
                                                        >
                                                            <i class="fas fa-phone text-[9px]"></i>
                                                            <span class="tabular-nums">{{ servicio.prestadorTelefono }}</span>
                                                        </a>
                                                    </div>
                                                </div>
                                            </td>

                                            <!-- Expediente -->
                                            <td class="px-3 py-3 hidden lg:table-cell align-top">
                                                <p class="text-sm font-bold text-slate-700 truncate max-w-[12rem]">
                                                    {{ servicio.file?.nombreGrupo || '—' }}
                                                </p>
                                                <p v-if="servicio.file?.pasajeroPrincipal" class="text-[10px] font-bold text-slate-400 truncate max-w-[12rem]">
                                                    {{ servicio.file.pasajeroPrincipal }}
                                                </p>
                                            </td>

                                            <!-- Pax -->
                                            <td class="px-3 py-3 hidden sm:table-cell whitespace-nowrap align-top">
                                                <span class="text-xs font-black text-slate-600 bg-slate-100 px-2 py-1 rounded-lg border border-slate-200">
                                                    <i class="fas fa-users text-slate-400 mr-1"></i>{{ servicio.cantidadPax }}
                                                </span>
                                            </td>

                                            <!-- Prestador (quién opera / dónde se recoge) y, debajo, el
                                                 proveedor comercial sólo si difiere. Ver docs/Operacion.md §3.3.b -->
                                            <td class="px-3 py-3 hidden md:table-cell align-top">
                                                <input
                                                    :value="servicio.prestadorNombre ?? ''"
                                                    @change="editarPrestador(servicio, $event)"
                                                    :placeholder="servicio.soloReferencia ? 'Referencia' : 'Por asignar'"
                                                    class="w-full max-w-[11rem] text-sm font-bold text-slate-700 bg-transparent px-2 py-1 rounded-lg border border-transparent hover:border-slate-200 outline-none focus:ring-2 focus:ring-[#376875] focus:bg-white placeholder:text-slate-300 placeholder:font-medium"
                                                />

                                                <!-- Los datos del recojo: es para lo que existe la fila
                                                     de referencia. El teléfono se marca desde aquí. -->
                                                <a
                                                    v-if="servicio.prestadorTelefono"
                                                    :href="telHref(servicio.prestadorTelefono)"
                                                    class="mt-1 ml-2 flex items-center gap-1.5 text-[10px] font-bold text-slate-500 hover:text-[#376875] transition-colors"
                                                >
                                                    <i class="fas fa-phone text-slate-300 text-[9px]"></i>
                                                    <span class="tabular-nums">{{ servicio.prestadorTelefono }}</span>
                                                </a>
                                                <p
                                                    v-if="servicio.prestadorDireccion"
                                                    class="mt-0.5 ml-2 flex items-start gap-1.5 text-[10px] font-medium text-slate-400 max-w-[11rem]"
                                                    :title="servicio.prestadorDireccion"
                                                >
                                                    <i class="fas fa-location-dot text-slate-300 text-[9px] mt-0.5 shrink-0"></i>
                                                    <span class="truncate">{{ servicio.prestadorDireccion }}</span>
                                                </p>

                                                <!-- A quién se le compra. Se edita porque es lo que agrupa
                                                     la OS: sin poder corregirlo aquí, dos filas del mismo
                                                     proveedor escrito distinto no se pueden juntar nunca. -->
                                                <label v-if="mostrarComercial(servicio)" class="mt-1.5 flex items-center gap-1.5">
                                                    <span class="text-[9px] font-black text-slate-300 uppercase tracking-wider shrink-0" title="Proveedor comercial: a quién se le compra">
                                                        Compra
                                                    </span>
                                                    <input
                                                        :value="servicio.proveedorNombreManual ?? ''"
                                                        @change="editarProveedor(servicio, $event)"
                                                        placeholder="Sin definir"
                                                        class="w-full max-w-[8rem] text-[10px] font-bold text-slate-400 bg-transparent px-1 py-0.5 rounded border border-transparent hover:border-slate-200 outline-none focus:ring-1 focus:ring-[#376875] focus:bg-white focus:text-slate-700 placeholder:text-slate-300 placeholder:font-medium"
                                                    />
                                                </label>
                                            </td>

                                            <!-- Costo: cotizado (solo lectura) vs real (editable) -->
                                            <td class="px-3 py-3 hidden xl:table-cell align-top text-right whitespace-nowrap">
                                                <p class="text-[10px] font-bold text-slate-400 tabular-nums">
                                                    <span class="text-slate-300 mr-1">{{ servicio.monedaCotizada?.id || '' }}</span>{{ importe(servicio.costoCotizado) }}
                                                </p>

                                                <!-- Una fila de referencia no se compra: no hay costo real que
                                                     registrar, y ofrecer el campo invitaría a inventarlo. -->
                                                <template v-if="!servicio.soloReferencia">
                                                    <input
                                                        :value="Number(servicio.costoRealOperativo ?? 0) === 0 ? '' : importe(servicio.costoRealOperativo)"
                                                        @change="editarCostoReal(servicio, $event)"
                                                        placeholder="real"
                                                        inputmode="decimal"
                                                        maxlength="13"
                                                        class="mt-1 w-[5.5rem] text-sm font-black text-slate-800 bg-slate-100 px-2 py-1 rounded-lg border border-slate-200 tabular-nums text-right outline-none focus:ring-2 focus:ring-[#376875] focus:bg-white placeholder:text-slate-300 placeholder:font-medium"
                                                    />
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
                                            <td class="px-3 py-3 whitespace-nowrap align-top">
                                                <select
                                                    :value="servicio.estadoReserva"
                                                    @change="guardarCampo(servicio, { estadoReserva: ($event.target as HTMLSelectElement).value })"
                                                    :disabled="guardando === servicio.id"
                                                    :class="['px-2 py-1 text-[10px] font-black rounded-lg border cursor-pointer outline-none appearance-none', getEstadoReservaConfig(servicio.estadoReserva).bg, getEstadoReservaConfig(servicio.estadoReserva).text, getEstadoReservaConfig(servicio.estadoReserva).border]"
                                                >
                                                    <option v-for="(cfg, k) in ESTADO_RESERVA_CONFIG" :key="k" :value="k">{{ cfg.label }}</option>
                                                </select>
                                            </td>

                                            <!-- Estado operación editable -->
                                            <td class="px-3 py-3 hidden sm:table-cell whitespace-nowrap align-top">
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

                <div v-else class="px-4 md:px-6 py-4">
                    <div class="bg-white rounded-2xl border border-slate-200 shadow-sm overflow-hidden">
                        <div class="overflow-x-auto">
                            <table class="w-full text-left border-collapse">
                                <thead>
                                    <tr class="bg-slate-50 border-b border-slate-200">
                                        <th class="px-4 py-3 text-[10px] font-black text-slate-500 uppercase tracking-widest">OS #</th>
                                        <th class="px-4 py-3 text-[10px] font-black text-slate-500 uppercase tracking-widest">Proveedor</th>
                                        <th class="px-4 py-3 text-[10px] font-black text-slate-500 uppercase tracking-widest hidden sm:table-cell">Total</th>
                                        <th class="px-4 py-3 text-[10px] font-black text-slate-500 uppercase tracking-widest">Estado</th>
                                        <th class="px-4 py-3 text-[10px] font-black text-slate-500 uppercase tracking-widest text-right">Acciones</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-slate-100">
                                    <tr
                                        v-for="orden in operacionStore.ordenesServicio"
                                        :key="orden.id"
                                        class="hover:bg-slate-50/80 transition-colors"
                                    >
                                        <td class="px-4 py-4 whitespace-nowrap align-top">
                                            <span class="text-sm font-black text-[#376875]">{{ orden.numeroOs }}</span>
                                        </td>

                                        <td class="px-4 py-4 align-top">
                                            <p class="text-sm font-bold text-slate-800">
                                                {{ orden.proveedorNombreManual || 'No definido' }}
                                            </p>
                                            <p class="text-[10px] font-black text-slate-400 mt-0.5 sm:hidden">
                                                <span class="text-slate-300">{{ orden.monedaOs?.id || 'USD' }}</span>
                                                {{ orden.totalOs }}
                                            </p>
                                        </td>

                                        <td class="px-4 py-4 hidden sm:table-cell whitespace-nowrap align-top">
                                            <span class="text-sm font-black text-slate-800">
                                                <span class="text-[10px] font-bold text-slate-400 mr-1">{{ orden.monedaOs?.id || 'USD' }}</span>{{ orden.totalOs }}
                                            </span>
                                        </td>

                                        <td class="px-4 py-4 whitespace-nowrap align-top">
                                            <span
                                                :class="['px-2 py-1 inline-flex items-center gap-1 text-[10px] font-black rounded-lg border', getEstadoOsConfig(orden.estadoOs).bg, getEstadoOsConfig(orden.estadoOs).text, getEstadoOsConfig(orden.estadoOs).border]"
                                            >
                                                <i :class="['fas text-[9px]', getEstadoOsConfig(orden.estadoOs).icon]"></i>
                                                {{ getEstadoOsConfig(orden.estadoOs).label }}
                                            </span>
                                        </td>

                                        <td class="px-4 py-4 text-right align-top">
                                            <button
                                                @click="abrirMensajes(orden)"
                                                class="inline-flex items-center gap-1.5 px-3 py-1.5 bg-slate-100 hover:bg-[#376875] hover:text-white hover:border-[#376875] text-slate-600 text-[10px] font-black uppercase tracking-widest rounded-lg border border-slate-200 transition-all shadow-sm"
                                            >
                                                <i class="fas fa-message text-[9px]"></i>
                                                <span class="hidden sm:inline">Mensajes</span>
                                            </button>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </section>

        </main>

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

                    <label class="flex flex-col gap-1">
                        <span class="text-[9px] font-black text-slate-400 uppercase tracking-widest">Número de OS</span>
                        <input
                            v-model="formOs.numeroOs"
                            class="bg-white border border-slate-200 rounded-lg px-3 py-2 text-sm font-bold text-slate-700 outline-none focus:ring-2 focus:ring-[#376875]"
                        />
                    </label>

                    <label class="flex flex-col gap-1">
                        <span class="text-[9px] font-black text-slate-400 uppercase tracking-widest">Proveedor</span>
                        <input
                            v-model="formOs.proveedorNombreManual"
                            placeholder="Nombre del proveedor"
                            class="bg-white border border-slate-200 rounded-lg px-3 py-2 text-sm font-bold text-slate-700 outline-none focus:ring-2 focus:ring-[#376875]"
                        />
                    </label>

                    <label class="flex flex-col gap-1">
                        <span class="text-[9px] font-black text-slate-400 uppercase tracking-widest">
                            Total ({{ formOs.monedaId || '—' }})
                        </span>
                        <input
                            v-model="formOs.totalOs"
                            class="bg-white border border-slate-200 rounded-lg px-3 py-2 text-sm font-black text-slate-700 tabular-nums outline-none focus:ring-2 focus:ring-[#376875]"
                        />
                        <span class="text-[10px] text-slate-400">Suma de los costos cotizados; ajústalo si negociaste otro precio.</span>
                    </label>

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
                    <button
                        @click="confirmarOs"
                        :disabled="guardandoOs"
                        class="px-5 py-2 bg-[#E07845] hover:bg-[#c96636] disabled:opacity-50 text-white text-xs font-black uppercase tracking-widest rounded-lg shadow-sm"
                    >
                        <i v-if="guardandoOs" class="fas fa-spinner fa-spin mr-1"></i>
                        Crear
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
                        <div class="text-sm text-slate-700 whitespace-pre-wrap" v-html="mensaje.cuerpoHtml"></div>
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
</template>
