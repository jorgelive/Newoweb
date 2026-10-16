import { describe, expect, it } from 'vitest';
import { responder, CONTRATO } from './adelanto.cli.ts';

describe('adelanto.cli: la puerta de PHP', () => {
    it('calcula en lote con la misma función que el navegador', () => {
        const r = responder({ contrato: CONTRATO, entradas: [{ total: 139.99, porcentaje: 50 }, { total: 840, porcentaje: 100 }] });
        expect(r).toEqual({ ok: true, cuerpo: { contrato: CONTRATO, salidas: [{ adelanto: 70, saldo: 69.99 }, { adelanto: 840, saldo: 0 }] } });
    });

    it('un contrato distinto revienta en vez de calcular a ciegas', () => {
        expect(responder({ contrato: 'adelanto@0', entradas: [] }).ok).toBe(false);
    });

    it('sin total o porcentaje no da un adelanto de cero: revienta', () => {
        expect(responder({ contrato: CONTRATO, entradas: [{ total: 100 }] }).ok).toBe(false);
    });
});
