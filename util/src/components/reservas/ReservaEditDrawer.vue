<script setup lang="ts">
import { ref, computed, watch, nextTick, onMounted, onBeforeUnmount, type ComponentPublicInstance } from 'vue';
import { useRouter } from 'vue-router';
import { useReservasStore, extractApiErrorMessage } from '@/stores/reservas/reservasStore';
import { useMaestroStore } from '@/stores/maestroStore';
import { getUrls } from '@/services/apiClient';
import { formatearTelefono, telefonoParaWhatsapp } from '@/utils/telefono';
import { enfocarEnScroller } from '@/utils/scrollEnfoque';
import ReservaFinanzasPanel from '@/components/reservas/ReservaFinanzasPanel.vue';
import FechaHoraPicker from '@/components/common/FechaHoraPicker.vue';
import {
    PMS_ESTADO,
    PMS_ESTADO_PAGO,
    PMS_CHANNEL,
    telefonoContactoDe,
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
    puedeBorrarseYa,
    COLOR_ESTADO_FALLBACK,
    type PmsSyncStatus,
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
     *
     * `cerrar` decide si la vista cierra el drawer. Por defecto NO se cierra: guardar suele
     * ser el paso previo a otra cosa sobre la misma reserva, y el calendario se refresca
     * igualmente. Sólo lo pide «Guardar y cerrar».
     */
    saved: [payload?: { reservaIdCreada?: string; cerrar?: boolean }];
    deleted: [];
}>();

const router = useRouter();
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
    /** Early check-in pactado: bloquea la noche ANTERIOR en el canal y genera su cargo. */
    entradaTemprana: boolean;
    /** Late check-out pactado: bloquea la noche en el canal y genera su cargo. */
    salidaTardia: boolean;
}

interface EventoEntry {
    /**
     * Clave estable de la fila, sólo para el `v-for`. No viaja al backend.
     *
     * Hace falta porque una estancia nueva no tiene `eventoId` y la clave era el índice:
     * al descartar una intermedia, las de abajo cambiaban de índice y Vue reutilizaba el
     * DOM de la equivocada, dejando el formulario de una casita sobre los datos de otra.
     */
    uid: string;
    eventoId: string | null;
    isOta: boolean;
    estadoActualId: string | null;
    channelNombre: string;
    form: EventoFormData;
    /**
     * ¿La estancia llegó del servidor con horario extra marcado? Es lo que congela
     * las fechas (ver `fechasBloqueadasPara`), y no la casilla actual.
     */
    horarioExtraGuardado: boolean;
    /**
     * Último valor conocido de `form.inicio`. No es un dato del formulario: sirve para saber
     * CUÁNTOS días se movió el check-in y arrastrar el check-out la misma distancia
     * (ver `onCambiarInicio`). Sin él, al cambiar el inicio no hay contra qué medir el salto.
     */
    inicioPrevio: string;
    /** Veredicto del backend sobre el borrado, tal cual llegó en la última carga. */
    safeToDelete: boolean;
    motivoNoBorrable: string | null;
    /** Estado de la cola de push. Ver `puedeBorrarseYa()`: `safeToDelete` solo no basta. */
    syncStatus: PmsSyncStatus | undefined;
}

let contadorUid = 0;
/** Clave de fila estable, viva sólo mientras el drawer está abierto. */
function nuevoUid(): string {
    contadorUid += 1;
    return `e${contadorUid}`;
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

/**
 * Parte de día de un `datetime-local` ("2026-07-13T14:00" -> "2026-07-13").
 *
 * Se usa como suelo del check-out. Pasarle la fecha COMPLETA hacía que el suelo llevase
 * también la hora de entrada, y entonces tocar la hora de check-in movía la de check-out:
 * el time-picker de `VueDatePicker` toma `min-date` como límite de hora y reajusta el valor.
 * La salida tiene que ser posterior a la entrada —eso lo valida `errorFechas()`—, pero su
 * HORA es del establecimiento y no depende de a qué hora entre el huésped.
 */
function soloDia(dt: string): string {
    return (dt || '').slice(0, 10);
}

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
        entradaTemprana: false,
        salidaTardia: false,
        cantidadNinos: 0,
        ...overrides,
    };
}

function entryDesdeEvento(evento: PmsEventoCalendario): EventoEntry {
    return {
        uid: nuevoUid(),
        eventoId: evento.id ?? null,
        isOta: !!evento.ota,
        estadoActualId: evento.estado?.id ?? null,
        channelNombre: evento.channel?.nombre ?? '—',
        horarioExtraGuardado: (evento.entradaTemprana ?? false) || (evento.salidaTardia ?? false),
        inicioPrevio: toDatetimeLocal(evento.inicio),
        safeToDelete: evento.safeToDelete ?? false,
        motivoNoBorrable: evento.motivoNoBorrable ?? null,
        syncStatus: evento.syncStatus,
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
            entradaTemprana: evento.entradaTemprana ?? false,
            salidaTardia: evento.salidaTardia ?? false,
            cantidadNinos: evento.cantidadNinos ?? 0,
        },
    };
}

const eventos = ref<EventoEntry[]>([]);
const activeIndex = ref(0);

/**
 * Las estancias CANCELADAS se ocultan por defecto.
 *
 * Una reserva trabajada acumula canceladas —cambios de casita, duplicados que se
 * anularon—, y mezcladas con la viva obligan a leer el estado de cada una para
 * saber cuál es la buena. Se siguen guardando y enviando igual: esto es solo qué
 * se pinta.
 *
 * La lista conserva el ÍNDICE REAL de `eventos`, porque el acordeón y
 * `entryActiva` indexan sobre él: filtrar renumerando abriría la estancia
 * equivocada.
 */
const mostrarCanceladas = ref(false);

const esCancelada = (entry: EventoEntry): boolean => entry.form.estado === PMS_ESTADO.CANCELADA;

const cuantasCanceladas = computed(() => eventos.value.filter(esCancelada).length);

const eventosVisibles = computed<{ entry: EventoEntry; i: number }[]>(() =>
    eventos.value
        .map((entry, i) => ({ entry, i }))
        .filter(({ entry }) => mostrarCanceladas.value || !esCancelada(entry)),
);

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

/**
 * El DÍA se congela; la hora no.
 *
 * Vale tanto para las OTA (el día lo manda el canal) como para las estancias con
 * horario extra. La hora tiene que seguir siendo editable: pactar un late
 * check-out ES poner las 17:00 en el check-out, y con el campo entero
 * deshabilitado no había forma de hacerlo.
 *
 * Espejo de `PmsEventoCalendarioIntegrityListener::assertFechasMoviblesConHorarioExtra()`:
 * mover la estancia obliga a recolocar la noche que bloquea en Beds24, y esa noche
 * puede chocar con otra reserva. Hay que quitar la casilla, GUARDAR, y entonces
 * mover — en dos pasos, no en el mismo guardado. Si se toca este criterio, hay que
 * tocar los dos lados.
 *
 * Se compara con el valor GUARDADO (`entry.horarioExtraGuardado`), no con el de la
 * casilla: si mirase la casilla, desmarcarla desbloquearía los campos en el acto y
 * el backend rechazaría el guardado combinado.
 */
