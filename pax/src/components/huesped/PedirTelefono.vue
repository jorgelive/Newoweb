<script setup lang="ts">
import { ref } from 'vue';
import { useMaestroStore } from '@/stores/maestroStore';
import { paxHuespedService } from '@/services/paxHuespedService';

/**
 * «¿Nos dejas tu WhatsApp?» — en la página de la reserva, cuando llegó sin teléfono.
 *
 * Booking dejó de pasar el número (octubre de 2026), y sin él sólo se le puede escribir por la
 * mensajería de Beds24. Lo que se escribe aquí va a SU reserva: entra por su localizador. El
 * backend valida el número (con el país de la reserva si viene sin prefijo), lo guarda y sus
 * avisos pendientes ganan la cola de WhatsApp. Ver `docs/Mensajeria.md`, «Pedirle el teléfono
 * al huésped».
 *
 * La pinta `PmsReservaView` sólo si el backend dice `necesitaTelefono` Y existe el texto del
 * título: sin sus traducciones saldría en español a todo el mundo.
 */
const props = defineProps<{ localizador: string }>();
const emit = defineEmits<{ (e: 'guardado'): void }>();

const maestroStore = useMaestroStore();

const telefono = ref('');
const enviando = ref(false);
const guardado = ref(false);
const error = ref<string | null>(null);

const guardar = async (): Promise<void> => {
  if (!telefono.value.trim() || enviando.value) return;

  enviando.value = true;
  error.value = null;
  const resultado = await paxHuespedService.guardarTelefono(props.localizador, telefono.value);
  enviando.value = false;

  // `ya_tenemos` también es un final feliz para quien escribe: su número ya está.
  if (resultado === 'guardado' || resultado === 'ya_tenemos') {
    guardado.value = true;
    emit('guardado');
    return;
  }

  error.value = resultado === 'invalido'
    ? (maestroStore.t('res_tel_invalido') || 'Revisa el número: escríbelo con el código de tu país (+…).')
    : (maestroStore.t('res_tel_error') || 'No pudimos guardarlo. Inténtalo de nuevo en un momento.');
};
</script>

<template>
  <section class="bg-white rounded-[2.5rem] shadow-xl shadow-slate-300/40 ring-1 ring-slate-200/70 border border-slate-200 overflow-hidden mb-6">
    <div class="p-5 md:p-8">
      <div v-if="guardado" class="flex items-center gap-3">
        <span class="w-10 h-10 rounded-2xl bg-emerald-50 text-emerald-600 flex items-center justify-center shrink-0">
          <i class="fas fa-check" aria-hidden="true"></i>
        </span>
        <p class="text-sm font-bold text-slate-700">{{ maestroStore.t('res_tel_gracias') || '¡Gracias! Te escribiremos por WhatsApp.' }}</p>
      </div>

      <form v-else @submit.prevent="guardar">
        <div class="flex items-start gap-3 mb-4">
          <span class="w-10 h-10 rounded-2xl bg-[#25D366]/10 text-[#25D366] flex items-center justify-center shrink-0">
            <i class="fab fa-whatsapp text-lg" aria-hidden="true"></i>
          </span>
          <div class="min-w-0">
            <h2 class="text-base md:text-lg font-black text-slate-800 leading-tight">{{ maestroStore.t('res_tel_titulo') }}</h2>
            <p class="text-sm text-slate-500 mt-1 leading-snug">{{ maestroStore.t('res_tel_texto') }}</p>
          </div>
        </div>

        <div class="flex gap-2">
          <input v-model="telefono" type="tel" inputmode="tel" autocomplete="tel" required
                 :aria-label="maestroStore.t('res_tel_titulo')"
                 class="flex-1 min-w-0 border border-slate-200 rounded-2xl px-4 py-3 text-base font-bold text-slate-800 focus:outline-none focus:ring-2 focus:ring-[#376875]" />
          <button type="submit" :disabled="enviando || !telefono.trim()"
                  class="px-5 py-3 rounded-2xl bg-[#376875] text-white text-xs font-black uppercase tracking-widest disabled:opacity-40 shrink-0">
            <i v-if="enviando" class="fas fa-circle-notch fa-spin mr-1" aria-hidden="true"></i>{{ maestroStore.t('res_tel_guardar') || 'Guardar' }}
          </button>
        </div>
        <p class="text-[11px] font-bold text-slate-400 mt-2">{{ maestroStore.t('res_tel_ejemplo') }}</p>
        <p v-if="error" class="text-xs font-bold text-red-600 mt-2">{{ error }}</p>
      </form>
    </div>
  </section>
</template>
