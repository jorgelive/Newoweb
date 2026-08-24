import { onMounted, onUnmounted } from 'vue';

/**
 * Cómo se entera una vista de que el asistente le cambió los datos bajo los pies.
 *
 * ── El problema ─────────────────────────────────────────────────────────────
 * El asistente escribe en el PMS —«registra la salida a las 8», «apunta el pago»— y lo que hay en
 * pantalla se queda viejo. Embebido en el Home eso se resolvía solo: emitía y el Home recargaba
 * su panel. Al mudarlo al armazón (`AsistenteFlotante`) se perdió, porque **desde el armazón no se
 * sabe qué pinta la pantalla de turno**, y recargar la página entera tira lo que el operador
 * estuviera editando sin guardar.
 *
 * ── Cómo ────────────────────────────────────────────────────────────────────
 * La vista dice qué hacer, y sólo mientras está montada:
 *
 * ```ts
 * useRefrescoDelAsistente(() => cargarPanelHoy());
 * ```
 *
 * ⚠️ **Lo importante no es el aviso, es saber si alguien lo escucha.** Si la vista actual se
 * refresca sola, el asistente no enseña nada; si no hay nadie escuchando, ofrece recargar la
 * página, que es lo único que puede prometer. Sin `hayQuienEscuche()` habría que elegir entre
 * molestar siempre o dejar datos viejos en las pantallas que no se registraron — y las dos son
 * peores.
 *
 * Registro por función y no por nombre de ruta: una ruta puede montar varias vistas y una vista
 * puede vivir en varias rutas. Lo que importa es qué hay en pantalla AHORA.
 */
const oyentes = new Set<() => void>();

export function useRefrescoDelAsistente(refrescar: () => void): void {
    onMounted(() => oyentes.add(refrescar));
    onUnmounted(() => oyentes.delete(refrescar));
}

/** ¿Hay alguna vista montada que sepa refrescarse? Lo consulta `AsistenteFlotante`. */
export function hayQuienEscuche(): boolean {
    return oyentes.size > 0;
}

/**
 * Avisa a las vistas montadas.
 *
 * Se copia el conjunto antes de recorrerlo: un refresco puede desmontar algo —cerrar un modal,
 * navegar— y modificar el `Set` mientras se itera.
 */
export function avisarDeCambio(): void {
    for (const refrescar of [...oyentes]) {
        refrescar();
    }
}
