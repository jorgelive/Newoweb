<script setup lang="ts">
import { ref, computed, shallowRef, watch, onBeforeUnmount } from 'vue';
import { useRoute, onBeforeRouteLeave } from 'vue-router';
import FullCalendar from '@fullcalendar/vue3';
import resourceTimelinePlugin from '@fullcalendar/resource-timeline';
import interactionPlugin from '@fullcalendar/interaction';
import esLocale from '@fullcalendar/core/locales/es';
import type {
    CalendarOptions,
    DateSelectArg,
    DatesSetArg,
    EventClickArg,
    EventContentArg,
    EventDropArg,
    EventMountArg,
    EventSourceFuncArg,
    EventInput,
} from '@fullcalendar/core';
import type { DateClickArg, EventResizeDoneArg } from '@fullcalendar/interaction';
import type { ResourceFuncArg, ResourceInput, ResourceLabelContentArg } from '@fullcalendar/resource';
import tippy from 'tippy.js';
import type { ReferenceElement } from 'tippy.js';
import 'tippy.js/dist/tippy.css';
import '@/assets/fullcalendar-overrides.css';
import { apiClient } from '@/services/apiClient';
import { escaparHtml } from '@/utils/html';
import { encuadrarTimeline, scrollTimelineADia, hoyLocal } from '@/utils/calendarTimeline';
import {
    coleccionFeed,
    comoError,
    type CalendarEventoFeed,
    type CalendarRecursoFeed,
} from '@/types/calendarFeedModel';
import { extractApiErrorMessage } from '@/stores/reservas/reservasStore';
import { useTarifasStore } from '@/stores/tarifas/tarifasStore';
import AppSwitcher from '@/components/common/AppSwitcher.vue';
import TarifaEditDrawer from '@/components/tarifas/TarifaEditDrawer.vue';
import TarifaMasivaDrawer from '@/components/tarifas/TarifaMasivaDrawer.vue';
import {
    PMS_TARIFA_CALENDARIOS,
    PMS_OCUPACION_CALENDARIO_KEY,
    COLOR_OCUPADO,
    fromDateLocal,
    sumarDias,
    colorCasita,
    pmsUnidadIri,
    type PmsTarifaExtendedProps,
} from '@/types/pmsTarifaModel';
import type { PmsEventoExtendedProps } from '@/types/pmsReservaModel';
import { useRefrescoDelAsistente } from '@/composables/useRefrescoDelAsistente';

const route = useRoute();
const tarifasStore = useTarifasStore();

// ============================================================================
// SELECTOR DE CALENDARIO (las dos vistas del legacy EasyAdmin: "Todas" y
// "Compactados", ahora contra los providers *_spa — ver pmsTarifaModel.ts)
// ============================================================================
const calendarioIndex = ref(0);
const calendarioActual = computed(() => PMS_TARIFA_CALENDARIOS[calendarioIndex.value]);

const calendarApiRef = shallowRef<InstanceType<typeof FullCalendar> | null>(null);

// Contenedor del calendario: lo necesita encuadrarTimeline() para alcanzar el
// scroller del cuerpo cuando hay que irse al extremo derecho del rango.
const calendarRootEl = ref<HTMLElement | null>(null);

// Título del mes/rango renderizado FUERA del headerToolbar: mismo motivo que en
// ReservasView (si comparte fila con los botones, en mobile todo se parte).
const calendarTitulo = ref('');

/** ¿Se está recargando ahora mismo? Señal visible para el botón ↻. */
const recargando = ref(false);

/**
 * Recarga manual del rango visible. Mismo motivo que en ReservasView: FullCalendar
 * sólo vuelve a pedir al cambiar de rango, así que un cambio hecho desde otro equipo
 * no aparece hasta navegar de mes. Con la pestaña abierta todo el día, eso es estar
 * mirando precios viejos sin enterarse.
 */
async function recargarCalendario(): Promise<void> {
    if (recargando.value) return;

    recargando.value = true;
    refrescarCalendario();
    await new Promise((r) => setTimeout(r, 600));
    recargando.value = false;
}

// `ajustar_tarifas` cambia precios: sin esto el operador sigue mirando los viejos, que es
// exactamente el fallo que el docblock de esta función ya avisa.
useRefrescoDelAsistente(() => { void recargarCalendario(); });

/**
 * Gira el icono mientras recarga.
 *
 * Se toca el DOM a mano porque el botón lo pinta FullCalendar desde `customButtons`,
 * fuera del árbol de Vue: no hay forma de bindearle una clase desde el template. Es
 * la señal de que el clic hizo algo — sin ella, pulsar y que nada cambie en pantalla
 * se lee como que el botón está roto.
 */
watch(recargando, (activo) => {
    document.querySelector('.fc-recargar-button')?.classList.toggle('esta-recargando', activo);
});

function refrescarCalendario(): void {
    // `refetchResources()` lo aporta @fullcalendar/resource, que amplía CalendarApi
    // vía `declare module`: está tipado mientras el plugin se importe en el archivo.
    const api = calendarApiRef.value?.getApi();
    if (!api) return;
    api.refetchResources();
    api.refetchEvents();
}

// ============================================================================
// PERSISTENCIA DE FECHA/VISTA (mismo esquema de claves que ReservasView, con su
// propio pathname: cada calendario recuerda dónde se quedó por separado)
// ============================================================================
const FC_STORAGE_KEY = `fc_state_${window.location.pathname}`;
const FC_VISTAS_PERMITIDAS = ['resourceTimelineTwoMonths', 'resourceTimelineOneMonth'];

function fcVistaGuardada(): string {
    const v = localStorage.getItem(`${FC_STORAGE_KEY}_view`);
    return v && FC_VISTAS_PERMITIDAS.includes(v) ? v : 'resourceTimelineTwoMonths';
}

/** `?fecha=YYYY-MM-DD` con el que se llega desde Reservas («Editar tarifas»). */
function fechaDeQuery(): string | null {
    const f = typeof route.query.fecha === 'string' ? route.query.fecha : null;
    return f && /^\d{4}-\d{2}-\d{2}$/.test(f) ? f : null;
}

function fcFechaGuardada(): string | undefined {
    // La fecha de la query gana sobre lo guardado: es como llega el usuario desde
    // el calendario de Reservas ("Ver tarifas de este día").
    return fechaDeQuery() ?? localStorage.getItem(`${FC_STORAGE_KEY}_date`) ?? undefined;
}

/**
 * Día al que hay que desplazarse en cuanto el rango esté montado; gana al
 * encuadre por defecto de `datesSet`. Dos usos:
 *
 * - Arranque con `?fecha=`: `initialDate` coloca el RANGO en esa fecha, pero el
 *   scroll horizontal seguiría donde lo dejara el encuadre.
 * - Botón «Hoy»: sin esto, el encuadre del rango nuevo (que manda por dirección
 *   de navegación) se llevaría el scroll al extremo contrario.
 *
 * Mismo mecanismo que `diaPendiente` en ReservasView.
 */
