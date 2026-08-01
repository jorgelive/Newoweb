// src/stores/tarifas/tarifasStore.ts
import { defineStore } from 'pinia';
import { ref } from 'vue';
import { apiClient } from '@/services/apiClient';
import type {
    PmsTarifaRango,
    PmsTarifaRangoCreate,
    PmsTarifaRangoPatch,
    PmsTarifaMasivaPayload,
    PmsTarifaMasivaResult,
    PmsTarifaMonedaRef,
} from '@/types/pmsTarifaModel';
import type { PmsUnidadOption } from '@/types/pmsReservaModel';

/**
 * Store del módulo de Tarifas (equivalente Vue de PmsTarifaRangoCrudController).
 * Mismo reparto de responsabilidades que reservasStore: catálogos cacheados en
 * memoria + CRUD sobre el recurso REST. El listado del calendario NO pasa por
 * aquí: lo sirven los providers `tarifa_*_spa` vía /fullcalendar/load/*.
 */
export const useTarifasStore = defineStore('tarifasStore', () => {
    // ============================================================================
    // ESTADO
    // ============================================================================
    const isLoading = ref<boolean>(false);
    const isSaving = ref<boolean>(false);
    const error = ref<string | null>(null);

    // Catálogos de los selects del formulario (unidad y moneda).
    const unidades = ref<PmsUnidadOption[]>([]);
    const monedas = ref<PmsTarifaMonedaRef[]>([]);
    const mastersLoaded = ref<boolean>(false);

    const tarifaActiva = ref<PmsTarifaRango | null>(null);

    // ============================================================================
    // CATÁLOGOS
    // ============================================================================

    /** Carga unidades y monedas una sola vez por sesión de la SPA. */
    const fetchMasters = async (): Promise<void> => {
        if (mastersLoaded.value) return;

        isLoading.value = true;
        error.value = null;

        try {
            const [uRes, mRes] = await Promise.all([
                apiClient.get('/platform/pms/pms_unidads'),
                apiClient.get('/platform/maestro/monedas'),
            ]);

            unidades.value = uRes.data['hydra:member'] || uRes.data['member'] || [];
            monedas.value = mRes.data['hydra:member'] || mRes.data['member'] || [];

            mastersLoaded.value = true;
        } catch (err) {
            console.error('Error cargando catálogos de tarifas:', err);
            error.value = 'No se pudieron cargar los catálogos de tarifas (unidades/monedas).';
            throw err;
        } finally {
            isLoading.value = false;
        }
    };

    // ============================================================================
    // TARIFA RANGO (CRUD)
    // ============================================================================

    const fetchTarifa = async (id: string): Promise<PmsTarifaRango> => {
        isLoading.value = true;
        error.value = null;
        try {
            const res = await apiClient.get(`/platform/pms/pms_tarifa_rangos/${id}`);
            tarifaActiva.value = res.data;
            return res.data;
        } finally {
            isLoading.value = false;
        }
    };

    const createTarifa = async (payload: PmsTarifaRangoCreate): Promise<PmsTarifaRango> => {
        isSaving.value = true;
        error.value = null;
        try {
            const res = await apiClient.post('/platform/pms/pms_tarifa_rangos', payload);
            return res.data;
        } finally {
            isSaving.value = false;
        }
    };

    const patchTarifa = async (id: string, payload: PmsTarifaRangoPatch): Promise<PmsTarifaRango> => {
        isSaving.value = true;
        error.value = null;
        try {
            const res = await apiClient.patch(`/platform/pms/pms_tarifa_rangos/${id}`, payload);
            tarifaActiva.value = res.data;
            return res.data;
        } finally {
            isSaving.value = false;
        }
    };

    /**
     * Elimina un rango. No hay reglas de "safe to delete" como en las reservas:
     * Beds24RatesPushQueueListener encola automáticamente el push de borrado
     * para el intervalo afectado, así que el canal queda consistente.
     */
    const deleteTarifa = async (id: string): Promise<void> => {
        isSaving.value = true;
        error.value = null;
        try {
            await apiClient.delete(`/platform/pms/pms_tarifa_rangos/${id}`);
        } finally {
            isSaving.value = false;
        }
    };

    /**
     * Generación masiva: un rango por unidad activa con tarifa base, con un %
     * de ajuste sobre ese precio base (equivalente al botón "Generar Masivo").
     */
    const generarMasivo = async (payload: PmsTarifaMasivaPayload): Promise<PmsTarifaMasivaResult> => {
        isSaving.value = true;
        error.value = null;
        try {
            const res = await apiClient.post('/platform/pms/pms_tarifa_rangos/generar-masivo', payload);
            return res.data;
        } finally {
            isSaving.value = false;
        }
    };

    const clearActiva = (): void => {
        tarifaActiva.value = null;
        error.value = null;
    };

    return {
        isLoading,
        isSaving,
        error,
        unidades,
        monedas,
        tarifaActiva,
        fetchMasters,
        fetchTarifa,
        createTarifa,
        patchTarifa,
        deleteTarifa,
        generarMasivo,
        clearActiva,
    };
});
