<script setup lang="ts">
import { ref, computed, shallowRef } from 'vue';
import { useRouter } from 'vue-router';
import FullCalendar from '@fullcalendar/vue3';
import resourceTimelinePlugin from '@fullcalendar/resource-timeline';
import dayGridPlugin from '@fullcalendar/daygrid';
import listPlugin from '@fullcalendar/list';
import interactionPlugin from '@fullcalendar/interaction';
import esLocale from '@fullcalendar/core/locales/es';
import tippy from 'tippy.js';
import 'tippy.js/dist/tippy.css';
import '@/assets/fullcalendar-overrides.css';
import { apiClient, getUrls } from '@/services/apiClient';
import { useReservasStore, extractApiErrorMessage } from '@/stores/reservas/reservasStore';
import { useChatStore, type ApiTemplate } from '@/stores/chat/chatStore';
import ReservaEditDrawer from '@/components/reservas/ReservaEditDrawer.vue';
import { abrirWhatsapp, PMS_CHANNEL, type PmsEventoExtendedProps } from '@/types/pmsReservaModel';
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
    // any: refetchResources() lo agrega @fullcalendar/resource vía plugin,
    // no está en el tipo base CalendarApi de @fullcalendar/core.
    const api = calendarApiRef.value?.getApi() as any;
    if (!api) return;
    api.refetchResources();
    api.refetchEvents();
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

function abrirCreacion(unidadId: string, fechaClic: Date, kind: 'bloqueo' | 'reserva'): void {
    const inicio = new Date(fechaClic);
    inicio.setHours(14, 0, 0, 0);
    const fin = new Date(inicio);
    fin.setDate(fin.getDate() + 1);
    fin.setHours(10, 0, 0, 0);

    drawerEventoId.value = null;
    drawerReservaId.value = null;
    drawerCreateKind.value = kind;
    drawerStartReadOnly.value = false;
    drawerCreateDefaults.value = {
        unidadId,
        inicio: inicio.toISOString(),
        fin: fin.toISOString(),
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
    kind: 'event' | 'create' | 'whatsapp';
    eventProps?: PmsEventoExtendedProps;
    unidadId?: string;
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

/** Etiqueta/ícono del enlace al canal según la OTA (espejo de extranetInfo del drawer). */
const otaMenuInfo = computed(() => {
    switch (menuReserva.value.channelId) {
        case PMS_CHANNEL.BOOKING: return { texto: 'Booking.com', icono: 'fas fa-hotel' };
        case PMS_CHANNEL.AIRBNB:  return { texto: 'Airbnb', icono: 'fab fa-airbnb' };
        default:                  return { texto: 'Ver reserva OTA', icono: 'fas fa-external-link-alt' };
    }
});

function avisar(mensaje: string): void {
    dragError.value = mensaje;
    setTimeout(() => (dragError.value = null), 5000);
}

// Tamaño aproximado del menú, para que nunca se salga de la pantalla.
const MENU_ANCHO = 240;
const MENU_ALTO = 260;
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

async function onEventClick(info: any): Promise<void> {
    const { x, y } = posicionMenu(info.jsEvent);
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
            telefono: reserva.telefono || reserva.telefono2 || null,
        };
    } catch {
        /* silencioso: los ítems dependientes (guía / canal) simplemente no aparecen */
    }
}

function onDateClick(info: any): void {
    const resourceId = info.resource?.id;
    if (!resourceId) return;
    const { x, y } = posicionMenu(info.jsEvent);
    menu.value = { x, y, kind: 'create', unidadId: resourceId, fecha: info.date };
    limpiarMenuReserva();
}

function cerrarMenu(): void {
    menu.value = null;
}

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

async function elegirAbrirWhatsapp(): Promise<void> {
    // Cambiamos de submenú primero para que el usuario vea feedback inmediato.
    if (menu.value) menu.value = { ...menu.value, kind: 'whatsapp' };
    if (chatStore.templates.length) return;
    cargandoPlantillas.value = true;
    try {
        await chatStore.fetchTemplates();
    } catch {
        avisar('No se pudieron cargar las plantillas de WhatsApp.');
    } finally {
        cargandoPlantillas.value = false;
    }
}

const plantillasWhatsapp = computed(() => (chatStore.templates as ApiTemplateWA[]).filter(t => t.whatsappLinkContent));

async function elegirEnviarWhatsapp(templateId: string): Promise<void> {
    const reservaId = menu.value?.eventProps?.reservaId;
    cerrarMenu();
    if (!reservaId) return;
    try {
        const { telefono, texto } = await reservasStore.fetchWhatsappLink(reservaId, templateId);
        abrirWhatsapp(telefono, texto);
    } catch (err) {
        avisar(extractApiErrorMessage(err, 'No se pudo generar el mensaje de WhatsApp.'));
    }
}

function onGuardado(): void {
    drawerVisible.value = false;
    reservasStore.clearActivo();
    const api = calendarApiRef.value?.getApi() as any;
    api?.refetchEvents();
    api?.refetchResources();
}

