// src/stores/pmsGuiaStore.ts

import { defineStore } from 'pinia';
import { ref } from 'vue';
import { paxService } from '@/services/paxService';
import { useMaestroStore } from './maestroStore';
import type { PmsGuia, PmsContenidoTraducible, GuiaHelperContext } from '@/types/pms';

export const usePmsGuiaStore = defineStore('pmsGuiaStore', () => {

    const maestroStore = useMaestroStore();

    // --- STATE ---
    const guia = ref<PmsGuia | null>(null);
    const helperContext = ref<GuiaHelperContext | null>(null);
    const loading = ref(false);
    const error = ref<string | null>(null);

    // --- ACTION PRINCIPAL ---
    const cargarDatosCompletos = async (uuid: string) => {
        loading.value = true;
        error.value = null;

        try {
            // 1. Carga de idiomas si hace falta
            if (maestroStore.idiomas.length === 0) {
                await maestroStore.cargarConfiguracion();
            }

            // 2. Obtener Contexto del Huésped (Aquí llega el JSON con replacements/config/widgets)
            const contextData = await paxService.getGuiaContext(uuid);
            helperContext.value = contextData;

            // 🔥 CORRECCIÓN CRÍTICA AQUÍ:
            // Antes buscábamos en contextData?.data?.unit_uuid (ruta vieja)
            // Ahora buscamos en contextData?.data?.config?.unit_uuid (ruta nueva)
            const unidadRealId = contextData?.data?.config?.unit_uuid;

            if (!unidadRealId) {
                console.error("Contexto recibido:", contextData); // Para depuración
                throw new Error('No se pudo identificar la unidad en la respuesta del servidor.');
            }

            // 3. Con el ID correcto, pedimos el contenido CMS (PmsGuia)
            const guiaData = await paxService.getPmsGuia(unidadRealId);
            guia.value = guiaData;

        } catch (err: any) {
            console.error('Error cargando guía:', err);
            error.value = err.message || 'Error de conexión.';
            guia.value = null;
        } finally {
            loading.value = false;
        }
    };

    // --- HELPER DE TRADUCCIÓN ---
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
        cargarDatosCompletos,
        traducir
    };
});