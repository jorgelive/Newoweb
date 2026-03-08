import { defineStore } from 'pinia';
import { ref } from 'vue';
import { paxService } from '@/services/paxService';
import type { MaestroIdioma } from '@/types/maestros';
import type { PmsContenidoTraducible } from '@/types/pms';
import type { PersistenceOptions } from 'pinia-plugin-persistedstate';

export const useMaestroStore = defineStore('maestroStore', () => {

        const idiomas = ref<MaestroIdioma[]>([]);
        const idiomaActual = ref('es');
        const loading = ref(false);
        const diccionario = ref<Record<string, PmsContenidoTraducible[]>>({});

        // ⏰ Control de Caché
        const lastUpdate = ref<number>(0);
        // 24 Horas en milisegundos (24 * 60 * 60 * 1000)
        // Los textos de la UI cambian muy poco, mejor un cache largo.
        const CACHE_TTL = 86400000;

        // Variable para Request Deduplication
        let loadPromise: Promise<void> | null = null;

        /**
         * Carga la configuración de idiomas y textos UI desde la API (UiI18n).
         * Utiliza caché local por 24 horas y deduplicación de peticiones.
         */
        const cargarConfiguracion = async () => {

            // 1. ANÁLISIS DE CACHÉ
            const ahora = Date.now();
            const tiempoTranscurrido = ahora - lastUpdate.value;
            const datosExisten = idiomas.value.length > 0 && Object.keys(diccionario.value).length > 0;
            const esFresco = tiempoTranscurrido < CACHE_TTL;
            const hayInternet = navigator.onLine;

            // CASO A: Datos frescos (menos de 24h) -> Usar caché
            if (datosExisten && esFresco) {
                console.log('⚡ MaestroStore: Cache válida (< 24h).');
                return;
            }

            // CASO B: Datos caducados PERO sin internet -> Usar caché
            if (datosExisten && !esFresco && !hayInternet) {
                console.warn('⚠️ MaestroStore: Datos caducados sin conexión. Usando versión antigua.');
                return;
            }

            // CASO C: Ya hay una petición en curso (para evitar llamadas dobles)
            if (loadPromise) {
                console.log('⏳ MaestroStore: Uniéndome a la petición en curso...');
                return loadPromise;
            }

            // CASO D: Datos caducados y con internet -> Actualizar
            console.log('🌍 MaestroStore: Actualizando textos e idiomas...');
            loading.value = true;

            loadPromise = (async () => {
                try {
                    const [dataIdiomas, dataTextos] = await Promise.all([
                        paxService.getIdiomasPrioritarios(),
                        paxService.getPaxUiTextos()
                    ]);

                    idiomas.value = dataIdiomas;
                    diccionario.value = dataTextos;

                    // Actualizamos la fecha solo si tuvimos éxito
                    lastUpdate.value = Date.now();
                    console.log('✅ MaestroStore: Actualizado correctamente.');

                } catch (error) {
                    console.error('❌ Error actualizando Maestro:', error);

                    // CASO E: Falló el servidor.
                    // Si tenemos datos viejos, NO lanzamos el error para que la App no rompa.
                    if (datosExisten) {
                        console.log('🛡️ MaestroStore: Manteniendo textos antiguos por seguridad.');
                        // Return silencioso (éxito falso) para que la app continúe
                        return;
                    }

                    // Si no hay datos, sí lanzamos error porque la app se vería vacía
                    throw error;
                } finally {
                    loading.value = false;
                    loadPromise = null;
                }
            })();

            return loadPromise;
        };

        /**
         * Extrae el string correcto basado en el idioma actual o sus fallbacks.
         */
        const traducir = (contenido: PmsContenidoTraducible[] | undefined): string => {
            if (!contenido || !Array.isArray(contenido) || contenido.length === 0) return '';
            const match = contenido.find(c => c.language === idiomaActual.value)
                || contenido.find(c => c.language === 'en')
                || contenido.find(c => c.language === 'es')
                || contenido[0];
            return match?.content || '';
        };

        /**
         * Obtiene un texto de la UI por su clave y opcionalmente inyecta variables dinámicas.
         * Ejemplo: t('gui_info_restringida', { date: '12 de marzo' }) reemplazará {{ date }} por el valor.
         */
        const t = (clave: string, variables?: Record<string, string>): string => {
            const entry = diccionario.value[clave];
            let texto = entry ? traducir(entry) : '';

            // Lógica de interpolación para reemplazar {{ variable }}
            if (variables && texto) {
                texto = texto.replace(/\{\{\s*([a-zA-Z0-9_]+)\s*\}\}/g, (match, key) => {
                    return variables[key] !== undefined ? variables[key] : match;
                });
            }

            return texto;
        };

        return {
            idiomas,
            idiomaActual,
            diccionario,
            loading,
            lastUpdate, // Exportamos para persistencia
            cargarConfiguracion,
            setIdioma: (id: string) => { idiomaActual.value = id },
            traducir,
            t
        };
    },
    {
        persist: {
            // 🔥 Guardamos 'lastUpdate' para saber la edad de los datos al recargar
            paths: ['idiomas', 'diccionario', 'idiomaActual', 'lastUpdate'],
            storage: localStorage,
        } as PersistenceOptions
    });