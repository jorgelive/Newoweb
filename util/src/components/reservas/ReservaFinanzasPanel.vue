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
// El MISMO candado protege el depósito automático de las OTA en la sección de
// pagos, con una diferencia que importa: ahí no basta con desbloquear la interfaz.
// El depósito lo cuadra el sistema en cada recálculo, así que guardar un importe a
// mano manda además `intervenido`, que es lo que hace al sincronizador soltarlo
// (§12.4.5). Sin eso, la edición se aceptaría y se desharía sola al momento.
//
// Las etiquetas de tipoCargo/medioPago NO se declaran aquí: llegan del backend
// (PmsEnumAjaxController), que es su única fuente de verdad.
// ============================================================================
import { ref, computed, watch, nextTick, type ComponentPublicInstance } from 'vue';
import { apiClient } from '@/services/apiClient';
import { useFinanzasStore } from '@/stores/reservas/finanzasStore';
import { useEnlacesPagoStore } from '@/stores/finanzas/enlacesPagoStore';
import { extractApiErrorMessage } from '@/stores/reservas/reservasStore';
import { enfocarEnScroller } from '@/utils/scrollEnfoque';
import ReservaEnlacesPagoSection from '@/components/reservas/ReservaEnlacesPagoSection.vue';
import InfoTooltip from '@/components/common/InfoTooltip.vue';
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
    descripcionVisible,
    type PmsCargoFinanciero,
    type PmsCargoFinancieroCreate,
    type PmsPagoFinanciero,
    type PmsPagoFinancieroCreate,
    type PmsFinanzasCostoTeorico,
    type PmsFinanzasMonedaRef,
    type PmsTotalMoneda,
    type PmsCuadre,
} from '@/types/pmsFinanzasModel';
// El icono y el color de cada canal salen de la misma tabla que usa el resto del módulo
// de reservas: aquí sólo se pintan.
import { canalInfo } from '@/types/pmsReservaModel';

const props = defineProps<{
    reservaId: string;
    /** El drawer completo en modo "Ver": aquí también se oculta toda edición. */
    readOnly?: boolean;
}>();

const finanzas = useFinanzasStore();

/**
 * Mismo store que usa `ReservaEnlacesPagoSection`, no una segunda carga: el componente hijo
 * ya lo llena al montarse y aquí sólo se lee para marcar los pagos que vinieron de un enlace.
 */
const enlacesPago = useEnlacesPagoStore();

/** El panel entero arranca COLAPSADO: la cabecera ya adelanta el saldo, que es lo que se busca. */
const panelAbierto = ref(false);

const seccionAbierta = ref<'cargos' | 'pagos' | null>(null);
const error = ref<string | null>(null);

/** Cabecera en ámbar cuando el cobro está anulado, para que se note sin desplegar. */
const panelAnulado = computed(() => finanzas.info?.activa === false);

/** 🔒 Candado: mientras esté cerrado, los cargos son de solo lectura. */
const cargosDesbloqueados = ref(false);

/**
 * 🔒 Su gemelo en la sección de pagos, y protege una sola cosa: el depósito automático
 * del canal. Los pagos normales nunca han necesitado candado y siguen sin tenerlo.
 */
const pagosDesbloqueados = ref(false);

function toggleSeccion(s: 'cargos' | 'pagos'): void {
    seccionAbierta.value = seccionAbierta.value === s ? null : s;
}

