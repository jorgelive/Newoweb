// ============================================================================
// CATÁLOGO DE PROVEEDORES
//
// Espejo de `App\Travel\Entity\Proveedor` y sus dos satélites. Vive aparte de
// `cotizacionEditorModel.ts` a propósito: aquí se ADMINISTRA el maestro, mientras que
// allí sólo se lee para congelar snapshots en la cotización.
//
// Vocabulario, que en este dominio no es intercambiable:
//   · PROVEEDOR  → a quién se le compra (identidad para el histórico financiero).
//   · PRESTADOR  → quién opera el servicio en destino.
// Suelen coincidir, pero no siempre; la UI los distingue con icono y color.
// ============================================================================

import type { components } from '@/types/api';

export interface I18nTexto {
    language: string;
    content: string;
}

/** Imagen de galería. `imageUrl` la inyecta el backend ya firmada (MediaTrait). */
export interface ProveedorImagen {
    id: string;
    '@id'?: string;
    imageUrl: string | null;
    orden: number;
    isPortada: boolean;
}

export interface ProveedorServicio {
    id: string;
    '@id'?: string;
    nombre: string | null;
    url?: string | null;
    titulo?: I18nTexto[];
    descripcion?: I18nTexto[];
}

/**
 * Los campos json de i18n salen del esquema generado como `string[]`; se redeclaran con
 * su forma real. Van en el `Omit` y no sólo redeclarados debajo: una intersección
 * `string[] & I18nTexto[]` compila pero produce un tipo inservible al usarlo.
 */
type ProveedorBase = components['schemas']['Proveedor-proveedor.read'];

export type Proveedor = Omit<ProveedorBase, 'titulo' | 'descripcion' | 'proveedorImagenes'> & {
    id: string;
    '@id'?: string;
    titulo?: I18nTexto[];
    descripcion?: I18nTexto[];
    proveedorImagenes?: ProveedorImagen[];
    proveedorServicios?: ProveedorServicio[];
    /** IRIs: viajan con `readableLink: false`. Se resuelven contra el vocabulario cargado. */
    lugares?: string[];
};

/** Payload de alta/edición. Sólo lo que el formulario toca. */
export interface ProveedorWrite {
    nombreComercial: string;
    razonSocial?: string | null;
    telefono?: string | null;
    email?: string | null;
    url?: string | null;
    direccion?: string | null;
    titulo?: I18nTexto[];
    /** IRIs de `TravelLugar`. Cobertura: hasta dónde opera este proveedor. */
    lugares?: string[];
}

/** Opción del maestro de lugares, para los selectores. */
export interface LugarOpcion {
    id: string;
    '@id'?: string;
    nombre: string;
}

export const proveedorVacio = (): ProveedorWrite => ({
    nombreComercial: '',
    razonSocial: '',
    telefono: '',
    email: '',
    url: '',
    direccion: '',
    titulo: [],
    lugares: [],
});

// ============================================================================
// VISIBILIDAD AL CLIENTE — SIN FLAGS
//
// Lo único que llega a la propuesta del pasajero es el TÍTULO traducible; el nombre
// comercial, el teléfono y la dirección no llevan el grupo `pax_cotizacion:read` y no
// salen de la capa interna (ver CotizacionCotcomponentePrestadorPublicNormalizer).
//
// De ahí la regla, que evita tener que mantener un booleano más: **si no tiene título,
// el cliente no lo ve**. Y en cascada — un servicio sin título no se muestra aunque su
// proveedor sí lo tenga.
// ============================================================================

export const AYUDA_TITULO_PUBLICO =
    'Es lo ÚNICO que ve el cliente en la propuesta. Si lo dejas vacío, este proveedor no '
    + 'aparece: la ausencia de título es la forma de ocultarlo, no hace falta ninguna casilla.';

export const AYUDA_TITULO_SERVICIO =
    'Mismo criterio que el proveedor: sin título, el servicio no se le muestra al cliente. '
    + 'Y si el proveedor tiene título pero el servicio no, tampoco se muestra el servicio.';

/** Texto en español del json i18n, que es el idioma base del que parte AutoTranslate. */
export const tituloEs = (titulo?: I18nTexto[]): string =>
    (titulo ?? []).find((t) => t.language === 'es')?.content ?? '';

/** Construye el json i18n desde el campo del formulario. Vacío = sin título = oculto. */
export const desdeTituloEs = (valor: string): I18nTexto[] =>
    valor.trim() ? [{ language: 'es', content: valor.trim() }] : [];

/** Portada de la galería, o la primera que haya. Para la miniatura del listado. */
export const portadaDe = (p: Proveedor): string | null => {
    const imagenes = p.proveedorImagenes ?? [];
    if (!imagenes.length) return null;

    return (imagenes.find((i) => i.isPortada) ?? imagenes[0]).imageUrl;
};

export const AYUDA_LUGARES_PROVEEDOR =
    'Cobertura: marca TODOS los centros desde los que opera. Un operador de Lima que '
    + 'también despacha Ica lleva las dos.';
