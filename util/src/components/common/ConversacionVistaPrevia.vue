<script setup lang="ts">
// ============================================================================
// VISTA PREVIA DE UNA CONVERSACIÓN — el «modo stalker», suelto y reutilizable
//
// Asomarse a un chat para ver qué se está hablando SIN abrirlo. Nació dentro de
// ChatView (la ventana flotante del inbox) y de ahí sale, porque el gesto no es
// del inbox: desde el calendario de reservas, antes de escribirle a un huésped,
// hace la misma falta.
//
// 🕵️ LA REGLA QUE LE DA NOMBRE: previsualizar NO deja rastro. No se llama a
// `selectConversation()` —ésa marca la conversación como leída, baja el contador
// global y abre Mercure—, sino a los dos métodos de sólo lectura del store
// (`fetchConversacionParaStalk`, `fetchLatestMessagesForStalk`). Con el otro
// camino, un simple hover le borraba los «sin leer» al compañero que iba a
// contestar. Tampoco se toca el estado del store: una previsualización abierta
// desde el calendario no puede alterar el inbox.
//
// AUTÓNOMO A PROPÓSITO: se le da un punto de la pantalla y una forma de
// identificar la conversación, y se ocupa de todo lo demás —resolverla, pedir el
// historial, medirse para no salirse de la pantalla, cerrarse— para que enchufarlo
// en una vista nueva sea una línea de plantilla y no copiar media docena de refs.
//
// Se identifica de dos maneras, y la segunda es la que lo hace universal:
//   · `conversacion-id` — cuando quien lo abre ya sabe cuál es.
//   · `contexto`        — `{ tipo: 'pms_reserva', id }`: el asunto en SU dominio.
//     El tipo viaja OPACO hasta el filtro de la API; este componente no sabe qué
//     es una reserva, así que sirve igual para cotizaciones o lo que venga
//     (misma regla que los identificadores de dominio del backend, ver CLAUDE.md).
// ============================================================================
import { ref, computed, watch, nextTick, onBeforeUnmount } from 'vue';
import { useRouter } from 'vue-router';
import { useChatStore, type ApiConversation, type ApiMessage } from '@/stores/chat/chatStore';

const props = withDefaults(defineProps<{
    /** La pinta o la quita. El anfitrión manda; así la transición vive aquí dentro. */
    abierta: boolean;
    /** Punto de la pantalla al que se ancla (el del clic o del tap). */
    ancla: { x: number; y: number };
    conversacionId?: string | null;
    /** El asunto en su dominio, para resolver la conversación sin conocerlo. */
    contexto?: { tipo: string; id: string } | null;
    /** Si el anfitrión ya la tiene cargada, se pasa y se ahorra el viaje. */
    conversacion?: ApiConversation | null;
    /**
     * `hover`: sin velo, se cierra sola al salir el ratón (asomarse de pasada).
     * `fijado`: velo que captura el clic y botón de cerrar (consultar con calma).
     */
    modo?: 'hover' | 'fijado';
    /** Cuántos mensajes de historial traer. */
    limite?: number;
    /**
     * Qué hace «Ir al chat»: `navegar` empuja a `/chat?id=…` (lo que quiere
     * cualquier vista que no sea el chat) y `delegar` sólo emite, para que el
     * propio ChatView abra la conversación en su panel sin recargar la ruta.
     *
     * `ninguna` esconde el botón. Es para cuando el anfitrión YA es el chat y esa
     * conversación ya está abierta en el panel: el botón seguiría ahí prometiendo
     * llevarte a un sitio en el que estás, y un botón que no lleva a ninguna parte
     * enseña al usuario a desconfiar de los demás.
     */
    accionChat?: 'navegar' | 'delegar' | 'ninguna';
}>(), {
    conversacionId: null,
    contexto: null,
    conversacion: null,
    modo: 'fijado',
    limite: 12,
    accionChat: 'navegar',
});

