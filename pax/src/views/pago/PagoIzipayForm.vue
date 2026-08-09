<script setup lang="ts">
/**
 * Formulario incrustado de Izipay (plataforma Lyra / librería Krypton).
 *
 * Flujo: el servidor abre el intento con un `formToken` de un solo uso, el navegador monta
 * el formulario y a partir de ahí habla DIRECTAMENTE con la pasarela. Nuestro servidor no
 * vuelve a intervenir hasta que llega el IPN firmado — por eso este componente emite
 * `procesando` y no `pagado`: aquí no se sabe si el dinero entró.
 *
 * Ver docs/FinanzasEnlacesPago.md §11 para la comparación con Culqi.
 */
import { onBeforeUnmount, onMounted, ref } from 'vue';
import { apiClient } from '@/services/apiClient';
import type { PaxConfigIzipay, PaxConfigPago } from '@/types/paxPagoModel';

const props = defineProps<{ token: string }>();

const emit = defineEmits<{
    /** El navegador dice que se pagó. NO es prueba: el parent sondea al backend. */
    (e: 'procesando'): void;
    (e: 'error', mensaje: string): void;
}>();

const listo = ref(false);

/**
 * Carga el `<script>` y el CSS de la pasarela una sola vez.
 *
 * La clave pública va como atributo `kr-public-key` del propio `<script>`: la librería la
 * lee al inicializarse y no admite pasarla después. Por eso no se puede precargar en
 * `index.html` — hasta consultar el enlace no sabemos con qué tienda se cobra.
 */
const cargarLibreria = (staticUrl: string, publicKey: string): Promise<void> => {
    if (window.KR) return Promise.resolve();

    return new Promise((resolve, reject) => {
        const css = document.createElement('link');
        css.rel = 'stylesheet';
        css.href = `${staticUrl}/static/js/krypton-client/V4.0/ext/neon-reset.min.css`;
        document.head.appendChild(css);

        const temaJs = document.createElement('script');
        temaJs.src = `${staticUrl}/static/js/krypton-client/V4.0/ext/neon.js`;

        const script = document.createElement('script');
        script.src = `${staticUrl}/static/js/krypton-client/V4.0/stable/kr-payment-form.min.js`;
        script.setAttribute('kr-public-key', publicKey);
        script.setAttribute('kr-post-url-success', '');
        script.onload = () => {
            // El tema va DESPUÉS del cliente: al revés no encuentra la librería.
            document.head.appendChild(temaJs);
            resolve();
        };
        script.onerror = () => reject(new Error('No se pudo cargar el formulario de pago.'));

        document.head.appendChild(script);
    });
};

const montar = async (): Promise<void> => {
    // POST y no GET: pedir el formToken ABRE un intento de cobro y caduca en minutos.
    const { data } = await apiClient.post<PaxConfigPago>(`/finanzas/pago/${props.token}/configuracion`, {});

    if (data.pasarela !== 'izipay') {
        throw new Error('El enlace no es de Izipay.');
    }

    const config: PaxConfigIzipay = data.config;
    await cargarLibreria(config.staticUrl, config.publicKey);

    const KR = window.KR;
    if (!KR) throw new Error('El formulario de pago no está disponible.');

    await KR.setFormConfig({ formToken: config.formToken, 'kr-language': 'es-ES' });

    KR.onSubmit((respuesta) => {
        if (respuesta.clientAnswer.orderStatus === 'PAID') emit('procesando');
        // `false` corta la redirección automática de la librería.
        return false;
    });

    KR.onError((err) => {
        emit('error', err.errorMessage || 'El pago no se pudo completar. Inténtalo de nuevo.');
    });

    const { result } = await KR.attachForm('#izipay-formulario');
    await KR.showForm(result.formId);
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
    // Sin `removeForms` la librería deja el formulario colgado y al volver a entrar
    // aparece duplicado.
    void window.KR?.removeForms();
});
</script>

<template>
    <div class="bg-white rounded-2xl shadow-sm p-6">
        <div id="izipay-formulario" class="kr-embedded" kr-form-expiry></div>
        <p v-if="!listo" class="text-center text-xs text-slate-400 py-4">Cargando formulario…</p>
    </div>
</template>
