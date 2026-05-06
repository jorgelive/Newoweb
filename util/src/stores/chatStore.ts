// src/stores/chatStore.ts
import { defineStore } from 'pinia';
import { ref, computed, shallowRef, watch } from 'vue';
import { useAttachmentStore } from './attachmentStore';
import { useNotificationStore } from './notificationStore';
// 👇 Importamos los tipos automáticos de API Platform generados desde OpenAPI
import type { components } from '@/types/api';
// 👇 Importamos el cliente centralizado y sus utilidades de sesión
import { apiClient, getUrls, processQueue, type CustomAxiosRequestConfig } from '@/services/apiClient';

// ============================================================================
// TIPOS AUTOGENERADOS (HÍBRIDOS)
// ============================================================================
export type ApiMessageQueue = components['schemas']['WhatsappMetaSendQueue.jsonld-message.read'] | components['schemas']['Beds24SendQueue-message.read'];
export type ApiAttachment = components['schemas']['MessageAttachment.jsonld-message.read'];
export type ApiTemplate = components['schemas']['Template.jsonld-template.read'];

/**
 * TIPADO HÍBRIDO CONVERSACIÓN:
 * Heredamos la estructura robusta de OpenAPI pero flexibilizamos `contextMilestones`.
 * Esto previene errores de tipado estricto cuando el PMS envía estructuras de fechas variables.
 */
type BaseApiConversation = components['schemas']['Conversation-conversation.read'];
export type ApiConversation = Omit<BaseApiConversation, 'contextMilestones'> & {
    '@id'?: string; // 👈 AÑADE ESTO
    '@type'?: string; // 👈 AÑADE ESTO
    contextMilestones?: { start?: string; end?: string; booked_at?: string; eta?: string; } | any;
};

/**
 * TIPADO HÍBRIDO MENSAJE:
 * Heredamos de OpenAPI pero definimos explícitamente el JSON libre (`metadata`)
 * para garantizar autocompletado en la UI al leer respuestas de Webhooks (ej. `error_reason`).
 */
