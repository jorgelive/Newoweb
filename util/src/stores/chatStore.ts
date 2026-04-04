//src/stores/chatStore.ts
import { defineStore } from 'pinia';
import { ref, computed, shallowRef, watch } from 'vue';
import axios, { InternalAxiosRequestConfig } from 'axios';
import { useAttachmentStore } from './attachmentStore';
import { useNotificationStore } from './notificationStore';

// Interfaz extendida para manejar estados personalizados en las peticiones Axios
export interface CustomAxiosRequestConfig extends InternalAxiosRequestConfig {
    _retry?: boolean;
    _silentAuthCheck?: boolean;
}

export interface ApiMessageQueue {
    status: string;
    deliveryStatus?: string;
}

export interface ApiAttachment {
    '@id': string;
    id: string;
    originalName: string;
    mimeType: string;
    fileUrl?: string;
}

export interface ApiMessage {
    '@id': string;
    id: string;
    direction: string;
    status: string;
    senderType: string;
    contentLocal: string | null;
    contentExternal: string | null;
    createdAt: string;
    scheduledAt?: string | null;
    effectiveDateTime?: string | null;
    scheduledForFuture?: boolean;
    metadata?: {
        beds24?: { sent_at?: string; delivered_at?: string; read_at?: string; error?: string; [key: string]: any; };
        whatsappMeta?: { sent_at?: string; delivered_at?: string; read_at?: string; error_code?: string; error_reason?: string; [key: string]: any; };
        dispatch_errors?: string[];
        dispatch_warnings?: string[];
        [key: string]: any;
    };
    channel?: { id: string; name: string } | string;
    whatsappMetaSendQueues?: ApiMessageQueue[] | string[];
    beds24SendQueues?: ApiMessageQueue[] | string[];
    template?: any;
    attachments?: ApiAttachment[] | string[];
}

export interface ApiTemplate {
    '@id': string; id: string; code: string; name: string; contextType: string | null; allowedSources: string[]; allowedAgencies: string[]; channels: string[]; whatsappMetaOfficial: boolean; beds24Active: boolean; whatsappMetaActive: boolean; emailActive: boolean;
}

export interface ApiConversation {
    '@id': string; id: string; status: string; guestName: string | null; guestPhone: string | null; contextType: string; contextId: string; createdAt: string; lastMessageAt: string | null; unreadCount: number; contextOrigin: string | null; contextStatusTag: string | null; contextMilestones: { start?: string; end?: string; booked_at?: string; eta?: string; }; contextItems: string[]; whatsappSessionActive?: boolean; whatsappDisabled?: boolean; whatsappDisabledReason?: string | null;
}

