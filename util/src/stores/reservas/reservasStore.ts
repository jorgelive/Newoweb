// src/stores/reservas/reservasStore.ts
import { defineStore } from 'pinia';
import { ref } from 'vue';
import { apiClient } from '@/services/apiClient';
import type {
    PmsEventoCalendario,
    PmsReserva,
    PmsUnidadOption,
    PmsEventoEstadoOption,
    PmsEventoEstadoPagoOption,
    PmsChannelOption,
    PmsEventoCalendarioPatch,
    PmsEventoCalendarioCreate,
    PmsReservaPatch,
    PmsReservaCrearPayload,
    PmsReservaWhatsappLink,
} from '@/types/pmsReservaModel';

export const useReservasStore = defineStore('reservasStore', () => {
    // ============================================================================
    // ESTADO
    // ============================================================================
    const isLoading = ref<boolean>(false);
    const isSaving = ref<boolean>(false);
    const error = ref<string | null>(null);

    // Catálogos de referencia para los selects del formulario (unidad/estado/pago/canal).
    // País e idioma se resuelven con useMaestroStore (ya existente, no se duplica aquí).
    const unidades = ref<PmsUnidadOption[]>([]);
    const estados = ref<PmsEventoEstadoOption[]>([]);
    const estadosPago = ref<PmsEventoEstadoPagoOption[]>([]);
    const channels = ref<PmsChannelOption[]>([]);
    const mastersLoaded = ref<boolean>(false);

    // Evento/reserva actualmente abiertos en el drawer de edición.
    const eventoActivo = ref<PmsEventoCalendario | null>(null);
    const reservaActiva = ref<PmsReserva | null>(null);

    // ============================================================================
    // CATÁLOGOS (unidad, estado, estado de pago, canal)
    // ============================================================================

    /**
     * Carga los catálogos que alimentan los selects del formulario de edición.
     * Se cachea en memoria: solo golpea la red la primera vez que se abre el calendario.
     */
    const fetchMasters = async (): Promise<void> => {
        if (mastersLoaded.value) return;

        isLoading.value = true;
        error.value = null;

        try {
            const [uRes, eRes, epRes, cRes] = await Promise.all([
                apiClient.get('/platform/pms/pms_unidads'),
                apiClient.get('/platform/pms/pms_evento_estados'),
                apiClient.get('/platform/pms/pms_evento_estado_pagos'),
                apiClient.get('/platform/pms/pms_channels'),
            ]);

            unidades.value = uRes.data['hydra:member'] || uRes.data['member'] || [];
            estados.value = eRes.data['hydra:member'] || eRes.data['member'] || [];
            estadosPago.value = epRes.data['hydra:member'] || epRes.data['member'] || [];
            channels.value = cRes.data['hydra:member'] || cRes.data['member'] || [];

            mastersLoaded.value = true;
        } catch (err) {
            console.error('Error cargando catálogos de reservas:', err);
            error.value = 'No se pudieron cargar los catálogos de reservas (unidades/estados).';
            throw err;
        } finally {
            isLoading.value = false;
        }
    };

    // ============================================================================
    // EVENTO DE CALENDARIO (estancia / bloqueo)
    // ============================================================================

    const fetchEvento = async (id: string): Promise<PmsEventoCalendario> => {
        isLoading.value = true;
        error.value = null;
        try {
            const res = await apiClient.get(`/platform/pms/pms_evento_calendarios/${id}`);
            eventoActivo.value = res.data;
            return res.data;
        } finally {
            isLoading.value = false;
        }
    };

    const patchEvento = async (id: string, payload: PmsEventoCalendarioPatch): Promise<PmsEventoCalendario> => {
        isSaving.value = true;
        error.value = null;
        try {
            const res = await apiClient.patch(`/platform/pms/pms_evento_calendarios/${id}`, payload);
            eventoActivo.value = res.data;
            return res.data;
        } finally {
            isSaving.value = false;
        }
    };

    /**
     * Crea un evento nuevo sin reserva asociada (bloqueo manual del calendario).
     * Equivalente al botón "Crear Bloqueo" de EasyAdmin.
     */
    const createEvento = async (payload: PmsEventoCalendarioCreate): Promise<PmsEventoCalendario> => {
        isSaving.value = true;
        error.value = null;
        try {
            const res = await apiClient.post('/platform/pms/pms_evento_calendarios', payload);
            return res.data;
        } finally {
            isSaving.value = false;
        }
    };

    /**
     * Crea una "reserva completa" (titular + estancia inicial) en un solo paso atómico.
     * Equivalente a crear un Bloqueo, pero con datos de cliente y estado inicial "pendiente".
     * Solo puede crear reservas directas: el backend ignora/fuerza el canal a "directo".
     */
    const createReservaCompleta = async (payload: PmsReservaCrearPayload): Promise<PmsReserva> => {
        isSaving.value = true;
        error.value = null;
        try {
            const res = await apiClient.post('/platform/pms/pms_reservas', payload);
            return res.data;
        } finally {
            isSaving.value = false;
        }
    };

    // ============================================================================
    // RESERVA (datos del cliente)
    // ============================================================================

    const fetchReserva = async (id: string): Promise<PmsReserva> => {
        isLoading.value = true;
        error.value = null;
        try {
            const res = await apiClient.get(`/platform/pms/pms_reservas/${id}`);
            reservaActiva.value = res.data;
            return res.data;
        } finally {
            isLoading.value = false;
        }
    };

    const patchReserva = async (id: string, payload: PmsReservaPatch): Promise<PmsReserva> => {
        isSaving.value = true;
        error.value = null;
        try {
            const res = await apiClient.patch(`/platform/pms/pms_reservas/${id}`, payload);
            reservaActiva.value = res.data;
            return res.data;
        } finally {
            isSaving.value = false;
        }
    };

    /**
     * Busca la conversación de chat interno vinculada a una reserva.
     * Devuelve su UUID o null si todavía no existe (igual que el legacy: si no
     * hay fila en msg_conversation, no se ofrece la opción de abrir el chat).
     */
    const fetchConversacionId = async (reservaId: string): Promise<string | null> => {
        const res = await apiClient.get('/platform/message/conversations', {
            params: { contextType: 'pms_reserva', contextId: reservaId },
        });
        const items = res.data['hydra:member'] || res.data['member'] || [];
        return items[0]?.id ?? null;
    };

    /**
     * Resuelve el texto final (variables reemplazadas) de una plantilla de WhatsApp
     * para una reserva. El propio caller arma la URL de wa.me y hace window.open().
     */
    const fetchWhatsappLink = async (reservaId: string, templateId: string): Promise<PmsReservaWhatsappLink> => {
        const res = await apiClient.get(`/pms/reservas/${reservaId}/whatsapp-link/${templateId}`);
        return res.data;
    };

    const clearActivo = (): void => {
        eventoActivo.value = null;
        reservaActiva.value = null;
        error.value = null;
    };

    return {
        isLoading,
        isSaving,
        error,
        unidades,
        estados,
        estadosPago,
        channels,
        eventoActivo,
        reservaActiva,
        fetchMasters,
        fetchEvento,
        patchEvento,
        createEvento,
        fetchReserva,
        patchReserva,
        createReservaCompleta,
        fetchConversacionId,
        fetchWhatsappLink,
        clearActivo,
    };
});

// ============================================================================
// HELPER: mensaje de error legible desde una respuesta de API Platform
// (ConstraintViolationListError en 422, AccessDeniedHttpException en 403, etc.)
// ============================================================================
export function extractApiErrorMessage(err: unknown, fallback = 'Ocurrió un error inesperado.'): string {
    const data = (err as { response?: { data?: Record<string, unknown> } })?.response?.data;
    if (!data) return fallback;

    const violations = data['violations'] as Array<{ propertyPath?: string; message?: string }> | undefined;
    if (Array.isArray(violations) && violations.length > 0) {
        return violations.map(v => (v.propertyPath ? `${v.propertyPath}: ${v.message}` : v.message)).join(' | ');
    }

    return (data['detail'] as string) || (data['hydra:description'] as string) || (data['title'] as string) || fallback;
}
