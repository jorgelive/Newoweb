import { defineStore } from 'pinia';
import { ref, computed } from 'vue';
import type { components } from '@/types/api';
import { apiClient } from '@/services/apiClient';
import type { ApiMaestro } from '@/stores/maestroStore';

// ============================================================================
// TIPOS AUTOGENERADOS Y EXTENDIDOS (HÍBRIDOS)
// ============================================================================
type BaseApiCotizacionFile = components['schemas']['CotizacionFile.jsonld-file.read_timestamp.read'];

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
    const getActiveFiles = computed(() => files.value.filter(f => f.estado === 'abierto'));

    // ============================================================================
    // ACCIONES PRINCIPALES (EXPEDIENTES)
    // ============================================================================

    const fetchFiles = async (page: number = 1, append: boolean = false): Promise<void> => {
        if (append) {
            loadingMore.value = true;
        } else {
            loadingFiles.value = true;
            files.value = [];
        }

        error.value = null;

        try {
            const response = await apiClient.get(`/platform/sales/cotizacion_files?page=${page}&order[createdAt]=desc`);
            const rawData = response.data;
            const newFiles = rawData['hydra:member'] || rawData['member'] || [];

            if (append) {
                files.value.push(...newFiles);
            } else {
                files.value = newFiles;
            }

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

    const createFile = async (payload: ApiCotizacionFileWrite): Promise<ApiCotizacionFile | null> => {
        loadingFiles.value = true;
        error.value = null;

        try {
            const response = await apiClient.post<ApiCotizacionFile>('/platform/sales/cotizacion_files', payload);
            files.value.unshift(response.data);
            return response.data;
        } catch (err: any) {
            error.value = err.response?.data?.['hydra:description'] || err.response?.data?.detail || 'Error al crear el expediente.';
            return null;
        } finally {
            loadingFiles.value = false;
        }
    };

    // ============================================================================
    // 🔥 ACCIONES DE PASAJEROS Y BÓVEDA DIGITAL
    // ============================================================================

    /**
     * Sube un documento físico a la bóveda del expediente usando Multipart
     */
    const uploadDocument = async (formData: FormData): Promise<boolean> => {
        error.value = null;
        try {
            await apiClient.post('/platform/sales/cotizacion_filedocumentos', formData, {
                headers: { 'Content-Type': 'multipart/form-data' }
            });
            return true;
        } catch (err: any) {
            error.value = err.response?.data?.['hydra:description'] || 'Error al subir el documento.';
            return false;
        }
    };

    const deleteDocument = async (iri: string): Promise<boolean> => {
        try {
            await apiClient.delete(iri);
            return true;
        } catch (err) {
            return false;
        }
    };

    const addPassenger = async (payload: any): Promise<boolean> => {
        error.value = null;
        try {
            await apiClient.post('/platform/sales/cotizacion_filepasajeros', payload);
            return true;
        } catch (err: any) {
            error.value = err.response?.data?.['hydra:description'] || 'Error al registrar el pasajero.';
            return false;
        }
    };

    const deletePassenger = async (iri: string): Promise<boolean> => {
        try {
            await apiClient.delete(iri);
            return true;
        } catch (err) {
            return false;
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
        createFile,
        uploadDocument,
        deleteDocument,
        addPassenger,
        deletePassenger
    };
});