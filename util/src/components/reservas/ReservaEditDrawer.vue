<script setup lang="ts">
import { ref, computed, watch, nextTick } from 'vue';
import { useReservasStore, extractApiErrorMessage } from '@/stores/reservas/reservasStore';
import { useMaestroStore } from '@/stores/maestroStore';
import { getUrls } from '@/services/apiClient';
import { formatearTelefono } from '@/utils/telefono';
import {
    PMS_ESTADO,
    PMS_ESTADO_PAGO,
    PMS_CHANNEL,
    pmsUnidadIri,
    pmsReservaIri,
    pmsEventoEstadoIri,
    pmsEventoEstadoPagoIri,
    pmsChannelIri,
    toDatetimeLocal,
    fromDatetimeLocal,
    filtrarEstadosDisponibles,
    resolveEventoColor,
    contrastText,
    COLOR_ESTADO_FALLBACK,
    type PmsEventoCalendario,
    type PmsEventoCalendarioPatch,
    type PmsEventoCalendarioCreate,
    type PmsReservaPatch,
    type PmsReservaCrearPayload,
} from '@/types/pmsReservaModel';


const props = defineProps<{
    eventoId?: string | null;
    reservaId?: string | null;
    createDefaults?: { unidadId: string; inicio: string; fin: string } | null;
    /** Solo aplica cuando hay createDefaults: 'bloqueo' (sin cliente) o 'reserva' (con cliente). */
    createKind?: 'bloqueo' | 'reserva' | null;
    /** Abre el drawer en modo solo-lectura ("Ver"); el usuario puede pasar a edición con el botón. */
    startReadOnly?: boolean;
}>();

const emit = defineEmits<{ close: []; saved: []; deleted: [] }>();

const reservasStore = useReservasStore();
const maestroStore = useMaestroStore();

const isCreate = computed(() => !props.eventoId && !!props.createDefaults);
const isCreateReserva = computed(() => isCreate.value && props.createKind === 'reserva');
const isLoadingDrawer = ref(false);
const localError = ref<string | null>(null);

// Modo solo-lectura ("Ver"). El botón "Editar" del header lo desactiva.
const readOnly = ref(!!props.startReadOnly);
function habilitarEdicion(): void {
    readOnly.value = false;
}

// ============================================================================
// FORM STATE — Estancias / Eventos (acordeón)
// Una reserva puede tener varias estancias en distintas casitas. Se editan
// como acordeón: solo la estancia "actual" (props.eventoId) arranca abierta.
// ============================================================================
interface EventoFormData {
    pmsUnidad: string;
    estado: string;
    estadoPago: string;
    inicio: string;
    fin: string;
    descripcion: string;
    comentariosHuesped: string;
    monto: string;
    comision: string;
    cantidadAdultos: number;
    cantidadNinos: number;
}

interface EventoEntry {
    eventoId: string | null;
    isOta: boolean;
    estadoActualId: string | null;
    channelNombre: string;
    form: EventoFormData;
}

function formVacio(overrides: Partial<EventoFormData> = {}): EventoFormData {
    return {
        pmsUnidad: '',
        estado: PMS_ESTADO.PENDIENTE,
        estadoPago: PMS_ESTADO_PAGO.SIN_PAGO,
        inicio: '',
        fin: '',
        descripcion: '',
        comentariosHuesped: '',
        monto: '0.00',
        comision: '0.00',
        cantidadAdultos: 1,
        cantidadNinos: 0,
        ...overrides,
    };
}

function entryDesdeEvento(evento: PmsEventoCalendario): EventoEntry {
    return {
        eventoId: evento.id ?? null,
        isOta: !!evento.ota,
        estadoActualId: evento.estado?.id ?? null,
        channelNombre: evento.channel?.nombre ?? '—',
        form: {
            pmsUnidad: evento.pmsUnidad?.id ?? '',
            estado: evento.estado?.id ?? '',
            estadoPago: evento.estadoPago?.id ?? '',
            inicio: toDatetimeLocal(evento.inicio),
            fin: toDatetimeLocal(evento.fin),
            descripcion: evento.descripcion ?? '',
            comentariosHuesped: evento.comentariosHuesped ?? '',
            monto: evento.monto ?? '0.00',
            comision: evento.comision ?? '0.00',
            cantidadAdultos: evento.cantidadAdultos ?? 1,
            cantidadNinos: evento.cantidadNinos ?? 0,
        },
    };
}

const eventos = ref<EventoEntry[]>([]);
const activeIndex = ref(0);

// Solo mostramos acordeón (cabeceras plegables + botón agregar) cuando estamos
// editando una reserva ya persistida. En creación (bloqueo o reserva nueva)
// hay una única estancia y no tiene sentido la envoltura de acordeón.
const esMultiEvento = computed(() => !isCreate.value && !!props.reservaId);
const hayOta = computed(() => eventos.value.some(e => e.isOta));

function estadosDisponiblesPara(entry: EventoEntry) {
    const todos = filtrarEstadosDisponibles(reservasStore.estados, entry.isOta, entry.estadoActualId);
    // Una estancia nueva (sin guardar) o cualquier estancia durante la creación
    // del drawer nunca ofrece el estado "bloqueo": estas son reservas reales.
    return (!entry.eventoId || isCreate.value) ? todos.filter(e => e.id !== PMS_ESTADO.BLOQUEO) : todos;
}

function fechasUnidadBloqueadasPara(entry: EventoEntry): boolean {
    return entry.isOta;
}

function nombreUnidad(entry: EventoEntry): string {
    return reservasStore.unidades.find(u => u.id === entry.form.pmsUnidad)?.nombre || 'Sin unidad';
}

