import { components } from '@/types/api';

export enum Language {
    De = "de",
    En = "en",
    Es = "es",
    Fr = "fr",
    It = "it",
    Nl = "nl",
    Pt = "pt",
}

export interface I18nContent {
    content: string;
    language: Language | string; // Permitimos string para flexibilizar asignaciones literales tipo 'es'
}

/**
 * Snapshot de una imagen de galería (proveedor o servicio de proveedor).
 * Espejo minimalista de OrganizacionImagen/OrganizacionServicioImagen del backend —
 * solo lo necesario para render, sin metadatos de archivo físico (imageName, imageSize).
 */
export interface ImagenProveedorSnapshot {
    imageUrl: string | null;
    orden: number;
    isPortada: boolean;
}

export type MaestroMoneda = components['schemas']['Moneda-componente.item.read'] & {
    '@id'?: string;
};

/**
 * El schema autogenerado declara `proveedorImagenes` como `string[]` (IRIs),
 * pero el grupo de lectura serializa los objetos completos. Se corrige aquí para
 * que el snapshot de imágenes del proveedor no tenga que pasar por un cast.
 */
export type Organizacion = Omit<components['schemas']['Organizacion-organizacion.read'], 'proveedorImagenes'> & {
    id: string;
    '@id'?: string;
    proveedorImagenes?: ImagenProveedorSnapshot[];
};

export type Servicio = components['schemas']['Servicio-servicio.item.read'] & {
    id: string;
    '@id'?: string;
};

export type Componente = components['schemas']['Componente-componente.item.read'] & {
    tarifas: Tarifa[];
    snapshotItems: SnapshotItem[];
    '@id'?: string;
};

export type Itinerario = components['schemas']['Itinerario-itinerario.read'];

type CotServicioBase = components["schemas"]["CotizacionCotservicio-cotizacion.read_timestamp.read"];

export type CotServicio = Omit<
    CotServicioBase,
    'nombreSnapshot' |
    'itinerarioNombreSnapshot' |
    'nombrePublicoSnapshot' |
    'cotcomponentes' |
    'cotsegmentos'
> & {
    nombreSnapshot: I18nContent[];
    itinerarioNombreSnapshot: I18nContent[];
    /** Id del maestro TravelItinerario desde el que se armó el servicio (re-sync exacto de flags). */
    itinerarioMaestroId?: string | null;
    nombrePublicoSnapshot: I18nContent[];
    cotcomponentes?: ComponenteCompleto[];
    cotsegmentos?: CotSegmento[];
};

/** Rango comercial "Desde X" por perfil de cliente (tours de catálogo). */
export interface PrecioDesdeRango {
    titulo: I18nContent[];
    moneda: string;
    valor: string;
}

/**
 * Los campos del `Omit` son los que el schema autogenerado tipa mal (i18n y los
 * JSON estructurados llegan como `string[]`) o de forma incompleta. Tienen que
 * ir en el `Omit`, no solo redeclararse debajo: una intersección `string[] & X`
 * no falla al compilar pero deja un tipo inservible — era justo lo que pasaba
 * con `preciosDesde` y `clasificacionFinanciera`.
 */
export type Cotizacion = Omit<
    components['schemas']['Cotizacion-cotizacion.read_timestamp.read'],
    'file' | 'cotservicios' | 'resumen' | 'titulo' | 'preciosDesde'
    | 'clasificacionFinanciera' | 'clasificacionFinancieraCliente' | 'imagenPortada'
> & {
    /**
     * Override editorial de la portada del tour: el snapshot de la imagen elegida
     * a mano (espejo de `Cotizacion::$imagenPortada`, columna json). `null` = se
     * deriva sola del itinerario. El schema la declara `string[]`.
     */
    imagenPortada?: ImagenSnapshot | null;
    idiomaEdicion: string;
    titulo?: I18nContent[];
    file?: { id?: string; '@id'?: string; createdAt?: string; updatedAt?: string; } | string | null;
    catalogo?: { id?: string; '@id'?: string; } | string | null;
    preciosDesde?: PrecioDesdeRango[];
    orden?: number;
    cotservicios: CotServicio[];
    resumen: I18nContent[];
    clasificacionFinanciera?: ClasificacionFinancieraInterna;
    clasificacionFinancieraCliente?: ClasificacionFinancieraCliente;
    proveedorOculto?: boolean;
    /** Modo catálogo unitario: oculta totales y referencias a cantidad de pax en la guía del cliente. */
    totalesOcultos?: boolean;
};

export type TarifaProcedenciaValue = NonNullable<TarifaBase['procedencia']>;

// Espejo de App\Travel\Enum\TarifaModalidadEnum
export type TarifaModalidadValue = NonNullable<TarifaBase['modalidad']>;

// Espejo de App\Travel\Enum\TarifaCategoriasEnum
export type TarifaCategoriaValue = NonNullable<TarifaBase['categoria']>;

export interface ProcedenciaUIConfig {
    icon: string;
    label: string;
}

export const MODALIDAD_CONFIG: Record<TarifaModalidadValue, ProcedenciaUIConfig> = {
    privado:    { icon: '🔒', label: 'Privado' },
    compartido: { icon: '👥', label: 'Compartido' },
};

export const CATEGORIA_CONFIG: Record<TarifaCategoriaValue, ProcedenciaUIConfig> = {
    economico: { icon: '💵', label: 'Económico' },
    estandar:  { icon: '⭐', label: 'Estándar' },
    superior:  { icon: '✨', label: 'Superior' },
    premium:   { icon: '👑', label: 'Premium' },
};

export const enumOptions = <T extends string>(
    config: Record<T, ProcedenciaUIConfig>
): { value: T; label: string; icon: string }[] =>
    (Object.keys(config) as T[]).map((value) => ({ value, ...config[value] }));

export const PROCEDENCIA_CONFIG: Record<TarifaProcedenciaValue, ProcedenciaUIConfig> = {
    nacional: { icon: '🇵🇪', label: 'Nacional' },
    extranjero: { icon: '🌎', label: 'Extranjero' },
    can: { icon: '🤝', label: 'CAN' },
};

export const getProcedenciaUI = (procedencia?: string | null): ProcedenciaUIConfig =>
    procedencia
        ? (PROCEDENCIA_CONFIG[procedencia as TarifaProcedenciaValue] || { icon: '🌐', label: procedencia })
        : { icon: '🌐', label: 'Sin restricción' };

// ============================================================================
// 🔥 ESPEJOS DE ENUMS PHP (App\Cotizacion\Enum / App\Travel\Enum)
// ============================================================================

export interface EstadoUIConfig {
    label: string;
    bg: string;
    text: string;
    border: string;
    icon: string;
}