export const useChatStore = defineStore('chatStore', () => {

    /**
     * Determina el estado visual de un mensaje basado en sus colas de envío.
     * @param {ApiMessage} msg El mensaje a evaluar.
     * @returns {string} El estado final calculado (ej: 'cancelled', 'sent', 'delivered').
     */
    const getMessageDisplayStatus = (msg: ApiMessage): string => {
        if (msg.status === 'cancelled') return 'cancelled';

        // Si todas las queues están canceladas, lo tratamos como cancelado visualmente
        const allQueues = [
            ...(msg.whatsappMetaSendQueues || []),
            ...(msg.beds24SendQueues || [])
        ].filter(q => typeof q === 'object') as ApiMessageQueue[];

        if (allQueues.length > 0 && allQueues.every(q => q.status === 'cancelled')) {
            return 'cancelled';
        }

        return msg.status;
    };

    /**
     * Obtiene las URLs base de la API y el Panel desde la configuración global o variables de entorno.
     * @returns {{api: string, panel: string}} Objeto con las URLs.
     */
    const getUrls = () => {
        // @ts-ignore
        const config = window.OPENPERU_CONFIG || {};
        return {
            api: config.apiUrl || import.meta.env.VITE_API_URL || 'https://api.openperu.pe',
            panel: config.panelUrl || import.meta.env.VITE_PANEL_URL || 'https://panel.openperu.pe'
        };
    };

    const apiClient = axios.create({
        withCredentials: true,
        headers: { 'Accept': 'application/ld+json' }
    });

    // ============================================================================
    // ESTADOS Y LÓGICA DE SESIÓN
    // ============================================================================
    const isSessionExpired = ref(false);
    let failedQueue: { resolve: Function, reject: Function, config: CustomAxiosRequestConfig }[] = [];

    /**
     * Procesa la cola de peticiones pausadas tras un intento de login o cancelación.
     * Si hay error, rechaza las promesas. Si fue exitoso, reintenta las peticiones originales.
     * @param {any} error El error a inyectar en las peticiones si el login falló.
     */
    const processQueue = (error: any = null) => {
        failedQueue.forEach(prom => {
            if (error) prom.reject(error);
            else prom.resolve(apiClient(prom.config));
        });
        failedQueue = [];
    };

    apiClient.interceptors.request.use((config) => {
        config.baseURL = getUrls().api;
        return config;
    });

    // ============================================================================
    // INTERCEPTOR MEJORADO (DETECTA SYMFONY REDIRECT 302 -> HTML)
    // ============================================================================
    apiClient.interceptors.response.use(
        (response) => {
            // Si esperamos JSON pero Symfony nos devuelve el HTML del login (Status 200 OK)
            const contentType = response.headers['content-type'];
            if (contentType && contentType.includes('text/html')) {
                const originalRequest = response.config as CustomAxiosRequestConfig;

                if (!originalRequest._retry && !originalRequest._silentAuthCheck) {
                    isSessionExpired.value = true;
                    originalRequest._retry = true;

                    return new Promise((resolve, reject) => {
                        failedQueue.push({ resolve, reject, config: originalRequest });
                    });
                }
                return Promise.reject(new Error('Sesión expirada (Redirección HTML detectada)'));
            }
            return response;
        },
        async (error) => {
            const originalRequest = error.config as CustomAxiosRequestConfig;

            // Si devuelve 401 explícito (en caso de que lo configuremos en el futuro)
            if (error.response?.status === 401 && !originalRequest._retry && !originalRequest._silentAuthCheck) {
                isSessionExpired.value = true;
                originalRequest._retry = true;

                return new Promise((resolve, reject) => {
                    failedQueue.push({ resolve, reject, config: originalRequest });
                });
            }
            return Promise.reject(error);
        }
    );

    // ============================================================================
    // ESTADOS DEL CHAT
    // ============================================================================
    const conversations = ref<ApiConversation[]>([]);
    const currentConversation = ref<ApiConversation | null>(null);
    const messages = ref<ApiMessage[]>([]);
    const templates = ref<ApiTemplate[]>([]);
    const filterStatus = ref<string>('open');

    const loadingConversations = ref(false);
    const loadingMessages = ref(false);
    const sendingMessage = ref(false);
    const error = ref<string | null>(null);

    const conversationsPage = ref(1);
    const hasMoreConversations = ref(true);
    const loadingMoreConversations = ref(false);

    const messagesPage = ref(1);
    const hasMoreMessages = ref(true);
    const loadingMoreMessages = ref(false);

    const isChatVisible = ref(true);

    const newNotification = ref<{ show: boolean, title: string, conversationId: string } | null>(null);

    const eventSource = shallowRef<EventSource | null>(null);
    const globalEventSource = shallowRef<EventSource | null>(null);

    const filteredConversations = computed(() => conversations.value.filter(c => c.status && c.status.toLowerCase() === filterStatus.value.toLowerCase()));
    const activeChatMessages = computed(() => messages.value.filter(m => !m.scheduledForFuture));

    const scheduledMessages = computed(() => {
        return messages.value
            .filter(m => m.scheduledForFuture)
            .sort((a, b) => new Date(a.effectiveDateTime || a.createdAt).getTime() - new Date(b.effectiveDateTime || b.createdAt).getTime());
    });

    const validTemplates = computed(() => {
        if (!currentConversation.value) return [];
        const chat = currentConversation.value;
        const origin = chat.contextOrigin || 'manual';
        return templates.value.filter(t => (!t.contextType || t.contextType === chat.contextType) && (!t.allowedSources?.length || t.allowedSources.includes(origin)));
    });

    const getExternalContextUrl = computed(() => {
        if (!currentConversation.value) return null;
        const chat = currentConversation.value;
        const routes: Record<string, string> = { 'pms_reserva': `/pms-reserva/${chat.contextId}` };
        return routes[chat.contextType] ? `${getUrls().panel}${routes[chat.contextType]}` : null;
    });

    const extractData = (response: any) => {
        const data = response.data;
        return data['hydra:member'] || data['member'] || (Array.isArray(data) ? data : []);
    };

    const hasNextPage = (response: any) => {
        const view = response.data['hydra:view'] || response.data['view'];
        return !!(view && (view['hydra:next'] || view['next']));
    };

    // ============================================================================
    // ACCIONES DE AUTENTICACIÓN
    // ============================================================================

    /**
     * Comprobación silenciosa de sesión, protegiendo contra respuestas HTML del firewall
     */
    const checkSession = async (): Promise<boolean> => {
        try {
            const res = await apiClient.get('/message/mercure/auth', { _silentAuthCheck: true } as CustomAxiosRequestConfig);
            // Si nos devolvió HTML (login form) en vez de JSON, la sesión murió
            const contentType = res.headers['content-type'];
            if (contentType && contentType.includes('text/html')) {
                return false;
            }
            return true;
        } catch (e) {
            return false;
        }
    };

    /**
     * Intenta renovar la sesión atacando el endpoint JSON del backend de Symfony.
     * Si es exitoso, libera la cola de mensajes y reinicia los túneles Mercure.
     * @param {Object} credentials Credenciales de acceso.
     * @returns {Promise<boolean>} True si la autenticación fue exitosa.
     */
    const renewSession = async (credentials: { _username: string, _password: string }): Promise<boolean> => {
        try {
            await apiClient.post('/ajax_login', credentials);

            isSessionExpired.value = false;
            error.value = null;

            // 1. Liberamos cualquier petición pendiente
            processQueue(null);

            // 2. Renovar túneles Mercure
            await initGlobalMercure();
            if (currentConversation.value) await connectToMercure(currentConversation.value.id);
            return true;
        } catch (err: any) {
            error.value = err.response?.data?.message || 'Error de autenticación. Verifica tus credenciales.';
            processQueue(err);
            return false;
        }
    };

    /**
     * Cancela explícitamente el proceso de renovación de sesión, limpiando la cola.
     */
    const cancelRenewal = () => {
        isSessionExpired.value = false;
        processQueue(new Error('Renovación cancelada.'));
    };

    // ============================================================================
    // ACCIONES DE DATOS
    // ============================================================================
    const fetchTemplates = async () => {
        try {
            const response = await apiClient.get('/platform/user/util/msg/templates');
            templates.value = extractData(response);
        } catch (err) {}
    };

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
            const response = await apiClient.get(`/platform/user/util/msg/conversations?order[lastMessageAt]=desc&page=${pageToFetch}`);
            const data = extractData(response);
            if (loadMore) conversations.value.push(...data);
            else conversations.value = data;

            hasMoreConversations.value = hasNextPage(response);
            conversationsPage.value = pageToFetch;
        } catch (err: any) {
            if (err.response?.status !== 401 && err.message !== 'Sesión expirada (Redirección HTML detectada)') {
                error.value = 'Error al sincronizar chats';
            }
        } finally {
            loadingConversations.value = false;
            loadingMoreConversations.value = false;
        }
    };

    const initGlobalMercure = async () => {
        if (globalEventSource.value) {
            globalEventSource.value.close();
            globalEventSource.value = null;
        }
        try {
            const authResponse = await apiClient.get('/message/mercure/auth', { _silentAuthCheck: true } as CustomAxiosRequestConfig);

            // Si nos topamos con HTML aquí, abortamos.
            if (authResponse.headers['content-type']?.includes('text/html')) {
                throw new Error('HTML response');
            }

            const { hubUrl, token } = authResponse.data;
            const url = new URL(hubUrl);
            url.searchParams.append('topic', 'https://openperu.pe/host/conversations');
            if (token) url.searchParams.append('authorization', token);

            globalEventSource.value = new EventSource(url.toString(), { withCredentials: true });

            // ✅ Instanciamos el store global
            const notificationStore = useNotificationStore();

            globalEventSource.value.onmessage = (event) => {
                const data = JSON.parse(event.data);

                if (data.type === 'conversation_updated' || data.type === 'conversation_created') {
                    const convData = data.conversation;
                    const existingConv = conversations.value.find(c => c['@id'] === convData['@id']);

                    if (convData.unreadCount > (existingConv?.unreadCount || 0) && (currentConversation.value?.['@id'] !== convData['@id'] || !isChatVisible.value)) {
                        newNotification.value = { show: true, conversationId: convData.id, title: convData.guestName || 'Huésped' };
                        setTimeout(() => { newNotification.value = null; }, 5000);

                        // 1. Extraemos el ID seguro por si no viene la propiedad "id" limpia
                        const safeId = convData.id || convData['@id'].split('/').pop();
                        notificationStore.addNotification({ title: `Mensaje de ${convData.guestName || 'Huésped'}`, body: 'Tienes un nuevo mensaje sin leer.', type: 'info', actionUrl: `/chat?id=${safeId}` });
                    }

                    if (existingConv) Object.assign(existingConv, convData);
                    else conversations.value.unshift(convData);
                    conversations.value.sort((a, b) => new Date(b.lastMessageAt || 0).getTime() - new Date(a.lastMessageAt || 0).getTime());
                }
            };

            globalEventSource.value.onerror = async () => {
                console.error('❌ Desconexión del túnel Global de Mercure...');
                globalEventSource.value?.close();

                // Verificamos silenciosamente si la cookie de sesión principal sigue viva
                const isAlive = await checkSession();
                if (!isAlive) isSessionExpired.value = true;
                else setTimeout(() => initGlobalMercure(), 5000);
            };
        } catch (err) {}
    };

    const connectToMercure = async (conversationId: string) => {
        if (eventSource.value) {
            eventSource.value.close();
            eventSource.value = null;
        }

        try {
            const authResponse = await apiClient.get('/message/mercure/auth', { _silentAuthCheck: true } as CustomAxiosRequestConfig);
            if (authResponse.headers['content-type']?.includes('text/html')) {
                throw new Error('HTML response');
            }

            const { hubUrl, token } = authResponse.data;
            const url = new URL(hubUrl);
            url.searchParams.append('topic', `https://openperu.pe/conversations/${conversationId}`);
            if (token) url.searchParams.append('authorization', token);

            eventSource.value = new EventSource(url.toString(), { withCredentials: true });

            eventSource.value.onmessage = (event) => {
                const incomingData = JSON.parse(event.data);
                const index = messages.value.findIndex(m => m['@id'] === incomingData['@id']);

                if (index !== -1) {
                    messages.value.splice(index, 1, { ...messages.value[index], ...incomingData });
                } else {
                    messages.value.push(incomingData);

                    if (incomingData.direction === 'incoming') {
                        if (isChatVisible.value) {
                            apiClient.post(`/platform/user/util/msg/conversations/${conversationId}/read`).catch(() => {});
                            if (currentConversation.value) currentConversation.value.unreadCount = 0;
                        } else if (currentConversation.value) {
                            currentConversation.value.unreadCount++;
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

    const selectConversation = async (id: string) => {
        error.value = null;

        // 1. Buscamos en memoria
        let found = conversations.value.find(c => c.id === id);

        // 2. Si no está en memoria (chat antiguo no cargado aún), lo buscamos directo en la API
        if (!found) {
            loadingMessages.value = true;
            try {
                // Hacemos un GET directo al ID de la conversación
                const response = await apiClient.get(`/platform/user/util/msg/conversations/${id}`);
                found = response.data;
                if (found) conversations.value.unshift(found);
            } catch (err: any) {
                // Si la API devuelve 404, la conversación no existe o no tiene permisos
                loadingMessages.value = false;
                error.value = 'Conversación no encontrada.';
                return;
            }
        }

        // 3. Procedemos con la carga normal (ahora que sabemos que "found" existe)
        currentConversation.value = found || null;
        loadingMessages.value = true;
        messagesPage.value = 1;
        hasMoreMessages.value = true;

        try {
            if (found && found.unreadCount > 0) {
                apiClient.post(`/platform/user/util/msg/conversations/${id}/read`).then(() => { if (found) found.unreadCount = 0; });
                found.unreadCount = 0;
            }

            const response = await apiClient.get(`/platform/user/util/msg/conversations/${id}/messages?order[createdAt]=desc&page=1`);
            messages.value = extractData(response).reverse();
            hasMoreMessages.value = hasNextPage(response);

            // Re-abrimos el túnel para escuchar mensajes en vivo de esta conversación
            connectToMercure(id);

        } catch (err) {
            error.value = 'Error al cargar mensajes.';
        } finally {
            loadingMessages.value = false;
        }
    };

    const loadMoreMessages = async () => {
        if (!currentConversation.value || !hasMoreMessages.value || loadingMoreMessages.value) return;

        loadingMoreMessages.value = true;
        const nextPage = messagesPage.value + 1;

        try {
            const response = await apiClient.get(`/platform/user/util/msg/conversations/${currentConversation.value.id}/messages?order[createdAt]=desc&page=${nextPage}`);
            const olderMessages = extractData(response).reverse();

            messages.value = [...olderMessages, ...messages.value];

            hasMoreMessages.value = hasNextPage(response);
            messagesPage.value = nextPage;
        } catch (err) {
            error.value = 'Error al cargar historial.';
        } finally {
            loadingMoreMessages.value = false;
        }
    };

    const sendMessage = async (text: string, templateIri: string | null = null, channels: string[] = []) => {
        if (!currentConversation.value) return;

        sendingMessage.value = true;
        const attachmentStore = useAttachmentStore();

        try {
            const form = new FormData();

            form.append('conversation', currentConversation.value['@id']);
            form.append('direction', 'outgoing');
            form.append('senderType', 'host');
            form.append('status', 'pending');
            channels.forEach(channel => form.append('transientChannels[]', channel));
            if (templateIri) form.append('template', templateIri);
            else form.append('contentLocal', text.trim());
            if (attachmentStore.file) form.append('file', attachmentStore.file);
            await apiClient.post('/platform/user/util/msg/messages', form, { headers: { 'Content-Type': 'multipart/form-data' } });
            attachmentStore.clear();
        } catch (err) {
            error.value = 'Fallo al enviar mensaje.';
        } finally {
            sendingMessage.value = false;
        }
    };

    /**
     * MODO STALKER: Trae los últimos mensajes de una conversación sin disparar el webhook de lectura.
     * Filtra rigurosamente los mensajes programados o pendientes, toma los 5 últimos y los
     * ordena de más antiguo a más nuevo para una visualización top-down correcta.
     * @param {string} conversationId ID de la conversación
     * @returns {Promise<ApiMessage[]>} Lista de los últimos mensajes enviados/recibidos
     */
    const fetchLatestMessagesForStalk = async (conversationId: string): Promise<ApiMessage[]> => {
        try {
            const response = await apiClient.get(`/platform/user/util/msg/conversations/${conversationId}/messages?order[createdAt]=desc&page=1`);
            const data = extractData(response) as ApiMessage[];

            // 1. Filtro estricto (rechaza colas, cancelados y programados a futuro)
            const realHistoryMessages = data.filter(m =>
                m.scheduledForFuture !== true &&
                (m as any).isScheduledForFuture !== true &&
                m.status !== 'pending' &&
                m.status !== 'queued' &&
                m.status !== 'cancelled'
            );

            // 2. Tomar los primeros 5 limpios (la API los devolvió del más reciente al más viejo)
            const latest5 = realHistoryMessages.slice(0, 5);
            return latest5.sort((a, b) => new Date(a.effectiveDateTime || a.createdAt).getTime() - new Date(b.effectiveDateTime || b.createdAt).getTime());
        } catch (err) { return []; }
    };

    watch(() => conversations.value.filter(c => c.unreadCount > 0).length, (unreadCount) => {
        if ('setAppBadge' in navigator && 'clearAppBadge' in navigator) {
            if (unreadCount > 0) navigator.setAppBadge(unreadCount).catch(() => {});
            else navigator.clearAppBadge().catch(() => {});
        }
        if (unreadCount === 0 && 'serviceWorker' in navigator && navigator.serviceWorker.controller) {
            navigator.serviceWorker.controller.postMessage({ type: 'CLEAR_NOTIFICATIONS' });
        }
    });


    return {
        conversations, filteredConversations, currentConversation, messages, activeChatMessages, scheduledMessages, templates, validTemplates, filterStatus, loadingConversations, loadingMessages, sendingMessage, error, loadingMoreConversations, loadingMoreMessages, hasMoreMessages, hasMoreConversations, isSessionExpired, checkSession, renewSession, cancelRenewal, getExternalContextUrl, fetchConversations, fetchTemplates, selectConversation, loadMoreMessages, sendMessage, initGlobalMercure, connectToMercure, newNotification, isChatVisible, getMessageDisplayStatus, fetchLatestMessagesForStalk
    };
});