const emit = defineEmits<{
    cerrar: [];
    'abrir-chat': [string];
    /**
     * «El cursor está DENTRO de la ventana, no la cierres.»
     *
     * Hace falta porque en modo hover hay dos temporizadores de cierre: el del
     * anfitrión (al salir del ítem que la abrió) y el de aquí. El cursor que viaja del
     * ítem a la ventana dispara el primero, y sin este aviso la ventana se cerraba en
     * la cara justo al llegar a ella — con el bug extra de que era imposible hacerle
     * scroll, que es para lo que se abre.
     */
    mantener: [];
}>();

const store = useChatStore();
const router = useRouter();

// ============================================================================
// DATOS
// ============================================================================
const conversacion = ref<ApiConversation | null>(null);
const mensajes = ref<ApiMessage[]>([]);
const cargando = ref(false);
/** El asunto existe pero nadie ha hablado con él todavía: no es un error. */
const sinConversacion = ref(false);

/**
 * Descarta respuestas de una previsualización que ya no está en pantalla.
 *
 * Con hover se abren y cierran muchas seguidas, y sin este contador la respuesta
 * lenta de la primera pintaba sus mensajes dentro de la segunda — el historial de
 * otro huésped bajo el nombre del actual, que en un chat es de lo peor que puede
 * pasar. Mismo patrón que `onEventClick()` en ReservasView.
 */
let peticion = 0;

const scroller = ref<HTMLElement | null>(null);

const identidad = computed(() => props.conversacion?.id
    ?? props.conversacionId
    ?? (props.contexto ? `${props.contexto.tipo}:${props.contexto.id}` : null));

async function cargar(): Promise<void> {
    const mia = ++peticion;

    conversacion.value = null;
    mensajes.value = [];
    sinConversacion.value = false;
    cargando.value = true;

    try {
        const resuelta = props.conversacion
            ?? (props.conversacionId ? await store.fetchConversacionParaStalk(props.conversacionId) : null)
            ?? (props.contexto ? await store.fetchConversacionPorContexto(props.contexto.tipo, props.contexto.id) : null);

        if (mia !== peticion) return;

        if (!resuelta?.id) {
            sinConversacion.value = true;
            return;
        }

        conversacion.value = resuelta;
        mensajes.value = await store.fetchLatestMessagesForStalk(resuelta.id, props.limite);

        if (mia !== peticion) return;
    } finally {
        if (mia === peticion) cargando.value = false;
    }

    await irAlFinal();
}

/**
 * Abre mostrando lo ÚLTIMO, que es lo que se viene a ver.
 *
 * ⚠️ El `await nextTick()` no es decorativo y el orden tampoco: mientras
 * `cargando` es true el scroller no está en el DOM, así que hay que dejar que Vue
 * lo pinte antes de medirlo. En la versión anterior este scroll se hacía DENTRO
 * del `try`, con `cargando` todavía en true: el `ref` valía `null`, no fallaba
 * nada y la ventana abría arriba, en el mensaje más viejo de los cinco.
 */
async function irAlFinal(): Promise<void> {
    await nextTick();
    const el = scroller.value;
    if (el) el.scrollTop = el.scrollHeight;
}

watch(
    () => [props.abierta, identidad.value] as const,
    ([abierta]) => {
        if (abierta) void cargar();
        else peticion++; // lo que llegue tarde ya no es de nadie
    },
    { immediate: true },
);

// ============================================================================
// POSICIÓN — medida, no estimada
//
// El alto depende de lo que llegue por fetch (resumen IA, número de mensajes),
// así que estimarlo deja la ventana saliéndose por abajo en cuanto crece. Un
// ResizeObserver la recoloca en cada cambio de alto. Copiado del menú contextual
// de ReservasView, que resolvió lo mismo: ver `reubicarMenu()`.
// ============================================================================
const BORDE = 8;
const panel = ref<HTMLElement | null>(null);
const pos = ref({ ...props.ancla });
let observador: ResizeObserver | null = null;

