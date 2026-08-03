import { defineStore } from 'pinia';
import { ref, computed } from 'vue';
import { apiClient } from '@/services/apiClient';
import { extractApiErrorMessage, esErrorSilencioso } from '@/services/apiError';
import {ApiCotizacionFile, ApiCotizacionFileWrite, I18nContent} from '@/types/fileDetalleModel.ts';

// ============================================================================
// TIPOS AUTOGENERADOS Y EXTENDIDOS (HÍBRIDOS)
// ============================================================================

/**
 * Datos de un pasajero del manifiesto tal como los manda el formulario. `file`
 * (la IRI del expediente) solo viaja al crear: en la edición el destino ya lo
 * fija la IRI del propio pasajero.
 */
export interface PasajeroPayload {
    nombre?: string;
    apellido?: string;
    pais?: string;
    sexo?: string;
    tipodocumento?: string;
    numerodocumento?: string;
    fechanacimiento?: string;
    file?: string;
}

export interface ApiIdioma {
    id: string;         // código de idioma: 'es', 'en', 'pt'...
    nombre: string;
    bandera?: string;
    prioridad?: number;
}

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
    const searchTerm = ref<string>('');

    // Idiomas disponibles para revisar traducciones (AutoTranslate)
    const idiomasDisponibles = ref<ApiIdioma[]>([]);

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
            const nombre = searchTerm.value.trim();
            const query = `/platform/sales/cotizacion_files?page=${page}&order[createdAt]=desc`
                + (nombre ? `&nombre=${encodeURIComponent(nombre)}` : '');
            const response = await apiClient.get(query);
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

        } catch (err: unknown) {
            if (!esErrorSilencioso(err)) {
                error.value = extractApiErrorMessage(err, 'Error de red al cargar los expedientes.');
            }
        } finally {
            loadingFiles.value = false;
            loadingMore.value = false;
        }
    };

    /**
     * Aplica el término de búsqueda (nombre de grupo o pasajero principal) y
     * recarga desde la página 1.
     */
    const setSearchTerm = async (term: string): Promise<void> => {
        searchTerm.value = term;
        await fetchFiles(1);
    };

    /**
     * Carga los idiomas activos (prioridad > 0) ordenados por prioridad desc.
     * Usado para el selector de idioma que revisa el contenido AutoTranslate.
     */
    const fetchIdiomas = async (): Promise<void> => {
        try {
            const response = await apiClient.get('/platform/maestro/idiomas?prioridad[gt]=0&order[prioridad]=desc');
            idiomasDisponibles.value = response.data['hydra:member'] || response.data['member'] || [];
        } catch (e) {
            idiomasDisponibles.value = [{ id: 'es', nombre: 'Español', bandera: '🇪🇸', prioridad: 1 }];
        }
    };

    /**
     * Solicita la clonación profunda de una cotización al servidor.
     * Utiliza el endpoint custom de API Platform que ejecuta la lógica en base de datos.
     *
     * @param iriOrId El UUID o IRI de la cotización a clonar.
     * @returns {Promise<boolean>} true si se clonó con éxito, false en caso de error.
     */
    const cloneCotizacion = async (iriOrId: string): Promise<boolean> => {
        error.value = null;
        const id = String(iriOrId).includes('/') ? String(iriOrId).split('/').pop() : iriOrId;

        try {
            // Se envía un body vacío {}. El interceptor pondrá application/ld+json
            // pero Symfony lo ignorará de forma segura gracias a 'deserialize: false'.
            await apiClient.post(`/platform/sales/client/cotizacion/${id}/clonar`, {});
            return true;
        } catch (err: unknown) {
            error.value = extractApiErrorMessage(err, 'Error al clonar la versión de la cotización.');
            return false;
        }
    };

    const createFile = async (payload: ApiCotizacionFileWrite): Promise<ApiCotizacionFile | null> => {
        loadingFiles.value = true;
        error.value = null;

        try {
            const response = await apiClient.post<ApiCotizacionFile>('/platform/sales/cotizacion_files', payload);
            files.value.unshift(response.data);
            return response.data;
        } catch (err: unknown) {
            error.value = extractApiErrorMessage(err, 'Error al crear el expediente.');
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
        } catch (err: unknown) {
            error.value = extractApiErrorMessage(err, 'Error al actualizar.');
            return null;
        } finally {
            loadingFiles.value = false;
        }
    };

    const deleteCotizacion = async (iri: string): Promise<boolean> => {
        try {
            await apiClient.delete(iri);
            return true;
        } catch (err) {
            return false;
        }
    };

    const deleteFile = async (iri: string): Promise<boolean> => {
        try {
            await apiClient.delete(iri);
            files.value = files.value.filter(f => f['@id'] !== iri && f.id !== iri);
            return true;
        } catch (err) {
            return false;
        }
    };

    const updateCotizacionVersion = async (iri: string, version: number): Promise<boolean> => {
        error.value = null;
        try {
            await apiClient.patch(iri, { version });
            return true;
        } catch (err: unknown) {
            error.value = extractApiErrorMessage(err, 'Error al actualizar la versión.');
            return false;
        }
    };

    /**
     * Extrae un preview truncado y sin HTML de un campo AutoTranslate (I18nContent[]).
     * Usado para previsualizar `resumen` en la tarjeta de versión sin abrir el motor.
     */
    const extraerResumenPreview = (resumen: I18nContent[] | null | undefined, idiomaPreferido = 'es', maxLen = 90): string => {
        if (!resumen || !Array.isArray(resumen) || resumen.length === 0) return '';

        const match = resumen.find((r) => r.language === idiomaPreferido) || resumen[0];
        const texto = match?.content || '';

        const sinHtml = texto.replace(/<[^>]*>/g, '').trim();
        return sinHtml.length > maxLen ? sinHtml.slice(0, maxLen) + '…' : sinHtml;
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
        } catch (err: unknown) {
            error.value = extractApiErrorMessage(err, 'Error al subir el documento.');
            return false;
        }
    };

    const updateDocument = async (
        iri: string,
        payload: { nombre?: I18nContent[] | null; tipodocumento: string; vencimiento: string | null; sobreescribirTraduccion?: boolean }
    ): Promise<boolean> => {
        error.value = null;
        try {
            await apiClient.patch(iri, payload);
            return true;
        } catch (err: unknown) {
            error.value = extractApiErrorMessage(err, 'Error al actualizar el documento.');
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

    const addPassenger = async (payload: PasajeroPayload): Promise<boolean> => {
        error.value = null;
        try {
            await apiClient.post('/platform/sales/cotizacion_filepasajeros', payload);
            return true;
        } catch (err: unknown) {
            error.value = extractApiErrorMessage(err, 'Error al registrar el pasajero.');
            return false;
        }
    };

    const updatePassenger = async (iri: string, payload: PasajeroPayload): Promise<boolean> => {
        error.value = null;
        try {
            await apiClient.patch(iri, payload);
            return true;
        } catch (err: unknown) {
            error.value = extractApiErrorMessage(err, 'Error al actualizar el pasajero.');
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
        searchTerm,
        idiomasDisponibles,
        getActiveFiles,
        fetchFiles,
        setSearchTerm,
        fetchIdiomas,
        createFile,
        updateFile,
        uploadDocument,
        deleteDocument,
        addPassenger,
        deletePassenger,
        deleteCotizacion,
        deleteFile,
        updateCotizacionVersion,
        extraerResumenPreview,
        updatePassenger,
        updateDocument,
        cloneCotizacion
    };
});