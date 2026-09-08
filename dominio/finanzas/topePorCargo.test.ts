import { describe, expect, it } from 'vitest';
import { avisoDeTope, totalConRecargo, type ConfigDelFront } from './topePorCargo';

const CONFIG: ConfigDelFront = {
    recargoTarjetaPorcentaje: '5.5',
    limitePorCargo: { USD: '3000', PEN: '10000' },
};

describe('el tope por cargo', () => {
    /**
     * 🔥 La prueba que justifica toda la regla: un neto POR DEBAJO del tope que la pasarela
     * rechaza igual, porque lo que compara es el importe con recargo.
     */
    it('bloquea un neto que parece válido y no lo es', () => {
        expect(totalConRecargo(2900, '5.5')).toBe(3059.5);
        expect(avisoDeTope(2900, 'USD', CONFIG)).toContain('3059.50');
    });

    it('deja pasar el que sí cabe con recargo', () => {
        expect(avisoDeTope(2800, 'USD', CONFIG)).toBeNull();
    });

    it('cuenta en la divisa que le toca', () => {
        expect(avisoDeTope(9600, 'PEN', CONFIG)).toContain('10128.00');
        expect(avisoDeTope(9400, 'PEN', CONFIG)).toBeNull();
    });

    /** Sin recargo el tope es el neto pelado: es el caso de un cobro sin comisión trasladada. */
    it('sin recargo compara el neto', () => {
        expect(avisoDeTope(2950, 'USD', CONFIG, false)).toBeNull();
        expect(avisoDeTope(3001, 'USD', CONFIG, false)).not.toBeNull();
    });

    /**
     * ⚠️ Una divisa sin tope NO se bloquea: el límite es del proveedor, no nuestro, e inventarlo
     * impediría cobros que sí acepta.
     */
    it('no inventa un tope para una divisa que no conoce', () => {
        expect(avisoDeTope(999999, 'EUR', CONFIG)).toBeNull();
    });

    it('ignora importes que no son importes', () => {
        expect(avisoDeTope(0, 'USD', CONFIG)).toBeNull();
        expect(avisoDeTope(Number.NaN, 'USD', CONFIG)).toBeNull();
    });
});
