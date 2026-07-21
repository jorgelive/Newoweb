// src/stores/pax/paxCotizacionStore.ts
import { defineStore } from 'pinia';
import { ref, computed } from 'vue';
import { paxCotizacionService } from '@/services/paxCotizacionService';
import { useMaestroStore } from '../maestroStore';
import type {
    PaxCotizacionFile,
    PaxCotizacion,
    I18n,
    PaxDiaItinerario,
    PaxSegmentoConServicio,
    PaxInclusionServicio,
    PaxVersionResumen,
} from '@/types/paxCotizacionModel';

import type { PersistenceOptions } from 'pinia-plugin-persistedstate';

export const usePaxCotizacionStore = defineStore('paxCotizacionStore', () => {

    const maestroStore = useMaestroStore();

    // ── Estado ────────────────────────────────────────────────────────────
    // PORTADA (file + cards de propuestas, sin árbol)
    const portada = ref<PaxCotizacionFile | null>(null);
    const lastUpdatePortada = ref<number>(0);

    // DETALLE (file + cotización completa de UNA versión)
    const detalle = ref<PaxCotizacionFile | null>(null);
    const currentVersion = ref<number | null>(null);
    const lastUpdateDetalle = ref<number>(0);

    const currentLocalizador = ref<string | null>(null);
    const loading = ref(false);
    const error = ref<string | null>(null);

    const CACHE_TTL = 30000; // 30 segundos

    // Request Deduplication (una por tipo de carga)
    let portadaPromise: Promise<void> | null = null;
    let detallePromise: Promise<void> | null = null;

    // ── Helpers internos ──────────────────────────────────────────────────

    const asegurarMaestro = async () => {
        if (maestroStore.idiomas.length === 0) {
            await maestroStore.cargarConfiguracion();
        }
    };

    const manejarError = (err: any, teniamosDatos: boolean) => {
        console.error('❌ PaxCotizacionStore:', err);
        if (teniamosDatos) {
            error.value = 'No se pudo actualizar, mostrando última versión guardada.';
            return;
        }
        error.value = err?.response?.status === 404
            ? 'Localizador o propuesta no encontrada.'
            : (err.message || 'Error de conexión crítico.');
        throw err;
    };

    // ── Acciones ──────────────────────────────────────────────────────────

    /**
     * Carga la PORTADA del expediente (cards de propuestas públicas vigentes).
     * Caché 30s + dedup + retención offline, mismo patrón que pmsGuiaStore.
     *
     * @param {string} localizador Código localizador del expediente.
     */
    const cargarPortada = async (localizador: string): Promise<void> => {
        const ahora = Date.now();
        const hayInternet = navigator.onLine;
        const datosExisten = portada.value !== null && currentLocalizador.value === localizador;
        const esFresco = (ahora - lastUpdatePortada.value) < CACHE_TTL;

        if (datosExisten && !hayInternet) {
            console.warn('⚠️ PaxCotizacionStore: Sin conexión. Reteniendo portada.');
            return;
        }
        if (datosExisten && esFresco) return;
        if (portadaPromise) return portadaPromise;

        loading.value = true;
        portadaPromise = (async () => {
            try {
                await asegurarMaestro();
                const data = await paxCotizacionService.getFilePortada(localizador);

                // Cambió de expediente → invalidar detalle previo
                if (currentLocalizador.value !== localizador) {
                    detalle.value = null;
                    currentVersion.value = null;
                    lastUpdateDetalle.value = 0;
                }

                portada.value = data;
                currentLocalizador.value = localizador;
                lastUpdatePortada.value = Date.now();
                error.value = null;
            } catch (err: any) {
                if (!datosExisten) { portada.value = null; currentLocalizador.value = null; }
                manejarError(err, datosExisten);
            } finally {
                loading.value = false;
                portadaPromise = null;
            }
        })();
        return portadaPromise;
    };

    /**
     * Carga el DETALLE de una propuesta concreta (guía día a día).
     * Sincroniza el idioma preferido del cliente al abrirla por primera vez.
     *
     * @param {string} localizador Código localizador del expediente.
     * @param {number} version Número de versión de la propuesta.
     */
    const cargarVersion = async (localizador: string, version: number): Promise<void> => {
        const ahora = Date.now();
        const hayInternet = navigator.onLine;
        const datosExisten = detalle.value !== null
            && currentLocalizador.value === localizador
            && currentVersion.value === version;
        const esFresco = (ahora - lastUpdateDetalle.value) < CACHE_TTL;

        if (datosExisten && !hayInternet) {
            console.warn('⚠️ PaxCotizacionStore: Sin conexión. Reteniendo detalle.');
            return;
        }
        if (datosExisten && esFresco) return;
        if (detallePromise) return detallePromise;

        loading.value = true;
        detallePromise = (async () => {
            try {
                await asegurarMaestro();
                const data = await paxCotizacionService.getFileVersion(localizador, version);

                detalle.value = data;
                currentLocalizador.value = localizador;
                currentVersion.value = version;
                lastUpdateDetalle.value = Date.now();
                error.value = null;

                // 🌐 Idioma preferido del cliente (solo si no eligió uno manualmente)
                const idiomaCliente = data.cotizacionParaCliente?.idiomaCliente;
                if (idiomaCliente && maestroStore.idiomaActual !== idiomaCliente && !localStorage.getItem('paxIdiomaManual')) {
                    maestroStore.setIdioma(idiomaCliente);
                }
            } catch (err: any) {
                if (!datosExisten) { detalle.value = null; currentVersion.value = null; }
                manejarError(err, datosExisten);
            } finally {
                loading.value = false;
                detallePromise = null;
            }
        })();
        return detallePromise;
    };

    // ── Getters derivados ─────────────────────────────────────────────────

    /** File "vigente" para cabecera (detalle si está cargado, sino portada) */
    const file = computed<PaxCotizacionFile | null>(() => detalle.value ?? portada.value);

    /** Cards de propuestas públicas (portada) */
    const versiones = computed<PaxVersionResumen[]>(() => file.value?.versionesParaCliente ?? []);

    /** Cotización completa de la versión abierta */
    const cotizacion = computed<PaxCotizacion | null>(() => detalle.value?.cotizacionParaCliente ?? null);

    const documentos = computed(() => file.value?.documentosParaCliente ?? []);
    const pasajeros = computed(() => file.value?.filepasajeros ?? []);

    /** Desglose incluye/no incluye por servicio (versión cliente, sin costos) */
    const inclusiones = computed<PaxInclusionServicio[]>(
        () => cotizacion.value?.clasificacionFinancieraCliente?.inclusiones ?? []
    );

    const precioVisible = computed(() => cotizacion.value ? !cotizacion.value.precioOculto : false);

    const totalVenta = computed(() => ({
        monto: cotizacion.value?.totalVenta ?? '0.00',
        moneda: cotizacion.value?.monedaGlobal ?? 'USD',
        adelanto: cotizacion.value?.adelanto ?? '0.00',
    }));

    /**
     * Itinerario de la versión abierta agrupado por fecha, con referencia al
     * servicio padre y a los componentes vinculados a cada segmento.
     */
    const itinerario = computed<PaxDiaItinerario[]>(() => {
        const cot = cotizacion.value;
        if (!cot) return [];

        const porFecha = new Map<string, PaxSegmentoConServicio[]>();

        for (const servicio of cot.cotservicios ?? []) {
            for (const segmento of servicio.cotsegmentos ?? []) {
                const fecha = (segmento.fechaAbsoluta || '').substring(0, 10);
                if (!fecha) continue;

                const componentes = (servicio.cotcomponentes ?? [])
                    .filter(c => c.cotsegmento?.id === segmento.id);

                if (!porFecha.has(fecha)) porFecha.set(fecha, []);
                porFecha.get(fecha)!.push({ segmento, servicio, componentes });
            }
        }

        return [...porFecha.entries()]
            .sort(([a], [b]) => a.localeCompare(b))
            .map(([fecha, segmentos], idx) => ({
                fecha,
                numeroDia: idx + 1,
                segmentos: segmentos.sort(
                    (a, b) => (a.segmento.dia - b.segmento.dia) || (a.segmento.orden - b.segmento.orden)
                ),
            }));
    });

    // ── Utilidades ────────────────────────────────────────────────────────

    /**
     * Extrae el string traducido según el idioma actual, con fallback
     * en → es → primer elemento (mismo criterio que pmsGuiaStore).
     */
    const traducir = (contenido: I18n | undefined | null): string => {
        if (!contenido || !Array.isArray(contenido) || contenido.length === 0) return '';
        const idioma = maestroStore.idiomaActual;
        const match = contenido.find(c => c.language === idioma);
        if (match?.content) return match.content;
        const fallback = contenido.find(c => c.language === 'en') || contenido.find(c => c.language === 'es');
        return fallback?.content || contenido[0].content || '';
    };

    return {
        // estado
        portada, detalle, loading, error,
        currentLocalizador, currentVersion,
        lastUpdatePortada, lastUpdateDetalle,
        // getters
        file, versiones, cotizacion, documentos, pasajeros,
        inclusiones, precioVisible, totalVenta, itinerario,
        // acciones
        cargarPortada, cargarVersion, traducir,
    };
}, {
    persist: {
        paths: [
            'portada', 'detalle', 'currentLocalizador', 'currentVersion',
            'lastUpdatePortada', 'lastUpdateDetalle',
        ],
        storage: localStorage,
    } as PersistenceOptions
});