let diaPendiente: string | null = fechaDeQuery();

/** Resuelve el salto pendiente, si el día ya está dentro del rango montado. */
function resolverDiaPendiente(): void {
    const api = calendarApiRef.value?.getApi();
    if (!api || !diaPendiente) return;

    const [y, m, d] = diaPendiente.split('-').map(Number);
    const objetivo = new Date(y, m - 1, d);
    diaPendiente = null;
    requestAnimationFrame(() => scrollTimelineADia(api, objetivo));
}

// ============================================================================
// COLOR POR CASITA
// El `orden` lo calcula el provider al ordenar los recursos; se cachea al
// cargarlos para poder teñir los eventos en cuanto llegan (FullCalendar siempre
// pide recursos antes que eventos, pero colorCasita() tiene fallback por hash
// para no depender de ese orden).
// ============================================================================
const ordenPorUnidad = new Map<string, number>();

function recordarOrden(recursos: CalendarRecursoFeed[]): CalendarRecursoFeed[] {
    for (const r of recursos) {
        if (typeof r.orden === 'number') {
            ordenPorUnidad.set(String(r.id), r.orden);
        }
    }
    return recursos;
}

type TarifaEventoFeed = CalendarEventoFeed<PmsTarifaExtendedProps>;

function pintarPorCasita(eventos: TarifaEventoFeed[]): TarifaEventoFeed[] {
    return eventos.map((ev) => {
        // El provider solo fija color para los rangos INACTIVOS (gris): ese
        // color sí es semántico y no se pisa.
        if (ev.backgroundColor) return ev;

        const unidadId = String(ev.resourceId ?? '');
        const color = colorCasita(unidadId, ordenPorUnidad.get(unidadId));
        return { ...ev, backgroundColor: color.bg, borderColor: color.border };
    });
}

// ============================================================================
// OCUPACIÓN DE FONDO
//
// Segunda fuente de eventos: las estancias del calendario de Reservas, pintadas
// como eventos de FONDO (beige) detrás de las barras de precio. Es lo que
// permite programar tarifas mirando los huecos libres en vez de a ciegas.
//
// Los eventos de fondo de FullCalendar no son clicables ni arrastrables y no
// interceptan el `dateClick`: el flujo de «crear tarifa aquí» sigue funcionando
// igual sobre un día ocupado. Ver el guardado por `display` en eventContent,
// eventDidMount y onEventClick.
// ============================================================================
type OcupacionEventoFeed = CalendarEventoFeed<PmsEventoExtendedProps>;

/**
 * Los avisos de la cabecera se pueden plegar, y se quedan plegados.
 *
 * Son útiles el primer día y estorban el resto: ocupan dos franjas fijas encima
 * del calendario, que es justo el espacio que falta en móvil. Al plegarlos queda
 * el botón «?» de la cabecera para recuperarlos, porque un aviso que se cierra
 * sin forma de volver a verlo es información perdida.
 */
const AYUDA_STORAGE_KEY = `${FC_STORAGE_KEY}_ayuda`;
const mostrarAyuda = ref(localStorage.getItem(AYUDA_STORAGE_KEY) !== '0');

watch(mostrarAyuda, (v) => localStorage.setItem(AYUDA_STORAGE_KEY, v ? '1' : '0'));

const OCUPACION_STORAGE_KEY = `${FC_STORAGE_KEY}_ocupacion`;
const mostrarOcupacion = ref(localStorage.getItem(OCUPACION_STORAGE_KEY) !== '0');

watch(mostrarOcupacion, (v) => localStorage.setItem(OCUPACION_STORAGE_KEY, v ? '1' : '0'));

function comoFondo(eventos: OcupacionEventoFeed[]): EventInput[] {
    return eventos.map((ev) => ({
        // Prefijo obligatorio: los dos feeds conviven en el mismo calendario y
        // FullCalendar trata dos eventos con el mismo id como el mismo evento
        // (los arrastraría en bloque, y aquí además chocarían tipos distintos).
        id: `ocupacion-${ev.id}`,
        start: ev.start,
        end: ev.end,
        resourceId: ev.resourceId,
        display: 'background',
        backgroundColor: COLOR_OCUPADO,
        classNames: ['fc-ocupado'],
        extendedProps: ev.extendedProps,
    }));
}

// ============================================================================
// TARIFA BASE POR CASITA
//
// El precio base vive en la unidad (`tarifaBasePrecio`), no en los rangos, y es
// el que usa «Generar Masivo» para calcular el precio de cada casita. Se pinta
// bajo el nombre del recurso para no tener que abrir la ficha de la unidad —ni
// generar el masivo a ciegas— y para tener a la vista la referencia con la que
// se comparan los precios del calendario.
// ============================================================================
const basePorUnidad = computed<Map<string, string>>(() => {
    const mapa = new Map<string, string>();

    for (const u of tarifasStore.unidades) {
        if (!u.id || !u.tarifaBaseActiva) continue;

        const precio = Number(u.tarifaBasePrecio ?? 0);
        if (!precio) continue;

        mapa.set(String(u.id), `${u.tarifaBaseMonedaSimbolo ?? ''} ${formatearPrecio(precio)}`.trim());
    }

    return mapa;
});

/** Sin decimales cuando el precio es redondo: en 110 px cada carácter cuenta. */
function formatearPrecio(valor: number): string {
    return Number.isInteger(valor) ? String(valor) : valor.toFixed(2);
}

// Los catálogos se piden aquí (no solo en los drawers) porque la etiqueta de
// cada casita depende de ellos. Como FullCalendar NO reacciona a Pinia, al
// llegar los datos hay que repintar los recursos a mano.
void tarifasStore.fetchMasters().then(() => calendarApiRef.value?.getApi()?.refetchResources());

// ============================================================================
// DRAWERS
// ============================================================================
const drawerVisible = ref(false);
const drawerTarifaId = ref<string | null>(null);
const drawerCreateDefaults = ref<{ unidadId?: string; fechaInicio: string; fechaFin: string } | null>(null);
const drawerStartReadOnly = ref(false);
const masivaVisible = ref(false);

/**
 * Rango que se está viendo, del 1 al 1: `[currentStart, currentEnd)` de la vista
 * —un mes o dos, según el botón—. Es el rango con el que arranca «Generar
 * Masivo»: generar tarifas para un mes que no se está mirando no es lo normal, y
 * teclear las dos fechas a mano cada vez lo era.
 */
const rangoVisible = ref<{ fechaInicio: string; fechaFin: string } | null>(null);

function abrirEdicion(tarifaId: string, readOnly: boolean): void {
    drawerTarifaId.value = tarifaId;
    drawerCreateDefaults.value = null;
    drawerStartReadOnly.value = readOnly;
    drawerVisible.value = true;
}

