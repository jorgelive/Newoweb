<script setup lang="ts">
import { ref, computed, shallowRef, watch, onMounted, onBeforeUnmount } from 'vue';
import { useRouter, useRoute, onBeforeRouteLeave } from 'vue-router';
import FullCalendar from '@fullcalendar/vue3';
import resourceTimelinePlugin from '@fullcalendar/resource-timeline';
import interactionPlugin from '@fullcalendar/interaction';
import esLocale from '@fullcalendar/core/locales/es';
import type {
    CalendarOptions,
    DatesSetArg,
    EventClickArg,
    EventDropArg,
    EventMountArg,
    EventSourceFuncArg,
    EventInput,
} from '@fullcalendar/core';
import type { DateClickArg, EventResizeDoneArg } from '@fullcalendar/interaction';
import type { ResourceFuncArg, ResourceInput } from '@fullcalendar/resource';
import tippy from 'tippy.js';
import type { ReferenceElement } from 'tippy.js';
import 'tippy.js/dist/tippy.css';
import '@/assets/fullcalendar-overrides.css';
import { apiClient, getUrls } from '@/services/apiClient';
import { useReservasStore, extractApiErrorMessage } from '@/stores/reservas/reservasStore';

import AppSwitcher from '@/components/common/AppSwitcher.vue';
import ConversacionVistaPrevia from '@/components/common/ConversacionVistaPrevia.vue';
import ReservaEditDrawer from '@/components/reservas/ReservaEditDrawer.vue';
import WhatsappPlantillasLista from '@/components/reservas/WhatsappPlantillasLista.vue';
import { escaparHtml } from '@/utils/html';
import { scrollTimelineADia, encuadrarTimeline, hoyLocal } from '@/utils/calendarTimeline';
import {
    canalInfo,
    fechaAInputLocal,
    pmsUnidadIri,
    PMS_ESTADO,
    type PmsEventoExtendedProps,
    type PmsReservaBusquedaItem,
} from '@/types/pmsReservaModel';
import { fromDateLocal, sumarDias, type PmsTarifaExtendedProps } from '@/types/pmsTarifaModel';
import {
    coleccionFeed,
    comoError,
    type CalendarEventoFeed,
    type CalendarRecursoFeed,
} from '@/types/calendarFeedModel';
import { telefonoParaWhatsapp } from '@/utils/telefono';
import { useRefrescoDelAsistente } from '@/composables/useRefrescoDelAsistente';

const router = useRouter();
const route = useRoute();
const reservasStore = useReservasStore();

// ============================================================================
// SELECTOR DE CALENDARIO (mismas dos vistas del legacy EasyAdmin)
// ============================================================================
const calendarios = [
    { nombre: 'No canceladas', key: 'pms_eventos_no_cancelados_spa' },
    { nombre: 'Todas', key: 'pms_eventos_todos_spa' },
];
const calendarioIndex = ref(0);
const calendarioActual = computed(() => calendarios[calendarioIndex.value]);

const calendarApiRef = shallowRef<InstanceType<typeof FullCalendar> | null>(null);

// Contenedor del calendario: lo necesita encuadrarTimeline() para alcanzar el
// scroller del cuerpo cuando hay que irse al extremo derecho del rango.
const calendarRootEl = ref<HTMLElement | null>(null);

// Título del mes/semana renderizado FUERA del headerToolbar (igual que el
// legacy en fullcalendar_controller.js): si el título comparte fila con los
// botones, un mes largo ("Julio de 2026") los empuja y todo se parte en varias
// líneas en mobile. Aquí se actualiza a mano en datesSet, como hace el legacy.
const calendarTitulo = ref('');

// ============================================================================
// PERSISTENCIA DE FECHA/VISTA (espejo de fullcalendar_controller.js: mismo
// esquema de clave `fc_state_<pathname>_date` / `_view`) para que al salir y
// volver al calendario, quede en el mes/semana y la vista donde se dejó.
// ============================================================================
const FC_STORAGE_KEY = `fc_state_${window.location.pathname}`;
// Solo las dos vistas de línea de tiempo, las mismas que el calendario de
// Tarifas. Las de Semana y Lista se retiraron: nadie las usaba para operar y
// quien caía en ellas por error se quedaba sin la rejilla de casitas, que es lo
// que se viene a mirar. Un valor guardado de las antiguas no valida y cae al
// por defecto, así que nadie queda atrapado en una vista que ya no existe.
const FC_VISTAS_PERMITIDAS = ['resourceTimelineTwoMonths', 'resourceTimelineOneMonth'];

function fcVistaGuardada(): string {
    const v = localStorage.getItem(`${FC_STORAGE_KEY}_view`);
    return v && FC_VISTAS_PERMITIDAS.includes(v) ? v : 'resourceTimelineOneMonth';
}

function fcFechaGuardada(): string | undefined {
    return localStorage.getItem(`${FC_STORAGE_KEY}_date`) || undefined;
}

/** ¿Se está recargando ahora mismo? Para dar señal visible en el botón. */
const recargando = ref(false);

/**
 * Vuelve a pedir eventos y recursos del rango visible.
 *
 * La usa el botón ↻ de la barra y cualquier sitio que necesite refrescar sin
 * cambiar de mes. `refetchEvents()` no devuelve promesa, así que el indicador se
 * apaga por tiempo: es señal de que se pulsó, no una barra de progreso real.
 */
async function recargarCalendario(): Promise<void> {
    const api = calendarApiRef.value?.getApi();
    if (!api || recargando.value) return;

    recargando.value = true;
    api.refetchResources();
    api.refetchEvents();

    await new Promise((r) => setTimeout(r, 600));
    recargando.value = false;
}

// El asistente escribe en el PMS —«confirma la reserva», «registra el pago»— y el calendario se
// queda viejo. Se registra aquí para recargar SÓLO esto en vez de la página entera.
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

function onCambiarCalendario(): void {
    // `refetchResources()` lo aporta @fullcalendar/resource, que amplía CalendarApi
    // vía `declare module`: está tipado mientras el plugin se importe en el archivo.
    const api = calendarApiRef.value?.getApi();
    if (!api) return;
    api.refetchResources();
    api.refetchEvents();
}

// ============================================================================
// BUSCADOR DE RESERVAS
//
// El calendario solo carga el rango visible: sin buscador no hay forma de dar
// con la reserva de un huésped sin saber de antemano su mes. El backend
// (PmsReservaBuscarController) devuelve una fila por estancia y al elegir una
// el calendario se posiciona en su fecha de inicio.
// ============================================================================
const BUSQUEDA_MIN = 2;

const busquedaTexto = ref('');
const busquedaResultados = ref<PmsReservaBusquedaItem[]>([]);
const busquedaAbierta = ref(false);
const buscando = ref(false);

let busquedaDebounce: ReturnType<typeof setTimeout> | null = null;
// Petición en vuelo: si el usuario sigue tecleando, una respuesta lenta de una
// consulta vieja pisaría en pantalla a la nueva. Se aborta la anterior.
let busquedaAbort: AbortController | null = null;

function onBuscarInput(): void {
    if (busquedaDebounce) clearTimeout(busquedaDebounce);

    if (busquedaTexto.value.trim().length < BUSQUEDA_MIN) {
        busquedaAbort?.abort();
        busquedaAbort = null;
        busquedaResultados.value = [];
        busquedaAbierta.value = false;
        buscando.value = false;
        return;
    }

    buscando.value = true;
    busquedaAbierta.value = true;
    busquedaDebounce = setTimeout(ejecutarBusqueda, 350);
}

async function ejecutarBusqueda(): Promise<void> {
    busquedaAbort?.abort();
    const ctrl = new AbortController();
    busquedaAbort = ctrl;

    try {
        busquedaResultados.value = await reservasStore.buscarReservas(busquedaTexto.value.trim(), ctrl.signal);
    } catch {
        // Abortada = reemplazada por una búsqueda más nueva: no es un error real.
        if (ctrl.signal.aborted) return;
        busquedaResultados.value = [];
        avisar('No se pudo completar la búsqueda de reservas.');
    } finally {
        if (busquedaAbort === ctrl) {
            buscando.value = false;
            busquedaAbort = null;
        }
    }
}

function limpiarBusqueda(): void {
    if (busquedaDebounce) clearTimeout(busquedaDebounce);
    busquedaAbort?.abort();
    busquedaAbort = null;
    busquedaTexto.value = '';
    busquedaResultados.value = [];
    busquedaAbierta.value = false;
    buscando.value = false;
}

onBeforeUnmount(() => {
    if (busquedaDebounce) clearTimeout(busquedaDebounce);
    busquedaAbort?.abort();
});

/** `YYYY-MM-DD` sin el corrimiento de un día de `new Date('YYYY-MM-DD')` (parsea en UTC). */
function formatFechaCorta(iso: string | null): string {
    if (!iso) return '—';
    const [y, m, d] = iso.split('-').map(Number);
    return new Date(y, m - 1, d).toLocaleDateString('es-PE', { day: '2-digit', month: 'short' });
}

// ============================================================================
// SALTO AL DÍA EXACTO
//
// El cálculo del scroll vive en `@/utils/calendarTimeline` (compartido con
// TarifasView, misma rejilla). Aquí queda solo la parte propia de esta vista:
// el rango recién navegado puede no estar renderizado en el instante del salto,
// así que el día queda "pendiente" y se reintenta cuando el calendario avisa:
// `datesSet` (rango ya montado) y `loading` (fin de carga, último intento).
// ============================================================================

