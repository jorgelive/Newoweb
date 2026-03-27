// Separado para la declaración de Pinia store
import { defineStore } from 'pinia';
import { ref, computed, shallowRef } from 'vue';
import axios, { InternalAxiosRequestConfig } from 'axios';
import { useAttachmentStore } from './attachmentStore';

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
        beds24?: {
            sent_at?: string;
            delivered_at?: string;
            read_at?: string;
            error?: string;
            [key: string]: any;
        };
        whatsappMeta?: {
            sent_at?: string;
            delivered_at?: string;
            read_at?: string;
            error_code?: string;
            error_reason?: string;
            [key: string]: any;
        };
        dispatch_errors?: string[];
        dispatch_warnings?: string[];
        // (Opcional) Si en el futuro quieres permitir cualquier otra llave dinámica en la raíz de metadata:
        [key: string]: any;
    };
    channel?: { id: string; name: string } | string;
    whatsappMetaSendQueues?: ApiMessageQueue[] | string[];
    beds24SendQueues?: ApiMessageQueue[] | string[];
    template?: any;
    attachments?: ApiAttachment[] | string[];
}

export interface ApiTemplate {
    '@id': string;
    id: string;
    code: string;
    name: string;
    contextType: string | null;
    allowedSources: string[];
    allowedAgencies: string[];
    channels: string[];
    whatsappMetaOfficial: boolean;
    beds24Active: boolean;
    whatsappMetaActive: boolean;
    emailActive: boolean;
}

export interface ApiConversation {
    '@id': string;
    id: string;
    status: string;
    guestName: string | null;
    guestPhone: string | null;
    contextType: string;
    contextId: string;
    createdAt: string;
    lastMessageAt: string | null;
    unreadCount: number;
    contextOrigin: string | null;
    contextStatusTag: string | null;
    contextMilestones: { start?: string; end?: string; booked_at?: string; eta?: string; };
    contextItems: string[];
    whatsappSessionActive?: boolean;
}