/** `unidadId` opcional: desde el botón de la cabecera la elige el usuario. */
function abrirCreacion(fechaInicio: string, fechaFin: string, unidadId?: string): void {
    drawerTarifaId.value = null;
    drawerStartReadOnly.value = false;
    drawerCreateDefaults.value = { unidadId, fechaInicio, fechaFin };
    drawerVisible.value = true;
}

/**
 * Alta desde la cabecera. Es el camino que SIEMPRE funciona: cuando las tarifas
 * cubren toda la línea de tiempo no queda hueco libre donde hacer clic, así que
 * no puede depender del calendario (ver también `select` y "Crear tarifa aquí").
 */
/**
 * Ventana por defecto de una tarifa nueva: 3 NOCHES.
 *
 * Una sola noche casi nunca es lo que se quiere —se tarifica un fin de semana,
 * un puente, una temporada—, y dejarlo en una obligaba a corregir el formulario
 * siempre. Arrastrando sobre la fila se sigue respetando el rango marcado.
 *
 * Se suma entero (y no `- 1`): `fechaFin` es EXCLUSIVA, marca la salida. Ver
 * `nochesDeRango` en pmsTarifaModel.ts.
 */
const VENTANA_POR_DEFECTO_NOCHES = 3;

function abrirCreacionLibre(): void {
    const hoy = fromDateLocal(new Date());
    abrirCreacion(hoy, sumarDias(hoy, VENTANA_POR_DEFECTO_NOCHES));
}

function cerrarDrawer(): void {
    drawerVisible.value = false;
    tarifasStore.clearActiva();
}

function onGuardado(): void {
    cerrarDrawer();
    refrescarCalendario();
}

function onGenerado(creadas: number): void {
    masivaVisible.value = false;
    avisarOk(creadas > 0
        ? `Se generaron ${creadas} tarifa(s).`
        : 'No se generó ninguna tarifa: ninguna unidad activa tiene tarifa base configurada.');
    refrescarCalendario();
}

// ============================================================================
// AVISOS (mismo patrón que ReservasView: banner efímero bajo la cabecera)
// ============================================================================
const avisoError = ref<string | null>(null);
const avisoOk = ref<string | null>(null);

function avisar(mensaje: string): void {
    avisoError.value = mensaje;
    setTimeout(() => (avisoError.value = null), 5000);
}

function avisarOk(mensaje: string): void {
    avisoOk.value = mensaje;
    setTimeout(() => (avisoOk.value = null), 5000);
}

// ============================================================================
// MENÚ CONTEXTUAL (tap/click en tarifa -> Ver/Editar; en vacío -> Crear)
// ============================================================================
interface MenuState {
    x: number;
    y: number;
    kind: 'event' | 'create';
    tarifaId?: string;
    /** Los segmentos compactados son calculados: no se pueden borrar como tales. */
    esCompactado?: boolean;
    unidadId?: string;
    /** Nombre de la casita sobre la que se tocó, para encabezar el menú. */
    unidadNombre?: string;
    fecha?: Date;
    /** Precio del tramo sobre el que se hizo clic, para el encabezado del menú. */
    resumen?: string;
    /** Líneas del tooltip del provider; en táctil se pintan dentro del menú. */
    tooltipLineas?: string[];
}
const menu = ref<MenuState | null>(null);

/**
 * Dispositivo sin puntero que pueda "posarse" (móvil/tablet).
 *
 * En escritorio el tooltip (hover) y el menú (clic) no compiten: el tooltip se
 * va en cuanto el ratón se mueve. En táctil un solo tap dispara LOS DOS, y sobre
 * una barra de las filas de abajo el tooltip flotante caía justo encima del
 * menú. Por eso en táctil el tooltip flotante se desactiva (`touch: false` en
 * tippy) y sus líneas se pintan DENTRO del menú, como cabecera: se sigue viendo
 * todo de un tap, pero apilado y sin solaparse nunca. Espejo de ReservasView.
 */
const ES_TACTIL = window.matchMedia('(hover: none)').matches;

// Tamaño aproximado, solo para que el menú no NAZCA fuera de la pantalla: la
// posición definitiva la calcula reubicarMenu() midiendo el panel real.
const MENU_ANCHO = 240;
const MENU_ALTO = 200;
const MENU_BORDE = 8;

/**
 * Ancla el menú al punto del click/tap. En móvil el gesto llega como TouchEvent,
 * que NO tiene clientX/clientY (viven en changedTouches) — leerlos directo daba
 * NaN y el menú se pegaba a la esquina superior izquierda.
 */
function posicionMenu(jsEvent: MouseEvent | TouchEvent): { x: number; y: number } {
    const touch = (jsEvent as TouchEvent).changedTouches?.[0] ?? (jsEvent as TouchEvent).touches?.[0];
    const punto = touch ?? (jsEvent as MouseEvent);
    const px = Number.isFinite(punto?.clientX) ? punto.clientX : 0;
    const py = Number.isFinite(punto?.clientY) ? punto.clientY : 0;

    return {
        x: Math.max(MENU_BORDE, Math.min(px, window.innerWidth - MENU_ANCHO)),
        y: Math.max(MENU_BORDE, Math.min(py, window.innerHeight - MENU_ALTO)),
    };
}

function cerrarMenu(): void {
    menu.value = null;
}

// ----------------------------------------------------------------------------
// POSICIÓN DEFINITIVA DEL MENÚ (medida, no estimada)
//
// El alto del panel depende de lo que lleve dentro —en táctil, la ficha del
// tramo—, así que estimarlo con una constante dejaba el menú medio fuera de la
// pantalla al abrirlo en las filas de abajo. Un ResizeObserver lo recoloca cada
// vez que cambia de tamaño. Espejo de ReservasView.
// ----------------------------------------------------------------------------
const menuEl = ref<HTMLElement | null>(null);
const menuPos = ref({ x: 0, y: 0 });
let menuObserver: ResizeObserver | null = null;

function reubicarMenu(): void {
    const el = menuEl.value;
    if (!el || !menu.value) return;

    menuPos.value = {
        x: Math.max(MENU_BORDE, Math.min(menu.value.x, window.innerWidth - el.offsetWidth - MENU_BORDE)),
        y: Math.max(MENU_BORDE, Math.min(menu.value.y, window.innerHeight - el.offsetHeight - MENU_BORDE)),
    };
}

// Arranca en el punto del tap (ya clampeado a ojo) para que no se vea saltar.
// Se compara el ancla, no `previo === null`: si un menú se reabre en otro punto
// sin pasar por null, hay que volver a anclar (ver el mismo watch en Reservas).
watch(menu, (m, previo) => {
    if (m && (m.x !== previo?.x || m.y !== previo?.y)) menuPos.value = { x: m.x, y: m.y };
});

