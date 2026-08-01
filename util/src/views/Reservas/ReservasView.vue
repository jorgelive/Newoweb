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
import { apiClient } from '@/services/apiClient';
import { useReservasStore, extractApiErrorMessage } from '@/stores/reservas/reservasStore';
import { useChatStore, type ApiTemplate } from '@/stores/chat/chatStore';
import ReservaEditDrawer from '@/components/reservas/ReservaEditDrawer.vue';
import { abrirWhatsapp, type PmsEventoExtendedProps } from '@/types/pmsReservaModel';

type ApiTemplateWA = ApiTemplate & { hasWhatsappLinkContent?: boolean };

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

function avisar(mensaje: string): void {
    dragError.value = mensaje;
    setTimeout(() => (dragError.value = null), 5000);
}

function posicionMenu(jsEvent: MouseEvent): { x: number; y: number } {
    const margen = 220;
    return {
        x: Math.min(jsEvent.clientX, window.innerWidth - margen),
        y: Math.min(jsEvent.clientY, window.innerHeight - 160),
    };
}

function onEventClick(info: any): void {
    const { x, y } = posicionMenu(info.jsEvent);
    menu.value = { x, y, kind: 'event', eventProps: info.event.extendedProps as PmsEventoExtendedProps };
}

function onDateClick(info: any): void {
    const resourceId = info.resource?.id;
    if (!resourceId) return;
    const { x, y } = posicionMenu(info.jsEvent);
    menu.value = { x, y, kind: 'create', unidadId: resourceId, fecha: info.date };
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
        router.push(`/chat/${convId}`);
    } catch {
        avisar('No se pudo abrir el chat interno.');
    }
}

async function elegirVerOta(): Promise<void> {
    const reservaId = menu.value?.eventProps?.reservaId;
    cerrarMenu();
    if (!reservaId) return;
    try {
        const reserva = await reservasStore.fetchReserva(reservaId);
        // urlBeds24 aún no está en el schema generado de api.d.ts (ver PmsReserva::getUrlBeds24()).
        const urlBeds24 = (reserva as { urlBeds24?: string | null }).urlBeds24;
        if (!urlBeds24) {
            avisar('Esta reserva OTA todavía no tiene referencia en Beds24.');
            return;
        }
        window.open(urlBeds24, '_blank', 'noopener');
    } catch {
        avisar('No se pudo abrir la reserva en Beds24.');
    }
}

async function elegirAbrirWhatsapp(): Promise<void> {
    if (!chatStore.templates.length) await chatStore.fetchTemplates();
    if (menu.value) menu.value = { ...menu.value, kind: 'whatsapp' };
}

const plantillasWhatsapp = computed(() => (chatStore.templates as ApiTemplateWA[]).filter(t => t.hasWhatsappLinkContent));

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
    initialView: 'resourceTimelineOneMonth',
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
            <div class="absolute w-52 bg-white rounded-xl shadow-2xl border border-slate-200 overflow-hidden py-1.5"
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
                        <button @click="elegirChatInterno"
                            class="w-full flex items-center gap-2.5 px-4 py-2.5 text-sm font-bold text-slate-700 hover:bg-slate-50">
                            <i class="fas fa-comment-dots w-4 text-slate-400"></i> Abrir chat interno
                        </button>
                        <button @click="elegirAbrirWhatsapp"
                            class="w-full flex items-center gap-2.5 px-4 py-2.5 text-sm font-bold text-slate-700 hover:bg-slate-50">
                            <i class="fab fa-whatsapp w-4 text-slate-400"></i> Abrir WhatsApp
                        </button>
                        <button v-if="menu.eventProps?.isOta" @click="elegirVerOta"
                            class="w-full flex items-center gap-2.5 px-4 py-2.5 text-sm font-bold text-slate-700 hover:bg-slate-50">
                            <i class="fas fa-external-link-alt w-4 text-slate-400"></i> Ver reserva OTA
                        </button>
                    </template>
                </template>
                <template v-else-if="menu.kind === 'whatsapp'">
                    <button v-if="!plantillasWhatsapp.length" disabled
                        class="w-full flex items-center gap-2.5 px-4 py-2.5 text-sm font-bold text-slate-400">
                        <i class="fas fa-spinner fa-spin w-4"></i> Cargando plantillas…
                    </button>
                    <button v-for="t in plantillasWhatsapp" :key="t.id ?? ''" @click="elegirEnviarWhatsapp(t.id ?? '')"
                        class="w-full flex items-center gap-2.5 px-4 py-2.5 text-sm font-bold text-slate-700 hover:bg-slate-50">
                        <i class="fas fa-paper-plane w-4 text-slate-400"></i> {{ t.name }}
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
