<script setup lang="ts">
import { computed, ref } from 'vue';
import { useMaestroStore } from '@/stores/maestroStore';
import { paxHuespedService } from '@/services/paxHuespedService';

/**
 * «¿Nos dejas tu WhatsApp?» — en la página de la reserva, cuando no tenemos uno que funcione.
 *
 * Dos casos, con su propio texto: no hay número (Booking dejó de pasarlo en octubre de 2026), o
 * el que hay no tiene WhatsApp (Meta lo vetó: mal escrito o sin cuenta). En el segundo se le dicen
 * las tres últimas cifras para que reconozca cuál era. Lo que se escribe aquí va a SU reserva:
 * entra por su localizador. Ver `docs/Mensajeria.md`, «Pedirle el teléfono al huésped».
 *
 * Y una salida para quien no quiere darlo: «Prefiero no dejarlo», con confirmación, y no se le
 * vuelve a pedir (`TelefonoDelHuesped::rechazar()`).
 *
 * La pinta `PmsReservaView` sólo si existe el texto del título: sin sus traducciones saldría en
 * español a todo el mundo. Por lo mismo, cada variante cae al texto general si la suya falta, y el
 * «prefiero no dejarlo» no sale hasta que existan sus textos.
 */
const props = defineProps<{
  localizador: string;
  /** Tres últimas cifras del número sin WhatsApp; null si simplemente no hay número. */
  terminadoEn: string | null;
}>();
const emit = defineEmits<{ (e: 'guardado'): void }>();

const maestroStore = useMaestroStore();

type Paso = 'pedir' | 'confirmarNo' | 'gracias' | 'entendido';

const paso = ref<Paso>('pedir');
const telefono = ref('');
const enviando = ref(false);
const error = ref<string | null>(null);

/** El texto de una variante, o el general si la variante aún no tiene el suyo. */
const texto = (clave: string, general: string, vars?: Record<string, string>): string =>
  maestroStore.t(clave, vars) || maestroStore.t(general, vars);

const titulo = computed(() => props.terminadoEn
  ? texto('res_tel_titulo_veto', 'res_tel_titulo')
  : maestroStore.t('res_tel_titulo'));

const explicacion = computed(() => props.terminadoEn
  ? texto('res_tel_texto_veto', 'res_tel_texto', { fin: props.terminadoEn })
  : maestroStore.t('res_tel_texto'));

const puedeRechazar = computed(() => !!maestroStore.t('res_tel_no_quiero'));

const guardar = async (): Promise<void> => {
  if (!telefono.value.trim() || enviando.value) return;

  enviando.value = true;
  error.value = null;
  const resultado = await paxHuespedService.guardarTelefono(props.localizador, telefono.value);
  enviando.value = false;

  // `ya_tenemos` también es un final feliz para quien escribe: su número ya está.
  if (resultado === 'guardado' || resultado === 'ya_tenemos') {
    paso.value = 'gracias';
    emit('guardado');
    return;
  }

  error.value = resultado === 'ese_no_tiene_whatsapp'
    ? (maestroStore.t('res_tel_ese_no') || maestroStore.t('res_tel_invalido') || 'Ese número no tiene WhatsApp. ¿Tienes otro?')
    : resultado === 'invalido'
      ? (maestroStore.t('res_tel_invalido') || 'Revisa el número: escríbelo con el código de tu país (+…).')
      : (maestroStore.t('res_tel_error') || 'No pudimos guardarlo. Inténtalo de nuevo en un momento.');
};

const rechazar = async (): Promise<void> => {
  enviando.value = true;
  error.value = null;
  const resultado = await paxHuespedService.rechazarTelefono(props.localizador);
  enviando.value = false;

  if (resultado === 'rechazado' || resultado === 'ya_tenemos') {
    paso.value = 'entendido';
    emit('guardado');
    return;
  }

  error.value = maestroStore.t('res_tel_error') || 'No pudimos guardarlo. Inténtalo de nuevo en un momento.';
};
</script>