/** Día `YYYY-MM-DD` al que hay que desplazarse en cuanto el rango esté listo. */
let diaPendiente: string | null = null;

/** @returns true si se pudo desplazar (el día ya está dentro del rango visible). */
function scrollAlDia(fechaIso: string): boolean {
    const api = calendarApiRef.value?.getApi();
    if (!api) return false;

    const [y, m, d] = fechaIso.split('-').map(Number);
    return scrollTimelineADia(api, new Date(y, m - 1, d));
}

/**
 * Reintenta el salto pendiente después del repintado.
 * `ultimoIntento` lo descarta aunque falle, para que un día que nunca entra en
 * el rango no quede acechando y desplace el calendario en una navegación futura.
 */
function resolverDiaPendiente(ultimoIntento = false): void {
    if (!diaPendiente) return;
    const objetivo = diaPendiente;

    requestAnimationFrame(() => {
        if (diaPendiente !== objetivo) return;
        if (scrollAlDia(objetivo) || ultimoIntento) diaPendiente = null;
    });
}

/** Lleva el calendario a la estancia elegida (mes y día). */
function irAResultado(item: PmsReservaBusquedaItem): void {
    if (!item.inicio) return;

    // El calendario "No canceladas" oculta justo lo que se acaba de encontrar:
    // si la estancia está cancelada, se pasa a "Todas" o el salto acabaría en
    // una fila vacía.
    const debeVerTodas = item.estadoId === PMS_ESTADO.CANCELADA && calendarioActual.value.key !== 'pms_eventos_todos_spa';
    if (debeVerTodas) calendarioIndex.value = calendarios.findIndex(c => c.key === 'pms_eventos_todos_spa');

    calendarApiRef.value?.getApi()?.gotoDate(item.inicio);
    if (debeVerTodas) onCambiarCalendario();

    // Si el mes ya era el visible, `gotoDate` no dispara `datesSet` y no habría
    // reintento: se intenta aquí mismo y solo queda pendiente si aún no cuadra.
    diaPendiente = item.inicio;
    resolverDiaPendiente();

    busquedaAbierta.value = false;
}

// ============================================================================
// DRAWER (ver / edición / creación)
// ============================================================================
const drawerVisible = ref(false);
const drawerEventoId = ref<string | null>(null);
const drawerReservaId = ref<string | null>(null);
const drawerCreateDefaults = ref<{ unidadId: string; inicio: string; fin: string } | null>(null);
const drawerCreateKind = ref<'bloqueo' | 'reserva' | null>(null);
const drawerStartReadOnly = ref(false);

function abrirEdicion(props: PmsEventoExtendedProps, readOnly: boolean): void {
    drawerEventoId.value = props.eventoId;
    drawerReservaId.value = props.reservaId;
    drawerCreateDefaults.value = null;
    drawerCreateKind.value = null;
    drawerStartReadOnly.value = readOnly;
    drawerVisible.value = true;
}

/**
 * Apertura directa de una ficha desde fuera: `/reservas?evento=<id>[&reserva=<id>]`.
 *
 * Es como llega el usuario desde el panel «Hoy» del portal (HomeView), que ya
 * tiene esos ids en el feed del calendario y no necesita que se busque nada.
 * Siempre en modo LECTURA: se viene a consultar la estancia, no a editarla.
 *
 * La query se limpia después para que cerrar la ficha no la reabra al volver
 * atrás, y para que un refresco no reviva un drawer que el usuario ya cerró.
 */
onMounted(() => {
    const evento = typeof route.query.evento === 'string' ? route.query.evento : null;
    if (!evento) return;

    drawerEventoId.value = evento;
    drawerReservaId.value = typeof route.query.reserva === 'string' ? route.query.reserva : null;
    drawerCreateDefaults.value = null;
    drawerCreateKind.value = null;
    drawerStartReadOnly.value = true;
    drawerVisible.value = true;

    router.replace({ path: '/reservas', query: {} });
});

/** Noches que abarca una estancia recién creada (espejo de agregarEvento() en el drawer). */
const RANGO_POR_DEFECTO_DIAS = 2;

function abrirCreacion(unidadId: string, fechaClic: Date, kind: 'bloqueo' | 'reserva'): void {
    const inicio = new Date(fechaClic);
    inicio.setHours(14, 0, 0, 0);
    const fin = new Date(inicio);
    fin.setDate(fin.getDate() + RANGO_POR_DEFECTO_DIAS);
    fin.setHours(10, 0, 0, 0);

    drawerEventoId.value = null;
    drawerReservaId.value = null;
    drawerCreateKind.value = kind;
    drawerStartReadOnly.value = false;
    // Hora de pared, NO `toISOString()`: estas fechas son 14:00/10:00 en recepción, no
    // instantes universales (ver el bloque de fechas en pmsReservaModel.ts).
    drawerCreateDefaults.value = {
        unidadId,
        inicio: fechaAInputLocal(inicio),
        fin: fechaAInputLocal(fin),
    };
    drawerVisible.value = true;
}

function cerrarDrawer(): void {
    drawerVisible.value = false;
    reservasStore.clearActivo();
}

// ============================================================================
// MENÚ CONTEXTUAL (tap/click en evento -> Ver/Editar; tap/click en vacío -> Bloqueo/Reserva)
// ============================================================================
interface MenuState {
    x: number;
    y: number;
    kind: 'event' | 'create' | 'whatsapp' | 'casita';
    eventProps?: PmsEventoExtendedProps;
    unidadId?: string;
    /** Nombre de la casita sobre la que se tocó, para encabezar el menú. */
    unidadNombre?: string;
    /** URL pública del catálogo de esa casita; null si le falta algún slug. */
    catalogoUrl?: string | null;
    fecha?: Date;
}
const menu = ref<MenuState | null>(null);

/**
 * Dispositivo sin puntero que pueda "posarse" (móvil/tablet).
 *
 * En escritorio el tooltip (hover) y el menú (clic) no compiten: el tooltip se
 * va en cuanto el ratón se mueve. En táctil un solo tap dispara LOS DOS, y sobre
 * un evento de las filas de abajo el tooltip flotante caía justo encima del
 * menú. Por eso en táctil el tooltip flotante se desactiva (`touch: false` en
 * tippy) y la misma ficha se pinta DENTRO del menú, como cabecera: se sigue
 * viendo todo de un tap, pero apilado y sin solaparse nunca.
 */
const ES_TACTIL = window.matchMedia('(hover: none)').matches;

// Datos de solo lectura de la reserva del evento seleccionado, cargados al abrir
// el menú (los extendedProps del calendario solo traen reservaId/isOta). Con esto
// el menú muestra "Abrir guía" y el enlace directo al canal (Booking/Airbnb).
const menuReserva = ref<{
    localizador: string | null;
    channelId: string | null;
    urlCanalExtranet: string | null;
    telefono: string | null;
}>({ localizador: null, channelId: null, urlCanalExtranet: null, telefono: null });

/** Conversación directa de WhatsApp con el huésped (sin plantilla). */
const menuWhatsappUrl = computed(() => {
    const numero = telefonoParaWhatsapp(menuReserva.value.telefono);
    return numero ? `https://wa.me/${numero}` : null;
});

/** Enlace público de la guía del huésped (mismo path que el drawer / backend). */
const menuGuideUrl = computed(() =>
    menuReserva.value.localizador ? `${getUrls().pax}/huesped/reserva/${menuReserva.value.localizador}` : null,
);

/** Etiqueta/ícono del enlace al canal. Tabla compartida: ver canalInfo(). */
const otaMenuInfo = computed(() => canalInfo(menuReserva.value.channelId));

function avisar(mensaje: string): void {
    dragError.value = mensaje;
    setTimeout(() => (dragError.value = null), 5000);
}

// Tamaño aproximado del menú, para que no NAZCA fuera de la pantalla; la
// posición definitiva la calcula reubicarMenu() midiendo el panel real.
const MENU_ANCHO = 240;
const MENU_ALTO = 320;
const MENU_BORDE = 8;

