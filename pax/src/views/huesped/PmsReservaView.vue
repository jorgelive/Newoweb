<script setup lang="ts">
/**
 * src/views/huesped/PmsReservaView.vue
 */
import { ref, computed, nextTick, onMounted, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { usePmsReservaStore } from '@/stores/huesped/paxHuespedReservaStore.ts';
import { useMaestroStore } from '@/stores/maestroStore';
import type { PmsEventoCalendario } from '@/types/paxHuespedModel';

const props = defineProps<{
  localizador?: string;
}>();

const pmsStore = usePmsReservaStore();
const maestroStore = useMaestroStore();
const router = useRouter();
const route = useRoute();

const isReady = ref(false);

// --- BUSCADOR ---
const codigoBusqueda = ref('');

const buscarReserva = () => {
  const loc = codigoBusqueda.value.trim().toUpperCase();
  if (loc) {
    router.push({ name: 'pms_reserva', params: { localizador: loc } });
  }
};

const cargar = async () => {
  isReady.value = false;
  try {
    await maestroStore.cargarConfiguracion();
    if (props.localizador) {
      await pmsStore.cargarReserva(props.localizador);
    }
  } catch (error) {
    console.error("Error en carga inicial:", error);
  } finally {
    isReady.value = true;
  }
};

/**
 * Al montar se carga y, si la URL trae ancla de cuenta, se lleva la tarjeta a la vista.
 *
 * ⚠️ El `watch` del hash NO cubre este caso: llegando por enlace, el hash ya está puesto
 * y nunca *cambia*, así que no dispara. Es el camino más frecuente —el huésped abre el
 * enlace que le mandamos por WhatsApp— y era justo el que se quedaba sin desplazar.
 *
 * Va DESPUÉS de `cargar()` porque la sección se pinta con `v-if="finanzas"`: antes de que
 * llegue la reserva no hay nada a lo que desplazarse.
 */
onMounted(async () => {
    await cargar();

    if (route.hash === '#' + CUENTA_RESUMEN || route.hash === '#' + CUENTA_DETALLE) {
        await enfocarCuenta();
    }
});

// 🔥 Recarga al cambiar el localizador (el buscador hace push sobre la misma ruta)
watch(() => props.localizador, cargar);

// Los dos llegan opcionales en el schema (no están en `required`), así que la firma
// lo admite y normaliza aquí en vez de obligar a cada llamada a rellenarlo.
const formatearOcupacion = (adultosRaw?: number | null, ninosRaw?: number | null) => {
  const adultos = adultosRaw ?? 0;
  const ninos = ninosRaw ?? 0;
  const labelAdultos = adultos === 1
      ? (maestroStore.t('res_adulto') || 'Adulto')
      : (maestroStore.t('res_adultos') || 'Adultos');

  let texto = `${adultos} ${labelAdultos}`;

  if (ninos && ninos > 0) {
    const labelNinos = ninos === 1
        ? (maestroStore.t('res_nino') || 'Niño')
        : (maestroStore.t('res_ninos') || 'Niños');

    texto += ` y ${ninos} ${labelNinos}`;
  }

  return texto;
};

// --- HELPER PARA FECHAS (Forzando GMT-5 / Lima) ---
const formatearFecha = (fechaStr: string) => {
  if (!fechaStr) return '--';
  const fecha = new Date(fechaStr);

  return fecha.toLocaleDateString(maestroStore.idiomaActual, {
    day: '2-digit',
    month: 'short',
    year: 'numeric',
    timeZone: 'America/Lima' // 🔥 Forzamos zona horaria de Cusco
  });
};

// --- HELPER PARA HORAS (Forzando GMT-5 / Lima) ---
const formatearHora = (fechaStr: string) => {
  if (!fechaStr) return '';
  const fecha = new Date(fechaStr);

  // Formato: 03:00 PM (Siempre en hora Perú)
  return fecha.toLocaleTimeString(maestroStore.idiomaActual, {
    hour: '2-digit',
    minute: '2-digit',
    hour12: true,
    timeZone: 'America/Lima' // 🔥 Importante: Evita que el navegador del turista cambie la hora
  });
};

/**
 * Abre la guía de UNA estancia.
 *
 * Va por `{localizador}/{slug de la unidad}`, no por el UUID del evento: el
 * identificador que ve el cliente es el localizador que ya recibió por correo
 * (docs/PmsGuiaHuesped.md §5). La ruta `guia_evento` del contrato anterior se
 * borró con él — apuntar ahí reventaba el router con "No match for…".
 *
 * Sin slug (unidad creada antes de PmsSlugListener) se usa la ruta corta
 * `/huesped/reserva/{loc}/guia`: el backend devuelve entonces la primera
 * estancia cronológica, que en una reserva de una sola casita es ésta misma.
 */
const verGuiaEvento = (evento: PmsEventoCalendario) => {
  const slug = evento.pmsUnidad?.slug;
  router.push(
      slug
          ? { name: 'guia_huesped', params: { localizador: props.localizador, unidad: slug } }
          : { name: 'guia_huesped_corta', params: { localizador: props.localizador } },
  );
};

/* ─────────────────────────────────────────────────────────────
 * ESTADO DE CUENTA
 * El backend manda solo el agregado (PmsReservaPaxProvider): total, adelanto
 * y saldo en la moneda de la cabecera. Aquí solo se presenta.
 * ───────────────────────────────────────────────────────────── */

/**
 * Recargo por pago con tarjeta, en %. Regla comercial del establecimiento
 * (comisión de la pasarela): se calcula SOLO para mostrar — el cobro real lo
 * hace recepción. Si algún día cambia, este es el único sitio.
 */
const RECARGO_TARJETA_PCT = 5.5;

const finanzas = computed(() => pmsStore.reserva?.resumenFinanciero ?? null);

const finTotal  = computed(() => Number(finanzas.value?.total ?? 0));
const finPagado = computed(() => Number(finanzas.value?.pagado ?? 0));
const finSaldo  = computed(() => Number(finanzas.value?.saldo ?? 0));

/**
 * Reserva de un canal que cobra por nosotros y sin extras añadidos a mano: no
 * hay ninguna cifra que se le pueda enseñar al huésped (los importes del canal
 * son lo que la OTA nos remite, no lo que él pagó). Se deja solo la barra llena
 * como acuse de recibo. Lo decide el backend, no la vista.
 */
const soloProgreso = computed(() => finanzas.value?.soloProgreso === true);

/**
 * El resumen ya decidido por `PmsSituacionDeCobro` (backend).
 *
 * ⚠️ **Aquí no se decide nada ni se calcula nada.** Ni qué se pide, ni qué medios valen, ni
 * cuánto sale con tarjeta: todo llega resuelto. Es la mitad del punto de tener una fuente
 * única — si esta vista volviera a multiplicar por 1.055, habría dos verdades otra vez.
 */
const situacion = computed(() => finanzas.value?.situacion ?? null);

/**
 * El grupo de la TARJETA, que es el único con recargo.
 *
 * Se separa del resto porque cuesta otra cosa: dentro del cuadro obligaría a leer dos cifras
 * y a decidir cuál es «la» cifra. Fuera, con su asterisco, se entiende que es una variante.
 */
const conTarjeta = computed(() =>
    situacion.value?.medios.find(g => g.recargoPorcentaje) ?? null);

/**
 * Los medios que valen el importe del cuadro, enumerados y **traducidos por código**.
 *
 * ⚠️ La `etiqueta` que manda el backend sale de `FinMedioCobroTipo::label()` y está en
 * español. `pax` tiene los suyos en `UiI18n` (`res_medio_yape`, `res_medio_efectivo`…), así
 * que se resuelve por CÓDIGO y la etiqueta queda de respaldo: sin esto, un huésped que lee
 * en inglés veía «Transferencia bancaria».
 */
/**
 * El enlace de pago que corresponde a lo que se está pidiendo.
 *
 * ⚠️ **No es siempre el primero.** Pueden convivir dos vigentes —el del adelanto y el del
 * total, y los dos son legítimos— y el banner que había arriba los pintaba los dos, uno por
 * enlace. Al quedar un solo botón, mandar al primero sin mirar podía llevar al huésped a
 * pagar un importe distinto del que la tarjeta le acaba de enseñar.
 *
 * Se elige por IMPORTE: el enlace cobra el total con recargo, que es justamente la cifra de
 * `conTarjeta`. Si ninguno cuadra —el equipo emitió otra cosa— se cae al primero, que es lo
 * que había antes, en vez de dejar al huésped sin botón.
 */
const enlaceDelImporte = computed(() => {
    const enlaces = enlacesPago.value;

    if (enlaces.length === 0) return null;

    const objetivo = conTarjeta.value?.importe;
    const exacto = objetivo
        ? enlaces.find(e => Number(e.montoTotal) === Number(objetivo))
        : undefined;

    return exacto ?? enlaces[0];
});

/**
 * Si el huésped ya abrió ALGUNA ficha en esta visita.
 *
 * Apaga el latido de las «i». El parpadeo es una señal de descubrimiento —«esto se puede
 * pulsar»— y una vez descubierto deja de ser información y pasa a ser insistencia, encima
 * en la pantalla donde le estamos pidiendo dinero.
 *
 * No se persiste entre visitas a propósito: quien vuelve una semana después a buscar el
 * número de cuenta agradece la pista otra vez, y guardarlo obligaría a decidir cuánto dura.
 */
const yaDescubrio = ref(false);

/** Abre o cierra la ficha de un medio, y calla el latido para siempre. */
function alternarFicha(codigo: string): void {
    fichaAbierta.value = fichaAbierta.value === codigo ? null : codigo;
    yaDescubrio.value = true;
}

/**
 * Qué ficha de medio está abierta, si alguna. `null` = ninguna.
 *
 * Uno a la vez: dos cuadros de cuentas abiertos a la vez en un móvil es una pantalla de
 * números donde había que elegir uno.
 */
const fichaAbierta = ref<string | null>(null);

/** Los datos del medio abierto, ya listos para pintar. */
const fichasDelAbierto = computed(() => {
    const codigo = fichaAbierta.value;

    if (!codigo) return [];

    const grupo = situacion.value?.medios.find(g => g.codigos.includes(codigo));

    return grupo?.fichas?.[codigo] ?? [];
});

/**
 * El titular, cuando es el MISMO en todas las fichas del medio abierto.
 *
 * «Transferencia bancaria» son ocho cuentas —cuatro bancos por dos monedas— y todas están a
 * nombre de la misma persona. Repetirlo ocho veces convierte el cuadro en una pared: se dice
 * una vez al pie y ya. Si algún día hubiera dos titulares distintos, devuelve `null` y cada
 * ficha vuelve a decir el suyo.
 */
const titularComun = computed(() => {
    const nombres = new Set(fichasDelAbierto.value.map(f => f.titular ?? ''));

    return nombres.size === 1 ? (fichasDelAbierto.value[0]?.titular ?? null) : null;
});

/**
 * La nota de uso, cuando es la MISMA en todas las fichas del medio abierto.
 *
 * Es el caso normal: la nota describe cómo se usa el medio —«envío en efectivo para recojo en
 * tienda», no «no lo mandes a una cuenta»— y eso no cambia entre las ocho cuentas de un banco.
 * Sacándola de la columna de los números se lee como lo que es: una frase, alineada a la
 * izquierda y a lo ancho, en vez de tres renglones estrechos junto a un importe.
 */
const notaComun = computed(() => {
    // Se traduce AQUÍ y no en el servidor: el idioma lo manda el selector de la ficha, no el
    // `idioma` que se dedujo al crear la reserva. Hay reservas con `en` guardado que se están
    // leyendo en castellano, y resolverlo en PHP metería un párrafo en inglés en una tarjeta
    // española. Es lo mismo que hace la guía con esta misma nota.
    const textos = fichasDelAbierto.value.map(f => maestroStore.traducir(f.nota));
    const distintos = new Set(textos);

    return distintos.size === 1 && textos[0] ? textos[0] : null;
});

/** El nombre de un medio por su código, traducido, con respaldo a la etiqueta del catálogo. */
function nombreDeMedio(codigo: string): string {
    const grupo = situacion.value?.medios.find(g => g.codigos.includes(codigo));
    const i = grupo?.codigos.indexOf(codigo) ?? -1;

    return maestroStore.t('res_medio_' + codigo) || (i >= 0 ? grupo?.etiquetas[i] : '') || codigo;
}

/**
 * El título del cuadro abierto: TODOS los medios que comparten esa misma cuenta.
 *
 * Yape y Plin son dos entradas del catálogo con el mismo número —son dos apps sobre el mismo
 * teléfono—, y el cuadro se titulaba con el que se hubiera pulsado. Quien abría por Yape leía
 * «YAPE» y no tenía forma de saber que ese número también le vale por Plin, que es justo lo
 * que el huésped quiere saber cuando sólo tiene una de las dos apps.
 *
 * Se agrupa comparando la FICHA, no una lista de pares conocidos: el día que dos cuentas dejen
 * de coincidir, el título se separa solo.
 */
const nombreDelAbierto = computed(() => {
    const codigo = fichaAbierta.value;

    if (!codigo) return '';

    const grupo = situacion.value?.medios.find(g => g.codigos.includes(codigo));
    const mia = JSON.stringify(grupo?.fichas?.[codigo] ?? []);

    const hermanos = (grupo?.codigos ?? [])
        .filter(c => JSON.stringify(grupo?.fichas?.[c] ?? []) === mia);

    return (hermanos.length ? hermanos : [codigo]).map(nombreDeMedio).join(' / ');
});

/**
 * Los medios que valen el importe SIN recargo, uno a uno.
 *
 * Devuelve una lista y no una cadena unida porque cada uno lleva ahora su «i»: la que
 * abre sus cuentas. Con `.join(' · ')` no había dónde colgarla.
 */
/**
 * Cadena i18n del rótulo «en qué moneda es esta cuenta», por código ISO.
 *
 * Con palabras y no con el símbolo: en la columna del importe «S/.» es lo correcto, pero como
 * rótulo de una fila —«S/.  +51 958191965»— se lee como el prefijo de un precio que no está.
 * Una moneda que no esté aquí cae a su símbolo, que es peor rótulo pero sigue diciendo algo.
 */
const ETIQUETA_MONEDA: Record<string, string> = {
    PEN: 'res_en_soles',
    USD: 'res_en_dolares',
};

/** El rótulo de moneda de una ficha: «En soles», o el símbolo si no hay cadena. */
function rotuloMoneda(codigo?: string, simbolo?: string): string {
    const clave = codigo ? ETIQUETA_MONEDA[codigo.toUpperCase()] : undefined;

    return (clave ? maestroStore.t(clave) : '') || simbolo || codigo || '';
}

const mediosSinTarjeta = computed(() => {
    const grupo = situacion.value?.medios.find(g => !g.recargoPorcentaje);

    if (!grupo) return [];

    return grupo.codigos.map((codigo, i) => ({
        codigo,
        etiqueta: maestroStore.t('res_medio_' + codigo) || grupo.etiquetas[i] || codigo,
        // Sin ficha no hay «i»: un icono que abre un cuadro vacío enseña a no pulsarlo.
        // Efectivo es el caso normal — se paga en recepción, no hay número que dar.
        tieneFicha: (grupo.fichas?.[codigo]?.length ?? 0) > 0,
    }));
});

/**
 * ¿La cuenta está cerrada?
 *
 * ⚠️ Lo dice el backend (`PmsTotalesPorMoneda::cuadra()`), que es la pregunta **con
 * tolerancia**. El `saldo <= 0` que había aquí dejaba a XTHRMQ anunciando «SALDO PENDIENTE
 * ≈ S/. 0,10» encima de un bloque que decía «no queda nada pendiente»: diez céntimos no son
 * una deuda, son que el cambio del mostrador no es el de SUNAT.
 *
 * El `??` cubre un payload viejo servido de caché; el criterio bueno es el de arriba.
 */
const todoPagado = computed(() =>
    soloProgreso.value || (finanzas.value?.cuadra ?? finSaldo.value <= 0)
);

/** Saldo pagando con tarjeta: saldo + comisión, redondeado a 2 decimales. */
const finSaldoTarjeta = computed(() =>
    Math.round(finSaldo.value * (1 + RECARGO_TARJETA_PCT / 100) * 100) / 100
);

/** % pagado para la barra de progreso (acotado a [0, 100]). */
const pctPagado = computed(() => {
  if (soloProgreso.value) return 100;
  if (finTotal.value <= 0) return 0;
  return Math.min(100, Math.max(0, (finPagado.value / finTotal.value) * 100));
});

/* ─────────────────────────────────────────────────────────────
 * DESGLOSE
 * El backend agrupa por TIPO (`PmsTipoCargo`) y manda el valor del enum, no la
 * descripción: las descripciones llegan de Beds24 en un idioma y no se pueden
 * traducir. Aquí el tipo se convierte en etiqueta i18n.
 * ───────────────────────────────────────────────────────────── */

/** Etiquetas de respaldo en español; la traducción real vive en `pax_ui_i18n`. */
const CARGO_FALLBACK: Record<string, string> = {
  alojamiento:  'Alojamiento',
  limpieza:     'Limpieza',
  servicio:     'Servicio',
  penalizacion: 'Penalización',
  otro:         'Otros',
};

const MEDIO_FALLBACK: Record<string, string> = {
  efectivo:               'Efectivo',
  plin_yape:              'Plin / Yape',
  tarjeta_credito:        'Tarjeta de crédito',
  western_union:          'Western Union',
  transferencia_bancaria: 'Transferencia bancaria',
  paypal:                 'PayPal',
};

const MEDIO_ICONO: Record<string, string> = {
  efectivo:               'fa-money-bill-wave',
  plin_yape:              'fa-mobile-screen-button',
  tarjeta_credito:        'fa-credit-card',
  western_union:          'fa-building-columns',
  transferencia_bancaria: 'fa-right-left',
  paypal:                 'fa-paypal',
};

const nombreCargo = (tipo: string): string =>
    maestroStore.t(`res_cargo_${tipo}`) || CARGO_FALLBACK[tipo] || tipo;

const nombreMedio = (medio: string): string =>
    maestroStore.t(`res_medio_${medio}`) || MEDIO_FALLBACK[medio] || medio;

const iconoMedio = (medio: string): string => MEDIO_ICONO[medio] || 'fa-receipt';

/**
 * Detalle línea a línea; el orden lo fija el backend (secuencia de lo cobrado).
 *
 * Antes se pintaba `cargos`, agrupado por tipo, y un ajuste de cuadre de −0.20 quedaba
 * sumado dentro de «Otros» sin forma de saber qué era. `lineas` trae cada cargo con su
 * descripción redactada para el huésped, cuando la tiene.
 */
const cargosDetalle = computed(() => finanzas.value?.lineas ?? []);

// ============================================================================
// CONMUTADOR A SOLES — REFERENCIAL
// ----------------------------------------------------------------------------
// El backend manda UN tipo de cambio (el del día) para toda la tarjeta, no el
// congelado de cada cargo: con los históricos las líneas no sumarían el total
// convertido. Por eso es referencial y hay que decirlo en pantalla — no es lo
// que se cobró ni lo que se va a cobrar.
//
// Si el backend no manda tipo de cambio (cabecera ya en soles, o no hay TC del
// día) el conmutador sencillamente no existe.
// ============================================================================
const verEnSoles = ref(false);

const tcReferencial = computed(() => Number(finanzas.value?.tipoCambioReferencial ?? 0));
const hayReferencia = computed(() => tcReferencial.value > 0);
const enSoles = computed(() => verEnSoles.value && hayReferencia.value);

/** Símbolo de la moneda en la que se cobra de verdad (la de la cabecera). */
const simboloCobro = computed(() => finanzas.value?.simbolo || finanzas.value?.moneda || '');

/** Símbolo de la moneda referencial (soles). Vacío si el backend no manda TC. */
const simboloReferencia = computed(
    () => finanzas.value?.simboloReferencial || finanzas.value?.monedaReferencial || ''
);

const pagosDetalle = computed(() => finanzas.value?.pagos ?? []);

/** Fecha corta del pago: 'YYYY-MM-DD' -> '15 jun 2026' en el idioma activo. */
const fechaPago = (iso: string | null): string => {
  if (!iso) return '';
  const [y, m, d] = iso.split('-').map(Number);
  if (!y || !m || !d) return '';
  // Se construye en UTC y se lee en UTC: la fecha es un día natural, no un
  // instante, y en zonas negativas `new Date('2026-06-15')` retrocede un día.
  return new Date(Date.UTC(y, m - 1, d)).toLocaleDateString(maestroStore.idiomaActual, {
    day: 'numeric', month: 'short', year: 'numeric', timeZone: 'UTC',
  });
};

const formatMonto = (v: number): string => {
  // Único sitio que formatea importes, así que el conmutador se aplica aquí y la
  // tarjeta entera cambia de moneda a la vez: total, pagado, saldo y cada línea.
  //
  // ⚠️ El conmutador sólo existe con UNA moneda (el backend no manda `tipoCambioReferencial`
  // cuando hay dos), así que aquí nunca se convierte una tarjeta mixta. Ver `porMoneda`.
  const importe = enSoles.value ? v * tcReferencial.value : v;
  const simbolo = enSoles.value ? simboloReferencia.value : simboloCobro.value;

  return `${simbolo} ${importe.toLocaleString(maestroStore.idiomaActual, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
};

/**
 * Importe con el símbolo de SU moneda. No pasa por el conmutador.
 *
 * Lo usa el desglose por moneda: ahí cada fila dice lo que se pactó en ella, y convertirla
 * sería deshacer justo lo que ese desglose vino a arreglar.
 */
const montoEnMoneda = (valor: string, simbolo?: string | null): string =>
  `${simbolo ?? ''} ${Number(valor).toLocaleString(maestroStore.idiomaActual, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`.trim();

/**
 * ¿Esta reserva tiene movimiento en más de una moneda?
 *
 * Con dos, las cifras del titular son el CUADRE —convertido— y hay que marcarlas como
 * aproximadas. El detalle exacto sale en su propio bloque.
 */
const mixta = computed(() => finanzas.value?.mixta === true);

/**
 * Un cruce de monedas YA SALDADO: pagó en una moneda una cuenta emitida en otra.
 *
 * Es el caso de un peruano que yapea soles contra una reserva en dólares, y es frecuente. La
 * contabilidad va por moneda y sin convertir (§12.2b), así que las dos líneas son ciertas por
 * separado —`PEN −223.70`, `USD +65.97`— y **juntas no son dos deudas**: son las dos mitades de
 * una misma transacción cerrada.
 *
 * Sin esto la tarjeta se contradecía: arriba «saldo 0.00, todo pagado» y tres centímetros más
 * abajo el mismo importe en el naranja que esta tarjeta usa para lo que se debe. El huésped no
 * tiene por qué saber cuál de las dos cifras manda.
 *
 * ⚠️ **Lo decide el backend** (`PmsTotalesPorMoneda::sugiereImputacion()`), no esta vista. Aquí
 * llegó a estar como `mixta && todoPagado`, y ese `saldo <= 0` es una SEGUNDA vara de medir el
 * mismo dinero: deja fuera los cruces con residuo —el cambio del mostrador nunca es el de
 * SUNAT— y XTHRMQ, que cuadra con 0.10 de diferencia, seguía pintando 176.90 en naranja sobre
 * una cuenta cerrada. La tolerancia vive en un solo sitio, con su calibración y su porqué.
 */
const cruceSaldado = computed(() => finanzas.value?.cruceSaldado === true);

/** Las filas por moneda, sólo cuando de verdad hay más de una que enseñar. */
const porMoneda = computed(() => (mixta.value ? finanzas.value?.porMoneda ?? [] : []));

/** `≈` delante de las cifras del titular cuando vienen convertidas. */
const marcaAprox = computed(() => (mixta.value ? '≈ ' : ''));

/**
 * Las dos caras del estado de cuenta: RESUMEN (por defecto) y DETALLADO.
 *
 * ── Un toggle, y no dos pestañas ────────────────────────────────────────────
 * El plegable «Mostrar más» tenía un problema real —**no se puede enlazar**— y se
 * probó a resolverlo con pestañas. Fue peor: elegir «Detallado» **escondía el
 * resumen**, y el resumen es justo lo que hay que tener delante mientras se mira el
 * desglose, porque es el total al que se refieren esas líneas.
 *
 * Así que se vuelve al plegable y se le añade lo único que le faltaba: el ancla. A
 * primera vista sólo el resumen; el detalle se AÑADE debajo cuando se pide.
 *
 * El ancla viaja en el enlace, y **las dos son explícitas**:
 *
 *   /huesped/reserva/{localizador}#resumen    → Resumen
 *   /huesped/reserva/{localizador}#detalle    → Detallado
 *
 * `#resumen` podría sobrar —es el estado por defecto— y aun así se manda: el enlace
 * tiene que decir a qué lleva. Quien lo recibe por WhatsApp no ve la página, ve la
 * URL; y quien lo pega en un mensaje meses después no tiene que acordarse de que
 * «sin hash» significaba resumen.
 *
 * Eso es lo que hace que **la plantilla de WhatsApp no necesite variantes**: fuera
 * de la ventana de 24 h el mensaje aprobado es un empujón con un botón de URL, y
 * lo único que cambia entre «te recuerdo que debes» y «aquí tienes el desglose»
 * es el `#` de esa URL. Ver docs/Mensajeria.md.
 *
 * ── ⚠️ El ancla también TRAE LA TARJETA A LA VISTA ──────────────────────────
 * El router de `pax` no declara `scrollBehavior`, así que un hash abre la pestaña
 * correcta y deja al huésped mirando el principio de la página — la cuenta es una
 * sección entre varias. Se resuelve aquí y no en el router a propósito: un
 * `scrollBehavior` global cambiaría el comportamiento de todas las rutas de `pax`
 * para arreglar una.
 *
 * Sólo se desplaza cuando el hash viene en la URL. Entrar sin hash deja la página
 * arriba, que es donde el huésped espera empezar.
 *
 * ── El «atrás» del móvil ────────────────────────────────────────────────────
 * Cambiar de pestaña usa `replace`, no `push`: con `push`, el botón atrás del
 * móvil alternaría pestañas en vez de salir de la reserva, que es lo que el
 * huésped espera de él.
 */
const CUENTA_RESUMEN = 'resumen';
const CUENTA_DETALLE = 'detalle';

const cuentaRef = ref<HTMLElement | null>(null);

/**
 * DOS estados, no tres: el resumen se ve **siempre** y el detalle se despliega.
 *
 * Hubo un peldaño intermedio —la tarjeta cerrada enseñando sólo la barra— que tenía sentido
 * cuando el «resumen» era el desglose entero y en móvil empujaba las unidades fuera de
 * pantalla. Al comprimirlo a un cuadro de tres renglones dejó de tenerlo: esconder detrás de
 * un botón lo único que pide una acción era esconderla por costumbre. Y quedaban **dos
 * toggles seguidos** —«Ver detalle» y «Mostrar menos»—, que es una pregunta de más para la
 * misma acción.
 *
 * Queda uno. Lo que cambia entre resumen y detalle son los **subtotales**: el resumen da una
 * cifra por precio; el detalle la abre en cargos, pagos, tipo de cambio y comisión.
 */
const detalleCuentaAbierto = ref(route.hash === '#' + CUENTA_DETALLE);

/** El hash manda al entrar: es lo que permite enlazar directamente a uno de los dos. */
watch(() => route.hash, (h) => {
    if (h !== '#' + CUENTA_RESUMEN && h !== '#' + CUENTA_DETALLE) return;

    detalleCuentaAbierto.value = h === '#' + CUENTA_DETALLE;
    void enfocarCuenta();
});

/**
 * Lleva la tarjeta de cuenta a la vista.
 *
 * `nextTick` porque al llegar con hash la sección puede no estar todavía en el DOM:
 * se pinta con `v-if="finanzas"`, y las finanzas llegan con la reserva.
 */
async function enfocarCuenta(): Promise<void> {
    await nextTick();
    cuentaRef.value?.scrollIntoView({ behavior: 'smooth', block: 'start' });
}

/** Abre o cierra el DETALLE, el único plegable que queda. */
function verDetalle(abrir: boolean): void {
    detalleCuentaAbierto.value = abrir;
    void router.replace({ hash: '#' + (abrir ? CUENTA_DETALLE : CUENTA_RESUMEN) });
}

/* ─────────────────────────────────────────────────────────────
 * ENLACES DE PAGO
 * Los emite el equipo (o el agente con `generar_enlace_prepago`); aquí solo se
 * enseñan los que ya existen y siguen vigentes. La app NUNCA emite uno: esta
 * vista se abre con el localizador y crear un cobro desde aquí sería un write
 * que dispara cualquiera que tenga el enlace de la reserva.
 *
 * Ya no se pintan uno a uno: el banner que lo hacía se retiró y el pago vive ahora en el
 * cuadro del resumen. Cuál de ellos se ofrece lo decide `enlaceDelImporte`, por importe y no
 * por orden — con dos vigentes, el primero puede no ser el que la tarjeta acaba de enseñar.
 * ───────────────────────────────────────────────────────────── */
const enlacesPago = computed(() => finanzas.value?.enlacesPago ?? []);

// `importeEnlace()` se retiró con el banner naranja: era quien formateaba su cifra. Lo que
// hacía —enseñar el importe en la moneda del ENLACE y no en la del conmutador de soles— lo
// garantiza ahora el read-model, que manda `importes` y `medios` ya en su moneda y con la
// equivalencia aparte. Enseñar «S/ 137.20» en un botón que carga US$ 40.50 sigue siendo la
// reclamación garantizada; sólo que ya no puede pasar por aquí.
</script>

<template>
  <div class="min-h-screen p-4 md:p-8 bg-[#E6EBF1] font-sans selection:bg-[#376875]/20 selection:text-[#376875]">

    <!-- ═══ BUSCADOR: sin localizador en la URL ═══ -->
    <div v-if="!localizador" class="max-w-md mx-auto text-center py-16 px-6 bg-white rounded-[2.5rem] shadow-xl shadow-slate-200/50 mt-10 border border-slate-50">
      <div class="w-20 h-20 bg-[#376875]/5 rounded-full flex items-center justify-center mx-auto mb-6">
        <i class="fas fa-key text-[#376875] text-2xl"></i>
      </div>
      <h3 class="text-gray-900 font-black text-lg mb-2">
        {{ maestroStore.t('res_buscar_titulo') || 'Encuentra tu reserva' }}
      </h3>
      <p class="text-slate-500 text-sm mb-6 leading-relaxed">
        {{ maestroStore.t('res_buscar_sub') || 'Ingresa el código de reserva que te enviamos por correo' }}
      </p>
      <form @submit.prevent="buscarReserva" class="flex gap-2">
        <input
            v-model="codigoBusqueda"
            :placeholder="maestroStore.t('res_buscar_placeholder') || 'Ej. AB12CD'"
            maxlength="10"
            autocomplete="off"
            class="flex-1 min-w-0 bg-slate-50 border border-slate-200 rounded-xl px-4 py-3 font-mono font-black uppercase tracking-widest text-center text-gray-800 focus:outline-none focus:border-[#E07845] focus:bg-white transition-colors"
        />
        <button
            type="submit"
            :disabled="!codigoBusqueda.trim()"
            class="bg-[#E07845] hover:bg-[#D06535] disabled:opacity-40 disabled:cursor-not-allowed text-white font-black px-6 rounded-xl transition-all active:scale-[0.97] shadow-lg shadow-orange-100"
        >
          <i class="fas fa-arrow-right"></i>
        </button>
      </form>
    </div>

    <!-- ═══ CARGANDO ═══ -->
    <div v-else-if="!isReady || pmsStore.loading" class="flex flex-col items-center justify-center py-20 min-h-[60vh]">
      <div class="relative w-16 h-16 mb-6">
        <div class="absolute inset-0 rounded-full border-4 border-slate-100"></div>
        <div class="absolute inset-0 rounded-full border-4 border-[#E07845] border-t-transparent animate-spin"></div>
      </div>
      <p class="text-[#376875]/60 font-black animate-pulse uppercase tracking-[0.2em] text-xs">
        {{ maestroStore.t('res_buscando_reserva') || 'Buscando tu reserva...' }}
      </p>
    </div>

    <!-- ═══ RESERVA ENCONTRADA ═══ -->
    <div v-else-if="pmsStore.reserva && pmsStore.reserva.nombreCliente" class="max-w-4xl mx-auto">

      <!-- 🔧 Header compactado: menos padding en móvil y el selector de idioma
           baja a la fila inferior junto al bloque de estancia (aprovecha el hueco). -->
      <header class="bg-[#376875] p-5 md:p-10 rounded-[2.5rem] shadow-xl shadow-[#376875]/20 mb-6 relative overflow-hidden text-white">
        <div class="absolute inset-0 opacity-10" style="background-image: radial-gradient(#ffffff 1px, transparent 1px); background-size: 24px 24px;"></div>

        <div class="relative z-10">
          <!-- Saludo -->
          <span class="inline-block px-3 py-1 rounded-lg bg-[#E07845] text-white text-[10px] font-black uppercase tracking-widest mb-3 shadow-sm">
            {{ maestroStore.t('res_localizador') || 'Booking Ref' }}: {{ pmsStore.reserva.localizador }}
          </span>
          <h1 class="text-3xl md:text-5xl font-black tracking-tight leading-none">
            {{ maestroStore.t('res_hola') || '¡Hola' }}, <span class="text-white/90">{{ pmsStore.reserva.nombreCliente }}</span>
          </h1>

          <!-- Fila inferior: estancia (izq) + selector idioma (der) -->
          <div class="flex justify-between items-end gap-4 mt-6">

            <div class="bg-white/10 backdrop-blur-sm px-4 py-3 rounded-2xl border border-white/10">
              <p class="text-[9px] uppercase font-black text-white/60 tracking-wider mb-0.5">{{ maestroStore.t('res_total_estancia') || 'Total Estancia' }}</p>
              <p class="text-2xl font-black text-white leading-none">{{ pmsStore.reserva.numeroNoches }} <span class="text-sm font-bold text-white/80">{{ maestroStore.t('res_noches') || 'Noches' }}</span></p>
            </div>

            <div class="relative z-20 shrink-0">
              <select
                  :value="maestroStore.idiomaActual"
                  @change="maestroStore.setIdioma(($event.target as HTMLSelectElement).value)"
                  class="appearance-none bg-white/10 border border-white/20 font-black text-[10px] uppercase tracking-widest rounded-xl pl-4 pr-8 py-2.5 focus:outline-none focus:bg-white focus:text-[#376875] cursor-pointer text-white transition-colors hover:bg-white/20"
              >
                <option v-for="lang in maestroStore.idiomas" :key="lang.id" :value="lang.id" class="text-gray-800">
                  {{ lang.bandera }} {{ lang.id.toUpperCase() }}
                </option>
              </select>
              <div class="absolute right-3 top-1/2 -translate-y-1/2 pointer-events-none text-white/70">
                <i class="fas fa-chevron-down text-[8px]"></i>
              </div>
            </div>

          </div>
        </div>
      </header>

      <!-- ═══ ESTADO DE CUENTA ═══ Solo si el backend mandó el resumen (hay
           cabecera financiera con cargos). Presentación, sin lógica de negocio:
           el saldo ya viene calculado; aquí solo se añade el recargo de tarjeta. -->
      <section v-if="finanzas" ref="cuentaRef" class="bg-white rounded-[2.5rem] shadow-xl shadow-slate-300/40 ring-1 ring-slate-200/70 border border-slate-200 overflow-hidden mb-6">
        <div class="p-5 md:p-8">

          <!-- Cabecera de la tarjeta -->
          <div class="flex items-center justify-between gap-3 mb-5">
            <div class="flex items-center gap-3 min-w-0">
              <span class="w-10 h-10 rounded-2xl bg-[#376875]/8 text-[#376875] flex items-center justify-center shrink-0">
                <i class="fas fa-file-invoice-dollar"></i>
              </span>
              <h2 class="text-base md:text-lg font-black text-gray-900 tracking-tight truncate">
                {{ maestroStore.t('res_estado_cuenta') || 'Estado de cuenta' }}
              </h2>
            </div>
            <!-- ═══ CONMUTADOR DE MONEDA ═══
                 Vive en la cabecera —no dentro del desglose— porque el desglose
                 arranca plegado y el importe que el huésped mira de un vistazo (el
                 badge de saldo, abajo) también cambia de moneda: el mando tiene que
                 estar visible en los dos estados de la tarjeta.

                 Segmentado en vez de un botón que alterna: el botón discreto de antes
                 obligaba a deducir si el símbolo era el estado actual o el destino.
                 Aquí las dos monedas están siempre a la vista y la activa es la que
                 lleva color.

                 `!soloProgreso` no sobra: el backend manda el tipo de cambio al margen
                 de que haya cifras que enseñar, y en una reserva de Airbnb sin extras
                 el conmutador no tendría nada que convertir. Antes esto lo daba gratis
                 el bloque plegable, que ya iba dentro de ese `v-if`. -->
            <div v-if="hayReferencia && !soloProgreso"
                 class="shrink-0 flex items-center gap-0.5 rounded-full bg-slate-100 p-1 ring-1 ring-slate-200"
                 role="group"
                 :aria-label="maestroStore.t('res_moneda_conmutador') || 'Cambiar moneda'">
              <button type="button"
                      @click="verEnSoles = false"
                      :aria-pressed="!enSoles"
                      class="rounded-full px-2.5 py-1 text-[13px] font-black leading-none tabular-nums transition-all"
                      :class="!enSoles
                        ? 'bg-white text-[#376875] shadow-sm ring-1 ring-slate-200'
                        : 'text-slate-400 hover:text-slate-600'">
                {{ simboloCobro }}
              </button>
              <i class="fas fa-right-left text-[9px] text-slate-400 px-0.5" aria-hidden="true"></i>
              <button type="button"
                      @click="verEnSoles = true"
                      :aria-pressed="enSoles"
                      class="rounded-full px-2.5 py-1 text-[13px] font-black leading-none tabular-nums transition-all"
                      :class="enSoles
                        ? 'bg-white text-amber-600 shadow-sm ring-1 ring-amber-200'
                        : 'text-slate-400 hover:text-slate-600'">
                {{ simboloReferencia }}
              </button>
            </div>
          </div>

          <!-- Progreso de pago -->
          <div>
            <div class="h-2.5 bg-slate-100 rounded-full overflow-hidden">
              <div class="h-full rounded-full bg-linear-to-r from-[#376875] to-emerald-500 transition-all duration-700"
                   :style="{ width: pctPagado + '%' }"></div>
            </div>

            <!-- Estado (izquierda) y porcentaje (derecha) a la misma altura, bajo la
                 barra: son las dos lecturas de lo mismo y antes estaban separadas por
                 media tarjeta. `flex-wrap` porque en móviles estrechos el badge con
                 importe y el porcentaje no siempre caben en un renglón. -->
            <div class="mt-2 flex flex-wrap items-center gap-x-3 gap-y-1.5">
              <span v-if="todoPagado"
                    class="inline-flex items-center gap-1.5 bg-emerald-50 text-emerald-700 border border-emerald-200 text-[10px] font-black uppercase tracking-wider px-2.5 py-1 rounded-full">
                <i class="fas fa-circle-check"></i> {{ maestroStore.t('res_al_dia') || 'Al día' }}
              </span>
              <!-- Con el detalle desplegado el saldo ya aparece abajo como número
                   grande («Saldo por pagar»): repetirlo aquí era ruido. -->
              <span v-else-if="!detalleCuentaAbierto"
                    class="inline-flex items-center gap-1.5 bg-orange-50 text-[#E07845] border border-orange-200 text-[10px] font-black uppercase tracking-wider px-2.5 py-1 rounded-full">
                <i class="fas fa-hourglass-half"></i> {{ maestroStore.t('res_saldo_pendiente') || 'Saldo pendiente' }}
                <!-- La cifra, un punto por encima del rótulo: es el dato, y el rótulo sólo
                     dice de qué es. Al mismo cuerpo se leían como una sola frase y había que
                     buscar el número dentro de ella. -->
                <span class="pl-1.5 ml-0.5 border-l border-orange-200 normal-case tracking-normal tabular-nums text-[13px] leading-none">
                  {{ marcaAprox }}{{ formatMonto(finSaldo) }}
                </span>
              </span>

              <!-- Por debajo de 420 px el badge con importe (227 px) y esta línea
                   completa (71 px) no caben juntos en los 288 px útiles de la tarjeta
                   y el renglón se partía en dos. Sin la palabra son 30 px y entran:
                   con la barra justo encima, «34 %» no necesita apoyo. -->
              <p class="ml-auto text-[11px] font-bold text-slate-400 whitespace-nowrap">
                <!-- `&nbsp;` y no un espacio normal: Vue condensa el blanco que abre el
                     span y el texto salía pegado ("34%pagado"). -->
                {{ Math.round(pctPagado) }}%<span class="max-[420px]:hidden">&nbsp;{{ maestroStore.t('res_pagado_pct') || 'pagado' }}</span>
              </p>
            </div>
          </div>

          <!-- El aviso de «referencial» está FUERA del plegable a propósito: con el
               conmutador en la cabecera se puede pasar a soles sin desplegar nada, y
               entonces el badge de saldo enseñaría una cifra en soles sin decir que no
               es la que se cobra (docs/FinanzasEnlacesPago.md §8). Una sola línea: dos
               renglones de aviso empujaban el desglose fuera de la pantalla en móvil. -->
          <p v-if="enSoles" class="mt-3 rounded-lg bg-amber-50 px-3 py-2 text-[11px] font-medium leading-snug text-amber-700">
            {{ maestroStore.t('res_soles_referencial') || 'Importes referenciales al tipo de cambio de hoy.' }}
          </p>

          <!-- ═══ DOS MONEDAS ═══
               Fuera del plegable, como el aviso de soles y por el mismo motivo: el titular
               enseña una cifra CONVERTIDA (el cuadre) y el huésped tiene que saberlo antes de
               leerla. Debajo, lo que de verdad se pactó en cada moneda — que es lo que él
               reconoce de sus recibos.

               El conmutador de soles no aparece aquí: el backend no manda tipo de cambio
               referencial cuando hay dos monedas, porque convertir una de ellas dejaría la
               tarjeta sin cuadrar consigo misma. -->
          <div v-if="porMoneda.length" class="mt-3 rounded-lg border border-slate-200 bg-slate-50 px-3 py-2.5">
            <p class="text-[11px] font-medium leading-snug text-slate-600">
              {{ cruceSaldado
                ? (maestroStore.t('res_dos_monedas_saldado') || 'Pagaste en una moneda una cuenta emitida en otra. Está saldado: no queda nada pendiente.')
                : (maestroStore.t('res_dos_monedas') || 'Esta reserva tiene movimientos en dos monedas. Arriba ves el equivalente aproximado; aquí, lo exacto de cada una.') }}
            </p>

            <!-- ═══ SALDADO: no se enseñan SALDOS, se enseña QUÉ PASÓ ═══
                 Con la cuenta cerrada, el saldo por moneda es un número cierto que no significa
                 nada suelto: «USD 65.97» es lo que costó, no lo que se debe, y «PEN −223.70» es
                 lo que pagó, no un crédito a su favor. Se nombra cada uno por lo que es y
                 desaparece el color de alarma, que es lo que hacía leer una cuenta saldada como
                 una deuda. -->
            <template v-if="cruceSaldado">
              <div v-for="m in porMoneda" :key="m.moneda" class="mt-2 flex items-center justify-between gap-3">
                <span class="text-[12px] font-bold text-slate-500">
                  {{ Number(m.cargos) > 0
                    ? (maestroStore.t('res_moneda_cuenta') || 'Cuenta')
                    : (maestroStore.t('res_moneda_pagaste') || 'Pagaste') }}
                </span>
                <span class="text-[12px] font-black tabular-nums text-slate-700">
                  {{ montoEnMoneda(Number(m.cargos) > 0 ? m.cargos : m.pagado, m.simbolo) }}
                </span>
              </div>
            </template>

            <!-- Con algo pendiente de verdad, el saldo por moneda sí es lo que hay que leer. -->
            <template v-else>
              <div v-for="m in porMoneda" :key="m.moneda" class="mt-2 flex items-center justify-between gap-3">
                <span class="text-[12px] font-bold text-slate-500">{{ m.moneda }}</span>
                <span class="text-[12px] font-black tabular-nums"
                      :class="Number(m.saldo) > 0 ? 'text-[#E07845]' : 'text-emerald-700'">
                  {{ montoEnMoneda(m.saldo, m.simbolo) }}
                </span>
              </div>
            </template>
          </div>

          <!-- ═══ RESUMEN ═══
               Lo que se lee de un vistazo: UNA cifra por opción que el huésped puede
               ejecutar, con el recargo ya dentro. El desglose —base, comisión, tipo de
               cambio, cargos línea a línea— vive en el detalle, que se despliega.

               La experiencia es la que manda aquí: a la mayoría le abruma el cálculo y
               sólo quiere saber cuánto y por dónde. Quien pide el porqué es minoría, y
               para ésos está el toggle. -->
          <div v-if="situacion?.hayAlgoQuePedir" class="mt-4">

            <!-- ═══ EL CUADRO: qué se pide, a cuánto, y por dónde ═══
                 En DOS COLUMNAS y no apilado: el rótulo es largo —«Adelanto para asegurar tu
                 reserva»— y debajo del importe partía la tarjeta en cinco renglones. Con el
                 texto a la izquierda y la cifra a la derecha son tres, y el ojo encuentra el
                 número donde ya lo busca en cualquier factura.

                 La tarjeta NO está aquí: cuesta otra cosa, y mezclarla obligaría a leer dos
                 cifras dentro del mismo cuadro. Va fuera, con su asterisco.

                 ⚠️ NO es un enlace, y lo fue: el cuadro entero llevaba al enlace de pago.
                 Se cruzaban DOS cosas. La de bulto, que dentro viven ahora las «i» de cada
                 medio, y un icono dentro de un enlace es un icono que navega. Y la de fondo,
                 que ya estaba mal antes: el cuadro dice 54.08 —lo que cuesta por Yape— y el
                 enlace cobra 57.05, porque es de tarjeta y lleva el recargo. Pulsar el
                 importe de un medio para acabar pagando el de otro es la trampa exacta que el
                 agrupado por precio venía a deshacer.

                 Pagar con tarjeta se hace desde «Pagar ahora», que está debajo, junto a SU
                 cifra. Un solo camino, y lleva a lo que dice. -->
            <div class="flex items-center justify-between gap-3 rounded-2xl border border-[#376875]/25 bg-[#376875]/5 px-4 py-3">
              <span class="min-w-0">
                <span class="block text-[11px] font-black uppercase tracking-widest text-[#376875] leading-tight">
                  {{ situacion.queSePide === 'ADELANTO'
                    ? (maestroStore.t('res_pide_adelanto') || 'Adelanto para asegurar tu reserva')
                    : (maestroStore.t('res_pide_total') || 'Total a pagar') }}
                </span>

                <!-- Con qué medios vale ESE importe. Fluido y en una sola corrida, no en
                     lista: son alternativas del mismo precio, no opciones que comparar.

                     Cada uno con su «i» cuando hay cuentas que dar: el huésped que elige Yape
                     necesita el número, y el que elige efectivo no necesita nada. Preguntarlo
                     por WhatsApp era el paso que sobraba. -->
                <span v-if="mediosSinTarjeta.length" class="mt-0.5 block text-[12px] font-bold text-slate-500 leading-snug">
                  <span v-for="(m, i) in mediosSinTarjeta" :key="m.codigo">
                    <span v-if="i > 0" class="text-slate-300"> · </span>{{ m.etiqueta }}<button
                        v-if="m.tieneFicha"
                        type="button"
                        class="ml-1 inline-block align-middle text-[#14A5A5] transition-colors hover:text-[#0E8585]"
                        :class="{ 'i-late': !yaDescubrio }"
                        :aria-label="m.etiqueta"
                        @click="alternarFicha(m.codigo)"
                    ><i class="fa-solid fa-circle-info text-[11px]"></i></button>
                  </span>
                </span>

                <span v-if="situacion.queSePide === 'ADELANTO' && finanzas?.prepago"
                      class="block text-[11px] font-medium text-slate-400 leading-snug">
                  {{ maestroStore.t(finanzas.prepago.claveI18n) }}
                </span>
              </span>

              <!-- Un bloque POR MONEDA. Con una sola —lo normal— es una cifra; con dos, cada
                   una dice lo suyo y NO se suman: el huésped debe en las dos.

                   La MONEDA va encima y pequeña, la cifra debajo y grande. Con «US$ 54.08»
                   en una línea, la columna se lleva un ancho que el rótulo necesita: en
                   inglés —«Advance payment to secure your reservation»— eso lo partía en
                   tres renglones. Apilada ocupa lo que mide el número.

                   Y sin flecha: el cuadro entero ya es el enlace, y una flecha compitiendo
                   con el importe le quita al número el sitio que necesita. -->
              <span class="shrink-0 text-right">
                <span v-for="imp in situacion.importes" :key="imp.moneda" class="block">
                  <span class="block text-[11px] font-black uppercase tracking-wide text-slate-400 leading-none">
                    {{ imp.simbolo || imp.moneda }}
                  </span>
                  <span class="block text-xl font-black tabular-nums text-gray-900 leading-tight">
                    {{ imp.importe }}
                  </span>
                  <span v-if="imp.enSoles" class="block text-[11px] font-bold text-slate-400 leading-none">
                    S/ {{ imp.enSoles }}
                  </span>
                </span>
              </span>
            </div>

            <!-- ═══ LA FICHA DEL MEDIO ═══
                 Debajo del cuadro y no encima, flotando: un tooltip absoluto sobre un móvil
                 tapa justo el importe que lo motivó, y se sale de la tarjeta por el lado.
                 Aquí empuja lo de abajo, que es lo que el huésped ya espera de un desplegable.

                 Con X, porque en táctil no hay «quitar el ratón de encima»: sin un cierre
                 explícito, un cuadro abierto se queda abierto. Pulsar la misma «i» también lo
                 cierra, para quien lo intente por ahí.

                 Se pinta con `v-for` sobre las fichas porque un medio puede tener VARIAS:
                 «transferencia bancaria» son tres cuentas de tres bancos. -->
            <div v-if="fichaAbierta && fichasDelAbierto.length"
                 class="mt-2 rounded-xl border border-[#376875]/20 bg-white px-3 py-2.5 shadow-sm">
              <div class="flex items-start justify-between gap-2">
                <span class="text-[11px] font-black uppercase tracking-widest text-[#376875] leading-tight">
                  {{ nombreDelAbierto }}
                </span>
                <button type="button"
                        class="-mr-1 -mt-0.5 shrink-0 px-1 text-slate-400 transition-colors hover:text-slate-700"
                        :aria-label="maestroStore.t('res_cerrar') || 'Cerrar'"
                        @click="fichaAbierta = null">
                  <i class="fa-solid fa-xmark text-[13px]"></i>
                </button>
              </div>

              <!-- Una FILA por cuenta, no un bloque: con ocho cuentas de por medio, cuatro
                   renglones cada una son treinta y dos, y el huésped sólo busca la suya. El
                   banco a la izquierda es por lo que la busca; el número a la derecha es lo
                   que se lleva. -->
              <div v-for="(f, i) in fichasDelAbierto" :key="i"
                   class="mt-1.5 flex items-baseline justify-between gap-3"
                   :class="i > 0 ? 'border-t border-slate-100 pt-1.5' : ''">
                <span v-if="f.banco || f.moneda" class="min-w-0 text-[11px] leading-snug">
                  <span v-if="f.banco" class="font-black uppercase tracking-wide text-slate-500">{{ f.banco }}</span>
                  <span v-if="f.moneda" class="block font-bold text-slate-400">{{ rotuloMoneda(f.moneda, f.monedaSimbolo) }}</span>
                </span>

                <span class="min-w-0 text-right">
                  <!-- `select-all`: el gesto de un dedo encima selecciona el número entero, que
                       es lo único que el huésped va a hacer aquí. -->
                  <!-- `tabular-nums` sólo si de verdad hay dígitos que alinear. Western
                       Union no tiene cuenta: su «número» es el destino del giro —«Cusco,
                       Perú»—, y puesto en cifras monoespaciadas se lee como un código que
                       hubiera que copiar. La clase existe para cuadrar dígitos; aplicarla sólo
                       cuando los hay es lo que dice. -->
                  <span v-if="f.numero"
                        class="block select-all text-[13px] font-black text-gray-900 leading-snug break-all"
                        :class="/\d/.test(f.numero) ? 'tabular-nums' : ''">
                    {{ f.numero }}
                  </span>
                  <span v-if="f.cci" class="block select-all text-[11px] font-bold tabular-nums text-slate-400 leading-snug break-all">
                    CCI {{ f.cci }}
                  </span>
                  <!-- El titular sólo aquí cuando difiere entre cuentas; si es el mismo en
                       todas, se dice una vez al pie. Igual con la nota. -->
                  <span v-if="!titularComun && f.titular" class="block text-[11px] font-medium text-slate-500 leading-snug">
                    <span class="text-slate-400">{{ maestroStore.t('res_a_nombre_de') || 'A nombre de' }}</span> {{ f.titular }}
                  </span>
                  <span v-if="!notaComun && f.nota" class="block text-[11px] font-medium text-slate-500 leading-snug">
                    {{ maestroStore.traducir(f.nota) }}
                  </span>
                </span>
              </div>

              <!-- A nombre de quién, una vez. Es lo que la app de destino le enseña antes de
                   confirmar, y verlo coincidir es lo que le dice que no se equivocó. -->
              <!-- Con rótulo: suelto era un nombre bajo unos números y no decía su papel —que
                   es el que el huésped teclea en su banca y coteja antes de confirmar—. -->
              <p v-if="titularComun" class="mt-2 border-t border-slate-100 pt-1.5 text-[11px] leading-snug">
                <span class="font-bold text-slate-400">{{ maestroStore.t('res_a_nombre_de') || 'A nombre de' }}</span>
                <span class="ml-1 font-medium text-slate-600">{{ titularComun }}<span v-if="fichasDelAbierto[0]?.titularAlterno"> · {{ fichasDelAbierto[0].titularAlterno }}</span></span>
              </p>

              <!-- La nota, al final y a lo ancho: se lee DESPUÉS del nombre y del destino,
                   que es el orden en que se rellena el formulario del giro. En Western Union
                   es la línea que evita que el dinero se mande por una vía que no podemos
                   cobrar, así que no va en gris de pie de página. -->
              <p v-if="notaComun" class="mt-2 border-t border-slate-100 pt-1.5 text-[11px] font-medium text-slate-600 leading-snug">
                {{ notaComun }}
              </p>

              <!-- Y lo último, porque es lo último que hace: avisarnos. El texto depende del
                   CANAL —el chat de Booking no admite imágenes— y por eso lo elige el backend
                   y no vive en la nota del medio: metido ahí, a un huésped de una reserva
                   directa se le hablaba del chat de una plataforma por la que no vino.

                   Sólo aquí dentro: todos los medios con ficha son los que se ejecutan a
                   distancia, que son justo los que hay que confirmar. Al efectivo y a la
                   tarjeta no les hace falta, y no tienen ficha. -->
              <p v-if="situacion?.avisoPago" class="mt-2 text-[11px] font-medium text-slate-400 leading-snug">
                {{ maestroStore.t(situacion.avisoPago) }}
              </p>
            </div>

            <!-- ═══ LA TARJETA, FUERA DEL CUADRO ═══
                 Cuesta más, así que no puede ir dentro: dos cifras en el mismo cuadro obligan
                 a decidir cuál es «la» cifra. El asterisco la ata al importe de arriba y dice
                 por qué difiere, sin pedirle al huésped que sume nada. -->
            <div v-if="conTarjeta" class="mt-3 flex items-center gap-3">
              <p class="min-w-0 flex-1 text-[12px] font-medium text-slate-500 leading-snug">
                <span class="font-black text-slate-400">*</span>
                {{ maestroStore.t('res_con_tarjeta') || 'Con tarjeta de crédito' }}
                <span class="font-black text-slate-700 tabular-nums">
                  {{ situacion.importes[0]?.simbolo || situacion.importes[0]?.moneda }} {{ conTarjeta.importe }}
                </span>
                <span class="block text-[11px] text-slate-400">
                  {{ maestroStore.t('res_recargo_nota', { pct: String(Number(conTarjeta.recargoPorcentaje)) })
                      || `Incluye ${Number(conTarjeta.recargoPorcentaje)}% de comisión` }}
                </span>
              </p>

              <!-- Compacto a propósito: es la acción CARA. Tiene que verse y poder pulsarse
                   —de ahí el color y el peso— pero no ganarle superficie al cuadro, o empuja
                   a pagar de más a quien podía transferir. -->
              <router-link v-if="enlaceDelImporte"
                  :to="{ name: 'pago_enlace', params: { token: enlaceDelImporte.token } }"
                  class="shrink-0 rounded-xl bg-[#E07845] px-3 py-2 text-center text-[12px] font-black uppercase tracking-wide leading-tight text-white shadow-md shadow-orange-100 transition-all hover:bg-[#D06535] active:scale-[0.98]">
                {{ maestroStore.t('res_pagar_online') || 'Pagar ahora' }}
              </router-link>
            </div>
          </div>

          <!-- ═══ DETALLE PLEGABLE ═══
               El truco de grid-rows 0fr -> 1fr anima la altura sin conocerla de
               antemano (lo que `max-height` obliga a adivinar y recortaría el
               contenido si crece). El hijo necesita overflow-hidden para que el
               recorte ocurra durante la transición. -->
          <div v-if="!soloProgreso"
               class="grid transition-[grid-template-rows] duration-500 ease-in-out"
               :class="detalleCuentaAbierto ? 'grid-rows-[1fr]' : 'grid-rows-[0fr]'">
            <div class="overflow-hidden">

              <!-- Detalle de cargos, línea a línea -->
              <div class="pt-6">

                <!-- El cuadro del PREPAGO ya no se pinta aquí: subió al resumen, que es
                     donde pide la acción. Repetirlo dentro del detalle enseñaba la misma
                     cifra dos veces en la misma tarjeta, con dos formatos distintos, y
                     obligaba a comprobar que decían lo mismo.
                     El detalle empieza directamente por el desglose, que es a lo que se
                     abre: de qué se compone el total. -->

                <!-- Separa el prepago —lo único que pide acción— del desglose informativo. -->
                <p class="mb-2 text-[11px] font-black uppercase tracking-[0.14em] text-slate-400">
                  {{ maestroStore.t('res_detalle') || 'Detalle' }}
                </p>

                <div v-if="cargosDetalle.length" class="space-y-2 mb-3">
                  <div v-for="(linea, i) in cargosDetalle" :key="`${linea.tipo}-${i}`"
                       class="flex items-start justify-between gap-4">
                    <span class="min-w-0">
                      <span class="block text-[13px] font-medium text-slate-500">{{ nombreCargo(linea.tipo) }}</span>
                      <!-- La explicación del cargo, cuando la tiene. La mayoría no la
                           necesita: se entienden por su tipo. -->
                      <span v-if="maestroStore.traducir(linea.descripcion)"
                            class="block text-[11px] leading-snug text-slate-400">
                        {{ maestroStore.traducir(linea.descripcion) }}
                      </span>
                    </span>
                    <span class="text-[13px] font-bold text-slate-600 tabular-nums whitespace-nowrap">{{ formatMonto(Number(linea.monto)) }}</span>
                  </div>
                </div>

                <div class="flex items-center justify-between gap-4"
                     :class="cargosDetalle.length ? 'pt-3 border-t border-slate-100' : ''">
                  <span class="text-[13px] font-semibold text-slate-500">
                    {{ maestroStore.t('res_total_reserva') || 'Total de la reserva' }}
                  </span>
                  <span class="text-[15px] font-black text-gray-900 tabular-nums">{{ marcaAprox }}{{ formatMonto(finTotal) }}</span>
                </div>
              </div>

              <!-- Pagos recibidos, con su fecha y medio -->
              <div class="mt-5 pt-5 border-t border-dashed border-slate-200">
                <div v-if="pagosDetalle.length" class="space-y-2 mb-3">
                  <div v-for="(pago, i) in pagosDetalle" :key="i"
                       class="flex items-center justify-between gap-4">
                    <span class="min-w-0 flex items-center gap-2 text-[13px] font-medium text-slate-500">
                      <i class="fas text-[11px] text-slate-400 shrink-0" :class="iconoMedio(pago.medio)"></i>
                      <span class="truncate">{{ nombreMedio(pago.medio) }}</span>
                      <span v-if="pago.fecha" class="text-[11px] text-slate-400 whitespace-nowrap">{{ fechaPago(pago.fecha) }}</span>
                    </span>
                    <span class="text-[13px] font-bold text-emerald-600 tabular-nums shrink-0">{{ formatMonto(Number(pago.monto)) }}</span>
                  </div>
                </div>

                <div class="flex items-center justify-between gap-4"
                     :class="pagosDetalle.length ? 'pt-3 border-t border-slate-100' : ''">
                  <span class="text-[13px] font-semibold text-slate-500">
                    <i class="fas fa-circle-check text-emerald-500 mr-1.5"></i>{{ maestroStore.t('res_adelanto_pagado') || 'Adelanto pagado' }}
                  </span>
                  <span class="text-[15px] font-bold text-emerald-600 tabular-nums">− {{ formatMonto(finPagado) }}</span>
                </div>
              </div>

              <!-- Saldo -->
              <div v-if="!todoPagado" class="mt-5 pt-5 border-t border-dashed border-slate-200">
                <div class="flex items-end justify-between gap-4">
                  <span class="text-[13px] font-black text-gray-800 uppercase tracking-wider">
                    {{ maestroStore.t('res_saldo') || 'Saldo por pagar' }}
                  </span>
                  <span class="text-xl md:text-2xl font-extrabold text-[#E07845] tabular-nums leading-none">{{ formatMonto(finSaldo) }}</span>
                </div>

                <!-- Pago con tarjeta: mismo saldo con la comisión de la pasarela -->
                <div class="mt-4 bg-slate-50 border border-slate-100 rounded-2xl px-4 py-3.5 flex items-center justify-between gap-4">
                  <span class="min-w-0">
                    <span class="block text-[12px] font-bold text-slate-600">
                      <i class="fas fa-credit-card mr-1.5 text-[#376875]"></i>{{ maestroStore.t('res_saldo_tarjeta') || 'Pagando con tarjeta' }}
                    </span>
                    <span class="block text-[10px] font-semibold text-slate-400 mt-0.5">
                      {{ maestroStore.t('res_recargo_nota', { pct: String(RECARGO_TARJETA_PCT) }) || `Incluye ${RECARGO_TARJETA_PCT}% de comisión` }}
                    </span>
                  </span>
                  <span class="text-lg font-black text-[#376875] tabular-nums shrink-0">{{ formatMonto(finSaldoTarjeta) }}</span>
                </div>
              </div>

              <!-- Todo pagado -->
              <div v-else class="mt-5 pt-5 border-t border-dashed border-slate-200 flex items-center gap-3 text-emerald-700">
                <span class="w-10 h-10 rounded-full bg-emerald-50 border border-emerald-200 flex items-center justify-center shrink-0">
                  <i class="fas fa-check text-emerald-500"></i>
                </span>
                <p class="text-sm font-bold leading-snug">
                  {{ maestroStore.t('res_todo_pagado') || '¡Tu reserva está totalmente pagada. Gracias!' }}
                </p>
              </div>

            </div>
          </div>
          <!-- ═══ FIN DETALLE PLEGABLE ═══ -->

          <!-- ═══ VER DETALLE ═══
               El único plegable. Hubo dos botones seguidos —«Ver detalle» dentro del resumen
               y «Mostrar menos» al pie— y era una pregunta de más para la misma acción. Se
               queda el del pie, que es donde el ojo ya miraba.

               `soloProgreso` (Airbnb sin extras) no enseña cifras: no hay nada que abrir. -->
          <button
              v-if="!soloProgreso"
              type="button"
              @click="verDetalle(!detalleCuentaAbierto)"
              :aria-expanded="detalleCuentaAbierto"
              class="w-full mt-3 pt-2.5 -mb-1 border-t border-slate-100 flex items-center justify-center gap-2 text-[11px] font-black uppercase tracking-widest text-[#376875]/70 hover:text-[#376875] transition-colors"
          >
            {{ detalleCuentaAbierto
              ? (maestroStore.t('res_ocultar_detalle') || 'Ocultar detalle')
              : (maestroStore.t('res_ver_detalle') || 'Ver detalle') }}
            <i class="fas fa-chevron-down transition-transform duration-300"
               :class="{ 'rotate-180': detalleCuentaAbierto }"></i>
          </button>
        </div>
      </section>

      <div v-if="pmsStore.reserva.eventosActivosGuia?.length">
        <div class="flex items-center gap-4 mb-5 ml-2">
          <span class="h-px bg-[#376875]/20 flex-1"></span>
          <h2 class="text-[#376875]/60 font-black uppercase tracking-[0.2em] text-[11px]">
            {{ maestroStore.t('res_tus_unidades') || 'Tus Unidades' }}
          </h2>
          <span class="h-px bg-[#376875]/20 flex-1"></span>
        </div>

        <!-- 🔧 Añadimos índice + total para el contador "1 de N" -->
        <!-- `id` es nullable en el schema (readOnly, aún sin persistir): se cae al
             índice para que la key nunca sea null. -->
        <div v-for="(evento, index) in pmsStore.reserva.eventosActivosGuia" :key="evento.id ?? index" class="bg-white rounded-[2.5rem] shadow-xl shadow-slate-300/40 ring-1 ring-slate-200/70 overflow-hidden border border-slate-200 mb-6 group hover:shadow-2xl hover:shadow-[#376875]/10 transition-all duration-500">

          <!-- 🔧 Altura de imagen reducida en móvil (h-44) para que quepa más de una unidad -->
          <div class="h-44 md:h-64 bg-slate-100 relative overflow-hidden">

            <!-- 🔧 Indicador "1 de N" (solo si hay más de una unidad) -->
            <div
                v-if="pmsStore.reserva.eventosActivosGuia.length > 1"
                class="absolute top-4 right-4 z-10 bg-black/45 backdrop-blur-sm text-white text-[11px] font-black px-3 py-1.5 rounded-full border border-white/20 shadow-md"
            >
              {{ index + 1 }} <span class="text-white/60 font-bold">{{ maestroStore.t('res_de') || 'de' }}</span> {{ pmsStore.reserva.eventosActivosGuia.length }}
            </div>

            <template v-if="evento.pmsUnidad?.imageUrl">
              <img
                  :src="evento.pmsUnidad.imageUrl"
                  :alt="evento.pmsUnidad.nombre ?? ''"
                  class="w-full h-full object-cover transition-transform duration-[2s] group-hover:scale-105"
              >
              <div class="absolute inset-0 bg-linear-to-t from-black/60 via-transparent to-transparent opacity-60"></div>

              <div class="absolute bottom-0 left-0 p-5 md:p-8 text-white">
                <h3 class="text-2xl md:text-3xl font-black leading-none drop-shadow-md">
                  {{ evento.pmsUnidad.nombre }}
                </h3>
                <p class="text-white/90 font-bold text-sm mt-2 flex items-center gap-2 drop-shadow-sm">
                  <i class="fas fa-user-friends text-[#E07845]"></i>
                  {{ formatearOcupacion(evento.cantidadAdultos, evento.cantidadNinos) }}
                </p>
              </div>
            </template>
            <div v-else class="w-full h-full flex flex-col items-center justify-center bg-[#376875]/5">
              <i class="fas fa-home text-4xl text-[#376875]/20 mb-2"></i>
              <span class="text-[#376875]/40 font-black text-xs uppercase tracking-widest">
                  {{ maestroStore.t('res_foto_unidad') || 'OpenPeru Unit' }}
              </span>
            </div>
          </div>

          <!-- 🔧 Padding reducido en móvil -->
          <div class="p-4 md:p-8 bg-white relative">
            <div class="grid grid-cols-1 md:grid-cols-12 gap-3 md:gap-4 items-stretch">

              <div class="md:col-span-5 md:order-2">
                <button @click="verGuiaEvento(evento)"
                        class="group/btn relative w-full h-full min-h-20 md:min-h-25 rounded-3xl flex flex-col justify-center px-6 py-4 md:py-5 transition-all active:scale-[0.98] shadow-lg shadow-orange-100 hover:shadow-orange-200 bg-[#E07845] hover:bg-[#D06535] overflow-hidden text-left">
                  <i class="fas fa-map-signs absolute -right-2 -bottom-4 text-6xl text-white/10 group-hover/btn:scale-110 group-hover/btn:rotate-12 transition-transform duration-500"></i>
                  <span class="relative z-10 flex items-center justify-between w-full mb-1">
                      <span class="text-[13px] font-black uppercase tracking-[0.15em] text-white">
                        {{ maestroStore.t('res_btn_instrucciones') || 'VER GUÍA' }}
                      </span>
                      <i class="fas fa-arrow-right text-white group-hover/btn:translate-x-1 transition-transform"></i>
                  </span>
                  <span class="relative z-10 block text-white/80 text-[11px] font-medium leading-tight max-w-[90%]">
                      {{ maestroStore.t('res_cta_sub') || 'Acceso, WiFi y Normas de la casa' }}
                  </span>
                </button>
              </div>

              <div class="md:col-span-7 md:order-1 bg-[#F1F5F9] rounded-3xl grid grid-cols-2 p-1 border border-slate-100">

                <div class="text-center p-3 md:p-4 rounded-[1.2rem] bg-white shadow-sm border border-slate-50 flex flex-col justify-center">
                  <p class="text-[9px] text-[#376875]/60 font-black uppercase tracking-widest mb-2">
                    <i class="fas fa-plane-arrival mr-1 text-[#E07845]"></i>
                    {{ maestroStore.t('res_checkin') || 'Check-in' }}
                  </p>
                  <p class="text-lg md:text-xl font-black text-gray-800 leading-none">
                    {{ formatearFecha(evento.inicio) }}
                  </p>
                  <p class="text-xs font-bold text-[#376875] mt-1.5 bg-[#376875]/5 rounded-md py-1 mx-4">
                    {{ formatearHora(evento.inicio) }}
                  </p>
                </div>

                <div class="text-center p-3 md:p-4 flex flex-col justify-center">
                  <p class="text-[9px] text-[#376875]/60 font-black uppercase tracking-widest mb-2">
                    {{ maestroStore.t('res_checkout') || 'Check-out' }}
                    <i class="fas fa-plane-departure ml-1 text-[#376875]"></i>
                  </p>
                  <p class="text-lg md:text-xl font-black text-gray-800 leading-none">
                    {{ formatearFecha(evento.fin) }}
                  </p>
                  <p class="text-xs font-bold text-[#376875] mt-1.5 bg-[#376875]/5 rounded-md py-1 mx-4">
                    {{ formatearHora(evento.fin) }}
                  </p>
                </div>
              </div>

            </div>
          </div>
        </div>
      </div>

      <div class="mt-10 text-center pb-8">
        <p class="text-[9px] text-[#376875]/40 uppercase tracking-[0.3em] font-black">
          {{ maestroStore.t('com_powered_by') || 'Powered by OpenPeru' }}
        </p>
      </div>

    </div>

    <!-- ═══ NO ENCONTRADA ═══ -->
    <div v-else class="max-w-md mx-auto text-center py-16 px-6 bg-white rounded-[2.5rem] shadow-xl shadow-slate-200/50 mt-10 border border-slate-50">
      <div class="w-20 h-20 bg-red-50 rounded-full flex items-center justify-center mx-auto mb-6">
        <i class="fas fa-search text-red-400 text-2xl"></i>
      </div>
      <h3 class="text-gray-900 font-black text-lg mb-2">
        {{ maestroStore.t('res_no_encontrada') || 'Reserva no encontrada' }}
      </h3>
      <p class="text-slate-500 text-sm mb-6 leading-relaxed">
        {{ pmsStore.error || 'No pudimos encontrar una reserva con el código proporcionado.' }}
      </p>
      <div class="bg-slate-50 py-3 px-6 rounded-xl inline-block border border-slate-100 mb-6">
        <p class="text-slate-400 text-[10px] font-mono font-bold uppercase tracking-widest">ID: {{ localizador }}</p>
      </div>

      <!-- Reintentar con otro código -->
      <form @submit.prevent="buscarReserva" class="flex gap-2">
        <input
            v-model="codigoBusqueda"
            :placeholder="maestroStore.t('res_buscar_placeholder') || 'Ej. AB12CD'"
            maxlength="10"
            autocomplete="off"
            class="flex-1 min-w-0 bg-slate-50 border border-slate-200 rounded-xl px-4 py-3 font-mono font-black uppercase tracking-widest text-center text-gray-800 focus:outline-none focus:border-[#E07845] focus:bg-white transition-colors"
        />
        <button
            type="submit"
            :disabled="!codigoBusqueda.trim()"
            class="bg-[#E07845] hover:bg-[#D06535] disabled:opacity-40 text-white font-black px-6 rounded-xl transition-all active:scale-[0.97]"
        >
          <i class="fas fa-arrow-right"></i>
        </button>
      </form>
    </div>
  </div>
</template>

<style scoped>
/**
 * El latido de las «i»: un guiño cada diez segundos.
 *
 * Un icono de información dentro de una corrida de texto no se lee como pulsable —parece
 * puntuación—, y quien no lo pulsa acaba preguntando el número de cuenta por WhatsApp, que
 * es justo el paso que estas fichas vienen a quitar.
 *
 * Es DOBLE y corto —dos golpes en poco más de un segundo, y nueve de quietud— porque un
 * latido se lee como una llamada y un parpadeo lento se lee como algo roto. Y va en CSS y no
 * en un `setInterval`: el navegador lo pausa solo cuando la pestaña está en segundo plano, y
 * no hay temporizador que limpiar al desmontar.
 *
 * El `transform` obliga a `inline-block`: en un `inline` no tiene efecto.
 */
@keyframes i-late {
  0%, 100% { transform: scale(1);    opacity: 0.85; }
  3%       { transform: scale(1.45); opacity: 1; }
  6%       { transform: scale(1);    opacity: 0.85; }
  9%       { transform: scale(1.3);  opacity: 1; }
  12%      { transform: scale(1);    opacity: 0.85; }
}

.i-late {
  animation: i-late 10s ease-in-out infinite;
}

/**
 * ⚠️ Quien pide menos movimiento no ve ninguno. No es cosmético: para alguien con trastorno
 * vestibular o migraña con aura, algo que se mueve solo en un bucle sin fin es un síntoma.
 * El icono sigue estando y sigue abriendo su ficha — lo único que se va es el guiño.
 */
@media (prefers-reduced-motion: reduce) {
  .i-late { animation: none; }
}
</style>
