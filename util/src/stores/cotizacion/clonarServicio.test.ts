import { describe, it, expect } from 'vitest';
import { clonarServicio, diasEntre } from './clonarServicio';
import type { CotServicio } from '@/types/cotizacionEditorModel.ts';

/**
 * Lo que la copia de un servicio no puede hacer mal sin que nadie se entere:
 *
 * - **Compartir un id con el original.** Al guardar, la copia «actualizaría» la fila original en
 *   vez de crear la suya, o un componente seguiría colgando del segmento del original.
 * - **Mover las horas por separado.** Un traslado a las 05:30 y su tren a las 07:45 tienen que
 *   seguir separados igual en el día nuevo.
 * - **Llevarse los subgrupos a otro expediente**, donde serían reservas ajenas.
 */

const SEG = '11111111-1111-4111-8111-111111111111';
const COMP = '22222222-2222-4222-8222-222222222222';
const COMP_COPIA = '33333333-3333-4333-8333-333333333333';
const MAESTRO = '99999999-9999-4999-8999-999999999999';

const servicio = (): CotServicio => ({
    id: '00000000-0000-4000-8000-000000000000',
    servicioMaestroId: MAESTRO,
    fechaInicioAbsoluta: '2027-01-07',
    nombreInternoSnapshot: [{ language: 'es', content: 'Alojamiento en Cusco' }],
    itinerarioNombreInternoSnapshot: [],
    tituloSnapshot: [],
    cotsegmentos: [{ id: SEG, fechaAbsoluta: '2027-01-07T00:00:00' }],
    cotcomponentes: [
        {
            id: COMP,
            cotsegmentoId: SEG,
            cotsegmento: `/platform/sales/cotizacion_segmentos/${SEG}`,
            fechaHoraInicio: '2027-01-07T05:30:00',
            fechaHoraFin: '2027-01-10T07:45:00',
            grupos: ['/platform/sales/cotizacion_file_grupos/abc'],
            cottarifas: [{ id: '44444444-4444-4444-8444-444444444444', tarifaMaestraId: MAESTRO }],
            snapshotItems: [],
            detallesOperativos: [],
        },
        {
            id: COMP_COPIA,
            duplicadoDe: COMP,
            cotsegmentoId: SEG,
            fechaHoraInicio: '2027-01-07T05:30:00',
            fechaHoraFin: '2027-01-07T07:45:00',
            grupos: [],
            cottarifas: [],
            snapshotItems: [],
            detallesOperativos: [],
        },
    ],
} as unknown as CotServicio);

let contador = 0;
const secuencia = () => `nuevo-${++contador}`;

describe('clonarServicio', () => {
    it('renueva todos los ids del árbol y reapunta las referencias internas', () => {
        const original = servicio();
        const copia = clonarServicio(original, { fechaDestino: '2027-01-07', mismoExpediente: true, nuevoId: secuencia });

        const [c1, c2] = copia.cotcomponentes!;
        const seg = copia.cotsegmentos![0];

        expect(copia.id).not.toBe(original.id);
        expect(seg.id).not.toBe(SEG);
        expect(c1.id).not.toBe(COMP);
        expect(c1.cottarifas[0].id).not.toBe('44444444-4444-4444-8444-444444444444');

        expect(c1.cotsegmentoId).toBe(seg.id);
        expect(c1.cotsegmento).toBe(`/platform/sales/cotizacion_segmentos/${seg.id}`);
        expect(c2.duplicadoDe).toBe(c1.id);
    });

    it('anula un duplicadoDe cuyo original ya no está en el servicio', () => {
        const original = servicio();
        original.cotcomponentes = original.cotcomponentes!.slice(1);   // queda sólo la copia
        const copia = clonarServicio(original, { fechaDestino: '2027-01-07', mismoExpediente: true, nuevoId: secuencia });

        expect(copia.cotcomponentes![0].duplicadoDe).toBeNull();
    });

    it('no toca lo que apunta fuera del árbol: el catálogo', () => {
        const copia = clonarServicio(servicio(), { fechaDestino: '2027-01-07', mismoExpediente: true, nuevoId: secuencia });

        expect(copia.servicioMaestroId).toBe(MAESTRO);
        expect(copia.cotcomponentes![0].cottarifas[0].tarifaMaestraId).toBe(MAESTRO);
    });

    it('corre todas las fechas los mismos días y conserva su forma', () => {
        const copia = clonarServicio(servicio(), { fechaDestino: '2027-02-01', mismoExpediente: true, nuevoId: secuencia });
        const c1 = copia.cotcomponentes![0];

        expect(copia.fechaInicioAbsoluta).toBe('2027-02-01');
        expect(copia.cotsegmentos![0].fechaAbsoluta).toBe('2027-02-01T00:00:00');
        expect(c1.fechaHoraInicio).toBe('2027-02-01T05:30:00');
        expect(c1.fechaHoraFin).toBe('2027-02-04T07:45:00');
    });

    it('conserva los subgrupos en el mismo expediente y los vacía en otro', () => {
        const mismo = clonarServicio(servicio(), { fechaDestino: '2027-01-07', mismoExpediente: true, nuevoId: secuencia });
        const otro = clonarServicio(servicio(), { fechaDestino: '2027-01-07', mismoExpediente: false, nuevoId: secuencia });

        expect(mismo.cotcomponentes![0].grupos).toEqual(['/platform/sales/cotizacion_file_grupos/abc']);
        expect(otro.cotcomponentes![0].grupos).toEqual([]);
    });

    it('deja el original como estaba', () => {
        const original = servicio();
        clonarServicio(original, { fechaDestino: '2027-03-01', mismoExpediente: false, nuevoId: secuencia });

        expect(original).toEqual(servicio());
    });
});

describe('diasEntre', () => {
    it('cuenta por fecha, sin que la hora mueva el resultado', () => {
        expect(diasEntre('2027-01-07T23:59:00', '2027-01-08')).toBe(1);
        expect(diasEntre('2027-01-08', '2027-01-07T00:30:00')).toBe(-1);
        expect(diasEntre('2026-12-31', '2027-01-01')).toBe(1);
    });
});
