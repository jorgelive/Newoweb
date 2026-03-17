import { defineStore } from 'pinia';
import { ref, computed } from 'vue';
import axios from 'axios';
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
    isScheduledForFuture?: boolean;
    metadata?: { beds24?: any; whatsappGupshup?: any; gupshup?: any };
    channel?: { id: string; name: string } | string;
    whatsappGupshupSendQueues?: ApiMessageQueue[] | string[];
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
}

export const useChatStore = defineStore('chatStore', () => {

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

    apiClient.interceptors.request.use((config) => {
        config.baseURL = getUrls().api;
        return config;
    });

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

    const eventSource = ref<EventSource | null>(null);
    const globalEventSource = ref<EventSource | null>(null);

    const filteredConversations = computed(() => {
        return conversations.value.filter(c => c.status && c.status.toLowerCase() === filterStatus.value.toLowerCase());
    });

    // ============================================================================
    // 🔥 FILTROS COMPUTADOS PARA LA INTERFAZ DE CHAT Y PROGRAMADOS
    // ============================================================================

    const activeChatMessages = computed(() => {
        return messages.value.filter(m => !m.isScheduledForFuture);
    });

    const scheduledMessages = computed(() => {
        return messages.value
            .filter(m => m.isScheduledForFuture)
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
            error.value = 'Error al sincronizar chats';
        } finally {
            loadingConversations.value = false;
            loadingMoreConversations.value = false;
        }
    };

    const initGlobalMercure = async () => {
        if (globalEventSource.value) return;

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
                    const index = conversations.value.findIndex(c => c.id === convData.id);

                    if (index !== -1) {
                        conversations.value[index] = { ...conversations.value[index], ...convData };
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
            console.error('❌ Fallo al inicializar Global Mercure:', err);
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

                const index = messages.value.findIndex(m => m.id === incomingData.id);

                if (index !== -1) {
                    messages.value[index] = { ...messages.value[index], ...incomingData };
                } else {
                    messages.value.push(incomingData);
                    fetchConversations();
                }
            };

            eventSource.value.onerror = (err) => {
                console.error('❌ Error en el túnel de Mercure:', err);
            };

        } catch (err) {
            console.error('❌ Fallo al inicializar Mercure:', err);
        }
    };

    const selectConversation = async (id: string) => {
        const found = conversations.value.find(c => c.id === id);
        if (!found) return;

        currentConversation.value = found;
        loadingMessages.value = true;
        messagesPage.value = 1;
        hasMoreMessages.value = true;

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

            const response = await apiClient.post('/platform/user/util/msg/messages', form, {
                headers: {
                    'Content-Type': 'multipart/form-data'
                }
            });

            messages.value.push(response.data);

            attachmentStore.clear();
            fetchConversations();

        } catch (err) {
            error.value = 'Fallo al enviar el mensaje. Verifica el tamaño del archivo.';
            console.error('Error enviando mensaje Multipart:', err);
        } finally {
            sendingMessage.value = false;
        }
    };

    return {
        conversations, filteredConversations, currentConversation, messages, activeChatMessages, scheduledMessages,
        templates, validTemplates, filterStatus, loadingConversations, loadingMessages, sendingMessage, error,
        loadingMoreConversations, loadingMoreMessages, hasMoreMessages, hasMoreConversations,
        getExternalContextUrl, fetchConversations, fetchTemplates, selectConversation, loadMoreMessages, sendMessage,
        initGlobalMercure, connectToMercure
    };
});