/**
 * Ancla el menú al punto del click/tap.
 *
 * En móvil el gesto llega como `TouchEvent`, que NO tiene `clientX/clientY`
 * (viven en `changedTouches`). Al leerlos directo quedaban `undefined` →
 * `NaN` en el style → el navegador descartaba left/top y el menú aparecía
 * pegado a la esquina superior izquierda.
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

// ----------------------------------------------------------------------------
// POSICIÓN DEFINITIVA DEL MENÚ (medida, no estimada)
//
// El alto del panel NO se conoce al abrirlo: la ficha del huésped, el enlace a
// la extranet del canal y la lista de plantillas llegan por fetch DESPUÉS del
// primer render. Con la constante MENU_ALTO sola, un menú abierto abajo se salía
// de la pantalla en cuanto crecía. Un ResizeObserver sobre el panel lo recoloca
// en cada cambio de alto, que es justo cuando hace falta.
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
// Se compara el ancla y no `previo === null` porque el menú también se reasigna
// SIN cerrarse (al pasar a la lista de plantillas de WhatsApp): ahí la posición
// ya ajustada debe mantenerse y dejar que el observer la corrija.
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

function limpiarMenuReserva(): void {
    menuReserva.value = { localizador: null, channelId: null, urlCanalExtranet: null, telefono: null };
}

async function onEventClick(info: EventClickArg): Promise<void> {
    const { x, y } = posicionMenu(info.jsEvent);
    // `extendedProps` es un diccionario abierto en FullCalendar: el contrato real
    // lo fija PmsEventosSpaCalendarProvider::buildContext() en el backend.
    const eventProps = info.event.extendedProps as PmsEventoExtendedProps;
    menu.value = { x, y, kind: 'event', eventProps };
    limpiarMenuReserva();
    if (!eventProps.reservaId) return;
    const reservaId = eventProps.reservaId;
    try {
        const reserva = await reservasStore.fetchReserva(reservaId);
        // Campos aún no tipados en el schema generado (ver Groups en PmsReserva).
        const extra = reserva as { localizador?: string | null; urlCanalExtranet?: string | null };
        // Descartar si el usuario ya cerró/cambió el menú mientras se resolvía.
        if (menu.value?.eventProps?.reservaId !== reservaId) return;
        menuReserva.value = {
            localizador: extra.localizador ?? null,
            channelId: reserva.channel?.id ?? null,
            urlCanalExtranet: extra.urlCanalExtranet ?? null,
            // El número al que se escribe NO está en la reserva: sale de las identidades de
            // la persona, y el backend lo resuelve sabiendo además si está vetado o retirado.
            // Se pide aquí, al abrir el menú, y no serializado en cada fila del calendario.
            telefono: (await reservasStore.fetchTelefonoContacto(reservaId))?.telefono ?? null,
        };
    } catch {
        /* silencioso: los ítems dependientes (guía / canal) simplemente no aparecen */
    }
}

// ============================================================================
// TARIFA DEL DÍA (al abrir el menú sobre un hueco libre)
//
// No hay endpoint nuevo: se reutiliza el calendario compactado de tarifas, que
// es justo "el rango ganador" por día (TarifaPricingEngine resuelve los
// solapamientos por prioridad) y ya filtra por tarifas activas.
// ============================================================================
const menuTarifa = ref<{ cargando: boolean; texto: string | null }>({ cargando: false, texto: null });

async function cargarTarifaDelDia(unidadId: string, fecha: string): Promise<void> {
    menuTarifa.value = { cargando: true, texto: null };
    try {
        // `end` es EXCLUSIVO: [D, D+1) es "solo el día D". Con [D, D] el motor
        // lanza InvalidArgumentException (ver TarifaDailyPriceFlattener::flatten).
        const r = await apiClient.get('/fullcalendar/load/event/tarifa_rangos_compactados_spa', {
            params: { start: fecha, end: sumarDias(fecha, 1), _t: Date.now() },
        });
        const eventos = coleccionFeed<CalendarEventoFeed<PmsTarifaExtendedProps>>(r.data);
        const tramo = eventos.find((e) => String(e.resourceId) === String(unidadId));
        const props = tramo?.extendedProps;

        // Descartar si el usuario ya cerró o movió el menú mientras se resolvía.
        if (menu.value?.kind !== 'create' || menu.value.unidadId !== unidadId) return;

        menuTarifa.value = {
            cargando: false,
            texto: props?.precio
                ? `${props.moneda ?? ''} ${props.precio}`.trim() + (props.minStay ? ` · mín. ${props.minStay}N` : '')
                : null,
        };
    } catch {
        // Silencioso: el menú simplemente muestra "sin tarifa".
        menuTarifa.value = { cargando: false, texto: null };
    }
}

/** Abre el calendario de tarifas posicionado en el día que se estaba mirando. */
function irATarifas(): void {
    const fecha = menu.value?.fecha ? fromDateLocal(menu.value.fecha) : null;
    cerrarMenu();
    router.push({ path: '/tarifas', query: fecha ? { fecha } : {} });
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
    limpiarMenuReserva();
    cargarTarifaDelDia(resourceId, fromDateLocal(info.date));
}

/**
 * Día tocado, en largo: «mar, 11 de agosto de 2026».
 *
 * Encabeza el menú de creación porque en una rejilla de 31 columnas el clic es
 * fácil de errar por una celda, y el resto del menú (tarifa, «Crear Reserva»)
 * no da ninguna pista de sobre qué día se está actuando.
 */
const menuFechaLarga = computed(() => {
    const fecha = menu.value?.fecha;
    if (!fecha) return '';
    return fecha.toLocaleDateString('es-PE', {
        weekday: 'short', day: 'numeric', month: 'long', year: 'numeric',
    });
});

/**
 * Menú al tocar el NOMBRE de la casita (columna izquierda).
 *
 * Los slugs viajan en `extendedProps` del recurso — los pide el bloque
 * `resources.extraFields` del YAML (ver CalendarResourceCatalog) — así que la
 * URL se arma sin una segunda petición. Si a la unidad le falta el slug propio
 * o el de su establecimiento, el menú se abre igual pero sin las acciones: es
 * un dato de configuración que falta, no un error del calendario.
 */
function onClickCasita(jsEvent: MouseEvent, recurso: { id: string; title: string; extendedProps?: Record<string, unknown> }): void {
    const props = recurso.extendedProps ?? {};
    const slug = typeof props.slug === 'string' ? props.slug : null;
    const estSlug = typeof props.establecimientoSlug === 'string' ? props.establecimientoSlug : null;

    const { x, y } = posicionMenu(jsEvent);
    menu.value = {
        x, y,
        kind: 'casita',
        unidadId: recurso.id,
        unidadNombre: recurso.title,
        catalogoUrl: slug && estSlug ? `${getUrls().pax}/${estSlug}/${slug}` : null,
    };
    limpiarMenuReserva();
}

/** Copia al portapapeles el enlace público de la casita. */
const copiadoCasita = ref(false);

async function copiarCatalogoUrl(): Promise<void> {
    const url = menu.value?.catalogoUrl;
    if (!url) return;
    try {
        await navigator.clipboard.writeText(url);
        copiadoCasita.value = true;
        setTimeout(() => { copiadoCasita.value = false; cerrarMenu(); }, 900);
    } catch {
        avisar('No se pudo copiar el enlace.');
        cerrarMenu();
    }
}

function cerrarMenu(): void {
    menu.value = null;
}

// ============================================================================
// BOTÓN "ATRÁS" DEL NAVEGADOR
//
// En móvil el gesto instintivo para cerrar el drawer (o el menú contextual) es
// el "atrás" del sistema. Si hay algo abierto encima del calendario, el back lo
// cierra y se cancela la navegación; solo con el calendario limpio se sale de
// la vista (al portal). Vue Router restaura la posición del historial cuando el
// guard devuelve `false`, así que el back sigue disponible para el siguiente
// intento.
// ============================================================================
onBeforeRouteLeave(() => {
    // La vista previa de la conversación es una capa más, y va primero porque se abre
    // POR ENCIMA (el menú se cierra al lanzarla).
    //
    // ⚠️ Y por eso su botón «Ir al chat» tiene que cerrarla ANTES de navegar: este
    // guard cancela cualquier salida de ruta, no sólo el back, así que un push con la
    // capa abierta se lo tragaría. Lo hace `ConversacionVistaPrevia::irAlChat()`,
    // emitiendo `cerrar` antes del `router.push` — mismo requisito que
    // `ReservaEditDrawer::abrirChatInterno()`.
    if (vistaPrevia.value.abierta) {
        cerrarVistaPrevia();
        return false;
    }
    if (menu.value) {
        cerrarMenu();
        return false;
    }
    if (drawerVisible.value) {
        cerrarDrawer();
        return false;
    }
    if (busquedaAbierta.value) {
        busquedaAbierta.value = false;
        return false;
    }
});

function elegirVerEvento(): void {
    if (menu.value?.eventProps) abrirEdicion(menu.value.eventProps, true);
    cerrarMenu();
}

function elegirEditarEvento(): void {
    if (menu.value?.eventProps) abrirEdicion(menu.value.eventProps, false);
    cerrarMenu();
}

function elegirCrear(kind: 'bloqueo' | 'reserva'): void {
    if (menu.value?.unidadId && menu.value.fecha) abrirCreacion(menu.value.unidadId, menu.value.fecha, kind);
    cerrarMenu();
}

// ============================================================================
// VISTA PREVIA DE LA CONVERSACIÓN
//
// Antes este ítem del menú saltaba DIRECTO al chat, y eso obligaba a abandonar el
// calendario para averiguar algo que casi siempre se resuelve leyendo las cuatro
// últimas líneas: qué se le prometió al huésped, si ya se le contestó, si la
// ventana de 24 h sigue abierta. Ahora primero se asoma —sin marcar nada como
// leído— y el salto al chat es un botón dentro de la propia ventana.
//
// La resolución reserva → conversación la hace el componente por su cuenta a
// partir del contexto; aquí ya no hace falta `fetchConversacionId()`.
// ============================================================================
const vistaPrevia = ref<{ abierta: boolean; reservaId: string | null; x: number; y: number }>({
    abierta: false, reservaId: null, x: 0, y: 0,
});

/** El asunto en la forma que entiende el chat. Null mientras no haya nada abierto. */
const contextoVistaPrevia = computed(() => (
    vistaPrevia.value.reservaId ? { tipo: 'pms_reserva', id: vistaPrevia.value.reservaId } : null
));

