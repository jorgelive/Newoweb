<script setup lang="ts">
import { ref, computed, watch, nextTick } from 'vue';
import { useReservasStore, extractApiErrorMessage } from '@/stores/reservas/reservasStore';
import { useMaestroStore } from '@/stores/maestroStore';
import { getUrls } from '@/services/apiClient';
import { formatearTelefono } from '@/utils/telefono';
import ReservaFinanzasPanel from '@/components/reservas/ReservaFinanzasPanel.vue';
import FechaHoraPicker from '@/components/common/FechaHoraPicker.vue';
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
    fechaAInputLocal,
    filtrarEstadosDisponibles,
    requiereAutoConfirmacionPorPago,
    pagoMandaSobreEstado,
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

const emit = defineEmits<{
    close: [];
    /**
     * `reservaIdCreada` sólo viaja al crear una reserva directa: la vista deja el drawer
     * abierto sobre ella para poder cargarle finanzas en el acto (ver ReservasView.onGuardado).
     */
    saved: [payload?: { reservaIdCreada?: string }];
    deleted: [];
}>();

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

/**
 * Vuelve a la ficha de solo lectura. Muestra los valores que hay AHORA en el formulario,
 * estén guardados o no: es una vista previa, no una recarga desde el servidor.
 */
function verSoloLectura(): void {
    readOnly.value = true;
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
    /**
     * Último valor conocido de `form.inicio`. No es un dato del formulario: sirve para saber
     * CUÁNTOS días se movió el check-in y arrastrar el check-out la misma distancia
     * (ver `onCambiarInicio`). Sin él, al cambiar el inicio no hay contra qué medir el salto.
     */
    inicioPrevio: string;
}

/** Noches que abarca una estancia nueva (espejo de RANGO_POR_DEFECTO_DIAS en ReservasView). */
const RANGO_POR_DEFECTO_DIAS = 2;

// ============================================================================
// UTILIDADES DE `datetime-local` ("YYYY-MM-DDTHH:mm")
//
// Se manipulan como fecha LOCAL, nunca con `new Date(str)` a secas: ese constructor
// interpreta "YYYY-MM-DD" como UTC y en Perú (UTC-5) devuelve el día anterior.
// ============================================================================
function parseLocal(dt: string): Date | null {
    if (!dt) return null;
    const [fecha, hora] = dt.split('T');
    const [y, m, d] = fecha.split('-').map(Number);
    const [hh, mm] = (hora ?? '00:00').split(':').map(Number);
    if ([y, m, d].some(Number.isNaN)) return null;
    return new Date(y, m - 1, d, hh || 0, mm || 0);
}

/** Alias del helper compartido: una sola definición de "Date -> hora de pared". */
const aDatetimeLocal = fechaAInputLocal;

