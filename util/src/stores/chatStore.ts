import { defineStore } from 'pinia';
import { ref, computed } from 'vue';
import axios from 'axios';

// ============================================================================
// INTERFACES
// ============================================================================

export interface ApiMessage {
    '@id': string; id: string; direction: string; status: string;
    senderType: string; contentLocal: string | null; contentExternal: string | null; createdAt: string;
}

export interface ApiConversation {
    '@id': string; id: string; status: string; guestName: string | null;
    guestPhone: string | null; contextType: string; contextId: string;
    createdAt: string; lastMessageAt: string | null; unreadCount: number;
    contextOrigin: string | null; contextStatusTag: string | null;
    contextMilestones: { start?: string; end?: string; booked_at?: string; eta?: string; };
    contextItems: string[];
}

export const useChatStore = defineStore('chatStore', () => {

    const getUrls = () => {
        // @ts-ignore
        const config = window.OPENPERU_CONFIG;
        return {
            api: config?.apiUrl || import.meta.env.VITE_API_URL || 'https://api.openperu.pe',
            panel: config?.panelUrl || import.meta.env.VITE_PANEL_URL || 'https://panel.openperu.pe'
        };
    };

    const apiClient = axios.create({
        withCredentials: true,
        headers: { 'Accept': 'application/ld+json', 'Content-Type': 'application/ld+json' }
    });

    apiClient.interceptors.request.use((config) => {
        config.baseURL = getUrls().api;
        return config;
    });

    const conversations = ref<ApiConversation[]>([]);
    const currentConversation = ref<ApiConversation | null>(null);
    const messages = ref<ApiMessage[]>([]);
    const filterStatus = ref<string>('open');

    const loadingConversations = ref(false);
    const loadingMessages = ref(false);
    const sendingMessage = ref(false);
    const error = ref<string | null>(null);

    const filteredConversations = computed(() => {
        return conversations.value.filter(c =>
            c.status && c.status.toLowerCase() === filterStatus.value.toLowerCase()
        );
    });

    const getExternalContextUrl = computed(() => {
        if (!currentConversation.value) return null;
        const urls = getUrls();
        const chat = currentConversation.value;
        const routes: Record<string, string> = { 'pms_reserva': `/pms-reserva/${chat.contextId}` };
        return routes[chat.contextType] ? `${urls.panel}${routes[chat.contextType]}` : null;
    });

    const extractData = (response: any) => {
        return response.data['hydra:member'] || response.data['member'] || (Array.isArray(response.data) ? response.data : []);
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

    const sendMessage = async (text: string) => {
        if (!currentConversation.value) return;
        sendingMessage.value = true;
        try {
            const payload = {
                conversation: currentConversation.value['@id'],
                contentLocal: text.trim(),
                direction: 'outgoing',
                senderType: 'host',
                status: 'pending'
            };
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
        conversations, filteredConversations, currentConversation, messages,
        filterStatus, loadingConversations, loadingMessages, sendingMessage, error,
        getExternalContextUrl, fetchConversations, selectConversation, sendMessage
    };
});