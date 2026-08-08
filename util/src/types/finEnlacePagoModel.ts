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

export interface FinEnlacePago {
    id: string;
    /** URL completa que se le manda al cliente (vive en el host de `pax`). */
    url: string;
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