async function cargar(): Promise<void> {
    error.value = null;
    cargosDesbloqueados.value = false;
    pagosDesbloqueados.value = false;
    // Al cambiar de reserva el panel vuelve a colapsarse: cada una se abre por decisión propia.
    panelAbierto.value = false;
    seccionAbierta.value = null;
    try {
        await Promise.all([
            finanzas.fetchEnums(),
            // Aparte de los enums y sin caché: es una lista de personas y un alta reciente
            // tiene que aparecer ya (ver `fetchCobradores` en el store).
            finanzas.fetchCobradores(),
            finanzas.fetchPorReserva(props.reservaId),
        ]);
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
// CONTABILIDAD POR MONEDA
//
// Aquí vivía la VISTA DUAL: un conmutador que reconvertía cada registro a «todo en soles» o
// «todo en dólares». Se retiró el 16/08/2026 con el modelo que lo sostenía.
//
// La regla nueva es que **no se convierte**: soles con soles, dólares con dólares. El backend
// manda `totalesPorMoneda` ya sumado (una entrada por moneda con movimiento) y aquí sólo se
// pinta. Con el conmutador se fueron `monedaVista`, `monedaAlterna`, `enMonedaContable`,
// `sinConvertir`, `saldoVista` y el saldo pintado como `—`: todos existían porque un registro
// sin tipo de cambio desaparecía de la suma, y eso ya no puede pasar.
//
// La única cifra convertida que queda es el CUADRE, y viene resuelta del backend con la
// tolerancia que la explica. Ver §12.2b de docs/PmsBeds24ReservasSync.md.
// ============================================================================

/**
 * Lo que queda por cobrar, por moneda, para los atajos del enlace de pago.
 *
 * Sólo las que deben algo: una moneda saldada no tiene enlace que emitir.
 */
const saldosParaCobrar = computed(() => totalesPorMoneda.value
    .filter(t => Number(t.saldo) > 0.005)
    .map(t => ({ moneda: t.moneda, simbolo: t.simbolo, saldo: t.saldo })));

/** Los totales por moneda, tal como los manda el backend. Vacío mientras carga. */
const totalesPorMoneda = computed<PmsTotalMoneda[]>(() => finanzas.info?.totalesPorMoneda ?? []);

/**
 * Las monedas que enseña la CABECERA, y con qué cifra.
 *
 * ── Por qué el saldo y no los cargos ────────────────────────────────────────
 * La cabecera es donde el ojo busca «¿cuánto debe?». Enseñar ahí el total cargado hacía que la
 * misma cifra saliera dos veces con dos colores: `US$ 65.97` en verde arriba (es un cargo, y los
 * cargos van en verde) y `US$ 65.97` en rojo cuatro líneas más abajo (es el saldo, y debe). El
 * operador tenía que decidir cuál de las dos leer.
 *
 * ── Y por qué se ocultan las monedas sin movimiento ─────────────────────────
 * Una estancia directa nace con una fila a `0.00` en su moneda, que es donde el operador teclea
 * el precio (§12.2b: nada de `HAVING`, las filas en cero se conservan). En la tabla esa fila tiene
 * sentido; en la cabecera era un `S/. 0.00` permanente ocupando el sitio donde debía verse que
 * habían entrado S/ 223.70.
 */
const saldosCabecera = computed<PmsTotalMoneda[]>(() =>
    totalesPorMoneda.value.filter(t => Number(t.cargos) !== 0 || Number(t.pagos) !== 0),
);

/**
 * Las monedas que enseña la cabecera de CADA SECCIÓN, con la cifra de esa sección.
 *
 * ── Por qué hace falta filtrar aquí y no vale `totalesPorMoneda` a secas ────
 * La tabla de totales tiene una fila por moneda con **cualquier** movimiento, cargos o cobros.
 * En `V6WDDQ` eso significa una fila PEN que existe por los dos Yape, y con los cargos en cero:
 * recorrerla sin filtrar hacía que la sección «Cargos» anunciara `S/. 0.00` al lado de
 * `US$ 131.41`, cuando los cuatro cargos de esa reserva son en dólares y no hay ninguno en soles.
 *
 * El conjunto de monedas sale de los REGISTROS y el importe sigue saliendo del rollup. No es
 * recalcular el total por la vía de atrás —eso es justo lo que se retiró—: es saber qué filas
 * tiene sentido enseñar. Y así el cargo de `0.00` con el que nace una estancia directa, que es
 * donde el operador teclea el precio, sigue anunciándose en su moneda en vez de desaparecer.
 */
function monedasDe(registros: { moneda?: PmsFinanzasMonedaRef | null }[]): Set<string> {
    return new Set(registros.map(r => r.moneda?.id).filter((m): m is string => !!m));
}

const totalesDeCargos = computed(() => {
    const conRegistro = monedasDe(cargosVista.value);

    return totalesPorMoneda.value.filter(t => conRegistro.has(t.moneda));
});

const totalesDePagos = computed(() => {
    const conRegistro = monedasDe(pagosVista.value);

    return totalesPorMoneda.value.filter(t => conRegistro.has(t.moneda));
});

/**
 * El color de un saldo, por su signo. Mismo criterio que la tabla de abajo, para que la cabecera
 * y el detalle no puedan contradecirse.
 */
function claseDeSaldo(saldo: string): string {
    const n = Number(saldo);

    if (n < 0) return 'text-[#3E6D9C]';       // a favor del huésped
    if (n === 0) return 'text-emerald-600';   // saldado

    // ⚖️ Deber algo NO es lo mismo que estar sin cobrar. Cuando la ficha cuadra, lo que queda es
    // el residuo de la diferencia cambiaria —el cambio del mostrador nunca es el de la reserva—,
    // y pintarlo de rojo manda a alguien a perseguir 41 céntimos que nadie debe de verdad.
    //
    // La cifra NO se toca: sigue diciendo la verdad estricta de esa moneda. Lo que cambia es que
    // deja de gritar. Con una sola moneda no hay conversión, `cuadra` es estricto y el rojo
    // vuelve a salir con el primer céntimo pendiente.
    return cuadre.value?.cuadra ? 'text-slate-500' : 'text-rose-600';
}

/** El balance soles↔dólares, o null si la ficha todavía no ha llegado. */
const cuadre = computed<PmsCuadre | null>(() => finanzas.info?.cuadre ?? null);

/**
 * ¿Hay movimiento en más de una moneda?
 *
 * Es lo que decide si se pinta la fila de cuadre. Con una sola no hay nada que cuadrar y la
 * fila sería ruido permanente.
 */
const hayVariasMonedas = computed(() => totalesPorMoneda.value.length > 1);

/** Ref de moneda (con símbolo) para un id, buscando en el maestro ya cargado. */
function monedaRef(id?: string | null): PmsFinanzasMonedaRef | null {
    if (!id) return null;

    return finanzas.monedas.find(m => m.id === id) ?? { id };
}

/** Importe con el símbolo de SU moneda. Nunca convierte. */
function importeEn(monto: string | number | null | undefined, moneda?: string | null): string {
    return importeConMoneda(monto === null || monto === undefined ? null : String(monto), monedaRef(moneda));
}

const cargosVista = computed(() => finanzas.info?.cargos ?? []);
const pagosVista = computed(() => finanzas.info?.pagos ?? []);

/**
 * Los cobros, partidos en los que puso el sistema y los que puso una persona.
 *
 * No es una separación estética: son dos cosas distintas y se leen distinto. El depósito
 * automático del canal **no es una decisión de nadie** —lo cuadra el sistema contra los cargos
 * en cada recálculo—, mientras que un cobro manual es dinero que alguien recibió y anotó. Con
 * los dos en la misma lista, el depósito del canal —que suele ser el importe más grande—
 * enterraba los cobros reales y el operador tenía que leer las etiquetas fila por fila.
 *
 * `esAutomatico` y no `gestionadoPorElSistema`: un depósito ya intervenido sigue naciendo del
 * canal, aunque su importe lo mande ahora el operador. Cambiarlo de grupo al intervenirlo lo
 * haría saltar de sitio justo cuando se está trabajando con él.
 */
const gruposPagos = computed(() => ({
    automaticos: pagosVista.value.filter(p => p.esAutomatico === true),
    manuales: pagosVista.value.filter(p => p.esAutomatico !== true),
}));

/**
 * Los cobros del canal arrancan PLEGADOS, y los manuales no.
 *
 * Quien abre esta sección viene a ver o a registrar un cobro suyo. El depósito del canal ya está
 * cuadrado y no se toca —corregirlo es tarea de los CARGOS—, así que ocupar con él la primera
 * pantalla es gastar el sitio en lo único que no hay que mirar.
 */
const pagosAutomaticosAbiertos = ref(false);

/**
 * Subtotal de un grupo, **una cifra por moneda**.
 *
 * Antes devolvía un número: el grupo entero convertido a la moneda de la vista. Ahora devuelve
 * el desglose, porque sumar dos monedas para dar un número es exactamente lo que se retiró.
 */
function subtotalPorMoneda(registros: { moneda?: PmsFinanzasMonedaRef | null }[], importeDe: (r: never) => string | null | undefined): { moneda: string; total: string }[] {
    const acumulado = new Map<string, number>();

    for (const r of registros) {
        const moneda = r.moneda?.id ?? 'USD';
        acumulado.set(moneda, (acumulado.get(moneda) ?? 0) + Number(importeDe(r as never) ?? 0));
    }

    return [...acumulado.entries()]
        .sort(([a], [b]) => a.localeCompare(b))
        .map(([moneda, total]) => ({ moneda, total: total.toFixed(2) }));
}

/** Subtotal de un bloque de cobros, por moneda. */
function subtotalPagos(pagos: PmsPagoFinanciero[]): { moneda: string; total: string }[] {
    return subtotalPorMoneda(pagos, (p: PmsPagoFinanciero) => p.monto);
}

interface BloquePagos {
    clave: string;
    titulo: string;
    icono: string;
    pagos: PmsPagoFinanciero[];
    /** ¿Se puede plegar? Sólo el del canal: los manuales son a lo que se viene. */
    plegable: boolean;
}

/**
 * Los bloques a pintar, en orden, y sin los vacíos.
 *
 * Se construye una lista en vez de repetir el marcado de la fila dos veces: la fila de un cobro
 * tiene ocho estados —enlace, depósito, intervenido, no borrable…— y mantener dos copias sería
 * garantizar que la segunda se quedara atrás.
 *
 * Los manuales van PRIMERO aunque el depósito del canal suela ser mayor: el orden de un panel lo
 * marca lo que se va a tocar, no el tamaño de las cifras.
 */
const bloquesPagos = computed<BloquePagos[]>(() => [
    {
        clave: 'manuales',
        titulo: 'Cobros registrados',
        icono: 'fas fa-hand-holding-dollar',
        pagos: gruposPagos.value.manuales,
        plegable: false,
    },
    {
        clave: 'automaticos',
        titulo: 'Depósito del canal',
        icono: 'fas fa-robot',
        pagos: gruposPagos.value.automaticos,
        plegable: true,
    },
].filter(b => b.pagos.length > 0));

/** ¿Está visible el contenido de este bloque? */
function bloqueAbierto(b: BloquePagos): boolean {
    return !b.plegable || pagosAutomaticosAbiertos.value;
}

/**
 * Enlace de pago que generó un pago dado, si lo hay.
 *
 * La relación ya existe en la base (`fin_enlace_pago.movimiento_generado_id`) y se resuelve
 * aquí en vez de añadir una columna a `PmsPagoFinanciero`: el PMS no tiene por qué guardar
 * una referencia a Finanzas para que la UI pinte una etiqueta, y las dos listas ya están
 * cargadas en este mismo panel.
 *
 * Ojo: si algún día hace falta esta marca FUERA del panel —en la vista de caja, en el
 * agente— habrá que persistirla, porque ahí no se dispone de los enlaces.
 */
function enlaceDePago(pagoId?: string | null) {
    if (!pagoId) return null;
    return enlacesPago.enlaces.find(e => e.movimientoGeneradoId === pagoId) ?? null;
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
    } catch (err) {
        error.value = extractApiErrorMessage(err, 'No se pudo cambiar la moneda base.');
    } finally {
        cambiandoMonedaBase.value = false;
    }
}

// ============================================================================
// PREPAGO PENDIENTE
//
// Lo calcula el backend (`PmsPrepagoCalculador::pendiente()`) y lo inyecta el
// provider de `por-reserva`: es LA MISMA llamada que alimenta el estado de cuenta
// del huésped, así que el importe que se ve aquí es el que él tiene delante.
//
// ⚠️ Va SIEMPRE en la moneda de la cabecera, aunque el conmutador de vista esté en
// la otra: el prepago es la cifra que se le pide al huésped y convertirla al vuelo
// crearía una tercera cantidad que nadie le ha dicho. Por eso usa `monedaCabecera`
// y no en la de un cargo suelto: es lo que se le va a pedir al huésped.
// ============================================================================

const prepago = computed(() => finanzas.info?.prepagoPendiente ?? null);

/** El prepago ya cobrado deja de existir, así que esto sólo aparece si queda algo por pedir. */
const prepagoImporte = computed(() =>
    prepago.value ? importeConMoneda(prepago.value.monto, monedaCabecera.value) : '',
);

/**
 * Cobrar el prepago de un clic: registra un cobro por el importe EXACTO que se está pidiendo.
 *
 * ── Por qué la confirmación pregunta el medio de pago ───────────────────────
 * Todo lo demás se puede deducir —el importe es el del prepago, la moneda es la de la ficha, la
 * fecha es hoy—, pero **cómo pagó el huésped no**. Y no es un detalle de formulario: `medioPago`
 * decide la comisión de la pasarela y es lo que separa en la caja el efectivo del Yape del
 * depósito. Dejarlo en un defecto sería estampar «efectivo» en todos los prepagos del año y
 * descubrirlo cuando alguien cuadre la caja.
 *
 * Así que la confirmación no es un «¿seguro?»: es la única pregunta que hay que hacer.
 */
/**
 * El cobro que se está confirmando, sea el prepago o el saldo de una moneda.
 *
 * Un solo estado para los dos botones: lo que cambia entre ellos es el importe, la moneda y el
 * texto: el resto —preguntar el medio, calcular el recargo, crear el cobro— es idéntico, y
 * tenerlo dos veces era garantizar que se separasen a la primera corrección.
 */
const cobroRapido = ref<{ monto: string; moneda: string; etiqueta: string; notas: string | null } | null>(null);
const prepagoMedioPago = ref('');
const registrandoPrepago = ref(false);

/**
 * El medio con el que se cobra un prepago casi siempre: el huésped todavía no ha llegado, así
 * que no hay efectivo ni Yape que valga — paga por el enlace, con tarjeta. Sigue siendo un
 * desplegable, porque a veces adelantan por transferencia.
 */
const MEDIO_PREPAGO_POR_DEFECTO = 'tarjeta_credito';

function abrirCobroRapido(monto: string, moneda: string, etiqueta: string, notas: string | null = null): void {
    prepagoMedioPago.value = finanzas.mediosPago.some(m => m.id === MEDIO_PREPAGO_POR_DEFECTO)
        ? MEDIO_PREPAGO_POR_DEFECTO
        : finanzas.mediosPago[0]?.id ?? '';
    cobroRapido.value = { monto, moneda, etiqueta, notas };
}

function abrirCobroPrepago(): void {
    const p = prepago.value;
    if (!p) return;

    abrirCobroRapido(p.monto, monedaCabecera.value?.id ?? 'USD', 'el prepago', p.concepto ?? null);
}

/**
 * Cobrar el saldo de una moneda entero. Sólo cuando queda algo que deber en ELLA: el botón no
 * aparece en una moneda con saldo a favor (ahí no hay nada que cobrar, hay algo que devolver) ni
 * cuando la ficha ya cuadra y lo que queda es el residuo del cambio.
 */
function puedeCobrarSaldo(t: PmsTotalMoneda): boolean {
    return !panelAnulado.value && Number(t.saldo) > 0 && !(cuadre.value?.cuadra ?? false);
}

/**
 * El porcentaje que declara el enum para el medio elegido — el mismo que rellena
 * `abrirNuevoPago()` en el formulario largo, para que un prepago no nazca distinto de un cobro
 * tecleado a mano.
 */
const prepagoComision = computed(
    () => medioPagoOpt(prepagoMedioPago.value)?.comisionPorcentaje ?? '',
);

/** El importe del cobro en curso, ya formateado con su moneda. */
const cobroRapidoImporte = computed(() =>
    cobroRapido.value ? importeEn(cobroRapido.value.monto, cobroRapido.value.moneda) : '',
);

/**
 * Lo que se le cobra REALMENTE al huésped: `monto` es el neto que entra y la comisión va encima
 * (`montoComision() = monto × pct / 100`). Con tarjeta, pedir US$ 31.98 significa pasarle
 * US$ 33.74. Se enseña antes de confirmar porque es la cifra que el operador va a teclear en la
 * pasarela, y descubrirla después es descubrirla tarde.
 */
const prepagoTotalConComision = computed(() => {
    const cobro = cobroRapido.value;
    if (!cobro || Number(prepagoComision.value) <= 0) return null;

    return importeEn(totalConComision(cobro.monto, prepagoComision.value), cobro.moneda);
});

async function confirmarCobroPrepago(): Promise<void> {
    const infoId = finanzas.info?.id;
    const cobro = cobroRapido.value;

    if (!infoId || !cobro || !prepagoMedioPago.value || registrandoPrepago.value) return;

    error.value = null;
    registrandoPrepago.value = true;
    try {
        await finanzas.createPago({
            informacionFinanciera: pmsInformacionFinancieraIri(infoId),
            // En SU moneda, sin convertir: el saldo en soles se cobra en soles. El prepago viene
            // en la de la cabecera porque es una petición —«adelanta US$ 32»— y es mono-moneda
            // a propósito.
            moneda: `/platform/maestro/monedas/${cobro.moneda}`,
            monto: cobro.monto,
            medioPago: prepagoMedioPago.value as PmsPagoFinancieroCreate['medioPago'],
            fechaPago: hoyInput(),
            // El tipo de cambio lo sella el backend en `prePersist` (§12.4.1b): no se manda desde
            // aquí para que sea el mismo criterio que en cualquier otro cobro.
            tipoCambio: null,
            comisionPorcentaje: prepagoComision.value || null,
            referencia: null,
            notas: cobro.notas,
            monedaSaldada: null,
            cobradorId: null,
        });
        cobroRapido.value = null;
    } catch (err) {
        error.value = extractApiErrorMessage(err, 'No se pudo registrar el cobro.');
    } finally {
        registrandoPrepago.value = false;
    }
}

/**
 * ¿Queda algo por cobrar?
 *
 * Sale del CUADRE, no de una moneda concreta: con deuda en soles y dólares la pregunta «¿debe?»
 * sólo tiene una respuesta si se miran juntas. Y respeta la tolerancia, así que un residuo de
 * cambio no pinta la reserva en rojo.
 */
const saldoPositivo = computed(() => cuadre.value !== null && !cuadre.value.cuadra);

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
    /** Id de canal de la estancia (`airbnb`, `booking`, `directo`), o null si no se imputa a una. */
    canal: string | null;
    /** Referencia del tarifario para esta estancia. Sólo en las directas; ver el tooltip. */
    costoTeorico: PmsFinanzasCostoTeorico | null;
    cargos: PmsCargoFinanciero[];
    /**
     * Subtotal del grupo, **una cifra por moneda**.
     *
     * Antes era un número: el grupo convertido a la moneda de la vista, con un flag
     * `subtotalIncompleto` para los cargos sin tipo de cambio. Los dos desaparecieron con la
     * conversión — una estancia puede tener el alojamiento en dólares y una ampliación en
     * soles, y sumarlos da un importe que no existe.
     */
    subtotales: { moneda: string; total: string }[];
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

    const nuevoGrupo = (clave: string): GrupoCargos => {
        const est = porEvento.get(clave);

        return {
            clave,
            titulo: est?.unidad ?? (clave === CLAVE_SIN_ESTANCIA ? 'Cargos de la reserva' : 'Estancia'),
            subtitulo: est ? `${fechaLegible(est.inicio)} → ${fechaLegible(est.fin)}` : null,
            canal: est?.canal ?? null,
            costoTeorico: est?.costoTeorico ?? null,
            cargos: [],
            subtotales: [],
        };
    };

    // Los grupos se siembran desde las ESTANCIAS, no desde los cargos, y ése es el orden que
    // importa: el `costoTeorico` de una directa se calcula del tarifario y llega siempre, tenga
    // cargos o no. Recorriendo sólo los cargos, una estancia sin ninguno no generaba cabecera
    // —y el tooltip de la referencia vive en la cabecera—, así que la referencia desaparecía
    // justo cuando sirve: antes de que nadie haya puesto precio.
    for (const e of estancias) {
        grupos.set(e.eventoId, nuevoGrupo(e.eventoId));
    }

    for (const c of cargos) {
        const clave = claveEstancia(c, porBooking);

        if (!grupos.has(clave)) {
            grupos.set(clave, nuevoGrupo(clave));
        }

        grupos.get(clave)!.cargos.push(c);
    }

    // El subtotal se calcula al final y POR MONEDA: una estancia puede tener el alojamiento en
    // dólares y una ampliación en soles, y sumarlos daría una cifra que no existe.
    for (const g of grupos.values()) {
        g.subtotales = subtotalPorMoneda(g.cargos, (c: PmsCargoFinanciero) => c.totalLinea ?? c.monto);
    }

    return [...grupos.values()];
});

/** ¿La reserva tiene más de una casita? Sólo entonces pedimos elegir estancia al crear. */
const hayVariasEstancias = computed(() => (finanzas.info?.estancias?.length ?? 0) > 1);

/**
 * La cabecera de estancia se muestra SIEMPRE, aunque haya una sola.
 *
 * Antes se ocultaba con un único grupo por no meter ruido, pero eso dejaba los cargos sin decir
 * **de qué estancia y de qué canal** son — que es justo lo que hay que saber para cuadrar una
 * reserva. Con una sola casita la cabecera no estorba, y con el icono de canal se lee de un
 * vistazo si ese dinero vino de Booking, de Airbnb o de una venta directa.
 */
const mostrarGrupos = computed(() => gruposCargos.value.length > 0);

