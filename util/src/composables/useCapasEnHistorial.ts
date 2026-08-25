import { computed, onUnmounted, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';

/**
 * Capas —modales, fichas, modos de edición— que el gesto «atrás» CIERRA en vez de sacarte de la
 * pantalla.
 *
 * ── El problema ─────────────────────────────────────────────────────────────
 * En un móvil, «atrás» es el gesto con el que se cierra cualquier cosa: el botón del sistema y el
 * deslizar desde el borde. Con un modal abierto no cerraba el modal, **navegaba**: estabas
 * revisando la ficha de un pasajero, deslizabas para volver a la lista, y aparecías en el Home.
 *
 * ── ⚠️ Por qué NO se usa `history.pushState` ────────────────────────────────
 * La primera versión empujaba entradas con `history.pushState` directamente. Funcionaba a medias y
 * fallaba de una forma desconcertante: **cerrar el modal te sacaba al listado de expedientes**.
 *
 * La causa es que vue-router lleva su propia contabilidad en `history.state` —`back`, `current`,
 * `forward`, `position`— y escucha `popstate` para navegar. Una entrada empujada por fuera copia
 * ese estado sin actualizar `position`, así que al volver el router cree que ha retrocedido en SU
 * historia y navega a la ruta anterior. No hay forma de arreglarlo sin reimplementar sus
 * interioridades.
 *
 * Aquí las capas viven en la QUERY (`?capa=pax.pax-edicion`). El router las empuja y las quita
 * como cualquier navegación, así que nunca se desincroniza — y de regalo la URL dice qué hay
 * abierto, que es lo que hace que recargar o compartir el enlace no mienta.
 *
 * ── Uso ─────────────────────────────────────────────────────────────────────
 * ```ts
 * const capas = useCapasEnHistorial();
 * const abrirFicha = () => { showModal.value = true; capas.abrir('pax', () => showModal.value = false); }
 * const cerrarFicha = () => capas.cerrar('pax');
 * ```
 *
 * ⚠️ **El cierre programático NO cierra: retrocede.** La ✕, «Cancelar» y el guardado pasan todos
 * por `cerrar()`, así que el cierre real ocurre siempre en el mismo sitio —el `watch` de la
 * query— venga del gesto o del botón. Cerrando a mano *y* dejando la entrada, el siguiente
 * «atrás» consumía una entrada fantasma y no hacía nada visible: había que pulsar dos veces para
 * salir, y nadie relaciona eso con el modal que cerró antes.
 *
 * Las capas se anidan: la ficha abierta es una, y pasar a editarla es otra encima.
 */
export function useCapasEnHistorial() {
    const router = useRouter();
    const route = useRoute();

    /** Qué cerrar cuando una capa desaparece de la ruta. */
    const cierres = new Map<string, () => void>();

    /** El punto es el separador: no aparece en los nombres, que son identificadores nuestros. */
    const capasDeLaRuta = computed<string[]>(() =>
        String(route.query.capa ?? '').split('.').filter(Boolean));

    const abrir = (nombre: string, alCerrar: () => void): void => {
        cierres.set(nombre, alCerrar);

        void router.push({
            query: { ...route.query, capa: [...capasDeLaRuta.value, nombre].join('.') },
        });
    };

    /**
     * Cierra una capa y las que tenga encima, retrocediendo tantas entradas como haga falta.
     *
     * `router.go()` y no un `push` con la query recortada: empujando quedaría una entrada más en
     * el historial y el «atrás» siguiente volvería a ABRIR el modal, que es lo contrario de lo que
     * espera cualquiera.
     */
    const cerrar = (nombre: string): void => {
        const desde = capasDeLaRuta.value.indexOf(nombre);
        if (desde === -1) return;

        router.go(-(capasDeLaRuta.value.length - desde));
    };

    const hayCapas = (): boolean => capasDeLaRuta.value.length > 0;

    // El cierre real ocurre AQUÍ y sólo aquí, venga del gesto atrás o de un botón. Se recorren las
    // que ya no están, de arriba abajo: cerrar la ficha tiene que cerrar antes su modo edición.
    watch(capasDeLaRuta, (ahora, antes) => {
        for (const nombre of [...(antes ?? [])].reverse()) {
            if (!ahora.includes(nombre)) {
                cierres.get(nombre)?.();
                cierres.delete(nombre);
            }
        }
    });

    // ⚠️ Al desmontar la vista se limpian los cierres pero NO se toca la ruta: quien navega fuera
    // ya está saliendo, y un `router.go()` aquí competiría con la navegación en curso.
    onUnmounted(() => cierres.clear());

    return { abrir, cerrar, hayCapas };
}
