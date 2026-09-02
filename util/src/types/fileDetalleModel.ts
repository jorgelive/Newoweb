import type { components } from '@/types/api';
import type { ApiIdioma, ApiPais } from '@/types/maestroModel';
import type { I18nContent, PropuestaDelFile } from "@/types/cotizacionEditorModel.ts";

// Reexportada, no redefinida: la fuente única es `cotizacionEditorModel`. Los importadores
// que ya la traían de aquí siguen funcionando, y no hay dos declaraciones que puedan divergir.
export type { I18nContent };

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
// `nombre` va en el Omit a propósito: el schema lo declara `string[]` y sin
// quitarlo la intersección da `string[] & I18nContent[]`, un tipo inservible
// (ver el aviso de §2 en docs/Cotizaciones.md).
export type ApiCotizacionFilearchivo = Omit<
    components['schemas']['CotizacionFilearchivo-file.read_file.item.read_timestamp.read'],
    'nombre'
> & {
    '@id'?: string;
    id?: string;
    nombre?: I18nContent[];
    sobreescribirTraduccion: boolean;
};

// ============================================================================
// FILE (expediente) — depende de los dos tipos anteriores
// ============================================================================
// Base correcta: el schema del Get INDIVIDUAL (item), que sí incluye
// filepasajeros/filearchivos — a diferencia del schema de colección
// (CotizacionFile.jsonld-file.read_timestamp.read), que no los trae porque
// esos campos solo llevan #[Groups(['file:item:read'])] en el entity.
type BaseApiCotizacionFile = components['schemas']['CotizacionFile.jsonld-file.read_file.item.read_timestamp.read'];

/**
 * Espejo de `App\Cotizacion\Enum\FileModoEnum`. **Al tocar una, tocar la otra.**
 *
 * Una decisión, no cinco banderas: de aquí cuelga si se pide documento, si se enseña precio por
 * persona en vez de total, y si hay padrón. Combinaciones sueltas —«padrón sí, documento no»— no
 * significarían nada y alguien tendría que decidir qué hacen.
 */
export const FILE_MODO_CONFIG: Record<string, { label: string; ayuda: string; icon: string }> = {
    estandar: {
        label: 'Estándar',
        ayuda: 'Venta normal: precio de grupo y enlace público sin identificarse.',
        icon: 'fa-user-group',
    },
    grupo: {
        label: 'Grupo / incentivo',
        ayuda: 'Colegio, empresa o promoción: padrón, precio por persona, y cada uno entra con su documento y ve sólo lo suyo.',
        icon: 'fa-school',
    },
};

/**
 * Espejo de `App\Cotizacion\Enum\PasajeroTipoEnum`. **Al tocar una, tocar la otra.**
 *
 * `alcance` es qué VE; `expuesto` es si le VEN. Son dos ejes distintos y confundirlos es la fuga:
 * el invitado no es «el que menos ve», es el que no se ve — sus gratuidades las paga la agencia y
 * el colegio no las mira.
 */
// ⚠️ `clase` lleva las clases ESCRITAS ENTERAS, no compuestas con el `color`.
//
// Tailwind lee los ficheros como texto: `bg-${color}-100` no existe para él y esa píldora saldría
// sin color en producción y CON color en desarrollo, que es la peor combinación —se ve bien
// mientras lo haces y se rompe al desplegar—. `color` se queda porque lo usa quien necesita el
// tono suelto.
export const PASAJERO_TIPO_CONFIG: Record<string, {
    label: string; alcance: string; expuesto: boolean; color: string; clase: string; punto: string;
}> = {
    supervisor:   { label: 'Supervisor',   alcance: 'Todo el expediente', expuesto: true,  color: 'indigo',
        clase: 'bg-indigo-100 text-indigo-700 border-indigo-200',   punto: 'bg-indigo-500' },
    coordinador:  { label: 'Coordinador',  alcance: 'Sus grupos',         expuesto: true,  color: 'teal',
        clase: 'bg-teal-100 text-teal-700 border-teal-200',         punto: 'bg-teal-500' },
    acompanante:  { label: 'Acompañante',  alcance: 'Sólo él',            expuesto: true,  color: 'sky',
        clase: 'bg-sky-100 text-sky-700 border-sky-200',            punto: 'bg-sky-500' },
    participante: { label: 'Participante', alcance: 'Sólo él',            expuesto: true,  color: 'slate',
        clase: 'bg-slate-100 text-slate-600 border-slate-200',      punto: 'bg-slate-400' },
    invitado:     { label: 'Invitado',     alcance: 'Sólo él',            expuesto: false, color: 'amber',
        clase: 'bg-amber-100 text-amber-800 border-amber-200',      punto: 'bg-amber-500' },
    no_participa: { label: 'No participa', alcance: 'Sólo él',            expuesto: true,  color: 'rose',
        clase: 'bg-rose-100 text-rose-700 border-rose-200',         punto: 'bg-rose-500' },
};