function diaBloqueadoPara(entry: EventoEntry): boolean {
    return fechasUnidadBloqueadasPara(entry) || entry.horarioExtraGuardado;
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

/**
 * Icono del estado, el MISMO que pinta la barra del calendario (`pms_evento_estado.icono`).
 *
 * ⚠️ El cast no es cosmético y tiene fecha de caducidad: el backend sí manda el campo
 * —`PmsEventoEstado::getIcono()` está en el grupo `pms_evento_estado:read`, verificado
 * contra `api:openapi:export`—, pero el tipo generado todavía no lo conoce: `api.d.ts` y
 * `openapi.json` no se regeneran desde que se añadió la columna. Regenerarlos ahora mismo
 * arrastraría cambios de otro trabajo en curso, así que se deja para quien lo cierre.
 *
 * Cuando se regeneren, esto se borra y se lee `estadoObj(id)?.icono` directo.
 */
function iconoEstado(id: string): string {
    const estado = estadoObj(id) as { icono?: string | null } | undefined;

    return estado?.icono || 'fas fa-circle';
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

    // SOLO UN CAMBIO DE DÍA TOCA EL CHECK-OUT.
    //
    // Ajustar la hora de llegada —un huésped que avisa de que entra a las 18:00— no dice
    // nada sobre cuándo se va, y la hora de salida es del establecimiento. Se sale antes de
    // hacer nada, incluida la red de seguridad de abajo: con las fechas ya válidas y sólo la
    // hora movida no hay ningún rango que recomponer, y entrar ahí sólo podía estropearlo.
    const previo = parseLocal(entry.inicioPrevio);
    const desplazamiento = previo ? diasEntre(previo, nuevo) : 0;

    if (previo && desplazamiento === 0) {
        entry.inicioPrevio = entry.form.inicio;
        return;
    }

    const fin = parseLocal(entry.form.fin);
    if (fin && desplazamiento !== 0) {
        const finNuevo = new Date(fin);
        finNuevo.setDate(finNuevo.getDate() + desplazamiento); // conserva hora y minutos
        entry.form.fin = aDatetimeLocal(finNuevo);
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

/**
 * Quitar del formulario una estancia que se añadió por error y AÚN NO EXISTE.
 *
 * No tiene nada que ver con el borrado de la zona roja de abajo: aquí no se llama a la API
 * ni se toca Beds24, sólo se deshace un «Agregar estancia». Por eso la condición es
 * `!eventoId` y no `safeToDelete`: una estancia sin id no llegó nunca al servidor, así que
 * no hay reglas de canal ni de sincronización que consultar.
 */
function descartarEstanciaNueva(i: number): void {
    const entry = eventos.value[i];
    if (!entry || entry.eventoId || readOnly.value) return;

    eventos.value.splice(i, 1);

    // Las refs se indexan por posición y acaban de correrse; Vue las repuebla al repintar.
    estanciaEls.clear();

    // Dejar abierta una estancia que exista: si se cerró la última, la nueva última.
    if (activeIndex.value > i) {
        activeIndex.value -= 1;
    } else if (activeIndex.value === i) {
        activeIndex.value = Math.min(i, eventos.value.length - 1);
    }
}

/** ¿Esta fila se puede descartar sin tocar el servidor? Sólo las que aún no se guardaron. */
function puedeDescartarEstancia(entry: EventoEntry): boolean {
    return !entry.eventoId && !readOnly.value && eventos.value.length > 1;
}

// ============================================================================
// ENFOQUE DE LA ESTANCIA ABIERTA
//
// Al expandir una estancia (o añadir una nueva) el panel crece hacia abajo, y con varias
// apiladas lo normal era quedarse mirando la mitad de un formulario sin ver de cuál era:
// el navegador no mueve el scroll solo, y la cabecera con la casita y las fechas —lo que
// dice DÓNDE estás— quedaba fuera de pantalla.
//
// Se desplaza a la TARJETA entera, que empieza por esa cabecera, no al cuerpo.
// ============================================================================

const scrollerRef = ref<HTMLElement | null>(null);
const estanciaEls = new Map<number, HTMLElement>();

/**
 * `ref` dentro de un `v-for`: Vue entrega el elemento o `null` al desmontarlo. El tipo del
 * callback admite también instancias de componente, de ahí el estrechamiento explícito en
 * vez de un cast: aquí siempre es un `div`.
 */
function setEstanciaRef(i: number, el: Element | ComponentPublicInstance | null): void {
    if (el instanceof HTMLElement) {
        estanciaEls.set(i, el);
    } else {
        estanciaEls.delete(i);
    }
}

/**
 * Lleva la estancia `i` a la parte alta del scroller del drawer.
 *
 * `suave = false` al abrir el drawer: el panel entra deslizándose y dos movimientos a la vez
 * se leen como un fallo.
 */
async function enfocarEstancia(i: number, suave = true): Promise<void> {
    await nextTick();
    enfocarEnScroller(estanciaEls.get(i) ?? null, { scroller: scrollerRef.value, suave });
}

function toggleAcordeon(i: number): void {
    const seAbre = activeIndex.value !== i;
    activeIndex.value = seAbre ? i : -1;
    // Al plegar no se mueve nada: el operador ya está mirando donde quiere estar.
    if (seAbre) void enfocarEstancia(i);
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
        uid: nuevoUid(),
        eventoId: null,
        isOta: false,
        estadoActualId: null,
        channelNombre: 'Directo',
        horarioExtraGuardado: false,
        inicioPrevio: inicioLocal,
        // Una estancia que aún no existe no se borra: se quita del formulario.
        safeToDelete: false,
        motivoNoBorrable: null,
        syncStatus: undefined,
        form: formVacio({
            inicio: inicioLocal,
            fin: aDatetimeLocal(fin),
        }),
    });
    activeIndex.value = eventos.value.length - 1;
    // La nueva nace al final de la lista, casi siempre fuera de pantalla.
    void enfocarEstancia(activeIndex.value);
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
        telefono2EsPrincipal: false,
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
    safeToDelete: boolean;
    motivoNoBorrable: string | null;
    syncStatusAggregate: PmsSyncStatus | undefined;
}>({
    localizador: null,
    referenciaCanalAggregate: null,
    urlCanalExtranet: null,
    channelId: null,
    safeToDelete: false,
    motivoNoBorrable: null,
    syncStatusAggregate: undefined,
});

/** Link público de la guía del huésped (mismo path que PmsMessageDataResolver::getMessageVariables() -> guide_url). */
const guideUrl = computed(() => {
    if (!reservaInfo.value.localizador) return null;
    return `${getUrls().pax}/huesped/reserva/${reservaInfo.value.localizador}`;
});

/**
 * Etiqueta e icono del enlace a la extranet del canal. Espejo de `otaMenuInfo`
 * en ReservasView: las dos pintan el mismo enlace, una desde el menú contextual
 * y otra desde la subbarra del drawer.
 */
const extranetInfo = computed(() => {
    switch (reservaInfo.value.channelId) {
        case PMS_CHANNEL.BOOKING: return { texto: 'Booking', icono: 'fas fa-hotel' };
        case PMS_CHANNEL.AIRBNB:  return { texto: 'Airbnb', icono: 'fab fa-airbnb' };
        default:                  return { texto: 'Canal', icono: 'fas fa-external-link-alt' };
    }
});

/**
 * Texto del chip del canal: el código de reserva de la OTA si lo hay, y si no
 * el nombre del canal. Son la MISMA cosa —ese código ES la reserva en
 * Booking/Airbnb—, así que se pintan juntos en vez de como dos piezas que
 * repiten la misma información y ocupan el doble.
 */
const canalEtiqueta = computed(() =>
    reservaInfo.value.referenciaCanalAggregate || extranetInfo.value.texto,
);

const canalTitulo = computed(() => {
    const ref = reservaInfo.value.referenciaCanalAggregate;
    const donde = extranetInfo.value.texto;
    return ref ? `Abrir la reserva ${ref} en ${donde}` : `Abrir la reserva en ${donde}`;
});

// ============================================================================
// SUBBARRA: colapso a iconos cuando no cabe
//
// No sirve un breakpoint de viewport: el drawer mide `min(viewport, 512px)`, así
// que en escritorio su ancho es constante y lo que desborda es el CONTENIDO —una
// referencia de canal de 12 dígitos ocupa lo que tres etiquetas—. Se mide el
// desbordamiento real y se quitan los textos, dejando solo los iconos (todos
// tienen `title`, así que no se pierde información).
// ============================================================================
const barraRef = ref<HTMLElement | null>(null);
const soloIconos = ref(false);

/**
 * Ancho que ocupa la barra CON etiquetas. Se recuerda al colapsar porque
 * colapsada ya no se puede medir: sin este dato no habría forma de saber cuándo
 * vuelve a caber, y la barra se quedaría en iconos para siempre.
 */
let anchoConEtiquetas = 0;

/** Holgura para volver a expandir; evita que un píxel de más haga oscilar la barra. */
const MARGEN_REEXPANDIR = 12;

function ajustarBarra(): void {
    const el = barraRef.value;
    if (!el) return;

    if (!soloIconos.value) {
        if (el.scrollWidth > el.clientWidth) {
            anchoConEtiquetas = el.scrollWidth;
            soloIconos.value = true;
        }
        return;
    }

    if (anchoConEtiquetas && el.clientWidth >= anchoConEtiquetas + MARGEN_REEXPANDIR) {
        soloIconos.value = false;
    }
}

/**
 * Al cambiar los datos (localizador y referencia llegan por fetch) el ancho
 * recordado ya no vale: se vuelve a expandir para medir de cero.
 */
async function remedirBarra(): Promise<void> {
    soloIconos.value = false;
    anchoConEtiquetas = 0;
    await nextTick();
    ajustarBarra();
}

watch(() => [reservaInfo.value.localizador, reservaInfo.value.referenciaCanalAggregate, reservaInfo.value.urlCanalExtranet], remedirBarra);

// El observer vigila la barra, no la ventana: así también reacciona si el drawer
// cambia de ancho por cualquier otro motivo. Colapsar no altera el tamaño de la
// propia barra (es `w-full`), así que el callback no se auto-dispara en bucle.
let observer: ResizeObserver | null = null;

onMounted(() => {
    observer = new ResizeObserver(() => ajustarBarra());
    if (barraRef.value) observer.observe(barraRef.value);
});

onBeforeUnmount(() => {
    observer?.disconnect();
    observer = null;
});

// La barra se monta con `v-if` (necesita reservaId y datos cargados), así que
// puede aparecer después del onMounted: se observa en cuanto exista.
watch(barraRef, (el) => {
    if (el && observer) {
        observer.observe(el);
        remedirBarra();
    }
});

// ============================================================================
// CABECERA: titular, pax y accesos directos de contacto
// ============================================================================

/** Estancia sobre la que informa la cabecera: la abierta en el acordeón. */
const entryActiva = computed(() => eventos.value[activeIndex.value] ?? eventos.value[0] ?? null);

/**
 * Titular de la reserva. En un bloqueo no hay cliente, así que se cae a la casita:
 * es lo único que identifica ese tramo de calendario.
 */
const tituloCabecera = computed(() => {
    const nombre = [clienteForm.value.nombreCliente, clienteForm.value.apellidoCliente]
        .filter(Boolean).join(' ').trim();
    if (nombre) return nombre;
    return entryActiva.value ? nombreUnidad(entryActiva.value) : '—';
});

/** `x2 +1` = 2 adultos y 1 niño; el `+` solo aparece si hay niños. */
const paxResumen = computed(() => {
    const entry = entryActiva.value;
    if (!entry) return null;
    const adultos = entry.form.cantidadAdultos || 0;
    const ninos = entry.form.cantidadNinos || 0;
    return `x${adultos}${ninos ? ` +${ninos}` : ''}`;
});

/**
 * Conversación directa de WhatsApp con el huésped.
 *
 * Resuelve sobre el FORMULARIO, no sobre lo guardado: el operador puede marcar
 * la casilla de número principal y el botón tiene que apuntar al nuevo número
 * antes de pulsar «Guardar». De ahí el espejo TS de la regla; el backend expone
 * el mismo dato ya resuelto en `telefonoContacto` para quien no edita.
 */
const whatsappUrl = computed(() => {
    const numero = telefonoParaWhatsapp(telefonoContactoDe(
        clienteForm.value.telefono,
        clienteForm.value.telefono2,
        clienteForm.value.telefono2EsPrincipal,
    ));
    return numero ? `https://wa.me/${numero}` : null;
});

const abriendoChat = ref(false);

async function abrirChatInterno(): Promise<void> {
    if (!props.reservaId) return;

    abriendoChat.value = true;
    try {
        const convId = await reservasStore.fetchConversacionId(props.reservaId);
        if (!convId) {
            localError.value = 'Esta reserva todavía no tiene una conversación de chat.';
            return;
        }

        // El drawer se cierra ANTES de navegar a propósito: ReservasView cancela la
        // salida de ruta mientras haya un drawer abierto (onBeforeRouteLeave, para
        // que el «atrás» del móvil cierre el formulario en vez de salir), así que un
        // push con el drawer todavía abierto se lo tragaría el guard.
        emit('close');
        // ChatView solo reacciona a `route.query.id`, no a un param de ruta.
        await router.push({ path: '/chat', query: { id: convId } });
    } catch {
        localError.value = 'No se pudo abrir el chat interno.';
    } finally {
        abriendoChat.value = false;
    }
}

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
                uid: nuevoUid(),
                eventoId: null,
                isOta: false,
                estadoActualId: null,
                channelNombre: 'Directo',
                horarioExtraGuardado: false,
                inicioPrevio: toDatetimeLocal(props.createDefaults.inicio),
                safeToDelete: false,
                motivoNoBorrable: null,
                syncStatus: undefined,
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
                telefono2EsPrincipal: reserva.telefono2EsPrincipal ?? false,
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
                safeToDelete: reserva.safeToDelete ?? false,
                motivoNoBorrable: reserva.motivoNoBorrable ?? null,
                syncStatusAggregate: reserva.syncStatusAggregate,
            };

            const ids = idsDeEventos(reserva);
            if (ids.length) {
                const detalles = await Promise.all(ids.map((id) => reservasStore.fetchEvento(id)));
                // Fuera las EXTENSIONES: la noche que bloquea una entrada temprana o
                // una salida tardía cuelga de la reserva —para que el borrado y las
                // finanzas la sigan— pero no es una estancia y aquí saldría como una
                // casita fantasma que además se podría editar.
                //
                // Se filtra por `eventoOrigen` y NO por el estado: al desmarcar la
                // casilla la extensión pasa a `cancelada`, y con el filtro de estado
                // reaparecían todas — una por cada vez que se marcó y se desmarcó.
                // Ver §7.1.b del doc de sincronización.
                eventos.value = detalles
                    .filter((e) => !e.eventoOrigen)
                    .map(entryDesdeEvento);
            } else if (props.eventoId) {
                // Fallback defensivo: la reserva no trajo la lista pero sabemos qué evento abrir.
                const evento = await reservasStore.fetchEvento(props.eventoId);
                eventos.value = [entryDesdeEvento(evento)];
            }

            const idx = eventos.value.findIndex(e => e.eventoId === props.eventoId);
            activeIndex.value = idx >= 0 ? idx : 0;

            // Si se viene a ver JUSTO una cancelada (desde el calendario «Todas», por
            // ejemplo), se destapan: si no, el drawer abriría sin nada a la vista.
            const abierta = eventos.value[activeIndex.value];
            if (abierta && esCancelada(abierta)) mostrarCanceladas.value = true;

            // Se abrió sobre una estancia que no es la primera (una reserva de varias
            // casitas, clicando la segunda en el calendario): sin esto el drawer arranca
            // arriba del todo y la estancia que se vino a ver queda más abajo, desplegada
            // pero fuera de pantalla.
            if (activeIndex.value > 0) void enfocarEstancia(activeIndex.value, false);
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
        entradaTemprana: entry.form.entradaTemprana,
        salidaTardia: entry.form.salidaTardia,
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

