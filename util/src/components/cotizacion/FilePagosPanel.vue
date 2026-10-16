<script setup lang="ts">
/**
 * Los pagos del cliente a cuenta de un expediente: la cuenta, la lista y el alta.
 *
 * ⚠️ **Aquí no se calcula el saldo.** Total, pagado y saldo vienen hechos del servidor
 * (`CuentaDelExpediente::de()`), que es quien sabe contra qué propuesta se mide. Lo único que se
 * calcula es el adelanto en dinero y si está cubierto, con `montoDeAdelanto()` y
 * `adelantoCubierto()` de `dominio/`: las mismas funciones que el editor y `pax`.
 *
 * Reglas que vienen del servidor y aquí sólo se respetan (ver `CotizacionPago`):
 * - **Registrar un pago NO confirma la propuesta**: si se paga una pendiente o enviada, se avisa.
 * - **No se borra ni se edita: se anula**, con motivo. El pago anulado se queda a la vista, tachado.
 * - **En soles hace falta el tipo de cambio** de ese día; se propone el de la propuesta, que es el
 *   que vio el cliente al calcular lo que pagaba.
 *
 * Ver docs/Cotizaciones.md §6.a.
 */
import { computed, ref, watch } from 'vue';
import { apiClient } from '@/services/apiClient';
import { extractApiErrorMessage } from '@/services/apiError';
import { hoyNaive } from '@/utils/naiveDate.ts';
import { adelantoCubierto, montoDeAdelanto } from '@dominio/cotizacion/index.ts';
import {
  MEDIO_PAGO_LABELS,
  type ApiCotizacionFile,
  type ApiCotizacionPago,
} from '@/types/fileDetalleModel';

const props = defineProps<{ file: ApiCotizacionFile }>();
const emit = defineEmits<{ (e: 'actualizado'): void }>();

const abierto = ref(false);

const cuenta = computed(() => props.file.cuenta ?? null);
const pagos = computed<ApiCotizacionPago[]>(() => [...(props.file.pagos ?? [])].reverse());

