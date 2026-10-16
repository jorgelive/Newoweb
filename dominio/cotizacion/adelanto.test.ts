import { describe, expect, it } from 'vitest';
import { adelantoCubierto, faltaParaAdelanto, montoDeAdelanto, saldoTrasAdelanto } from './adelanto.ts';

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

describe('adelanto cubierto', () => {
    it('falta lo que queda hasta el adelanto, y nunca un negativo', () => {
        expect(faltaParaAdelanto(840, 50, 100)).toBe(320);
        expect(faltaParaAdelanto(840, 50, 1000)).toBe(0);
    });

    it('se compara en céntimos: medio céntimo no deja «faltan 0,01»', () => {
        expect(adelantoCubierto(139.99, 50, 69.995)).toBe(true);
        expect(adelantoCubierto(139.99, 50, 69.99)).toBe(false);
        expect(faltaParaAdelanto(139.99, 50, 69.99)).toBe(0.01);
    });

    it('sin adelanto (0 %) está cubierto desde el principio', () => {
        expect(adelantoCubierto(500, 0, 0)).toBe(true);
    });
});
