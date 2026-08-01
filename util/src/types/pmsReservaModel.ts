// src/types/pmsReservaModel.ts
// ============================================================================
// Tipos del módulo de Reservas PMS (calendario SPA), anclados a api.d.ts.
//
// Endpoints (routePrefix: '/pms'):
//   GET|PATCH /platform/pms/pms_reservas/{id}            (cliente/datos de la reserva)
//   GET|POST|PATCH /platform/pms/pms_evento_calendarios   (estancia/bloqueo individual)
//   GET (collection) /platform/pms/pms_unidads             (selector de unidad)
//   GET (collection) /platform/pms/pms_evento_estados      (selector de estado)
//   GET (collection) /platform/pms/pms_evento_estado_pagos (selector de estado de pago)
//   GET (collection) /platform/pms/pms_channels            (selector de canal)
//
// Endpoints de solo-fetch del calendario (no son ApiResource, son el provider
// "Spa" de Calendar — ver docs/CALENDAR_ARCHITECTURE.md):
//   GET /fullcalendar/load/event/pms_eventos_no_cancelados_spa
//   GET /fullcalendar/load/event/pms_eventos_todos_spa
//   GET /fullcalendar/load/resource/pms_eventos_*_spa
//
// Notas de diseño:
//  - Los campos IRI (pmsUnidad, channel, estado, estadoPago, pais, idioma) ya
//    vienen tipados como `string` en los schemas de escritura porque API
//    Platform los infiere como format:iri-reference — no hace falta Omit/override.
//  - Las relaciones anidadas en lectura (pmsUnidad, estado, estadoPago, channel,
//    pais, idioma) sí exponen id/nombre/color: se agregaron los grupos de
//    serialización correspondientes en el backend para que vengan pobladas.
// ============================================================================

import { components } from '@/types/api';

// ============================================================================
// TIPOS DE LECTURA
// ============================================================================

export type PmsEventoCalendario = components['schemas']['PmsEventoCalendario-pms_evento.read_timestamp.read'];
export type PmsReserva = components['schemas']['PmsReserva-pms_reserva.read_timestamp.read'];

export type PmsUnidadOption = components['schemas']['PmsUnidad-pms_unidad.read'];
export type PmsEventoEstadoOption = components['schemas']['PmsEventoEstado-pms_evento_estado.read'];
/**
 * `colorOverride` todavía no está en el schema generado (se agregó el grupo
 * de serialización en el backend pero falta regenerar api.d.ts).
 */
export type PmsEventoEstadoPagoOption = components['schemas']['PmsEventoEstadoPago-pms_evento_estado_pago.read'] & { colorOverride?: boolean };
export type PmsChannelOption = components['schemas']['PmsChannel-pms_channel.read'];

// ============================================================================
// COLOR DE ESTADO (espejo de PmsEventosSpaCalendarProvider::resolveColor() en PHP)
// El color final de una estancia combina el estado y el estado de pago: si el
// estado de pago tiene `colorOverride` activo, su color manda sobre el del
// estado. Usado tanto en la barra de cada evento del calendario (backend) como
// en la cabecera de cada acordeón del drawer y los indicadores de los selects.
// ============================================================================

/** Color hexadecimal por defecto cuando el estado/estado de pago no tiene uno configurado. */
export const COLOR_ESTADO_FALLBACK = '#94A3B8';

export function resolveEventoColor(
    estado?: { color?: string | null } | null,
    estadoPago?: { color?: string | null; colorOverride?: boolean } | null,
): string {
    if (estadoPago?.colorOverride && estadoPago.color) return estadoPago.color;
    return estado?.color || COLOR_ESTADO_FALLBACK;
}

/** Texto blanco o gris oscuro, el que mejor contraste dé sobre `hexColor`. */
export function contrastText(hexColor: string): string {
    const hex = hexColor.replace('#', '');
    if (hex.length !== 6) return '#1e293b';
    const r = parseInt(hex.substring(0, 2), 16);
    const g = parseInt(hex.substring(2, 4), 16);
    const b = parseInt(hex.substring(4, 6), 16);
    const luminance = (0.299 * r + 0.587 * g + 0.114 * b) / 255;
    return luminance > 0.6 ? '#1e293b' : '#ffffff';
}

