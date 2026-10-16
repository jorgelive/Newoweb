// src/types/maestroModel.ts
import type { components } from '@dominio/api';
import type { PmsContenidoTraducible } from './paxHuespedModel';

export interface MaestroIdioma {
    '@id'?: string;    // "/api/maestro_idiomas/es" (Viene de JSON-LD)
    id: string;        // "es"
    nombre: string;    // "Español"
    bandera?: string;  // "🇪🇸"
    prioridad: number; // 100
}
/**
 * Una moneda del maestro (`MaestroMoneda`), con su nombre para el cliente en cada idioma.
 *
 * Del esquema; sólo se estrecha `titulo`, que la introspección ve como diccionario abierto y es la
 * forma i18n de siempre (`{ language, content }[]`).
 */
export type MaestroMoneda = Omit<components['schemas']['Moneda-maestro.moneda.read'], 'titulo'> & {
    titulo: PmsContenidoTraducible[];
};
