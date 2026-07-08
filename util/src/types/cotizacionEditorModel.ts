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

export type MaestroMoneda = components['schemas']['MaestroMoneda-componente.item.read'] & {
    '@id'?: string;
};

export type Proveedor = components['schemas']['Proveedor-proveedor.read'] & {
    id: string;
    '@id'?: string;
};

export type Servicio = components['schemas']['Servicio-servicio.item.read'];

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

type CotizacionFileBase = components["schemas"]["CotizacionFile-file.read_file.item.read_timestamp.read"];

export type CotizacionFileExtended = Omit<CotizacionFileBase, 'cotizaciones'> & {
    id?: string | null;
    localizador?: string;
    // Forzamos a que las cotizaciones anidadas usen el tipo corregido de tu Frontend
    cotizaciones?: Cotizacion[];
};

export type Segmento = components['schemas']['Segmento-segmento.read'];
export type TarifaBase = components['schemas']['Tarifa-componente.item.read'];

export type ComponenteCatalogo = Componente | ComponentePlaceholder;

export type Tarifa = Omit<TarifaBase, 'moneda'> & {
    proveedor?: string | null;
    moneda: MaestroMoneda;
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
    tipoModalidadSnapshot: string;
    proveedorMaestroId: string | null;
    proveedorNombreSnapshot: string | null;
    proveedorTituloSnapshot?: I18nContent[];
    proveedorUrlSnapshot?: string | null;
    proveedorServicioMaestroId?: string | null;
    proveedorServicioNombreSnapshot?: string | null;
    proveedorServicioTituloSnapshot?: I18nContent[];
    proveedorServicioUrlSnapshot?: string | null;
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
    tipo?: string;
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

export type NivelInspector = 'resumen' | 'servicio' | 'componente' | 'tarifa';