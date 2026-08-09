<script setup lang="ts">
/**
 * Checkout v4 de Culqi (multipago: tarjeta, Yape, PagoEfectivo, Cuotéalo BCP).
 *
 * ## El flujo es al revés que el de Izipay
 *
 * Culqi **no cobra**: abre un modal, captura la tarjeta y deja un TOKEN en
 * `window.Culqi.token.id`. El cargo lo crea NUESTRO servidor con ese token y la clave
 * secreta (`POST /finanzas/pago/{token}/culqi/cobrar`), y esa respuesta ya dice si el
 * dinero entró. Por eso este componente emite `pagado` —confirmación real, de nuestro
 * backend— y no `procesando` como el de Izipay.
 *
 * El importe que se cobra sale del ENLACE en el servidor, no de lo que diga este JS:
 * manipular el navegador no abarata el cobro. Ver docs/FinanzasEnlacesPago.md §11.
 */
import { onBeforeUnmount, onMounted, ref } from 'vue';
import { apiClient } from '@/services/apiClient';
import type { PaxConfigCulqi, PaxConfigPago, PaxCulqiCobroRespuesta } from '@/types/paxPagoModel';

const props = defineProps<{ token: string; monedaSimbolo?: string | null; montoTotal: string }>();

const emit = defineEmits<{
    /** Cobro confirmado por NUESTRO backend, no por el navegador. */
    (e: 'pagado'): void;
    (e: 'error', mensaje: string): void;
}>();

const listo = ref(false);
const cobrando = ref(false);

const cargarLibreria = (src: string): Promise<void> => {
    if (window.Culqi) return Promise.resolve();

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

const abrir = (): void => window.Culqi?.open();

const montar = async (): Promise<void> => {
    const { data } = await apiClient.post<PaxConfigPago>(`/finanzas/pago/${props.token}/configuracion`, {});

    if (data.pasarela !== 'culqi') {
        throw new Error('El enlace no es de Culqi.');
    }

    const config: PaxConfigCulqi = data.config;
    await cargarLibreria(config.checkoutJs);

    const Culqi = window.Culqi;
    if (!Culqi) throw new Error('El formulario de pago no está disponible.');

    Culqi.publicKey = config.publicKey;
    // Sin `order`: en Culqi ese campo es el id de una orden de su API (`ord_...`), no una
    // referencia libre. Mandarle la nuestra hacía que `open()` no abriera nada, en silencio.
    // Ver la nota de `CulqiClient::configuracionPago()`.
    Culqi.settings({
        title: config.descripcion.slice(0, 50),
        currency: config.currency,
        // Céntimos, entero: lo calcula el backend, aquí no se hace aritmética de importes.
        amount: config.amount,
    });
    Culqi.options({ lang: 'es', installments: false });

    // La librería busca el callback por nombre en `window`; no admite pasarlo por parámetro.
    window.culqi = () => {
        const actual = window.Culqi;
        if (actual?.token?.id) {
            void cobrar(actual.token.id);
        } else if (actual?.error) {
            emit('error', actual.error.user_message || 'No se pudo procesar la tarjeta.');
        }
    };

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
    // Sin limpiarlo, al volver a entrar se ejecuta el callback del componente anterior,
    // ya desmontado, y el cobro se dispara contra un `props.token` viejo.
    delete window.culqi;
    window.Culqi?.close();
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
