<script setup lang="ts">
/**
 * src/views/pago/PaxPagoView.vue
 *
 * Página de cobro que abre el cliente: `.pe/pago/{token}`.
 *
 * ## Por qué la página es nuestra y no un link de Izipay
 *
 * Emitiendo la URL nosotros controlamos importe, vigencia, reintentos y el estado que ve el
 * operador en `util`. El formulario de tarjeta sí es de Izipay (va incrustado): los datos
 * de la tarjeta NUNCA pasan por este código ni por nuestro servidor, que es lo que mantiene
 * el alcance de PCI donde tiene que estar.
 *
 * ## La confirmación NO se decide aquí
 *
 * Al terminar el pago, la librería nos avisa en el navegador. Ese aviso sólo cambia lo que
 * se pinta. Quien marca el cobro como bueno es el webhook firmado que Izipay manda a
 * nuestro servidor (`IzipayWebhookController`). Por eso, tras pagar, esta vista **pregunta
 * al backend** hasta ver `estado === 'pagado'` en vez de creerse al navegador: si alguien
 * manipula el JS, lo peor que consigue es ver una pantalla verde falsa en su propio equipo.
 *
 * Espejo del backend: `App\Finanzas\Controller\Publico\FinPagoPublicoController`.
 */
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { useRoute } from 'vue-router';
import { apiClient } from '@/services/apiClient';
import type { PaxEnlacePago, PaxFormToken } from '@/types/paxPagoModel';

const route = useRoute();
const token = computed(() => String(route.params.token || '').trim());

const enlace = ref<PaxEnlacePago | null>(null);
const cargando = ref(true);
const error = ref<string | null>(null);

/** Pantalla de "gracias": se enciende al volver la librería y se confirma al consultar. */
const pagando = ref(false);
const confirmado = ref(false);

/** Handle del sondeo, para no dejarlo vivo si el cliente cierra la vista. */
let sondeoId: number | null = null;

const importe = computed(() => {
    if (!enlace.value) return '';
    return `${enlace.value.monedaSimbolo || enlace.value.moneda || ''} ${enlace.value.montoTotal}`;
});

const hayRecargo = computed(() => Number(enlace.value?.montoRecargo ?? 0) > 0.005);

const yaPagado = computed(() => enlace.value?.estado === 'pagado' || confirmado.value);

const noDisponible = computed(
    () => !!enlace.value && !enlace.value.vigente && enlace.value.estado !== 'pagado',
);

const mensajeNoDisponible = computed(() => {
    switch (enlace.value?.estado) {
        case 'expirado': return 'Este enlace de pago caducó. Pide uno nuevo y lo volvemos a enviar.';
        case 'anulado': return 'Este enlace de pago fue anulado.';
        default: return 'Este enlace de pago ya no está disponible.';
    }
});

/**
 * Carga el `<script>` y el CSS de la pasarela una sola vez.
 *
 * La clave pública va como atributo `kr-public-key` del propio `<script>`: la librería la
 * lee al inicializarse y no admite pasarla después. Por eso no se puede precargar el script
 * en `index.html` — hasta no consultar el enlace no sabemos con qué tienda se cobra.
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
            // El tema se añade después del cliente: al revés no encuentra la librería.
            document.head.appendChild(temaJs);
            resolve();
        };
        script.onerror = () => reject(new Error('No se pudo cargar el formulario de pago.'));

        document.head.appendChild(script);
    });
};

/** Pide un formToken nuevo y monta el formulario. Uno por carga: el token es de un solo uso. */
const montarFormulario = async (): Promise<void> => {
    const { data } = await apiClient.post<PaxFormToken>(`/finanzas/pago/${token.value}/form-token`, {});

    const KR = window.KR;
    if (!KR) throw new Error('El formulario de pago no está disponible.');

    await KR.setFormConfig({
        formToken: data.formToken,
        'kr-language': 'es-ES',
    });

    KR.onSubmit((respuesta) => {
        // No se da nada por cobrado con esto: sólo enciende la espera y arranca el sondeo
        // contra nuestro backend, que es quien escucha el webhook firmado.
        pagando.value = true;
        if (respuesta.clientAnswer.orderStatus === 'PAID') {
            sondearConfirmacion();
        }

        // `false` corta la redirección automática de la librería.
        return false;
    });

    KR.onError((err) => {
        error.value = err.errorMessage || 'El pago no se pudo completar. Inténtalo de nuevo.';
        pagando.value = false;
    });

    const { result } = await KR.attachForm('#izipay-formulario');
    await KR.showForm(result.formId);
};

/**
 * Pregunta al backend hasta que el webhook haya llegado.
 *
 * El IPN es servidor-a-servidor y suele tardar un par de segundos más que el navegador. Se
 * sondea 20 veces cada 2 s (~40 s) y luego se para: si a los 40 s no ha entrado, el pago no
 * se ha perdido —el webhook seguirá llegando— pero al cliente no se le deja girando, se le
 * dice que revisaremos y quede tranquilo.
 */
