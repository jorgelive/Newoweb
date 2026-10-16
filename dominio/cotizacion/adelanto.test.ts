import { describe, expect, it } from 'vitest';
import { montoDeAdelanto, saldoTrasAdelanto } from './adelanto.ts';

describe('adelanto para reservar', () => {
    it('el 50 % de siempre', () => {
        expect(montoDeAdelanto(840, 50)).toBe(420);
        expect(saldoTrasAdelanto(840, 50)).toBe(420);
    });

    it('adelanto y saldo suman el total al céntimo, aunque el total sea impar', () => {
        // Redondeados por separado darían 70,00 + 70,00 = 140,00.
        expect(montoDeAdelanto(139.99, 50)).toBe(70);
        expect(saldoTrasAdelanto(139.99, 50)).toBe(69.99);
    });

    it('100 % es pagar todo para reservar, 0 % es no pedir adelanto', () => {
        expect(montoDeAdelanto(250, 100)).toBe(250);
        expect(saldoTrasAdelanto(250, 100)).toBe(0);
        expect(montoDeAdelanto(250, 0)).toBe(0);
        expect(saldoTrasAdelanto(250, 0)).toBe(250);
    });

    it('un porcentaje fuera de rango no pide más que el total ni un negativo', () => {
        expect(montoDeAdelanto(100, 150)).toBe(100);
        expect(montoDeAdelanto(100, -10)).toBe(0);
        expect(montoDeAdelanto(100, Number.NaN)).toBe(0);
    });
});