type BaseApiMessage = components['schemas']['Message.jsonld-message.read'];
export type ApiMessage = Omit<BaseApiMessage, 'metadata' | 'template' | 'channel' | 'whatsappMetaSendQueues' | 'beds24SendQueues' | 'attachments'> & {
    '@id'?: string; // 👈 AÑADE ESTO
    '@type'?: string; // 👈 AÑADE ESTO
    metadata?: {
        beds24?: { sent_at?: string; delivered_at?: string; read_at?: string; error?: string; [key: string]: any; };
        whatsappMeta?: { sent_at?: string; delivered_at?: string; read_at?: string; error_code?: string; error_reason?: string; reactions?: Record<string, string>; [key: string]: any; };
        dispatch_errors?: string[];
        dispatch_warnings?: string[];
        [key: string]: any;
    };
    template?: any; // API Platform puede devolver el IRI (string) o el objeto anidado según el grupo
    channel?: { id: string; name: string } | string | null;
    whatsappMetaSendQueues?: ApiMessageQueue[] | string[];
    beds24SendQueues?: ApiMessageQueue[] | string[];
    attachments?: ApiAttachment[] | string[];
};

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
    // ESTADOS Y LÓGICA DE SESIÓN
    // ============================================================================

    /**
     * Flag reactivo que detiene la UI y muestra el modal de re-login
     * cuando `apiClient.ts` detecta una caída de sesión (HTML o 401).
     */
    const isSessionExpired = ref(false);

    // ============================================================================
    // ESTADOS PRINCIPALES DEL CHAT
    // ============================================================================
    const conversations = ref<ApiConversation[]>([]);
    const currentConversation = ref<ApiConversation | null>(null);
    const messages = ref<ApiMessage[]>([]);
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
    // GETTERS (COMPUTED)
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
     */
    const getExternalContextUrl = computed(() => {
        if (!currentConversation.value) return null;
        const chat = currentConversation.value;
        const routes: Record<string, string> = { 'pms_reserva': `/pms-reserva/${chat.contextId}` };
        return routes[chat.contextType || ''] ? `${getUrls().panel}${routes[chat.contextType || '']}` : null;
    });

    // ============================================================================
    // UTILERÍAS INTERNAS API PLATFORM
    // ============================================================================

    const extractData = (response: any) => {
        const data = response.data;
        return data['hydra:member'] || data['member'] || (Array.isArray(data) ? data : []);
    };

    const hasNextPage = (response: any) => {
        const view = response.data['hydra:view'] || response.data['view'];
        return !!(view && (view['hydra:next'] || view['next']));
    };

    // ============================================================================
    // COMPROBACIÓN DE SESIÓN RESILIENTE A CAÍDAS DE RED
    // ============================================================================

    /**
     * Verifica la vigencia de la sesión sin disparar errores visuales.
     * Utiliza un endpoint protegido. Si devuelve HTML (Firewall atrapado) o 401, la sesión murió.
     * Tolera fallas de conexión (timeout) para no cerrar la sesión si solo se cortó el WiFi.
     *
     * @returns {Promise<boolean>} True si la sesión está viva, False si expiró.
     */
    const checkSession = async (): Promise<boolean> => {
        try {
            const authResponse = await apiClient.get('/message/mercure/auth', { _silentAuthCheck: true } as CustomAxiosRequestConfig);
            if (authResponse.headers['content-type']?.includes('text/html')) {
                return false;
            }
            return true;
        } catch (e: any) {
            if (e.response?.status === 401) {
                return false;
            }
            return true;
        }
    };

    /**
     * Ejecuta el login AJAX contra Symfony. Si es exitoso, purga la cola centralizada
     * de peticiones fallidas (en apiClient) y reinicia los túneles WebSockets.
     *
     * @param {Object} credentials Credenciales (_username, _password, _remember_me).
     * @returns {Promise<boolean>}
     */
    const renewSession = async (credentials: { _username: string, _password: string, _remember_me?: boolean }): Promise<boolean> => {
        try {
            await apiClient.post('/ajax_login', credentials);
            isSessionExpired.value = false;
            error.value = null;

            // 1. Liberamos cualquier petición pendiente pausada en el interceptor global
            processQueue(null);

            // 2. Reconectamos escuchas en tiempo real
            await initGlobalMercure();
            if (currentConversation.value?.id) await connectToMercure(currentConversation.value.id);

            return true;
        } catch (err: any) {
            error.value = err.response?.data?.message || 'Error de autenticación.';
            processQueue(err);
            return false;
        }
    };

    /**
     * Aborta el proceso de re-login vaciando la cola de promesas con un error.
     */
    const cancelRenewal = () => {
        isSessionExpired.value = false;
        processQueue(new Error('Cancelado por el usuario.'));
    };

    // ============================================================================
    // ACCIONES DE DATOS (CRUD)
    // ============================================================================

    const fetchTemplates = async () => {
        try {
            const response = await apiClient.get('/platform/message/templates');
            templates.value = extractData(response);
        } catch (err) {}
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
            const data = extractData(response);
            if (loadMore) conversations.value.push(...data);
            else conversations.value = data;

            hasMoreConversations.value = hasNextPage(response);
            conversationsPage.value = pageToFetch;
        } catch (err: any) {
            // Ignoramos errores 401/HTML ya que el apiClient centralizado los maneja.
            if (err.response?.status !== 401 && !err.message?.includes('HTML')) {
                error.value = 'Error al sincronizar chats';
            }
        } finally {
            loadingConversations.value = false;
            loadingMoreConversations.value = false;
        }
    };

    // ============================================================================
    // CONEXIONES MERCURE (WEBSOCKETS)
    // ============================================================================

    /**
     * Inicializa el túnel global para escuchar nuevos mensajes en *todas* las conversaciones.
     * Gestiona las notificaciones push nativas si el chat no está en foco.
     */
    const initGlobalMercure = async () => {
        if (globalEventSource.value) {
            globalEventSource.value.close();
            globalEventSource.value = null;
        }
        try {
            const authResponse = await apiClient.get('/message/mercure/auth', { _silentAuthCheck: true } as CustomAxiosRequestConfig);
            if (authResponse.headers['content-type']?.includes('text/html')) throw new Error('HTML response');

            const { hubUrl, token } = authResponse.data;
            const url = new URL(hubUrl);
            url.searchParams.append('topic', 'https://openperu.pe/host/conversations');
            if (token) url.searchParams.append('authorization', token);

            globalEventSource.value = new EventSource(url.toString(), { withCredentials: true });

            const notificationStore = useNotificationStore();

            globalEventSource.value.onmessage = (event) => {
                const data = JSON.parse(event.data);
                if (data.type === 'conversation_updated' || data.type === 'conversation_created') {
                    const convData = data.conversation;
                    // Búsqueda tolerante a IRIs o UUIDs
                    const existingConv = conversations.value.find(c => (c['@id'] || c.id) === (convData['@id'] || convData.id));

                    // Lógica para disparar Notificación Push
                    if (convData.unreadCount > (existingConv?.unreadCount || 0) && ((currentConversation.value as any)?.['@id'] !== convData['@id'] || !isChatVisible.value)) {
                        newNotification.value = { show: true, conversationId: convData.id, title: convData.guestName || 'Huésped' };
                        setTimeout(() => { newNotification.value = null; }, 5000);

                        const safeId = convData.id || convData['@id'].split('/').pop();
                        notificationStore.addNotification({
                            title: `Mensaje de ${convData.guestName || 'Huésped'}`,
                            body: 'Tienes un nuevo mensaje sin leer.',
                            type: 'info',
                            actionUrl: `/chat?id=${safeId}`
                        });
                    }

                    if (existingConv) Object.assign(existingConv, convData);
                    else conversations.value.unshift(convData);

                    // Reordenamos el inbox para subir los mensajes recientes
                    conversations.value.sort((a, b) => new Date(b.lastMessageAt || 0).getTime() - new Date(a.lastMessageAt || 0).getTime());
                }
            };

            globalEventSource.value.onerror = async () => {
                console.error('❌ Desconexión del túnel Global de Mercure...');
                globalEventSource.value?.close();
                const isAlive = await checkSession();
                if (!isAlive) isSessionExpired.value = true;
                else setTimeout(() => initGlobalMercure(), 5000); // Backoff retry
            };
        } catch (err) {}
    };

    /**
     * Inicializa el túnel dedicado a una conversación específica.
     * Escucha la confirmación de lectura, entrega y contenido en vivo del chat abierto.
     *
     * @param {string} conversationId UUID de la conversación.
     */
    const connectToMercure = async (conversationId: string) => {
        if (eventSource.value) {
            eventSource.value.close();
            eventSource.value = null;
        }

        try {
            const authResponse = await apiClient.get('/message/mercure/auth', { _silentAuthCheck: true } as CustomAxiosRequestConfig);
            if (authResponse.headers['content-type']?.includes('text/html')) throw new Error('HTML response');

            const { hubUrl, token } = authResponse.data;
            const url = new URL(hubUrl);
            url.searchParams.append('topic', `https://openperu.pe/conversations/${conversationId}`);
            if (token) url.searchParams.append('authorization', token);

            eventSource.value = new EventSource(url.toString(), { withCredentials: true });

            eventSource.value.onmessage = (event) => {
                const incomingData = JSON.parse(event.data);

                const index = messages.value.findIndex(m => (m['@id'] || m.id) === (incomingData['@id'] || incomingData.id));

                if (index !== -1) {
                    // Actualiza estado de un mensaje existente (ej. pasó de "sent" a "delivered")
                    messages.value.splice(index, 1, { ...messages.value[index], ...incomingData });
                } else {
                    // Es un mensaje nuevo entrante
                    messages.value.push(incomingData);

                    if (incomingData.direction === 'incoming') {
                        // Si el chat está abierto en pantalla, disparamos POST para marcar como leído en BD
                        if (isChatVisible.value) {
                            apiClient.post(`/platform/message/conversations/${conversationId}/read`).catch(() => {});
                            if (currentConversation.value) currentConversation.value.unreadCount = 0;
                        } else if (currentConversation.value) {
                            currentConversation.value.unreadCount = (currentConversation.value.unreadCount || 0) + 1;
                        }
                    }
                }
            };

            eventSource.value.onerror = async () => {
                eventSource.value?.close();
                const isAlive = await checkSession();
                if (!isAlive) isSessionExpired.value = true;
                else if (currentConversation.value?.id === conversationId) setTimeout(() => connectToMercure(conversationId), 5000);
            };
        } catch (err) {}
    };

    /**
     * Carga el historial de una conversación y la establece como la ventana principal.
     * Emite la llamada de lectura si el chat tenía notificaciones pendientes.
     *
     * @param {string} id UUID de la conversación.
     */
    const selectConversation = async (id: string) => {
        error.value = null;

        // 1. Buscamos en caché local (Inbox)
        let found = conversations.value.find(c => c.id === id);

        // 2. Si es un enlace directo o un chat muy antiguo, buscamos en BD
        if (!found) {
            loadingMessages.value = true;
            try {
                const response = await apiClient.get(`/platform/message/conversations/${id}`);
                found = response.data;
                if (found) conversations.value.unshift(found);
            } catch (err: any) {
                loadingMessages.value = false;
                error.value = 'Conversación no encontrada.';
                return;
            }
        }

        currentConversation.value = found || null;
        loadingMessages.value = true;
        messagesPage.value = 1;
        hasMoreMessages.value = true;

        try {
            if (found && (found.unreadCount ?? 0) > 0) {
                apiClient.post(`/platform/message/conversations/${id}/read`).then(() => { if (found) found.unreadCount = 0; });
                found.unreadCount = 0;
            }

            const response = await apiClient.get(`/platform/message/conversations/${id}/messages?order[createdAt]=desc&page=1`);
            messages.value = extractData(response).reverse();
            hasMoreMessages.value = hasNextPage(response);

            connectToMercure(id);
        } catch (err) {
            error.value = 'Error al cargar historial del chat.';
        } finally {
            loadingMessages.value = false;
        }
    };

    const loadMoreMessages = async () => {
        if (!currentConversation.value || !hasMoreMessages.value || loadingMoreMessages.value) return;

        loadingMoreMessages.value = true;
        const nextPage = messagesPage.value + 1;

        try {
            const response = await apiClient.get(`/platform/message/conversations/${currentConversation.value.id}/messages?order[createdAt]=desc&page=${nextPage}`);
            const olderMessages = extractData(response).reverse();

            messages.value = [...olderMessages, ...messages.value];
            hasMoreMessages.value = hasNextPage(response);
            messagesPage.value = nextPage;
        } catch (err) {
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

            // Flexibilidad IRI vs UUID
            const convId = (currentConversation.value as any)['@id'] || `/platform/message/conversations/${currentConversation.value.id}`;

            form.append('conversation', convId);
            form.append('direction', 'outgoing');
            form.append('senderType', 'host');
            form.append('status', 'pending'); // Backend asume el control del pipeline

            channels.forEach(channel => form.append('transientChannels[]', channel));

            if (templateIri) form.append('template', templateIri);
            else form.append('contentLocal', text.trim());

            if (attachmentStore.file) form.append('file', attachmentStore.file);

            await apiClient.post('/platform/message/messages', form, { headers: { 'Content-Type': 'multipart/form-data' } });
            attachmentStore.clear(); // Limpia RAM del navegador
        } catch (err) {
            error.value = 'Fallo al enviar el mensaje. Intente de nuevo.';
        } finally {
            sendingMessage.value = false;
        }
    };

    /**
     * MODO STALKER:
     * Trae los últimos 5 mensajes limpios de una conversación SIN disparar el webhook
     * de lectura (No notifica al huésped). Ideal para previsualizaciones.
     * Filtra rigurosamente los mensajes programados o pendientes, y
     * ordena de más antiguo a más nuevo para una visualización top-down correcta.
     *
     * @param {string} conversationId ID de la conversación
     * @returns {Promise<ApiMessage[]>} Lista recortada de mensajes
     */
    const fetchLatestMessagesForStalk = async (conversationId: string): Promise<ApiMessage[]> => {
        try {
            const response = await apiClient.get(`/platform/message/conversations/${conversationId}/messages?order[createdAt]=desc&page=1`);
            const data = extractData(response) as ApiMessage[];

            const realHistoryMessages = data.filter(m => m.scheduledForFuture !== true && m.status !== 'pending' && m.status !== 'queued' && m.status !== 'cancelled');
            const latest5 = realHistoryMessages.slice(0, 5);

            return latest5.sort((a, b) => new Date(a.effectiveDateTime || a.createdAt as string).getTime() - new Date(b.effectiveDateTime || b.createdAt as string).getTime());
        } catch (err) { return []; }
    };

    // ============================================================================
    // MANEJO DE BADGE NATIVO DEL NAVEGADOR
    // ============================================================================
    watch(() => conversations.value.filter(c => (c.unreadCount ?? 0) > 0).length, (unreadCount) => {
        if ('setAppBadge' in navigator && 'clearAppBadge' in navigator) {
            if (unreadCount > 0) (navigator as any).setAppBadge(unreadCount).catch(() => {});
            else (navigator as any).clearAppBadge().catch(() => {});
        }
        // Limpiamos las notificaciones persistentes del OS si ya se leyeron todos los chats
        if (unreadCount === 0 && 'serviceWorker' in navigator && navigator.serviceWorker.controller) {
            navigator.serviceWorker.controller.postMessage({ type: 'CLEAR_NOTIFICATIONS' });
        }
    });

    return {
        conversations, filteredConversations, currentConversation, messages, activeChatMessages, scheduledMessages, cancelledMessages, templates, validTemplates, filterStatus, loadingConversations, loadingMessages, sendingMessage, error, loadingMoreConversations, loadingMoreMessages, hasMoreMessages, hasMoreConversations, isSessionExpired, checkSession, renewSession, cancelRenewal, getExternalContextUrl, fetchConversations, fetchTemplates, selectConversation, loadMoreMessages, sendMessage, initGlobalMercure, connectToMercure, newNotification, isChatVisible, getMessageDisplayStatus, fetchLatestMessagesForStalk
    };
});