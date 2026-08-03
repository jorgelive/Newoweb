// src/types/paxCatalogoUnidadModel.ts
// ============================================================================
// Tipos del ESCAPARATE PÚBLICO de una unidad (`/casita/casita-1`).
//
// Espejo del grupo de serialización `pax_catalogo:read`. Ver en el backend:
//  - src/Api/Provider/Pms/PmsUnidadCatalogoProvider.php  (quién lo llena)
//  - src/Pms/Entity/PmsGuia.php::getSeccionesParaCliente()
//  - src/Pms/Enum/PmsGuiaVisibilidad.php                 (qué entra aquí)
//
// Se escriben a mano en vez de anclarlos a api.d.ts porque las columnas i18n
// son `json` y API Platform no las puede introspeccionar: el schema generado
// las declara `string[]`, que es justo el error que documenta la cabecera de
// paxHuespedModel.ts. Al declararlas aquí, el tipo dice la verdad.
//
// Lo que este modelo NO tiene, y es lo importante: ni códigos, ni contraseñas,
// ni contexto de huésped. El provider sirve exclusivamente ítems marcados como
// públicos y construye su contexto sin cargar credenciales.
// ============================================================================

import type { PmsContenidoTraducible } from './paxHuespedModel';

/** Foto de la galería de un ítem. */
export interface CatalogoFoto {
    imageUrl: string;
    descripcion?: PmsContenidoTraducible[];
}

/** Formato visual del ítem; mismo vocabulario que PmsGuiaItem::TIPO_*. */
export type CatalogoItemTipo = 'card' | 'album' | 'alert';

export interface CatalogoItem {
    '@id'?: string;
    tipo: CatalogoItemTipo;
    /** Ya interpolado por el backend; en el catálogo nunca trae placeholders de datos. */
    titulo: PmsContenidoTraducible[];
    descripcion: PmsContenidoTraducible[];
    icono?: string | null;
    labelBoton?: PmsContenidoTraducible[];
    urlBoton?: string | null;
    galeria: CatalogoFoto[];
    /** Siempre 'publico' en este endpoint; viaja para no tener que asumirlo. */
    visibilidad: 'publico' | 'privado' | 'llegada';
}

export interface CatalogoSeccion {
    '@id'?: string;
    id: string;
    icono?: string | null;
    tipo?: 'ingreso' | 'descriptivo' | 'normas' | null;
    titulo: PmsContenidoTraducible[];
    subtitulo: PmsContenidoTraducible[];
    /** Solo los ítems públicos; las secciones sin ninguno no llegan a viajar. */
    items: CatalogoItem[];
}

export interface CatalogoEstablecimiento {
    slug?: string | null;
    nombreComercial?: string | null;
    ciudad?: string | null;
    /** Contacto comercial para el CTA de consulta. Público por naturaleza. */
    telefonoPrincipal?: string | null;
}

export interface CatalogoUnidad {
    nombre?: string | null;
    slug?: string | null;
    imageUrl?: string | null;
    capacidad?: number | null;
    establecimiento?: CatalogoEstablecimiento | null;
}

/** Raíz de la respuesta: es una PmsGuia serializada con `pax_catalogo:read`. */
export interface CatalogoUnidadResponse {
    '@id'?: string;
    titulo: PmsContenidoTraducible[];
    unidad?: CatalogoUnidad;
    secciones: CatalogoSeccion[];
}
