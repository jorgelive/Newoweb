// src/stores/pmsGuiaStore.ts
import { defineStore } from 'pinia';
import { ref } from 'vue';
import { paxService } from '@/services/paxService';
import { useMaestroStore } from './maestroStore';
import type { PmsGuia, PmsContenidoTraducible, GuiaHelperContext } from '@/types/pms';

export const usePmsGuiaStore = defineStore('pmsGuiaStore', () => {

    const maestroStore = useMaestroStore();

    const guia = ref<PmsGuia | null>(null);
    const helperContext = ref<GuiaHelperContext | null>(null);
    const loading = ref(false);
    const error = ref<string | null>(null);

    const cargarDatosCompletos = async (uuid: string) => {
        loading.value = true;
        error.value = null;

        try {
            if (maestroStore.idiomas.length === 0) {
                await maestroStore.cargarConfiguracion();
            }

            // 1. Obtener Helper Context
            const contextData = await paxService.getGuiaContext(uuid);
            helperContext.value = contextData;

            // 🔥 VALIDACIÓN CRÍTICA DEL UUID en 'config'
            const unidadRealId = contextData?.data?.config?.unit_uuid;

            if (!unidadRealId || typeof unidadRealId !== 'string' || unidadRealId.trim() === '') {
                console.error("Payload inválido recibido:", contextData);
                throw new Error('Error de integridad: La unidad no tiene un ID válido (unit_uuid).');
            }

            // 2. Cargar CMS con el ID validado
            const guiaData = await paxService.getPmsGuia(unidadRealId);
            guia.value = guiaData;

        } catch (err: any) {
            console.error('Error Store:', err);
            error.value = err.message || 'Error de conexión.';
            guia.value = null;
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
        cargarDatosCompletos,
        traducir
    };
});