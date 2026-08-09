// src/stores/chat/noLeidosStore.ts
import { defineStore } from 'pinia';
import { ref, computed } from 'vue';
import { apiClient, type CustomAxiosRequestConfig } from '@/services/apiClient';

// ============================================================================
// RESUMEN GLOBAL DE MENSAJES SIN LEER
// ----------------------------------------------------------------------------
// Fuente ÚNICA de "cuántos mensajes hay sin leer" para toda la app: el badge del
// icono, el contador del portal, el del selector de módulos y el de las pestañas
// del chat.
//
// Existe porque antes ese número se sacaba de `chatStore.conversations`, que
// está PAGINADO: una conversación con no leídos fuera de la primera página no se
// contaba, así que el badge dependía de cuánto scroll hubiera hecho el operador.
// Aquí el total lo calcula el servidor de una consulta agregada.
//
// Espejo del backend: `UnreadSummaryController` — si cambian las claves de la
// respuesta, hay que tocar los dos lados.
// ============================================================================

export type EstadoConversacion = 'open' | 'archived' | 'closed';

export interface ConversacionNoLeida {
    id: string;
    guestName: string;
    unreadCount: number;
    status: EstadoConversacion;
    /** ISO-8601, o `null` si la conversación nunca registró un último mensaje. */
    lastMessageAt: string | null;
    /**
     * Qué está pidiendo el huésped, en una línea, resumido por IA sobre los mensajes
     * posteriores a la última respuesta del equipo.
     *
     * `null` mientras la IA no haya pasado (mensaje recién llegado, resumen apagado o
     * motor caído). Quien lo pinta debe tolerarlo, no asumir que siempre hay texto.
     */
    resumenIa: string | null;
}

interface ResumenEstado {
    mensajes: number;
    conversaciones: number;
}

/** Espejo exacto de lo que devuelve `UnreadSummaryController::__invoke()`. */
interface RespuestaResumen {
    totalMensajes: number;
    totalConversaciones: number;
    porEstado: Record<EstadoConversacion, ResumenEstado>;
    conversaciones: ConversacionNoLeida[];
}

const ESTADO_VACIO: Record<EstadoConversacion, ResumenEstado> = {
    open: { mensajes: 0, conversaciones: 0 },
    archived: { mensajes: 0, conversaciones: 0 },
    closed: { mensajes: 0, conversaciones: 0 },
};

export const useNoLeidosStore = defineStore('noLeidosStore', () => {

    const totalMensajes = ref(0);
    const totalConversaciones = ref(0);
    const porEstado = ref<Record<EstadoConversacion, ResumenEstado>>({ ...ESTADO_VACIO });
    const conversaciones = ref<ConversacionNoLeida[]>([]);
    const cargando = ref(false);

    /** Mensajes sin leer de un estado concreto; 0 si ese estado no trae nada. */
    const mensajesDe = (estado: EstadoConversacion): number => porEstado.value[estado]?.mensajes ?? 0;

    const hayNoLeidos = computed(() => totalMensajes.value > 0);

    /**
     * Evita que una ráfaga de eventos de Mercure dispare una petición por mensaje.
     * Al llegar varios seguidos —lo normal cuando un huésped escribe tres líneas—
     * se agrupa todo en una sola consulta.
     */
    let temporizador: ReturnType<typeof setTimeout> | null = null;

    const aplicar = (datos: RespuestaResumen): void => {
        totalMensajes.value = datos.totalMensajes ?? 0;
        totalConversaciones.value = datos.totalConversaciones ?? 0;
        porEstado.value = { ...ESTADO_VACIO, ...(datos.porEstado ?? {}) };
        conversaciones.value = datos.conversaciones ?? [];
        pintarBadge(totalMensajes.value);
    };

    /**
     * Badge nativo del icono de la app.
     *
     * Vive aquí y NO en chatStore a propósito: es el mismo número que los demás
     * contadores y debe salir de la misma fuente. Cuando lo calculaba chatStore
     * sobre su lista paginada, el icono decía 4 mientras el chat mostraba 24.
     *
     * Con la app cerrada el badge lo pone el sistema operativo al mostrarse la
     * notificación push; esto solo cubre el caso de la app abierta.
     */
    const pintarBadge = (total: number): void => {
        if (!('setAppBadge' in navigator) || !('clearAppBadge' in navigator)) return;

        if (total > 0) navigator.setAppBadge(total).catch(() => {});
        else navigator.clearAppBadge().catch(() => {});

        // Si ya no queda nada pendiente, se retiran también los avisos que el SO
        // tenga en la barra: leerlos en el escritorio debe limpiarlos del móvil.
        if (total === 0 && 'serviceWorker' in navigator && navigator.serviceWorker.controller) {
            navigator.serviceWorker.controller.postMessage({ type: 'CLEAR_NOTIFICATIONS' });
        }
    };

    /**
     * Relee el resumen del servidor.
     *
     * Se llama con `_silentAuthCheck` porque lo dispara la carga de cualquier
     * vista: si la sesión aún no está lista, debe fallar en silencio y no abrir
     * el modal de login por su cuenta.
     */
    const refrescar = async (): Promise<void> => {
        if (cargando.value) return;
        cargando.value = true;
        try {
            const respuesta = await apiClient.get<RespuestaResumen>(
                '/platform/message/conversations/unread-summary',
                { _silentAuthCheck: true } as CustomAxiosRequestConfig,
            );
            aplicar(respuesta.data);
        } catch {
            // Silencio deliberado: un contador que no se pudo refrescar no es
            // motivo para molestar al operador ni para borrar el último valor
            // bueno, que sigue siendo la mejor aproximación que tenemos.
        } finally {
            cargando.value = false;
        }
    };

    /** Refresco agrupado, para engancharlo a los eventos de Mercure. */
    const refrescarPronto = (): void => {
        if (temporizador) clearTimeout(temporizador);
        temporizador = setTimeout(() => {
            temporizador = null;
            void refrescar();
        }, 400);
    };

    return {
        totalMensajes,
        totalConversaciones,
        porEstado,
        conversaciones,
        cargando,
        hayNoLeidos,
        mensajesDe,
        refrescar,
        refrescarPronto,
    };
});
