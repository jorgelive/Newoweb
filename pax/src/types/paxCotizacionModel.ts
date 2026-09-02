// src/types/paxCotizacionModel.ts
// ============================================================================
// Tipos de la VISTA PÚBLICA DEL CLIENTE (grupo de serialización pax_cotizacion:read)
//
// Endpoint: GET /client/cotizacion/cotizacion_file/{localizador}  (PUBLIC_ACCESS)
// Provider: CotizacionFilePublicProvider
//
// ⚠️ **EL CONTRATO ES `api.d.ts`. Estos tipos se ANCLAN a él, no se copian.**
//
// Hasta el 27/08/2026 estaban escritos enteros a mano «porque los snapshots JSON salen como
// diccionario abierto en el export». El diagnóstico era correcto y la conclusión no: un tipo a
// mano no falla cuando el backend cambia — **describe una API que ya no existe**, y `vue-tsc` lo
// da por bueno porque sólo sabe lo que dice el `.d.ts`. Se vio en el renombrado de nombres de
// agosto: `util` señaló sus 80 usos y `pax` no dijo ni una, porque no miraba el esquema.
//
// La regla: `Omit<Base, 'campo'> & { campo: TipoReal }`, y **sólo** por estos dos motivos:
//
//   1. El export tipa una columna JSON como `{[k: string]: string|null}[]` y la forma real es
//      `{ language, content }[]`. Se estrecha, no se inventa.
//   2. El campo lo INYECTA el normalizer al servir y la introspección no lo ve (los datos del
//      prestador, que se leen del catálogo vivo). Ahí no hay esquema que respetar.
//   3. El esquema lo marca OPCIONAL porque API Platform no puede garantizarlo al escribir —`id`,
//      `fechaAbsoluta`—, pero un recurso que se LEE siempre lo trae. Se pasa a requerido para no
//      sembrar `!` y `?? ''` por toda la vista. Mismo criterio que `util` (`CotSegmento`,
//      `ComponenteCompleto`). Es la única familia de override que estrecha una opcionalidad, y
//      sólo vale porque este modelo describe una respuesta de LECTURA.
//
// Cualquier otro campo se toma del esquema tal cual. Si falta uno, se arregla en PHP y se
// regenera con `npm run gen:api` — no se declara aquí.
// ============================================================================

import type { components } from './api';

type SegmentoBase   = components['schemas']['CotizacionSegmento-pax_file.read_pax_cotizacion.read'];
type CottarifaBase  = components['schemas']['CotizacionCottarifa-pax_file.read_pax_cotizacion.read'];
type ComponenteBase = components['schemas']['CotizacionCotcomponente-pax_file.read_pax_cotizacion.read'];
type ServicioBase   = components['schemas']['CotizacionCotservicio-pax_file.read_pax_cotizacion.read'];
type CotizacionBase = components['schemas']['Cotizacion-pax_file.read_pax_cotizacion.read'];
type PasajeroBase   = components['schemas']['CotizacionFilepasajero-pax_file.read'];

// --- Primitivos compartidos --------------------------------------------------

/**
 * Elemento de contenido multiidioma: `[{ content, language }, …]`.
 *
 * Espejo de `I18nContent` en `util/src/types/cotizacionEditorModel.ts`. Son dos apps distintas,
 * así que la copia está justificada — pero si una gana un campo, la otra también.
 */