/** Días naturales entre dos fechas, ignorando la hora. */
function diasEntre(desde: Date, hasta: Date): number {
    const a = new Date(desde.getFullYear(), desde.getMonth(), desde.getDate());
    const b = new Date(hasta.getFullYear(), hasta.getMonth(), hasta.getDate());
    return Math.round((b.getTime() - a.getTime()) / 86_400_000);
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
        inicioPrevio: toDatetimeLocal(evento.inicio),
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
/**
 * ¿Esta reserva admite varias estancias? Sí al crear una reserva completa (un grupo puede
 * ocupar varias casitas desde el minuto uno) y al editar una ya guardada. Un BLOQUEO no:
 * es un único tramo de calendario.
 */
const permiteVariasEstancias = computed(() => isCreateReserva.value || (!isCreate.value && !!props.reservaId));

/**
 * ¿Se pinta la envoltura de acordeón? En una reserva ya guardada siempre (así se ha visto
 * siempre); al crear, sólo en cuanto se añade la segunda estancia — con una sola, las
 * cabeceras plegables serían ruido sobre un formulario que ya se ve entero.
 */
const esMultiEvento = computed(
    () => permiteVariasEstancias.value && (!isCreate.value || eventos.value.length > 1),
);
const hayOta = computed(() => eventos.value.some(e => e.isOta));

function estadosDisponiblesPara(entry: EventoEntry) {
    const todos = filtrarEstadosDisponibles(reservasStore.estados, entry.isOta, entry.estadoActualId);
    // Una estancia nueva (sin guardar) o cualquier estancia durante la creación
    // del drawer nunca ofrece el estado "bloqueo": estas son reservas reales.
    const base = (!entry.eventoId || isCreate.value) ? todos.filter(e => e.id !== PMS_ESTADO.BLOQUEO) : todos;

    // El estado vigente siempre tiene que poder mostrarse, aunque el filtro OTA no
    // lo ofrezca (caso real: una consulta "abierta" de Airbnb con pago registrado,
    // que la auto-confirmación pasa a "confirmada"). Sin esto el <select> se queda
    // en blanco y el guardado mandaría un estado vacío.
    const actual = estadoObj(entry.form.estado);
    return actual && !base.some(e => e.id === actual.id) ? [...base, actual] : base;
}

// En "Nueva Reserva" el backend (PmsReservaCrearProcessor) crea la estancia
// siempre como pendiente + no pagado y descarta estos dos campos del formulario:
// aquí no anticipamos una confirmación que no va a ocurrir al guardar.
const autoConfirmacionNoAplica = computed(() => isCreateReserva.value);

/**
 * Aplica en el formulario la misma regla que el backend ejecutará al guardar
 * (PmsEventoCalendarioIntegrityListener): con un pago registrado la estancia
 * queda "confirmada". Sin esto, el operador elegía "Pago total", guardaba viendo
 * "Pendiente" y el calendario se recargaba en verde: un cambio invisible hasta
 * después del guardado.
 */
function aplicarAutoConfirmacionPorPago(entry: EventoEntry): void {
    if (autoConfirmacionNoAplica.value) return;

    if (requiereAutoConfirmacionPorPago(entry.form.estado, entry.form.estadoPago)) {
        entry.form.estado = PMS_ESTADO.CONFIRMADA;
    }
}

/** ¿Mostrar el aviso de que el pago manda sobre el estado de esta estancia? */
function pagoFuerzaConfirmada(entry: EventoEntry): boolean {
    return !autoConfirmacionNoAplica.value && pagoMandaSobreEstado(entry.form.estado, entry.form.estadoPago);
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

/**
 * Sufijos de opacidad (canal alfa en hex) que se concatenan al color del estado.
 *
 * La cabecera va más saturada que el cuerpo a propósito: con varias estancias apiladas, ese
 * salto de tono es lo que marca dónde acaba una y empieza la siguiente. El cuerpo queda muy
 * tenue para no restar contraste a los campos del formulario que lleva encima.
 */
const CABECERA_ALPHA = '2E'; // ~18%
const CUERPO_ALPHA   = '0A'; // ~4%
const BORDE_ALPHA    = '59'; // ~35%

// ============================================================================
// FECHAS: arrastre del check-out y validación del rango
// ============================================================================

/**
 * Al mover el check-in, el check-out se desplaza los MISMOS días, conservando su hora.
 * Así el operador que corre una reserva de semana no tiene que recalcular la salida: la
 * duración de la estancia se mantiene y la hora de check-out del establecimiento no se toca.
 *
 * Si el inicio se empuja más allá del fin (o el fin quedaba vacío), se recompone con el
 * rango por defecto, que siempre deja una estancia válida.
 */
function onCambiarInicio(entry: EventoEntry): void {
    const nuevo = parseLocal(entry.form.inicio);
    if (!nuevo) return;

    const previo = parseLocal(entry.inicioPrevio);
    const fin = parseLocal(entry.form.fin);

    if (previo && fin) {
        const desplazamiento = diasEntre(previo, nuevo);
        if (desplazamiento !== 0) {
            const finNuevo = new Date(fin);
            finNuevo.setDate(finNuevo.getDate() + desplazamiento); // conserva hora y minutos
            entry.form.fin = aDatetimeLocal(finNuevo);
        }
    }

    // Red de seguridad: nunca dejar un rango imposible tras el arrastre.
    const finFinal = parseLocal(entry.form.fin);
    if (!finFinal || finFinal <= nuevo) {
        const recompuesto = new Date(nuevo);
        recompuesto.setDate(recompuesto.getDate() + RANGO_POR_DEFECTO_DIAS);
        if (finFinal) recompuesto.setHours(finFinal.getHours(), finFinal.getMinutes(), 0, 0);
        entry.form.fin = aDatetimeLocal(recompuesto);
    }

    entry.inicioPrevio = entry.form.inicio;
}

/** ¿El rango de esta estancia es imposible? Devuelve el motivo, o null si está bien. */
function errorFechas(entry: EventoEntry): string | null {
    const inicio = parseLocal(entry.form.inicio);
    const fin = parseLocal(entry.form.fin);
    if (!inicio || !fin) return 'Falta la fecha de entrada o de salida.';
    if (fin <= inicio) return 'La salida debe ser posterior a la entrada.';
    return null;
}

/**
 * La unidad es obligatoria: sin ella el backend responde 400 y el drawer se quedaba
 * mostrando un error genérico. Se valida aquí para señalar el campo concreto.
 * En estancias OTA no se comprueba: el canal ya la fijó y el select va bloqueado.
 */
function errorUnidad(entry: EventoEntry): string | null {
    if (fechasUnidadBloqueadasPara(entry)) return null;
    return entry.form.pmsUnidad ? null : 'Elige la casita de esta estancia.';
}

/** El guardado se bloquea mientras cualquier estancia esté incompleta. */
const hayErrorFechas = computed(() => eventos.value.some(e => errorFechas(e) !== null));
const hayErrorUnidad = computed(() => eventos.value.some(e => errorUnidad(e) !== null));
const hayErroresEstancia = computed(() => hayErrorFechas.value || hayErrorUnidad.value);

/**
 * Copia fechas y horas de la estancia anterior a la de índice `i`.
 *
 * Al añadir una estancia, las fechas por defecto ENCADENAN (empiezan donde acabó la
 * anterior), que es lo correcto para una mudanza de casita a mitad de viaje. Pero el otro
 * caso es igual de común: dos casitas ocupadas **en paralelo** por el mismo grupo. Este
 * botón resuelve ese segundo caso sin teclear las fechas de nuevo.
 */
function clonarFechasDeAnterior(i: number): void {
    const anterior = eventos.value[i - 1];
    const actual = eventos.value[i];
    if (!anterior || !actual) return;

    actual.form.inicio = anterior.form.inicio;
    actual.form.fin = anterior.form.fin;
    actual.inicioPrevio = anterior.form.inicio;
}

/** Sólo tiene sentido ofrecerlo si hay una estancia anterior de la que copiar. */
function puedeClonarFechas(entry: EventoEntry, i: number): boolean {
    return i > 0 && !readOnly.value && !fechasUnidadBloqueadasPara(entry);
}

function toggleAcordeon(i: number): void {
    activeIndex.value = activeIndex.value === i ? -1 : i;
}

function agregarEvento(): void {
    const ultimo = eventos.value[eventos.value.length - 1];
    // `parseLocal` y no `new Date(fromDatetimeLocal(...))`: la salida de la estancia anterior
    // es hora de pared; pasarla por UTC desplazaría el día de entrada de la nueva.
    const inicio = parseLocal(ultimo?.form.fin ?? '') ?? new Date();
    inicio.setHours(14, 0, 0, 0);
    const fin = new Date(inicio);
    fin.setDate(fin.getDate() + RANGO_POR_DEFECTO_DIAS);
    fin.setHours(10, 0, 0, 0);

    const inicioLocal = aDatetimeLocal(inicio);

    eventos.value.push({
        eventoId: null,
        isOta: false,
        estadoActualId: null,
        channelNombre: 'Directo',
        inicioPrevio: inicioLocal,
        form: formVacio({
            inicio: inicioLocal,
            fin: aDatetimeLocal(fin),
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
                inicioPrevio: toDatetimeLocal(props.createDefaults.inicio),
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

/**
 * Referencia al panel financiero, para arrastrar sus formularios a medio escribir al pulsar
 * "Guardar Cambios". Ese botón es mucho más visible que los pequeños de cada formulario del
 * panel, así que es el que se acaba usando: sin esto, un cargo o un pago tecleado pero no
 * confirmado se perdía en silencio al cerrar el drawer.
 */
const finanzasPanel = ref<{ guardarPendientes: () => Promise<void> } | null>(null);

/** El id de la reserva recién creada; según el formato de respuesta llega en `id` o en `@id`. */
function extraerId(reserva: unknown): string | null {
    const r = reserva as { id?: string; '@id'?: string } | null;
    if (r?.id) return r.id;
    const iri = r?.['@id'];
    return iri ? (iri.split('/').pop() ?? null) : null;
}

async function guardar(): Promise<void> {
    localError.value = null;

    // Se corta antes de tocar la red: el backend rechaza ambos casos (400 / 422), pero
    // avisando aquí se señala el campo concreto y no se pierde el formulario.
    if (hayErrorUnidad.value) {
        localError.value = 'Falta elegir la casita en alguna estancia.';
        return;
    }
    if (hayErrorFechas.value) {
        localError.value = 'Revisa las fechas: la salida debe ser posterior a la entrada.';
        return;
    }

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
            const creada = await reservasStore.createReservaCompleta(payload);
            const idCreada = extraerId(creada);

            // El endpoint de creación sólo admite UNA estancia (PmsReservaCrearProcessor).
            // Las demás casitas del acordeón se crean después, colgadas de la reserva recién
            // nacida — mismo camino que usa la edición para una estancia añadida a mano.
            if (idCreada && eventos.value.length > 1) {
                for (const extra of eventos.value.slice(1)) {
                    const payloadExtra = payloadCreacion(extra, extra.form.estado);
                    payloadExtra.reserva = pmsReservaIri(idCreada);
                    await reservasStore.createEvento(payloadExtra);
                }
            }

            // La reserva directa nace sin cargos (no hay canal que los mande). En vez de
            // cerrar y obligar a buscarla de nuevo en el calendario, devolvemos su id para
            // que la vista reabra el drawer en modo edición, ya con el panel financiero.
            emit('saved', idCreada ? { reservaIdCreada: idCreada } : undefined);
            return;
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

        // Al final, para que un fallo aquí no deje a medias la estancia y el titular: si esto
        // revienta, se muestra el error y el drawer NO se cierra, así no se pierde lo tecleado
        // en el panel. Lo ya guardado arriba es idempotente al reintentar.
        await finanzasPanel.value?.guardarPendientes();

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
                    <!-- Vuelta a la ficha de lectura. No aparece al crear: todavía no hay nada que ver. -->
                    <button v-else-if="!isCreate" @click="verSoloLectura"
                        class="px-3 h-8 flex items-center gap-1.5 bg-slate-700 hover:bg-slate-600 rounded-full transition-colors text-xs font-black">
                        <i class="fas fa-eye text-[11px]"></i> Ver
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

                <!-- Nota discreta: es contexto permanente de una reserva OTA, no una alerta. -->
                <p v-if="hayOta" class="text-[11px] text-slate-400 leading-snug">
                    <i class="fas fa-info-circle mr-1"></i>
                    Fechas y unidad las gestiona el canal; para cambiarlas, hazlo en Booking/Airbnb.
                </p>

                <!-- ================= FINANZAS =================
                     Va ARRIBA del todo y colapsada: es lo primero que se consulta al abrir una
                     reserva (¿cuánto debe?), pero ocupa mucho desplegada. Solo sobre una reserva
                     ya persistida: la cabecera financiera no existe mientras se está creando. -->
                <ReservaFinanzasPanel v-if="reservaId" ref="finanzasPanel"
                    :reserva-id="reservaId" :read-only="readOnly" />

                <!-- ================= ESTANCIA(S) ================= -->
                <section>
                    <h3 class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-3">
                        <i class="fas fa-bed mr-1"></i> {{ esMultiEvento ? 'Estancias' : 'Estancia' }}
                    </h3>

                    <div class="space-y-3">
                        <!-- Cada estancia se tiñe con el color de SU estado: la cabecera más
                             saturada y el cuerpo apenas insinuado, para que se vea de un vistazo
                             dónde empieza y acaba cada una sin leer las fechas. -->
                        <div v-for="(entry, i) in eventos" :key="entry.eventoId ?? `nuevo-${i}`"
                            class="rounded-xl overflow-hidden border"
                            :style="{ borderColor: colorEntry(entry) + BORDE_ALPHA }">

                            <!-- Barra: `div` y no `button`, porque lleva dentro el botón de clonar
                                 fechas (un <button> anidado es HTML inválido y roba el clic). -->
                            <div v-if="esMultiEvento" class="flex items-stretch"
                                :style="{ borderLeft: `5px solid ${colorEntry(entry)}`, backgroundColor: colorEntry(entry) + CABECERA_ALPHA }">
                                <button type="button" @click="toggleAcordeon(i)"
                                    class="flex-1 min-w-0 flex items-center gap-2 px-4 py-3 hover:brightness-95 text-left transition-[filter]">
                                    <span class="flex items-center gap-2 text-sm font-bold text-slate-700 min-w-0">
                                        <i class="fas fa-chevron-right text-[10px] text-slate-400 transition-transform shrink-0"
                                            :class="{ 'rotate-90': activeIndex === i }"></i>
                                        <span class="w-2.5 h-2.5 rounded-full shrink-0" :style="{ backgroundColor: colorEntry(entry) }"></span>
                                        <span class="truncate">{{ nombreUnidad(entry) }}</span>
                                        <!-- Las fechas son el dato que más se consulta de la cabecera:
                                             van en el mismo peso que la casita, con el pax en gris. -->
                                        <span class="text-slate-700 font-bold text-xs whitespace-nowrap">
                                            {{ fechaCorta(entry.form.inicio) }} → {{ fechaCorta(entry.form.fin) }}
                                            <span class="text-slate-400 font-medium">· {{ paxTotal(entry) }} pax</span>
                                        </span>
                                        <span v-if="!entry.eventoId" class="text-emerald-600 text-[10px] font-black uppercase shrink-0">Nuevo</span>
                                    </span>
                                </button>

                                <!-- Las fechas por defecto ENCADENAN (mudanza de casita). Esto cubre el
                                     otro caso frecuente: dos casitas ocupadas en paralelo. -->
                                <button v-if="puedeClonarFechas(entry, i)" type="button"
                                    @click="clonarFechasDeAnterior(i)"
                                    title="Usar las mismas fechas y horas que la estancia anterior"
                                    class="px-3 flex items-center text-slate-400 hover:text-[#376875] hover:bg-white/40 transition-colors shrink-0">
                                    <!-- Doble calendario: dos iconos superpuestos. FontAwesome no trae
                                         uno "calendario duplicado", así que se compone; leerlo como
                                         "copiar fechas" es más directo que un icono de copiar genérico. -->
                                    <span class="relative inline-block w-[15px] h-[13px]">
                                        <i class="far fa-calendar text-[11px] absolute left-0 top-0 opacity-45"></i>
                                        <i class="fas fa-calendar text-[11px] absolute left-[4px] top-[2px]"></i>
                                    </span>
                                </button>
                            </div>

                            <div v-show="!esMultiEvento || activeIndex === i" class="p-4"
                                :style="{ backgroundColor: colorEntry(entry) + CUERPO_ALPHA }">
                                <div v-if="esMultiEvento" class="text-[11px] font-bold text-slate-500 mb-3">
                                    <i class="fas fa-home mr-1 text-slate-400"></i> {{ nombreUnidad(entry) }}
                                    · <span class="text-slate-700">{{ fechaCorta(entry.form.inicio) }} → {{ fechaCorta(entry.form.fin) }}</span>
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
                                        <!-- Monto y comisión no se muestran: el dinero se consulta en el
                                             panel de Finanzas, que es la única fuente. -->
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
                                            class="mt-1 w-full border rounded-lg px-3 py-2 text-sm disabled:bg-slate-100 disabled:text-slate-400"
                                            :class="errorUnidad(entry) ? 'border-rose-300 bg-rose-50' : 'border-slate-200'">
                                            <!-- Opción vacía explícita: sin ella el select muestra la primera casita
                                                 aunque el modelo esté vacío, y el operador cree haber elegido. -->
                                            <option value="">— Elegir casita —</option>
                                            <option v-for="u in reservasStore.unidades" :key="u.id ?? ''" :value="u.id ?? ''">
                                                {{ u.nombre }}{{ !u.activo ? ' (inactiva)' : '' }}
                                            </option>
                                        </select>
                                        <span v-if="errorUnidad(entry)" class="mt-1 block text-[11px] font-bold text-rose-600">
                                            <i class="fas fa-triangle-exclamation mr-1"></i>{{ errorUnidad(entry) }}
                                        </span>
                                    </label>

                                    <!-- Fechas: rejilla propia con `auto-fit` + `minmax`. Una fecha con
                                         hora ("DD/MM/AAAA HH:MM") necesita ~11rem para no cortarse;
                                         por debajo de 2×11rem + gap, el navegador baja el check-out a
                                         la fila siguiente solo, sin media queries. -->
                                    <div class="col-span-2 grid gap-3"
                                        style="grid-template-columns: repeat(auto-fit, minmax(11rem, 1fr))">
                                        <label>
                                            <span class="text-xs font-bold text-slate-500">Check-in</span>
                                            <!-- Al mover la entrada, la salida se arrastra los mismos días (onCambiarInicio). -->
                                            <div class="mt-1">
                                                <!-- Handler explícito en vez de `v-model` + `@update`:
                                                     con los dos, el orden de ejecución no está
                                                     garantizado y onCambiarInicio podía leer el
                                                     valor anterior. -->
                                                <FechaHoraPicker :model-value="entry.form.inicio"
                                                    :disabled="fechasUnidadBloqueadasPara(entry)"
                                                    @update:model-value="(v: string) => { entry.form.inicio = v; onCambiarInicio(entry); }" />
                                            </div>
                                        </label>

                                        <label>
                                            <span class="text-xs font-bold text-slate-500">Check-out</span>
                                            <div class="mt-1">
                                                <FechaHoraPicker v-model="entry.form.fin"
                                                    :min-date="entry.form.inicio"
                                                    :disabled="fechasUnidadBloqueadasPara(entry)"
                                                    :invalido="!!errorFechas(entry)" />
                                            </div>
                                        </label>

                                        <p v-if="errorFechas(entry)" class="text-[11px] font-bold text-rose-600 -mt-1">
                                            <i class="fas fa-triangle-exclamation mr-1"></i>{{ errorFechas(entry) }}
                                        </p>
                                    </div>

                                    <label v-if="!isCreate || isCreateReserva">
                                        <span class="text-xs font-bold text-slate-500 inline-flex items-center gap-1.5">
                                            <span class="w-2 h-2 rounded-full inline-block"
                                                :style="{ backgroundColor: estadoObj(entry.form.estado)?.color || COLOR_ESTADO_FALLBACK }"></span>
                                            Estado
                                        </span>
                                        <select v-model="entry.form.estado" @change="aplicarAutoConfirmacionPorPago(entry)"
                                            class="mt-1 w-full border border-slate-200 rounded-lg px-3 py-2 text-sm">
                                            <option v-for="e in estadosDisponiblesPara(entry)" :key="e.id ?? ''" :value="e.id ?? ''"
                                                :style="{ backgroundColor: e.color || undefined, color: e.color ? contrastText(e.color) : undefined }">
                                                {{ e.nombre }}
                                            </option>
                                        </select>
                                        <!-- Explica por qué el select "rebota" a Confirmada si se intenta bajarlo. -->
                                        <span v-if="pagoFuerzaConfirmada(entry)"
                                            class="mt-1 block text-[10px] font-bold text-emerald-600">
                                            <i class="fas fa-lock text-[9px] mr-1"></i>Con un pago registrado la estancia queda Confirmada.
                                        </span>
                                    </label>

                                    <label>
                                        <span class="text-xs font-bold text-slate-500 inline-flex items-center gap-1.5">
                                            <span class="w-2 h-2 rounded-full inline-block"
                                                :style="{ backgroundColor: estadoPagoObj(entry.form.estadoPago)?.color || COLOR_ESTADO_FALLBACK }"></span>
                                            Estado de Pago
                                        </span>
                                        <select v-model="entry.form.estadoPago" @change="aplicarAutoConfirmacionPorPago(entry)"
                                            class="mt-1 w-full border border-slate-200 rounded-lg px-3 py-2 text-sm">
                                            <option v-for="ep in reservasStore.estadosPago" :key="ep.id ?? ''" :value="ep.id ?? ''"
                                                :style="{ backgroundColor: ep.color || undefined, color: ep.color ? contrastText(ep.color) : undefined }">
                                                {{ ep.nombre }}
                                            </option>
                                        </select>
                                    </label>

                                    <!-- Canal (bloqueado) + pax caben en una sola fila incluso en móvil:
                                         son tres campos cortos, así que van a 3 columnas fijas.
                                         Adultos/Niños son editables también en OTA (el backend no los
                                         protege por listener, a diferencia de fechas/unidad/canal). -->
                                    <div class="col-span-2 grid grid-cols-3 gap-3">
                                        <label>
                                            <span class="text-xs font-bold text-slate-500">Canal</span>
                                            <div class="mt-1 w-full border border-slate-200 bg-slate-100 text-slate-500 rounded-lg px-3 py-2 text-sm flex items-center gap-1.5 truncate">
                                                <i class="fas fa-lock text-[10px] shrink-0"></i>
                                                <span class="truncate">{{ entry.channelNombre }}</span>
                                            </div>
                                        </label>

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
                                    </div>

                                    <!-- Monto y comisión ya NO se editan aquí: el dinero de la reserva vive
                                         en el panel de Finanzas (cargos + pagos). Duplicarlo daba dos cifras
                                         que podían contradecirse. Los campos siguen en el modelo y se envían
                                         sin tocar, para no pisar lo que sincroniza el canal. -->

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

                    <button v-if="permiteVariasEstancias && !readOnly" type="button" @click="agregarEvento"
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
                    <button @click="guardar" :disabled="reservasStore.isSaving || isLoadingDrawer || hayErroresEstancia"
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
