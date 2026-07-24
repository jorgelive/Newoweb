

// src/services/paxService.ts  (fragmento a AGREGAR al servicio existente)
// ============================================================================
// Endpoints públicos de la vista cliente de Cotización (por localizador).
// baseURL del apiClient debe incluir /platform.
// ============================================================================
import {apiClient} from '@/services/apiClient'; // ajusta al cliente axios que ya uses
import type { PaxCotizacionFile, PaxCatalogo } from '@/types/paxCotizacionModel';

export const paxCotizacionService = {

    /**
     * PORTADA: expediente con las cards de todas las propuestas públicas
     * vigentes (resumen i18n + precio). Sin árbol de servicios (liviano).
     *
     * @param {string} localizador Código localizador del expediente.
     * @returns {Promise<PaxCotizacionFile>}
     */
    async getFilePortada(localizador: string): Promise<PaxCotizacionFile> {
        const { data } = await apiClient.get<PaxCotizacionFile>(
            `/platform/sales/client/cotizacion/cotizacion_file/${encodeURIComponent(localizador)}`
        );
        return data;
    },

    /**
     * DETALLE: expediente + la cotización completa de una versión concreta
     * (itinerario, segmentos, inclusiones, etc.).
     *
     * @param {string} localizador Código localizador del expediente.
     * @param {number} version Número de versión de la propuesta.
     * @returns {Promise<PaxCotizacionFile>}
     */
    async getFileVersion(localizador: string, version: number): Promise<PaxCotizacionFile> {
        const { data } = await apiClient.get<PaxCotizacionFile>(
            `/platform/sales/client/cotizacion/cotizacion_file/${encodeURIComponent(localizador)}/${version}`
        );
        return data;
    },

    /**
     * PORTADA del catálogo de tours: cards de todos los tours públicos
     * (título, resumen, rangos "Desde", portada, días). Liviano.
     *
     * @param {string} localizador Código localizador del catálogo.
     * @returns {Promise<PaxCatalogo>}
     */
    async getCatalogoPortada(localizador: string): Promise<PaxCatalogo> {
        const { data } = await apiClient.get<PaxCatalogo>(
            `/platform/sales/client/cotizacion/cotizacion_catalogo/${encodeURIComponent(localizador)}`
        );
        return data;
    },

    /**
     * DETALLE del catálogo: catálogo + la cotización completa de un tour
     * (itinerario, segmentos, inclusiones, etc.).
     *
     * @param {string} localizador Código localizador del catálogo.
     * @param {number} version Número de tour dentro del catálogo.
     * @returns {Promise<PaxCatalogo>}
     */
    async getCatalogoVersion(localizador: string, version: number): Promise<PaxCatalogo> {
        const { data } = await apiClient.get<PaxCatalogo>(
            `/platform/sales/client/cotizacion/cotizacion_catalogo/${encodeURIComponent(localizador)}/${version}`
        );
        return data;
    },
};



