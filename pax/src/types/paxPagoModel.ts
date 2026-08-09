/**
 * src/types/paxPagoModel.ts
 *
 * Espejo TS de `App\Finanzas\Controller\Publico\FinPagoPublicoController`.
 * Si cambias un campo allí, cámbialo aquí: no hay generación automática.
 */

/** Valores de `App\Finanzas\Enum\FinPasarela`. Conviven; no se migra de una a otra. */
export type PaxPasarela = 'izipay' | 'culqi';

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
    /** Con qué librería se monta el formulario. La vista hace un switch por esto. */
    pasarela: PaxPasarela;
}

/**
 * Config de Izipay (plataforma Lyra).
 *
 * `formToken` es de UN SOLO USO y caduca en minutos: se pide en cada carga y no se cachea.
 */
export interface PaxConfigIzipay {
    formToken: string;
    publicKey: string;
    /** Host de los assets (`https://static.micuentaweb.pe`). */
    staticUrl: string;
}

/**
 * Config de Culqi.
 *
 * A diferencia de Izipay, no abre nada en la pasarela: son datos estáticos más el importe,
 * así que recargar la página no consume nada.
 */
export interface PaxConfigCulqi {
    publicKey: string;
    /** URL de la librería del Checkout v4. */
    checkoutJs: string;
    /** Céntimos, entero. Culqi cobra en la unidad mínima igual que Lyra. */
    amount: number;
    currency: string;
    descripcion: string;
    email: string | null;
    // Sin `order` a propósito: en Culqi es el id de una orden de su API (`ord_...`) y
    // mandarle una cadena nuestra impide que el Checkout abra. Ver CulqiClient.
}

/** Respuesta de `POST /finanzas/pago/{token}/configuracion`. */
export type PaxConfigPago =
    | { pasarela: 'izipay'; config: PaxConfigIzipay }
    | { pasarela: 'culqi'; config: PaxConfigCulqi };

/** Respuesta de `POST /finanzas/pago/{token}/culqi/cobrar`. */
export interface PaxCulqiCobroRespuesta {
    ok: boolean;
    estado: string;
}