/** Aviso tras guardar un horario extra: el cargo ya está abajo, esperando importe. */
const avisoHorarioExtra = ref(false);

/** Confirmación breve tras guardar sin cerrar, para que el botón no parezca inerte. */
const avisoGuardado = ref(false);

async function guardar(cerrarAlTerminar = false): Promise<void> {
    let huboHorarioExtra = false;
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
                telefono2EsPrincipal: clienteForm.value.telefono2EsPrincipal,
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
                // ¿Se acaba de tocar el horario extra? Entonces el guardado deja
                // cargos nuevos que hay que valorar, y cerrar el drawer obligaría a
                // buscar la reserva otra vez (ver el `if` del final).
                const horarioExtraTocado = entry.form.entradaTemprana || entry.form.salidaTardia
                    ? !entry.horarioExtraGuardado
                    : entry.horarioExtraGuardado;
                if (horarioExtraTocado) huboHorarioExtra = true;

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
                        entradaTemprana: entry.form.entradaTemprana,
                        salidaTardia: entry.form.salidaTardia,
                    };

                    // La HORA de check-in/out se manda SIEMPRE, también en las OTA:
                    // el canal vende noches y a Beds24 sólo le viajan los días, así
                    // que un late check-out pactado aquí no contradice nada suyo. El
                    // backend rechaza el cambio de DÍA, no el de hora.
                    payload.inicio = fromDatetimeLocal(entry.form.inicio);
                    payload.fin = fromDatetimeLocal(entry.form.fin);

                    // La unidad sí es inmutable en OTA (la manda el canal).
                    if (!fechasUnidadBloqueadasPara(entry)) {
                        payload.pmsUnidad = pmsUnidadIri(entry.form.pmsUnidad);
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
                telefono2EsPrincipal: clienteForm.value.telefono2EsPrincipal,
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

        // Marcar «entrada temprana» o «salida tardía» deja un cargo NUEVO en 0.00 que
        // el operador tiene que valorar, y ese cargo no existe hasta que el backend
        // procesa el guardado. Cerrando el drawer habría que buscar la reserva otra
        // vez para ponerle el importe, así que aquí se recarga y se queda abierto.
        if (huboHorarioExtra) {
            await cargarDatos();
            emit('saved', { cerrar: false });
            avisoHorarioExtra.value = true;
            setTimeout(() => (avisoHorarioExtra.value = false), 8000);
            return;
        }

        if (!cerrarAlTerminar) {
            // El drawer se queda abierto a propósito. Guardar suele ser el paso PREVIO a otra
            // cosa —cancelar una estancia y esperar a que Beds24 confirme para poder borrarla,
            // ajustar un cargo, revisar el resultado— y cerrar obligaba a volver a buscar la
            // reserva en el calendario para cada uno de esos pasos.
            //
            // Se recarga del servidor en vez de fiarse del formulario: `safeToDelete`,
            // `motivoNoBorrable` y `syncStatus` los calcula el backend, y son justo los que
            // deciden si la zona de borrado de abajo se abre.
            await cargarDatos();
            emit('saved', { cerrar: false });
            avisoGuardado.value = true;
            setTimeout(() => (avisoGuardado.value = false), 3000);
            return;
        }

        emit('saved', { cerrar: true });
    } catch (err) {
        localError.value = extractApiErrorMessage(err, 'No se pudo guardar. Revisa los datos e intenta de nuevo.');
    }
}

