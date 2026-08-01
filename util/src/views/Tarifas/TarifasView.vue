<script setup lang="ts">
import { ref, computed, shallowRef } from 'vue';
import { useRouter } from 'vue-router';
import FullCalendar from '@fullcalendar/vue3';
import resourceTimelinePlugin from '@fullcalendar/resource-timeline';
import interactionPlugin from '@fullcalendar/interaction';
import esLocale from '@fullcalendar/core/locales/es';
import tippy from 'tippy.js';
import 'tippy.js/dist/tippy.css';
import '@/assets/fullcalendar-overrides.css';
import { apiClient } from '@/services/apiClient';
import { extractApiErrorMessage } from '@/stores/reservas/reservasStore';
import { useTarifasStore } from '@/stores/tarifas/tarifasStore';
import TarifaEditDrawer from '@/components/tarifas/TarifaEditDrawer.vue';
import TarifaMasivaDrawer from '@/components/tarifas/TarifaMasivaDrawer.vue';
import {
    PMS_TARIFA_CALENDARIOS,
    fromDateLocal,
    type PmsTarifaExtendedProps,
} from '@/types/pmsTarifaModel';

const router = useRouter();
const tarifasStore = useTarifasStore();

// ============================================================================
// SELECTOR DE CALENDARIO (las dos vistas del legacy EasyAdmin: "Todas" y
// "Compactados", ahora contra los providers *_spa — ver pmsTarifaModel.ts)
// ============================================================================
const calendarioIndex = ref(0);
const calendarioActual = computed(() => PMS_TARIFA_CALENDARIOS[calendarioIndex.value]);

const calendarApiRef = shallowRef<InstanceType<typeof FullCalendar> | null>(null);

// Título del mes/rango renderizado FUERA del headerToolbar: mismo motivo que en
// ReservasView (si comparte fila con los botones, en mobile todo se parte).
const calendarTitulo = ref('');

