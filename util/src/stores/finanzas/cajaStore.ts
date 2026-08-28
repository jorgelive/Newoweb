// src/stores/finanzas/cajaStore.ts
// ============================================================================
// Módulo Finanzas: las dos vistas transversales.
//
// Separado de `enlacesPagoStore` a propósito: aquel sirve el panel de UNA reserva y se
// indexa por documento; este sirve la pantalla global y se indexa por filtros. Meterlos
// juntos obligaba a que el panel arrastrase filtros que no usa, y a que la lista global
// se invalidase cada vez que alguien abría una reserva.
//
// Endpoints: `App\Finanzas\Controller\Api\FinCajaApiController`.
// ============================================================================
import { defineStore } from 'pinia';
import { ref } from 'vue';
import { apiClient } from '@/services/apiClient';
import type {
    FinEnlacePago,
    FinEnlacePagoManualCreate,
    FinCobrosRespuesta,
    FinTotalCobro,
    FinCobroDetalle,
} from '@/types/finEnlacePagoModel';
import type {
    FinCajaFiltros,
    FinMovimiento,
    FinMovimientosRespuesta,
    FinTotalMoneda,
} from '@/types/finMovimientoModel';

export const useCajaStore = defineStore('finanzasCajaStore', () => {
    const isLoading = ref<boolean>(false);
    const error = ref<string | null>(null);

    // --- Pestaña COBROS ---
    const cobros = ref<FinEnlacePago[]>([]);
    const totalesCobros = ref<FinTotalCobro[]>([]);
    const cobrosTruncado = ref<boolean>(false);

    // --- Pestaña CAJA ---
    const movimientos = ref<FinMovimiento[]>([]);
    const totalesCaja = ref<FinTotalMoneda[]>([]);
    const cajaTruncado = ref<boolean>(false);

    /** Catálogo de medios servido por los módulos; nunca se declara en el frontend. */
    const medios = ref<{ value: string; label: string }[]>([]);

    /**
     * Sólo se mandan los filtros con valor.
     *
     * Un `estado=` vacío en la query no significa "todos" para el backend, significa un
     * estado inválido: el resultado era una lista vacía sin explicación.
     */
    const params = (filtros: FinCajaFiltros, claves: (keyof FinCajaFiltros)[]): Record<string, string> => {
        const salida: Record<string, string> = {};
        for (const clave of claves) {
            const valor = filtros[clave];
            if (valor) salida[clave === 'q' ? 'q' : clave] = valor;
        }
        return salida;
    };

    /**
     * Emite un cobro manual y lo coloca el primero de la lista.
     *
     * Se inserta en local en vez de recargar: el operador acaba de crearlo y lo que quiere
     * es copiar la URL ya, no esperar a que vuelva la consulta con sus filtros.
     */
    const crearManual = async (payload: FinEnlacePagoManualCreate): Promise<FinEnlacePago> => {
        const { data } = await apiClient.post<{ enlace: FinEnlacePago }>('/finanzas/enlaces-pago/manual', payload);
        cobros.value = [data.enlace, ...cobros.value];
        return data.enlace;
    };

    /**
     * Anula un cobro y sustituye SU fila con lo que devuelve el backend.
     *
     * ── Por qué se anula desde aquí y no sólo desde la reserva ─────────────────
     * Un cobro **manual** nace sin `origenId` —es lo que lo define— así que no aparece en el
     * panel de ninguna reserva, y sólo se crea desde esta pantalla. Sin este botón, un manual
     * emitido por error se quedaba vigente y pagable hasta caducar; y con vigencia 0 no
     * caducaba nunca. El endpoint siempre lo permitió: va por id y no mira el origen.
     *
     * ── Se sustituye la fila, no se recarga la lista ───────────────────────────
     * Recargar traería la consulta entera con sus filtros —hasta 500 filas— para cambiar una
     * etiqueta de estado, y devolvería el scroll al principio con la ficha abierta encima. El
     * backend responde el enlace ya serializado, así que la verdad de la fila sigue siendo
     * suya: aquí no se compone ningún estado a mano.
     *
     * ⚠️ Gemelo de `enlacesPagoStore.anular()`, que hace lo mismo sobre la lista del panel de
     * una reserva. Son dos listas distintas y cada store mantiene la suya; si cambia el
     * endpoint o su respuesta, hay que tocar los dos.
     */
    const anularCobro = async (id: string): Promise<FinEnlacePago> => {
        const { data } = await apiClient.post<{ enlace: FinEnlacePago }>(
            `/finanzas/enlaces-pago/${id}/anular`, {},
        );
        cobros.value = cobros.value.map(c => (c.id === id ? data.enlace : c));

        return data.enlace;
    };

    const fetchCobros = async (filtros: FinCajaFiltros): Promise<void> => {
        isLoading.value = true;
        error.value = null;
        try {
            const { data } = await apiClient.get<FinCobrosRespuesta>('/finanzas/caja/cobros', {
                params: params(filtros, ['desde', 'hasta', 'estado', 'q']),
            });
            cobros.value = data.cobros ?? [];
            totalesCobros.value = data.totales ?? [];
            cobrosTruncado.value = !!data.truncado;
        } catch (err) {
            error.value = mensajeDeError(err);
        } finally {
            isLoading.value = false;
        }
    };

    const fetchMovimientos = async (filtros: FinCajaFiltros): Promise<void> => {
        isLoading.value = true;
        error.value = null;
        try {
            const { data } = await apiClient.get<FinMovimientosRespuesta>('/finanzas/caja/movimientos', {
                params: params(filtros, ['desde', 'hasta', 'medio', 'q']),
            });
            movimientos.value = data.movimientos ?? [];
            totalesCaja.value = data.totales ?? [];
            cajaTruncado.value = !!data.truncado;
            medios.value = data.medios ?? [];
        } catch (err) {
            error.value = mensajeDeError(err);
        } finally {
            isLoading.value = false;
        }
    };

    /**
     * La ficha de UN cobro, con los datos de su documento de origen.
     *
     * Va en su propia petición y no en el listado a propósito: resolver el origen es
     * preguntarle a su módulo, o sea una consulta por fila, y en un listado de hasta 500 serían
     * 500 consultas para pintar algo que casi nunca se mira. Ver `FinCajaApiController`.
     *
     * No toca el estado del store: devuelve y se va, para que abrir una ficha no altere la
     * lista que hay detrás.
     */
    const fetchCobroDetalle = async (id: string): Promise<FinCobroDetalle> => {
        const { data } = await apiClient.get<FinCobroDetalle>(`/finanzas/caja/cobros/${id}`);

        return data;
    };

    /** El backend responde `{error: "..."}` plano, no hydra. */
    const mensajeDeError = (err: unknown): string => {
        const data = (err as { response?: { data?: { error?: string } } })?.response?.data;
        return data?.error || 'No se pudieron cargar los datos.';
    };

    return {
        isLoading,
        error,
        cobros,
        totalesCobros,
        cobrosTruncado,
        movimientos,
        totalesCaja,
        cajaTruncado,
        medios,
        fetchCobros,
        fetchCobroDetalle,
        fetchMovimientos,
        crearManual,
        anularCobro,
    };
});
