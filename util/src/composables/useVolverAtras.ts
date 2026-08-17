import { useRouter, type RouteLocationRaw } from 'vue-router';

/**
 * Un «volver» que respeta DE DÓNDE vino el usuario.
 *
 * ── El problema que resuelve ────────────────────────────────────────────────
 * Los botones de retroceso de la app navegaban a una ruta FIJA —«volver al dashboard de
 * cotizaciones»—, así que llegar al editor desde La Biblia y pulsar atrás te dejaba en un
 * sitio en el que nunca habías estado, perdiendo tu contexto. Lo que uno espera de «atrás»
 * es volver a donde estaba, no a un sitio que el programador eligió por él.
 *
 * ── Cómo ────────────────────────────────────────────────────────────────────
 * Si hay una entrada anterior EN EL HISTORIAL DEL SPA (`history.state.back` no es null),
 * `router.back()` — vuelve exactamente a la pantalla previa, sea cual sea. Sólo cuando no la
 * hay —se entró por un enlace directo, un deep link, o recién se abrió la app— se cae al
 * destino fijo, que es lo razonable ahí: no hay «atrás» posible.
 *
 * `history.state.back` y no `window.history.length`: el segundo cuenta también páginas de
 * fuera del SPA, así que no distingue «vine de otra vista» de «esta es mi primera parada».
 *
 * ── Uso ─────────────────────────────────────────────────────────────────────
 * ```ts
 * const volver = useVolverAtras();
 * const handleVolver = () => volver('/cotizacion');   // el fallback del deep link
 * ```
 */
export function useVolverAtras() {
    const router = useRouter();

    return (fallback: RouteLocationRaw): void => {
        if (router.options.history.state.back != null) {
            router.back();
        } else {
            void router.push(fallback);
        }
    };
}
