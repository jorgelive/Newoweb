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
import type { CulqiCheckoutInstance, Parametros3DS } from '@/types/culqiCheckout';

const props = defineProps<{ token: string; monedaSimbolo?: string | null; montoTotal: string }>();

const emit = defineEmits<{
    /** Cobro confirmado por NUESTRO backend, no por el navegador. */
    (e: 'pagado'): void;
    (e: 'error', mensaje: string): void;
}>();

const listo = ref(false);
const cobrando = ref(false);

/**
 * Cuánto se espera al reto del banco antes de rendirse. **Es un respaldo, no el plazo.**
 *
 * El plazo lo pone la librería: su propia sesión dura **diez minutos** y al agotarse emite
 * `SESSION_EXPIRED` («Han pasado 10 minutos»), que nos llega como `error` y corta limpiamente.
 * Esto es sólo la red por si ese aviso no llega nunca.
 *
 * ⚠️ **Estuvo en cinco, y era rendirse antes que el banco.** Al minuto cinco rechazábamos
 * mientras la ventana del banco seguía abierta: si el titular terminaba de teclear su clave en
 * el seis, autenticaba para nada. Once minutos deja que hable primero quien sabe si el reto
 * sigue vivo.
 */
const MINUTOS_DE_RETO = 11;

/** Lo que el reto necesita saber, guardado al montar: viene de la misma configuración. */
const publicKey = ref('');
const emailCliente = ref<string | null>(null);
const montoCentimos = ref(0);

/**
 * La moneda, para el reto 3DS.
 *
 * ⚠️ **Estrechada aquí, y no en el modelo.** El servidor manda un `string` sin acotar
 * (`getMonedaCodigo() ?? 'PEN'`), pero Culqi sólo admite estas dos —lo valida su propio bundle—,
 * así que quien la usa es quien decide qué hacer con lo demás. PEN es el mismo respaldo que usa
 * el servidor, no una elección nueva.
 */
const moneda = ref<'PEN' | 'USD'>('PEN');

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

/**
 * Carga un `<script>` externo una sola vez.
 *
 * 🔥 **La guarda pregunta por SU global, y ésa es toda la corrección.** Antes era
 * `if (window.CulqiCheckout) return` para las dos librerías: la primera llamada cargaba el
 * checkout y definía ese global, y la segunda —la del 3DS— entraba, lo veía puesto y se iba
 * **sin insertar nada**. `window.Culqi3DS` sólo lo define `culqi3ds.min.js`, así que el reto
 * moría en su primera línea con «No se pudo cargar la autenticación del banco», que para el
 * huésped extranjero es el mismo «no puedo pagar» de siempre con otro texto.
 *
 * Una guarda que mira el global equivocado no falla: convierte la carga en un no-op.
 */
const cargarLibreria = (src: string, yaCargada: () => boolean): Promise<void> => {
    if (yaCargada()) return Promise.resolve();

    return new Promise((resolve, reject) => {
        const script = document.createElement('script');
        script.src = src;
        script.onload = () => resolve();
        script.onerror = () => reject(new Error('No se pudo cargar el formulario de pago.'));
        document.head.appendChild(script);
    });
};

/**
 * Pasa el reto 3-D Secure y devuelve los cinco parámetros que Culqi pide para reintentar.
 *
 * 🔥 **Sin esto no puede pagar ninguna tarjeta extranjera.** Medido entre el 26/08 y el 05/09: las
 * peruanas pasaban y las de España y Australia se denegaban siempre con `DNGE0116` —«sospecha de
 * fraude, se solicita autenticación 3DS»—. No era un fallo del cobro: es que el reto no existía.
 *
 * ⚠️ **El resultado llega por `window.postMessage`, no por callback.** Lo emite la propia
 * librería (`_postMessageAuthentication` en `culqi3ds.min.js`):
 *
 * ```js
 * window.postMessage({ parameters3DS, error, loading }, window.location.origin)
 * ```
 *
 * Se filtra por `origin` —es la única defensa: cualquier iframe de la página puede mandar
 * mensajes— y se ignora el `loading`, que son avisos de progreso, no resultados.
 *
 * ⚠️ **Con `timeout`.** Si el titular cierra el modal del banco o su móvil no le llega el SMS, no
 * llega ni resultado ni error: sin plazo, la promesa no se resuelve nunca y el botón se queda en
 * «Procesando…» para siempre.
 */
/**
 * Suelta el reto en curso al desmontar. Ver `onBeforeUnmount`.
 *
 * Sólo desengancha: no resuelve ni rechaza, porque a estas alturas ya no hay a quién contarle
 * nada — la promesa se queda colgada y se la lleva el recolector con el componente.
 */
let soltarReto: (() => void) | null = null;

const abandonarReto = (): void => {
    soltarReto?.();
};

