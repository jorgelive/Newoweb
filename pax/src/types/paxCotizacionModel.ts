// src/types/paxCotizacionModel.ts
// ============================================================================
// Tipos de la VISTA PÚBLICA DEL CLIENTE (grupo de serialización pax_cotizacion:read)
//
// Endpoint: GET /client/cotizacion/cotizacion_file/{localizador}  (PUBLIC_ACCESS)
// Provider: CotizacionFilePublicProvider
//
// A diferencia del editor (que deriva sus tipos de components['schemas'][...]),
// aquí casi todo el contenido son SNAPSHOTS JSON (columnas type: 'json') que el
// OpenAPI export tipa como `any`/`object`. Por eso se modelan a mano, espejando
// exactamente los campos que llevan #[Groups(['pax_cotizacion:read'])] en las
// entities. Si prefieres anclarlos al schema autogenerado, la raíz equivale a:
//   components['schemas']['CotizacionFile.jsonld-pax_cotizacion.read']
// ============================================================================

// --- Primitivos compartidos --------------------------------------------------

/** Elemento de contenido multiidioma: [{ content, language }, ...] */
export interface PaxI18nContent {
    content: string;
    language: string;
}

export type I18n = PaxI18nContent[];

/** Imagen de snapshot (segmentos, proveedor, proveedorServicio) */
export interface PaxImagenSnapshot {
    imageUrl: string;
    orden: number;
    isPortada: boolean;
    imageName?: string;
    imageSize?: number;
}

/** Nota/recomendación congelada dentro de notasSnapshot de un segmento */
export interface PaxNotaSnapshot {
    id: string;
    tipo: string; // 'recomendacion' | ...
    titulo: I18n;
    contenido: I18n;
    nombreInterno?: string;
}

/** Bloque de detalle operativo visible al cliente (getDetallesParaCliente) */
export interface PaxDetalleCliente {
    id: string;
    tipo: 'cliente';
    detalle: I18n;
}

// --- Segmento (día a día del itinerario) -------------------------------------

export interface PaxCotSegmento {
    '@id'?: string;
    '@type'?: string;
    id: string;
    dia: number;
    orden: number;
    fechaAbsoluta: string; // ISO date
    segmentoMaestroId?: string | null;
    nombreSnapshot: I18n;
    contenidoSnapshot: I18n; // HTML por idioma
    imagenesSnapshot: PaxImagenSnapshot[];
    notasSnapshot: PaxNotaSnapshot[];
}

// --- Tarifa (solo campos expuestos al cliente) --------------------------------

export interface PaxCottarifa {
    '@id'?: string;
    id: string;
    cantidad: number;
    tituloSnapshot: I18n;
    nombreInternoSnapshot?: string | null;
    modalidadSnapshot?: string | null; // 'privado' | 'compartido' | null
    categoriaSnapshot?: string | null; // 'superior' | ...
    procedenciaSnapshot?: string | null;
    edadMinimaSnapshot?: number | null;
    edadMaximaSnapshot?: number | null;
    esGrupal: boolean;
    rolSnapshot?: string | null;
    notaRol?: I18n;
}

// --- Item dentro de snapshotItems de un componente ----------------------------

export interface PaxSnapshotItem {
    id: string;
    modo: 'incluido' | 'no_incluido' | 'opcional' | 'cortesia' | string;
    incluido: boolean;
    nombreSnapshot: I18n;
    tituloTarifaVisible: boolean;
    categoriaTarifaVisible: boolean;
    modalidadTarifaVisible: boolean;
}

// --- Componente ---------------------------------------------------------------

export interface PaxCotComponente {
    '@id'?: string;
    id: string;
    cantidad: number;
    nombreSnapshot: I18n;
    fechaHoraInicio?: string | null;
    fechaHoraFin?: string | null;
    sinHorario?: boolean;
    tipo?: string | null;
    cotsegmento?: PaxCotSegmento | null;
    cottarifas: PaxCottarifa[];
    detallesParaCliente: PaxDetalleCliente[];

