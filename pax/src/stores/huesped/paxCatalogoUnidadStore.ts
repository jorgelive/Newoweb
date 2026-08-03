// src/stores/huesped/paxCatalogoUnidadStore.ts
import { defineStore } from 'pinia';
import { ref } from 'vue';
import type { CatalogoUnidadResponse } from '@/types/paxCatalogoUnidadModel';

const API_BASE = window.OPENPERU_CONFIG?.apiUrl || import.meta.env.VITE_API_URL;

/**
 * Escaparate público de una unidad (`/casita/casita-1`).
 *
 * Store propio y deliberadamente aburrido: una petición, un objeto, sin caché
 * con TTL ni modo offline. Es contenido comercial que se visita una vez desde
 * un buscador o un QR, no la guía que el huésped consulta a las 2 de la mañana
 * sin datos en el móvil.
 *
 * Tampoco persiste en localStorage. El store de la guía sí lo hace —y ahí está
 * el problema que documenta docs/PmsGuiaHuesped.md §6: guarda el contexto con
 * los códigos reales sin fecha de caducidad—, así que este arranca limpio para
 * no repetirlo.
 */
export const usePaxCatalogoUnidadStore = defineStore('paxCatalogoUnidadStore', () => {

    const catalogo = ref<CatalogoUnidadResponse | null>(null);
    const loading = ref(false);
    const error = ref<string | null>(null);

    /** Clave de lo que hay cargado, para no repetir la petición al re-montar. */
    const rutaActual = ref<string | null>(null);

    const cargar = async (establecimiento: string, unidad: string): Promise<void> => {
        const clave = `${establecimiento}/${unidad}`;

        if (rutaActual.value === clave && catalogo.value) return;

        loading.value = true;
        error.value = null;

        try {
            const res = await fetch(
                `${API_BASE}/platform/public/pax/pms/pms_guia/${encodeURIComponent(establecimiento)}/${encodeURIComponent(unidad)}`,
                { headers: { Accept: 'application/ld+json' } }
            );

            if (!res.ok) {
                // El provider devuelve null —y por tanto 404— tanto si la unidad
                // no existe como si no tiene nada publicado. Para el visitante
                // son el mismo caso: aquí no hay página.
                throw new Error(res.status === 404 ? 'ERR_404' : 'ERR_CONEXION');
            }

            catalogo.value = await res.json() as CatalogoUnidadResponse;
            rutaActual.value = clave;

        } catch (err: unknown) {
            error.value = (err as Error)?.message ?? 'ERR_CONEXION';
            catalogo.value = null;
            rutaActual.value = null;
        } finally {
            loading.value = false;
        }
    };

    return { catalogo, loading, error, cargar };
});
