<script setup lang="ts">
/**
 * Culqi Checkout Custom (`js.culqi.com/checkout-js`).
 *
 * ## El flujo es al revés que el de Izipay
 *
 * Culqi **no cobra**: abre un modal, captura la tarjeta y deja un TOKEN en `Culqi.token.id`.
 * El cargo lo crea NUESTRO servidor con ese token y la clave secreta
 * (`POST /finanzas/pago/{token}/culqi/cobrar`), y esa respuesta ya dice si el dinero entró.
 * Por eso este componente emite `pagado` —confirmación real, de nuestro backend— y no
 * `procesando` como el de Izipay.
 *
 * El importe que se cobra sale del ENLACE en el servidor, no de lo que diga este JS:
 * manipular el navegador no abarata el cobro.
 *
 * ## Sin estado global
 *
 * La versión anterior (Checkout v4) usaba `window.Culqi` como singleton y `window.culqi`
 * como callback buscado por nombre, lo que obligaba a asignar y borrar globales en cada
 * montaje. Aquí la instancia vive en un `ref` del componente y el callback va **en la
 * instancia**, así que nace y muere con él: al reentrar en la página no queda nada que
 * pueda dispararse contra un enlace que ya no toca. Ver §11 del doc.
 */
import { markRaw, onBeforeUnmount, onMounted, ref, shallowRef } from 'vue';
import { apiClient } from '@/services/apiClient';
import type { PaxConfigCulqi, PaxConfigPago, PaxCulqiCobroRespuesta } from '@/types/paxPagoModel';
import type { CulqiCheckoutInstance } from '@/types/culqiCheckout';

const props = defineProps<{ token: string; monedaSimbolo?: string | null; montoTotal: string }>();

const emit = defineEmits<{
    /** Cobro confirmado por NUESTRO backend, no por el navegador. */
    (e: 'pagado'): void;
    (e: 'error', mensaje: string): void;
}>();

const listo = ref(false);
const cobrando = ref(false);

/**
 * La instancia del checkout. Local al componente: esa es la mejora sobre v4.
 *
 * ⚠️ **`shallowRef` + `markRaw`, NUNCA `ref`.** Un `ref` envuelve el objeto en `reactive()`,
 * que es un `Proxy`, y un Proxy **rompe los campos privados de clase** (`#campo`):
 * `CulqiCheckout` los usa por dentro y al pulsar Pagar reventaba con
 * `TypeError: Cannot read private member #e from an object whose class did not declare it`,
 * con la traza apuntando a `Proxy.validateConfig` / `Proxy.open`.
 *
 * El error engaña porque parece de la librería y es nuestro. La regla: cualquier instancia
 * de clase de terceros que se guarde en el estado de Vue va con `shallowRef` o `markRaw` —
 * aquí ambos, porque el objeto ni se lee reactivamente ni debe proxiarse nunca.
 */
const checkout = shallowRef<CulqiCheckoutInstance | null>(null);

const cargarLibreria = (src: string): Promise<void> => {
    if (window.CulqiCheckout) return Promise.resolve();

    return new Promise((resolve, reject) => {
        const script = document.createElement('script');
        script.src = src;
        script.onload = () => resolve();
        script.onerror = () => reject(new Error('No se pudo cargar el formulario de pago.'));
        document.head.appendChild(script);
    });
};

/** Canjea el token por un cargo real. Es el único punto que confirma el pago. */
const cobrar = async (tokenTarjeta: string): Promise<void> => {
    cobrando.value = true;
    try {
        const { data } = await apiClient.post<PaxCulqiCobroRespuesta>(
            `/finanzas/pago/${props.token}/culqi/cobrar`,
            { token: tokenTarjeta },
        );
        if (data.ok) emit('pagado');
        else emit('error', 'No pudimos confirmar el pago.');
    } catch (err) {
        // 402 = el banco rechazó. El backend ya lo dejó como FALLIDO, que NO es final:
        // el cliente puede reintentar con otra tarjeta en el mismo enlace.
        const data = (err as { response?: { data?: { mensaje?: string; error?: string } } })?.response?.data;
        emit('error', data?.mensaje || data?.error || 'El pago no se pudo completar.');
    } finally {
        cobrando.value = false;
    }
};