const autenticar3DS = (tokenTarjeta: string): Promise<Parametros3DS> =>
    new Promise((resolve, reject) => {
        const Culqi3DS = window.Culqi3DS;

        if (!Culqi3DS) {
            reject(new Error('No se pudo cargar la autenticación del banco.'));

            return;
        }

        const limpiar = (): void => {
            window.removeEventListener('message', escuchar);
            clearTimeout(reloj);
            soltarReto = null;
        };

        const reloj = window.setTimeout(() => {
            limpiar();
            reject(new Error('La autenticación con tu banco tardó demasiado. Inténtalo otra vez.'));
        }, MINUTOS_DE_RETO * 60_000);

        function escuchar(evento: MessageEvent): void {
            if (evento.origin !== window.location.origin) return;

            const datos = evento.data as { parameters3DS?: Parametros3DS; error?: string | null };

            if (datos?.parameters3DS) {
                limpiar();
                resolve(datos.parameters3DS);
            } else if (datos?.error) {
                limpiar();
                reject(new Error(String(datos.error)));
            }
        }

        window.addEventListener('message', escuchar);
        soltarReto = limpiar;

        Culqi3DS.publicKey = publicKey.value;

        // ⚠️ **`currency` es obligatorio en la práctica**: el bundle arranca en PEN y su setter
        // MEZCLA lo que le des, así que omitirlo no hereda nada — deja PEN. Con un enlace en
        // dólares se pedía autenticar el importe en soles, y las cinco denegaciones reales que
        // motivaron todo esto fueron en USD.
        //
        // ⚠️ Y el `email` **sólo si lo hay**: el checkout ya escribe el suyo en esta misma
        // instancia al tokenizar (`Culqi3DS._settings.card.email = …`), y mandar `undefined`
        // explícito lo BORRA por el mismo spread. En las reservas directas no hay email, que es
        // justo cuando se perdería.
        Culqi3DS.settings = {
            card: emailCliente.value !== null ? { email: emailCliente.value } : {},
            charge: {
                totalAmount: montoCentimos.value,
                currency: moneda.value,
                returnUrl: window.location.href,
            },
        };

        void Culqi3DS.initAuthentication(tokenTarjeta);
    });

/**
 * Canjea el token por un cargo real. Es el único punto que confirma el pago.
 *
 * ⚠️ **El primer intento va SIN los parámetros 3DS**, y no es una optimización: lo dice el propio
 * SDK de Culqi —«la primera vez que se consume el servicio no se debe enviar los parámetros
 * 3ds»—. Si el banco los pide, el servidor contesta `409 requiere_3ds`, se pasa el reto y se
 * repite el MISMO cobro con ellos.
 *
 * ⚠️ **Un solo reintento.** Si el segundo también se deniega, es un no de verdad: repetir el reto
 * en bucle sólo pasea al titular por la pantalla de su banco.
 */
/**
 * Qué lee el huésped cuando el error viene sin `mensaje`.
 *
 * ⚠️ **Antes se enseñaba el código tal cual.** El respaldo era `data.error`, que es nuestro
 * identificador de máquina: quien se topaba con esto leía literalmente `cargo_no_valido` o
 * `no_vigente` en mitad de la pantalla de pago. Un código en la cara del cliente no le dice qué
 * hacer y encima parece una avería.
 */
const textoDelError = (codigo?: string): string => {
    const textos: Record<string, string> = {
        no_vigente: 'Este enlace de pago ya no está disponible. Pídenos uno nuevo.',
        no_encontrado: 'Este enlace de pago no existe. Comprueba el enlace que te enviamos.',
        cargo_no_valido: 'Tu banco no confirmó el cobro. No se te ha cobrado nada; inténtalo otra vez.',
        token_requerido: 'No pudimos leer los datos de la tarjeta. Inténtalo otra vez.',
    };

    return (codigo !== undefined ? textos[codigo] : undefined) ?? 'El pago no se pudo completar.';
};

const cobrar = async (tokenTarjeta: string, autenticacion3DS?: Parametros3DS): Promise<void> => {
    cobrando.value = true;
    try {
        const { data } = await apiClient.post<PaxCulqiCobroRespuesta>(
            `/finanzas/pago/${props.token}/culqi/cobrar`,
            autenticacion3DS ? { token: tokenTarjeta, autenticacion3DS } : { token: tokenTarjeta },
        );
        if (data.ok) emit('pagado');
        else emit('error', 'No pudimos confirmar el pago.');
    } catch (err) {
        const respuesta = (err as { response?: { status?: number; data?: { mensaje?: string; error?: string } } })?.response;
        const data = respuesta?.data;

        // 409 = el banco pide autenticar al titular. No es un rechazo: es un paso más.
        if (respuesta?.status === 409 && data?.error === 'requiere_3ds' && !autenticacion3DS) {
            try {
                const parametros = await autenticar3DS(tokenTarjeta);
                await cobrar(tokenTarjeta, parametros);
            } catch (fallo3DS) {
                emit('error', fallo3DS instanceof Error ? fallo3DS.message : 'No se pudo autenticar con tu banco.');
            }

            return;
        }

        // 402 = el banco rechazó. El backend ya lo dejó como FALLIDO, que NO es final:
        // el cliente puede reintentar con otra tarjeta en el mismo enlace.
        emit('error', data?.mensaje || textoDelError(data?.error));
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
    publicKey.value = config.publicKey;
    emailCliente.value = config.email ?? null;
    montoCentimos.value = config.amount;
    moneda.value = config.currency === 'USD' ? 'USD' : 'PEN';

    // Las DOS librerías, cada una con su propio testigo. El checkout ya la busca
    // —`window.Culqi3DS && …` en su bundle— pero nunca la cargaba nadie, así que ese `&&`
    // siempre era falso y el reto no existía.
    await cargarLibreria(config.checkoutJs, () => Boolean(window.CulqiCheckout));
    await cargarLibreria(config.culqi3dsJs, () => Boolean(window.Culqi3DS));

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

    // ⚠️ Y el reto, si estaba a medias. Sin esto, un `postMessage` que llega después de que la
    // persona haya navegado dispara `cobrar()` desde un componente muerto: el cargo se hace y el
    // enlace queda pagado, pero nadie ve la confirmación y un segundo intento choca con un 410.
    abandonarReto();
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