// ============================================================================
// CARGOS: alta manual, edición y borrado
//
// Regla de permisos (espejo del backend):
//  · Cargo de Beds24 → editable SOLO con el candado abierto; nunca borrable.
//  · Cargo manual    → editable y borrable siempre: es nuestro, no del canal.
// ============================================================================
// ============================================================================
// ENFOQUE DE LOS MINI-FORMULARIOS
//
// Cargos y pagos se editan en un formulario que se despliega DENTRO de la lista, y el
// panel vive a su vez dentro del drawer, que es largo. Al abrirlo, el navegador no mueve
// nada: el formulario aparecía a media pantalla o directamente fuera, sin que se viera
// dónde empieza ni de qué es. Se le lleva el scroll, igual que al acordeón de estancias.
//
// El scroller no se pasa: lo busca `enfocarEnScroller()` subiendo por los ancestros, porque
// es del componente padre (el drawer) y este panel no lo conoce.
// ============================================================================
const formCargoEl = ref<HTMLElement | null>(null);
const formPagoEl = ref<HTMLElement | null>(null);

/**
 * `ref` de FUNCIÓN y no `ref="nombre"`. El formulario de edición de un cargo vive dentro del
 * `v-for` de la lista, y ahí Vue puebla los refs por nombre como un ARRAY: `formCargoEl.value`
 * dejaría de ser un elemento y `enfocarEnScroller()` reventaría al medirlo. Con una función se
 * recibe el nodo suelto, monte donde monte.
 *
 * El desmontaje (`null`) se ignora a propósito: alta y edición son excluyentes, pero al pasar
 * de una a otra Vue puede avisar del cierre DESPUÉS de la apertura y dejaría el ref vacío justo
 * antes de enfocar. De que el nodo guardado siga vivo se encarga `enfocarEnScroller()`.
 */
function setFormCargoRef(el: Element | ComponentPublicInstance | null): void {
    if (el instanceof HTMLElement) formCargoEl.value = el;
}

function setFormPagoRef(el: Element | ComponentPublicInstance | null): void {
    if (el instanceof HTMLElement) formPagoEl.value = el;
}

async function enfocarFormCargo(): Promise<void> {
    await nextTick();
    enfocarEnScroller(formCargoEl.value);
}

async function enfocarFormPago(): Promise<void> {
    await nextTick();
    enfocarEnScroller(formPagoEl.value);
}

const cargoEditandoId = ref<string | null>(null);
/** 'nuevo' mientras se está dando de alta un cargo manual. */
const cargoNuevoAbierto = ref(false);
const cargoForm = ref({
    tipoCargo: '', descripcion: '', descripcionClienteEs: '', totalLinea: '',
    tipoCambio: '', moneda: '', evento: '',
    // Fuerza la RE-traducción de `descripcionClienteEs` al guardar. Ver `toggleTraduccion`.
    sobreescribirTraduccion: false,
});

/**
 * Botón "forzar traducción" de la descripción para el huésped, espejo del que tiene el
 * editor de cotizaciones (CotizacionEditorView, sobre los mismos campos `#[AutoTranslate]`).
 *
 * Hace falta porque el traductor automático trabaja en MODO SEGURO: rellena los idiomas que
 * están vacíos y respeta los que ya tienen texto. Eso está bien la primera vez, pero al
 * CORREGIR el español de un cargo ya traducido el inglés se quedaba con la versión vieja, en
 * silencio — y es la que acaba viendo el huésped en su estado de cuenta.
 *
 * El flag se apaga solo en el backend en cuanto traduce (`AutoTranslationService`), así que
 * no se queda pegado: hay que volver a pulsarlo la próxima vez que haga falta.
 */
function toggleTraduccion(): void {
    cargoForm.value.sobreescribirTraduccion = !cargoForm.value.sobreescribirTraduccion;
}

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
        descripcionClienteEs: c.descripcionClienteEs ?? '',
        totalLinea: c.totalLinea ?? c.monto ?? '',
        tipoCambio: c.tipoCambio ?? '',
        moneda: c.moneda?.id ?? monedaCabecera.value?.id ?? 'USD',
        evento: idDeIri(c.evento) ?? '',
        // Arranca apagado siempre: forzar la traducción es una decisión de ESTE guardado,
        // no un ajuste del cargo. El backend lo devuelve en false tras usarlo.
        sobreescribirTraduccion: false,
    };

    // Al abrir un cargo que se guardó sin TC, se ofrece ya el del día: es el caso
    // de los registros que quedaron cojos al cambiar la moneda base, y así se
    // reparan con un clic en vez de tecleando la cotización.
    void autocompletarTipoCambioCargo();
    void enfocarFormCargo();
}

function abrirNuevoCargo(): void {
    cargoEditandoId.value = null;
    cargoNuevoAbierto.value = true;
    const estancias = finanzas.info?.estancias ?? [];
    cargoForm.value = {
        tipoCargo: '',
        descripcion: '',
        descripcionClienteEs: '',
        totalLinea: '',
        tipoCambio: '',
        moneda: monedaCabecera.value?.id ?? 'USD',
        // Con una sola casita no hay nada que elegir: se preselecciona.
        evento: estancias.length === 1 ? estancias[0].eventoId : '',
        // En un cargo NUEVO no hay traducciones que pisar: el modo seguro ya las crea todas.
        sobreescribirTraduccion: false,
    };
    // El TC se consulta de entrada, coincida o no la moneda (ver `tcSiempre`).
    void autocompletarTipoCambioCargo();
    void enfocarFormCargo();
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

/**
 * ¿A este cargo se le puede todavía cambiar la moneda?
 *
 * ⚠️ **Espejo de `PmsInformacionFinancieraCoherenciaListener::importeAnteriorEnCero()`.** La
 * moneda queda fija al registrar un importe; mientras el cargo siga en `0.00` no se ha
 * registrado nada, así que no hay foto que romper y se deja cambiar.
 *
 * Es lo que permite escribir en soles el precio de una estancia directa: la línea nace en cero
 * y en la moneda de la cabecera —normalmente USD—, y el precio acordado puede no serlo. Sin
 * esto, la única salida era borrar la línea y crear otra.
 *
 * Si aquí y en el backend dejan de decir lo mismo, el panel ofrecerá un select que el guardado
 * va a rechazar. Al tocar uno, tocar el otro.
 */
function puedeCambiarMoneda(c: PmsCargoFinanciero): boolean {
    return (Number(c.totalLinea ?? c.monto ?? 0) || 0) === 0;
}

/**
 * El importe del cargo visto en la OTRA moneda, mientras se teclea.
 *
 * Ayuda de lectura y nada más: el cargo se guarda y se suma en la moneda en que se pactó. Es la
 * única conversión que queda en el panel, y no toca ninguna cifra — por eso se llama
 * «equivalente» y no «total».
 */
const equivalenteDelCargo = computed<string | null>(() => {
    const tc = Number(cargoForm.value.tipoCambio);
    const importe = Number(cargoForm.value.totalLinea);
    const otra = cargoForm.value.moneda === 'PEN' ? 'USD' : 'PEN';

    if (!tc || !importe) return null;

    return importeEn((cargoForm.value.moneda === 'PEN' ? importe / tc : importe * tc).toFixed(2), otra);
});

/** Un cargo en otra moneda que la cabecera necesita TC o no sumará al saldo (§12.2). */
const monedaCargoEsExtranjera = computed(
    () => !!monedaCabecera.value?.id && cargoForm.value.moneda !== monedaCabecera.value.id,
);

/**
 * Motivo por el que el cargo no se puede guardar todavía, o null si está listo.
 * Se valida ANTES de enviar: un cargo en otra moneda sin TC entra en la BD aportando 0.00
 * al saldo, y eso se ve como "el cargo no se reflejó en el precio".
 *
 * Por eso el TC se exige SIEMPRE que las monedas difieran, sin excepciones: el servicio
 * de recálculo ignora el TC cuando ambas coinciden, así que uno de más nunca deforma un
 * total — pero uno de menos sí lo rompe.
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
            // Cast puntual y acotado: el formulario guarda el id del enum como cadena
            // suelta —viene del <select> que alimenta el AJAX de PmsTipoCargo— y el tipo
            // derivado del esquema exige la unión exacta. El contrato lo fija el backend,
            // que es quien sirve esas opciones.
            tipoCargo: (cargoForm.value.tipoCargo || null) as PmsCargoFinancieroCreate['tipoCargo'],
            descripcion: cargoForm.value.descripcion || null,
            // Lo que ve el huésped. Se manda el texto en español; el backend arma el
            // I18nContent[] y el traductor automático rellena los demás idiomas.
            descripcionClienteEs: cargoForm.value.descripcionClienteEs || null,
            totalLinea: cargoForm.value.totalLinea,
            tipoCambio: cargoForm.value.tipoCambio || null,
            evento: cargoForm.value.evento ? pmsEventoIri(cargoForm.value.evento) : null,
            // Obligatorio en el contrato de escritura. `false` en un cargo nuevo: no hay
            // traducción previa que pisar, y el servicio lo apaga solo tras traducir.
            sobreescribirTraduccion: false,
        };
        await finanzas.createCargo(payload);
    } else if (cargoEditandoId.value) {
        // `moneda` viaja SÓLO si el cargo seguía en cero: con importe registrado el backend la
        // rechaza (§12.4). `tipoCambio` igual, sólo si no lo tenía — es la reparación de un
        // cargo que no sumaba.
        //
        // ⚠️ La condición se pregunta al cargo ORIGINAL, no al formulario: el operador acaba de
        // teclear el importe, así que mirar el formulario diría «ya tiene importe» y la moneda
        // no viajaría justo en el caso para el que se abrió esto.
        const original = cargosVista.value.find(c => c.id === cargoEditandoId.value);
        await finanzas.patchCargo(cargoEditandoId.value, {
            // Cast puntual y acotado: el formulario guarda el id del enum como cadena
            // suelta —viene del <select> que alimenta el AJAX de PmsTipoCargo— y el tipo
            // derivado del esquema exige la unión exacta. El contrato lo fija el backend,
            // que es quien sirve esas opciones.
            tipoCargo: (cargoForm.value.tipoCargo || null) as PmsCargoFinancieroCreate['tipoCargo'],
            descripcion: cargoForm.value.descripcion || null,
            // Lo que ve el huésped. Se manda el texto en español; el backend arma el
            // I18nContent[] y el traductor automático rellena los demás idiomas.
            descripcionClienteEs: cargoForm.value.descripcionClienteEs || null,
            // Sólo en el PATCH: es el caso en que ya existen traducciones que pisar. El
            // backend lo apaga en cuanto traduce, así que no se queda encendido.
            sobreescribirTraduccion: cargoForm.value.sobreescribirTraduccion,
            totalLinea: cargoForm.value.totalLinea || null,
            evento: cargoForm.value.evento ? pmsEventoIri(cargoForm.value.evento) : null,
            ...(original && !original.tipoCambio && cargoForm.value.tipoCambio
                ? { tipoCambio: cargoForm.value.tipoCambio }
                : {}),
            ...(original && puedeCambiarMoneda(original) && cargoForm.value.moneda !== original.moneda?.id
                ? { moneda: `/platform/maestro/monedas/${cargoForm.value.moneda}` }
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
        /**
         * A qué deuda se imputa este cobro, si no es a la de su propia moneda.
         *
         * Vacío es lo normal y lo que hace el 97 % de los cobros: el dinero salda deuda en la
         * moneda en que entró. Con valor, este cobro abona la deuda de ESA moneda al tipo de
         * cambio del propio cobro — la única conversión de todo el módulo, y sólo porque ahí el
         * dinero cruzó de verdad.
         */
        monedaSaldada: '',
        /**
         * Quién RECIBIÓ el dinero (UUID de un usuario con ROLE_COBRADOR), no quién lo apunta.
         *
         * Arranca VACÍO a propósito, sin preseleccionar al operador que tiene la sesión
         * abierta: el efectivo lo cobra quien está en la casita —la limpiadora, el de
         * mantenimiento— y lo registra después otra persona. Preseleccionar al de recepción
         * haría que toda la caja figurase a su nombre, que es justo lo que impide cuadrarla
         * (ver la nota de `PmsPagoFinanciero::$cobrador`).
         */
        cobrador: '',
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
    void enfocarFormPago();
}

/**
 * ¿Se puede tocar este pago?
 *
 * Todos menos el depósito automático del canal, que exige abrir el candado. La condición
 * es `esAutomatico` y no `gestionadoPorElSistema`: un depósito YA intervenido sigue siendo
 * el del canal y sigue mereciendo la pausa —lo que cambia al intervenirlo es quién manda en
 * el importe, no lo fácil que debe ser tocarlo por descuido—.
 */
function puedeEditarPago(p: PmsPagoFinanciero): boolean {
    if (props.readOnly) return false;
    return p.esAutomatico !== true || pagosDesbloqueados.value;
}

/** El pago abierto en el formulario es el depósito del canal: hay que avisar de la consecuencia. */
const editandoDepositoAutomatico = computed(() => {
    if (pagoEditandoId.value === null) return false;

    return finanzas.info?.pagos?.find(p => p.id === pagoEditandoId.value)?.esAutomatico === true;
});

