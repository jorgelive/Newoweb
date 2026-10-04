<script setup lang="ts">
import { onMounted, ref, watch } from 'vue';
import { apiClient } from '@/services/apiClient';
import { useChatStore } from '@/stores/chat/chatStore';
import { useDuenioDeIdentificador } from '@/composables/useDuenioDeIdentificador';

/**
 * «¿De qué asunto es esta persona?» — cuelga un asunto de ESTE hilo.
 *
 * Carla escribió «les escribo sobre mi reserva» desde su número, sin decir de quién (12/09/2026).
 * El hilo nació «manual», el agente la trató como desconocida y el equipo tuvo que adivinar que era
 * del grupo de Bruna por el prefijo +55 85. Esto pone esa adivinanza delante.
 *
 * ⚠️ **El chat no sabe qué es una reserva.** Los candidatos los propone cada dominio
 * (`CandidatosDeAsuntoInterface`, vía `GET /conversations/{id}/asuntos/candidatos`) con su
 * etiqueta y su motivo; aquí se pintan y se devuelve el par `contextType`/`contextId`. Un dominio
 * nuevo —Travel— aparece aquí sin tocar este archivo.
 *
 * Dos respuestas, y no una, porque son dos situaciones:
 *
 * - **Acompañante**: otra persona del grupo. Su hilo se queda suyo y sabe de qué le hablan, pero
 *   la agenda automática y lo que es del titular siguen siendo del titular.
 * - **Es el titular**: la misma persona con otro número. Se UNE con su hilo, con la misma previa
 *   que el editor de identidades, porque quién sobrevive lo decide la antigüedad y no quien pulsa.
 */

/** 🪞 Espejo de `CandidatoDeAsunto::comoArray()`: el endpoint no entra en `api.d.ts` (`output: false`). */
interface CandidatoDeAsunto {
  contextType: string;
  contextId: string;
  etiqueta: string;
  motivo: string | null;
}

const props = defineProps<{
  conversacionId: string;
}>();

const emit = defineEmits<{
  (e: 'enlazado'): void;
  /** Ya unidos. El superviviente puede NO ser este hilo: quien escucha decide a dónde ir. */
  (e: 'fusionado', supervivienteId: string): void;
}>();

const chat = useChatStore();
const fusion = useDuenioDeIdentificador(() => true);

const candidatos = ref<CandidatoDeAsunto[]>([]);
const busqueda = ref('');
const cargando = ref(false);
const ocupado = ref(false);
const error = ref<string | null>(null);

/** La previa de la unión con el hilo titular, cuando se eligió «Es el titular». */
const previa = ref<Awaited<ReturnType<typeof fusion.previaDeFusion>>>(null);
const titularElegido = ref<string | null>(null);

let controlador: AbortController | null = null;

const cargar = async (q: string): Promise<void> => {
  controlador?.abort();
  controlador = new AbortController();
  cargando.value = true;

  try {
    const { data } = await apiClient.get(`/platform/message/conversations/${props.conversacionId}/asuntos/candidatos`, {
      params: q.trim().length >= 2 ? { q: q.trim() } : {},
      signal: controlador.signal,
    });
    candidatos.value = Array.isArray(data?.candidatos) ? data.candidatos : [];
    cargando.value = false;
  } catch {
    // Cancelada por la siguiente tecla: la que gana es la última, y ésa apaga el «cargando».
  }
};

watch(busqueda, q => { void cargar(q); });
onMounted(() => { void cargar(''); });

const comoAcompanante = async (c: CandidatoDeAsunto): Promise<void> => {
  const aviso = `¿«${c.etiqueta}» es el asunto de esta persona?\n\n`
    + 'Queda como ACOMPAÑANTE: el agente sabrá de qué le habla, pero los envíos programados y lo '
    + 'que es del titular siguen siendo del titular. Si ese asunto todavía no tiene conversación, '
    + 'este chat pasa a ser su titular.';

  if (!window.confirm(aviso)) return;

  ocupado.value = true;
  error.value = await chat.enlazarComoAcompanante(c.contextType, c.contextId);
  ocupado.value = false;

  if (!error.value) emit('enlazado');
};