/** Un subgrupo del expediente: salón B, grupo 5, habitación HA13, reserva JA2CWN. */
export type ApiFileGrupo = components['schemas']['CotizacionFileGrupo-file.read_file.item.read_timestamp.read'] & {
    '@id'?: string;
};

/** Espejo de `App\Cotizacion\Enum\GrupoTipoEnum`. **Al tocar una, tocar la otra.** */
// ⚠️ El plural va escrito, no se deduce. «Habitación» + «s» da «habitacións», y «reserva aérea
// nacional» + «s» da algo peor. Es una palabra por caso, y se escribe una vez.
export const GRUPO_TIPO_LABELS: Record<string, { label: string; plural: string; icon: string; color: string }> = {
    grupo:         { label: 'Grupo',         plural: 'grupos',         icon: 'fa-people-group',    color: 'teal' },
    habitacion:    { label: 'Habitación',    plural: 'habitaciones',   icon: 'fa-bed',             color: 'amber' },
    // ⚠️ UN solo eje de vuelo. El TRAMO —«Nacional», «Cusco-Puno», «Retorno»— vive en
    // `grupo.subeje` y es texto libre, así que un multitramo no pide ningún caso nuevo. Estuvo
    // partido en dos casos de enum y era un error: convertía una etiqueta en un tipo.
    reserva_aerea: { label: 'Vuelo', plural: 'vuelos', icon: 'fa-plane-departure', color: 'sky' },
    // ⚠️ Binario: se pertenece o no, sin valor. Es lo que llena el panel de inclusiones
    // específicas de cada participante, y la lista de quién va en cada orden de servicio.
    servicio:      { label: 'Servicio',      plural: 'servicios',      icon: 'fa-circle-check',    color: 'emerald' },
};

export type ApiCotizacionFile = Omit<BaseApiCotizacionFile, 'pais' | 'idioma' | 'filepasajeros' | 'filearchivos' | 'grupos' | 'cotizaciones' | 'propuestasFechas'> & {
    '@id'?: string;
    '@type'?: string;
    id?: string;
    localizador?: string | null;
    idiomaCliente?: string;
    pais?: ApiPais | null;
    idioma?: ApiIdioma | null;
    cotizaciones?: ApiCotizacionVersion[];
    filepasajeros?: ApiCotizacionFilepasajero[];
    filearchivos?: ApiCotizacionFilearchivo[];
    grupos?: ApiFileGrupo[];
    /**
     * Tramo, estado y título de cada versión. Viene del listado admin (GetCollection), inyectado
     * por `CotizacionFileCollectionProvider`.
     *
     * ⚠️ Se estrecha porque el esquema no puede decir nada útil: `getPropuestasFechas()` devuelve
     * un `array` y OpenAPI lo exporta como diccionario abierto. El tipo real es `PropuestaDelFile`
     * —el mismo que consume el dashboard—, y **se reutiliza en vez de reescribirlo**: escrito a
     * mano aquí ya se quedó corto una vez (anunciaba `version` y `fechaInicio`, y desde el
     * 30/08/2026 viajan seis claves), lo que obligaba a castear en la plantilla.
     */
    propuestasFechas?: PropuestaDelFile[];
};

/**
 * `idiomaCliente` sale del `Omit` a propósito: el schema lo marca obligatorio
 * (no es nullable), pero la entidad lo inicializa a `'es'`
 * (`CotizacionFile::$idiomaCliente`), así que el alta desde el dashboard puede
 * no mandarlo. Se declara opcional para no obligar a inventar un valor.
 */
export type ApiCotizacionFileWrite = Omit<
    components['schemas']['CotizacionFile-file.write'],
    'idiomaCliente'
> & {
    idiomaCliente?: string;
    pais?: string | null;
    idioma?: string | null;
    email?: string | null;
    telefono?: string | null;
};


// ============================================================================
// ENUMS — espejos de los enums PHP, derivados del schema OpenAPI
// ============================================================================

// Espejo de App\Cotizacion\Enum\ArchivoTipoEnum
export type ArchivoTipoValue = NonNullable<components['schemas']['CotizacionFilearchivo']['tipoArchivo']>;

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

// Espejo de App\Enum\DocumentoTipoEnum. Sale del schema de la IDENTIFICACIÓN, que es donde vive
// ahora: el pasajero tenía un `tipodocumento` suelto y admitía uno solo. Ver docs §6.l.
export type DocumentoIdentidadValue = NonNullable<components['schemas']['CotizacionPasajeroIdentificacion']['tipo']>;

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
export type ApiCotizacionVersion = Omit<
    components['schemas']['Cotizacion.jsonld-file.read_file.item.read_timestamp.read'],
    'titulo' | 'resumen'
> & {
    '@id'?: string;
    idiomaCliente?: string;
    titulo?: I18nContent[];
    resumen?: I18nContent[];
};