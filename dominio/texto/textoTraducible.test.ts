import { describe, it, expect } from 'vitest';
import { textoVacio, sinTraduccionesDeLoBorrado } from './index.ts';

const auto = (language: string, content: string) => ({ language, content, origenHash: 'abc' });

describe('textoVacio', () => {
    it('un español borrado con traducciones automáticas dentro está vacío', () => {
        expect(textoVacio([{ language: 'es', content: '' }, auto('en', '1')])).toBe(true);
        expect(textoVacio([{ language: 'es', content: '   ' }])).toBe(true);
    });

    it('el HTML sin texto también: el editor enriquecido deja <br> al vaciar', () => {
        expect(textoVacio([{ language: 'es', content: '<p><br></p>' }, auto('en', '<p>Hi</p>')])).toBe(true);
        expect(textoVacio([{ language: 'es', content: '&nbsp;' }])).toBe(true);
    });

    it('lo escrito a mano o curado sobrevive al español borrado', () => {
        expect(textoVacio([{ language: 'es', content: '' }, { language: 'en', content: 'Breakfast' }])).toBe(false);
        expect(textoVacio([{ language: 'es', content: '' }, { language: 'en', content: 'Hi', origenHash: 'manual' }])).toBe(false);
    });

    it('un nombre escrito sólo en inglés, sin español, no lo está', () => {
        expect(textoVacio([auto('en', 'Breakfast')])).toBe(false);
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

describe('sinTraduccionesDeLoBorrado', () => {
    it('quita las automáticas y deja el español y lo hecho a mano', () => {
        const texto = [
            { language: 'es', content: '' },
            auto('en', '1'),
            { language: 'fr', content: 'À la main' },
            { language: 'it', content: 'Curata', origenHash: 'manual' },
        ];

        expect(sinTraduccionesDeLoBorrado(texto).map((t) => t.language)).toEqual(['es', 'fr', 'it']);
    });

    it('con el español escrito, o sin español, no toca nada', () => {
        const conTexto = [{ language: 'es', content: 'Hola' }, auto('en', 'Hello')];
        const sinEs = [auto('en', 'Hello')];

        expect(sinTraduccionesDeLoBorrado(conTexto)).toBe(conTexto);
        expect(sinTraduccionesDeLoBorrado(sinEs)).toBe(sinEs);
    });
});
