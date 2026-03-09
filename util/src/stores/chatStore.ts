import { defineStore } from 'pinia';
import { ref } from 'vue';
import axios from 'axios';

// ============================================================================
// INTERFACES (Mapeo exacto de los grupos de serialización de API Platform)
// ============================================================================

export interface ApiMessage {
    '@id': string;
    id: string;
    direction: string;
    status: string;
    senderType: string;
    contentLocal: string | null;
    contentExternal: string | null;
    createdAt: string;
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
}

export const useChatStore = defineStore('chatStore', () => {
    // ============================================================================
    // CONFIGURACIÓN BASE
    // ============================================================================

    // Obtenemos la URL base desde la configuración inyectada por Symfony o el archivo .env
    // @ts-ignore
    const apiBaseUrl = window.OPENPERU_CONFIG?.apiUrl || import.meta.env.VITE_API_URL || '';

    // Cliente Axios pre-configurado para interactuar con API Platform y Symfony
    const apiClient = axios.create({
        baseURL: apiBaseUrl,
        withCredentials: true, // Vital: Permite enviar la cookie de sesión de Symfony para pasar el firewall
        headers: {
            'Accept': 'application/ld+json',
            'Content-Type': 'application/ld+json'
        }
    });

    // ============================================================================
    // ESTADO REACTIVO (State)
    // ============================================================================

    const conversations = ref<ApiConversation[]>([]);
    const currentConversation = ref<ApiConversation | null>(null);
    const messages = ref<ApiMessage[]>([]);

    const loadingConversations = ref<boolean>(false);
    const loadingMessages = ref<boolean>(false);
    const sendingMessage = ref<boolean>(false);
    const error = ref<string | null>(null);

    // ============================================================================
    // ACCIONES (Actions)
    // ============================================================================

    /**
     * Obtiene la lista de todas las conversaciones disponibles.
     * API Platform ordena automáticamente por createdAt DESC gracias al atributo #[ApiFilter].
     */
    const fetchConversations = async (): Promise<void> => {
        loadingConversations.value = true;
        error.value = null;
        try {
            const response = await apiClient.get('/platform/user/util/msg/conversations');
            // 'hydra:member' es el array donde API Platform coloca los resultados
            conversations.value = response.data['member'] || response.data['hydra:member'] || [];
        } catch (err: any) {
            console.error('Error fetching conversations:', err);
            error.value = err.response?.data?.['hydra:description'] || 'No se pudieron cargar las conversaciones.';
        } finally {
            loadingConversations.value = false;
        }
    };

    /**
     * Selecciona una conversación como activa y descarga su historial de mensajes.
     * @param conversationId El UUID (id normal) de la conversación a seleccionar.
     */
    const selectConversation = async (conversationId: string): Promise<void> => {
        // Buscamos la conversación en nuestra lista local
        const found = conversations.value.find(c => c.id === conversationId);

        if (found) {
            currentConversation.value = found;
        } else {
            error.value = 'Conversación no encontrada en memoria.';
            return;
        }

        loadingMessages.value = true;
        error.value = null;
        try {
            // Utilizamos el '@id' (IRI) de la conversación para filtrar los mensajes en API Platform.
            // Ordenamos ascendente para que los mensajes más antiguos queden arriba.
            const response = await apiClient.get(`/platform/user/util/msg/conversations/${conversationId}/messages`);
            messages.value = response.data['member'] || response.data['hydra:member'] || [];
        } catch (err: any) {
            console.error('Error fetching messages:', err);
            error.value = 'No se pudieron cargar los mensajes de esta conversación.';
        } finally {
            loadingMessages.value = false;
        }
    };

    /**
     * Envía un nuevo mensaje asociado a la conversación actual activa.
     * @param text El contenido escrito por el usuario en la interfaz.
     */
    const sendMessage = async (text: string): Promise<void> => {
        if (!currentConversation.value || !text.trim()) return;

        sendingMessage.value = true;
        error.value = null;

        try {
            // El payload cumple con la estructura requerida por la entidad Message en Symfony
            const payload = {
                conversation: currentConversation.value['@id'], // Referencia IRI vital para la relación ORM
                contentLocal: text.trim(),
                direction: 'outgoing', // Siempre saliente porque lo envía el host desde la app
                senderType: 'host',
                status: 'pending'      // Queda pendiente hasta que el worker/API lo procese
            };

            const response = await apiClient.post('/platform/user/util/msg/messages', payload);

            // Agregamos el mensaje recién creado a la lista local para respuesta visual instantánea (UI optimista)
            messages.value.push(response.data);

        } catch (err: any) {
            console.error('Error sending message:', err);
            error.value = 'Fallo al enviar el mensaje. Revisa tu conexión.';
        } finally {
            sendingMessage.value = false;
        }
    };

    /**
     * Limpia la conversación activa y los mensajes actuales.
     * Útil para cuando el usuario presiona "Volver" en la vista móvil.
     */
    const clearCurrentConversation = (): void => {
        currentConversation.value = null;
        messages.value = [];
        error.value = null;
    };

    return {
        // Estado
        conversations,
        currentConversation,
        messages,
        loadingConversations,
        loadingMessages,
        sendingMessage,
        error,

        // Acciones
        fetchConversations,
        selectConversation,
        sendMessage,
        clearCurrentConversation
    };
});