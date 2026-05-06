// src/stores/cotizaciones/fileStore.ts
import { defineStore } from 'pinia';
import { ref, computed } from 'vue';
import type { components } from '@/types/api';
import { apiClient } from '@/services/apiClient';
import type { ApiMaestro } from '@/stores/maestroStore';

// ============================================================================
// TIPOS AUTOGENERADOS Y EXTENDIDOS (HÍBRIDOS)
// ============================================================================
type BaseApiCotizacionFile = components['schemas']['CotizacionFile.jsonld-file.read_timestamp.read'];

/**
 * TIPADO HÍBRIDO EXPEDIENTE:
 * Extiende la definición base OpenAPI inyectando identificadores Hydra estandarizados
 * y forzando la lectura de relaciones anidadas (País/Idioma) gracias a los Serialization Groups.
 */
export type ApiCotizacionFile = BaseApiCotizacionFile & {
    '@id'?: string;
    '@type'?: string;
    id?: string;
    localizador?: string | null;
    email?: string | null;
    telefono?: string | null;
    pais?: ApiMaestro | null;
    idioma?: ApiMaestro | null;
};

// Tipo para el POST, donde las relaciones son puramente IRIs (strings)
export type ApiCotizacionFileWrite = components['schemas']['CotizacionFile-file.write'] & {
    pais?: string | null;
    idioma?: string | null;
    email?: string | null;
    telefono?: string | null;
};

export const useCotizacionFileStore = defineStore('cotizacionFileStore', () => {

    // ============================================================================
    // ESTADOS
    // ============================================================================
    const files = ref<ApiCotizacionFile[]>([]);
    const loadingFiles = ref<boolean>(false);
    const loadingMore = ref<boolean>(false);
    const hasNextPage = ref<boolean>(true);
    const currentPage = ref<number>(1);
    const error = ref<string | null>(null);

    // ============================================================================
    // GETTERS
    // ============================================================================

    /**
     * Devuelve únicamente los expedientes cuyo ciclo de vida sigue abierto.
     * @returns {ApiCotizacionFile[]}
     */
    const getActiveFiles = computed(() => files.value.filter(f => f.estado === 'abierto'));

    // ============================================================================
    // ACCIONES
    // ============================================================================

    /**
     * Obtiene el listado paginado de Expedientes desde la base de datos central.
     * Soporta nativamente el estándar Hydra (v2) y el Context Aliasing (v3).
     *
     * @param {number} page Índice de la página a solicitar.
     * @param {boolean} append Determina si los resultados reemplazan la lista actual o se concatenan.
     * @returns {Promise<void>}
     */
    const fetchFiles = async (page: number = 1, append: boolean = false): Promise<void> => {
        if (append) {
            loadingMore.value = true;
        } else {
            loadingFiles.value = true;
            files.value = [];
        }

        error.value = null;

        try {
            // 🔥 Contexto Sales: Consumiendo la ruta refactorizada de expedientes
            const response = await apiClient.get(`/platform/sales/cotizacion_files?page=${page}&order[createdAt]=desc`);
            const rawData = response.data;

            // Resolución defensiva de miembros de colección para API Platform 3
            const newFiles = rawData['hydra:member'] || rawData['member'] || [];

            if (append) {
                files.value.push(...newFiles);
            } else {
                files.value = newFiles;
            }

            // Resolución defensiva de paginación para API Platform 3
            const viewData = rawData['hydra:view'] || rawData['view'];
            hasNextPage.value = !!(viewData && (viewData['hydra:next'] || viewData['next']));

            currentPage.value = page;

        } catch (err: any) {
            if (err.response?.status !== 401 && !err.message?.includes('HTML')) {
                error.value = err.response?.data?.['hydra:description'] || 'Error de red al cargar los expedientes.';
            }
        } finally {
            loadingFiles.value = false;
            loadingMore.value = false;
        }
    };

    /**
     * Persiste un nuevo expediente comercial.
     *
     * @param {ApiCotizacionFileWrite} payload Estructura de escritura admitida por el endpoint POST.
     * @returns {Promise<ApiCotizacionFile | null>} Instancia del expediente registrado en la BD o null ante falla.
     */
    const createFile = async (payload: ApiCotizacionFileWrite): Promise<ApiCotizacionFile | null> => {
        loadingFiles.value = true;
        error.value = null;

        try {
            // 🔥 Contexto Sales: Unificación de la ruta para mutaciones POST
            const response = await apiClient.post<ApiCotizacionFile>('/platform/sales/cotizacion_files', payload);
            files.value.unshift(response.data);
            return response.data;
        } catch (err: any) {
            error.value = err.response?.data?.['hydra:description'] || err.response?.data?.detail || 'Error de validación al crear el expediente.';
            return null;
        } finally {
            loadingFiles.value = false;
        }
    };

    return {
        files,
        loadingFiles,
        loadingMore,
        hasNextPage,
        currentPage,
        error,
        getActiveFiles,
        fetchFiles,
        createFile
    };
});