function refrescarCalendario(): void {
    // any: refetchResources() lo agrega @fullcalendar/resource vía plugin,
    // no está en el tipo base CalendarApi de @fullcalendar/core.
    const api = calendarApiRef.value?.getApi() as any;
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

function fcFechaGuardada(): string | undefined {
    return localStorage.getItem(`${FC_STORAGE_KEY}_date`) || undefined;
}

// ============================================================================
// DRAWERS
// ============================================================================
const drawerVisible = ref(false);
const drawerTarifaId = ref<string | null>(null);
const drawerCreateDefaults = ref<{ unidadId: string; fecha: string } | null>(null);
const drawerStartReadOnly = ref(false);
const masivaVisible = ref(false);

function abrirEdicion(tarifaId: string, readOnly: boolean): void {
    drawerTarifaId.value = tarifaId;
    drawerCreateDefaults.value = null;
    drawerStartReadOnly.value = readOnly;
    drawerVisible.value = true;
}

function abrirCreacion(unidadId: string, fecha: Date): void {
    drawerTarifaId.value = null;
    drawerStartReadOnly.value = false;
    drawerCreateDefaults.value = { unidadId, fecha: fromDateLocal(fecha) };
    drawerVisible.value = true;
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
    fecha?: Date;
}
const menu = ref<MenuState | null>(null);

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

function onEventClick(info: any): void {
    const props = info.event.extendedProps as PmsTarifaExtendedProps;
    if (!props?.tarifaRangoId) return;
    const { x, y } = posicionMenu(info.jsEvent);
    menu.value = {
        x, y,
        kind: 'event',
        tarifaId: props.tarifaRangoId,
        esCompactado: props.context === 'tarifaRangoCompactado',
    };
}

function onDateClick(info: any): void {
    const resourceId = info.resource?.id;
    if (!resourceId) return;
    const { x, y } = posicionMenu(info.jsEvent);
    menu.value = { x, y, kind: 'create', unidadId: resourceId, fecha: info.date };
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
    if (menu.value?.unidadId && menu.value.fecha) abrirCreacion(menu.value.unidadId, menu.value.fecha);
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
async function patchDesdeCalendario(info: any, payloadExtra: Record<string, unknown> = {}): Promise<void> {
    const props = info.event.extendedProps as PmsTarifaExtendedProps;
    if (!props?.tarifaRangoId) {
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

function onEventDrop(info: any): void {
    // Mover a otra fila = cambiar de unidad.
    patchDesdeCalendario(
        info,
        info.newResource ? { unidad: `/platform/pms/pms_unidads/${info.newResource.id}` } : {},
    );
}

function onEventResize(info: any): void {
    patchDesdeCalendario(info);
}

// ============================================================================
// FULLCALENDAR OPTIONS
// ============================================================================
// `computed` (y no un objeto plano como en ReservasView) porque `editable`
// depende del calendario elegido: los rangos compactados son segmentos
// calculados y no admiten drag & drop.
//
// any: @fullcalendar/vue3 y @fullcalendar/core resuelven CalendarOptions/LocaleInput
// como tipos estructuralmente distintos; es una fricción conocida del paquete.
const calendarOptions = computed<any>(() => ({
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
    refetchResourcesOnNavigate: true,
    resourceOrder: 'orden',
    eventOrder: '-prioridadImportante,-duration,start',
    eventOrderStrict: true,
    resourceAreaWidth: '90px',
    resourceAreaHeaderContent: 'Casita',

    headerToolbar: {
        left: 'today prev,next',
        center: '',
        right: 'resourceTimelineTwoMonths,resourceTimelineOneMonth',
    },

    datesSet: (info: any) => {
        const titulo = String(info.view?.title || '');
        calendarTitulo.value = titulo ? titulo.charAt(0).toUpperCase() + titulo.slice(1) : '';

        const fechaRef: Date | undefined = info.view?.currentStart || info.start;
        if (fechaRef) {
            localStorage.setItem(`${FC_STORAGE_KEY}_date`, fechaRef.toISOString().slice(0, 10));
        }
        localStorage.setItem(`${FC_STORAGE_KEY}_view`, info.view.type);
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
}));
</script>

<template>
    <div class="h-screen bg-[#F8FAFC] flex flex-col font-sans overflow-hidden">
        <header class="bg-slate-900 text-white px-4 md:px-6 py-3 flex items-center justify-between gap-3 z-20 shadow-md shrink-0">
            <div class="flex items-center gap-3 min-w-0">
                <button @click="router.push('/')" class="w-8 md:w-10 h-10 flex items-center justify-center bg-slate-800 hover:bg-slate-700 rounded-full transition-colors shrink-0">
                    <i class="fas fa-arrow-left text-sm"></i>
                </button>
                <div class="overflow-hidden">
                    <h1 class="font-black text-base md:text-xl tracking-tight leading-none">Calendario de Tarifas</h1>
                    <p class="text-[10px] md:text-[11px] font-bold text-slate-400 uppercase tracking-widest mt-1">
                        PMS · Precios y estancia mínima
                    </p>
                </div>
            </div>

            <div class="flex items-center gap-2 shrink-0">
                <button @click="masivaVisible = true"
                    class="h-9 px-3 flex items-center gap-1.5 bg-[#E07845] hover:bg-[#c9663a] rounded-lg text-xs font-black transition-colors">
                    <i class="fas fa-magic"></i>
                    <span class="hidden sm:inline">Generar Masivo</span>
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

        <!-- Los segmentos compactados no son filas reales de la tabla: se avisa
             para que nadie espere poder arrastrarlos como en la vista "Todas". -->
        <div v-if="!calendarioActual.editable" class="bg-slate-100 border-b border-slate-200 text-slate-500 text-xs font-bold px-4 py-2">
            <i class="fas fa-info-circle mr-2"></i>
            Vista compactada (solo lectura): son segmentos calculados a partir de los rangos solapados.
            Para mover o redimensionar, cambia a «Todas».
        </div>

        <main class="flex-1 overflow-y-auto p-3 md:p-4">
            <h3 v-if="calendarTitulo" class="text-center font-black text-slate-800 uppercase tracking-wide text-sm mb-2">
                {{ calendarTitulo }}
            </h3>
            <FullCalendar ref="calendarApiRef" :options="calendarOptions" />
        </main>

        <!-- Menú contextual -->
        <div v-if="menu" class="fixed inset-0 z-30" @click="cerrarMenu" @contextmenu.prevent="cerrarMenu">
            <div class="absolute w-52 bg-white rounded-xl shadow-2xl border border-slate-200 overflow-hidden py-1.5 max-w-[calc(100vw-1rem)]"
                :style="{ left: menu.x + 'px', top: menu.y + 'px' }" @click.stop>
                <template v-if="menu.kind === 'event'">
                    <p v-if="menu.esCompactado" class="px-4 py-1.5 text-[10px] font-black text-slate-400 uppercase tracking-widest">
                        Rango de origen
                    </p>
                    <button @click="elegirVer"
                        class="w-full flex items-center gap-2.5 px-4 py-2.5 text-sm font-bold text-slate-700 hover:bg-slate-50">
                        <i class="fas fa-eye w-4 text-slate-400"></i> Ver
                    </button>
                    <button @click="elegirEditar"
                        class="w-full flex items-center gap-2.5 px-4 py-2.5 text-sm font-bold text-slate-700 hover:bg-slate-50">
                        <i class="fas fa-pen w-4 text-slate-400"></i> Editar
                    </button>
                </template>
                <template v-else>
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
            @close="masivaVisible = false"
            @generated="onGenerado"
        />
    </div>
</template>