// ============================================================================
// BORRADO
//
// Dos condiciones, y la segunda es la que no se ve venir:
//
//  1. `safeToDelete` — el veredicto del backend (OTA, existe en Beds24 sin cancelar,
//     cola tomada por el worker). Si es false se muestra `motivoNoBorrable` tal cual.
//  2. `syncStatus === 'synced' | 'local'` — que NO quede push por delante. En cuanto la
//     estancia pasa a «cancelada» el backend ya la da por borrable, aunque el push de esa
//     cancelación siga encolado. Borrar en esa ventana cancela el POST pendiente y manda a
//     Beds24 un DELETE sobre una reserva todavía confirmada: Beds24 lo rechaza y la reserva
//     se queda viva allí bloqueando las noches. Por eso aquí se espera a `synced`.
//
// La espera es un sondeo: el push lo procesa un worker asíncrono, así que no hay evento
// que escuchar desde el navegador.
// ============================================================================
const POLL_SYNC_MS = 4000;
const POLL_SYNC_INTENTOS_MAX = 45; // ~3 minutos

const borrando = ref(false);
const esperandoSync = ref(false);
const pollIntentos = ref(0);
const confirmarBorrado = ref<'evento' | 'reserva' | null>(null);
let pollTimer: ReturnType<typeof setTimeout> | null = null;

function pararEspera(): void {
    if (pollTimer) {
        clearTimeout(pollTimer);
        pollTimer = null;
    }
    esperandoSync.value = false;
    pollIntentos.value = 0;
}

onBeforeUnmount(pararEspera);

// `entryActiva` (la estancia abierta en el acordeón) se define arriba, en la sección de
// cabecera: es la misma sobre la que se informa y sobre la que se borra.

const eventoBorrable = computed(() =>
    puedeBorrarseYa(
        { safeToDelete: entryActiva.value?.safeToDelete, motivoNoBorrable: entryActiva.value?.motivoNoBorrable },
        entryActiva.value?.syncStatus,
    ),
);

const reservaBorrable = computed(() =>
    puedeBorrarseYa(
        { safeToDelete: reservaInfo.value.safeToDelete, motivoNoBorrable: reservaInfo.value.motivoNoBorrable },
        reservaInfo.value.syncStatusAggregate,
    ),
);

/**
 * ¿Está sólo pendiente del push? Es el caso que merece esperar: el backend ya la considera
 * borrable y lo único que falta es que la cola termine. Si además falta `safeToDelete`, no
 * hay nada que esperar — hace falta una acción del operador (cancelarla, por ejemplo).
 */
const soloFaltaSync = computed(() => {
    const eventoEsperando = !!entryActiva.value?.safeToDelete && entryActiva.value.syncStatus === 'pending';
    const reservaEsperando = reservaInfo.value.safeToDelete && reservaInfo.value.syncStatusAggregate === 'pending';
    return eventoEsperando || reservaEsperando;
});

/** Hay más de una estancia viva: entonces borrar la reserva entera no es lo mismo que borrar ésta. */
const tieneVariasEstancias = computed(() => eventos.value.filter((e) => e.eventoId).length > 1);

const syncEnError = computed(() =>
    entryActiva.value?.syncStatus === 'error' || reservaInfo.value.syncStatusAggregate === 'error',
);

/** Motivo a mostrar cuando el borrado no está disponible. */
const motivoBorradoBloqueado = computed<string | null>(() => {
    const objetivo = confirmarBorrado.value === 'reserva' ? 'reserva' : 'evento';
    if (objetivo === 'reserva' && !reservaInfo.value.safeToDelete) return reservaInfo.value.motivoNoBorrable;
    if (objetivo === 'evento' && !entryActiva.value?.safeToDelete) return entryActiva.value?.motivoNoBorrable ?? null;
    return null;
});

/**
 * Sondea el estado hasta que la sincronización termine. Se apoya en `cargarDatos()` para no
 * duplicar la lectura: así `safeToDelete`, `motivoNoBorrable` y `syncStatus` se refrescan a la
 * vez y no pueden quedar desparejados entre sí.
 */
async function esperarSincronizacion(): Promise<void> {
    if (esperandoSync.value) return;
    esperandoSync.value = true;
    pollIntentos.value = 0;
    localError.value = null;

    const tick = async (): Promise<void> => {
        pollIntentos.value += 1;
        await cargarDatos();

        if (eventoBorrable.value || reservaBorrable.value) {
            pararEspera();
            return;
        }
        if (syncEnError.value) {
            pararEspera();
            localError.value = 'La sincronización con Beds24 falló. Revisa la cola de push antes de borrar.';
            return;
        }
        if (pollIntentos.value >= POLL_SYNC_INTENTOS_MAX) {
            pararEspera();
            localError.value = 'La sincronización sigue pendiente. Puedes cerrar y volver más tarde.';
            return;
        }
        pollTimer = setTimeout(() => { void tick(); }, POLL_SYNC_MS);
    };

    pollTimer = setTimeout(() => { void tick(); }, POLL_SYNC_MS);
}

async function ejecutarBorrado(): Promise<void> {
    const objetivo = confirmarBorrado.value;
    if (!objetivo) return;

    borrando.value = true;
    localError.value = null;
    try {
        if (objetivo === 'reserva') {
            if (!props.reservaId) return;
            await reservasStore.deleteReserva(props.reservaId);
        } else {
            const id = entryActiva.value?.eventoId;
            if (!id) return;
            await reservasStore.deleteEvento(id);
        }
        confirmarBorrado.value = null;
        pararEspera();
        emit('deleted');
    } catch (err) {
        // El 403 del listener trae el motivo real; es la última red por si el estado
        // cambió entre que se pintó el botón y se pulsó.
        localError.value = extractApiErrorMessage(err, 'No se pudo eliminar.');
        confirmarBorrado.value = null;
        await cargarDatos();
    } finally {
        borrando.value = false;
    }
}
</script>