type CotizacionEstadoValue = components['schemas']['Cotizacion-cotizacion.read_timestamp.read']['estado'];

export type Item = components['schemas']['TravelComponenteItem-componente.item.read'];

export const ESTADO_COTIZACION_CONFIG: Record<CotizacionEstadoValue, EstadoUIConfig> = {
    pendiente: { label: 'Pendiente', bg: 'bg-amber-50', text: 'text-amber-700', border: 'border-amber-200', icon: 'fa-clock' },
    enviado: { label: 'Enviado', bg: 'bg-sky-50', text: 'text-sky-700', border: 'border-sky-200', icon: 'fa-paper-plane' },
    archivado: { label: 'Archivado', bg: 'bg-slate-100', text: 'text-slate-500', border: 'border-slate-200', icon: 'fa-box-archive' },
    confirmado: { label: 'Confirmado', bg: 'bg-emerald-50', text: 'text-emerald-700', border: 'border-emerald-200', icon: 'fa-check' },
    operado: { label: 'Operado', bg: 'bg-blue-50', text: 'text-blue-700', border: 'border-blue-200', icon: 'fa-plane-departure' },
    cancelado: { label: 'Cancelado', bg: 'bg-rose-50', text: 'text-rose-700', border: 'border-rose-200', icon: 'fa-times-circle' },
    // Espejo de `CotizacionEstadoEnum::HISTORICO`. No es una propuesta: es la foto de una que ya
    // se vendió, congelada antes de tocarla. Comparte número de versión con la viva a propósito.
    historico: { label: 'Histórico', bg: 'bg-violet-50', text: 'text-violet-700', border: 'border-violet-200', icon: 'fa-clock-rotate-left' },
};

export type ComponenteModoValue = 'incluido' | 'no_incluido' | 'cortesia' | 'reemplazado';
export type ItemModoValue = 'incluido' | 'opcional' | 'no_incluido';

export const MODO_COMERCIAL_CONFIG: Record<ComponenteModoValue | ItemModoValue | string, EstadoUIConfig> = {
    incluido:    { label: 'Incluido',    bg: 'bg-emerald-50', text: 'text-emerald-700', border: 'border-emerald-200', icon: 'fa-check-circle' },
    opcional:    { label: 'Opcional',    bg: 'bg-amber-50',   text: 'text-amber-700',   border: 'border-amber-200',   icon: 'fa-circle-question' },
    no_incluido: { label: 'No incluido', bg: 'bg-slate-100',  text: 'text-slate-500',   border: 'border-slate-200',   icon: 'fa-ban' },
    cortesia:    { label: 'Cortesía',    bg: 'bg-sky-50',     text: 'text-sky-700',     border: 'border-sky-200',     icon: 'fa-gift' },
    reemplazado: { label: 'Reemplazado', bg: 'bg-rose-50',    text: 'text-rose-700',    border: 'border-rose-200',    icon: 'fa-rotate' },
};

/**
 * Espejo de `ComponenteEstadoEnum` (PHP). Responde **sólo** si el componente sigue en
 * pie dentro de la cotización.
 *
 * ⚠️ No confundir con `ESTADO_OPERATIVO_CONFIG`, justo debajo: ése sí es el estado de
 * reserva con el proveedor y vive en La Biblia. Este enum tuvo `confirmado` y
 * `reconfirmado` durante un tiempo, no los leía nadie, y hacían creer al vendedor que
 * marcaba una confirmación real. Ver docs/Cotizaciones.md §3.b.
 */
export type ComponenteEstadoValue = 'activo' | 'cancelado';

export const ESTADO_COMPONENTE_CONFIG: Record<ComponenteEstadoValue, EstadoUIConfig> = {
    activo:    { label: 'Activo',    bg: 'bg-emerald-50', text: 'text-emerald-700', border: 'border-emerald-200', icon: 'fa-check' },
    cancelado: { label: 'Cancelado', bg: 'bg-red-50',     text: 'text-red-700',     border: 'border-red-200',     icon: 'fa-times-circle' },
};

export type EstadoOperativoValue = 'sin-solicitar' | 'solicitado' | 'confirmado' | 'reconfirmado' | 'pendiente-pago';

export const ESTADO_OPERATIVO_CONFIG: Record<EstadoOperativoValue, EstadoUIConfig> = {
    'sin-solicitar':  { label: 'Sin Solicitar',  bg: 'bg-slate-100', text: 'text-slate-500',  border: 'border-slate-200', icon: 'fa-circle-minus' },
    'solicitado':     { label: 'Solicitado',     bg: 'bg-amber-50',  text: 'text-amber-700',  border: 'border-amber-200', icon: 'fa-paper-plane' },
    'confirmado':     { label: 'Confirmado',     bg: 'bg-emerald-50', text: 'text-emerald-700', border: 'border-emerald-200', icon: 'fa-check' },
    'reconfirmado':   { label: 'Reconfirmado',   bg: 'bg-teal-50',   text: 'text-teal-700',   border: 'border-teal-200',  icon: 'fa-check-double' },
    'pendiente-pago': { label: 'Pendiente Pago', bg: 'bg-red-50',    text: 'text-red-700',    border: 'border-red-200',  icon: 'fa-money-bill-wave' },
};

export const getModoItemConfig = (modo?: string | null): EstadoUIConfig =>
    MODO_COMERCIAL_CONFIG[modo || 'incluido'] || MODO_COMERCIAL_CONFIG.incluido;

// El fallback es `activo`: si llega un valor viejo o desconocido, lo prudente es
// tratarlo como vigente. Dar por cancelado lo que no se reconoce lo borraría del
// cálculo y de la propuesta sin que nadie lo pidiera.
export const getEstadoComponenteConfig = (estado?: string | null): EstadoUIConfig =>
    ESTADO_COMPONENTE_CONFIG[(estado as ComponenteEstadoValue) || 'activo'] || ESTADO_COMPONENTE_CONFIG.activo;

export const getEstadoOperativoConfig = (estado?: string | null): EstadoUIConfig =>
    ESTADO_OPERATIVO_CONFIG[(estado as EstadoOperativoValue) || 'sin-solicitar'] || ESTADO_OPERATIVO_CONFIG['sin-solicitar'];

export type NotaTipoValue = components['schemas']['Nota-segmento.read']['tipo'];

export const NOTA_TIPO_CONFIG: Record<NotaTipoValue, EstadoUIConfig> = {
    introduccion:  { label: 'Introducción',  bg: 'bg-indigo-100', text: 'text-indigo-700', border: 'border-indigo-200', icon: 'fa-book-open' },
    recomendacion: { label: 'Recomendación', bg: 'bg-amber-100',  text: 'text-amber-700',  border: 'border-amber-200', icon: 'fa-lightbulb' },
    advertencia:   { label: 'Advertencia',   bg: 'bg-red-100',    text: 'text-red-700',    border: 'border-red-200',   icon: 'fa-exclamation-triangle' },
};

