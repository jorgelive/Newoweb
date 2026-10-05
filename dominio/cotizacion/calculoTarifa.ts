/**
 * **Cómo se cuenta el dinero de una tarifa**: por persona, por grupo, o repartido y oculto.
 *
 * ⚠️ **Espejo de `App\Travel\Enum\TarifaCalculoEnum`** (`src/Travel/Enum/TarifaCalculoEnum.php`).
 * Si cambia una regla allí, se toca aquí — y **sólo aquí**: `util` y `pax` importan de este
 * archivo. Ver `docs/PlanModalidadDeTarifa.md`.
 *
 * ⚠️ **No confundir con `MODALIDAD_CONFIG`** de `util` (`privado`/`compartido`), que es el nivel de
 * exclusividad del servicio y no tiene nada que ver con la aritmética. El panel llama «Modalidad de
 * Cálculo» a esto y «Modalidad» a aquello, que es lo que invita a mezclarlos.
 *
 * ## ⚠️ Por qué son tres predicados y no un booleano
 *
 * El `esGrupal` al que sustituye gobernaba **dos comportamientos a la vez**:
 *
 *     × 1 en vez de × cantidad        al calcular el total
 *     / numPax                        al sacar la cifra por persona
 *
 * En `individual` y `grupal` van juntos, y por eso un booleano parecía bastar. **`operativa` los
 * rompe: multiplica por cantidad Y se prorratea** — son los liberados de un grupo, cinco vuelos
 * que el grupo paga entre todos.
 *
 * 🔑 Los diez puntos del clasificador que hoy preguntan `esGrupal` **no hacen la misma pregunta**:
 * unos preguntan cómo multiplicar y otros si repartir. Por eso cada uno llama al predicado que le
 * toca en vez de comparar con un valor del enum: un reemplazo mecánico dejaría la operativa sin
 * prorratear, y eso sale como una cifra por persona baja, plausible y revisada por nadie.
 *
 * ```
 *                multiplicaPorCantidad   seProrratea   visibleParaCliente
 * individual            sí                    no              sí
 * grupal                no (× 1)              sí              sí
 * operativa             sí                    sí              NO
 * ```
 */

export const CALCULOS_TARIFA = ['individual', 'grupal', 'operativa'] as const;

export type CalculoTarifa = (typeof CALCULOS_TARIFA)[number];

/** Cómo se nombra en pantalla. Espejo de `TarifaModalidadEnum::etiqueta()`. */
export const ETIQUETAS_CALCULO: Record<CalculoTarifa, string> = {
    individual: 'Individual',
    grupal: 'Grupal',
    operativa: 'Operativa',
};

/**
 * Lee una modalidad de un dato que viene de fuera.
 *
 * ⚠️ Un valor desconocido cae a `individual`, que es el caso de la inmensa mayoría (553 de 852
 * tarifas maestras) y el único que no cambia ningún número por sí solo: multiplica por cantidad,
 * que es lo que haría un `esGrupal` ausente leído como `false`. Es el respaldo que menos sorprende.
 */
export const comoCalculo = (valor?: string | null): CalculoTarifa =>
    (CALCULOS_TARIFA as readonly string[]).includes(valor ?? '')
        ? (valor as CalculoTarifa)
        : 'individual';

/** ¿El monto se multiplica por la cantidad, o es un precio cerrado? */
export const multiplicaPorCantidad = (m: CalculoTarifa): boolean => m !== 'grupal';

/** ¿La cifra por persona sale de dividir el total entre los pax? */
export const seProrratea = (m: CalculoTarifa): boolean => m !== 'individual';

/**
 * ¿Sale como línea en lo que lee el cliente?
 *
 * ⚠️ Lo contrario NO significa que no cueste: una operativa **suma al costo igual que las demás**.
 * Lo único que no hace es aparecer.
 */
export const visibleParaCliente = (m: CalculoTarifa): boolean => m !== 'operativa';