/**
 * ¿Este guardado saca al depósito del automático?
 *
 * Sólo si cambia alguno de los campos que el sincronizador gobierna —los mismos que veta
 * `assertPagoAutomaticoNoEditable()` en el backend—. La referencia y las notas quedan fuera
 * a propósito: son anotaciones que nunca han estado vetadas y no mueven el saldo.
 *
 * ⚠️ Espejo de `$camposBloqueados` en PmsInformacionFinancieraCoherenciaListener; si allí se
 * añade o quita un campo, hay que tocarlo aquí también, o el panel mandará a guardar algo
 * que el backend va a rechazar (o intervendrá un depósito sin necesidad).
 *
 * Los importes se comparan en NÚMERO, no en cadena: el servidor devuelve '118.07' y el enum
 * de comisión '0.00', mientras el formulario trae lo que se tecleó ('118.070', '0'), y una
 * comparación de texto declararía cambiado lo que no lo está.
 */
/**
 * ¿Este cobro entra en una moneda donde NO hay nada que deber?
 *
 * Es la señal de que hace falta imputarlo: ese dinero no puede saldar nada suyo. Es el caso de
 * GASUNN —cargos de Booking en dólares, cobro por Yape en soles— y sin resolverlo la ficha diría
 * «debe US$ 65.97» y «tiene S/ 223.70 a favor», que es falso: el huésped pagó y se fue.
 *
 * Devuelve la moneda a la que tocaría imputarlo, o `null` si no hace falta.
 */
const monedaAImputar = computed<string | null>(() => {
    const propia = pagoForm.value.moneda;
    const conDeuda = totalesPorMoneda.value.filter(t => Number(t.cargos) > 0);

    // Si en su propia moneda hay algo que deber, el cobro salda lo suyo y no hay nada que decidir.
    if (conDeuda.some(t => t.moneda === propia)) return null;

    // Sólo se propone cuando hay UNA candidata: con deuda en dos monedas distintas de la del
    // cobro, elegir por él sería adivinar a cuál se aplica.
    return conDeuda.length === 1 ? conDeuda[0].moneda : null;
});

/** El importe del cobro visto en la moneda a la que se imputaría, para enseñarlo antes de guardar. */
const importeImputado = computed<string | null>(() => {
    const destino = monedaAImputar.value;
    const tc = Number(pagoForm.value.tipoCambio);
    const monto = Number(pagoForm.value.monto);

    if (!destino || !tc || !monto) return null;

    return importeEn((pagoForm.value.moneda === 'PEN' ? monto / tc : monto * tc).toFixed(2), destino);
});

function intervieneAlGuardar(p?: PmsPagoFinanciero): boolean {
    if (p?.esAutomatico !== true) return false;

    const igualNumero = (a?: string | null, b?: string | null): boolean =>
        (Number(a) || 0) === (Number(b) || 0);

    return !igualNumero(p.monto, pagoForm.value.monto)
        || !igualNumero(p.comisionPorcentaje, pagoForm.value.comisionPorcentaje)
        || p.medioPago !== pagoForm.value.medioPago
        || toDateInput(p.fechaPago) !== pagoForm.value.fechaPago
        // Reimputar un depósito del canal es cambiar a qué deuda se aplica: también interviene.
        || (p.monedaSaldada?.id ?? '') !== pagoForm.value.monedaSaldada;
}

/**
 * Devuelve el depósito al automático: el sistema vuelve a cuadrarlo contra los cargos del
 * canal en este mismo guardado (y lo retira si ya no queda ninguno).
 *
 * Va SOLO el flag: mandar además el importe del formulario haría que el backend viera un
 * pago gestionado por el sistema con el monto cambiado, y lo rechazaría — que es justo la
 * incoherencia que representa («devuélveme el control, pero con mi cifra»).
 */
async function devolverPagoAlAutomatico(p: PmsPagoFinanciero): Promise<void> {
    if (!p.id) return;
    if (!window.confirm(
        '¿Devolver el depósito al automático? El sistema volverá a cuadrarlo con los cargos '
        + 'del canal y se perderá el importe que fijaste a mano.'
    )) return;

    error.value = null;
    try {
        if (pagoEditandoId.value === p.id) cerrarPagoForm();
        await finanzas.patchPago(p.id, { intervenido: false });
    } catch (err) {
        error.value = extractApiErrorMessage(err, 'No se pudo devolver el depósito al automático.');
    }
}

/**
 * Cerrar el candado cierra la edición del depósito que estuviera en marcha, por lo mismo
 * que en los cargos: el formulario abierto ya no se podría guardar y el operador se llevaría
 * el rechazo. Los pagos normales no se ven afectados.
 */
watch(pagosDesbloqueados, (abierto) => {
    if (abierto || pagoEditandoId.value === null) return;

    const enEdicion = finanzas.info?.pagos?.find(p => p.id === pagoEditandoId.value);
    if (enEdicion?.esAutomatico === true) cerrarPagoForm();
});

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
        monedaSaldada: p.monedaSaldada?.id ?? '',
        cobrador: p.cobradorId ?? '',
    };
    refrescarTotalDesdeMonto();
    pagoFormAbierto.value = true;
    void enfocarFormPago();
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
 * ¿El tipo de cambio de este pago ya está guardado y por tanto es intocable?
 *
 * Se mira el valor DEL SERVIDOR, no el del formulario: el autocompletado rellena el campo en
 * cuanto se abre un pago que no lo tenía, y comparar contra el formulario congelaría justo la
 * reparación que sí está permitida (§12.4: null → X se acepta, X → Y no).
 */
const tipoCambioPagoCongelado = computed(() => {
    if (pagoEditandoId.value === null) return false;

    const original = finanzas.info?.pagos?.find(p => p.id === pagoEditandoId.value)?.tipoCambio;

    return !!original;
});

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

/**
 * Los cobros cruzados: los que entraron en una moneda donde no hay nada que deber.
 *
 * Es lo que hace posible el botón de un clic de la fila de cuadre. Se calcula aquí y no en el
 * backend porque el panel ya tiene la lista entera de cobros; pedirla otra vez sería una llamada
 * para saber algo que está en pantalla.
 *
 * ── Dónde está la ambigüedad, que no es donde parece ────────────────────────
 * Lo que no se puede adivinar es **a qué deuda** se aplica un cobro cruzado, y eso sólo pasa
 * cuando hay DOS monedas debiendo. Con una sola, todos los cobros de fuera van ahí y no hay
 * ninguna decisión que tomar: `V6WDDQ` tiene dos cobros por Yape que juntos saldan los dólares
 * que faltaban, y exigir que fuera uno solo dejaba esa ficha sin botón por nada.
 */
const cobrosCruzados = computed(() => {
    if (!cuadre.value?.sugiereImputacion) return null;

    const monedasConDeuda = totalesPorMoneda.value.filter(t => Number(t.cargos) > 0);

    // La única ambigüedad real: con deuda en dos monedas, elegir por el operador sería adivinar.
    if (monedasConDeuda.length !== 1) return null;

    const destino = monedasConDeuda[0].moneda;

    const pagos = pagosVista.value.filter(
        (p): p is typeof p & { id: string } =>
            !!p.id && !!p.moneda?.id && p.moneda.id !== destino && !p.monedaSaldada,
    );

    if (pagos.length === 0) return null;

    // El importe sólo se enseña si todos comparten moneda; si no, se dice cuántos son y ya.
    const monedas = new Set(pagos.map(p => p.moneda?.id));
    const importe = monedas.size === 1
        ? importeEn(String(pagos.reduce((suma, p) => suma + Number(p.monto), 0)), [...monedas][0] ?? '')
        : null;

    return { pagos, destino, importe };
});

const imputando = ref(false);

/**
 * Escribe `monedaSaldada` en el cobro cruzado: un clic donde antes había que desplegar los
 * cobros, habilitar la edición, abrir el cobro y marcar una casilla.
 *
 * No inventa nada que el formulario no hiciera: es el mismo PATCH del mismo campo, en el grupo
 * `pms_pago:patch` porque reimputar es corregir una decisión contable, no falsear un hecho.
 */
async function imputarCobroCruzado(): Promise<void> {
    const cruce = cobrosCruzados.value;
    if (!cruce || imputando.value) return;

    error.value = null;
    imputando.value = true;
    try {
        // De uno en uno y no en paralelo: cada PATCH dispara el recálculo de la ficha en el
        // `postFlush`, y lanzarlos a la vez sería hacer que dos transacciones reescriban la misma
        // tabla de totales sin necesidad. Son dos cobros como mucho.
        for (const pago of cruce.pagos) {
            await finanzas.patchPago(pago.id, {
                monedaSaldada: `/platform/maestro/monedas/${cruce.destino}`,
            });
        }
    } catch (err) {
        error.value = extractApiErrorMessage(err, 'No se pudo imputar el cobro.');
    } finally {
        imputando.value = false;
    }
}

