

// src/services/paxService.ts  (fragmento a AGREGAR al servicio existente)
// ============================================================================
// Endpoints públicos de la vista cliente de Cotización (por localizador).
// baseURL del apiClient debe incluir /platform.
// ============================================================================
import {apiClient} from '@/services/apiClient'; // ajusta al cliente axios que ya uses
import type { PaxCotizacionFile } from '@/types/paxCotizacionModel';

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
};