function fechaCorta(datetimeLocal: string): string {
    if (!datetimeLocal) return '—';
    const [fecha] = datetimeLocal.split('T');
    const partes = fecha.split('-');
    return partes.length === 3 ? `${partes[2]}/${partes[1]}` : '—';
}

function paxTotal(entry: EventoEntry): number {
    return (entry.form.cantidadAdultos || 0) + (entry.form.cantidadNinos || 0);
}

/** Formato legible dd/mm/aaaa HH:mm para la vista de solo lectura (el form usa datetime-local crudo). */
function formatFechaHora(datetimeLocal: string): string {
    if (!datetimeLocal) return '—';
    const d = new Date(datetimeLocal);
    if (Number.isNaN(d.getTime())) return '—';
    const pad = (n: number): string => String(n).padStart(2, '0');
    return `${pad(d.getDate())}/${pad(d.getMonth() + 1)}/${d.getFullYear()} ${pad(d.getHours())}:${pad(d.getMinutes())}`;
}

function nombreEstado(entry: EventoEntry): string {
    return estadoObj(entry.form.estado)?.nombre || '—';
}
function nombreEstadoPago(entry: EventoEntry): string {
    return estadoPagoObj(entry.form.estadoPago)?.nombre || '—';
}
function nombrePais(): string {
    return maestroStore.paises.find(p => p.id === clienteForm.value.pais)?.nombre || '—';
}
function nombreIdioma(): string {
    return maestroStore.idiomas.find(i => i.id === clienteForm.value.idioma)?.nombre || '—';
}

// ============================================================================
// COLOR (espejo de PmsEventosSpaCalendarProvider::resolveColor()): el estado de
// pago puede "override" el color del estado. Se usa en la cabecera de cada
// acordeón y como indicador junto a los selects de Estado / Estado de Pago.
// ============================================================================
function estadoObj(id: string) {
    return reservasStore.estados.find(e => e.id === id);
}
function estadoPagoObj(id: string) {
    return reservasStore.estadosPago.find(e => e.id === id);
}
function colorEntry(entry: EventoEntry): string {
    return resolveEventoColor(estadoObj(entry.form.estado), estadoPagoObj(entry.form.estadoPago));
}

function toggleAcordeon(i: number): void {
    activeIndex.value = activeIndex.value === i ? -1 : i;
}

function agregarEvento(): void {
    const ultimo = eventos.value[eventos.value.length - 1];
    const inicio = ultimo?.form.fin ? new Date(fromDatetimeLocal(ultimo.form.fin)) : new Date();
    inicio.setHours(14, 0, 0, 0);
    const fin = new Date(inicio);
    fin.setDate(fin.getDate() + 1);
    fin.setHours(10, 0, 0, 0);

    eventos.value.push({
        eventoId: null,
        isOta: false,
        estadoActualId: null,
        channelNombre: 'Directo',
        form: formVacio({
            inicio: toDatetimeLocal(inicio.toISOString()),
            fin: toDatetimeLocal(fin.toISOString()),
        }),
    });
    activeIndex.value = eventos.value.length - 1;
}

/** Acepta IRIs sueltas o los objetos que exponga el schema, según el grupo de serialización activo. */
function idsDeEventos(reserva: unknown): string[] {
    const raw = (reserva as { eventosCalendario?: unknown[] })?.eventosCalendario ?? [];
    return raw
        .map((e) => (typeof e === 'string' ? e.split('/').pop() : (e as { id?: string })?.id))
        .filter((id): id is string => !!id);
}

// ============================================================================
// FORM STATE — Cliente (reserva asociada, o titular al crear una reserva completa)
// ============================================================================
function clienteVacio() {
    return {
        nombreCliente: '',
        apellidoCliente: '',
        telefono: '',
        telefono2: '',
        emailCliente: '',
        pais: '',
        idioma: '',
        nota: '',
        datosLocked: false,
    };
}
const clienteForm = ref(clienteVacio());

const muestraCliente = computed(() => !!props.reservaId || isCreateReserva.value);

// ============================================================================
// DATOS DE SOLO LECTURA DE LA RESERVA (localizador, referencia OTA, extranet)
// Réplica de los campos que ya existían en el detalle de EasyAdmin
// (templates/panel/pms/pms_reserva/fields/*.html.twig), no están en `clienteForm`
// porque no son editables desde este drawer.
// ============================================================================
const reservaInfo = ref<{
    localizador: string | null;
    referenciaCanalAggregate: string | null;
    urlCanalExtranet: string | null;
    channelId: string | null;
}>({ localizador: null, referenciaCanalAggregate: null, urlCanalExtranet: null, channelId: null });

/** Link público de la guía del huésped (mismo path que PmsMessageDataResolver::getMessageVariables() -> guide_url). */
const guideUrl = computed(() => {
    if (!reservaInfo.value.localizador) return null;
    return `${getUrls().pax}/huesped/reserva/${reservaInfo.value.localizador}`;
});

const vcardUrl = computed(() => {
    if (!props.reservaId) return null;
    return `${getUrls().api}/pms/reservas/${props.reservaId}/vcard`;
});

// Feedback visual "Copiado" (mismo patrón que assets/controllers/panel/clipboard_controller.js).
const copiadoKey = ref<string | null>(null);
async function copiar(texto: string, key: string): Promise<void> {
    try {
        await navigator.clipboard.writeText(texto);
        copiadoKey.value = key;
        setTimeout(() => { if (copiadoKey.value === key) copiadoKey.value = null; }, 1500);
    } catch {
        localError.value = 'No se pudo copiar al portapapeles.';
    }
}


