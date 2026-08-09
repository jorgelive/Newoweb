// src/stores/finanzas/enlacesPagoStore.ts
// ============================================================================
// Enlaces de pago por pasarela.
//
// Vive en `stores/finanzas/` y NO en `stores/reservas/`: el módulo es transversal
// (hoy reservas del PMS, mañana tours) y el store se indexa por `origenTipo` +
// `origenId`, no por reserva. Cuando exista el módulo de tours reusará este mismo
// store cambiando el `origenTipo` — nada más.
//
// Endpoints: `App\Finanzas\Controller\Api\FinEnlacePagoApiController` (no es API
// Platform: rutas planas bajo /finanzas, sin prefijo /platform).
// ============================================================================
import { defineStore } from 'pinia';
import { ref } from 'vue';
import { apiClient } from '@/services/apiClient';
import type {
    FinEnlacePago,
    FinEnlacePagoCreate,
    FinOrigenCobro,
    FinPasarelaOpcion,
    FinRespuestaPasarela,
} from '@/types/finEnlacePagoModel';

export const useEnlacesPagoStore = defineStore('enlacesPagoStore', () => {
    const isLoading = ref<boolean>(false);
    const isSaving = ref<boolean>(false);

    /** Enlaces del documento abierto, del más nuevo al más viejo (los ordena el backend). */
    const enlaces = ref<FinEnlacePago[]>([]);

    /**
     * Pasarelas CON credenciales. Se cargan una vez por sesión: no cambian sin un deploy.
     *
     * Sólo llegan las configuradas, así que si el array trae una sola opción no hay nada
     * que elegir y el formulario ni pinta el selector.
     */
    const pasarelas = ref<FinPasarelaOpcion[]>([]);

    const fetchPasarelas = async (): Promise<void> => {
        if (pasarelas.value.length) return;
        const { data } = await apiClient.get<FinPasarelaOpcion[]>('/finanzas/enlaces-pago/pasarelas');
        pasarelas.value = data ?? [];
    };

    const fetchPorOrigen = async (origenTipo: FinOrigenCobro, origenId: string): Promise<void> => {
        isLoading.value = true;
        try {
            const { data } = await apiClient.get<{ enlaces: FinEnlacePago[] }>('/finanzas/enlaces-pago', {
                params: { origenTipo, origenId },
            });
            enlaces.value = data.enlaces ?? [];
        } finally {
            isLoading.value = false;
        }
    };

    /**
     * Emite un enlace y lo deja el primero de la lista.
     *
     * Se inserta en local en vez de recargar: el operador acaba de pulsar el botón y lo que
     * quiere es copiar la URL ya, no esperar un segundo viaje.
     */
    const crear = async (payload: FinEnlacePagoCreate): Promise<FinEnlacePago> => {
        isSaving.value = true;
        try {
            const { data } = await apiClient.post<{ enlace: FinEnlacePago }>('/finanzas/enlaces-pago', payload);
            enlaces.value = [data.enlace, ...enlaces.value];
            return data.enlace;
        } finally {
            isSaving.value = false;
        }
    };

    /**
     * Respuesta cruda de la pasarela, bajo demanda.
     *
     * No se cachea ni se guarda en el store: es un dato de auditoría que se abre, se mira y
     * se cierra. Mantenerlo en memoria solo serviría para que un panel abierto media tarde
     * acumulase respuestas de enlaces que ya nadie mira.
     */
    const fetchRespuesta = async (id: string): Promise<FinRespuestaPasarela> => {
        const { data } = await apiClient.get<FinRespuestaPasarela>(`/finanzas/enlaces-pago/${id}/respuesta`);
        return data;
    };

    const anular = async (id: string): Promise<void> => {
        isSaving.value = true;
        try {
            const { data } = await apiClient.post<{ enlace: FinEnlacePago }>(`/finanzas/enlaces-pago/${id}/anular`, {});
            enlaces.value = enlaces.value.map((e) => (e.id === id ? data.enlace : e));
        } finally {
            isSaving.value = false;
        }
    };

    const clear = (): void => {
        enlaces.value = [];
    };

    return {
        isLoading, isSaving, enlaces, pasarelas,
        fetchPasarelas, fetchPorOrigen, fetchRespuesta, crear, anular, clear,
    };
});
