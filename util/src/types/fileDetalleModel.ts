import type { components } from '@/types/api';
import type { ApiIdioma, ApiPais } from '@/types/maestroModel';
import {Language} from "@/types/cotizacionEditorModel.ts";

// ============================================================================
// PASAJERO
// ============================================================================
// Schema "plano" (sin envelope Hydra .jsonld-) del Get INDIVIDUAL de CotizacionFile.
// pais/sexo/tipodocumento ya vienen tipados correctamente en este grupo (objeto
// Pais embebido, no IRI string) — a diferencia de file.write o pax_cotizacion.read
// donde pais es un IRI de escritura.
export type ApiCotizacionFilepasajero = Omit<components['schemas']['CotizacionFilepasajero-file.read_file.item.read_timestamp.read'], 'pais'> & {
    '@id'?: string;
    id?: string;
    pais?: ApiPais | null;
};

// ============================================================================
// DOCUMENTO
// ============================================================================
export type ApiCotizacionFiledocumento = components['schemas']['CotizacionFiledocumento-file.read_file.item.read_timestamp.read'] & {
    '@id'?: string;
    id?: string;
    nombre?: I18nContent[];
    sobreescribirTraduccion: boolean;
};

// ============================================================================
// FILE (expediente) — depende de los dos tipos anteriores
// ============================================================================
// Base correcta: el schema del Get INDIVIDUAL (item), que sí incluye
// filepasajeros/filedocumentos — a diferencia del schema de colección
// (CotizacionFile.jsonld-file.read_timestamp.read), que no los trae porque
// esos campos solo llevan #[Groups(['file:item:read'])] en el entity.
type BaseApiCotizacionFile = components['schemas']['CotizacionFile.jsonld-file.read_file.item.read_timestamp.read'];

export type ApiCotizacionFile = Omit<BaseApiCotizacionFile, 'pais' | 'idioma' | 'filepasajeros' | 'filedocumentos' | 'cotizaciones' | 'versionesFechas'> & {
    '@id'?: string;
    '@type'?: string;
    id?: string;
    localizador?: string | null;
    idiomaCliente?: string;
    pais?: ApiPais | null;
    idioma?: ApiIdioma | null;
    cotizaciones?: ApiCotizacionVersion[];
    filepasajeros?: ApiCotizacionFilepasajero[];
    filedocumentos?: ApiCotizacionFiledocumento[];
    /** Fecha de primer servicio (MIN fechaInicioAbsoluta) de cada versión. Viene del listado admin (GetCollection). */
    versionesFechas?: { version: number; fechaInicio: string | null }[];
};

export type ApiCotizacionFileWrite = components['schemas']['CotizacionFile-file.write'] & {
    pais?: string | null;
    idioma?: string | null;
    email?: string | null;
    telefono?: string | null;
};

export interface I18nContent {
    content: string;
    language: Language | string; // Permitimos string para flexibilizar asignaciones literales tipo 'es'
}

// ============================================================================
// ENUMS — espejos de los enums PHP, derivados del schema OpenAPI
// ============================================================================

// Espejo de App\Cotizacion\Enum\ArchivoTipoEnum
export type ArchivoTipoValue = NonNullable<components['schemas']['CotizacionFiledocumento']['tipodocumento']>;

export const ARCHIVO_TIPO_LABELS: Record<ArchivoTipoValue, string> = {
    boleto: 'Boleto / Ticket',
    factura: 'Factura / Recibo',
    reserva: 'Confirmación de Reserva',
    otros: 'Otros Documentos',
};

export const getArchivoLabel = (val?: string | null): string =>
    ARCHIVO_TIPO_LABELS[(val as ArchivoTipoValue)] || val || 'Documento';

// Espejo del enum de sexo en App\Cotizacion\Entity\CotizacionFilepasajero
export type SexoValue = NonNullable<components['schemas']['CotizacionFilepasajero']['sexo']>;

export const SEXO_LABELS: Record<SexoValue, string> = {
    M: 'Masculino',
    F: 'Femenino',
};

export const getSexoLabel = (val?: string | null): string =>
    SEXO_LABELS[(val as SexoValue)] || val || '—';

// Espejo del enum de tipo de documento en App\Cotizacion\Entity\CotizacionFilepasajero
export type DocumentoIdentidadValue = NonNullable<components['schemas']['CotizacionFilepasajero']['tipodocumento']>;

export const DOCUMENTO_IDENTIDAD_LABELS: Record<DocumentoIdentidadValue, string> = {
    DNI: 'DNI',
    CE: 'C.E.',
    RUC: 'RUC',
    PASAPORTE: 'Pasaporte',
    CI: 'Carné de Identidad',
};

export const getDocIdLabel = (val?: string | null): string =>
    DOCUMENTO_IDENTIDAD_LABELS[(val as DocumentoIdentidadValue)] || val || '—';

// Cotizacion tal como viaja embebida en el listado de versiones del File
// (schema propio y más liviano que el Cotizacion completo del editor —
// sin cotservicios, idiomaEdicion, clasificacionFinanciera, etc.).
export type ApiCotizacionVersion = components['schemas']['Cotizacion.jsonld-file.read_file.item.read_timestamp.read'] & {
    '@id'?: string;
    idiomaCliente?: string;
    titulo?: { language?: string; content?: string }[];
};