async function cargarDatos(): Promise<void> {
    isLoadingDrawer.value = true;
    localError.value = null;
    reservasStore.clearActivo();

    try {
        await Promise.all([
            reservasStore.fetchMasters(),
            maestroStore.fetchMaestros(),
        ]);

        if (isCreate.value && props.createDefaults) {
            eventos.value = [{
                eventoId: null,
                isOta: false,
                estadoActualId: null,
                channelNombre: 'Directo',
                form: formVacio({
                    pmsUnidad: props.createDefaults.unidadId,
                    estado: isCreateReserva.value ? PMS_ESTADO.PENDIENTE : PMS_ESTADO.BLOQUEO,
                    inicio: toDatetimeLocal(props.createDefaults.inicio),
                    fin: toDatetimeLocal(props.createDefaults.fin),
                }),
            }];
            activeIndex.value = 0;
            clienteForm.value = clienteVacio();
            return;
        }

        if (props.reservaId) {
            const reserva = await reservasStore.fetchReserva(props.reservaId);
            clienteForm.value = {
                nombreCliente: reserva.nombreCliente ?? '',
                apellidoCliente: reserva.apellidoCliente ?? '',
                telefono: reserva.telefono ?? '',
                telefono2: reserva.telefono2 ?? '',
                emailCliente: reserva.emailCliente ?? '',
                pais: reserva.pais?.id ?? '',
                idioma: reserva.idioma?.id ?? '',
                nota: reserva.nota ?? '',
                datosLocked: reserva.datosLocked ?? false,
            };

            // Campos de solo lectura que no vienen aún en el schema generado
            // (urlCanalExtranet, referenciaCanalAggregate: ver Groups agregados en PmsReserva).
            const reservaExtra = reserva as {
                localizador?: string | null;
                referenciaCanalAggregate?: string | null;
                urlCanalExtranet?: string | null;
            };
            reservaInfo.value = {
                localizador: reservaExtra.localizador ?? null,
                referenciaCanalAggregate: reservaExtra.referenciaCanalAggregate ?? null,
                urlCanalExtranet: reservaExtra.urlCanalExtranet ?? null,
                channelId: reserva.channel?.id ?? null,
            };

            const ids = idsDeEventos(reserva);
            if (ids.length) {
                const detalles = await Promise.all(ids.map((id) => reservasStore.fetchEvento(id)));
                eventos.value = detalles.map(entryDesdeEvento);
            } else if (props.eventoId) {
                // Fallback defensivo: la reserva no trajo la lista pero sabemos qué evento abrir.
                const evento = await reservasStore.fetchEvento(props.eventoId);
                eventos.value = [entryDesdeEvento(evento)];
            }

            const idx = eventos.value.findIndex(e => e.eventoId === props.eventoId);
            activeIndex.value = idx >= 0 ? idx : 0;
            return;
        }

        if (props.eventoId) {
            const evento = await reservasStore.fetchEvento(props.eventoId);
            eventos.value = [entryDesdeEvento(evento)];
            activeIndex.value = 0;
        }
    } catch (err) {
        localError.value = extractApiErrorMessage(err, 'No se pudo cargar la información.');
    } finally {
        isLoadingDrawer.value = false;
    }
}

watch(
    () => [props.eventoId, props.reservaId, props.createDefaults],
    () => cargarDatos(),
    { immediate: true }
);

// ============================================================================
// GUARDAR
// ============================================================================
function payloadCreacion(entry: EventoEntry, estado: string): PmsEventoCalendarioCreate {
    return {
        pmsUnidad: pmsUnidadIri(entry.form.pmsUnidad),
        channel: pmsChannelIri(PMS_CHANNEL.DIRECTO),
        estado: pmsEventoEstadoIri(estado),
        estadoPago: pmsEventoEstadoPagoIri(entry.form.estadoPago),
        inicio: fromDatetimeLocal(entry.form.inicio),
        fin: fromDatetimeLocal(entry.form.fin),
        descripcion: entry.form.descripcion || null,
        monto: entry.form.monto,
        comision: entry.form.comision,
        cantidadAdultos: entry.form.cantidadAdultos,
        cantidadNinos: entry.form.cantidadNinos,
    };
}

