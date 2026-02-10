// src/types/maestros.ts

export interface MaestroIdioma {
    '@id'?: string;    // "/api/maestro_idiomas/es" (Viene de JSON-LD)
    id: string;        // "es"
    nombre: string;    // "Español"
    bandera?: string;  // "🇪🇸"
    prioridad: number; // 100
}