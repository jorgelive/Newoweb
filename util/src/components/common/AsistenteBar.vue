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
import { ref, computed, watch, nextTick, onMounted, onBeforeUnmount } from 'vue';
import { apiClient } from '@/services/apiClient';
import { formatoAHtml, paraWhatsapp } from '@/utils/formatoDeTexto';

/** Espejo de App\Agent\Controller\Api\PanelAssistantController::consulta(). */
interface RespuestaAsistente {
    respuesta: string;
    herramientas: string[];
    /**
     * Alguna skill de las usadas escribió en la base, así que lo que la vista tiene en
     * pantalla ya no vale. Lo decide el backend (`NivelRiesgo::modificaDatos()`), no una
     * lista de nombres aquí: esa lista se desincroniza en cuanto se añade una skill nueva.
     */
    hubo_escritura: boolean;
    proveedor: string;
    modelo: string;
}

/**
 * Se emite tras una respuesta que modificó datos, para que la vista que embebe la barra
 * recargue lo suyo. El asistente no sabe QUÉ pinta cada vista —salidas, calendario, cuenta—
 * así que sólo avisa de que algo cambió y deja el refresco a quien sí lo sabe.
 */
const emit = defineEmits<{ (e: 'datosCambiados'): void }>();

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

/** Contenedor del hilo: se necesita la referencia para llevarlo al turno más reciente. */
const contenedorHilo = ref<HTMLElement | null>(null);

/**
 * La caja de escribir. Se le devuelve el foco al terminar cada respuesta.
 *
 * Casi siempre hay una pregunta detrás —una repregunta que contestar, el dato siguiente— y sin
 * esto hay que volver a pinchar el campo cada vez. El `disabled` mientras carga es justo lo que
 * hace que el navegador suelte el foco, así que hay que reponerlo a mano.
 */
const campoPregunta = ref<HTMLInputElement | null>(null);

async function enfocarPregunta(): Promise<void> {
    await nextTick();
    campoPregunta.value?.focus();
}

const HILO_STORAGE_KEY = 'asistente_hilo';

/**
 * Cuánto sobrevive el hilo a un refresco.
 *
 * Una hora es el turno de trabajo largo: el operador pregunta algo, se va a recepción, vuelve
 * y sigue. Más allá el contexto ya no es el suyo —otro día, otra incidencia— y arrastrarlo
 * sólo sirve para que el modelo responda sobre una conversación que nadie recuerda.
 */
const HILO_TTL_MS = 60 * 60 * 1000;

/** Lo que se guarda: los turnos y CUÁNDO, que es lo que permite caducarlos. */
interface HiloGuardado {
    guardadoEn: number;
    turnos: Turno[];
}

/**
 * Rescata el hilo del último rato, si no ha caducado.
 *
 * Cualquier fallo de lectura —JSON corrupto, otra versión del formato, `localStorage`
 * bloqueado en modo privado— se traga y devuelve vacío: perder el hilo es un incordio, pero
 * romper la barra del asistente al entrar al portal es peor.
 */
function restaurarHilo(): Turno[] {
    try {
        const crudo = localStorage.getItem(HILO_STORAGE_KEY);
        if (!crudo) return [];

        const guardado = JSON.parse(crudo) as HiloGuardado;

        if (!Array.isArray(guardado?.turnos) || typeof guardado.guardadoEn !== 'number') return [];
        if (Date.now() - guardado.guardadoEn > HILO_TTL_MS) {
            localStorage.removeItem(HILO_STORAGE_KEY);
            return [];
        }

        return guardado.turnos;
    } catch {
        return [];
    }
}

/**
 * El reloj se reinicia en CADA cambio, no en el primer turno.
 *
 * Así una conversación viva no caduca a mitad por haber empezado hace 59 minutos; lo que
 * caduca es el hilo que lleva una hora sin tocarse, que es la intención.
 */
watch(hilo, (turnos) => {
    try {
        if (turnos.length === 0) {
            localStorage.removeItem(HILO_STORAGE_KEY);
            return;
        }

        const guardado: HiloGuardado = { guardadoEn: Date.now(), turnos };
        localStorage.setItem(HILO_STORAGE_KEY, JSON.stringify(guardado));
    } catch {
        // Cuota llena o almacenamiento bloqueado: el hilo sigue funcionando en memoria.
    }
}, { deep: true });

/**
 * Lleva la vista al intercambio más reciente, que se pinta ARRIBA del todo.
 *
 * Al final del hilo está lo más viejo: quien pregunta escribe en la caja de arriba y espera la
 * respuesta ahí mismo, no seis turnos más abajo.
 */
async function subirAlUltimo(): Promise<void> {
    await nextTick();
    const caja = contenedorHilo.value;
    if (caja) caja.scrollTop = 0;
}

/**
 * El hilo agrupado en intercambios y del más NUEVO al más viejo.
 *
 * Se agrupa en pares en vez de invertir la lista pelada porque invertirla pondría cada
 * respuesta ENCIMA de su propia pregunta. Lo que se invierte es el orden de los intercambios;
 * dentro de cada uno se lee como siempre: primero lo que preguntó, debajo lo que contestó.
 *
 * Una pregunta sin respuesta —la que está en vuelo— es un intercambio con `respuesta` vacía,
 * y aparece arriba en cuanto se envía.
 */
