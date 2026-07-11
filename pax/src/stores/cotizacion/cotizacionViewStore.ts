import { defineStore } from 'pinia';
import { ref, computed } from 'vue';
import { apiClient } from '@/services/apiClient';
import {CotizacionResumen, ApiCotizacionFile} from '@/types/fileDetalleModel';
import type {
    Cotizacion,
    CotServicio,
    ClasificacionFinancieraCliente,
    I18nContent
} from '@/types/cotizacionClientModel';



export const useCotizacionViewStore = defineStore('cotizacionViewStore', () => {

    const localizador = ref<string>('');
    const fileData = ref<ApiCotizacionFile | null>(null);
    const cotizacionActiva = ref<Cotizacion | null>(null);
    const isLoading = ref<boolean>(false);
    const error = ref<string | null>(null);

    // ============================================================================
    // SETTERS Y GETTERS TIPADOS
    // ============================================================================

    const setLocalizador = (val: string): void => { localizador.value = val; };
    const setFileData = (data: ApiCotizacionFile | null): void => { fileData.value = data; };
    const setCotizacionActiva = (cotizacion: Cotizacion | null): void => { cotizacionActiva.value = cotizacion; };
    const setIsLoading = (status: boolean): void => { isLoading.value = status; };
    const setError = (msg: string | null): void => { error.value = msg; };

    const getIsReady = computed<boolean>(() => fileData.value !== null && cotizacionActiva.value !== null);

    const getServiciosActivos = computed<CotServicio[]>(() => {
        const servicios = cotizacionActiva.value?.cotservicios ?? [];
        return [...servicios].sort((a: CotServicio, b: CotServicio) => {
            const dateA = a.fechaInicioAbsoluta ?? '9999-12-31';
            const dateB = b.fechaInicioAbsoluta ?? '9999-12-31';
            return dateA.localeCompare(dateB);
        });
    });

    const getPrecioVentaPublico = computed<string>(() => {
        const cot = cotizacionActiva.value;
        if (!cot) return '0.00';
        if (cot.precioOculto) return 'Consultar precio';

        const moneda = cot.monedaGlobal ?? 'USD';
        const resumenCliente = cot.clasificacionFinancieraCliente as ClasificacionFinancieraCliente | undefined;

        const monto = resumenCliente
            ? parseFloat(String(resumenCliente.totalVentaBruta)).toFixed(2)
            : parseFloat(String(cot.totalVenta)).toFixed(2);

        return `${moneda} ${monto}`;
    });

    // ============================================================================
    // LÓGICA DE NEGOCIO TIPADA
    // ============================================================================

    /**
     * Helper para obtener el contenido traducido sin usar any.
     * @param snapshotArray Array de I18nContent definido en el modelo.
     * @param lang Idioma actual solicitado.
     */
    const getTranslatedContent = (snapshotArray: I18nContent[], lang: string): string => {
        const match = snapshotArray.find((item: I18nContent) => item.language === lang);
        return match ? match.content : (snapshotArray.find((item: I18nContent) => item.language === 'es')?.content ?? '');
    };

    const limpiarEstado = (): void => {
        setLocalizador('');
        setFileData(null);
        setCotizacionActiva(null);
        setError(null);
    };

    const cargarCotizacionPorLocalizador = async (locatorCode: string): Promise<boolean> => {
        if (!locatorCode.trim()) {
            setError('Por favor, ingresa un código de localizador válido.');
            return false;
        }

        setIsLoading(true);
        setError(null);
        limpiarEstado();
        setLocalizador(locatorCode.trim().toUpperCase());

        try {
            // GET /sales/cotizacion_files?localizador=...
            const resFile = await apiClient.get<{ 'hydra:member': ApiCotizacionFile[] }>(
                `/platform/sales/cotizacion_files?localizador=${localizador.value}`
            );

            const expediente = resFile.data['hydra:member'][0] ?? null;
            if (!expediente) {
                setError('Propuesta no encontrada.');
                return false;
            }
            setFileData(expediente);

            // Filtrado tipado de versiones
            const cotizaciones = expediente.cotizaciones ?? [];
            if (cotizaciones.length === 0) {
                setError('Expediente sin cotizacion.');
                return false;
            }

            const masReciente = cotizaciones.reduce((prev: CotizacionResumen, current: CotizacionResumen) =>
                (prev.version > current.version) ? prev : current
            );

            // Fetch profundo de la cotización
            const resCot = await apiClient.get<Cotizacion>(masReciente['@id'] ?? `/platform/sales/cotizacions/${masReciente.id}`);
            setCotizacionActiva(resCot.data);

            return true;

        } catch (err) {
            setError('Error al buscar la propuesta.');
            return false;
        } finally {
            setIsLoading(false);
        }
    };

    return {
        localizador, fileData, cotizacionActiva, isLoading, error,
        getIsReady, getServiciosActivos, getPrecioVentaPublico,
        getTranslatedContent, cargarCotizacionPorLocalizador
    };
});