const sondearConfirmacion = (): void => {
    let intentos = 0;

    sondeoId = window.setInterval(async () => {
        intentos += 1;

        try {
            const { data } = await apiClient.get<PaxEnlacePago>(`/finanzas/pago/${token.value}`);
            enlace.value = data;

            if (data.estado === 'pagado') {
                confirmado.value = true;
                detenerSondeo();
            }
        } catch {
            // Un fallo de red puntual no aborta el sondeo: el siguiente tick reintenta.
        }

        if (intentos >= 20) detenerSondeo();
    }, 2000);
};

const detenerSondeo = (): void => {
    if (sondeoId !== null) {
        window.clearInterval(sondeoId);
        sondeoId = null;
    }
};

const cargar = async (): Promise<void> => {
    if (!token.value) {
        error.value = 'Enlace de pago incompleto.';
        cargando.value = false;
        return;
    }

    try {
        const { data } = await apiClient.get<PaxEnlacePago>(`/finanzas/pago/${token.value}`);
        enlace.value = data;

        if (data.vigente) {
            await cargarLibreria(data.staticUrl, data.publicKey);
            await montarFormulario();
        }
    } catch (err) {
        const status = (err as { response?: { status?: number } })?.response?.status;
        error.value = status === 404
            ? 'No encontramos este enlace de pago. Revisa que la dirección esté completa.'
            : 'No pudimos preparar el pago. Inténtalo en unos minutos.';
    } finally {
        cargando.value = false;
    }
};

onMounted(cargar);

onBeforeUnmount(() => {
    detenerSondeo();
    // Sin `removeForms` la librería deja el formulario colgado y al volver a entrar
    // aparece duplicado.
    void window.KR?.removeForms();
});
</script>

<template>
    <div class="min-h-screen bg-slate-50 flex items-start justify-center px-4 py-10">
        <div class="w-full max-w-md">

            <div v-if="cargando" class="text-center py-20 text-slate-400">
                <p class="text-sm font-medium">Preparando tu pago…</p>
            </div>

            <template v-else>
                <!-- Cobrado: el estado viene del backend, no del navegador -->
                <div v-if="yaPagado" class="bg-white rounded-2xl shadow-sm p-8 text-center">
                    <div class="w-14 h-14 rounded-full bg-emerald-50 text-emerald-600 mx-auto flex items-center justify-center text-2xl">✓</div>
                    <h1 class="mt-4 text-lg font-black text-slate-800">Pago recibido</h1>
                    <p class="mt-1 text-sm text-slate-500">{{ enlace?.concepto }}</p>
                    <p class="mt-4 text-2xl font-black text-slate-800">{{ importe }}</p>
                    <p v-if="enlace?.referencia" class="mt-3 text-xs text-slate-400">
                        Referencia {{ enlace.referencia }}
                    </p>
                </div>

                <!-- Pagó, pero la confirmación del servidor aún no ha entrado -->
                <div v-else-if="pagando" class="bg-white rounded-2xl shadow-sm p-8 text-center">
                    <h1 class="text-lg font-black text-slate-800">Confirmando tu pago…</h1>
                    <p class="mt-2 text-sm text-slate-500">
                        Tu banco ya autorizó la operación. En cuanto nos llegue la confirmación
                        lo verás aquí; si tarda, no vuelvas a pagar: nosotros lo revisamos.
                    </p>
                </div>

                <div v-else-if="noDisponible" class="bg-white rounded-2xl shadow-sm p-8 text-center">
                    <h1 class="text-lg font-black text-slate-800">Enlace no disponible</h1>
                    <p class="mt-2 text-sm text-slate-500">{{ mensajeNoDisponible }}</p>
                </div>

                <div v-else-if="error" class="bg-white rounded-2xl shadow-sm p-8 text-center">
                    <h1 class="text-lg font-black text-slate-800">No pudimos abrir el pago</h1>
                    <p class="mt-2 text-sm text-slate-500">{{ error }}</p>
                </div>

                <!-- Formulario incrustado de la pasarela -->
                <div v-else class="space-y-4">
                    <div class="bg-white rounded-2xl shadow-sm p-6">
                        <p class="text-[11px] font-black uppercase tracking-wide text-slate-400">A pagar</p>
                        <p class="mt-1 text-3xl font-black text-slate-800">{{ importe }}</p>
                        <p class="mt-2 text-sm text-slate-600">{{ enlace?.concepto }}</p>

                        <dl v-if="hayRecargo" class="mt-4 pt-4 border-t border-slate-100 space-y-1 text-xs text-slate-500">
                            <div class="flex justify-between">
                                <dt>Importe</dt>
                                <dd>{{ enlace?.monedaSimbolo }} {{ enlace?.montoNeto }}</dd>
                            </div>
                            <div class="flex justify-between">
                                <dt>Comisión de pago con tarjeta ({{ enlace?.recargoPorcentaje }}%)</dt>
                                <dd>{{ enlace?.monedaSimbolo }} {{ enlace?.montoRecargo }}</dd>
                            </div>
                        </dl>
                    </div>

                    <div class="bg-white rounded-2xl shadow-sm p-6">
                        <div id="izipay-formulario" class="kr-embedded" kr-form-expiry></div>
                    </div>

                    <p class="text-center text-[11px] text-slate-400">
                        Pago procesado por Izipay. Tus datos de tarjeta no pasan por nuestros servidores.
                    </p>
                </div>
            </template>
        </div>
    </div>
</template>
