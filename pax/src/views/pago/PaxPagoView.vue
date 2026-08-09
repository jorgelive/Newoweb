<script setup lang="ts">
/**
 * src/views/pago/PaxPagoView.vue
 *
 * Página de cobro que abre el cliente: `.pe/pago/{token}`.
 *
 * ## Por qué la página es nuestra y no un link de la pasarela
 *
 * Emitiendo la URL nosotros controlamos importe, vigencia, reintentos y el estado que ve el
 * operador en `util`. Y sobre todo: **el enlace no depende de la pasarela**. Cuando Izipay
 * resultó inaccesible (exige S/200 000 de venta para habilitar sus links) bastó con añadir
 * Culqi por debajo — los enlaces ya enviados habrían seguido funcionando. Ver §11 del doc.
 *
 * El formulario de tarjeta sí es de la pasarela y va incrustado: los datos de la tarjeta
 * NUNCA pasan por este código ni por nuestro servidor.
 *
 * ## Esta vista sólo orquesta
 *
 * Cada pasarela tiene su componente porque son dos librerías JS que no se parecen en nada.
 * Aquí queda lo común: cargar el enlace, pintar el importe y decidir qué pantalla toca.
 *
 * ## La confirmación NO la decide el navegador
 *
 * - **Culqi** confirma de verdad: el cobro lo cierra nuestro backend y `pagado` es fiable.
 * - **Izipay** avisa al navegador, pero quien manda es el IPN firmado. Por eso ese caso
 *   emite `procesando` y aquí se **sondea al backend** hasta verlo pagado.
 *
 * Espejo del backend: `App\Finanzas\Controller\Publico\FinPagoPublicoController`.
 */
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { useRoute } from 'vue-router';
import { apiClient } from '@/services/apiClient';
import PagoIzipayForm from '@/views/pago/PagoIzipayForm.vue';
import PagoCulqiForm from '@/views/pago/PagoCulqiForm.vue';
import type { PaxEnlacePago } from '@/types/paxPagoModel';

const route = useRoute();
const token = computed(() => String(route.params.token || '').trim());

const enlace = ref<PaxEnlacePago | null>(null);
const cargando = ref(true);
const error = ref<string | null>(null);

/** Pagó pero falta la confirmación del servidor (sólo Izipay). */
const esperandoConfirmacion = ref(false);
const confirmado = ref(false);

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

const consultar = async (): Promise<PaxEnlacePago> => {
    const { data } = await apiClient.get<PaxEnlacePago>(`/finanzas/pago/${token.value}`);
    enlace.value = data;
    return data;
};

/**
 * Sondea hasta que el IPN haya llegado (Izipay).
 *
 * 20 intentos cada 2 s (~40 s) y para. Si tarda más, el pago no se ha perdido —el IPN
 * seguirá llegando— pero al cliente no se le deja girando: se le dice que lo revisamos.
 */
const sondearConfirmacion = (): void => {
    let intentos = 0;

    sondeoId = window.setInterval(async () => {
        intentos += 1;
        try {
            const data = await consultar();
            if (data.estado === 'pagado') {
                confirmado.value = true;
                detenerSondeo();
            }
        } catch {
            // Fallo de red puntual: el siguiente tick reintenta.
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

/** Culqi: el backend ya confirmó. Se recarga una vez para pintar los datos del cobro. */
const alPagar = async (): Promise<void> => {
    confirmado.value = true;
    try {
        await consultar();
    } catch {
        // Da igual: `confirmado` ya manda y la pantalla de éxito está pintada.
    }
};

/** Izipay: el navegador dice que pagó, pero eso no es prueba. A sondear. */
const alProcesar = (): void => {
    esperandoConfirmacion.value = true;
    sondearConfirmacion();
};

const alError = (mensaje: string): void => {
    error.value = mensaje;
    esperandoConfirmacion.value = false;
};

onMounted(async () => {
    if (!token.value) {
        error.value = 'Enlace de pago incompleto.';
        cargando.value = false;
        return;
    }

    try {
        await consultar();
    } catch (err) {
        const status = (err as { response?: { status?: number } })?.response?.status;
        error.value = status === 404
            ? 'No encontramos este enlace de pago. Revisa que la dirección esté completa.'
            : 'No pudimos preparar el pago. Inténtalo en unos minutos.';
    } finally {
        cargando.value = false;
    }
});

onBeforeUnmount(detenerSondeo);
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

                <!-- Izipay: pagó, pero el IPN aún no ha entrado -->
                <div v-else-if="esperandoConfirmacion" class="bg-white rounded-2xl shadow-sm p-8 text-center">
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

                <!-- Importe + formulario de la pasarela que toque -->
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

                    <p v-if="error" class="px-4 py-3 rounded-xl bg-rose-50 text-rose-700 text-xs font-bold">
                        {{ error }}
                    </p>

                    <PagoCulqiForm
                        v-if="enlace?.pasarela === 'culqi'"
                        :token="token"
                        :moneda-simbolo="enlace?.monedaSimbolo"
                        :monto-total="enlace?.montoTotal ?? ''"
                        @pagado="alPagar"
                        @error="alError" />

                    <PagoIzipayForm
                        v-else-if="enlace?.pasarela === 'izipay'"
                        :token="token"
                        @procesando="alProcesar"
                        @error="alError" />

                    <p class="text-center text-[11px] text-slate-400">
                        Pago procesado por una pasarela certificada. Tus datos de tarjeta no
                        pasan por nuestros servidores.
                    </p>
                </div>
            </template>
        </div>
    </div>
</template>
