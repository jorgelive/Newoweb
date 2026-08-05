<script setup lang="ts">
// ============================================================================
// Panel financiero de la reserva (acordeones dentro del drawer de detalle).
//
// Estructura: un acordeón "Resumen" siempre visible + dos acordeones plegables,
// "Cargos" (lo que se le cobra al huésped, viene de Beds24) y "Pagos" (lo que
// hemos recibido, se registra aquí).
//
// 🔒 CANDADO DE EDICIÓN: los cargos llegan sincronizados desde el canal (son
// verdad histórica de Beds24). Editarlos a mano es una corrección excepcional,
// así que el formulario nace bloqueado y hay que abrir el candado a propósito.
// Es el mismo criterio del backend: los cargos no tienen Post/Delete en la API.
//
// Las etiquetas de tipoCargo/medioPago NO se declaran aquí: llegan del backend
// (PmsEnumAjaxController), que es su única fuente de verdad.
// ============================================================================
import { ref, computed, watch } from 'vue';
import { apiClient } from '@/services/apiClient';
import { useFinanzasStore } from '@/stores/reservas/finanzasStore';
import { extractApiErrorMessage } from '@/stores/reservas/reservasStore';
import {
    clasesTipoCargo,
    importeConMoneda,
    hoyInput,
    toDateInput,
    pmsInformacionFinancieraIri,
    pmsEventoIri,
    idDeIri,
    totalConComision,
    netoDesdeTotal,
    sumarEnMoneda,
    importeEnMoneda,
    type PmsCargoFinanciero,
    type PmsCargoFinancieroCreate,
    type PmsPagoFinanciero,
    type PmsPagoFinancieroCreate,
} from '@/types/pmsFinanzasModel';

const props = defineProps<{
    reservaId: string;
    /** El drawer completo en modo "Ver": aquí también se oculta toda edición. */
    readOnly?: boolean;
}>();

const finanzas = useFinanzasStore();

/** El panel entero arranca COLAPSADO: la cabecera ya adelanta el saldo, que es lo que se busca. */
const panelAbierto = ref(false);

const seccionAbierta = ref<'cargos' | 'pagos' | null>(null);
const error = ref<string | null>(null);

/** Cabecera en ámbar cuando el cobro está anulado, para que se note sin desplegar. */
const panelAnulado = computed(() => finanzas.info?.activa === false);

/** 🔒 Candado: mientras esté cerrado, los cargos son de solo lectura. */
const cargosDesbloqueados = ref(false);

/**
 * Moneda en la que se están MIRANDO los totales (null = la de la cabecera). Ver el bloque
 * "VISTA DUAL" más abajo, donde vive el resto de la lógica.
 *
 * ⚠️ Se declara AQUÍ, y no junto a sus computed, porque `cargar()` la resetea y el watch de
 * abajo llama a `cargar()` con `immediate: true`. Declararla después daba un TDZ
 * ("Cannot access 'monedaVista' before initialization") que TypeScript no detecta.
 */
const monedaVista = ref<string | null>(null);

function toggleSeccion(s: 'cargos' | 'pagos'): void {
    seccionAbierta.value = seccionAbierta.value === s ? null : s;
}

async function cargar(): Promise<void> {
    error.value = null;
    cargosDesbloqueados.value = false;
    // Al cambiar de reserva el panel vuelve a colapsarse: cada una se abre por decisión propia.
    panelAbierto.value = false;
    seccionAbierta.value = null;
    // La vista siempre arranca en la moneda contable: es la que da fe.
    monedaVista.value = null;
    try {
        await Promise.all([finanzas.fetchEnums(), finanzas.fetchPorReserva(props.reservaId)]);
    } catch (err) {
        error.value = extractApiErrorMessage(err, 'No se pudo cargar la información financiera.');
    }
}

watch(() => props.reservaId, cargar, { immediate: true });

// ============================================================================
// PRESENTACIÓN (resuelta contra los enums del backend)
// ============================================================================
const monedaCabecera = computed(() => finanzas.info?.moneda ?? null);

function tipoCargoOpt(id?: string | null) {
    return finanzas.tiposCargo.find(t => t.id === id);
}
function medioPagoOpt(id?: string | null) {
    return finanzas.mediosPago.find(m => m.id === id);
}

/** Importe representativo del cargo: el total de línea, con el monto como respaldo. */
function importeCargo(c: PmsCargoFinanciero): string {
    return importeConMoneda(c.totalLinea ?? c.monto, c.moneda);
}

function fechaLegible(iso?: string | null): string {
    if (!iso) return '—';
    const [y, m, d] = iso.slice(0, 10).split('-');
    return y && m && d ? `${d}/${m}/${y}` : '—';
}

// ============================================================================
// VISTA DUAL SOLES / DÓLARES
//
// La moneda CONTABLE es siempre la de la cabecera: es la que persiste el backend en
// `totalCargos`/`totalPagos` y la que da fe. Este conmutador es sólo de PRESENTACIÓN.
//
// Los totales de la otra moneda se recalculan aquí registro a registro (sumarEnMoneda),
// no reconvirtiendo el total: ver el bloque de espejo en pmsFinanzasModel.ts. Por eso
// cambiar de vista no dispara ninguna llamada al servidor.
// ============================================================================

/** La otra moneda del par, si la reserva tiene registros que la justifiquen. */
const monedaAlterna = computed<string | null>(() => {
    const base = monedaCabecera.value?.id;
    if (!base) return null;

    // Sólo se ofrece el conmutador si de verdad hay dinero en la otra moneda: en una
    // reserva enteramente en dólares, el botón sería ruido.
    const registros = [...(finanzas.info?.cargos ?? []), ...(finanzas.info?.pagos ?? [])];
    const otras = new Set(
        registros.map(r => r.moneda?.id).filter((id): id is string => !!id && id !== base),
    );

    if (otras.size === 1) return [...otras][0];
    // Con más de una moneda extranjera no hay "la otra": se ofrece el par habitual.
    return otras.has('PEN') ? 'PEN' : (otras.size ? [...otras][0] : null);
});

const monedaMostrada = computed(() => monedaVista.value ?? monedaCabecera.value?.id ?? null);
const enMonedaContable = computed(() => monedaMostrada.value === monedaCabecera.value?.id);

/** Ref de moneda (símbolo incluido) de la vista activa, para pintar los importes. */
const monedaVistaRef = computed(() => {
    if (enMonedaContable.value) return monedaCabecera.value;
    const id = monedaMostrada.value;
    return finanzas.monedas.find(m => m.id === id) ?? (id ? { id } : null);
});

const cargosVista = computed(() => finanzas.info?.cargos ?? []);
const pagosVista = computed(() => finanzas.info?.pagos ?? []);

/**
 * Totales de la vista activa.
 *
 * En la moneda contable se usan los del backend tal cual (son la verdad persistida); en la
 * otra se suman los registros. Así la vista por defecto nunca discrepa del servidor por un
 * redondeo distinto.
 */
const totalesVista = computed(() => {
    const info = finanzas.info;
    if (!info) return { cargos: 0, pagos: 0, saldo: 0, sinConvertir: 0 };

    if (enMonedaContable.value) {
        return {
            cargos: Number(info.totalCargos ?? 0),
            pagos: Number(info.totalPagos ?? 0),
            saldo: Number(info.saldo ?? 0),
            sinConvertir: 0,
        };
    }

    const destino = monedaMostrada.value;
    // Anulada: sólo la penalización computa, igual que el rollup del backend (§12.7).
    const cargosComputables = info.activa === false
        ? cargosVista.value.filter(c => c.tipoCargo === 'penalizacion')
        : cargosVista.value;

    const cargos = sumarEnMoneda(cargosComputables, c => c.totalLinea ?? c.monto, destino);
    const pagos = sumarEnMoneda(pagosVista.value, p => p.monto, destino);

    return {
        cargos: cargos.total,
        pagos: pagos.total,
        saldo: cargos.total - pagos.total,
        sinConvertir: cargos.sinConvertir + pagos.sinConvertir,
    };
});

/** Importe ya formateado en la moneda de la vista. */
function importeVista(valor: number): string {
    return importeConMoneda(valor.toFixed(2), monedaVistaRef.value);
}

// ============================================================================
// MONEDA BASE (contable, PERSISTIDA)
//
// Distinta del conmutador de arriba: aquí sí se cambia el dato. Sólo en reservas
// directas puras — lo decide el backend en `monedaBaseEditable` y lo vuelve a
// comprobar al ejecutar la operación (§12.4.4).
// ============================================================================

const cambiandoMonedaBase = ref(false);

const puedeCambiarMonedaBase = computed(
    () => !props.readOnly && finanzas.info?.monedaBaseEditable === true,
);

/** Monedas ofrecidas como base. Se limita al par que el conversor sabe cruzar. */
const monedasBase = computed(() => finanzas.monedas.filter(m => m.id === 'USD' || m.id === 'PEN'));

/**
 * Cambia la moneda contable de la reserva.
 *
 * Se confirma a propósito: reescribe los importes de los cargos. El texto dice exactamente
 * qué va a pasar con cargos y pagos, porque son cosas distintas y no es evidente.
 */
