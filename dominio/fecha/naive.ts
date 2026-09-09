/**
 * Fechas «naive» (hora de pared, sin zona).
 *
 * ── El contrato ─────────────────────────────────────────────────────────────
 *
 * El servidor guarda y serializa **hora de pared del establecimiento**: `Y-m-d\TH:i:s`, sin `Z` y
 * sin desplazamiento. «Check-in a las 14:00» significa las 14:00 *en la casa*, y eso vale para
 * todo el mundo — el huésped que mira vuelos desde Madrid necesita leer 14:00, no las 21:00 que
 * serían en su reloj.
 *
 * ⚠️ **Por eso una fecha de pared NO se convierte a ninguna zona: ni a la del visitante ni a la
 * del establecimiento.** No es un instante, es un hecho sobre un sitio.
 *
 * ── El problema que resuelve ────────────────────────────────────────────────
 *
 * `new Date('2026-08-31T14:00:00')` interpreta la cadena en la zona **del navegador que la lee**.
 * Con eso solo, un huésped en Madrid ya tiene un instante distinto al que quiso decir el servidor;
 * si además se formatea forzando `timeZone: 'America/Lima'`, se desplaza **otra vez**, y el
 * check-in de las 14:00 se le enseña como «07:00». Eso estuvo pasando en `pax` hasta el
 * 08/09/2026 — con un comentario al lado que decía, sin ironía, «evita que el navegador del
 * turista cambie la hora».
 *
 * ── La técnica ──────────────────────────────────────────────────────────────
 *
 * UTC como **riel neutro**: se ancla todo a UTC —que no tiene horario de verano— y se leen los
 * componentes en UTC. Los dígitos de pared sobreviven intactos a cualquier round-trip y en
 * cualquier zona del mundo. No se está «pasando a UTC»: se está usando UTC como una regla
 * graduada que no se mueve.
 *
 * **Regla de oro: nunca pases una cadena naive por `new Date(str)` para calcular ni para mostrar.**
 *
 * ── Lo que NO va aquí ───────────────────────────────────────────────────────
 *
 * Los **instantes** —cuándo se recibió un pago, cuándo caduca un enlace, cuándo se liberan los
 * códigos— sí son momentos en el tiempo, y el servidor los manda con desplazamiento (`DATE_ATOM`).
 * Ésos se parsean con `new Date()` de toda la vida y se muestran en la zona que convenga: el
 * desplazamiento viaja dentro de la propia cadena, así que no hace falta saber nada más.
 */

/**
 * Parsea `yyyy-MM-dd[THH:mm[:ss]]` anclándolo a UTC, sin influencia de la zona del cliente.
 *
 * ⚠️ **Rechaza a propósito lo que NO es hora de pared.** Una cadena con `Z` o con `±hh:mm` es un
 * instante, y tratarla como pared es mezclar las dos categorías —justo lo que este módulo existe
 * para separar—. Antes se la tragaba en silencio: `'2026-08-31T14:00:00Z'` devolvía las 14:00 como
 * si fueran de pared, y salía bien sólo mientras el desplazamiento que viajara fuese el de casa.
 * Ahora devuelve `NaN` y quien llame ve el marcador de vacío, que es un fallo que se nota.
 *
 * Y valida de verdad en vez de dejar que `Date.UTC` ruede: `'2026-13-45'` daba febrero de 2027, y
 * `'26-08-31'` daba el año 26. Un dato corrupto tiene que verse como corrupto, no como una fecha
 * plausible.
 */
export const parseNaiveAsUTC = (s: string): number => {
    const limpia = (s ?? '').trim();
    if (limpia === '') return NaN;

    // Con huso no es una fecha de pared: es un instante y no le toca a este módulo.
    if (/[zZ]$|[+-]\d{2}:?\d{2}$/.test(limpia)) return NaN;

    const m = /^(\d{4})-(\d{2})-(\d{2})(?:[T ](\d{2}):(\d{2})(?::(\d{2}))?)?$/.exec(limpia);
    if (!m) return NaN;

    const [y, mes, d, hh, mm, ss] = m.slice(1).map((v) => (v === undefined ? 0 : Number(v)));

    // `Date.UTC` acepta el 31 de febrero y lo rueda a marzo. Se comprueba que los componentes
    // sobrevivan al viaje: si no, la fecha no existía.
    const ms = Date.UTC(y, mes - 1, d, hh, mm, ss);
    const back = new Date(ms);

    if (back.getUTCFullYear() !== y || back.getUTCMonth() !== mes - 1 || back.getUTCDate() !== d
        || back.getUTCHours() !== hh || back.getUTCMinutes() !== mm) {
        return NaN;
    }

    return ms;
};

