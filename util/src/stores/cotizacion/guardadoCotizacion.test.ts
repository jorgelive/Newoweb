import { describe, it, expect, beforeEach, vi } from 'vitest';
import { setActivePinia, createPinia } from 'pinia';

/**
 * El guardado: que un guardado que la base ACEPTÓ no se cuente nunca como fallido.
 *
 * ── Por qué este test ───────────────────────────────────────────────────────
 * El 16/10/2026 los registros de nginx daban 70 de 70 PUT con 200, y aun así el editor decía
 * «Falló la sincronización con la base de datos». El fallo era nuestro, después de guardar: al
 * borrar la PRIMERA tarifa de un componente, la respuesta traía `cottarifas` como objeto
 * (`{"1": {…}}`, ver `comoLista()` en el store) y el `forEach` del repintado reventaba dentro del
 * mismo `try` que la petición. Se fija aquí con la forma exacta que manda la API.
 */

const { put, post, get } = vi.hoisted(() => ({ put: vi.fn(), post: vi.fn(), get: vi.fn() }));

vi.mock('@/services/apiClient', () => ({ apiClient: { put, post, get } }));

import { useCotizacionEditorStore } from './cotizacionEditorStore';

const tarifa = (id: string) => ({
    id,
    montoCosto: '100',
    moneda: 'USD',
    cantidad: 1,
    rolSnapshot: 'estandar',
    grupoTarifa: 1,
    tituloSnapshot: [],
    nombreInternoSnapshot: 'Tarifa',
    comisionOverrideSnapshot: null,
});

/** La cotización tal como la sirve la API: con `createdAt`, o sea ya existente. */
const cotizacion = (cottarifas: unknown, extra: Record<string, unknown> = {}) => ({
    id: 'cot-1',
    numPax: 1,
    comision: '0',
    adelanto: '50',
    tipoCambio: '3.7',
    estado: 'pendiente',
    publicado: false,
    createdAt: '2026-10-16T10:00:00-05:00',
    cotservicios: [{
        id: 's-1',
        fechaInicioAbsoluta: '2026-11-01T00:00:00',
        tituloSnapshot: [],
        nombreInternoSnapshot: [],
        cotsegmentos: [],
        cotcomponentes: [{
            id: 'c-1',
            modo: 'incluido',
            estado: 'confirmado',
            cantidad: 1,
            fechaHoraInicio: '2026-11-01T09:00:00',
            tituloSnapshot: [],
            nombreInternoSnapshot: 'Tour',
            snapshotItems: [],
            grupos: [],
            cottarifas,
        }],
    }],
    ...extra,
});

describe('guardar una cotización', () => {
    let avisos: string[];

    beforeEach(() => {
        setActivePinia(createPinia());
        put.mockReset();
        post.mockReset();
        get.mockReset();
        avisos = [];
        vi.stubGlobal('alert', (m: string) => { avisos.push(m); });
        vi.stubGlobal('confirm', () => true);
    });

    it('con la primera tarifa borrada, la respuesta trae un objeto y aun así se guarda', async () => {
        const store = useCotizacionEditorStore();
        // @ts-expect-error — fixture mínimo: lleva lo que el guardado lee.
        store.cotizacion = cotizacion([tarifa('t-2')]);

        // Lo que de verdad responde API Platform tras quitar la tarifa del índice 0.
        put.mockResolvedValue({ data: cotizacion({ 1: tarifa('t-2') }) });

        expect(await store.guardarCotizacion()).toBe(true);
        expect(avisos).toEqual(['Cotización guardada exitosamente.']);

        const tarifas = store.cotizacion?.cotservicios?.[0].cotcomponentes?.[0].cottarifas;
        expect(Array.isArray(tarifas), 'la colección tiene que volver a ser una lista').toBe(true);
        expect(tarifas?.map((t) => t.id)).toEqual(['t-2']);
    });

    it('no manda lo que es de solo lectura: contenedorGenerico lo pone el servidor (23/10/2026)', async () => {
        const store = useCotizacionEditorStore();
        const servida = cotizacion([tarifa('t-1')]);
        (servida.cotservicios[0] as Record<string, unknown>).contenedorGenerico = true;
        // @ts-expect-error — fixture mínimo: lleva lo que el guardado lee.
        store.cotizacion = servida;
        put.mockResolvedValue({ data: cotizacion([tarifa('t-1')]) });

        expect(await store.guardarCotizacion()).toBe(true);

        const enviado = put.mock.calls[0][1] as { cotservicios: Record<string, unknown>[] };
        expect(enviado.cotservicios[0]).not.toHaveProperty('contenedorGenerico');
    });

    it('un alta cortada por la red que sí se creó se da por guardada, y deja de ser «nueva»', async () => {
        const store = useCotizacionEditorStore();
        // @ts-expect-error — fixture mínimo, todavía sin `createdAt`: es un alta.
        store.cotizacion = cotizacion([tarifa('t-1')], { createdAt: undefined });

        post.mockRejectedValue(new Error('Network Error'));   // sin `response`: fue la red
        get.mockImplementation((url: string) => url.endsWith('/cot-1')
            ? Promise.resolve({ data: cotizacion([tarifa('t-1')]) })
            : Promise.resolve({ data: { member: [] } }));

        expect(await store.guardarCotizacion()).toBe(true);
        expect(store.cotizacion?.createdAt, 'el siguiente guardado tiene que ser un PUT').toBeTruthy();
        expect(avisos.at(-1)).toBe('Cotización guardada exitosamente.');
    });

    it('un rechazo de la API sigue siendo un fallo, con su motivo', async () => {
        const store = useCotizacionEditorStore();
        // @ts-expect-error — fixture mínimo.
        store.cotizacion = cotizacion([tarifa('t-1')]);

        put.mockRejectedValue({ response: { status: 422, data: { detail: 'La tarifa no tiene moneda.' } } });

        expect(await store.guardarCotizacion()).toBe(false);
        expect(get, 'un 422 no se confunde con un corte de red').not.toHaveBeenCalled();
        expect(avisos).toHaveLength(1);
    });
});
