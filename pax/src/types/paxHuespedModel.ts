// src/types/paxHuespedModel.ts
// ============================================================================
// Tipos del módulo PAX / Huésped.
//
// Los tipos con schema en api.d.ts se anclan con components['schemas'][...],
// extendiéndolos donde el schema OpenAPI es incompleto:
//  - PmsUnidad: api.d.ts solo expone `imageUrl`; en runtime también viene `id` y `nombre`.
//  - PmsReserva.eventosActivosGuia: api.d.ts lo declara string[] pero
//    el endpoint devuelve objetos PmsEventoCalendario embebidos.
//
// Los tipos del árbol de la guía ya no viven aquí; ver la nota de más abajo.
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
    /** Segundo segmento del enlace a la guía: /huesped/reserva/{loc}/{slug}. */
    slug?: string | null;
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

/**
 * Estado de cuenta del huésped. Espejo del array que arma
 * `PmsReservaPaxProvider` (backend); importes como strings decimales en la
 * moneda de la cabecera financiera. Solo el agregado: el detalle de cargos y
 * pagos nunca viaja al cliente.
 */
export interface PmsResumenFinanciero {
    moneda: string;
    simbolo?: string | null;
    /**
     * Sin cifras que enseñar: reserva de un canal que cobra por nosotros
     * (Airbnb, VRBO) y sin cargos añadidos a mano. Se pinta la barra al 100 %
     * como acuse de recibo y NADA más — los importes del canal son lo que la
     * OTA nos remite, no lo que el huésped pagó. Ver `PmsReservaPaxProvider::cifras()`.
     *
     * Cuando viene `true`, `total`/`pagado`/`saldo` NO llegan.
     */
    soloProgreso?: boolean;
    total?: string;
    pagado?: string;
    saldo?: string;
    /**
     * Cargos agrupados por tipo: `{ alojamiento: '350.00', limpieza: '15.00' }`.
     *
     * La clave es el valor de `PmsTipoCargo` (PHP), **no una descripción**: las
     * descripciones vienen de Beds24 en un solo idioma y no se pueden traducir.
     * Con el tipo, el front resuelve la etiqueta por i18n (`res_cargo_*`).
     *
     * Llega VACÍO si las líneas no suman el total —cargos en varias monedas—:
     * un desglose que no cuadra es peor que ninguno.
     */
    cargos?: Record<string, string>;
    /** Pagos visibles, de más antiguo a más reciente. `medio` es `PmsMedioPago`. */
    pagos?: PmsPagoResumen[];
}

export interface PmsPagoResumen {
    /** 'YYYY-MM-DD', o null si el pago no tiene fecha registrada. */
    fecha: string | null;
    /** Valor del enum PmsMedioPago; se traduce en el front con `res_medio_*`. */
    medio: string;
    monto: string;
}

export type PmsReserva = Omit<components['schemas']['PmsReserva-pax_reserva.read'], 'eventosActivosGuia'> & {
    eventosActivosGuia?: PmsEventoCalendario[];
    resumenFinanciero?: PmsResumenFinanciero | null;
};

// ============================================================================
// Los tipos del ÁRBOL de la guía (PmsGuia, PmsGuiaSeccion, PmsGuiaItem) y
// `GuiaHelperContext` vivían aquí y se han retirado con el contrato viejo.
//
// Aquel contrato eran dos peticiones: el CMS por UUID de unidad más un
// "helper" que traía el diccionario de valores sensibles para que el navegador
// interpolara los `{{ door_code }}`. Ya no existe ninguno de los dos endpoints.
//
// El sustituto para el catálogo público está en `paxCatalogoUnidadModel.ts`.
// El de la guía del huésped se escribirá contra el grupo `pax_guia:read`
// (GET /platform/client/pax/pms/pms_guia/{localizador}), cuyo payload llega
// ya podado por visibilidad y con los placeholders resueltos: no lleva
// diccionario de valores ni banderas de bloqueo que interpretar.
// ============================================================================