// ============================================================================
// DRAG & DROP (mover fechas / cambiar unidad directo en el calendario)
// ============================================================================
const dragError = ref<string | null>(null);

async function onEventDrop(info: any): Promise<void> {
    const extendedProps = info.event.extendedProps as PmsEventoExtendedProps;
    try {
        await reservasStore.patchEvento(extendedProps.eventoId, {
            inicio: info.event.start.toISOString(),
            fin: info.event.end.toISOString(),
            pmsUnidad: info.newResource ? `/platform/pms/pms_unidads/${info.newResource.id}` : undefined,
        });
        dragError.value = null;
    } catch (err) {
        dragError.value = extractApiErrorMessage(err, 'No se pudo mover la reserva.');
        info.revert();
        setTimeout(() => (dragError.value = null), 5000);
    }
}

async function onEventResize(info: any): Promise<void> {
    const extendedProps = info.event.extendedProps as PmsEventoExtendedProps;
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
// any: @fullcalendar/vue3 y @fullcalendar/core resuelven CalendarOptions/LocaleInput
// como tipos estructuralmente distintos (dos "chunks" internos separados); es una
// fricción conocida del paquete, no un error de lógica en estas opciones.
const calendarOptions: any = {
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

    datesSet: (info: any) => {
        const titulo = String(info.view?.title || '');
        calendarTitulo.value = titulo ? titulo.charAt(0).toUpperCase() + titulo.slice(1) : '';

        // Persistencia de fecha/vista (ver fcVistaGuardada/fcFechaGuardada arriba).
        const fechaRef: Date | undefined = info.view?.currentStart || info.start;
        if (fechaRef) {
            localStorage.setItem(`${FC_STORAGE_KEY}_date`, fechaRef.toISOString().slice(0, 10));
        }
        localStorage.setItem(`${FC_STORAGE_KEY}_view`, info.view.type);
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

    resources: (fetchInfo: any, success: (r: unknown[]) => void, failure: (e: unknown) => void) => {
        apiClient.get(`/fullcalendar/load/resource/${calendarioActual.value.key}`, {
            params: { start: fetchInfo.startStr, end: fetchInfo.endStr, _t: Date.now() },
        })
            .then((r) => success(Array.isArray(r.data) ? r.data : (r.data?.data ?? [])))
            .catch(failure);
    },

    events: (fetchInfo: any, success: (e: unknown[]) => void, failure: (e: unknown) => void) => {
        apiClient.get(`/fullcalendar/load/event/${calendarioActual.value.key}`, {
            params: { start: fetchInfo.startStr, end: fetchInfo.endStr, _t: Date.now() },
        })
            .then((r) => success(Array.isArray(r.data) ? r.data : (r.data?.data ?? [])))
            .catch(failure);
    },

    dateClick: onDateClick,
    eventClick: onEventClick,

    eventDrop: onEventDrop,
    eventResize: onEventResize,

    eventDidMount: (info: any) => {
        const tooltipContent = info.event.extendedProps.tooltip;
        const finalContent = Array.isArray(tooltipContent) ? tooltipContent.join('<br>') : (tooltipContent || info.event.title);
        if (info.el._tippy) info.el._tippy.destroy();
        tippy(info.el, { content: finalContent, allowHTML: true, appendTo: document.body, placement: 'top' });
        info.el.style.cursor = 'pointer';
    },
};
</script>

<template>
    <div class="h-screen bg-[#F8FAFC] flex flex-col font-sans overflow-hidden">
        <header class="bg-slate-900 text-white px-4 md:px-6 py-3 flex items-center justify-between z-20 shadow-md shrink-0">
            <div class="flex items-center gap-3">
                <button @click="router.push('/')" class="w-8 md:w-10 h-10 flex items-center justify-center bg-slate-800 hover:bg-slate-700 rounded-full transition-colors">
                    <i class="fas fa-arrow-left text-sm"></i>
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
                :class="menu.kind === 'whatsapp' ? 'w-[280px]' : 'w-52'"
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
                    <button v-for="t in plantillasWhatsapp" :key="t.id ?? ''" @click="elegirEnviarWhatsapp(t.id ?? '')"
                        class="w-full flex items-center gap-2.5 px-4 py-2.5 text-left text-sm font-bold text-slate-700 hover:bg-slate-50">
                        <i class="fab fa-whatsapp w-4 text-emerald-500 shrink-0"></i> <span class="truncate">{{ t.name }}</span>
                    </button>
                </template>
                <template v-else>
                    <button @click="elegirCrear('bloqueo')"
                        class="w-full flex items-center gap-2.5 px-4 py-2.5 text-sm font-bold text-slate-700 hover:bg-slate-50">
                        <i class="fas fa-ban w-4 text-slate-400"></i> Crear Bloqueo
                    </button>
                    <button @click="elegirCrear('reserva')"
                        class="w-full flex items-center gap-2.5 px-4 py-2.5 text-sm font-bold text-slate-700 hover:bg-slate-50">
                        <i class="fas fa-calendar-plus w-4 text-slate-400"></i> Crear Reserva
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
