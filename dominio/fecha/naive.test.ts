import { describe, expect, it } from 'vitest';
import {
    addDurationToDate,
    fmtNaive,
    fmtNaiveDia,
    formatNaiveFromUTC,
    getDuracionMs,
    parseNaiveAsUTC,
} from './naive.ts';

/**
 * ⚠️ Estas pruebas sólo valen si se ejecutan en VARIAS zonas horarias.
 *
 * Todo lo de aquí pasaría igual de verde con el código roto si el runner estuviera en
 * `America/Lima`, que es exactamente por qué el fallo llegó a producción: en la máquina de quien
 * lo escribió no se ve. Ver el script `test:zonas` de `package.json`, que corre la suite en Lima,
 * Madrid, Tokio y UTC.
 *
 * El caso que las motiva: un huésped abre `pax` desde Madrid y su check-in de las 14:00 se le
 * enseñaba como «07:00 AM», porque la cadena naive se parseaba en su zona y luego se formateaba
 * forzando la de Lima. Dos desplazamientos.
 */
describe('fechas naive: los dígitos de pared sobreviven a cualquier zona', () => {
    const CHECK_IN = '2026-08-31T14:00:00';

    it('enseña la hora guardada, no la del reloj de quien mira', () => {
        expect(fmtNaive(CHECK_IN, { hour: '2-digit', minute: '2-digit', hour12: false }))
            .toBe('14:00');
    });

    it('enseña el día guardado, sin adelantarlo ni retrasarlo', () => {
        expect(fmtNaive(CHECK_IN, { year: 'numeric', month: '2-digit', day: '2-digit' }))
            .toBe('31/08/2026');
    });

    it('un día natural (sin hora) tampoco retrocede', () => {
        // `new Date('2026-06-15')` es medianoche UTC: en cualquier zona negativa cae en el día 14.
        expect(fmtNaiveDia('2026-06-15', { day: '2-digit', month: '2-digit', year: 'numeric' }))
            .toBe('15/06/2026');
    });

    it('acepta una fecha con hora y se queda sólo con el día', () => {
        expect(fmtNaiveDia('2026-06-15T23:30:00', { day: '2-digit', month: '2-digit', year: 'numeric' }))
            .toBe('15/06/2026');
    });

    it('el round-trip no mueve ni un dígito', () => {
        expect(formatNaiveFromUTC(parseNaiveAsUTC(CHECK_IN))).toBe(CHECK_IN);
    });

    it('mide la duración de pared aunque el cliente tenga horario de verano', () => {
        // La madrugada del cambio de hora en Madrid: 27/03/2027, que dura 23 h de reloj local.
        // Contando de pared son 24, y es lo que se factura.
        expect(getDuracionMs('2027-03-27T12:00:00', '2027-03-28T12:00:00'))
            .toBe(24 * 60 * 60 * 1000);
    });

    it('suma horas sin saltar por el horario de verano', () => {
        expect(addDurationToDate('2027-03-27T22:00:00', 4)).toBe('2027-03-28T02:00:00');
    });

    it('devuelve el marcador de vacío en vez de «Invalid Date»', () => {
        expect(fmtNaive('', { hour: '2-digit' })).toBe('--');
        expect(fmtNaive('no es una fecha', { hour: '2-digit' })).toBe('--');
        expect(fmtNaive('', { hour: '2-digit' }, 'es-PE', '')).toBe('');
    });
});

/**
 * Lo que NO debe tragarse.
 *
 * Cada uno de estos devolvía antes una fecha **plausible**, que es la peor forma de fallar: nadie
 * mira un `14/02/2027` y sospecha que la entrada decía `2026-13-45`.
 */
describe('rechaza lo que no es una hora de pared', () => {
    const opts = { day: '2-digit', month: '2-digit', year: 'numeric' } as const;

    it('un INSTANTE no es una fecha de pared, aunque lo parezca', () => {
        // Antes devolvía «14:00» tal cual, y acertaba sólo mientras el desplazamiento que viajaba
        // fuese el de casa. Mezclar las dos categorías es el fallo que este módulo separa.
        expect(parseNaiveAsUTC('2026-08-31T14:00:00Z')).toBeNaN();
        expect(parseNaiveAsUTC('2026-08-31T14:00:00-05:00')).toBeNaN();
        expect(parseNaiveAsUTC('2026-08-31T14:00:00+0200')).toBeNaN();
    });

    it('una fecha que no existe no se convierte en otra que sí', () => {
        expect(fmtNaive('2026-13-45T10:00:00', opts)).toBe('--'); // daba 14/02/2027
        expect(fmtNaive('2026-02-30T10:00:00', opts)).toBe('--'); // daba 02/03/2026
    });

    it('un año de dos dígitos no es el año 26', () => {
        expect(fmtNaive('26-08-31T14:00:00', opts)).toBe('--');
    });

    it('sólo espacios, o una hora sin fecha, no son 01/01/1900', () => {
        expect(fmtNaive('   ', opts)).toBe('--');
        expect(fmtNaive('T14:00:00', opts)).toBe('--');
    });

    it('minutos no numéricos no valen como cero', () => {
        expect(fmtNaive('2026-08-31T14:xx:00', opts)).toBe('--');
    });

    it('acepta el separador con espacio igual que con T, y sin segundos', () => {
        // MySQL y algunos serializadores mandan el espacio; los dos son hora de pared.
        expect(fmtNaive('2026-08-31 14:00:00', { hour: '2-digit', minute: '2-digit', hour12: false })).toBe('14:00');
        expect(fmtNaive('2026-08-31T14:00', { hour: '2-digit', minute: '2-digit', hour12: false })).toBe('14:00');
    });
});