/** Lógica de guardado del pago. PROPAGA el error para que el drawer pueda no cerrarse. */
async function guardarPagoOrThrow(): Promise<void> {
    const infoId = finanzas.info?.id;
    if (!infoId) {
        throw new Error('Esta reserva todavía no tiene información financiera asociada.');
    }

    if (pagoEditandoId.value) {
        const editado = finanzas.info?.pagos?.find(p => p.id === pagoEditandoId.value);

        // `moneda` no viaja: es inmutable una vez registrado el pago.
        await finanzas.patchPago(pagoEditandoId.value, {
            // 🔓 Cambiar lo que el sincronizador gobierna es, por definición, intervenir el
            // depósito: tiene que soltarlo o el importe volvería a su sitio en el mismo flush.
            // Viaja en el MISMO PATCH que el importe —el backend lee el estado ya mutado— así
            // que no hacen falta dos viajes ni un botón aparte.
            //
            // Sólo cuando de verdad cambió algo de eso: anotar la REFERENCIA o una nota en el
            // depósito nunca ha necesitado permiso —el backend siempre las dejó pasar— y
            // marcarlo como intervenido por escribir un comentario lo sacaría del automático
            // sin que nadie lo pidiera, que es una decisión demasiado grande para un tooltip.
            ...(intervieneAlGuardar(editado) ? { intervenido: true } : {}),
            monto: pagoForm.value.monto,
            // Mismo caso que `tipoCargo`: el formulario guarda el id del enum como cadena
            // —viene del <select> que alimenta el AJAX de PmsMedioPago— y el tipo derivado
            // del esquema exige la unión exacta. El contrato lo fija el backend.
            medioPago: pagoForm.value.medioPago as PmsPagoFinancieroCreate['medioPago'],
            fechaPago: pagoForm.value.fechaPago,
            tipoCambio: pagoForm.value.tipoCambio || null,
            comisionPorcentaje: pagoForm.value.comisionPorcentaje || null,
            referencia: pagoForm.value.referencia || null,
            notas: pagoForm.value.notas || null,
            // A qué deuda se imputa. SÍ viaja en el PATCH, al contrario que `moneda`: reimputar
            // un cobro es corregir una decisión contable, no falsear un hecho. Cadena vacía =
            // vuelve a saldar su propia moneda.
            monedaSaldada: pagoForm.value.monedaSaldada
                ? `/platform/maestro/monedas/${pagoForm.value.monedaSaldada}`
                : null,
            // UUID plano, NO una IRI: `User` no es un recurso de API Platform y lo resuelve
            // PmsPagoFinancieroProcessor. Cadena vacía = desasignar.
            cobradorId: pagoForm.value.cobrador || null,
        });
    } else {
        const payload: PmsPagoFinancieroCreate = {
            informacionFinanciera: pmsInformacionFinancieraIri(infoId),
            moneda: `/platform/maestro/monedas/${pagoForm.value.moneda}`,
            monto: pagoForm.value.monto,
            // Mismo caso que `tipoCargo`: el formulario guarda el id del enum como cadena
            // —viene del <select> que alimenta el AJAX de PmsMedioPago— y el tipo derivado
            // del esquema exige la unión exacta. El contrato lo fija el backend.
            medioPago: pagoForm.value.medioPago as PmsPagoFinancieroCreate['medioPago'],
            fechaPago: pagoForm.value.fechaPago,
            tipoCambio: pagoForm.value.tipoCambio || null,
            comisionPorcentaje: pagoForm.value.comisionPorcentaje || null,
            referencia: pagoForm.value.referencia || null,
            notas: pagoForm.value.notas || null,
            monedaSaldada: pagoForm.value.monedaSaldada
                ? `/platform/maestro/monedas/${pagoForm.value.monedaSaldada}`
                : null,
            // Ver la nota del PATCH: UUID plano, lo resuelve el processor.
            cobradorId: pagoForm.value.cobrador || null,
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

/**
 * Recarga la información financiera SIN resetear el estado de la interfaz.
 *
 * Lo llama el drawer después de guardar las estancias: ese guardado genera cargos en el
 * backend —los automáticos de una estancia directa nueva (§12.0.1), el de un horario
 * extra (§7.1.b), el reajuste del depósito de la OTA— y el panel no se enteraba: sólo
 * cargaba al cambiar `props.reservaId`, así que los totales parecían congelados hasta
 * cerrar y reabrir la reserva.
 *
 * A diferencia de `cargar()`, conserva acordeones, candado y moneda de vista: es un
 * refresco de datos, no una reapertura. Silencioso ante fallo: los datos en pantalla
 * siguen siendo los últimos buenos y el siguiente movimiento volverá a intentarlo.
 */
async function refrescar(): Promise<void> {
    try {
        await finanzas.fetchPorReserva(props.reservaId);
    } catch {
        /* se conserva lo que había */
    }
}

defineExpose({ guardarPendientes, refrescar });

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
                 en verde lo que vale la reserva, debajo el saldo: rojo si falta cobrar, azul
                 acero si está saldado (ver `claseSaldo`).
                 `tabular-nums` mantiene las cifras alineadas al apilarlas.

                 UNA LÍNEA POR MONEDA, y no una cifra convertida: con deuda en soles y dólares,
                 sumarlas daría un importe que nadie pactó. Con una sola moneda —313 de 317
                 reservas— se ve exactamente igual que antes. -->
            <span v-if="finanzas.info" class="flex flex-col items-end gap-0.5 shrink-0">
                <span v-for="t in saldosCabecera" :key="t.moneda"
                    class="px-2 py-0.5 rounded-md bg-white text-[11px] font-black tabular-nums"
                    :class="claseDeSaldo(t.saldo)"
                    :title="`${t.moneda}: cargos ${t.cargos} · pagado ${t.pagos}`">
                    {{ importeEn(t.saldo, t.moneda) }}
                </span>
                <!-- El cuadre: los saldos de las dos monedas llevados a una sola cifra, marcada
                     con `≈` porque hubo conversión.

                     ⚠️ SÓLO con dos monedas, que es la misma condición que la fila de abajo. Con
                     una sola no hay nada que cuadrar: el cuadre ES ese saldo, y se pintaba la
                     misma cifra dos veces seguidas — en 313 de 317 reservas. Dos pastillas
                     idénticas no se leen como «lo mismo dicho dos veces», se leen como dos datos
                     distintos que por casualidad coinciden, y eso obliga a pararse a averiguar
                     cuál es cuál. -->
                <span v-if="cuadre && cuadre.mixta"
                    class="px-2 py-0.5 rounded-md bg-white text-[11px] font-black tabular-nums"
                    :class="claseSaldo"
                    :title="`Balance de las dos monedas al cambio ${cuadre.tipoCambio ?? '—'}`">
                    ≈ {{ importeEn(cuadre.diferencia, cuadre.moneda) }}
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

            <!-- ===== RESUMEN =====
                 Una fila por moneda. Con una sola —la inmensa mayoría— se lee igual que antes;
                 con dos, cada una dice la verdad de lo suyo y no se suman entre sí. -->
            <div class="rounded-xl border border-slate-200 overflow-hidden mb-3">
                <div v-for="t in totalesPorMoneda" :key="t.moneda"
                    class="grid grid-cols-3 divide-x divide-slate-100 bg-slate-50 border-b border-slate-100 last:border-b-0">
                    <div class="px-3 py-3 text-center">
                        <p class="text-[10px] font-black text-slate-400 uppercase tracking-wide">
                            Cargos<template v-if="hayVariasMonedas"> · {{ t.moneda }}</template>
                        </p>
                        <p class="text-sm font-black text-slate-800 mt-0.5">{{ importeEn(t.cargos, t.moneda) }}</p>
                    </div>
                    <div class="px-3 py-3 text-center">
                        <p class="text-[10px] font-black text-slate-400 uppercase tracking-wide">Pagado</p>
                        <p class="text-sm font-black text-emerald-600 mt-0.5">{{ importeEn(t.pagos, t.moneda) }}</p>
                    </div>
                    <div class="px-3 py-3 text-center">
                        <p class="text-[10px] font-black text-slate-400 uppercase tracking-wide">Saldo</p>
                        <p class="text-sm font-black mt-0.5 whitespace-nowrap"
                            :class="claseDeSaldo(t.saldo)">
                            {{ importeEn(t.saldo, t.moneda) }}
                        </p>
                        <!-- DEBAJO y no al lado: la celda es un tercio del panel, y con el botón
                             en la misma línea el importe se partía en dos renglones. Lo que no
                             puede romperse nunca es la cifra. -->
                        <button v-if="puedeCobrarSaldo(t)" type="button"
                            class="mt-1.5 px-2 py-0.5 rounded text-[9px] font-black uppercase tracking-wide
                                   bg-[#376875] text-white hover:bg-[#2c545f] transition-colors"
                            :title="`Registra un cobro por los ${importeEn(t.saldo, t.moneda)} que faltan.`"
                            @click="abrirCobroRapido(t.saldo, t.moneda, `el saldo en ${t.moneda}`)">
                            Cobrar
                        </button>
                    </div>
                </div>

                <!-- ===== CUADRE =====
                     Sólo con más de una moneda: es la respuesta a «te pago en soles lo que
                     falta, ¿cuánto es?». NO es contabilidad —los totales de arriba lo son— y
                     por eso va aparte, marcado con `≈` y con su tipo de cambio a la vista. -->
                <div v-if="cuadre && cuadre.mixta"
                    class="flex items-center justify-between gap-2 px-3 py-2.5 border-t border-slate-200 bg-white">
                    <span class="flex items-center gap-1.5 text-[11px] font-black uppercase tracking-wide text-slate-500">
                        Cuadre
                        <InfoTooltip lado="izquierda">
                            Los saldos de las dos monedas llevados a <b class="text-white">{{ cuadre.moneda }}</b>
                            con el cambio de la reserva. Es lo que hay que cobrar (o devolver) si se cierra todo
                            en una sola moneda.
                            <span class="block mt-1.5">
                                <b class="text-white">No es contabilidad</b>: la contabilidad son los totales de
                                arriba, que no se convierten. Esto es una referencia para cerrar.
                            </span>
                            <span class="block mt-1.5 text-slate-400">
                                Se admite hasta {{ importeEn(cuadre.tolerancia, cuadre.moneda) }} de diferencia:
                                el cambio del mostrador nunca es exactamente el de la reserva, y ese margen crece
                                con lo que se convirtió.
                            </span>
                        </InfoTooltip>
                        <span v-if="cuadre.tipoCambio" class="font-bold normal-case tracking-normal text-slate-400">
                            al {{ cuadre.tipoCambio }}
                        </span>
                    </span>

                    <span class="flex items-center gap-2 shrink-0">
                        <!-- 🎯 EL CLIC QUE CIERRA EL CRUCE.
                             Va AQUÍ, en la fila del cuadre, y no sólo en el formulario de cobro:
                             ésta es la línea donde el operador está viendo la contradicción («debe
                             US$ 65.97» y «cuadra en 0.00»), y la salida no puede ser desplegar los
                             cobros, habilitar la edición y buscar una casilla. -->
                        <button v-if="cobrosCruzados" type="button"
                            class="px-2 py-1 rounded-md text-[10px] font-black uppercase tracking-wide
                                   bg-sky-600 text-white hover:bg-sky-700 disabled:opacity-50
                                   disabled:cursor-not-allowed transition-colors"
                            :disabled="imputando || panelAnulado"
                            :title="`Marca ${cobrosCruzados.pagos.length === 1 ? 'este cobro' : 'estos cobros'} `
                                + `como pago de la deuda en ${cobrosCruzados.destino}. `
                                + 'La caja no cambia: sigue habiendo entrado el importe en su moneda.'"
                            @click="imputarCobroCruzado()">
                            <i v-if="imputando" class="fas fa-spinner fa-spin mr-1"></i>
                            Imputar
                            {{ cobrosCruzados.importe ?? `${cobrosCruzados.pagos.length} cobros` }}
                            a {{ cobrosCruzados.destino }}
                        </button>
                        <span v-if="cuadre.saldoAFavor"
                            class="px-1.5 py-0.5 rounded text-[9px] font-black uppercase tracking-wide bg-sky-100 text-sky-700"
                            title="El huésped pagó de más: está saldada, pero ese dinero es suyo.">
                            A favor del huésped
                        </span>
                        <span class="text-sm font-black tabular-nums"
                            :class="cuadre.cuadra ? 'text-[#3E6D9C]' : 'text-rose-600'">
                            ≈ {{ importeEn(cuadre.diferencia, cuadre.moneda) }}
                        </span>
                    </span>
                </div>

                <!-- ===== PREPAGO PENDIENTE =====
                     Fila propia y no una cuarta columna del grid: en el drawer las tres
                     cifras ya van justas y una cuarta las partía. Además esto no es un
                     total, es una acción pendiente, y leerlo en la misma hilera que
                     Cargos/Pagado/Saldo invitaba a sumarlo o restarlo — que es justo lo
                     que no hay que hacer con él.

                     Sólo aparece si queda algo por pedir: en cuanto hay un pago registrado
                     el backend deja de mandarlo (ese pago ERA el prepago). -->
                <div v-if="prepago"
                    class="flex items-center justify-between gap-2 px-3 py-2 border-t border-slate-100 bg-[#376875]/5">
                    <span class="min-w-0">
                        <span class="text-[10px] font-black text-[#376875] uppercase tracking-wide">
                            <i class="fas fa-hand-holding-dollar mr-1"></i>Prepago pendiente
                        </span>
                        <span v-if="prepago.politicaEtiqueta"
                            class="block text-[10px] font-bold text-slate-400 leading-tight mt-0.5">
                            {{ prepago.politicaEtiqueta }}
                        </span>
                    </span>
                    <!-- En la moneda de la CABECERA aunque se esté mirando la otra: ver el
                         bloque PREPAGO PENDIENTE del script. -->
                    <span class="flex items-center gap-2 shrink-0">
                        <span class="text-sm font-black text-[#376875] tabular-nums">
                            {{ prepagoImporte }}
                        </span>
                        <button v-if="!cobroRapido && !panelAnulado" type="button"
                            class="px-2 py-1 rounded-md text-[10px] font-black uppercase tracking-wide
                                   bg-[#376875] text-white hover:bg-[#2c545f] transition-colors"
                            title="Registra un cobro por este importe exacto."
                            @click="abrirCobroPrepago()">
                            Cobrar
                        </button>
                    </span>
                </div>

                <!-- La confirmación. No es un «¿seguro?»: el medio de pago es el único dato que no
                     se puede deducir, y de él salen la comisión y la fila de la caja. -->
                <div v-if="cobroRapido"
                    class="flex flex-wrap items-center justify-between gap-2 px-3 py-2.5 border-t
                           border-[#376875]/20 bg-[#376875]/10">
                    <span class="text-[11px] font-bold text-[#376875]">
                        Registrar <b class="font-black">{{ cobroRapidoImporte }}</b> por
                        {{ cobroRapido.etiqueta }}. ¿Cómo pagó?
                        <b v-if="prepagoTotalConComision" class="block font-black text-[10px] text-slate-500 mt-0.5">
                            Se le pasan {{ prepagoTotalConComision }} — {{ prepagoComision }}% de recargo
                        </b>
                    </span>
                    <span class="flex items-center gap-1.5 shrink-0">
                        <select v-model="prepagoMedioPago" :disabled="registrandoPrepago"
                            class="px-2 py-1 rounded-md border border-slate-300 bg-white text-[11px] font-bold
                                   text-slate-700 focus:outline-none focus:ring-2 focus:ring-[#376875]/30">
                            <option v-for="m in finanzas.mediosPago" :key="m.id" :value="m.id">{{ m.label }}</option>
                        </select>
                        <button type="button"
                            class="px-2.5 py-1 rounded-md text-[10px] font-black uppercase tracking-wide
                                   bg-emerald-600 text-white hover:bg-emerald-700 disabled:opacity-50
                                   disabled:cursor-not-allowed transition-colors"
                            :disabled="registrandoPrepago || !prepagoMedioPago"
                            @click="confirmarCobroPrepago()">
                            <i v-if="registrandoPrepago" class="fas fa-spinner fa-spin mr-1"></i>
                            Confirmar
                        </button>
                        <button type="button" :disabled="registrandoPrepago"
                            class="px-2 py-1 rounded-md text-[10px] font-black uppercase tracking-wide
                                   text-slate-500 hover:text-slate-700 disabled:opacity-50"
                            @click="cobroRapido = null">
                            Cancelar
                        </button>
                    </span>
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

                <!-- El CONMUTADOR de moneda se retiró el 16/08/2026. Convertía todo a soles o
                     a dólares para mirarlo; ahora cada moneda tiene su propia fila y no hay
                     nada que conmutar. Con él se fue el aviso de «registros que no suman»:
                     existía porque un registro sin tipo de cambio desaparecía de la suma. -->
                <p class="px-3 py-1.5 text-[10px] font-bold text-slate-400 border-t border-slate-100 bg-white flex items-center justify-between gap-2">
                    <span class="inline-flex items-center gap-1.5">
                        Se cotiza en {{ monedaCabecera?.nombre ?? monedaCabecera?.id ?? 'USD' }}
                        <InfoTooltip lado="izquierda">
                            Cada importe se suma <b class="text-white">en la moneda en que se pactó</b>:
                            soles con soles, dólares con dólares. No se convierte nada, así que lo que ves es
                            exactamente lo que se cobró.
                            <span class="block mt-1.5">
                                Esta moneda es sólo el <b class="text-white">defecto</b> al abrir un cargo
                                nuevo, y la moneda en la que se presenta el cuadre cuando hay dos.
                            </span>
                            <span class="block mt-1.5 text-slate-400">
                                Si el huésped debe en las dos, debe en las dos: no hay un total único, y el
                                cuadre de arriba es la referencia para cerrarlo en una sola.
                            </span>
                        </InfoTooltip>
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
                    <!-- Por moneda, como todo lo demás. Antes era el escalar convertido de la
                         cabecera, y con dos monedas se contradecía con las filas de arriba: la
                         de Pagos llegó a decir «US$ 65.97» de un cobro de S/ 223.70. -->
                    <span class="flex items-center gap-2 text-sm font-black" :class="seccionAbierta === 'cargos' ? 'text-emerald-800' : 'text-slate-700'">
                        <span v-for="t in totalesDeCargos" :key="t.moneda" class="whitespace-nowrap">
                            {{ importeEn(t.cargos, t.moneda) }}
                        </span>
                    </span>
                </button>

                <div v-show="seccionAbierta === 'cargos'" class="border-t border-emerald-100">
                    <!-- 🔒 Candado: sólo protege los cargos sincronizados desde el canal.
                         Los manuales (reservas directas) se editan y borran sin candado. -->
                    <div v-if="!readOnly" class="flex items-center gap-2 px-4 py-2 bg-amber-50 border-b border-amber-100">
                        <label class="flex items-center gap-2 cursor-pointer">
                            <input type="checkbox" v-model="cargosDesbloqueados" class="rounded" />
                            <span class="text-[11px] font-black uppercase tracking-wide"
                                :class="cargosDesbloqueados ? 'text-amber-700' : 'text-slate-500'">
                                <i class="fas" :class="cargosDesbloqueados ? 'fa-lock-open' : 'fa-lock'"></i>
                                {{ cargosDesbloqueados ? 'Edición habilitada' : 'Habilitar edición' }}
                            </span>
                        </label>
                        <!-- El párrafo pasó a la «i»: se lee una vez, y después son dos líneas
                             fijas donde se quiere ver la lista de cargos. -->
                        <InfoTooltip lado="izquierda" clase-icono="text-amber-500 hover:text-amber-700">
                            Protege los cargos <b class="text-white">sincronizados desde el canal</b>: los manda
                            Beds24 y el siguiente pull devolvería a su sitio cualquier cambio.
                            <span class="block mt-1.5 text-slate-400">
                                Los cargos manuales —los de las reservas directas— se editan y se borran siempre,
                                sin abrir esto.
                            </span>
                        </InfoTooltip>
                    </div>

                    <p v-if="!gruposCargos.length && !cargoNuevoAbierto" class="px-4 py-3 text-xs font-bold text-slate-400">
                        Sin cargos registrados.
                    </p>

                    <!-- Un bloque por estancia. Con una sola no se pinta cabecera (sería ruido);
                         en una reserva agrupada distingue cada casita. -->
                    <template v-for="g in gruposCargos" :key="g.clave">
                        <!-- Más alta y con tipografía mayor que antes: aquí vive el icono de
                             canal, y a 10px no se distinguía el azul de Booking del rojo de
                             Airbnb, que es precisamente lo que hay que ver de un vistazo. -->
                        <div v-if="mostrarGrupos"
                            class="flex items-center justify-between gap-2 px-4 py-2.5 bg-slate-50 border-y border-slate-100">
                            <span class="flex items-center gap-2 min-w-0">
                                <i v-if="g.canal" :class="[canalInfo(g.canal).icono, canalInfo(g.canal).color]"
                                    class="text-sm shrink-0" :title="canalInfo(g.canal).texto"></i>
                                <i v-else class="fas fa-door-open text-xs text-slate-400 shrink-0"></i>
                                <span class="text-xs font-black text-slate-700 truncate">{{ g.titulo }}</span>
                                <span v-if="g.subtitulo" class="text-[11px] font-bold text-slate-400 whitespace-nowrap">
                                    · {{ g.subtitulo }}
                                </span>
                            </span>
                            <span class="flex items-center gap-2 text-xs font-black text-slate-700 shrink-0">
                                <!-- Un subtotal por moneda: una estancia puede tener el alojamiento
                                     en dólares y una ampliación en soles, y sumarlos daría una
                                     cifra que no existe. Con una sola moneda se lee igual que antes. -->
                                <span v-for="s in g.subtotales" :key="s.moneda" class="whitespace-nowrap">
                                    {{ importeEn(s.total, s.moneda) }}
                                </span>

                                <!-- 💡 Lo que ESTA estancia costaría según el tarifario.
                                     Va en la cabecera y no pegado a un cargo concreto porque es
                                     de la estancia entera: colgado de una línea, desaparecería
                                     en cuanto alguien borrara esa línea. Sólo aparece en las
                                     directas, que son las que nacen con el cargo en cero. -->
                                <span v-if="g.costoTeorico" class="relative group/teorico">
                                    <i class="fas fa-circle-info text-slate-400 group-hover/teorico:text-[#376875] cursor-help"
                                        aria-label="Coste teórico según el tarifario"></i>

                                    <!-- `right-0` y no centrado: la burbuja nace pegada al borde
                                         derecho del panel y centrada se saldría de la pantalla. -->
                                    <span class="hidden group-hover/teorico:block absolute right-0 top-full mt-1.5 z-30
                                                 w-max max-w-[17rem] rounded-lg bg-slate-800 p-3 text-left shadow-xl">
                                        <span class="block text-[10px] font-black uppercase tracking-wide text-slate-400 mb-2">
                                            Costo teórico · tarifario
                                        </span>

                                        <span v-if="g.costoTeorico.alojamiento" class="flex items-baseline gap-2 text-[11px] text-slate-200 mb-1">
                                            <i class="fas fa-bed w-4 text-center text-slate-400 shrink-0"></i>
                                            <span class="flex-1 font-medium">
                                                <template v-if="g.costoTeorico.alojamiento.porNoche">
                                                    {{ g.costoTeorico.alojamiento.porNoche }} × {{ g.costoTeorico.alojamiento.noches }} N
                                                </template>
                                                <!-- Sin precio por noche = las noches NO valen lo mismo. Se dice,
                                                     en vez de enseñar una media que no multiplica. -->
                                                <template v-else>
                                                    {{ g.costoTeorico.alojamiento.noches }} N (tarifa variable)
                                                </template>
                                            </span>
                                            <span class="font-black text-white whitespace-nowrap">{{ g.costoTeorico.alojamiento.importe }}</span>
                                        </span>
                                        <!-- Alojamiento a null = al tarifario le faltaban noches. NO es cero. -->
                                        <span v-else class="flex items-baseline gap-2 text-[11px] text-amber-300 mb-1">
                                            <i class="fas fa-bed w-4 text-center shrink-0"></i>
                                            <span class="flex-1 font-medium">Sin tarifa para todas las noches</span>
                                        </span>

                                        <span v-if="g.costoTeorico.paxAdicional" class="flex items-baseline gap-2 text-[11px] text-slate-200 mb-1">
                                            <i class="fas fa-user w-4 text-center text-slate-400 shrink-0"></i>
                                            <span class="flex-1 font-medium">
                                                {{ g.costoTeorico.paxAdicional.porPersonaNoche }}
                                                × {{ g.costoTeorico.paxAdicional.personas }} P
                                                × {{ g.costoTeorico.paxAdicional.noches }} N
                                            </span>
                                            <span class="font-black text-white whitespace-nowrap">{{ g.costoTeorico.paxAdicional.importe }}</span>
                                        </span>

                                        <span v-if="g.costoTeorico.limpieza" class="flex items-baseline gap-2 text-[11px] text-slate-200 mb-1">
                                            <i class="fas fa-broom w-4 text-center text-slate-400 shrink-0"></i>
                                            <span class="flex-1 font-medium">
                                                Limpieza<template v-if="g.costoTeorico.limpieza.esPorcentaje"> (%)</template>
                                            </span>
                                            <span class="font-black text-white whitespace-nowrap">{{ g.costoTeorico.limpieza.importe }}</span>
                                        </span>

                                        <span class="flex items-baseline gap-2 text-xs border-t border-slate-600 mt-2 pt-2">
                                            <span class="flex-1 font-black text-slate-300">Total</span>
                                            <span class="font-black text-white whitespace-nowrap">
                                                {{ g.costoTeorico.total }} {{ g.costoTeorico.moneda ?? '' }}
                                            </span>
                                        </span>

                                        <!-- Sin esta línea, el número de arriba se lee como el precio
                                             de la reserva y alguien lo va a teclear tal cual. -->
                                        <span class="block text-[10px] font-medium text-slate-400 mt-2 leading-snug">
                                            Referencia, no el precio acordado: en una venta directa lo cierras tú.
                                        </span>
                                    </span>
                                </span>
                            </span>
                        </div>

                    <!-- 👉 Indentado respecto a la barra de la estancia: es lo que hace visible
                         que estos cargos cuelgan de ESA casita y no de la reserva entera. Con
                         todo al mismo margen, la barra parecía un separador y no una cabecera. -->
                    <div v-for="c in g.cargos" :key="c.id ?? ''" class="pl-6 pr-4 py-3 border-b border-slate-50 last:border-0">
                        <!-- Fila normal -->
                        <div v-if="cargoEditandoId !== c.id" class="flex items-start justify-between gap-3">
                            <div class="min-w-0 flex-1">
                                <div class="flex items-center gap-2 flex-wrap">
                                    <span v-if="tipoCargoOpt(c.tipoCargo)"
                                        class="px-2 py-0.5 rounded-full text-[10px] font-black uppercase tracking-wide"
                                        :class="clasesTipoCargo(tipoCargoOpt(c.tipoCargo)?.color)">
                                        {{ tipoCargoOpt(c.tipoCargo)?.label }}
                                    </span>
                                    <!-- La plantilla sin resolver de Beds24 se oculta: ver
                                         `descripcionVisible()`. Con el tipo de cargo al lado y la
                                         casita en la barra de la estancia, la línea no queda coja. -->
                                    <span v-if="descripcionVisible(c.descripcion)" class="text-xs font-bold text-slate-700 truncate">
                                        {{ descripcionVisible(c.descripcion) }}
                                    </span>
                                    <span v-else-if="!tipoCargoOpt(c.tipoCargo)" class="text-xs font-bold text-slate-400 truncate">
                                        Sin descripción
                                    </span>
                                    <span v-if="c.manual"
                                        class="px-1.5 py-0.5 rounded text-[9px] font-black uppercase tracking-wide bg-slate-100 text-slate-500"
                                        title="Cargo creado manualmente">Manual</span>
                                </div>
                                <!-- Lo que el huésped ve de este cargo en su estado de cuenta.
                                     Hasta ahora sólo se podía leer abriendo el formulario de
                                     edición, así que el operador no sabía sin más si un cargo
                                     estaba explicado o llegaba como una cifra suelta. En cursiva
                                     y con comillas para que no se confunda con `descripcion`,
                                     que es la interna y viene del canal. -->
                                <p v-if="c.descripcionClienteEs"
                                    class="text-[10px] font-bold text-[#376875] italic mt-1 leading-snug">
                                    <i class="fas fa-comment-dots mr-1 not-italic" title="Descripción que ve el huésped"></i>
                                    «{{ c.descripcionClienteEs }}»
                                </p>
                                <p class="text-[10px] font-bold text-slate-400 mt-1">
                                    {{ fechaLegible(c.fechaCreacionBeds24) }}
                                    <template v-if="c.tipoCambio"> · TC {{ c.tipoCambio }}</template>
                                    <!-- El equivalente «≈» en la otra moneda se retiró: era la vista dual.
                                         El importe de un cargo se enseña en la moneda en que se pactó y en
                                         ninguna otra; el cuadre del resumen es el único sitio donde algo se
                                         convierte, y va etiquetado como tal. -->
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
                             su celda. Y con cabecera, por lo mismo que el alta: sin recuadro
                             el formulario se confundía con la fila que estaba editando. -->
                        <!-- ⚠️ NADA de `overflow-hidden` aquí: rompería el pie sticky de abajo
                             (ver la nota del <section> raíz). El redondeo lo pone cada
                             elemento: cabecera arriba, barra de acción abajo. -->
                        <div v-else :ref="setFormCargoRef"
                            class="-mx-1 rounded-xl border-2 border-emerald-300 bg-emerald-50/60 shadow-sm">
                            <div class="px-4 py-2 bg-emerald-100/70 border-b border-emerald-200 rounded-t-[0.6rem] flex items-center gap-2">
                                <i class="fas fa-pen text-emerald-700 text-xs"></i>
                                <h4 class="text-[11px] font-black text-emerald-900 uppercase tracking-wide">Editar cargo</h4>
                            </div>
                        <div class="grid grid-cols-2 gap-2 px-4 py-3">
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
                            <!-- Lo que SÍ ve el huésped. `descripcion` es la interna: en los
                                 cargos de Beds24 viene con lo que mande el canal y no es
                                 presentable. Opcional a propósito: la mayoría de cargos se
                                 explican con su tipo. -->
                            <label class="col-span-2">
                                <span class="text-[11px] font-bold text-slate-500">Descripción para el huésped</span>
                                <!-- El botón de forzar traducción va SÓLO aquí, en la edición:
                                     en un cargo nuevo no hay traducciones que pisar y sería un
                                     control que no hace nada. Mismo botón que el editor de
                                     cotizaciones (fa-language, ámbar cuando está activo). -->
                                <span class="mt-1 flex items-stretch gap-1.5">
                                    <input type="text" v-model="cargoForm.descripcionClienteEs"
                                        placeholder="Ej. Ajuste de redondeo para el cuadre"
                                        class="flex-1 min-w-0 border border-slate-200 rounded-lg px-3 py-2 text-sm bg-white" />
                                    <button type="button" @click="toggleTraduccion"
                                        :class="cargoForm.sobreescribirTraduccion
                                            ? 'bg-orange-100 text-orange-600 border-orange-300'
                                            : 'bg-slate-50 text-slate-400 border-slate-200 hover:bg-slate-100'"
                                        class="px-3 border rounded-lg transition-colors shadow-sm shrink-0"
                                        title="Rehacer las traducciones a partir de este texto al guardar">
                                        <i class="fas fa-language"></i>
                                    </button>
                                </span>
                                <span v-if="cargoForm.sobreescribirTraduccion"
                                    class="mt-1 block text-[10px] font-bold text-orange-600">
                                    Al guardar se rehacen las traducciones desde este texto.
                                </span>
                                <span v-else class="text-[10px] text-slate-400">
                                    Aparece en su estado de cuenta. Se traduce sola la primera vez;
                                    si la corriges, pulsa <i class="fas fa-language"></i> para rehacer los demás idiomas.
                                </span>
                            </label>

                            <label>
                                <span class="text-[11px] font-bold text-slate-500">Importe</span>
                                <input type="text" inputmode="decimal" v-model="cargoForm.totalLinea"
                                    class="mt-1 w-full border border-slate-200 rounded-lg px-3 py-2 text-sm bg-white" />
                            </label>
                            <!-- La moneda de un cargo con importe NO se cambia: está atada al
                                 importe y al TC capturados en ese momento (§12.4). Mientras siga
                                 en 0.00 no hay nada capturado, y ahí sí — que es lo que permite
                                 escribir en soles el precio de una estancia directa. -->
                            <label>
                                <span class="text-[11px] font-bold text-slate-500 inline-flex items-center gap-1.5">
                                    Moneda
                                    <InfoTooltip v-if="!puedeCambiarMoneda(c)" lado="izquierda">
                                        La moneda queda fija al registrar el importe: va atada al tipo de cambio
                                        que se capturó en ese momento, y cambiarla dejaría el cargo diciendo una
                                        cifra que nadie pactó.
                                        <span class="block mt-1.5 text-slate-400">
                                            Si está equivocada, borra el cargo y créalo de nuevo.
                                        </span>
                                    </InfoTooltip>
                                </span>
                                <select v-model="cargoForm.moneda" :disabled="!puedeCambiarMoneda(c)"
                                    @change="autocompletarTipoCambioCargo"
                                    class="mt-1 w-full border border-slate-200 rounded-lg px-3 py-2 text-sm bg-white
                                           disabled:bg-slate-100 disabled:text-slate-400">
                                    <option v-for="m in finanzas.monedas" :key="m.id ?? ''" :value="m.id ?? ''">
                                        {{ m.id }}{{ m.simbolo ? ` (${m.simbolo})` : '' }}
                                    </option>
                                </select>
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
                        <div class="sticky -bottom-4 px-4 py-2.5 bg-emerald-100/95 backdrop-blur
                                    border-t border-emerald-200 rounded-b-[0.6rem] flex items-center justify-end gap-2 z-10">
                            <button type="button" @click="cancelarEdicionCargo"
                                class="px-3 py-2 text-xs font-bold text-slate-500 hover:text-slate-700">Cancelar</button>
                            <button type="button" @click="guardarCargo" :disabled="finanzas.isSaving || !!errorCargo"
                                class="px-4 py-2 bg-[#376875] hover:bg-[#2d5660] disabled:opacity-50 text-white rounded-lg text-xs font-black">
                                <i class="fas" :class="finanzas.isSaving ? 'fa-circle-notch fa-spin' : 'fa-check'"></i> Guardar cargo
                            </button>
                        </div>
                        </div>
                    </div>

                    <!-- Una estancia sin cargos ya no desaparece: su cabecera es donde vive el
                         costo teórico del tarifario, que es justo lo que hace falta ver ANTES de
                         teclear el primer importe. -->
                    <p v-if="!g.cargos.length" class="pl-6 pr-4 py-3 text-xs font-bold text-slate-400 border-b border-slate-50 last:border-0">
                        Sin cargos en esta estancia.
                    </p>
                    </template>

                    <!-- Alta de un cargo manual (reservas directas).
                         Mismo envoltorio que el formulario de pago: el grid va dentro y la barra
                         de acción fuera, o el sticky no tendría recorrido dentro de su celda.

                         Va en un recuadro CON CABECERA porque antes quedaba suelto entre las
                         filas de la lista: no se veía dónde empezaba ni acababa el mini-form,
                         y con la lista llena parecía un cargo más a medio pintar. -->
                    <!-- ⚠️ NADA de `overflow-hidden` aquí: rompería el pie sticky de abajo
                         (ver la nota del <section> raíz). El redondeo lo pone cada
                         elemento: cabecera arriba, barra de acción abajo. -->
                    <div v-if="cargoNuevoAbierto" :ref="setFormCargoRef"
                        class="m-3 rounded-xl border-2 border-emerald-300 bg-emerald-50/60 shadow-sm">
                        <div class="px-4 py-2 bg-emerald-100/70 border-b border-emerald-200 rounded-t-[0.6rem] flex items-center gap-2">
                            <i class="fas fa-file-invoice-dollar text-emerald-700 text-xs"></i>
                            <h4 class="text-[11px] font-black text-emerald-900 uppercase tracking-wide">Nuevo cargo</h4>
                        </div>
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
                        <!-- Lo que SÍ ve el huésped. `descripcion` es la interna: en los
                             cargos de Beds24 viene con lo que mande el canal y no es
                             presentable. Opcional a propósito: la mayoría de cargos se
                             explican con su tipo. -->
                        <label class="col-span-2">
                            <span class="text-[11px] font-bold text-slate-500">Descripción para el huésped</span>
                            <input type="text" v-model="cargoForm.descripcionClienteEs"
                                placeholder="Ej. Ajuste de redondeo para el cuadre"
                                class="mt-1 w-full border border-slate-200 rounded-lg px-3 py-2 text-sm bg-white" />
                            <span class="text-[10px] text-slate-400">Aparece en su estado de cuenta. Se traduce sola.</span>
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
                            <!-- Equivalente en vivo: quita la duda de «¿cuánto es esto en la otra
                                 moneda?» mientras se teclea. Es una AYUDA DE LECTURA: el cargo se
                                 guarda y se suma en su propia moneda, no en ésta. -->
                            <span v-if="equivalenteDelCargo" class="mt-1 block text-[10px] font-bold text-slate-400">
                                Son unos {{ equivalenteDelCargo }} — sólo para hacerte una idea;
                                el cargo se registra en {{ cargoForm.moneda }}.
                            </span>
                        </label>
                        <p v-if="errorCargo" class="col-span-2 text-[10px] font-black text-amber-600 leading-snug">
                            <i class="fas fa-triangle-exclamation mr-1"></i>{{ errorCargo }}
                        </p>
                    </div>

                        <!-- Pie sticky, mismo criterio que el del formulario de pago. -->
                        <div class="sticky -bottom-4 px-4 py-2.5 bg-emerald-100/95 backdrop-blur
                                    border-t border-emerald-200 rounded-b-[0.6rem] flex items-center justify-end gap-2 z-10">
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
                    <span class="flex items-center gap-2 text-sm font-black" :class="seccionAbierta === 'pagos' ? 'text-sky-800' : 'text-emerald-600'">
                        <span v-for="t in totalesDePagos" :key="t.moneda" class="whitespace-nowrap">
                            {{ importeEn(t.pagos, t.moneda) }}
                        </span>
                    </span>
                </button>

                <div v-show="seccionAbierta === 'pagos'" class="border-t border-sky-100">
                    <!-- 🔒 Candado: sólo protege el depósito automático del canal, que el
                         sistema cuadra solo. Los pagos normales se editan sin candado.
                         No se pinta si esta reserva no tiene depósito: sería una advertencia
                         sobre algo que no está en pantalla. -->
                    <p v-if="!finanzas.info.pagos?.length && !pagoFormAbierto" class="px-4 py-3 text-xs font-bold text-slate-400">
                        Sin pagos registrados.
                    </p>

                    <template v-for="b in bloquesPagos" :key="b.clave">
                        <!-- Cabecera de bloque. En el plegable es un botón; en el otro no, para
                             que no invite a pulsar algo que no hace nada. -->
                        <component :is="b.plegable ? 'button' : 'div'"
                            :type="b.plegable ? 'button' : undefined"
                            @click="b.plegable && (pagosAutomaticosAbiertos = !pagosAutomaticosAbiertos)"
                            class="w-full flex items-center justify-between gap-2 px-4 py-2.5 bg-slate-50 border-y border-slate-100 text-left"
                            :class="b.plegable ? 'hover:bg-slate-100 transition-colors' : ''">
                            <span class="flex items-center gap-2 min-w-0">
                                <i v-if="b.plegable" class="fas fa-chevron-right text-[10px] text-slate-400 transition-transform shrink-0"
                                    :class="{ 'rotate-90': pagosAutomaticosAbiertos }"></i>
                                <i :class="b.icono" class="text-xs text-slate-400 shrink-0"></i>
                                <span class="text-xs font-black text-slate-700 truncate">{{ b.titulo }}</span>
                                <span class="text-[11px] font-bold text-slate-400 whitespace-nowrap">({{ b.pagos.length }})</span>
                            </span>
                            <span class="flex items-center gap-2 text-xs font-black text-slate-700 shrink-0">
                                <span v-for="s in subtotalPagos(b.pagos)" :key="s.moneda" class="whitespace-nowrap">
                                    {{ importeEn(s.total, s.moneda) }}
                                </span>
                            </span>
                        </component>

                        <!-- 🔒 El candado vive DENTRO del bloque que protege, y no arriba de la
                             sección: sólo afecta al depósito del canal, y puesto fuera parecía
                             que hacía falta para tocar cualquier cobro —los manuales nunca lo
                             han necesitado—. Al bloque hay que abrirlo para editar de todas
                             formas, así que no esconde nada. -->
                        <div v-if="!readOnly && b.clave === 'automaticos' && bloqueAbierto(b)"
                            class="flex items-center gap-2 pl-6 pr-4 py-2 bg-amber-50 border-b border-amber-100">
                            <label class="flex items-center gap-2 cursor-pointer">
                                <input type="checkbox" v-model="pagosDesbloqueados" class="rounded" />
                                <span class="text-[11px] font-black uppercase tracking-wide"
                                    :class="pagosDesbloqueados ? 'text-amber-700' : 'text-slate-500'">
                                    <i class="fas" :class="pagosDesbloqueados ? 'fa-lock-open' : 'fa-lock'"></i>
                                    {{ pagosDesbloqueados ? 'Edición habilitada' : 'Habilitar edición' }}
                                </span>
                            </label>
                            <InfoTooltip lado="izquierda" clase-icono="text-amber-500 hover:text-amber-700">
                                Protege el <b class="text-white">depósito automático del canal</b>, que el
                                sistema mantiene cuadrado con los cargos en cada recálculo.
                                <span class="block mt-1.5">
                                    Al guardarlo a mano el sistema deja de cuadrarlo y manda tu importe, hasta
                                    que lo devuelvas al automático con la flecha de la fila.
                                </span>
                                <span class="block mt-1.5 text-slate-400">
                                    Los cobros manuales no necesitan candado: se editan siempre.
                                </span>
                            </InfoTooltip>
                        </div>

                    <!-- 👉 Indentado respecto a su cabecera: es lo que hace visible que estas
                         filas cuelgan del bloque de arriba y no de la sección entera. -->
                    <div v-for="p in (bloqueAbierto(b) ? b.pagos : [])" :key="p.id ?? ''"
                        class="pl-6 pr-4 py-3 border-b border-slate-50 last:border-0 flex items-start justify-between gap-3">
                        <div class="min-w-0 flex-1">
                            <p class="text-xs font-bold text-slate-700 flex items-center gap-1.5 flex-wrap">
                                <i class="fas text-slate-400" :class="medioPagoOpt(p.medioPago)?.icono ?? 'fa-money-bill'"></i>
                                {{ medioPagoOpt(p.medioPago)?.label ?? p.medioPago }}
                                <!-- Marca de origen: este pago no lo tecleó nadie, lo generó
                                     un enlace al cobrarse. Sin ella, un pago de US$ 311 junto
                                     a un enlace de US$ 328.11 parece un descuadre, cuando la
                                     diferencia es el recargo de la pasarela. -->
                                <span v-if="enlaceDePago(p.id)"
                                    class="px-1.5 py-0.5 rounded bg-[#376875]/10 text-[#376875] text-[9px] font-black uppercase tracking-wide">
                                    <i class="fas fa-link mr-0.5"></i>
                                    Enlace · {{ enlaceDePago(p.id)?.pasarelaEtiqueta }}
                                </span>
                                <!-- El depósito del canal se distingue a simple vista, y con qué
                                     manda: mientras lo cuadra el sistema, corregirlo es tarea de
                                     los CARGOS; una vez intervenido, la cifra es la del operador
                                     y nadie la va a mover. Sin esta marca, un depósito ajustado a
                                     mano era indistinguible de uno automático descuadrado. -->
                                <span v-if="p.esAutomatico"
                                    class="px-1.5 py-0.5 rounded text-[9px] font-black uppercase tracking-wide"
                                    :class="p.intervenido
                                        ? 'bg-amber-100 text-amber-700'
                                        : 'bg-slate-100 text-slate-500'"
                                    :title="p.intervenido
                                        ? 'Importe fijado a mano: el sistema ya no lo cuadra con los cargos del canal.'
                                        : 'Depósito que genera el sistema: sigue al total de los cargos del canal.'">
                                    <i class="fas mr-0.5" :class="p.intervenido ? 'fa-hand' : 'fa-robot'"></i>
                                    {{ p.intervenido ? 'Ajustado a mano' : 'Depósito del canal' }}
                                </span>
                            </p>
                            <p class="text-[10px] font-bold text-slate-400 mt-1 flex flex-wrap gap-x-2">
                                <span>{{ fechaLegible(p.fechaPago) }}</span>
                                <span v-if="p.tipoCambio">TC {{ p.tipoCambio }}</span>
                                <!-- Cobrado = neto + recargo; el neto es lo que abona la reserva. -->
                                <span v-if="p.comisionPorcentaje && Number(p.comisionPorcentaje) > 0">
                                    +{{ p.comisionPorcentaje }}% · cobrado {{ importeConMoneda(p.montoTotalCobrado, p.moneda) }}
                                </span>
                                <span v-if="p.referencia">#{{ p.referencia }}</span>
                                <!-- Quién lo cobró: es el dato que permite cuadrar la caja de
                                     cada persona, así que se ve sin abrir el formulario. -->
                                <span v-if="p.cobradorNombre" class="text-slate-500">
                                    <i class="fas fa-user text-[9px] mr-0.5"></i>{{ p.cobradorNombre }}
                                </span>
                            </p>
                        </div>
                        <div class="flex items-center gap-2 shrink-0">
                            <span class="text-sm font-black text-emerald-600">{{ importeConMoneda(p.monto, p.moneda) }}</span>
                            <template v-if="!readOnly">
                                <!-- Volver al automático: sólo con el candado abierto, y sólo en
                                     un depósito ya intervenido. Es la marcha atrás de haberlo
                                     fijado a mano, así que vive junto al lápiz y no escondida en
                                     el formulario. -->
                                <button v-if="p.intervenido && pagosDesbloqueados" type="button"
                                    @click="devolverPagoAlAutomatico(p)"
                                    title="Devolver al automático: el sistema volverá a cuadrarlo con los cargos del canal"
                                    class="w-7 h-7 flex items-center justify-center rounded-lg text-amber-500 hover:text-amber-700 hover:bg-amber-50">
                                    <i class="fas fa-rotate-left text-[11px]"></i>
                                </button>
                                <button v-if="puedeEditarPago(p)" type="button" @click="editarPago(p)" title="Editar pago"
                                    class="w-7 h-7 flex items-center justify-center rounded-lg text-slate-400 hover:text-[#376875] hover:bg-slate-100">
                                    <i class="fas fa-pen text-[11px]"></i>
                                </button>
                                <!-- Sin basurero si el backend va a rechazarlo igual: el
                                     depósito automático del canal se regenera solo. Ofrecer
                                     la acción y luego negarla es el sistema peleando contra
                                     el operador. En su lugar, un candado con el motivo. -->
                                <button v-if="p.borrable !== false" type="button" @click="borrarPago(p)"
                                    title="Eliminar pago"
                                    class="w-7 h-7 flex items-center justify-center rounded-lg text-slate-400 hover:text-rose-600 hover:bg-rose-50">
                                    <i class="fas fa-trash text-[11px]"></i>
                                </button>
                                <span v-else :title="p.motivoNoBorrableTexto ?? 'Este pago no se puede eliminar.'"
                                    class="w-7 h-7 flex items-center justify-center text-slate-300 cursor-help">
                                    <i class="fas fa-lock text-[11px]"></i>
                                </span>
                            </template>
                        </div>
                    </div>
                    </template>

                    <!-- Formulario de alta / edición.
                         El envoltorio NO es el grid: la barra de acción sticky va fuera de él.
                         En un CSS grid el bloque contenedor de un `sticky` es su propia celda,
                         que mide exactamente lo que el elemento: sin recorrido, no se pega. -->
                    <!-- ⚠️ NADA de `overflow-hidden` aquí: rompería el pie sticky de abajo
                         (ver la nota del <section> raíz). El redondeo lo pone cada
                         elemento: cabecera arriba, barra de acción abajo. -->
                    <div v-if="pagoFormAbierto" :ref="setFormPagoRef"
                        class="m-3 rounded-xl border-2 border-sky-300 bg-sky-50/60 shadow-sm">
                        <div class="px-4 py-2 bg-sky-100/70 border-b border-sky-200 rounded-t-[0.6rem] flex items-center gap-2">
                            <i class="fas fa-hand-holding-dollar text-sky-700 text-xs"></i>
                            <h4 class="text-[11px] font-black text-sky-900 uppercase tracking-wide">
                                {{ pagoEditandoId ? 'Editar pago' : 'Nuevo pago' }}
                            </h4>
                        </div>
                        <!-- La consecuencia se dice ANTES de guardar, no después: al confirmar,
                             este depósito deja de seguir a los cargos del canal y se queda con
                             la cifra tecleada hasta que se devuelva al automático. -->
                        <p v-if="editandoDepositoAutomatico"
                            class="px-4 py-2 bg-amber-50 border-b border-amber-100 text-[10px] font-bold text-amber-700 leading-snug">
                            <i class="fas fa-triangle-exclamation mr-1"></i>
                            Es el depósito automático del canal. Al guardarlo, el sistema
                            <b>dejará de cuadrarlo</b> con los cargos y mandará tu importe. Se
                            puede devolver al automático con la flecha de la fila.
                        </p>
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

                        <!-- ===== IMPUTACIÓN =====
                             Sale sólo cuando el cobro entra en una moneda donde NO hay nada que
                             deber: ese dinero no puede saldar nada suyo. Es el caso de cobrar
                             por Yape en soles una reserva de Booking en dólares — sin esto la
                             ficha diría «debe US$ 65.97» y «tiene S/ 223.70 a favor», y el
                             huésped pagó y se fue.

                             No se marca solo: quién paga qué es una decisión contable, y a veces
                             esos soles son de verdad otra cosa (una propina, un servicio extra
                             que todavía no se ha cargado). -->
                        <div v-if="monedaAImputar" class="col-span-2 rounded-lg border border-sky-200 bg-sky-50/70 px-3 py-2.5">
                            <label class="flex items-start gap-2 cursor-pointer">
                                <input type="checkbox" class="mt-0.5 rounded"
                                    :checked="pagoForm.monedaSaldada === monedaAImputar"
                                    @change="pagoForm.monedaSaldada = pagoForm.monedaSaldada === monedaAImputar ? '' : monedaAImputar" />
                                <span class="min-w-0">
                                    <span class="block text-[11px] font-black text-sky-900">
                                        Este cobro salda la deuda en {{ monedaAImputar }}
                                    </span>
                                    <span class="block text-[10px] font-bold text-sky-700/80 leading-snug mt-0.5">
                                        En {{ pagoForm.moneda }} no hay ningún cargo, así que este dinero no puede
                                        saldar nada suyo.
                                        <template v-if="importeImputado">
                                            Al cambio {{ pagoForm.tipoCambio }} abonaría
                                            <b>{{ importeImputado }}</b>.
                                        </template>
                                    </span>
                                    <span class="block text-[10px] font-medium text-sky-700/60 leading-snug mt-1">
                                        Déjalo sin marcar si de verdad es otra cosa —una propina, un extra que
                                        todavía no se ha cargado—: entonces queda como saldo a favor en
                                        {{ pagoForm.moneda }}.
                                    </span>
                                </span>
                            </label>
                        </div>
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
                            <!-- Se congela una vez puesto, igual que en los cargos: el backend
                                 sólo permite RELLENAR el que está vacío (§12.4), así que un
                                 campo abierto sobre un TC ya guardado es un rechazo asegurado
                                 con el botón de guardar bien visible. Editable mientras esté
                                 vacío, que es la reparación legítima. -->
                            <input type="text" inputmode="decimal" v-model="pagoForm.tipoCambio" placeholder="Ej. 3.750"
                                :disabled="tipoCambioPagoCongelado"
                                class="mt-1 w-full border border-slate-200 rounded-lg px-3 py-2 text-sm"
                                :class="tipoCambioPagoCongelado ? 'bg-slate-100 text-slate-500' : 'bg-white'" />
                            <span v-if="tipoCambioPagoCongelado" class="mt-1 block text-[10px] font-bold text-slate-400">
                                <i class="fas fa-lock text-[9px] mr-1"></i>
                                Es la foto del día en que se registró el importe: no se corrige.
                            </span>
                            <span v-else-if="monedaPagoEsExtranjera && !pagoForm.tipoCambio"
                                class="mt-1 block text-[10px] font-bold text-amber-600">
                                <i class="fas fa-triangle-exclamation text-[9px] mr-1"></i>
                                Sin tipo de cambio este pago no suma al saldo (moneda distinta a la reserva).
                            </span>
                            <span v-else-if="!pagoForm.tipoCambio" class="mt-1 block text-[10px] font-bold text-slate-400">
                                Guárdalo aunque coincida la moneda: si mañana se cambia la moneda base
                                de la reserva, sin él este pago dejaría de sumar.
                            </span>
                        </label>
                        <!-- Quién RECIBIÓ el dinero, que NO es quien lo está registrando.
                             La lista son los usuarios con ROLE_COBRADOR y llega del backend
                             (PmsEnumAjaxController::getCobradores), sin filtrar por `enabled`:
                             la limpiadora que cobra en la casita no tiene login y aun así
                             tiene que poder elegirse. -->
                        <label class="col-span-2">
                            <span class="text-[11px] font-bold text-slate-500">Lo cobró</span>
                            <select v-model="pagoForm.cobrador"
                                class="mt-1 w-full border border-slate-200 rounded-lg px-3 py-2 text-sm bg-white">
                                <option value="">— Sin especificar —</option>
                                <option v-for="c in finanzas.cobradores" :key="c.id" :value="c.id">{{ c.label }}</option>
                            </select>
                            <span v-if="!finanzas.cobradores.length" class="mt-1 block text-[10px] font-bold text-amber-600">
                                <i class="fas fa-triangle-exclamation text-[9px] mr-1"></i>
                                Nadie tiene el rol de cobrador todavía. Se asigna desde el panel de usuarios.
                            </span>
                            <span v-else class="mt-1 block text-[10px] font-bold text-slate-400">
                                Quién recibió el dinero de manos del huésped, no quién lo apunta aquí.
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
                        <div class="sticky -bottom-4 px-4 py-2.5 bg-sky-100/95 backdrop-blur
                                    border-t border-sky-200 rounded-b-[0.6rem] flex items-center justify-end gap-2 z-10">
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

                    <!-- Cobro por pasarela. Va DENTRO de "Pagos" porque para el operador es
                         otra forma de que entre dinero, pero el módulo es Finanzas, no el PMS:
                         se comunica por origenTipo/origenId (ver el componente).

                         Se le pasan los saldos POR MONEDA: una pasarela cobra en una divisa, así
                         que con deuda en dos se ofrece un atajo por cada una y cada enlace pide
                         exactamente lo que se debe en ella. Convertir para dar «un total» sería
                         deshacer lo que este rediseño vino a arreglar.

                         Al confirmarse un cobro, el webhook crea el PmsPagoFinanciero en el
                         backend; `recargar()` es lo que lo trae a esta lista. -->
                    <!-- El prepago viaja para los ATAJOS de importe. Es `null` en cuanto hay un
                         pago registrado, y de eso depende que el atajo ofrezca «adelanto +
                         total» o sólo «saldo»: ver los presets del componente. -->
                    <ReservaEnlacesPagoSection
                        origen-tipo="pms_reserva"
                        :origen-id="props.reservaId"
                        :saldos="saldosParaCobrar"
                        :prepago="prepago"
                        :moneda-simbolo="monedaCabecera?.simbolo"
                        :read-only="readOnly"
                        @actualizado="finanzas.recargar()" />
                </div>
            </div>
        </template>

        </div>
    </section>
</template>
