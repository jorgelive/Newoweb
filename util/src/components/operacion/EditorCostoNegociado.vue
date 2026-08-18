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

const props = defineProps<{
    /** Lo que dijo el cotizador. Referencia, no se edita: es el placeholder y el «cotizado». */
    costoCotizado: string;
    monedaCotizada: string;
    /** Lo negociado hoy. Vacío/0 = todavía sin negociar. */
    costoReal: string | null;
    monedaReal: string | null;
    /** Códigos de moneda del maestro (`PEN`, `USD`…). */
    monedas: string[];
    /** Compacto para la tarjeta móvil; algo mayor en escritorio. */
    denso?: boolean;
}>();

const emit = defineEmits<{
    (e: 'guardar', payload: { costoRealOperativo: string; monedaReal: string }): void;
}>();

const PATRON_IMPORTE = /^\d{1,10}([.,]\d{1,2})?$/;

const editando = ref(false);
const guardado = ref(false);          // ✓ efímero tras guardar
const error = ref<string | null>(null);

// Borrador LOCAL: sólo existe mientras esta fila está en edición.
const borradorCosto = ref('');
const borradorMoneda = ref('');
const inputRef = ref<HTMLInputElement | null>(null);

const hayNegociado = computed(() => Number(props.costoReal ?? 0) > 0);
const monedaMostrada = computed(() => props.monedaReal || props.monedaCotizada);

/** El importe formateado para leer en reposo. */
const importeReposo = computed(() =>
    hayNegociado.value ? Number(props.costoReal).toFixed(2) : '');

const abrir = async (): Promise<void> => {
    error.value = null;
    borradorCosto.value = hayNegociado.value ? Number(props.costoReal).toFixed(2) : '';
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

    let costoRealOperativo: string;
    if (valor === '') {
        costoRealOperativo = '0.00';   // vaciar = «vuelve a no estar negociado»
    } else if (PATRON_IMPORTE.test(valor)) {
        costoRealOperativo = Number(valor).toFixed(2);
    } else {
        error.value = `«${borradorCosto.value}» no es un importe.`;
        return;
    }

    // El padre resuelve el IRI y hace el PATCH; aquí sólo se decide QUÉ guardar. La moneda
    // se manda SIEMPRE, no sólo cuando cambia: si se guarda importe sin moneda, el backend
    // suma por `monedaReal` y quedaría desparejado del número que el operador está viendo.
    emit('guardar', { costoRealOperativo, monedaReal: borradorMoneda.value });

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
    <!-- ── REPOSO: sólo el valor, tocable ────────────────────────────────── -->
    <button
        v-if="!editando"
        type="button"
        @click="abrir"
        class="inline-flex items-baseline gap-1 rounded px-1 -mx-1 hover:bg-slate-100 active:scale-95 transition-all"
        :class="denso ? 'text-[11px]' : 'text-sm'"
    >
      <span class="font-bold text-slate-400" :class="denso ? 'text-[9px]' : 'text-[10px]'">
        {{ monedaMostrada }}
      </span>
      <!-- Sin negociar se muestra el COTIZADO mismo (atenuado, editable): una sola línea. Antes
           se apilaban «registrar» y «cotizado», que confundía y ocupaba el doble. -->
      <span v-if="hayNegociado" class="font-black text-slate-800 tabular-nums decoration-dotted underline decoration-slate-300 underline-offset-2">
        {{ importeReposo }}
      </span>
      <span v-else class="font-bold text-slate-400 italic tabular-nums decoration-dotted underline decoration-slate-300 underline-offset-2">
        {{ Number(costoCotizado).toFixed(2) }}
      </span>
      <i v-if="guardado" class="fas fa-check text-emerald-600 text-[10px] ml-0.5"></i>
    </button>

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

    <!-- El cotizado de referencia SÓLO cuando ya hay un negociado que comparar. Sin negociar el
         valor de arriba YA es el cotizado, así que repetirlo aquí era el ruido que confundía. -->
    <p v-if="hayNegociado" class="text-slate-400 tabular-nums" :class="denso ? 'text-[9px]' : 'text-[10px]'">
      cotizado {{ monedaCotizada }} {{ Number(costoCotizado).toFixed(2) }}
    </p>

    <p v-if="error" class="text-[10px] font-bold text-rose-600 text-right leading-snug max-w-[10rem]">
      <i class="fas fa-triangle-exclamation mr-1"></i>{{ error }}
    </p>
  </div>
</template>