const esElTitular = async (c: CandidatoDeAsunto): Promise<void> => {
  ocupado.value = true;
  error.value = null;

  const suyo = await chat.fetchConversacionPorContexto(c.contextType, c.contextId);

  // Sin hilo todavía no hay nada que unir: enlazarlo lo deja de titular, que es lo que se pidió.
  if (!suyo?.id) {
    error.value = await chat.enlazarComoAcompanante(c.contextType, c.contextId);
    ocupado.value = false;
    if (!error.value) emit('enlazado');
    return;
  }

  if (suyo.id === props.conversacionId) {
    error.value = 'Esta conversación ya es la de ese asunto.';
    ocupado.value = false;
    return;
  }

  titularElegido.value = suyo.id;
  previa.value = await fusion.previaDeFusion(props.conversacionId, suyo.id);
  ocupado.value = false;

  if (!previa.value) error.value = 'No se pudo preparar la unión.';
};

const unir = async (): Promise<void> => {
  if (!titularElegido.value) return;

  ocupado.value = true;
  const r = await fusion.fusionar(props.conversacionId, titularElegido.value);
  ocupado.value = false;

  if ('error' in r) { error.value = r.error; return; }

  previa.value = null;
  emit('fusionado', r.supervivienteId);
};
</script>

<template>
  <div class="text-[11px] font-bold text-sky-900 bg-sky-50 border border-sky-200 rounded-xl px-3 py-2 leading-snug">
    <p><i class="fas fa-link mr-1"></i>¿De qué asunto es esta persona?</p>

    <input v-model="busqueda" type="search" placeholder="Buscar por nombre, código…"
           class="mt-1.5 w-full px-2 py-1 rounded-lg border border-sky-200 bg-white text-slate-700 text-[11px] font-medium focus:outline-none focus:ring-1 focus:ring-sky-400" />

    <p v-if="cargando" class="mt-1.5 text-slate-400"><i class="fas fa-circle-notch fa-spin mr-1"></i>Buscando…</p>
    <p v-else-if="candidatos.length === 0" class="mt-1.5 text-slate-500 font-medium">
      {{ busqueda.trim().length >= 2 ? 'Nada coincide.' : 'Nada en curso estos días.' }}
    </p>

    <ul v-else-if="!previa" class="mt-1.5 flex flex-col gap-1">
      <li v-for="c in candidatos" :key="`${c.contextType}:${c.contextId}`"
          class="flex flex-wrap items-center gap-x-2 gap-y-1 bg-white border border-sky-100 rounded-lg px-2 py-1.5">
        <span class="min-w-0 flex-1">
          <span class="text-slate-800">{{ c.etiqueta }}</span>
          <span v-if="c.motivo" class="ml-1 px-1.5 py-0.5 rounded bg-sky-100 text-sky-700 text-[9px] uppercase tracking-wider">{{ c.motivo }}</span>
        </span>
        <span class="flex gap-1 shrink-0">
          <button type="button" @click="comoAcompanante(c)" :disabled="ocupado"
                  class="px-2 py-1 bg-sky-600 hover:bg-sky-700 text-white rounded-lg text-[10px] font-black uppercase tracking-wider disabled:opacity-50">
            Acompañante
          </button>
          <button type="button" @click="esElTitular(c)" :disabled="ocupado"
                  class="px-2 py-1 bg-white border border-sky-300 hover:bg-sky-100 rounded-lg text-[10px] font-black uppercase tracking-wider text-sky-800 disabled:opacity-50">
            Es el titular
          </button>
        </span>
      </li>
    </ul>

    <!-- «Es el titular» = la misma persona con otro número: se une, y se enseña antes quién sobrevive. -->
    <div v-if="previa" class="mt-1.5 bg-white border border-sky-300 rounded-lg p-2">
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
                class="px-2 py-1 bg-sky-600 hover:bg-sky-700 text-white rounded-lg text-[10px] font-black uppercase tracking-wider disabled:opacity-50">
          <i v-if="ocupado" class="fas fa-circle-notch fa-spin mr-1"></i>Unir
        </button>
      </div>
    </div>

    <p v-if="error" class="text-red-600 mt-1.5">{{ error }}</p>
  </div>
</template>
