/**
 * src/types/paxPagoModel.ts
 *
 * Espejo TS de lo que sirve `App\Finanzas\Controller\Publico\FinPagoPublicoController`.
 * Si cambias un campo allí, cámbialo aquí: no hay generación automática.
 */

/** Respuesta de `GET /finanzas/pago/{token}`. */
export interface PaxEnlacePago {
    concepto: string;
    referencia: string | null;
    /** ISO 4217 alfa-3: 'PEN', 'USD'. */
    moneda: string | null;
    monedaSimbolo: string | null;
    /** Importe que abona la deuda, sin recargo. */
    montoNeto: string;
    /** Recargo de la pasarela, ya calculado por el backend. */
    montoRecargo: string;
    /** Lo que se cobra a la tarjeta: neto + recargo. */
    montoTotal: string;
    recargoPorcentaje: string;
    estado: 'pendiente' | 'pagado' | 'fallido' | 'expirado' | 'anulado';
    vigente: boolean;
    expiraEn: string | null;
    pagadoEn: string | null;
    /** Clave pública de Izipay: es pública por diseño, va al navegador. */
    publicKey: string;
    /** Host de los assets de la pasarela (`https://static.micuentaweb.pe`). */
    staticUrl: string;
}

/** Respuesta de `POST /finanzas/pago/{token}/form-token`. */
export interface PaxFormToken {
    formToken: string;
    publicKey: string;
}
