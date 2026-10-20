import { describe, it, expect } from 'vitest';
import { avisoIgvDeHotel, peruanosQueViajan } from './igvHoteleria';

describe('IGV de hotel cuando viajan peruanos', () => {
    it('se detectan por el manifiesto o por una tarifa nacional', () => {
        expect(peruanosQueViajan(['PE', 'pe', 'CL', null], [])).toEqual({ pasajeros: 2, tarifasNacionales: false });
        expect(peruanosQueViajan([], ['extranjero', 'nacional'])).toEqual({ pasajeros: 0, tarifasNacionales: true });
        expect(peruanosQueViajan(['US', 'BO'], ['extranjero', 'can', null])).toBeNull();
    });

    it('sólo avisa en un alojamiento, y sólo si viajan peruanos', () => {
        const peruanos = peruanosQueViajan(['PE'], []);

        expect(avisoIgvDeHotel('alojamiento', peruanos)).toContain('1 pasajero peruano');
        expect(avisoIgvDeHotel('transporte', peruanos)).toBeNull();
        expect(avisoIgvDeHotel('alojamiento', null)).toBeNull();
    });

    it('sin manifiesto lo dice por la tarifa', () => {
        expect(avisoIgvDeHotel('alojamiento', peruanosQueViajan([], ['nacional']))).toContain('tarifas de procedencia nacional');
    });
});