// ============================================================================
// TIPOS DE ESCRITURA
// ============================================================================

/**
 * PATCH /pms_evento_calendarios/{id} — todos los campos opcionales.
 * `Partial<>` porque el schema generado marca monto/comision como requeridos
 * (tienen @default en OpenAPI); en un merge-patch real cualquier subset es válido.
 */
export type PmsEventoCalendarioPatch = Partial<components['schemas']['PmsEventoCalendario-pms_evento.write.jsonMergePatch']>;

/**
 * POST /pms_evento_calendarios — crear un bloqueo manual, o una estancia más
 * (`reserva` IRI) en otra casita para una reserva ya existente. `reserva` no
 * está en el schema generado todavía (grupo pms_evento:write_create, ver
 * backend); solo es válido en creación, nunca en el PATCH.
 */
export type PmsEventoCalendarioCreate = components['schemas']['PmsEventoCalendario-pms_evento.write'] & { reserva?: string };

/** PATCH /pms_reservas/{id} — todos los campos opcionales. */
export type PmsReservaPatch = Partial<components['schemas']['PmsReserva-pms_reserva.write.jsonMergePatch']>;

/**
 * POST /pms_reservas — "Reserva completa" (titular + estancia inicial) creada en un
 * único paso desde el calendario SPA. Ver PmsReservaCrearInput/PmsReservaCrearProcessor
 * en el backend. No incluye `channel`: el processor siempre fuerza canal directo.
 * (Tipado a mano porque este endpoint usa un Input DTO propio, no reflejado aún en
 * la introspección OpenAPI de api.d.ts.)
 */
export interface PmsReservaCrearPayload {
    nombreCliente: string;
    apellidoCliente?: string | null;
    telefono?: string | null;
    telefono2?: string | null;
    emailCliente?: string | null;
    /** IRI de MaestroPais. */
    pais?: string | null;
    /** IRI de MaestroIdioma; si se omite, el backend usa el idioma por defecto. */
    idioma?: string | null;
    nota?: string | null;
    /** IRI de PmsUnidad. */
    pmsUnidad: string;
    inicio: string;
    fin: string;
    cantidadAdultos: number;
    cantidadNinos: number;
    descripcion?: string | null;
    monto: string;
    comision: string;
}

// ============================================================================
// CONTEXTO DE EVENTO (extendedProps del feed FullCalendar SPA)
// Ver PmsEventosSpaCalendarProvider::buildContext() en el backend.
// ============================================================================

export interface PmsEventoExtendedProps {
    context: 'reserva' | 'bloqueo';
    eventoId: string;
    reservaId: string | null;
    isOta: boolean;
}

// ============================================================================
// CÓDIGOS NATURALES (IDs string, deben coincidir con las constantes PHP)
// ============================================================================

export const PMS_ESTADO = {
    PENDIENTE: 'pendiente',
    CONFIRMADA: 'confirmada',
    CANCELADA: 'cancelada',
    ABIERTO: 'abierto',
    REQUERIMIENTO: 'requerimiento',
    BLOQUEO: 'bloqueo',
} as const;

export const PMS_ESTADO_PAGO = {
    SIN_PAGO: 'no-pagado',
    PAGO_ALOJAMIENTO: 'pago-alojamiento',
    PAGO_PARCIAL: 'pago-parcial',
    PAGO_TOTAL: 'pago-total',
} as const;

export const PMS_CHANNEL = {
    DIRECTO: 'directo',
    AIRBNB: 'airbnb',
    VRBO: 'vrbo',
    BOOKING: 'booking',
} as const;

// ============================================================================
// HELPERS DE IRI
// API Platform espera IRIs completas en los campos de relación al escribir.
// ============================================================================

