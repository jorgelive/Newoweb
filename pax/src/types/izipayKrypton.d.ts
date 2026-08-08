/**
 * src/types/izipayKrypton.d.ts
 *
 * Tipos de la librería JS de Izipay (Krypton / plataforma Lyra), que se carga por
 * `<script>` desde `static.micuentaweb.pe` y se cuelga de `window.KR`.
 *
 * Se declara a mano porque Izipay no publica un paquete npm con tipos: la alternativa era
 * `(window as any).KR`, y eso apaga el compilador justo en el punto donde el contrato lo
 * fija un tercero que puede cambiarlo sin avisarnos. Aquí sólo se declara lo que usamos
 * (`PaxPagoView.vue`); si hace falta más, se añade aquí, no se castea en la vista.
 *
 * Referencia: https://developers.izipay.pe/api/ (sección Pago Incrustado / Embedded).
 */

/** Lo que devuelve la librería al terminar un pago en el navegador. */
export interface KryptonRespuestaPago {
    /**
     * Mismo JSON que el `kr-answer` del webhook.
     *
     * ⚠️ NO es una prueba de cobro: llega por el navegador del cliente y no va firmado
     * contra nuestro servidor. Sirve para pintar la pantalla de "listo", nunca para dar
     * por cobrado nada — de eso se encarga el IPN (`IzipayWebhookController`).
     */
    clientAnswer: {
        orderStatus?: string;
        orderDetails?: { orderId?: string };
        [clave: string]: unknown;
    };
    hash?: string;
    rawClientAnswer?: string;
}

export interface KryptonFormularioAdjunto {
    result: {
        formId: string;
    };
}

export interface KryptonError {
    errorCode?: string;
    errorMessage?: string;
    detailedErrorMessage?: string;
    [clave: string]: unknown;
}

export interface KryptonApi {
    /**
     * Configura el formulario. `formToken` es de un solo uso: cada carga de la página pide
     * uno nuevo a nuestro backend.
     */
    setFormConfig(config: Record<string, unknown>): Promise<{ KR: KryptonApi }>;

    /** Monta el formulario en el selector indicado. */
    attachForm(selector: string): Promise<KryptonFormularioAdjunto>;

    showForm(formId: string): Promise<unknown>;

    /**
     * Callback tras un pago aceptado en el navegador.
     *
     * Devolver `false` impide la redirección automática de la librería, que es lo que
     * queremos: la confirmación de verdad la pinta esta misma vista consultando a nuestro
     * backend, no la pasarela.
     */
    onSubmit(callback: (respuesta: KryptonRespuestaPago) => boolean | void): void;

    onError(callback: (error: KryptonError) => void): void;

    /** Descarga el formulario montado. Necesario al desmontar la vista. */
    removeForms(): Promise<unknown>;
}

declare global {
    interface Window {
        KR?: KryptonApi;
    }
}

export {};
