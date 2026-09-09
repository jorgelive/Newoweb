/**
 * Fechas: la puerta del módulo.
 *
 * Lo que hay dentro trata **hora de pared** —la del establecimiento, sin zona— que es lo que el
 * servidor guarda y serializa. El porqué, la técnica del riel de UTC y el fallo que motivó todo
 * están en `naive.ts`.
 */
export {
    addDurationToDate,
    fmtNaive,
    fmtNaiveDia,
    formatNaiveFromUTC,
    getDuracionMs,
    hoyNaive,
    parseNaiveAsUTC,
} from './naive.ts';
