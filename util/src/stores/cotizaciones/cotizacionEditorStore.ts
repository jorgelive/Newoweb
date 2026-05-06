// src/stores/cotizaciones/cotizacionEditorStore.ts
import { defineStore } from 'pinia';
import { ref, computed } from 'vue';
import { apiClient } from '@/services/apiClient';

export type NivelInspector = 'resumen' | 'servicio' | 'componente' | 'tarifa';

export interface I18nString {
    language: string;
    content: string;
}

export interface MaestroIdioma {
    id: string;
    nombre: string;
    bandera: string | null;
    prioridad: number;
}

export const useCotizacionEditorStore = defineStore('cotizacionEditorStore', () => {

    const isLoading = ref<boolean>(false);
    const idiomasDisponibles = ref<MaestroIdioma[]>([]);

    const catalogos = ref({
        servicios: [] as any[],
        allComponentes: [] as any[],
        componentes: [] as any[],
        tarifas: [] as any[],
        plantillasItinerario: [] as any[],
        poolSegmentos: [] as any[]
    });

    const cotizacion = ref<any>(null);

    // ============================================================================
    // 🔥 HELPERS INVENCIBLES (Defensas contra datos incompletos del backend)
    // ============================================================================

    // Convierte arreglos de URLs (IRIs) en objetos reales de forma automática
    const hydrateRelations = async (items: any[]) => {
        if (!items || !Array.isArray(items) || items.length === 0) return [];
        if (typeof items[0] === 'string') {
            const responses = await Promise.all(items.map(iri => apiClient.get(iri)));
            return responses.map(res => res.data);
        }
        return items;
    };

    // Previene que se borren los textos si el array 'titulo' llega vacío []
    const getTituloSafe = (entity: any) => {
        if (entity.titulo && Array.isArray(entity.titulo) && entity.titulo.length > 0) {
            return entity.titulo;
        }
        return [{ language: 'es', content: entity.nombreInterno || entity.nombre || 'Item sin nombre' }];
    };

    // ============================================================================

    const inicializarEditor = async (cotizacionId?: string) => {
        isLoading.value = true;
        try {
            await fetchIdiomas();
            await fetchCatalogos();

            if (cotizacionId) {
                await fetchCotizacion(cotizacionId);
            } else {
                crearCotizacionVacia();
            }
        } catch (error) {
            console.error("Error al inicializar el motor operativo:", error);
            alert("Error al cargar los datos del servidor.");
        } finally {
            isLoading.value = false;
        }
    };

    const fetchIdiomas = async () => {
        try {
            const response = await apiClient.get('/platform/maestro/idiomas?prioridad[gt]=0&order[prioridad]=asc');
            idiomasDisponibles.value = response.data['hydra:member'] || response.data['member'] || [];
        } catch (e) {
            idiomasDisponibles.value = [
                {id: 'es', nombre: 'Español', bandera: '🇪🇸', prioridad: 1}
            ];
        }
    };

    const fetchCatalogos = async () => {
        try {
            const [resServicios, resComponentes] = await Promise.all([
                apiClient.get('/platform/travel/servicios?pagination=false'),
                apiClient.get('/platform/travel/componentes?pagination=false')
            ]);

            catalogos.value.servicios = resServicios.data['hydra:member'] || resServicios.data['member'] || [];
            catalogos.value.allComponentes = resComponentes.data['hydra:member'] || resComponentes.data['member'] || [];

            // Iniciamos con todos para que el usuario pueda ver el catálogo global si no selecciona servicio
            catalogos.value.componentes = catalogos.value.allComponentes;
        } catch (e) {
            console.warn("No se pudo cargar los catálogos base.", e);
        }
    };

    const fetchServicioDetalles = async (servicioIriOrId: string) => {
        try {
            // Asegura que la ruta sea válida sea UUID o IRI
            const endpoint = servicioIriOrId.startsWith('/') ? servicioIriOrId : `/platform/travel/servicios/${servicioIriOrId}`;
            const response = await apiClient.get(endpoint);
            const data = response.data;

            // 🔥 1. FILTRO DE COMPONENTES REPARADO
            if (data.componentes && data.componentes.length > 0) {
                if (typeof data.componentes[0] === 'string') {
                    // Cruza los IRIs del backend con la memoria RAM para filtrar
                    catalogos.value.componentes = catalogos.value.allComponentes.filter((c: any) =>
                        data.componentes.some((iri: string) => iri === c['@id'] || iri.includes(c.id))
                    );
                } else {
                    catalogos.value.componentes = data.componentes;
                }
            } else {
                // Si el servicio no tiene logística amarrada en la BD, mostramos todo el catálogo
                catalogos.value.componentes = catalogos.value.allComponentes;
            }

            // 🔥 2. HIDRATACIÓN OBLIGATORIA DE STORYTELLING
            catalogos.value.plantillasItinerario = await hydrateRelations(data.itinerarios);
            catalogos.value.poolSegmentos = await hydrateRelations(data.segmentos);

        } catch (e) {
            console.error("🚨 Error al cargar detalles del servicio maestro", e);
        }
    };

    const fetchComponenteDetalles = async (componenteIriOrId: string) => {
        try {
            const endpoint = componenteIriOrId.startsWith('/') ? componenteIriOrId : `/platform/travel/componentes/${componenteIriOrId}`;
            const response = await apiClient.get(endpoint);
            // Hidrata las tarifas en caso de que vengan como strings
            catalogos.value.tarifas = await hydrateRelations(response.data.tarifas);
        } catch (e) {
            console.error("Error al cargar detalles del componente maestro", e);
        }
    };

    const fetchCotizacion = async (id: string) => {
        try {
            const response = await apiClient.get(`/platform/sales/cotizacions/${id}`);
            cotizacion.value = response.data;
            cotizacion.value.idiomaEdicion = 'es';
        } catch (e) {
            throw new Error("No se encontró la cotización");
        }
    };

    const crearCotizacionVacia = () => {
        cotizacion.value = {
            version: 1, estado: 'Pendiente', monedaGlobal: 'USD',
            idiomaCliente: idiomasDisponibles.value.length ? idiomasDisponibles.value[0].id : 'es',
            idiomaEdicion: 'es', numPax: 1, comision: 0.00, adelanto: 0.00,
            hotelOculto: true, precioOculto: false, resumenI18n: [],
            itinerario: [{diaNumero: 1, fechaAbsoluta: new Date().toISOString().split('T')[0], cotservicios: []}]
        };
    };

    const guardarCotizacion = async (): Promise<void> => {
        isLoading.value = true;
        try {
            const isUpdate = !!cotizacion.value.id;
            const endpoint = isUpdate ? `/platform/sales/cotizacions/${cotizacion.value.id}` : `/platform/sales/cotizacions`;
            const payload = JSON.parse(JSON.stringify(cotizacion.value));
            delete payload.idiomaEdicion;

            const response = await (isUpdate ? apiClient.put : apiClient.post)(endpoint, payload);
            const savedData = response.data;
            savedData.idiomaEdicion = 'es';
            cotizacion.value = savedData;
            alert('Cotización guardada exitosamente.');
        } catch (error) {
            alert('Falló la sincronización con la base de datos (Ver consola).');
        } finally {
            isLoading.value = false;
        }
    };

    const totalCostoNeto = computed(() => {
        if (!cotizacion.value) return 0;
        let total = 0;
        cotizacion.value.itinerario.forEach((dia: any) => {
            dia.cotservicios.forEach((servicio: any) => {
                servicio.cotcomponentes.forEach((componente: any) => {
                    if (componente.modo === 'incluido' && componente.estado !== 'Cancelado') {
                        componente.cottarifas.forEach((tarifa: any) => {
                            total += (parseFloat(tarifa.montoCosto) * tarifa.cantidad);
                        });
                    }
                });
            });
        });
        return total;
    });

    const ventaSugerida = computed(() => {
        if (!cotizacion.value) return 0;
        return totalCostoNeto.value * (1 + (parseFloat(cotizacion.value.comision) / 100));
    });

    const getI18n = (arrayI18n: I18nString[] | undefined, lang: string): I18nString => {
        if (!arrayI18n) return {language: lang, content: ''};
        let found = arrayI18n.find(item => item.language === lang);
        if (!found) {
            found = {language: lang, content: ''};
            arrayI18n.push(found);
        }
        return found;
    };

    // 🔥 Limpio: Si no hay traducción, devuelve '' para que Vue use el fallback (|| cat.nombreInterno)
    const renderI18n = (arrayI18n: I18nString[] | undefined, lang: string): string => {
        if (!arrayI18n || !Array.isArray(arrayI18n)) return '';
        const found = arrayI18n.find(item => item.language === lang && item.content.trim() !== '');
        return found ? found.content : '';
    };

    const inspectorActivo = ref<NivelInspector>('resumen');
    const dataActiva = ref<any>(null);
    const historialNavegacion = ref<{ nivel: NivelInspector, data: any }[]>([]);
    const isMobileOpen = ref<boolean>(false);
    const isSegmentEditorOpen = ref<boolean>(false);

    const abrirNivel = async (nivel: NivelInspector, data: any = null): Promise<void> => {
        if (nivel === 'servicio' || nivel === 'resumen') historialNavegacion.value = [];
        else historialNavegacion.value.push({nivel: inspectorActivo.value, data: dataActiva.value});

        inspectorActivo.value = nivel;
        dataActiva.value = data;
        isMobileOpen.value = true;

        if (nivel === 'servicio' && data?.servicioMaestroId) await fetchServicioDetalles(data.servicioMaestroId);
        if (nivel === 'componente' && data?.componenteMaestroId) await fetchComponenteDetalles(data.componenteMaestroId);
    };

    const retrocederNivel = (): void => {
        if (historialNavegacion.value.length > 0) {
            const previo = historialNavegacion.value.pop()!;
            inspectorActivo.value = previo.nivel;
            dataActiva.value = previo.data;
        } else {
            inspectorActivo.value = 'resumen';
            dataActiva.value = null;
            isMobileOpen.value = false;
        }
    };

    const cerrarInspectorMobile = (): void => {
        isMobileOpen.value = false;
        setTimeout(() => {
            inspectorActivo.value = 'resumen';
            dataActiva.value = null;
            historialNavegacion.value = [];
        }, 300);
    };

    const verificarInspectorTrasBorrado = (idBorrado: string) => {
        if (dataActiva.value && dataActiva.value.id === idBorrado) retrocederNivel();
    };

    const agregarServicio = (diaNumero: number): void => {
        const dia = cotizacion.value.itinerario.find((d: any) => d.diaNumero === diaNumero);
        if (dia) {
            const nuevoServicio = {
                id: crypto.randomUUID(), servicioMaestroId: null,
                nombreSnapshot: [{language: 'es', content: 'Nuevo Servicio'}],
                itinerarioNombreSnapshot: [{language: 'es', content: 'Sin plantilla'}],
                fechaInicioAbsoluta: dia.fechaAbsoluta, cotsegmentos: [], cotcomponentes: []
            };
            dia.cotservicios.push(nuevoServicio as any);
            abrirNivel('servicio', nuevoServicio);
        }
    };

    const eliminarServicio = (diaNumero: number, servicioId: string): void => {
        const dia = cotizacion.value.itinerario.find((d: any) => d.diaNumero === diaNumero);
        if (dia) {
            dia.cotservicios = dia.cotservicios.filter((s: any) => s.id !== servicioId);
            verificarInspectorTrasBorrado(servicioId);
        }
    };

    const agregarComponente = (servicioId: string): void => {
        for (const dia of cotizacion.value.itinerario) {
            const servicio = dia.cotservicios.find((s: any) => s.id === servicioId);
            if (servicio) {
                const nuevoComponente = {
                    id: crypto.randomUUID(), componenteMaestroId: null,
                    nombreSnapshot: [{language: 'es', content: 'Nuevo Componente'}],
                    cantidad: 1, estado: 'Pendiente', modo: 'incluido',
                    fechaHoraInicio: `${servicio.fechaInicioAbsoluta}T08:00`,
                    fechaHoraFin: `${servicio.fechaInicioAbsoluta}T09:00`,
                    cotsegmentoId: null, snapshotItems: [], cottarifas: []
                };
                servicio.cotcomponentes.push(nuevoComponente as any);
                abrirNivel('componente', nuevoComponente);
                return;
            }
        }
    };

    const eliminarComponente = (servicioId: string, componenteId: string): void => {
        for (const dia of cotizacion.value.itinerario) {
            const servicio = dia.cotservicios.find((s: any) => s.id === servicioId);
            if (servicio) {
                servicio.cotcomponentes = servicio.cotcomponentes.filter((c: any) => c.id !== componenteId);
                verificarInspectorTrasBorrado(componenteId);
                return;
            }
        }
    };

    const agregarTarifa = (componenteId: string): void => {
        for (const dia of cotizacion.value.itinerario) {
            for (const servicio of dia.cotservicios) {
                const componente = servicio.cotcomponentes.find((c: any) => c.id === componenteId);
                if (componente) {
                    const nuevaTarifa = {
                        id: crypto.randomUUID(), tarifaMaestraId: null,
                        nombreSnapshot: [{language: 'es', content: 'Nueva Tarifa'}],
                        cantidad: cotizacion.value.numPax, moneda: cotizacion.value.monedaGlobal,
                        montoCosto: 0.00, tipoModalidadSnapshot: 'Normal',
                        proveedorNombreSnapshot: null, detallesOperativos: []
                    };
                    componente.cottarifas.push(nuevaTarifa as any);
                    abrirNivel('tarifa', nuevaTarifa);
                    return;
                }
            }
        }
    };

    const eliminarTarifa = (componenteId: string, tarifaId: string): void => {
        for (const dia of cotizacion.value.itinerario) {
            for (const servicio of dia.cotservicios) {
                const componente = servicio.cotcomponentes.find((c: any) => c.id === componenteId);
                if (componente) {
                    componente.cottarifas = componente.cottarifas.filter((t: any) => t.id !== tarifaId);
                    verificarInspectorTrasBorrado(tarifaId);
                    return;
                }
            }
        }
    };

    const agregarDetalleOperativo = (tarifaId: string): void => {
        for (const dia of cotizacion.value.itinerario) {
            for (const servicio of dia.cotservicios) {
                for (const componente of servicio.cotcomponentes) {
                    const tarifa = componente.cottarifas.find((t: any) => t.id === tarifaId);
                    if (tarifa) {
                        tarifa.detallesOperativos.push({id: crypto.randomUUID(), tipo: 'Info', contenido: ''});
                        return;
                    }
                }
            }
        }
    };

    const eliminarDetalleOperativo = (tarifaId: string, detalleId: string): void => {
        for (const dia of cotizacion.value.itinerario) {
            for (const servicio of dia.cotservicios) {
                for (const componente of servicio.cotcomponentes) {
                    const tarifa = componente.cottarifas.find((t: any) => t.id === tarifaId);
                    if (tarifa) {
                        tarifa.detallesOperativos = tarifa.detallesOperativos.filter((d: any) => d.id !== detalleId);
                        return;
                    }
                }
            }
        }
    };

    const abrirEditorSegmentos = () => {
        isSegmentEditorOpen.value = true;
    };
    const cerrarEditorSegmentos = () => {
        isSegmentEditorOpen.value = false;
    };

    const inyectarComponentesDeSegmento = (segmentoMaestro: any) => {
        if (!dataActiva.value || !dataActiva.value.cotcomponentes) return;

        if (segmentoMaestro.segmentoComponentes && Array.isArray(segmentoMaestro.segmentoComponentes)) {
            segmentoMaestro.segmentoComponentes.forEach((segComp: any) => {
                const compMaestro = segComp.componente;
                if (!compMaestro || typeof compMaestro === 'string') return;

                const nuevoComponente = {
                    id: crypto.randomUUID(),
                    componenteMaestroId: compMaestro.id || compMaestro['@id'] || null,
                    nombreSnapshot: JSON.parse(JSON.stringify(getTituloSafe(compMaestro))),
                    cantidad: 1, estado: 'Pendiente', modo: 'incluido',
                    fechaHoraInicio: `${dataActiva.value.fechaInicioAbsoluta}T08:00`,
                    fechaHoraFin: `${dataActiva.value.fechaInicioAbsoluta}T09:00`,
                    cotsegmentoId: null, snapshotItems: [], cottarifas: []
                };
                dataActiva.value.cotcomponentes.push(nuevoComponente);
            });
        }
    };

    const aplicarPlantilla = async (plantillaId: string): Promise<void> => {
        isLoading.value = true;
        try {
            // Soporta que plantillaId sea UUID o IRI
            const endpoint = plantillaId.startsWith('/') ? plantillaId : `/platform/travel/itinerarios/${plantillaId}`;
            const response = await apiClient.get(endpoint);
            const plantillaProfunda = response.data;
            if (!dataActiva.value) return;

            dataActiva.value.itinerarioNombreSnapshot = JSON.parse(JSON.stringify(getTituloSafe(plantillaProfunda)));
            let ordenMaximo = dataActiva.value.cotsegmentos.length;

            // 🔥 1. DETECTAR LA PROPIEDAD CORRECTA (Depende de cómo se llame en tu PHP)
            const arrayRelaciones = plantillaProfunda.segmentos || plantillaProfunda.itinerarioSegmentos || [];

            if (arrayRelaciones && Array.isArray(arrayRelaciones)) {

                // 🔥 2. DESEMPAQUETAR LA ENTIDAD INTERMEDIA (TravelItinerarioSegmentoRel)
                // Si el item tiene una propiedad "segmento", la extraemos. Si no, usamos el item directamente.
                const segmentosRaw = arrayRelaciones.map((rel: any) => rel.segmento ? rel.segmento : rel);

                // 🔥 3. HIDRATAR (Convierte los IRIs anidados en objetos reales)
                const segmentosReales = await hydrateRelations(segmentosRaw);

                segmentosReales.forEach((seg: any) => {
                    ordenMaximo++;
                    dataActiva.value.cotsegmentos.push({
                        id: crypto.randomUUID(), dia: 1, orden: ordenMaximo,
                        nombreSnapshot: JSON.parse(JSON.stringify(getTituloSafe(seg))),
                        contenidoSnapshot: JSON.parse(JSON.stringify(seg.contenido || []))
                    });
                    inyectarComponentesDeSegmento(seg);
                });
            }
        } catch (error) {
            console.error("Error al aplicar la plantilla profunda", error);
        } finally {
            isLoading.value = false;
        }
    };

    const agregarSegmentoIndividual = (segmentoMaestro: any): void => {
        if (!dataActiva.value) return;
        const ordenNuevo = dataActiva.value.cotsegmentos.length + 1;
        dataActiva.value.cotsegmentos.push({
            id: crypto.randomUUID(), dia: 1, orden: ordenNuevo,
            nombreSnapshot: JSON.parse(JSON.stringify(getTituloSafe(segmentoMaestro))),
            contenidoSnapshot: JSON.parse(JSON.stringify(segmentoMaestro.contenido || []))
        });
        inyectarComponentesDeSegmento(segmentoMaestro);
    };

    const removerCotSegmento = (id: string): void => {
        if (!dataActiva.value) return;
        dataActiva.value.cotsegmentos = dataActiva.value.cotsegmentos.filter((s: any) => s.id !== id);
    };

    // 🔥 SINCRONIZADORES REPARADOS: El `.find` no fallará si recibe un IRI
    const onServicioMaestroChange = async (val: string | null): Promise<void> => {
        if (!val || val === 'null') {
            catalogos.value.componentes = catalogos.value.allComponentes;
            catalogos.value.plantillasItinerario = [];
            catalogos.value.poolSegmentos = [];
            return;
        }

        // Busca tanto si "val" es el ID como si es el @id de API Platform
        const maestro = catalogos.value.servicios.find((s: any) => s.id === val || s['@id'] === val);
        if (maestro && dataActiva.value) {
            dataActiva.value.nombreSnapshot = JSON.parse(JSON.stringify(getTituloSafe(maestro)));
            await fetchServicioDetalles(val);
        }
    };

    const onComponenteMaestroChange = async (val: string | null): Promise<void> => {
        if (!val || val === 'null') {
            catalogos.value.tarifas = [];
            return;
        }

        const maestro = catalogos.value.componentes.find((c: any) => c.id === val || c['@id'] === val)
            || catalogos.value.allComponentes.find((c: any) => c.id === val || c['@id'] === val);

        if (maestro && dataActiva.value) {
            dataActiva.value.nombreSnapshot = JSON.parse(JSON.stringify(getTituloSafe(maestro)));
            await fetchComponenteDetalles(val);
        }
    };

    const onTarifaMaestraChange = (val: string): void => {
        const maestro = catalogos.value.tarifas.find((t: any) => t.id === val || t['@id'] === val);
        if (maestro && dataActiva.value) {
            dataActiva.value.nombreSnapshot = JSON.parse(JSON.stringify(getTituloSafe(maestro)));
            dataActiva.value.moneda = maestro.moneda?.codigo || maestro.moneda || 'USD';
            dataActiva.value.montoCosto = maestro.monto;
            dataActiva.value.tipoModalidadSnapshot = maestro.modalidad;
        }
    };

    return {
        catalogos, cotizacion, idiomasDisponibles, isLoading, inspectorActivo, dataActiva,
        isMobileOpen, isSegmentEditorOpen, totalCostoNeto, ventaSugerida, inicializarEditor,
        getI18n, renderI18n, guardarCotizacion, abrirNivel, retrocederNivel, cerrarInspectorMobile,
        agregarServicio, eliminarServicio, agregarComponente, eliminarComponente, agregarTarifa,
        eliminarTarifa, agregarDetalleOperativo, eliminarDetalleOperativo, abrirEditorSegmentos,
        cerrarEditorSegmentos, aplicarPlantilla, agregarSegmentoIndividual, removerCotSegmento,
        onServicioMaestroChange, onComponenteMaestroChange, onTarifaMaestraChange
    };
});