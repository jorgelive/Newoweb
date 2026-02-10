// src/stores/maestroStore.ts

import { defineStore } from 'pinia';
import { ref } from 'vue';
import { paxService } from '@/services/paxService';
import type { MaestroIdioma } from '@/types/maestros';
import type { PmsContenidoTraducible } from '@/types/pms';

export const useMaestroStore = defineStore('maestroStore', () => {

    const idiomas = ref<MaestroIdioma[]>([]);
    const idiomaActual = ref('es');
    const loading = ref(false);

    // 🔥 El diccionario que vendrá de /api/pax/ui
    const diccionario = ref<Record<string, PmsContenidoTraducible[]>>({});

    const cargarConfiguracion = async () => {
        // Si ya tenemos idiomas y diccionario, no recargamos
        if (idiomas.value.length > 0 && Object.keys(diccionario.value).length > 0) return;

        loading.value = true;
        try {
            console.log('🌍 MaestroStore: Iniciando carga global (Idiomas + UI)...');

            // Lanzamos ambas peticiones en paralelo para ganar velocidad
            const [dataIdiomas, dataTextos] = await Promise.all([
                paxService.getIdiomasPrioritarios(),
                paxService.getPaxUiTextos()
            ]);

            idiomas.value = dataIdiomas;
            diccionario.value = dataTextos;

            console.log('✅ MaestroStore: Configuración cargada con éxito.');
        } catch (error) {
            console.error('❌ Error en carga inicial:', error);
        } finally {
            loading.value = false;
        }
    };

    const traducir = (contenido: PmsContenidoTraducible[] | undefined): string => {
        if (!contenido || !Array.isArray(contenido) || contenido.length === 0) return '';
        const match = contenido.find(c => c.language === idiomaActual.value)
            || contenido.find(c => c.language === 'en')
            || contenido.find(c => c.language === 'es')
            || contenido[0];
        return match?.content || '';
    };

    const t = (clave: string): string => {
        const entry = diccionario.value[clave];
        // Si el backend devuelve la clave, la traducimos.
        // Si no, devolvemos string vacío para activar el fallback del template || 'Texto'
        return entry ? traducir(entry) : '';
    };

    return {
        idiomas,
        idiomaActual,
        diccionario,
        loading,
        cargarConfiguracion,
        setIdioma: (id: string) => { idiomaActual.value = id },
        traducir,
        t
    };
});