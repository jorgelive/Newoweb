// src/stores/cotizacion/pedidosStore.ts
// ============================================================================
// Pendientes de cotizar (`App\Cotizacion\Entity\CotizacionPedido`).
//
// Un tour o una cotización que un cliente pidió y que el área de Cotizaciones tiene que
// trabajar. Se cierran SOLOS al vincularse un expediente a la conversación —ver
// `CotizacionSincronizadorDeEnlace`—; este store sólo lee la lista y ofrece cerrar uno a mano
// para lo que no pasa por ahí (un duplicado, uno que el cliente retiró).
//
// Endpoint: API Platform bajo `/platform/cotizacion/pedidos` (ver `docs/Cotizaciones.md`).
// ============================================================================
import { defineStore } from 'pinia';
import { ref, computed } from 'vue';
import { apiClient } from '@/services/apiClient';
import { extractApiErrorMessage } from '@/services/apiError';
import type { ApiCotizacionPedido } from '@/types/cotizacionPedidoModel';

export const usePedidosCotizacionStore = defineStore('pedidosCotizacionStore', () => {
    const pedidos = ref<ApiCotizacionPedido[]>([]);
    const loading = ref(false);
    const error = ref<string | null>(null);

    /** Los que siguen sin expediente. Es lo que se mira primero al abrir la pantalla. */
    const pendientes = computed(() => pedidos.value.filter(p => p.pendiente));

    /** Los ya resueltos, de los que se pidieron los últimos primero: es el historial. */
    const resueltos = computed(() =>
        [...pedidos.value]
            .filter(p => !p.pendiente)
            .sort((a, b) => (b.efectuadaAt ?? '').localeCompare(a.efectuadaAt ?? ''))
    );

    /**
     * Trae TODOS —pendientes y resueltos— en una sola llamada: la pantalla no necesita paginar
     * (es una cola de trabajo, no un histórico completo) y separar en dos pestañas locales evita
     * un segundo viaje al cambiar de una a otra.
     */
    const fetchPedidos = async (): Promise<void> => {
        loading.value = true;
        error.value = null;
        try {
            const { data } = await apiClient.get('/platform/cotizacion/pedidos');
            pedidos.value = data['hydra:member'] ?? data['member'] ?? [];
        } catch (err: unknown) {
            error.value = extractApiErrorMessage(err, 'No se pudieron cargar los pendientes de cotizar.');
        } finally {
            loading.value = false;
        }
    };

    /**
     * Lo cierra a mano. El servidor pone quién y cuándo —`CotizacionPedidoProcessor`—; de aquí
     * sólo sale la señal de «ya está», nunca un nombre ni una fecha.
     */
    const marcarHecho = async (pedido: ApiCotizacionPedido): Promise<boolean> => {
        if (!pedido['@id']) return false;

        error.value = null;
        try {
            const { data } = await apiClient.patch<ApiCotizacionPedido>(pedido['@id'], {
                efectuadaAt: new Date().toISOString(),
            });
            pedidos.value = pedidos.value.map(p => (p['@id'] === pedido['@id'] ? data : p));
            return true;
        } catch (err: unknown) {
            error.value = extractApiErrorMessage(err, 'No se pudo marcar el pedido como hecho.');
            return false;
        }
    };

    return { pedidos, pendientes, resueltos, loading, error, fetchPedidos, marcarHecho };
});
