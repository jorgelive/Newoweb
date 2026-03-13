import { defineStore } from 'pinia';
import { ref, computed } from 'vue';
import axios from 'axios';

// ============================================================================
// INTERFACES (TypeScript Estricto)
// ============================================================================

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
    // Soportamos 'gupshup' por retrocompatibilidad con registros viejos en la BD
    metadata?: { beds24?: any; whatsappGupshup?: any; gupshup?: any };
    // API Platform puede devolver el objeto hidratado o solo el string (IRI)
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
        headers: { 'Accept': 'application/ld+json', 'Content-Type': 'application/ld+json' }
    });

    // 🔥 RESTAURADO: Inyección dinámica de la URL base para evitar el error 404
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

    const filteredConversations = computed(() => {
        return conversations.value.filter(c => c.status && c.status.toLowerCase() === filterStatus.value.toLowerCase());
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

    const extractData = (response: any) => response.data['hydra:member'] || response.data['member'] || (Array.isArray(response.data) ? response.data : []);

    const fetchTemplates = async () => {
        try {
            const response = await apiClient.get('/platform/user/util/msg/templates');
            templates.value = extractData(response);
        } catch (err) {
            console.error('Error cargando plantillas', err);
        }
    };

    const fetchConversations = async () => {
        loadingConversations.value = true;
        error.value = null;
        try {
            const response = await apiClient.get('/platform/user/util/msg/conversations?order[lastMessageAt]=desc');
            conversations.value = extractData(response);
        } catch (err: any) {
            error.value = 'Error al sincronizar chats';
        } finally {
            loadingConversations.value = false;
        }
    };

    const selectConversation = async (id: string) => {
        const found = conversations.value.find(c => c.id === id);
        if (!found) return;
        currentConversation.value = found;
        loadingMessages.value = true;
        try {
            const response = await apiClient.get(`/platform/user/util/msg/conversations/${id}/messages`);
            messages.value = extractData(response);
            found.unreadCount = 0;
        } catch (err) {
            error.value = 'Error al cargar mensajes';
        } finally {
            loadingMessages.value = false;
        }
    };

    const sendMessage = async (text: string, templateIri: string | null = null, channels: string[] = []) => {
        if (!currentConversation.value) return;
        sendingMessage.value = true;
        try {
            const payload: any = {
                conversation: currentConversation.value['@id'],
                direction: 'outgoing',
                senderType: 'host',
                status: 'pending',
                transientChannels: channels
            };

            if (templateIri) {
                payload.template = templateIri;
            } else {
                payload.contentLocal = text.trim();
            }

            const response = await apiClient.post('/platform/user/util/msg/messages', payload);
            messages.value.push(response.data);
            fetchConversations();
        } catch {
            error.value = 'Fallo al enviar mensaje';
        } finally {
            sendingMessage.value = false;
        }
    };

    return {
        conversations, filteredConversations, currentConversation, messages, templates, validTemplates,
        filterStatus, loadingConversations, loadingMessages, sendingMessage, error,
        getExternalContextUrl, fetchConversations, fetchTemplates, selectConversation, sendMessage
    };
});