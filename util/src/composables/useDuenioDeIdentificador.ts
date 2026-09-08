import { ref, computed, type Ref } from 'vue';
import { apiClient } from '@/services/apiClient';

/**
 * ¿De quién es ya este teléfono o este correo? — y qué va a pasar si lo guardas.
 *
 * ── Por qué es un composable y no una copia más ─────────────────────────────
 * 🔥 El aviso vivía dentro de `ContactoDeIdentidad`, que sólo está en el detalle del expediente y
 * en organizaciones. Faltaba justo donde más duele: al **abrir** un expediente y al **añadir un
 * identificador** en el editor de conversaciones — los dos sitios donde se teclea un número por
 * primera vez y donde el desenlace es invisible.
 *
 * Copiar el texto habría sido la tercera versión de la misma frase, y estas frases **describen lo
 * que el sistema va a hacer**: el día que cambie el comportamiento, una copia vieja no falla,
 * miente. Por eso el mensaje se redacta aquí y los tres lo consumen.
 *
 * ⚠️ **La normalización la hace el BACKEND**, no esto. El endpoint pasa el valor por
 * `IdentidadTipo::normalizar()`, que es exactamente lo que usará la resolución al guardar: si el
 * aviso mirara un valor y el guardado otro, diría lo contrario de lo que va a pasar.
 */
export interface DuenioDeIdentificador {
    conversacionId: string;
    nombre: string | null;
    /** Retirada: sigue resolviendo el historial, pero ya no es salida. */
    retirada: boolean;
    /** Lo que ese hilo ya atiende. La etiqueta la redacta el dominio. */
    asuntos?: Array<{ negocio: string; etiqueta: string }>;
}

export type TipoIdentificador = 'telefono' | 'email';

export function useDuenioDeIdentificador(
    /** ¿Este asunto YA tiene hilo propio? Cambia el desenlace, y por tanto el aviso. */
    yaTieneHilo: Ref<boolean> | (() => boolean) = () => false,
) {
    const duenio = ref<DuenioDeIdentificador | null>(null);
    const comprobando = ref(false);

    const tieneHilo = (): boolean =>
        typeof yaTieneHilo === 'function' ? yaTieneHilo() : yaTieneHilo.value;

    let temporizador: ReturnType<typeof setTimeout> | null = null;

    /**
     * Nº de la consulta en curso.
     *
     * ⚠️ Cancelar el `setTimeout` no cancela una petición **ya en vuelo**: se teclea, sale A; se
     * corrige, y A llega tarde y pinta el aviso del valor anterior — que ya no es cierto. Con el
     * contador, sólo la última contestación tiene derecho a escribir.
     */
    let secuencia = 0;

    /**
     * Con retardo: se teclea dígito a dígito y no hay que preguntar por cada uno.
     *
     * ⚠️ El resultado se limpia ANTES de la consulta, no después: si no, mientras se corrige un
     * número el aviso del anterior sigue en pantalla diciendo algo que ya no es verdad.
     */
    const comprobar = (tipo: TipoIdentificador, valor: string, retardo = 400): void => {
        if (temporizador) clearTimeout(temporizador);

        duenio.value = null;

        if (!valor || valor.trim().length < 4) return;

        comprobando.value = true;
        const mia = ++secuencia;

        temporizador = setTimeout(async () => {
            try {
                const { data } = await apiClient.get('/platform/message/identidades/duenio', {
                    params: { tipo, valor },
                });

                if (mia !== secuencia) return;

                duenio.value = (data?.duenio as DuenioDeIdentificador | null) ?? null;
            } catch {
                // Sin aviso es peor que con aviso, pero un error aquí no puede frenar el
                // formulario: se falla en silencio y el guardado dirá lo que sea.
                if (mia === secuencia) duenio.value = null;
            }
            if (mia === secuencia) comprobando.value = false;
        }, retardo);
    };

    /**
     * Qué va a pasar EXACTAMENTE al guardar. **No las dos opciones: la que toca.**
     *
     * Lo decide una sola cosa —si este asunto ya tiene hilo propio— y eso ya se sabe, así que
     * enunciar los dos finales era pedirle al operador que dedujera lo que el sistema ya resolvió.
     */
    const aviso = computed<string | null>(() => {
        const d = duenio.value;

        if (!d) return null;

        const quien = d.nombre || 'otra persona';
        const suyos = (d.asuntos ?? []).map(a => a.etiqueta).join(' · ');
        const conQue = suyos ? ` — que ya atiende: ${suyos}` : '';

        return tieneHilo()
            // Con hilo propio no hay fusión posible: el identificador es único y no se le quita a
            // su dueño. Se dice que NO se va a guardar, y cuál es la salida de verdad.
            ? `No se guardará: ya es de ${quien}${conQue}. Un identificador no se le puede quitar a `
              + 'su dueño. Si son la misma persona, hay que fusionar las dos conversaciones.'
            // Sin hilo propio, la unión es el resultado, y hay que decirlo con nombre y asunto.
            : `Al guardar, este asunto se unirá a la conversación de ${quien}${conQue}. `
              + 'Si no son la misma persona, corrige el dato antes de guardar.';
    });

    /**
     * Qué pasaría al fusionar con el hilo que ya tiene este identificador.
     *
     * ⚠️ **Se pregunta antes de aplicar y no se deduce.** Quién sobrevive lo decide la antigüedad
     * —el hilo viejo tiene el historial largo y los enlaces que ya funcionan— y eso lo sabe el
     * backend, no esta pantalla. Enseñar aquí una suposición y que el servidor hiciera otra cosa
     * sería peor que no enseñar nada.
     */
    const previaDeFusion = async (miHiloId: string): Promise<{
        superviviente: { id: string; nombre: string | null; mensajes: number; asuntos: number };
        absorbido: { id: string; nombre: string | null; mensajes: number; asuntos: number };
    } | null> => {
        const otro = duenio.value?.conversacionId;

        if (!otro || !miHiloId) return null;

        try {
            const { data } = await apiClient.get(
                `/platform/message/conversations/${miHiloId}/fusion/previa`,
                { params: { con: otro } },
            );

            return data;
        } catch {
            return null;
        }
    };

    /**
     * Aplica la fusión. **No se deshace**: los mensajes quedan en una sola línea de tiempo.
     *
     * ⚠️ Devuelve el id del SUPERVIVIENTE, que puede no ser el hilo desde el que se pulsó: lo
     * decide la antigüedad. Si quien llama se queda donde estaba, se queda mirando un hilo
     * archivado y **sin identidades** —y dentro del chat, pudiendo escribirle a números que ya no
     * son suyos—.
     */
    const fusionar = async (miHiloId: string): Promise<{ supervivienteId: string } | { error: string }> => {
        const otro = duenio.value?.conversacionId;

        if (!otro || !miHiloId) return { error: 'No sé con qué hilo fusionar.' };

        try {
            const { data } = await apiClient.post(
                `/platform/message/conversations/${miHiloId}/fusion`,
                { con: otro },
            );

            return { supervivienteId: String(data?.supervivienteId ?? miHiloId) };
        } catch (e: unknown) {
            const r = e as { response?: { data?: { error?: string } } };

            return { error: r.response?.data?.error ?? 'No se pudo fusionar.' };
        }
    };

    const limpiar = (): void => {
        if (temporizador) clearTimeout(temporizador);
        ++secuencia;
        duenio.value = null;
        comprobando.value = false;
    };

    return { duenio, comprobando, aviso, comprobar, limpiar, previaDeFusion, fusionar };
}
