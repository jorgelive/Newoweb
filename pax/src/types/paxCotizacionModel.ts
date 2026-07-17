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

export type PaxI18n = PaxI18nContent[];

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
    titulo: PaxI18n;
    contenido: PaxI18n;
    nombreInterno?: string;
}

/** Bloque de detalle operativo visible al cliente (getDetallesParaCliente) */
export interface PaxDetalleCliente {
    id: string;
    tipo: 'cliente';
    detalle: PaxI18n;
}

// --- Segmento (día a día del itinerario) -------------------------------------

export interface PaxCotSegmento {
    '@id'?: string;
    '@type'?: string;
    id: string;
    dia: number;
    orden: number;
    fechaAbsoluta: string; // ISO date
    nombreSnapshot: PaxI18n;
    contenidoSnapshot: PaxI18n; // HTML por idioma
    imagenesSnapshot: PaxImagenSnapshot[];
    notasSnapshot: PaxNotaSnapshot[];
}

// --- Tarifa (solo campos expuestos al cliente) --------------------------------

export interface PaxCottarifa {
    '@id'?: string;
    id: string;
    cantidad: number;
    tituloSnapshot: PaxI18n;
    nombreInternoSnapshot?: string | null;
    proveedorNombreSnapshot?: string | null;
    proveedorTituloSnapshot: PaxI18n;
    proveedorUrlSnapshot?: string | null;
    proveedorImagenesSnapshot: PaxImagenSnapshot[];
    proveedorServicioTituloSnapshot: PaxI18n;
    proveedorServicioUrlSnapshot?: string | null;
    proveedorServicioImagenesSnapshot: PaxImagenSnapshot[];
    modalidadSnapshot?: string | null; // 'privado' | 'compartido' | null
    categoriaSnapshot?: string | null; // 'superior' | ...
    procedenciaSnapshot?: string | null;
    edadMinimaSnapshot?: number | null;
    edadMaximaSnapshot?: number | null;
    esGrupal: boolean;
    proveedorOculto: boolean; // 🔥 si true, no mostrar marca del proveedor
    rolSnapshot?: string | null;
    notaRol?: PaxI18n;
}

// --- Item dentro de snapshotItems de un componente ----------------------------

export interface PaxSnapshotItem {
    id: string;
    modo: 'incluido' | 'no_incluido' | 'opcional' | 'cortesia' | string;
    incluido: boolean;
    nombreSnapshot: PaxI18n;
    tituloTarifaVisible: boolean;
    categoriaTarifaVisible: boolean;
    modalidadTarifaVisible: boolean;
}

// --- Componente ---------------------------------------------------------------

export interface PaxCotComponente {
    '@id'?: string;
    id: string;
    cantidad: number;
    nombreSnapshot: PaxI18n;
    fechaHoraInicio?: string | null;
    fechaHoraFin?: string | null;
    cotsegmento?: PaxCotSegmento | null;
    snapshotItems: PaxSnapshotItem[];
    cottarifas: PaxCottarifa[];
    detallesParaCliente: PaxDetalleCliente[];
}

// --- Servicio -----------------------------------------------------------------

export interface PaxCotServicio {
    '@id'?: string;
    id: string;
    nombrePublicoSnapshot: PaxI18n;
    fechaInicioAbsoluta?: string | null;
    cotcomponentes: PaxCotComponente[];
    cotsegmentos: PaxCotSegmento[];
}

// --- Clasificación financiera CLIENTE (sin costos ni márgenes) -----------------

export interface PaxTarifaFinanciera {
    rol: string;
    moneda: string | null; // null en la versión cliente
    notaRol: PaxI18n;
    cantidad: number;
    esGrupal: boolean;
    categoria: string | null;
    modalidad: string | null;
    tarifaTitulo: PaxI18n;
    montoCotizado: string | null; // null en la versión cliente
}

export interface PaxInclusionItem {
    modo: string;
    fecha: string;
    nombre: PaxI18n;
    origen: 'componente' | 'item' | string;
    tarifas: PaxTarifaFinanciera[];
    categoria: string | null;
    modalidad: string | null;
    tarifaTitulo: PaxI18n;
    cantidadComponente: number;
}

export interface PaxInclusionServicio {
    servicioId: string;
    servicioNombre: PaxI18n;
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
    montoCotizado: string;
    tarifaTitulo: PaxI18n;
    servicioNombre: PaxI18n;
    componenteNombre: PaxI18n;
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
    montoAdelanto: number;
    totalVentaBruta: number;
    inclusiones: PaxInclusionServicio[];
    resumenGeneral: Record<'cortesia' | 'incluido' | 'noIncluido', PaxResumenVenta>;
    clasesPasajeros: PaxClasePasajero[];
    opcionesUpgrade: unknown[];
}

// --- Cotización activa (solo campos pax) ---------------------------------------

export interface PaxCotizacion {
    '@id'?: string;
    '@type'?: string;
    id?: string;
    version: number;
    estado: string; // CotizacionEstadoEnum
    numPax: number;
    adelanto: string;
    precioOculto: boolean;
    proveedorOculto: boolean; // 🔥 anonimato global de proveedores
    resumen: unknown[];
    fechaExpiracion?: string | null;
    monedaGlobal: string;
    idiomaCliente: string;
    totalVenta: string;
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
    vencimiento?: string | null;
    tipodocumento?: string | null; // ArchivoTipoEnum (solo los esPublico())
    imageUrl?: string | null;
}

// --- Resumen de propuesta (card de la portada) ----------------------------------

/** Item de getVersionesParaCliente(): resumen liviano para comparar propuestas */
export interface PaxVersionResumen {
    version: number;
    estado: string;
    numPax: number;
    resumen: PaxI18n; // HTML comercial multiidioma
    idiomaCliente: string;
    monedaGlobal: string;
    precioOculto: boolean;
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
    /** Cards de todas las propuestas públicas vigentes (siempre presente) */
    versionesParaCliente: PaxVersionResumen[];
    /** Cotización completa; solo viene cuando la URL incluye /{version} */
    cotizacionParaCliente?: PaxCotizacion | null;
    documentosParaCliente: PaxFiledocumento[];
    filepasajeros: PaxFilepasajero[];
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
