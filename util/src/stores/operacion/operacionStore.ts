// src/store/operacionStore.ts
import { defineStore } from 'pinia';
import { ref } from 'vue';
import { apiClient } from '@/services/apiClient';
import type {
    OperacionOrdenServicio,
    OperacionServicio,
    OperacionMensaje,
    OperacionOrdenServicioWrite,
    OperacionServicioWrite,
    OperacionMensajeWrite
} from '@/types/operacionModel';

export const useOperacionStore = defineStore('operacionStore', () => {
    // ============================================================================
    // ESTADO
    // ============================================================================
    const isLoading = ref<boolean>(false);

    // La Biblia: listado plano de servicios para el equipo de tráfico
    const servicios = ref<OperacionServicio[]>([]);

    // Panel de Reservas: listado de órdenes agrupadas
    const ordenesServicio = ref<OperacionOrdenServicio[]>([]);

    // Bitácora de comunicación de la OS activa
    const mensajesActivos = ref<OperacionMensaje[]>([]);

    // ============================================================================
    // ACCIONES: LA BIBLIA (SERVICIOS)
    // ============================================================================

    /**
     * Obtiene el listado de servicios operativos según filtros.
     *
     * Este método existe para alimentar "La Biblia" (el cuadro de tráfico diario).
     * Permite filtrar por expediente, por fecha exacta o por órdenes de servicio
     * específicas, garantizando que el equipo de tráfico solo vea la logística activa.
     *
     * @param {Record<string, string>} filtros - Parámetros de búsqueda (ej. { fecha_servicio: '2026-09-14' }).
     */
    const fetchServicios = async (filtros: Record<string, string> = {}): Promise<void> => {
        isLoading.value = true;
        try {
            const response = await apiClient.get('/platform/ops/operacion_servicios', { params: filtros });
            servicios.value = response.data['hydra:member'] || response.data['member'] || [];
        } catch (error) {
            console.error('Error al cargar la Biblia de operaciones:', error);
            throw error;
        } finally {
            isLoading.value = false;
        }
    };

    /**
     * Actualiza un servicio operativo individual.
     *
     * Este método existe para registrar las incidencias diarias del tráfico (ej. cambios
     * de chofer, modificaciones de hora de recojo o confirmación de No-Shows), impactando
     * directamente la ejecución logística sin alterar la cotización.
     *
     * @param {string} id - UUID del servicio operativo.
     * @param {Partial<OperacionServicioWrite>} payload - Los campos a parchear.
     */
    const actualizarServicio = async (id: string, payload: Partial<OperacionServicioWrite>): Promise<void> => {
        isLoading.value = true;
        try {
            const response = await apiClient.patch(
                `/platform/ops/operacion_servicios/${id}`,
                payload
            );

            const index = servicios.value.findIndex(s => s.id === id);
            if (index !== -1) {
                servicios.value[index] = response.data;
            }
        } catch (error) {
            console.error(`Error al actualizar el servicio ${id}:`, error);
            throw error;
        } finally {
            isLoading.value = false;
        }
    };

    // ============================================================================
    // ACCIONES: ÓRDENES DE SERVICIO (OS)
    // ============================================================================

    /**
     * Obtiene las Órdenes de Servicio vigentes.
     *
     * Este método existe para nutrir el panel del equipo de reservas, permitiéndoles
     * hacer seguimiento a las solicitudes enviadas a proveedores y controlar facturación.
     *
     * @param {Record<string, string>} filtros - Parámetros de búsqueda.
     */
    const fetchOrdenesServicio = async (filtros: Record<string, string> = {}): Promise<void> => {
        isLoading.value = true;
        try {
            const response = await apiClient.get('/platform/ops/operacion_orden_servicios', { params: filtros });
            ordenesServicio.value = response.data['hydra:member'] || response.data['member'] || [];
        } catch (error) {
            console.error('Error al cargar órdenes de servicio:', error);
            throw error;
        } finally {
            isLoading.value = false;
        }
    };

    /**
     * Genera una nueva Orden de Servicio y agrupa los servicios seleccionados.
     *
     * Este método existe para empaquetar servicios huérfanos que pertenecen al mismo
     * proveedor dentro del mismo File, creando la cabecera que se usará para el envío
     * formal de correos y la liquidación contable.
     *
     * @param {OperacionOrdenServicioWrite} payload - Cabecera de la orden.
     * @param {string[]} serviciosIds - Array de UUIDs de servicios a agrupar en esta OS.
     */
    const crearOrdenServicio = async (payload: OperacionOrdenServicioWrite, serviciosIds: string[]): Promise<OperacionOrdenServicio> => {
        isLoading.value = true;
        try {
            // 1. Crear cabecera
            const response = await apiClient.post('/platform/ops/operacion_orden_servicios', payload);
            const nuevaOs = response.data;
            ordenesServicio.value.unshift(nuevaOs);

            // 2. Asociar los servicios huérfanos a la nueva OS
            if (!nuevaOs.id || serviciosIds.length === 0) return nuevaOs;
            const osIri = `/platform/ops/operacion_orden_servicios/${nuevaOs.id}`;
            await Promise.all(serviciosIds.map(id =>
                actualizarServicio(id, { ordenServicio: osIri })
            ));

            return nuevaOs;
        } catch (error) {
            console.error('Error al generar la Orden de Servicio:', error);
            throw error;
        } finally {
            isLoading.value = false;
        }
    };

    // ============================================================================
    // ACCIONES: MENSAJERÍA MULTICANAL
    // ============================================================================

    /**
     * Carga el hilo de comunicación de una Orden de Servicio.
     *
     * Este método existe para garantizar la trazabilidad inmutable de qué se le envió
     * al proveedor y cuándo, resolviendo disputas sobre reservas y tarifas.
     *
     * @param {string} ordenServicioId - UUID de la OS.
     */
    const fetchMensajesPorOrden = async (ordenServicioId: string): Promise<void> => {
        try {
            const response = await apiClient.get('/platform/ops/operacion_mensajes', {
                params: { 'ordenServicio': ordenServicioId }
            });
            mensajesActivos.value = response.data['hydra:member'] || response.data['member'] || [];
        } catch (error) {
            console.error('Error al cargar la bitácora de mensajes:', error);
            mensajesActivos.value = [];
        }
    };

    /**
     * Registra un nuevo envío de comunicación al proveedor.
     *
     * Este método existe para guardar el texto enriquecido generado por el operador antes
     * de que sea procesado por los workers para salir por Email, WhatsApp, etc.
     *
     * @param {OperacionMensajeWrite} payload - Contenido HTML/RichText y metadatos.
     */
    const registrarMensaje = async (payload: OperacionMensajeWrite): Promise<void> => {
        try {
            const response = await apiClient.post('/platform/ops/operacion_mensajes', payload);
            mensajesActivos.value.push(response.data);
        } catch (error) {
            console.error('Error al registrar el mensaje de operación:', error);
            throw error;
        }
    };

    return {
        isLoading,
        servicios,
        ordenesServicio,
        mensajesActivos,
        fetchServicios,
        actualizarServicio,
        fetchOrdenesServicio,
        crearOrdenServicio,
        fetchMensajesPorOrden,
        registrarMensaje
    };
});