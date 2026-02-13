// src/stores/pmsGuiaStore.ts
import { defineStore } from 'pinia';
import { ref } from 'vue';
import { paxService } from '@/services/paxService';
import { useMaestroStore } from './maestroStore';
import type { PmsGuia, GuiaHelperContext, PmsContenidoTraducible } from '@/types/pms';
import type { PersistenceOptions } from 'pinia-plugin-persistedstate';

export const usePmsGuiaStore = defineStore('pmsGuiaStore', () => {

    const maestroStore = useMaestroStore();

    // Estado
    const guia = ref<PmsGuia | null>(null);
    const helperContext = ref<GuiaHelperContext | null>(null);
    const loading = ref(false);
    const error = ref<string | null>(null);
    const currentId = ref<string | null>(null);

    // ⏰ Control de Tiempo
    const lastUpdate = ref<number>(0);
    // 10 minutos en milisegundos (10 * 60 * 1000)
    const CACHE_TTL = 600000;

    const cargarDatosCompletos = async (uuid: string, mode: 'public' | 'guest') => {

        // 1. ANÁLISIS DE CACHÉ
        const ahora = Date.now();
        const tiempoTranscurrido = ahora - lastUpdate.value;
        const datosExisten = helperContext.value && currentId.value === uuid;
        const esFresco = tiempoTranscurrido < CACHE_TTL;
        const hayInternet = navigator.onLine; // Chequeo nativo del navegador

        // CASO A: Datos frescos (menos de 10 min) -> Usar caché siempre
        if (datosExisten && esFresco) {
            console.log('⚡ GuiaStore: Cache válida (< 10m). Ahorrando petición.');
            if (guia.value) return;
        }

        // CASO B: Datos caducados PERO sin internet -> Usar caché (Salvavidas)
        if (datosExisten && !esFresco && !hayInternet) {
            console.warn('⚠️ GuiaStore: Datos caducados, pero SIN INTERNET. Usando versión antigua.');
            // Opcional: Podrías poner una notificación UI tipo "Modo Offline"
            return;
        }

        // CASO C: Datos caducados y CON internet -> Intentar actualizar
        console.log('🔄 GuiaStore: Actualizando datos...');
        loading.value = true;

        // 🔥 TRUCO: No borramos 'error' ni 'guia' todavía para mantener la UI visible
        // mientras carga la nueva versión por detrás.

        try {
            // Cargar idiomas si faltan (esto suele ser rápido o estar en caché)
            if (maestroStore.idiomas.length === 0) {
                await maestroStore.cargarConfiguracion();
            }

            let contextData;

            if (mode === 'guest') {
                contextData = await paxService.getGuiaGuestContext(uuid);
            } else {
                contextData = await paxService.getGuiaPublicContext(uuid);
            }

            // Si llegamos aquí, la red funcionó. Actualizamos.
            helperContext.value = contextData;
            currentId.value = uuid;
            lastUpdate.value = Date.now(); // 🕒 Renovamos el tiempo de vida
            error.value = null; // Limpiamos errores viejos

            const unidadRealId = contextData?.data?.config?.unit_uuid;
            if (!unidadRealId) throw new Error('Datos corruptos: Sin ID de unidad');

            // Cargar CMS visual
            if (!guia.value || guia.value.unidad.id !== unidadRealId) {
                const guiaData = await paxService.getPmsGuia(unidadRealId);
                guia.value = guiaData;
            }

        } catch (err: any) {
            console.error('❌ GuiaStore: Falló la actualización.', err);

            // CASO D: Falló la petición (servidor caído o internet inestable)
            // ¿Teníamos datos viejos? ¡NOS LOS QUEDAMOS!
            if (datosExisten) {
                console.log('🛡️ GuiaStore: Manteniendo datos antiguos por seguridad.');
                // No lanzamos error a la UI, dejamos que el usuario siga navegando
                // Solo guardamos el mensaje por si queremos mostrar un "Toast" de advertencia
                error.value = "No se pudo actualizar, mostrando versión guardada.";
            } else {
                // Si no teníamos nada y falló, ahí sí mostramos error fatal
                error.value = err.message || 'Error de conexión.';
                guia.value = null;
                helperContext.value = null;
            }
        } finally {
            loading.value = false;
        }
    };

    const traducir = (contenido: PmsContenidoTraducible[] | undefined): string => {
        if (!contenido || !Array.isArray(contenido) || contenido.length === 0) return '';
        const idioma = maestroStore.idiomaActual;
        const match = contenido.find(c => c.language === idioma);
        if (match?.content) return match.content;
        const fallback = contenido.find(c => c.language === 'en') || contenido.find(c => c.language === 'es');
        return fallback?.content || contenido[0].content || '';
    };

    return {
        guia,
        helperContext,
        loading,
        error,
        currentId,
        lastUpdate,
        cargarDatosCompletos,
        traducir
    };
}, {
    persist: {
        // Importante guardar lastUpdate para saber cuánto tiempo pasó al recargar
        paths: ['guia', 'helperContext', 'currentId', 'lastUpdate'],
        storage: localStorage,
    } as PersistenceOptions
});