watch(menuEl, (el) => {
    menuObserver?.disconnect();
    menuObserver = null;
    if (!el) return;
    // El observer dispara también al empezar a observar: esa primera llamada es
    // la que coloca el menú ya medido.
    menuObserver = new ResizeObserver(reubicarMenu);
    menuObserver.observe(el);
});

onBeforeUnmount(() => menuObserver?.disconnect());

// ============================================================================
// BOTÓN "ATRÁS" DEL NAVEGADOR
//
// Igual que en ReservasView: en móvil el gesto instintivo para cerrar el menú o
// un drawer es el "atrás" del sistema. Se cierra una capa por intento y solo se
// sale al portal con el calendario limpio. Vue Router restaura la posición del
// historial al devolver `false`, así que el back sigue disponible después.
// ============================================================================
onBeforeRouteLeave(() => {
    if (menu.value) {
        cerrarMenu();
        return false;
    }
    if (drawerVisible.value) {
        cerrarDrawer();
        return false;
    }
    if (masivaVisible.value) {
        masivaVisible.value = false;
        return false;
    }
});

function onEventClick(info: EventClickArg): void {
    // El fondo de ocupación no tiene menú propio (no es una tarifa). FullCalendar
    // ya no dispara eventClick sobre eventos de fondo, pero el guardado es de una
    // línea y evita que un cambio de versión abra un menú apuntando a un id ajeno.
    if (info.event.display === 'background') return;

    // `extendedProps` es un diccionario abierto en FullCalendar: el contrato real
    // lo fijan los providers *_spa de tarifas en el backend.
    const props = info.event.extendedProps as PmsTarifaExtendedProps;
    if (!props?.tarifaRangoId) return;
    const { x, y } = posicionMenu(info.jsEvent);
    const tooltip = info.event.extendedProps.tooltip as string | string[] | undefined;
    menu.value = {
        x, y,
        kind: 'event',
        tarifaId: props.tarifaRangoId,
        esCompactado: props.context === 'tarifaRangoCompactado',
        resumen: `${props.moneda ?? ''} ${props.precio ?? ''}`.trim(),
        tooltipLineas: Array.isArray(tooltip) ? tooltip : (tooltip ? [tooltip] : []),
        // Para "Crear tarifa aquí": la casita de la barra y su día de inicio
        // como valor de arranque. Es el atajo cuando no queda hueco libre.
        unidadId: info.event.getResources()?.[0]?.id,
        unidadNombre: info.event.getResources()?.[0]?.title,
        fecha: info.event.start ?? undefined,
    };
}

function onDateClick(info: DateClickArg): void {
    // `resource` lo agrega @fullcalendar/resource sobre DatePointApi: solo llega
    // en las vistas de recursos (timeline), de ahí el opcional.
    const resourceId = info.resource?.id;
    if (!resourceId) return;
    const { x, y } = posicionMenu(info.jsEvent);
    menu.value = {
        x, y,
        kind: 'create',
        unidadId: resourceId,
        unidadNombre: info.resource?.title,
        fecha: info.date,
    };
}

/**
 * Casita y día sobre los que se va a crear, para encabezar el menú. En una
 * rejilla de 31 o 62 columnas el toque se falla por una celda con facilidad, y
 * «Crear Tarifa» a secas no dice sobre qué. Espejo del menú de ReservasView.
 */
const menuFechaLarga = computed(() => {
    const fecha = menu.value?.fecha;
    if (!fecha) return '';
    return fecha.toLocaleDateString('es-PE', {
        weekday: 'short', day: 'numeric', month: 'long', year: 'numeric',
    });
});

/**
 * Arrastre sobre la línea de tiempo: crea la tarifa con el rango exacto que se
 * marcó. `info.end` es EXCLUSIVO en FullCalendar (una celda de un día termina a
 * las 00:00 del día siguiente), por eso se resta 1 ms antes de leer el día.
 */
function onSelect(info: DateSelectArg): void {
    const unidadId = info.resource?.id;
    if (!unidadId) return;

    // `info.end` de FullCalendar ya es EXCLUSIVO (medianoche del día siguiente al
    // último seleccionado) y `fechaFin` también lo es, así que se copia tal cual.
    // Antes se le restaba un milisegundo para «hacerlo inclusivo»: seleccionar un
    // solo día daba `fin === inicio`, un rango de CERO noches que el motor de
    // precios descarta entero — se guardaba sin error y no hacía nada.
    abrirCreacion(fromDateLocal(info.start), fromDateLocal(info.end), unidadId);
    calendarApiRef.value?.getApi()?.unselect();
}

function elegirVer(): void {
    if (menu.value?.tarifaId) abrirEdicion(menu.value.tarifaId, true);
    cerrarMenu();
}

function elegirEditar(): void {
    if (menu.value?.tarifaId) abrirEdicion(menu.value.tarifaId, false);
    cerrarMenu();
}

function elegirCrear(): void {
    if (menu.value?.unidadId && menu.value.fecha) {
        const fecha = fromDateLocal(menu.value.fecha);
        abrirCreacion(fecha, sumarDias(fecha, VENTANA_POR_DEFECTO_NOCHES), menu.value.unidadId);
    }
    cerrarMenu();
}

// ============================================================================
// DRAG & DROP (solo en el calendario "Todas": ver PmsTarifaCalendario.editable)
//
// Los eventos llegan con horas de UI inyectadas por el provider (12:00 -> 11:59)
// y FullCalendar mueve/redimensiona en múltiplos de 24h, así que la hora del día
// se conserva y basta con leer el día local de start/end para reconstruir el
// rango de fechas real (columnas `date` en la BD).
// ============================================================================
async function patchDesdeCalendario(
    info: EventDropArg | EventResizeDoneArg,
    payloadExtra: Record<string, unknown> = {},
): Promise<void> {
    const props = info.event.extendedProps as PmsTarifaExtendedProps;
    if (!props?.tarifaRangoId || !info.event.start || !info.event.end) {
        info.revert();
        return;
    }

    try {
        await tarifasStore.patchTarifa(props.tarifaRangoId, {
            fechaInicio: fromDateLocal(info.event.start),
            fechaFin: fromDateLocal(info.event.end),
            ...payloadExtra,
        });
        avisoError.value = null;
    } catch (err) {
        info.revert();
        avisar(extractApiErrorMessage(err, 'No se pudo actualizar la tarifa.'));
    }
}

function onEventDrop(info: EventDropArg): void {
    // Mover a otra fila = cambiar de unidad.
    patchDesdeCalendario(
        info,
        info.newResource ? { unidad: pmsUnidadIri(info.newResource.id) } : {},
    );
}

function onEventResize(info: EventResizeDoneArg): void {
    patchDesdeCalendario(info);
}

