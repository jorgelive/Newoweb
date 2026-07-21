/**
 * src/utils/naiveDate.ts
 *
 * Utilidades de fecha "naive" (wall-clock, sin zona horaria).
 *
 * ¿Por qué existe?
 * El backend serializa las fechas en formato naive (`Y-m-d\TH:i:s`, sin `Z` ni offset)
 * a propósito: la hora que se guarda es la hora "de pared" (ej. 07:00 en Perú), no un
 * instante absoluto. El problema es que `new Date('2026-08-31T07:00:00')` en el navegador
 * interpreta ese string en la zona horaria del cliente, y cualquier aritmética posterior
 * (sumar duraciones, recalcular fines) se corre ±1h si el cliente tiene horario de verano.
 *
 * La solución: anclar TODA la aritmética a UTC, que no tiene DST. Usamos UTC solo como
 * "riel neutro": los dígitos de pared (día, hora, minuto) sobreviven intactos en cada
 * round-trip, sin convertir a ninguna zona. Es decir, mantenemos el contrato naive de
 * punta a punta.
 *
 * Regla de oro: en el frontend, NUNCA pasar un string naive por `new Date(str)` para
 * hacer cálculos. Siempre vía `parseNaiveAsUTC` / `formatNaiveFromUTC`.
 */

/** Parsea 'yyyy-MM-ddTHH:mm(:ss)' como si fuera UTC (sin influencia de la zona del cliente). */
export const parseNaiveAsUTC = (s: string): number => {
    if (!s) return NaN;
    const [fecha, hora = '00:00:00'] = s.split('T');
    const [y, m, d] = fecha.split('-').map(Number);
    const [hh = 0, mm = 0, ss = 0] = hora.split(':').map(Number);
    if (Number.isNaN(y) || Number.isNaN(m) || Number.isNaN(d)) return NaN;
    return Date.UTC(y, (m || 1) - 1, d || 1, hh || 0, mm || 0, ss || 0);
};

/** Serializa ms-UTC de vuelta a 'yyyy-MM-ddTHH:mm:ss' leyendo componentes UTC. */
export const formatNaiveFromUTC = (ms: number): string => {
    if (Number.isNaN(ms)) return '';
    const d = new Date(ms);
    const p = (n: number) => String(n).padStart(2, '0');
    return `${d.getUTCFullYear()}-${p(d.getUTCMonth() + 1)}-${p(d.getUTCDate())}` +
        `T${p(d.getUTCHours())}:${p(d.getUTCMinutes())}:${p(d.getUTCSeconds())}`;
};

/**
 * Duración en ms entre dos fechas naive.
 * Al anclar ambas a UTC, la diferencia es la duración de pared real (no la afecta el DST del cliente).
 */
export const getDuracionMs = (inicioIso: string, finIso: string, defaultHoras = 0): number => {
    if (inicioIso && finIso) {
        const oS = parseNaiveAsUTC(inicioIso);
        const oE = parseNaiveAsUTC(finIso);
        if (!Number.isNaN(oS) && !Number.isNaN(oE) && oE >= oS) return oE - oS;
    }
    return defaultHoras * 60 * 60 * 1000;
};

/** Suma una duración (en horas decimales) a una fecha naive y devuelve otra fecha naive. */
export const addDurationToDate = (baseIsoString: string, durationDecimal: number | string): string => {
    if (!baseIsoString) return '';
    const base = parseNaiveAsUTC(baseIsoString);
    if (Number.isNaN(base)) return '';
    const horas = typeof durationDecimal === 'string' ? parseFloat(durationDecimal) : durationDecimal;
    if (Number.isNaN(horas)) return formatNaiveFromUTC(base);
    return formatNaiveFromUTC(base + Math.round(horas * 60) * 60000);
};

/** Nº de pernoctes (noches) entre dos fechas naive, contando por día de calendario. */
export const calcularPernoctes = (inicioStr: string, finStr: string): number => {
    if (!inicioStr || !finStr) return 1;
    const DIA = 24 * 60 * 60 * 1000;
    const s = Math.floor(parseNaiveAsUTC(inicioStr) / DIA);
    const e = Math.floor(parseNaiveAsUTC(finStr) / DIA);
    const diff = e - s;
    return diff > 0 ? diff : 1;
};

/**
 * Formatea una fecha naive para mostrarla, respetando exactamente los dígitos guardados
 * (sin que la zona del navegador la mueva). Úsalo en los formateadores de solo lectura
 * que hoy hacen `new Date(iso).toLocaleTimeString(...)`.
 *
 * @example fmtNaive('2026-08-31T07:00:00', { hour: '2-digit', minute: '2-digit', hour12: false }, 'es-PE') // "07:00"
 */
export const fmtNaive = (
    naiveIso: string,
    opts: Intl.DateTimeFormatOptions,
    locale = 'es-PE'
): string => {
    if (!naiveIso) return '--';
    const ms = parseNaiveAsUTC(naiveIso);
    if (Number.isNaN(ms)) return '--';
    return new Date(ms).toLocaleString(locale, { ...opts, timeZone: 'UTC' });
};