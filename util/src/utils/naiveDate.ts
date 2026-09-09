/**
 * src/utils/naiveDate.ts
 *
 * ⚠️ **La implementación ya NO vive aquí: está en `dominio/fecha/naive.ts`.**
 *
 * Se movió el 09/09/2026 porque `pax` la necesitaba y copiarla habría sido tener la misma regla
 * escrita dos veces —lo que `CLAUDE.md` prohíbe—. Y no era una preocupación teórica: `pax` llevaba
 * meses con el fallo que este archivo existe para evitar, formateando el check-in de las 14:00 como
 * «07:00 AM» para cualquier huésped fuera de Perú, precisamente porque nunca recibió esta utilidad.
 *
 * Este archivo se queda como puerta de entrada para no tocar los imports que ya existen, y porque
 * `calcularUnidades` sí es de aquí: adapta la firma al `string` que usa el editor.
 *
 * Regla de oro, que sigue valiendo: en el frontend **nunca** pases una cadena naive por
 * `new Date(str)` para calcular ni para mostrar. El porqué está en `dominio/fecha/naive.ts`.
 */
import { unidadesEntre } from '@dominio/cotizacion/index.ts';

export {
    addDurationToDate,
    fmtNaive,
    fmtNaiveDia,
    formatNaiveFromUTC,
    getDuracionMs,
    hoyNaive,
    parseNaiveAsUTC,
} from '@dominio/fecha/index.ts';

/**
 * Cuántas unidades hay entre dos fechas naive, **según en qué se cuente**.
 *
 * 🔥 **Se llamaba `calcularPernoctes` y sólo sabía restar**, que es correcto para una cama y falso
 * para todo lo que se consume por jornada. Un hotel del 18 al 22 son 4 noches; un seguro del 18 al
 * 22 son 5 días. Con una sola cuenta, para cobrar los 5 días el operador **tuvo que escribir una
 * fecha de fin falsa** —«18 → 23»—, porque era la única forma de que la resta diera 5.
 *
 * `'unidades'` devuelve `null`: un ticket no dura, se compra, y su cantidad la escribe quien
 * cotiza. Quien llame decide qué hacer con el `null` — normalmente, no tocar lo que ya hay.
 *
 * ⚠️ La regla vive en `dominio/`, que es de donde la lee también la guía del huésped. Aquí sólo
 * se adapta la firma al `string` que usa el editor.
 */
export const calcularUnidades = (
    inicioStr: string | null | undefined,
    finStr: string | null | undefined,
    unidad: string | null | undefined,
): number | null => unidadesEntre(inicioStr, finStr, unidad ?? 'noches');