export const pmsUnidadIri = (id: string): string => `/platform/pms/pms_unidads/${id}`;
export const pmsReservaIri = (id: string): string => `/platform/pms/pms_reservas/${id}`;
export const pmsEventoEstadoIri = (id: string): string => `/platform/pms/pms_evento_estados/${id}`;
export const pmsEventoEstadoPagoIri = (id: string): string => `/platform/pms/pms_evento_estado_pagos/${id}`;
export const pmsChannelIri = (id: string): string => `/platform/pms/pms_channels/${id}`;
export const maestroPaisIri = (id: string): string => `/platform/maestro/pais/${id}`;
export const maestroIdiomaIri = (id: string): string => `/platform/maestro/idiomas/${id}`;

// ============================================================================
// WHATSAPP (GET /pms/reservas/{id}/whatsapp-link/{templateId})
// Reemplaza al viejo flujo de redirect de EasyAdmin: el backend solo resuelve
// el texto (variables reemplazadas) en JSON, y el frontend arma la URL de
// WhatsApp y hace window.open() — evita que un mensaje largo genere una
// cabecera `Location` de redirect que colapse en el servidor/proxy.
// ============================================================================
export interface PmsReservaWhatsappLink {
    telefono: string;
    texto: string;
}

/** Abre WhatsApp Web/App con el texto ya resuelto, sin pasar por un redirect del backend. */
export function abrirWhatsapp(telefono: string, texto: string): void {
    const url = `https://api.whatsapp.com/send/?phone=${encodeURIComponent(telefono)}&text=${encodeURIComponent(texto)}&type=phone_number&app_absent=0`;
    window.open(url, '_blank', 'noopener');
}

// ============================================================================
// HELPERS DE FECHA (ISO backend <-> input datetime-local del navegador)
// ============================================================================

/** ISO ("2025-03-01T14:00:00+00:00") -> valor para <input type="datetime-local"> ("2025-03-01T14:00"). */
export const toDatetimeLocal = (iso?: string | null): string => {
    if (!iso) return '';
    const d = new Date(iso);
    if (Number.isNaN(d.getTime())) return '';
    const pad = (n: number): string => String(n).padStart(2, '0');
    return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}`;
};

/** Valor de <input type="datetime-local"> ("2025-03-01T14:00") -> ISO para el backend. */
export const fromDatetimeLocal = (local: string): string => {
    const d = new Date(local);
    return d.toISOString();
};

// ============================================================================
// REGLAS OTA (espejo de PmsEventoCalendario::OTA_*_SELECCIONABLES en PHP)
// Usadas para restringir el select de estado cuando el evento es OTA.
// ============================================================================

export const OTA_ESTADOS_NO_SELECCIONABLES: readonly string[] = [
    PMS_ESTADO.CANCELADA,
    PMS_ESTADO.ABIERTO,
    PMS_ESTADO.BLOQUEO,
];

export const OTA_ABIERTO_ESTADOS_SELECCIONABLES: readonly string[] = [
    PMS_ESTADO.ABIERTO,
    PMS_ESTADO.CANCELADA,
];

/**
 * Filtra las opciones de estado disponibles para un evento, replicando
 * PmsEventoCalendarioSecurityListener: eventos OTA no pueden pasar a
 * cancelada/abierto/bloqueo (salvo abierto -> cancelada), y una vez
 * cancelada queda en estado terminal (no seleccionable ningún cambio).
 */
export function filtrarEstadosDisponibles(
    todos: PmsEventoEstadoOption[],
    isOta: boolean,
    estadoActualId?: string | null,
): PmsEventoEstadoOption[] {
    if (!isOta) return todos;

    if (estadoActualId === PMS_ESTADO.CANCELADA) {
        return todos.filter(e => e.id === PMS_ESTADO.CANCELADA);
    }

    if (estadoActualId === PMS_ESTADO.ABIERTO) {
        return todos.filter(e => e.id && OTA_ABIERTO_ESTADOS_SELECCIONABLES.includes(e.id));
    }

    return todos.filter(e => e.id && !OTA_ESTADOS_NO_SELECCIONABLES.includes(e.id));
}
