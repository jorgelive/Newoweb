/* eslint-disable vue/one-component-per-file -- los stubs del router y de los componentes pesados son de prueba */
import { describe, it, expect, vi, beforeEach } from 'vitest';
import { createApp, defineComponent, h, reactive, type App } from 'vue';
import { createRouter, createWebHistory, RouterView, type Router } from 'vue-router';

/**
 * «Atrás» en Finanzas cierra lo que hay encima y NO sale de la vista.
 *
 * La ficha del cobro y la ficha de la reserva son capas de `useCapasEnHistorial`. Se monta la
 * vista REAL con el router real y un historial de navegador: lo único falso son los datos.
 */

const cobro = {
    id: 'c1', url: 'u', pasarela: 'culqi', pasarelaEtiqueta: 'Culqi', estado: 'pendiente', estadoEtiqueta: 'Pendiente',
    vigente: true, moneda: 'USD', monedaSimbolo: '$', montoNeto: '10.00', montoRecargo: '0', montoTotal: '10.00',
    recargoPorcentaje: '0', concepto: 'Saldo', ordenId: null, expiraEn: null, pagadoEn: null, medioDetalle: null,
    autorizacionCodigo: null, creadoPorNombre: null, createdAt: '2026-10-01T00:00:00Z', origenTipo: 'pms_reserva',
    origenId: 'r1', moduloEtiqueta: 'PMS', esManual: false, origenReferencia: 'ABC123', clienteNombre: 'Ana',
    clienteApellido: null, clienteEmail: null, clienteTelefono: null, notas: null, movimientoGeneradoId: null,
};

const store = reactive({
    cobros: [cobro], movimientos: [], totalesCobros: [], estadosCobro: [], medios: [], isLoading: false, error: null,
    cobrosTruncado: false, cajaTruncado: false,
    fetchCobros: vi.fn(async () => {}), fetchMovimientos: vi.fn(async () => {}),
    fetchCobroDetalle: vi.fn(async () => ({ cobro, origen: null })),
});

vi.mock('@/stores/finanzas/cajaStore', () => ({ useCajaStore: () => store }));
vi.mock('@/composables/useConfigDelFront', () => ({
    useConfigDelFront: () => ({ cargar: async () => {}, superaElTope: () => null }),
}));
vi.mock('@/components/common/AppSwitcher.vue', () => ({ default: defineComponent({ render: () => h('span') }) }));
vi.mock('@/components/reservas/ReservaEditDrawer.vue', () => ({
    default: defineComponent({ render: () => h('div', { id: 'drawer-reserva' }) }),
}));

/** `history.back()` dispara `popstate` de forma asíncrona: hay que dejarle pasar. */
const pausa = () => new Promise(r => setTimeout(r, 40));

let router: Router;
let app: App;

beforeEach(async () => {
    app?.unmount();
    const FinanzasView = (await import('./FinanzasView.vue')).default;
    router = createRouter({ history: createWebHistory(), routes: [
        { path: '/', component: { render: () => h('div', 'portal') } },
        { path: '/finanzas', component: FinanzasView },
    ] });
    app = createApp({ render: () => h(RouterView) });
    app.use(router);
    document.body.innerHTML = '<div id="raiz"></div>';
    await router.push('/');
    app.mount('#raiz');
    await router.push('/finanzas');
    await pausa();
});

describe('FinanzasView: «atrás» cierra la capa de encima', () => {
    it('con la ficha del cobro abierta, vuelve al listado', async () => {
        (document.querySelector('article') as HTMLElement).click();
        await pausa();
        expect(router.currentRoute.value.fullPath).toBe('/finanzas?capa=cobro');
        expect(document.querySelector('aside')).not.toBeNull();

        router.back();
        await pausa();

        expect(router.currentRoute.value.fullPath).toBe('/finanzas');
        expect(document.querySelector('aside')).toBeNull();
    });

    it('con la reserva abierta desde la tarjeta, vuelve al listado', async () => {
        (document.querySelector('article button') as HTMLElement).click();
        await pausa();
        expect(router.currentRoute.value.fullPath).toBe('/finanzas?capa=reserva');
        expect(document.querySelector('#drawer-reserva')).not.toBeNull();

        router.back();
        await pausa();

        expect(router.currentRoute.value.fullPath).toBe('/finanzas');
        expect(document.querySelector('#drawer-reserva')).toBeNull();
    });
});