const intercambios = computed<{ pregunta: Turno; respuesta?: Turno }[]>(() => {
    const pares: { pregunta: Turno; respuesta?: Turno }[] = [];

    for (const turno of hilo.value) {
        if (turno.rol === 'usuario') {
            pares.push({ pregunta: turno });
        } else if (pares.length > 0) {
            pares[pares.length - 1].respuesta = turno;
        }
    }

    return pares.reverse();
});

/**
 * ¿Es el intercambio que se está contestando ahora?
 *
 * El primero, porque el hilo va del más nuevo al más viejo. Con uno solo devuelve `false`: si
 * todo lo que hay es el turno actual, destacarlo no distingue nada de nada.
 *
 * Función y no tres condiciones repetidas en el template — que es como estaba y es como se
 * desincronizan: bastaría con tocar una y dejar las otras.
 */
function esElVivo(indice: number): boolean {
    return indice === 0 && intercambios.value.length > 1;
}

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
    // El hilo se recupera antes de pedir el catálogo: no depende de la red y así el operador
    // ve lo que estaba haciendo sin esperar a que responda `/agent/motores`.
    hilo.value = restaurarHilo();
    if (hilo.value.length) void subirAlUltimo();

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
    void subirAlUltimo();

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
        void subirAlUltimo();

        // Se avisa DESPUÉS de pintar la respuesta: el operador ve primero el «listo» y la
        // recarga ocurre detrás. Al revés, la vista se recargaría con el hilo aún sin el turno.
        if (r.data.hubo_escritura) {
            emit('datosCambiados');
        }
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
        // Tanto si respondió como si falló: en los dos casos lo siguiente es escribir —la
        // respuesta a una repregunta, o el reintento—. Va DESPUÉS de soltar `cargando`, porque
        // hasta ese momento el campo sigue deshabilitado y no acepta el foco.
        void enfocarPregunta();
    }
}

const copiadoIndice = ref<number | null>(null);

async function copiarAlPortapapeles(texto: string, indice: number): Promise<void> {
    // `paraWhatsapp()` y no `normalizar()`: el operador pega esto en WhatsApp, así que
    // tiene que salir degradado igual que lo que el backend ENVÍA por ese canal —con las
    // URLs protegidas y sin el `__subrayado__`, que WhatsApp no tiene.
    const textoParaWhatsapp = paraWhatsapp(texto);
    try {
        await navigator.clipboard.writeText(textoParaWhatsapp);
        copiadoIndice.value = indice;
        setTimeout(() => {
            if (copiadoIndice.value === indice) {
                copiadoIndice.value = null;
            }
        }, 2000);
    } catch {
        // Fallback si el navegador restringe el portapapeles
        const textarea = document.createElement('textarea');
        textarea.value = textoParaWhatsapp;
        document.body.appendChild(textarea);
        textarea.select();
        document.execCommand('copy');
        document.body.removeChild(textarea);
        copiadoIndice.value = indice;
        setTimeout(() => {
            if (copiadoIndice.value === indice) {
                copiadoIndice.value = null;
            }
        }, 2000);
    }
}

