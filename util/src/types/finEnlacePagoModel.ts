// src/types/finEnlacePagoModel.ts
// ============================================================================
// Enlaces de pago por pasarela (módulo Finanzas).
//
// Espejo del serializador de `App\Finanzas\Controller\Api\FinEnlacePagoApiController`
// (método privado `serializar()`). NO es un recurso de API Platform: no hay contrato
// generado, así que si cambia un campo allí hay que cambiarlo aquí a mano.
//
// Los importes viajan como STRING con dos decimales, igual que en el resto de finanzas:
// pasarlos a `number` en el transporte introduce errores de coma flotante justo en las
// cifras que el cliente ve en su extracto.
// ============================================================================

/** Estados de `App\Finanzas\Enum\FinEnlacePagoEstado`. */
export type FinEnlacePagoEstado = 'pendiente' | 'pagado' | 'fallido' | 'expirado' | 'anulado';

/** Orígenes de `App\Finanzas\Enum\FinOrigenCobro`. */
export type FinOrigenCobro = 'pms_reserva' | 'tour_reserva';

/**
 * Pasarelas de `App\Finanzas\Enum\FinPasarela`.
 *
 * CONVIVEN, no se sustituyen: Izipay quedó sin habilitar (exigen S/200 000 de venta
 * acumulada) y Culqi cobra hoy, pero cada enlace recuerda por cuál se emitió. Ver §11 de
 * docs/FinanzasEnlacesPago.md.
 */
export type FinPasarela = 'izipay' | 'culqi';

/**
 * Respuesta cruda de la pasarela (`GET /finanzas/enlaces-pago/{id}/respuesta`).
 *
 * `respuesta` es **deliberadamente `unknown`**, no una interfaz. Cada pasarela devuelve lo
 * que quiere —un `charge` de Culqi y un `kr-answer` de Lyra no comparten un campo— y tiparlo
 * ataría el módulo a la forma de una de ellas: el día que entre la tercera habría que
 * reescribir el tipo o meter un `any`, que es justo lo que no queremos.
 *
 * Se pinta como JSON formateado, sin interpretar. Quien audita quiere ver lo que dijo la
 * pasarela, no nuestra lectura de lo que dijo.
 */
export interface FinRespuestaPasarela {
    pasarela: FinPasarela;
    pasarelaEtiqueta: string;
    respuesta: unknown;
}

/** Opción del selector, servida por `GET /finanzas/enlaces-pago/pasarelas`. */
export interface FinPasarelaOpcion {
    value: FinPasarela;
    label: string;
    /** La que se usa si el operador no elige. Sólo llegan las que tienen credenciales. */
    porDefecto: boolean;
}

export interface FinEnlacePago {
    id: string;
    /** URL completa que se le manda al cliente (vive en el host de `pax`). */
    url: string;
    /** Por qué pasarela se emitió. Congelado en la fila, no se deduce de la config de hoy. */
    pasarela: FinPasarela;
    pasarelaEtiqueta: string;
    estado: FinEnlacePagoEstado;
    estadoEtiqueta: string;
    /** ¿Se puede pagar ahora? Un `fallido` sigue siendo vigente: se puede reintentar. */
    vigente: boolean;
    moneda: string | null;
    monedaSimbolo: string | null;
    /** Lo que abona la deuda de la reserva, sin recargo. */
    montoNeto: string;
    /** Recargo trasladado al cliente. */
    montoRecargo: string;
    /** Lo que se cobra a la tarjeta: neto + recargo. */
    montoTotal: string;
    recargoPorcentaje: string;
    concepto: string;
    /** `orderId` en el Backoffice de la pasarela. Es la clave para conciliar. */
    ordenId: string | null;
    expiraEn: string | null;
    pagadoEn: string | null;
    /** Marca y últimos dígitos de la tarjeta, tal como los devuelve la pasarela. */
    medioDetalle: string | null;
    autorizacionCodigo: string | null;
    creadoPorNombre: string | null;
    createdAt: string | null;
    /**
     * De quién es el cobro. Sólo se usa en la vista global: dentro del panel de una
     * reserva ya se sabe, pero en la lista transversal sin esto no se puede ni etiquetar
     * la fila ni enlazar a su ficha.
     */
    origenTipo: FinOrigenCobro;
    origenId: string;
    origenReferencia: string | null;
    clienteNombre: string | null;
    /**
     * Id del `PmsPagoFinanciero` que generó este enlace al cobrarse.
     *
     * Es el hilo que ata enlace y pago. Lo usa el panel para marcar ese pago como "vino de
     * un enlace" y para explicar por qué los importes no coinciden: el pago registra el
     * NETO y el enlace cobra el total con recargo.
     */
    movimientoGeneradoId: string | null;
}

/** Respuesta de `GET /finanzas/caja/cobros`. */
export interface FinCobrosRespuesta {
    cobros: FinEnlacePago[];
    totales: FinTotalCobro[];
    estados: { value: FinEnlacePagoEstado; label: string }[];
    /** Se rozó el tope de filas: hay más cobros de los que se ven. Estrecha las fechas. */
    truncado: boolean;
}

/** Total por estado y moneda. El backend NO convierte entre monedas: ver §9 del doc. */
export interface FinTotalCobro {
    estado: FinEnlacePagoEstado;
    etiqueta: string;
    moneda: string;
    total: string;
    registros: number;
}

/** Cuerpo de `POST /finanzas/enlaces-pago`. */
export interface FinEnlacePagoCreate {
    origenTipo: FinOrigenCobro;
    origenId: string;
    /** NETO a cobrar. Omitido = todo el saldo pendiente. */
    monto?: string;
    /** Si se le traslada al cliente la comisión de la pasarela. */
    conRecargo?: boolean;
    /** 0 = sin caducidad. Omitido = el defecto del backend (7 días). */
    vigenciaDias?: number;
    concepto?: string;
    /** Omitido = la del backend. Sólo se manda si el operador eligió otra. */
    pasarela?: FinPasarela;
}

/** Clases de color por estado, para no repetir el `match` en cada plantilla. */
export const clasesEstadoEnlace = (estado: FinEnlacePagoEstado): string => {
    switch (estado) {
        case 'pagado': return 'bg-emerald-50 text-emerald-700 border-emerald-200';
        case 'pendiente': return 'bg-sky-50 text-sky-700 border-sky-200';
        case 'fallido': return 'bg-rose-50 text-rose-700 border-rose-200';
        default: return 'bg-slate-100 text-slate-500 border-slate-200';
    }
};
