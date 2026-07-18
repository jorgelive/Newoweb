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
 * Espejo minimalista de ProveedorImagen/ProveedorServicioImagen del backend —
 * solo lo necesario para render, sin metadatos de archivo físico (imageName, imageSize).
 */
export interface ImagenProveedorSnapshot {
    imageUrl: string | null;
    orden: number;
    isPortada: boolean;
}

export type MaestroMoneda = components['schemas']['MaestroMoneda-componente.item.read'] & {
    '@id'?: string;
};

export type Proveedor = components['schemas']['Proveedor-proveedor.read'] & {
    id: string;
    '@id'?: string;
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

export type CotServicio = Omit<CotServicioBase, 'nombreSnapshot' | 'itinerarioNombreSnapshot' | 'nombrePublicoSnapshot' | 'cotcomponentes'> & {
    nombreSnapshot: I18nContent[];
    itinerarioNombreSnapshot: I18nContent[];
    nombrePublicoSnapshot: I18nContent[];
    cotcomponentes?: ComponenteCompleto[];
    cotsegmentos?: CotSegmento[];
};

export type Cotizacion = Omit<
    components['schemas']['Cotizacion-cotizacion.read_timestamp.read'],
    'file' | 'cotservicios' | 'resumen'
> & {
    idiomaEdicion: string;
    file: { id?: string; '@id'?: string; createdAt?: string; updatedAt?: string; } | string;
    cotservicios: CotServicio[];
    resumen: I18nContent[];
    clasificacionFinanciera?: ClasificacionFinancieraInterna;
    clasificacionFinancieraCliente?: ClasificacionFinancieraCliente;
    proveedorOculto?: boolean;
};


export type TarifaProcedenciaValue = NonNullable<TarifaBase['procedencia']>;

// Espejo de App\Travel\Enum\TarifaModalidadEnum
// Derivado directamente del schema OpenAPI (no a mano) para heredar la
// protección de codegen: si el backend agrega un case, esto se actualiza
// solo la próxima vez que regeneres tipos.
// Solo se copia del maestro a la cotización (modalidadSnapshot) — no se
// renderiza con label/ícono propio en el editor, por eso no tiene _CONFIG.
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
// Cada tipo abajo debe reflejar exactamente los cases del enum PHP correspondiente.
// Si el backend agrega/quita un case, TypeScript marcará error de compilación en
// el Record correspondiente hasta que se actualice aquí — esa es la protección
// que reemplaza tener que "acordarse" de sincronizar manualmente.

export interface EstadoUIConfig {
    label: string;
    bg: string;
    text: string;
    border: string;
    icon: string;
}

// Espejo de App\Cotizacion\Enum\CotizacionEstadoEnum
type CotizacionEstadoValue = components['schemas']['Cotizacion-cotizacion.read_timestamp.read']['estado'];

export type Item = components['schemas']['TravelComponenteItem-componente.item.read'];

// Espejo de CotizacionEstadoEnum::badgeColor() + labels de UI
export const ESTADO_COTIZACION_CONFIG: Record<CotizacionEstadoValue, EstadoUIConfig> = {
    pendiente: { label: 'Pendiente', bg: 'bg-amber-50', text: 'text-amber-700', border: 'border-amber-200', icon: 'fa-clock' },
    enviado: { label: 'Enviado', bg: 'bg-sky-50', text: 'text-sky-700', border: 'border-sky-200', icon: 'fa-paper-plane' },
    archivado: { label: 'Archivado', bg: 'bg-slate-100', text: 'text-slate-500', border: 'border-slate-200', icon: 'fa-box-archive' },
    confirmado: { label: 'Confirmado', bg: 'bg-emerald-50', text: 'text-emerald-700', border: 'border-emerald-200', icon: 'fa-check' },
    operado: { label: 'Operado', bg: 'bg-blue-50', text: 'text-blue-700', border: 'border-blue-200', icon: 'fa-plane-departure' },
    cancelado: { label: 'Cancelado', bg: 'bg-rose-50', text: 'text-rose-700', border: 'border-rose-200', icon: 'fa-times-circle' },
};

// Espejo de App\Cotizacion\Enum\ComponenteItemModoEnum (a nivel de CotizacionCotcomponente.modo)
// Espejo de App\Travel\Enum\ComponenteModoEnum
export type ComponenteModoValue = 'incluido' | 'no_incluido' | 'cortesia' | 'reemplazado';

// Espejo de App\Travel\Enum\ItemModoEnum
export type ItemModoValue = 'incluido' | 'opcional' | 'no_incluido';

// Diccionario visual unificado para no alterar las llamadas en los templates de Vue
export const MODO_COMERCIAL_CONFIG: Record<ComponenteModoValue | ItemModoValue | string, EstadoUIConfig> = {
    incluido:    { label: 'Incluido',    bg: 'bg-emerald-50', text: 'text-emerald-700', border: 'border-emerald-200', icon: 'fa-check-circle' },
    opcional:    { label: 'Opcional',    bg: 'bg-amber-50',   text: 'text-amber-700',   border: 'border-amber-200',   icon: 'fa-circle-question' },
    no_incluido: { label: 'No incluido', bg: 'bg-slate-100',  text: 'text-slate-500',   border: 'border-slate-200',   icon: 'fa-ban' },
    cortesia:    { label: 'Cortesía',    bg: 'bg-sky-50',     text: 'text-sky-700',     border: 'border-sky-200',     icon: 'fa-gift' },
    reemplazado: { label: 'Reemplazado', bg: 'bg-rose-50',    text: 'text-rose-700',    border: 'border-rose-200',    icon: 'fa-rotate' },
};

// Espejo de App\Cotizacion\Enum\ComponenteEstadoEnum
export type ComponenteEstadoValue = 'pendiente' | 'confirmado' | 'reconfirmado' | 'cancelado';

export const ESTADO_COMPONENTE_CONFIG: Record<ComponenteEstadoValue, EstadoUIConfig> = {
    pendiente:    { label: 'Pendiente',    bg: 'bg-amber-50',   text: 'text-amber-700',   border: 'border-amber-200', icon: 'fa-clock' },
    confirmado:   { label: 'Confirmado',   bg: 'bg-emerald-50', text: 'text-emerald-700', border: 'border-emerald-200', icon: 'fa-check' },
    reconfirmado: { label: 'Reconfirmado', bg: 'bg-teal-50',    text: 'text-teal-700',    border: 'border-teal-200',  icon: 'fa-check-double' },
    cancelado:    { label: 'Cancelado',    bg: 'bg-red-50',     text: 'text-red-700',     border: 'border-red-200',  icon: 'fa-times-circle' },
};

// Espejo de App\Cotizacion\Enum\EstadoOperativo
export type EstadoOperativoValue = 'sin-solicitar' | 'solicitado' | 'confirmado' | 'reconfirmado' | 'pendiente-pago';

export const ESTADO_OPERATIVO_CONFIG: Record<EstadoOperativoValue, EstadoUIConfig> = {
    'sin-solicitar':  { label: 'Sin Solicitar',  bg: 'bg-slate-100', text: 'text-slate-500',  border: 'border-slate-200', icon: 'fa-circle-minus' },
    'solicitado':     { label: 'Solicitado',     bg: 'bg-amber-50',  text: 'text-amber-700',  border: 'border-amber-200', icon: 'fa-paper-plane' },
    'confirmado':     { label: 'Confirmado',     bg: 'bg-emerald-50', text: 'text-emerald-700', border: 'border-emerald-200', icon: 'fa-check' },
    'reconfirmado':   { label: 'Reconfirmado',   bg: 'bg-teal-50',   text: 'text-teal-700',   border: 'border-teal-200',  icon: 'fa-check-double' },
    'pendiente-pago': { label: 'Pendiente Pago', bg: 'bg-red-50',    text: 'text-red-700',    border: 'border-red-200',  icon: 'fa-money-bill-wave' },
};

// Helpers de acceso con fallback seguro — reemplazan el patrón `x[val] || default`
// repetido antes en cada componente Vue.
export const getModoItemConfig = (modo?: string | null): EstadoUIConfig =>
    MODO_COMERCIAL_CONFIG[modo || 'incluido'] || MODO_COMERCIAL_CONFIG.incluido;

export const getEstadoComponenteConfig = (estado?: string | null): EstadoUIConfig =>
    ESTADO_COMPONENTE_CONFIG[(estado as ComponenteEstadoValue) || 'pendiente'] || ESTADO_COMPONENTE_CONFIG.pendiente;

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
    // Forzamos a que las cotizacion anidadas usen el tipo corregido de tu Frontend
    cotizaciones?: Cotizacion[];
};

