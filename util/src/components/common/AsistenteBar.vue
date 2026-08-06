<script setup lang="ts">
/**
 * Barra del asistente interno: se escribe (o se dicta) una pregunta en lenguaje natural y
 * el backend la resuelve consultando el PMS.
 *
 * NO es el chat del huésped. Aquí pregunta un compañero autenticado y la respuesta sólo se
 * pinta en pantalla; nada sale hacia fuera. Ver docs/Mensajeria.md §11.
 *
 * El dictado usa la Web Speech API del navegador: la transcripción ocurre en el cliente y
 * al backend llega texto, así que no hay coste de audio ni endpoint que mantener. Donde no
 * exista (Firefox, navegadores viejos) el botón simplemente no se muestra.
 */
import { ref, computed, onBeforeUnmount } from 'vue';
import { apiClient } from '@/services/apiClient';

/** Espejo de App\Agent\Controller\Api\PanelAssistantController::__invoke(). */
interface RespuestaAsistente {
    respuesta: string;
    herramientas: string[];
}

/**
 * Superficie mínima de la Web Speech API que usamos. Se declara a mano porque `lib.dom` no
 * la incluye de forma estable — y declarar sólo lo que se usa evita arrastrar un `any`.
 */
interface ResultadoVozLike {
    results: ArrayLike<ArrayLike<{ transcript: string }>>;
}

interface ReconocimientoVoz {
    lang: string;
    continuous: boolean;
    interimResults: boolean;
    start(): void;
    stop(): void;
    onresult: ((evento: ResultadoVozLike) => void) | null;
    onerror: (() => void) | null;
    onend: (() => void) | null;
}

type ConstructorReconocimiento = new () => ReconocimientoVoz;

/** Un turno del hilo. `rol` viaja al backend, que lo traduce al formato de la API. */
interface Turno {
    rol: 'usuario' | 'asistente';
    texto: string;
    herramientas?: string[];
}

const pregunta = ref('');
const hilo = ref<Turno[]>([]);
const cargando = ref(false);
const error = ref('');
const dictando = ref(false);

let reconocimiento: ReconocimientoVoz | null = null;

/**
 * El constructor vive en `window` con prefijo en los navegadores basados en WebKit. El cast
 * se acota a esta línea: es un diccionario abierto del navegador, no un contrato nuestro.
 */
const constructorVoz = computed<ConstructorReconocimiento | null>(() => {
    const w = window as unknown as {
        SpeechRecognition?: ConstructorReconocimiento;
        webkitSpeechRecognition?: ConstructorReconocimiento;
    };
    return w.SpeechRecognition ?? w.webkitSpeechRecognition ?? null;
});

const soportaDictado = computed(() => constructorVoz.value !== null);

async function preguntar(): Promise<void> {
    const texto = pregunta.value.trim();
    if (!texto || cargando.value) return;

    cargando.value = true;
    error.value = '';

    // El hilo se envía SIN el turno nuevo (el backend lo añade) y se pinta CON él, para que
    // el operador vea su pregunta en cuanto la manda y no mientras espera en blanco.
    const historial = hilo.value.map(({ rol, texto: t }) => ({ rol, texto: t }));
    hilo.value.push({ rol: 'usuario', texto });
    pregunta.value = '';

    try {
        const r = await apiClient.post<RespuestaAsistente>('/agent/consulta', {
            pregunta: texto,
            historial,
        });
        hilo.value.push({
            rol: 'asistente',
            texto: r.data.respuesta,
            herramientas: r.data.herramientas ?? [],
        });
    } catch (e: unknown) {
        // El backend manda `{error: string}` en 4xx/5xx; cualquier otra cosa es un fallo de red.
        const detalle =
            typeof e === 'object' && e !== null && 'response' in e
                ? (e as { response?: { data?: { error?: string } } }).response?.data?.error
                : undefined;
        error.value = detalle ?? 'No se pudo consultar al asistente.';
        // Se retira la pregunta del hilo: si falló, no formó parte de la conversación y
        // mandarla en el siguiente historial confundiría al modelo.
        hilo.value.pop();
        pregunta.value = texto;
    } finally {
        cargando.value = false;
    }
}

function limpiar(): void {
    hilo.value = [];
    error.value = '';
}