export const getTipoNotaUI = (tipo?: string | null): EstadoUIConfig =>
    NOTA_TIPO_CONFIG[tipo as NotaTipoValue] || { label: tipo || 'Otros', bg: 'bg-sky-100', text: 'text-sky-700', border: 'border-sky-200', icon: 'fa-info-circle' };

export const formatRangoEdad = (min?: number | null, max?: number | null): string => {
    const edadMin = min ?? 0;
    const edadMax = max ?? 120;
    if (edadMin <= 0 && edadMax >= 120) return '';
    if (edadMin > 0 && edadMax < 120) return `${edadMin} - ${edadMax} años`;
    if (edadMin > 0) return `${edadMin}+ años`;
    return `Hasta ${edadMax} años`;
};

type CotizacionFileBase = components["schemas"]["CotizacionFile-file.read_file.item.read_timestamp.read"];

export type CotizacionFileExtended = Omit<CotizacionFileBase, 'cotizaciones'> & {
    id?: string | null;
    localizador?: string;
    idiomaCliente?: string;
    cotizaciones?: Cotizacion[];
};

/** Nota del maestro de segmento (la del snapshot es `NotaSnapshot`). */
export interface NotaMaestra {
    nombreInterno?: string;
    tipo?: string;
    titulo?: I18nContent[];
    contenido?: I18nContent[];
}

/**
 * Maestro de segmento. `titulo`, `contenido` y los textos de `notas` son i18n:
 * el schema autogenerado los declara `string[]` pero llegan objetos
 * `{language, content}` (ver el aviso de §2 en docs/Cotizaciones.md).
 */
export type Segmento = Omit<
    components['schemas']['Segmento-segmento.item.read'],
    'titulo' | 'contenido' | 'notas'
> & {
    titulo?: I18nContent[];
    contenido?: I18nContent[];
    notas?: NotaMaestra[];
};
export type TarifaBase = components['schemas']['Tarifa-componente.item.read'];

/**
 * Los tres papeles de una tarifa, en la forma en que se congelan.
 *
 * Espejo de las seis columnas de `App\Cotizacion\Entity\CotizacionCottarifa` y de los seis
 * campos que expone `App\Travel\Entity\TravelTarifa` — los mismos nombres a propósito: la
 * copia va de una a la otra sin traducir, y que coincidan es lo que hace obvio de dónde sale
 * cada cosa.
 */
export interface PapelesDeTarifa {
    prestadorMaestroId: string | null;
    prestadorNombreSnapshot: string | null;
    prestadorServicioMaestroId: string | null;
    prestadorServicioNombreSnapshot: string | null;
    compradorMaestroId: string | null;
    compradorNombreSnapshot: string | null;
}

export type ComponenteCatalogo = Componente | ComponentePlaceholder;

// Los papeles (`prestador`, `prestadorServicio`, `comprador`) NO se redeclaran: el schema ya
// los da como `string | null` (IRI) y no requeridos, y al lado viajan sus nombres planos
// (`prestadorNombre`…). Repetirlos era ruido — y del tipo que envejece mal, porque una
// redeclaración sobrevive intacta a que el backend cambie la forma del campo.
export type Tarifa = Omit<TarifaBase, 'moneda' | 'titulo'> & {
    moneda: MaestroMoneda;
    titulo: I18nContent[];
    tarifaId: string;
    etiquetaOpciones: string;
    '@id'?: string;
};

type CotSegmentoBase = components["schemas"]["CotizacionSegmento-cotizacion.read_timestamp.read"];

export interface NotaSnapshot {
    id: string;
    tipo: string;
    titulo: I18nContent[];
    contenido: I18nContent[];
    nombreInterno?: string;
}

export interface ImagenSnapshot {
    '@id'?: string;
    '@type'?: string;
    orden: number;
    imageUrl: string;
    imageName: string;
    imageSize: number;
    isPortada: boolean;
}

export type CotSegmento = Omit<
    CotSegmentoBase,
    'id' |
    'fechaAbsoluta' |
    'sobreescribirTraduccion' |
    'nombreSnapshot' |       // <-- Agrégalo aquí
    'contenidoSnapshot' |    // <-- Agrégalo aquí
    'imagenesSnapshot' |     // <-- Agrégalo aquí
    'notasSnapshot'          // <-- Agrégalo aquí
> & {
    id: string;
    fechaAbsoluta: string;
    sobreescribirTraduccion: boolean;
    dia: number;
    orden: number;
    segmentoMaestroId?: string | null;
    nombreSnapshot?: I18nContent[];
    contenidoSnapshot?: I18nContent[];
    imagenesSnapshot?: ImagenSnapshot[];
    notasSnapshot?: NotaSnapshot[];
    '@id'?: string;
};

/**
 * Identidad mínima de cualquier recurso de API Platform: la IRI (`@id`) y/o el
 * `id`. Es lo único que hace falta para resolver identificadores, y evita tener
 * que castear a `any` cada vez que se compara un maestro con su snapshot.
 *
 * `tarifaId` va aquí porque las tarifas locales del editor arrastran el id del
 * maestro en esa clave (ver `Tarifa`), y `extractIdStr()` la contempla.
 */
export interface RecursoHydra {
    id?: string | null;
    '@id'?: string;
    tarifaId?: string | null;
}

export interface ComponenteTipo {
    id: string;
    sinHorario: boolean;
    prioridad: number;
}

export interface OrganizacionServicioOption {
    id: string;
    nombre: string;
    proveedorId: string;
}

export interface ComponentePlaceholder {
    id: string;
    nombre: string;
    '@id'?: string;
}

export interface Catalogos {
    servicios: Servicio[];
    allComponentes: (Componente | ComponentePlaceholder)[];
    componentes: (Componente | ComponentePlaceholder)[];
    tarifas: Tarifa[];
    plantillasItinerario: Itinerario[];
    poolSegmentos: Segmento[];
    proveedores: Organizacion[];
    proveedorServicios: OrganizacionServicioOption[];
    tiposComponente: ComponenteTipo[];
    monedas: MaestroMoneda[];
}