export type Segmento = components['schemas']['Segmento-segmento.item.read'];
export type TarifaBase = components['schemas']['Tarifa-componente.item.read'];

export type ComponenteCatalogo = Componente | ComponentePlaceholder;

export type Tarifa = Omit<TarifaBase, 'moneda' | 'titulo'> & {
    proveedor?: string | null;
    moneda: MaestroMoneda;
    titulo: I18nContent[];
    tarifaId: string;
    etiquetaOpciones: string;
    '@id'?: string;
};

type CotSegmentoBase = components["schemas"]["CotizacionSegmento-cotizacion.read_timestamp.read"];

// Extendemos garantizando tipado estricto para los campos requeridos por el frontend
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

export type CotSegmento = Omit<CotSegmentoBase, 'id' | 'fechaAbsoluta' | 'sobreescribirTraduccion'> & {
    id: string;
    fechaAbsoluta: string;
    sobreescribirTraduccion: boolean;
    dia: number;
    orden: number;
    nombreSnapshot?: I18nContent[];
    contenidoSnapshot?: I18nContent[];
    imagenesSnapshot?: ImagenSnapshot[];
    notasSnapshot?: NotaSnapshot[];
    '@id'?: string;
};

export interface ComponenteTipo {
    id: string;
    requiereHoraExacta: boolean;
    prioridad: number;
}