async function cambiarMonedaBase(nueva: string): Promise<void> {
    const actual = monedaCabecera.value?.id;
    if (!nueva || nueva === actual) return;

    const nCargos = cargosVista.value.length;
    const nPagos = pagosVista.value.length;

    const confirmado = window.confirm(
        `¿Pasar la contabilidad de esta reserva de ${actual} a ${nueva}?\n\n`
        + `· Los ${nCargos} cargo(s) se reexpresan en ${nueva} al tipo de cambio de hoy. `
        + `La descripción conserva el importe original.\n`
        + `· Los ${nPagos} pago(s) NO se tocan: el dinero entró en su moneda y ahí se queda.\n\n`
        + `Los nuevos cargos y pagos pasarán a proponerse en ${nueva}.`
    );
    if (!confirmado) return;

    error.value = null;
    cambiandoMonedaBase.value = true;
    try {
        await finanzas.cambiarMonedaBase(nueva);
        monedaVista.value = null;
    } catch (err) {
        error.value = extractApiErrorMessage(err, 'No se pudo cambiar la moneda base.');
    } finally {
        cambiandoMonedaBase.value = false;
    }
}

const saldoPositivo = computed(() => totalesVista.value.saldo > 0.005);

/**
 * Color del SALDO, con el criterio contable: rojo lo que se debe, azul lo saldado.
 *
 * El verde queda reservado al dinero que YA entró (cargos cobrados, pagos), así
 * que un saldo cero en verde daba tres cifras verdes seguidas y había que leer
 * las etiquetas para distinguirlas. En azul acero, «no se debe nada» se ve de un
 * vistazo y no compite con la paleta de marca (teal #376875 / naranja #E07845).
 *
 * Se define aquí, y no suelto en cada plantilla, porque el saldo se pinta en dos
 * sitios —la cabecera plegada y el detalle— y tienen que decir lo mismo.
 */
const claseSaldo = computed(() => (saldoPositivo.value ? 'text-rose-600' : 'text-[#3E6D9C]'));

/**
 * Activa/anula el cobro de la reserva (§12.7). Anular NO borra nada: los cargos siguen
 * visibles, sólo dejan de sumar al saldo.
 */
async function cambiarActiva(activa: boolean): Promise<void> {
    error.value = null;
    try {
        await finanzas.setActiva(activa);
    } catch (err) {
        error.value = extractApiErrorMessage(err, 'No se pudo cambiar el estado de cobro.');
    }
}

// ============================================================================
// AGRUPACIÓN DE CARGOS POR ESTANCIA (reservas agrupadas, §11.6)
//
// Un grupo de Booking.com deja los cargos de varias habitaciones en la MISMA cabecera,
// todos con la descripción sin resolver ("[ROOMNAME1] [FIRSTNIGHT] - [LEAVINGDAY]").
// Se agrupan por `beds24BookingId` y se etiquetan con la casita de `estancias`; así deja
// de haber dos "Alojamiento" idénticos sin saber cuál es cuál.
//
// Con una sola estancia (el caso normal) NO se pinta cabecera de grupo: sería ruido.
// ============================================================================
interface GrupoCargos {
    clave: string;
    titulo: string | null;
    subtitulo: string | null;
    cargos: PmsCargoFinanciero[];
    /** Convertido a la moneda de la vista; NO es la suma cruda de importes (§12.2). */
    subtotal: number;
    /** Cargos del grupo que no se pudieron convertir (sin TC). */
    subtotalIncompleto: boolean;
}

/** Cargos que no se imputan a ninguna estancia concreta (van a la reserva en conjunto). */
const CLAVE_SIN_ESTANCIA = '__general__';

/**
 * Clave de estancia de un cargo. Se unifica en el `eventoId` porque las dos familias de
 * cargos lo identifican de forma distinta:
 *   · Beds24  → por `beds24BookingId` (se traduce con el mapa de estancias)
 *   · manual  → por la IRI de `evento` que eligió el operador
 */
function claveEstancia(c: PmsCargoFinanciero, porBooking: Map<string, string>): string {
    const porEvento = idDeIri(c.evento);
    if (porEvento) return porEvento;

    const bookId = c.beds24BookingId;
    return (bookId && porBooking.get(bookId)) || CLAVE_SIN_ESTANCIA;
}

const gruposCargos = computed<GrupoCargos[]>(() => {
    const cargos = finanzas.info?.cargos ?? [];
    const estancias = finanzas.info?.estancias ?? [];

    // bookingId -> eventoId, para traducir los cargos que vienen del canal.
    const porBooking = new Map(
        estancias.filter(e => e.beds24BookingId).map(e => [e.beds24BookingId as string, e.eventoId]),
    );
    const porEvento = new Map(estancias.map(e => [e.eventoId, e]));
    const grupos = new Map<string, GrupoCargos>();

    for (const c of cargos) {
        const clave = claveEstancia(c, porBooking);

        if (!grupos.has(clave)) {
            const est = porEvento.get(clave);
            grupos.set(clave, {
                clave,
                titulo: est?.unidad ?? (clave === CLAVE_SIN_ESTANCIA ? 'Cargos de la reserva' : 'Estancia'),
                subtitulo: est ? `${fechaLegible(est.inicio)} → ${fechaLegible(est.fin)}` : null,
                cargos: [],
                subtotal: 0,
                subtotalIncompleto: false,
            });
        }

        const g = grupos.get(clave)!;
        g.cargos.push(c);

        // Se convierte a la moneda de la vista antes de sumar. Antes se sumaba el importe
        // crudo y se etiquetaba con la moneda de la cabecera: un cargo de S/. 1425 aparecía
        // como "US$ 1425.00" en el subtotal del grupo.
        const enVista = importeEnMoneda(c, c.totalLinea ?? c.monto, monedaMostrada.value);
        if (enVista === null) g.subtotalIncompleto = true;
        else g.subtotal += enVista;
    }

    return [...grupos.values()];
});

/** ¿La reserva tiene más de una casita? Sólo entonces pedimos elegir estancia al crear. */
const hayVariasEstancias = computed(() => (finanzas.info?.estancias?.length ?? 0) > 1);

/** Sólo se muestran cabeceras de grupo si de verdad hay más de un bloque que distinguir. */
const mostrarGrupos = computed(() => gruposCargos.value.length > 1);

// ============================================================================
// CARGOS: alta manual, edición y borrado
//
// Regla de permisos (espejo del backend):
//  · Cargo de Beds24 → editable SOLO con el candado abierto; nunca borrable.
//  · Cargo manual    → editable y borrable siempre: es nuestro, no del canal.
// ============================================================================
const cargoEditandoId = ref<string | null>(null);
/** 'nuevo' mientras se está dando de alta un cargo manual. */
const cargoNuevoAbierto = ref(false);
const cargoForm = ref({ tipoCargo: '', descripcion: '', totalLinea: '', tipoCambio: '', moneda: '', evento: '' });

function puedeEditarCargo(c: PmsCargoFinanciero): boolean {
    if (props.readOnly) return false;
    return c.manual === true || cargosDesbloqueados.value;
}

function puedeBorrarCargo(c: PmsCargoFinanciero): boolean {
    return !props.readOnly && c.manual === true;
}

function empezarEdicionCargo(c: PmsCargoFinanciero): void {
    cargoNuevoAbierto.value = false;
    cargoEditandoId.value = c.id ?? null;
    cargoForm.value = {
        tipoCargo: c.tipoCargo ?? '',
        descripcion: c.descripcion ?? '',
        totalLinea: c.totalLinea ?? c.monto ?? '',
        tipoCambio: c.tipoCambio ?? '',
        moneda: c.moneda?.id ?? monedaCabecera.value?.id ?? 'USD',
        evento: idDeIri(c.evento) ?? '',
    };

    // Al abrir un cargo que se guardó sin TC, se ofrece ya el del día: es el caso
    // de los registros que quedaron cojos al cambiar la moneda base, y así se
    // reparan con un clic en vez de tecleando la cotización.
    void autocompletarTipoCambioCargo();
}

function abrirNuevoCargo(): void {
    cargoEditandoId.value = null;
    cargoNuevoAbierto.value = true;
    const estancias = finanzas.info?.estancias ?? [];
    cargoForm.value = {
        tipoCargo: '',
        descripcion: '',
        totalLinea: '',
        tipoCambio: '',
        moneda: monedaCabecera.value?.id ?? 'USD',
        // Con una sola casita no hay nada que elegir: se preselecciona.
        evento: estancias.length === 1 ? estancias[0].eventoId : '',
    };
    // El TC se consulta de entrada, coincida o no la moneda (ver `tcSiempre`).
    void autocompletarTipoCambioCargo();
}

function cancelarEdicionCargo(): void {
    cargoEditandoId.value = null;
    cargoNuevoAbierto.value = false;
}

/**
 * Cerrar el candado cierra también la edición que estaba en marcha.
 *
 * Si no, quedaba abierto el formulario de un cargo del canal que ya no se puede
 * guardar: el botón seguía ahí, el operador lo pulsaba y se llevaba el rechazo.
 * Los cargos MANUALES no se ven afectados —nunca necesitaron candado— y su
 * edición sigue abierta.
 */
