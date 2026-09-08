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
        temporizador = setTimeout(async () => {
            try {
                const { data } = await apiClient.get('/platform/message/identidades/duenio', {
                    params: { tipo, valor },
                });
                duenio.value = (data?.duenio as DuenioDeIdentificador | null) ?? null;
            } catch {
                // Sin aviso es peor que con aviso, pero un error aquí no puede frenar el
                // formulario: se falla en silencio y el guardado dirá lo que sea.
                duenio.value = null;
            }
            comprobando.value = false;
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

    const limpiar = (): void => {
        if (temporizador) clearTimeout(temporizador);
        duenio.value = null;
        comprobando.value = false;
    };

    return { duenio, comprobando, aviso, comprobar, limpiar };
}
