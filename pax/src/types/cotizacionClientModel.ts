/**
 * Modelos reducidos para el visor público (Solo Lectura).
 */

export interface I18nContent {
    content: string;
    language: string;
}

export interface ImagenSnapshot {
    '@id'?: string;
    imageUrl: string;
    imageName: string;
    isPortada: boolean;
}

export interface NotaSnapshot {
    id: string;
    tipo: string;
    titulo: I18nContent[];
    contenido: I18nContent[];
}

export interface CotSegmento {
    id: string;
    dia: number;
    orden: number;
    fechaAbsoluta: string;
    nombreSnapshot?: I18nContent[];
    contenidoSnapshot?: I18nContent[];
    imagenesSnapshot?: ImagenSnapshot[];
    notasSnapshot?: NotaSnapshot[];
}

export interface CotServicio {
    id: string;
    nombreSnapshot: I18nContent[];
    nombrePublicoSnapshot: I18nContent[];
    fechaInicioAbsoluta: string;
    cotsegmentos?: CotSegmento[];
}

export interface ClasificacionFinancieraCliente {
    totalVentaBruta: number;
    clasesPasajeros: Array<{
        tipo: string;
        tipoPaxNombre: string;
        cantidad: number;
        resumen: {
            ventaDolares: number;
        };
    }>;
}

export interface Cotizacion {
    id: string;
    version: number;
    estado: string;
    numPax: number;
    monedaGlobal: string;
    precioOculto: boolean;
    totalVenta: string;
    clasificacionFinancieraCliente?: ClasificacionFinancieraCliente;
    cotservicios: CotServicio[];
}