async function guardar(): Promise<void> {
    localError.value = null;

    try {
        if (isCreateReserva.value) {
            const entry = eventos.value[0];
            const payload: PmsReservaCrearPayload = {
                nombreCliente: clienteForm.value.nombreCliente,
                apellidoCliente: clienteForm.value.apellidoCliente || null,
                telefono: clienteForm.value.telefono || null,
                telefono2: clienteForm.value.telefono2 || null,
                emailCliente: clienteForm.value.emailCliente || null,
                pais: clienteForm.value.pais ? `/platform/maestro/pais/${clienteForm.value.pais}` : null,
                idioma: clienteForm.value.idioma ? `/platform/maestro/idiomas/${clienteForm.value.idioma}` : null,
                nota: clienteForm.value.nota || null,
                pmsUnidad: pmsUnidadIri(entry.form.pmsUnidad),
                inicio: fromDatetimeLocal(entry.form.inicio),
                fin: fromDatetimeLocal(entry.form.fin),
                cantidadAdultos: entry.form.cantidadAdultos,
                cantidadNinos: entry.form.cantidadNinos,
                descripcion: entry.form.descripcion || null,
                monto: entry.form.monto,
                comision: entry.form.comision,
            };
            await reservasStore.createReservaCompleta(payload);
        } else if (isCreate.value) {
            // Bloqueo manual: el canal SIEMPRE es directo, no es seleccionable ni negociable.
            await reservasStore.createEvento(payloadCreacion(eventos.value[0], PMS_ESTADO.BLOQUEO));
        } else {
            // Edición: una reserva puede tener varias estancias (acordeón). Las
            // existentes se actualizan por PATCH; las agregadas en el acordeón
            // (sin eventoId) se crean y se ligan a la misma reserva.
            for (const entry of eventos.value) {
                if (entry.eventoId) {
                    // NOTA: `channel` nunca se manda. El canal es inmutable tras la
                    // creación (blindado también en el backend por el listener).
                    const payload: PmsEventoCalendarioPatch = {
                        estado: pmsEventoEstadoIri(entry.form.estado),
                        estadoPago: pmsEventoEstadoPagoIri(entry.form.estadoPago),
                        descripcion: entry.form.descripcion || null,
                        comentariosHuesped: entry.form.comentariosHuesped || null,
                        monto: entry.form.monto,
                        comision: entry.form.comision,
                        cantidadAdultos: entry.form.cantidadAdultos,
                        cantidadNinos: entry.form.cantidadNinos,
                    };

                    // Fechas y unidad: inmutables para eventos OTA (bloqueadas en la
                    // UI, y de todas formas el backend las rechazaría con 403).
                    if (!fechasUnidadBloqueadasPara(entry)) {
                        payload.pmsUnidad = pmsUnidadIri(entry.form.pmsUnidad);
                        payload.inicio = fromDatetimeLocal(entry.form.inicio);
                        payload.fin = fromDatetimeLocal(entry.form.fin);
                    }

                    await reservasStore.patchEvento(entry.eventoId, payload);
                } else if (props.reservaId) {
                    const payload = payloadCreacion(entry, entry.form.estado);
                    payload.reserva = pmsReservaIri(props.reservaId);
                    await reservasStore.createEvento(payload);
                }
            }

            if (props.reservaId) {
                const reservaPayload: PmsReservaPatch = {
                    nombreCliente: clienteForm.value.nombreCliente || null,
                    apellidoCliente: clienteForm.value.apellidoCliente || null,
                    telefono: clienteForm.value.telefono || null,
                    telefono2: clienteForm.value.telefono2 || null,
                    emailCliente: clienteForm.value.emailCliente || null,
                    pais: clienteForm.value.pais ? `/platform/maestro/pais/${clienteForm.value.pais}` : null,
                    idioma: clienteForm.value.idioma ? `/platform/maestro/idiomas/${clienteForm.value.idioma}` : undefined,
                    nota: clienteForm.value.nota || null,
                    datosLocked: clienteForm.value.datosLocked,
                };
                await reservasStore.patchReserva(props.reservaId, reservaPayload);
            }
        }

        emit('saved');
    } catch (err) {
        localError.value = extractApiErrorMessage(err, 'No se pudo guardar. Revisa los datos e intenta de nuevo.');
    }
}
</script>