function reubicar(): void {
    const el = panel.value;
    if (!el) return;

    pos.value = {
        x: Math.max(BORDE, Math.min(props.ancla.x, window.innerWidth - el.offsetWidth - BORDE)),
        y: Math.max(BORDE, Math.min(props.ancla.y, window.innerHeight - el.offsetHeight - BORDE)),
    };
}

// Arranca en el ancla ya recortada a ojo para que no se vea saltar; el observer
// pone la definitiva en cuanto hay panel que medir.
watch(() => props.ancla, (a) => {
    pos.value = {
        x: Math.max(BORDE, Math.min(a.x, window.innerWidth - 320)),
        y: Math.max(BORDE, Math.min(a.y, window.innerHeight - 260)),
    };
    reubicar();
});

watch(panel, (el) => {
    observador?.disconnect();
    observador = null;
    if (!el) return;
    // Observar dispara ya una primera vez: ésa es la que coloca la ventana medida.
    observador = new ResizeObserver(reubicar);
    observador.observe(el);
});

onBeforeUnmount(() => observador?.disconnect());

// ============================================================================
// CIERRE
// ============================================================================
let cierreDiferido: number | null = null;

function cancelarCierre(): void {
    if (cierreDiferido === null) return;
    clearTimeout(cierreDiferido);
    cierreDiferido = null;
}

/** El cursor entró en la ventana: se frena el cierre propio Y el del anfitrión. */
function alEntrar(): void {
    cancelarCierre();
    // Se avisa siempre, incluso sin temporizador propio pendiente: el que hay que
    // frenar puede ser el del anfitrión (ver el emit `mantener`).
    emit('mantener');
}

/**
 * En modo hover se cierra con un margen de 200 ms. El margen es el que permite
 * que el cursor VIAJE del ítem a la ventana sin que se cierre por el camino —sin
 * él no se podía ni hacer scroll para ver los últimos mensajes—.
 */
function cerrarConMargen(): void {
    if (props.modo !== 'hover') return;
    cancelarCierre();
    cierreDiferido = window.setTimeout(() => emit('cerrar'), 200);
}

onBeforeUnmount(cancelarCierre);

function irAlChat(): void {
    const id = conversacion.value?.id;
    if (!id) return;

    emit('abrir-chat', id);
    emit('cerrar');

    // ChatView pasa `delegar` porque ya ESTÁ en el chat: allí abrir la conversación
    // es seleccionarla en su panel, no volver a navegar a la misma ruta.
    if (props.accionChat === 'navegar') {
        // ChatView sólo reacciona a `route.query.id`, no a un param de ruta:
        // /chat/:id no abre nada (ver su watch/onMounted).
        void router.push({ path: '/chat', query: { id } });
    }
}

// ============================================================================
// PRESENTACIÓN
// ============================================================================
/** Hoy sólo la hora; otro día, día y mes: en una previsualización sobra el año. */
function fechaCorta(iso?: string | null): string {
    if (!iso) return '';

    const fecha = new Date(iso);
    const hoy = new Date();
    const hora = fecha.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });

    const esHoy = fecha.getDate() === hoy.getDate()
        && fecha.getMonth() === hoy.getMonth()
        && fecha.getFullYear() === hoy.getFullYear();

    return esHoy ? hora : `${fecha.toLocaleDateString('es-ES', { day: 'numeric', month: 'short' })}, ${hora}`;
}

/**
 * El nombre de la plantilla sin pedir nada.
 *
 * El mensaje la trae embebida (con nombre) o como IRI. Con IRI se busca en las
 * plantillas que el store ya tenga cargadas, pero NO se piden: fuera del chat esa
 * lista está vacía y una previsualización no justifica un viaje extra para una
 * etiqueta. Sin nombre, se dice que es una plantilla y basta.
 */
function nombrePlantilla(plantilla: ApiMessage['template']): string {
    if (!plantilla) return 'Plantilla';
    if (typeof plantilla !== 'string') return plantilla.name || 'Plantilla automática';

    return store.templates.find(t => (t['@id'] || t.id) === plantilla)?.name || 'Plantilla automática';
}