/**
 * El ancla como computed y no como objeto en la plantilla: escrito inline
 * (`:ancla="{ x: …, y: … }"`) nace un objeto nuevo en CADA render, y el watch del
 * componente que recoloca la ventana se dispararía en todos ellos.
 */
const anclaVistaPrevia = computed(() => ({ x: vistaPrevia.value.x, y: vistaPrevia.value.y }));

function elegirVerConversacion(): void {
    const reservaId = menu.value?.eventProps?.reservaId;
    // Se hereda la posición ya ajustada del menú: la ventana nace donde estaba el
    // menú que se acaba de cerrar, y no de un salto al otro lado de la pantalla.
    const { x, y } = menuPos.value;
    cerrarMenu();
    if (!reservaId) return;

    vistaPrevia.value = { abierta: true, reservaId, x, y };
}

function cerrarVistaPrevia(): void {
    vistaPrevia.value = { abierta: false, reservaId: null, x: 0, y: 0 };
}

// Las plantillas de WhatsApp (qué sale y cómo se resuelve cada enlace) viven en
// `WhatsappPlantillasLista`, compartido con ReservaEditDrawer. Aquí sólo se cambia de
// submenú; la carga la arranca el componente al montarse.
function elegirAbrirWhatsapp(): void {
    if (menu.value) menu.value = { ...menu.value, kind: 'whatsapp' };
}

function onGuardado(payload?: { reservaIdCreada?: string; cerrar?: boolean }): void {
    reservasStore.clearActivo();

    const api = calendarApiRef.value?.getApi();
    api?.refetchEvents();
    api?.refetchResources();

    // Reserva directa recién creada: en vez de cerrar, el drawer se queda abierto sobre ella
    // en modo edición. Es el único momento en que se puede cargar su información financiera
    // sin tener que volver a buscarla en el calendario (una directa no recibe cargos del canal).
    if (payload?.reservaIdCreada) {
        drawerEventoId.value = null;
        drawerReservaId.value = payload.reservaIdCreada;
        drawerCreateDefaults.value = null;
        drawerCreateKind.value = null;
        drawerStartReadOnly.value = false;
        return;
    }

    // Por defecto el drawer se queda abierto: guardar suele ser el paso previo a otra cosa
    // sobre la misma reserva (cancelar y esperar a Beds24 para poder borrar, ajustar un
    // cargo…). Cerrar es ahora una petición explícita de «Guardar y cerrar».
    if (payload?.cerrar) {
        drawerVisible.value = false;
    }
}

/** La estancia o la reserva ya no existe: el drawer no tiene sobre qué quedarse abierto. */
function onBorrado(): void {
    reservasStore.clearActivo();

    const api = calendarApiRef.value?.getApi();
    api?.refetchEvents();
    api?.refetchResources();

    cerrarDrawer();
}

// ============================================================================
// DRAG & DROP (mover fechas / cambiar unidad directo en el calendario)
// ============================================================================
const dragError = ref<string | null>(null);

async function onEventDrop(info: EventDropArg): Promise<void> {
    const extendedProps = info.event.extendedProps as PmsEventoExtendedProps;
    // `start`/`end` son `Date | null` en el tipo; un evento que se acaba de arrastrar
    // siempre los tiene, pero si faltasen no hay nada que guardar.
    if (!info.event.start || !info.event.end) return;

    try {
        await reservasStore.patchEvento(extendedProps.eventoId, {
            inicio: info.event.start.toISOString(),
            fin: info.event.end.toISOString(),
            // `newResource` solo viene si el arrastre cambió de fila (de casita).
            pmsUnidad: info.newResource ? pmsUnidadIri(info.newResource.id) : undefined,
        });
        dragError.value = null;
    } catch (err) {
        dragError.value = extractApiErrorMessage(err, 'No se pudo mover la reserva.');
        info.revert();
        setTimeout(() => (dragError.value = null), 5000);
    }
}

async function onEventResize(info: EventResizeDoneArg): Promise<void> {
    const extendedProps = info.event.extendedProps as PmsEventoExtendedProps;
    if (!info.event.start || !info.event.end) return;

    try {
        await reservasStore.patchEvento(extendedProps.eventoId, {
            inicio: info.event.start.toISOString(),
            fin: info.event.end.toISOString(),
        });
        dragError.value = null;
    } catch (err) {
        dragError.value = extractApiErrorMessage(err, 'No se pudo redimensionar la reserva.');
        info.revert();
        setTimeout(() => (dragError.value = null), 5000);
    }
}

// ============================================================================
// FULLCALENDAR OPTIONS
// ============================================================================
/**
 * Icono del estado, como primera pastilla de la fila de cifras.
 *
 * Sale del maestro (`pms_evento_estado.icono/color`, vía `PmsEventosSpaCalendarProvider`) y no
 * de una tabla aquí: un estado nuevo dado de alta en el panel se pinta solo, sin recompilar.
 *
 * El color es el del ESTADO, que no siempre es el de la barra —ésta puede venir tintada por el
 * estado de PAGO (`resolveColor()` en el provider)—. Ahí está la gracia: el icono dice algo que
 * el color de fondo ya no siempre dice.
 *
 * Por eso va en pastilla y no suelto sobre la barra, que es como estaba: el rojo de «Cancelada»
 * o el ámbar de «Pendiente» sobre el morado o el azul del fondo no se leían. `.fc-reserva-dato`
 * le da el mismo lienzo plomo claro y opaco que ya usan el saldo y el total, y por el mismo
 * motivo. Se paga un precio: la fila de cifras es la primera que se recorta, así que en una
 * estancia de una noche el estado no llega a verse —ahí queda el tooltip—.
 *
 * 🔒 El saneado del icono y del color vive en `claseIconoEstado()` / `estiloColorEstado()`,
 * porque lo usan también los eventos sin reserva. Ahí está el porqué de cada regla.
 */
function iconoEstadoHtml(p: PmsEventoExtendedProps): string {
    const clase = claseIconoEstado(p);
    if (!clase) return '';

    return `<span class="fc-reserva-dato fc-reserva-estado" title="${escaparHtml(p.estado ?? '')}">`
        + `<i class="${clase}"${estiloColorEstado(p)}></i>`
        + `</span>`;
}

/**
 * La clase de FontAwesome del estado, ya saneada y escapada. `''` si no sirve.
 *
 * 🔒 `icono` es texto que teclea un operador en el CRUD y acaba dentro de un atributo
 * `class`. Se acota a la FORMA de una clase de FontAwesome (letras, dígitos, guiones y
 * espacios) ANTES de escaparlo: escapar protege del cierre de atributo, no de que alguien
 * cuele `fa-x" onload="`. Lo que no encaje no se pinta.
 */
function claseIconoEstado(p: PmsEventoExtendedProps): string {
    const icono = (p.estadoIcono ?? '').trim();
    if (!icono || !/^[a-zA-Z0-9 -]{1,50}$/.test(icono)) return '';

    return escaparHtml(icono);
}

/**
 * El `style` con el color del estado, o `''`.
 *
 * El maestro normaliza a `#RRGGBB` (`PmsEventoEstado::normalizeColor()`), pero el estilo se
 * compone aquí y no se confía en eso: lo que no sea un hex exacto no se pinta.
 */
function estiloColorEstado(p: PmsEventoExtendedProps): string {
    const color = (p.estadoColor ?? '').trim();

    return /^#[0-9A-Fa-f]{6}$/.test(color) ? ` style="color:${color}"` : '';
}