export interface TarifaSnapshot {
    id: string;
    tarifaMaestraId: string | null;
    tituloSnapshot: I18nContent[];
    nombreInternoSnapshot: string | null;
    cantidad: number;
    moneda: string;
    montoCosto: number | string;
    esGrupal: boolean;
    rolSnapshot: TarifaRolValue;
    grupoTarifa: number | null;
    comisionOverrideSnapshot: number | string | null;
    notaRol: I18nContent[];
    modalidadSnapshot: TarifaModalidadValue | null;
    categoriaSnapshot: TarifaCategoriaValue | null;
    procedenciaSnapshot: TarifaProcedenciaValue | null;
    edadMinimaSnapshot: number | null;
    edadMaximaSnapshot: number | null;
    capacidadMinimaSnapshot?: number | null;
    capacidadMaximaSnapshot?: number | null;
    /**
     * Cómo llama el PRESTADOR a esta tarifa, para el requerimiento que se le manda.
     *
     * Vacío = lo llama igual que nosotros. Ver `resolverDescripcion()` en PHP.
     */
    nombreParaProveedorSnapshot?: string | null;

    /**
     * DE QUIÉN ERA ESTE PRECIO, congelado.
     *
     * ⚠️ **Estuvo, se quitó «porque nadie lo leía», y volvió.** Al subir el prestador de la
     * tarifa al componente (`Version20260816240000`) se retiró también de aquí, y con eso se
     * perdió el único sitio donde constaba a qué empresa se le compró ESTA línea. El motivo de
     * aquel cambio era no llenar el formulario de campos —bueno—, pero el snapshot ya congela
     * las otras doce cosas de la tarifa: omitir justo ésta no ahorraba nada.
     *
     * Importa porque una línea puede acabar con tarifas de componentes distintos —el editor lo
     * avisa y lo deja pasar a propósito— y entonces «el prestador del componente» ya no dice de
     * quién era cada precio.
     *
     * ⚠️ **La fuente es la TARIFA maestra**, desde el 20/08/2026: el prestador dejó de vivir en
     * el componente porque un componente puede tener tarifas de empresas distintas. Espejo de
     * `TravelTarifa::$prestador` (ver `docs/Travel.md` §11).
     *
     * Es una referencia histórica, no un campo del formulario: se rellena solo al elegir la
     * tarifa y no se edita a mano.
     */
    prestadorMaestroId?: string | null;
    prestadorNombreSnapshot?: string | null;

    /** El servicio contratado (ej. el tipo de habitación). Espejo de `TravelTarifa::$prestadorServicio`. */
    prestadorServicioMaestroId?: string | null;
    prestadorServicioNombreSnapshot?: string | null;

    /**
     * A quién se le encargó la compra. **Vacío = se le compra al prestador**, que es el caso
     * normal — no es un olvido. Espejo de `TravelTarifa::$comprador`.
     *
     * Importa porque la Orden de Servicio sale a nombre del comprador, no del prestador.
     */
    compradorMaestroId?: string | null;
    compradorNombreSnapshot?: string | null;

    sobreescribirTraduccion: boolean;
}

export interface SnapshotItem {
    id: string;
    nombreSnapshot: I18nContent[];
    modo: string;
    modoOriginal: string;
    incluido: boolean;
    tieneUpsell: boolean;
    componenteAdicionalVinculado: string | Componente | null;
    idComponenteInyectado: string | null;
    isInjecting: boolean;
    sobreescribirTraduccion: boolean;
    cantidad?: number;
    montoCosto?: number;
    tituloTarifaVisible: boolean;
    categoriaTarifaVisible: boolean;
    modalidadTarifaVisible: boolean;
}

type CotComponenteBase = components["schemas"]["CotizacionCotcomponente-cotizacion.read_timestamp.read"];

export type ComponenteCompleto = Omit<CotComponenteBase,
    'id' | 'nombreSnapshot' | 'estado' | 'modo' | 'fechaHoraInicio' | 'fechaHoraFin'
    | 'snapshotItems' | 'cottarifas' | 'detallesOperativos' | 'cotsegmento'
    | 'prestadorTituloSnapshot' | 'prestadorImagenesSnapshot'
> & {
    id: string;
    nombreSnapshot: I18nContent[];
    estado: string;
    modo: string;
    fechaHoraInicio: string;
    fechaHoraFin: string;
    snapshotItems: SnapshotItem[];
    cottarifas: TarifaSnapshot[];
    detallesOperativos: DetalleOperativoBloque[];
    cotsegmento?: string | CotSegmento | null;
    sobreescribirTraduccion: boolean;
    duracion?: string | number;
    cotsegmentoId?: string | null;
    upsellSourceItemId?: string;
    /** La hora del componente representa el horario de toda la excursión (servicio completo). */
    horaServicioCompleto?: boolean;
    /**
     * PRESTADOR — enlace y nombre histórico, nada más.
     *
     * `Organizacion` es la entidad maestra; esto es el papel que juega aquí. Título, url,
     * imágenes y contacto NO se guardan: se resuelven contra el catálogo al servir y al
     * mandar la orden. Lo que queda escrito es la degradación — si borran la empresa, el
     * uuid y el nombre son lo único que sobrevive, y con eso la propuesta antigua sigue
     * contando quién prestó el servicio.
     *
     * Filtrar es por `prestadorMaestroId` y sólo por ahí: antes la misma empresa estaba en
     * trece columnas que podían discrepar entre sí.
     */
    prestadorMaestroId?: string | null;
    prestadorNombreSnapshot?: string | null;
    prestadorServicioMaestroId?: string | null;
    prestadorServicioNombreSnapshot?: string | null;

    /**
     * COMPRADOR — a quién se le encarga ejecutar la compra. Sin cara pública: nunca sale
     * a la vista del cliente. Vacío = se le pide al propio prestador.
     */
    compradorMaestroId?: string | null;
    compradorNombreSnapshot?: string | null;
};

export type SegmentoComponenteProcesado = components['schemas']['TravelSegmentoComponente-segmento.item.read'] & {
    tempCompObj?: Componente | components['schemas']['Componente-componente.item.read'];
    esPrioritario?: boolean;
    tarifaId?: string | null;
};

/**
 * A quién se le enseña un detalle del componente.
 *
 * Espejo de `App\Cotizacion\Enum\AudienciaDetalleEnum` — ahí está el porqué de que sean
 * banderas y no un tipo, y por qué la audiencia de casa se llama `interno` y no `operador`
 * (en turismo «operador» es muchas veces una agencia de fuera). **Al tocar una, tocar la otra.**
 */
export const AudienciaDetalle = {
    CLIENTE: 'cliente',
    INTERNO: 'interno',
    PRESTADOR: 'prestador',
} as const;
export type AudienciaDetalle = typeof AudienciaDetalle[keyof typeof AudienciaDetalle];

