/**
 * Reglas de negocio que cuelgan del TIPO de un componente.
 *
 * ⚠️ **Espejo de `App\Travel\Enum\ComponenteTipoEnum`** (`src/Travel/Enum/ComponenteTipoEnum.php`).
 * Si cambia la regla allí, se toca aquí — y **sólo aquí**: `util` y `pax` importan de este archivo.
 *
 * Fueron TRES copias hasta el 07/09/2026: el enum, `util/src/utils/componenteTipo.ts` y una copia
 * inline en la guía del huésped, que no puede importar de `util` porque son dos apps distintas.
 * `dominio/` es justo el sitio que las dos sí comparten.
 *
 * No se puede leer del backend: el vocabulario de tipos no se expone como recurso, y el intento de
 * hacerlo con `#[ApiResource]` sobre el enum rompe el guardado — ver `docs/Travel.md` §9. Las
 * tablas que SÍ viajan serializadas —`ordenNarrativo`, la unidad de conteo— lo hacen porque cuelgan
 * de un componente concreto; ésta se pregunta sobre un tipo suelto, a veces sin componente delante.
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
 * intenta: la flecha «↔» y el «(ida o vuelta)» son vocabulario de catálogo. Quien sí la dice es el
 * segmento, porque hay uno por sentido — «Transporte Aeropuerto Lima – Miraflores».
 *
 * Espejo de `ComponenteTipoEnum::mandaElSegmento()`, que delega en `nombraUnaRuta()`:
 *
 *   nombraUnaRuta() = TRANSPORTE || esSalto()      esSalto() = VUELO || TREN
 *
 * En los demás tipos el componente nombra lo comprado y su título es la prosa buena.
 */
export const mandaElSegmento = (tipo?: string | null): boolean =>
  tipo === 'transporte' || tipo === 'tren' || tipo === 'vuelo';
