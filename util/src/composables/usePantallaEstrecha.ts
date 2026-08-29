import { onUnmounted, readonly, ref } from 'vue';

/**
 * ¿La pantalla es estrecha? Para decisiones de comportamiento, no de estilo.
 *
 * Lo que se puede resolver con una clase de Tailwind se resuelve con una clase. Esto existe para
 * lo que hay que pasarle a un componente como **prop**, que es donde CSS no llega: el ejemplo que
 * lo trajo es `teleport-center` de `VueDatePicker`.
 *
 * ⚠️ El umbral es 640 px —el `sm` de Tailwind— a propósito: si aquí se elige otro número, el
 * comportamiento salta en un ancho distinto al del diseño y nadie entiende por qué.
 */
export function usePantallaEstrecha(umbral = 640) {
    const consulta = window.matchMedia(`(max-width: ${umbral - 1}px)`);
    const esEstrecha = ref(consulta.matches);

    const alCambiar = (e: MediaQueryListEvent) => { esEstrecha.value = e.matches; };
    consulta.addEventListener('change', alCambiar);

    onUnmounted(() => consulta.removeEventListener('change', alCambiar));

    return { esEstrecha: readonly(esEstrecha) };
}
