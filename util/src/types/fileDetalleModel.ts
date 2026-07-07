import type { components } from '@/types/api';
import type {ApiIdioma, ApiPais} from '@/types/maestroModel';


type BaseApiCotizacionFile = components['schemas']['CotizacionFile.jsonld-file.read_timestamp.read'];

export type ApiCotizacionFile = BaseApiCotizacionFile & {
    '@id'?: string;
    '@type'?: string;
    id?: string;
    localizador?: string | null;
    pais?: ApiPais | null;
    idioma?: ApiIdioma | null;
};

export type ApiCotizacionFileWrite = components['schemas']['CotizacionFile-file.write'] & {
    pais?: string | null;
    idioma?: string | null;
    email?: string | null;
    telefono?: string | null;
};