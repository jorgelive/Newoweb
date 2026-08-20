// src/stores/chatStore.ts — VERSIÓN CORREGIDA
// Busca "FIX #" para ver cada corrección y su justificación.
import { defineStore } from 'pinia';
import { ref, computed, shallowRef, watch } from 'vue';
import { useAttachmentStore } from '../attachmentStore.ts';
import { useNotificationStore } from '../notificationStore.ts';
import { useNoLeidosStore } from './noLeidosStore.ts';
import type { components } from '@/types/api';
import { apiClient, getUrls, type CustomAxiosRequestConfig } from '@/services/apiClient.ts';
import { esErrorSilencioso } from '@/services/apiError';
import { miembrosHydra, hayPaginaSiguiente, uuidDe, mismaEntidad, type RecursoHydra } from '@/services/hydra';
import { isSessionExpired, checkSession } from '@/services/sessionAuth.ts';

// ============================================================================
// TIPOS (sin cambios)
// ============================================================================
export type ApiMessageQueue = components['schemas']['WhatsappMetaSendQueue.jsonld-message.read'] | components['schemas']['Beds24SendQueue-message.read'];
export type ApiAttachment = components['schemas']['MessageAttachment.jsonld-message.read'];
export type ApiTemplate = components['schemas']['Template.jsonld-template.read'];

/**
 * TIPADO HÍBRIDO CONVERSACIÓN:
 * Heredamos la estructura robusta de OpenAPI pero flexibilizamos `contextMilestones`.
 * Esto previene errores de tipado estricto cuando el PMS envía estructuras de fechas variables.
 */
/**
 * Un canal y si el operador puede usarlo en este hilo.
 *
 * 🪞 Espejo de `CanalesDisponibles::para()` en
 * `src/Message/Service/Conversacion/CanalesDisponibles.php`. **Se declara a mano y no sale de
 * `api.d.ts`** porque el endpoint devuelve una `JsonResponse` montada (`output: false`), así
 * que API Platform no documenta su forma. Si allí cambia la forma o nace un código de motivo
 * nuevo, esto se toca en la misma sesión.
 *
 * `motivo` es un CÓDIGO, no una frase: el texto lo escribe el panel, que es quien sabe en qué
 * idioma y con cuántos caracteres cabe.
 */
/**
 * Un ASUNTO del hilo: una reserva, un expediente de viaje.
 *
 * 🪞 Espejo de `AsuntosDeConversacionController`. Se declara a mano porque el endpoint devuelve
 * una `JsonResponse` montada (`output: false`) y no entra en `api.d.ts`.
 *
 * `etiqueta` la redacta el DOMINIO, que es quien sabe qué se puede enseñar —la casita y las
 * fechas sí, el localizador y el saldo no—. Aquí se pinta tal cual, no se compone.
 */
export interface AsuntoDelHilo {
    negocio: string;
    contextType: string;
    contextId: string;
    etiqueta: string;
    esTitular: boolean;
    origen: string | null;
}

export interface CanalDisponible {
    id: string;
    nombre: string;
    disponible: boolean;
    motivo: 'no_existe_para_el_asunto' | 'sin_datos_o_vetado' | null;
}

type BaseApiConversation = components['schemas']['Conversation-conversation.read'];
export type ApiConversation = Omit<BaseApiConversation, 'contextMilestones'> & {
    '@id'?: string;
    '@type'?: string;
    /**
     * Hitos del contexto (reserva/cotización). El PMS manda un JSON con claves
     * variables según el tipo, de ahí el índice abierto; las cuatro conocidas se
     * declaran para que la UI las autocomplete.
     *
     * Antes esto era `{...} | any`, que en TypeScript colapsa TODA la unión a
     * `any` y dejaba el campo sin tipar del todo.
     */
    contextMilestones?: {
        start?: string;
        end?: string;
        booked_at?: string;
        eta?: string;
        [clave: string]: string | undefined;
    };
};

/**
 * TIPADO HÍBRIDO MENSAJE:
 * Heredamos de OpenAPI pero definimos explícitamente el JSON libre (`metadata`)
 * para garantizar autocompletado en la UI al leer respuestas de Webhooks (ej. `error_reason`).
 */
type BaseApiMessage = components['schemas']['Message.jsonld-message.read'];
export type ApiMessage = Omit<BaseApiMessage, 'metadata' | 'template' | 'channel' | 'whatsappMetaSendQueues' | 'beds24SendQueues' | 'attachments'> & {
    '@id'?: string;
    '@type'?: string;
    metadata?: {
        beds24?: { sent_at?: string; delivered_at?: string; read_at?: string; error?: string; [key: string]: unknown; };
        whatsappMeta?: { sent_at?: string; delivered_at?: string; read_at?: string; error_code?: string; error_reason?: string; reactions?: Record<string, string>; [key: string]: unknown; };
        dispatch_errors?: string[];
        dispatch_warnings?: string[];
        [key: string]: unknown;
    };
    /** IRI cuando llega como referencia, u objeto embebido con el nombre. */
    template?: string | (RecursoHydra & { name?: string }) | null;
    channel?: { id: string; name: string } | string | null;
    whatsappMetaSendQueues?: ApiMessageQueue[] | string[];
    beds24SendQueues?: ApiMessageQueue[] | string[];
    attachments?: ApiAttachment[] | string[];
};

// ============================================================================
// FIX #1 — IDENTIDAD CANÓNICA DE ENTIDADES
// ============================================================================
// La API REST expone "@id": "/platform/message/conversations/{uuid}"
// pero Mercure publica "@id": "/platform/user/util/msg/conversations/{uuid}".
// Comparar por "@id" NUNCA hace match entre ambos orígenes, por eso el
// findIndex/find fallaba y cada evento de Mercure insertaba un DUPLICADO
// (de la conversación en el inbox y de cada mensaje en el chat).
// Solución: comparar SIEMPRE por el UUID (campo `id`, o el último segmento
// del "@id" como fallback).
const uuidOf = uuidDe;
const sameEntity = mismaEntidad;

