// src/store/operacionStore.ts
import { defineStore } from 'pinia';
import { ref } from 'vue';
import { apiClient } from '@/services/apiClient';
import { mensajeDeErrorApi } from '@/utils/errorApi';
import type {
    OperacionOrdenServicio,
    OperacionServicio,
    OperacionMensaje,
    OperacionOrdenServicioWrite,
    OperacionServicioWrite,
    OperacionMensajeWrite,
    FiltrosBiblia,
    LugarOpcion,
    PuntosDerivadosPorServicio
} from '@/types/operacionModel';
import { construirParamsBiblia } from '@/types/operacionModel';

/**
 * Datos de contacto vivos de un `Organizacion`, resueltos contra el catálogo.
 *
 * No sale de `api.d.ts` a propósito: es el subconjunto que La Biblia consume del maestro,
 * no la forma del recurso. Declararlo como el `Organizacion` entero obligaría a arrastrar
 * imágenes, servicios y títulos i18n que aquí no se pintan.
 */
export interface ContactoProveedor {
    telefono: string | null;
    direccion: string | null;
    email: string | null;
}

/** Ficha de expediente para el modal: namelist + documentos. Forma laxa del file:item:read. */
export interface ExpedienteDetalle {
    nombreGrupo?: string | null;
    pasajeroPrincipal?: string | null;
    filepasajeros?: Array<Record<string, unknown>>;
    filedocumentos?: Array<Record<string, unknown>>;
}

/** Un pago a cuenta hecho al proveedor. */
export interface PagoProveedor {
    id?: string;
    monto: string;
    moneda?: { id?: string } | null;
    fecha: string;
    /** Por qué medio se pagó. Obligatorio: ver `OperacionPago::$medioPago`. */
    medioPago: string;
    /** La etiqueta legible, que la redacta PHP. Aquí NO se duplica el diccionario. */
    medioPagoLabel?: string | null;
    notas: string | null;
    usuarioNombre: string | null;
}

/**
 * Lo que se le mandaría al proveedor.
 *
 * 🪞 Espejo de `OperacionOrdenEnvio::previsualizar()`. Se declara a mano porque el endpoint
 * devuelve una `JsonResponse` montada y no entra en `api.d.ts`.
 */
export interface DocumentoDeOrden {
    asunto: string;
    cuerpo: string;
    lineas: number;
    destinatario: string;
    canales: Array<{ id: string; nombre: string; disponible: boolean; motivo: string | null }>;
    /** El enlace público que ve el proveedor. `null` mientras la orden no se haya emitido. */
    enlace: string | null;
    /**
     * ⚠️ Cerrada, Meta sólo admite plantillas aprobadas — y una orden con varias líneas no cabe
     * en una. Se avisa ANTES de elegir WhatsApp, no después de leer todo el documento.
     */
    ventanaWhatsappAbierta: boolean;
}

/** Un medio de pago del selector, tal como lo publica `OperacionEnumAjaxController`. */
export interface MedioPagoOpcion {
    id: string;
    label: string;
    icono: string;
}

/** Una línea de la bitácora de estados de un servicio. */
export interface BitacoraEstado {
    campo: string;
    valorAnterior: string | null;
    valorNuevo: string;
    usuarioNombre: string | null;
    createdAt: string;
}