export interface PaxI18nContent {
    content: string;
    language: string;
    /**
     * Huella del español del que salió esta traducción. La pone y la lee el backend; `pax` sólo
     * la recibe y la ignora. Está declarada para que el tipo no se quede corto: uno escrito a
     * mano que no dice toda la verdad no falla al compilar, miente.
     */
    origenHash?: string;
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

export type PaxCotSegmento = Omit<
    SegmentoBase,
    'tituloSnapshot' | 'contenidoSnapshot' | 'imagenesSnapshot' | 'notasSnapshot'
> & {
    // Motivo 1: columnas i18n/JSON que el export da como diccionario abierto.
    tituloSnapshot: I18n;
    contenidoSnapshot: I18n; // HTML por idioma
    imagenesSnapshot: PaxImagenSnapshot[];
    notasSnapshot: PaxNotaSnapshot[];
    // Motivo 3.
    id: string;
    fechaAbsoluta: string;
    '@id'?: string;
    '@type'?: string;
};

// --- Tarifa (solo campos expuestos al cliente) --------------------------------

export type PaxCottarifa = Omit<CottarifaBase, 'tituloSnapshot' | 'notaRol'> & {
    // Motivo 1. El resto —modalidad, categoría, edades, esGrupal— se toma del esquema.
    tituloSnapshot: I18n;
    notaRol?: I18n;
    id: string;   // Motivo 3.
    '@id'?: string;
};

// --- Item dentro de snapshotItems de un componente ----------------------------

export interface PaxSnapshotItem {
    id: string;
    modo: 'incluido' | 'no_incluido' | 'opcional' | 'cortesia' | string;
    incluido: boolean;
    tituloSnapshot: I18n;
    tituloTarifaVisible: boolean;
    categoriaTarifaVisible: boolean;
    modalidadTarifaVisible: boolean;
}

// --- Componente ---------------------------------------------------------------

export type PaxCotComponente = Omit<
    ComponenteBase,
    'tituloSnapshot' | 'cotsegmento' | 'cottarifas' | 'detallesParaCliente'
> & {
    // ── Motivo 1: columnas JSON que el export da como diccionario abierto ──
    tituloSnapshot: I18n;
    cotsegmento?: PaxCotSegmento | null;
    cottarifas: PaxCottarifa[];
    detallesParaCliente: PaxDetalleCliente[];
    id: string;   // Motivo 3.
    '@id'?: string;

    // (del esquema; el docblock se conserva porque explica la REGLA, no la forma)
    /**
     * Dónde va este componente dentro de su jornada al CONTAR el viaje.
     *
     * ⚠️ Lo decide `ComponenteTipoEnum::ordenNarrativo()` en PHP, no el front. `util/` y `pax/`
     * son dos aplicaciones que no comparten código: poner los números aquí sería escribirlos dos
     * veces, y dos copias de una regla discrepan el día que alguien toca una.
     *
     * Sirve para que el alojamiento cierre su día en vez de caer en medio —el backend sirve los
     * componentes por `fechaHoraInicio` y el check-in de un hotel es a media tarde—.
     */
    // `ordenNarrativo` viene del esquema: no se redeclara.

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
    /** Qué es la empresa, en prosa. Mismo origen y mismo gate que el título. */
    prestadorDescripcion?: I18n;
    prestadorUrl?: string | null;
    prestadorImagenes?: PaxImagenSnapshot[];
    /** El servicio contratado (ej. el tipo de habitación), también inyectado. */
    prestadorServicioTitulo?: I18n;
    /** Qué incluye ESE servicio suyo: la piscina, el buffet, la habitación. */
    prestadorServicioDescripcion?: I18n;
    prestadorServicioImagenes?: PaxImagenSnapshot[];
};

// --- Servicio -----------------------------------------------------------------

export type PaxCotServicio = Omit<
    ServicioBase,
    'tituloSnapshot' | 'cotcomponentes' | 'cotsegmentos'
> & {
    tituloSnapshot: I18n;
    // Se estrechan a los tipos ya anclados de abajo, para que la recursión del esquema no
    // arrastre la forma cruda en cada nivel.
    cotcomponentes: PaxCotComponente[];
    cotsegmentos: PaxCotSegmento[];
    id: string;   // Motivo 3.
    '@id'?: string;
};

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

export type PaxCotizacion = Omit<
    CotizacionBase,
    'titulo' | 'resumen' | 'clasificacionFinancieraCliente' | 'cotservicios'
> & {
    // Motivo 1: columnas JSON.
    titulo?: I18n;
    resumen: unknown[];
    clasificacionFinancieraCliente?: PaxClasificacionFinancieraCliente | null;
    cotservicios: PaxCotServicio[];
    '@id'?: string;
    '@type'?: string;
};


// --- Pasajeros y documentos visibles ------------------------------------------

export type PaxFilepasajero = Omit<PasajeroBase, 'identificaciones'> & {
    /**
     * Sus documentos de identidad. Espejo de `CotizacionPasajeroIdentificacion`.
     *
     * ⚠️ Sustituye a `tipodocumento` + `numerodocumento`, que admitían uno solo: una persona lleva
     * DNI *y* pasaporte con vencimientos distintos. **Al tocar la entidad, tocar esto.**
     *
     * Se estrecha (motivo 1) porque el export da la colección embebida sin forma útil.
     */
    identificaciones?: Array<{ tipo?: string | null; numero?: string | null; vencimiento?: string | null }>;
    '@id'?: string;
};


/**
 * Un adjunto del expediente: boleto, factura, confirmación de reserva.
 *
 * ⚠️ Escrito a mano, así que **no lo protege el compilador**: cuando el backend renombró
 * `tipodocumento` → `tipoArchivo` y quitó `vencimiento`, este archivo siguió compilando y la
 * portada dejó de rotular los adjuntos en silencio. Es literalmente el caso que documenta
 * `CLAUDE.md`: un tipo escrito a mano que se queda corto no falla, miente.
 * Espejo de `App\Cotizacion\Entity\CotizacionFilearchivo`. **Al tocar una, tocar la otra.**
 */
export interface PaxFilearchivo {
    '@id'?: string;
    id?: string;
    nombre?: I18n;
    tipoArchivo?: string | null;
    imageUrl?: string | null;
}

// --- Resumen de propuesta (card de la portada) ----------------------------------

/** Item de getVersionesParaCliente(): resumen liviano para comparar propuestas */
export interface PaxPropuestaResumen {
    propuesta: number;
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
    propuestasParaCliente: PaxPropuestaResumen[];
    /** Cotización completa; solo viene cuando la URL incluye /{version} */
    cotizacionParaCliente?: PaxCotizacion | null;
    documentosParaCliente: PaxFilearchivo[];
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
    propuesta: number;
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