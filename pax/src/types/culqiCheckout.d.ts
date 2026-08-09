/**
 * src/types/culqiCheckout.d.ts
 *
 * Tipos del **Culqi Checkout Custom**, que se carga por `<script>` desde
 * `js.culqi.com/checkout-js` y expone el constructor global `CulqiCheckout`.
 *
 * A mano porque Culqi no publica tipos en npm. La alternativa era `(window as any)`, y eso
 * apaga el compilador justo donde el contrato lo fija un tercero. Sólo se declara lo que usa
 * `PagoCulqiForm.vue`.
 *
 * ## Por qué esta versión y no Checkout v4
 *
 * v4 vive en globales: `window.Culqi` como singleton y `window.culqi` como callback buscado
 * por nombre. En una SPA eso obliga a asignar y borrar globales en cada montaje, y si se
 * escapa uno, al reentrar en la página se dispara el callback del componente anterior contra
 * un enlace que ya no toca.
 *
 * Checkout Custom es **instanciable** y el callback va en la instancia (`Culqi.culqi = fn`),
 * así que nace y muere con el componente. No es un cambio de versión: es quitar estado
 * global compartido. Ver §11 de docs/FinanzasEnlacesPago.md.
 *
 * Referencia: https://docs.culqi.com/es/documentacion/checkout/checkout-custom
 */

export interface CulqiToken {
    id: string;
    email?: string;
    /** Marca y últimos 4, para el resumen. Nunca el PAN completo. */
    last_four?: string;
    iin?: { card_brand?: string };
    [clave: string]: unknown;
}

export interface CulqiOrder {
    id: string;
    [clave: string]: unknown;
}

export interface CulqiError {
    /** Mensaje ya en español y apto para enseñar al cliente. */
    user_message?: string;
    merchant_message?: string;
    type?: string;
    [clave: string]: unknown;
}

/**
 * `order` sólo se manda si es un id de orden de la API de Culqi (`ord_...`), y es lo que
 * habilita Yape y efectivo. Mandar una referencia nuestra impide que el modal abra —ver la
 * nota de `CulqiClient::configuracionPago()`—, así que aquí va deliberadamente opcional.
 */
export interface CulqiCheckoutSettings {
    title: string;
    currency: string;
    /** Céntimos, entero. */
    amount: number;
    order?: string;
    /** Cifrado de payload: opcional y por endpoint. Hoy no se usa. */
    xculqirsaid?: string;
    rsapublickey?: string;
}

export interface CulqiPaymentMethods {
    tarjeta: boolean;
    yape: boolean;
    billetera: boolean;
    bancaMovil: boolean;
    agente: boolean;
    cuotealo: boolean;
}

export interface CulqiCheckoutOptions {
    lang?: string;
    installments?: boolean;
    modal?: boolean;
    /** Sólo con `modal: false`. */
    container?: string;
    paymentMethods?: CulqiPaymentMethods;
    paymentMethodsSort?: string[];
}

export interface CulqiCheckoutConfig {
    settings: CulqiCheckoutSettings;
    client?: { email?: string };
    options?: CulqiCheckoutOptions;
    appearance?: {
        theme?: string;
        hiddenCulqiLogo?: boolean;
        menuType?: string;
    };
}

export interface CulqiCheckoutInstance {
    open(): void;
    close(): void;
    /**
     * Callback que la librería invoca al terminar. Se asigna **en la instancia**, no en
     * `window`: es la diferencia de fondo con v4.
     */
    culqi: (() => void) | null;
    /** Presente si el usuario completó la captura de tarjeta. */
    token?: CulqiToken;
    /** Presente en los medios que van por orden (Yape, efectivo). Hoy no los usamos. */
    order?: CulqiOrder;
    error?: CulqiError;
}

declare global {
    /** Constructor global que expone el script de `js.culqi.com/checkout-js`. */
    const CulqiCheckout: {
        new (publicKey: string, config: CulqiCheckoutConfig): CulqiCheckoutInstance;
    };

    interface Window {
        CulqiCheckout?: {
            new (publicKey: string, config: CulqiCheckoutConfig): CulqiCheckoutInstance;
        };
    }
}

export {};
