/**
 * Los álbumes de experiencias de la web: crear, editar, publicar, y subir/ordenar sus fotos y
 * vídeos. API: `/platform/front/experiencia_albums` y `/platform/front/experiencia_medios`.
 * Ver docs/WebPublica.md, «Experiencias».
 *
 * 🔒 Publicar exige «autorizado» (permiso firmado de las familias: viajes con menores). La regla la
 * hace cumplir el servidor (`ExperienciaAlbum::validarPublicacion()`); aquí sólo se enseña su motivo.
 */
import { mensajeDeErrorApi } from '@/utils/errorApi';
import { defineStore } from 'pinia';
import { ref } from 'vue';
import { apiClient } from '@/services/apiClient';
import { miembrosHydra } from '@/services/hydra';
import type { ExperienciaAlbum, ExperienciaAlbumWrite } from '@/types/experienciaModel';
import type { I18nContent } from '@/types/cotizacionEditorModel';

const BASE_ALBUMES = '/platform/front/experiencia_albums';
const BASE_MEDIOS = '/platform/front/experiencia_medios';
const MERGE = { headers: { 'Content-Type': 'application/merge-patch+json' } };

export const useExperienciaStore = defineStore('experiencias', () => {
    const albumes = ref<ExperienciaAlbum[]>([]);
    const cargando = ref(false);
    const error = ref<string | null>(null);

    const cargar = async (): Promise<void> => {
        cargando.value = true;
        error.value = null;
        try {
            const { data } = await apiClient.get(BASE_ALBUMES, { params: { pagination: false } });
            albumes.value = miembrosHydra<ExperienciaAlbum>(data);
        } catch (e) {
            error.value = mensajeDeErrorApi(e, 'No se pudieron cargar los álbumes.');
        } finally {
            cargando.value = false;
        }
    };

    /** Sustituye el álbum en la lista por la versión que devolvió el servidor. */
    const reemplazar = (album: ExperienciaAlbum): ExperienciaAlbum => {
        const i = albumes.value.findIndex((a) => a.id === album.id);
        if (i >= 0) albumes.value.splice(i, 1, album);
        else albumes.value.unshift(album);
        return album;
    };

    const recargarAlbum = async (id: string): Promise<ExperienciaAlbum | null> => {
        try {
            const { data } = await apiClient.get<ExperienciaAlbum>(`${BASE_ALBUMES}/${id}`);
            return reemplazar(data);
        } catch (e) {
            error.value = mensajeDeErrorApi(e, 'No se pudo recargar el álbum.');
            return null;
        }
    };

    const crear = async (titulo: string): Promise<ExperienciaAlbum | null> => {
        error.value = null;
        try {
            const cuerpo: ExperienciaAlbumWrite = {
                titulo: [{ language: 'es', content: titulo.trim() }],
                orden: albumes.value.length,
            };
            const { data } = await apiClient.post<ExperienciaAlbum>(BASE_ALBUMES, cuerpo);
            return reemplazar(data);
        } catch (e) {
            error.value = mensajeDeErrorApi(e, 'No se pudo crear el álbum.');
            return null;
        }
    };

    /** Guarda campos del álbum. Devuelve el mensaje del servidor si los rechaza (p. ej. publicar sin autorizar). */
    const guardar = async (id: string, cambios: ExperienciaAlbumWrite): Promise<string | null> => {
        error.value = null;
        try {
            const { data } = await apiClient.patch<ExperienciaAlbum>(`${BASE_ALBUMES}/${id}`, cambios, MERGE);
            reemplazar(data);
            return null;
        } catch (e) {
            return mensajeDeErrorApi(e, 'No se pudo guardar el álbum.');
        }
    };

    const borrar = async (id: string): Promise<boolean> => {
        error.value = null;
        try {
            await apiClient.delete(`${BASE_ALBUMES}/${id}`);
            albumes.value = albumes.value.filter((a) => a.id !== id);
            return true;
        } catch (e) {
            error.value = mensajeDeErrorApi(e, 'No se pudo borrar el álbum.');
            return false;
        }
    };

    /** Sube UNA foto. La vista las manda de una en una para enseñar el progreso y no saturar. */
    const subirFoto = async (albumId: string, archivo: File, orden: number): Promise<string | null> => {
        try {
            const fd = new FormData();
            fd.append('imagen', archivo);
            fd.append('album', `${BASE_ALBUMES}/${albumId}`);
            fd.append('tipo', 'foto');
            fd.append('orden', String(orden));
            await apiClient.post(BASE_MEDIOS, fd, { headers: { 'Content-Type': 'multipart/form-data' } });
            return null;
        } catch (e) {
            return mensajeDeErrorApi(e, `No se pudo subir «${archivo.name}».`);
        }
    };

    /** Añade un vídeo por enlace. El servidor rechaza lo que no sea YouTube o Vimeo. */
    const agregarVideo = async (albumId: string, url: string, orden: number): Promise<string | null> => {
        try {
            await apiClient.post(BASE_MEDIOS, { album: `${BASE_ALBUMES}/${albumId}`, tipo: 'video', videoUrl: url.trim(), orden });
            return null;
        } catch (e) {
            return mensajeDeErrorApi(e, 'No se pudo añadir el vídeo.');
        }
    };

    const editarMedio = async (medioId: string, cambios: { orden?: number; esPortada?: boolean; pie?: I18nContent[] }): Promise<string | null> => {
        try {
            await apiClient.patch(`${BASE_MEDIOS}/${medioId}`, cambios, MERGE);
            return null;
        } catch (e) {
            return mensajeDeErrorApi(e, 'No se pudo guardar el cambio.');
        }
    };

    const borrarMedio = async (medioId: string): Promise<string | null> => {
        try {
            await apiClient.delete(`${BASE_MEDIOS}/${medioId}`);
            return null;
        } catch (e) {
            return mensajeDeErrorApi(e, 'No se pudo borrar.');
        }
    };

    /** Portada única: desmarca la anterior antes de marcar la nueva (el servidor no lo hace solo). */
    const marcarPortada = async (album: ExperienciaAlbum, medioId: string): Promise<string | null> => {
        const anterior = (album.medios ?? []).find((m) => m.esPortada && m.id !== medioId);
        if (anterior) {
            const fallo = await editarMedio(anterior.id, { esPortada: false });
            if (fallo) return fallo;
        }
        return editarMedio(medioId, { esPortada: true });
    };

    return { albumes, cargando, error, cargar, recargarAlbum, crear, guardar, borrar, subirFoto, agregarVideo, editarMedio, borrarMedio, marcarPortada };
});
