import { defineStore } from 'pinia';
import { ref, computed } from 'vue';
import { apiClient } from '@/services/apiClient';
import { extractApiErrorMessage, esErrorSilencioso } from '@/services/apiError';
import {ApiCotizacionFile, ApiCotizacionFilepasajero, ApiCotizacionFileWrite, I18nContent, PlanCargaZip} from '@/types/fileDetalleModel.ts';
import type { PlanReconciliacion, AplicarPlanPayload, ResultadoAplicacion, InformeCoherencia } from '@/types/operacionModel';
import type { EstadoFile } from '@/types/cotizacionEditorModel';
import type { DocumentoSuelto } from '@/types/fileDetalleModel';

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

    /**
     * Abre la propuesta OPERATIVA de una confirmada: lo que de verdad se va a operar.
     *
     * ⚠️ **Traspasa la operación.** Las filas de La Biblia dejan de colgar de la confirmada y
     * pasan a la operativa, en una sola transacción. La confirmada queda congelada por convención
     * —no por candado— y sigue siendo lo que el cliente ve en dinero.
     *
     * Es idempotente: si ya hay una operativa para esa propuesta, devuelve la que hay.
     *
     * El porqué está en `AbrirOperativaProcessor` y en `docs/Cotizaciones.md` §6.j.3.
     */
    const abrirOperativa = async (iriOrId: string): Promise<boolean> => {
        error.value = null;
        const id = String(iriOrId).includes('/') ? String(iriOrId).split('/').pop() : iriOrId;

        try {
            await apiClient.post(`/platform/sales/client/cotizacion/${id}/operativa`, {});
            return true;
        } catch (err: unknown) {
            error.value = extractApiErrorMessage(err, 'Error al abrir la propuesta operativa.');
            return false;
        }
    };

    /**
     * Arma el cuadro de operación: una fila de La Biblia por componente.
     *
     * ⚠️ **Ya no ocurre solo al confirmar.** Se separó el 02/09/2026: confirmar es un acto
     * comercial y puede pasar semanas antes de que la operación esté lista para armarse.
     * Encadenadas, el cuadro nacía con lo que hubiera ese día.
     *
     * Es idempotente y ésa es su forma de uso: pulsarlo tras añadir servicios **completa lo que
     * falta sin tocar lo demás**, y no revive lo que el operador canceló a mano.
     */
    const generarOperacion = async (iriOrId: string): Promise<boolean> => {
        error.value = null;
        const id = String(iriOrId).includes('/') ? String(iriOrId).split('/').pop() : iriOrId;

        try {
            await apiClient.post(`/platform/sales/client/cotizacion/${id}/operacion`, {});
            return true;
        } catch (err: unknown) {
            error.value = extractApiErrorMessage(err, 'Error al armar la operación.');
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
        payload: { tipo?: string; subeje?: string; clave?: string; nombre?: string | null; detalle?: string | null; emitido?: boolean }
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
    /**
     * Descarga los vuelos del expediente en el mismo JSON que se carga.
     *
     * 🔥 Es la otra mitad del viaje de ida y vuelta, y sustituye a un formulario que no compensa:
     * un PNR con cuatro tramos son veintitantos campos anidados en dos niveles, y lo que de verdad
     * se hace no es crearlo de cero sino corregir un horario cuando la aerolínea reprograma.
     *
     * ⚠️ Va por `apiClient` y NO por un `<a href>`: la petición lleva la sesión, y un enlace suelto
     * la perdería y bajaría un 401 con extensión `.txt`.
     */
    const descargarVuelos = async (fileId: string, localizador: string): Promise<boolean> => {
        error.value = null;

        try {
            const { data } = await apiClient.get(
                `/cotizacion/user/vuelos/exportar/${fileId}`,
                { responseType: 'blob' },
            );

            const url = URL.createObjectURL(data as Blob);
            const enlace = document.createElement('a');
            enlace.href = url;
            enlace.download = `vuelos-${localizador || 'expediente'}.txt`;
            enlace.click();
            URL.revokeObjectURL(url);

            return true;
        } catch (err: unknown) {
            error.value = extractApiErrorMessage(err, 'No se pudieron descargar los vuelos.');

            return false;
        }
    };

    /** Corrige UN vuelo. Los vínculos con los PNR no se tocan aquí: eso es cosa del JSON. */
    const editarVuelo = async (
        vueloId: string,
        datos: Record<string, string | null>,
    ): Promise<boolean> => {
        error.value = null;

        try {
            await apiClient.patch(`/cotizacion/user/vuelos/${vueloId}`, datos);

            return true;
        } catch (err: unknown) {
            error.value = extractApiErrorMessage(err, 'No se pudo guardar el vuelo.');

            return false;
        }
    };

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
     *
     * ⚠️ Publicar una **despublica a sus hermanas de la misma propuesta**, y eso lo hace el
     * servidor (`CotizacionPublicadaEventListener`), no esta función. No hace falta refrescar por
     * cuenta propia lo que se acaba de publicar, pero **sí recargar el expediente**: alguna otra
     * fila de esa propuesta pudo quedar despublicada.
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

    /**
     * Sube el ZIP y devuelve el REPARTO, sin guardar nada.
     *
     * Dos pasos a propósito: con ~1 000 boarding passes, aplicar a ciegas mete el de uno en la
     * ficha de otro y no se descubre hasta el gate.
     */
    const planificarZip = async (fileId: string, zip: File): Promise<PlanCargaZip | null> => {
        error.value = null;
        const cuerpo = new FormData();
        cuerpo.append('zip', zip);

        try {
            const { data } = await apiClient.post(
                `/platform/sales/cotizacion_files/${fileId}/archivos-zip/plan`,
                cuerpo,
                { headers: { 'Content-Type': 'multipart/form-data' } },
            );
            return data as PlanCargaZip;
        } catch (err: unknown) {
            error.value = extractApiErrorMessage(err, 'No se pudo leer el ZIP.');
            return null;
        }
    };

    /** Guarda lo que casa. El servidor recalcula el dueño: aquí sólo se manda qué carga era. */
    const aplicarZip = async (fileId: string, carpeta: string): Promise<number | null> => {
        error.value = null;
        const cuerpo = new FormData();
        cuerpo.append('carpeta', carpeta);

        try {
            const { data } = await apiClient.post(
                `/platform/sales/cotizacion_files/${fileId}/archivos-zip/aplicar`,
                cuerpo,
                { headers: { 'Content-Type': 'multipart/form-data' } },
            );
            return Number(data?.creados ?? 0);
        } catch (err: unknown) {
            error.value = extractApiErrorMessage(err, 'No se pudo aplicar la carga.');
            return null;
        }
    };

    /**
     * El operador miró el reparto y no le gustó: se tira el extracto del servidor.
     *
     * ⚠️ No devuelve nada ni molesta si falla. Es limpieza: que no se pueda borrar la carpeta no
     * es motivo para interrumpir a quien ya decidió que esa carga no valía.
     */
    const descartarZip = async (fileId: string, carpeta: string): Promise<void> => {
        const cuerpo = new FormData();
        cuerpo.append('carpeta', carpeta);

        try {
            await apiClient.post(
                `/platform/sales/cotizacion_files/${fileId}/archivos-zip/descartar`,
                cuerpo,
                { headers: { 'Content-Type': 'multipart/form-data' } },
            );
        } catch {
            // Silencio a propósito: ver el comentario de arriba.
        }
    };

    const updateDocument = async (
        iri: string,
        // `pasajero`/`grupo`/`vuelo` van en `file:write`, así que el PATCH los acepta tal cual.
        // `null` DESASIGNA —devuelve el archivo al expediente entero—, que es lo que hace falta
        // para deshacer un reparto torcido sin borrar el fichero.
        payload: {
            nombre?: I18nContent[] | null;
            tipoArchivo: string;
            sobreescribirTraduccion?: boolean;
            pasajero?: string | null;
            grupo?: string | null;
            vuelo?: string | null;
        }
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

    /**
     * Lanza el control de validación sobre el manifiesto entero.
     *
     * ⚠️ Idempotente en el servidor: lo ya validado se salta y la lectura de cada documento está
     * cacheada, así que pulsar dos veces no cuesta el doble. Por eso el botón no confirma.
     */
    const validarManifiesto = async (fileId: string): Promise<ApiCotizacionFile | null> => {
        error.value = null;
        try {
            // Devuelve el expediente ya actualizado: quien llama lo usa tal cual en vez de volver
            // a pedirlo. Eran dos descargas del mismo payload grande por pulsación.
            const { data } = await apiClient.post(`/platform/sales/client/cotizacion_file/${fileId}/validar-manifiesto`, {});
            return data as ApiCotizacionFile;
        } catch (err: unknown) {
            error.value = extractApiErrorMessage(err, 'No se pudo validar el manifiesto.');
            return null;
        }
    };

    /**
     * Los documentos de la bóveda que no son de nadie, con sus candidatos. **No escribe nada**, así
     * que se puede pedir al abrir el panel las veces que haga falta.
     */
    const documentosSueltos = async (fileId: string): Promise<DocumentoSuelto[]> => {
        error.value = null;
        try {
            const { data } = await apiClient.get(`/cotizacion/user/documentos-sueltos/${fileId}`);
            return (data as { documentos: DocumentoSuelto[] }).documentos ?? [];
        } catch (err: unknown) {
            error.value = extractApiErrorMessage(err, 'No se pudieron cargar los documentos sueltos.');
            return [];
        }
    };

    /**
     * Lee un documento suelto con la IA. **Cuesta ~$0,0016 y ~3,5 s**, así que se pide de uno en
     * uno y a propósito: la tanda del manifiesto nunca toca un archivo sin dueño.
     */
    const leerDocumentoSuelto = async (archivoId: string): Promise<boolean> => {
        error.value = null;
        try {
            await apiClient.post(`/cotizacion/user/documentos-sueltos/${archivoId}/leer`, {});
            return true;
        } catch (err: unknown) {
            error.value = extractApiErrorMessage(err, 'No se pudo leer el documento.');
            return false;
        }
    };

    /**
     * Resuelve UNO: lo vincula a alguien o le crea la ficha.
     *
     * ⚠️ De uno en uno a propósito: un «resolver todos» aplicaría también las corazonadas por
     * nombre, que son las que se equivocan en las familias.
     */
    const resolverDocumento = async (
        archivoId: string,
        accion: 'vincular' | 'crear',
        pasajeroId?: string,
    ): Promise<boolean> => {
        error.value = null;
        try {
            await apiClient.post(`/cotizacion/user/documentos-sueltos/${archivoId}/resolver`, { accion, pasajeroId });
            return true;
        } catch (err: unknown) {
            error.value = extractApiErrorMessage(err, 'No se pudo resolver el documento.');
            return false;
        }
    };

    /**
     * Gira el escaneo. **Reescribe el fichero**, no guarda un ángulo para aplicarlo al mostrar.
     *
     * ⚠️ Tira la lectura del documento: uno torcido casi siempre se leyó mal —es la razón de
     * girarlo—, así que la siguiente tanda lo relee ya derecho.
     */
    const girarDocumento = async (
        archivoId: string,
        grados: number,
    ): Promise<{ actualizado?: string; bordeSuperior?: string; rotacionPendiente?: number } | null> => {
        error.value = null;
        try {
            const { data } = await apiClient.post(`/cotizacion/user/documentos-sueltos/${archivoId}/girar`, { grados });
            // Devuelve lo justo para parchear la pantalla en sitio: recargar el expediente entero
            // por un fichero costaba 16 de los 20 segundos que tardaba un giro.
            return data as { actualizado?: string; bordeSuperior?: string; rotacionPendiente?: number };
        } catch (err: unknown) {
            error.value = extractApiErrorMessage(err, 'No se pudo girar el documento.');
            return null;
        }
    };

    /**
     * Reprocesa a una sola persona. **No relee el documento** —la lectura está cacheada—, así que
     * cuesta cero: coteja lo que ya se leyó contra lo que hay guardado AHORA.
     */
    const revalidarPasajero = async (pasajeroId: string): Promise<boolean> => {
        error.value = null;
        try {
            await apiClient.post(`/cotizacion/user/manifiesto/pasajero/${pasajeroId}/revalidar`, {});
            return true;
        } catch (err: unknown) {
            error.value = extractApiErrorMessage(err, 'No se pudo reprocesar a esa persona.');
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

    /**
     * Guarda un pasajero y **devuelve cómo quedó**.
     *
     * ⚠️ Devolvía `boolean` y tiraba la respuesta, así que quien llamaba no tenía más remedio que
     * recargar el expediente entero para ver el cambio — y ese GET pesa hasta 4,3 MB. El PATCH ya
     * trae el pasajero serializado con `file:item:read`, que es **la misma forma** con la que
     * viaja dentro del expediente (comprobado: no hay ningún campo suyo que esté en `file:read` y
     * no en `file:item:read`). Con eso, quien edita puede sustituirlo en su lista y no viajar.
     *
     * `null` si falló; el motivo queda en `error`.
     */
    const updatePassenger = async (iri: string, payload: PasajeroPayload): Promise<ApiCotizacionFilepasajero | null> => {
        error.value = null;
        try {
            const { data } = await apiClient.patch<ApiCotizacionFilepasajero>(iri, payload);
            return data;
        } catch (err: unknown) {
            error.value = extractApiErrorMessage(err, 'Error al actualizar el pasajero.');
            return null;
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
        planificarZip,
        aplicarZip,
        descartarZip,
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
        validarManifiesto,
        documentosSueltos,
        leerDocumentoSuelto,
        resolverDocumento,
        girarDocumento,
        revalidarPasajero,
        cloneCotizacion,
        guardarHistorico,
        abrirOperativa,
        generarOperacion,
        crearGrupo,
        actualizarGrupo,
        cargarPadron,
        cargarVuelos,
        descargarVuelos,
        editarVuelo,
        eliminarGrupo,
        planificarOperacion,
        revisarCoherencia,
        aplicarPlanOperacion
    };
});