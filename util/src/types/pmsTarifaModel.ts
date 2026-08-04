// src/types/pmsTarifaModel.ts
// ============================================================================
// Tipos del módulo de Tarifas PMS (calendario SPA), equivalente Vue del legacy
// PmsTarifaRangoCrudController de EasyAdmin.
//
// Endpoints REST (routePrefix: '/pms'):
//   GET (collection) /platform/pms/pms_tarifa_rangos
//   GET|PATCH|DELETE /platform/pms/pms_tarifa_rangos/{id}
//   POST             /platform/pms/pms_tarifa_rangos
//   POST             /platform/pms/pms_tarifa_rangos/generar-masivo  (Generar Masivo)
//
// Endpoints de solo-fetch del calendario (providers "Spa", ver
// docs/Calendar_architecture.md y config/services/services_pms.yaml):
//   GET /fullcalendar/load/event/tarifa_rangos_raw_spa
//   GET /fullcalendar/load/event/tarifa_rangos_compactados_spa
//   GET /fullcalendar/load/resource/tarifa_rangos_*_spa
//
// A diferencia de pmsReservaModel.ts, estos tipos se escriben a mano en vez de
// derivarse de `components['schemas']`: el api.d.ts generado todavía no incluye
// PmsTarifaRango (falta regenerarlo con openapi-typescript). Los nombres de
// campo salen de `api:openapi:export`, no de suposiciones.
// ============================================================================

import { pmsUnidadIri } from '@/types/pmsReservaModel';

// ============================================================================
// TIPOS DE LECTURA
// ============================================================================

/** Unidad embebida en la tarifa (grupo pms_unidad:read). */
export interface PmsTarifaUnidadRef {
    id?: string;
    nombre?: string | null;
    activo?: boolean;
}

/** Moneda embebida en la tarifa (grupo maestro:moneda:read). */
export interface PmsTarifaMonedaRef {
    id?: string;
    nombre?: string | null;
    simbolo?: string | null;
}

/** Estado del push de tarifas a Beds24 (PmsTarifaRango::getSyncStatus()). */
export type PmsTarifaSyncStatus = 'local' | 'error' | 'pending' | 'synced';

export interface PmsTarifaRango {
    id?: string;
    unidad?: PmsTarifaUnidadRef | null;
    moneda?: PmsTarifaMonedaRef | null;
    /** ISO con hora 00:00 UTC: son columnas `date`, solo importa el día. */
    fechaInicio?: string | null;
    fechaFin?: string | null;
    precio?: string | null;
    minStay?: number;
    importante?: boolean;
    prioridad?: number;
    activo?: boolean;
    syncStatus?: PmsTarifaSyncStatus;
    createdAt?: string | null;
    updatedAt?: string | null;
}

// ============================================================================
// TIPOS DE ESCRITURA
// ============================================================================

/** POST /pms_tarifa_rangos — `unidad` y `moneda` viajan como IRI. */
export interface PmsTarifaRangoCreate {
    unidad: string;
    moneda: string;
    /** Formato 'YYYY-MM-DD' (columna `date`). */
    fechaInicio: string;
    fechaFin: string;
    precio: string;
    minStay: number;
    importante: boolean;
    prioridad: number;
    activo: boolean;
}

/** PATCH /pms_tarifa_rangos/{id} — cualquier subset del payload de creación. */
export type PmsTarifaRangoPatch = Partial<PmsTarifaRangoCreate>;

/**
 * POST /pms_tarifa_rangos/generar-masivo — equivalente del botón "Generar Masivo".
 * Crea un rango por cada unidad activa con tarifa base, aplicando `porcentaje`
 * sobre el precio base de cada una (ver GeneradorTarifaMasivaService).
 */
export interface PmsTarifaMasivaPayload {
    /** Formato 'YYYY-MM-DD'. */
    fechaInicio: string;
    fechaFin: string;
    /** % de ajuste sobre la tarifa base: 20 = +20%, -10 = -10%, 0 = precio base. */
    porcentaje: number;
    minStay: number;
    prioridad: number;
    importante: boolean;
}

export interface PmsTarifaMasivaResult {
    creadas: number;
    mensaje: string;
}

