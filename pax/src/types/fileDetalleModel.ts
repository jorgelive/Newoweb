/**
 * Modelo para el expediente raíz (File) visto por el cliente.
 */
export interface ApiCotizacionFile {
    id?: string;
    localizador?: string | null;
    nombreGrupo?: string | null;
    pasajeroPrincipal?: string | null;
    cotizaciones?: {
        id: string;
        version: number;
        '@id'?: string;
    }[];
}

export interface CotizacionResumen {
    id: string;
    version: number;
    '@id'?: string;
}