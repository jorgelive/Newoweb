<script setup lang="ts">
import { computed, ref } from 'vue';
import type { components } from '@dominio/api';
import { apiClient } from '@/services/apiClient';
import { formatearTelefono } from '@/utils/telefono';
import { useDuenioDeIdentificador } from '@/composables/useDuenioDeIdentificador';

/**
 * «El teléfono de este hilo ya es de otro: ¿son la misma persona?» — con los dos botones.
 *
 * 🔥 **El choque existía y no lo veía nadie.** Cuando un teléfono llega por el dominio —el pull de
 * Beds24, una reserva a la que se le pone después— y ya es de otro hilo, no se mueve: unir
 * historiales lo decide una persona. Pero lo único que quedaba era una línea en el log, y el hilo
 * sin teléfono: la guía de llegada y el aviso de salida de Adrián (02/10/2026) se quedaron en
 * `sin_canal` sin que nadie se enterara. El backend apunta ahora la sugerencia
 * (`MessageConversation::sugerirFusion()`), avisa al equipo, y esto la pinta.
 *
 * Va en el chat y en la reserva. La fusión es la misma que la del editor de identidades —previa
 * primero, porque quién sobrevive lo decide la antigüedad y no quien pulsa— y por eso pasa por
 * `useDuenioDeIdentificador`.
 */
type FusionSugerida = NonNullable<components['schemas']['Conversation-conversation.read']['fusionSugerida']>;

const props = defineProps<{
  conversacionId: string;
  sugerencia: FusionSugerida;
}>();

const emit = defineEmits<{
  /** Ya unidos. El superviviente puede NO ser este hilo: quien escucha decide a dónde ir. */
  (e: 'fusionado', supervivienteId: string): void;
  (e: 'descartado'): void;
}>();

const fusion = useDuenioDeIdentificador(() => true);

const previa = ref<Awaited<ReturnType<typeof fusion.previaDeFusion>>>(null);
const ocupado = ref(false);
const error = ref<string | null>(null);

const dato = computed(() => props.sugerencia.tipo === 'telefono'
  ? `El teléfono ${formatearTelefono('+' + props.sugerencia.valor) || props.sugerencia.valor}`
  : `El correo ${props.sugerencia.valor}`);

const pedirPrevia = async (): Promise<void> => {
  ocupado.value = true;
  error.value = null;
  previa.value = await fusion.previaDeFusion(props.conversacionId, props.sugerencia.con);
  ocupado.value = false;

  if (!previa.value) error.value = 'No se pudo preparar la unión.';
};

const unir = async (): Promise<void> => {
  ocupado.value = true;
  const r = await fusion.fusionar(props.conversacionId, props.sugerencia.con);
  ocupado.value = false;

  if ('error' in r) { error.value = r.error; return; }

  previa.value = null;
  emit('fusionado', r.supervivienteId);
};

const descartar = async (): Promise<void> => {
  ocupado.value = true;
  error.value = null;

  try {
    await apiClient.post(`/platform/message/conversations/${props.conversacionId}/fusion/descartar`, {});
    emit('descartado');
  } catch {
    error.value = 'No se pudo guardar. Inténtalo de nuevo.';
  } finally {
    ocupado.value = false;
  }
};
</script>

<template>
  <div class="text-[11px] font-bold text-amber-800 bg-amber-50 border border-amber-200 rounded-xl px-3 py-2 leading-snug">
    <p>
      <i class="fas fa-link mr-1"></i>{{ dato }} ya está en la conversación de
      <RouterLink :to="{ name: 'chat_conversation', params: { conversationId: sugerencia.con } }"
                  class="underline hover:no-underline">{{ sugerencia.nombre || 'otra persona' }}</RouterLink>.
      Mientras no se unan, lo que tenga programado aquí puede no salir.
    </p>

    <div v-if="!previa" class="flex flex-wrap gap-1.5 mt-1.5">
      <button type="button" @click="pedirPrevia" :disabled="ocupado"
              class="px-2 py-1 bg-amber-600 hover:bg-amber-700 text-white rounded-lg text-[10px] font-black uppercase tracking-wider transition-colors disabled:opacity-50">
        <i class="fas fa-code-merge mr-1"></i>Es la misma persona: unir
      </button>
      <button type="button" @click="descartar" :disabled="ocupado"
              class="px-2 py-1 bg-white border border-amber-300 hover:bg-amber-100 rounded-lg text-[10px] font-black uppercase tracking-wider text-amber-800 transition-colors disabled:opacity-50">
        No es la misma persona
      </button>
    </div>

    <!-- Lo mismo que el editor de identidades: se enseña quién sobrevive antes de aplicarlo, y
         que no se deshace. -->
    <div v-else class="mt-1.5 bg-white border border-amber-300 rounded-lg p-2">
      <p class="text-slate-600 leading-snug">
        Sobrevive <b class="text-slate-800">{{ previa.superviviente.nombre || 'el hilo más antiguo' }}</b>
        ({{ previa.superviviente.mensajes }} mensajes) y absorbe
        <b class="text-slate-800">{{ previa.absorbido.nombre || 'el otro' }}</b>
        ({{ previa.absorbido.mensajes }} mensajes, {{ previa.absorbido.asuntos }} asuntos).
        <span class="text-amber-700">Esto no se deshace.</span>
      </p>
      <div class="flex gap-1.5 mt-1.5">
        <button type="button" @click="previa = null"
                class="px-2 py-1 border border-slate-200 rounded-lg text-[10px] font-bold text-slate-500">Cancelar</button>
        <button type="button" @click="unir" :disabled="ocupado"
                class="px-2 py-1 bg-amber-600 hover:bg-amber-700 text-white rounded-lg text-[10px] font-black uppercase tracking-wider disabled:opacity-50">
          <i v-if="ocupado" class="fas fa-circle-notch fa-spin mr-1"></i>Unir
        </button>
      </div>
    </div>

    <p v-if="error" class="text-red-600 mt-1.5">{{ error }}</p>
  </div>
</template>