// ============================================================================
// FULLCALENDAR OPTIONS
// ============================================================================
// `computed` (y no un objeto plano como en ReservasView) porque `editable`
// depende del calendario elegido: los rangos compactados son segmentos
// calculados y no admiten drag & drop.
const calendarOptions = computed<CalendarOptions>(() => ({
    plugins: [resourceTimelinePlugin, interactionPlugin],
    schedulerLicenseKey: 'CC-Attribution-NonCommercial-NoDerivatives',
    locale: esLocale,
    timeZone: 'local',
    initialView: fcVistaGuardada(),
    initialDate: fcFechaGuardada(),
    nowIndicator: true,
    contentHeight: 'auto',
    editable: calendarioActual.value.editable,
    eventStartEditable: calendarioActual.value.editable,
    eventDurationEditable: calendarioActual.value.editable,
    // Arrastrar sobre la línea de tiempo para crear una tarifa con su rango.
    // selectMinDistance evita que un tap suelto (que abre el menú) se interprete
    // como selección.
    selectable: calendarioActual.value.editable,
    selectMirror: true,
    selectMinDistance: 5,
    refetchResourcesOnNavigate: true,
    resourceOrder: 'orden',
    eventOrder: '-prioridadImportante,-duration,start',
    eventOrderStrict: true,
    // 110 px (antes 90): la etiqueta lleva ahora una segunda línea con el precio
    // base y con 90 px se partía en dos.
    resourceAreaWidth: '110px',
    resourceAreaHeaderContent: 'Casita',

    /**
     * Nombre de la casita + su tarifa base debajo. El feed de recursos sólo trae
     * `title`, así que el precio sale de `basePorUnidad` (catálogo REST de
     * unidades) cruzando por el id del recurso.
     */
    resourceLabelContent: (arg: ResourceLabelContentArg) => {
        const base = basePorUnidad.value.get(String(arg.resource.id));
        const nombre = escaparHtml(arg.resource.title || '');

        return {
            html: base
                ? `<div class="fc-tarifa-recurso"><span class="fc-tarifa-recurso-nombre">${nombre}</span>`
                    + `<span class="fc-tarifa-recurso-base" title="Tarifa base de la casita">${escaparHtml(base)}</span></div>`
                : `<div class="fc-tarifa-recurso"><span class="fc-tarifa-recurso-nombre">${nombre}</span></div>`,
        };
    },

    headerToolbar: {
        left: 'irHoy prev,next recargar',
        center: '',
        right: 'resourceTimelineTwoMonths,resourceTimelineOneMonth',
    },

    /**
     * Botón «Hoy» propio y con nombre PROPIO (`irHoy`, no `today`): el de
     * fábrica no desplaza si el mes actual ya es el visible, y redefinir
     * `customButtons.today` no vale porque FullCalendar decide el estado
     * deshabilitado por el nombre del botón. Espejo del de ReservasView, donde
     * está el detalle.
     */
    customButtons: {
        recargar: {
            text: '↻',
            hint: 'Recargar tarifas y unidades desde el servidor',
            click: () => { void recargarCalendario(); },
        },
        irHoy: {
            text: 'Hoy',
            hint: 'Ir a hoy',
            click: () => {
                const api = calendarApiRef.value?.getApi();
                if (!api) return;
                // Se marca ANTES: si `today()` cambia de mes, el `datesSet` que
                // dispara lo consume y así el encuadre por dirección no se lleva
                // el scroll al extremo del rango.
                diaPendiente = fromDateLocal(hoyLocal());
                api.today();
                // Y si el mes ya era el visible no hubo `datesSet`: se resuelve
                // aquí, que es justo el caso que el botón de fábrica no cubría.
                resolverDiaPendiente();
            },
        },
    },

    datesSet: (info: DatesSetArg) => {
        rangoVisible.value = {
            fechaInicio: fromDateLocal(info.view.currentStart),
            fechaFin: fromDateLocal(info.view.currentEnd),
        };

        const titulo = String(info.view.title || '');
        calendarTitulo.value = titulo ? titulo.charAt(0).toUpperCase() + titulo.slice(1) : '';

        const fechaRef: Date | undefined = info.view.currentStart || info.start;
        if (fechaRef) {
            localStorage.setItem(`${FC_STORAGE_KEY}_date`, fechaRef.toISOString().slice(0, 10));
        }
        localStorage.setItem(`${FC_STORAGE_KEY}_view`, info.view.type);

        if (diaPendiente) {
            resolverDiaPendiente();
            return;
        }

        // Encuadre del rango recién montado: manda la dirección de la
        // navegación (ver encuadrarTimeline).
        const api = info.view.calendar;
        requestAnimationFrame(() => encuadrarTimeline(api, calendarRootEl.value));
    },

    views: {
        // Vista por defecto del legacy para tarifas: dos meses de un vistazo.
        resourceTimelineTwoMonths: {
            type: 'resourceTimeline',
            duration: { months: 2 },
            buttonText: '2 Meses',
            slotDuration: '24:00:00',
            // Dos filas de cabecera (mes arriba, día abajo): con 60 columnas no
            // cabe la etiqueta completa por día.
            slotLabelFormat: [
                { month: 'long' },
                { day: 'numeric' },
            ],
        },
        resourceTimelineOneMonth: {
            type: 'resourceTimeline',
            duration: { months: 1 },
            buttonText: 'Mes',
            slotDuration: '24:00:00',
            slotLabelFormat: [{ weekday: 'short', day: 'numeric', month: 'numeric', omitCommas: true }],
        },
    },

    resources: (
        fetchInfo: ResourceFuncArg,
        success: (recursos: ResourceInput[]) => void,
        failure: (error: Error) => void,
    ) => {
        apiClient.get(`/fullcalendar/load/resource/${calendarioActual.value.key}`, {
            params: { start: fetchInfo.startStr, end: fetchInfo.endStr, _t: Date.now() },
        })
            .then((r) => success(recordarOrden(coleccionFeed<CalendarRecursoFeed>(r.data))))
            .catch((err: unknown) => failure(comoError(err)));
    },

    // Dos fuentes: las tarifas y, opcionalmente, la ocupación de fondo. Van como
    // `eventSources` (y no como un único `events`) porque cada una viene de un
    // calendario distinto del backend y FullCalendar las refresca por separado.
    eventSources: [
        {
            id: 'tarifas',
            events: (
                fetchInfo: EventSourceFuncArg,
                success: (eventos: EventInput[]) => void,
                failure: (error: Error) => void,
            ) => {
                apiClient.get(`/fullcalendar/load/event/${calendarioActual.value.key}`, {
                    params: { start: fetchInfo.startStr, end: fetchInfo.endStr, _t: Date.now() },
                })
                    .then((r) => success(pintarPorCasita(coleccionFeed<TarifaEventoFeed>(r.data))))
                    .catch((err: unknown) => failure(comoError(err)));
            },
        },
        ...(mostrarOcupacion.value ? [{
            id: 'ocupacion',
            events: (
                fetchInfo: EventSourceFuncArg,
                success: (eventos: EventInput[]) => void,
                failure: (error: Error) => void,
            ) => {
                apiClient.get(`/fullcalendar/load/event/${PMS_OCUPACION_CALENDARIO_KEY}`, {
                    params: { start: fetchInfo.startStr, end: fetchInfo.endStr, _t: Date.now() },
                })
                    .then((r) => success(comoFondo(coleccionFeed<OcupacionEventoFeed>(r.data))))
                    .catch((err: unknown) => failure(comoError(err)));
            },
        }] : []),
    ],

    dateClick: onDateClick,
    eventClick: onEventClick,
    eventDrop: onEventDrop,
    eventResize: onEventResize,
    select: onSelect,

    /**
     * Contenido de la barra. El provider ya manda el título listo, pero aquí se
     * arma con los datos crudos de extendedProps para poder meter iconografía
     * (etiqueta, estrella de prioritaria, badge de estancia mínima) en vez de
     * una única cadena de texto plano.
     */
    eventContent: (arg: EventContentArg) => {
        // La ocupación es puro fondo: sin texto, o el beige se llenaría de
        // nombres de huésped compitiendo con los precios.
        if (arg.event.display === 'background') return { html: '' };

        const p = arg.event.extendedProps as PmsTarifaExtendedProps;
        if (!p?.precio) return { html: `<div class="fc-tarifa">${escaparHtml(arg.event.title)}</div>` };

        const moneda = p.moneda ? `${escaparHtml(p.moneda)} ` : '';
        const partes = [
            p.importante ? '<i class="fas fa-star fc-tarifa-ico fc-tarifa-star"></i>' : '',
            p.active === false ? '<i class="fas fa-eye-slash fc-tarifa-ico"></i>' : '<i class="fas fa-tag fc-tarifa-ico"></i>',
            `<span class="fc-tarifa-precio">${moneda}${escaparHtml(p.precio)}</span>`,
            p.minStay ? `<span class="fc-tarifa-min">${escaparHtml(String(p.minStay))}N</span>` : '',
        ];

        return { html: `<div class="fc-tarifa">${partes.join('')}</div>` };
    },

    eventDidMount: (info: EventMountArg) => {
        // `_tippy` lo cuelga tippy del propio elemento (ver ReferenceElement en sus
        // tipos); se destruye antes para no apilar instancias en cada re-render.
        const el = info.el as ReferenceElement;

        if (info.event.display === 'background') {
            // Saber QUIÉN ocupa el hueco es justo lo que se pregunta al tarifar
            // (una OTA con comisión no se tarifa igual que un bloqueo interno).
            const p = info.event.extendedProps as PmsEventoExtendedProps;
            const lineas = [
                '<b>Ocupado</b>',
                p.cliente ? escaparHtml(p.cliente) : '',
                p.estado ? `Estado: ${escaparHtml(p.estado)}` : '',
            ].filter(Boolean);

            el._tippy?.destroy();
            tippy(info.el, { content: lineas.join('<br>'), allowHTML: true, appendTo: document.body, placement: 'top', touch: false });
            return; // sin cursor pointer: el fondo no se puede abrir ni arrastrar
        }

        // El provider manda `tooltip` como string o como lista de líneas.
        const tooltipContent = info.event.extendedProps.tooltip as string | string[] | undefined;
        const finalContent = Array.isArray(tooltipContent) ? tooltipContent.join('<br>') : (tooltipContent || info.event.title);

        el._tippy?.destroy();
        // `touch: false`: en táctil estas mismas líneas van dentro del menú (ES_TACTIL).
        tippy(info.el, { content: finalContent, allowHTML: true, appendTo: document.body, placement: 'top', touch: false });
        info.el.style.cursor = 'pointer';
    },
}));
</script>