/** Serializa ms-UTC de vuelta a `yyyy-MM-ddTHH:mm:ss` leyendo componentes UTC. */
export const formatNaiveFromUTC = (ms: number): string => {
    if (Number.isNaN(ms)) return '';
    const d = new Date(ms);
    const p = (n: number) => String(n).padStart(2, '0');
    return `${d.getUTCFullYear()}-${p(d.getUTCMonth() + 1)}-${p(d.getUTCDate())}` +
        `T${p(d.getUTCHours())}:${p(d.getUTCMinutes())}:${p(d.getUTCSeconds())}`;
};

/**
 * Duración en ms entre dos fechas naive.
 *
 * Al anclar las dos al riel de UTC, la diferencia es la duración de pared real: no la mueve el
 * horario de verano del cliente, que en una noche de cambio se comería o regalaría una hora.
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

/**
 * Formatea una fecha naive para enseñarla, respetando **exactamente** los dígitos guardados.
 *
 * Es el reemplazo directo de `new Date(iso).toLocaleTimeString(...)` en cualquier formateador de
 * sólo lectura. El `timeZone: 'UTC'` de dentro no convierte a UTC: cierra el riel, para que se
 * lean los mismos componentes que se anclaron.
 *
 * @example fmtNaive('2026-08-31T07:00:00', { hour: '2-digit', minute: '2-digit' }, 'es-PE') // "07:00"
 */
export const fmtNaive = (
    naiveIso: string,
    opts: Intl.DateTimeFormatOptions,
    locale = 'es-PE',
    vacio = '--',
): string => {
    if (!naiveIso) return vacio;
    const ms = parseNaiveAsUTC(naiveIso);
    if (Number.isNaN(ms)) return vacio;
    return new Date(ms).toLocaleString(locale, { ...opts, timeZone: 'UTC' });
};

/**
 * Formatea una fecha SIN hora (`yyyy-MM-dd`) — un día natural, no un instante.
 *
 * ⚠️ Va aparte porque el fallo es distinto y también estaba en `pax`: `new Date('2026-06-15')` se
 * interpreta como medianoche **UTC**, así que en cualquier zona negativa —Lima incluida— retrocede
 * al día anterior. Y construirla como medianoche local y luego formatear en otra zona la mueve
 * hacia delante. El día natural se ancla al riel igual que todo lo demás.
 */
export const fmtNaiveDia = (
    fechaIso: string,
    opts: Intl.DateTimeFormatOptions,
    locale = 'es-PE',
    vacio = '--',
): string => fmtNaive((fechaIso || '').slice(0, 10), opts, locale, vacio);

/**
 * El día de HOY como `yyyy-MM-dd`, leído del reloj local.
 *
 * ⚠️ Existe porque `new Date().toISOString().slice(0, 10)` **no es hoy**: es el día en UTC. En
 * Lima, a partir de las 19:00, devuelve el de mañana. Eso hacía que el editor de cotizaciones
 * consultara el tipo de cambio del día siguiente, que la fecha base de una cotización nueva naciera
 * en mañana, y que un documento que vence hoy se marcara vencido — todo a partir de las siete de la
 * tarde y sin que nada avisara.
 *
 * Se leen los componentes locales, que son los que ve la persona delante de la pantalla.
 *
 * ⚠️ Es el «hoy» de QUIEN MIRA, no el del establecimiento. Para el equipo, que trabaja en la zona
 * de la operación, son el mismo. Si algún día hay operadores en otro huso y hace falta el día del
 * alojamiento, eso tiene que venir del servidor: el navegador no puede saberlo.
 */
export const hoyNaive = (): string => {
    const d = new Date();
    const p = (n: number) => String(n).padStart(2, '0');

    return `${d.getFullYear()}-${p(d.getMonth() + 1)}-${p(d.getDate())}`;
};
