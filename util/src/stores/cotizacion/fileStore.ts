import { defineStore } from 'pinia';
import { ref, computed } from 'vue';
import { apiClient } from '@/services/apiClient';
import { extractApiErrorMessage, esErrorSilencioso } from '@/services/apiError';
import {ApiCotizacionFile, ApiCotizacionFileWrite, I18nContent} from '@/types/fileDetalleModel.ts';
import type { PlanReconciliacion, AplicarPlanPayload, ResultadoAplicacion, InformeCoherencia } from '@/types/operacionModel';
import type { EstadoFile } from '@/types/cotizacionEditorModel';

// ============================================================================
// TIPOS AUTOGENERADOS Y EXTENDIDOS (HÍBRIDOS)
// ============================================================================

/**
 * Datos de un pasajero del manifiesto tal como los manda el formulario. `file`
 * (la IRI del expediente) solo viaja al crear: en la edición el destino ya lo
 * fija la IRI del propio pasajero.
 */
export interface PasajeroPayload {
    nombre?: string;
    apellido?: string;
    pais?: string;
    // ⚠️ `null` y no sólo `undefined`, y la diferencia importa: `undefined` se cae del JSON y el
    // backend no se entera, así que vaciar un campo sería imposible. `null` viaja y borra. El
    // formulario manda `f.sexo || null` justo por eso, y el tipo se había quedado atrás — el
    // payload era correcto y lo que no compilaba era la promesa.
    sexo?: string | null;
    tipo?: string | null;
    telefono?: string | null;
    observaciones?: string | null;
    /** Espejo de `CotizacionPasajeroIdentificacion`: una persona lleva DNI *y* pasaporte. */
    identificaciones?: Array<{ tipo: string; numero: string; vencimiento?: string | null }>;
    /** A qué subgrupos pertenece. Se manda la lista ENTERA: `orphanRemoval` reemplaza. */
    pertenencias?: Array<{ grupo: string }>;
    fechanacimiento?: string | null;
    file?: string;
}

export interface ApiIdioma {
    id: string;         // código de idioma: 'es', 'en', 'pt'...
    nombre: string;
    bandera?: string;
    prioridad?: number;
}