/** Cada audiencia existe porque hay un documento que la recibe. */
export const AUDIENCIA_DETALLE_CONFIG: Record<AudienciaDetalle, { label: string; documento: string; icon: string }> = {
    cliente:   { label: 'Cliente',   documento: 'Cotización y app del pasajero', icon: 'fa-user' },
    interno:   { label: 'Interno',   documento: 'La Biblia',                     icon: 'fa-lock' },
    prestador: { label: 'Prestador', documento: 'Orden de Servicio',             icon: 'fa-truck' },
};

export interface DetalleOperativoBloque {
    id: string;
    /**
     * Nunca vacío: un detalle sin audiencia no lo lee nadie, y el backend lo rechaza.
     *
     * ⚠️ Sin `| string` a propósito. Estaba así y una unión con `string` **colapsa el tipo
     * entero**: la unión cerrada de la izquierda no tipaba nada y cualquier cadena pasaba.
     */
    audiencias: AudienciaDetalle[];
    detalle: I18nContent[];
}

export type TarifaRolValue = TarifaBase['rol'];

export const ROL_TARIFA_CONFIG: Record<TarifaRolValue, EstadoUIConfig> = {
    estandar:    { label: 'Estándar',    bg: 'bg-blue-50',   text: 'text-blue-700',   border: 'border-blue-200',  icon: 'fa-star' },
    operativo:   { label: 'Operativo',   bg: 'bg-slate-100', text: 'text-slate-500',  border: 'border-slate-200', icon: 'fa-wrench' },
    alternativa: { label: 'Alternativa', bg: 'bg-purple-50', text: 'text-purple-700', border: 'border-purple-200', icon: 'fa-right-left' },
};

export type EstadoFile = CotizacionFileBase['estado'];

export const ESTADO_FILE_LABELS: Record<EstadoFile, string> = {
    abierto: 'Abierto',
    cerrado: 'Cerrado (Ganado)',
    archivado: 'Archivado (no venta)',
};

export const getRolTarifaUI = (rol?: string | null): EstadoUIConfig =>
    ROL_TARIFA_CONFIG[(rol as TarifaRolValue) || 'estandar'] || ROL_TARIFA_CONFIG.estandar;

/**
 * Etiqueta consistente para el grupo de tarifas dentro de un componente.
 *
 * Grupo 1 es siempre el estándar. La numeración depende de si el componente
 * tiene una tarifa estándar (rol 'estandar'):
 *  - hayEstandar:  g===1 → "Estándar", g>1 → "Alternativa (g-1)"
 *  - !hayEstandar: g      → "Opción g"  (todo el componente es opcional)
 *
 * Blindaje req 4: las operativas tienen grupoTarifa nulo en BD, por eso se
 * normaliza con `?? 0` para no romper el agrupamiento.
 */
export type TipoGrupoTarifa = 'estandar' | 'alternativa' | 'opcion';

export interface EtiquetaGrupoTarifa {
    label: string;
    tipo: TipoGrupoTarifa;
    indice: number;
}

export const etiquetaGrupoTarifa = (
    grupo: number | null | undefined,
    hayEstandar: boolean
): EtiquetaGrupoTarifa => {
    const g = grupo ?? 0;
    if (!hayEstandar) {
        return { label: `Opción ${g}`, tipo: 'opcion', indice: g };
    }
    if (g <= 1) {
        return { label: 'Estándar', tipo: 'estandar', indice: 0 };
    }
    return { label: `Alternativa ${g - 1}`, tipo: 'alternativa', indice: g - 1 };
};

export type NivelInspector = 'resumen' | 'servicio' | 'componente' | 'tarifa';

/**
 * Nodo del árbol que el inspector tiene abierto. Cuál de los cuatro es lo dice
 * `inspectorActivo`: el store asigna nivel y nodo SIEMPRE juntos (ver
 * `abrirNivel()`), así que el nivel es un discriminante fiable.
 *
 * Para leerlo con tipos usa los accesores del store (`servicioActivo`,
 * `componenteActivo`, `tarifaActiva`), que devuelven null si el inspector está
 * en otro nivel, en vez de tocar `dataActiva` a pelo.
 */
export type NodoInspector = Cotizacion | CotServicio | ComponenteCompleto | TarifaSnapshot;

export type ModoFinanciero = 'incluido' | 'no_incluido' | 'cortesia';

export interface TotalesVenta {
    ventaSoles: number;
    ventaDolares: number;
}

export interface TotalesInternos extends TotalesVenta {
    costoSoles: number;
    costoDolares: number;
    gananciaSoles: number;
    gananciaDolares: number;
}

export const totalesInternosVacios = (): TotalesInternos =>
    ({ costoSoles: 0, costoDolares: 0, ventaSoles: 0, ventaDolares: 0, gananciaSoles: 0, gananciaDolares: 0 });

// ── Detalle por clase (montos POR PAX) ──────────────────────────────────────

export interface LineaDetalleClaseCliente {
    // ⚠️ AQUÍ NO VA EL COSTO. La versión interna tiene `montoCosto`; ésta es la que se sirve al
    // huésped, y el costo de proveedor es exactamente lo que no puede ver. Si algún día hace
    // falta un importe por línea aquí, será una VENTA —ya están `ventaSoles` y `ventaDolares`—.
    moneda: string;
    esGrupal: boolean;
    cantidad: number;
    cantidadComponente: number;
    modo: ModoFinanciero;
    fecha: string;
    modalidad: TarifaModalidadValue | null;
    categoria: TarifaCategoriaValue | null;
    // Restricciones de clasificación de la tarifa (badges). null = sin restricción.
    procedencia: TarifaProcedenciaValue | null;
    edadMin: number | null;
    edadMax: number | null;
    rol: TarifaRolValue;
    notaRol: I18nContent[];
    tarifaTitulo: I18nContent[];
    componenteNombre: I18nContent[];
    servicioId: string;
    servicioNombre: I18nContent[];
    ventaSoles: number;
    ventaDolares: number;
}

export interface LineaDetalleClaseInterna extends LineaDetalleClaseCliente {
    /**
     * Lo que le cuesta al negocio esta línea. **Interno.**
     *
     * ⚠️ Se llamaba `montoCotizado` y vivía en `LineaDetalleClaseCliente` —la interfaz BASE—.
     * Dos errores que se sumaron: el nombre se lee como «lo que se le cotiza al cliente», y la
     * herencia va al revés de lo seguro (el contrato del cliente es la base, así que **todo lo
     * que se declare sale publicado salvo que alguien se acuerde de ponerlo aquí**). Al montar
     * la lista blanca de `expurgarParaCliente()`, alguien leyó «monto cotizado» y lo dio por
     * dato del cliente: las 10 cotizaciones de producción salieron con el costo de proveedor
     * dentro del JSON del huésped.
     *
     * Con el nombre honesto y en esta interfaz, publicarlo deja de ser un despiste.
     */
    montoCosto: string;
    costoSoles: number;
    costoDolares: number;
    comisionAplicada: number;
    comisionOverride: string | null;
    tarifaMaestraId: string | null;
    nombreInterno: string | null;
}