export const useChatStore = defineStore('chatStore', () => {

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
    let failedQueue: { resolve: Function, reject: Function, config: InternalAxiosRequestConfig }[] = [];

    /**
     * Procesa la cola de peticiones pausadas tras un intento de login.
     * Si hay error, rechaza las promesas. Si fue exitoso, reintenta las peticiones originales.
     */
    const processQueue = (error: any = null) => {
        failedQueue.forEach(prom => {
            if (error) {
                prom.reject(error);
            } else {
                prom.resolve(apiClient(prom.config));
            }
        });
        failedQueue = [];
    };

    apiClient.interceptors.request.use((config) => {
        config.baseURL = getUrls().api;
        return config;
    });

    /**
     * Interceptor global para capturar errores 401 (No autorizado).
     * Pausa la ejecución, levanta la bandera de sesión expirada y encola la petición.
     */
    apiClient.interceptors.response.use(
        response => response,
        async (error) => {
            const originalRequest = error.config;

            if (error.response?.status === 401 && !originalRequest._retry) {
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

    // NUEVO: Estado para la alerta de mensaje en otro chat
    const newNotification = ref<{ show: boolean, title: string, conversationId: string } | null>(null);

    // Usamos shallowRef para que Pinia no intente hacer reactiva la conexión nativa
    const eventSource = shallowRef<EventSource | null>(null);
    const globalEventSource = shallowRef<EventSource | null>(null);

    const filteredConversations = computed(() => {
        return conversations.value.filter(c => c.status && c.status.toLowerCase() === filterStatus.value.toLowerCase());
    });

    const activeChatMessages = computed(() => {
        return messages.value.filter(m => !m.scheduledForFuture);
    });

    const scheduledMessages = computed(() => {
        return messages.value
            .filter(m => m.scheduledForFuture)
            .sort((a, b) => {
                const dateA = new Date(a.effectiveDateTime || a.createdAt).getTime();
                const dateB = new Date(b.effectiveDateTime || b.createdAt).getTime();
                return dateA - dateB;
            });
    });

    const validTemplates = computed(() => {
        if (!currentConversation.value) return [];
        const chat = currentConversation.value;
        const origin = chat.contextOrigin || 'manual';
        return templates.value.filter(t => {
            if (t.contextType && t.contextType !== chat.contextType) return false;
            if (t.allowedSources?.length && !t.allowedSources.includes(origin)) return false;
            return true;
        });
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
     * Intenta renovar la sesión atacando el endpoint JSON del backend de Symfony.
     * Si es exitoso, libera la cola de mensajes y reinicia los túneles Mercure.
     */
    const renewSession = async (credentials: { _username: string, _password: string }) => {
        try {
            await apiClient.post('/ajax_login', credentials);

            isSessionExpired.value = false;
            error.value = null;

            // 1. Liberamos cualquier petición pendiente (ej. el envío de un mensaje)
            processQueue(null);

            // 2. Renovar túneles Mercure (vital para obtener el nuevo JWT de Mercure)
            await initGlobalMercure();
            if (currentConversation.value) {
                await connectToMercure(currentConversation.value.id);
            }

            return true;
        } catch (err: any) {
            error.value = err.response?.data?.message || 'Error de autenticación. Verifica tus credenciales.';
            processQueue(err);
            return false;
        }
    };

    /**
     * Cancela explícitamente el proceso de renovación de sesión.
     */
    const cancelRenewal = () => {
        isSessionExpired.value = false;
        processQueue(new Error('Renovación de sesión cancelada por el usuario.'));
    };

    // ============================================================================
    // ACCIONES ORIGINALES
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

        error.value = null;
        try {
            const response = await apiClient.get(`/platform/user/util/msg/conversations?order[lastMessageAt]=desc&page=${pageToFetch}`);
            const data = extractData(response);

            if (loadMore) {
                conversations.value.push(...data);
            } else {
                conversations.value = data;
            }

            hasMoreConversations.value = hasNextPage(response);
            conversationsPage.value = pageToFetch;
        } catch (err: any) {
            // Solo mostramos error general si no es un 401 (que ya maneja el modal)
            if (err.response?.status !== 401) {
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
            const authResponse = await apiClient.get('/message/mercure/auth');
            const { hubUrl, token } = authResponse.data;

            const topic = 'https://openperu.pe/host/conversations';
            const url = new URL(hubUrl);
            url.searchParams.append('topic', topic);

            if (token) url.searchParams.append('authorization', token);

            globalEventSource.value = new EventSource(url.toString(), { withCredentials: true });

            globalEventSource.value.onmessage = (event) => {
                const data = JSON.parse(event.data);

                if (data.type === 'conversation_updated' || data.type === 'conversation_created') {
                    const convData = data.conversation;

                    // Lógica para detectar si hay un mensaje nuevo y disparar la alerta
                    const existingConv = conversations.value.find(c => c['@id'] === convData['@id']);
                    const isNewUnread = convData.unreadCount > (existingConv?.unreadCount || 0);

                    if (isNewUnread && (currentConversation.value?.['@id'] !== convData['@id'] || !isChatVisible.value)) {
                        newNotification.value = {
                            show: true,
                            conversationId: convData.id,
                            title: convData.guestName || 'Huésped',
                        };
                        setTimeout(() => { newNotification.value = null; }, 5000);
                    }

                    if (existingConv) {
                        Object.assign(existingConv, convData);
                    } else {
                        conversations.value.unshift(convData);
                    }

                    conversations.value.sort((a, b) => {
                        const dateA = new Date(a.lastMessageAt || 0).getTime();
                        const dateB = new Date(b.lastMessageAt || 0).getTime();
                        return dateB - dateA;
                    });
                }
            };

            globalEventSource.value.onerror = (err) => {
                console.error('❌ Error en el túnel Global de Mercure:', err);
            };

        } catch (err) {
            console.error('❌ Fallo al inicializar Global Mercure (posible sesión expirada)');
        }
    };

    const connectToMercure = async (conversationId: string) => {
        if (eventSource.value) {
            eventSource.value.close();
            eventSource.value = null;
        }

        try {
            const authResponse = await apiClient.get('/message/mercure/auth');
            const { hubUrl, token } = authResponse.data;

            const topic = `https://openperu.pe/conversations/${conversationId}`;
            const url = new URL(hubUrl);
            url.searchParams.append('topic', topic);

            if (token) {
                url.searchParams.append('authorization', token);
            }

            eventSource.value = new EventSource(url.toString(), { withCredentials: true });

            eventSource.value.onmessage = (event) => {
                const incomingData = JSON.parse(event.data);
                const index = messages.value.findIndex(m => m['@id'] === incomingData['@id']);

                if (index !== -1) {
                    messages.value.splice(index, 1, { ...messages.value[index], ...incomingData });
                } else {
                    messages.value.push(incomingData);

                    // Auto-Read Inmediato SOLO si el chat es visible
                    if (incomingData.direction === 'incoming') {
                        if (isChatVisible.value) {
                            apiClient.post(`/platform/user/util/msg/conversations/${conversationId}/read`)
                                .catch(e => console.error("Error auto-reading", e));

                            if (currentConversation.value) currentConversation.value.unreadCount = 0;
                        } else {
                            // Si el chat está colapsado, sumamos el contador visualmente
                            if (currentConversation.value) currentConversation.value.unreadCount++;
                        }
                    }
                }
            };

            eventSource.value.onerror = (err) => {
                console.error('❌ Error en el túnel de Mercure:', err);
            };

        } catch (err) {
            console.error('❌ Fallo al inicializar Mercure (posible sesión expirada)');
        }
    };

    const selectConversation = async (id: string) => {
        const found = conversations.value.find(c => c.id === id);
        if (!found) return;

        currentConversation.value = found;
        loadingMessages.value = true;
        messagesPage.value = 1;
        hasMoreMessages.value = true;
        newNotification.value = null;

        try {
            if (found.unreadCount > 0) {
                apiClient.post(`/platform/user/util/msg/conversations/${id}/read`)
                    .then(() => found.unreadCount = 0)
                    .catch(e => console.error("Error al marcar leídos", e));
                found.unreadCount = 0;
            }

            const response = await apiClient.get(`/platform/user/util/msg/conversations/${id}/messages?order[createdAt]=desc&page=1`);
            messages.value = extractData(response).reverse();
            hasMoreMessages.value = hasNextPage(response);

            connectToMercure(id);

        } catch (err) {
            error.value = 'Error al cargar mensajes';
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
            error.value = 'Error al cargar historial antiguo';
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

            channels.forEach(channel => {
                form.append('transientChannels[]', channel);
            });

            if (templateIri) {
                form.append('template', templateIri);
            } else {
                form.append('contentLocal', text.trim());
            }

            if (attachmentStore.file) {
                form.append('file', attachmentStore.file);
            }

            await apiClient.post('/platform/user/util/msg/messages', form, {
                headers: {
                    'Content-Type': 'multipart/form-data'
                }
            });

            attachmentStore.clear();

        } catch (err) {
            error.value = 'Fallo al enviar el mensaje. Verifica el tamaño del archivo o tu conexión.';
            console.error('Error enviando mensaje Multipart:', err);
        } finally {
            sendingMessage.value = false;
        }
    };

    return {
        conversations, filteredConversations, currentConversation, messages, activeChatMessages, scheduledMessages,
        templates, validTemplates, filterStatus, loadingConversations, loadingMessages, sendingMessage, error,
        loadingMoreConversations, loadingMoreMessages, hasMoreMessages, hasMoreConversations,
        isSessionExpired, renewSession, cancelRenewal,
        getExternalContextUrl, fetchConversations, fetchTemplates, selectConversation, loadMoreMessages, sendMessage,
        initGlobalMercure, connectToMercure, newNotification, isChatVisible, getMessageDisplayStatus
    };
});