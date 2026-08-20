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
    PmsReservaBusquedaItem,
    PmsLimpiadorOption,
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
    /** Quién puede limpiar (usuarios con ROLE_LIMPIEZA) — ver PmsEnumAjaxController::getLimpiadores(). */
    const limpiadores = ref<PmsLimpiadorOption[]>([]);
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
            const [uRes, eRes, epRes, cRes, lRes] = await Promise.all([
                apiClient.get('/platform/pms/pms_unidads'),
                apiClient.get('/platform/pms/pms_evento_estados'),
                apiClient.get('/platform/pms/pms_evento_estado_pagos'),
                apiClient.get('/platform/pms/pms_channels'),
                // No es un ApiResource sino un endpoint plano (`[{id, label}]`), y su fallo NO
                // tumba al resto: sin unidades no hay calendario, pero sin esta lista sólo se
                // queda gris el selector de limpieza — y el drawer ya avisa cuando llega vacía.
                apiClient.get<PmsLimpiadorOption[]>('/tipo/user/enum/pms/limpiadores').catch(() => null),
            ]);

            unidades.value = uRes.data['hydra:member'] || uRes.data['member'] || [];
            estados.value = eRes.data['hydra:member'] || eRes.data['member'] || [];
            estadosPago.value = epRes.data['hydra:member'] || epRes.data['member'] || [];
            channels.value = cRes.data['hydra:member'] || cRes.data['member'] || [];
            limpiadores.value = lRes?.data ?? [];

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
     * Elimina una estancia/bloqueo. El backend la veta con 403 si no es borrable
     * (PmsEventoCalendarioSecurityListener::preRemove -> isSafeToDelete): OTA, ya
     * existente en Beds24 sin cancelar, o con una sincronización en curso.
     */
    const deleteEvento = async (id: string): Promise<void> => {
        isSaving.value = true;
        error.value = null;
        try {
            await apiClient.delete(`/platform/pms/pms_evento_calendarios/${id}`);
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
     * Elimina la reserva completa (y en cascada todas sus estancias). El backend
     * la veta con 403 si alguna estancia no es borrable (PmsReservaDeleteListener).
     */
    const deleteReserva = async (id: string): Promise<void> => {
        isSaving.value = true;
        error.value = null;
        try {
            await apiClient.delete(`/platform/pms/pms_reservas/${id}`);
        } finally {
            isSaving.value = false;
        }
    };

    /**
     * Busca la conversación de chat interno vinculada a una reserva.
     * Devuelve su UUID o null si todavía no existe: sin hilo, no se ofrece abrir el chat.
     *
     * ⚠️ **No se filtra la colección por `contextType`/`contextId`.** Eso resuelve por la
     * CABECERA del hilo, y desde que las conversaciones se fusionan por persona la cabecera
     * del superviviente apunta a UNA de sus reservas mientras los hilos absorbidos —archivados
     * y vacíos— conservan la suya. Medido el 20/08/2026: **26 reservas abrían un hilo
     * archivado con 0 mensajes** teniendo su conversación viva al lado, y sin dar error.
     *
     * `/por-asunto` resuelve por el ENLACE TITULAR, que es el camino que sobrevive a la
     * fusión y a que la persona tenga varias reservas en el mismo hilo. Espejo del backend:
     * `EnlacesDeConversacion::hiloTitularDe()`.
     */
    const fetchConversacionId = async (reservaId: string): Promise<string | null> => {
        const res = await apiClient.get('/platform/message/conversations/por-asunto', {
            params: { contextType: 'pms_reserva', contextId: reservaId },
        });

        // 204 sin cuerpo cuando el asunto todavía no tiene hilo.
        return res.data?.id ?? null;
    };

    /**
     * El número al que se le escribe a esta reserva, y de dónde salió.
     *
     * ⚠️ **No está en la reserva.** El campo `telefono` es la SEMILLA con la que se creó; el
     * número bueno vive en las identidades de la persona, que es donde se corrige, se retira y
     * se marca cuál usar. Llegar hasta él es `reserva → enlace titular → hilo → identidad`, y
     * por eso se pide aparte en vez de serializarlo: en un listado de calendario ese recorrido
     * sería un N+1 por fila.
     *
     * `origen` vale `semilla` cuando la persona aún no tiene identificador propio, y el panel lo
     * pinta como «sin verificar».
     */
    const fetchTelefonoContacto = async (
        reservaId: string,
    ): Promise<{ telefono: string | null; origen: 'identidad' | 'semilla' | null } | null> => {
        try {
            const { data } = await apiClient.get(`/pms/reservas/${reservaId}/telefono-contacto`);

            return data ?? null;
        } catch {
            return null;
        }
    };

    /**
     * Resuelve el texto final (variables reemplazadas) de una plantilla de WhatsApp
     * para una reserva. El propio caller arma la URL de wa.me y hace window.open().
     */
    const fetchWhatsappLink = async (reservaId: string, templateId: string): Promise<PmsReservaWhatsappLink> => {
        const res = await apiClient.get(`/pms/reservas/${reservaId}/whatsapp-link/${templateId}`);
        return res.data;
    };

    /**
     * Buscador de reservas por texto libre (nombre/apellido del huésped,
     * localizador, referencia del canal o casita). Devuelve una fila por
     * estancia, ordenadas por cercanía a hoy — ver PmsReservaBuscarController.
     *
     * `signal` permite cancelar la petición en vuelo cuando el usuario sigue
     * tecleando: sin eso, una respuesta lenta de una consulta vieja puede pisar
     * a la nueva en pantalla.
     */
    const buscarReservas = async (q: string, signal?: AbortSignal): Promise<PmsReservaBusquedaItem[]> => {
        const res = await apiClient.get('/pms/reservas/buscar', { params: { q }, signal });
        return Array.isArray(res.data) ? res.data : [];
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
        limpiadores,
        eventoActivo,
        reservaActiva,
        fetchMasters,
        fetchEvento,
        patchEvento,
        createEvento,
        deleteEvento,
        fetchReserva,
        patchReserva,
        deleteReserva,
        createReservaCompleta,
        fetchConversacionId,
        fetchTelefonoContacto,
        fetchWhatsappLink,
        buscarReservas,
        clearActivo,
    };
});

// El helper vive ahora en services/apiError.ts (lo comparten todos los stores).
// Se re-exporta para no romper los imports existentes desde este módulo.
export { extractApiErrorMessage } from '@/services/apiError';