export interface ProveedorServicioOption {
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
    proveedores: Proveedor[];
    proveedorServicios: ProveedorServicioOption[];
    tiposComponente: ComponenteTipo[];
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
    proveedorMaestroId: string | null;
    proveedorNombreSnapshot: string | null;
    proveedorTituloSnapshot?: I18nContent[];
    proveedorUrlSnapshot?: string | null;
    proveedorImagenesSnapshot: ImagenProveedorSnapshot[];
    proveedorServicioMaestroId?: string | null;
    proveedorServicioNombreSnapshot?: string | null;
    proveedorServicioTituloSnapshot?: I18nContent[];
    proveedorServicioUrlSnapshot?: string | null;
    proveedorServicioImagenesSnapshot: ImagenProveedorSnapshot[];
    estadoOperativoSnapshot: string;
    fechaLimitePago: string | null;
    nombreParaProveedorSnapshot?: string | null;
    condicionesPagoSnapshot?: string | null;
    proveedorOculto: boolean;
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

export type ComponenteCompleto = Omit<
    components['schemas']['Componente-componente.item.read'],
    'cottarifas' | 'snapshotItems'
> & {
    id: string;
    componenteMaestroId?: string | null;
    nombreSnapshot: I18nContent[];
    duracion?: string | number;
    cantidad: number;
    estado: string;
    modo: string;
    fechaHoraInicio: string;
    fechaHoraFin: string;
    cotsegmentoId?: string | null;
    cotsegmento?: string | CotSegmento | null;
    snapshotItems: SnapshotItem[];
    cottarifas: TarifaSnapshot[];
    detallesOperativos: DetalleOperativoBloque[];
    upsellSourceItemId?: string;
    sobreescribirTraduccion: boolean;
};

export type SegmentoComponenteProcesado = components['schemas']['TravelSegmentoComponente-segmento.item.read'] & {
    // 👉 Propiedades volátiles de hidratación exclusivas del frontend
    tempCompObj?: ComponenteCompleto | components['schemas']['Componente-componente.item.read'];
    esPrioritario?: boolean;
    tarifaId?: string | null;
};

export const DetalleOperativoTipo = {
    CLIENTE: 'cliente',
    OPERATIVA: 'operativa',
} as const;
export type DetalleOperativoTipo = typeof DetalleOperativoTipo[keyof typeof DetalleOperativoTipo];

export interface DetalleOperativoBloque {
    id: string;
    tipo: DetalleOperativoTipo | string;
    detalle: I18nContent[];
}

export type TarifaRolValue = TarifaBase['rol'];

export const ROL_TARIFA_CONFIG: Record<TarifaRolValue, EstadoUIConfig> = {
    estandar:    { label: 'Estándar',    bg: 'bg-blue-50',   text: 'text-blue-700',   border: 'border-blue-200',  icon: 'fa-star' },
    operativo:   { label: 'Operativo',   bg: 'bg-slate-100', text: 'text-slate-500',  border: 'border-slate-200', icon: 'fa-wrench' },
    alternativa: { label: 'Alternativa', bg: 'bg-purple-50', text: 'text-purple-700', border: 'border-purple-200', icon: 'fa-right-left' },
};

export const getRolTarifaUI = (rol?: string | null): EstadoUIConfig =>
    ROL_TARIFA_CONFIG[(rol as TarifaRolValue) || 'estandar'] || ROL_TARIFA_CONFIG.estandar;

export type NivelInspector = 'resumen' | 'servicio' | 'componente' | 'tarifa';


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

const totalesVentaVacios = (): TotalesVenta => ({ ventaSoles: 0, ventaDolares: 0 });
export const totalesInternosVacios = (): TotalesInternos =>
    ({ costoSoles: 0, costoDolares: 0, ventaSoles: 0, ventaDolares: 0, gananciaSoles: 0, gananciaDolares: 0 });

// ── Detalle por clase (montos POR PAX) ──────────────────────────────────────

export interface LineaDetalleClaseCliente {
    montoCotizado: string;              // "152.00" — original, en su moneda
    moneda: string;                     // 'PEN' | 'USD'
    esGrupal: boolean;                  // (P) / (U)
    cantidad: number;                   // pax de la tarifa (1 si grupal)
    cantidadComponente: number;         // noches / multiplicador ("2 x ...")
    modo: ModoFinanciero;
    fecha: string;                      // YYYY-MM-DD del componente
    modalidad: TarifaModalidadValue | null;
    categoria: TarifaCategoriaValue | null;
    rol: TarifaRolValue;
    notaRol: I18nContent[];
    tarifaTitulo: I18nContent[];
    componenteNombre: I18nContent[];    // fallback: nombre del segmento
    servicioId: string;
    servicioNombre: I18nContent[];
    ventaSoles: number;                 // POR PAX (override aplicado; cortesía=0)
    ventaDolares: number;
}

export interface LineaDetalleClaseInterna extends LineaDetalleClaseCliente {
    costoSoles: number;                 // POR PAX
    costoDolares: number;
    comisionAplicada: number;           // % efectivo de la línea
    comisionOverride: string | null;    // override crudo (null = usó global)
    tarifaMaestraId: string | null;
    nombreInterno: string | null;       // nombreInternoSnapshot
}

// ── Clases de pasajero ───────────────────────────────────────────────────────

export interface ClasePasajeroCliente {
    tipo: string;
    tipoPaxNombre: string;
    cantidad: number;
    edadMin: number;
    edadMax: number;
    detalle: LineaDetalleClaseCliente[];
    resumenPorModo: { normal: TotalesVenta; ctaPax: TotalesVenta; cortesia: TotalesVenta };  // POR PAX
    resumen: { ventaDolares: number };  // clase completa, solo incluido
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
    deltaVentaPorPax: number;           // USD
}

export interface OpcionUpgradeCliente {
    componenteId: string;
    grupoTarifa: number;
    componenteNombre: I18nContent[];
    servicioId: string;
    servicioNombre: I18nContent[];
    tarifaTitulo: I18nContent[];
    notaRol: I18nContent[];
    modalidad: TarifaModalidadValue | null;
    categoria: TarifaCategoriaValue | null;
    deltaVentaPorPax: number;           // USD, promedio ponderado (cifra única de UI)
    deltasPorPerfil: DeltaUpgradePorPerfil[];   // exacto por procedencia/edad (grupos simétricos)
    deltaVentaTotal: number;            // USD
}

export interface OpcionUpgradeInterna extends OpcionUpgradeCliente {
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
    rol: TarifaRolValue;
    notaRol: I18nContent[];
    montoCotizado: string | null;       // interna: siempre; cliente: solo no_incluido
    moneda: string | null;
}

export interface InclusionLinea {
    origen: 'componente' | 'item';
    modo: ModoFinanciero | 'opcional';  // los items pueden ser opcionales
    nombre: I18nContent[];
    fecha: string;
    cantidadComponente: number;         // "x 2 noches"
    // Herencia condicional (items: gobernada por flags; componentes: de su tarifa estándar)
    modalidad: TarifaModalidadValue | null;
    categoria: TarifaCategoriaValue | null;
    tarifaTitulo: I18nContent[];        // items: solo si tituloTarifaVisible
    tarifas: InclusionTarifa[];         // items: siempre []
}

export interface InclusionServicio {
    servicioId: string;
    servicioNombre: I18nContent[];
    incluidos: InclusionLinea[];
    noIncluidos: InclusionLinea[];
    cortesias: InclusionLinea[];
    opcionales: InclusionLinea[];       // items modo 'opcional' (upsells no activados)
}

// ── Raíz ─────────────────────────────────────────────────────────────────────

export interface ClasificacionFinancieraCliente {
    schemaVersion: number;
    generatedAt: string;
    numPax: number;
    tipoCambio: number;
    precioOculto: boolean;
    totalVentaBruta: number;            // USD, solo incluido
    montoAdelanto: number;
    resumenGeneral: { incluido: TotalesVenta; noIncluido: TotalesVenta; cortesia: TotalesVenta };
    clasesPasajeros: ClasePasajeroCliente[];
    opcionesUpgrade: OpcionUpgradeCliente[];
    inclusiones: InclusionServicio[];
}

export interface ClasificacionFinancieraInterna extends Omit<ClasificacionFinancieraCliente,
    'resumenGeneral' | 'clasesPasajeros' | 'opcionesUpgrade'> {
    totalCostoNeto: number;             // USD, solo incluido
    ganancia: number;                   // ventaIncl − costoIncl − costoCortesía
    comisionGlobal: number;
    resumenGeneral: { incluido: TotalesInternos; noIncluido: TotalesInternos; cortesia: TotalesInternos };
    clasesPasajeros: ClasePasajeroInterna[];
    opcionesUpgrade: OpcionUpgradeInterna[];
    advertencias: string[];             // simetría de grupos, pools sin modalidad, etc.
    publicable: boolean;                // sin conflictos ni advertencias bloqueantes
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
                .filter((d) => d.rol !== 'operativo')   // operativas: suman al precio, no se listan
                .map((d): LineaDetalleClaseCliente => ({
                    montoCotizado: d.montoCotizado,
                    moneda: d.moneda,
                    esGrupal: d.esGrupal,
                    cantidad: d.cantidad,
                    cantidadComponente: d.cantidadComponente,
                    modo: d.modo,
                    fecha: d.fecha,
                    modalidad: d.modalidad,
                    categoria: d.categoria,
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
            componenteNombre: o.componenteNombre,
            servicioId: o.servicioId,
            servicioNombre: o.servicioNombre,
            tarifaTitulo: o.tarifaTitulo,
            notaRol: o.notaRol,
            modalidad: o.modalidad,
            categoria: o.categoria,
            deltaVentaPorPax: r2(o.deltaVentaPorPax),
            deltasPorPerfil: o.deltasPorPerfil.map(dp => ({ ...dp, deltaVentaPorPax: r2(dp.deltaVentaPorPax) })),
            deltaVentaTotal: r2(o.deltaVentaTotal)
        })),
        inclusiones: fin.inclusiones.map((s): InclusionServicio => ({
            ...s,
            incluidos: s.incluidos.map(limpiarMontoInclusion),
            cortesias: s.cortesias.map(limpiarMontoInclusion),
            opcionales: s.opcionales.map(limpiarMontoInclusion),
            noIncluidos: s.noIncluidos          // única fuga monetaria deliberada: "(S/. 152)"
        }))
    };
}

