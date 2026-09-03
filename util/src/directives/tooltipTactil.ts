import type { Directive } from 'vue';

/**
 * El `title` de un botón, visible también en táctil — con una pulsación larga.
 *
 * ── El problema ─────────────────────────────────────────────────────────────
 * Una fila de ocho botones-icono se lee bien en escritorio porque el `title` nativo aparece al
 * pasar por encima. En un móvil **no hay hover**: los mismos ocho iconos son ocho adivinanzas, y
 * uno de ellos traspasa las órdenes de servicio.
 *
 * ⚠️ **No sustituye al `title`, lo acompaña.** El nativo se queda para escritorio —es más rápido,
 * lo dibuja el sistema y respeta las preferencias de accesibilidad—; esto sólo cubre el caso que
 * aquél no puede.
 *
 * ── Por qué una directiva y no un componente ────────────────────────────────
 * Envolver cada botón en un componente obligaría a tocar los ocho y a pasarles el texto dos veces.
 * La directiva **lee el `title` que ya está ahí**, así que un botón nuevo lo hereda con sólo
 * escribir `v-tooltip-tactil`, y un botón sin `title` no hace nada.
 *
 * ```html
 * <button v-tooltip-tactil title="Abrir la propuesta operativa">…</button>
 * ```
 */

const RETARDO_MS = 400;
const VISIBLE_MS = 2200;

interface EstadoTooltip {
    temporizador?: number;
    ocultar?: number;
    globo?: HTMLElement;
}

const estados = new WeakMap<HTMLElement, EstadoTooltip>();

const quitar = (el: HTMLElement): void => {
    const estado = estados.get(el);

    if (!estado) {
        return;
    }

    window.clearTimeout(estado.temporizador);
    window.clearTimeout(estado.ocultar);
    estado.globo?.remove();
    estado.globo = undefined;
};

const mostrar = (el: HTMLElement): void => {
    const texto = el.getAttribute('title');

    if (!texto) {
        return;
    }

    const globo = document.createElement('div');
    globo.textContent = texto;
    globo.className = 'fixed z-50 px-3 py-2 rounded-xl bg-slate-800 text-white text-[11px] font-bold '
        + 'leading-snug shadow-lg pointer-events-none max-w-60';

    document.body.appendChild(globo);

    // Se coloca DESPUÉS de medirlo: un globo de dos líneas mide distinto que uno de una, y
    // calcularlo antes lo deja saliéndose por arriba justo en los textos largos.
    const caja = el.getBoundingClientRect();
    const suyo = globo.getBoundingClientRect();

    globo.style.left = `${Math.max(8, Math.min(window.innerWidth - suyo.width - 8, caja.left + caja.width / 2 - suyo.width / 2))}px`;
    globo.style.top = caja.top > suyo.height + 12 ? `${caja.top - suyo.height - 8}px` : `${caja.bottom + 8}px`;

    const estado = estados.get(el) ?? {};
    estado.globo = globo;
    estado.ocultar = window.setTimeout(() => quitar(el), VISIBLE_MS);
    estados.set(el, estado);
};

export const tooltipTactil: Directive<HTMLElement> = {
    mounted(el) {
        const empezar = (): void => {
            quitar(el);

            const estado = estados.get(el) ?? {};
            estado.temporizador = window.setTimeout(() => mostrar(el), RETARDO_MS);
            estados.set(el, estado);
        };

        const parar = (): void => quitar(el);

        // ⚠️ `passive: true` en `touchstart`: no se llama a `preventDefault()` —el botón tiene que
        // seguir funcionando con un toque normal— y declararlo evita que el navegador retrase el
        // desplazamiento esperando a ver si lo cancelamos.
        el.addEventListener('touchstart', empezar, { passive: true });
        el.addEventListener('touchend', parar);
        el.addEventListener('touchmove', parar, { passive: true });
        el.addEventListener('touchcancel', parar);

        // Al desplazar la página el globo se quedaría flotando donde ya no hay botón.
        window.addEventListener('scroll', parar, { passive: true });

        estados.set(el, {});
    },

    // Sin esto, un globo abierto sobrevive a su botón: la tarjeta se va y el texto se queda.
    unmounted(el) {
        quitar(el);
        estados.delete(el);
    },
};
