/**
 * Cómo se CUENTA y cómo se LLAMA lo que dura: noches, días o unidades sueltas.
 *
 * Vive aparte de `itinerarioVista` porque no es composición de itinerario: lo usan también el
 * editor —para calcular la cantidad al mover una fecha— y las pantallas que rotulan un «×5».
 *
 * ⚠️ La tabla **tipo → unidad** NO está aquí ni en ningún sitio de TypeScript: la resuelve PHP
 * (`ComponenteTipoEnum::unidadPorDefecto()`) y viaja ya resuelta en el snapshot. Aquí sólo se
 * consume el valor.
 */

import { dateOf, diffDays } from './itinerarioVista.ts';

/**
 * Cuántas unidades hay entre dos fechas, según en qué se cuente.
 *
 * 🔥 **Es todo el asunto, y cabe en un `+1`:**
 *
 * ```
 * 18 → 22   noches:  4   (se duerme 18, 19, 20 y 21; el día de salida no cuenta)
 * 18 → 22   días:    5   (18, 19, 20, 21 y 22; el último cuenta)
 * ```
 *
 * ⚠️ **Hasta el 07/09/2026 sólo existía la primera**, en `util/src/utils/naiveDate.ts`, con el
 * nombre `calcularPernoctes` — que confiesa la suposición— y aplicada a todo. La consecuencia no
 * fue un número mal pintado: para cobrar los 5 días de un seguro el operador **tuvo que escribir
 * una fecha de fin falsa**, porque era la única forma de que la resta diera 5.
 *
 * `unidades` (un ticket, una propina) no se deriva de fechas: la escribe quien cotiza y esto
 * devuelve `null` para no pisarla.
 */
export const unidadesEntre = (
  inicio: string | null | undefined,
  fin: string | null | undefined,
  unidad: string | null | undefined,
): number | null => {
  if (unidad === 'unidades') return null;
  if (!inicio || !fin) return null;

  const dif = diffDays(dateOf(inicio), dateOf(fin));
  const total = dif + (unidad === 'dias' ? 1 : 0);

  return total > 0 ? total : 1;
};


/**
 * «5 días», «4 noches», «5 desayunos» — o el número a secas si no hay sustantivo.
 *
 * ⚠️ **El plural se forma aquí y no en el catálogo** para que el operador escriba una palabra y
 * no dos. Regla simple: vocal final → `+s`, consonante → `+es`. Cubre día/noche/desayuno/almuerzo
 * /cena/masaje, que es lo que hay; una palabra que no encaje se escribe ya en plural y este
 * añadido no la toca porque el sustantivo manda tal cual.
 */
export const etiquetaDeUnidades = (n: number, sustantivo: string | null | undefined): string => {
  const palabra = (sustantivo ?? '').trim();
  if (palabra === '') return String(n);
  if (n === 1) return `1 ${palabra}`;

  const plural = /[aeiouáéíóú]$/i.test(palabra) ? `${palabra}s` : `${palabra}es`;
  return `${n} ${plural}`;
};