    /**
     * PRESTADOR — quién presta este servicio.
     *
     * `TravelOrganizacion` es la entidad maestra; el prestador es el papel que juega aquí. La
     * cotización sólo guarda el enlace y el nombre histórico: **el título, la url y las
     * fotos las INYECTA el backend leyendo el catálogo al servir**, así que lo que llega
     * es lo que dice hoy la ficha de la empresa, no una copia del día en que se cotizó.
     *
     * Si el maestro ya no existe no llega nada de esto — sólo el nombre histórico en la
     * línea de inclusión. Es la degradación buscada: la propuesta antigua sigue contando
     * quién prestó el servicio, sin tarjeta a medias.
     *
     * ⚠️ Sólo llega si la propuesta decidió nombrarlo: el normalizer lo borra cuando el
     * flag global oculta proveedores o el componente no está marcado. La bandera no viaja.
     *
     * El nombre comercial, el correo, el teléfono y la dirección son operativos y nunca
     * salen del grupo interno.
     */
    prestadorTitulo?: I18n;
    prestadorUrl?: string | null;
    prestadorImagenes?: PaxImagenSnapshot[];
    /** El servicio contratado (ej. el tipo de habitación), también inyectado. */
    prestadorServicioTitulo?: I18n;
    prestadorServicioImagenes?: PaxImagenSnapshot[];

    /** Su hora representa el horario de toda la excursión (servicio completo), no
     *  la del segmento donde está anclado. Ver CotizacionCotcomponente. */
    horaServicioCompleto?: boolean;
}

// --- Servicio -----------------------------------------------------------------

export interface PaxCotServicio {
    '@id'?: string;
    id: string;
    nombrePublicoSnapshot: I18n;
    fechaInicioAbsoluta?: string | null;
    cotcomponentes: PaxCotComponente[];
    cotsegmentos: PaxCotSegmento[];
}

// --- Clasificación financiera CLIENTE (sin costos ni márgenes) -----------------

export interface PaxTarifaFinanciera {
    rol: string;
    moneda: string | null; // null en la versión cliente
    notaRol: I18n;
    cantidad: number;
    esGrupal: boolean;
    categoria: string | null;
    modalidad: string | null;
    procedencia?: string | null;
    edadMin?: number | null;
    edadMax?: number | null;
    tarifaTitulo: I18n;
    montoCotizado: string | null; // null en la versión cliente
}

export interface PaxInclusionItem {
    modo: string;
    fecha: string;
    nombre: I18n;
    /** Número de opción (req 3): componente opcional sin estándar. El texto
     *  "Opción N" se compone en la vista con el idioma actual. */
    grupoOpcion?: number;
    origen: 'componente' | 'item' | string;
    tarifas: PaxTarifaFinanciera[];
    categoria: string | null;
    modalidad: string | null;
    procedencia?: string | null;
    edadMin?: number | null;
    edadMax?: number | null;
    tarifaTitulo: I18n;
    cantidadComponente: number;
    /**
     * Prestador de referencia — sólo llega en líneas `no_incluido`.
     *
     * Es el hotel o el vuelo que el pasajero contrató por su cuenta. Convierte un
     * «no incluye alojamiento» en «Alojamiento, por su cuenta — Casa Andina», que es
     * la diferencia entre una lista de carencias y un itinerario completo.
     *
     * Espejo de `InclusionLinea` en `util/src/types/cotizacionEditorModel.ts`; lo
     * llena `construirInclusiones()` y viaja dentro de
     * `clasificacionFinancieraCliente`. Sólo la cara pública: nombre comercial,
     * teléfono y dirección son operativos y no salen del backend.
     */
    /** Nombre histórico del prestador: lo que se ve si la empresa ya no está. */
    prestadorNombre?: string | null;

    /**
     * Id del componente del que salió la línea. Espejo de `InclusionLinea` en
     * `util/src/types/cotizacionEditorModel.ts`.
     *
     * Es el enlace con el árbol vivo: el proveedor NO viaja en el snapshot, se lee del
     * componente, que el backend resuelve contra el catálogo maestro al servir. Así,
     * renombrar un hotel se ve al instante sin re-guardar ninguna propuesta.
     *
     * Opcional porque las propuestas anteriores al campo no lo traen; para ésas hay un
     * backfill (`app:cotizacion:backfill-componente-id`).
     */
    componenteId?: string;
}