<template>
    <div class="h-screen bg-[#F8FAFC] flex flex-col font-sans overflow-hidden">
        <!-- En móvil la cabecera va en DOS filas: con el título y las acciones en
             la misma, el título se partía en cuatro líneas de dos palabras. Desde
             `md` vuelve a ser una sola fila. -->
        <header class="bg-slate-900 text-white px-4 md:px-6 py-3 flex flex-col md:flex-row md:items-center md:justify-between gap-2 md:gap-3 z-20 shadow-md shrink-0">
            <div class="flex items-center gap-3 min-w-0">
                <AppSwitcher />
                <div class="min-w-0">
                    <h1 class="font-black text-base md:text-xl tracking-tight leading-none truncate">Calendario de Tarifas</h1>
                    <p class="text-[10px] md:text-[11px] font-bold text-slate-400 uppercase tracking-widest mt-1 truncate">
                        PMS · Precios y estancia mínima
                    </p>
                </div>
            </div>

            <div class="flex items-center gap-2 shrink-0">
                <!-- Los dos botones de alta llevan SIEMPRE su etiqueta, también en
                     móvil: una varita y un «+» naranja no dicen qué hacen, y estos
                     dos hacen cosas muy distintas —una tarifa suelta frente a
                     generar el tarifario de todas las casitas—. La versión larga
                     aparece cuando hay sitio. -->
                <button @click="abrirCreacionLibre" title="Crear una tarifa suelta"
                    class="h-9 px-3 flex items-center gap-1.5 bg-[#376875] hover:bg-[#2d5660] rounded-lg text-xs font-black transition-colors">
                    <i class="fas fa-tag"></i>
                    <span>Nueva</span>
                </button>

                <button @click="masivaVisible = true"
                    title="Generar tarifas en bloque para todas las casitas con tarifa base"
                    class="h-9 px-3 flex items-center gap-1.5 bg-[#E07845] hover:bg-[#c9663a] rounded-lg text-xs font-black transition-colors">
                    <i class="fas fa-layer-group"></i>
                    <span>Masivo<span class="hidden sm:inline">: generar</span></span>
                </button>

                <button v-if="!mostrarAyuda" @click="mostrarAyuda = true" title="Mostrar las ayudas de la vista"
                    class="w-9 h-9 flex items-center justify-center bg-slate-800 hover:bg-slate-700 text-slate-300 rounded-lg text-xs transition-colors">
                    <i class="fas fa-circle-question"></i>
                </button>

                <button @click="mostrarOcupacion = !mostrarOcupacion"
                    :title="mostrarOcupacion ? 'Ocultar la ocupación de fondo' : 'Mostrar la ocupación de fondo'"
                    :class="['h-9 px-3 flex items-center gap-1.5 rounded-lg text-xs font-black transition-colors',
                             mostrarOcupacion ? 'bg-[#F5E9C0] text-slate-800 hover:bg-[#ecdca9]' : 'bg-slate-800 text-slate-300 hover:bg-slate-700']">
                    <i class="fas fa-bed"></i>
                    <span class="hidden sm:inline">Ocupación</span>
                </button>

                <select v-model.number="calendarioIndex" @change="refrescarCalendario"
                    class="bg-slate-800 text-white text-xs font-bold rounded-lg px-3 py-2 border border-slate-700">
                    <option v-for="(cal, i) in PMS_TARIFA_CALENDARIOS" :key="cal.key" :value="i">{{ cal.nombre }}</option>
                </select>
            </div>
        </header>

        <div v-if="avisoError" class="bg-rose-50 border-b border-rose-200 text-rose-700 text-sm font-bold px-4 py-2">
            <i class="fas fa-exclamation-triangle mr-2"></i>{{ avisoError }}
        </div>
        <div v-if="avisoOk" class="bg-emerald-50 border-b border-emerald-200 text-emerald-700 text-sm font-bold px-4 py-2">
            <i class="fas fa-check-circle mr-2"></i>{{ avisoOk }}
        </div>

        <!-- Avisos de la vista. Plegables y con memoria (ver `mostrarAyuda`): son
             útiles al principio y estorban después, y en móvil se comen el sitio
             del calendario. -->
        <template v-if="mostrarAyuda">
            <!-- Los segmentos compactados no son filas reales de la tabla: se avisa
                 para que nadie espere poder arrastrarlos como en la vista "Todas". -->
            <div class="bg-slate-100 border-b border-slate-200 text-slate-500 text-[11px] font-bold px-4 py-1.5 flex items-start gap-2">
                <template v-if="!calendarioActual.editable">
                    <i class="fas fa-info-circle mt-0.5"></i>
                    <span class="flex-1">
                        Vista compactada (solo lectura): son segmentos calculados a partir de los rangos
                        solapados. Para mover o redimensionar, cambia a «Todas».
                    </span>
                </template>
                <template v-else>
                    <i class="fas fa-hand-pointer mt-0.5"></i>
                    <span class="flex-1">
                        Arrastra sobre una fila para crear una tarifa con ese rango, o usa «Nueva».
                        Sobre una tarifa existente, el menú ofrece «Crear tarifa aquí».
                    </span>
                </template>

                <button @click="mostrarAyuda = false" title="Ocultar las ayudas"
                    class="shrink-0 w-5 h-5 flex items-center justify-center rounded hover:bg-slate-200 text-slate-400 hover:text-slate-600">
                    <i class="fas fa-xmark text-[11px]"></i>
                </button>
            </div>

            <div v-if="mostrarOcupacion" class="bg-slate-50 border-b border-slate-200 text-slate-500 text-[11px] font-bold px-4 py-1.5 flex items-center gap-2">
                <span class="inline-block w-4 h-3 rounded-sm border border-slate-300 shrink-0" :style="{ backgroundColor: COLOR_OCUPADO }"></span>
                <span class="flex-1">
                    Fondo beige = casita vendida (pendiente, confirmada o requerimiento). No incluye
                    canceladas, consultas abiertas ni bloqueos.
                </span>
            </div>
        </template>

        <!-- `fc-tarifas` acota los estilos de calendario de este archivo: ver la
             nota del bloque <style>. -->
        <main ref="calendarRootEl" class="fc-tarifas flex-1 overflow-y-auto p-3 md:p-4">
            <h3 v-if="calendarTitulo" class="text-center font-black text-slate-800 uppercase tracking-wide text-sm mb-2">
                {{ calendarTitulo }}
            </h3>
            <FullCalendar ref="calendarApiRef" :options="calendarOptions" />
        </main>

        <!-- Menú contextual -->
        <div v-if="menu" class="fixed inset-0 z-30" @click="cerrarMenu" @contextmenu.prevent="cerrarMenu">
            <div ref="menuEl"
                class="absolute w-52 bg-white rounded-xl shadow-2xl border border-slate-200 overflow-hidden py-1.5 max-w-[calc(100vw-1rem)] max-h-[85vh] overflow-y-auto"
                :style="{ left: menuPos.x + 'px', top: menuPos.y + 'px' }" @click.stop>
                <template v-if="menu.kind === 'event'">
                    <!-- Ficha del tramo SOLO en táctil: en escritorio ya la da el
                         tooltip al pasar el ratón (ver ES_TACTIL). -->
                    <div v-if="ES_TACTIL && menu.tooltipLineas?.length" class="fc-tip-menu">
                        <p v-for="(linea, i) in menu.tooltipLineas" :key="i"
                            :class="i === 0 ? 'font-black pb-1 mb-1 border-b border-white/20' : 'opacity-90'">
                            {{ linea }}
                        </p>
                    </div>
                    <p class="px-4 py-1.5 text-[10px] font-black text-slate-400 uppercase tracking-widest">
                        {{ menu.esCompactado ? 'Rango de origen' : 'Tarifa' }}
                        <span v-if="menu.resumen" class="text-slate-600">· {{ menu.resumen }}</span>
                    </p>
                    <button @click="elegirVer"
                        class="w-full flex items-center gap-2.5 px-4 py-2.5 text-sm font-bold text-slate-700 hover:bg-slate-50">
                        <i class="fas fa-eye w-4 text-slate-400"></i> Ver
                    </button>
                    <button @click="elegirEditar"
                        class="w-full flex items-center gap-2.5 px-4 py-2.5 text-sm font-bold text-slate-700 hover:bg-slate-50">
                        <i class="fas fa-pen w-4 text-slate-400"></i> Editar
                    </button>
                    <!-- Atajo cuando la casita no tiene huecos libres donde hacer clic -->
                    <button v-if="calendarioActual.editable && menu.unidadId && menu.fecha" @click="elegirCrear"
                        class="w-full flex items-start gap-2.5 px-4 py-2.5 text-left text-sm font-bold text-slate-700 hover:bg-slate-50 border-t border-slate-100">
                        <i class="fas fa-plus w-4 mt-0.5 text-slate-400"></i>
                        <span class="min-w-0">
                            Crear tarifa aquí
                            <!-- Arranca en el primer día de ESTA barra, no donde
                                 se tocó: conviene verlo antes de crear. -->
                            <span class="block text-[10px] font-bold text-slate-400 truncate first-letter:uppercase">
                                {{ menu.unidadNombre }} · {{ menuFechaLarga }}
                            </span>
                        </span>
                    </button>
                </template>
                <template v-else>
                    <!-- Sobre qué se está actuando: en una rejilla de 31 o 62
                         columnas el toque se falla por una celda con facilidad. -->
                    <div class="px-4 pt-2 pb-2.5 border-b border-slate-100">
                        <p class="text-sm font-black text-slate-800 leading-tight truncate">
                            <i class="fas fa-home mr-1.5 text-slate-300"></i>{{ menu.unidadNombre || 'Casita' }}
                        </p>
                        <p class="text-[11px] font-bold text-slate-500 mt-0.5 first-letter:uppercase">
                            {{ menuFechaLarga }}
                        </p>
                    </div>

                    <button @click="elegirCrear"
                        class="w-full flex items-center gap-2.5 px-4 py-2.5 text-sm font-bold text-slate-700 hover:bg-slate-50">
                        <i class="fas fa-tag w-4 text-slate-400"></i> Crear Tarifa
                    </button>
                </template>
            </div>
        </div>

        <TarifaEditDrawer
            v-if="drawerVisible"
            :tarifa-id="drawerTarifaId"
            :create-defaults="drawerCreateDefaults"
            :start-read-only="drawerStartReadOnly"
            @close="cerrarDrawer"
            @saved="onGuardado"
            @deleted="onGuardado"
        />

        <TarifaMasivaDrawer
            v-if="masivaVisible"
            :rango-defecto="rangoVisible"
            @close="masivaVisible = false"
            @generated="onGenerado"
        />
    </div>