const calendarOptions: CalendarOptions = {
    plugins: [resourceTimelinePlugin, interactionPlugin],
    schedulerLicenseKey: 'CC-Attribution-NonCommercial-NoDerivatives',
    locale: esLocale,
    timeZone: 'local',
    initialView: fcVistaGuardada(),
    initialDate: fcFechaGuardada(),
    nowIndicator: true,
    contentHeight: 'auto',
    editable: true,
    eventStartEditable: true,
    eventDurationEditable: true,

    /**
     * Las estancias sincronizadas por una OTA NO se arrastran ni se redimensionan: sus fechas
     * y su unidad las manda el canal (el backend rechaza el PATCH con 403, ver §3 del doc).
     * Marcarlas `editable: false` aquí evita el arrastre en falso — el usuario ni siquiera ve
     * el cursor de "mover" — en vez de dejarle soltar y revertir con un error.
     */
    eventDataTransform: (evento: EventInput): EventInput => {
        const props = evento.extendedProps as PmsEventoExtendedProps | undefined;
        if (props?.isOta) {
            evento.editable = false;
            evento.startEditable = false;
            evento.durationEditable = false;
        }
        return evento;
    },

    // Red de seguridad por si algún evento llega sin `isOta` resuelto en el transform.
    eventAllow: (_span, evento) => !(evento?.extendedProps as PmsEventoExtendedProps | undefined)?.isOta,
    refetchResourcesOnNavigate: true,
    resourceOrder: 'orden',
    eventOrder: '-prioridadImportante,-duration,start',
    eventOrderStrict: true,
    resourceAreaWidth: '85px',
    resourceAreaHeaderContent: 'Casita',

    // Sin título en el centro: así "today prev,next" y los botones de vista
    // caben en una sola fila incluso en mobile (ver calendarTitulo más arriba).
    headerToolbar: {
        left: 'irHoy prev,next recargar',
        center: '',
        right: 'resourceTimelineTwoMonths,resourceTimelineOneMonth',
    },

    /**
     * Botón «Hoy» propio, y con nombre PROPIO (`irHoy`, no `today`).
     *
     * El de fábrica solo llama a `today()`, que no hace nada si el mes actual ya
     * es el visible: te dejaba mirando el día 1 del mes sin desplazar la línea
     * de tiempo. Y no basta con redefinir `customButtons.today` —aunque el clic
     * sí se sustituye—: FullCalendar decide el estado deshabilitado por el
     * NOMBRE del botón (`!isTodayEnabled && buttonName === 'today'`, con
     * `isTodayEnabled = !rangeContainsMarker(currentRange, now)`), así que el
     * botón seguía apagado justo en el caso que se quería arreglar. Con otro
     * nombre, siempre está activo.
     */
    customButtons: {
        irHoy: {
            text: 'Hoy',
            hint: 'Ir a hoy',
            click: () => {
                const api = calendarApiRef.value?.getApi();
                if (!api) return;
                api.today();
                // El rango puede no estar montado todavía (si hubo salto de mes):
                // mismo mecanismo de reintento que usa la búsqueda de reservas.
                diaPendiente = fromDateLocal(hoyLocal());
                resolverDiaPendiente();
            },
        },

        /**
         * Recarga a mano lo que el calendario ya tiene en pantalla.
         *
         * FullCalendar sólo vuelve a pedir eventos al cambiar de rango, así que una
         * reserva creada desde otro equipo —o desde el canal— no aparece hasta navegar
         * a otro mes y volver. Con la pestaña abierta toda la jornada eso significa
         * estar mirando datos viejos sin saberlo.
         *
         * Recarga también los recursos: si se dio de alta una casita, la fila nueva
         * tampoco estaría.
         */
        recargar: {
            text: '↻',
            hint: 'Recargar reservas y casitas desde el servidor',
            click: () => recargarCalendario(),
        },
    },

    datesSet: (info: DatesSetArg) => {
        const titulo = String(info.view.title || '');
        calendarTitulo.value = titulo ? titulo.charAt(0).toUpperCase() + titulo.slice(1) : '';

        // Persistencia de fecha/vista (ver fcVistaGuardada/fcFechaGuardada arriba).
        const fechaRef: Date | undefined = info.view.currentStart || info.start;
        if (fechaRef) {
            localStorage.setItem(`${FC_STORAGE_KEY}_date`, fechaRef.toISOString().slice(0, 10));
        }
        localStorage.setItem(`${FC_STORAGE_KEY}_view`, info.view.type);

        // El rango ya está montado: si venimos de una búsqueda (o del botón
        // «Hoy»), es el momento de desplazar la línea de tiempo al día exacto.
        // Si no hay nada pendiente, encuadre por defecto del rango — ver
        // encuadrarTimeline(): hoy si cae dentro, y si no el extremo por el que
        // se ha llegado navegando.
        if (diaPendiente) {
            resolverDiaPendiente();
            return;
        }

        const api = info.view.calendar;
        requestAnimationFrame(() => encuadrarTimeline(api, calendarRootEl.value));
    },

    // Fin de la carga de eventos: último intento por si el rango tardó en
    // quedar listo (con el flag de "último" para no dejarlo colgado).
    loading: (cargando: boolean) => {
        if (!cargando) resolverDiaPendiente(true);
    },

    views: {
        resourceTimelineTwoMonths: {
            type: 'resourceTimeline',
            duration: { months: 2 },
            buttonText: '2 Meses',
            slotDuration: '24:00:00',
            // Dos filas de cabecera (mes arriba, día abajo): con ~60 columnas no
            // cabe la etiqueta completa por día. Igual que en Tarifas.
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
            .then((r) => success(coleccionFeed<CalendarRecursoFeed>(r.data)))
            .catch((err: unknown) => failure(comoError(err)));
    },

    events: (
        fetchInfo: EventSourceFuncArg,
        success: (eventos: EventInput[]) => void,
        failure: (error: Error) => void,
    ) => {
        apiClient.get(`/fullcalendar/load/event/${calendarioActual.value.key}`, {
            params: { start: fetchInfo.startStr, end: fetchInfo.endStr, _t: Date.now() },
        })
            .then((r) => success(coleccionFeed<CalendarEventoFeed<PmsEventoExtendedProps>>(r.data)))
            .catch((err: unknown) => failure(comoError(err)));
    },

    /**
     * FullCalendar no expone un `resourceLabelClick`, así que el listener se ata
     * al montar la celda. `resourceLabelWillUnmount` no hace falta: FullCalendar
     * descarta el nodo entero al re-renderizar, y con él su listener.
     */
    resourceLabelDidMount: (arg) => {
        arg.el.style.cursor = 'pointer';
        arg.el.title = 'Opciones de la casita';
        arg.el.addEventListener('click', (ev) => onClickCasita(ev, {
            id: arg.resource.id,
            title: arg.resource.title,
            extendedProps: arg.resource.extendedProps,
        }));
    },

    dateClick: onDateClick,
    eventClick: onEventClick,

    eventDrop: onEventDrop,
    eventResize: onEventResize,

    /**
     * Contenido de la barra, en DOS filas: arriba el nombre, abajo las cifras
     * (pax, noches, total y saldo), con el icono del canal centrado a la
     * izquierda ocupando el alto completo.
     *
     * Por qué dos filas y no una: en una sola línea sólo cabían canal + pax +
     * nombre, y el dato que de verdad se busca al mirar el calendario —cuánto
     * falta por cobrar— obligaba a abrir la reserva o esperar al tooltip. El
     * coste es que la fila del calendario es más alta; a cambio la información
     * de una estancia se lee sin interacción.
     *
     * El `title` de texto que manda el provider («A x8 | Nombre | Casita») se
     * mantiene como respaldo si el evento llegara sin datos sueltos.
     */
    eventContent: (arg) => {
        const p = arg.event.extendedProps as PmsEventoExtendedProps;
        // Un evento SIN reserva —un bloqueo de mantenimiento, típicamente— también va a dos
        // filas, y por el mismo motivo que una estancia: tiene dos cosas que decir.
        //
        // Antes se despachaba con una línea suelta que pintaba el `title`, y ese título es un
        // «Evento (Bloqueo)» que fabrica `buildTitle()`: repite el estado, que el color de la
        // barra y el icono ya dicen. Mientras tanto la DESCRIPCIÓN —«Pintado»— que es el único
        // dato propio del bloqueo, sólo se veía abriendo el tooltip.
        //
        // Misma anatomía que una estancia para que el calendario no hable dos idiomas: icono a
        // la izquierda, qué es arriba, el detalle abajo. El icono es el del ESTADO y no el del
        // canal: un bloqueo es siempre «directo» y ese apretón de manos no dice nada aquí.
        if (!p?.cliente) {
            const clase = p ? claseIconoEstado(p) : '';
            const estilo = p ? estiloColorEstado(p) : '';
            const descripcion = (p?.descripcion ?? '').trim();

            return {
                html: `<div class="fc-reserva">`
                    + (clase ? `<i class="${clase} fc-reserva-canal"${estilo}></i>` : '')
                    + `<span class="fc-reserva-col">`
                    + `<span class="fc-reserva-nombre">${escaparHtml(p?.estado ?? arg.event.title)}</span>`
                    + (descripcion
                        ? `<span class="fc-reserva-meta fc-reserva-motivo">${escaparHtml(descripcion)}</span>`
                        : '')
                    + `</span>`
                    + `</div>`,
            };
        }

        const canal = canalInfo(p.canalId);

        // Cada dato es opcional por separado: un bloqueo no trae cifras y una
        // reserva recién creada tampoco. La fila se arma con lo que haya.
        const meta = [
            // El estado abre la fila: es lo que primero se busca al barrer el
            // calendario, y así es lo último que se recorta de la fila.
            iconoEstadoHtml(p),
            // Horario extra: esas noches están bloqueadas en el canal aunque la
            // barra no llegue hasta ellas (ver getInicioCanal() y getFinCanal()
            // en PmsEventoCalendario).
            //
            // Las dos son la misma bandera y el ámbar de `.fc-reserva-tarde` ya
            // dice «fuera de horario», así que al glifo sólo le toca decir POR QUÉ
            // EXTREMO. De ahí el par entrar/salir del umbral: misma silueta y
            // sentido opuesto, que a 0.58rem es lo único que se distingue de un
            // vistazo. Antes eran una puerta y un reloj —dos dibujos sin relación
            // entre sí, y ninguno decía si era el principio o el final—.
            p.entradaTemprana ? '<span class="fc-reserva-dato fc-reserva-tarde" title="Entrada temprana"><i class="fas fa-right-to-bracket"></i></span>' : '',
            p.salidaTardia ? '<span class="fc-reserva-dato fc-reserva-tarde" title="Salida tardía"><i class="fas fa-right-from-bracket"></i></span>' : '',
            p.pax ? `<span class="fc-reserva-dato fc-reserva-num"><i class="fas fa-user"></i>${escaparHtml(String(p.pax))}</span>` : '',
            p.noches ? `<span class="fc-reserva-dato fc-reserva-num"><i class="fas fa-moon"></i>${escaparHtml(String(p.noches))}</span>` : '',
            p.total ? `<span class="fc-reserva-dato fc-reserva-total">${escaparHtml(importeCorto(p.total, p.simbolo))}</span>` : '',
            saldoPill(p),
        ].filter(Boolean);

        const filaMeta = meta.length ? `<span class="fc-reserva-meta">${meta.join('')}</span>` : '';

        return {
            html: `<div class="fc-reserva">`
                + `<i class="${canal.icono} fc-reserva-canal" title="${escaparHtml(canal.texto)}"></i>`
                + `<span class="fc-reserva-col">`
                + `<span class="fc-reserva-nombre">${escaparHtml(p.cliente)}</span>`
                + filaMeta
                + `</span>`
                + `</div>`,
        };
    },

    eventDidMount: (info: EventMountArg) => {
        const p = info.event.extendedProps as PmsEventoExtendedProps;

        // El provider sigue mandando `tooltip` como lista de líneas de texto: es
        // el respaldo si el evento llegara sin los datos sueltos.
        const tooltipContent = info.event.extendedProps.tooltip as string | string[] | undefined;
        const respaldo = Array.isArray(tooltipContent) ? tooltipContent.join('<br>') : (tooltipContent || info.event.title);

        // `_tippy` lo cuelga tippy del propio elemento (ver ReferenceElement en sus
        // tipos); se destruye antes para no apilar instancias en cada re-render.
        const el = info.el as ReferenceElement;
        el._tippy?.destroy();
        tippy(info.el, {
            content: p?.cliente ? tooltipHtml(p) : respaldo,
            allowHTML: true,
            appendTo: document.body,
            placement: 'top',
            // En táctil esta misma ficha va dentro del menú (ver ES_TACTIL).
            touch: false,
        });
        info.el.style.cursor = 'pointer';
    },
};

/**
 * Importe SIN decimales para la barra del calendario.
 *
 * En una barra de 60 px «S/1,250.00» no cabe y «S/1,250» sí; los céntimos, que
 * ahí no aportan nada, se leen exactos en el tooltip y en el panel financiero.
 * El backend manda el decimal en texto para no perder precisión en el JSON, así
 * que el redondeo es sólo de presentación.
 */
function importeCorto(monto: string, simbolo?: string | null): string {
    const n = Number(monto);
    if (!Number.isFinite(n)) return '';

    return `${simbolo ?? ''}${Math.round(n).toLocaleString('es-PE')}`;
}

/**
 * Saldo como pastilla, que es el dato que se busca al barrer el calendario.
 *
 * Sigue el código contable del panel financiero —rojo lo que se debe, azul lo
 * saldado—, que aquí se puede usar porque la pastilla lleva fondo plomo claro
 * propio: sobre el morado o el azul de la barra, un rojo directo no se leería.
 */
function saldoPill(p: PmsEventoExtendedProps): string {
    if (!p.saldo) return '';

    const n = Number(p.saldo);
    if (!Number.isFinite(n)) return '';

    // 🎨 El color lo decide el BACKEND, no una comparación contra cero aquí.
    //
    // `cuadra` viene del cuadre, que respeta la tolerancia del cambio: sin esto, una reserva
    // pagada en soles una deuda en dólares se pintaba de rojo por diez céntimos de redondeo.
    // El `n <= 0.005` sólo queda como respaldo para eventos que aún no traen el campo.
    const cobrada = p.cuadra ?? (n <= 0.005);

    // `≈` cuando la cifra pasó por un tipo de cambio: es lo que separa «debe esto» de «debe
    // aproximadamente esto». El desglose exacto está en el tooltip.
    const marca = p.convertido ? '≈' : '';
    const titulo = p.convertido
        ? (cobrada ? 'Cobrada · dos monedas, importe aproximado' : 'Saldo pendiente · dos monedas, importe aproximado')
        : (cobrada ? 'Cobrada' : 'Saldo pendiente');

    return `<span class="fc-reserva-dato ${cobrada ? 'fc-reserva-pagado' : 'fc-reserva-debe'}"`
        + ` title="${titulo}">`
        + escaparHtml(marca + importeCorto(p.saldo, p.simbolo))
        + `</span>`;
}

/**
 * Tooltip con formato: cabecera con el canal y una rejilla etiqueta/valor.
 *
 * Sustituye a las líneas «Estado: X» en texto plano — con cuatro o cinco datos
 * apilados, el prefijo repetido pesaba más que el dato. tippy va con
 * `allowHTML: true`, así que todo valor pasa por escaparHtml().
 */
function tooltipHtml(p: PmsEventoExtendedProps): string {
    const canal = canalInfo(p.canalId);

    const fila = (etiqueta: string, valor?: string | number | null): string => {
        if (valor === null || valor === undefined || valor === '') return '';
        return `<div class="fc-tip-fila">`
            + `<span class="fc-tip-et">${escaparHtml(etiqueta)}</span>`
            + `<span class="fc-tip-val">${escaparHtml(String(valor))}</span>`
            + `</div>`;
    };

    return `
        <div class="fc-tip">
            <div class="fc-tip-cab">
                <i class="${canal.icono}"></i>
                <span>${escaparHtml(p.cliente ?? '')}</span>
            </div>
            ${fila('Casita', p.unidad)}
            ${fila('Pax', p.pax)}
            ${fila('Noches', p.noches)}
            ${fila('Estado', p.estado)}
            ${fila('Pago', p.estadoPago)}
            ${fila(canal.texto, p.referenciaCanal)}
            ${p.total ? fila('Total', `${p.simbolo ?? ''}${p.total}`) : ''}
            ${p.saldo ? fila('Saldo', `${p.simbolo ?? ''}${p.saldo}`) : ''}
        </div>`;
}
</script>

<template>
    <div class="h-screen bg-[#F8FAFC] flex flex-col font-sans overflow-hidden">
        <header class="bg-slate-900 text-white px-4 md:px-6 py-3 flex items-center justify-between z-20 shadow-md shrink-0">
            <div class="flex items-center gap-3">
                <AppSwitcher />
                <div class="overflow-hidden">
                    <h1 class="font-black text-base md:text-xl tracking-tight leading-none">Calendario de Reservas</h1>
                    <p class="text-[10px] md:text-[11px] font-bold text-slate-400 uppercase tracking-widest mt-1">
                        PMS · Estancias y Bloqueos
                    </p>
                </div>
            </div>

            <select v-model.number="calendarioIndex" @change="onCambiarCalendario"
                class="bg-slate-800 text-white text-xs font-bold rounded-lg px-3 py-2 border border-slate-700">
                <option v-for="(cal, i) in calendarios" :key="cal.key" :value="i">{{ cal.nombre }}</option>
            </select>
        </header>

        <!-- Capta el clic fuera para cerrar los resultados (bajo la barra, sobre el calendario) -->
        <div v-if="busquedaAbierta" class="fixed inset-0 z-20" @click="busquedaAbierta = false"></div>

        <!-- BUSCADOR: huésped, localizador, referencia del canal o casita -->
        <div class="relative z-30 bg-white border-b border-slate-200 px-3 md:px-4 py-2 shrink-0">
            <div class="relative max-w-xl">
                <i class="fas fa-search absolute left-3 top-1/2 -translate-y-1/2 text-slate-400 text-xs"></i>
                <input v-model="busquedaTexto" @input="onBuscarInput"
                    @focus="busquedaResultados.length && (busquedaAbierta = true)"
                    @keyup.esc="busquedaAbierta = false"
                    type="text" inputmode="search" autocomplete="off"
                    placeholder="Buscar reserva por nombre y apellido…"
                    class="w-full bg-slate-50 border border-slate-200 rounded-xl pl-9 pr-9 py-2 text-sm font-bold text-slate-700 placeholder:font-medium placeholder:text-slate-400 outline-none focus:bg-white focus:border-[#376875] focus:ring-2 focus:ring-[#376875]/20 transition-colors">
                <button v-if="busquedaTexto" @click="limpiarBusqueda" title="Limpiar"
                    class="absolute right-2 top-1/2 -translate-y-1/2 w-6 h-6 flex items-center justify-center text-slate-400 hover:text-slate-700 rounded-full hover:bg-slate-100">
                    <i class="fas fa-times text-xs"></i>
                </button>

                <!-- Resultados -->
                <div v-if="busquedaAbierta"
                    class="absolute left-0 right-0 top-full mt-2 bg-white rounded-xl shadow-2xl border border-slate-200 overflow-hidden max-h-[60vh] overflow-y-auto">

                    <div v-if="buscando" class="flex items-center gap-2.5 px-4 py-3 text-sm font-bold text-slate-400">
                        <i class="fas fa-spinner fa-spin w-4"></i> Buscando…
                    </div>

                    <p v-else-if="!busquedaResultados.length" class="px-4 py-3 text-xs font-bold text-slate-400">
                        Sin reservas que coincidan con «{{ busquedaTexto }}».
                    </p>

                    <template v-else>
                        <p class="px-4 py-2 text-[10px] font-black text-slate-400 uppercase tracking-widest border-b border-slate-100">
                            {{ busquedaResultados.length }} estancia{{ busquedaResultados.length !== 1 ? 's' : '' }} · toca para ir a esa fecha
                        </p>
                        <button v-for="r in busquedaResultados" :key="r.eventoId" @click="irAResultado(r)"
                            class="w-full flex items-start gap-3 px-4 py-2.5 text-left hover:bg-slate-50 border-b border-slate-50 last:border-0">
                            <span class="mt-1.5 w-2.5 h-2.5 rounded-full shrink-0 border border-black/10"
                                :style="{ backgroundColor: r.color || '#94a3b8' }"></span>
                            <div class="min-w-0 flex-1">
                                <p class="text-sm font-black text-slate-800 truncate">
                                    {{ r.cliente || 'Sin nombre' }}
                                </p>
                                <p class="text-[11px] font-bold text-slate-600 mt-0.5">
                                    <i class="far fa-calendar mr-1 text-slate-400"></i>
                                    {{ formatFechaCorta(r.inicio) }} → {{ formatFechaCorta(r.fin) }}
                                    <span class="text-slate-300 mx-1">·</span>{{ r.noches }}N
                                    <template v-if="r.unidad"><span class="text-slate-300 mx-1">·</span>{{ r.unidad }}</template>
                                </p>
                                <p class="text-[10px] font-bold text-slate-400 mt-0.5 flex flex-wrap items-center gap-x-2">
                                    <span v-if="r.estado" class="uppercase tracking-widest">{{ r.estado }}</span>
                                    <span v-if="r.pax"><i class="fas fa-users mr-1"></i>{{ r.pax }}</span>
                                    <span v-if="r.localizador">#{{ r.localizador }}</span>
                                    <span v-if="r.canal" class="uppercase">{{ r.canal }}</span>
                                </p>
                            </div>
                            <i class="fas fa-arrow-right text-slate-300 text-xs mt-1.5 shrink-0"></i>
                        </button>
                    </template>
                </div>
            </div>
        </div>

        <div v-if="dragError" class="bg-rose-50 border-b border-rose-200 text-rose-700 text-sm font-bold px-4 py-2">
            <i class="fas fa-exclamation-triangle mr-2"></i>{{ dragError }}
        </div>

        <main ref="calendarRootEl" class="flex-1 overflow-y-auto p-3 md:p-4">
            <h3 v-if="calendarTitulo" class="text-center font-black text-slate-800 uppercase tracking-wide text-sm mb-2">
                {{ calendarTitulo }}
            </h3>
            <FullCalendar ref="calendarApiRef" :options="calendarOptions" />
        </main>

        <!-- Menú contextual: Ver/Editar sobre un evento, Bloqueo/Reserva sobre espacio vacío -->
        <div v-if="menu" class="fixed inset-0 z-30" @click="cerrarMenu" @contextmenu.prevent="cerrarMenu">
            <div ref="menuEl"
                class="absolute bg-white rounded-xl shadow-2xl border border-slate-200 overflow-hidden py-1.5 max-w-[calc(100vw-1rem)] max-h-[85vh] overflow-y-auto"
                :class="menu.kind === 'whatsapp' ? 'w-70' : 'w-52'"
                :style="{ left: menuPos.x + 'px', top: menuPos.y + 'px' }" @click.stop>
                <template v-if="menu.kind === 'event'">
                    <!-- Ficha del huésped SOLO en táctil: en escritorio ya la da el
                         tooltip al pasar el ratón. `v-html` con el mismo generador que
                         usa tippy — todo valor sale escapado de tooltipHtml(). -->
                    <!-- eslint-disable vue/no-v-html -- Lo arma `tooltipHtml()`, que pasa cada valor por `escaparHtml()`. -->
                    <div v-if="ES_TACTIL && menu.eventProps?.cliente" class="fc-tip-menu"
                        v-html="tooltipHtml(menu.eventProps)"></div>
                    <!-- eslint-enable vue/no-v-html -->
                    <button @click="elegirVerEvento"
                        class="w-full flex items-center gap-2.5 px-4 py-2.5 text-sm font-bold text-slate-700 hover:bg-slate-50">
                        <i class="fas fa-eye w-4 text-slate-400"></i> Ver
                    </button>
                    <button @click="elegirEditarEvento"
                        class="w-full flex items-center gap-2.5 px-4 py-2.5 text-sm font-bold text-slate-700 hover:bg-slate-50">
                        <i class="fas fa-pen w-4 text-slate-400"></i> Editar
                    </button>
                    <template v-if="menu.eventProps?.reservaId">
                        <div class="my-1 border-t border-slate-100"></div>
                        <a v-if="menuGuideUrl" :href="menuGuideUrl" target="_blank" rel="noopener" @click="cerrarMenu"
                            class="w-full flex items-center gap-2.5 px-4 py-2.5 text-sm font-bold text-slate-700 hover:bg-slate-50">
                            <i class="fas fa-book-open w-4 text-slate-400"></i> Abrir guía
                        </a>
                        <!-- Las tres vías de contacto con el huésped, agrupadas: la
                             lista corrida no dejaba ver que «guía» es otra cosa. -->
                        <div v-if="menuGuideUrl" class="my-1 border-t border-slate-100"></div>
                        <p class="px-4 py-1.5 text-[10px] font-black text-slate-400 uppercase tracking-widest">
                            Mensajería
                        </p>
                        <button @click="elegirVerConversacion"
                            class="w-full flex items-center gap-2.5 px-4 py-2.5 text-sm font-bold text-slate-700 hover:bg-slate-50">
                            <i class="fas fa-eye w-4 text-slate-400"></i> Ver conversación
                        </button>
                        <a v-if="menuWhatsappUrl" :href="menuWhatsappUrl" target="_blank" rel="noopener" @click="cerrarMenu"
                            class="w-full flex items-center gap-2.5 px-4 py-2.5 text-sm font-bold text-slate-700 hover:bg-slate-50">
                            <i class="fab fa-whatsapp w-4 text-emerald-500"></i> Abrir conversación
                        </a>
                        <button @click="elegirAbrirWhatsapp"
                            class="w-full flex items-center gap-2.5 px-4 py-2.5 text-sm font-bold text-slate-700 hover:bg-slate-50">
                            <i class="fas fa-paper-plane w-4 text-slate-400"></i> Enviar plantilla
                        </button>
                        <!-- Fuera del grupo de mensajería: lleva a la extranet del canal. -->
                        <div v-if="menu.eventProps?.isOta && menuReserva.urlCanalExtranet" class="my-1 border-t border-slate-100"></div>
                        <a v-if="menu.eventProps?.isOta && menuReserva.urlCanalExtranet"
                            :href="menuReserva.urlCanalExtranet" target="_blank" rel="noopener" @click="cerrarMenu"
                            class="w-full flex items-center gap-2.5 px-4 py-2.5 text-sm font-bold text-slate-700 hover:bg-slate-50">
                            <i :class="otaMenuInfo.icono" class="w-4 text-slate-400"></i> {{ otaMenuInfo.texto }}
                        </a>
                    </template>
                </template>
                <template v-else-if="menu.kind === 'casita'">
                    <p class="px-4 pt-2 pb-2 text-sm font-black text-slate-800 border-b border-slate-100 truncate">
                        <i class="fas fa-home mr-1.5 text-slate-300"></i>{{ menu.unidadNombre }}
                    </p>

                    <template v-if="menu.catalogoUrl">
                        <a :href="menu.catalogoUrl" target="_blank" rel="noopener" @click="cerrarMenu"
                            class="w-full flex items-center gap-2.5 px-4 py-2.5 text-sm font-bold text-slate-700 hover:bg-slate-50">
                            <i class="fas fa-store w-4 text-slate-400"></i> Abrir catálogo
                        </a>
                        <button @click="copiarCatalogoUrl"
                            class="w-full flex items-center gap-2.5 px-4 py-2.5 text-sm font-bold hover:bg-slate-50"
                            :class="copiadoCasita ? 'text-emerald-600' : 'text-slate-700'">
                            <i class="fas w-4" :class="copiadoCasita ? 'fa-check text-emerald-500' : 'fa-link text-slate-400'"></i>
                            {{ copiadoCasita ? 'Enlace copiado' : 'Copiar enlace' }}
                        </button>
                    </template>

                    <p v-else class="px-4 py-2.5 text-xs font-bold text-amber-600">
                        <i class="fas fa-triangle-exclamation mr-1"></i>
                        Esta casita no tiene slug configurado, así que no tiene página pública.
                    </p>
                </template>
                <template v-else-if="menu.kind === 'whatsapp'">
                    <p class="px-4 py-2 text-[10px] font-black text-slate-400 uppercase tracking-widest">
                        Elegir Plantilla
                    </p>
                    <div class="border-t border-slate-100"></div>
                    <!-- Las filas y las reglas (qué plantillas salen, cómo se resuelve el
                         enlace) las pone el componente compartido con ReservaEditDrawer. -->
                    <WhatsappPlantillasLista v-if="menu.eventProps?.reservaId"
                        :reserva-id="menu.eventProps.reservaId"
                        @elegido="cerrarMenu"
                        @error="avisar"
                    />
                </template>
                <template v-else>
                    <!-- Sobre qué se está actuando. En una rejilla de 31 columnas el
                         clic se falla por una celda con facilidad, y ni la tarifa ni
                         «Crear Reserva» dicen a qué día y casita apuntan. -->
                    <div class="px-4 pt-2 pb-2.5 border-b border-slate-100">
                        <p class="text-sm font-black text-slate-800 leading-tight truncate">
                            <i class="fas fa-home mr-1.5 text-slate-300"></i>{{ menu.unidadNombre || 'Casita' }}
                        </p>
                        <p class="text-[11px] font-bold text-slate-500 mt-0.5 first-letter:uppercase">
                            {{ menuFechaLarga }}
                        </p>
                    </div>

                    <!-- Precio vigente de esa casita ese día (rango ganador) -->
                    <div class="px-4 py-2 border-b border-slate-100">
                        <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest">Tarifa del día</p>
                        <p v-if="menuTarifa.cargando" class="text-xs font-bold text-slate-400 mt-0.5">
                            <i class="fas fa-spinner fa-spin mr-1"></i> Consultando…
                        </p>
                        <p v-else-if="menuTarifa.texto" class="text-sm font-black text-slate-800 mt-0.5">
                            {{ menuTarifa.texto }}
                        </p>
                        <p v-else class="text-xs font-bold text-amber-600 mt-0.5">
                            <i class="fas fa-triangle-exclamation mr-1"></i> Sin tarifa configurada
                        </p>
                    </div>

                    <button @click="elegirCrear('bloqueo')"
                        class="w-full flex items-center gap-2.5 px-4 py-2.5 text-sm font-bold text-slate-700 hover:bg-slate-50">
                        <i class="fas fa-ban w-4 text-slate-400"></i> Crear Bloqueo
                    </button>
                    <button @click="elegirCrear('reserva')"
                        class="w-full flex items-center gap-2.5 px-4 py-2.5 text-sm font-bold text-slate-700 hover:bg-slate-50">
                        <i class="fas fa-calendar-plus w-4 text-slate-400"></i> Crear Reserva
                    </button>
                    <button @click="irATarifas"
                        class="w-full flex items-center gap-2.5 px-4 py-2.5 text-sm font-bold text-slate-700 hover:bg-slate-50 border-t border-slate-100">
                        <i class="fas fa-tags w-4 text-slate-400"></i> Editar tarifas
                    </button>
                </template>
            </div>
        </div>

        <ReservaEditDrawer
            v-if="drawerVisible"
            :evento-id="drawerEventoId"
            :reserva-id="drawerReservaId"
            :create-defaults="drawerCreateDefaults"
            :create-kind="drawerCreateKind"
            :start-read-only="drawerStartReadOnly"
            @close="cerrarDrawer"
            @saved="onGuardado"
            @deleted="onBorrado"
        />

        <!-- Se le da el asunto y se ocupa de todo: resolver la conversación de esa
             reserva, traer el historial sin marcarlo como leído y ofrecer el salto al
             chat. Modo `fijado` porque se abre desde un ítem del menú, no al pasar por
             encima. -->
        <ConversacionVistaPrevia
            :abierta="vistaPrevia.abierta"
            :ancla="anclaVistaPrevia"
            :contexto="contextoVistaPrevia"
            modo="fijado"
            @cerrar="cerrarVistaPrevia"
        />
    </div>
</template>

<!--
  Sin `scoped`: FullCalendar inyecta el HTML de `eventContent` y tippy monta su
  tooltip en `document.body` (`appendTo`), así que ninguno de los dos lleva el
  atributo de scope que Vue pone a lo que compila él. Las clases van prefijadas
  con `fc-` para no colisionar, igual que en assets/fullcalendar-overrides.css.
-->
<style>
/* ── Barra del evento ───────────────────────────────────────────── */
/* Dos filas: el icono del canal a la izquierda, centrado sobre el alto
   completo, y a su derecha una columna con nombre arriba y cifras abajo. */
.fc-reserva {
    display: flex;
    align-items: center;
    gap: 6px;
    min-width: 0;
    padding: 1px 3px;
    overflow: hidden;
    white-space: nowrap;
}

/*
  En la línea de tiempo el contenido de la barra es STICKY: una estancia de dos
  semanas pintaba el nombre al principio del rango, así que bastaba desplazar el
  calendario para quedarse mirando barras de color sin un solo dato. Pegado al
  borde izquierdo del área visible, nombre y cifras acompañan al scroll mientras
  la estancia siga en pantalla.

  Las tres declaraciones son obligatorias juntas (el mismo mecanismo que
  `.fc-tarifa` en TarifasView; el porqué de cada una, en
  docs/Calendar_architecture.md §12): sin `width: fit-content` no hay margen
  donde desplazarse y `sticky` no hace NADA, sin `max-width: 100%` el texto se
  sale por la derecha de las barras cortas.

  Acotado a `.fc-timeline-event` a propósito: en las vistas Mes y Lista no hay
  scroll horizontal, así que ahí sólo cambiaría cómo se recorta el contenido.
*/
.fc-timeline-event .fc-reserva {
    position: sticky;
    left: 0;
    width: fit-content;
    max-width: 100%;
}

/* Más grande que antes porque ahora tiene dos filas de alto que ocupar: a
   0.68rem quedaba flotando arriba y perdía su papel de ancla visual. */
.fc-reserva-canal {
    font-size: 0.95rem;
    opacity: 0.9;
    flex-shrink: 0;
}

.fc-reserva-col {
    display: flex;
    flex-direction: column;
    justify-content: center;
    gap: 3px; /* respiro entre el nombre y la fila de cifras */
    min-width: 0; /* sin esto el flex no deja que los hijos se recorten */
    line-height: 1.15;
}

.fc-reserva-nombre {
    font-weight: 600;
    font-size: 0.72rem;
    overflow: hidden;
    text-overflow: ellipsis;
}

/* La fila de cifras es la primera en sacrificarse: en una estancia de una noche
   la barra es estrechísima y se recorta entera antes que el nombre. */
.fc-reserva-meta {
    display: flex;
    align-items: center;
    gap: 4px;
    min-width: 0;
    overflow: hidden;
    font-size: 0.58rem;
    font-weight: 700;
    line-height: 1;
    opacity: 0.95;
}

/* Fondo plomo claro OPACO, no un blanco translúcido: al ser un lienzo neutro
   propio, el color del texto ya no depende del morado o el azul de la barra, y
   eso es lo que permite usar aquí el código contable del panel financiero. */
.fc-reserva-dato {
    display: inline-flex;
    align-items: center;
    gap: 2px;
    flex-shrink: 0;
    padding: 2px 4px;
    border-radius: 4px;
    background: #eef0f3;
    color: #334155;
}

.fc-reserva-dato i {
    font-size: 0.85em;
    opacity: 0.7;
}

/* El motivo de un bloqueo ocupa la segunda fila entera, donde una estancia lleva
   sus pastillas. Va como TEXTO y no en pastilla: no compite con nada al lado, y
   el lienzo propio de `.fc-reserva-dato` existe para que un rojo o un verde se
   lean sobre la barra — aquí no hay código de color que defender. Se recorta con
   puntos suspensivos porque un «Mantenimiento del calentador» no cabe. */
.fc-reserva-motivo {
    display: block;
    overflow: hidden;
    text-overflow: ellipsis;
    font-weight: 600;
    opacity: 0.85;
}

/* La pastilla del estado es sólo el glifo, sin cifra al lado: se le quita el
   `gap` y se aprieta el padding lateral para que no quede un óvalo con aire.
   El icono va a plena opacidad y algo mayor que el resto —al contrario que el
   `.fc-reserva-dato i` de arriba, que atenúa iconos que sólo acompañan a un
   número—: aquí el glifo ES el dato, y su color es el del estado. */
.fc-reserva-estado {
    gap: 0;
    padding: 2px 3px;
}

.fc-reserva-estado i {
    font-size: 1em;
    opacity: 1;
}

/* Pax y noches un punto por encima del resto de la fila: son los dos números
   que más se consultan de un vistazo. */
.fc-reserva-num {
    font-size: 0.66rem;
}

.fc-reserva-total {
    color: #15803d;
}

/* Rojo lo que se debe, azul lo saldado — mismo código que el panel financiero. */
.fc-reserva-debe {
    color: #b91c1c;
}

.fc-reserva-tarde {
    color: #92400e;
    background: #fde68a;
}

.fc-reserva-pagado {
    color: #1d4ed8;
}

/* ── Tooltip ────────────────────────────────────────────────────── */
.fc-tip {
    text-align: left;
    min-width: 170px;
}

/*
  La misma ficha, pero embebida como cabecera del menú en táctil. El fondo
  oscuro lo ponía `.tippy-box`; dentro del menú blanco hay que repetirlo aquí o
  el texto claro quedaría ilegible. `-mt-1.5` equivalente para comerse el
  padding superior del panel y quedar pegada al borde redondeado.
*/
.fc-tip-menu {
    background: #1e293b;
    color: #fff;
    padding: 8px 12px 10px;
    margin-top: -0.375rem;
    margin-bottom: 0.375rem;
    font-size: 0.8rem;
    line-height: 1.35;
}

.fc-tip-cab {
    display: flex;
    align-items: center;
    gap: 6px;
    font-weight: 800;
    padding-bottom: 5px;
    margin-bottom: 5px;
    border-bottom: 1px solid rgba(255, 255, 255, 0.18);
}

.fc-tip-fila {
    display: flex;
    align-items: baseline;
    justify-content: space-between;
    gap: 12px;
    line-height: 1.5;
}

.fc-tip-et {
    font-size: 0.68rem;
    text-transform: uppercase;
    letter-spacing: 0.06em;
    opacity: 0.55;
}

.fc-tip-val {
    font-weight: 700;
    font-size: 0.78rem;
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
