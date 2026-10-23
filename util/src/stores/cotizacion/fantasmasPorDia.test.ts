import { describe, it, expect } from 'vitest';
import { fantasmasPorDia } from './fantasmasPorDia';
import type { CotServicio, I18nContent } from '@/types/cotizacionEditorModel.ts';

/**
 * Los fantasmas tienen que salir en los MISMOS días que el cliente ve el servicio. El reparto lo
 * hace `componerItinerario()`; esto comprueba que el editor le pregunta bien y que no se pinta un
 * fantasma en el día del propio servicio (ahí ya está la tarjeta de verdad).
 */

const texto = (i18n: I18nContent[] | undefined) => i18n?.find((t) => t.language === 'es')?.content ?? '';
const opciones = {
    diaDelServicio: (s: CotServicio) => String(s.fechaInicioAbsoluta).slice(0, 10),
    texto,
};
const es = (content: string): I18nContent[] => [{ language: 'es', content }];

const hotel = (unidad: 'noches' | 'dias' = 'noches', sustantivo = 'noche'): CotServicio => ({
    id: 'hotel',
    fechaInicioAbsoluta: '2027-01-07',
    nombreInternoSnapshot: es('Alojamiento en Cusco'),
    cotsegmentos: [{ id: 'seg-h', dia: 1, orden: 1, fechaAbsoluta: '2027-01-07T00:00:00', tituloSnapshot: es('Tu hotel en Cusco') }],
    cotcomponentes: [{
        id: 'c-h', cotsegmentoId: 'seg-h', sinHorario: true,
        fechaHoraInicio: '2027-01-07T00:00:00', fechaHoraFin: '2027-01-10T00:00:00',
        unidadDeConteo: unidad, sustantivoUnidad: sustantivo,
    }],
} as unknown as CotServicio);

const caminoInca = (): CotServicio => ({
    id: 'inca',
    fechaInicioAbsoluta: '2027-01-02',
    nombreInternoSnapshot: es('Camino Inca'),
    cotsegmentos: [
        { id: 's1', dia: 1, orden: 1, fechaAbsoluta: '2027-01-02T00:00:00', tituloSnapshot: es('Km 82 a Wayllabamba') },
        { id: 's2', dia: 2, orden: 1, fechaAbsoluta: '2027-01-03T00:00:00', tituloSnapshot: es('Paso de la Mujer Muerta') },
        { id: 's3', dia: 3, orden: 1, fechaAbsoluta: '2027-01-04T00:00:00', tituloSnapshot: es('Wiñay Wayna') },
    ],
    cotcomponentes: [
        { id: 'k1', cotsegmento: '/platform/sales/cotizacion_segmentos/s1', fechaHoraInicio: '2027-01-02T06:00:00', fechaHoraFin: '2027-01-02T17:00:00' },
        { id: 'k2', cotsegmentoId: 's2', fechaHoraInicio: '2027-01-03T06:00:00', fechaHoraFin: '2027-01-03T17:00:00' },
        { id: 'k3', cotsegmento: { id: 's3' }, fechaHoraInicio: '2027-01-04T06:00:00', fechaHoraFin: '2027-01-04T17:00:00' },
    ],
} as unknown as CotServicio);

describe('fantasmasPorDia', () => {
    it('un hotel se ve en sus noches siguientes, no en el día de salida', () => {
        const mapa = fantasmasPorDia([hotel()], opciones);

        expect([...mapa.keys()]).toEqual(['2027-01-08', '2027-01-09']);
        expect(mapa.get('2027-01-08')?.[0]).toMatchObject({ servicioId: 'hotel', nombre: 'Alojamiento en Cusco', etiqueta: 'noche 2 de 3', titulos: [] });
        expect(mapa.get('2027-01-09')?.[0].etiqueta).toBe('noche 3 de 3');
    });

    it('lo que se cuenta en días sí cubre el último', () => {
        const mapa = fantasmasPorDia([hotel('dias', 'día')], opciones);

        expect([...mapa.keys()]).toEqual(['2027-01-08', '2027-01-09', '2027-01-10']);
        expect(mapa.get('2027-01-10')?.[0].etiqueta).toBe('día 4 de 4');
    });

    it('un servicio con párrafos en varios días sale en cada uno con su título, venga como venga el segmento', () => {
        const mapa = fantasmasPorDia([caminoInca()], opciones);

        expect([...mapa.keys()]).toEqual(['2027-01-03', '2027-01-04']);
        expect(mapa.get('2027-01-03')?.[0]).toMatchObject({ etiqueta: 'día 2 de 3', titulos: ['Paso de la Mujer Muerta'] });
        expect(mapa.get('2027-01-04')?.[0]).toMatchObject({ etiqueta: 'día 3 de 3', titulos: ['Wiñay Wayna'] });
    });

    it('un componente recién añadido cuenta con su snapshot, no en noches', () => {
        const boleto = hotel();
        const c = boleto.cotcomponentes![0] as unknown as Record<string, unknown>;
        delete c.unidadDeConteo;
        c.unidadDeConteoSnapshot = 'dias';
        c.sustantivoUnidadSnapshot = 'día';

        expect(fantasmasPorDia([boleto], opciones).get('2027-01-10')?.[0].etiqueta).toBe('día 4 de 4');
    });

    it('si una parte sale antes que su tarjeta, se cuenta desde ahí: nunca «día 0»', () => {
        const vuelo = caminoInca();
        vuelo.fechaInicioAbsoluta = '2027-01-03';

        const mapa = fantasmasPorDia([vuelo], opciones);

        expect(mapa.get('2027-01-02')?.[0].etiqueta).toBe('día 1 de 3');
        expect(mapa.get('2027-01-04')?.[0].etiqueta).toBe('día 3 de 3');
        expect(mapa.has('2027-01-03')).toBe(false);
    });

    it('un servicio de un día no deja fantasmas', () => {
        const traslado = {
            ...caminoInca(),
            cotsegmentos: caminoInca().cotsegmentos!.slice(0, 1),
            cotcomponentes: caminoInca().cotcomponentes!.slice(0, 1),
        } as CotServicio;

        expect(fantasmasPorDia([traslado], opciones).size).toBe(0);
    });
});
