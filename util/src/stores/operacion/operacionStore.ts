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
    OperacionMensajeWrite,
    FiltrosBiblia,
    LugarOpcion
} from '@/types/operacionModel';
import { construirParamsBiblia } from '@/types/operacionModel';

/** Expediente reducido para el selector de filtros de La Biblia. */
export interface ExpedienteOpcion {
    id: string;
    nombreGrupo: string;
    pasajeroPrincipal?: string | null;
}

/** Cotización reducida para el selector de filtros de La Biblia. */
export interface CotizacionOpcion {
    id: string;
    version?: number | null;
    titulo?: string | null;
    estado?: string | null;
}

export const useOperacionStore = defineStore('operacionStore', () => {
    // ============================================================================
    // ESTADO
    // ============================================================================
    const isLoading = ref<boolean>(false);

    // La Biblia: listado plano de servicios para el equipo de tráfico
    const servicios = ref<OperacionServicio[]>([]);

    /**
     * Cuántos servicios hay DE VERDAD en el rango, frente a los que caben en la página.
     *
     * `construirParamsBiblia()` pide 200 por página y la vista no pagina. Sin este dato,
     * un rango amplio con más de 200 servicios pintaba un cuadro incompleto **sin ningún
     * síntoma**: el operador leía el día y se le quedaban servicios fuera de la pantalla
     * y de la cabeza. Ahora la vista avisa cuando falta algo. Ver docs/Operacion.md §7.
     */
    const totalServicios = ref<number>(0);

    // Vocabulario de lugares para los chips de filtro. Se carga una vez por sesión de vista.
    const lugares = ref<LugarOpcion[]>([]);

    /**
     * Mapa `componenteMaestroId` → nombres de lugar, para pintar los badges de cada fila.
     *
     * Se llena EN LOTE, nunca fila por fila: `OperacionServicio` no tiene relación con el
     * catálogo, así que resolverlo por fila serían N peticiones cruzando de módulo.
     */
    const lugaresPorComponente = ref<Record<string, string[]>>({});

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
     * Permite filtrar por rango de fechas, expediente, cotización, tipo de componente
     * y estados, garantizando que el equipo de tráfico solo vea la logística activa.
     *
     * El orden lo impone el backend (fechaServicio ASC, horaRecojoReal ASC declarado en
     * el #[ApiResource]); no reordenar aquí salvo para agrupar en pantalla.
     */
    const fetchServicios = async (filtros: FiltrosBiblia = {}): Promise<void> => {
        isLoading.value = true;
        try {
            // Sin paramsSerializer a propósito: las claves multivalor ya llevan los
            // corchetes puestos (`tipoComponente[]`) y el serializador por defecto de
            // axios las repite tal cual. Con `indexes: null` los corchetes se pierden
            // y PHP se queda sólo con el último valor, filtrando por un tipo en vez de N.
            const response = await apiClient.get('/platform/ops/operacion_servicios', {
                params: construirParamsBiblia(filtros),
            });
            servicios.value = response.data['hydra:member'] || response.data['member'] || [];
            // Hydra devuelve el total real bajo dos nombres según la versión del formato.
            totalServicios.value = Number(
                response.data['hydra:totalItems'] ?? response.data['totalItems'] ?? servicios.value.length
            );
            await resolverLugaresDeServicios();
        } catch (error) {
            console.error('Error al cargar la Biblia de operaciones:', error);
            throw error;
        } finally {
            isLoading.value = false;
        }
    };

    /**
     * Carga el vocabulario de lugares para los chips de filtro.
     *
     * Sólo los activos: desactivar un lugar es la forma de retirarlo del cuadro sin destruir
     * el etiquetado. Si falla, se deja vacío y la vista simplemente no pinta chips — el
     * cuadro de tráfico tiene que abrirse igual.
     */
    const fetchLugares = async (): Promise<void> => {
        if (lugares.value.length) return;

        try {
            const res = await apiClient.get('/platform/travel/lugares', {
                params: { activo: true, itemsPerPage: 100 },
            });
            const miembros = res.data['hydra:member'] || res.data['member'] || [];

            lugares.value = miembros.map((l: Record<string, unknown>) => ({
                // `.split('/').pop()` como cinturón: si algún día el recurso dejara de exponer
                // `id`, el IRI sigue trayendo el uuid al final.
                id: String(l.id ?? String(l['@id'] ?? '').split('/').pop() ?? ''),
                nombre: String(l.nombre ?? ''),
            }));
        } catch (error) {
            console.error('No se pudo cargar el vocabulario de lugares:', error);
            lugares.value = [];
        }
    };

    /**
     * Resuelve las etiquetas de las filas cargadas, EN UNA SOLA petición.
     *
     * Junta los `componenteMaestroId` distintos y los pide en lote con `?id[]=`, que es el
     * mismo mecanismo que ya usa el editor de cotizaciones para servicios y proveedores
     * (lo atiende `UuidBatchIdExtension`). Nunca una petición por fila: `OperacionServicio`
     * no tiene relación con el catálogo y resolverlo fila a fila serían decenas de saltos
     * entre módulos.
     */
    const resolverLugaresDeServicios = async (): Promise<void> => {
        const ids = new Set<string>();

        servicios.value.forEach((s) => {
            const maestro = (s.cotizacionComponente as { componenteMaestroId?: string } | undefined)
                ?.componenteMaestroId;
            if (maestro) ids.add(maestro);
        });

        if (!ids.size) {
            lugaresPorComponente.value = {};
            return;
        }

        try {
            await fetchLugares();
            const nombrePorIri = new Map(lugares.value.map((l) => [`/platform/travel/lugares/${l.id}`, l.nombre]));

            const query = Array.from(ids).map((id) => `id[]=${id}`).join('&');
            const res = await apiClient.get(`/platform/travel/componentes?${query}&pagination=false`);
            const miembros = res.data['hydra:member'] || res.data['member'] || [];

            const mapa: Record<string, string[]> = {};

            miembros.forEach((c: Record<string, unknown>) => {
                const id = String(c.id ?? String(c['@id'] ?? '').split('/').pop() ?? '');
                const iris = Array.isArray(c.lugares) ? (c.lugares as string[]) : [];
                if (id) {
                    mapa[id] = iris.map((iri) => nombrePorIri.get(iri) ?? '').filter(Boolean);
                }
            });

            lugaresPorComponente.value = mapa;
        } catch (error) {
            // Los badges son decoración: si el catálogo falla, el cuadro sigue siendo usable.
            console.error('No se pudieron resolver las etiquetas de lugar:', error);
            lugaresPorComponente.value = {};
        }
    };

    /** Etiquetas de una fila del cuadro, ya resueltas a nombre. */
    const lugaresDeServicio = (servicio: OperacionServicio): string[] => {
        const maestro = (servicio.cotizacionComponente as { componenteMaestroId?: string } | undefined)
            ?.componenteMaestroId;

        return maestro ? (lugaresPorComponente.value[maestro] ?? []) : [];
    };

    /**
     * Busca expedientes por nombre para el selector de filtros.
     *
     * No se reutiliza cotizacionFileStore.fetchFiles() a propósito: ese store mantiene
     * el listado paginado de la pantalla de expedientes y buscar desde aquí lo
     * sobreescribiría, cambiando lo que ve el usuario en otra vista.
     */
    const buscarExpedientes = async (termino: string): Promise<ExpedienteOpcion[]> => {
        const nombre = termino.trim();
        if (nombre.length < 2) return [];

        try {
            const response = await apiClient.get('/platform/sales/cotizacion_files', {
                params: { nombre, itemsPerPage: 10 },
            });
            const miembros = response.data['hydra:member'] || response.data['member'] || [];

            return miembros.map((f: Record<string, unknown>) => ({
                id: String(f.id ?? ''),
                nombreGrupo: String(f.nombreGrupo ?? 'Sin nombre'),
                pasajeroPrincipal: (f.pasajeroPrincipal as string | null) ?? null,
            }));
        } catch (error) {
            console.error('Error al buscar expedientes:', error);
            return [];
        }
    };

    /**
     * Carga las cotizaciones (versiones) de un expediente.
     *
     * Sólo el GET de item de CotizacionFile expone la colección `cotizaciones`
     * (grupo file:item:read), de ahí que haga falta una llamada aparte.
     */
    const fetchCotizacionesDeExpediente = async (fileId: string): Promise<CotizacionOpcion[]> => {
        try {
            const response = await apiClient.get(`/platform/sales/cotizacion_files/${fileId}`);
            const cotizaciones = response.data.cotizaciones || [];

            return cotizaciones.map((c: Record<string, unknown>) => ({
                id: String(c.id ?? ''),
                version: (c.version as number | null) ?? null,
                titulo: (c.titulo as string | null) ?? null,
                estado: (c.estado as string | null) ?? null,
            }));
        } catch (error) {
            console.error('Error al cargar cotizaciones del expediente:', error);
            return [];
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
        totalServicios,
        ordenesServicio,
        mensajesActivos,
        lugares,
        lugaresPorComponente,
        fetchServicios,
        fetchLugares,
        lugaresDeServicio,
        buscarExpedientes,
        fetchCotizacionesDeExpediente,
        actualizarServicio,
        fetchOrdenesServicio,
        crearOrdenServicio,
        fetchMensajesPorOrden,
        registrarMensaje
    };
});