const nombreHuesped = computed(() => conversacion.value?.guestName || 'Huésped');

/** El asunto en una línea: «Airbnb · Casa Azul» o lo que traiga el contexto. */
const subtitulo = computed(() => {
    const c = conversacion.value;
    if (!c) return null;

    const partes = [
        c.contextAgency || c.contextOrigin,
        c.contextItems?.length ? c.contextItems.join(', ') : null,
    ].filter(Boolean);

    return partes.length ? partes.join(' · ') : null;
});

const noLeidos = computed(() => conversacion.value?.unreadCount ?? 0);

/** ¿El pie va a llevar algún botón? Si no, no se pinta la franja. */
const hayPie = computed(() =>
    (conversacion.value !== null && props.accionChat !== 'ninguna') || props.modo === 'fijado'
);

/**
 * El resumen IA es de lo PENDIENTE, así que sólo se pinta con no leídos: si ya se
 * contestó, el campo está vacío o cuenta algo que ya no está vivo (ver §12 de
 * docs/Mensajeria.md).
 */
const resumen = computed(() => (noLeidos.value > 0 ? conversacion.value?.resumenIa || null : null));

/**
 * La ventana de 24 h de WhatsApp, que es lo que de verdad decide si se puede
 * contestar con texto libre o hay que gastar una plantilla. Al asomarse antes de
 * escribir es EL dato operativo, y hasta ahora había que abrir el chat para verlo.
 */
const ventanaAbierta = computed(() => conversacion.value?.whatsappSessionActive === true);
</script>