function alternarDictado(): void {
    const Constructor = constructorVoz.value;
    if (!Constructor) return;

    if (dictando.value) {
        reconocimiento?.stop();
        return;
    }

    reconocimiento = new Constructor();
    reconocimiento.lang = 'es-PE';
    reconocimiento.continuous = false;
    reconocimiento.interimResults = false;

    reconocimiento.onresult = (evento) => {
        const transcripcion = evento.results?.[0]?.[0]?.transcript;
        if (transcripcion) {
            pregunta.value = transcripcion;
            void preguntar();
        }
    };
    reconocimiento.onerror = () => { dictando.value = false; };
    reconocimiento.onend = () => { dictando.value = false; };

    dictando.value = true;
    reconocimiento.start();
}

// Si se sale de la vista dictando, el micrófono se queda abierto.
onBeforeUnmount(() => reconocimiento?.stop());
</script>

<template>
  <div class="bg-white rounded-3xl border border-slate-100 shadow-sm overflow-hidden">
    <div class="flex items-center gap-2 px-4 py-3">
      <span class="w-8 h-8 rounded-xl bg-[#376875]/10 text-[#376875] flex items-center justify-center shrink-0">
        <i class="fas fa-wand-magic-sparkles text-sm" aria-hidden="true"></i>
      </span>

      <input
        v-model="pregunta"
        type="text"
        :disabled="cargando"
        placeholder="¿Qué casitas tengo libres del 12 al 15 de marzo?"
        class="flex-1 min-w-0 bg-transparent text-sm font-medium text-slate-800 placeholder:text-slate-400 focus:outline-none disabled:opacity-50"
        @keydown.enter="preguntar"
      />

      <button
        v-if="soportaDictado"
        type="button"
        :disabled="cargando"
        :title="dictando ? 'Detener dictado' : 'Dictar la pregunta'"
        class="w-9 h-9 rounded-xl flex items-center justify-center shrink-0 transition-colors disabled:opacity-40"
        :class="dictando ? 'bg-red-50 text-red-600' : 'text-slate-400 hover:bg-slate-50 hover:text-slate-700'"
        @click="alternarDictado"
      >
        <i class="fas" :class="dictando ? 'fa-stop' : 'fa-microphone'" aria-hidden="true"></i>
      </button>

      <button
        type="button"
        :disabled="cargando || !pregunta.trim()"
        class="w-9 h-9 rounded-xl bg-slate-900 text-white flex items-center justify-center shrink-0 transition-opacity disabled:opacity-30"
        title="Preguntar"
        @click="preguntar"
      >
        <i class="fas" :class="cargando ? 'fa-circle-notch fa-spin' : 'fa-arrow-right'" aria-hidden="true"></i>
      </button>
    </div>

    <div v-if="dictando" class="px-4 pb-3 text-[11px] font-bold uppercase tracking-widest text-red-500">
      <i class="fas fa-circle text-[7px] animate-pulse mr-1" aria-hidden="true"></i> Escuchando…
    </div>

    <!-- El hilo: sostiene el ir y venir cuando el asistente repregunta ("¿cuál de los dos
         Carlos?"). Se pinta en orden y el más reciente queda abajo. -->
    <div v-if="hilo.length" class="border-t border-slate-100 divide-y divide-slate-50">
      <div v-for="(turno, i) in hilo" :key="i" class="px-4 py-3">
        <p v-if="turno.rol === 'usuario'" class="text-sm font-bold text-slate-500">
          <i class="fas fa-angle-right text-slate-300 mr-1" aria-hidden="true"></i>{{ turno.texto }}
        </p>
        <template v-else>
          <p class="text-sm text-slate-800 whitespace-pre-line leading-relaxed">{{ turno.texto }}</p>
          <p v-if="turno.herramientas?.length" class="mt-2 text-[10px] font-bold uppercase tracking-widest text-slate-400">
            <i class="fas fa-database text-[9px] mr-1" aria-hidden="true"></i> Consultado en el PMS
          </p>
        </template>
      </div>

      <div class="px-4 py-2 flex justify-end">
        <button type="button" class="text-[10px] font-bold uppercase tracking-widest text-slate-400 hover:text-slate-700" @click="limpiar">
          Empezar de nuevo
        </button>
      </div>
    </div>

    <div v-if="error" class="border-t border-slate-100 px-4 py-3 text-sm font-medium text-red-600">
      {{ error }}
    </div>
  </div>
</template>