function limpiar(): void {
    hilo.value = [];
    error.value = '';
    copiadoIndice.value = null;
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
      <!-- El icono también avisa: mientras consulta late, para que se vea desde el rabillo
           del ojo sin tener que leer nada. -->
      <span
        class="w-8 h-8 rounded-xl flex items-center justify-center shrink-0 transition-colors"
        :class="cargando ? 'bg-[#376875] text-white animate-pulse' : 'bg-[#376875]/10 text-[#376875]'"
      >
        <i class="fas fa-wand-magic-sparkles text-sm" aria-hidden="true"></i>
      </span>

      <input
        ref="campoPregunta"
        v-model="pregunta"
        type="text"
        :disabled="cargando"
        :placeholder="cargando ? 'Consultando el PMS…' : '¿Qué casitas tengo libres del 12 al 15 de marzo?'"
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
         Carlos?").

         Va del intercambio MÁS NUEVO al más viejo, porque la caja de escribir está arriba: la
         respuesta aparece justo debajo de lo que acabas de teclear, no al final de una lista
         que se aleja un poco más en cada pregunta. Dentro de cada intercambio se lee en el
         orden de siempre —pregunta y debajo su respuesta—; ver el computed `intercambios`.

         Crece hasta `max-h-96` y a partir de ahí hace scroll, en vez de seguir empujando el
         panel de llegadas y salidas fuera de la pantalla.

         `divide-slate-200` y no `slate-50`: con los intercambios apilándose hacia arriba, una
         línea casi invisible dejaba la duda de dónde acaba una consulta y empieza la anterior.
         Es la única señal de que son cosas distintas. -->
    <div
      v-if="intercambios.length"
      ref="contenedorHilo"
      class="border-t border-slate-100 divide-y divide-slate-200 max-h-96 overflow-y-auto"
    >
      <!-- El intercambio de arriba es el vivo: el que se está contestando. Se marca con una
           barra lateral y un fondo apenas visible, no con color de aviso — es orientación, no
           una alerta. Sólo cuando hay más de uno: con uno solo no hay nada que distinguir. -->
      <div
        v-for="(cambio, i) in intercambios"
        :key="i"
        class="px-4 py-3.5 border-l-2 transition-colors"
        :class="esElVivo(i) ? 'border-[#376875]/40 bg-slate-50/60' : 'border-transparent'"
      >
        <p class="text-sm font-bold" :class="esElVivo(i) ? 'text-slate-700' : 'text-slate-500'">
          <i class="fas fa-angle-right mr-1" :class="esElVivo(i) ? 'text-[#376875]' : 'text-slate-300'" aria-hidden="true"></i>{{ cambio.pregunta.texto }}
        </p>

        <template v-if="cambio.respuesta">
          <!-- El modelo responde con el marcado canónico (*negrita*, listas, [texto](url)) y
               `formatoAHtml` lo pinta —escapando antes— igual que el chat de mensajería. Sin
               esto los asteriscos y los enlaces llegaban crudos al operador. -->
          <!-- eslint-disable vue/no-v-html -- Mismo pipeline escapado del chat (`formatoAHtml`). -->
          <div class="relative group mt-2">
            <p
              class="asistente-respuesta text-sm text-slate-800 whitespace-pre-line leading-relaxed pr-16"
              v-html="formatoAHtml(cambio.respuesta.texto)"
            ></p>
            <button
              type="button"
              class="absolute top-0 right-0 text-xs px-2 py-1 bg-slate-100 hover:bg-slate-200 text-slate-600 rounded flex items-center gap-1 transition-all opacity-80 group-hover:opacity-100 shadow-sm"
              :title="'Copiar texto para WhatsApp / portapapeles'"
              @click="copiarAlPortapapeles(cambio.respuesta.texto, i)"
            >
              <i :class="copiadoIndice === i ? 'fas fa-check text-emerald-600' : 'far fa-copy text-slate-500'"></i>
              <span class="text-[11px] font-medium">{{ copiadoIndice === i ? 'Copiado' : 'Copiar' }}</span>
            </button>
          </div>
          <!-- eslint-enable vue/no-v-html -->
          <p class="mt-2 text-[10px] font-bold uppercase tracking-widest text-slate-400 flex items-center justify-between">
            <span>
              <template v-if="cambio.respuesta.herramientas?.length">
                <i class="fas fa-database text-[9px] mr-1" aria-hidden="true"></i> Consultado en el PMS
              </template>
              <span
                v-if="cambio.respuesta.firma"
                :class="cambio.respuesta.herramientas?.length ? 'ml-2 text-slate-300' : 'text-slate-300'"
              >
                {{ cambio.respuesta.firma }}
              </span>
            </span>
          </p>
        </template>

        <!-- Sin respuesta todavía. Antes sólo giraba el icono del botón de enviar, en la otra
             punta de la barra: el operador se quedaba mirando su propia pregunta sin señal de
             que algo estuviera pasando. El aviso va DONDE va a aparecer la respuesta. -->
        <p v-else-if="cargando" class="mt-2 flex items-center gap-2 text-sm font-medium text-[#376875]">
          <i class="fas fa-circle-notch fa-spin text-xs" aria-hidden="true"></i>
          <span>Consultando el PMS</span>
          <span class="asistente-puntos" aria-hidden="true"><span>.</span><span>.</span><span>.</span></span>
        </p>
      </div>
    </div>

    <!-- FUERA del área con scroll: si viajara con los turnos, en un hilo largo habría que
         desplazarse hasta el final para encontrar el botón de vaciarlo. -->
    <div v-if="hilo.length" class="border-t border-slate-100 px-4 py-2 flex justify-end">
      <button type="button" class="text-[10px] font-bold uppercase tracking-widest text-slate-400 hover:text-slate-700" @click="limpiar">
        Empezar de nuevo
      </button>
    </div>

    <div v-if="error" class="border-t border-slate-100 px-4 py-3 text-sm font-medium text-red-600">
      {{ error }}
    </div>
  </div>
</template>

<style scoped>
/*
 * Los puntos suspensivos del «Consultando el PMS…», cada uno con su retraso.
 *
 * Es la señal de que sigue vivo: una consulta con varias skills encadenadas puede tardar
 * bastante, y un texto quieto se confunde con algo colgado.
 */
.asistente-puntos span {
    animation: asistente-latido 1.4s infinite;
}
.asistente-puntos span:nth-child(2) { animation-delay: 0.2s; }
.asistente-puntos span:nth-child(3) { animation-delay: 0.4s; }

@keyframes asistente-latido {
    0%, 60%, 100% { opacity: 0.25; }
    30%           { opacity: 1; }
}

/* Quien prefiera no ver movimiento se queda con el texto, que ya dice lo que pasa. */
@media (prefers-reduced-motion: reduce) {
    .asistente-puntos span { animation: none; opacity: 1; }
}
</style>
