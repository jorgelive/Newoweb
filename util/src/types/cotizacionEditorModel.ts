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
    clasificacionFinancieraCliente?: ClasificacionFinancieraCliente;
    proveedorOculto?: boolean;
};


type TarifaProcedenciaValue = NonNullable<TarifaBase['procedencia']>;

// Espejo de App\Travel\Enum\TarifaModalidadEnum
// Derivado directamente del schema OpenAPI (no a mano) para heredar la
// protección de codegen: si el backend agrega un case, esto se actualiza
// solo la próxima vez que regeneres tipos.
// Solo se copia del maestro a la cotización (modalidadSnapshot) — no se
// renderiza con label/ícono propio en el editor, por eso no tiene _CONFIG.
export type TarifaModalidadValue = NonNullable<TarifaBase['modalidad']>;

export interface ProcedenciaUIConfig {
    icon: string;
    label: string;
}

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
export type ComponenteItemModoValue = 'incluido' | 'opcional' | 'no_incluido' | 'cortesia' | 'reemplazado';

export const MODO_ITEM_CONFIG: Record<ComponenteItemModoValue, EstadoUIConfig> = {
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
    MODO_ITEM_CONFIG[(modo as ComponenteItemModoValue) || 'incluido'] || MODO_ITEM_CONFIG.incluido;

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

export type Segmento = components['schemas']['Segmento-segmento.read'];
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

export interface ClasificacionFinanciera {
    ganancia: number;
    montoAdelanto: number;
    totalCostoNeto: number;
    totalVentaBruta: number;
    clasesPasajeros: Array<{
        tipo: string;
        tipoPaxNombre: string;
        cantidad: number;
        edadMin: number;
        edadMax: number;
        conflictos: string[];
        resumen: {
            montoDolares: number;
            ventaDolares: number;
            gananciaDolares: number;
        };
    }>;
}

// 🔥 NUEVA INTERFAZ: Molde estricto para la data expuesta al cliente
export interface ClasificacionFinancieraCliente {
    montoAdelanto: number;
    totalVentaBruta: number;
    clasesPasajeros: Array<{
        tipo: string;
        tipoPaxNombre: string;
        cantidad: number;
        edadMin: number;
        edadMax: number;
        conflictos: string[];
        resumen: {
            ventaDolares: number;
        };
    }>;
}

export interface TarifaSnapshot {
    id: string;
    tarifaMaestraId: string | null;
    nombreSnapshot: I18nContent[];
    cantidad: number;
    moneda: string;
    montoCosto: number | string;
    esGrupal: boolean;
    rolSnapshot: TarifaRolValue;
    grupoTarifa: number | null;
    comisionOverrideSnapshot: number | string | null;
    notaRol: I18nContent[];
    modalidadSnapshot: string | null; // renombrado de tipoModalidadSnapshot
    procedenciaSnapshot: string | null;
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

