import { components } from '@/types/api';

export type Proveedor = components['schemas']['Proveedor-proveedor.read'] & {
    id: string;
};
export type Servicio = components['schemas']['Servicio-servicio.item.read'];
export type Componente = components['schemas']['Componente-componente.item.read'] & {
    tarifas: Tarifa[];
    snapshotItems: SnapshotItem[];
};

export type Itinerario = components['schemas']['Itinerario-itinerario.read'];


type CotServicioBase = components["schemas"]["CotizacionCotservicio-cotizacion.read_timestamp.read"];

export type CotServicio = Omit<CotServicioBase, 'nombreSnapshot' | 'itinerarioNombreSnapshot'> & {
    nombreSnapshot: I18nContent[];
    itinerarioNombreSnapshot: I18nContent[];
};

export type Cotizacion = Omit<
    components['schemas']['Cotizacion-cotizacion.read_timestamp.read'],
    'file' | 'cotservicios' // 👉 Añadimos 'cotservicios' al Omit para remover la definición de OpenAPI
> & {
    idiomaEdicion: string;
    file: { id?: string; '@id'?: string; createdAt?: string; updatedAt?: string; } | string;

    // 👉 Ahora sí, el store sabrá que este array contiene ÚNICAMENTE tus CotServicio i18n extendidos
    cotservicios: CotServicio[];
};
export type Segmento = components['schemas']['Segmento-segmento.read'];
export type TarifaBase = components['schemas']['Tarifa-componente.item.read'];

export type ComponenteCatalogo = Componente | ComponentePlaceholder;

// 2. Extensión (El "Overwrite" real)
export type Tarifa = Omit<TarifaBase, 'proveedor' | 'moneda'> & {

    proveedor: { id: string; nombreComercial: string; '@id'?: string } | null;
    moneda: { id: string; codigo: string; '@id'?: string };

    tarifaId: string;
    etiquetaOpciones: string;
    nombreParaProveedor?: string | null;
    condicionesPagoSnapshot?: string | null;
};

export interface ComponenteTipo {
    id: string;
    requiereHoraExacta: boolean;
    prioridad: number;
}

export interface ComponentePlaceholder {
    id: string;
    nombre: string;
}

export interface ComponenteLoading {
    id: string;
    nombre: string
}

export interface Catalogos {
    servicios: Servicio[];
    allComponentes: (Componente | ComponentePlaceholder)[];
    componentes: (Componente | ComponentePlaceholder)[];
    tarifas: Tarifa[];
    plantillasItinerario: Itinerario[];
    poolSegmentos: Segmento[];
    proveedores: Proveedor[];
    tiposComponente: ComponenteTipo[];
}

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
    language: Language;
}

// Esta es la estructura real que tu backend guarda en el campo JSON
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

export interface TarifaSnapshot {
    id: string;
    tarifaMaestraId: string | null; // 👉 Permitimos null para las tarifas creadas desde cero
    nombreSnapshot: I18nContent[];
    cantidad: number;
    moneda: string;
    montoCosto: number | string; // Asegúrate de que admita string si tu API lo manda así
    esGrupal: boolean;
    tipoModalidadSnapshot: string;
    proveedorMaestroId: string | null; // 👉 Permitimos null también aquí si aplica
    proveedorNombreSnapshot: string | null;
    estadoOperativoSnapshot: string;
    fechaLimitePago: string | null;

    nombreParaProveedorSnapshot?: string | null;
    condicionesPagoSnapshot?: string | null;
}

export interface SnapshotItem {
    id: string;
    nombreSnapshot: I18nContent[];
    modo: 'incluido' | 'opcional' | 'no_incluido' | 'cortesia' | 'upsell_injected';
    modoOriginal: string;
    incluido: boolean;
    tieneUpsell: boolean;
    idComponenteInyectado: string | null;
    isInjecting: boolean;
    sobreescribirTraduccion: boolean;
}

// Extensión de lo que realmente tiene un componente en tu Store
export type ComponenteCompleto = Omit<components['schemas']['Componente-componente.item.read'], 'cottarifas' | 'snapshotItems'> & {
    cantidad: number;
    estado: string;
    modo: string;
    fechaHoraInicio: string;
    fechaHoraFin: string;
    cotsegmentoId: string | null;
    snapshotItems: SnapshotItem[];
    cottarifas: TarifaSnapshot[];
    upsellSourceItemId?: string;
};

export type NivelInspector = 'resumen' | 'servicio' | 'componente' | 'tarifa';