// ============================================================================
// CONTEXTO DE EVENTO (extendedProps de los providers SPA de tarifas)
// Ver TarifaRangesSpaCalendarProvider / TarifaCompressedRangesSpaCalendarProvider.
// ============================================================================

export interface PmsTarifaExtendedProps {
    /** 'tarifaRango' = rango real; 'tarifaRangoCompactado' = segmento calculado. */
    context: 'tarifaRango' | 'tarifaRangoCompactado';
    /** Id del PmsTarifaRango que originó el evento (en compactados, el rango ganador). */
    tarifaRangoId: string;
    /** Solo lo expone el provider raw. */
    active?: boolean;
    // Datos crudos para pintar la barra sin re-parsear el título ni pedir la
    // tarifa por REST (los agregan ambos providers *_spa de tarifas).
    precio?: string;
    minStay?: number;
    /** Símbolo de la moneda ('$', 'S/'...), según `fields.currency` del YAML. */
    moneda?: string | null;
    /** Solo lo expone el provider raw. */
    importante?: boolean;
}

// ============================================================================
// COLOR POR CASITA
// Los eventos de tarifa no tienen un color semántico propio (a diferencia de
// las reservas, donde el color ES el estado): sin esto, el calendario entero
// sale del mismo azul por defecto de FullCalendar y no se distingue una fila de
// otra. Se asigna por `orden` del recurso, que el provider ya calcula tras
// ordenar las unidades alfabéticamente.
// ============================================================================

export interface ColorCasita {
    bg: string;
    border: string;
}

export const PALETA_CASITAS: readonly ColorCasita[] = [
    { bg: '#376875', border: '#2b525c' }, // teal de marca
    { bg: '#E07845', border: '#b85f34' }, // naranja de marca
    { bg: '#5B7BA8', border: '#48628544' }, // azul acero
    { bg: '#6B8E5A', border: '#547046' }, // verde oliva
    { bg: '#8E6BA8', border: '#715486' }, // morado
    { bg: '#C2557A', border: '#9b4462' }, // frambuesa
];

/** Hash estable de un id, para que el color no baile si aún no cargaron los recursos. */
function hashId(id: string): number {
    let h = 0;
    for (let i = 0; i < id.length; i++) h = (h * 31 + id.charCodeAt(i)) >>> 0;
    return h;
}

/**
 * Color de la casita. `orden` viene del recurso (alternancia real); si todavía
 * no está disponible se cae a un hash del id, que es estable entre recargas.
 */
export function colorCasita(unidadId: string, orden?: number): ColorCasita {
    const idx = orden ?? hashId(unidadId);
    return PALETA_CASITAS[idx % PALETA_CASITAS.length];
}

// ============================================================================
// CALENDARIOS DISPONIBLES (claves de config/services/services_pms.yaml)
// ============================================================================

export interface PmsTarifaCalendario {
    nombre: string;
    key: string;
    /**
     * Los rangos compactados son segmentos CALCULADOS (la unión de varios rangos
     * solapados resuelta por prioridad), no filas de la tabla: arrastrarlos o
     * redimensionarlos no tendría un destino único al que aplicar el cambio.
     * Por eso solo el calendario "raw" permite editar por drag & drop.
     */
    editable: boolean;
}

export const PMS_TARIFA_CALENDARIOS: readonly PmsTarifaCalendario[] = [
    { nombre: 'Todas', key: 'tarifa_rangos_raw_spa', editable: true },
    { nombre: 'Compactados', key: 'tarifa_rangos_compactados_spa', editable: false },
];

// ============================================================================
// OCUPACIÓN DE FONDO
//
// El calendario de tarifas carga TAMBIÉN las estancias, como segunda fuente de
// eventos pintada en `display: 'background'`: sin ella se programan precios a
// ciegas, sin saber qué días quedan libres. La clave es un calendario propio
// (`pms_eventos_ocupacion_spa` en config/services/services_parameters_pms.yaml)
// porque no muestra los mismos estados que los calendarios de Reservas: sólo
// pinta los de `PmsEventoEstado::OCUPAN_UNIDAD` (pendiente, confirmada,
// requerimiento). Esa constante en PHP es la fuente de verdad — aquí no se
// duplica la lista, se nombra para saber dónde mirar.
// ============================================================================

