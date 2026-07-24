// src/types/operacionModel.ts
// ============================================================================
// Tipos del módulo de Operaciones anclados a api.d.ts.
//
// Endpoints (routePrefix: '/ops'):
//   /platform/ops/operacion_orden_servicios
//   /platform/ops/operacion_servicios      (#[ApiFilter] by ordenServicio, file)
//   /platform/ops/operacion_mensajes       (#[ApiFilter] by ordenServicio)
//
// Notas de diseño:
//  - MaestroMoneda-operacion.write = Record<string,never> en el schema porque
//    la entidad no expone campos en operacion:write; en runtime se pasa IRI string.
//  - CotizacionFile/Cottarifa/Cotservicio/Cotcomponente embebidos en lectura son
//    stubs (solo createdAt/updatedAt + @id JSON-LD); los campos útiles vienen
//    del @id IRI, no del objeto embebido.
//  - operacionServicios dentro de OperacionOrdenServicio son stubs vacíos en
//    operacion:read (campos de OperacionServicio usan operacion:item:read);
//    usar cargarServiciosPorOrden() para datos completos.
// ============================================================================

import { components } from '@/types/api';
import { EstadoUIConfig } from '@/types/cotizacionEditorModel';

// ============================================================================
// TIPOS ANCLADOS A LOS SCHEMAS
// ============================================================================

export type OperacionOrdenServicio = components['schemas']['OperacionOrdenServicio-operacion.read_timestamp.read'];
export type OperacionServicio      = components['schemas']['OperacionServicio-operacion.item.read_timestamp.read'];
export type OperacionMensaje       = components['schemas']['OperacionMensaje-operacion.mensaje.read_timestamp.read'];

// MaestroMoneda con los campos que expone en contexto de operacion
export type MaestroMonedaOperacion = components['schemas']['Moneda-operacion.read_timestamp.read'];

// ============================================================================
// TIPOS DE ESCRITURA
// Los schemas de write tienen moneda como Record<string,never> porque la entidad
// no expone campos en operacion:write; en la práctica se envía IRI string.
// Se sobreescriben esos campos con string y se normaliza ordenServicio a IRI.
// ============================================================================

export type OperacionOrdenServicioWrite = Omit<
    components['schemas']['OperacionOrdenServicio-operacion.write'],
    'monedaOs'
> & {
    monedaOs: string;   // IRI, e.g. '/platform/maestro/maestro_monedas/PEN'
};

export type OperacionServicioWrite = Omit<
    components['schemas']['OperacionServicio-operacion.write'],
    'ordenServicio' | 'monedaCotizada' | 'monedaReal'
> & {
    ordenServicio?: string | null;  // IRI OperacionOrdenServicio
    monedaCotizada: string;         // IRI MaestroMoneda
    monedaReal: string;             // IRI MaestroMoneda
};

export type OperacionMensajeWrite = Omit<
    components['schemas']['OperacionMensaje-operacion.write'],
    'ordenServicio'
> & {
    ordenServicio: string;          // IRI OperacionOrdenServicio
};

// ============================================================================
// TIPOS DE ENUM (derivados del schema para mantenerse sincronizados)
// ============================================================================

export type EstadoOsValue       = OperacionOrdenServicio['estadoOs'];
export type EstadoReservaValue  = OperacionServicio['estadoReserva'];
export type EstadoOperacionValue = OperacionServicio['estadoOperacion'];

// ============================================================================
// CONFIGS UI
// ============================================================================

export const ESTADO_OS_CONFIG: Record<EstadoOsValue, EstadoUIConfig> = {
    borrador:   { label: 'Borrador',   bg: 'bg-slate-100',  text: 'text-slate-500',   border: 'border-slate-200',   icon: 'fa-pencil' },
    emitida:    { label: 'Emitida',    bg: 'bg-amber-50',   text: 'text-amber-700',   border: 'border-amber-200',   icon: 'fa-paper-plane' },
    confirmada: { label: 'Confirmada', bg: 'bg-emerald-50', text: 'text-emerald-700', border: 'border-emerald-200', icon: 'fa-check' },
    completada: { label: 'Completada', bg: 'bg-blue-50',    text: 'text-blue-700',    border: 'border-blue-200',    icon: 'fa-flag-checkered' },
    cancelada:  { label: 'Cancelada',  bg: 'bg-rose-50',    text: 'text-rose-700',    border: 'border-rose-200',    icon: 'fa-times-circle' },
};

export const ESTADO_RESERVA_CONFIG: Record<EstadoReservaValue, EstadoUIConfig> = {
    'sin-solicitar':  { label: 'Sin Solicitar',  bg: 'bg-slate-100',  text: 'text-slate-500',   border: 'border-slate-200',   icon: 'fa-circle-minus' },
    'solicitado':     { label: 'Solicitado',     bg: 'bg-amber-50',   text: 'text-amber-700',   border: 'border-amber-200',   icon: 'fa-paper-plane' },
    'confirmado':     { label: 'Confirmado',     bg: 'bg-emerald-50', text: 'text-emerald-700', border: 'border-emerald-200', icon: 'fa-check' },
    'reconfirmado':   { label: 'Reconfirmado',   bg: 'bg-teal-50',    text: 'text-teal-700',    border: 'border-teal-200',    icon: 'fa-check-double' },
    'pendiente-pago': { label: 'Pendiente Pago', bg: 'bg-red-50',     text: 'text-red-700',     border: 'border-red-200',     icon: 'fa-money-bill-wave' },
};

export const ESTADO_OPERACION_CONFIG: Record<EstadoOperacionValue, EstadoUIConfig> = {
    'pendiente':  { label: 'Pendiente',  bg: 'bg-amber-50',   text: 'text-amber-700',   border: 'border-amber-200',   icon: 'fa-clock' },
    'en-proceso': { label: 'En Proceso', bg: 'bg-sky-50',     text: 'text-sky-700',     border: 'border-sky-200',     icon: 'fa-gears' },
    'completado': { label: 'Completado', bg: 'bg-emerald-50', text: 'text-emerald-700', border: 'border-emerald-200', icon: 'fa-check' },
    'cancelado':  { label: 'Cancelado',  bg: 'bg-rose-50',    text: 'text-rose-700',    border: 'border-rose-200',    icon: 'fa-times-circle' },
};

export const getEstadoOsConfig = (v?: string | null): EstadoUIConfig =>
    ESTADO_OS_CONFIG[(v as EstadoOsValue) || 'borrador'] ?? ESTADO_OS_CONFIG.borrador;

export const getEstadoReservaConfig = (v?: string | null): EstadoUIConfig =>
    ESTADO_RESERVA_CONFIG[(v as EstadoReservaValue) || 'sin-solicitar'] ?? ESTADO_RESERVA_CONFIG['sin-solicitar'];

export const getEstadoOperacionConfig = (v?: string | null): EstadoUIConfig =>
    ESTADO_OPERACION_CONFIG[(v as EstadoOperacionValue) || 'pendiente'] ?? ESTADO_OPERACION_CONFIG.pendiente;
