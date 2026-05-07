// src/stores/cotizaciones/cotizacionEditorStore.ts
import { defineStore } from 'pinia';
import { ref, computed } from 'vue';
import { apiClient } from '@/services/apiClient';

export type NivelInspector = 'resumen' | 'servicio' | 'componente' | 'tarifa';

export interface I18nString { language: string; content: string; }
export interface MaestroIdioma { id: string; nombre: string; bandera: string | null; prioridad: number; }

export const useCotizacionEditorStore = defineStore('cotizacionEditorStore', () => {

    const isLoading = ref<boolean>(false);
    const idiomasDisponibles = ref<MaestroIdioma[]>([]);

    const catalogos = ref({
        servicios: [] as any[], allComponentes: [] as any[], componentes: [] as any[],
        tarifas: [] as any[], plantillasItinerario: [] as any[], poolSegmentos: [] as any[]
    });

    const cotizacion = ref<any>(null);

    // ============================================================================
    // 🔥 HELPERS Y LÓGICA DE TIEMPO
    // ============================================================================

    const hydrateRelations = async (items: any[]) => {
        if (!items || !Array.isArray(items) || items.length === 0) return [];

        if (typeof items[0] === 'string') {
            const promises = items.map(async (iri) => {
                if (iri.includes('.well-known/genid')) return iri;
                const res = await apiClient.get(iri);
                return res.data;
            });
            return Promise.all(promises);
        }

        if (typeof items[0] === 'object' && items[0]['@id'] && !items[0].nombreInterno && !items[0].titulo && !items[0].nombre) {
            const promises = items.map(async (obj) => {
                if (obj['@id'].includes('.well-known/genid')) return obj;
                const res = await apiClient.get(obj['@id']);
                return res.data;
            });
            return Promise.all(promises);
        }

        return items;
    };

    const getTituloSafe = (entity: any) => {
        if (entity && entity.titulo && Array.isArray(entity.titulo) && entity.titulo.length > 0) return entity.titulo;
        return [{ language: 'es', content: entity?.nombreInterno || entity?.nombre || 'Item sin nombre' }];
    };

    const getTarifaLabel = (cat: any, lang: string) => {
        const nombre = cat.nombreInterno || cat.nombre || 'Tarifa sin nombre';
        const moneda = cat.moneda?.codigo || cat.moneda?.id || cat.moneda || '';
        const monto = parseFloat(cat.monto || cat.montoCosto || 0).toFixed(2);
        if (moneda && moneda !== '[]') return `${nombre} (${moneda} ${monto})`;
        return `${nombre} ($ ${monto})`;
    };

    const addDurationToDate = (baseIsoString: string, durationDecimal: number | string): string => {
        if (!baseIsoString) return '';
        const date = new Date(baseIsoString);
        if (isNaN(date.getTime())) return '';

        const hoursToAdd = typeof durationDecimal === 'string' ? parseFloat(durationDecimal) : durationDecimal;
        date.setMinutes(date.getMinutes() + Math.round(hoursToAdd * 60));

        const offset = date.getTimezoneOffset() * 60000;
        return (new Date(date.getTime() - offset)).toISOString().slice(0, 16);
    };

    const calcularPernoctes = (inicioStr: string, finStr: string): number => {
        if (!inicioStr || !finStr) return 1;
        const fInicio = new Date(inicioStr);
        const fFin = new Date(finStr);
        fInicio.setHours(0, 0, 0, 0);
        fFin.setHours(0, 0, 0, 0);
        const diffDays = Math.round((fFin.getTime() - fInicio.getTime()) / (1000 * 60 * 60 * 24));
        return diffDays > 0 ? diffDays : 1;
    };

    const getI18nText = (arrayI18n: I18nString[] | undefined, lang: string): string => {
        if (!arrayI18n || !Array.isArray(arrayI18n)) return '';
        const found = arrayI18n.find(item => item.language === lang);
        return found ? found.content : '';
    };

    const setI18nText = (arrayI18n: any, lang: string, text: string): void => {
        if (!arrayI18n || !Array.isArray(arrayI18n)) return;
        let found = arrayI18n.find((item: any) => item.language === lang);
        if (found) {
            if (found.content !== text) found.content = text;
        } else {
            arrayI18n.push({ language: lang, content: text });
        }
    };

    // ============================================================================
    // 🔥 VALIDACIONES DE CUADRE DE TARIFAS (ALERTAS)
    // ============================================================================

    const isComponenteConAlerta = (componente: any): boolean => {
        if (!cotizacion.value) return false;
        if (componente.modo !== 'incluido') return false;
        if (!componente.cottarifas || componente.cottarifas.length === 0) return true;

        const numPaxGlobal = parseInt(cotizacion.value.numPax) || 1;
        let paxAsignados = 0;
        let tieneGrupal = false;

        componente.cottarifas.forEach((t: any) => {
            const maestro = catalogos.value.tarifas.find((cat: any) => cat.id === t.tarifaMaestraId || cat['@id'] === t.tarifaMaestraId);
            const tarifaEsGrupal = t.esGrupal !== undefined ? t.esGrupal : (maestro?.costoPorGrupo || false);

            if (tarifaEsGrupal) {
                tieneGrupal = true;
            } else {
                paxAsignados += parseInt(t.cantidad) || 0;
            }
        });

        if (tieneGrupal) return false;
        return paxAsignados !== numPaxGlobal;
    };

    const isServicioConAlerta = (servicio: any): boolean => {
        if (!servicio.cotcomponentes || servicio.cotcomponentes.length === 0) return true;
        return servicio.cotcomponentes.some((comp: any) => isComponenteConAlerta(comp));
    };

    // ============================================================================
    // 🔥 CLASIFICADOR / RESUMEN FINANCIERO EN VIVO
    // ============================================================================

    const resumenFinanciero = computed(() => {
        if (!cotizacion.value) return null;

        let totalCostoNeto = 0;
        let totalVentaBruta = 0;
        const desglosePorMoneda: Record<string, { neto: number, venta: number }> = {};

        const markup = (parseFloat(cotizacion.value.comision) || 0) / 100;
        const adelantoPct = (parseFloat(cotizacion.value.adelanto) || 0) / 100;

        cotizacion.value.itinerario.forEach((dia: any) => {
            dia.cotservicios.forEach((servicio: any) => {
                servicio.cotcomponentes.forEach((componente: any) => {
                    if (componente.modo === 'incluido' && componente.estado !== 'Cancelado') {
                        componente.cottarifas.forEach((tarifa: any) => {
                            const moneda = tarifa.moneda || 'USD';
                            const costo = (parseFloat(tarifa.montoCosto) || 0) * (parseInt(tarifa.cantidad) || 1);
                            const venta = costo * (1 + markup);

                            totalCostoNeto += costo;
                            totalVentaBruta += venta;

                            if (!desglosePorMoneda[moneda]) {
                                desglosePorMoneda[moneda] = { neto: 0, venta: 0 };
                            }
                            desglosePorMoneda[moneda].neto += costo;
                            desglosePorMoneda[moneda].venta += venta;
                        });
                    }
                });
            });
        });

        const ganancia = totalVentaBruta - totalCostoNeto;
        const montoAdelanto = totalVentaBruta * adelantoPct;

        return {
            totalCostoNeto,
            totalVentaBruta,
            ganancia,
            montoAdelanto,
            desglosePorMoneda,
            clasificacionJSON: JSON.stringify(desglosePorMoneda)
        };
    });

    const totalCostoNeto = computed(() => resumenFinanciero.value?.totalCostoNeto || 0);
    const ventaSugerida = computed(() => resumenFinanciero.value?.totalVentaBruta || 0);

    // ============================================================================
    // 🔥 MOTOR DE REORGANIZACIÓN VIVO (ITINERARIO REACTIVO)
    // ============================================================================

    const itinerarioDinamico = computed(() => {
        if (!cotizacion.value) return [];
        const todosLosServicios = cotizacion.value.itinerario.flatMap((d: any) => d.cotservicios);
        todosLosServicios.sort((a: any, b: any) => {
            const dateA = new Date(a.fechaInicioAbsoluta || '9999-12-31').getTime();
            const dateB = new Date(b.fechaInicioAbsoluta || '9999-12-31').getTime();
            return dateA - dateB;
        });

        const grupos: Record<string, any[]> = {};
        todosLosServicios.forEach((srv: any) => {
            const fecha = srv.fechaInicioAbsoluta || 'Sin Fecha';
            if (!grupos[fecha]) grupos[fecha] = [];
            grupos[fecha].push(srv);
        });

        const fechasOrdenadas = Object.keys(grupos).sort();
        const fechaBase = fechasOrdenadas.length > 0 ? new Date(fechasOrdenadas[0] + 'T12:00:00Z') : null;

        return fechasOrdenadas.map((fecha) => {
            const fechaActual = new Date(fecha + 'T12:00:00Z');
            let diaNumero = 1;

            if (fechaBase) {
                const diffTime = fechaActual.getTime() - fechaBase.getTime();
                diaNumero = Math.round(diffTime / (1000 * 60 * 60 * 24)) + 1;
            }

            return {
                fechaAbsoluta: fecha,
                diaNumero: diaNumero,
                cotservicios: grupos[fecha]
            };
        });
    });

    // ============================================================================
    // INICIALIZACIÓN Y FETCH
    // ============================================================================

    const inicializarEditor = async (cotizacionId?: string) => {
        isLoading.value = true;
        try {
            await fetchIdiomas();
            await fetchCatalogos();
            if (cotizacionId) await fetchCotizacion(cotizacionId);
            else crearCotizacionVacia();
        } catch (error) {
            console.error(error);
        } finally {
            isLoading.value = false;
        }
    };

    const fetchIdiomas = async () => {
        try {
            const response = await apiClient.get('/platform/maestro/idiomas?prioridad[gt]=0&order[prioridad]=asc');
            idiomasDisponibles.value = response.data['hydra:member'] || response.data['member'] || [];
        } catch (e) {
            idiomasDisponibles.value = [{ id: 'es', nombre: 'Español', bandera: '🇪🇸', prioridad: 1 }];
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
            catalogos.value.componentes = catalogos.value.allComponentes;
        } catch (e) {}
    };

    const fetchServicioDetalles = async (servicioIriOrId: string) => {
        try {
            const id = servicioIriOrId.split('/').pop();
            const response = await apiClient.get(`/platform/travel/servicios/${id}`);
            const data = response.data;

            if (data.componentes && data.componentes.length > 0) {
                if (typeof data.componentes[0] === 'string') {
                    catalogos.value.componentes = catalogos.value.allComponentes.filter((c: any) =>
                        data.componentes.some((iri: string) => iri === c['@id'] || iri.includes(c.id))
                    );
                } else catalogos.value.componentes = data.componentes;
            } else catalogos.value.componentes = [];

            catalogos.value.plantillasItinerario = await hydrateRelations(data.itinerarios || []);
            catalogos.value.poolSegmentos = await hydrateRelations(data.segmentos || []);
        } catch (e) {}
    };

    const fetchComponenteDetalles = async (componenteIriOrId: string) => {
        try {
            const id = componenteIriOrId.split('/').pop();
            const response = await apiClient.get(`/platform/travel/componentes/${id}`);
            catalogos.value.tarifas = await hydrateRelations(response.data.tarifas || []);

            if (dataActiva.value && inspectorActivo.value === 'componente') {
                const itemsRaw = response.data.componenteItems || [];

                // 🔥 FIX: Rescatamos los nombres/títulos desde la relación "diccionario"
                const itemsProcesados = await Promise.all(itemsRaw.map(async (item: any) => {
                    let diccData = item.diccionario;

                    // Si el diccionario es un IRI, lo traemos
                    if (typeof diccData === 'string') {
                        try {
                            const res = await apiClient.get(diccData);
                            diccData = res.data;
                        } catch (err) {
                            console.warn("No se pudo cargar el diccionario", diccData);
                        }
                    }

                    return {
                        id: crypto.randomUUID(),
                        // Extraemos el título del diccionario (si existe)
                        nombreSnapshot: JSON.parse(JSON.stringify(getTituloSafe(diccData || item))),
                        incluido: item.modo !== 'no_incluido'
                    };
                }));

                dataActiva.value.snapshotItems = itemsProcesados;
            }
        } catch (e) {
            console.error(e);
        }
    };

    const fetchCotizacion = async (id: string) => {
        try {
            const response = await apiClient.get(`/platform/sales/cotizacions/${id}`);
            cotizacion.value = response.data;
            cotizacion.value.idiomaEdicion = 'es';
        } catch (e) { throw new Error("No se encontró la cotización"); }
    };

    const crearCotizacionVacia = () => {
        cotizacion.value = {
            version: 1, estado: 'Pendiente', monedaGlobal: 'USD',
            idiomaCliente: idiomasDisponibles.value.length ? idiomasDisponibles.value[0].id : 'es',
            idiomaEdicion: 'es', numPax: 1, comision: 0.00, adelanto: 0.00,
            hotelOculto: true, precioOculto: false, resumenI18n: [],
            itinerario: [{ diaNumero: 1, fechaAbsoluta: new Date().toISOString().split('T')[0], cotservicios: [] }]
        };
    };

    const guardarCotizacion = async (): Promise<void> => {
        isLoading.value = true;
        try {
            const isUpdate = !!cotizacion.value.id;
            const endpoint = isUpdate ? `/platform/sales/cotizacions/${cotizacion.value.id}` : `/platform/sales/cotizacions`;
            const payload = JSON.parse(JSON.stringify(cotizacion.value));

            payload.clasificacionFinanciera = resumenFinanciero.value?.clasificacionJSON;
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

    // ============================================================================
    // NAVEGACIÓN Y ABMC
    // ============================================================================

    const inspectorActivo = ref<NivelInspector>('resumen');
    const dataActiva = ref<any>(null);
    const historialNavegacion = ref<{ nivel: NivelInspector, data: any }[]>([]);
    const isMobileOpen = ref<boolean>(false);
    const isSegmentEditorOpen = ref<boolean>(false);

    const abrirNivel = async (nivel: NivelInspector, data: any = null): Promise<void> => {
        if (nivel === 'servicio' || nivel === 'resumen') historialNavegacion.value = [];
        else historialNavegacion.value.push({ nivel: inspectorActivo.value, data: dataActiva.value });
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
                nombreSnapshot: [{ language: 'es', content: 'Nuevo Servicio' }],
                itinerarioNombreSnapshot: [{ language: 'es', content: 'Sin plantilla' }],
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
                const fechaBase = servicio.fechaInicioAbsoluta ? `${servicio.fechaInicioAbsoluta}T08:00:00` : new Date().toISOString();
                const nuevoComponente = {
                    id: crypto.randomUUID(), componenteMaestroId: null,
                    nombreSnapshot: [],
                    cantidad: 1, estado: 'Pendiente', modo: 'incluido',
                    fechaHoraInicio: fechaBase.slice(0, 16),
                    fechaHoraFin: addDurationToDate(fechaBase, 1),
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

    const agregarSnapshotItem = (componenteId: string): void => {
        if (dataActiva.value && dataActiva.value.id === componenteId) {
            dataActiva.value.snapshotItems.push({
                id: crypto.randomUUID(),
                nombreSnapshot: [{ language: 'es', content: 'Nueva inclusión' }],
                incluido: true
            });
        }
    };

    const eliminarSnapshotItem = (componenteId: string, itemId: string): void => {
        if (dataActiva.value && dataActiva.value.id === componenteId) {
            dataActiva.value.snapshotItems = dataActiva.value.snapshotItems.filter((i: any) => i.id !== itemId);
        }
    };

    const agregarTarifa = (componenteId: string): void => {
        const numPaxGlobal = parseInt(cotizacion.value.numPax) || 1;
        for (const dia of cotizacion.value.itinerario) {
            for (const servicio of dia.cotservicios) {
                const componente = servicio.cotcomponentes.find((c: any) => c.id === componenteId);
                if (componente) {
                    let paxAsignados = 0;
                    componente.cottarifas.forEach((t: any) => {
                        const maestro = catalogos.value.tarifas.find((cat: any) => cat.id === t.tarifaMaestraId || cat['@id'] === t.tarifaMaestraId);
                        const esGrupal = t.esGrupal !== undefined ? t.esGrupal : (maestro?.costoPorGrupo || false);
                        if (!esGrupal) paxAsignados += parseInt(t.cantidad) || 0;
                    });

                    let pasajerosRestantes = numPaxGlobal - paxAsignados;
                    if (pasajerosRestantes <= 0) pasajerosRestantes = 1;

                    const nuevaTarifa = {
                        id: crypto.randomUUID(), tarifaMaestraId: null,
                        nombreSnapshot: [{ language: 'es', content: 'Nueva Tarifa' }],
                        cantidad: pasajerosRestantes,
                        moneda: cotizacion.value.monedaGlobal,
                        montoCosto: 0.00, tipoModalidadSnapshot: 'Normal',
                        proveedorNombreSnapshot: null, detallesOperativos: [],
                        esGrupal: false
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
                        tarifa.detallesOperativos.push({ id: crypto.randomUUID(), tipo: 'Info', contenido: '' });
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

    const abrirEditorSegmentos = () => { isSegmentEditorOpen.value = true; };
    const cerrarEditorSegmentos = () => { isSegmentEditorOpen.value = false; };

    const inyectarComponentesDeSegmento = (segmentoMaestro: any, diaDelSegmento: number = 1) => {
        if (!dataActiva.value || !dataActiva.value.cotcomponentes) return;

        if (segmentoMaestro.segmentoComponentes && Array.isArray(segmentoMaestro.segmentoComponentes)) {
            segmentoMaestro.segmentoComponentes.forEach((segComp: any) => {
                const compMaestro = segComp.componente;
                if (!compMaestro || typeof compMaestro === 'string') return;

                let fechaBaseCalculada = dataActiva.value.fechaInicioAbsoluta;
                if (fechaBaseCalculada && diaDelSegmento > 1) {
                    const dateObj = new Date(`${fechaBaseCalculada}T12:00:00Z`);
                    dateObj.setUTCDate(dateObj.getUTCDate() + (diaDelSegmento - 1));
                    fechaBaseCalculada = dateObj.toISOString().split('T')[0];
                }

                let fechaHoraInicioFormateada = '';
                if (fechaBaseCalculada && segComp.hora) {
                    const horaExtract = segComp.hora.split('T')[1]?.substring(0, 5) || '08:00';
                    fechaHoraInicioFormateada = `${fechaBaseCalculada}T${horaExtract}`;
                } else if (fechaBaseCalculada) {
                    fechaHoraInicioFormateada = `${fechaBaseCalculada}T08:00`;
                } else {
                    fechaHoraInicioFormateada = new Date().toISOString().slice(0, 16);
                }

                const duracionDecimal = compMaestro.duracion !== undefined ? parseFloat(compMaestro.duracion) : 1;
                const fechaHoraFinFormateada = addDurationToDate(fechaHoraInicioFormateada, duracionDecimal);
                const nuevaCantidad = calcularPernoctes(fechaHoraInicioFormateada, fechaHoraFinFormateada);

                const nuevoComponente = {
                    id: crypto.randomUUID(),
                    componenteMaestroId: compMaestro.id || compMaestro['@id'] || null,
                    nombreSnapshot: JSON.parse(JSON.stringify(getTituloSafe(compMaestro))),
                    cantidad: nuevaCantidad,
                    estado: 'Pendiente',
                    modo: segComp.esIncluido === false ? 'opcional' : 'incluido',
                    fechaHoraInicio: fechaHoraInicioFormateada,
                    fechaHoraFin: fechaHoraFinFormateada,
                    cotsegmentoId: null, snapshotItems: [], cottarifas: []
                };

                dataActiva.value.cotcomponentes.push(nuevoComponente);
            });
        }
    };

    const aplicarPlantilla = async (plantillaId: string): Promise<void> => {
        isLoading.value = true;
        try {
            const endpoint = plantillaId.startsWith('/') ? plantillaId : `/platform/travel/itinerarios/${plantillaId}`;
            const response = await apiClient.get(endpoint);
            const plantillaProfunda = response.data;
            if (!dataActiva.value) return;

            dataActiva.value.itinerarioNombreSnapshot = JSON.parse(JSON.stringify(getTituloSafe(plantillaProfunda)));
            let ordenMaximo = dataActiva.value.cotsegmentos.length;

            const arrayRelaciones = plantillaProfunda.segmentos || plantillaProfunda.itinerarioSegmentos || [];

            if (arrayRelaciones && Array.isArray(arrayRelaciones)) {
                const segmentosRaw = arrayRelaciones.map((rel: any) => rel.segmento ? rel.segmento : rel);
                const segmentosReales = await hydrateRelations(segmentosRaw);

                segmentosReales.forEach((seg: any, index: number) => {
                    ordenMaximo++;
                    const relacionOriginal = arrayRelaciones[index];
                    const diaDelSegmento = relacionOriginal.dia || 1;

                    dataActiva.value.cotsegmentos.push({
                        id: crypto.randomUUID(), dia: diaDelSegmento, orden: ordenMaximo,
                        nombreSnapshot: JSON.parse(JSON.stringify(getTituloSafe(seg))),
                        contenidoSnapshot: JSON.parse(JSON.stringify(seg.contenido || []))
                    });
                    inyectarComponentesDeSegmento(seg, diaDelSegmento);
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
        inyectarComponentesDeSegmento(segmentoMaestro, 1);
    };

    const removerCotSegmento = (id: string): void => {
        if (!dataActiva.value) return;
        dataActiva.value.cotsegmentos = dataActiva.value.cotsegmentos.filter((s: any) => s.id !== id);
    };

    // ============================================================================
    // SINCRONIZADORES DE CAMBIOS Y REACTIVIDAD
    // ============================================================================

    const onServicioMaestroChange = async (val: string | null): Promise<void> => {
        if (!val || val === 'null') {
            catalogos.value.componentes = catalogos.value.allComponentes;
            catalogos.value.plantillasItinerario = [];
            catalogos.value.poolSegmentos = [];
            return;
        }
        const maestro = catalogos.value.servicios.find((s: any) => s.id === val || s['@id'] === val);
        if (maestro && dataActiva.value) {
            dataActiva.value.nombreSnapshot = JSON.parse(JSON.stringify(getTituloSafe(maestro)));
            await fetchServicioDetalles(val);
        }
    };

    const onServicioFechaChange = (): void => {
        if (!dataActiva.value || !dataActiva.value.fechaInicioAbsoluta) return;
        const nuevaFechaBase = dataActiva.value.fechaInicioAbsoluta;
        if (dataActiva.value.cotcomponentes && Array.isArray(dataActiva.value.cotcomponentes)) {
            dataActiva.value.cotcomponentes.forEach((comp: any) => {
                if (comp.fechaHoraInicio) {
                    const horaActual = comp.fechaHoraInicio.split('T')[1];
                    comp.fechaHoraInicio = `${nuevaFechaBase}T${horaActual}`;
                    const cMaestro = catalogos.value.allComponentes.find(c => c.id === comp.componenteMaestroId || c['@id'] === comp.componenteMaestroId);
                    const duracion = cMaestro?.duracion !== undefined ? parseFloat(cMaestro.duracion) : 1;
                    comp.fechaHoraFin = addDurationToDate(comp.fechaHoraInicio, duracion);
                }
            });
        }
    };

    const onComponenteMaestroChange = async (val: string | null): Promise<void> => {
        if (!val || val === 'null') { catalogos.value.tarifas = []; return; }
        const maestro = catalogos.value.componentes.find((c: any) => c.id === val || c['@id'] === val)
            || catalogos.value.allComponentes.find((c: any) => c.id === val || c['@id'] === val);

        if (maestro && dataActiva.value) {
            dataActiva.value.nombreSnapshot = JSON.parse(JSON.stringify(getTituloSafe(maestro)));
            if (maestro.duracion !== undefined && dataActiva.value.fechaHoraInicio) {
                dataActiva.value.fechaHoraFin = addDurationToDate(dataActiva.value.fechaHoraInicio, maestro.duracion);
            }
            if(dataActiva.value.fechaHoraInicio && dataActiva.value.fechaHoraFin){
                dataActiva.value.cantidad = calcularPernoctes(dataActiva.value.fechaHoraInicio, dataActiva.value.fechaHoraFin);
            }
            await fetchComponenteDetalles(val);
        }
    };

    const onComponenteFechasChange = (esCambioInicio: boolean = true): void => {
        if (!dataActiva.value) return;

        if (esCambioInicio && dataActiva.value.fechaHoraInicio) {
            const maestro = catalogos.value.allComponentes.find(c => c.id === dataActiva.value.componenteMaestroId || c['@id'] === dataActiva.value.componenteMaestroId);
            const duracion = maestro?.duracion !== undefined ? parseFloat(maestro.duracion) : 1;
            dataActiva.value.fechaHoraFin = addDurationToDate(dataActiva.value.fechaHoraInicio, duracion);
        }

        if (dataActiva.value.fechaHoraInicio && dataActiva.value.fechaHoraFin) {
            dataActiva.value.cantidad = calcularPernoctes(dataActiva.value.fechaHoraInicio, dataActiva.value.fechaHoraFin);
        }
    };

    const onTarifaMaestraChange = (val: string): void => {
        const maestro = catalogos.value.tarifas.find((t: any) => t.id === val || t['@id'] === val);
        if (maestro && dataActiva.value) {
            dataActiva.value.nombreSnapshot = JSON.parse(JSON.stringify(getTituloSafe(maestro)));

            if (typeof maestro.moneda === 'object' && maestro.moneda !== null) dataActiva.value.moneda = maestro.moneda.id || maestro.moneda.codigo || 'USD';
            else dataActiva.value.moneda = maestro.moneda || 'USD';

            dataActiva.value.montoCosto = parseFloat(maestro.monto || maestro.montoCosto || 0);
            dataActiva.value.tipoModalidadSnapshot = maestro.modalidad || 'Normal';

            if (maestro.costoPorGrupo) {
                dataActiva.value.cantidad = 1;
                dataActiva.value.esGrupal = true;
            } else {
                dataActiva.value.esGrupal = false;
            }
        }
    };

    return {
        catalogos, cotizacion, idiomasDisponibles, isLoading, inspectorActivo, dataActiva,
        isMobileOpen, isSegmentEditorOpen, totalCostoNeto, ventaSugerida, inicializarEditor,
        resumenFinanciero, itinerarioDinamico,
        isComponenteConAlerta, isServicioConAlerta,
        getI18nText, setI18nText,
        guardarCotizacion, abrirNivel, retrocederNivel, cerrarInspectorMobile,
        agregarServicio, eliminarServicio, agregarComponente, eliminarComponente, agregarTarifa,
        eliminarTarifa, agregarDetalleOperativo, eliminarDetalleOperativo,
        agregarSnapshotItem, eliminarSnapshotItem,
        abrirEditorSegmentos, cerrarEditorSegmentos, aplicarPlantilla, agregarSegmentoIndividual, removerCotSegmento,
        onServicioMaestroChange, onServicioFechaChange,
        onComponenteMaestroChange, onTarifaMaestraChange, getTarifaLabel,
        onComponenteFechasChange
    };
});