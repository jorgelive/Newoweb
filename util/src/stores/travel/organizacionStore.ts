// ============================================================================
// STORE DEL CATÁLOGO DE PROVEEDORES
//
// Administra el maestro `travel_proveedor` desde la SPA. Hasta ahora el único sitio
// donde se podía dar de alta un proveedor era EasyAdmin, fuera del flujo de cotizar, y
// eso empujaba al operador al campo de texto libre — que deja `prestadorMaestroId` vacío
// y rompe el histórico financiero.
// ============================================================================

import { defineStore } from 'pinia';
import { ref } from 'vue';
import { apiClient } from '@/services/apiClient';
import { miembrosHydra } from '@/services/hydra';
import type { LugarOpcion, Organizacion, OrganizacionServicio, ProveedorWrite } from '@/types/organizacionModel';

const RUTA = '/platform/travel/proveedores';

export const useOrganizacionStore = defineStore('proveedorCatalogo', () => {
    const proveedores = ref<Organizacion[]>([]);
    const proveedorActivo = ref<Organizacion | null>(null);
    const isLoading = ref(false);
    const isGuardando = ref(false);
    const error = ref<string | null>(null);

    /** Vocabulario de lugares. Se carga una vez: cambia poco y lo usan todos los formularios. */
    const lugares = ref<LugarOpcion[]>([]);

    const mensajeDeError = (e: unknown, porDefecto: string): string => {
        const r = (e as { response?: { data?: Record<string, unknown> } })?.response?.data;
        return String(r?.['hydra:description'] ?? r?.detail ?? porDefecto);
    };

    /**
     * Listado. `termino` filtra por nombre comercial con el SearchFilter `partial` que ya
     * declara la entidad; sin término trae la primera página completa.
     */
    const fetchProveedores = async (termino = ''): Promise<void> => {
        isLoading.value = true;
        error.value = null;
        try {
            const params: Record<string, string | number> = { itemsPerPage: 60 };
            if (termino.trim()) params.nombreComercial = termino.trim();

            const res = await apiClient.get(RUTA, { params });
            proveedores.value = miembrosHydra<Organizacion>(res.data);
        } catch (e) {
            error.value = mensajeDeError(e, 'No se pudo cargar el catálogo de proveedores.');
            proveedores.value = [];
        } finally {
            isLoading.value = false;
        }
    };

    /** Ficha completa: la colección no trae servicios ni galería, sólo el item. */
    const fetchProveedor = async (id: string): Promise<void> => {
        isLoading.value = true;
        error.value = null;
        try {
            const res = await apiClient.get(`${RUTA}/${id}`);
            proveedorActivo.value = res.data as Organizacion;
        } catch (e) {
            error.value = mensajeDeError(e, 'No se pudo cargar el proveedor.');
            proveedorActivo.value = null;
        } finally {
            isLoading.value = false;
        }
    };

    /** Devuelve el proveedor creado para que el llamador lo seleccione en el acto. */
    const crearProveedor = async (datos: ProveedorWrite): Promise<Organizacion | null> => {
        isGuardando.value = true;
        error.value = null;
        try {
            const res = await apiClient.post(RUTA, datos);
            const creado = res.data as Organizacion;
            proveedores.value.unshift(creado);
            return creado;
        } catch (e) {
            error.value = mensajeDeError(e, 'No se pudo crear el proveedor.');
            return null;
        } finally {
            isGuardando.value = false;
        }
    };

    const actualizarProveedor = async (id: string, datos: Partial<ProveedorWrite>): Promise<boolean> => {
        isGuardando.value = true;
        error.value = null;
        try {
            // PATCH y no PUT: el formulario manda sólo lo que tocó, y PUT exigiría
            // reenviar el recurso entero (incluidos los json de i18n) para no vaciarlo.
            const res = await apiClient.patch(`${RUTA}/${id}`, datos, {
                headers: { 'Content-Type': 'application/merge-patch+json' },
            });
            const i = proveedores.value.findIndex((p) => p.id === id);
            if (i !== -1) proveedores.value[i] = res.data as Organizacion;
            if (proveedorActivo.value?.id === id) proveedorActivo.value = res.data as Organizacion;
            return true;
        } catch (e) {
            error.value = mensajeDeError(e, 'No se pudo guardar el proveedor.');
            return false;
        } finally {
            isGuardando.value = false;
        }
    };

    /**
     * Borra el proveedor. Arrastra en cascada su galería y sus servicios.
     *
     * No toca las cotizaciones ya emitidas: guardan un soft-link con el nombre y los
     * textos congelados, así que el documento del cliente sigue siendo el que se vendió.
     */
    const borrarProveedor = async (id: string): Promise<boolean> => {
        error.value = null;
        try {
            await apiClient.delete(`${RUTA}/${id}`);
            proveedores.value = proveedores.value.filter((p) => p.id !== id);
            if (proveedorActivo.value?.id === id) proveedorActivo.value = null;
            return true;
        } catch (e) {
            error.value = mensajeDeError(e, 'No se pudo eliminar el proveedor.');
            return false;
        }
    };

    /**
     * Maestro de lugares para el selector de cobertura. Sólo los activos: desactivar un
     * lugar es la forma de retirarlo sin destruir el etiquetado existente.
     */
    const fetchLugares = async (): Promise<void> => {
        if (lugares.value.length) return;

        try {
            const res = await apiClient.get('/platform/travel/lugares', {
                params: { activo: true, itemsPerPage: 100 },
            });
            lugares.value = miembrosHydra<Record<string, unknown>>(res.data).map((l) => ({
                id: String(l.id ?? ''),
                '@id': String(l['@id'] ?? ''),
                nombre: String(l.nombre ?? ''),
            }));
        } catch (e) {
            console.error('No se pudo cargar el vocabulario de lugares:', e);
            lugares.value = [];
        }
    };

    // ── Servicios del proveedor ────────────────────────────────────────────────

    const crearServicio = async (proveedorIri: string, nombre: string): Promise<boolean> => {
        error.value = null;
        try {
            await apiClient.post('/platform/travel/proveedor-servicios', {
                nombre,
                proveedor: proveedorIri,
            });
            return true;
        } catch (e) {
            error.value = mensajeDeError(e, 'No se pudo crear el servicio.');
            return false;
        }
    };

    const borrarServicio = async (servicio: OrganizacionServicio): Promise<boolean> => {
        error.value = null;
        try {
            await apiClient.delete(servicio['@id'] ?? `/platform/travel/proveedor-servicios/${servicio.id}`);
            return true;
        } catch (e) {
            error.value = mensajeDeError(e, 'No se pudo eliminar el servicio.');
            return false;
        }
    };

    // ── Galería ────────────────────────────────────────────────────────────────

    /**
     * Sube una imagen por multipart.
     *
     * El campo del binario se llama `imagen` y NO `file`: en multipart un campo con el
     * nombre de una propiedad de Doctrine confunde al denormalizador. El backend lo
     * recoge en `ProveedorImagenMultipartProcessor` y Vich lo convierte a WebP solo.
     */
    const subirImagen = async (proveedorIri: string, archivo: File, orden = 0, esPortada = false): Promise<boolean> => {
        error.value = null;
        try {
            const fd = new FormData();
            fd.append('imagen', archivo);
            fd.append('proveedor', proveedorIri);
            fd.append('orden', String(orden));
            fd.append('isPortada', esPortada ? '1' : '0');

            await apiClient.post('/platform/travel/proveedor_imagens', fd, {
                headers: { 'Content-Type': 'multipart/form-data' },
            });
            return true;
        } catch (e) {
            error.value = mensajeDeError(e, 'No se pudo subir la imagen.');
            return false;
        }
    };

    const borrarImagen = async (imagenId: string): Promise<boolean> => {
        error.value = null;
        try {
            await apiClient.delete(`/platform/travel/proveedor_imagens/${imagenId}`);
            return true;
        } catch (e) {
            error.value = mensajeDeError(e, 'No se pudo eliminar la imagen.');
            return false;
        }
    };

    /** Marca portada. El backend no desmarca la anterior, así que se hace aquí. */
    const marcarPortada = async (imagenId: string): Promise<boolean> => {
        error.value = null;
        try {
            const actuales = proveedorActivo.value?.proveedorImagenes ?? [];
            const anterior = actuales.find((i) => i.isPortada && i.id !== imagenId);

            if (anterior) {
                await apiClient.patch(`/platform/travel/proveedor_imagens/${anterior.id}`, { isPortada: false },
                    { headers: { 'Content-Type': 'application/merge-patch+json' } });
            }
            await apiClient.patch(`/platform/travel/proveedor_imagens/${imagenId}`, { isPortada: true },
                { headers: { 'Content-Type': 'application/merge-patch+json' } });
            return true;
        } catch (e) {
            error.value = mensajeDeError(e, 'No se pudo marcar la portada.');
            return false;
        }
    };

    return {
        proveedores,
        proveedorActivo,
        isLoading,
        isGuardando,
        error,
        lugares,
        fetchLugares,
        fetchProveedores,
        fetchProveedor,
        crearProveedor,
        actualizarProveedor,
        borrarProveedor,
        crearServicio,
        borrarServicio,
        subirImagen,
        borrarImagen,
        marcarPortada,
    };
});