watch(cargosDesbloqueados, (abierto) => {
    if (abierto || cargoEditandoId.value === null) return;

    const enEdicion = cargosVista.value.find(c => c.id === cargoEditandoId.value);
    if (enEdicion && enEdicion.manual !== true) cancelarEdicionCargo();
});

/** Un cargo en otra moneda que la cabecera necesita TC o no sumará al saldo (§12.2). */
const monedaCargoEsExtranjera = computed(
    () => !!monedaCabecera.value?.id && cargoForm.value.moneda !== monedaCabecera.value.id,
);

/**
 * EL TIPO DE CAMBIO SE PIDE SIEMPRE, en cualquier moneda.
 *
 * Antes el campo sólo aparecía cuando la moneda del registro no era la de la
 * cabecera, y eso dejaba los registros COJOS: el día que se cambia la moneda base
 * de la reserva (USD → PEN), todos los que se guardaron «en la moneda de casa» se
 * quedan sin TC y dejan de sumar — hay que ir uno por uno a repararlos.
 *
 * Guardarlo siempre no tiene coste ni riesgo, porque el TC de este módulo NO es
 * «moneda del registro → cabecera»: es la venta **USD→PEN** del día
 * (`PmsInformacionFinancieraRecalculoService::expresionConvertida()` multiplica o
 * divide según el par, y cuando las dos monedas coinciden lo IGNORA). O sea que
 * un TC de más nunca deforma un total; uno de menos sí lo rompe.
 */
const tcSiempre = true;

/**
 * Motivo por el que el cargo no se puede guardar todavía, o null si está listo.
 * Se valida ANTES de enviar: un cargo en otra moneda sin TC entra en la BD aportando 0.00
 * al saldo, y eso se ve como "el cargo no se reflejó en el precio".
 */
const errorCargo = computed<string | null>(() => {
    if (!cargoForm.value.totalLinea) return 'Falta el importe.';
    if (monedaCargoEsExtranjera.value && !cargoForm.value.tipoCambio) {
        return `Falta el tipo de cambio: sin él, un cargo en ${cargoForm.value.moneda} no suma al total en ${monedaCabecera.value?.id}.`;
    }
    return null;
});

/** ¿Este cargo ya guardado se quedó sin TC y por eso no suma? Se puede reparar. */
function cargoSinTipoCambio(c: PmsCargoFinanciero): boolean {
    const base = monedaCabecera.value?.id;
    return !!base && !!c.moneda?.id && c.moneda.id !== base && !c.tipoCambio;
}

/** Lógica de guardado del cargo. PROPAGA el error para que el drawer pueda no cerrarse. */
async function guardarCargoOrThrow(): Promise<void> {
    if (errorCargo.value) throw new Error(errorCargo.value);

    if (cargoNuevoAbierto.value) {
        const infoId = finanzas.info?.id;
        if (!infoId) return;

        const payload: PmsCargoFinancieroCreate = {
            informacionFinanciera: pmsInformacionFinancieraIri(infoId),
            moneda: `/platform/maestro/monedas/${cargoForm.value.moneda}`,
            tipoCargo: cargoForm.value.tipoCargo || null,
            descripcion: cargoForm.value.descripcion || null,
            totalLinea: cargoForm.value.totalLinea,
            tipoCambio: cargoForm.value.tipoCambio || null,
            evento: cargoForm.value.evento ? pmsEventoIri(cargoForm.value.evento) : null,
        };
        await finanzas.createCargo(payload);
    } else if (cargoEditandoId.value) {
        // `moneda` no viaja: queda fija tras registrar el importe. `tipoCambio` SÓLO viaja si
        // el cargo no lo tenía — es la reparación de un cargo que no sumaba; cambiarlo cuando
        // ya existe lo rechaza el backend (§12.4).
        const original = cargosVista.value.find(c => c.id === cargoEditandoId.value);
        await finanzas.patchCargo(cargoEditandoId.value, {
            tipoCargo: cargoForm.value.tipoCargo || null,
            descripcion: cargoForm.value.descripcion || null,
            totalLinea: cargoForm.value.totalLinea || null,
            evento: cargoForm.value.evento ? pmsEventoIri(cargoForm.value.evento) : null,
            ...(original && !original.tipoCambio && cargoForm.value.tipoCambio
                ? { tipoCambio: cargoForm.value.tipoCambio }
                : {}),
        });
    }
    cancelarEdicionCargo();
}

/** Handler del botón del propio formulario: muestra el error aquí mismo. */
async function guardarCargo(): Promise<void> {
    error.value = null;
    try {
        await guardarCargoOrThrow();
    } catch (err) {
        error.value = extractApiErrorMessage(err, 'No se pudo guardar el cargo.');
    }
}

async function borrarCargo(c: PmsCargoFinanciero): Promise<void> {
    if (!c.id) return;
    if (!window.confirm('¿Eliminar este cargo? El saldo de la reserva se recalculará.')) return;
    error.value = null;
    try {
        await finanzas.deleteCargo(c.id);
    } catch (err) {
        error.value = extractApiErrorMessage(err, 'No se pudo eliminar el cargo.');
    }
}

// ============================================================================
// ALTA / EDICIÓN DE PAGOS
// ============================================================================
const pagoFormAbierto = ref(false);
const pagoEditandoId = ref<string | null>(null);

function pagoVacio() {
    return {
        monto: '',
        moneda: monedaCabecera.value?.id ?? 'USD',
        medioPago: finanzas.mediosPago[0]?.id ?? 'efectivo',
        fechaPago: hoyInput(),
        tipoCambio: '',
        comisionPorcentaje: '',
        referencia: '',
        notas: '',
    };
}
const pagoForm = ref(pagoVacio());

/**
 * Total cobrado al huésped (neto + recargo). NO se persiste: el backend lo deriva.
 * Es editable y bidireccional — al escribir aquí se recalcula el neto (`monto`), que es
 * lo natural cuando el operador conoce lo que pasó por la tarjeta y no el neto.
 */
const pagoTotalCobrado = ref('');

/** Recalcula el total desde el neto (dirección monto → total). */
function refrescarTotalDesdeMonto(): void {
    pagoTotalCobrado.value = pagoForm.value.monto
        ? totalConComision(pagoForm.value.monto, pagoForm.value.comisionPorcentaje)
        : '';
}

/** Dirección inversa: el operador escribe el total y de ahí sale el neto. */
function onCambiarTotalCobrado(): void {
    pagoForm.value.monto = pagoTotalCobrado.value
        ? netoDesdeTotal(pagoTotalCobrado.value, pagoForm.value.comisionPorcentaje)
        : '';
}

/** Importe del recargo, solo para mostrarlo desglosado. */
const pagoImporteComision = computed(() => {
    const neto = Number(pagoForm.value.monto) || 0;
    const pct = Number(pagoForm.value.comisionPorcentaje) || 0;
    return (neto * pct) / 100;
});

function abrirNuevoPago(): void {
    pagoEditandoId.value = null;
    pagoForm.value = pagoVacio();
    // Arranca con el % que declara el enum para el medio preseleccionado.
    pagoForm.value.comisionPorcentaje = medioPagoOpt(pagoForm.value.medioPago)?.comisionPorcentaje ?? '';
    pagoTotalCobrado.value = '';
    pagoFormAbierto.value = true;
    autocompletarTipoCambio();
}

function editarPago(p: PmsPagoFinanciero): void {
    pagoEditandoId.value = p.id ?? null;
    pagoForm.value = {
        monto: p.monto ?? '',
        // La moneda es inmutable tras registrar el pago (candado del backend):
        // se muestra pero no se envía en el PATCH.
        moneda: p.moneda?.id ?? 'USD',
        medioPago: p.medioPago ?? 'efectivo',
        fechaPago: toDateInput(p.fechaPago),
        tipoCambio: p.tipoCambio ?? '',
        comisionPorcentaje: p.comisionPorcentaje ?? '',
        referencia: p.referencia ?? '',
        notas: p.notas ?? '',
    };
    refrescarTotalDesdeMonto();
    pagoFormAbierto.value = true;
}

function cerrarPagoForm(): void {
    pagoFormAbierto.value = false;
    pagoEditandoId.value = null;
}

/**
 * Al cambiar el medio de pago se repone su % por defecto (5.5 en tarjeta, 0 en el resto),
 * que declara el enum en PHP. Es una sugerencia: el operador puede pisarla después.
 */
function onCambiarMedioPago(): void {
    pagoForm.value.comisionPorcentaje = medioPagoOpt(pagoForm.value.medioPago)?.comisionPorcentaje ?? '0';
    refrescarTotalDesdeMonto();
}

const monedaPagoEsExtranjera = computed(
    () => !!monedaCabecera.value?.id && pagoForm.value.moneda !== monedaCabecera.value.id,
);

/**
 * Rellena el tipo de cambio del día consultando el servicio ya existente
 * (`POST /platform/maestro/tipo-cambio/consultar`, el mismo que usa el editor de
 * cotizaciones). Solo actúa si el pago va en una moneda distinta a la de la reserva y el
 * campo está vacío: nunca pisa un valor que haya escrito el operador.
 *
 * Silencioso ante fallo: el campo queda vacío y el aviso de "sin TC no suma al saldo" ya
 * explica la consecuencia.
 */
