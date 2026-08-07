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
import { ref, computed, watch, onMounted, onBeforeUnmount } from 'vue';
import { apiClient } from '@/services/apiClient';

/** Espejo de App\Agent\Controller\Api\PanelAssistantController::consulta(). */
interface RespuestaAsistente {
    respuesta: string;
    herramientas: string[];
    proveedor: string;
    modelo: string;
}

/**
 * Espejo de App\Agent\Conversation\AgentEngineRegistry::catalogo().
 *
 * El catálogo lo sirve el backend (`GET /agent/motores`) porque depende de qué credenciales
 * tiene ESTE entorno: en local suele haber un solo proveedor configurado.
 */
interface Motor {
    proveedor: string;
    etiqueta: string;
    disponible: boolean;
    modelos: string[];
    modeloPorDefecto: string;
    porDefecto: boolean;
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

/**
 * Un turno del hilo. `rol` viaja al backend, que lo traduce al formato de la API.
 *
 * `firma` guarda quién contestó ESE turno, no quién está seleccionado ahora: el sentido del
 * desplegable es preguntar lo mismo a dos proveedores seguidos, y si el hilo se repintara
 * con el motor actual la comparación se perdería al cambiar de opción.
 */
interface Turno {
    rol: 'usuario' | 'asistente';
    texto: string;
    herramientas?: string[];
    firma?: string;
}

const pregunta = ref('');
const hilo = ref<Turno[]>([]);
const cargando = ref(false);
const error = ref('');
const dictando = ref(false);

const motores = ref<Motor[]>([]);
const proveedor = ref('');
const modelo = ref('');

let reconocimiento: ReconocimientoVoz | null = null;

const motorActual = computed<Motor | null>(
    () => motores.value.find((m) => m.proveedor === proveedor.value) ?? null,
);

/** El selector sólo estorba cuando no hay nada que elegir. */
const hayQueElegir = computed(
    () => motores.value.length > 1 || (motorActual.value?.modelos.length ?? 0) > 1,
);

// Al cambiar de proveedor, el modelo anterior es de otra casa: se vuelve al de por defecto.
watch(proveedor, () => {
    modelo.value = motorActual.value?.modeloPorDefecto ?? '';
});

onMounted(async () => {
    try {
        const r = await apiClient.get<{ motores: Motor[] }>('/agent/motores');
        motores.value = r.data.motores ?? [];
    } catch {
        // Sin catálogo no se bloquea nada: se pregunta sin `proveedor` y contesta el de por
        // defecto del entorno, que es exactamente lo que hacía antes de existir el selector.
        return;
    }

    const inicial =
        motores.value.find((m) => m.porDefecto) ?? motores.value.find((m) => m.disponible);
    if (inicial) {
        proveedor.value = inicial.proveedor;
        modelo.value = inicial.modeloPorDefecto;
    }
});

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
            // Vacíos = los de por defecto del entorno. El backend valida ambos contra su
            // lista blanca, así que mandar algo raro devuelve un 400, no una factura.
            proveedor: proveedor.value,
            modelo: modelo.value,
        });
        const etiqueta = motores.value.find((m) => m.proveedor === r.data.proveedor)?.etiqueta;
        hilo.value.push({
            rol: 'asistente',
            texto: r.data.respuesta,
            herramientas: r.data.herramientas ?? [],
            firma: [etiqueta ?? r.data.proveedor, r.data.modelo].filter(Boolean).join(' · '),
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

    <!-- Proveedor y modelo. Se elige por consulta, no por sesión: la gracia es preguntar lo
         mismo dos veces seguidas y comparar quién responde mejor. -->
    <div v-if="hayQueElegir" class="px-4 pb-3 flex items-center gap-2">
      <i class="fas fa-microchip text-[10px] text-slate-300" aria-hidden="true"></i>

      <select
        v-if="motores.length > 1"
        v-model="proveedor"
        :disabled="cargando"
        class="text-[11px] font-bold uppercase tracking-widest text-slate-500 bg-slate-50 rounded-lg px-2 py-1 focus:outline-none disabled:opacity-40"
        title="Proveedor de IA"
      >
        <option
          v-for="m in motores"
          :key="m.proveedor"
          :value="m.proveedor"
          :disabled="!m.disponible"
        >
          {{ m.etiqueta }}{{ m.disponible ? '' : ' (sin clave)' }}
        </option>
      </select>

      <select
        v-if="(motorActual?.modelos.length ?? 0) > 1"
        v-model="modelo"
        :disabled="cargando"
        class="text-[11px] font-medium text-slate-500 bg-slate-50 rounded-lg px-2 py-1 focus:outline-none disabled:opacity-40"
        title="Modelo"
      >
        <option v-for="nombre in motorActual?.modelos ?? []" :key="nombre" :value="nombre">
          {{ nombre }}
        </option>
      </select>
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
          <p class="mt-2 text-[10px] font-bold uppercase tracking-widest text-slate-400">
            <template v-if="turno.herramientas?.length">
              <i class="fas fa-database text-[9px] mr-1" aria-hidden="true"></i> Consultado en el PMS
            </template>
            <span v-if="turno.firma" :class="turno.herramientas?.length ? 'ml-2 text-slate-300' : 'text-slate-300'">
              {{ turno.firma }}
            </span>
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
