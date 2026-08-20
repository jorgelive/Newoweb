<script setup lang="ts">
/**
 * Edita el costo NEGOCIADO de un servicio: importe + moneda. Con estado LOCAL.
 *
 * ── Por qué un componente y no tres bloques compartiendo helpers ─────────────
 * Este editor vivía tres veces —columna de escritorio, tarjeta móvil, detalle de orden—
 * compartiendo dos `Record` globales indexados por id de servicio. Cuando la orden empezó a
 * traer los servicios embebidos SIN id, todas las filas cayeron en la misma clave vacía:
 * escribir en una las pintaba todas iguales, y el borrador de una se colaba en las otras dos
 * vistas. El estado compartido era la enfermedad; el id ausente sólo la destapó.
 *
 * Con estado por instancia esa clase de fallo es imposible por construcción: cada editor sólo
 * conoce lo suyo. El padre no gestiona borradores — recibe un `guardar` con el payload listo.
 *
 * ── Click-to-edit ───────────────────────────────────────────────────────────
 * En reposo se ve sólo el VALOR, como texto. Un toque lo abre. Es lo que pidió el operador
 * («que el campo sólo aparezca al editar»): en un móvil, N filas con un formulario abierto
 * cada una es ruido, y el valor a secas es el objetivo de toque más grande que ya existe. El
 * subrayado punteado y el «registrar» en vacío lo anuncian como editable.
 */
import { ref, computed, nextTick } from 'vue';
import InfoTooltip from '@/components/common/InfoTooltip.vue';

const props = defineProps<{
    /** Lo que dijo el cotizador. Referencia, no se edita: es el placeholder y el «cotizado». */
    costoCotizado: string;
    monedaCotizada: string;
    /** Lo negociado hoy. Vacío/0 = todavía sin negociar. */
    costoNegociado: string | null;
    monedaNegociada: string | null;
    /** Códigos de moneda del maestro (`PEN`, `USD`…). */
    monedas: string[];
    /** Compacto para la tarjeta móvil; algo mayor en escritorio. */
    denso?: boolean;
    /**
     * De dónde sale el `costoCotizado`: una línea por tarifa (unitario × cantidad × unidades).
     * Viene de `OperacionServicio.desgloseCotizado`. Alimenta el tooltip del cotizado.
     */
    desglose?: DesgloseLinea[];
}>();

interface DesgloseLinea {
    concepto: string;
    unitario: string;
    cantidad: number;
    unidades: number;
    subtotal: string;
    moneda?: string | null;
}

const emit = defineEmits<{
    (e: 'guardar', payload: { costoNegociado: string; monedaNegociada: string }): void;
}>();

const PATRON_IMPORTE = /^\d{1,10}([.,]\d{1,2})?$/;

const editando = ref(false);
const guardado = ref(false);          // ✓ efímero tras guardar
const error = ref<string | null>(null);

// Borrador LOCAL: sólo existe mientras esta fila está en edición.
const borradorCosto = ref('');
const borradorMoneda = ref('');
const inputRef = ref<HTMLInputElement | null>(null);

const hayNegociado = computed(() => Number(props.costoNegociado ?? 0) > 0);
// El cotizado de referencia sólo tiene sentido cuando el negociado LO CAMBIA. Si se negoció el
// mismo importe (o no se negoció), repetirlo es ruido: se muestra una sola línea.
const difiereDelCotizado = computed(() =>
    hayNegociado.value
    && Number(props.costoNegociado).toFixed(2) !== Number(props.costoCotizado).toFixed(2));
const monedaMostrada = computed(() => props.monedaNegociada || props.monedaCotizada);

/** El importe formateado para leer en reposo. */
const importeReposo = computed(() =>
    hayNegociado.value ? Number(props.costoNegociado).toFixed(2) : '');

const abrir = async (): Promise<void> => {
    error.value = null;
    borradorCosto.value = hayNegociado.value ? Number(props.costoNegociado).toFixed(2) : '';
    borradorMoneda.value = monedaMostrada.value;
    editando.value = true;
    await nextTick();
    inputRef.value?.focus();
    inputRef.value?.select();
};

const cancelar = (): void => {
    editando.value = false;
    error.value = null;
};

const guardar = (): void => {
    const valor = borradorCosto.value.trim().replace(',', '.');

    let costoNegociado: string;
    if (valor === '') {
        costoNegociado = '0.00';   // vaciar = «vuelve a no estar negociado»
    } else if (PATRON_IMPORTE.test(valor)) {
        costoNegociado = Number(valor).toFixed(2);
    } else {
        error.value = `«${borradorCosto.value}» no es un importe.`;
        return;
    }

    // El padre resuelve el IRI y hace el PATCH; aquí sólo se decide QUÉ guardar. La moneda
    // se manda SIEMPRE, no sólo cuando cambia: si se guarda importe sin moneda, el backend
    // suma por `monedaNegociada` y quedaría desparejado del número que el operador está viendo.
    emit('guardar', { costoNegociado, monedaNegociada: borradorMoneda.value });

    editando.value = false;
    guardado.value = true;
    setTimeout(() => { guardado.value = false; }, 2500);
};

/** Lo llama el padre si el PATCH falla: reabre con lo escrito para reintentar. */
const marcarError = (mensaje: string): void => {
    error.value = mensaje;
    guardado.value = false;
    editando.value = true;
};

