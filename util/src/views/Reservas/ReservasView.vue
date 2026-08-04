<script setup lang="ts">
import { ref, computed, shallowRef, onBeforeUnmount } from 'vue';
import { useRouter, onBeforeRouteLeave } from 'vue-router';
import FullCalendar from '@fullcalendar/vue3';
import resourceTimelinePlugin from '@fullcalendar/resource-timeline';
import dayGridPlugin from '@fullcalendar/daygrid';
import listPlugin from '@fullcalendar/list';
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
import { useChatStore, type ApiTemplate } from '@/stores/chat/chatStore';
import ReservaEditDrawer from '@/components/reservas/ReservaEditDrawer.vue';
import { escaparHtml } from '@/utils/html';
import {
    canalInfo,
    whatsappUrl,
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

// El backend expone `hasWhatsappLinkContent()` pero el serializer de Symfony
// recorta el prefijo `has`, así que en la API el campo llega como
// `whatsappLinkContent` (verificado en api:openapi:export). Aún no está en el
// schema generado de api.d.ts, de ahí la extensión manual del tipo.
type ApiTemplateWA = ApiTemplate & { whatsappLinkContent?: boolean };

const router = useRouter();
const reservasStore = useReservasStore();
const chatStore = useChatStore();

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
const FC_VISTAS_PERMITIDAS = ['resourceTimelineOneMonth', 'resourceTimelineOneWeek', 'listMonth'];

function fcVistaGuardada(): string {
    const v = localStorage.getItem(`${FC_STORAGE_KEY}_view`);
    return v && FC_VISTAS_PERMITIDAS.includes(v) ? v : 'resourceTimelineOneMonth';
}

function fcFechaGuardada(): string | undefined {
    return localStorage.getItem(`${FC_STORAGE_KEY}_date`) || undefined;
}

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
// `gotoDate()` solo coloca el RANGO (el mes): en una vista timeline el scroll
// horizontal se queda donde estaba, así que la estancia podía caer fuera de
// pantalla. El desplazamiento fino lo hace `scrollToTime()`, que en timeline
// cuenta la duración desde el inicio del rango visible — de ahí el cálculo de
// días entre `view.activeStart` y el día buscado.
//
// El rango nuevo puede no estar renderizado en el instante del salto, así que el
// día queda "pendiente" y se reintenta cuando el calendario avisa: `datesSet`
// (rango ya montado) y `loading` (fin de la carga de eventos, último intento).
// ============================================================================
const MS_POR_DIA = 86_400_000;

/** Día `YYYY-MM-DD` al que hay que desplazarse en cuanto el rango esté listo. */
let diaPendiente: string | null = null;

/** @returns true si se pudo desplazar (el día ya está dentro del rango visible). */
function scrollAlDia(fechaIso: string): boolean {
    const api = calendarApiRef.value?.getApi();
    if (!api) return false;

    const [y, m, d] = fechaIso.split('-').map(Number);
    const objetivo = new Date(y, m - 1, d);
    const inicio = api.view.activeStart;
    if (!inicio || objetivo < inicio || objetivo >= api.view.activeEnd) return false;

    // Un día de margen para que la barra no quede pegada al borde izquierdo.
    const dias = Math.round((objetivo.getTime() - inicio.getTime()) / MS_POR_DIA) - 1;
    api.scrollToTime({ days: Math.max(0, dias) });
    return true;
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

// Tamaño aproximado del menú, para que nunca se salga de la pantalla.
const MENU_ANCHO = 240;
// Alto aproximado del menú más largo (el de creación: cabecera + tarifa + 3 opciones).
// Solo sirve para que el menú no nazca fuera de la pantalla; pasarse un poco es
// inofensivo, quedarse corto lo deja recortado abajo.
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
            // Ya resuelto por PmsReserva::getTelefonoContacto(): aquí no se
            // vuelve a decidir cuál de los dos números es el bueno.
            telefono: (reserva as { telefonoContacto?: string | null }).telefonoContacto ?? null,
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

async function elegirChatInterno(): Promise<void> {
    const reservaId = menu.value?.eventProps?.reservaId;
    cerrarMenu();
    if (!reservaId) return;
    try {
        const convId = await reservasStore.fetchConversacionId(reservaId);
        if (!convId) {
            avisar('Esta reserva todavía no tiene una conversación de chat.');
            return;
        }
        // ChatView.vue solo reacciona a `route.query.id` (ver su watch/onMounted),
        // no a un param de ruta: /chat/:id no abre nada, hay que usar ?id=.
        router.push({ path: '/chat', query: { id: convId } });
    } catch {
        avisar('No se pudo abrir el chat interno.');
    }
}

const cargandoPlantillas = ref(false);

const plantillasWhatsapp = computed(() => (chatStore.templates as ApiTemplateWA[]).filter(t => t.whatsappLinkContent));

// ============================================================================
// PLANTILLAS DE WHATSAPP — POR QUÉ EL ENLACE SE RESUELVE ANTES DE PINTARLO
//
// El flujo anterior era: pulsar plantilla → `await` al backend para resolver el
// texto → `window.open()`. En iOS, y en particular en la PWA instalada (standalone),
// ese `window.open()` ya no cuenta como parte del gesto del usuario y el sistema lo
// descarta EN SILENCIO: sin error, sin ventana, el usuario cree que la app se colgó.
//
// Solución: al abrir el submenú se resuelven los enlaces de todas las plantillas y
// cada una se pinta como un `<a href>` de verdad. El toque del usuario navega
// directamente — no hay ventana emergente que bloquear. Son N peticiones pequeñas en
// paralelo, y sólo de las plantillas con contenido de enlace (suelen ser un puñado).
// ============================================================================

/** templateId -> URL de WhatsApp ya resuelta. Vacío mientras carga o si falló. */
const whatsappLinks = ref<Record<string, string>>({});

async function precargarLinksWhatsapp(reservaId: string): Promise<void> {
    const plantillas = plantillasWhatsapp.value;
    // allSettled: que una plantilla rota (variable sin resolver, teléfono ausente)
    // no deje sin enlace a las demás.
    const resueltos = await Promise.allSettled(
        plantillas.map(t => reservasStore.fetchWhatsappLink(reservaId, t.id ?? '')),
    );

    // El usuario pudo cerrar el menú o cambiar de reserva mientras se resolvía.
    if (menu.value?.kind !== 'whatsapp' || menu.value.eventProps?.reservaId !== reservaId) return;

    const mapa: Record<string, string> = {};
    resueltos.forEach((r, i) => {
        const id = plantillas[i]?.id;
        if (id && r.status === 'fulfilled') mapa[id] = whatsappUrl(r.value.telefono, r.value.texto);
    });
    whatsappLinks.value = mapa;

    if (plantillas.length && !Object.keys(mapa).length) {
        const primero = resueltos.find(r => r.status === 'rejected') as PromiseRejectedResult | undefined;
        avisar(extractApiErrorMessage(primero?.reason, 'No se pudo generar el mensaje de WhatsApp.'));
    }
}

async function elegirAbrirWhatsapp(): Promise<void> {
    // Cambiamos de submenú primero para que el usuario vea feedback inmediato.
    if (menu.value) menu.value = { ...menu.value, kind: 'whatsapp' };
    const reservaId = menu.value?.eventProps?.reservaId;
    if (!reservaId) return;

    whatsappLinks.value = {};
    cargandoPlantillas.value = true;
    try {
        if (!chatStore.templates.length) await chatStore.fetchTemplates();
        await precargarLinksWhatsapp(reservaId);
    } catch {
        avisar('No se pudieron cargar las plantillas de WhatsApp.');
    } finally {
        cargandoPlantillas.value = false;
    }
}

function onGuardado(payload?: { reservaIdCreada?: string }): void {
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

    drawerVisible.value = false;
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
const calendarOptions: CalendarOptions = {
    plugins: [resourceTimelinePlugin, dayGridPlugin, listPlugin, interactionPlugin],
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
        left: 'today prev,next',
        center: '',
        right: 'resourceTimelineOneMonth,resourceTimelineOneWeek,listMonth',
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

        // El rango ya está montado: si venimos de una búsqueda, es el momento de
        // desplazar la línea de tiempo hasta el día exacto.
        resolverDiaPendiente();
    },

    // Fin de la carga de eventos: último intento por si el rango tardó en
    // quedar listo (con el flag de "último" para no dejarlo colgado).
    loading: (cargando: boolean) => {
        if (!cargando) resolverDiaPendiente(true);
    },

    views: {
        resourceTimelineOneMonth: {
            type: 'resourceTimeline',
            duration: { months: 1 },
            buttonText: 'Mes',
            slotDuration: '24:00:00',
            slotLabelFormat: [{ weekday: 'short', day: 'numeric', month: 'numeric', omitCommas: true }],
        },
        resourceTimelineOneWeek: {
            type: 'resourceTimeline',
            duration: { weeks: 1 },
            buttonText: 'Semana',
        },
        listMonth: {
            type: 'listMonth',
            buttonText: 'Lista',
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
     * Contenido de la barra. El provider ya manda el `title` listo
     * («A x8 | Nombre | Casita»), pero aquí se rearma con los datos sueltos de
     * extendedProps para cambiar la INICIAL del canal por su icono: una «A» y una
     * «B» no se distinguen de un vistazo, un logo de Airbnb sí. El título de
     * texto se mantiene como respaldo si el evento llegara sin datos.
     */
    eventContent: (arg) => {
        const p = arg.event.extendedProps as PmsEventoExtendedProps;
        if (!p?.cliente) return { html: `<div class="fc-reserva">${escaparHtml(arg.event.title)}</div>` };

        const canal = canalInfo(p.canalId);
        const partes = [
            `<i class="${canal.icono} fc-reserva-canal" title="${escaparHtml(canal.texto)}"></i>`,
            p.pax ? `<span class="fc-reserva-pax">${escaparHtml(String(p.pax))}</span>` : '',
            `<span class="fc-reserva-nombre">${escaparHtml(p.cliente)}</span>`,
        ];

        return { html: `<div class="fc-reserva">${partes.join('')}</div>` };
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
        });
        info.el.style.cursor = 'pointer';
    },
};

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
        </div>`;
}
</script>

<template>
    <div class="h-screen bg-[#F8FAFC] flex flex-col font-sans overflow-hidden">
        <header class="bg-slate-900 text-white px-4 md:px-6 py-3 flex items-center justify-between z-20 shadow-md shrink-0">
            <div class="flex items-center gap-3">
                <button @click="router.push('/')" title="Volver al inicio" class="w-8 md:w-10 h-10 flex items-center justify-center bg-slate-800 hover:bg-slate-700 rounded-full transition-colors">
                    <i class="fas fa-home text-sm"></i>
                </button>
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

        <main class="flex-1 overflow-y-auto p-3 md:p-4">
            <h3 v-if="calendarTitulo" class="text-center font-black text-slate-800 uppercase tracking-wide text-sm mb-2">
                {{ calendarTitulo }}
            </h3>
            <FullCalendar ref="calendarApiRef" :options="calendarOptions" />
        </main>

        <!-- Menú contextual: Ver/Editar sobre un evento, Bloqueo/Reserva sobre espacio vacío -->
        <div v-if="menu" class="fixed inset-0 z-30" @click="cerrarMenu" @contextmenu.prevent="cerrarMenu">
            <div class="absolute bg-white rounded-xl shadow-2xl border border-slate-200 overflow-hidden py-1.5 max-w-[calc(100vw-1rem)] max-h-[70vh] overflow-y-auto"
                :class="menu.kind === 'whatsapp' ? 'w-70' : 'w-52'"
                :style="{ left: menu.x + 'px', top: menu.y + 'px' }" @click.stop>
                <template v-if="menu.kind === 'event'">
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
                        <button @click="elegirChatInterno"
                            class="w-full flex items-center gap-2.5 px-4 py-2.5 text-sm font-bold text-slate-700 hover:bg-slate-50">
                            <i class="fas fa-comment-dots w-4 text-slate-400"></i> Abrir chat interno
                        </button>
                        <a v-if="menuWhatsappUrl" :href="menuWhatsappUrl" target="_blank" rel="noopener" @click="cerrarMenu"
                            class="w-full flex items-center gap-2.5 px-4 py-2.5 text-sm font-bold text-slate-700 hover:bg-slate-50">
                            <i class="fab fa-whatsapp w-4 text-emerald-500"></i> Abrir conversación
                        </a>
                        <button @click="elegirAbrirWhatsapp"
                            class="w-full flex items-center gap-2.5 px-4 py-2.5 text-sm font-bold text-slate-700 hover:bg-slate-50">
                            <i class="fas fa-paper-plane w-4 text-slate-400"></i> Enviar plantilla
                        </button>
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
                    <div v-if="cargandoPlantillas" class="flex items-center gap-2.5 px-4 py-2.5 text-sm font-bold text-slate-400">
                        <i class="fas fa-spinner fa-spin w-4"></i> Cargando plantillas…
                    </div>
                    <p v-else-if="!plantillasWhatsapp.length" class="px-4 py-2.5 text-xs font-bold text-slate-400">
                        No hay plantillas de WhatsApp configuradas.
                    </p>
                    <!-- <a> real con el enlace ya resuelto: el toque del usuario navega sin
                         window.open(), que la PWA de iOS bloquea en silencio (ver el bloque
                         de comentarios del script). Sin enlace, la fila queda inerte. -->
                    <a v-for="t in plantillasWhatsapp" :key="t.id ?? ''"
                        :href="whatsappLinks[t.id ?? ''] || undefined"
                        :target="whatsappLinks[t.id ?? ''] ? '_blank' : undefined" rel="noopener"
                        @click="whatsappLinks[t.id ?? ''] && cerrarMenu()"
                        class="w-full flex items-center gap-2.5 px-4 py-2.5 text-left text-sm font-bold text-slate-700"
                        :class="whatsappLinks[t.id ?? ''] ? 'hover:bg-slate-50 cursor-pointer' : 'opacity-40 cursor-not-allowed'">
                        <i class="fab fa-whatsapp w-4 text-emerald-500 shrink-0"></i> <span class="truncate">{{ t.name }}</span>
                    </a>
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
.fc-reserva {
    display: flex;
    align-items: center;
    gap: 5px;
    min-width: 0;
    padding: 0 2px;
    overflow: hidden;
    white-space: nowrap;
}

.fc-reserva-canal {
    font-size: 0.68rem;
    opacity: 0.85;
    flex-shrink: 0;
}

/* El número de huéspedes, en pastilla: separa el canal del nombre sin usar la
   barra vertical del formato antiguo, que se confundía con los bordes. */
.fc-reserva-pax {
    flex-shrink: 0;
    font-size: 0.58rem;
    font-weight: 800;
    line-height: 1;
    padding: 2px 4px;
    border-radius: 4px;
    background: rgba(255, 255, 255, 0.22);
}

.fc-reserva-nombre {
    font-weight: 600;
    font-size: 0.72rem;
    overflow: hidden;
    text-overflow: ellipsis;
}

/* ── Tooltip ────────────────────────────────────────────────────── */
.fc-tip {
    text-align: left;
    min-width: 170px;
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
</style>