<template>
  <section class="bg-white rounded-[2.5rem] shadow-xl shadow-slate-300/40 ring-1 ring-slate-200/70 border border-slate-200 overflow-hidden mb-6">
    <div class="p-5 md:p-8">
      <div v-if="paso === 'gracias' || paso === 'entendido'" class="flex items-center gap-3">
        <span class="w-10 h-10 rounded-2xl bg-emerald-50 text-emerald-600 flex items-center justify-center shrink-0">
          <i class="fas fa-check" aria-hidden="true"></i>
        </span>
        <p class="text-sm font-bold text-slate-700">
          {{ paso === 'gracias'
            ? (maestroStore.t('res_tel_gracias') || '¡Gracias! Te escribiremos por WhatsApp.')
            : maestroStore.t('res_tel_entendido') }}
        </p>
      </div>

      <!-- «Prefiero no dejarlo»: se confirma, porque sin WhatsApp se pierde lo que más le sirve
           al llegar, y no se le vuelve a preguntar. -->
      <div v-else-if="paso === 'confirmarNo'">
        <p class="text-sm font-bold text-slate-700 leading-snug">{{ maestroStore.t('res_tel_confirmar') }}</p>
        <div class="flex flex-wrap gap-2 mt-4">
          <button type="button" @click="paso = 'pedir'"
                  class="px-5 py-3 rounded-2xl bg-[#376875] text-white text-xs font-black uppercase tracking-widest">
            {{ maestroStore.t('res_tel_volver') }}
          </button>
          <button type="button" @click="rechazar" :disabled="enviando"
                  class="px-5 py-3 rounded-2xl border border-slate-200 text-slate-500 text-xs font-black uppercase tracking-widest disabled:opacity-40">
            <i v-if="enviando" class="fas fa-circle-notch fa-spin mr-1" aria-hidden="true"></i>{{ maestroStore.t('res_tel_si_seguro') }}
          </button>
        </div>
        <p v-if="error" class="text-xs font-bold text-red-600 mt-2">{{ error }}</p>
      </div>

      <form v-else @submit.prevent="guardar">
        <div class="flex items-start gap-3 mb-4">
          <span class="w-10 h-10 rounded-2xl bg-[#25D366]/10 text-[#25D366] flex items-center justify-center shrink-0">
            <i class="fab fa-whatsapp text-lg" aria-hidden="true"></i>
          </span>
          <div class="min-w-0">
            <h2 class="text-base md:text-lg font-black text-slate-800 leading-tight">{{ titulo }}</h2>
            <p class="text-sm text-slate-500 mt-1 leading-snug">{{ explicacion }}</p>
          </div>
        </div>

        <div class="flex gap-2">
          <input v-model="telefono" type="tel" inputmode="tel" autocomplete="tel" required
                 :aria-label="titulo"
                 class="flex-1 min-w-0 border border-slate-200 rounded-2xl px-4 py-3 text-base font-bold text-slate-800 focus:outline-none focus:ring-2 focus:ring-[#376875]" />
          <button type="submit" :disabled="enviando || !telefono.trim()"
                  class="px-5 py-3 rounded-2xl bg-[#376875] text-white text-xs font-black uppercase tracking-widest disabled:opacity-40 shrink-0">
            <i v-if="enviando" class="fas fa-circle-notch fa-spin mr-1" aria-hidden="true"></i>{{ maestroStore.t('res_tel_guardar') || 'Guardar' }}
          </button>
        </div>
        <p class="text-[11px] font-bold text-slate-400 mt-2">{{ maestroStore.t('res_tel_ejemplo') }}</p>
        <p v-if="error" class="text-xs font-bold text-red-600 mt-2">{{ error }}</p>

        <button v-if="puedeRechazar" type="button" @click="paso = 'confirmarNo'; error = null"
                class="mt-3 text-[11px] font-bold text-slate-400 underline hover:text-slate-600">
          {{ maestroStore.t('res_tel_no_quiero') }}
        </button>
      </form>
    </div>
  </section>
</template>