// ── Clases de pasajero ───────────────────────────────────────────────────────

export interface ClasePasajeroCliente {
    tipo: string;
    tipoPaxNombre: string;
    cantidad: number;
    edadMin: number;
    edadMax: number;
    detalle: LineaDetalleClaseCliente[];
    resumenPorModo: { normal: TotalesVenta; ctaPax: TotalesVenta; cortesia: TotalesVenta };
    resumen: { ventaDolares: number };
}

export interface ClasePasajeroInterna extends Omit<ClasePasajeroCliente, 'detalle' | 'resumenPorModo' | 'resumen'> {
    conflictos: string[];
    detalle: LineaDetalleClaseInterna[];
    resumenPorModo: { normal: TotalesInternos; ctaPax: TotalesInternos; cortesia: TotalesInternos };
    resumen: { montoDolares: number; ventaDolares: number; gananciaDolares: number };
}

// ── Upgrades (alternativas por componente) ───────────────────────────────────

export interface DeltaUpgradePorPerfil {
    procedencia: TarifaProcedenciaValue | null;
    edadMin: number;
    edadMax: number;
    deltaVentaPorPax: number;
}

export interface OpcionUpgradeCliente {
    componenteId: string;
    grupoTarifa: number;
    grupoLabel: string;
    esOpcion: boolean;
    componenteNombre: I18nContent[];
    servicioId: string;
    servicioNombre: I18nContent[];
    tarifaTitulo: I18nContent[];
    notaRol: I18nContent[];
    modalidad: TarifaModalidadValue | null;
    categoria: TarifaCategoriaValue | null;
    // Restricciones de clasificación de la tarifa alternativa (badges).
    procedencia: TarifaProcedenciaValue | null;
    edadMin: number | null;
    edadMax: number | null;
    // Tarifa estándar espejo a la que reemplaza (datos PÚBLICOS: título + mod/cat).
    // En cliente ya vienen gateados por los flags de visibilidad del componente.
    tieneEstandarEspejo: boolean;
    estandarTitulo: I18nContent[];
    estandarModalidad: TarifaModalidadValue | null;
    estandarCategoria: TarifaCategoriaValue | null;
    deltaVentaPorPax: number;
    deltasPorPerfil: DeltaUpgradePorPerfil[];
    deltaVentaTotal: number;
}

export interface OpcionUpgradeInterna extends OpcionUpgradeCliente {
    // Nombre interno de la tarifa alternativa: en vistas internas es el fallback
    // cuando el componente contenedor no tiene nombre propio (evita caer al título
    // del segmento, que en contenedores multisegmento se ve genérico/raro).
    tarifaNombreInterno: string | null;
    // Nombre INTERNO del componente (siempre presente vía maestro). En vistas
    // internas se antepone al nombre de la tarifa: "Componente · Tarifa", porque
    // el nombre de tarifa suele ser genérico y la misma tarifa cae en varios
    // componentes. NO se expone al cliente.
    componenteNombreInterno: string | null;
    // Nombre interno de la estándar reemplazada (editor). NO se expone al cliente.
    estandarNombreInterno: string | null;
    // ── Insumos client-safe para expurgarParaCliente (no se muestran en interno) ──
    // Título público del componente para el cliente: nombreSnapshot o, si falta,
    // los primeros 3 ítems incluidos. Nunca nombre interno ni segmento.
    componenteNombreCliente: I18nContent[];
    // Herencia tarifa→ítems gateada (permisiva: algún ítem lo permite).
    mostrarTituloCliente: boolean;
    mostrarModalidadCliente: boolean;
    mostrarCategoriaCliente: boolean;
    tarifaMaestraId: string | null;
    ventaPorPaxEstandar: number;
    ventaPorPaxAlternativa: number;
    deltaCostoPorPax: number;
    comisionAplicada: number;
    comisionOverride: string | null;
}

// ── Inclusiones (líneas aplanadas, vista "Incluye / No incluye") ─────────────

export interface InclusionTarifa {
    tarifaTitulo: I18nContent[];
    cantidad: number;
    esGrupal: boolean;
    modalidad: TarifaModalidadValue | null;
    categoria: TarifaCategoriaValue | null;
    procedencia: TarifaProcedenciaValue | null;
    edadMin: number | null;
    edadMax: number | null;
    rol: TarifaRolValue;
    notaRol: I18nContent[];
    montoCotizado: string | null;
    moneda: string | null;
}

export interface InclusionLinea {
    origen: 'componente' | 'item';
    modo: ModoFinanciero | 'opcional';
    nombre: I18nContent[];
    /** Número de opción (req 3) para componentes opcionales sin estándar.
     *  El texto "Opción N" se compone en cada vista para respetar el idioma. */
    grupoOpcion?: number;
    fecha: string;
    cantidadComponente: number;
    modalidad: TarifaModalidadValue | null;
    categoria: TarifaCategoriaValue | null;
    procedencia: TarifaProcedenciaValue | null;
    edadMin: number | null;
    edadMax: number | null;
    tarifaTitulo: I18nContent[];
    tarifas: InclusionTarifa[];
    /**
     * Prestador de referencia — SOLO en líneas `no_incluido` de origen componente.
     *
     * Es el hotel o el vuelo que el pasajero contrató por su cuenta. Convierte un
     * «no incluye alojamiento» en «Alojamiento, por su cuenta — Casa Andina», que es
     * la diferencia entre una lista de carencias y un itinerario completo.
     *
     * Sólo la cara pública: el nombre comercial, el teléfono y la dirección son
     * operativos y no salen del backend en `pax_cotizacion:read`. Espejo de
     * `CotizacionCotcomponente::resolverPrestador()` (ver `resolverPrestador()` en
     * `cotizacionEditorStore.ts`).
     */
    /**
     * Nombre histórico del prestador. La ficha —título, url, fotos— NO viaja aquí: pax la
     * hidrata en lote contra el catálogo con el id del componente, así no se repite por
     * cada línea. Esto es lo que se ve si la empresa ya no existe.
     */
    prestadorNombre?: string | null;

