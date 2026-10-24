import { describe, it, expect } from 'vitest';
import { textoVacio } from './index.ts';

describe('textoVacio', () => {
    it('un español borrado con traducciones viejas dentro está vacío', () => {
        expect(textoVacio([{ language: 'es', content: '' }, { language: 'en', content: '1' }])).toBe(true);
        expect(textoVacio([{ language: 'es', content: '   ' }])).toBe(true);
    });

    it('un nombre escrito sólo en inglés, sin español, no lo está', () => {
        expect(textoVacio([{ language: 'en', content: 'Breakfast' }])).toBe(false);
    });

    it('con español, manda el español', () => {
        expect(textoVacio([{ language: 'es', content: 'Desayuno' }, { language: 'en', content: '' }])).toBe(false);
    });

    it('sin nada, vacío', () => {
        expect(textoVacio([])).toBe(true);
        expect(textoVacio(null)).toBe(true);
        expect(textoVacio([{ language: 'en', content: '' }])).toBe(true);
    });
});