export interface PaxInclusionServicio {
    servicioId: string;
    servicioNombre: I18n;
    incluidos: PaxInclusionItem[];
    noIncluidos: PaxInclusionItem[];
    opcionales: PaxInclusionItem[];
    cortesias: PaxInclusionItem[];
}

export interface PaxResumenVenta {
    ventaSoles: number;
    ventaDolares: number;
}

export interface PaxClasePasajeroDetalle {
    rol: string;
    modo: string;
    fecha: string;
    moneda: string;
    cantidad: number;
    esGrupal: boolean;
    categoria: string | null;
    modalidad: string | null;
    servicioId: string;
    ventaSoles: number;
    ventaDolares: number;
    // El costo de proveedor ya no viaja en el detalle por clase: era una fuga de margen.
    // Los importes que sí puede ver el cliente son `ventaSoles` y `ventaDolares`.
    tarifaTitulo: I18n;
    servicioNombre: I18n;
    componenteNombre: I18n;
    cantidadComponente: number;
}

export interface PaxClasePasajero {
    tipo: string;
    tipoPaxNombre: string;
    cantidad: number;
    edadMin: number;
    edadMax: number;
    detalle: PaxClasePasajeroDetalle[];
    resumen: { ventaDolares: number };
    resumenPorModo: Record<'ctaPax' | 'normal' | 'cortesia', PaxResumenVenta>;
}

export interface PaxClasificacionFinancieraCliente {
    numPax: number;
    tipoCambio: number;
    generatedAt: string;
    schemaVersion: number;
    precioOculto: boolean;
    /** Ausente si precioOculto=true (redactado por CotizacionPublicNormalizer). */
    montoAdelanto?: number;
    /** Ausente si precioOculto=true (redactado por CotizacionPublicNormalizer). */
    totalVentaBruta?: number;
    inclusiones: PaxInclusionServicio[];
    /** Ausente si precioOculto=true (redactado por CotizacionPublicNormalizer). */
    resumenGeneral?: Record<'cortesia' | 'incluido' | 'noIncluido', PaxResumenVenta>;
    /** Ausente si precioOculto=true (redactado por CotizacionPublicNormalizer). */
    clasesPasajeros?: PaxClasePasajero[];
    opcionesUpgrade: PaxOpcionUpgrade[];
}

// --- Cotización activa (solo campos pax) ---------------------------------------

export interface PaxCotizacion {
    '@id'?: string;
    '@type'?: string;
    id?: string;
    version: number;
    estado: string; // CotizacionEstadoEnum
    numPax: number;
    /** Título comercial opcional de la propuesta/tour (i18n); vacío si no se definió. */
    titulo?: I18n;
    precioOculto: boolean;
    proveedorOculto: boolean; // 🔥 anonimato global de proveedores
    /** Modo catálogo unitario: oculta totales y toda referencia a cantidad de pasajeros. */
    totalesOcultos?: boolean;
    resumen: unknown[];
    fechaExpiracion?: string | null;
    monedaGlobal: string;
    idiomaCliente: string;
    /** Ausente si precioOculto=true (redactado por CotizacionPublicNormalizer). */
    totalVenta?: string;
    /** Ausente si precioOculto=true (redactado por CotizacionPublicNormalizer). */
    adelanto?: string;
    clasificacionFinancieraCliente?: PaxClasificacionFinancieraCliente | null;
    cotservicios: PaxCotServicio[];
}

// --- Pasajeros y documentos visibles ------------------------------------------

export interface PaxFilepasajero {
    '@id'?: string;
    id?: string;
    nombre: string;
    apellido: string;
    pais?: unknown; // objeto MaestroPais embebido según serialización
    sexo?: 'M' | 'F' | null;
    tipodocumento?: string | null;
    fechanacimiento?: string | null;
    numerodocumento?: string | null;
}

export interface PaxFiledocumento {
    '@id'?: string;
    id?: string;
    nombre?: I18n;
    vencimiento?: string | null;
    tipodocumento?: string | null;
    imageUrl?: string | null;
}

// --- Resumen de propuesta (card de la portada) ----------------------------------

