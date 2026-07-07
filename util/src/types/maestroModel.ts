import { components } from '@/types/api';

/**
 * Interfaz base para elementos maestros.
 */

// Tipo exacto para País, usando su esquema documentado
export type ApiPais = components['schemas']['Pais-file.read_timestamp.read'] & {
    '@id'?: string;
};

// Tipo exacto para Idioma. Si ya contiene 'nombre' y 'prioridad', se hereda directamente
export type ApiIdioma = components['schemas']['Idioma-file.read_timestamp.read'] & {
    '@id'?: string;
};