const cargandoTipoCambio = ref(false);

/** Consulta la venta del día. Devuelve null si no se pudo (nunca lanza). */
async function consultarVenta(fecha: string): Promise<string | null> {
    try {
        const res = await apiClient.post('/platform/maestro/tipo-cambio/consultar', { fecha });
        // `venta` es la punta que se snapshotea en el resto del módulo (§11.4).
        const venta = res.data?.venta;
        return venta ? String(venta) : null;
    } catch {
        return null;
    }
}

async function autocompletarTipoCambio(): Promise<void> {
    // Se rellena SIEMPRE, coincida o no la moneda con la de la reserva: ver la
    // nota de `tcSiempre` — es la foto del día, y sin ella el registro queda cojo
    // en cuanto alguien cambia la moneda base.
    if (pagoForm.value.tipoCambio) return;

    cargandoTipoCambio.value = true;
    try {
        const venta = await consultarVenta(pagoForm.value.fechaPago);
        if (venta && !pagoForm.value.tipoCambio) pagoForm.value.tipoCambio = venta;
    } finally {
        cargandoTipoCambio.value = false;
    }
}

/**
 * Mismo autocompletado para los CARGOS.
 *
 * Faltaba, y era justo el agujero por el que un cargo en soles acababa aportando 0.00 al
 * saldo: el campo existía con su aviso, pero había que teclear el tipo de cambio a mano y
 * el aviso pasaba desapercibido. Ahora se rellena solo y, si aun así queda vacío, el
 * guardado se bloquea (`errorCargo`) en vez de aceptar un cargo que no suma.
 *
 * No hay fecha en el formulario de cargo: el cargo se está creando hoy, así que se usa la
 * cotización de hoy — el mismo criterio que `TipoCambioDelDia` en el backend.
 */
async function autocompletarTipoCambioCargo(): Promise<void> {
    if (cargoForm.value.tipoCambio) return;

    cargandoTipoCambio.value = true;
    try {
        const venta = await consultarVenta(hoyInput());
        if (venta && !cargoForm.value.tipoCambio) cargoForm.value.tipoCambio = venta;
    } finally {
        cargandoTipoCambio.value = false;
    }
}

/** Lógica de guardado del pago. PROPAGA el error para que el drawer pueda no cerrarse. */
async function guardarPagoOrThrow(): Promise<void> {
    const infoId = finanzas.info?.id;
    if (!infoId) {
        throw new Error('Esta reserva todavía no tiene información financiera asociada.');
    }

    if (pagoEditandoId.value) {
        // `moneda` no viaja: es inmutable una vez registrado el pago.
        await finanzas.patchPago(pagoEditandoId.value, {
            monto: pagoForm.value.monto,
            medioPago: pagoForm.value.medioPago,
            fechaPago: pagoForm.value.fechaPago,
            tipoCambio: pagoForm.value.tipoCambio || null,
            comisionPorcentaje: pagoForm.value.comisionPorcentaje || null,
            referencia: pagoForm.value.referencia || null,
            notas: pagoForm.value.notas || null,
        });
    } else {
        const payload: PmsPagoFinancieroCreate = {
            informacionFinanciera: pmsInformacionFinancieraIri(infoId),
            moneda: `/platform/maestro/monedas/${pagoForm.value.moneda}`,
            monto: pagoForm.value.monto,
            medioPago: pagoForm.value.medioPago,
            fechaPago: pagoForm.value.fechaPago,
            tipoCambio: pagoForm.value.tipoCambio || null,
            comisionPorcentaje: pagoForm.value.comisionPorcentaje || null,
            referencia: pagoForm.value.referencia || null,
            notas: pagoForm.value.notas || null,
        };
        await finanzas.createPago(payload);
    }
    cerrarPagoForm();
}

/** Handler del botón del propio formulario: muestra el error aquí mismo. */
async function guardarPago(): Promise<void> {
    error.value = null;
    try {
        await guardarPagoOrThrow();
    } catch (err) {
        error.value = extractApiErrorMessage(err, 'No se pudo guardar el pago.');
    }
}

/**
 * Guarda los formularios que el operador dejó abiertos sin confirmar.
 *
 * Lo llama el botón "Guardar Cambios" del drawer: es mucho más visible que los botones
 * pequeños de cada formulario, así que es el que se acaba pulsando. Sin esto, un cargo o un
 * pago a medio escribir se perdía al cerrar, en silencio.
 *
 * Los formularios vacíos se ignoran — uno abierto por descuido no debe bloquear el guardado
 * con un error de validación.
 *
 * @throws Si alguno falla; el drawer lo captura para no cerrar y no perder lo tecleado.
 */
async function guardarPendientes(): Promise<void> {
    const hayCargo = (cargoNuevoAbierto.value || cargoEditandoId.value !== null) && !!cargoForm.value.totalLinea;
    if (hayCargo) {
        await guardarCargoOrThrow();
    }

    if (pagoFormAbierto.value && !!pagoForm.value.monto) {
        await guardarPagoOrThrow();
    }
}

defineExpose({ guardarPendientes });

async function borrarPago(p: PmsPagoFinanciero): Promise<void> {
    if (!p.id) return;
    if (!window.confirm('¿Eliminar este pago? El saldo de la reserva se recalculará.')) return;
    error.value = null;
    try {
        await finanzas.deletePago(p.id);
    } catch (err) {
        error.value = extractApiErrorMessage(err, 'No se pudo eliminar el pago.');
    }
}
</script>