export const PMS_OCUPACION_CALENDARIO_KEY = 'pms_eventos_ocupacion_spa';

/**
 * Beige de los días ocupados. Deliberadamente apagado: compite con seis colores
 * de casita y con el texto de los precios, así que tiene que leerse como fondo,
 * no como un evento más.
 */
export const COLOR_OCUPADO = '#F5E9C0';

// ============================================================================
// HELPERS DE IRI
// ============================================================================

export { pmsUnidadIri };
export const maestroMonedaIri = (id: string): string => `/platform/maestro/monedas/${id}`;

// ============================================================================
// HELPERS DE FECHA
//
// Las columnas son `date`: la API las serializa como ISO a medianoche UTC
// ("2026-08-01T00:00:00+00:00"). Pasarlas por `new Date()` y leer getDate() da
// el día ANTERIOR en cualquier zona con offset negativo (Perú es UTC-5), así que
// el día se extrae por string, nunca por Date.
// ============================================================================

/** ISO de la API -> valor para <input type="date"> ('2026-08-01'). */
export const toDateInput = (iso?: string | null): string => (iso ? iso.slice(0, 10) : '');

/** Date local (la que entrega FullCalendar) -> 'YYYY-MM-DD' para la API. */
export const fromDateLocal = (d: Date): string => {
    const pad = (n: number): string => String(n).padStart(2, '0');
    return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;
};

/**
 * Suma días a un 'YYYY-MM-DD' en aritmética UTC (sin sorpresas de horario de
 * verano). Se usa sobre todo para construir el `end` EXCLUSIVO que espera el
 * calendario compactado: pedir [D, D] revienta con InvalidArgumentException en
 * TarifaDailyPriceFlattener ("to > from"), hay que pedir [D, D+1).
 */
export const sumarDias = (fecha: string, dias: number): string => {
    const ms = Date.parse(`${fecha}T00:00:00Z`);
    if (Number.isNaN(ms)) return fecha;
    return new Date(ms + dias * 86_400_000).toISOString().slice(0, 10);
};

/** 'YYYY-MM-DD' -> 'dd/mm/aaaa' para las fichas de solo lectura. */
export const fechaLegible = (fecha?: string | null): string => {
    if (!fecha) return '—';
    const [y, m, d] = fecha.slice(0, 10).split('-');
    return y && m && d ? `${d}/${m}/${y}` : '—';
};

/** Noches entre dos 'YYYY-MM-DD' (inclusive: un rango de un día cuenta 1). */
export const diasDeRango = (inicio: string, fin: string): number => {
    if (!inicio || !fin) return 0;
    const ms = Date.parse(`${fin}T00:00:00Z`) - Date.parse(`${inicio}T00:00:00Z`);
    return Number.isNaN(ms) ? 0 : Math.floor(ms / 86_400_000) + 1;
};

// ============================================================================
// PRESENTACIÓN
// ============================================================================

/** Netos que muestra el título del calendario (espejo del provider en PHP). */
export const NETO_BOOKING = 0.8;
export const NETO_AIRBNB = 0.7;

export function netoDe(precio: string | number | null | undefined, factor: number): string {
    const n = typeof precio === 'number' ? precio : parseFloat(precio ?? '0');
    return Number.isFinite(n) ? (n * factor).toFixed(2) : '0.00';
}

export const SYNC_STATUS_META: Record<PmsTarifaSyncStatus, { texto: string; icono: string; clase: string }> = {
    local:   { texto: 'Local',        icono: 'fa-hard-drive',  clase: 'bg-slate-100 text-slate-500' },
    pending: { texto: 'Pendiente',    icono: 'fa-sync fa-spin', clase: 'bg-amber-100 text-amber-700' },
    synced:  { texto: 'Sincronizado', icono: 'fa-check',       clase: 'bg-emerald-100 text-emerald-700' },
    error:   { texto: 'Error',        icono: 'fa-triangle-exclamation', clase: 'bg-rose-100 text-rose-700' },
};
