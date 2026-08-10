<script setup lang="ts">
/**
 * src/views/huesped/PmsReservaView.vue
 */
import { ref, computed, onMounted, watch } from 'vue';
import { useRouter } from 'vue-router';
import { usePmsReservaStore } from '@/stores/huesped/paxHuespedReservaStore.ts';
import { useMaestroStore } from '@/stores/maestroStore';
import type { PmsEventoCalendario } from '@/types/paxHuespedModel';

const props = defineProps<{
  localizador?: string;
}>();

const pmsStore = usePmsReservaStore();
const maestroStore = useMaestroStore();
const router = useRouter();

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

onMounted(cargar);
// 🔥 Recarga al cambiar el localizador (el buscador hace push sobre la misma ruta)
watch(() => props.localizador, cargar);

const formatearOcupacion = (adultos: number, ninos: number) => {
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

const todoPagado = computed(() => soloProgreso.value || finSaldo.value <= 0);

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
  const importe = enSoles.value ? v * tcReferencial.value : v;
  const simbolo = enSoles.value
      ? (finanzas.value?.simboloReferencial || finanzas.value?.monedaReferencial || '')
      : (finanzas.value?.simbolo || finanzas.value?.moneda || '');

  return `${simbolo} ${importe.toLocaleString(maestroStore.idiomaActual, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
};

/**
 * El detalle del estado de cuenta (desglose, saldo y recargo de tarjeta) arranca
 * plegado: en móvil la tarjeta empujaba las unidades fuera de pantalla. Arriba
 * queda solo el titular — estado y barra de progreso — que es lo que el huésped
 * mira de un vistazo.
 */
const detalleCuentaAbierto = ref(false);
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
      <section v-if="finanzas" class="bg-white rounded-[2.5rem] shadow-xl shadow-slate-300/40 ring-1 ring-slate-200/70 border border-slate-200 overflow-hidden mb-6">
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
            <span v-if="todoPagado"
                  class="shrink-0 inline-flex items-center gap-1.5 bg-emerald-50 text-emerald-700 border border-emerald-200 text-[10px] font-black uppercase tracking-widest px-3 py-1.5 rounded-full">
              <i class="fas fa-circle-check"></i> {{ maestroStore.t('res_al_dia') || 'Al día' }}
            </span>
            <!-- Con el detalle plegado el badge lleva el importe: es el dato que
                 el huésped busca, y abajo no hay nada visible que lo muestre.
                 Al desplegar se quita de aquí para no repetirlo junto al número
                 grande de "Saldo por pagar". -->
            <span v-else
                  class="shrink-0 inline-flex items-center gap-1.5 bg-orange-50 text-[#E07845] border border-orange-200 text-[10px] font-black uppercase tracking-widest px-3 py-1.5 rounded-full">
              <i class="fas fa-hourglass-half"></i> {{ maestroStore.t('res_saldo_pendiente') || 'Saldo pendiente' }}
              <span v-if="!detalleCuentaAbierto"
                    class="pl-1.5 ml-0.5 border-l border-orange-200 normal-case tracking-normal tabular-nums">
                {{ formatMonto(finSaldo) }}
              </span>
            </span>
          </div>

          <!-- Progreso de pago -->
          <div>
            <div class="h-2.5 bg-slate-100 rounded-full overflow-hidden">
              <div class="h-full rounded-full bg-linear-to-r from-[#376875] to-emerald-500 transition-all duration-700"
                   :style="{ width: pctPagado + '%' }"></div>
            </div>
            <p class="text-[11px] font-bold text-slate-400 mt-2 text-right">
              {{ Math.round(pctPagado) }}% {{ maestroStore.t('res_pagado_pct') || 'pagado' }}
            </p>
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

                <!-- Conmutador a soles. Solo existe si el backend mandó tipo de cambio;
                     la etiqueta «referencial» no es opcional: el cobro real va en la
                     moneda de la cabecera. -->
                <div v-if="hayReferencia" class="flex items-center justify-end gap-2 mb-3">
                  <button type="button" @click="verEnSoles = !verEnSoles"
                          class="inline-flex items-center gap-1.5 rounded-full border border-slate-200 bg-white px-3 py-1 text-[11px] font-bold text-slate-500 transition-colors hover:border-slate-300 hover:text-slate-700">
                    <i class="fas fa-right-left text-[9px]" aria-hidden="true"></i>
                    {{ enSoles
                        ? (finanzas?.simbolo || finanzas?.moneda)
                        : (finanzas?.simboloReferencial || finanzas?.monedaReferencial) }}
                  </button>
                </div>

                <p v-if="enSoles" class="mb-3 rounded-lg bg-amber-50 px-3 py-2 text-[11px] font-medium leading-snug text-amber-700">
                  {{ maestroStore.t('res_soles_referencial')
                     || 'Importes referenciales al tipo de cambio de hoy. El cobro se realiza en' }}
                  {{ finanzas?.moneda }}.
                </p>

                <!-- Prepago pendiente. Solo lo manda el backend a quien no ha pagado nada:
                     si ya hay un pago, ese pago era el prepago. Se pinta ARRIBA del
                     desglose porque es lo único aquí que pide una acción. -->
                <div v-if="finanzas?.prepago"
                     class="mb-4 rounded-xl border border-[#376875]/20 bg-[#376875]/5 px-4 py-3">
                  <div class="flex items-center justify-between gap-3">
                    <span class="text-[12px] font-black uppercase tracking-wider text-[#376875]">
                      💳 {{ maestroStore.t('res_prepago_titulo') || 'Prepago' }}
                    </span>
                    <span class="text-[15px] font-black text-[#376875] tabular-nums">
                      {{ formatMonto(Number(finanzas.prepago.monto)) }}
                    </span>
                  </div>
                  <p class="mt-1 text-[11px] font-medium leading-snug text-slate-500">
                    {{ maestroStore.t(finanzas.prepago.claveI18n) }}
                  </p>
                </div>

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
                  <span class="text-[15px] font-black text-gray-900 tabular-nums">{{ formatMonto(finTotal) }}</span>
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

          <!-- Sin cifras no hay detalle que desplegar: el botón sobraría. -->
          <button
              v-if="!soloProgreso"
              type="button"
              @click="detalleCuentaAbierto = !detalleCuentaAbierto"
              :aria-expanded="detalleCuentaAbierto"
              class="w-full mt-3 pt-2.5 -mb-1 border-t border-slate-100 flex items-center justify-center gap-2 text-[11px] font-black uppercase tracking-widest text-[#376875]/70 hover:text-[#376875] transition-colors"
          >
            {{ detalleCuentaAbierto
              ? (maestroStore.t('res_ver_menos') || 'Mostrar menos')
              : (maestroStore.t('res_ver_mas') || 'Mostrar más') }}
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
        <div v-for="(evento, index) in pmsStore.reserva.eventosActivosGuia" :key="evento.id" class="bg-white rounded-[2.5rem] shadow-xl shadow-slate-300/40 ring-1 ring-slate-200/70 overflow-hidden border border-slate-200 mb-6 group hover:shadow-2xl hover:shadow-[#376875]/10 transition-all duration-500">

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
                  :alt="evento.pmsUnidad.nombre"
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
