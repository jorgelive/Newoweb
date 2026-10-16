/**
 * Álbumes de experiencias de la web (fotos y vídeos de viajes ya operados), anclados al esquema
 * generado: espejo de `App\Front\Comun\Entity\ExperienciaAlbum` y `ExperienciaMedio`. Ver
 * docs/WebPublica.md, «Experiencias».
 *
 * Los overrides son los de siempre (CLAUDE.md): los i18n se estrechan a `I18nContent[]` (el export
 * los tipa como diccionario abierto) e `id` pasa a requerido porque un recurso LEÍDO siempre lo trae.
 */
import type { components } from '@dominio/api';
import type { I18nContent } from './cotizacionEditorModel';

type AlbumBase = components['schemas']['ExperienciaAlbum-experiencia.read_timestamp.read'];
type MedioBase = components['schemas']['ExperienciaMedio-experiencia.read_timestamp.read'];

export type ExperienciaMedio = Omit<MedioBase, 'id' | 'pie'> & {
    id: string;
    pie?: I18nContent[];
};

export type ExperienciaAlbum = Omit<AlbumBase, 'id' | 'titulo' | 'texto' | 'medios'> & {
    id: string;
    '@id'?: string;
    titulo?: I18nContent[];
    texto?: I18nContent[];
    medios?: ExperienciaMedio[];
};

/** Lo que se escribe del álbum (el grupo `experiencia:write`), con los i18n estrechados. */
export type ExperienciaAlbumWrite = Partial<Pick<ExperienciaAlbum, 'titulo' | 'texto' | 'destino' | 'fecha' | 'slug' | 'orden' | 'autorizado' | 'publicado'>>;