const abrir = (): void => checkout.value?.open();

const montar = async (): Promise<void> => {
    const { data } = await apiClient.post<PaxConfigPago>(`/finanzas/pago/${props.token}/configuracion`, {});

    if (data.pasarela !== 'culqi') {
        throw new Error('El enlace no es de Culqi.');
    }

    const config: PaxConfigCulqi = data.config;
    await cargarLibreria(config.checkoutJs);

    const Constructor = window.CulqiCheckout;
    if (!Constructor) throw new Error('El formulario de pago no está disponible.');

    const instancia = new Constructor(config.publicKey, {
        settings: {
            title: config.descripcion.slice(0, 50),
            currency: config.currency,
            // Céntimos, entero: lo calcula el backend, aquí no se hace aritmética de importes.
            amount: config.amount,
            // Sin `order`: en Culqi es el id de una orden de su API (`ord_...`), no una
            // referencia libre. Mandarle la nuestra impedía que el modal abriera, en
            // silencio. Ver la nota de `CulqiClient::configuracionPago()`.
        },
        client: { email: config.email ?? undefined },
        options: {
            lang: 'es',
            installments: false,
            modal: true,
            // Sólo tarjeta: el resto de medios exige una orden de la API de Culqi, que aún
            // no creamos. Ofrecerlos sin ella sería enseñar botones que no funcionan.
            paymentMethods: {
                tarjeta: true,
                yape: false,
                billetera: false,
                bancaMovil: false,
                agente: false,
                cuotealo: false,
            },
        },
        appearance: { theme: 'default', hiddenCulqiLogo: false, menuType: 'sidebar' },
    });

    // En la INSTANCIA, no en `window`. Es la diferencia de fondo con v4.
    instancia.culqi = () => {
        if (instancia.token?.id) {
            instancia.close();
            void cobrar(instancia.token.id);
        } else if (instancia.error) {
            emit('error', instancia.error.user_message || 'No se pudo procesar la tarjeta.');
        }
    };

    // `markRaw` además del `shallowRef`: deja marcado el objeto para que nunca lo proxie
    // nadie, ni aunque mañana se guarde en otro sitio reactivo. Ver la nota de `checkout`.
    checkout.value = markRaw(instancia);
    listo.value = true;
};

onMounted(async () => {
    try {
        await montar();
    } catch (err) {
        emit('error', err instanceof Error ? err.message : 'No pudimos preparar el pago.');
    }
});

onBeforeUnmount(() => {
    // Cerrar y soltar el callback: sin esto, un modal abierto al navegar deja el nodo
    // colgado y la instancia viva por la referencia del callback.
    const instancia = checkout.value;
    if (instancia) {
        instancia.culqi = null;
        instancia.close();
    }
    checkout.value = null;
});
</script>

<template>
    <div class="bg-white rounded-2xl shadow-sm p-6 text-center">
        <button type="button" @click="abrir" :disabled="!listo || cobrando"
            class="w-full py-3 rounded-xl bg-slate-900 hover:bg-slate-800 disabled:opacity-50 text-white font-black text-sm">
            <span v-if="cobrando">Procesando…</span>
            <span v-else-if="!listo">Cargando…</span>
            <span v-else>Pagar {{ props.monedaSimbolo }} {{ props.montoTotal }}</span>
        </button>

        <!-- Sólo tarjeta: Yape y efectivo exigen una orden de la API de Culqi, que aún no
             creamos. Prometerlos aquí haría que el cliente abriera el modal buscando un
             botón que no existe. -->
        <p class="mt-3 text-[11px] text-slate-400">
            Tarjeta de crédito o débito.
        </p>
    </div>
</template>
