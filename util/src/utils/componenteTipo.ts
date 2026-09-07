/**
 * ⚠️ **La regla ya no vive aquí: está en `@dominio/cotizacion`.**
 *
 * Eran TRES copias —el enum de PHP, ésta y una inline en la guía del huésped— y las dos de
 * TypeScript no podían compartirse porque `util` y `pax` son apps distintas. `dominio/` es el
 * sitio que ambas sí comparten, así que allí se quedó una sola.
 *
 * Este archivo sobrevive como puerta para no tocar a sus consumidores, y porque el import por
 * ruta corta es lo que ya tienen escrito.
 */
export { mandaElSegmento } from '@dominio/cotizacion/index.ts';
