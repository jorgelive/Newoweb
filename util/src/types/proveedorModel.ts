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
 * Los campos json de i18n se redeclaran con su forma real. Van en el `Omit` y no sólo
 * redeclarados debajo: la intersección de las dos formas compila pero produce un tipo
 * inservible al usarlo.
 *
 * ⚠️ El motivo cambió, la necesidad no. El generador emitía `(string | null)[]`, que era
 * simplemente falso; hoy emite `{ [key: string]: string | null }[]`, que **se parece pero
 * no sirve**: una firma de índice abierta no garantiza que existan `language` ni
 * `content`, ni que sean `string` y no `null`. Comprobado quitando el `Omit` — el
 * compilador rechaza pasar el campo a cualquier helper tipado con `I18nTexto[]`.
 *
 * Es la misma clase de trampa que avisa CLAUDE.md: un tipo generado que se acerca a la
 * verdad engaña más que uno que se equivoca del todo, porque invita a retirar el arreglo.
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
    /** ¿Nombrable ante el cliente? Semilla, no veto — ver el bloque de visibilidad. */
    visibleParaCliente?: boolean;
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
    // Opt-in, igual que el default de la columna: nombrar a un proveedor que no tocaba
    // invita al cliente a saltarse la intermediación, y ése es el olvido caro.
    visibleParaCliente: false,
    lugares: [],
});

// ============================================================================
// VISIBILIDAD AL CLIENTE — LA BANDERA MANDA, EL TÍTULO APORTA EL TEXTO
//
// Lo único que llega a la propuesta del pasajero es el TÍTULO traducible; el nombre
// comercial, el teléfono y la dirección no llevan el grupo `pax_cotizacion:read` y no
// salen de la capa interna (ver CotizacionCotcomponentePrestadorPublicNormalizer).
//
// Aquí había una regla distinta —«si no tiene título, el cliente no lo ve»— vendida como
// un booleano menos que mantener. Se retiró porque deducir la visibilidad de la presencia
// de un dato tiene tres costes que no se ven al decidirlo:
//
//   · Ocultar era DESTRUCTIVO: el título es la única copia del texto, así que esconder a
//     un proveedor obligaba a borrarlo y volver a mostrarlo, a reescribirlo.
//   · Nadie había decidido el estado real: 93 de 98 proveedores estaban invisibles por no
//     tener título, no porque se quisiera ocultarlos.
//   · «Tiene título pero aquí no lo nombres» no tenía dónde escribirse.
//
// Ahora son dos cosas separadas: `visibleParaCliente` dice SI se puede nombrar, `titulo`
// dice CÓMO se le llama. Hacen falta las dos — marcar visible sin título no da nada que
// pintar, y el formulario avisa de ese hueco en vez de dejar una tarjeta vacía.
//
// ⚠️ Espejo de `Proveedor::puedeMostrarseAlCliente()` en PHP.
//
// ⚠️ La bandera del maestro es la SEMILLA que se copia al asignar el proveedor a una
// cotización, no un veto que se relea después: cambiarla aquí no altera propuestas ya
// emitidas. Quien decide en cada propuesta es la bandera del snapshot.
// ============================================================================

/** ¿Hay bandera puesta Y texto que enseñar? Espejo de `puedeMostrarseAlCliente()`. */
export const puedeMostrarseAlCliente = (p: Pick<Proveedor, 'visibleParaCliente' | 'titulo'>): boolean =>
    Boolean(p.visibleParaCliente) && (p.titulo ?? []).length > 0;

export const AYUDA_VISIBLE_PARA_CLIENTE =
    'Permite nombrar a este proveedor en las propuestas. Es el valor por defecto al '
    + 'asignarlo: cada cotización puede decidir lo contrario, y cambiarlo aquí NO altera '
    + 'las propuestas ya emitidas.';

export const AYUDA_TITULO_PUBLICO =
    'Es lo ÚNICO que ve el cliente en la propuesta. Ya no sirve para ocultar: para eso '
    + 'está la casilla de arriba. Sin título no hay nada que mostrar aunque esté marcada.';

export const AYUDA_TITULO_SERVICIO =
    'Mismo criterio que el proveedor: sin título, el servicio no se le muestra al cliente. '
    + 'Y si el proveedor no es nombrable, tampoco se muestra el servicio.';

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