<template>
    <!-- ⚠️ SIN `overflow-hidden`, a propósito: rompería el `position: sticky` de las barras de
         acción de los formularios (un ancestro con overflow distinto de visible se convierte en
         el scrollport de referencia, y como no scrollea, el sticky no se pega a nada). El
         redondeo se hace elemento a elemento en lugar de recortando el contenedor. -->
    <section class="rounded-2xl shadow-sm ring-1"
        :class="panelAnulado ? 'ring-amber-300' : 'ring-[#376875]/25'">

        <!-- Cabecera del acordeón: siempre visible y con el saldo a la vista, que es el dato
             que se busca al abrir una reserva. Arranca colapsado para no tapar la estancia. -->
        <button type="button" @click="panelAbierto = !panelAbierto"
            class="w-full flex items-center justify-between gap-3 px-4 py-3 text-left transition-colors"
            :class="[
                panelAnulado
                    ? 'bg-gradient-to-r from-amber-500 to-amber-600 hover:from-amber-600 hover:to-amber-700'
                    : 'bg-gradient-to-r from-[#376875] to-[#2d5660] hover:from-[#2d5660] hover:to-[#24454e]',
                // Colapsado la cabecera ES el panel entero, así que se redondea por los cuatro lados.
                panelAbierto ? 'rounded-t-2xl' : 'rounded-2xl',
            ]">
            <span class="flex items-center gap-2.5 min-w-0 text-white">
                <i class="fas fa-chevron-right text-[11px] transition-transform shrink-0"
                    :class="{ 'rotate-90': panelAbierto }"></i>
                <i class="fas fa-money-check-dollar text-sm shrink-0"></i>
                <!-- Título corto: en móvil no cabía "Información Financiera" junto a las cifras. -->
                <span class="font-black text-sm tracking-tight">Finanzas</span>
                <span v-if="panelAnulado"
                    class="px-2 py-0.5 rounded-full bg-white/25 text-[10px] font-black uppercase tracking-wide shrink-0">
                    Anulada
                </span>
            </span>

            <!-- Sin etiquetas: en móvil no cabían. El color ya dice qué es cada cifra —
                 arriba en verde lo que vale la reserva, abajo el saldo: rojo si falta
                 cobrar, azul acero si está saldado (ver `claseSaldo`).
                 `tabular-nums` mantiene las dos alineadas al apilarlas. -->
            <span v-if="finanzas.info" class="flex flex-col items-end gap-0.5 shrink-0">
                <span class="px-2 py-0.5 rounded-md bg-white text-emerald-600 text-[11px] font-black tabular-nums">
                    {{ importeVista(totalesVista.cargos) }}
                </span>
                <span class="px-2 py-0.5 rounded-md bg-white text-[11px] font-black tabular-nums"
                    :class="claseSaldo">
                    {{ importeVista(totalesVista.saldo) }}
                </span>
            </span>
            <i v-else-if="finanzas.isLoading" class="fas fa-spinner fa-spin text-white/80 text-sm shrink-0"></i>
        </button>

        <div v-show="panelAbierto" class="bg-white px-4 py-4 rounded-b-2xl">

        <div v-if="finanzas.isLoading" class="flex items-center gap-2 text-sm font-bold text-slate-400 px-1 py-3">
            <i class="fas fa-spinner fa-spin"></i> Cargando finanzas…
        </div>

        <!-- Toda reserva estrena cabecera al crearse (y las antiguas se rellenaron por
             migración), así que este caso ya sólo se da si la carga falló. -->
        <p v-else-if="!finanzas.info" class="text-xs font-bold text-slate-400 bg-slate-50 border border-slate-200 rounded-xl px-4 py-3">
            <i class="fas fa-circle-info mr-1.5"></i>
            No se encontró la información financiera de esta reserva.
        </p>

        <template v-else>
            <div v-if="error" class="mb-3 bg-rose-50 border border-rose-200 text-rose-700 text-xs font-bold rounded-xl px-4 py-3">
                <i class="fas fa-exclamation-triangle mr-1.5"></i>{{ error }}
            </div>

            <!-- ===== RESERVA ANULADA (cancelada en el canal) ===== -->
            <div v-if="finanzas.info.activa === false"
                class="mb-3 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3">
                <p class="text-xs font-black text-amber-800">
                    <i class="fas fa-ban mr-1.5"></i>Reserva cancelada en el canal
                </p>
                <p class="text-[11px] font-bold text-amber-700/90 mt-1 leading-snug">
                    Los cargos de la estancia se conservan pero <b>no suman al saldo</b>: solo cuenta la penalización.
                    Si el huésped sigue adelante como reserva directa, vuelve a activarla para que se cobren.
                </p>
                <button v-if="!readOnly" type="button" @click="cambiarActiva(true)" :disabled="finanzas.isSaving"
                    class="mt-2 inline-flex items-center gap-1.5 px-3 py-1.5 bg-amber-600 hover:bg-amber-700 disabled:opacity-50 text-white rounded-lg text-[11px] font-black">
                    <i class="fas" :class="finanzas.isSaving ? 'fa-circle-notch fa-spin' : 'fa-rotate-left'"></i>
                    Reactivar cobro
                </button>
            </div>

            <!-- ===== RESUMEN ===== -->
            <div class="rounded-xl border border-slate-200 overflow-hidden mb-3">
                <div class="grid grid-cols-3 divide-x divide-slate-100 bg-slate-50">
                    <div class="px-3 py-3 text-center">
                        <p class="text-[10px] font-black text-slate-400 uppercase tracking-wide">Cargos</p>
                        <p class="text-sm font-black text-slate-800 mt-0.5">
                            {{ importeVista(totalesVista.cargos) }}
                        </p>
                    </div>
                    <div class="px-3 py-3 text-center">
                        <p class="text-[10px] font-black text-slate-400 uppercase tracking-wide">Pagado</p>
                        <p class="text-sm font-black text-emerald-600 mt-0.5">
                            {{ importeVista(totalesVista.pagos) }}
                        </p>
                    </div>
                    <div class="px-3 py-3 text-center">
                        <p class="text-[10px] font-black text-slate-400 uppercase tracking-wide">Saldo</p>
                        <p class="text-sm font-black mt-0.5" :class="claseSaldo">
                            {{ importeVista(totalesVista.saldo) }}
                        </p>
                    </div>
                </div>

                <!-- ===== MONEDA BASE (contable, persistida) =====
                     Sólo en directas puras. En una reserva con cargos del canal ni siquiera
                     se pinta: la moneda la manda la OTA. -->
                <div v-if="puedeCambiarMonedaBase"
                    class="flex items-center justify-between gap-2 px-3 py-2 border-t border-slate-100 bg-white">
                    <span class="text-[10px] font-black text-slate-400 uppercase tracking-wide">
                        <i class="fas fa-scale-balanced mr-1"></i>Moneda base
                    </span>
                    <span class="flex items-center gap-1.5">
                        <i v-if="cambiandoMonedaBase" class="fas fa-circle-notch fa-spin text-slate-300 text-[11px]"></i>
                        <select :value="monedaCabecera?.id"
                            @change="cambiarMonedaBase(($event.target as HTMLSelectElement).value)"
                            :disabled="cambiandoMonedaBase || finanzas.isSaving"
                            class="border border-slate-200 rounded-lg px-2 py-1 text-[11px] font-black text-slate-600 disabled:opacity-50">
                            <option v-for="m in monedasBase" :key="m.id ?? ''" :value="m.id ?? ''">
                                {{ m.id }}{{ m.simbolo ? ` (${m.simbolo})` : '' }}
                            </option>
                        </select>
                    </span>
                </div>

                <!-- ===== CONMUTADOR DE MONEDA (sólo presentación) =====
                     Aparece únicamente si hay registros en otra moneda: en una reserva
                     enteramente en dólares no hay nada que conmutar. -->
                <div v-if="monedaAlterna" class="flex items-center gap-1 px-3 py-2 border-t border-slate-100 bg-white">
                    <button type="button" @click="monedaVista = null"
                        class="flex-1 px-3 py-1.5 rounded-lg text-[11px] font-black transition-colors"
                        :class="enMonedaContable ? 'bg-[#376875] text-white' : 'text-slate-400 hover:bg-slate-50'">
                        {{ monedaCabecera?.id }}
                    </button>
                    <button type="button" @click="monedaVista = monedaAlterna"
                        class="flex-1 px-3 py-1.5 rounded-lg text-[11px] font-black transition-colors"
                        :class="!enMonedaContable ? 'bg-[#376875] text-white' : 'text-slate-400 hover:bg-slate-50'">
                        {{ monedaAlterna }}
                    </button>
                </div>

                <!-- Aviso honesto: los dos totales no guardan proporción entre sí. -->
                <p v-if="!enMonedaContable"
                    class="px-3 py-1.5 text-[10px] font-bold text-[#376875] bg-[#376875]/5 border-t border-slate-100">
                    <i class="fas fa-eye mr-1"></i>
                    Vista en {{ monedaMostrada }}: cada importe se convierte con
                    <b>su propio</b> tipo de cambio, no con una cotización única. La contabilidad
                    de la reserva sigue en {{ monedaCabecera?.id }}.
                </p>

                <p v-if="totalesVista.sinConvertir"
                    class="px-3 py-1.5 text-[10px] font-black text-amber-700 bg-amber-50 border-t border-amber-100">
                    <i class="fas fa-triangle-exclamation mr-1"></i>
                    {{ totalesVista.sinConvertir }}
                    {{ totalesVista.sinConvertir === 1 ? 'registro no suma' : 'registros no suman' }}:
                    les falta el tipo de cambio. Edítalos para completarlo.
                </p>

                <p class="px-3 py-1.5 text-[10px] font-bold text-slate-400 border-t border-slate-100 bg-white flex items-center justify-between gap-2">
                    <span>
                        Contabilidad en {{ monedaCabecera?.nombre ?? monedaCabecera?.id ?? 'USD' }}.
                        Los importes en otra moneda se convierten con el tipo de cambio de cada registro.
                    </span>
                    <!-- Anular a mano: por ejemplo, un no-show que el canal no marcó. -->
                    <button v-if="!readOnly && finanzas.info.activa !== false" type="button"
                        @click="cambiarActiva(false)" :disabled="finanzas.isSaving"
                        class="shrink-0 text-[10px] font-black text-slate-400 hover:text-rose-600 underline decoration-dotted">
                        Anular cobro
                    </button>
                </p>
            </div>

            <!-- ===== ACORDEÓN: CARGOS ===== -->
            <!-- Sin overflow-hidden: ver la nota del <section> raíz (rompe el sticky). -->
            <!-- Cada bloque tiene SU color —verde lo que se factura, azul lo que
                 entra— para no perder de vista en cuál se está trabajando: los dos
                 formularios se parecen mucho y con todo en gris se confundían. -->
            <div class="border rounded-xl mb-3 transition-colors"
                :class="seccionAbierta === 'cargos' ? 'border-emerald-200 bg-emerald-50/40' : 'border-slate-200'">
                <button type="button" @click="toggleSeccion('cargos')"
                    class="w-full flex items-center justify-between gap-2 px-4 py-3 text-left transition-colors hover:bg-emerald-50/70"
                    :class="seccionAbierta === 'cargos' ? 'rounded-t-xl bg-emerald-50/80' : 'rounded-xl'">
                    <span class="flex items-center gap-2 text-sm font-bold" :class="seccionAbierta === 'cargos' ? 'text-emerald-900' : 'text-slate-700'">
                        <i class="fas fa-chevron-right text-[10px] transition-transform"
                            :class="[seccionAbierta === 'cargos' ? 'rotate-90 text-emerald-500' : 'text-slate-400']"></i>
                        <i class="fas fa-receipt" :class="seccionAbierta === 'cargos' ? 'text-emerald-600' : 'text-slate-400'"></i>
                        Cargos
                        <span class="font-normal text-xs" :class="seccionAbierta === 'cargos' ? 'text-emerald-600/70' : 'text-slate-400'">({{ finanzas.info.cargos?.length ?? 0 }})</span>
                    </span>
                    <span class="text-sm font-black" :class="seccionAbierta === 'cargos' ? 'text-emerald-800' : 'text-slate-700'">
                        {{ importeConMoneda(finanzas.info.totalCargos, monedaCabecera) }}
                    </span>
                </button>

                <div v-show="seccionAbierta === 'cargos'" class="border-t border-emerald-100">
                    <!-- 🔒 Candado: sólo protege los cargos sincronizados desde el canal.
                         Los manuales (reservas directas) se editan y borran sin candado. -->
                    <div v-if="!readOnly" class="flex items-start gap-2 px-4 py-2.5 bg-amber-50 border-b border-amber-100">
                        <label class="flex items-center gap-2 cursor-pointer shrink-0">
                            <input type="checkbox" v-model="cargosDesbloqueados" class="rounded" />
                            <span class="text-[11px] font-black uppercase tracking-wide"
                                :class="cargosDesbloqueados ? 'text-amber-700' : 'text-slate-500'">
                                <i class="fas" :class="cargosDesbloqueados ? 'fa-lock-open' : 'fa-lock'"></i>
                                {{ cargosDesbloqueados ? 'Edición habilitada' : 'Habilitar edición' }}
                            </span>
                        </label>
                        <p class="text-[10px] font-bold text-amber-700/80 leading-snug">
                            Protege los cargos sincronizados desde el canal. Los cargos manuales se editan sin candado.
                        </p>
                    </div>

                    <p v-if="!finanzas.info.cargos?.length && !cargoNuevoAbierto" class="px-4 py-3 text-xs font-bold text-slate-400">
                        Sin cargos registrados.
                    </p>

                    <!-- Un bloque por estancia. Con una sola no se pinta cabecera (sería ruido);
                         en una reserva agrupada distingue cada casita. -->
                    <template v-for="g in gruposCargos" :key="g.clave">
                        <div v-if="mostrarGrupos"
                            class="flex items-center justify-between gap-2 px-4 py-2 bg-slate-50 border-y border-slate-100">
                            <span class="flex items-center gap-1.5 min-w-0">
                                <i class="fas fa-door-open text-[10px] text-slate-400 shrink-0"></i>
                                <span class="text-[11px] font-black text-slate-600 truncate">{{ g.titulo }}</span>
                                <span v-if="g.subtitulo" class="text-[10px] font-bold text-slate-400 whitespace-nowrap">
                                    · {{ g.subtitulo }}
                                </span>
                            </span>
                            <span class="text-[11px] font-black text-slate-600 shrink-0">
                                <i v-if="g.subtotalIncompleto" class="fas fa-triangle-exclamation text-amber-500 mr-1"
                                    title="Falta el tipo de cambio de algún cargo: el subtotal está incompleto."></i>
                                {{ importeVista(g.subtotal) }}
                            </span>
                        </div>

                    <div v-for="c in g.cargos" :key="c.id ?? ''" class="px-4 py-3 border-b border-slate-50 last:border-0">
                        <!-- Fila normal -->
                        <div v-if="cargoEditandoId !== c.id" class="flex items-start justify-between gap-3">
                            <div class="min-w-0 flex-1">
                                <div class="flex items-center gap-2 flex-wrap">
                                    <span v-if="tipoCargoOpt(c.tipoCargo)"
                                        class="px-2 py-0.5 rounded-full text-[10px] font-black uppercase tracking-wide"
                                        :class="clasesTipoCargo(tipoCargoOpt(c.tipoCargo)?.color)">
                                        {{ tipoCargoOpt(c.tipoCargo)?.label }}
                                    </span>
                                    <span class="text-xs font-bold text-slate-700 truncate">
                                        {{ c.descripcion || 'Sin descripción' }}
                                    </span>
                                    <span v-if="c.manual"
                                        class="px-1.5 py-0.5 rounded text-[9px] font-black uppercase tracking-wide bg-slate-100 text-slate-500"
                                        title="Cargo creado manualmente">Manual</span>
                                </div>
                                <p class="text-[10px] font-bold text-slate-400 mt-1">
                                    {{ fechaLegible(c.fechaCreacionBeds24) }}
                                    <template v-if="c.tipoCambio"> · TC {{ c.tipoCambio }}</template>
                                    <!-- Equivalente en la otra moneda, para no tener que calcularlo de cabeza. -->
                                    <template v-if="c.tipoCambio && c.moneda?.id !== monedaMostrada">
                                        · ≈ {{ importeVista(importeEnMoneda(c, c.totalLinea ?? c.monto, monedaMostrada) ?? 0) }}
                                    </template>
                                </p>
                                <p v-if="cargoSinTipoCambio(c)"
                                    class="text-[10px] font-black text-amber-600 mt-1 leading-snug">
                                    <i class="fas fa-triangle-exclamation mr-1"></i>
                                    Sin tipo de cambio: este cargo <b>no suma</b> al total en
                                    {{ monedaCabecera?.id }}. Edítalo para completarlo.
                                </p>
                            </div>
                            <div class="flex items-center gap-2 shrink-0">
                                <span class="text-sm font-black text-slate-800">{{ importeCargo(c) }}</span>
                                <button v-if="puedeEditarCargo(c)" type="button" @click="empezarEdicionCargo(c)"
                                    title="Editar cargo"
                                    class="w-7 h-7 flex items-center justify-center rounded-lg text-slate-400 hover:text-[#376875] hover:bg-slate-100">
                                    <i class="fas fa-pen text-[11px]"></i>
                                </button>
                                <button v-if="puedeBorrarCargo(c)" type="button" @click="borrarCargo(c)"
                                    title="Eliminar cargo"
                                    class="w-7 h-7 flex items-center justify-center rounded-lg text-slate-400 hover:text-rose-600 hover:bg-rose-50">
                                    <i class="fas fa-trash text-[11px]"></i>
                                </button>
                            </div>
                        </div>

                        <!-- Fila en edición -->
                        <!-- Mismo envoltorio que el alta y que el pago: el grid va dentro y la
                             barra de acción fuera, o el sticky no tendría recorrido dentro de
                             su celda. -->
                        <div v-else>
                        <div class="grid grid-cols-2 gap-2">
                            <label class="col-span-2">
                                <span class="text-[11px] font-bold text-slate-500">Tipo</span>
                                <select v-model="cargoForm.tipoCargo"
                                    class="mt-1 w-full border border-slate-200 rounded-lg px-3 py-2 text-sm bg-white">
                                    <option value="">—</option>
                                    <option v-for="t in finanzas.tiposCargo" :key="t.id" :value="t.id">{{ t.label }}</option>
                                </select>
                            </label>
                            <label v-if="hayVariasEstancias" class="col-span-2">
                                <span class="text-[11px] font-bold text-slate-500">Estancia</span>
                                <select v-model="cargoForm.evento"
                                    class="mt-1 w-full border border-slate-200 rounded-lg px-3 py-2 text-sm bg-white">
                                    <option value="">Toda la reserva</option>
                                    <option v-for="e in finanzas.info?.estancias ?? []" :key="e.eventoId" :value="e.eventoId">
                                        {{ e.unidad ?? 'Estancia' }} · {{ fechaLegible(e.inicio) }} → {{ fechaLegible(e.fin) }}
                                    </option>
                                </select>
                            </label>
                            <label class="col-span-2">
                                <span class="text-[11px] font-bold text-slate-500">Descripción</span>
                                <input type="text" v-model="cargoForm.descripcion"
                                    class="mt-1 w-full border border-slate-200 rounded-lg px-3 py-2 text-sm bg-white" />
                            </label>
                            <label>
                                <span class="text-[11px] font-bold text-slate-500">Importe</span>
                                <input type="text" inputmode="decimal" v-model="cargoForm.totalLinea"
                                    class="mt-1 w-full border border-slate-200 rounded-lg px-3 py-2 text-sm bg-white" />
                            </label>
                            <!-- SIEMPRE presente (ver `tcSiempre`): es la venta USD→PEN del día.
                                 Si el cargo ya lo tenía guardado, se muestra bloqueado — es la
                                 foto del día y el backend rechaza cambiarlo. -->
                            <label class="col-span-2">
                                <span class="text-[11px] font-bold text-slate-500">
                                    Tipo de cambio (USD→PEN)
                                    <i v-if="cargandoTipoCambio" class="fas fa-circle-notch fa-spin ml-1 text-slate-300"></i>
                                </span>
                                <input type="text" inputmode="decimal" v-model="cargoForm.tipoCambio"
                                    :disabled="!!c.tipoCambio"
                                    placeholder="Ej. 3.750" @focus="autocompletarTipoCambioCargo"
                                    class="mt-1 w-full rounded-lg px-3 py-2 text-sm disabled:bg-slate-100 disabled:text-slate-500"
                                    :class="cargoSinTipoCambio(c) ? 'border border-amber-300' : 'border border-slate-200'" />
                                <span v-if="c.tipoCambio" class="mt-1 block text-[10px] font-bold text-slate-400">
                                    Fijo: es la cotización del día en que se registró.
                                </span>
                                <span v-else class="mt-1 block text-[10px] font-bold text-amber-600">
                                    Guárdalo aunque el cargo esté en la moneda de la reserva: si mañana se
                                    cambia la moneda base, sin él este cargo dejaría de sumar.
                                </span>
                            </label>
                            <p v-if="errorCargo" class="col-span-2 text-[10px] font-black text-amber-600">
                                <i class="fas fa-triangle-exclamation mr-1"></i>{{ errorCargo }}
                            </p>
                        </div>

                        <!-- Pie STICKY. El formulario es largo y el drawer scrollea: con los
                             botones al final se perdían de vista y el operador acababa pulsando
                             «Guardar Cambios» de la reserva creyendo que guardaba el cargo. -->
                        <div class="sticky -bottom-4 mt-2 px-4 py-2.5 bg-emerald-50/95 backdrop-blur
                                    border-t border-emerald-200 rounded-b-xl flex items-center justify-end gap-2 z-10">
                            <button type="button" @click="cancelarEdicionCargo"
                                class="px-3 py-2 text-xs font-bold text-slate-500 hover:text-slate-700">Cancelar</button>
                            <button type="button" @click="guardarCargo" :disabled="finanzas.isSaving || !!errorCargo"
                                class="px-4 py-2 bg-[#376875] hover:bg-[#2d5660] disabled:opacity-50 text-white rounded-lg text-xs font-black">
                                <i class="fas" :class="finanzas.isSaving ? 'fa-circle-notch fa-spin' : 'fa-check'"></i> Guardar cargo
                            </button>
                        </div>
                        </div>
                    </div>
                    </template>

                    <!-- Alta de un cargo manual (reservas directas).
                         Mismo envoltorio que el formulario de pago: el grid va dentro y la barra
                         de acción fuera, o el sticky no tendría recorrido dentro de su celda. -->
                    <div v-if="cargoNuevoAbierto" class="bg-emerald-50/50 border-t border-emerald-100">
                    <div class="px-4 py-3 grid grid-cols-2 gap-2">
                        <label class="col-span-2">
                            <span class="text-[11px] font-bold text-slate-500">Tipo</span>
                            <select v-model="cargoForm.tipoCargo"
                                class="mt-1 w-full border border-slate-200 rounded-lg px-3 py-2 text-sm bg-white">
                                <option value="">—</option>
                                <option v-for="t in finanzas.tiposCargo" :key="t.id" :value="t.id">{{ t.label }}</option>
                            </select>
                        </label>
                        <!-- Sólo tiene sentido elegir casita si la reserva tiene más de una. -->
                        <label v-if="hayVariasEstancias" class="col-span-2">
                            <span class="text-[11px] font-bold text-slate-500">Estancia</span>
                            <select v-model="cargoForm.evento"
                                class="mt-1 w-full border border-slate-200 rounded-lg px-3 py-2 text-sm bg-white">
                                <option value="">Toda la reserva</option>
                                <option v-for="e in finanzas.info?.estancias ?? []" :key="e.eventoId" :value="e.eventoId">
                                    {{ e.unidad ?? 'Estancia' }} · {{ fechaLegible(e.inicio) }} → {{ fechaLegible(e.fin) }}
                                </option>
                            </select>
                        </label>

                        <label class="col-span-2">
                            <span class="text-[11px] font-bold text-slate-500">Descripción</span>
                            <input type="text" v-model="cargoForm.descripcion" placeholder="Ej. Alojamiento 3 noches"
                                class="mt-1 w-full border border-slate-200 rounded-lg px-3 py-2 text-sm bg-white" />
                        </label>
                        <label>
                            <span class="text-[11px] font-bold text-slate-500">Importe</span>
                            <input type="text" inputmode="decimal" v-model="cargoForm.totalLinea"
                                class="mt-1 w-full border border-slate-200 rounded-lg px-3 py-2 text-sm bg-white" />
                        </label>
                        <label>
                            <span class="text-[11px] font-bold text-slate-500">Moneda</span>
                            <select v-model="cargoForm.moneda" @change="autocompletarTipoCambioCargo"
                                class="mt-1 w-full border border-slate-200 rounded-lg px-3 py-2 text-sm bg-white">
                                <option v-for="m in finanzas.monedas" :key="m.id ?? ''" :value="m.id ?? ''">
                                    {{ m.id }}{{ m.simbolo ? ` (${m.simbolo})` : '' }}
                                </option>
                            </select>
                        </label>
                        <!-- Siempre, en cualquier moneda: ver `tcSiempre`. -->
                        <label class="col-span-2">
                            <span class="text-[11px] font-bold text-slate-500">
                                Tipo de cambio (USD→PEN)
                                <i v-if="cargandoTipoCambio" class="fas fa-circle-notch fa-spin ml-1 text-slate-300"></i>
                            </span>
                            <input type="text" inputmode="decimal" v-model="cargoForm.tipoCambio" placeholder="Ej. 3.750"
                                class="mt-1 w-full border border-slate-200 rounded-lg px-3 py-2 text-sm bg-white" />
                            <!-- Equivalente en vivo: quita la duda de "¿cuánto es esto en dólares?". -->
                            <span v-if="cargoForm.tipoCambio && cargoForm.totalLinea"
                                class="mt-1 block text-[10px] font-bold text-slate-400">
                                Equivale a
                                {{ importeConMoneda(
                                    String(importeEnMoneda(
                                        { moneda: { id: cargoForm.moneda }, tipoCambio: cargoForm.tipoCambio },
                                        cargoForm.totalLinea, monedaCabecera?.id) ?? 0),
                                    monedaCabecera) }}
                                en la contabilidad de la reserva.
                            </span>
                        </label>
                        <p v-if="errorCargo" class="col-span-2 text-[10px] font-black text-amber-600 leading-snug">
                            <i class="fas fa-triangle-exclamation mr-1"></i>{{ errorCargo }}
                        </p>
                    </div>

                        <!-- Pie sticky, mismo criterio que el del formulario de pago. -->
                        <div class="sticky -bottom-4 px-4 py-2.5 bg-emerald-50/95 backdrop-blur
                                    border-t border-emerald-200 rounded-b-xl flex items-center justify-end gap-2 z-10">
                            <button type="button" @click="cancelarEdicionCargo"
                                class="px-3 py-2 text-xs font-bold text-slate-500 hover:text-slate-700">Cancelar</button>
                            <button type="button" @click="guardarCargo" :disabled="finanzas.isSaving || !!errorCargo"
                                class="px-4 py-2 bg-[#376875] hover:bg-[#2d5660] disabled:opacity-50 text-white rounded-lg text-xs font-black">
                                <i class="fas" :class="finanzas.isSaving ? 'fa-circle-notch fa-spin' : 'fa-check'"></i> Agregar cargo
                            </button>
                        </div>
                    </div>

                    <button v-else-if="!readOnly && cargoEditandoId === null" type="button" @click="abrirNuevoCargo"
                        class="w-full flex items-center justify-center gap-2 px-4 py-2.5 border-t border-slate-100 text-xs font-black text-slate-400 hover:text-[#376875] hover:bg-slate-50 transition-colors">
                        <i class="fas fa-plus"></i> Agregar cargo manual
                    </button>
                </div>
            </div>

            <!-- ===== ACORDEÓN: PAGOS ===== -->
            <!-- Sin overflow-hidden: ver la nota del <section> raíz (rompe el sticky). -->
            <div class="border rounded-xl transition-colors"
                :class="seccionAbierta === 'pagos' ? 'border-sky-200 bg-sky-50/40' : 'border-slate-200'">
                <button type="button" @click="toggleSeccion('pagos')"
                    class="w-full flex items-center justify-between gap-2 px-4 py-3 text-left transition-colors hover:bg-sky-50/70"
                    :class="seccionAbierta === 'pagos' ? 'rounded-t-xl bg-sky-50/80' : 'rounded-xl'">
                    <span class="flex items-center gap-2 text-sm font-bold" :class="seccionAbierta === 'pagos' ? 'text-sky-900' : 'text-slate-700'">
                        <i class="fas fa-chevron-right text-[10px] transition-transform"
                            :class="[seccionAbierta === 'pagos' ? 'rotate-90 text-sky-500' : 'text-slate-400']"></i>
                        <i class="fas fa-hand-holding-dollar" :class="seccionAbierta === 'pagos' ? 'text-sky-600' : 'text-slate-400'"></i>
                        Pagos
                        <span class="font-normal text-xs" :class="seccionAbierta === 'pagos' ? 'text-sky-600/70' : 'text-slate-400'">({{ finanzas.info.pagos?.length ?? 0 }})</span>
                    </span>
                    <span class="text-sm font-black" :class="seccionAbierta === 'pagos' ? 'text-sky-800' : 'text-emerald-600'">
                        {{ importeConMoneda(finanzas.info.totalPagos, monedaCabecera) }}
                    </span>
                </button>

                <div v-show="seccionAbierta === 'pagos'" class="border-t border-sky-100">
                    <p v-if="!finanzas.info.pagos?.length && !pagoFormAbierto" class="px-4 py-3 text-xs font-bold text-slate-400">
                        Sin pagos registrados.
                    </p>

                    <div v-for="p in finanzas.info.pagos" :key="p.id ?? ''"
                        class="px-4 py-3 border-b border-slate-50 last:border-0 flex items-start justify-between gap-3">
                        <div class="min-w-0 flex-1">
                            <p class="text-xs font-bold text-slate-700 flex items-center gap-1.5">
                                <i class="fas text-slate-400" :class="medioPagoOpt(p.medioPago)?.icono ?? 'fa-money-bill'"></i>
                                {{ medioPagoOpt(p.medioPago)?.label ?? p.medioPago }}
                            </p>
                            <p class="text-[10px] font-bold text-slate-400 mt-1 flex flex-wrap gap-x-2">
                                <span>{{ fechaLegible(p.fechaPago) }}</span>
                                <span v-if="p.tipoCambio">TC {{ p.tipoCambio }}</span>
                                <!-- Cobrado = neto + recargo; el neto es lo que abona la reserva. -->
                                <span v-if="p.comisionPorcentaje && Number(p.comisionPorcentaje) > 0">
                                    +{{ p.comisionPorcentaje }}% · cobrado {{ importeConMoneda(p.montoTotalCobrado, p.moneda) }}
                                </span>
                                <span v-if="p.referencia">#{{ p.referencia }}</span>
                            </p>
                        </div>
                        <div class="flex items-center gap-2 shrink-0">
                            <span class="text-sm font-black text-emerald-600">{{ importeConMoneda(p.monto, p.moneda) }}</span>
                            <template v-if="!readOnly">
                                <button type="button" @click="editarPago(p)" title="Editar pago"
                                    class="w-7 h-7 flex items-center justify-center rounded-lg text-slate-400 hover:text-[#376875] hover:bg-slate-100">
                                    <i class="fas fa-pen text-[11px]"></i>
                                </button>
                                <button type="button" @click="borrarPago(p)" title="Eliminar pago"
                                    class="w-7 h-7 flex items-center justify-center rounded-lg text-slate-400 hover:text-rose-600 hover:bg-rose-50">
                                    <i class="fas fa-trash text-[11px]"></i>
                                </button>
                            </template>
                        </div>
                    </div>

                    <!-- Formulario de alta / edición.
                         El envoltorio NO es el grid: la barra de acción sticky va fuera de él.
                         En un CSS grid el bloque contenedor de un `sticky` es su propia celda,
                         que mide exactamente lo que el elemento: sin recorrido, no se pega. -->
                    <div v-if="pagoFormAbierto" class="bg-sky-50/50 border-t border-sky-100">
                    <div class="px-4 py-3 grid grid-cols-2 gap-2">
                        <label>
                            <span class="text-[11px] font-bold text-slate-500">Monto neto</span>
                            <input type="text" inputmode="decimal" v-model="pagoForm.monto" @input="refrescarTotalDesdeMonto"
                                class="mt-1 w-full border border-slate-200 rounded-lg px-3 py-2 text-sm bg-white" />
                            <span class="mt-1 block text-[10px] font-bold text-slate-400">Es lo que abona la reserva.</span>
                        </label>
                        <label>
                            <span class="text-[11px] font-bold text-slate-500">Moneda</span>
                            <select v-model="pagoForm.moneda" :disabled="!!pagoEditandoId" @change="autocompletarTipoCambio"
                                class="mt-1 w-full border border-slate-200 rounded-lg px-3 py-2 text-sm disabled:bg-slate-100 disabled:text-slate-400">
                                <option v-for="m in finanzas.monedas" :key="m.id ?? ''" :value="m.id ?? ''">
                                    {{ m.id }}{{ m.simbolo ? ` (${m.simbolo})` : '' }}
                                </option>
                            </select>
                            <span v-if="pagoEditandoId" class="mt-1 block text-[10px] font-bold text-slate-400">
                                <i class="fas fa-lock text-[9px] mr-1"></i>La moneda no se puede cambiar tras registrar el pago.
                            </span>
                        </label>
                        <label>
                            <span class="text-[11px] font-bold text-slate-500">Medio de pago</span>
                            <select v-model="pagoForm.medioPago" @change="onCambiarMedioPago"
                                class="mt-1 w-full border border-slate-200 rounded-lg px-3 py-2 text-sm bg-white">
                                <option v-for="m in finanzas.mediosPago" :key="m.id" :value="m.id">{{ m.label }}</option>
                            </select>
                        </label>
                        <label>
                            <span class="text-[11px] font-bold text-slate-500">Fecha</span>
                            <!-- El TC es el del día del pago: al cambiar la fecha se vuelve a consultar. -->
                            <input type="date" v-model="pagoForm.fechaPago" @change="autocompletarTipoCambio"
                                class="mt-1 w-full border border-slate-200 rounded-lg px-3 py-2 text-sm bg-white" />
                        </label>
                        <!-- Comisión en PORCENTAJE, con el sufijo % dentro del campo. -->
                        <label>
                            <span class="text-[11px] font-bold text-slate-500">Comisión</span>
                            <span class="relative mt-1 block">
                                <input type="number" inputmode="decimal" step="0.01" min="0" max="100"
                                    v-model="pagoForm.comisionPorcentaje" @input="refrescarTotalDesdeMonto"
                                    class="w-full border border-slate-200 rounded-lg pl-3 pr-8 py-2 text-sm" />
                                <span class="absolute right-3 top-1/2 -translate-y-1/2 text-sm font-bold text-slate-400 pointer-events-none">%</span>
                            </span>
                            <span v-if="pagoImporteComision > 0" class="mt-1 block text-[10px] font-bold text-slate-400">
                                = {{ importeConMoneda(pagoImporteComision.toFixed(2), { id: pagoForm.moneda }) }}
                            </span>
                        </label>

                        <!-- Derivado, NO se persiste: el backend lo recalcula al leer. -->
                        <label>
                            <span class="text-[11px] font-bold text-slate-500">Total cobrado</span>
                            <input type="text" inputmode="decimal" v-model="pagoTotalCobrado" @input="onCambiarTotalCobrado"
                                class="mt-1 w-full border border-slate-200 rounded-lg px-3 py-2 text-sm bg-white" />
                            <span class="mt-1 block text-[10px] font-bold text-slate-400">
                                Neto + comisión. Si lo editas, se recalcula el neto.
                            </span>
                        </label>

                        <label class="col-span-2">
                            <span class="text-[11px] font-bold text-slate-500">
                                Tipo de cambio (USD→PEN)
                                <span v-if="cargandoTipoCambio" class="ml-1 font-normal text-slate-400">
                                    <i class="fas fa-spinner fa-spin text-[9px]"></i> consultando…
                                </span>
                            </span>
                            <input type="text" inputmode="decimal" v-model="pagoForm.tipoCambio" placeholder="Ej. 3.750"
                                class="mt-1 w-full border border-slate-200 rounded-lg px-3 py-2 text-sm bg-white" />
                            <span v-if="monedaPagoEsExtranjera && !pagoForm.tipoCambio"
                                class="mt-1 block text-[10px] font-bold text-amber-600">
                                <i class="fas fa-triangle-exclamation text-[9px] mr-1"></i>
                                Sin tipo de cambio este pago no suma al saldo (moneda distinta a la reserva).
                            </span>
                            <span v-else-if="!pagoForm.tipoCambio" class="mt-1 block text-[10px] font-bold text-slate-400">
                                Guárdalo aunque coincida la moneda: si mañana se cambia la moneda base
                                de la reserva, sin él este pago dejaría de sumar.
                            </span>
                        </label>
                        <label class="col-span-2">
                            <span class="text-[11px] font-bold text-slate-500">Referencia / Nº operación</span>
                            <input type="text" v-model="pagoForm.referencia"
                                class="mt-1 w-full border border-slate-200 rounded-lg px-3 py-2 text-sm bg-white" />
                        </label>
                        <label class="col-span-2">
                            <span class="text-[11px] font-bold text-slate-500">Notas</span>
                            <textarea v-model="pagoForm.notas" rows="2"
                                class="mt-1 w-full border border-slate-200 rounded-lg px-3 py-2 text-sm bg-white"></textarea>
                        </label>
                    </div>

                        <!-- Pie STICKY: el formulario de pago es largo (medio, fecha, comisión, TC,
                             referencia, notas) y en móvil el botón quedaba fuera de pantalla, así
                             que se acababa usando el "Guardar Cambios" del drawer. Ahora queda
                             pegado justo ENCIMA de esa barra mientras se rellena el formulario.

                             `-bottom-4` (y no `bottom-0`) compensa el `py-4` del contenedor con
                             scroll del drawer: con 0 quedaba un hueco blanco de 1rem entre las
                             dos barras. Si cambia ese padding, hay que cambiar esto. -->
                        <div class="sticky -bottom-4 px-4 py-2.5 bg-sky-50/95 backdrop-blur
                                    border-t border-sky-200 rounded-b-xl flex items-center justify-end gap-2 z-10">
                            <button type="button" @click="cerrarPagoForm"
                                class="px-3 py-2 text-xs font-bold text-slate-500 hover:text-slate-700">Cancelar</button>
                            <button type="button" @click="guardarPago" :disabled="finanzas.isSaving || !pagoForm.monto"
                                class="px-4 py-2 bg-[#376875] hover:bg-[#2d5660] disabled:opacity-50 text-white rounded-lg text-xs font-black">
                                <i class="fas" :class="finanzas.isSaving ? 'fa-circle-notch fa-spin' : 'fa-check'"></i>
                                {{ pagoEditandoId ? 'Guardar pago' : 'Registrar pago' }}
                            </button>
                        </div>
                    </div>

                    <button v-else-if="!readOnly" type="button" @click="abrirNuevoPago"
                        class="w-full flex items-center justify-center gap-2 px-4 py-2.5 border-t border-slate-100 text-xs font-black text-slate-400 hover:text-[#376875] hover:bg-slate-50 transition-colors">
                        <i class="fas fa-plus"></i> Registrar un pago
                    </button>
                </div>
            </div>
        </template>

        </div>
    </section>
</template>
