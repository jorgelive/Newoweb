// src/types/paxHuespedModel.ts
// ============================================================================
// Tipos del módulo PAX / Huésped.
//
// Los tipos con schema en api.d.ts se anclan con components['schemas'][...],
// extendiéndolos donde el schema OpenAPI es incompleto:
//  - PmsUnidad: api.d.ts solo expone `imageUrl`; en runtime también viene `id` y `nombre`.
//  - PmsReserva.eventosActivosGuia: api.d.ts lo declara string[] pero
//    el endpoint devuelve objetos PmsEventoCalendario embebidos.
//  - PmsGuia.titulo y secciones: api.d.ts los tipifica como string[] (columnas
//    JSON que API Platform no puede introspeccionar); en runtime son objetos.
//  - GuiaHelperContext: endpoint personalizado, sin schema en api.d.ts.
// ============================================================================

import type { components } from './api';

// --- Tipos de contenido traducible (columnas JSON, sin schema propio en api.d.ts) ---

/** Elemento de contenido multiidioma: { language, content } */
export interface PmsContenidoTraducible {
    language: string;
    content: string;
}

// --- PmsChannel: anclado a api.d.ts ---

export type PmsChannel = components['schemas']['PmsChannel-pax_reserva.read'];

// --- PmsUnidad: extiende api.d.ts con campos que el endpoint devuelve en runtime ---
// api.d.ts solo expone imageUrl en pax_evento.read; id y nombre también se serializan.

export type PmsUnidad = components['schemas']['PmsUnidad-pax_evento.read'] & {
    id?: string;
    nombre?: string;
};

// --- PmsEventoCalendario: no tiene schema en api.d.ts; el endpoint los embebe como objetos ---

export interface PmsEventoEstado {
    nombre: string;
}

export interface PmsEventoCalendario {
    '@type'?: string;
    '@id'?: string;
    id: string;
    pmsUnidad: PmsUnidad;
    estado?: PmsEventoEstado;
    reserva?: string;
    inicio: string;
    fin: string;
    cantidadAdultos: number;
    cantidadNinos: number;
}

// --- PmsReserva: ancla al schema de api.d.ts pero sobreescribe eventosActivosGuia ---
// api.d.ts declara eventosActivosGuia como string[]; en runtime son objetos embebidos.

export type PmsReserva = Omit<components['schemas']['PmsReserva-pax_reserva.read'], 'eventosActivosGuia'> & {
    eventosActivosGuia?: PmsEventoCalendario[];
};

// --- Guía del huésped: secciones e items (no son schemas separados en api.d.ts) ---

export interface PmsGuiaItemGaleria {
    descripcion?: PmsContenidoTraducible[];
    imageUrl: string;
}

export interface PmsGuiaItem {
    '@type'?: string;
    '@id'?: string;
    tipo: 'card' | 'album' | 'alert' | string;
    titulo: PmsContenidoTraducible[];
    descripcion?: PmsContenidoTraducible[];
    icono?: string | null;
    labelBoton?: PmsContenidoTraducible[];
    urlBoton?: string;
    galeria: PmsGuiaItemGaleria[];
}

export type PmsGuiaSeccionTipo = 'ingreso' | 'descriptivo' | 'normas';

export interface PmsGuiaSeccion {
    '@type'?: string;
    '@id'?: string;
    id: string;
    icono: string;
    tipo?: PmsGuiaSeccionTipo | null;
    titulo: PmsContenidoTraducible[];
    subtitulo: PmsContenidoTraducible[];
    items: PmsGuiaItem[];
}

// PmsGuia: ancla al schema de api.d.ts corrigiendo los campos JSON no introspectables.
// Se sobreescribe también `unidad` para usar PmsUnidad extendido con id y nombre.

export type PmsGuia = Omit<components['schemas']['PmsGuia-pax_evento.read'], 'titulo' | 'secciones' | 'unidad'> & {
    unidad?: PmsUnidad;
    titulo: PmsContenidoTraducible[];
    secciones: PmsGuiaSeccion[];
};

// --- GuiaHelperContext: endpoint personalizado, sin schema en api.d.ts ---

export interface GuiaHelperContext {
    data: {
        text_fixed: {
            guest_name?: string;
            unit_name?: string;
            booking_ref?: string;
            [key: string]: string | undefined;
        };
        text_translatable: {
            status_msg?: PmsContenidoTraducible[];
            [key: string]: PmsContenidoTraducible[] | undefined;
        };
        widgets: {
            wifi_data?: Array<{
                ssid: string;
                password: string;
                ubicacion: PmsContenidoTraducible[] | string;
                is_locked?: boolean;
            }>;
            [key: string]: any;
        };
        config: {
            mode: 'guest' | 'demo';
            access_status: 'active' | 'pending' | 'expired' | 'unconfirmed' | 'demo';
            is_locked: boolean;
            unit_uuid: string;
            [key: string]: any;
        };
    }
}
