import { defineStore } from 'pinia';
import { ref, computed } from 'vue';
import { apiClient } from '@/services/apiClient';
import {ApiCotizacionFile, ApiCotizacionFileWrite} from '@/types/fileDetalleModel.ts';

// ============================================================================
// TIPOS AUTOGENERADOS Y EXTENDIDOS (HÍBRIDOS)
// ============================================================================


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

    const updateFile = async (iri: string, payload: Partial<ApiCotizacionFileWrite>): Promise<ApiCotizacionFile | null> => {
        loadingFiles.value = true;
        error.value = null;

        try {
            // Ya no necesitas pasar los headers manualmente, el interceptor los pone
            const response = await apiClient.patch<ApiCotizacionFile>(iri, payload);

            const index = files.value.findIndex(f => f['@id'] === iri || f.id === iri);
            if (index !== -1) {
                files.value[index] = { ...files.value[index], ...response.data };
            }
            return response.data;
        } catch (err: any) {
            // El interceptor ya maneja los errores globales, aquí solo gestionamos la UI
            error.value = err.response?.data?.['hydra:description'] || 'Error al actualizar.';
            return null;
        } finally {
            loadingFiles.value = false;
        }
    };

    // ============================================================================
    // ACCIONES DE PASAJEROS Y BÓVEDA DIGITAL
    // ============================================================================

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
        updateFile,
        uploadDocument,
        deleteDocument,
        addPassenger,
        deletePassenger
    };
});