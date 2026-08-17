import { defineStore } from 'pinia';
import { ref } from 'vue';
import { apiClient } from '@/services/apiClient';

/**
 * Qué puede hacer el usuario que está mirando la pantalla.
 *
 * ── Para qué sirve ──────────────────────────────────────────────────────────
 * Para **pintar**: deshabilitar un botón de alta y decir por qué, en vez de dejar que el
 * usuario lo pulse y reciba un error del backend. Un «No tienes permiso» tras el clic
 * parece que la aplicación está rota; un botón apagado con su explicación, no.
 *
 * ⚠️ **No es el candado.** Quien decide sigue siendo el `#[IsGranted]` de cada endpoint:
 * cualquiera puede mentirle a su propio navegador. Si una comprobación vive sólo aquí,
 * deja de ser una comprobación.
 *
 * ── Cómo se usa ─────────────────────────────────────────────────────────────
 * ```ts
 * const permisos = usePermisosStore();
 * onMounted(() => permisos.cargar());          // idempotente, se puede llamar en cada vista
 * :disabled="!permisos.puede('ROLE_MAESTROS_WRITE')"
 * :title="permisos.motivo('ROLE_MAESTROS_WRITE', 'dar de alta empresas')"
 * ```
 *
 * ── Optimista mientras carga, a propósito ───────────────────────────────────
 * Antes de la primera respuesta `puede()` devuelve `true`. Si devolviera `false`, cada
 * pantalla parpadearía con todo deshabilitado durante la primera petición, y eso se lee
 * como «no tengo permisos» en vez de como «aún no lo sé». El coste de equivocarse hacia
 * este lado es un error del backend en el peor caso, que es exactamente lo que había antes.
 */
export const usePermisosStore = defineStore('permisosStore', () => {
    const concedidos = ref<Record<string, boolean>>({});
    const cargado = ref(false);
    let enVuelo: Promise<void> | null = null;

    /** Trae los permisos una sola vez. Las llamadas simultáneas comparten la petición. */
    const cargar = async (): Promise<void> => {
        if (cargado.value) return;
        if (enVuelo) return enVuelo;

        enVuelo = apiClient.get('/tipo/user/enum/permisos')
            .then((res) => {
                concedidos.value = res.data ?? {};
                cargado.value = true;
            })
            .catch(() => {
                // Silencio a propósito: no saber los permisos no debe romper la pantalla.
                // Se queda optimista y manda el backend, como antes de que esto existiera.
            })
            .finally(() => {
                enVuelo = null;
            });

        return enVuelo;
    };

    const puede = (rol: string): boolean => (cargado.value ? concedidos.value[rol] === true : true);

    /**
     * Texto del `title` de un control: explica la acción, o por qué está apagado.
     *
     * Que el motivo viaje con el botón es lo que evita el «no funciona»: apagar un control
     * sin decir por qué genera más consultas que el error que se quería evitar.
     */
    const motivo = (rol: string, accion: string): string =>
        puede(rol) ? accion : `No tienes permiso para ${accion}`;

    return { concedidos, cargado, cargar, puede, motivo };
});