const usd = (v: number | null | undefined): string =>
    v == null ? '—' : `$ ${v.toLocaleString('es-PE', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
const importe = (monto: string, moneda: string): string =>
    `${moneda === 'PEN' ? 'S/' : '$'} ${Number(monto).toLocaleString('es-PE', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
const fecha = (iso: string): string => {
  const [a, m, d] = iso.substring(0, 10).split('-');
  return `${d}/${m}/${a}`;
};

/** El adelanto que pide la propuesta base, en dinero, y si ya está cubierto. */
const adelanto = computed(() => {
  const c = cuenta.value;
  if (!c || c.totalUsd == null || c.porcentajeAdelanto == null) return null;
  const monto = montoDeAdelanto(c.totalUsd, c.porcentajeAdelanto);
  return { monto, porcentaje: c.porcentajeAdelanto, cubierto: adelantoCubierto(c.totalUsd, c.porcentajeAdelanto, c.pagadoUsd ?? 0) };
});

const resumen = computed(() => {
  const c = cuenta.value;
  const vivos = (props.file.pagos ?? []).filter(p => !p.anulado).length;
  if (!c || vivos === 0) return 'Sin pagos';
  const pagado = `Pagado ${usd(c.pagadoUsd)}`;
  return c.saldoUsd == null ? pagado : `${pagado} · Saldo ${usd(c.saldoUsd)}`;
});

/* ── Alta ─────────────────────────────────────────────────────────────────── */

/** Las propuestas que se pueden pagar: sin históricos ni descartadas, una por número. */
const propuestas = computed(() => {
  const vistas = new Map<number, { numero: number; estado: string; tipoCambio: string }>();
  for (const c of props.file.cotizaciones ?? []) {
    if (['historico', 'cerrado', 'cancelado'].includes(c.estado)) continue;
    const ya = vistas.get(c.propuesta);
    if (!ya || c.publicado) vistas.set(c.propuesta, { numero: c.propuesta, estado: c.estado, tipoCambio: c.tipoCambio });
  }
  return [...vistas.values()].sort((a, b) => a.numero - b.numero);
});

const pasajeros = computed(() =>
    (props.file.filepasajeros ?? [])
        .filter(p => p['@id'])
        .map(p => ({ iri: p['@id'] as string, nombre: `${p.nombre ?? ''} ${p.apellido ?? ''}`.trim() || 'Sin nombre' })));

const formularioVacio = () => ({
  monto: '',
  moneda: 'USD' as 'USD' | 'PEN',
  tipoCambio: '',
  medio: 'transferencia_bancaria' as ApiCotizacionPago['medio'],
  fechaPago: hoyNaive(),
  propuesta: (cuenta.value?.propuestaBase ?? propuestas.value[0]?.numero ?? null) as number | null,
  pasajero: '',
  referencia: '',
  nota: '',
});

const form = ref(formularioVacio());
const formAbierto = ref(false);
const guardando = ref(false);
const error = ref('');

/** El tipo de cambio que vio el cliente: el de la propuesta que paga. */
const tipoCambioDePropuesta = (numero: number | null): string =>
    propuestas.value.find(p => p.numero === numero)?.tipoCambio ?? '';

watch(() => [form.value.moneda, form.value.propuesta] as const, ([moneda, propuesta]) => {
  if (moneda === 'PEN' && !form.value.tipoCambio) form.value.tipoCambio = tipoCambioDePropuesta(propuesta);
});

const equivalente = computed(() => {
  const tc = Number(form.value.tipoCambio);
  return form.value.moneda === 'PEN' && tc > 0 && Number(form.value.monto) > 0
      ? usd(Math.round(Number(form.value.monto) / tc * 100) / 100)
      : '';
});

const abrirAlta = () => {
  form.value = formularioVacio();
  error.value = '';
  formAbierto.value = true;
  abierto.value = true;
};

const registrar = async () => {
  if (guardando.value) return;
  guardando.value = true;
  error.value = '';
  try {
    await apiClient.post('/platform/sales/cotizacion_pagos', {
      file: props.file['@id'],
      monto: String(form.value.monto),
      moneda: form.value.moneda,
      tipoCambio: form.value.moneda === 'PEN' ? String(form.value.tipoCambio) : null,
      medio: form.value.medio,
      fechaPago: form.value.fechaPago,
      propuesta: form.value.propuesta,
      pasajero: form.value.pasajero || null,
      referencia: form.value.referencia || null,
      nota: form.value.nota || null,
    });
    formAbierto.value = false;
    emit('actualizado');
  } catch (e) {
    error.value = extractApiErrorMessage(e, 'No se pudo registrar el pago.');
  } finally {
    guardando.value = false;
  }
};

/* ── Anular ───────────────────────────────────────────────────────────────── */

const anulando = ref<string | null>(null);
const motivo = ref('');
const errorAnular = ref('');

const pedirAnular = (pago: ApiCotizacionPago) => {
  anulando.value = pago.id ?? null;
  motivo.value = '';
  errorAnular.value = '';
};

const anular = async () => {
  if (!anulando.value) return;
  errorAnular.value = '';
  try {
    await apiClient.patch(`/platform/sales/cotizacion_pagos/${anulando.value}/anular`,
        { motivoAnulacion: motivo.value },
        { headers: { 'Content-Type': 'application/merge-patch+json' } });
    anulando.value = null;
    emit('actualizado');
  } catch (e) {
    errorAnular.value = extractApiErrorMessage(e, 'No se pudo anular el pago.');
  }
};

const ESTADOS: Record<string, string> = {
  pendiente: 'pendiente', enviado: 'enviada', confirmado: 'confirmada', operado: 'operada', operativa: 'operativa',
};
</script>

<template>
  <div class="mb-8 bg-white rounded-3xl p-6 border border-slate-200 shadow-sm">
    <button type="button" @click="abierto = !abierto"
            class="w-full flex items-center justify-between gap-3 border-b border-slate-200 pb-3 mb-4 text-left group">
      <h2 class="text-sm font-black text-slate-800 uppercase tracking-widest min-w-0">
        <i class="fas fa-wallet mr-2 text-[#E07845]"></i> Pagos del cliente
        <span class="text-slate-300 font-bold normal-case tracking-normal ml-1">{{ resumen }}</span>
      </h2>
      <i class="fas fa-chevron-down text-slate-400 text-xs transition-transform shrink-0 group-hover:text-[#E07845]"
         :class="abierto ? 'rotate-180' : ''"></i>
    </button>

    <div v-show="abierto" class="space-y-5">
      <!-- La cuenta -->
      <div v-if="cuenta" class="grid grid-cols-3 gap-2">
        <div class="bg-slate-50 rounded-2xl border border-slate-200 p-3">
          <p class="text-[9px] font-black text-slate-400 uppercase tracking-widest">
            Total<template v-if="cuenta.propuestaBase"> · v{{ cuenta.propuestaBase }} {{ ESTADOS[cuenta.estadoBase ?? ''] ?? cuenta.estadoBase }}</template>
          </p>
          <p class="text-base font-black text-slate-800 tabular-nums mt-1">{{ usd(cuenta.totalUsd) }}</p>
        </div>
        <div class="bg-emerald-50 rounded-2xl border border-emerald-100 p-3">
          <p class="text-[9px] font-black text-emerald-600 uppercase tracking-widest">Pagado</p>
          <p class="text-base font-black text-emerald-700 tabular-nums mt-1">{{ usd(cuenta.pagadoUsd) }}</p>
        </div>
        <div class="rounded-2xl border p-3"
             :class="(cuenta.saldoUsd ?? 0) < 0 ? 'bg-red-50 border-red-200' : 'bg-orange-50 border-orange-200'">
          <p class="text-[9px] font-black uppercase tracking-widest"
             :class="(cuenta.saldoUsd ?? 0) < 0 ? 'text-red-600' : 'text-orange-600'">
            {{ (cuenta.saldoUsd ?? 0) < 0 ? 'Cobrado de más' : 'Saldo' }}
          </p>
          <p class="text-base font-black text-slate-800 tabular-nums mt-1">{{ usd(cuenta.saldoUsd == null ? null : Math.abs(cuenta.saldoUsd)) }}</p>
        </div>
      </div>
      <p v-if="cuenta && cuenta.totalUsd == null && (cuenta.pagadoUsd ?? 0) > 0" class="text-[11px] font-bold text-slate-500">
        Sin propuesta confirmada ni elegida no hay saldo que dar: marca en el pago qué propuesta se está pagando.
      </p>

      <p v-if="adelanto" class="text-[11px] font-bold" :class="adelanto.cubierto ? 'text-emerald-700' : 'text-slate-500'">
        <i class="fas mr-1" :class="adelanto.cubierto ? 'fa-circle-check' : 'fa-hourglass-half'"></i>
        Adelanto del {{ adelanto.porcentaje }} %: {{ usd(adelanto.monto) }} —
        {{ adelanto.cubierto ? 'cubierto' : 'pendiente' }}
      </p>

      <!-- Registrar un pago NO confirma: se avisa. Decisión de negocio, ver `CotizacionPago`. -->
      <div v-if="cuenta?.propuestaSinConfirmar"
           class="bg-amber-50 border border-amber-200 rounded-2xl px-4 py-3 text-[12px] font-bold text-amber-800">
        <i class="fas fa-triangle-exclamation mr-1"></i>
        Hay pagos sobre la propuesta {{ cuenta.propuestaSinConfirmar }}, que sigue sin confirmar. Confírmala cuando
        corresponda: registrar un pago no la confirma.
      </div>

      <!-- La lista -->
      <ul v-if="pagos.length" class="divide-y divide-slate-100">
        <li v-for="p in pagos" :key="p.id ?? ''" class="py-3">
          <div class="flex items-start justify-between gap-3" :class="p.anulado ? 'opacity-50' : ''">
            <div class="min-w-0">
              <p class="text-sm font-black text-slate-800" :class="p.anulado ? 'line-through' : ''">
                {{ importe(p.monto, p.moneda) }}
                <span class="text-[11px] font-bold text-slate-400 no-underline">· {{ MEDIO_PAGO_LABELS[p.medio] }}</span>
              </p>
              <p class="text-[11px] text-slate-500 font-medium mt-0.5">
                {{ fecha(p.fechaPago) }}
                <template v-if="p.propuesta"> · v{{ p.propuesta }}</template>
                <template v-if="p.moneda === 'PEN' && p.tipoCambio"> · TC {{ Number(p.tipoCambio) }}</template>
                <template v-if="p.referencia"> · {{ p.referencia }}</template>
                <template v-if="p.pasajeroNombre"> · {{ p.pasajeroNombre }}</template>
              </p>
              <p v-if="p.nota" class="text-[11px] text-slate-500 mt-0.5">{{ p.nota }}</p>
              <p class="text-[10px] text-slate-400 mt-0.5">
                <template v-if="p.registradoPorNombre">Registró {{ p.registradoPorNombre }}</template>
              </p>
              <p v-if="p.anulado" class="text-[11px] font-bold text-red-600 mt-1">
                Anulado<template v-if="p.anuladoPorNombre"> por {{ p.anuladoPorNombre }}</template>: {{ p.motivoAnulacion }}
              </p>
            </div>
            <button v-if="!p.anulado && anulando !== p.id" type="button" @click="pedirAnular(p)"
                    class="shrink-0 text-[10px] font-black uppercase tracking-widest text-slate-400 hover:text-red-500">
              Anular
            </button>
          </div>

          <div v-if="anulando === p.id" class="mt-2 flex flex-col sm:flex-row gap-2">
            <input v-model="motivo" type="text" placeholder="Por qué se anula (obligatorio)"
                   class="flex-1 bg-white border border-slate-300 rounded-xl px-3 py-2 text-sm outline-none focus:ring-2 focus:ring-red-300">
            <div class="flex gap-2">
              <button type="button" @click="anular" :disabled="!motivo.trim()"
                      class="px-3 py-2 rounded-xl bg-red-500 text-white text-xs font-black disabled:opacity-40">Anular pago</button>
              <button type="button" @click="anulando = null" class="px-3 py-2 rounded-xl border border-slate-300 text-xs font-black text-slate-500">Cancelar</button>
            </div>
            <p v-if="errorAnular" class="text-[11px] font-bold text-red-600">{{ errorAnular }}</p>
          </div>
        </li>
      </ul>

      <!-- El alta -->
      <button v-if="!formAbierto" type="button" @click="abrirAlta"
              class="w-full py-3 rounded-xl border-2 border-dashed border-[#E07845]/40 text-[#E07845] text-xs font-black uppercase tracking-widest hover:bg-[#E07845]/5">
        <i class="fas fa-plus mr-1"></i> Registrar pago
      </button>

      <form v-else @submit.prevent="registrar" class="bg-slate-50 border border-slate-200 rounded-2xl p-4 space-y-3">
        <div class="grid grid-cols-2 gap-3">
          <label class="block">
            <span class="block text-[10px] font-black text-slate-500 uppercase mb-1">Importe *</span>
            <input v-model="form.monto" type="number" step="0.01" min="0.01" required
                   class="w-full bg-white border border-slate-300 rounded-xl px-3 py-2 text-sm font-bold text-right outline-none focus:ring-2 focus:ring-[#376875]">
          </label>
          <label class="block">
            <span class="block text-[10px] font-black text-slate-500 uppercase mb-1">Moneda</span>
            <select v-model="form.moneda" class="w-full bg-white border border-slate-300 rounded-xl px-3 py-2 text-sm font-bold outline-none">
              <option value="USD">Dólares (USD)</option>
              <option value="PEN">Soles (PEN)</option>
            </select>
          </label>
          <label v-if="form.moneda === 'PEN'" class="block col-span-2">
            <span class="block text-[10px] font-black text-slate-500 uppercase mb-1">Tipo de cambio de ese día *</span>
            <input v-model="form.tipoCambio" type="number" step="0.0001" min="0.0001" required
                   class="w-full bg-white border border-slate-300 rounded-xl px-3 py-2 text-sm font-bold text-right outline-none focus:ring-2 focus:ring-[#376875]">
            <span v-if="equivalente" class="block text-[10px] text-slate-400 mt-1">Descuenta {{ equivalente }} del saldo.</span>
          </label>
          <label class="block">
            <span class="block text-[10px] font-black text-slate-500 uppercase mb-1">Medio</span>
            <select v-model="form.medio" class="w-full bg-white border border-slate-300 rounded-xl px-3 py-2 text-sm font-bold outline-none">
              <option v-for="(label, valor) in MEDIO_PAGO_LABELS" :key="valor" :value="valor">{{ label }}</option>
            </select>
          </label>
          <label class="block">
            <span class="block text-[10px] font-black text-slate-500 uppercase mb-1">Fecha del pago *</span>
            <input v-model="form.fechaPago" type="date" required
                   class="w-full bg-white border border-slate-300 rounded-xl px-3 py-2 text-sm font-bold outline-none">
          </label>
          <label class="block">
            <span class="block text-[10px] font-black text-slate-500 uppercase mb-1">Propuesta que paga</span>
            <select v-model="form.propuesta" class="w-full bg-white border border-slate-300 rounded-xl px-3 py-2 text-sm font-bold outline-none">
              <option :value="null">A cuenta del viaje</option>
              <option v-for="p in propuestas" :key="p.numero" :value="p.numero">v{{ p.numero }} · {{ ESTADOS[p.estado] ?? p.estado }}</option>
            </select>
          </label>
          <label class="block">
            <span class="block text-[10px] font-black text-slate-500 uppercase mb-1">Quién pagó</span>
            <select v-model="form.pasajero" class="w-full bg-white border border-slate-300 rounded-xl px-3 py-2 text-sm font-bold outline-none">
              <option value="">— El expediente —</option>
              <option v-for="p in pasajeros" :key="p.iri" :value="p.iri">{{ p.nombre }}</option>
            </select>
          </label>
          <label class="block col-span-2">
            <span class="block text-[10px] font-black text-slate-500 uppercase mb-1">Nº de operación</span>
            <input v-model="form.referencia" type="text" maxlength="100" placeholder="Voucher, código de Western Union…"
                   class="w-full bg-white border border-slate-300 rounded-xl px-3 py-2 text-sm outline-none focus:ring-2 focus:ring-[#376875]">
          </label>
          <label class="block col-span-2">
            <span class="block text-[10px] font-black text-slate-500 uppercase mb-1">Nota</span>
            <input v-model="form.nota" type="text"
                   class="w-full bg-white border border-slate-300 rounded-xl px-3 py-2 text-sm outline-none focus:ring-2 focus:ring-[#376875]">
          </label>
        </div>
        <p v-if="error" class="text-[12px] font-bold text-red-600">{{ error }}</p>
        <div class="flex gap-2 justify-end">
          <button type="button" @click="formAbierto = false" class="px-4 py-2 rounded-xl border border-slate-300 text-xs font-black text-slate-500">Cancelar</button>
          <button type="submit" :disabled="guardando"
                  class="px-4 py-2 rounded-xl bg-[#E07845] text-white text-xs font-black uppercase tracking-widest disabled:opacity-50">
            {{ guardando ? 'Guardando…' : 'Registrar' }}
          </button>
        </div>
      </form>
    </div>
  </div>
</template>
