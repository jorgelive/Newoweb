// src/types/pms.ts

// --- COMUNES ---
export interface PmsContenidoTraducible {
    language: string;
    content: string;
}

// --- MAESTROS ---
export interface MaestroPais {
    "@type"?: string;
    "@id"?: string;
    id: string;
    nombre: string;
}

export interface MaestroIdioma {
    "@type"?: string;
    "@id"?: string;
    id: string;
    nombre: string;
}

export interface PmsChannel {
    "@type"?: string;
    "@id"?: string;
    id: string;
    nombre: string;
}

export interface PmsEventoEstado {
    "@type"?: string;
    "@id"?: string;
    nombre: string;
}

// --- CORE ---
export interface PmsUnidad {
    "@type"?: string;
    "@id"?: string;
    nombre: string;
    id: string;
    imageUrl: string;
}

export interface PmsEventoCalendario {
    "@type"?: string;
    "@id": string;
    id: string;
    pmsUnidad: PmsUnidad;
    estado: PmsEventoEstado;
    reserva?: string;
    inicio: string;
    fin: string;
    cantidadAdultos: number;
    cantidadNinos: number;
}

export interface PmsReserva {
    "@context"?: string;
    "@id": string;
    "@type": string;
    id: string;
    localizador: string;
    nombreCliente: string;
    apellidoCliente: string;
    nombreCompleto: string;
    telefono: string;
    emailCliente: string;
    pais: MaestroPais;
    idioma: MaestroIdioma;
    channel: PmsChannel;
    nombreHotel: string;
    nombreHabitacion: string;
    fechaLlegada: string;
    fechaSalida: string;
    numeroNoches: number;
    cantidadAdultos: number;
    cantidadNinos: number;
    paxTotal: number;
    eventosActivosGuia: PmsEventoCalendario[];
}

// --- GUÍA ---
export interface PmsGuiaItemGaleria {
    "@type"?: string;
    "@id"?: string;
    descripcion?: PmsContenidoTraducible[];
    imageUrl: string;
}

export interface PmsGuiaItem {
    "@type"?: string;
    "@id": string;
    tipo: 'card' | 'album' | 'alert' | string;
    titulo: PmsContenidoTraducible[];
    descripcion?: PmsContenidoTraducible[];
    labelBoton?: PmsContenidoTraducible[];
    urlBoton?: string;
    galeria: PmsGuiaItemGaleria[];
}

export interface PmsGuiaSeccion {
    "@type"?: string;
    "@id": string;
    id: string;
    icono: string;
    titulo: PmsContenidoTraducible[];
    items: PmsGuiaItem[];
}

export interface PmsGuia {
    "@context"?: string;
    "@id": string;
    "@type": string;
    unidad: PmsUnidad;
    activo: boolean;
    titulo: PmsContenidoTraducible[];
    secciones: PmsGuiaSeccion[];
}

// --- HELPER CONTEXT (ESTRUCTURA SEGURA) ---
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
            // 🔥 Añadido 'unconfirmed' al tipo
            access_status: 'active' | 'pending' | 'expired' | 'unconfirmed' | 'demo';
            is_locked: boolean;
            unit_uuid: string;
            [key: string]: any;
        };
    }
}