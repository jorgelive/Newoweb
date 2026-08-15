import { components } from '@/types/api';

/**
 * Interfaz base para elementos maestros.
 */

// Tipo exacto para País, usando su esquema documentado
export type ApiPais = components['schemas']['Pais-file.read_timestamp.read'] & {
    '@id'?: string;
};

// Tipo exacto para Idioma. Si ya contiene 'nombre' y 'prioridad', se hereda directamente.
//
// Era `Idioma-file.read_timestamp.read`, un grupo que el recurso ya no expone: el
// esquema desapareció del contrato y el tipo llevaba tiempo apuntando al vacío sin que
// nadie se enterase, porque api.d.ts estaba desfasado. `pax.read` es el que trae los
// cuatro campos que se usan aquí: id, nombre, bandera y prioridad.
export type ApiIdioma = components['schemas']['Idioma-pax.read'] & {
    '@id'?: string;
};