/** Organizacion reducido para el selector de destinatario de la Orden de Servicio. */
export interface ProveedorOpcion {
    id: string;
    nombreComercial: string;
    email?: string | null;
}

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
    // Nombre interno del componente maestro (id → nombre), del MISMO batch que los lugares.
    // El nombre del componente no vive en el snapshot: viene del maestro, como sus lugares.
    const nombreComponentePorMaestro = ref<Record<string, string>>({});

    // Contacto vivo de los proveedores del cuadro, por uuid de maestro. Lo llena
    // `resolverContactoDeProveedores()` en cada carga; ver su docblock.
    const contactoPorProveedor = ref<Record<string, ContactoProveedor>>({});
    // Nombre interno (operativo) del segmento maestro, resuelto en vivo por su id. Para
    // servicios mono-segmento sin plantilla: la fila muestra el nombre del segmento en vez
    // del genérico del servicio. Se llena en cada carga, como los contactos de proveedor.
    const nombreSegmentoPorMaestro = ref<Record<string, string>>({});

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
     * El orden lo impone el backend (fechaServicio ASC, horaRecojo ASC declarado en
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
            // Los dos van DENTRO del try y antes del `finally`, así que `isLoading` sigue
            // en alto mientras se resuelven: la tabla no llega a pintarse sin teléfono
            // para rellenarlo medio segundo después. Vale para cada filtro y cada recarga,
            // porque todo el cuadro entra por aquí.
            await Promise.all([
                resolverLugaresDeServicios(),
                resolverContactoDeProveedores(),
                resolverNombresDeSegmento(),
                resolverPuntosDeServicios(),
            ]);
        } catch (error) {
            console.error('Error al cargar la Biblia de operaciones:', error);
            throw error;
        } finally {
            isLoading.value = false;
        }
    };

    /**
     * Las monedas del maestro, para elegir en qué se cerró con el proveedor.
     *
     * Sólo los códigos (`PEN`, `USD`…): es lo único que se pinta y lo único que hace falta
     * para componer el IRI al guardar.
     */
    const monedas = ref<string[]>([]);

    const fetchMonedas = async (): Promise<void> => {
        if (monedas.value.length) return;

        try {
            const res = await apiClient.get('/platform/maestro/monedas?pagination=false');
            const miembros = res.data['hydra:member'] || res.data['member'] || [];
            monedas.value = miembros
                .map((m: Record<string, unknown>) => String(m.id ?? ''))
                .filter(Boolean);
        } catch (error) {
            // Sin lista no se puede cambiar la moneda, pero el resto del cuadro funciona.
            console.error('No se pudieron cargar las monedas:', error);
            monedas.value = [];
        }
    };

    /**
     * El catálogo de proveedores, para elegir a quién va dirigida la Orden de Servicio.
     *
     * Antes el destinatario era un `<input>` de texto libre, y eso rompía justo lo que la OS
     * tiene que garantizar: a quién se le manda. Escrito a mano, «Gabrie Aime» y «Gabriel
     * Aimé» son dos proveedores distintos para cualquier agrupación o filtro, y la orden no
     * tenía a quién enviarse porque no había id detrás — sólo una cadena.
     *
     * Se piden todos de una vez: son ~100 y el selector filtra en local.
     */
    const proveedores = ref<ProveedorOpcion[]>([]);

    const fetchProveedores = async (): Promise<void> => {
        if (proveedores.value.length) return;   // idempotente: el modal puede abrirse muchas veces

        try {
            const res = await apiClient.get('/platform/travel/organizaciones?pagination=false');
            const miembros = res.data['hydra:member'] || res.data['member'] || [];

            proveedores.value = miembros
                .map((p: Record<string, unknown>) => ({
                    id: String(p.id ?? String(p['@id'] ?? '').split('/').pop() ?? ''),
                    nombreComercial: String(p.nombreComercial ?? ''),
                    email: (p.email as string | null) ?? null,
                }))
                .filter((p: ProveedorOpcion) => p.id && p.nombreComercial)
                .sort((a: ProveedorOpcion, b: ProveedorOpcion) =>
                    a.nombreComercial.localeCompare(b.nombreComercial, 'es'));
        } catch (error) {
            // Sin catálogo el modal sigue abriéndose: mejor una lista vacía y un aviso que
            // impedir crear la orden.
            console.error('No se pudieron cargar los proveedores:', error);
            proveedores.value = [];
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
            const mapaNombres: Record<string, string> = {};

            miembros.forEach((c: Record<string, unknown>) => {
                const id = String(c.id ?? String(c['@id'] ?? '').split('/').pop() ?? '');
                const iris = Array.isArray(c.lugares) ? (c.lugares as string[]) : [];
                if (id) {
                    mapa[id] = iris.map((iri) => nombrePorIri.get(iri) ?? '').filter(Boolean);
                    if (c.nombre) mapaNombres[id] = String(c.nombre);
                }
            });

            lugaresPorComponente.value = mapa;
            nombreComponentePorMaestro.value = mapaNombres;
        } catch (error) {
            // Los badges son decoración: si el catálogo falla, el cuadro sigue siendo usable.
            console.error('No se pudieron resolver las etiquetas de lugar:', error);
            lugaresPorComponente.value = {};
            nombreComponentePorMaestro.value = {};
        }
    };

    /** Etiquetas de una fila del cuadro, ya resueltas a nombre. */
    const lugaresDeServicio = (servicio: OperacionServicio): string[] => {
        const maestro = (servicio.cotizacionComponente as { componenteMaestroId?: string } | undefined)
            ?.componenteMaestroId;

        return maestro ? (lugaresPorComponente.value[maestro] ?? []) : [];
    };

    /** Nombre interno del componente de una fila (del maestro), o `null` si no se resolvió. */
    const nombreComponenteDeServicio = (servicio: OperacionServicio): string | null => {
        const maestro = (servicio.cotizacionComponente as { componenteMaestroId?: string } | undefined)
            ?.componenteMaestroId;

        return maestro ? (nombreComponentePorMaestro.value[maestro] ?? null) : null;
    };

    /**
     * Teléfono y dirección de los proveedores del cuadro, EN UNA SOLA petición.
     *
     * ── Por qué no está en el snapshot ──────────────────────────────────────────
     * El snapshot congela lo que hay que congelar —qué se vendió, a cuánto, a nombre de
     * quién— porque cambiar el catálogo no puede reescribir una propuesta ya enviada. El
     * teléfono es lo contrario: cuando el conductor no aparece, el número que sirve es el
     * de HOY, no el del día que se cotizó. Congelarlo sería un dato caducado con apariencia
     * de bueno, que en el cuadro de tráfico es peor que no tener nada.
     *
     * Por eso `BibliaSnapshotService` los deja nulos y se resuelven aquí. Se piden en lote
     * con `?id[]=`, igual que las etiquetas de lugar: son 12 proveedores distintos en 42
     * filas, así que fila a fila serían decenas de peticiones para repetir doce respuestas.
     *
     * Prestador y comprador van en la MISMA petición: los dos son `Organizacion` desde que se
     * unificaron los papeles, así que separarlos serían dos llamadas al mismo endpoint.
     */
    const resolverContactoDeProveedores = async (): Promise<void> => {
        const ids = new Set<string>();

        // Los EFECTIVOS, no los cotizados: si operaciones cambió el prestador o el comprador,
        // el contacto que hace falta es el de la empresa nueva. Se piden también los cotizados
        // porque la fila sigue enseñándolos como «Cotizado: …».
        servicios.value.forEach((s) => {
            if (s.prestadorEfectivoMaestroId) ids.add(s.prestadorEfectivoMaestroId);
            if (s.compradorEfectivoMaestroId) ids.add(s.compradorEfectivoMaestroId);
            if (s.prestadorMaestroId) ids.add(s.prestadorMaestroId);
            if (s.compradorMaestroId) ids.add(s.compradorMaestroId);
        });

        if (!ids.size) {
            contactoPorProveedor.value = {};
            return;
        }

        try {
            const query = Array.from(ids).map((id) => `id[]=${id}`).join('&');
            const res = await apiClient.get(`/platform/travel/organizaciones?${query}&pagination=false`);
            const miembros = res.data['hydra:member'] || res.data['member'] || [];

            const mapa: Record<string, ContactoProveedor> = {};

            miembros.forEach((p: Record<string, unknown>) => {
                const id = String(p.id ?? String(p['@id'] ?? '').split('/').pop() ?? '');
                if (!id) return;

                mapa[id] = {
                    telefono: (p.telefono as string | null) ?? null,
                    direccion: (p.direccion as string | null) ?? null,
                    email: (p.email as string | null) ?? null,
                };
            });

            contactoPorProveedor.value = mapa;
        } catch (error) {
            // Sin contacto el cuadro sigue siendo usable: la fila conserva nombre, hora y
            // pax, que es lo que la ubica. Se cae al dato guardado, si lo hubiera.
            console.error('No se pudo resolver el contacto de los proveedores:', error);
            contactoPorProveedor.value = {};
        }
    };

    /**
     * Resuelve EN VIVO el nombre interno del segmento para las filas mono-segmento sin
     * plantilla (las que el backend marca con `segmentoUnicoMaestroId`). Mismo patrón que los
     * contactos de proveedor: batch por id contra el maestro, sin snapshot. El nombre interno
     * homogeneizado (= nombre real del segmento) reemplaza al genérico del servicio en la fila.
     */
    /**
     * Lo que dice el CATÁLOGO sobre dónde recoge y deja cada servicio.
     *
     * Vive aparte de la fila porque es derivado, no del documento: corregir un segmento del
     * maestro lo arregla en todos los viajes a la vez, sin re-guardar nada. El override que
     * escribe el operador sí viaja en la fila (`puntoRecojo` / `puntoEntrega`).
     *
     * Se usa como MARCADOR DE POSICIÓN del campo editable: enseña qué saldría si lo vaciara.
     */
    const puntosDerivados = ref<PuntosDerivadosPorServicio>({});

    const resolverPuntosDeServicios = async (): Promise<void> => {
        const ids = Array.from(new Set(
            servicios.value
                .map((s: OperacionServicio) => s.id)
                .filter((id): id is string => typeof id === 'string' && id !== '')
        ));

        if (ids.length === 0) { puntosDerivados.value = {}; return; }

        try {
            const query = ids.map((id) => `id[]=${id}`).join('&');
            const res = await apiClient.get(`/operacion/user/puntos?${query}`);
            puntosDerivados.value = (res.data?.servicios ?? {}) as PuntosDerivadosPorServicio;
        } catch (error) {
            // Es información de apoyo: sin ella el campo se queda sin marcador de posición y el
            // cuadro sigue entero. Lo que no puede pasar es que tumbe la tabla.
            console.error('No se pudieron resolver los puntos de recojo:', error);
            puntosDerivados.value = {};
        }
    };

    const resolverNombresDeSegmento = async (): Promise<void> => {
        const ids = new Set<string>();
        servicios.value.forEach((s) => {
            if (s.segmentoUnicoMaestroId) ids.add(s.segmentoUnicoMaestroId);
        });

        if (!ids.size) {
            nombreSegmentoPorMaestro.value = {};
            return;
        }

        try {
            const query = Array.from(ids).map((id) => `id[]=${id}`).join('&');
            const res = await apiClient.get(`/platform/travel/segmentos?${query}&pagination=false`);
            const miembros = res.data['hydra:member'] || res.data['member'] || [];

            const mapa: Record<string, string> = {};
            miembros.forEach((sg: Record<string, unknown>) => {
                const id = String(sg.id ?? String(sg['@id'] ?? '').split('/').pop() ?? '');
                if (id && sg.nombreInterno) mapa[id] = String(sg.nombreInterno);
            });

            nombreSegmentoPorMaestro.value = mapa;
        } catch (error) {
            // Sin resolver, la fila se cae al nombre del servicio (contextoServicio): usable.
            console.error('No se pudo resolver el nombre de los segmentos:', error);
            nombreSegmentoPorMaestro.value = {};
        }
    };

    /**
     * El contacto de una organización, **siempre vivo desde el catálogo**.
     *
     * Ya no hay copia congelada por fila. La hubo para el prestador —`prestadorTelefono` y
     * `prestadorDireccion`— y acabó en 0 de 42: el snapshot las ponía a `null` y la pantalla
     * no tenía dónde escribirlas, así que sólo eran una segunda fuente de verdad esperando a
     * discrepar. Corregir el teléfono en la ficha ahora lo corrige en todas las filas.
     *
     * ⚠️ Se resuelve por el id **EFECTIVO**: si operaciones cambió el prestador, el teléfono
     * que hace falta es el de la empresa nueva, no el de la que se cotizó.
     */
    const contactoDeOrganizacion = (maestroId?: string | null): ContactoProveedor => {
        const vivo = maestroId ? contactoPorProveedor.value[maestroId] : undefined;

        return {
            telefono: vivo?.telefono || null,
            direccion: vivo?.direccion || null,
            email: vivo?.email || null,
        };
    };

    /** Quién opera y dónde se recoge. */
    const contactoDePrestador = (servicio: OperacionServicio): ContactoProveedor =>
        contactoDeOrganizacion(servicio.prestadorEfectivoMaestroId);

    /**
     * A quién se le manda el encargo — **es a éste a quien se le envía la Orden de Servicio**.
     *
     * Existe porque la asimetría era el fallo: el prestador tenía copia congelada y el
     * comprador no, siendo el comprador el destinatario real. Ahora los dos salen del mismo
     * sitio y por el mismo camino.
     */
    const contactoDeComprador = (servicio: OperacionServicio): ContactoProveedor =>
        contactoDeOrganizacion(servicio.compradorEfectivoMaestroId);

    /**
     * Nombre interno del segmento para una fila mono-segmento sin plantilla, o `null` si no
     * aplica (tiene plantilla, o 0/varios segmentos → manda el nombre del servicio). La vista
     * lo usa como título de la fila en vez del genérico.
     */
    const nombreSegmentoDeServicio = (servicio: OperacionServicio): string | null => {
        const id = servicio.segmentoUnicoMaestroId;
        return id ? (nombreSegmentoPorMaestro.value[id] ?? null) : null;
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
        // 🔥 SIN `isLoading` global. Editar un campo de una fila es una operación puntual, y
        // encender el spinner de pantalla completa por un número hacía parpadear todo el
        // cuadro —«¿se recargó?»— por un cambio que sólo afecta a esa fila. El editor da su
        // propio feedback (✓), y la respuesta reemplaza la fila en su sitio sin refetch.
        const response = await apiClient.patch(
            `/platform/ops/operacion_servicios/${id}`,
            payload
        );

        const index = servicios.value.findIndex(s => s.id === id);
        if (index !== -1) {
            servicios.value[index] = response.data;
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
    /**
     * Recarga UNA orden y la reemplaza en la lista, sin tocar el resto.
     *
     * Al guardar el costo de un servicio hay que refrescar su orden —`totalesPorMoneda` lo
     * recalcula el servidor— pero recargar la colección entera hacía parpadear toda la
     * pantalla por un número. Esto sustituye sólo la afectada.
     */
    const refrescarOrden = async (ordenId: string): Promise<void> => {
        try {
            const { data } = await apiClient.get(`/platform/ops/operacion_orden_servicios/${ordenId}`);
            const i = ordenesServicio.value.findIndex(o => o.id === ordenId);
            if (i !== -1) ordenesServicio.value[i] = data;
        } catch (error) {
            console.error('No se pudo refrescar la orden:', error);
        }
    };

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
     * Emite una Orden de Servicio entera **en una sola llamada**.
     *
     * Antes esto se orquestaba aquí: un `POST` para la cabecera y luego un `PATCH` por cada
     * servicio para atarlo. Si la pestaña se caía en medio quedaban filas atadas a una orden
     * que no llegó a existir, y las reglas de coherencia vivían la mitad en el navegador —dos
     * pestañas abiertas armaban lo que la vista impedía—.
     *
     * Ahora el servidor valida antes de tocar nada, crea, enlaza y congela en una transacción,
     * y devuelve la orden completa. Ver `EmitirOrdenProcessor`.
     */
    const emitirOrdenServicio = async (datos: {
        servicioIds: string[];
        numeroOs: string;
        compradorMaestroId?: string | null;
        compradorNombre?: string | null;
        reemplazaAId?: string | null;
        soloBorrador?: boolean;
    }): Promise<OperacionOrdenServicio> => {
        isLoading.value = true;
        try {
            const { data } = await apiClient.post('/platform/ops/orden-servicios/emitir', datos);
            ordenesServicio.value.unshift(data);

            return data;
        } finally {
            isLoading.value = false;
        }
    };

    /**
     * Aplica lo que NO obliga a reemitir: hoy, la hora que el proveedor acaba de confirmar.
     *
     * Actualiza el documento y la orden **sigue emitida**. El aviso que sale de aquí es una
     * confirmación al cliente y un acuse al proveedor, no un «cambio de horario»: mandar una
     * modificación donde hubo una confirmación siembra dudas sobre un servicio que va bien.
     */
    const aplicarCambiosMenores = async (id: string): Promise<OperacionOrdenServicio> => {
        isLoading.value = true;
        try {
            const { data } = await apiClient.post(`/platform/ops/orden-servicios/${id}/aplicar-menores`, {});
            const i = ordenesServicio.value.findIndex(o => o.id === id);
            if (i >= 0) ordenesServicio.value[i] = data;

            return data;
        } finally {
            isLoading.value = false;
        }
    };

    /**
     * Mueve una orden de estado. Emitir congela su contenido; anular suelta sus filas.
     *
     * Es una ACCIÓN y no un `PATCH` de `estadoOs`: ese campo ya no es escribible. Con dos
     * puertas a la misma transición, las reglas se escapan por la que no mira nadie — y es
     * aquí donde cuelga lo que pase al emitir (avisar al proveedor, generar el PDF).
     */
    const cambiarEstadoOrden = async (
        id: string,
        estado: string,
        motivo?: string | null,
    ): Promise<OperacionOrdenServicio> => {
        isLoading.value = true;
        try {
            const { data } = await apiClient.post(`/platform/ops/orden-servicios/${id}/estado`, { estado, motivo: motivo ?? null });
            const i = ordenesServicio.value.findIndex(o => o.id === id);
            if (i >= 0) ordenesServicio.value[i] = data;

            return data;
        } finally {
            isLoading.value = false;
        }
    };

    /**
     * Edita la cabecera de una orden ya creada.
     *
     * PATCH y no PUT: se mandan sólo los campos tocados. Con PUT, un campo ausente se
     * interpreta como «ponlo a null», y aquí se editan de uno en uno.
     */
    const actualizarOrdenServicio = async (
        id: string,
        payload: Partial<OperacionOrdenServicioWrite>
    ): Promise<OperacionOrdenServicio> => {
        const { data } = await apiClient.patch(
            `/platform/ops/operacion_orden_servicios/${id}`,
            payload,
            { headers: { 'Content-Type': 'application/merge-patch+json' } }
        );

        // Se reemplaza en la lista para que la pantalla refleje lo guardado sin recargar
        // todo: el servidor devuelve la orden entera, incluidos los totales recalculados.
        const i = ordenesServicio.value.findIndex(o => o.id === id);
        if (i !== -1) ordenesServicio.value[i] = data;

        return data;
    };

    /**
     * El detalle del expediente: pasajeros (namelist) y documentos cargados.
     *
     * Se pide al abrir el modal, no antes: es el `file:item:read` completo, que trae toda la
     * ficha. Cargarlo para las 200 filas del cuadro sería traer cientos de expedientes que
     * nadie va a abrir.
     */
    const fetchExpedienteDetalle = async (fileId: string): Promise<ExpedienteDetalle | null> => {
        try {
            const { data } = await apiClient.get(`/platform/sales/cotizacion_files/${fileId}`);
            return data;
        } catch (error) {
            console.error('No se pudo cargar el expediente:', error);
            return null;
        }
    };

    /**
     * Sube un documento al expediente desde La Biblia. Mismo endpoint multipart que usa
     * FileDetalle (`/cotizacion_filedocumentos`): estos documentos —vouchers, confirmaciones de
     * reserva— se generan justo al operar, así que tiene sentido subirlos sin salir del cuadro.
     * `tipodocumento` sale del nombre del archivo; se puede renombrar luego en el expediente.
     */
    const subirDocumentoExpediente = async (fileId: string, archivo: File): Promise<boolean> => {
        try {
            const fd = new FormData();
            fd.append('documento', archivo);
            fd.append('tipodocumento', archivo.name.replace(/\.[^.]+$/, '') || 'Documento');
            fd.append('file', `/platform/sales/cotizacion_files/${fileId}`);
            await apiClient.post('/platform/sales/cotizacion_filedocumentos', fd, {
                headers: { 'Content-Type': 'multipart/form-data' },
            });
            return true;
        } catch (error) {
            console.error('No se pudo subir el documento:', error);
            return false;
        }
    };

    /** Los pagos a cuenta de una orden. Bajo demanda: se ven al abrir el modal de pagos. */
    const fetchPagos = async (ordenId: string): Promise<PagoProveedor[]> => {
        try {
            const { data } = await apiClient.get('/platform/ops/operacion_pagos', {
                params: { ordenServicio: `/platform/ops/operacion_orden_servicios/${ordenId}` },
            });
            return data['hydra:member'] || data['member'] || [];
        } catch (error) {
            console.error('No se pudieron cargar los pagos:', error);
            return [];
        }
    };

    /**
     * Registra un pago a cuenta. Devuelve `null` si se guardó, o el MOTIVO si no.
     *
     * ⚠️ Devolvía un booleano y el panel pintaba «No se pudo registrar el pago». El servidor
     * sabe qué falló —«esta orden se maneja en USD: no se le puede registrar un pago en PEN»,
     * «elige por qué medio se pagó»— y taparlo obligaba a adivinar delante de un formulario
     * que no dice qué campo está mal.
     */
    const crearPago = async (payload: {
        ordenServicio: string;
        monto: string;
        moneda: string;
        fecha: string;
        medioPago: string;
        notas: string | null;
    }): Promise<string | null> => {
        try {
            await apiClient.post('/platform/ops/operacion_pagos', payload);

            return null;
        } catch (error) {
            return mensajeDeErrorApi(error, 'No se pudo registrar el pago.');
        }
    };

    /**
     * Los medios de pago, del backend.
     *
     * Se cachea en memoria: es un diccionario que sólo cambia con un despliegue, y el endpoint
     * ya manda `s-maxage`. Duplicarlo en TypeScript se desincroniza en silencio — el selector
     * se queda corto y nadie echa de menos la opción que no sabía que existía.
     */
    const mediosPago = ref<MedioPagoOpcion[]>([]);

    const cargarMediosPago = async (): Promise<void> => {
        if (mediosPago.value.length) return;

        try {
            const { data } = await apiClient.get('/tipo/user/enum/operacion/medios-pago');
            mediosPago.value = data as MedioPagoOpcion[];
        } catch (error) {
            console.error('No se pudieron cargar los medios de pago:', error);
        }
    };

    /**
     * Borra una orden en BORRADOR y devuelve sus servicios al pool.
     *
     * Lo segundo no lo hace este código: `operacion_servicio.orden_servicio_id` es
     * `ON DELETE SET NULL`, así que los servicios se sueltan solos y quedan libres para entrar
     * en otra orden. Los pagos y la bitácora sí caen en cascada — son de la orden.
     *
     * ⚠️ Sólo borradores. Una orden ya emitida se ANULA, que también libera los servicios pero
     * deja constancia de que existió; lo impide `OperacionOrdenBorradoListener` y el motivo se
     * devuelve tal cual.
     */
    /** Qué se le mandaría al proveedor y por qué canales, sin mandar nada. */
    const documentoDeOrden = async (id: string): Promise<DocumentoDeOrden | { error: string }> => {
        try {
            const { data } = await apiClient.get(`/platform/ops/orden-servicios/${id}/documento`);

            return data as DocumentoDeOrden;
        } catch (error) {
            return { error: mensajeDeErrorApi(error, 'No se pudo preparar el documento.') };
        }
    };

    /**
     * Lo manda. Devuelve `null` si salió, o el MOTIVO si no.
     *
     * ⚠️ Es irreversible: un correo mandado no se retira. Por eso el panel previsualiza antes.
     */
    const enviarOrdenAlProveedor = async (id: string, canal: string): Promise<string | null> => {
        try {
            await apiClient.post(`/platform/ops/orden-servicios/${id}/enviar`, { canal });

            return null;
        } catch (error) {
            return mensajeDeErrorApi(error, 'No se pudo enviar la orden.');
        }
    };

    const eliminarOrdenServicio = async (id: string): Promise<string | null> => {
        try {
            await apiClient.delete(`/platform/ops/operacion_orden_servicios/${id}`);

            // ⚠️ **Fuera de la colección aquí**, no en la vista.
            //
            // La tarjeta se quedaba en pantalla después de borrar: quien la llamaba refrescaba
            // La Biblia (`fetchServicios`) creyendo que eso bastaba, y son DOS colecciones
            // distintas — los servicios y las órdenes. Había que recargar a mano para verlo.
            //
            // Va en el store por lo mismo que `aplicarCambiosMenores` y `cambiarEstadoOrden` ya
            // sincronizan ahí: cada vista que llame a esto tendría que acordarse, y la segunda
            // no se acuerda. Y se hace en memoria en vez de refetchear porque la respuesta ya se
            // sabe: la orden que se acaba de borrar no está.
            ordenesServicio.value = ordenesServicio.value.filter(o => o.id !== id);

            return null;
        } catch (error) {
            return mensajeDeErrorApi(error, 'No se pudo eliminar la orden.');
        }
    };

    const eliminarPago = async (pagoId: string): Promise<boolean> => {
        try {
            await apiClient.delete(`/platform/ops/operacion_pagos/${pagoId}`);
            return true;
        } catch (error) {
            console.error('No se pudo eliminar el pago:', error);
            return false;
        }
    };

    /**
     * El historial de estados de un servicio, bajo demanda.
     *
     * No viaja en el listado: es una serie temporal que sólo se mira al pulsar «ver historial»
     * de una fila concreta. Traerla para las 200 filas del cuadro sería cargar toneladas de
     * registros que nadie va a ver.
     */
    const fetchBitacoraEstado = async (servicioId: string): Promise<BitacoraEstado[]> => {
        try {
            const { data } = await apiClient.get('/platform/ops/operacion_estado_bitacoras', {
                params: { operacionServicio: `/platform/ops/operacion_servicios/${servicioId}` },
            });
            return data['hydra:member'] || data['member'] || [];
        } catch (error) {
            console.error('No se pudo cargar la bitácora de estados:', error);
            return [];
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
        puntosDerivados, resolverPuntosDeServicios,
        isLoading,
        servicios,
        totalServicios,
        ordenesServicio,
        mensajesActivos,
        lugares,
        lugaresPorComponente,
        proveedores,
        fetchProveedores,
        fetchBitacoraEstado,
        fetchExpedienteDetalle,
        subirDocumentoExpediente,
        fetchPagos,
        crearPago,
        eliminarPago,
        mediosPago,
        cargarMediosPago,
        refrescarOrden,
        monedas,
        fetchMonedas,
        contactoPorProveedor,
        contactoDePrestador,
        contactoDeComprador,
        contactoDeOrganizacion,
        nombreSegmentoDeServicio,
        fetchServicios,
        fetchLugares,
        lugaresDeServicio,
        nombreComponenteDeServicio,
        buscarExpedientes,
        fetchCotizacionesDeExpediente,
        actualizarServicio,
        fetchOrdenesServicio,
        emitirOrdenServicio,
        cambiarEstadoOrden,
        eliminarOrdenServicio,
        documentoDeOrden,
        enviarOrdenAlProveedor,
        aplicarCambiosMenores,
        actualizarOrdenServicio,
        fetchMensajesPorOrden,
        registrarMensaje
    };
});