/** Item de getVersionesParaCliente(): resumen liviano para comparar propuestas */
export interface PaxVersionResumen {
    version: number;
    estado: string;
    numPax: number;
    titulo?: I18n; // título comercial multiidioma (opcional)
    resumen: I18n; // HTML comercial multiidioma
    idiomaCliente: string;
    monedaGlobal: string;
    precioOculto: boolean;
    tipoCambio: number;
    totalVenta: string | null; // null si precioOculto
    adelanto: string | null;
    fechaExpiracion?: string | null;
    fechaInicio?: string | null; // primera fecha de servicio (yyyy-MM-dd)
}

// --- Raíz: el expediente público ------------------------------------------------

export interface PaxCotizacionFile {
    '@context'?: string;
    '@id'?: string;
    '@type'?: string;
    localizador: string;
    nombreGrupo: string;
    pasajeroPrincipal?: string | null;
    idiomaCliente?: string;
    /** Cards de todas las propuestas públicas vigentes (siempre presente) */
    versionesParaCliente: PaxVersionResumen[];
    /** Cotización completa; solo viene cuando la URL incluye /{version} */
    cotizacionParaCliente?: PaxCotizacion | null;
    documentosParaCliente: PaxFiledocumento[];
    filepasajeros: PaxFilepasajero[];
}

// --- Catálogo de tours (escaparate público) -------------------------------------

/** Rango comercial "Desde X" por perfil de cliente (título traducible). */
export interface PaxPrecioDesdeRango {
    titulo: I18n;
    moneda: string;
    valor: string;
}

/** Card liviana de un tour del catálogo (portada del escaparate). */
export interface PaxTourResumen {
    version: number;
    estado: string;
    numPax: number;
    titulo: I18n;
    resumen: I18n; // HTML comercial multiidioma
    idiomaCliente: string;
    monedaGlobal: string;
    precioOculto: boolean;
    orden: number;
    preciosDesde: PaxPrecioDesdeRango[];
    /** Snapshot de la imagen de portada (override o derivada); null si el tour no tiene fotos */
    imagenPortada?: { imageUrl?: string; imageName?: string; isPortada?: boolean } | null;
    /** Duración del programa en días (span del itinerario nominal) */
    numDias?: number | null;
}

/** Raíz: el catálogo público de tours (por localizador). */
export interface PaxCatalogo {
    '@context'?: string;
    '@id'?: string;
    '@type'?: string;
    localizador: string;
    nombre: string;
    idiomaCliente?: string;
    /** Cards de todos los tours públicos (siempre presente) */
    toursParaCliente: PaxTourResumen[];
    /** Cotización completa del tour; solo viene cuando la URL incluye /{version} */
    cotizacionParaCliente?: PaxCotizacion | null;
}

// --- Tipos derivados para la UI (itinerario agrupado) ---------------------------

/** Segmento enriquecido con referencia a su servicio padre */
export interface PaxSegmentoConServicio {
    segmento: PaxCotSegmento;
    servicio: PaxCotServicio;
    /** Componentes cuyo cotsegmento apunta a este segmento */
    componentes: PaxCotComponente[];
}

/** Un día del itinerario del cliente */
export interface PaxDiaItinerario {
    fecha: string; // yyyy-MM-dd
    numeroDia: number; // correlativo 1..N sobre el viaje completo
    segmentos: PaxSegmentoConServicio[];
}

export interface PaxOpcionUpgrade {
    servicioNombre: I18n;
    componenteNombre: I18n;
    tarifaTitulo: I18n;
    modalidad: string | null;
    categoria: string | null;
    procedencia?: string | null;
    edadMin?: number | null;
    edadMax?: number | null;
    notaRol?: I18n;
    /** Estándar reemplazada (datos públicos, ya gateados en el editor). */
    tieneEstandarEspejo?: boolean;
    estandarTitulo?: I18n;
    estandarModalidad?: string | null;
    estandarCategoria?: string | null;
    /** Grupo de tarifa (1 = estándar). Con el flag esOpcion permite reconstruir
     *  la etiqueta traducida "Alternativa N" (grupo-1) u "Opción N" (grupo). */
    grupoTarifa: number;
    esOpcion: boolean;
    /** Deltas en USD (negativo = descuento). Ausentes si precioOculto=true
     *  (redactados por CotizacionPublicNormalizer). */
    deltaVentaTotal?: number;
    deltaVentaPorPax?: number;
}