export const useCotizacionFileStore = defineStore('cotizacionFileStore', () => {

    // ============================================================================
    // ESTADOS
    // ============================================================================
    const files = ref<ApiCotizacionFile[]>([]);
    const loadingFiles = ref<boolean>(false);
    const loadingMore = ref<boolean>(false);
    const hasNextPage = ref<boolean>(true);
    const currentPage = ref<number>(1);
    const error = ref<string | null>(null);
    const searchTerm = ref<string>('');

    // Idiomas disponibles para revisar traducciones (AutoTranslate)
    const idiomasDisponibles = ref<ApiIdioma[]>([]);

    // ============================================================================
    // GETTERS
    // ============================================================================
    const getActiveFiles = computed(() => files.value.filter(f => f.estado === 'abierto'));

    /**
     * Qué estados enseña el dashboard. **Arranca acotado a los abiertos.**
     *
     * Es el estado normal de trabajo: lo ganado y lo perdido son historia, y con el orden por
     * fecha de creación un expediente cerrado en marzo empuja hacia abajo a los que sí hay que
     * mover hoy. `null` = todos.
     *
     * Se filtra en el SERVIDOR y no aquí: filtrando en el cliente, la paginación traería veinte
     * expedientes y enseñaría tres, y «cargar más» pediría la página siguiente de una lista que
     * no es la que se está viendo.
     */
    const estadoFiltro = ref<EstadoFile | null>('abierto');

    // ============================================================================
    // ACCIONES PRINCIPALES (EXPEDIENTES)
    // ============================================================================

    const fetchFiles = async (page: number = 1, append: boolean = false): Promise<void> => {
        if (append) {
            loadingMore.value = true;
        } else {
            loadingFiles.value = true;
            files.value = [];
        }

        error.value = null;

        try {
            const nombre = searchTerm.value.trim();
            const query = `/platform/sales/cotizacion_files?page=${page}&order[createdAt]=desc`
                + (nombre ? `&nombre=${encodeURIComponent(nombre)}` : '')
                + (estadoFiltro.value ? `&estado=${estadoFiltro.value}` : '');
            const response = await apiClient.get(query);
            const rawData = response.data;
            const newFiles = rawData['hydra:member'] || rawData['member'] || [];

            if (append) {
                files.value.push(...newFiles);
            } else {
                files.value = newFiles;
            }

            const viewData = rawData['hydra:view'] || rawData['view'];
            hasNextPage.value = !!(viewData && (viewData['hydra:next'] || viewData['next']));
            currentPage.value = page;

        } catch (err: unknown) {
            if (!esErrorSilencioso(err)) {
                error.value = extractApiErrorMessage(err, 'Error de red al cargar los expedientes.');
            }
        } finally {
            loadingFiles.value = false;
            loadingMore.value = false;
        }
    };

    /**
     * Aplica el término de búsqueda (nombre de grupo o pasajero principal) y
     * recarga desde la página 1.
     */
    const setSearchTerm = async (term: string): Promise<void> => {
        searchTerm.value = term;
        await fetchFiles(1);
    };

    /** Cambia el estado que se enseña y recarga desde la primera página. */
    const setEstadoFiltro = async (estado: EstadoFile | null): Promise<void> => {
        estadoFiltro.value = estado;
        await fetchFiles(1);
    };

    /**
     * Carga los idiomas activos (prioridad > 0) ordenados por prioridad desc.
     * Usado para el selector de idioma que revisa el contenido AutoTranslate.
     */
    const fetchIdiomas = async (): Promise<void> => {
        try {
            const response = await apiClient.get('/platform/maestro/idiomas?prioridad[gt]=0&order[prioridad]=desc');
            idiomasDisponibles.value = response.data['hydra:member'] || response.data['member'] || [];
        } catch {
            idiomasDisponibles.value = [{ id: 'es', nombre: 'Español', bandera: '🇪🇸', prioridad: 1 }];
        }
    };

    /**
     * Solicita la clonación profunda de una cotización al servidor.
     * Utiliza el endpoint custom de API Platform que ejecuta la lógica en base de datos.
     *
     * @param iriOrId El UUID o IRI de la cotización a clonar.
     * @returns {Promise<boolean>} true si se clonó con éxito, false en caso de error.
     */
    const cloneCotizacion = async (iriOrId: string): Promise<boolean> => {
        error.value = null;
        const id = String(iriOrId).includes('/') ? String(iriOrId).split('/').pop() : iriOrId;

        try {
            // Se envía un body vacío {}. El interceptor pondrá application/ld+json
            // pero Symfony lo ignorará de forma segura gracias a 'deserialize: false'.
            await apiClient.post(`/platform/sales/client/cotizacion/${id}/clonar`, {});
            return true;
        } catch (err: unknown) {
            error.value = extractApiErrorMessage(err, 'Error al clonar la versión de la cotización.');
            return false;
        }
    };

    /**
     * Congela una foto de la cotización ANTES de tocarla.
     *
     * ⚠️ **No es `cloneCotizacion` con otro estado.** Aquélla clona hacia adelante —la copia es la
     * nueva propuesta— y vale mientras se está vendiendo. Ésta clona hacia atrás: la copia es el
     * pasado y la cotización viva conserva su id, sus componentes y, con ellos, sus órdenes de
     * servicio. Después de vender, la primera obliga a reemitirlo todo.
     *
     * El porqué completo está en `GuardarHistoricoProcessor` y en `docs/Cotizaciones.md` §6.j.
     */
    const guardarHistorico = async (iriOrId: string): Promise<boolean> => {
        error.value = null;
        const id = String(iriOrId).includes('/') ? String(iriOrId).split('/').pop() : iriOrId;

        try {
            await apiClient.post(`/platform/sales/client/cotizacion/${id}/historico`, {});
            return true;
        } catch (err: unknown) {
            error.value = extractApiErrorMessage(err, 'Error al guardar el histórico de la cotización.');
            return false;
        }
    };

    // ────────────────────────────────────────────────────────────────────────
    // SUBGRUPOS DEL EXPEDIENTE
    //
    // Salón, grupo, habitación, reserva aérea. No anidan: en un padrón real 9 de
    // cada 10 grupos aparecen en más de un salón, así que son ejes cruzados y la
    // pertenencia es N:M. Ver docs/Cotizaciones.md §6.m.
    // ────────────────────────────────────────────────────────────────────────

    const crearGrupo = async (
        fileId: string,
        payload: { tipo: string; subeje?: string; clave: string; nombre?: string | null; detalle?: string | null }
    ): Promise<boolean> => {
        error.value = null;
        try {
            await apiClient.post('/platform/sales/cotizacion_file_grupos', {
                ...payload,
                file: `/platform/sales/cotizacion_files/${fileId}`,
            });
            return true;
        } catch (err: unknown) {
            // El 422 más probable es la unicidad `(file, tipo, clave)`: ese grupo ya existe.
            error.value = extractApiErrorMessage(err, 'No se pudo crear el subgrupo. ¿Ya existe uno con esa clave?');
            return false;
        }
    };

    /**
     * Corrige un subgrupo ya creado: su tramo, su clave, su rótulo o su itinerario.
     *
     * ⚠️ Cambiar la CLAVE renombra el grupo, no crea otro: las pertenencias apuntan a su `id`, así
     * que la gente se queda dentro. Pero el padrón casa por clave, de modo que un .xlsx con la
     * clave vieja crearía un grupo nuevo al reimportarlo — hay que corregirla también en la hoja.
     */
    const actualizarGrupo = async (
        iri: string,
        payload: { tipo?: string; subeje?: string; clave?: string; nombre?: string | null; detalle?: string | null }
    ): Promise<boolean> => {
        error.value = null;
        try {
            await apiClient.patch(iri, payload, {
                headers: { 'Content-Type': 'application/merge-patch+json' },
            });
            return true;
        } catch (err: unknown) {
            error.value = extractApiErrorMessage(err, 'No se pudo guardar el subgrupo. ¿Ya existe otro con esa clave?');
            return false;
        }
    };

    const eliminarGrupo = async (iri: string): Promise<boolean> => {
        error.value = null;
        try {
            await apiClient.delete(iri);
            return true;
        } catch (err: unknown) {
            error.value = extractApiErrorMessage(err, 'No se pudo eliminar el subgrupo.');
            return false;
        }
    };

    interface ResultadoVuelos {
        expediente: string;
        grupo: string | null;
        /** Lo que va a pasar (o pasó). */
        cambios: string[];
        /** Cosas que mirar, pero que no impiden guardar: un vuelo sin nadie dentro. */
        avisos: string[];
        /** Lo que NO se hizo: un PNR que no existe, un JSON ilegible. */
        problemas: string[];
        hayCambios: boolean;
    }

    /** Lo que devuelve una carga de padrón, en ensayo o de verdad. */
    interface ResultadoPadron {
        expediente: string;
        ensayo: boolean;
        filasLeidas: number;
        pasajerosCreados: number;
        pasajerosActualizados: number;
        identificacionesCreadas: number;
        gruposCreados: number;
        pertenenciasCreadas: number;
        pertenenciasQuitadas: number;
        noEstanEnElArchivo: string[];
        avisos: string[];
        errores: string[];
    }

    /**
     * Carga vuelos desde un JSON pegado a mano. En ENSAYO por defecto.
     *
     * Mismo trato que el padrón —el backend escribe dentro de una transacción y la deshace—, así
     * que el informe incluye lo que fallaría al guardar.
     *
     * ⚠️ El 422 trae el motivo dentro y se devuelve en vez de tirarlo: cuando el JSON viene de
     * pegar un correo, «falta una coma en la línea 40» es lo único accionable.
     */
    const cargarVuelos = async (
        fileId: string,
        json: string,
        ensayo = true,
    ): Promise<ResultadoVuelos | null> => {
        error.value = null;

        try {
            const { data } = await apiClient.post(
                `/cotizacion/user/vuelos/cargar/${fileId}?ensayo=${ensayo ? 1 : 0}`,
                { json },
            );
            return data as ResultadoVuelos;
        } catch (err: unknown) {
            const cuerpo = (err as { response?: { data?: { error?: string } } })?.response?.data;
            if (cuerpo?.error) {
                return { expediente: '', grupo: '', cambios: [], avisos: [], problemas: [cuerpo.error], hayCambios: false };
            }
            error.value = extractApiErrorMessage(err, 'No se pudieron cargar los vuelos.');
            return null;
        }
    };

    /**
     * Carga un padrón. En ENSAYO por defecto.
     *
     * El ensayo no es una estimación: el backend escribe dentro de una transacción y la deshace,
     * así que lo que devuelve incluye lo que fallaría al guardar.
     */
    const cargarPadron = async (fileId: string, archivo: File, ensayo = true): Promise<ResultadoPadron | null> => {
        error.value = null;
        const fd = new FormData();
        fd.append('padron', archivo);

        try {
            const { data } = await apiClient.post(
                `/cotizacion/user/padron/cargar/${fileId}?ensayo=${ensayo ? 1 : 0}`,
                fd,
                { headers: { 'Content-Type': 'multipart/form-data' } },
            );
            return data as ResultadoPadron;
        } catch (err: unknown) {
            // Un 422 trae el informe con los errores dentro: se devuelve para poder pintarlos.
            const cuerpo = (err as { response?: { data?: ResultadoPadron } })?.response?.data;
            if (cuerpo?.errores) { return cuerpo; }
            error.value = extractApiErrorMessage(err, 'No se pudo leer el padrón.');
            return null;
        }
    };

    // ────────────────────────────────────────────────────────────────────────
    // RECONCILIACIÓN CON OPERACIONES — dos pasos, nunca uno
    //
    // La generación automática sólo se dispara en la TRANSICIÓN a `confirmado`, y
    // ocurre una única vez: lo que se edite después no llega a Operaciones. Pero
    // regenerar a ciegas tampoco vale, porque las filas de La Biblia guardan cosas
    // que no están en la cotización (hora pactada, prestador, teléfono del recojo).
    // De ahí los dos pasos: se calcula el diff, lo revisa una persona, y sólo
    // entonces se aplica lo aprobado. Ver docs/Operacion.md §3.5.
    // ────────────────────────────────────────────────────────────────────────

    /**
     * Calcula el plan de cambios. **No escribe nada.**
     *
     * El backend responde 422 si la cotización no está confirmada o es de catálogo;
     * ese mensaje ya está escrito para el operador, así que se propaga tal cual.
     */
    /**
     * Busca configuraciones a medias en ESTA cotización: ids puestos con su nombre vacío y demás.
     *
     * `reparar` va como endpoint distinto, no como parámetro: mirar lo puede hacer quien sólo
     * consulta, y escribir no. Ver `CoherenciaCatalogoChecker` para qué se repara y qué se avisa.
     */
    const revisarCoherencia = async (iriOrId: string, reparar = false): Promise<InformeCoherencia | null> => {
        // Mismo desmenuzado que `planificarOperacion`: acepta el IRI o el uuid pelado.
        const id = String(iriOrId).includes('/') ? String(iriOrId).split('/').pop() : iriOrId;
        if (!id) return null;

        try {
            const ruta = reparar ? 'coherencia/reparar' : 'coherencia';
            const { data } = await apiClient.post<InformeCoherencia>(`/platform/sales/cotizacions/${id}/${ruta}`, {});

            return data;
        } catch (err: unknown) {
            error.value = extractApiErrorMessage(err, 'No se pudo revisar la coherencia.');

            return null;
        }
    };

    const planificarOperacion = async (iriOrId: string): Promise<PlanReconciliacion | null> => {
        error.value = null;
        const id = String(iriOrId).includes('/') ? String(iriOrId).split('/').pop() : iriOrId;

        try {
            // Body vacío: la operación declara 'deserialize: false' y sólo usa el {id}.
            const response = await apiClient.post<PlanReconciliacion>(
                `/platform/sales/cotizacions/${id}/operacion/plan`,
                {}
            );
            return response.data;
        } catch (err: unknown) {
            error.value = extractApiErrorMessage(err, 'No se pudo calcular el plan de operación.');
            return null;
        }
    };

    /**
     * Aplica ÚNICAMENTE lo aprobado, y sólo si la firma del plan sigue vigente.
     *
     * Si alguien tocó la operación mientras se revisaba, el backend responde 422 y
     * hay que recalcular: aplicar decisiones tomadas sobre datos viejos es justo lo
     * que la firma existe para impedir.
     */
    const aplicarPlanOperacion = async (
        iriOrId: string,
        payload: AplicarPlanPayload
    ): Promise<ResultadoAplicacion | null> => {
        error.value = null;
        const id = String(iriOrId).includes('/') ? String(iriOrId).split('/').pop() : iriOrId;

        try {
            const response = await apiClient.post<ResultadoAplicacion>(
                `/platform/sales/cotizacions/${id}/operacion/aplicar`,
                payload
            );
            return response.data;
        } catch (err: unknown) {
            error.value = extractApiErrorMessage(err, 'No se pudieron aplicar los cambios de operación.');
            return null;
        }
    };

    const createFile = async (payload: ApiCotizacionFileWrite): Promise<ApiCotizacionFile | null> => {
        loadingFiles.value = true;
        error.value = null;

        try {
            const response = await apiClient.post<ApiCotizacionFile>('/platform/sales/cotizacion_files', payload);
            files.value.unshift(response.data);
            return response.data;
        } catch (err: unknown) {
            error.value = extractApiErrorMessage(err, 'Error al crear el expediente.');
            return null;
        } finally {
            loadingFiles.value = false;
        }
    };

    const updateFile = async (iri: string, payload: Partial<ApiCotizacionFileWrite>): Promise<ApiCotizacionFile | null> => {
        loadingFiles.value = true;
        error.value = null;

        try {
            // Ya no necesitas pasar los headers manualmente, el interceptor los pone
            const response = await apiClient.patch<ApiCotizacionFile>(iri, payload);

            const index = files.value.findIndex(f => f['@id'] === iri || f.id === iri);
            if (index !== -1) {
                files.value[index] = { ...files.value[index], ...response.data };
            }
            return response.data;
        } catch (err: unknown) {
            error.value = extractApiErrorMessage(err, 'Error al actualizar.');
            return null;
        } finally {
            loadingFiles.value = false;
        }
    };

    const deleteCotizacion = async (iri: string): Promise<boolean> => {
        try {
            await apiClient.delete(iri);
            return true;
        } catch {
            return false;
        }
    };

    const deleteFile = async (iri: string): Promise<boolean> => {
        try {
            await apiClient.delete(iri);
            files.value = files.value.filter(f => f['@id'] !== iri && f.id !== iri);
            return true;
        } catch {
            return false;
        }
    };

    const updateCotizacionPropuesta = async (iri: string, propuesta: number): Promise<boolean> => {
        error.value = null;
        try {
            await apiClient.patch(iri, { propuesta });
            return true;
        } catch (err: unknown) {
            error.value = extractApiErrorMessage(err, 'Error al actualizar la versión.');
            return false;
        }
    };

    /**
     * Publica o despublica una propuesta.
     *
     * ⚠️ Separado de `estado` a propósito: son dos preguntas distintas —dónde está comercialmente
     * y si el cliente puede verla—, y mezclarlas obligaba a poner «enviada» sólo para conseguir
     * una visibilidad. Ver `docs/PlanPropuestaOperativa.md` §2.
     */
    const actualizarPublicado = async (iri: string, publicado: boolean): Promise<boolean> => {
        error.value = null;
        try {
            await apiClient.patch(iri, { publicado });
            return true;
        } catch (err: unknown) {
            error.value = extractApiErrorMessage(err, 'Error al cambiar la publicación.');
            return false;
        }
    };

    /**
     * Extrae un preview truncado y sin HTML de un campo AutoTranslate (I18nContent[]).
     * Usado para previsualizar `resumen` en la tarjeta de versión sin abrir el motor.
     */
    const extraerResumenPreview = (resumen: I18nContent[] | null | undefined, idiomaPreferido = 'es', maxLen = 90): string => {
        if (!resumen || !Array.isArray(resumen) || resumen.length === 0) return '';

        const match = resumen.find((r) => r.language === idiomaPreferido) || resumen[0];
        const texto = match?.content || '';

        const sinHtml = texto.replace(/<[^>]*>/g, '').trim();
        return sinHtml.length > maxLen ? sinHtml.slice(0, maxLen) + '…' : sinHtml;
    };

    // ============================================================================
    // ACCIONES DE PASAJEROS Y BÓVEDA DIGITAL
    // ============================================================================

    const uploadDocument = async (formData: FormData): Promise<boolean> => {
        error.value = null;
        try {
            await apiClient.post('/platform/sales/cotizacion_filearchivos', formData, {
                headers: { 'Content-Type': 'multipart/form-data' }
            });
            return true;
        } catch (err: unknown) {
            error.value = extractApiErrorMessage(err, 'Error al subir el documento.');
            return false;
        }
    };

    const updateDocument = async (
        iri: string,
        payload: { nombre?: I18nContent[] | null; tipoArchivo: string; sobreescribirTraduccion?: boolean }
    ): Promise<boolean> => {
        error.value = null;
        try {
            await apiClient.patch(iri, payload);
            return true;
        } catch (err: unknown) {
            error.value = extractApiErrorMessage(err, 'Error al actualizar el documento.');
            return false;
        }
    };

    const deleteDocument = async (iri: string): Promise<boolean> => {
        try {
            await apiClient.delete(iri);
            return true;
        } catch {
            return false;
        }
    };

    const addPassenger = async (payload: PasajeroPayload): Promise<boolean> => {
        error.value = null;
        try {
            await apiClient.post('/platform/sales/cotizacion_filepasajeros', payload);
            return true;
        } catch (err: unknown) {
            error.value = extractApiErrorMessage(err, 'Error al registrar el pasajero.');
            return false;
        }
    };

    const updatePassenger = async (iri: string, payload: PasajeroPayload): Promise<boolean> => {
        error.value = null;
        try {
            await apiClient.patch(iri, payload);
            return true;
        } catch (err: unknown) {
            error.value = extractApiErrorMessage(err, 'Error al actualizar el pasajero.');
            return false;
        }
    };

    const deletePassenger = async (iri: string): Promise<boolean> => {
        try {
            await apiClient.delete(iri);
            return true;
        } catch {
            return false;
        }
    };

    return {
        files,
        loadingFiles,
        loadingMore,
        hasNextPage,
        currentPage,
        error,
        searchTerm,
        idiomasDisponibles,
        getActiveFiles,
        fetchFiles,
        setSearchTerm,
        estadoFiltro,
        setEstadoFiltro,
        fetchIdiomas,
        createFile,
        updateFile,
        uploadDocument,
        deleteDocument,
        addPassenger,
        deletePassenger,
        deleteCotizacion,
        deleteFile,
        updateCotizacionPropuesta,
        actualizarPublicado,
        extraerResumenPreview,
        updatePassenger,
        updateDocument,
        cloneCotizacion,
        guardarHistorico,
        crearGrupo,
        actualizarGrupo,
        cargarPadron,
        cargarVuelos,
        eliminarGrupo,
        planificarOperacion,
        revisarCoherencia,
        aplicarPlanOperacion
    };
});