</template>

<!--
  Sin `scoped`: el HTML de `eventContent` lo inyecta FullCalendar fuera del
  árbol que Vue marca con el atributo de scope, así que un bloque scoped no lo
  alcanzaría. Las clases van prefijadas con `fc-tarifa-` para no colisionar,
  igual que hace assets/fullcalendar-overrides.css.
-->
<style>
/*
  Aire alrededor de cada barra de tarifa. Los rangos son largos y se tocan unos
  con otros: sin separación, tres tarifas seguidas se leen como una sola mancha
  de color y la rejilla se satura.

  Va en `.fc-timeline-event` y NO en el harness: el harness es quien lleva la
  posición absoluta y el ancho que calcula FullCalendar para el rango, así que
  el margen tiene que encogerlo desde dentro. Las variantes `.fc-event-start` /
  `.fc-event-end` se repiten para ganar en especificidad al `margin-left/right:
  1px` que FullCalendar les pone.

  El fondo de ocupación no se ve afectado —y no debe: tiñe el día completo—,
  porque los eventos de fondo son `.fc-bg-event` dentro de
  `.fc-timeline-bg-harness`, otra rama del DOM.

  Todo bajo `.fc-tarifas` (la clase del <main>) porque este bloque no lleva
  `scoped` y el CSS del bundle vive en el documento aunque la vista esté
  desmontada: sin acotar, el margen se colaría en el calendario de Reservas.
*/
/*
  Etiqueta de cada casita: nombre arriba y tarifa base debajo, en pequeño. Se
  pinta desde `resourceLabelContent`, así que el CSS también va aquí (fuera de
  `scoped`, como el resto del bloque).
*/
.fc-tarifas .fc-tarifa-recurso {
    display: flex;
    flex-direction: column;
    line-height: 1.15;
}
.fc-tarifas .fc-tarifa-recurso-nombre {
    font-weight: 700;
}
.fc-tarifas .fc-tarifa-recurso-base {
    font-size: 10px;
    font-weight: 700;
    color: #64748b; /* slate-500 */
}