    /**
     * Id del componente del que salió esta línea.
     *
     * Es el ÚNICO enlace fiable entre el snapshot financiero y el árbol vivo. Antes pax
     * reconstruía el vínculo con una clave natural —`servicioId::títuloDeTarifa`— y dos
     * tarifas homónimas del mismo día colisionaban en silencio, pintando el proveedor
     * equivocado. Medido sobre datos reales: `(servicio, nombre)` ya colisionaba 6 veces.
     *
     * Con el id, pax busca el componente vivo y lee de ahí el proveedor, que el backend
     * resuelve contra el catálogo maestro al servir. Por eso el proveedor NO viaja en la
     * línea: sería una foto, y lo que se quiere es que esté vivo.
     */
    componenteId?: string;
}

export interface InclusionServicio {
    servicioId: string;
    servicioNombre: I18nContent[];
    incluidos: InclusionLinea[];
    noIncluidos: InclusionLinea[];
    cortesias: InclusionLinea[];
    opcionales: InclusionLinea[];
}

// ── Raíz ─────────────────────────────────────────────────────────────────────

export interface ClasificacionFinancieraCliente {
    schemaVersion: number;
    generatedAt: string;
    numPax: number;
    tipoCambio: number;
    precioOculto: boolean;
    totalVentaBruta: number;
    montoAdelanto: number;
    resumenGeneral: { incluido: TotalesVenta; noIncluido: TotalesVenta; cortesia: TotalesVenta };
    clasesPasajeros: ClasePasajeroCliente[];
    opcionesUpgrade: OpcionUpgradeCliente[];
    inclusiones: InclusionServicio[];
}

export interface ClasificacionFinancieraInterna extends Omit<ClasificacionFinancieraCliente,
    'resumenGeneral' | 'clasesPasajeros' | 'opcionesUpgrade'> {
    totalCostoNeto: number;
    ganancia: number;
    comisionGlobal: number;
    resumenGeneral: { incluido: TotalesInternos; noIncluido: TotalesInternos; cortesia: TotalesInternos };
    clasesPasajeros: ClasePasajeroInterna[];
    opcionesUpgrade: OpcionUpgradeInterna[];
    advertencias: string[];
    publicable: boolean;
}

export const CLASIFICACION_SCHEMA_VERSION = 2;

// ── Expurgador tipado Interna → Cliente ─────────────────────────────────────

const r2 = (v: number): number => Math.round(v * 100) / 100;
const ventaDe = (t: TotalesVenta): TotalesVenta => ({ ventaSoles: r2(t.ventaSoles), ventaDolares: r2(t.ventaDolares) });

export function expurgarParaCliente(fin: ClasificacionFinancieraInterna): ClasificacionFinancieraCliente {
    return {
        schemaVersion: fin.schemaVersion,
        generatedAt: fin.generatedAt,
        numPax: fin.numPax,
        tipoCambio: fin.tipoCambio,
        precioOculto: fin.precioOculto,
        totalVentaBruta: r2(fin.totalVentaBruta),
        montoAdelanto: r2(fin.montoAdelanto),
        resumenGeneral: {
            incluido: ventaDe(fin.resumenGeneral.incluido),
            noIncluido: ventaDe(fin.resumenGeneral.noIncluido),
            cortesia: ventaDe(fin.resumenGeneral.cortesia)
        },
        clasesPasajeros: fin.clasesPasajeros.map((c): ClasePasajeroCliente => ({
            tipo: c.tipo,
            tipoPaxNombre: c.tipoPaxNombre,
            cantidad: c.cantidad,
            edadMin: c.edadMin,
            edadMax: c.edadMax,
            detalle: c.detalle
                .filter((d) => d.rol !== 'operativo')
                .map((d): LineaDetalleClaseCliente => ({
                    // `montoCosto` NO se copia: es el costo de proveedor. Ver la interfaz.
                    moneda: d.moneda,
                    esGrupal: d.esGrupal,
                    cantidad: d.cantidad,
                    cantidadComponente: d.cantidadComponente,
                    modo: d.modo,
                    fecha: d.fecha,
                    modalidad: d.modalidad,
                    categoria: d.categoria,
                    procedencia: d.procedencia,
                    edadMin: d.edadMin,
                    edadMax: d.edadMax,
                    rol: d.rol,
                    notaRol: d.notaRol,
                    tarifaTitulo: d.tarifaTitulo,
                    componenteNombre: d.componenteNombre,
                    servicioId: d.servicioId,
                    servicioNombre: d.servicioNombre,
                    ventaSoles: r2(d.ventaSoles),
                    ventaDolares: r2(d.ventaDolares)
                })),
            resumenPorModo: {
                normal: ventaDe(c.resumenPorModo.normal),
                ctaPax: ventaDe(c.resumenPorModo.ctaPax),
                cortesia: ventaDe(c.resumenPorModo.cortesia)
            },
            resumen: { ventaDolares: r2(c.resumen.ventaDolares) }
        })),
        opcionesUpgrade: fin.opcionesUpgrade.map((o): OpcionUpgradeCliente => ({
            componenteId: o.componenteId,
            grupoTarifa: o.grupoTarifa,
            grupoLabel: o.grupoLabel,
            esOpcion: o.esOpcion,
            // Título PÚBLICO (nombreSnapshot o primeros ítems), nunca nombre interno.
            componenteNombre: o.componenteNombreCliente,
            servicioId: o.servicioId,
            servicioNombre: o.servicioNombre,
            // Título real de tarifa gateado por tituloTarifaVisible (antes se mandaba
            // notaRol por error). Si no es visible, vacío.
            tarifaTitulo: o.mostrarTituloCliente ? o.tarifaTitulo : [],
            notaRol: o.notaRol,
            modalidad: o.mostrarModalidadCliente ? o.modalidad : null,
            categoria: o.mostrarCategoriaCliente ? o.categoria : null,
            // Procedencia/edad: se exponen junto con la categoría (misma puerta).
            procedencia: o.mostrarCategoriaCliente ? o.procedencia : null,
            edadMin: o.mostrarCategoriaCliente ? o.edadMin : null,
            edadMax: o.mostrarCategoriaCliente ? o.edadMax : null,
            // Estándar reemplazada, solo datos públicos y gateados.
            tieneEstandarEspejo: o.tieneEstandarEspejo,
            estandarTitulo: o.mostrarTituloCliente ? o.estandarTitulo : [],
            estandarModalidad: o.mostrarModalidadCliente ? o.estandarModalidad : null,
            estandarCategoria: o.mostrarCategoriaCliente ? o.estandarCategoria : null,
            deltaVentaPorPax: r2(o.deltaVentaPorPax),
            deltasPorPerfil: o.deltasPorPerfil.map(dp => ({ ...dp, deltaVentaPorPax: r2(dp.deltaVentaPorPax) })),
            deltaVentaTotal: r2(o.deltaVentaTotal)
        })),
        inclusiones: fin.inclusiones.map((s): InclusionServicio => ({
            ...s,
            incluidos: s.incluidos.map(limpiarMontoInclusion),
            cortesias: s.cortesias.map(limpiarMontoInclusion),
            opcionales: s.opcionales.map(limpiarMontoInclusion),
            noIncluidos: s.noIncluidos
        }))
    };
}