const limpiarMontoInclusion = (l: InclusionLinea): InclusionLinea => ({
    ...l,
    tarifas: l.tarifas.map(t => ({ ...t, montoCotizado: null, moneda: null }))
});

// ── Helpers de vista (puros, para los templates) ─────────────────────────────

/** "2 x 60.00 Dolares (P)" / "152.00 Soles (U)" — columna Monto Cotizado del legacy */
export const formatMontoCotizado = (l: { montoCotizado: string; moneda: string; esGrupal: boolean; cantidadComponente: number }): string => {
    const prefijo = l.cantidadComponente > 1 ? `${l.cantidadComponente} x ` : '';
    const monedaLabel = l.moneda === 'PEN' ? 'Soles' : 'Dolares';
    return `${prefijo}${parseFloat(l.montoCotizado).toFixed(2)} ${monedaLabel} (${l.esGrupal ? 'P' : 'U'})`;
};

/** Filas de la tabla "Resumen General" (Incluido / No incluido / Cortesía) */
export const filasResumenGeneral = (fin: ClasificacionFinancieraInterna) => ([
    { tipo: 'incluido' as const,    label: 'Incluido',    ...fin.resumenGeneral.incluido },
    { tipo: 'no_incluido' as const, label: 'No incluido', ...fin.resumenGeneral.noIncluido },
    { tipo: 'cortesia' as const,    label: 'Cortesía',    ...fin.resumenGeneral.cortesia }
]);

/** Etiqueta "Mod: Privado · Cat: Superior" (omite nulos) */
export const formatModCat = (modalidad?: TarifaModalidadValue | null, categoria?: TarifaCategoriaValue | null): string => {
    const partes: string[] = [];
    if (modalidad) partes.push(`Mod: ${MODALIDAD_CONFIG[modalidad]?.label || modalidad}`);
    if (categoria) partes.push(`Cat: ${CATEGORIA_CONFIG[categoria]?.label || categoria}`);
    return partes.join(' · ');
};
