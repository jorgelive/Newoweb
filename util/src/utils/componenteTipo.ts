/**
 * Reglas de negocio que cuelgan del TIPO de un componente.
 *
 * ⚠️ **Espejo de `App\Travel\Enum\ComponenteTipoEnum`** (`src/Travel/Enum/ComponenteTipoEnum.php`).
 * Si cambia la regla allí, se toca aquí y en `pax/src/views/cotizacion/PaxCotizacionGuiaView.vue`.
 * Son TRES copias y no cuatro porque este archivo existe: antes `util` llevaba la suya duplicada
 * en `OperacionView.vue` y el editor no la tenía en absoluto, que es de donde salió el fallo del
 * 31/08/2026.
 *
 * No se puede leer del backend: el vocabulario de tipos no se expone como recurso, y el intento
 * de hacerlo con `#[ApiResource]` sobre el enum rompe el guardado — ver `docs/Travel.md` §9.
 */

/**
 * ¿El nombre de la línea lo pone el SEGMENTO en vez del componente?
 *
 * Sí en los tres tipos que **nombran una ruta**: un traslado, un tren y un vuelo se cargan en el
 * catálogo como **un componente por ruta, no por sentido** («Transporte Aeropuerto Lima ↔
 * Miraflores (ida o vuelta)»), porque el mismo vehículo y el mismo precio sirven para ir y para
 * volver. Ver `docs/TravelCargaDeCatalogo.md` §2.
 *
 * La consecuencia es que **el nombre del componente no puede decir la dirección**, y encima lo
 * intenta: la flecha «↔» y el «(ida o vuelta)» son vocabulario de catálogo. Quien sí la dice es
 * el segmento, porque hay uno por sentido — «Transporte Aeropuerto Lima – Miraflores».
 *
 * Espejo de `ComponenteTipoEnum::mandaElSegmento()`, que delega en `nombraUnaRuta()`:
 *
 *   nombraUnaRuta() = TRANSPORTE || esSalto()      esSalto() = VUELO || TREN
 *
 * En los demás tipos el componente nombra lo comprado y su título es la prosa buena.
 */
export const mandaElSegmento = (tipo?: string | null): boolean =>
    tipo === 'transporte' || tipo === 'tren' || tipo === 'vuelo';