<template>
    <div class="fixed inset-0 z-40 flex justify-end" role="dialog" aria-modal="true">
        <!-- Backdrop -->
        <div class="absolute inset-0 bg-slate-900/40" @click="emit('close')"></div>

        <!-- Panel -->
        <div class="relative w-full max-w-lg h-full bg-white shadow-2xl flex flex-col animate-slide-in">
            <!-- El modo (Ver/Editar) pasa a línea secundaria: quien abre el drawer ya
                 sabe qué pulsó, y el dato que de verdad ubica la ficha es el titular. -->
            <header class="bg-slate-900 text-white px-5 py-3 flex items-center justify-between gap-3 shrink-0">
                <div class="min-w-0">
                    <p class="text-[10px] font-bold text-slate-400 uppercase tracking-widest flex items-center gap-2">
                        <span>
                            <template v-if="isCreateReserva">Nueva Reserva</template>
                            <template v-else-if="isCreate">Nuevo Bloqueo</template>
                            <template v-else-if="readOnly">Ver Estancia</template>
                            <template v-else>Editar Estancia</template>
                        </span>
                        <span v-if="hayOta" class="text-amber-400"><i class="fas fa-lock mr-1"></i>OTA</span>
                    </p>
                    <h2 class="font-black text-base tracking-tight truncate mt-0.5">
                        {{ tituloCabecera }}
                        <span v-if="paxResumen" class="text-slate-400 font-bold text-xs ml-1">{{ paxResumen }}</span>
                    </h2>
                </div>
                <!-- Contacto e identificadores viven en la subbarra de abajo: aquí
                     solo queda el control de modo, que es lo único que cambia el
                     estado del propio drawer. -->
                <div class="flex items-center gap-1.5 shrink-0">
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

            <!-- ═══ SUBBARRA: identificadores y accesos directos ═══
                 Todo lo que es "sobre esta reserva" y no "sobre este formulario":
                 localizador, referencia del canal y los enlaces de contacto.
                 Está FUERA del cuerpo desplazable a propósito — antes el
                 localizador vivía enterrado en la sección del titular y había que
                 bajar hasta él, que es justo lo contrario de lo que se necesita
                 cuando alguien llama preguntando por su reserva.

                 Se pinta igual en Ver y en Editar: son datos de solo lectura y
                 accesos, nada que dependa del modo. -->
            <div v-if="reservaId && !isLoadingDrawer" ref="barraRef"
                class="shrink-0 bg-slate-50 border-b border-slate-200 px-4 py-2 flex items-center gap-1.5 overflow-x-auto scrollbar-none">

                <span v-if="reservaInfo.localizador"
                    class="shrink-0 px-2.5 py-1 bg-white border border-slate-200 text-slate-700 rounded-lg text-xs font-mono font-black tracking-wide"
                    title="Localizador de la reserva">
                    {{ reservaInfo.localizador }}
                </span>

                <!-- Reserva en el canal: el código de la OTA es el propio enlace a su
                     extranet. Si no hay URL sigue mostrándose como dato, que es lo que
                     el operador dicta por teléfono.
                     NO colapsa a icono: el código es un DATO, y perderlo dejaría la
                     barra sin el número que identifica la reserva en el canal. Lo que
                     se colapsa son las etiquetas de las ACCIONES, que el icono ya
                     explica por sí solo. -->
                <a v-if="reservaInfo.urlCanalExtranet" :href="reservaInfo.urlCanalExtranet"
                    target="_blank" rel="noopener" :title="canalTitulo"
                    class="shrink-0 flex items-center justify-center gap-1.5 h-8 px-2.5 bg-white border border-slate-200 hover:border-[#376875] hover:text-[#376875] text-slate-500 rounded-lg text-[11px] font-mono font-bold transition-colors">
                    <i :class="extranetInfo.icono" class="text-[11px]"></i>
                    <span>{{ canalEtiqueta }}</span>
                </a>

                <span v-else-if="reservaInfo.referenciaCanalAggregate"
                    :title="`Referencia de ${extranetInfo.texto}: ${reservaInfo.referenciaCanalAggregate}`"
                    class="shrink-0 flex items-center justify-center gap-1.5 h-8 px-2.5 bg-white border border-slate-200 text-slate-500 rounded-lg text-[11px] font-mono font-bold">
                    <i :class="extranetInfo.icono" class="text-[11px]"></i>
                    <span>{{ reservaInfo.referenciaCanalAggregate }}</span>
                </span>

                <span class="shrink-0 w-px h-5 bg-slate-200 mx-0.5"></span>

                <!-- Abrir la guía tal como la ve el huésped. Estaba solo en el menú
                     contextual del calendario; aquí evita tener que cerrar el drawer
                     para comprobar qué está viendo quien llama. -->
                <a v-if="guideUrl" :href="guideUrl" target="_blank" rel="noopener"
                    title="Abrir la guía del huésped"
                    class="shrink-0 flex items-center justify-center gap-1.5 h-8 bg-white border border-slate-200 hover:border-[#376875] hover:text-[#376875] text-slate-600 rounded-lg text-xs font-bold transition-colors"
                    :class="soloIconos ? 'w-8' : 'px-2.5'">
                    <i class="fas fa-book-open text-[11px]"></i>
                    <span v-if="!soloIconos">Guía</span>
                </a>

                <button v-if="guideUrl" type="button" @click="copiar(guideUrl, 'guide')"
                    title="Copiar el enlace de la guía"
                    class="shrink-0 flex items-center justify-center gap-1.5 h-8 border rounded-lg text-xs font-bold transition-colors"
                    :class="[
                        soloIconos ? 'w-8' : 'px-2.5',
                        copiadoKey === 'guide'
                            ? 'bg-emerald-50 border-emerald-200 text-emerald-700'
                            : 'bg-white border-slate-200 hover:border-slate-300 text-slate-600',
                    ]">
                    <i class="fas text-[11px]" :class="copiadoKey === 'guide' ? 'fa-check' : 'fa-link'"></i>
                    <span v-if="!soloIconos">{{ copiadoKey === 'guide' ? 'Copiado' : 'Copiar' }}</span>
                </button>

                <a v-if="whatsappUrl" :href="whatsappUrl" target="_blank" rel="noopener"
                    title="Abrir WhatsApp con el huésped"
                    class="shrink-0 w-8 h-8 flex items-center justify-center bg-white border border-slate-200 hover:border-emerald-400 hover:bg-emerald-50 rounded-lg transition-colors">
                    <i class="fab fa-whatsapp text-emerald-500 text-sm"></i>
                </a>

                <button type="button" @click="abrirChatInterno" :disabled="abriendoChat"
                    title="Abrir chat interno"
                    class="shrink-0 w-8 h-8 flex items-center justify-center bg-white border border-slate-200 hover:border-[#376875] hover:bg-[#376875]/5 rounded-lg transition-colors disabled:opacity-50">
                    <i class="fas text-sm text-slate-500"
                        :class="abriendoChat ? 'fa-spinner fa-spin' : 'fa-comment-dots'"></i>
                </button>

            </div>

            <div v-if="isLoadingDrawer" class="flex-1 flex items-center justify-center">
                <i class="fas fa-spinner fa-spin text-3xl text-[#376875]"></i>
            </div>

            <div v-else ref="scrollerRef" class="flex-1 overflow-y-auto px-5 py-4 space-y-6">

                <div v-if="localError" class="bg-rose-50 border border-rose-200 text-rose-700 text-sm font-bold rounded-xl px-4 py-3">
                    <i class="fas fa-exclamation-triangle mr-2"></i>{{ localError }}
                </div>

                <!-- El drawer NO se cierra al guardar un horario extra: el cargo nace
                     ahora, en 0.00, y hay que poder valorarlo sin volver a buscar la
                     reserva (ver el final de `guardar()`). -->
                <div v-if="avisoHorarioExtra" class="bg-amber-50 border border-amber-200 text-amber-800 text-sm font-bold rounded-xl px-4 py-3 flex items-start gap-2.5">
                    <i class="fas fa-circle-check mt-0.5"></i>
                    <span>
                        Guardado. La noche queda bloqueada en Beds24 y abajo, en Finanzas, tienes el
                        cargo en <b>0.00</b>: ponle el importe que se cobra (o déjalo en 0 si se regala).
                    </span>
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
                    <div class="flex items-center justify-between gap-3 mb-3">
                        <h3 class="text-[10px] font-black text-slate-400 uppercase tracking-widest">
                            <i class="fas fa-bed mr-1"></i> {{ esMultiEvento ? 'Estancias' : 'Estancia' }}
                        </h3>

                        <!-- Solo aparece si hay algo que mostrar: en la mayoría de reservas
                             no hay canceladas y el interruptor sería ruido. -->
                        <label v-if="cuantasCanceladas" class="flex items-center gap-2 cursor-pointer shrink-0">
                            <span class="text-[10px] font-bold text-slate-400 uppercase tracking-wide">
                                {{ cuantasCanceladas }} cancelada{{ cuantasCanceladas > 1 ? 's' : '' }}
                            </span>
                            <input type="checkbox" v-model="mostrarCanceladas" class="sr-only peer" />
                            <span class="relative inline-flex h-5 w-9 items-center rounded-full bg-slate-300 transition-colors peer-checked:bg-slate-500">
                                <span class="inline-block h-3.5 w-3.5 translate-x-1 rounded-full bg-white transition-transform"
                                    :class="mostrarCanceladas ? 'translate-x-[1.125rem]' : ''"></span>
                            </span>
                        </label>
                    </div>

                    <div class="space-y-3">
                        <!-- Cada estancia se tiñe con el color de SU estado: la cabecera más
                             saturada y el cuerpo apenas insinuado, para que se vea de un vistazo
                             dónde empieza y acaba cada una sin leer las fechas. -->
                        <!-- El `ref` es la tarjeta ENTERA (cabecera incluida): es lo que se
                             lleva a la vista al expandir, para que el operador vea de qué
                             estancia es el formulario que se acaba de abrir. -->
                        <div v-for="{ entry, i } in eventosVisibles" :key="entry.uid"
                            :ref="(el) => setEstanciaRef(i, el)"
                            class="rounded-xl overflow-hidden border scroll-mt-3"
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
                                        <!-- El icono del ESTADO, no un punto anónimo: el punto repetía
                                             el color que ya llevan el borde y el fondo de la cabecera,
                                             así que no añadía nada. Y va con el color del estado, que
                                             puede NO ser el de la cabecera —ésa la tiñe `colorEntry()`,
                                             donde el estado de pago manda—: ahí está lo que aporta. -->
                                        <i class="shrink-0 text-xs w-4 text-center" :class="iconoEstado(entry.form.estado)"
                                            :style="{ color: estadoObj(entry.form.estado)?.color || COLOR_ESTADO_FALLBACK }"
                                            :title="nombreEstado(entry)"></i>
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
                                <!-- Con icono solo nadie adivinaba qué hacía: lleva etiqueta.
                                     En pantallas estrechas se queda el icono, con el `title`. -->
                                <button v-if="puedeClonarFechas(entry, i)" type="button"
                                    @click="clonarFechasDeAnterior(i)"
                                    title="Usar las mismas fechas y horas que la estancia anterior"
                                    class="px-2.5 flex items-center gap-1.5 text-slate-500 hover:text-[#376875] hover:bg-white/50 transition-colors shrink-0">
                                    <!-- Doble calendario: dos iconos superpuestos. FontAwesome no trae
                                         uno "calendario duplicado", así que se compone; leerlo como
                                         "copiar fechas" es más directo que un icono de copiar genérico. -->
                                    <span class="relative inline-block w-[15px] h-[13px] shrink-0">
                                        <i class="far fa-calendar text-[11px] absolute left-0 top-0 opacity-45"></i>
                                        <i class="fas fa-calendar text-[11px] absolute left-[4px] top-[2px]"></i>
                                    </span>
                                    <span class="hidden sm:inline text-[10px] font-black uppercase tracking-wide whitespace-nowrap">
                                        Mismas fechas
                                    </span>
                                </button>

                                <!-- Descartar una estancia añadida por error. Sólo en las que
                                     todavía no existen en el servidor: no llama a la API. El
                                     borrado de verdad vive en la zona roja del final. -->
                                <button v-if="puedeDescartarEstancia(entry)" type="button"
                                    @click="descartarEstanciaNueva(i)"
                                    title="Descartar esta estancia nueva (aún no se ha guardado)"
                                    class="px-2.5 flex items-center gap-1.5 text-slate-400 hover:text-rose-600 hover:bg-white/50 transition-colors shrink-0">
                                    <i class="fas fa-trash-can text-[11px]"></i>
                                    <span class="hidden sm:inline text-[10px] font-black uppercase tracking-wide">Quitar</span>
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
                                            <span class="inline-flex items-center gap-1.5 mt-1 px-2.5 py-1 rounded-full text-[11px] font-black"
                                                :style="{ backgroundColor: estadoObj(entry.form.estado)?.color || COLOR_ESTADO_FALLBACK, color: contrastText(estadoObj(entry.form.estado)?.color || COLOR_ESTADO_FALLBACK) }">
                                                <i :class="iconoEstado(entry.form.estado)"></i>
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

                                    <!-- Cada fecha con SU casilla de horario extra al lado, en una
                                         fila propia. Antes las dos fechas iban una junto a otra y el
                                         bloque de casillas debajo: en móvil no entraban los dos
                                         selectores y el bloque de texto ocupaba media pantalla.
                                         La explicación pasa al `title` y al aviso que aparece SOLO al
                                         marcar, que es cuando importa. -->
                                    <div class="col-span-2 space-y-3">
                                        <div>
                                            <div class="flex items-end gap-2">
                                                <label class="flex-1 min-w-0">
                                                    <span class="text-xs font-bold text-slate-500">Check-in</span>
                                                    <!-- Al mover la entrada, la salida se arrastra los mismos días
                                                         (onCambiarInicio). Handler explícito en vez de `v-model` +
                                                         `@update`: con los dos, el orden no está garantizado y
                                                         onCambiarInicio leía el valor anterior. -->
                                                    <div class="mt-1">
                                                        <FechaHoraPicker :model-value="entry.form.inicio"
                                                            :dia-bloqueado="diaBloqueadoPara(entry)"
                                                            :borrable="false"
                                                            @update:model-value="(v: string) => { entry.form.inicio = v; onCambiarInicio(entry); }" />
                                                    </div>
                                                </label>

                                                <label class="shrink-0 h-[38px] px-2.5 flex items-center gap-2 rounded-lg border cursor-pointer transition-colors"
                                                    :class="entry.form.entradaTemprana ? 'border-amber-300 bg-amber-50' : 'border-slate-200 bg-white hover:bg-slate-50'"
                                                    title="Entrada temprana: llega por la mañana. Bloquea la NOCHE ANTERIOR en Beds24 y añade el cargo «Entrada temprana» en 0.00 para que pongas lo que se cobre.">
                                                    <input type="checkbox" v-model="entry.form.entradaTemprana"
                                                        class="w-4 h-4 accent-[#E07845]" />
                                                    <i class="fas fa-right-to-bracket text-sm" :class="entry.form.entradaTemprana ? 'text-amber-600' : 'text-slate-400'"></i>
                                                </label>
                                            </div>

                                            <p v-if="entry.form.entradaTemprana" class="mt-1.5 text-[11px] font-bold text-amber-700 leading-snug">
                                                <i class="fas fa-right-to-bracket mr-1"></i>
                                                Entrada temprana: bloquea la noche ANTERIOR en Beds24 y abre su cargo en 0.00.
                                            </p>
                                        </div>

                                        <div>
                                            <div class="flex items-end gap-2">
                                                <label class="flex-1 min-w-0">
                                                    <span class="text-xs font-bold text-slate-500">Check-out</span>
                                                    <div class="mt-1">
                                                        <!-- Suelo = el DÍA de entrada, sin su hora (ver `soloDia`):
                                                             con la hora dentro, cambiar el check-in movía el check-out. -->
                                                        <FechaHoraPicker v-model="entry.form.fin"
                                                            :min-date="soloDia(entry.form.inicio)"
                                                            :dia-bloqueado="diaBloqueadoPara(entry)"
                                                            :borrable="false"
                                                            :invalido="!!errorFechas(entry)" />
                                                    </div>
                                                </label>

                                                <label class="shrink-0 h-[38px] px-2.5 flex items-center gap-2 rounded-lg border cursor-pointer transition-colors"
                                                    :class="entry.form.salidaTardia ? 'border-amber-300 bg-amber-50' : 'border-slate-200 bg-white hover:bg-slate-50'"
                                                    title="Salida tardía: se va por la tarde. Bloquea ESA NOCHE en Beds24 y añade el cargo «Salida tardía» en 0.00. La estancia sigue contando las noches reales.">
                                                    <input type="checkbox" v-model="entry.form.salidaTardia"
                                                        class="w-4 h-4 accent-[#E07845]" />
                                                    <i class="fas fa-right-from-bracket text-sm" :class="entry.form.salidaTardia ? 'text-amber-600' : 'text-slate-400'"></i>
                                                </label>
                                            </div>

                                            <p v-if="entry.form.salidaTardia" class="mt-1.5 text-[11px] font-bold text-amber-700 leading-snug">
                                                <i class="fas fa-right-from-bracket mr-1"></i>
                                                Salida tardía: bloquea ESA noche en Beds24 y abre su cargo en 0.00.
                                                Las noches de la estancia no cambian.
                                            </p>
                                        </div>

                                        <p v-if="entry.horarioExtraGuardado && !entry.isOta"
                                            class="text-[11px] font-bold text-slate-500 flex items-start gap-2">
                                            <i class="fas fa-lock mt-0.5 text-slate-400"></i>
                                            <span>Con horario extra el DÍA queda fijo (la hora no): para moverlo, quita la casilla, guarda, y entonces cámbialo.</span>
                                        </p>

                                        <p v-if="errorFechas(entry)" class="text-[11px] font-bold text-rose-600">
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
                                            class="mt-1 w-full border border-slate-200 rounded-lg px-3 py-2 text-sm bg-white">
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
                                            class="mt-1 w-full border border-slate-200 rounded-lg px-3 py-2 text-sm bg-white">
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
                                                class="mt-1 w-full border border-slate-200 rounded-lg px-3 py-2 text-sm bg-white" />
                                        </label>

                                        <label>
                                            <span class="text-xs font-bold text-slate-500">Niños</span>
                                            <input type="number" min="0" v-model.number="entry.form.cantidadNinos"
                                                class="mt-1 w-full border border-slate-200 rounded-lg px-3 py-2 text-sm bg-white" />
                                        </label>
                                    </div>

                                    <!-- Monto y comisión ya NO se editan aquí: el dinero de la reserva vive
                                         en el panel de Finanzas (cargos + pagos). Duplicarlo daba dos cifras
                                         que podían contradecirse. Los campos siguen en el modelo y se envían
                                         sin tocar, para no pisar lo que sincroniza el canal. -->

                                    <label class="col-span-2">
                                        <span class="text-xs font-bold text-slate-500">{{ isCreate && !isCreateReserva ? 'Motivo del bloqueo' : 'Descripción' }}</span>
                                        <input type="text" v-model="entry.form.descripcion"
                                            class="mt-1 w-full border border-slate-200 rounded-lg px-3 py-2 text-sm bg-white" />
                                    </label>

                                    <!-- Comentarios del huésped: ocultos al editar una estancia OTA. -->
                                    <label class="col-span-2" v-if="!isCreate && !entry.isOta">
                                        <span class="text-xs font-bold text-slate-500">Comentarios del huésped</span>
                                        <textarea v-model="entry.form.comentariosHuesped" rows="2"
                                            class="mt-1 w-full border border-slate-200 rounded-lg px-3 py-2 text-sm bg-white"></textarea>
                                    </label>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Iba en gris sobre borde punteado y se leía como deshabilitado: nadie lo
                         encontraba. Ahora es un botón sólido con el color de acción del drawer,
                         y el «+» va en su propia pastilla para que se lea como «añadir». -->
                    <button v-if="permiteVariasEstancias && !readOnly" type="button" @click="agregarEvento"
                        title="Añade otra casita a esta misma reserva"
                        class="mt-3 w-full flex items-center justify-center gap-2.5 px-4 py-3 rounded-xl bg-[#376875] hover:bg-[#2d5660] text-white text-xs font-black shadow-sm transition-colors">
                        <span class="w-5 h-5 rounded-full bg-white/20 flex items-center justify-center shrink-0">
                            <i class="fas fa-plus text-[10px]"></i>
                        </span>
                        Agregar estancia en otra casita
                    </button>
                </section>

                <!-- ================= CLIENTE ================= -->
                <section v-if="muestraCliente">
                    <h3 class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-3">
                        <i class="fas fa-user mr-1"></i> Datos del Titular
                    </h3>

                    <!-- Localizador, referencia del canal y accesos directos ya no viven
                         aquí: subieron a la subbarra fija bajo la cabecera, para no tener
                         que desplazarse hasta ellos. -->

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
                            <!-- La insignia marca a cuál llaman WhatsApp, plantillas y vCard.
                                 Solo se pinta con los dos rellenos: con uno, no hay elección. -->
                            <div>
                                <p class="text-[10px] font-black text-slate-400 uppercase tracking-wide flex items-center gap-1.5">
                                    Teléfono
                                    <span v-if="clienteForm.telefono && clienteForm.telefono2 && !clienteForm.telefono2EsPrincipal"
                                        class="px-1.5 py-0.5 bg-[#376875]/10 text-[#376875] rounded normal-case tracking-normal">principal</span>
                                </p>
                                <div class="flex items-center gap-2 mt-0.5">
                                    <p class="text-sm font-bold text-slate-800">{{ formatearTelefono(clienteForm.telefono) || '—' }}</p>
                                    <a v-if="vcardUrl && clienteForm.telefono" :href="vcardUrl" target="_blank" title="Descargar contacto (vCard)"
                                        class="inline-flex items-center gap-1 px-2 py-1 bg-indigo-100 hover:bg-indigo-200 text-indigo-700 border border-indigo-200 rounded-lg text-[10px] font-black uppercase tracking-wide transition-colors shrink-0">
                                        <i class="fas fa-address-card"></i> vCard
                                    </a>
                                </div>
                            </div>
                            <div>
                                <p class="text-[10px] font-black text-slate-400 uppercase tracking-wide flex items-center gap-1.5">
                                    Teléfono 2
                                    <span v-if="clienteForm.telefono && clienteForm.telefono2 && clienteForm.telefono2EsPrincipal"
                                        class="px-1.5 py-0.5 bg-[#376875]/10 text-[#376875] rounded normal-case tracking-normal">principal</span>
                                </p>
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
                                class="mt-1 w-full border border-slate-200 rounded-lg px-3 py-2 text-sm bg-white" />
                        </label>
                        <label>
                            <span class="text-xs font-bold text-slate-500">Apellido</span>
                            <input type="text" v-model="clienteForm.apellidoCliente"
                                class="mt-1 w-full border border-slate-200 rounded-lg px-3 py-2 text-sm bg-white" />
                        </label>
                        <label>
                            <span class="text-xs font-bold text-slate-500">Teléfono</span>
                            <span v-if="formatearTelefono(clienteForm.telefono) && formatearTelefono(clienteForm.telefono) !== clienteForm.telefono"
                                class="ml-1.5 text-[10px] font-bold text-slate-400">{{ formatearTelefono(clienteForm.telefono) }}</span>
                            <input type="text" v-model="clienteForm.telefono"
                                class="mt-1 w-full border border-slate-200 rounded-lg px-3 py-2 text-sm bg-white" />
                        </label>
                        <label>
                            <span class="text-xs font-bold text-slate-500">Teléfono 2</span>
                            <span v-if="formatearTelefono(clienteForm.telefono2) && formatearTelefono(clienteForm.telefono2) !== clienteForm.telefono2"
                                class="ml-1.5 text-[10px] font-bold text-slate-400">{{ formatearTelefono(clienteForm.telefono2) }}</span>
                            <input type="text" v-model="clienteForm.telefono2"
                                class="mt-1 w-full border border-slate-200 rounded-lg px-3 py-2 text-sm bg-white" />
                        </label>

                        <!-- Elección del número de contacto. Solo tiene sentido con los dos
                             rellenos: con uno solo, `getTelefonoContacto()` lo usa igual. -->
                        <label v-if="clienteForm.telefono && clienteForm.telefono2"
                            class="col-span-2 flex items-center gap-2 -mt-1">
                            <input type="checkbox" v-model="clienteForm.telefono2EsPrincipal" class="rounded" />
                            <span class="text-xs font-bold text-slate-500">
                                Usar el <strong class="text-slate-700">Teléfono 2</strong> para contactar
                                <span class="font-medium text-slate-400">(WhatsApp, plantillas y vCard)</span>
                            </span>
                        </label>
                        <label class="col-span-2">
                            <span class="text-xs font-bold text-slate-500">Email</span>
                            <input type="email" v-model="clienteForm.emailCliente"
                                class="mt-1 w-full border border-slate-200 rounded-lg px-3 py-2 text-sm bg-white" />
                        </label>
                        <label>
                            <span class="text-xs font-bold text-slate-500">País</span>
                            <select v-model="clienteForm.pais"
                                class="mt-1 w-full border border-slate-200 rounded-lg px-3 py-2 text-sm bg-white">
                                <option value="">-</option>
                                <option v-for="p in maestroStore.paises" :key="p.id ?? ''" :value="p.id ?? ''">{{ p.nombre }}</option>
                            </select>
                        </label>
                        <label>
                            <span class="text-xs font-bold text-slate-500">Idioma</span>
                            <select v-model="clienteForm.idioma"
                                class="mt-1 w-full border border-slate-200 rounded-lg px-3 py-2 text-sm bg-white">
                                <option value="">-</option>
                                <option v-for="i in maestroStore.idiomas" :key="i.id ?? ''" :value="i.id ?? ''">{{ i.nombre }}</option>
                            </select>
                        </label>
                        <label class="col-span-2">
                            <span class="text-xs font-bold text-slate-500">Nota interna</span>
                            <textarea v-model="clienteForm.nota" rows="2"
                                class="mt-1 w-full border border-slate-200 rounded-lg px-3 py-2 text-sm bg-white"></textarea>
                        </label>
                        <label class="col-span-2 flex items-center gap-2" v-if="!isCreateReserva">
                            <input type="checkbox" v-model="clienteForm.datosLocked" class="rounded" />
                            <span class="text-xs font-bold text-slate-500">Bloquear datos (proteger contra sincronización)</span>
                        </label>
                    </div>
                </section>

                <!-- ================================================================
                     ZONA DE BORRADO
                     Sólo en edición y sobre algo que ya existe. La regla de si se
                     puede está en `puedeBorrarseYa()`: veredicto del backend + cola
                     de push terminada. Ver el bloque BORRADO del script.
                     ================================================================ -->
                <section v-if="!readOnly && !isCreate && entryActiva?.eventoId"
                    class="border border-rose-200 bg-rose-50/50 rounded-2xl p-4 space-y-3">
                    <h3 class="text-xs font-black text-rose-700 uppercase tracking-wide flex items-center gap-2">
                        <i class="fas fa-trash-can"></i> Eliminar
                    </h3>

                    <!-- Esperando a que Beds24 confirme -->
                    <div v-if="esperandoSync"
                        class="flex items-start gap-3 text-xs text-amber-800 bg-amber-50 border border-amber-200 rounded-xl px-3 py-2">
                        <i class="fas fa-circle-notch fa-spin mt-0.5"></i>
                        <div>
                            <p class="font-bold">Esperando la sincronización con Beds24…</p>
                            <p class="mt-0.5 text-amber-700">
                                No se puede borrar hasta que la cancelación llegue al canal: si no, la reserva
                                se quedaría viva allí bloqueando las noches. Comprobando cada
                                {{ Math.round(POLL_SYNC_MS / 1000) }} s ({{ pollIntentos }}).
                            </p>
                            <button type="button" @click="pararEspera"
                                class="mt-1 text-amber-900 underline font-bold">Dejar de esperar</button>
                        </div>
                    </div>

                    <!-- Falta sincronizar, pero aún no se está esperando -->
                    <div v-else-if="soloFaltaSync" class="space-y-2">
                        <p class="text-xs text-slate-600">
                            La cancelación está encolada hacia Beds24. En cuanto termine, podrás eliminarla.
                        </p>
                        <button type="button" @click="esperarSincronizacion"
                            class="px-3 py-1.5 bg-amber-500 hover:bg-amber-600 text-white rounded-lg text-xs font-black transition-colors">
                            <i class="fas fa-hourglass-half"></i> Esperar y eliminar
                        </button>
                    </div>

                    <!-- Bloqueado por regla de negocio: se muestra el motivo del backend tal cual -->
                    <p v-else-if="!eventoBorrable && !reservaBorrable"
                        class="text-xs text-slate-600 bg-white border border-slate-200 rounded-xl px-3 py-2">
                        <i class="fas fa-lock text-slate-400"></i>
                        {{ entryActiva?.motivoNoBorrable || reservaInfo.motivoNoBorrable || 'Esta estancia no se puede eliminar.' }}
                    </p>

                    <!-- Confirmación -->
                    <div v-else-if="confirmarBorrado" class="space-y-2">
                        <p class="text-xs font-bold text-rose-800">
                            {{ confirmarBorrado === 'reserva'
                                ? '¿Eliminar la reserva completa y todas sus estancias?'
                                : '¿Eliminar esta estancia?' }}
                            Se pedirá a Beds24 que borre las reservas asociadas.
                        </p>
                        <p v-if="motivoBorradoBloqueado" class="text-xs text-slate-600">{{ motivoBorradoBloqueado }}</p>
                        <div class="flex items-center gap-2">
                            <button type="button" @click="ejecutarBorrado" :disabled="borrando"
                                class="px-3 py-1.5 bg-rose-600 hover:bg-rose-700 disabled:opacity-50 text-white rounded-lg text-xs font-black transition-colors">
                                <i class="fas" :class="borrando ? 'fa-circle-notch fa-spin' : 'fa-trash-can'"></i>
                                Sí, eliminar
                            </button>
                            <button type="button" @click="confirmarBorrado = null" :disabled="borrando"
                                class="px-3 py-1.5 text-xs font-bold text-slate-500 hover:text-slate-700">
                                No
                            </button>
                        </div>
                    </div>

                    <!-- Botones de borrado -->
                    <div v-else class="flex flex-wrap items-center gap-2">
                        <button v-if="eventoBorrable" type="button" @click="confirmarBorrado = 'evento'"
                            class="px-3 py-1.5 bg-white border border-rose-300 hover:bg-rose-100 text-rose-700 rounded-lg text-xs font-black transition-colors">
                            <i class="fas fa-trash-can"></i>
                            {{ tieneVariasEstancias ? 'Eliminar esta estancia' : 'Eliminar estancia' }}
                        </button>
                        <button v-if="reservaBorrable && props.reservaId" type="button" @click="confirmarBorrado = 'reserva'"
                            class="px-3 py-1.5 bg-rose-600 hover:bg-rose-700 text-white rounded-lg text-xs font-black transition-colors">
                            <i class="fas fa-trash-can"></i> Eliminar reserva completa
                        </button>
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

                    <!-- Guardar deja el drawer ABIERTO (ver `guardar()`): lo normal es
                         encadenar acciones sobre la misma reserva. Este botón pequeño es
                         para cuando ya se terminó con ella. -->
                    <button @click="guardar(true)"
                        :disabled="reservasStore.isSaving || isLoadingDrawer || hayErroresEstancia"
                        :title="'Guardar y cerrar el editor'"
                        class="px-3 py-2 bg-slate-100 hover:bg-slate-200 disabled:opacity-50 text-slate-600 rounded-xl text-xs font-black transition-colors whitespace-nowrap">
                        <i class="fas fa-check-double"></i> Guardar y cerrar
                    </button>

                    <button @click="guardar(false)" :disabled="reservasStore.isSaving || isLoadingDrawer || hayErroresEstancia"
                        class="px-5 py-2 bg-[#376875] hover:bg-[#2d5660] disabled:opacity-50 text-white rounded-xl text-sm font-black shadow-sm transition-colors">
                        <i class="fas"
                            :class="reservasStore.isSaving ? 'fa-circle-notch fa-spin' : (avisoGuardado ? 'fa-circle-check' : 'fa-check')"></i>
                        {{ isCreateReserva ? 'Crear Reserva' : (isCreate ? 'Crear Bloqueo' : (avisoGuardado ? 'Guardado' : 'Guardar Cambios')) }}
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

/*
 * La subbarra desplaza en horizontal cuando no caben todos los accesos (móvil),
 * pero una barra de scroll visible ahí se come el poco alto que tiene y se ve
 * como un fallo de maquetación. Tailwind no trae utilidad para esto; ChatView
 * define la suya igual, en local.
 */
.scrollbar-none {
    -ms-overflow-style: none;
    scrollbar-width: none;
}
.scrollbar-none::-webkit-scrollbar {
    display: none;
}
</style>
