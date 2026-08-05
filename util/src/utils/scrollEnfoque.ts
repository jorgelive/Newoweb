/**
 * Llevar a la vista un bloque que se acaba de abrir.
 *
 * Nace de un problema repetido en formularios largos: al desplegar un acordeón o abrir un
 * mini-formulario, el navegador no mueve el scroll. El bloque crece hacia abajo y el operador
 * se queda mirando la mitad de unos campos sin ver su cabecera, que es justo lo que dice de
 * QUÉ son. Pasa en el acordeón de estancias del drawer de reservas y en los formularios de
 * cargo y pago del panel financiero, así que la aritmética vive aquí una sola vez.
 *
 * **Por qué no `Element.scrollIntoView()`:** ese método escala hasta el primer ancestro
 * desplazable y, con un drawer abierto sobre la página, acaba moviendo también el fondo. Aquí
 * se localiza el scroller real y se le desplaza solo a él.
 */

/** Aire que se deja sobre el bloque enfocado, para que no quede pegado al borde. */
const MARGEN_POR_DEFECTO_PX = 12;

/**
 * ¿Este elemento es el que desplaza a sus hijos?
 *
 * Se exige `scrollHeight > clientHeight` además del `overflow`: un contenedor marcado como
 * `overflow-y: auto` pero sin contenido que sobre no desplaza nada, y tomarlo por scroller
 * dejaría el bloque igual de escondido.
 */
function esDesplazable(el: HTMLElement): boolean {
    const { overflowY } = getComputedStyle(el);
    if (overflowY !== 'auto' && overflowY !== 'scroll') return false;
    return el.scrollHeight > el.clientHeight;
}

/** El ancestro que de verdad desplaza a `el`, o `null` si lo hace la ventana. */
export function scrollerDe(el: HTMLElement): HTMLElement | null {
    let actual = el.parentElement;
    while (actual) {
        if (esDesplazable(actual)) return actual;
        actual = actual.parentElement;
    }
    return null;
}

export interface OpcionesEnfoque {
    /** Scroller explícito. Si no se pasa, se busca subiendo por los ancestros. */
    scroller?: HTMLElement | null;
    /** Aire sobre el bloque, en píxeles. */
    margen?: number;
    /**
     * ¿Animado? Conviene `false` cuando el bloque aparece a la vez que otra animación —un
     * drawer entrando, por ejemplo—: dos movimientos simultáneos se leen como un fallo.
     */
    suave?: boolean;
}

/**
 * Desplaza el scroller para dejar `el` arriba del todo.
 *
 * El llamador debe haber esperado a que el DOM esté actualizado (`await nextTick()`): la
 * posición se mide con `getBoundingClientRect()`, y antes del repintado el bloque todavía
 * está plegado o ni existe.
 */
export function enfocarEnScroller(el: HTMLElement | null, opciones: OpcionesEnfoque = {}): void {
    // `isConnected` no es paranoia: los `ref` de función conservan el último elemento visto,
    // y si ese bloque ya se cerró el nodo sigue existiendo pero fuera del documento. Medirlo
    // devolvería ceros y el scroll saltaría al principio de la lista.
    if (!el || !el.isConnected) return;

    const scroller = opciones.scroller ?? scrollerDe(el);
    if (!scroller) return;

    const margen = opciones.margen ?? MARGEN_POR_DEFECTO_PX;
    const delta = el.getBoundingClientRect().top - scroller.getBoundingClientRect().top;

    scroller.scrollTo({
        top: Math.max(0, scroller.scrollTop + delta - margen),
        behavior: opciones.suave === false ? 'auto' : 'smooth',
    });
}
