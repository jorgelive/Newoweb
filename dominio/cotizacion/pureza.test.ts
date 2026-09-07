import { describe, it, expect } from 'vitest';
import { componerItinerario } from './itinerarioVista.ts';
import { unidadesEntre, resumenDeDuracion, etiquetaDeUnidades, sustantivoDeUnidad } from './unidades.ts';
import cot2KVBMX from './__fixtures__/2KVBMX.json';
import cot5SRAJV from './__fixtures__/5SRAJV.json';

/**
 * Lo único que hace seguro dejar un proceso VIVO: que aquí dentro no haya estado.
 *
 * ── Por qué existe ──────────────────────────────────────────────────────────
 * Hoy cada invocación arranca un `node` nuevo, así que la contaminación entre peticiones es
 * imposible por construcción. El día que haya un proceso residente atendiendo consultas
 * —`docs/NodeEnElStack.md`, «un demonio, no un arranque por proceso»— deja de serlo: una función
 * que mute su entrada, o que guarde un `Map` a nivel de módulo, cruzaría datos de una cotización a
 * otra **sin dar ningún error**.
 *
 * Este test no prueba un cálculo: prueba una PROPIEDAD. Y hay que escribirlo ahora, mientras la
 * propiedad se cumple, porque el día que se rompa nadie va a estar mirando.
 *
 * ⚠️ Congela la entrada en profundidad. Sin `freeze`, mutarla pasaría desapercibido y sólo se
 * notaría en el segundo llamador — que en un demonio es otra petición, de otro expediente.
 */
const congelar = <T>(x: T): T => {
    if (x === null || typeof x !== 'object') return x;
    Object.values(x as Record<string, unknown>).forEach(congelar);
    return Object.freeze(x);
};

describe('dominio/ es apto para un proceso residente', () => {
    it('componerItinerario no toca su entrada y devuelve lo mismo dos veces', () => {
        for (const cot of [cot2KVBMX, cot5SRAJV]) {
            const entrada = congelar(structuredClone(cot));

            // Si mutara la entrada, `freeze` lo convierte en TypeError en modo estricto — que es
            // lo que hace un módulo ES.
            const primera = componerItinerario(entrada as never);
            const segunda = componerItinerario(entrada as never);

            expect(JSON.stringify(segunda)).toBe(JSON.stringify(primera));
            expect(entrada).toEqual(cot);
        }
    });

    it('las funciones de unidades son puras: misma entrada, misma salida', () => {
        const casos: [string, () => unknown][] = [
            ['unidadesEntre noches', () => unidadesEntre('2030-01-18', '2030-01-22', 'noches')],
            ['unidadesEntre dias', () => unidadesEntre('2030-01-18', '2030-01-22', 'dias')],
            ['unidadesEntre unidades', () => unidadesEntre('2030-01-18', '2030-01-22', 'unidades')],
            ['etiquetaDeUnidades', () => etiquetaDeUnidades(4, 'noche')],
            ['sustantivoDeUnidad', () => sustantivoDeUnidad('dias', null)],
            ['resumenDeDuracion', () => resumenDeDuracion(componerItinerario(cot2KVBMX as never))],
        ];

        for (const [nombre, fn] of casos) {
            const a = JSON.stringify(fn());
            const b = JSON.stringify(fn());
            const c = JSON.stringify(fn());
            expect(b, nombre).toBe(a);
            expect(c, nombre).toBe(a);
        }
    });

    /**
     * ⚠️ Un `Map` o un contador a nivel de módulo sobreviviría entre peticiones en un demonio.
     * Esto no puede detectarlos todos —haría falta inspeccionar el módulo— pero sí el síntoma que
     * de verdad importa: que la respuesta cambie según lo que se preguntó antes.
     */
    it('el orden de las llamadas no cambia el resultado', () => {
        const soloA = JSON.stringify(componerItinerario(cot5SRAJV as never));

        componerItinerario(cot2KVBMX as never);
        componerItinerario(cot2KVBMX as never);

        const despuesDeOtras = JSON.stringify(componerItinerario(cot5SRAJV as never));

        expect(despuesDeOtras).toBe(soloA);
    });
});
