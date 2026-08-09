/**
 * src/types/culqiCheckout.d.ts
 *
 * Tipos del Checkout v4 de Culqi, que se carga por `<script>` desde `checkout.culqi.com`
 * y se cuelga de `window.Culqi`.
 *
 * A mano porque Culqi no publica un paquete npm con tipos. La alternativa era
 * `(window as any).Culqi`, y eso apaga el compilador justo donde el contrato lo fija un
 * tercero. Sólo se declara lo que usa `PagoCulqiForm.vue`.
 *
 * ## El detalle que hay que entender del flujo
 *
 * Culqi **no cobra**: captura la tarjeta y deja un TOKEN en `window.Culqi.token.id`. El
 * cargo lo crea nuestro servidor con ese token y la clave secreta. Por eso el callback no
 * significa "pagado", significa "tengo un token que canjear" — y por eso el importe real
 * sale del enlace en el backend y no de lo que diga este JS.
 *
 * Referencia: https://docs.culqi.com/es/documentacion/checkout/v4/culqi-checkout/
 */

export interface CulqiToken {
    id: string;
    email?: string;
    /** Últimos 4 y marca, para pintar el resumen. Nunca el PAN completo. */
    last_four?: string;
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

export interface CulqiSettings {
    title: string;
    currency: string;
    /** Céntimos, entero. */
    amount: number;
    order?: string;
}

export interface CulqiOptions {
    lang?: string;
    installments?: boolean;
    paymentMethods?: Record<string, boolean>;
    style?: Record<string, string>;
}

export interface CulqiApi {
    /** Llave PÚBLICA (`pk_...`). La secreta no toca el navegador jamás. */
    publicKey: string;
    settings(config: CulqiSettings): void;
    options(config: CulqiOptions): void;
    /** Abre el modal de pago. */
    open(): void;
    close(): void;
    /** Presente cuando el usuario completó la captura de tarjeta. */
    token?: CulqiToken;
    order?: CulqiOrder;
    error?: CulqiError;
}

declare global {
    interface Window {
        Culqi?: CulqiApi;
        /**
         * Callback GLOBAL que invoca la librería al terminar.
         *
         * Culqi lo busca por nombre en `window` — no admite pasar una función por
         * parámetro—, así que el componente lo asigna al montar y lo limpia al desmontar.
         * Sin limpiarlo, al volver a entrar en la página se ejecutaba el callback del
         * componente anterior, ya desmontado.
         */
        culqi?: () => void;
    }
}

export {};