defineExpose({ marcarError });
</script>

<template>
  <div class="inline-flex flex-col items-end gap-0.5">
    <!-- ── REPOSO: valor + «antes» tachado inline + desglose (layout B) ───── -->
    <div v-if="!editando" class="inline-flex items-baseline gap-1.5">
      <button
          type="button"
          @click="abrir"
          class="inline-flex items-baseline gap-1 rounded px-1 -mx-1 hover:bg-slate-100 active:scale-95 transition-all"
          :class="denso ? 'text-[11px]' : 'text-sm'"
      >
        <span class="font-bold text-slate-400" :class="denso ? 'text-[9px]' : 'text-[10px]'">
          {{ monedaMostrada }}
        </span>
        <!-- Sin negociar se muestra el COTIZADO mismo (atenuado, editable). -->
        <span v-if="hayNegociado" class="font-black text-slate-800 tabular-nums decoration-dotted underline decoration-slate-300 underline-offset-2">
          {{ importeReposo }}
        </span>
        <span v-else class="font-bold text-slate-400 italic tabular-nums decoration-dotted underline decoration-slate-300 underline-offset-2">
          {{ Number(costoCotizado).toFixed(2) }}
        </span>
        <i v-if="guardado" class="fas fa-check text-emerald-600 text-[10px] ml-0.5"></i>
      </button>

      <!-- «antes X» tachado, EN LÍNEA (layout B), sólo si el negociado difiere del cotizado. -->
      <span v-if="difiereDelCotizado" class="text-slate-400 tabular-nums" :class="denso ? 'text-[9px]' : 'text-[10px]'">
        antes <s class="decoration-slate-300">{{ Number(costoCotizado).toFixed(2) }}</s>
      </span>

      <!-- De dónde sale el cotizado. Reutiliza InfoTooltip (hover en escritorio, tap en táctil,
           teletransportado al body). Ver docs/UI_Componentes_Compartidos.md. -->
      <InfoTooltip v-if="desglose && desglose.length" lado="derecha" ancho="max-w-[15rem]"
                   :clase-icono="denso ? 'text-slate-300 hover:text-[#376875] text-[9px]' : 'text-slate-300 hover:text-[#376875]'">
        <p class="font-black text-slate-100 mb-1.5 text-[10px] uppercase tracking-wider">De dónde sale el cotizado</p>
        <div class="flex flex-col gap-1">
          <div v-for="(l, i) in desglose" :key="i" class="flex items-baseline justify-between gap-3 tabular-nums">
            <span class="text-slate-300 truncate max-w-[7rem]">{{ l.concepto }}</span>
            <span class="text-slate-400 whitespace-nowrap">
              {{ Number(l.unitario).toFixed(2) }} × {{ l.cantidad }} × {{ l.unidades }}
              <span class="font-bold text-slate-100 ml-1">= {{ Number(l.subtotal).toFixed(2) }}</span>
            </span>
          </div>
        </div>
        <p class="mt-1.5 pt-1.5 border-t border-slate-600 text-right font-black text-slate-100 tabular-nums">
          Total {{ monedaCotizada }} {{ Number(costoCotizado).toFixed(2) }}
        </p>
        <p class="mt-1 text-[9px] text-slate-500 leading-tight">unitario × cantidad × noches/días</p>
      </InfoTooltip>
    </div>

    <!-- ── EDICIÓN: select + input + guardar / cancelar ──────────────────── -->
    <div v-else class="flex items-center gap-1">
      <select
          v-model="borradorMoneda"
          class="font-black text-slate-500 bg-white border border-slate-200 rounded px-1 py-1 outline-none focus:ring-2 focus:ring-[#376875]"
          :class="denso ? 'text-[9px]' : 'text-[10px]'"
      >
        <option v-for="m in monedas" :key="m" :value="m">{{ m }}</option>
      </select>
      <input
          ref="inputRef"
          v-model="borradorCosto"
          @keyup.enter="guardar"
          @keyup.esc="cancelar"
          :placeholder="Number(costoCotizado).toFixed(2)"
          inputmode="decimal"
          maxlength="13"
          class="text-right font-black text-slate-800 bg-amber-50 border border-amber-400 rounded px-2 py-1 tabular-nums outline-none focus:ring-2 focus:ring-[#376875] placeholder:text-slate-300 placeholder:font-medium"
          :class="denso ? 'w-[4.5rem] text-[11px]' : 'w-[5.5rem] text-sm'"
      />
      <button
          type="button"
          @click="guardar"
          class="w-7 h-7 shrink-0 rounded bg-[#E07845] hover:bg-[#c96636] text-white flex items-center justify-center shadow-sm active:scale-95 transition-all"
          title="Guardar"
      >
        <i class="fas fa-check text-[11px]"></i>
      </button>
      <button
          type="button"
          @click="cancelar"
          class="w-7 h-7 shrink-0 rounded bg-slate-100 hover:bg-slate-200 text-slate-500 flex items-center justify-center active:scale-95 transition-all"
          title="Cancelar"
      >
        <i class="fas fa-xmark text-[11px]"></i>
      </button>
    </div>

    <p v-if="error" class="text-[10px] font-bold text-rose-600 text-right leading-snug max-w-[10rem]">
      <i class="fas fa-triangle-exclamation mr-1"></i>{{ error }}
    </p>
  </div>
</template>
