// src/stores/maestroStore.ts
import { defineStore } from 'pinia';
import { ref } from 'vue';
import { apiClient } from '@/services/apiClient';

/**
 * Interfaz base para elementos maestros.
 */
export interface ApiMaestro {
    '@id'?: string;
    id: string;
    nombre: string;
    bandera?: string | null;
    prioridad?: number;
}

export const useMaestroStore = defineStore('maestroStore', () => {
    // ============================================================================
    // ESTADOS
    // ============================================================================
    const paises = ref<ApiMaestro[]>([]);
    const idiomas = ref<ApiMaestro[]>([]);
    const isLoading = ref<boolean>(false);
    const error = ref<string | null>(null);

    // ============================================================================
    // ACCIONES
    // ============================================================================

    /**
     * Carga de manera concurrente los catálogos de Países e Idiomas desde el API.
     * Utiliza validación de estado para no repetir llamadas si los datos ya residen en memoria.
     * Implementa la doble validación de colecciones Hydra (API Platform v2/v3).
     *
     * @returns {Promise<void>}
     */
    const fetchMaestros = async (): Promise<void> => {
        // Evitamos peticiones redundantes si ya tenemos la caché en memoria
        if (paises.value.length > 0 && idiomas.value.length > 0) return;

        isLoading.value = true;
        error.value = null;

        try {
            // Ejecución de promesas en paralelo para optimizar la carga de red
            const [paisesRes, idiomasRes] = await Promise.all([
                apiClient.get('/platform/public/maestro_pais'),
                apiClient.get('/platform/public/maestro_idioma')
            ]);

            paises.value = paisesRes.data['hydra:member'] || paisesRes.data['member'] || [];
            idiomas.value = idiomasRes.data['hydra:member'] || idiomasRes.data['member'] || [];

            // Ordenamiento por prioridad del idioma (por seguridad de visualización)
            idiomas.value.sort((a, b) => (b.prioridad ?? 0) - (a.prioridad ?? 0));

        } catch (err: any) {
            console.error('Error cargando tablas maestras:', err);
            error.value = 'No se pudieron cargar los catálogos del sistema.';
        } finally {
            isLoading.value = false;
        }
    };

    return {
        paises,
        idiomas,
        isLoading,
        error,
        fetchMaestros
    };
});