<template>
    <!-- El velo sólo en modo fijado: en hover taparía el ítem del que se salió y
         el cursor no podría volver a la ventana. -->
    <div v-if="abierta && modo === 'fijado'" class="fixed inset-0 z-400" @click="emit('cerrar')"></div>

    <Transition name="fade-scale">
        <div v-if="abierta" ref="panel"
            :style="{ top: pos.y + 'px', left: pos.x + 'px' }"
            @mouseenter="alEntrar"
            @mouseleave="cerrarConMargen"
            class="fixed z-500 w-72 md:w-80 max-h-[26rem] flex flex-col bg-white border border-slate-200
                   shadow-2xl rounded-2xl origin-top-left overflow-hidden">

            <!-- CABECERA: de quién es esto. Antes decía sólo «Vista Previa», que es
                 lo único que el operador ya sabía. -->
            <div class="px-4 pt-3 pb-2 border-b border-slate-100 shrink-0">
                <div class="flex items-start justify-between gap-2">
                    <div class="min-w-0">
                        <p class="text-sm font-black text-slate-800 truncate flex items-center gap-1.5">
                            <i class="fas fa-eye text-[10px] text-[#376875] shrink-0"></i>
                            {{ nombreHuesped }}
                        </p>
                        <p v-if="subtitulo" class="text-[10px] font-bold text-slate-400 truncate mt-0.5">
                            {{ subtitulo }}
                        </p>
                    </div>
                    <span v-if="noLeidos"
                        class="shrink-0 text-[10px] font-black text-[#E07845] bg-orange-50 px-2 py-0.5 rounded-md">
                        {{ noLeidos }} sin leer
                    </span>
                </div>

                <!-- Se puede contestar o no: el dato por el que se abría el chat. -->
                <p v-if="conversacion" class="mt-1.5 text-[9px] font-black uppercase tracking-wider flex items-center gap-1"
                    :class="ventanaAbierta ? 'text-emerald-600' : 'text-slate-400'">
                    <i class="fas" :class="ventanaAbierta ? 'fa-unlock' : 'fa-lock'"></i>
                    {{ ventanaAbierta ? 'Ventana de 24 h abierta' : 'Ventana de 24 h cerrada' }}
                </p>
            </div>

            <!-- El resumen IA va ARRIBA, antes del historial: la gracia de asomarse
                 es saber qué piden sin tener que leerlo. -->
            <div v-if="resumen"
                class="mx-4 mt-3 flex gap-2 items-start bg-[#E07845]/8 border border-[#E07845]/20 rounded-xl px-3 py-2 shrink-0">
                <i class="fas fa-wand-magic-sparkles text-[#E07845] text-[10px] mt-0.5 shrink-0"></i>
                <p class="text-[11px] font-bold text-slate-700 leading-snug">{{ resumen }}</p>
            </div>

            <!-- ⚠️ El scroller está SIEMPRE en el DOM, con el spinner y los estados
                 vacíos dentro. Cuando el `ref` vivía en un `v-else` de «cargando», el
                 scroll al final se aplicaba a `null` y la ventana abría arriba. -->
            <div ref="scroller" class="flex-1 min-h-0 overflow-y-auto scrollbar-hide px-4 py-3 flex flex-col gap-3">
                <div v-if="cargando" class="flex justify-center py-6">
                    <i class="fas fa-circle-notch fa-spin text-slate-300 text-2xl"></i>
                </div>

                <p v-else-if="sinConversacion" class="text-xs text-slate-400 text-center py-4 italic leading-snug">
                    Todavía no hay conversación con este huésped.
                </p>

                <p v-else-if="!mensajes.length" class="text-xs text-slate-400 text-center py-4 italic">
                    No hay historial reciente.
                </p>

                <div v-for="m in mensajes" :key="m.id"
                    class="text-[12px] p-2.5 rounded-xl border shadow-sm leading-relaxed"
                    :class="m.direction === 'outgoing'
                        ? 'bg-[#376875]/5 border-[#376875]/10 text-slate-700 ml-6 rounded-tr-sm'
                        : 'bg-white border-slate-200 text-slate-800 mr-6 rounded-tl-sm'">
                    <div class="font-black mb-1 flex justify-between gap-2 text-[9px] uppercase tracking-wider"
                        :class="m.direction === 'outgoing' ? 'text-[#376875]' : 'text-slate-400'">
                        <span class="truncate">{{ m.direction === 'incoming' ? nombreHuesped : 'Tú' }}</span>
                        <span class="shrink-0">{{ fechaCorta(m.effectiveDateTime || m.createdAt) }}</span>
                    </div>

                    <div class="whitespace-pre-wrap wrap-break-word opacity-90 font-medium">
                        <span v-if="m.template" class="block text-[10px] font-black uppercase opacity-80">
                            🤖 {{ nombrePlantilla(m.template) }}
                        </span>
                        <span v-else>{{ m.contentLocal || m.contentExternal || '📎 [Archivo Adjunto]' }}</span>
                    </div>
                </div>
            </div>

            <!-- PIE: la salida hacia el chat de verdad. Es lo que antes hacía el ítem
                 «Abrir chat interno» del menú del calendario, ahora al final de lo que
                 se acaba de leer.

                 Se oculta entero si no va a llevar ningún botón —modo `hover` sin acción
                 de chat—; si no, quedaría una franja gris vacía bajo los mensajes. -->
            <div v-if="hayPie"
                 class="shrink-0 px-4 py-2.5 bg-slate-50 border-t border-slate-100 flex items-center gap-2">
                <button v-if="conversacion && accionChat !== 'ninguna'" type="button" @click="irAlChat"
                    class="flex-1 py-2 text-xs font-black text-white bg-[#376875] hover:bg-[#2d5660]
                           rounded-xl transition-colors flex items-center justify-center gap-1.5">
                    <i class="fas fa-comment-dots text-[10px]"></i> Ir al chat
                </button>
                <button v-if="modo === 'fijado'" type="button" @click="emit('cerrar')"
                    class="px-3 py-2 text-xs font-bold text-slate-500 hover:text-slate-800 bg-white
                           hover:bg-slate-100 border border-slate-200 rounded-xl transition-colors">
                    Cerrar
                </button>
            </div>
        </div>
    </Transition>
</template>