<template>
    <div class="fixed inset-0 z-40 flex justify-end" role="dialog" aria-modal="true">
        <!-- Backdrop -->
        <div class="absolute inset-0 bg-slate-900/40" @click="emit('close')"></div>

        <!-- Panel -->
        <div class="relative w-full max-w-lg h-full bg-white shadow-2xl flex flex-col animate-slide-in">
            <header class="bg-slate-900 text-white px-5 py-4 flex items-center justify-between shrink-0">
                <div>
                    <h2 class="font-black text-base tracking-tight">
                        <template v-if="isCreateReserva">Nueva Reserva</template>
                        <template v-else-if="isCreate">Nuevo Bloqueo</template>
                        <template v-else-if="readOnly">Ver Estancia</template>
                        <template v-else>Editar Estancia</template>
                    </h2>
                    <p class="text-[10px] font-bold text-slate-400 uppercase tracking-widest mt-1">
                        <span v-if="hayOta" class="text-amber-400"><i class="fas fa-lock mr-1"></i>Sincronizado por OTA</span>
                        <span v-else>Reserva PMS</span>
                    </p>
                </div>
                <div class="flex items-center gap-2">
                    <button v-if="readOnly" @click="habilitarEdicion"
                        class="px-3 h-8 flex items-center gap-1.5 bg-[#376875] hover:bg-[#2d5660] rounded-full transition-colors text-xs font-black">
                        <i class="fas fa-pen text-[11px]"></i> Editar
                    </button>
                    <button @click="emit('close')" class="w-8 h-8 flex items-center justify-center bg-slate-800 hover:bg-slate-700 rounded-full transition-colors">
                        <i class="fas fa-times text-sm"></i>
                    </button>
                </div>
            </header>

            <div v-if="isLoadingDrawer" class="flex-1 flex items-center justify-center">
                <i class="fas fa-spinner fa-spin text-3xl text-[#376875]"></i>
            </div>

            <div v-else class="flex-1 overflow-y-auto px-5 py-4 space-y-6">

                <div v-if="localError" class="bg-rose-50 border border-rose-200 text-rose-700 text-sm font-bold rounded-xl px-4 py-3">
                    <i class="fas fa-exclamation-triangle mr-2"></i>{{ localError }}
                </div>

                <div v-if="hayOta" class="bg-amber-50 border border-amber-200 text-amber-800 text-xs font-bold rounded-xl px-4 py-3">
                    <i class="fas fa-info-circle mr-2"></i>
                    Fechas y unidad son de solo lectura en las estancias sincronizadas por un canal externo (Booking/Airbnb).
                    Cualquier cambio de fechas o habitación debe hacerse directamente en el canal.
                </div>

                <!-- ================= ESTANCIA(S) ================= -->
                <section>
                    <h3 class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-3">
                        <i class="fas fa-bed mr-1"></i> {{ esMultiEvento ? 'Estancias' : 'Estancia' }}
                    </h3>

                    <div class="space-y-3">
                        <div v-for="(entry, i) in eventos" :key="entry.eventoId ?? `nuevo-${i}`"
                            class="border border-slate-200 rounded-xl overflow-hidden">

                            <button v-if="esMultiEvento" type="button" @click="toggleAcordeon(i)"
                                class="w-full flex items-center justify-between gap-2 px-4 py-3 hover:brightness-95 text-left transition-[filter]"
                                :style="{ borderLeft: `5px solid ${colorEntry(entry)}`, backgroundColor: colorEntry(entry) + '14' }">
                                <span class="flex items-center gap-2 text-sm font-bold text-slate-700 min-w-0">
                                    <i class="fas fa-chevron-right text-[10px] text-slate-400 transition-transform shrink-0"
                                        :class="{ 'rotate-90': activeIndex === i }"></i>
                                    <span class="w-2.5 h-2.5 rounded-full shrink-0" :style="{ backgroundColor: colorEntry(entry) }"></span>
                                    <span class="truncate">{{ nombreUnidad(entry) }}</span>
                                    <span class="text-slate-400 font-normal text-xs whitespace-nowrap">
                                        {{ fechaCorta(entry.form.inicio) }} → {{ fechaCorta(entry.form.fin) }} · {{ paxTotal(entry) }} pax
                                    </span>
                                    <span v-if="!entry.eventoId" class="text-emerald-600 text-[10px] font-black uppercase shrink-0">Nuevo</span>
                                </span>
                            </button>

                            <div v-show="!esMultiEvento || activeIndex === i" class="p-4">
                                <div v-if="esMultiEvento" class="text-[11px] font-bold text-slate-400 mb-3">
                                    <i class="fas fa-home mr-1"></i> {{ nombreUnidad(entry) }}
                                    · {{ fechaCorta(entry.form.inicio) }} → {{ fechaCorta(entry.form.fin) }}
                                    · {{ paxTotal(entry) }} pax
                                </div>

                                <!-- ===== VISTA (modo "Ver"): no es un form deshabilitado, es una ficha de solo lectura ===== -->
                                <div v-if="readOnly" class="rounded-xl border border-slate-200 divide-y divide-slate-100 overflow-hidden">
                                    <div class="grid grid-cols-2 gap-x-4 gap-y-3 p-4">
                                        <div>
                                            <p class="text-[10px] font-black text-slate-400 uppercase tracking-wide">Unidad</p>
                                            <p class="text-sm font-bold text-slate-800 mt-0.5">{{ nombreUnidad(entry) }}</p>
                                        </div>
                                        <div>
                                            <p class="text-[10px] font-black text-slate-400 uppercase tracking-wide">Canal</p>
                                            <p class="text-sm font-bold text-slate-800 mt-0.5 flex items-center gap-1.5">
                                                <i class="fas fa-lock text-[10px] text-slate-400"></i> {{ entry.channelNombre }}
                                            </p>
                                        </div>
                                        <div>
                                            <p class="text-[10px] font-black text-slate-400 uppercase tracking-wide">Check-in</p>
                                            <p class="text-sm font-bold text-slate-800 mt-0.5">{{ formatFechaHora(entry.form.inicio) }}</p>
                                        </div>
                                        <div>
                                            <p class="text-[10px] font-black text-slate-400 uppercase tracking-wide">Check-out</p>
                                            <p class="text-sm font-bold text-slate-800 mt-0.5">{{ formatFechaHora(entry.form.fin) }}</p>
                                        </div>
                                        <div v-if="!isCreate || isCreateReserva">
                                            <p class="text-[10px] font-black text-slate-400 uppercase tracking-wide">Estado</p>
                                            <span class="inline-block mt-1 px-2.5 py-1 rounded-full text-[11px] font-black"
                                                :style="{ backgroundColor: estadoObj(entry.form.estado)?.color || COLOR_ESTADO_FALLBACK, color: contrastText(estadoObj(entry.form.estado)?.color || COLOR_ESTADO_FALLBACK) }">
                                                {{ nombreEstado(entry) }}
                                            </span>
                                        </div>
                                        <div>
                                            <p class="text-[10px] font-black text-slate-400 uppercase tracking-wide">Estado de Pago</p>
                                            <span class="inline-block mt-1 px-2.5 py-1 rounded-full text-[11px] font-black"
                                                :style="{ backgroundColor: estadoPagoObj(entry.form.estadoPago)?.color || COLOR_ESTADO_FALLBACK, color: contrastText(estadoPagoObj(entry.form.estadoPago)?.color || COLOR_ESTADO_FALLBACK) }">
                                                {{ nombreEstadoPago(entry) }}
                                            </span>
                                        </div>
                                        <div>
                                            <p class="text-[10px] font-black text-slate-400 uppercase tracking-wide">Pax</p>
                                            <p class="text-sm font-bold text-slate-800 mt-0.5">{{ entry.form.cantidadAdultos }} adultos · {{ entry.form.cantidadNinos }} niños</p>
                                        </div>
                                        <div v-if="entry.form.monto && entry.form.monto !== '0.00'">
                                            <p class="text-[10px] font-black text-slate-400 uppercase tracking-wide">Monto</p>
                                            <p class="text-sm font-bold text-slate-800 mt-0.5">{{ entry.form.monto }}</p>
                                        </div>
                                        <div v-if="entry.form.comision && entry.form.comision !== '0.00'">
                                            <p class="text-[10px] font-black text-slate-400 uppercase tracking-wide">Comisión</p>
                                            <p class="text-sm font-bold text-slate-800 mt-0.5">{{ entry.form.comision }}</p>
                                        </div>
                                    </div>
                                    <div v-if="entry.form.descripcion" class="px-4 py-3">
                                        <p class="text-[10px] font-black text-slate-400 uppercase tracking-wide">{{ isCreate && !isCreateReserva ? 'Motivo del bloqueo' : 'Descripción' }}</p>
                                        <p class="text-sm text-slate-700 mt-0.5">{{ entry.form.descripcion }}</p>
                                    </div>
                                    <div v-if="entry.form.comentariosHuesped" class="px-4 py-3">
                                        <p class="text-[10px] font-black text-slate-400 uppercase tracking-wide">Comentarios del huésped</p>
                                        <p class="text-sm text-slate-700 mt-0.5">{{ entry.form.comentariosHuesped }}</p>
                                    </div>
                                </div>

                                <!-- ===== FORM (crear / editar) ===== -->
                                <div v-else class="grid grid-cols-2 gap-3">
                                    <label class="col-span-2">
                                        <span class="text-xs font-bold text-slate-500">Unidad</span>
                                        <select v-model="entry.form.pmsUnidad" :disabled="fechasUnidadBloqueadasPara(entry)"
                                            class="mt-1 w-full border border-slate-200 rounded-lg px-3 py-2 text-sm disabled:bg-slate-100 disabled:text-slate-400">
                                            <option v-for="u in reservasStore.unidades" :key="u.id ?? ''" :value="u.id ?? ''">
                                                {{ u.nombre }}{{ !u.activo ? ' (inactiva)' : '' }}
                                            </option>
                                        </select>
                                    </label>

                                    <label>
                                        <span class="text-xs font-bold text-slate-500">Check-in</span>
                                        <input type="datetime-local" v-model="entry.form.inicio" :disabled="fechasUnidadBloqueadasPara(entry)"
                                            class="mt-1 w-full border border-slate-200 rounded-lg px-3 py-2 text-sm disabled:bg-slate-100 disabled:text-slate-400" />
                                    </label>

                                    <label>
                                        <span class="text-xs font-bold text-slate-500">Check-out</span>
                                        <input type="datetime-local" v-model="entry.form.fin" :disabled="fechasUnidadBloqueadasPara(entry)"
                                            class="mt-1 w-full border border-slate-200 rounded-lg px-3 py-2 text-sm disabled:bg-slate-100 disabled:text-slate-400" />
                                    </label>

                                    <label v-if="!isCreate || isCreateReserva">
                                        <span class="text-xs font-bold text-slate-500 inline-flex items-center gap-1.5">
                                            <span class="w-2 h-2 rounded-full inline-block"
                                                :style="{ backgroundColor: estadoObj(entry.form.estado)?.color || COLOR_ESTADO_FALLBACK }"></span>
                                            Estado
                                        </span>
                                        <select v-model="entry.form.estado"
                                            class="mt-1 w-full border border-slate-200 rounded-lg px-3 py-2 text-sm">
                                            <option v-for="e in estadosDisponiblesPara(entry)" :key="e.id ?? ''" :value="e.id ?? ''"
                                                :style="{ backgroundColor: e.color || undefined, color: e.color ? contrastText(e.color) : undefined }">
                                                {{ e.nombre }}
                                            </option>
                                        </select>
                                    </label>

                                    <label>
                                        <span class="text-xs font-bold text-slate-500 inline-flex items-center gap-1.5">
                                            <span class="w-2 h-2 rounded-full inline-block"
                                                :style="{ backgroundColor: estadoPagoObj(entry.form.estadoPago)?.color || COLOR_ESTADO_FALLBACK }"></span>
                                            Estado de Pago
                                        </span>
                                        <select v-model="entry.form.estadoPago"
                                            class="mt-1 w-full border border-slate-200 rounded-lg px-3 py-2 text-sm">
                                            <option v-for="ep in reservasStore.estadosPago" :key="ep.id ?? ''" :value="ep.id ?? ''"
                                                :style="{ backgroundColor: ep.color || undefined, color: ep.color ? contrastText(ep.color) : undefined }">
                                                {{ ep.nombre }}
                                            </option>
                                        </select>
                                    </label>

                                    <label>
                                        <span class="text-xs font-bold text-slate-500">Canal</span>
                                        <div class="mt-1 w-full border border-slate-200 bg-slate-100 text-slate-500 rounded-lg px-3 py-2 text-sm flex items-center gap-2">
                                            <i class="fas fa-lock text-[10px]"></i> {{ entry.channelNombre }}
                                        </div>
                                    </label>

                                    <!-- Adultos/Niños: SIEMPRE editables y visibles, también en eventos OTA
                                         (el backend no los protege por listener, a diferencia de fechas/unidad/canal). -->
                                    <label>
                                        <span class="text-xs font-bold text-slate-500">Adultos</span>
                                        <input type="number" min="0" v-model.number="entry.form.cantidadAdultos"
                                            class="mt-1 w-full border border-slate-200 rounded-lg px-3 py-2 text-sm" />
                                    </label>

                                    <label>
                                        <span class="text-xs font-bold text-slate-500">Niños</span>
                                        <input type="number" min="0" v-model.number="entry.form.cantidadNinos"
                                            class="mt-1 w-full border border-slate-200 rounded-lg px-3 py-2 text-sm" />
                                    </label>

                                    <!-- Monto/Comisión: ocultos al editar una estancia OTA (los define el canal). -->
                                    <label v-if="!entry.isOta">
                                        <span class="text-xs font-bold text-slate-500">Monto</span>
                                        <input type="text" v-model="entry.form.monto"
                                            class="mt-1 w-full border border-slate-200 rounded-lg px-3 py-2 text-sm" />
                                    </label>

                                    <label v-if="!entry.isOta">
                                        <span class="text-xs font-bold text-slate-500">Comisión</span>
                                        <input type="text" v-model="entry.form.comision"
                                            class="mt-1 w-full border border-slate-200 rounded-lg px-3 py-2 text-sm" />
                                    </label>

                                    <label class="col-span-2">
                                        <span class="text-xs font-bold text-slate-500">{{ isCreate && !isCreateReserva ? 'Motivo del bloqueo' : 'Descripción' }}</span>
                                        <input type="text" v-model="entry.form.descripcion"
                                            class="mt-1 w-full border border-slate-200 rounded-lg px-3 py-2 text-sm" />
                                    </label>

                                    <!-- Comentarios del huésped: ocultos al editar una estancia OTA. -->
                                    <label class="col-span-2" v-if="!isCreate && !entry.isOta">
                                        <span class="text-xs font-bold text-slate-500">Comentarios del huésped</span>
                                        <textarea v-model="entry.form.comentariosHuesped" rows="2"
                                            class="mt-1 w-full border border-slate-200 rounded-lg px-3 py-2 text-sm"></textarea>
                                    </label>
                                </div>
                            </div>
                        </div>
                    </div>

                    <button v-if="esMultiEvento && !readOnly" type="button" @click="agregarEvento"
                        class="mt-3 w-full flex items-center justify-center gap-2 px-4 py-2.5 border-2 border-dashed border-slate-300 hover:border-[#376875] hover:text-[#376875] rounded-xl text-xs font-black text-slate-400 transition-colors">
                        <i class="fas fa-plus"></i> Agregar estancia en otra casita
                    </button>
                </section>

                <!-- ================= CLIENTE ================= -->
                <section v-if="muestraCliente">
                    <h3 class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-3">
                        <i class="fas fa-user mr-1"></i> Datos del Titular
                    </h3>

                    <!-- ===== IDENTIFICADORES (localizador + guía, referencia del canal, vCard) =====
                         WhatsApp, chat interno y el enlace al canal (Booking/Airbnb) viven ahora en
                         el menú contextual del calendario para no duplicarlos. -->
                    <div v-if="reservaId" class="mb-3 space-y-2">
                        <!-- Localizador propio + copiar enlace a la guía del huésped (van juntos) -->
                        <div v-if="reservaInfo.localizador" class="flex items-center gap-2 flex-wrap">
                            <span class="text-[10px] font-black text-slate-400 uppercase tracking-wide">Localizador</span>
                            <span class="px-2.5 py-1 bg-slate-100 text-slate-600 rounded-lg text-xs font-mono font-bold">
                                {{ reservaInfo.localizador }}
                            </span>
                            <button v-if="guideUrl" @click="copiar(guideUrl, 'guide')"
                                class="flex items-center gap-1.5 px-2.5 py-1 rounded-lg text-xs font-bold transition-colors"
                                :class="copiadoKey === 'guide' ? 'bg-emerald-100 text-emerald-700' : 'bg-slate-100 hover:bg-slate-200 text-slate-600'">
                                <i class="fas" :class="copiadoKey === 'guide' ? 'fa-check' : 'fa-link'"></i>
                                {{ copiadoKey === 'guide' ? 'Copiado' : 'Copiar enlace' }}
                            </button>
                        </div>

                        <!-- Código de referencia del canal externo (OTA), solo informativo -->
                        <div v-if="reservaInfo.referenciaCanalAggregate" class="flex items-center gap-2 flex-wrap">
                            <span class="text-[10px] font-black text-slate-400 uppercase tracking-wide">Ref. canal</span>
                            <span class="px-2.5 py-1 bg-slate-100 text-slate-600 rounded-lg text-xs font-mono font-bold">
                                {{ reservaInfo.referenciaCanalAggregate }}
                            </span>
                        </div>

                    </div>

                    <!-- ===== VISTA (modo "Ver") ===== -->
                    <div v-if="readOnly" class="rounded-xl border border-slate-200 divide-y divide-slate-100 overflow-hidden">
                        <div class="grid grid-cols-2 gap-x-4 gap-y-3 p-4">
                            <div>
                                <p class="text-[10px] font-black text-slate-400 uppercase tracking-wide">Nombre</p>
                                <p class="text-sm font-bold text-slate-800 mt-0.5">{{ clienteForm.nombreCliente || '—' }}</p>
                            </div>
                            <div>
                                <p class="text-[10px] font-black text-slate-400 uppercase tracking-wide">Apellido</p>
                                <p class="text-sm font-bold text-slate-800 mt-0.5">{{ clienteForm.apellidoCliente || '—' }}</p>
                            </div>
                            <div>
                                <p class="text-[10px] font-black text-slate-400 uppercase tracking-wide">Teléfono</p>
                                <div class="flex items-center gap-2 mt-0.5">
                                    <p class="text-sm font-bold text-slate-800">{{ formatearTelefono(clienteForm.telefono) || '—' }}</p>
                                    <a v-if="vcardUrl && clienteForm.telefono" :href="vcardUrl" target="_blank" title="Descargar contacto (vCard)"
                                        class="inline-flex items-center gap-1 px-2 py-1 bg-indigo-100 hover:bg-indigo-200 text-indigo-700 border border-indigo-200 rounded-lg text-[10px] font-black uppercase tracking-wide transition-colors shrink-0">
                                        <i class="fas fa-address-card"></i> vCard
                                    </a>
                                </div>
                            </div>
                            <div>
                                <p class="text-[10px] font-black text-slate-400 uppercase tracking-wide">Teléfono 2</p>
                                <p class="text-sm font-bold text-slate-800 mt-0.5">{{ formatearTelefono(clienteForm.telefono2) || '—' }}</p>
                            </div>
                            <div class="col-span-2">
                                <p class="text-[10px] font-black text-slate-400 uppercase tracking-wide">Email</p>
                                <p class="text-sm font-bold text-slate-800 mt-0.5">{{ clienteForm.emailCliente || '—' }}</p>
                            </div>
                            <div>
                                <p class="text-[10px] font-black text-slate-400 uppercase tracking-wide">País</p>
                                <p class="text-sm font-bold text-slate-800 mt-0.5">{{ nombrePais() }}</p>
                            </div>
                            <div>
                                <p class="text-[10px] font-black text-slate-400 uppercase tracking-wide">Idioma</p>
                                <p class="text-sm font-bold text-slate-800 mt-0.5">{{ nombreIdioma() }}</p>
                            </div>
                        </div>
                        <div v-if="clienteForm.nota" class="px-4 py-3">
                            <p class="text-[10px] font-black text-slate-400 uppercase tracking-wide">Nota interna</p>
                            <p class="text-sm text-slate-700 mt-0.5">{{ clienteForm.nota }}</p>
                        </div>
                        <div v-if="clienteForm.datosLocked" class="px-4 py-3 text-xs font-bold text-amber-700 bg-amber-50">
                            <i class="fas fa-lock mr-1.5"></i> Datos bloqueados contra sincronización.
                        </div>
                    </div>

                    <!-- ===== FORM (crear / editar) ===== -->
                    <div v-else class="grid grid-cols-2 gap-3">
                        <label>
                            <span class="text-xs font-bold text-slate-500">Nombre</span>
                            <input type="text" v-model="clienteForm.nombreCliente"
                                class="mt-1 w-full border border-slate-200 rounded-lg px-3 py-2 text-sm" />
                        </label>
                        <label>
                            <span class="text-xs font-bold text-slate-500">Apellido</span>
                            <input type="text" v-model="clienteForm.apellidoCliente"
                                class="mt-1 w-full border border-slate-200 rounded-lg px-3 py-2 text-sm" />
                        </label>
                        <label>
                            <span class="text-xs font-bold text-slate-500">Teléfono</span>
                            <span v-if="formatearTelefono(clienteForm.telefono) && formatearTelefono(clienteForm.telefono) !== clienteForm.telefono"
                                class="ml-1.5 text-[10px] font-bold text-slate-400">{{ formatearTelefono(clienteForm.telefono) }}</span>
                            <input type="text" v-model="clienteForm.telefono"
                                class="mt-1 w-full border border-slate-200 rounded-lg px-3 py-2 text-sm" />
                        </label>
                        <label>
                            <span class="text-xs font-bold text-slate-500">Teléfono 2</span>
                            <input type="text" v-model="clienteForm.telefono2"
                                class="mt-1 w-full border border-slate-200 rounded-lg px-3 py-2 text-sm" />
                        </label>
                        <label class="col-span-2">
                            <span class="text-xs font-bold text-slate-500">Email</span>
                            <input type="email" v-model="clienteForm.emailCliente"
                                class="mt-1 w-full border border-slate-200 rounded-lg px-3 py-2 text-sm" />
                        </label>
                        <label>
                            <span class="text-xs font-bold text-slate-500">País</span>
                            <select v-model="clienteForm.pais"
                                class="mt-1 w-full border border-slate-200 rounded-lg px-3 py-2 text-sm">
                                <option value="">-</option>
                                <option v-for="p in maestroStore.paises" :key="p.id ?? ''" :value="p.id ?? ''">{{ p.nombre }}</option>
                            </select>
                        </label>
                        <label>
                            <span class="text-xs font-bold text-slate-500">Idioma</span>
                            <select v-model="clienteForm.idioma"
                                class="mt-1 w-full border border-slate-200 rounded-lg px-3 py-2 text-sm">
                                <option value="">-</option>
                                <option v-for="i in maestroStore.idiomas" :key="i.id ?? ''" :value="i.id ?? ''">{{ i.nombre }}</option>
                            </select>
                        </label>
                        <label class="col-span-2">
                            <span class="text-xs font-bold text-slate-500">Nota interna</span>
                            <textarea v-model="clienteForm.nota" rows="2"
                                class="mt-1 w-full border border-slate-200 rounded-lg px-3 py-2 text-sm"></textarea>
                        </label>
                        <label class="col-span-2 flex items-center gap-2" v-if="!isCreateReserva">
                            <input type="checkbox" v-model="clienteForm.datosLocked" class="rounded" />
                            <span class="text-xs font-bold text-slate-500">Bloquear datos (proteger contra sincronización)</span>
                        </label>
                    </div>
                </section>

            </div>

            <footer class="border-t border-slate-200 px-5 py-4 flex items-center justify-end gap-3 shrink-0">
                <button v-if="readOnly" @click="emit('close')"
                    class="px-5 py-2 bg-slate-100 hover:bg-slate-200 text-slate-600 rounded-xl text-sm font-black transition-colors">
                    Cerrar
                </button>
                <template v-else>
                    <button @click="emit('close')" class="px-4 py-2 text-sm font-bold text-slate-500 hover:text-slate-700">
                        Cancelar
                    </button>
                    <button @click="guardar" :disabled="reservasStore.isSaving || isLoadingDrawer"
                        class="px-5 py-2 bg-[#376875] hover:bg-[#2d5660] disabled:opacity-50 text-white rounded-xl text-sm font-black shadow-sm transition-colors">
                        <i class="fas" :class="reservasStore.isSaving ? 'fa-circle-notch fa-spin' : 'fa-check'"></i>
                        {{ isCreateReserva ? 'Crear Reserva' : (isCreate ? 'Crear Bloqueo' : 'Guardar Cambios') }}
                    </button>
                </template>
            </footer>
        </div>
    </div>
</template>

<style scoped>
.animate-slide-in {
    animation: slideIn 0.25s cubic-bezier(0.16, 1, 0.3, 1) both;
}
@keyframes slideIn {
    from { transform: translateX(100%); }
    to { transform: translateX(0); }
}
</style>
