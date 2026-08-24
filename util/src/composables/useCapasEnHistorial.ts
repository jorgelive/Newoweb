import { onMounted, onUnmounted } from 'vue';

/**
 * Capas —modales, fichas, modos de edición— que el gesto «atrás» CIERRA en vez de sacarte de la
 * pantalla.
 *
 * ── El problema ─────────────────────────────────────────────────────────────
 * En un móvil, «atrás» es el gesto que se usa para cerrar cualquier cosa: es el botón del sistema
 * y el deslizar desde el borde. Con un modal abierto no cerraba el modal, **navegaba**: estabas
 * revisando la ficha de un pasajero, deslizabas para volver a la lista, y aparecías en el Home.
 * Con el trabajo a medias y sin forma de regresar a donde estabas.
 *
 * ── Cómo ────────────────────────────────────────────────────────────────────
 * Al abrir una capa se empuja una entrada en el historial marcada con su nombre. El `popstate`
 * cierra la de arriba. Y **el cierre programático —la ✕, «Cancelar», la tecla Esc— NO cierra
 * directamente: llama a `history.back()`**, así que el cierre real ocurre siempre en el mismo
 * sitio, venga del gesto o del botón.
 *
 * ⚠️ Esa asimetría es deliberada. Cerrando a mano *y* dejando la entrada en el historial, el
 * siguiente «atrás» consumía una entrada fantasma y no hacía nada visible: había que pulsar dos
 * veces para salir de la pantalla, y nadie relaciona eso con el modal que cerró antes.
 *
 * Las capas se anidan: la ficha abierta es una, y pasar a editarla es otra encima. «Atrás» dentro
 * de la edición vuelve a la lectura, y el siguiente sale de la ficha — que es exactamente el
 * camino por el que se entró.
 *
 * ── Uso ─────────────────────────────────────────────────────────────────────
 * ```ts
 * const capas = useCapasEnHistorial();
 * const abrirFicha = () => { showModal.value = true; capas.abrir('ficha', () => showModal.value = false); }
 * const cerrarFicha = () => capas.cerrar('ficha');
 * ```
 */
export function useCapasEnHistorial() {
    /** La pila de capas abiertas, de abajo a arriba. */
    let pila: Array<{ nombre: string; alCerrar: () => void }> = [];

    /**
     * `popstate` también salta al navegar de verdad. Se distingue por el estado al que se llega:
     * si ya no lleva nuestra marca, la capa que había arriba se cierra igual —el usuario está
     * saliendo— pero sin volver a tocar el historial.
     */
    const alVolver = (): void => {
        const capa = pila.pop();
        if (capa) capa.alCerrar();
    };

    const abrir = (nombre: string, alCerrar: () => void): void => {
        pila.push({ nombre, alCerrar });
        history.pushState({ ...(history.state as Record<string, unknown> | null), capa: nombre }, '');
    };

    /**
     * Cierra una capa por su nombre, y las que tenga encima.
     *
     * Se hace retrocediendo el historial tantas veces como capas haya que quitar, para no dejar
     * entradas fantasma. Cada retroceso dispara `alVolver()`, que es quien cierra de verdad.
     */
    const cerrar = (nombre: string): void => {
        const desde = pila.findIndex(c => c.nombre === nombre);
        if (desde === -1) return;

        history.go(-(pila.length - desde));
    };

    /** ¿Hay alguna capa abierta? Útil para decidir si un atajo de teclado le pertenece. */
    const hayCapas = (): boolean => pila.length > 0;

    onMounted(() => window.addEventListener('popstate', alVolver));
    onUnmounted(() => {
        window.removeEventListener('popstate', alVolver);
        pila = [];
    });

    return { abrir, cerrar, hayCapas };
}
