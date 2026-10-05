import { describe, expect, it } from 'vitest';
import {
    CALCULOS_TARIFA,
    ETIQUETAS_CALCULO,
    comoCalculo,
    multiplicaPorCantidad,
    seProrratea,
    visibleParaCliente,
    type CalculoTarifa,
} from './calculoTarifa.ts';

/**
 * Espejo de `tests/Travel/Enum/TarifaCalculoTest.php`. Los dos lados fijan lo mismo porque la
 * regla es la misma, y lo que importa no es cada predicado por separado sino que las dos primeras
 * preguntas sean DISTINTAS: el booleano al que sustituyen las respondía a la vez.
 */
describe('modalidad de tarifa', () => {
    it('individual multiplica y no reparte', () => {
        expect(multiplicaPorCantidad('individual')).toBe(true);
        // El monto YA es por pax: dividirlo otra vez lo encogería.
        expect(seProrratea('individual')).toBe(false);
        expect(visibleParaCliente('individual')).toBe(true);
    });

    it('grupal no multiplica y reparte', () => {
        // Precio cerrado: multiplicarlo por pax lo dobla.
        expect(multiplicaPorCantidad('grupal')).toBe(false);
        expect(seProrratea('grupal')).toBe(true);
        expect(visibleParaCliente('grupal')).toBe(true);
    });

    it('operativa multiplica Y reparte, y no se ve', () => {
        // 🔑 La combinación que el booleano no podía expresar: cinco vuelos liberados son
        // cantidad 5 —se multiplica— y se reparten entre el grupo sin salir como línea.
        expect(multiplicaPorCantidad('operativa')).toBe(true);
        expect(seProrratea('operativa')).toBe(true);
        expect(visibleParaCliente('operativa')).toBe(false);
    });

    it('las dos preguntas no son la misma', () => {
        // Si alguien colapsara los dos predicados en uno, esto se cae.
        const discrepan = CALCULOS_TARIFA.filter(
            (m) => multiplicaPorCantidad(m) === seProrratea(m),
        );

        expect(discrepan).toEqual(['operativa']);
    });

    it('un valor desconocido cae a individual', () => {
        // Es el respaldo que menos sorprende: 553 de 852 tarifas son individuales, y multiplicar
        // por cantidad es lo que haría un `esGrupal` ausente leído como false.
        expect(comoCalculo(null)).toBe('individual');
        expect(comoCalculo('')).toBe('individual');
        expect(comoCalculo('marciano')).toBe('individual');
        expect(comoCalculo('operativa')).toBe('operativa');
    });

    it('las tres tienen etiqueta', () => {
        const esperado: Record<CalculoTarifa, string> = {
            individual: 'Individual',
            grupal: 'Grupal',
            operativa: 'Operativa',
        };

        expect(ETIQUETAS_CALCULO).toEqual(esperado);
    });
});
