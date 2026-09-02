import { describe, it, expect, beforeEach } from 'vitest';
import { setActivePinia, createPinia } from 'pinia';
import { useCotizacionEditorStore } from './cotizacionEditorStore';

/**
 * Congela cómo ordena los días el EDITOR, antes de que esa regla se mueva.
 *
 * ── Por qué este test y no otro ─────────────────────────────────────────────
 * `docs/NodeEnElStack.md` §5: se fija el comportamiento **antes** de mover la lógica, no después.
 * Y lo que la fase 3 del plan va a mover es exactamente esto: `posicionDeServicio()` vive hoy en
 * `itinerarioDinamico` (este store) **y** en `pax/src/dominio/itinerarioVista.ts` —el mismo
 * cálculo escrito dos veces, con un comentario pidiendo que se cambien juntos—. Cuando `util`
 * importe el módulo compartido y esta copia se borre, **este test es lo único que dirá si el
 * editor sigue enseñando los días en el mismo orden**.
 *
 * Sin él, la fase 3 sería un cambio a ciegas sobre la app con la que se trabaja todos los días.
 *
 * ── Por qué a través del store y no de la función ───────────────────────────
 * ⚠️ `posicionDeServicio()` es una función anidada dentro de un `computed`, no se exporta y no se
 * puede llamar desde fuera. Eso **es en sí un hallazgo**: una regla de negocio inalcanzable para
 * cualquier consumidor que no sea la propia pantalla, igual que le pasaba a la composición del
 * itinerario en `pax` antes de sacarla. Se prueba por el `computed`, que es la única puerta.
 *
 * ── Los datos son mínimos y sintéticos, a propósito ─────────────────────────
 * Aquí no se congela «cómo sale una cotización real» —para eso están los fixtures de `pax`— sino
 * **la regla**: qué gana cuando el reloj y la persona dicen cosas distintas. Un caso mínimo hace
 * evidente qué se rompió cuando se rompa; una cotización de 17 servicios, no.
 */

/** Un servicio con lo justo que `itinerarioDinamico` lee. */
const servicio = (
    id: string,
    fecha: string,
    orden: number,
    componentes: { hora?: string | null; ordenNarrativo?: number }[],
) => ({
    id,
    fechaInicioAbsoluta: fecha,
    orden,
    cotsegmentos: [],
    cotcomponentes: componentes.map((c, i) => ({
        id: `${id}-c${i}`,
        fechaHoraInicio: c.hora ?? null,
        fechaHoraFin: null,
        sinHorario: !c.hora,
        ordenNarrativo: c.ordenNarrativo ?? 30,
        cotsegmento: null,
    })),
});

const montar = (cotservicios: ReturnType<typeof servicio>[]) => {
    const store = useCotizacionEditorStore();
    (store.cotizacion as unknown) = { cotservicios };
    return store;
};

// `CotServicio.id` es opcional en el tipo del editor —un servicio recién creado aún no lo tiene—,
// así que aquí se afirma: los del test siempre lo traen.
const idsPorDia = (store: ReturnType<typeof montar>): string[][] =>
    store.itinerarioDinamico.map((dia) => dia.cotservicios.map((s) => s.id as string));

describe('itinerarioDinamico · cómo ordena el editor un día', () => {
    beforeEach(() => setActivePinia(createPinia()));

    it('sin orden manual: manda el reloj', () => {
        const store = montar([
            servicio('tarde', '2030-01-01', 0, [{ hora: '2030-01-01T18:00:00' }]),
            servicio('manana', '2030-01-01', 0, [{ hora: '2030-01-01T08:00:00' }]),
        ]);

        expect(idsPorDia(store)).toEqual([['manana', 'tarde']]);
    });

    it('🔑 con orden manual: manda la persona, aunque contradiga al reloj', () => {
        // Es la regla que más cuesta si se rompe: el operador coloca el día arrastrando y el
        // huésped lo lee en otro orden. La contradicción se AVISA (chequeo
        // `orden-contradice-hora`), no se impide — un chequeo que vigila algo imposible no vigila.
        const store = montar([
            servicio('tarde', '2030-01-01', 1, [{ hora: '2030-01-01T18:00:00' }]),
            servicio('manana', '2030-01-01', 2, [{ hora: '2030-01-01T08:00:00' }]),
        ]);

        expect(idsPorDia(store)).toEqual([['tarde', 'manana']]);
    });

    /**
     * ⚠️ **Ojo con lo que pasa aquí, porque no es lo que uno predice.**
     *
     * Basta que UN servicio del día tenga `orden` para que el día entero se ordene a mano —eso sí
     * es lo esperado—. Lo que sorprende es cómo se coloca el que NO lo tiene: no vale 0, sino que
     * cae a su `ordenNarrativo`, que por defecto es **30**. Y 30 se compara directamente contra el
     * `orden` manual del otro, que aquí es 1.
     *
     * O sea: **dos escalas numéricas con significados distintos se comparan como si fueran una**.
     * Con los datos reales de `5SRAJV` los órdenes manuales son 10, 20 y 30 — el mismo rango que
     * `ordenNarrativo`—, así que un servicio sin colocar puede caer **en medio** de los colocados
     * a mano según qué números eligiera el operador.
     *
     * No se cambia aquí: este test congela lo que hace HOY, que es su trabajo. Queda anotado
     * porque el mismo cálculo está en `pax` y en `OperacionServicio.php`, y una decisión sobre
     * esto hay que tomarla en los tres a la vez.
     */
    it('en un día a mano, el que no tiene orden cae a su orden narrativo (30), no a 0', () => {
        const store = montar([
            servicio('sinOrden', '2030-01-01', 0, [{ hora: '2030-01-01T08:00:00' }]),
            servicio('conOrden', '2030-01-01', 1, [{ hora: '2030-01-01T18:00:00' }]),
        ]);

        // conOrden = 1 · sinOrden = 30 (narrativo por defecto) → el colocado a mano va primero,
        // aunque su hora sea diez horas más tarde.
        expect(idsPorDia(store)).toEqual([['conOrden', 'sinOrden']]);
    });

    it('sin hora ninguno: decide el orden narrativo, no el orden de inserción', () => {
        // ⚠️ Antes devolvía 0 aquí y el resultado lo fijaba el orden en que llegaron del API:
        // parecía aleatorio. Ahora llegar abre la jornada y dormir la cierra.
        const store = montar([
            servicio('dormir', '2030-01-01', 0, [{ ordenNarrativo: 90 }]),
            servicio('llegar', '2030-01-01', 0, [{ ordenNarrativo: 10 }]),
        ]);

        expect(idsPorDia(store)).toEqual([['llegar', 'dormir']]);
    });

    it('el que no tiene hora va detrás del que sí', () => {
        const store = montar([
            servicio('sinHora', '2030-01-01', 0, [{ ordenNarrativo: 10 }]),
            servicio('conHora', '2030-01-01', 0, [{ hora: '2030-01-01T18:00:00' }]),
        ]);

        expect(idsPorDia(store)).toEqual([['conHora', 'sinHora']]);
    });

    it('los días salen en orden de fecha', () => {
        const store = montar([
            servicio('b', '2030-01-02', 0, [{ hora: '2030-01-02T08:00:00' }]),
            servicio('a', '2030-01-01', 0, [{ hora: '2030-01-01T08:00:00' }]),
        ]);

        expect(idsPorDia(store)).toEqual([['a'], ['b']]);
    });
});