const limpiarMontoInclusion = (l: InclusionLinea): InclusionLinea => ({
    ...l,
    tarifas: l.tarifas.map(t => ({ ...t, montoCotizado: null, moneda: null }))
});

export const formatMontoCotizado = (l: { montoCosto: string; moneda: string; esGrupal: boolean; cantidadComponente: number }): string => {
    const prefijo = l.cantidadComponente > 1 ? `${l.cantidadComponente} x ` : '';
    const monedaLabel = l.moneda === 'PEN' ? 'Soles' : 'Dolares';
    return `${prefijo}${parseFloat(l.montoCosto).toFixed(2)} ${monedaLabel} (${l.esGrupal ? 'P' : 'U'})`;
};

export const filasResumenGeneral = (fin: ClasificacionFinancieraInterna) => ([
    { tipo: 'incluido' as const,    label: 'Incluido',    ...fin.resumenGeneral.incluido },
    { tipo: 'no_incluido' as const, label: 'No incluido', ...fin.resumenGeneral.noIncluido },
    { tipo: 'cortesia' as const,    label: 'Cortesía',    ...fin.resumenGeneral.cortesia }
]);

export const formatModCat = (modalidad?: TarifaModalidadValue | null, categoria?: TarifaCategoriaValue | null): string => {
    const partes: string[] = [];
    if (modalidad) partes.push(`Mod: ${MODALIDAD_CONFIG[modalidad]?.label || modalidad}`);
    if (categoria) partes.push(`Cat: ${CATEGORIA_CONFIG[categoria]?.label || categoria}`);
    return partes.join(' · ');
};

/** Modalidad/categoría como badges inline (mismo icono del dropdown de edición).
 *  Fuente única para las tarjetas de tarifa/alternativa en las vistas internas. */
export const modCatBadges = (
    modalidad?: TarifaModalidadValue | null,
    categoria?: TarifaCategoriaValue | null
): { icon: string; label: string; type: 'modalidad' | 'categoria' }[] => {
    const badges: { icon: string; label: string; type: 'modalidad' | 'categoria' }[] = [];
    if (modalidad && MODALIDAD_CONFIG[modalidad]) badges.push({ ...MODALIDAD_CONFIG[modalidad], type: 'modalidad' });
    if (categoria && CATEGORIA_CONFIG[categoria]) badges.push({ ...CATEGORIA_CONFIG[categoria], type: 'categoria' });
    return badges;
};

// ── Badges de clasificación (modalidad · categoría · procedencia · edad) ─────
// Superset de modCatBadges: mismo patrón visual, ampliado con procedencia y el
// rango de edad. Fuente única para las vistas internas que muestran la
// clasificación completa de una tarifa/alternativa/inclusión.
export type ClasifBadgeTipo = 'modalidad' | 'categoria' | 'procedencia' | 'edad';

export const CLASIF_BADGE_CLASE: Record<ClasifBadgeTipo, string> = {
    modalidad:   'bg-sky-50 text-sky-700 border-sky-200',
    categoria:   'bg-purple-50 text-purple-700 border-purple-200',
    procedencia: 'bg-teal-50 text-teal-700 border-teal-200',
    edad:        'bg-orange-50 text-orange-700 border-orange-200',
};

export interface ClasifBadgeData {
    modalidad?: TarifaModalidadValue | null;
    categoria?: TarifaCategoriaValue | null;
    procedencia?: TarifaProcedenciaValue | null;
    edadMin?: number | null;
    edadMax?: number | null;
}

export const clasificacionBadges = (
    o: ClasifBadgeData
): { icon: string; label: string; type: ClasifBadgeTipo }[] => {
    const badges: { icon: string; label: string; type: ClasifBadgeTipo }[] = [];
    if (o.modalidad && MODALIDAD_CONFIG[o.modalidad]) badges.push({ ...MODALIDAD_CONFIG[o.modalidad], type: 'modalidad' });
    if (o.categoria && CATEGORIA_CONFIG[o.categoria]) badges.push({ ...CATEGORIA_CONFIG[o.categoria], type: 'categoria' });
    if (o.procedencia && PROCEDENCIA_CONFIG[o.procedencia]) badges.push({ ...PROCEDENCIA_CONFIG[o.procedencia], type: 'procedencia' });
    const edad = formatRangoEdad(o.edadMin, o.edadMax);
    if (edad) badges.push({ icon: '🎂', label: edad, type: 'edad' });
    return badges;
};
// ── Dónde recoge y dónde deja cada servicio ─────────────────────────────────
//
// Espejo de la salida de `App\Cotizacion\Service\CotizacionPuntosDelServicio::paraServicio()`,
// que sirve `GET /cotizacion/user/puntos/{cotizacionId}`.
//
// ⚠️ Este endpoint NO es `ApiResource`, así que no entra en `api.d.ts` (que se genera del
// OpenAPI de API Platform) y hay que declararlo a mano — igual que `PmsLimpiadorOption` y
// compañía. Si cambias el array del servicio PHP, cambia también esto: no hay nada que lo cace.
//
// ⚠️ La REGLA de dónde empieza y termina un servicio vive SÓLO en PHP, a propósito. Aquí no se
// recalcula nada: se pinta lo que llega. El precio es que refleja lo guardado, y se prefirió a
// tener dos versiones de «cuál es el último segmento del día».

/** `sin_definir` | `alojamiento` | `fijo` — espejo de `App\Travel\Enum\PuntoModoEnum`. */
export type PuntoModoValue = 'sin_definir' | 'alojamiento' | 'fijo';

export interface PuntoExtremo {
    modo: PuntoModoValue;
    /** Ya redactado por el backend («el alojamiento del pasajero», «Plaza de Armas de Cusco»). */
    texto: string | null;
}

export interface PuntosDetalleComponente {
    componente: string;
    tipo: string;
    inicio: string | null;
    fin: string | null;
}

export interface PuntosDeServicioCot {
    /** `false` = este servicio no recoge ni deja a nadie (alojamiento, ticket, comida). NO es un hueco. */
    aplica: boolean;
    inicio: PuntoExtremo;
    fin: PuntoExtremo;
    /** `false` en un guiado: se presenta en un punto y ahí acaba su parte. */
    tieneFin: boolean;
    completo: boolean;
    faltantes: string[];
    detalle: PuntosDetalleComponente[];
}

export type PuntosPorServicio = Record<string, PuntosDeServicioCot>;
