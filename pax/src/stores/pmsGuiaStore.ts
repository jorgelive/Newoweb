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
    const lastUpdate = ref<number>(0); // Caché para el contexto (Guest/Public)
    const lastUpdateGuia = ref<number>(0); // Caché específico para el CMS visual (getPmsGuia)

    // 30 segundos en milisegundos (30 * 1000)
    const CACHE_TTL = 30000;

    /**
     * Carga el contexto de la guía y el CMS visual asegurando caché de 30s.
     * Retiene los datos activos si no hay conexión a internet.
     * * @param {string} uuid Identificador único de la reserva o propiedad.
     * @param {'public' | 'guest'} mode Modo de acceso.
     * @returns {Promise<void>}
     */
    const cargarDatosCompletos = async (uuid: string, mode: 'public' | 'guest'): Promise<void> => {
        const ahora = Date.now();
        const hayInternet = navigator.onLine; // Chequeo nativo del navegador

        // Evaluaciones de estado y frescura
        const tiempoTranscurridoContexto = ahora - lastUpdate.value;
        const contextoExiste = helperContext.value && currentId.value === uuid;
        const contextoFresco = tiempoTranscurridoContexto < CACHE_TTL;

        const tiempoTranscurridoGuia = ahora - lastUpdateGuia.value;
        const guiaExiste = guia.value !== null;
        const guiaFresca = tiempoTranscurridoGuia < CACHE_TTL;

        // 🛑 REGLA ESTRICTA OFFLINE: Si no hay red y tenemos datos, abortamos actualización.
        if (!hayInternet) {
            if (contextoExiste || guiaExiste) {
                console.warn('⚠️ GuiaStore: Sin conexión a internet. Reteniendo la última data activa en caché de forma indefinida.');
                return;
            }
        }

        console.log('🔄 GuiaStore: Validando caché y actualizando datos si es necesario...');
        loading.value = true;

        // 🔥 TRUCO: No borramos 'error' ni 'guia' todavía para mantener la UI visible
        // mientras carga la nueva versión por detrás.

        try {
            // Cargar idiomas si faltan (el maestroStore gestiona su propio caché de 30s)
            if (maestroStore.idiomas.length === 0) {
                await maestroStore.cargarConfiguracion();
            }

            // 1. ACTUALIZACIÓN DEL CONTEXTO (Guest/Public)
            if (!contextoExiste || !contextoFresco) {
                let contextData;
                if (mode === 'guest') {
                    contextData = await paxService.getGuiaGuestContext(uuid);
                } else {
                    contextData = await paxService.getGuiaPublicContext(uuid);
                }

                helperContext.value = contextData;
                currentId.value = uuid;
                lastUpdate.value = Date.now();
                error.value = null;
            } else {
                console.log('⚡ GuiaStore: Contexto fresco (< 30s). Ahorrando petición.');
            }

            // Extraer el ID real de la unidad del contexto actual
            const unidadRealId = helperContext.value?.data?.config?.unit_uuid;
            if (!unidadRealId) throw new Error('Datos corruptos: Sin ID de unidad en el contexto.');

            // 2. ACTUALIZACIÓN DE LA GUÍA VISUAL (getPmsGuia)
            // Actualiza si: no existe, cambió de unidad, o el caché de 30s expiró
            if (!guiaExiste || guia.value?.unidad?.id !== unidadRealId || !guiaFresca) {
                const guiaData = await paxService.getPmsGuia(unidadRealId);
                guia.value = guiaData;
                lastUpdateGuia.value = Date.now();
            } else {
                console.log('⚡ GuiaStore: CMS visual de Guía fresco (< 30s). Ahorrando petición.');
            }

        } catch (err: any) {
            console.error('❌ GuiaStore: Falló la actualización.', err);

            // Si falla el servidor pero teníamos datos, nos los quedamos por seguridad
            if (contextoExiste || guiaExiste) {
                console.log('🛡️ GuiaStore: Servidor falló. Manteniendo datos antiguos por seguridad.');
                error.value = "No se pudo actualizar, mostrando última versión activa guardada.";
            } else {
                error.value = err.message || 'Error de conexión crítico.';
                guia.value = null;
                helperContext.value = null;
            }
        } finally {
            loading.value = false;
        }
    };

    /**
     * Extrae y devuelve el string traducido según el idioma actual.
     * * @param {PmsContenidoTraducible[] | undefined} contenido Arreglo de traducciones.
     * @returns {string} Texto traducido o cadena vacía si no existe.
     */
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
        lastUpdateGuia,
        cargarDatosCompletos,
        traducir
    };
}, {
    persist: {
        // Se añade lastUpdateGuia para persistir ambos temporizadores de caché
        paths: ['guia', 'helperContext', 'currentId', 'lastUpdate', 'lastUpdateGuia'],
        storage: localStorage,
    } as PersistenceOptions
});