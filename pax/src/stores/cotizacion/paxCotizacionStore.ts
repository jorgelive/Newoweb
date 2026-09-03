// src/stores/pax/paxCotizacionStore.ts
import axios from 'axios';
import { defineStore } from 'pinia';
import { ref, computed } from 'vue';
import { paxCotizacionService } from '@/services/paxCotizacionService';
import { useMaestroStore } from '../maestroStore';
import type {
    PaxCotizacionFile,
    PaxCotizacion,
    PaxCotServicio,
    PaxCotComponente,
    I18n,
    PaxDiaItinerario,
    PaxSegmentoConServicio,
    PaxInclusionServicio,
    PaxPropuestaResumen,
    PaxCatalogo,
    PaxTourResumen,
    PaxOpcionUpgrade,
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

    /**
     * La propuesta pedida existe, pero hay que decir quién eres.
     *
     * ⚠️ **Es un estado, no un error.** Sólo pasa en la OPERATIVA de un expediente de grupo, que
     * lleva datos por persona —tu vuelo, tu código—. Lo comercial (confirmadas, históricas) se
     * navega entero sin identificarse.
     *
     * Lo distingue del 404 el código `IDENTIFICACION_REQUERIDA` que manda el servidor: mirar el
     * texto del mensaje sería atarse a una traducción.
     */
    const requiereIdentificacion = ref(false);
    const identificadoComo = ref<string | null>(null);

    // CATÁLOGO DE TOURS (escaparate público). El detalle de un tour reusa
    // el ref `detalle` para que la guía día a día funcione sin cambios.
    const portadaCatalogo = ref<PaxCatalogo | null>(null);
    const lastUpdatePortadaCatalogo = ref<number>(0);
    const esCatalogo = ref(false);

    const CACHE_TTL = 30000; // 30 segundos

    // Request Deduplication (una por tipo de carga)
    let portadaPromise: Promise<void> | null = null;
    let detallePromise: Promise<void> | null = null;
    let portadaCatalogoPromise: Promise<void> | null = null;

    // ── Helpers internos ──────────────────────────────────────────────────

    const asegurarMaestro = async () => {
        if (maestroStore.idiomas.length === 0) {
            await maestroStore.cargarConfiguracion();
        }
    };

    const manejarError = (err: unknown, teniamosDatos: boolean) => {
        // ⚠️ ANTES que nada: un 403 con este código no es un fallo, es una puerta. Si cayera en
        // las ramas de abajo, el cliente vería «error de conexión» delante de algo que sí existe
        // y a lo que sí tiene derecho.
        if (axios.isAxiosError(err) && err.response?.status === 403) {
            const detalleErr = err.response.data as { detail?: string; 'hydra:description'?: string } | undefined;
            const texto = `${detalleErr?.detail ?? ''} ${detalleErr?.['hydra:description'] ?? ''}`;

            if (texto.includes('IDENTIFICACION_REQUERIDA')) {
                requiereIdentificacion.value = true;
                error.value = null;

                return;
            }
        }

        console.error('❌ PaxCotizacionStore:', err);
        if (teniamosDatos) {
            error.value = 'No se pudo actualizar, mostrando última versión guardada.';
            return;
        }
        const esNoEncontrado = axios.isAxiosError(err) && err.response?.status === 404;
        error.value = esNoEncontrado
            ? 'Localizador o propuesta no encontrada.'
            : ((err as Error)?.message || 'Error de conexión crítico.');
        throw err;
    };

    /**
     * Manda documento + fecha de nacimiento y, si cuadran, abre la operativa.
     *
     * ⚠️ La identidad queda en la **sesión del servidor**, no aquí: `pax` sólo se entera de si
     * entró. Guardarla en el cliente la haría reenviable, que es justo lo que esto limita.
     */
    const identificarse = async (localizador: string, documento: string, fechaNacimiento: string): Promise<string | null> => {
        try {
            const nombre = await paxCotizacionService.identificar(localizador, documento, fechaNacimiento);

            requiereIdentificacion.value = false;
            identificadoComo.value = nombre;

            // ⚠️ **Invalidar la caché a mano.** `pax` retiene el detalle 30 s, y si el 403 llegó
            // teniendo una copia guardada de esa misma propuesta, `cargarPropuesta()` la daría por
            // fresca y volvería sin pedir nada: el cliente se identificaría correctamente y
            // seguiría sin ver su viaje, sin ningún error que lo explicara.
            lastUpdateDetalle.value = 0;

            return null;
        } catch (err: unknown) {
            if (axios.isAxiosError(err)) {
                const cuerpo = err.response?.data as { mensaje?: string } | undefined;

                return cuerpo?.mensaje ?? 'No pudimos comprobar tus datos. Inténtalo de nuevo.';
            }

            return 'No pudimos comprobar tus datos. Inténtalo de nuevo.';
        }
    };

    // ── Acciones ──────────────────────────────────────────────────────────

    /**
     * Carga la PORTADA del expediente (cards de propuestas públicas vigentes).
     * Caché 30s + dedup + retención offline, mismo patrón que pmsGuiaStore.
     *
     * @param {string} localizador Código localizador del expediente.
     */
    const cargarPortada = async (localizador: string): Promise<void> => {
        // ⚠️ **Se limpia en CADA carga.** Sólo se ponía a `true` y sólo se bajaba al identificarse,
        // así que tras un 403 en una operativa el formulario se quedaba pegado: «Volver» y abrir
        // otra propuesta —u otro expediente, o un tour del catálogo— seguía pintándolo encima de
        // una carga que había ido bien. Un estado que sólo sube no es un estado: es una marca.
        requiereIdentificacion.value = false;

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

                // Aplicar idioma predeterminado del expediente si no hay selección manual
                const idiomaFile = data.idiomaCliente;
                if (idiomaFile && maestroStore.idiomaActual !== idiomaFile && !localStorage.getItem('paxIdiomaManual')) {
                    maestroStore.setIdioma(idiomaFile);
                }
            } catch (err: unknown) {
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
     * @param {number} propuesta Número de versión de la propuesta.
     */
/**
 * Reordena los componentes de cada servicio para CONTAR el viaje.
 *
 * ⚠️ El backend los sirve por `fechaHoraInicio`, y como el check-in de un hotel es a media tarde,
 * el alojamiento caía **en medio del día** en vez de cerrarlo. Nadie cuenta un día así: se llega,
 * se hace lo del día, se come y se duerme.
 *
 * ⚠️ **El criterio no se escribe aquí**: cada componente trae `ordenNarrativo`, que lo decide
 * `ComponenteTipoEnum::ordenNarrativo()` en PHP. Ésta es la mitad `pax/` de un par —la otra está
 * en `util/src/stores/cotizacion/cotizacionEditorStore.ts`— y por eso ninguna de las dos lleva los
 * números: son dos apps que no comparten código, y dos copias de una regla acaban discrepando.
 *
 * Se ordena por DÍA primero: un servicio puede cruzar jornadas y el relato es por día. A igual día
 * y rango, manda la hora real.
 */
const ordenarComponentesPorRelato = (data: PaxCotizacionFile | null): void => {
    // La versión completa sólo viene cuando la URL lleva /{propuesta}; en la portada no hay nada
    // que ordenar y el `?.` se encarga.
    [data?.cotizacionParaCliente].forEach((cot: PaxCotizacion | null | undefined) => {
        cot?.cotservicios?.forEach((servicio: PaxCotServicio) => {
            servicio.cotcomponentes?.sort((a: PaxCotComponente, b: PaxCotComponente) => {
                const diaA = (a.fechaHoraInicio ?? '').slice(0, 10);
                const diaB = (b.fechaHoraInicio ?? '').slice(0, 10);

                if (diaA !== diaB) return diaA.localeCompare(diaB);

                const rangoA = a.ordenNarrativo ?? 30;
                const rangoB = b.ordenNarrativo ?? 30;

                if (rangoA !== rangoB) return rangoA - rangoB;

                return String(a.fechaHoraInicio ?? '').localeCompare(String(b.fechaHoraInicio ?? ''));
            });
        });
    });
};

    const cargarPropuesta = async (localizador: string, propuesta: number): Promise<void> => {
        // ⚠️ **Se limpia en CADA carga.** Sólo se ponía a `true` y sólo se bajaba al identificarse,
        // así que tras un 403 en una operativa el formulario se quedaba pegado: «Volver» y abrir
        // otra propuesta —u otro expediente, o un tour del catálogo— seguía pintándolo encima de
        // una carga que había ido bien. Un estado que sólo sube no es un estado: es una marca.
        requiereIdentificacion.value = false;

        const ahora = Date.now();
        const hayInternet = navigator.onLine;
        const datosExisten = detalle.value !== null
            && currentLocalizador.value === localizador
            && currentVersion.value === propuesta;
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
                const data = await paxCotizacionService.getFileVersion(localizador, propuesta);

                ordenarComponentesPorRelato(data);
                detalle.value = data;
                currentLocalizador.value = localizador;
                currentVersion.value = propuesta;
                lastUpdateDetalle.value = Date.now();
                error.value = null;

                // 🌐 Idioma preferido del cliente (solo si no eligió uno manualmente)
                const idiomaCliente = data.cotizacionParaCliente?.idiomaCliente;
                if (idiomaCliente && maestroStore.idiomaActual !== idiomaCliente && !localStorage.getItem('paxIdiomaManual')) {
                    maestroStore.setIdioma(idiomaCliente);
                }
            } catch (err: unknown) {
                if (!datosExisten) { detalle.value = null; currentVersion.value = null; }
                manejarError(err, datosExisten);
            } finally {
                loading.value = false;
                detallePromise = null;
            }
        })();
        return detallePromise;
    };

    /**
     * Carga la PORTADA del catálogo de tours (escaparate).
     * Mismo patrón de caché/dedup que la portada de expediente.
     *
     * @param {string} localizador Código localizador del catálogo.
     */
    const cargarPortadaCatalogo = async (localizador: string): Promise<void> => {
        const ahora = Date.now();
        const hayInternet = navigator.onLine;
        const datosExisten = portadaCatalogo.value !== null && currentLocalizador.value === localizador;
        const esFresco = (ahora - lastUpdatePortadaCatalogo.value) < CACHE_TTL;

        if (datosExisten && !hayInternet) return;
        if (datosExisten && esFresco) return;
        if (portadaCatalogoPromise) return portadaCatalogoPromise;

        loading.value = true;
        portadaCatalogoPromise = (async () => {
            try {
                await asegurarMaestro();
                const data = await paxCotizacionService.getCatalogoPortada(localizador);

                if (currentLocalizador.value !== localizador) {
                    detalle.value = null;
                    currentVersion.value = null;
                    lastUpdateDetalle.value = 0;
                }

                portadaCatalogo.value = data;
                esCatalogo.value = true;
                currentLocalizador.value = localizador;
                lastUpdatePortadaCatalogo.value = Date.now();
                error.value = null;

                // Idioma predeterminado del catálogo (sin selección manual previa)
                const idiomaCat = data.idiomaCliente;
                if (idiomaCat && maestroStore.idiomaActual !== idiomaCat && !localStorage.getItem('paxIdiomaManual')) {
                    maestroStore.setIdioma(idiomaCat);
                }
            } catch (err: unknown) {
                if (!datosExisten) { portadaCatalogo.value = null; }
                manejarError(err, datosExisten);
            } finally {
                loading.value = false;
                portadaCatalogoPromise = null;
            }
        })();
        return portadaCatalogoPromise;
    };

    /**
     * Carga el DETALLE de un tour del catálogo. Llena el mismo ref `detalle`
     * (con `nombre` alias de `nombreGrupo`) para que la guía día a día y sus
     * getters (`cotizacion`, `itinerario`, etc.) funcionen sin cambios.
     *
     * @param {string} localizador Código localizador del catálogo.
     * @param {number} propuesta Número de tour dentro del catálogo.
     */
    const cargarPropuestaCatalogo = async (localizador: string, propuesta: number): Promise<void> => {
        // ⚠️ **Se limpia en CADA carga.** Sólo se ponía a `true` y sólo se bajaba al identificarse,
        // así que tras un 403 en una operativa el formulario se quedaba pegado: «Volver» y abrir
        // otra propuesta —u otro expediente, o un tour del catálogo— seguía pintándolo encima de
        // una carga que había ido bien. Un estado que sólo sube no es un estado: es una marca.
        requiereIdentificacion.value = false;

        const ahora = Date.now();
        const hayInternet = navigator.onLine;
        const datosExisten = detalle.value !== null
            && currentLocalizador.value === localizador
            && currentVersion.value === propuesta
            && esCatalogo.value;
        const esFresco = (ahora - lastUpdateDetalle.value) < CACHE_TTL;

        if (datosExisten && !hayInternet) return;
        if (datosExisten && esFresco) return;
        if (detallePromise) return detallePromise;

        loading.value = true;
        detallePromise = (async () => {
            try {
                await asegurarMaestro();
                const data = await paxCotizacionService.getCatalogoVersion(localizador, propuesta);

                detalle.value = { ...data, nombreGrupo: data.nombre } as unknown as PaxCotizacionFile;
                esCatalogo.value = true;
                currentLocalizador.value = localizador;
                currentVersion.value = propuesta;
                lastUpdateDetalle.value = Date.now();
                error.value = null;

                const idiomaTour = data.cotizacionParaCliente?.idiomaCliente;
                if (idiomaTour && maestroStore.idiomaActual !== idiomaTour && !localStorage.getItem('paxIdiomaManual')) {
                    maestroStore.setIdioma(idiomaTour);
                }
            } catch (err: unknown) {
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
    const propuestas = computed<PaxPropuestaResumen[]>(() => file.value?.propuestasParaCliente ?? []);

    /** Cards de tours del catálogo, ya ordenadas por el backend (orden, propuesta) */
    const tours = computed<PaxTourResumen[]>(() => portadaCatalogo.value?.toursParaCliente ?? []);

    /** Cotización completa de la versión abierta */
    const cotizacion = computed<PaxCotizacion | null>(() => detalle.value?.cotizacionParaCliente ?? null);

    /**
     * Lo que es TUYO en este viaje: tu nombre y tus códigos. `null` si no te has identificado o si
     * el expediente no lo pide.
     *
     * ⚠️ Sale del **detalle** y, si no, de la portada: identificarse vale para el expediente
     * entero, así que tu localizador es tuyo también mirando la lista de propuestas.
     */
    const miIdentidad = computed(() => detalle.value?.miIdentidad ?? portada.value?.miIdentidad ?? null);

    const documentos = computed(() => file.value?.documentosParaCliente ?? []);
    const pasajeros = computed(() => file.value?.filepasajeros ?? []);

    /** Desglose incluye/no incluye por servicio (versión cliente, sin costos) */
    const inclusiones = computed<PaxInclusionServicio[]>(
        () => cotizacion.value?.clasificacionFinancieraCliente?.inclusiones ?? []
    );

    /**
     * Opciones de upgrade agrupadas por escenario. Devuelve la estructura
     * (esOpcion + índice) para que la vista componga la etiqueta traducida
     * ("Alternativa N" u "Opción N"). Todas las opciones del mismo escenario
     * trabajan juntas aunque provengan de componentes distintos.
     */
    const gruposUpgrade = computed<{ esOpcion: boolean; indice: number; opciones: PaxOpcionUpgrade[] }[]>(() => {
        const list = cotizacion.value?.clasificacionFinancieraCliente?.opcionesUpgrade ?? [];
        const mapa = new Map<string, { esOpcion: boolean; indice: number; opciones: PaxOpcionUpgrade[] }>();
        list.forEach((o) => {
            const grupo = o.grupoTarifa ?? 0;
            const indice = o.esOpcion ? grupo : Math.max(grupo - 1, 0);
            const key = `${o.esOpcion ? 'o' : 'a'}${indice}`;
            if (!mapa.has(key)) mapa.set(key, { esOpcion: !!o.esOpcion, indice, opciones: [] });
            mapa.get(key)!.opciones.push(o);
        });
        return [...mapa.values()].sort((a, b) =>
            a.esOpcion === b.esOpcion ? a.indice - b.indice : (a.esOpcion ? 1 : -1)
        );
    });

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
        portada, detalle, loading, error, miIdentidad,
        requiereIdentificacion, identificadoComo, identificarse,
        currentLocalizador, currentVersion,
        lastUpdatePortada, lastUpdateDetalle,
        portadaCatalogo, lastUpdatePortadaCatalogo, esCatalogo,
        // getters
        file, propuestas, tours, cotizacion, documentos, pasajeros,
        inclusiones, gruposUpgrade, precioVisible, totalVenta, itinerario,
        // acciones
        cargarPortada, cargarPropuesta, traducir,
        cargarPortadaCatalogo, cargarPropuestaCatalogo,
    };
}, {
    persist: {
        paths: [
            'portada', 'detalle', 'currentLocalizador', 'currentVersion',
            'lastUpdatePortada', 'lastUpdateDetalle',
            'portadaCatalogo', 'lastUpdatePortadaCatalogo', 'esCatalogo',
        ],
        storage: localStorage,
    } as PersistenceOptions
});