.fc-tarifas .fc-timeline-event,
.fc-tarifas .fc-timeline-event.fc-event-start,
.fc-tarifas .fc-timeline-event.fc-event-end {
    /* El que hace el trabajo es el INFERIOR: cuando una casita tiene varias
       tarifas solapadas, las barras se apilan y FullCalendar las deja pegadas
       (`margin-bottom: 0` de fábrica), que es lo que satura la fila. Los 3 px
       laterales son secundarios: solo despegan dos rangos consecutivos. */
    margin: 2px 3px 7px;
    border-radius: 5px;
}

/*
  El contenido de la barra es STICKY dentro de su propia barra: en un tramo de
  tres semanas, el precio se pintaba al inicio del rango y desaparecía en cuanto
  se desplazaba la línea de tiempo — quedaban barras de color sin un solo dato.
  Pegado a la izquierda del área visible, el precio acompaña al scroll mientras
  la barra siga en pantalla.

  Las dos piezas son imprescindibles:
  - `width: fit-content` — con el ancho completo de la barra no habría margen
    donde desplazarse y `sticky` no haría nada.
  - `max-width: 100%` — sin él, el texto se saldría por la derecha de las barras
    cortas (ni FullCalendar ni `.fc-event-main` recortan).
*/
.fc-tarifa {
    position: sticky;
    left: 0;
    display: flex;
    align-items: center;
    gap: 5px;
    width: fit-content;
    max-width: 100%;
    min-width: 0;
    padding: 0 2px;
    overflow: hidden;
    white-space: nowrap;
}

/* Ficha del tramo embebida en el menú (táctil). Mismo aspecto que el tooltip
   oscuro de tippy, que aquí no está para poner el fondo. */
.fc-tip-menu {
    background: #1e293b;
    color: #fff;
    padding: 8px 12px 10px;
    margin-top: -0.375rem;
    margin-bottom: 0.375rem;
    font-size: 0.78rem;
    line-height: 1.35;
}

.fc-tarifa-ico {
    font-size: 0.62rem;
    opacity: 0.75;
    flex-shrink: 0;
}

.fc-tarifa-star {
    color: #FFD466;
    opacity: 1;
}

.fc-tarifa-precio {
    font-weight: 800;
    font-size: 0.74rem;
    letter-spacing: -0.01em;
    overflow: hidden;
    text-overflow: ellipsis;
}

/*
  Fondo de ocupación. FullCalendar pinta los eventos de fondo con `opacity: .3`
  (regla de `.fc-bg-event`): a ese nivel el beige se perdía contra el blanco de
  la grilla, así que se fuerza la opacidad y el color final lo sigue mandando el
  evento (COLOR_OCUPADO en pmsTarifaModel.ts). El rayado diagonal distingue
  «ocupado» de un simple sombreado de fin de semana sin subir el contraste.
*/
.fc-ocupado {
    opacity: 1 !important;
    background-image: repeating-linear-gradient(
        45deg,
        rgba(0, 0, 0, 0.045) 0 5px,
        transparent 5px 10px
    );
}

/* Badge de estancia mínima: se distingue del precio sin robarle protagonismo. */
.fc-tarifa-min {
    flex-shrink: 0;
    font-size: 0.58rem;
    font-weight: 800;
    line-height: 1;
    padding: 2px 4px;
    border-radius: 4px;
    background: rgba(255, 255, 255, 0.22);
}

/* El botón ↻ de la barra del calendario, mientras recarga. */
:deep(.fc-recargar-button.esta-recargando) {
    animation: girar-recarga 0.6s linear infinite;
}

@keyframes girar-recarga {
    from { transform: rotate(0deg); }
    to { transform: rotate(360deg); }
}
</style>