export const useChatStore = defineStore('chatStore', () => {

    /**
     * Calcula el estado visual final de un mensaje consolidando sus colas de envío subyacentes.
     * Si todas las colas (WhatsApp, Beds24, etc.) fueron canceladas, el mensaje se muestra como cancelado.
     *
     * @param {ApiMessage} msg El mensaje a evaluar.
     * @returns {string} El estado semántico (ej: 'cancelled', 'sent', 'delivered').
     */
    const getMessageDisplayStatus = (msg: ApiMessage): string => {
        if (msg.status === 'cancelled') return 'cancelled';
        const allQueues = [
            ...(msg.whatsappMetaSendQueues || []),
            ...(msg.beds24SendQueues || [])
        ].filter(q => typeof q === 'object') as ApiMessageQueue[];
        if (allQueues.length > 0 && allQueues.every(q => q.status === 'cancelled')) {
            return 'cancelled';
        }
        return msg.status;
    };

    // ============================================================================
    // ESTADOS PRINCIPALES
    // ============================================================================
    const conversations = ref<ApiConversation[]>([]);
    const currentConversation = ref<ApiConversation | null>(null);
    const messages = ref<ApiMessage[]>([]);
    /**
     * Qué canales puede usar el operador en el chat abierto, según el BACKEND.
     *
     * ⚠️ Antes esto se decidía aquí, en TypeScript: `ChatView.vue` comprobaba
     * `contextType !== 'pms_reserva'` y una lista de orígenes copiada a mano de
     * `Beds24SendEnqueuer`. Era la misma regla de negocio escrita dos veces —lo que
     * `CLAUDE.md` prohíbe— y, peor, la copia juzgaba por la CABECERA del hilo: la señal que
     * dejó de ser fiable el día que las conversaciones se fusionaron por persona, porque un
     * hilo puede llevar una estancia de Booking y un viaje a la vez y la cabecera sólo nombra
     * a uno.
     *
     * 🪞 Fuente única: `CanalesDisponibles::para()`. Aquí no se decide, se pinta.
     *
     * Vacío mientras carga o si la petición falla; el chat entonces no ofrece ningún canal,
     * que es el lado seguro: ofrecer uno que no funciona termina en «enviado» y un mensaje
     * que nunca sale.
     */
    const canalesDelChat = ref<CanalDisponible[]>([]);

    /**
     * Los asuntos del chat abierto, y a cuál va lo que se escriba.
     *
     * ⚠️ Con un solo asunto el backend lo estampa solo (`AsuntoDelMensaje`), así que el panel
     * no manda nada y `asuntoElegido` se queda en `null`. El selector sólo aparece cuando de
     * verdad hay que elegir — con dos o más—, que es cuando `null` significa «ambiguo».
     */
    const asuntosDelChat = ref<AsuntoDelHilo[]>([]);
    const asuntoElegido = ref<AsuntoDelHilo | null>(null);

    const templates = ref<ApiTemplate[]>([]);
    const filterStatus = ref<string>('open');

    // Estados de Carga
    const loadingConversations = ref(false);
    const loadingMessages = ref(false);
    const sendingMessage = ref(false);
    const error = ref<string | null>(null);

    // Paginación
    const conversationsPage = ref(1);
    const hasMoreConversations = ref(true);
    const loadingMoreConversations = ref(false);

    const messagesPage = ref(1);
    const hasMoreMessages = ref(true);
    const loadingMoreMessages = ref(false);

    // UI & Webhooks
    const isChatVisible = ref(true);
    const newNotification = ref<{ show: boolean, title: string, conversationId: string } | null>(null);

    // Conexiones Mercure
    const eventSource = shallowRef<EventSource | null>(null);
    const globalEventSource = shallowRef<EventSource | null>(null);

    // ============================================================================
    // FIX #2 — GUARDAS DE RECONEXIÓN (generación + timer único)
    // ============================================================================
    // Antes: cada onerror programaba un setTimeout de reconexión SIN cancelar
    // los anteriores, y las llamadas concurrentes a initGlobalMercure /
    // connectToMercure (renewSession, retry, selección rápida de chats) podían
    // pasar juntas el `close()` inicial y terminar con 2+ EventSource vivos.
    // Dos túneles paralelos = cada evento procesado dos veces = mensajes y
    // conversaciones duplicados, notificaciones dobles.
    // Solución: contador de generación; solo la llamada más reciente puede
    // asignar el EventSource y sus handlers ignoran eventos si fueron superados.
    let globalGen = 0;
    let globalRetryTimer: ReturnType<typeof setTimeout> | null = null;
    let convGen = 0;
    let convRetryTimer: ReturnType<typeof setTimeout> | null = null;

    // ============================================================================
    // FIX #3 — Last-Event-ID (eventos perdidos = notificaciones que no llegan)
    // ============================================================================
    // Antes: al reconectar se creaba un EventSource nuevo "desde cero", así que
    // todo lo publicado durante la desconexión (≥5s de backoff + tiempo caído)
    // se PERDÍA para siempre → "a veces las notificaciones no llegan".
    // Solución: guardar event.lastEventId y reenviarlo como query param
    // `lastEventID`; el hub de Mercure re-entrega lo que ocurrió en el gap.
    let globalLastEventId: string | null = null;
    let convLastEventId: string | null = null;

    // ============================================================================
    // GETTERS (sin cambios funcionales)
    // ============================================================================
    const filteredConversations = computed(() => conversations.value.filter(c => c.status && c.status.toLowerCase() === filterStatus.value.toLowerCase()));

    /**
     * Filtra los mensajes del historial activo.
     * Excluye los mensajes cancelados y los programados cuyo tiempo efectivo aún no se cumple.
     */
    const activeChatMessages = computed(() => {
        const now = new Date();
        return messages.value.filter(m => {
            if (getMessageDisplayStatus(m) === 'cancelled') return false;
            const effectiveDate = new Date(m.effectiveDateTime || m.createdAt as string);
            return m.scheduledForFuture === false || effectiveDate <= now;
        });
    });

    /**
     * Extrae y ordena cronológicamente los mensajes que están encolados para envío futuro.
     */
    const scheduledMessages = computed(() => {
        const now = new Date();
        return messages.value
            .filter(m => getMessageDisplayStatus(m) !== 'cancelled' && m.scheduledForFuture === true && new Date(m.effectiveDateTime || m.createdAt as string) > now)
            .sort((a, b) => new Date(a.effectiveDateTime || a.createdAt as string).getTime() - new Date(b.effectiveDateTime || b.createdAt as string).getTime());
    });

    /**
     * Extrae los mensajes abortados para la pestaña de cancelados.
     */
    const cancelledMessages = computed(() => {
        return messages.value
            .filter(m => getMessageDisplayStatus(m) === 'cancelled')
            .sort((a, b) => new Date(a.effectiveDateTime || a.createdAt as string).getTime() - new Date(b.effectiveDateTime || b.createdAt as string).getTime());
    });

    /**
     * Devuelve únicamente las plantillas autorizadas para el contexto actual.
     * Previene que se envíen Quick Replies de un tipo de reserva a otro origen no compatible.
     */
    const validTemplates = computed(() => {
        if (!currentConversation.value) return [];
        const chat = currentConversation.value;
        const origin = chat.contextOrigin || 'manual';
        return templates.value.filter(t => (!t.contextType || t.contextType === chat.contextType) && (!t.allowedSources?.length || t.allowedSources.includes(origin)));
    });

    /**
     * Resuelve dinámicamente la URL absoluta del panel de administración (Symfony)
     * basándose en el ID de la reserva vinculada a la conversación.
     *
     * ⚠️ Ya NO lo usa el botón «Ver Reserva»: para `pms_reserva` la ficha se abre con
     * `ReservaEditDrawer` sobre el propio chat (ver `getReservaContextId`). Se mantiene
     * porque sigue siendo la única salida al panel para los contextos que aún no tienen
     * pantalla propia en la SPA; en cuanto no quede ninguno, se retira entero.
     */
    const getExternalContextUrl = computed(() => {
        if (!currentConversation.value) return null;
        const chat = currentConversation.value;
        const routes: Record<string, string> = { 'pms_reserva': `/pms-reserva/${chat.contextId}` };
        return routes[chat.contextType || ''] ? `${getUrls().panel}${routes[chat.contextType || '']}` : null;
    });

    /**
     * El UUID de la PmsReserva de la que habla este hilo, o `null` si el contexto es otro.
     *
     * `contextId` es opaco: sólo significa «reserva» cuando `contextType` es `pms_reserva`.
     * La comprobación vive aquí y no en la vista para que el día que haya un drawer de
     * cotizaciones no se copie el `if` a otro sitio.
     */
    const getReservaContextId = computed(() => {
        const chat = currentConversation.value;
        if (!chat || chat.contextType !== 'pms_reserva') return null;
        return chat.contextId || null;
    });

    // ============================================================================
    // UTILERÍAS API PLATFORM
    // ============================================================================
    const extractData = <T>(response: { data: unknown }): T[] => miembrosHydra<T>(response.data);

    const hasNextPage = (response: { data: unknown }): boolean => hayPaginaSiguiente(response.data);

    // ============================================================================
    // SESIÓN (sin cambios)
    // ============================================================================


    // ============================================================================
    // ACCIONES DE DATOS
    // ============================================================================
    const fetchTemplates = async () => {
        try {
            const response = await apiClient.get('/platform/message/templates');
            templates.value = extractData<ApiTemplate>(response);
        } catch {
            // Las plantillas son un atajo para escribir, no un requisito: si el maestro no
            // responde, el chat funciona igual y el operador teclea el mensaje. Avisar aquí
            // sería un error visible por algo que no le impide hacer nada.
        }
    };

    /**
     * Obtiene el listado de conversaciones. Soporta paginación.
     *
     * @param {boolean} loadMore Si es true, añade los resultados al final de la lista existente.
     */
    const fetchConversations = async (loadMore = false) => {
        let pageToFetch = 1;
        if (loadMore) {
            if (!hasMoreConversations.value || loadingMoreConversations.value) return;
            loadingMoreConversations.value = true;
            pageToFetch = conversationsPage.value + 1;
        } else {
            loadingConversations.value = true;
            hasMoreConversations.value = true;
        }

        try {
            const response = await apiClient.get(`/platform/message/conversations?order[lastMessageAt]=desc&page=${pageToFetch}`);
            const data = extractData<ApiConversation>(response);
            if (loadMore) {
                // FIX #4: la paginación + eventos de Mercure entre páginas puede
                // traer una conversación que ya subió al tope del inbox → dedup.
                const fresh = data.filter(d => !conversations.value.some(c => sameEntity(c, d)));
                conversations.value.push(...fresh);
            } else {
                conversations.value = data;
            }

            hasMoreConversations.value = hasNextPage(response);
            conversationsPage.value = pageToFetch;
        } catch (err: unknown) {
            // Ignoramos errores 401/HTML ya que el apiClient centralizado los maneja.
            if (!esErrorSilencioso(err)) {
                error.value = 'Error al sincronizar chats';
            }
        } finally {
            loadingConversations.value = false;
            loadingMoreConversations.value = false;
        }
    };

    // ============================================================================
    // MERCURE GLOBAL
    // ============================================================================

    /**
     * Inicializa el túnel global para escuchar nuevos mensajes en *todas* las conversaciones.
     * Gestiona las notificaciones push nativas si el chat no está en foco.
     */
    const initGlobalMercure = async () => {
        const gen = ++globalGen;                               // FIX #2
        if (globalRetryTimer) { clearTimeout(globalRetryTimer); globalRetryTimer = null; }
        globalEventSource.value?.close();
        globalEventSource.value = null;

        const scheduleRetry = () => {
            if (gen !== globalGen || globalRetryTimer) return;
            globalRetryTimer = setTimeout(async () => {
                globalRetryTimer = null;
                if (gen !== globalGen) return;
                const isAlive = await checkSession();
                if (!isAlive) isSessionExpired.value = true;
                else initGlobalMercure();
            }, 5000);
        };

        try {
            const authResponse = await apiClient.get('/message/mercure/auth', { _silentAuthCheck: true } as CustomAxiosRequestConfig);
            if (authResponse.headers['content-type']?.includes('text/html')) throw new Error('HTML response');
            if (gen !== globalGen) return;                     // FIX #2: llamada superada

            const { hubUrl, token } = authResponse.data;
            const url = new URL(hubUrl);
            url.searchParams.append('topic', 'https://openperu.pe/host/conversations');
            if (token) url.searchParams.append('authorization', token);
            if (globalLastEventId) url.searchParams.append('lastEventID', globalLastEventId); // FIX #3

            const es = new EventSource(url.toString(), { withCredentials: true });
            globalEventSource.value = es;

            const notificationStore = useNotificationStore();

            es.onmessage = (event) => {
                if (gen !== globalGen) return;                 // FIX #2: túnel viejo → ignorar
                if (event.lastEventId) globalLastEventId = event.lastEventId; // FIX #3

                const data = JSON.parse(event.data);
                if (data.type !== 'conversation_updated' && data.type !== 'conversation_created') return;

                const convData = data.conversation;
                // FIX #1: match por UUID, no por "@id" (los IRIs difieren entre REST y Mercure)
                const existingConv = conversations.value.find(c => sameEntity(c, convData));
                const isCurrentOpen = sameEntity(currentConversation.value, convData); // FIX #1

                if (convData.unreadCount > (existingConv?.unreadCount || 0) && (!isCurrentOpen || !isChatVisible.value)) {
                    const safeId = uuidOf(convData);
                    newNotification.value = { show: true, conversationId: safeId || '', title: convData.guestName || 'Huésped' };
                    setTimeout(() => { newNotification.value = null; }, 5000);

                    notificationStore.addNotification({
                        title: `Mensaje de ${convData.guestName || 'Huésped'}`,
                        body: 'Tienes un nuevo mensaje sin leer.',
                        type: 'info',
                        actionUrl: safeId ? `/chat?id=${safeId}` : '/chat'
                    });
                }

                if (existingConv) {
                    // FIX #5: NO sobrescribir el "@id" original con el IRI de Mercure;
                    // otras partes del código (sendMessage) construyen URLs con él.
                    const { '@id': _mercureIri, '@type': _t, ...rest } = convData;
                    Object.assign(existingConv, rest);
                } else {
                    conversations.value.unshift(convData);
                }

                conversations.value.sort((a, b) => new Date(b.lastMessageAt || 0).getTime() - new Date(a.lastMessageAt || 0).getTime());

                // El resumen global no se deriva de esta lista (está paginada): se
                // le pide al servidor que lo recalcule. Agrupado, porque un huésped
                // que escribe tres líneas seguidas dispara tres eventos.
                useNoLeidosStore().refrescarPronto();
            };

            es.onerror = () => {
                if (gen !== globalGen) return;                 // FIX #2
                es.close();
                scheduleRetry();
            };
        } catch {
            scheduleRetry();                                    // FIX #3: antes un fallo de auth
                                                                // dejaba el túnel muerto sin retry
        }
    };

    // ============================================================================
    // MERCURE POR CONVERSACIÓN
    // ============================================================================
    const connectToMercure = async (conversationId: string) => {
        const gen = ++convGen;                                  // FIX #2
        if (convRetryTimer) { clearTimeout(convRetryTimer); convRetryTimer = null; }
        eventSource.value?.close();
        eventSource.value = null;
        convLastEventId = null; // el historial se recarga completo al seleccionar; empezamos limpio

        try {
            const authResponse = await apiClient.get('/message/mercure/auth', { _silentAuthCheck: true } as CustomAxiosRequestConfig);
            if (authResponse.headers['content-type']?.includes('text/html')) throw new Error('HTML response');
            if (gen !== convGen) return;                        // FIX #2: el usuario ya cambió de chat

            const { hubUrl, token } = authResponse.data;
            const url = new URL(hubUrl);
            url.searchParams.append('topic', `https://openperu.pe/conversations/${conversationId}`);
            if (token) url.searchParams.append('authorization', token);
            if (convLastEventId) url.searchParams.append('lastEventID', convLastEventId); // FIX #3 (reconexiones)

            const es = new EventSource(url.toString(), { withCredentials: true });
            eventSource.value = es;

            es.onmessage = (event) => {
                if (gen !== convGen) return;                    // FIX #2
                if (event.lastEventId) convLastEventId = event.lastEventId;

                // FIX #6: si por carrera el chat visible ya no es este, no tocar `messages`
                if (uuidOf(currentConversation.value) !== conversationId) return;

                const incomingData = JSON.parse(event.data);

                // FIX #1: dedupe por UUID. Antes, un "message_updated" de Mercure
                // (ej. sent → delivered) traía otro IRI, no hacía match y se
                // insertaba como mensaje NUEVO → mensajes duplicados en pantalla.
                const index = messages.value.findIndex(m => sameEntity(m, incomingData));

                if (index !== -1) {
                    messages.value.splice(index, 1, { ...messages.value[index], ...incomingData, '@id': messages.value[index]['@id'] || incomingData['@id'] }); // FIX #5
                } else {
                    // Es un mensaje nuevo entrante
                    messages.value.push(incomingData);

                    if (incomingData.direction === 'incoming') {
                        // Si el chat está abierto en pantalla, disparamos POST para marcar como leído en BD
                        if (isChatVisible.value) {
                            apiClient.post(`/platform/message/conversations/${conversationId}/read`)
                                .then(() => useNoLeidosStore().refrescarPronto())
                                .catch(() => {});
                            if (currentConversation.value) currentConversation.value.unreadCount = 0;
                        } else if (currentConversation.value) {
                            currentConversation.value.unreadCount = (currentConversation.value.unreadCount || 0) + 1;
                        }
                    }
                }
            };

            es.onerror = () => {
                if (gen !== convGen) return;                    // FIX #2
                es.close();
                if (convRetryTimer) return;
                convRetryTimer = setTimeout(async () => {
                    convRetryTimer = null;
                    if (gen !== convGen) return;
                    const isAlive = await checkSession();
                    if (!isAlive) isSessionExpired.value = true;
                    else if (uuidOf(currentConversation.value) === conversationId) connectToMercure(conversationId);
                }, 5000);
            };
        } catch {
            // El propio EventSource ya trae su reintento en `onerror`. Lo que se atrapa aquí
            // es que el navegador ni siquiera deje abrirlo (sin red, o bloqueado): en ese
            // caso el chat se queda sin tiempo real, pero sigue leyéndose y enviándose por
            // HTTP. Romper la vista por perder el empuje sería peor que no tenerlo.
        }
    };

    // ============================================================================
    // FIX #7 — REANUDAR MERCURE TRAS RE-LOGIN
    // ============================================================================
    // Antes: cuando initGlobalMercure/connectToMercure detectaban sesión muerta,
    // marcaban isSessionExpired = true y dejaban de reintentar (correcto, para no
    // seguir pegándole a una sesión caída). Pero una vez el usuario re-loguea desde
    // el GlobalLoginModal, NADA volvía a llamar a estas funciones: los túneles
    // quedaban cerrados para siempre y el usuario dejaba de recibir mensajes y
    // notificaciones en tiempo real hasta recargar la página a mano.
    // Solución: escuchar el mismo flag compartido (isSessionExpired) y, apenas
    // pasa de true a false (o sea: el modal se cerró porque el login funcionó),
    // reabrir el túnel global y, si había una conversación abierta, su túnel también.
    watch(isSessionExpired, (expired, wasExpired) => {
        if (!wasExpired || expired) return; // solo nos interesa la transición true -> false

        initGlobalMercure();

        const openConversationId = uuidOf(currentConversation.value);
        if (openConversationId) {
            connectToMercure(openConversationId);
        }
    });

    // ============================================================================
    // SELECCIÓN / HISTORIAL
    // ============================================================================
    const selectConversation = async (id: string) => {
        error.value = null;

        // FIX #1: buscar por UUID canónico (el item puede haber entrado vía Mercure)
        let found = conversations.value.find(c => uuidOf(c) === id);

        // 2. Si es un enlace directo o un chat muy antiguo, buscamos en BD
        if (!found) {
            loadingMessages.value = true;
            try {
                const response = await apiClient.get(`/platform/message/conversations/${id}`);
                found = response.data;
                // FIX #4: revalidar que Mercure no la insertó mientras esperábamos el GET
                if (found && !conversations.value.some(c => sameEntity(c, found))) {
                    conversations.value.unshift(found);
                }
            } catch {
                loadingMessages.value = false;
                error.value = 'Conversación no encontrada.';
                return;
            }
        }

        currentConversation.value = found || null;
        loadingMessages.value = true;
        messagesPage.value = 1;
        hasMoreMessages.value = true;
        canalesDelChat.value = [];
        asuntosDelChat.value = [];
        asuntoElegido.value = null;

        // Una petición por chat abierto. No son campos de la colección a propósito:
        // serializarlos metería un N+1 en un listado de 300 y pico hilos para ahorrar esto.
        void fetchCanales(id);
        void fetchAsuntos(id);

        try {
            if (found && (found.unreadCount ?? 0) > 0) {
                apiClient.post(`/platform/message/conversations/${id}/read`).then(() => {
                    if (found) found.unreadCount = 0;
                    // Abrir un chat baja el total global: el badge y los contadores
                    // del portal tienen que enterarse, no solo esta lista.
                    useNoLeidosStore().refrescarPronto();
                });
                found.unreadCount = 0;
            }

            const response = await apiClient.get(`/platform/message/conversations/${id}/messages?order[createdAt]=desc&page=1`);
            // FIX #6: si el usuario cambió de chat mientras cargaba, descartar
            if (uuidOf(currentConversation.value) !== id) return;

            messages.value = extractData<ApiMessage>(response).reverse();
            hasMoreMessages.value = hasNextPage(response);

            connectToMercure(id);
        } catch {
            error.value = 'Error al cargar historial del chat.';
        } finally {
            loadingMessages.value = false;
        }
    };

    /**
     * Trae del backend los canales usables en un hilo. Ver `canalesDelChat`.
     *
     * @param {string} id UUID de la conversación.
     */
    const fetchCanales = async (id: string, asunto: AsuntoDelHilo | null = null): Promise<void> => {
        try {
            const response = await apiClient.get(`/platform/message/conversations/${id}/canales`, {
                params: asunto
                    ? { asuntoType: asunto.contextType, asuntoId: asunto.contextId }
                    : {},
            });

            // Si el operador cambió de chat mientras cargaba, esta respuesta ya no vale.
            if (uuidOf(currentConversation.value) !== id) return;

            canalesDelChat.value = (response.data?.canales ?? []) as CanalDisponible[];
        } catch {
            canalesDelChat.value = [];
        }
    };

    /**
     * Trae los asuntos del hilo y preselecciona el titular si hay que elegir.
     *
     * @param {string} id UUID de la conversación.
     */
    const fetchAsuntos = async (id: string): Promise<void> => {
        try {
            const response = await apiClient.get(`/platform/message/conversations/${id}/asuntos`);

            if (uuidOf(currentConversation.value) !== id) return;

            const asuntos = (response.data?.asuntos ?? []) as AsuntoDelHilo[];
            asuntosDelChat.value = asuntos;

            // Con uno solo no se elige: lo estampa el backend. Con varios se propone el
            // TITULAR —el hilo que atiende el asunto— y el operador cambia si toca.
            asuntoElegido.value = asuntos.length > 1
                ? (asuntos.find(a => a.esTitular) ?? asuntos[0])
                : null;
        } catch {
            asuntosDelChat.value = [];
            asuntoElegido.value = null;
        }
    };

    /**
     * Añade un identificador a la persona del chat abierto.
     *
     * ⚠️ Devuelve el MENSAJE de error del backend cuando lo hay, y no un genérico: el caso que
     * importa es «ese número ya es de otra conversación», que no es un dato mal escrito sino
     * una fusión, y el operador tiene que leer cuál es la otra persona para decidir.
     *
     * @param {string} tipo `telefono` | `email` | `beds24`.
     * @param {string} valor El identificador tal como lo teclea el operador; se normaliza en el
     *                       backend, que es el único sitio donde se normaliza.
     * @returns {Promise<string | null>} `null` si fue bien; el motivo si no.
     */
    const anadirIdentidad = async (tipo: string, valor: string): Promise<string | null> => {
        const id = uuidOf(currentConversation.value);
        if (!id) return 'No hay ninguna conversación abierta.';

        try {
            await apiClient.post(`/platform/message/conversations/${id}/identidades`, { tipo, valor });
            await refrescarConversacion(id);

            return null;
        } catch (e: unknown) {
            return mensajeDeError(e) ?? 'No se pudo añadir el identificador.';
        }
    };

    /**
     * Marca principal, veta / levanta el veto, o retira un identificador.
     *
     * `retirada` NO borra: el identificador sigue resolviendo el historial y deja de ser salida.
     *
     * @param {string} identidadId UUID de la identidad.
     * @param {object} cambios Campos a aplicar; los omitidos no se tocan.
     * @returns {Promise<string | null>} `null` si fue bien; el motivo si no.
     */
    const cambiarIdentidad = async (
        identidadId: string,
        cambios: { principal?: boolean; bloqueado?: boolean; retirada?: boolean },
    ): Promise<string | null> => {
        const id = uuidOf(currentConversation.value);
        if (!id) return 'No hay ninguna conversación abierta.';

        try {
            await apiClient.patch(`/platform/message/conversations/${id}/identidades/${identidadId}`, cambios);
            await refrescarConversacion(id);

            return null;
        } catch (e: unknown) {
            return mensajeDeError(e) ?? 'No se pudo cambiar el identificador.';
        }
    };

    /**
     * Recarga la conversación tras tocar sus identidades.
     *
     * Hace falta porque cambiar una identidad puede mover el veto del HILO —queda bloqueado sólo
     * si todos sus teléfonos vivos lo están—, y ese flag lo pinta la barra de canales. Se
     * refrescan también los canales por lo mismo.
     */
    const refrescarConversacion = async (id: string): Promise<void> => {
        const { data } = await apiClient.get(`/platform/message/conversations/${id}`);

        if (uuidOf(currentConversation.value) !== id) return;

        currentConversation.value = data;

        const enLista = conversations.value.findIndex(c => uuidOf(c) === id);
        if (enLista !== -1) conversations.value[enLista] = data;

        await fetchCanales(id, asuntoElegido.value);
    };

    /** El texto que manda el backend en `{"error": "…"}`, si vino. */
    const mensajeDeError = (e: unknown): string | null => {
        const cuerpo = (e as { response?: { data?: { error?: string } } })?.response?.data;

        return typeof cuerpo?.error === 'string' ? cuerpo.error : null;
    };

    /**
     * Marca este hilo como TITULAR de un asunto: el que recibe la agenda automática.
     *
     * El otro sigue existiendo y el agente le contesta sobre el asunto; lo que deja de recibir
     * son los envíos programados —bienvenida, recordatorio de saldo, check-out—. Es la decisión
     * de a quién de los dos se le escribe cuando una reserva la atienden dos personas desde
     * números distintos.
     *
     * @param {AsuntoDelHilo} asunto El asunto cuyo titular pasa a ser este hilo.
     * @returns {Promise<string | null>} `null` si fue bien; el motivo si no.
     */
    const hacerseTitular = async (asunto: AsuntoDelHilo): Promise<string | null> => {
        const id = uuidOf(currentConversation.value);
        if (!id) return 'No hay ninguna conversación abierta.';

        try {
            const { data } = await apiClient.patch(`/platform/message/conversations/${id}/asuntos`, {
                contextType: asunto.contextType,
                contextId: asunto.contextId,
            });

            if (uuidOf(currentConversation.value) === id) {
                asuntosDelChat.value = (data?.asuntos ?? []) as AsuntoDelHilo[];
            }

            return null;
        } catch (e: unknown) {
            return mensajeDeError(e) ?? 'No se pudo cambiar el titular.';
        }
    };

    /**
     * Cambia el asunto destino y vuelve a preguntar los canales: no son los mismos.
     *
     * Es el punto del que cuelga todo lo demás — un expediente de viaje no sale por Beds24 y
     * una reserva de OTA sí, y hasta ahora el panel no tenía forma de decir a cuál iba.
     *
     * @param {AsuntoDelHilo | null} asunto El asunto elegido.
     */
    const elegirAsunto = (asunto: AsuntoDelHilo | null): void => {
        asuntoElegido.value = asunto;

        const id = uuidOf(currentConversation.value);
        if (id) void fetchCanales(id, asunto);
    };

    const loadMoreMessages = async () => {
        if (!currentConversation.value || !hasMoreMessages.value || loadingMoreMessages.value) return;

        loadingMoreMessages.value = true;
        const nextPage = messagesPage.value + 1;

        try {
            const response = await apiClient.get(`/platform/message/conversations/${currentConversation.value.id}/messages?order[createdAt]=desc&page=${nextPage}`);
            // FIX #4: un mensaje nuevo llegado por Mercure desplaza la paginación
            // (page 2 puede repetir el último de page 1) → dedup por UUID.
            const olderMessages = extractData<ApiMessage>(response)
                .filter(om => !messages.value.some(m => sameEntity(m, om)))
                .reverse();

            messages.value = [...olderMessages, ...messages.value];
            hasMoreMessages.value = hasNextPage(response);
            messagesPage.value = nextPage;
        } catch {
            error.value = 'Error al paginar historial.';
        } finally {
            loadingMoreMessages.value = false;
        }
    };

    /**
     * Despacha un nuevo mensaje hacia Symfony utilizando FormData.
     * Soporta adjuntos (Multipart) y envíos multicanal (Transient Channels).
     *
     * @param {string} text Contenido local redactado por el usuario.
     * @param {string | null} templateIri IRI del recurso plantilla (opcional).
     * @param {string[]} channels Array con IDs de canales seleccionados (ej. 'beds24').
     */
    const sendMessage = async (text: string, templateIri: string | null = null, channels: string[] = []) => {
        if (!currentConversation.value) return;

        sendingMessage.value = true;
        const attachmentStore = useAttachmentStore();

        try {
            const form = new FormData();
            // FIX #5: construir el IRI SIEMPRE desde el UUID canónico. Antes, si la
            // conversación entró vía Mercure, su "@id" era /platform/user/util/msg/...
            // y el POST a /platform/message/messages podía rechazar la referencia.
            const convId = `/platform/message/conversations/${uuidOf(currentConversation.value)}`;

            form.append('conversation', convId);
            form.append('direction', 'outgoing');
            form.append('senderType', 'host');
            form.append('status', 'pending');

            channels.forEach(channel => form.append('transientChannels[]', channel));

            // A QUÉ asunto va. Sólo cuando hay que elegir: con uno solo lo estampa el backend
            // (`AsuntoDelMensaje`), y con ninguno es que no hay. Lo que se manda se contrasta
            // allí contra los enlaces reales del hilo — si no casa, se descarta.
            if (asuntoElegido.value) {
                form.append('asuntoType', asuntoElegido.value.contextType);
                form.append('asuntoId', asuntoElegido.value.contextId);
            }

            if (templateIri) form.append('template', templateIri);
            else form.append('contentLocal', text.trim());

            if (attachmentStore.file) form.append('file', attachmentStore.file);

            await apiClient.post('/platform/message/messages', form, { headers: { 'Content-Type': 'multipart/form-data' } });
            attachmentStore.clear();
        } catch {
            error.value = 'Fallo al enviar el mensaje. Intente de nuevo.';
        } finally {
            sendingMessage.value = false;
        }
    };

    /**
     * MODO STALKER:
     * Trae los últimos mensajes limpios de una conversación SIN disparar el webhook
     * de lectura (No notifica al huésped). Ideal para previsualizaciones.
     * Filtra rigurosamente los mensajes programados o pendientes, y
     * ordena de más antiguo a más nuevo para una visualización top-down correcta.
     *
     * @param {string} conversationId ID de la conversación
     * @param {number} limite Cuántos mensajes recientes devolver
     * @returns {Promise<ApiMessage[]>} Lista recortada de mensajes
     */
    const fetchLatestMessagesForStalk = async (conversationId: string, limite = 5): Promise<ApiMessage[]> => {
        try {
            const response = await apiClient.get(`/platform/message/conversations/${conversationId}/messages?order[createdAt]=desc&page=1`);
            const data = extractData<ApiMessage>(response);

            const realHistoryMessages = data.filter(m => m.scheduledForFuture !== true && m.status !== 'pending' && m.status !== 'queued' && m.status !== 'cancelled');
            const latest5 = realHistoryMessages.slice(0, limite);

            return latest5.sort((a, b) => new Date(a.effectiveDateTime || a.createdAt as string).getTime() - new Date(b.effectiveDateTime || b.createdAt as string).getTime());
        } catch { return []; }
    };

    /**
     * La cabecera de una conversación, en modo stalker: un GET a secas.
     *
     * ⚠️ Existe para NO usar `selectConversation()` en una previsualización: ésa marca la
     * conversación como leída (`POST …/read`), baja el contador global y abre Mercure. Eso
     * está bien al abrir el chat y está MAL al asomarse a él — el sentido del modo stalker
     * es enterarse sin dejar rastro, y con `selectConversation` un simple hover borraba los
     * «sin leer» del compañero que iba a contestar.
     *
     * Tampoco toca el estado del store (`currentConversation`, `messages`): devuelve el dato
     * y se va, para que una previsualización abierta desde otra vista no altere el inbox.
     *
     * @param {string} id UUID de la conversación.
     * @returns {Promise<ApiConversation | null>} null si no existe o falla.
     */
    const fetchConversacionParaStalk = async (id: string): Promise<ApiConversation | null> => {
        try {
            const response = await apiClient.get(`/platform/message/conversations/${id}`);
            return response.data as ApiConversation;
        } catch {
            return null;
        }
    };

    /**
     * La conversación vinculada a un asunto de otro dominio, también sin marcar nada.
     *
     * `contextType`/`contextId` viajan OPACOS: aquí no se interpreta qué es un `pms_reserva`
     * ni una `cotizacion`, sólo se filtra la colección por ellos. Es lo que permite que la
     * vista previa se use desde cualquier módulo del SPA sin que el chat conozca ninguno
     * (misma regla que los identificadores de dominio en el backend, ver CLAUDE.md).
     *
     * Devuelve la conversación ENTERA y no sólo su id, al contrario que
     * `reservasStore.fetchConversacionId()`: la vista previa necesita además el nombre del
     * huésped, los no leídos y el resumen IA, y pedirlos después serían dos viajes para lo
     * que la colección ya trae en uno.
     *
     * @param {string} contextType Tipo de asunto, tal como lo guarda el chat ('pms_reserva'…).
     * @param {string} contextId UUID del asunto en su dominio.
     * @returns {Promise<ApiConversation | null>} null si ese asunto aún no tiene conversación.
     */
    const fetchConversacionPorContexto = async (contextType: string, contextId: string): Promise<ApiConversation | null> => {
        try {
            // Por el ENLACE TITULAR, no filtrando la colección por cabecera: ver el aviso en
            // `reservasStore.fetchConversacionId()`. Filtrar por cabecera devolvía el hilo
            // equivocado —archivado y vacío— en 26 reservas, y sin dar error.
            const response = await apiClient.get('/platform/message/conversations/por-asunto', {
                params: { contextType, contextId },
            });

            return (response.data as ApiConversation | undefined)?.['@id'] !== undefined
                ? response.data as ApiConversation
                : null;
        } catch {
            return null;
        }
    };

    /**
     * Edita la cabecera de la conversación (nombre/teléfono del huésped, idioma, WhatsApp)
     * vía PATCH (merge-patch+json). Sincroniza el resultado en `currentConversation` y en
     * la lista `conversations` para que el inbox refleje el cambio sin recargar.
     *
     * @param {string} id UUID de la conversación (no el IRI completo).
     * @param {Partial<Pick<ApiConversation, 'guestName' | 'guestPhone' | 'idioma' | 'idiomaFijado' | 'whatsappDisabled' | 'whatsappDisabledReason'>>} payload Campos a actualizar.
     * @returns {Promise<boolean>} true si la actualización tuvo éxito.
     */
    const updateConversation = async (id: string, payload: Record<string, unknown>): Promise<boolean> => {
        try {
            const response = await apiClient.patch(`/platform/message/conversations/${id}`, payload);
            const updated = response.data as ApiConversation;

            if (currentConversation.value && sameEntity(currentConversation.value, updated)) {
                Object.assign(currentConversation.value, updated);
            }
            const idx = conversations.value.findIndex(c => sameEntity(c, updated));
            if (idx !== -1) Object.assign(conversations.value[idx], updated);

            return true;
        } catch {
            error.value = 'No se pudo actualizar la conversación.';
            return false;
        }
    };

    /**
     * Elimina permanentemente una conversación (y sus mensajes, en cascada) vía DELETE.
     * Limpia la conversación de la lista y, si era la conversación abierta, cierra el chat.
     *
     * @param {string} id UUID de la conversación (no el IRI completo).
     * @returns {Promise<boolean>} true si la eliminación tuvo éxito.
     */
    const deleteConversation = async (id: string): Promise<boolean> => {
        try {
            await apiClient.delete(`/platform/message/conversations/${id}`);

            conversations.value = conversations.value.filter(c => uuidOf(c) !== id);
            if (uuidOf(currentConversation.value) === id) {
                currentConversation.value = null;
                messages.value = [];
                eventSource.value?.close();
                eventSource.value = null;
            }

            return true;
        } catch {
            error.value = 'No se pudo eliminar la conversación.';
            return false;
        }
    };

    // ============================================================================
    // BADGE NATIVO — se movió a `noLeidosStore`
    // ----------------------------------------------------------------------------
    // Aquí se calculaba sobre `conversations`, que está PAGINADO: el icono decía 4
    // mientras el chat mostraba 24 no leídos, porque las conversaciones antiguas
    // con pendientes ni siquiera estaban cargadas. Ahora el total lo da el
    // servidor y lo pinta `noLeidosStore.pintarBadge()`, que es la misma fuente
    // que alimenta los contadores de Home, del selector y de las pestañas.
    // No reintroducir un contador local aquí: volvería a divergir.
    // ============================================================================

    return {
        conversations, filteredConversations, currentConversation, canalesDelChat, fetchCanales, asuntosDelChat, asuntoElegido, elegirAsunto, hacerseTitular, anadirIdentidad, cambiarIdentidad, messages, activeChatMessages, scheduledMessages, cancelledMessages, templates, validTemplates, filterStatus, loadingConversations, loadingMessages, sendingMessage, error, loadingMoreConversations, loadingMoreMessages, hasMoreMessages, hasMoreConversations, isSessionExpired, checkSession, getExternalContextUrl, getReservaContextId, fetchConversations, fetchTemplates, selectConversation, loadMoreMessages, sendMessage, initGlobalMercure, connectToMercure, newNotification, isChatVisible, getMessageDisplayStatus, fetchLatestMessagesForStalk, fetchConversacionParaStalk, fetchConversacionPorContexto, updateConversation, deleteConversation
    };
});
