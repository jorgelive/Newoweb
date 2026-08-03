// src/stores/huesped/paxHuespedGuiaStore.ts
import { defineStore } from 'pinia';
import { ref } from 'vue';
import type { GuiaHuespedResponse } from '@/types/paxHuespedGuiaModel';

const API_BASE = window.OPENPERU_CONFIG?.apiUrl || import.meta.env.VITE_API_URL;

/**
 * Guía del huésped de una estancia (`/huesped/reserva/:localizador/:unidad`).
 *
 * **Sin `persist`, y es deliberado.** El store anterior guardaba el contexto en
 * localStorage sin caducidad — con los códigos de puerta y la contraseña del
 * WiFi dentro — y sobrevivía a la estancia: un móvil prestado o robado seguía
 * abriendo la puerta meses después. Ver docs/PmsGuiaHuesped.md §6. Cada visita
 * vuelve a pedir el payload; el backend ya decide qué liberar según la ventana,
 * así que cachearlo sería además servir datos caducados.
 *
 * Una petición y un objeto: el árbol llega podado e interpolado, aquí no se
 * calcula nada.
 */
export const usePaxHuespedGuiaStore = defineStore('paxHuespedGuiaStore', () => {

    const guia = ref<GuiaHuespedResponse | null>(null);
    const loading = ref(false);
    /** Código de error para la vista: ERR_404 | ERR_THROTTLE | ERR_CONEXION. */
    const error = ref<string | null>(null);

    const cargar = async (localizador: string, unidad?: string | null): Promise<void> => {
        loading.value = true;
        error.value = null;

        // Sin slug de unidad el backend devuelve la primera estancia cronológica
        // (ver PmsGuiaHuespedProvider): un enlace corto siempre lleva a algo útil.
        const ruta = unidad
            ? `${encodeURIComponent(localizador)}/${encodeURIComponent(unidad)}`
            : encodeURIComponent(localizador);

        try {
            const res = await fetch(`${API_BASE}/platform/client/pax/pms/pms_guia/${ruta}`, {
                headers: { Accept: 'application/ld+json' },
                // El payload puede llevar códigos de acceso: que no se quede en
                // ninguna caché intermedia ni en el historial del navegador.
                cache: 'no-store',
            });

            if (!res.ok) {
                // 429 = PmsGuiaThrottle. Merece su propio mensaje: al huésped
                // legítimo no le pasa nunca (solo los fallos gastan presupuesto),
                // y si le pasa, "vuelve en unos minutos" es accionable.
                if (res.status === 429) throw new Error('ERR_THROTTLE');
                // 404 uniforme: localizador inexistente, estancia sin guía o
                // guía deshabilitada. Para el huésped es el mismo caso, y esa
                // indistinción es intencionada (no filtra qué localizadores existen).
                throw new Error(res.status === 404 ? 'ERR_404' : 'ERR_CONEXION');
            }

            guia.value = await res.json() as GuiaHuespedResponse;

        } catch (err: unknown) {
            error.value = (err as Error)?.message ?? 'ERR_CONEXION';
            guia.value = null;
        } finally {
            loading.value = false;
        }
    };

    /** Borra el payload al salir de la vista: los códigos no siguen en memoria. */
    const limpiar = (): void => {
        guia.value = null;
        error.value = null;
    };

    return { guia, loading, error, cargar, limpiar };
});
