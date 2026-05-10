import { defineStore } from 'pinia';
import { ref, computed } from 'vue';
import { apiClient } from '@/services/apiClient';

export type NivelInspector = 'resumen' | 'servicio' | 'componente' | 'tarifa';

export interface I18nString { language: string; content: string; }
export interface MaestroIdioma { id: string; nombre: string; bandera: string | null; prioridad: number; }

export const useCotizacionEditorStore = defineStore('cotizacionEditorStore', () => {

    const isLoading = ref<boolean>(false);
    const idiomasDisponibles = ref<MaestroIdioma[]>([]);
    const tipoCambioSugerido = ref<number>(1); // Renombrado para mayor claridad

    const catalogos = ref({
        servicios: [] as any[], allComponentes: [] as any[], componentes: [] as any[],
        tarifas: [] as any[], plantillasItinerario: [] as any[], poolSegmentos: [] as any[]
    });

    const cotizacion = ref<any>(null);
    const fileActual = ref<any>(null);

    // ============================================================================
    // 🔥 HELPERS Y LÓGICA DE TIEMPO
    // ============================================================================

    const extractIdStr = (val: any) => val ? String(val).split('/').pop() : '';

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
        return [];
    };

    const getTarifaLabel = (cat: any, lang: string) => {
        const nombre = cat.nombreInterno || cat.nombre || 'Tarifa sin nombre';
        const moneda = cat.moneda?.codigo || cat.moneda?.id || cat.moneda || '';
        const monto = parseFloat(cat.monto || cat.montoCosto || 0).toFixed(2);
        const esGrupal = cat.costoPorGrupo || cat.esGrupal || false;

        // Parseo de edades
        const min = (cat.edadMinima !== undefined && cat.edadMinima !== null && cat.edadMinima !== '') ? Number(cat.edadMinima) : null;
        const max = (cat.edadMaxima !== undefined && cat.edadMaxima !== null && cat.edadMaxima !== '') ? Number(cat.edadMaxima) : null;

        let edadStr = '';
        if (min !== null && max !== null) edadStr = ` [${min}-${max} años]`;
        else if (min !== null) edadStr = ` [${min}+ años]`;
        else if (max !== null) edadStr = ` [Hasta ${max} años]`;

        const monedaFinal = (moneda && moneda !== '[]') ? moneda : '$';
        const indicadorMatematica = esGrupal ? ' 👥' : ' 👤';

        return `${nombre}${edadStr}${indicadorMatematica} (${monedaFinal} ${monto})`;
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
            if (tarifaEsGrupal) tieneGrupal = true;
            else paxAsignados += parseInt(t.cantidad) || 0;
        });

        if (tieneGrupal) return false;
        return paxAsignados !== numPaxGlobal;
    };

    const isServicioConAlerta = (servicio: any): boolean => {
        if (!servicio.cotcomponentes || servicio.cotcomponentes.length === 0) return true;
        return servicio.cotcomponentes.some((comp: any) => isComponenteConAlerta(comp));
    };

    // ============================================================================
    // 🔥 CLASIFICADOR Y MOTOR DINÁMICO
    // ============================================================================

    const resumenFinanciero = computed(() => {
        if (!cotizacion.value || !cotizacion.value.cotservicios) return null;
        let totalCostoNeto = 0;
        let totalVentaBruta = 0;
        const desglosePorMoneda: Record<string, { neto: number, venta: number }> = {};
        const markup = (parseFloat(cotizacion.value.comision) || 0) / 100;
        const adelantoPct = (parseFloat(cotizacion.value.adelanto) || 0) / 100;

        cotizacion.value.cotservicios.forEach((servicio: any) => {
            if (!servicio.cotcomponentes) return;
            servicio.cotcomponentes.forEach((componente: any) => {
                if (componente.modo === 'incluido' && componente.estado !== 'Cancelado') {
                    if (!componente.cottarifas) return;
                    componente.cottarifas.forEach((tarifa: any) => {
                        const moneda = tarifa.moneda || 'USD';
                        const costo = (parseFloat(tarifa.montoCosto) || 0) * (parseInt(tarifa.cantidad) || 1);
                        const venta = costo * (1 + markup);
                        totalCostoNeto += costo;
                        totalVentaBruta += venta;
                        if (!desglosePorMoneda[moneda]) desglosePorMoneda[moneda] = { neto: 0, venta: 0 };
                        desglosePorMoneda[moneda].neto += costo;
                        desglosePorMoneda[moneda].venta += venta;
                    });
                }
            });
        });

        return {
            totalCostoNeto, totalVentaBruta,
            ganancia: totalVentaBruta - totalCostoNeto,
            montoAdelanto: totalVentaBruta * adelantoPct,
            desglosePorMoneda,
            clasificacionJSON: JSON.stringify(desglosePorMoneda)
        };
    });

    const totalCostoNeto = computed(() => resumenFinanciero.value?.totalCostoNeto || 0);
    const ventaSugerida = computed(() => resumenFinanciero.value?.totalVentaBruta || 0);

    const itinerarioDinamico = computed(() => {
        if (!cotizacion.value || !cotizacion.value.cotservicios) return [];

        const todosLosServicios = [...cotizacion.value.cotservicios];

        todosLosServicios.sort((a: any, b: any) => {
            const dateA = new Date(a.fechaInicioAbsoluta || '9999-12-31').getTime();
            const dateB = new Date(b.fechaInicioAbsoluta || '9999-12-31').getTime();
            return dateA - dateB;
        });

        const grupos: Record<string, any[]> = {};
        todosLosServicios.forEach((srv: any) => {
            const fecha = srv.fechaInicioAbsoluta || new Date().toISOString().split('T')[0];
            if (!grupos[fecha]) grupos[fecha] = [];
            grupos[fecha].push(srv);
        });

        const fechasOrdenadas = Object.keys(grupos).sort();
        const fechaBase = fechasOrdenadas.length > 0 ? new Date(fechasOrdenadas[0] + 'T12:00:00Z') : new Date();

        return fechasOrdenadas.map((fecha) => {
            const fechaActual = new Date(fecha + 'T12:00:00Z');
            const diffTime = fechaActual.getTime() - fechaBase.getTime();
            const diaNumero = Math.round(diffTime / (1000 * 60 * 60 * 24)) + 1;
            return { fechaAbsoluta: fecha, diaNumero, cotservicios: grupos[fecha] };
        });
    });

    // ============================================================================
    // INICIALIZACIÓN Y PERSISTENCIA
    // ============================================================================

    const inicializarEditor = async (fileId: string, cotizacionId: string) => {
        if (!fileId) return;

        isLoading.value = true;
        try {
            try {
                const tcResponse = await apiClient.post('/platform/maestro/tipo-cambio/consultar', { fecha: new Date().toISOString().split('T')[0] });
                // 🔥 CAMBIO: Guardamos el PROMEDIO
                tipoCambioSugerido.value = parseFloat(tcResponse.data.promedio) || 1;
            } catch (err) {}

            await fetchIdiomas();
            await fetchCatalogos();

            const fileRes = await apiClient.get(`/platform/sales/cotizacion_files/${fileId}`);
            fileActual.value = fileRes.data;

            if (cotizacionId === 'nueva') {
                const maxVersion = fileActual.value.cotizaciones?.reduce((max: number, c: any) => Math.max(max, c.version), 0) || 0;
                crearCotizacionVacia(fileId);
                cotizacion.value.version = maxVersion + 1;
            } else {
                await fetchCotizacion(cotizacionId);
            }

            // 🔥 ABRIMOS LA CABECERA AUTOMÁTICAMENTE
            abrirNivel('resumen');

        } catch (error) {
            console.error("Error al inicializar el editor:", error);
            alert("No se pudo cargar el Expediente. Verifica la URL.");
        } finally {
            isLoading.value = false;
        }
    };

    const fetchIdiomas = async () => {
        try {
            // 🔥 CAMBIO: order[prioridad]=desc para que el español (prioridad alta) llegue primero
            const response = await apiClient.get('/platform/maestro/idiomas?prioridad[gt]=0&order[prioridad]=desc');
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

    const fetchComponenteMaestroSilencioso = async (id: string) => {
        const cleanId = extractIdStr(id);
        if (!cleanId) return;

        const existsIdx = catalogos.value.allComponentes.findIndex(c => extractIdStr(c.id) === cleanId);
        if (existsIdx !== -1 && catalogos.value.allComponentes[existsIdx].nombreInterno !== 'Sincronizando...') return;

        if (existsIdx === -1) {
            catalogos.value.allComponentes.push({ id: cleanId, nombreInterno: 'Sincronizando...' });
        }

        try {
            const res = await apiClient.get(`/platform/travel/componentes/${cleanId}`);
            const idx = catalogos.value.allComponentes.findIndex(c => extractIdStr(c.id) === cleanId);
            if (idx !== -1) {
                catalogos.value.allComponentes.splice(idx, 1, res.data);
            }
        } catch (e) {}
    };

    const fetchServicioDetalles = async (servicioIriOrId: string) => {
        try {
            const id = extractIdStr(servicioIriOrId);
            const response = await apiClient.get(`/platform/travel/servicios/${id}`);
            const data = response.data;

            if (data.componentes && data.componentes.length > 0) {
                const hydratedComps = await hydrateRelations(data.componentes);
                catalogos.value.componentes = hydratedComps;
                hydratedComps.forEach((c: any) => {
                    const targetId = extractIdStr(c.id || c['@id']);
                    if (!catalogos.value.allComponentes.some(exist => extractIdStr(exist.id) === targetId)) {
                        catalogos.value.allComponentes.push(c);
                    }
                });
            } else {
                catalogos.value.componentes = [];
            }

            catalogos.value.plantillasItinerario = await hydrateRelations(data.itinerarios || []);
            catalogos.value.poolSegmentos = await hydrateRelations(data.segmentos || []);
        } catch (e) {}
    };

    const fetchComponenteDetalles = async (componenteIriOrId: string) => {
        try {
            const id = extractIdStr(componenteIriOrId);
            const response = await apiClient.get(`/platform/travel/componentes/${id}`);
            const fetchedComp = response.data;

            const targetId = extractIdStr(fetchedComp.id);
            const exists = catalogos.value.allComponentes.some(c => extractIdStr(c.id) === targetId);
            if (!exists) catalogos.value.allComponentes.push(fetchedComp);

            catalogos.value.tarifas = await hydrateRelations(fetchedComp.tarifas || []);

            if (dataActiva.value && inspectorActivo.value === 'componente') {
                const itemsRaw = fetchedComp.componenteItems || [];

                if (!dataActiva.value.snapshotItems || dataActiva.value.snapshotItems.length === 0) {
                    dataActiva.value.snapshotItems = await Promise.all(itemsRaw.map(async (item: any) => {
                        let diccData = item.diccionario;
                        if (typeof diccData === 'string') {
                            try { const res = await apiClient.get(diccData); diccData = res.data; } catch (err) {}
                        }

                        const modoBackend = item.modo || 'incluido';
                        const isIncluido = modoBackend === 'incluido' || modoBackend === 'cortesia';
                        const tieneUpsell = !!item.componenteAdicionalVinculado;

                        return {
                            id: crypto.randomUUID(),
                            nombreSnapshot: JSON.parse(JSON.stringify(getTituloSafe(diccData || item))),
                            modo: modoBackend,
                            modoOriginal: modoBackend,
                            incluido: isIncluido,
                            tieneUpsell: tieneUpsell,
                            componenteAdicionalVinculado: item.componenteAdicionalVinculado || null,
                            idComponenteInyectado: null,
                            isInjecting: false,
                            sobreescribirTraduccion: false
                        };
                    }));
                } else {
                    dataActiva.value.snapshotItems.forEach((i: any) => i.isInjecting = false);
                }
            }
        } catch (e) {}
    };

    const fetchCotizacion = async (id: string) => {
        try {
            const response = await apiClient.get(`/platform/sales/cotizacions/${id}`);
            const data = response.data;

            if (!data.cotservicios) data.cotservicios = [];

            data.cotservicios.forEach((s: any) => {
                if (s.fechaInicioAbsoluta && s.fechaInicioAbsoluta.includes('T')) {
                    s.fechaInicioAbsoluta = s.fechaInicioAbsoluta.split('T')[0];
                }

                if (s.cotsegmentos && Array.isArray(s.cotsegmentos)) {
                    s.cotsegmentos.forEach((seg: any) => {
                        if (seg.fechaAbsoluta && seg.fechaAbsoluta.includes('T')) {
                            seg.fechaAbsoluta = seg.fechaAbsoluta.split('T')[0];
                        }
                    });
                }

                if (s.cotcomponentes && Array.isArray(s.cotcomponentes)) {
                    s.cotcomponentes.forEach((c: any) => {
                        if (c.cotsegmento && !c.cotsegmentoId) {
                            c.cotsegmentoId = typeof c.cotsegmento === 'string' ? extractIdStr(c.cotsegmento) : extractIdStr(c.cotsegmento.id || c.cotsegmento['@id']);
                        }
                    });
                }
            });

            cotizacion.value = data;
            cotizacion.value.idiomaEdicion = 'es';
        } catch (e) { throw new Error("No se encontró la cotización"); }
    };

    const crearCotizacionVacia = (fileId: string) => {
        // 🔥 Buscamos explícitamente el español para nacer bien
        const idiomaDefault = idiomasDisponibles.value.find(i => i.id === 'es')
            ? 'es'
            : (idiomasDisponibles.value.length ? idiomasDisponibles.value[0].id : 'es');

        cotizacion.value = {
            id: crypto.randomUUID(),
            file: `/platform/sales/cotizacion_files/${fileId}`,
            version: 1, estado: 'Pendiente', monedaGlobal: 'USD',
            idiomaCliente: idiomaDefault,
            idiomaEdicion: 'es', numPax: 1,
            comision: 20.00, // 🔥 Iniciamos en 20%
            adelanto: 0.00,
            tipoCambio: String(tipoCambioSugerido.value || 1), // 🔥 Iniciamos con el PROMEDIO
            hotelOculto: true, precioOculto: false, resumenI18n: [],
            sobreescribirTraduccion: false,
            cotservicios: []
        };
    };

    const guardarCotizacion = async (): Promise<void> => {
        isLoading.value = true;
        try {
            const isUpdate = !!cotizacion.value.createdAt;
            const endpoint = isUpdate
                ? `/platform/sales/cotizacions/${cotizacion.value.id}`
                : `/platform/sales/cotizacions`;

            const payload = JSON.parse(JSON.stringify(cotizacion.value));

            if (payload.file && typeof payload.file === 'object') {
                payload.file = payload.file['@id'] || payload.file.id;
            } else if (payload.file && !payload.file.includes('/platform/')) {
                payload.file = `/platform/sales/cotizacion_files/${payload.file}`;
            }

            payload.comision = String(payload.comision || '0');
            payload.adelanto = String(payload.adelanto || '0');
            payload.totalCosto = String(resumenFinanciero.value?.totalCostoNeto || '0');
            payload.totalVenta = String(resumenFinanciero.value?.totalVentaBruta || '0');
            payload.numPax = parseInt(payload.numPax) || 1;
            payload.tipoCambio = String(payload.tipoCambio || tipoCambioSugerido.value || 1); // 🔥
            payload.clasificacionFinanciera = resumenFinanciero.value?.desglosePorMoneda || {};
            delete payload.idiomaEdicion;

            if (payload.cotservicios && Array.isArray(payload.cotservicios)) {
                payload.cotservicios.forEach((servicio: any) => {

                    if (servicio.servicioMaestroId) {
                        servicio.servicioMaestroId = extractIdStr(servicio.servicioMaestroId);
                    }

                    if (!servicio.fechaInicioAbsoluta) {
                        servicio.fechaInicioAbsoluta = new Date().toISOString().split('T')[0];
                    }
                    if (servicio.fechaInicioAbsoluta.length === 10) {
                        servicio.fechaInicioAbsoluta += 'T00:00:00';
                    }

                    if (servicio.cotsegmentos && Array.isArray(servicio.cotsegmentos)) {
                        servicio.cotsegmentos.forEach((seg: any) => {
                            if (!seg.fechaAbsoluta) seg.fechaAbsoluta = servicio.fechaInicioAbsoluta;
                            if (seg.fechaAbsoluta.length === 10) seg.fechaAbsoluta += 'T00:00:00';
                        });
                    }

                    if (servicio.cotcomponentes && Array.isArray(servicio.cotcomponentes)) {
                        servicio.cotcomponentes.forEach((componente: any) => {
                            componente.cantidad = parseInt(componente.cantidad) || 1;

                            if (componente.componenteMaestroId) {
                                componente.componenteMaestroId = extractIdStr(componente.componenteMaestroId);
                            }

                            const segId = componente.cotsegmentoId || (
                                typeof componente.cotsegmento === 'string'
                                    ? extractIdStr(componente.cotsegmento)
                                    : extractIdStr(componente.cotsegmento?.id || componente.cotsegmento?.['@id'])
                            );

                            componente.cotsegmento = segId
                                ? `/platform/sales/cotizacion_segmentos/${segId}`
                                : null;

                            delete componente.cotsegmentoId;

                            if (componente.cottarifas && Array.isArray(componente.cottarifas)) {
                                componente.cottarifas.forEach((tarifa: any) => {
                                    tarifa.cantidad = parseInt(tarifa.cantidad) || 1;
                                    tarifa.montoCosto = String(tarifa.montoCosto || '0');
                                    if (tarifa.tarifaMaestraId) {
                                        tarifa.tarifaMaestraId = extractIdStr(tarifa.tarifaMaestraId);
                                    }
                                });
                            }
                        });
                    }
                });
            }

            const response = await (isUpdate ? apiClient.put : apiClient.post)(endpoint, payload);
            let savedData = response.data;

            if (!savedData.cotservicios) savedData.cotservicios = [];
            savedData.idiomaEdicion = 'es';

            savedData.cotservicios.forEach((s: any) => {
                s.sobreescribirTraduccion = false;
                if (s.fechaInicioAbsoluta?.includes('T')) {
                    s.fechaInicioAbsoluta = s.fechaInicioAbsoluta.split('T')[0];
                }
                s.cotsegmentos?.forEach((seg: any) => {
                    seg.sobreescribirTraduccion = false;
                    if (seg.fechaAbsoluta?.includes('T')) {
                        seg.fechaAbsoluta = seg.fechaAbsoluta.split('T')[0];
                    }
                });
                s.cotcomponentes?.forEach((c: any) => {
                    c.sobreescribirTraduccion = false;
                    c.snapshotItems?.forEach((i: any) => i.sobreescribirTraduccion = false);
                    c.cottarifas?.forEach((t: any) => t.sobreescribirTraduccion = false);

                    if (c.cotsegmento) {
                        c.cotsegmentoId = typeof c.cotsegmento === 'string'
                            ? extractIdStr(c.cotsegmento)
                            : extractIdStr(c.cotsegmento.id || c.cotsegmento['@id']);
                    }
                });
            });

            cotizacion.value = savedData;
            alert('Cotización guardada exitosamente.');

        } catch (error) {
            console.error('Error al guardar la cotización:', error);
            alert('Falló la sincronización con la base de datos.');
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

    const findServicioByComponenteId = (compId: string) => {
        return cotizacion.value.cotservicios.find((s: any) => s.cotcomponentes?.some((c: any) => c.id === compId)) || null;
    };

    const updateNumPaxGlobal = (newPaxStr: string | number) => {
        if (!cotizacion.value || !cotizacion.value.cotservicios) return;

        const oldPax = parseInt(cotizacion.value.numPax) || 1;
        const newPax = parseInt(newPaxStr as string) || 1;

        if (oldPax === newPax) return;

        for (const servicio of cotizacion.value.cotservicios) {
            if (!servicio.cotcomponentes) continue;
            for (const componente of servicio.cotcomponentes) {
                if (!componente.cottarifas) continue;
                for (const tarifa of componente.cottarifas) {
                    if (!tarifa.esGrupal && parseInt(tarifa.cantidad) === oldPax) {
                        tarifa.cantidad = newPax;
                    }
                }
            }
        }

        cotizacion.value.numPax = newPax;
    };

    const agregarServicio = (): void => {
        const cots = cotizacion.value.cotservicios || [];
        const fechaBase = cots.length > 0
            ? cots[cots.length - 1].fechaInicioAbsoluta
            : new Date().toISOString().split('T')[0];

        const nuevoServicio = {
            id: crypto.randomUUID(), servicioMaestroId: null,
            nombreSnapshot: [{ language: 'es', content: 'Nuevo Servicio' }],
            itinerarioNombreSnapshot: [{ language: 'es', content: 'Sin plantilla' }],
            fechaInicioAbsoluta: fechaBase, cotsegmentos: [], cotcomponentes: [],
            sobreescribirTraduccion: false
        };
        if (!cotizacion.value.cotservicios) cotizacion.value.cotservicios = [];
        cotizacion.value.cotservicios.push(nuevoServicio);
        abrirNivel('servicio', nuevoServicio);
    };

    const eliminarServicio = (servicioId: string): void => {
        if (!cotizacion.value || !cotizacion.value.cotservicios) return;
        cotizacion.value.cotservicios = cotizacion.value.cotservicios.filter((s: any) => s.id !== servicioId);
        if (dataActiva.value?.id === servicioId) retrocederNivel();
    };

    const agregarComponente = (servicioId: string): void => {
        if (!cotizacion.value || !cotizacion.value.cotservicios) return;
        const servicio = cotizacion.value.cotservicios.find((s: any) => s.id === servicioId);
        if (servicio) {
            const fechaBase = servicio.fechaInicioAbsoluta ? `${servicio.fechaInicioAbsoluta}T08:00:00` : new Date().toISOString();
            const nuevoComponente = {
                id: crypto.randomUUID(), componenteMaestroId: null,
                nombreSnapshot: [],
                cantidad: 1, estado: 'Pendiente', modo: 'incluido',
                fechaHoraInicio: fechaBase.slice(0, 16),
                fechaHoraFin: addDurationToDate(fechaBase, 1),
                cotsegmentoId: null,
                sobreescribirTraduccion: false,
                snapshotItems: [], cottarifas: []
            };
            if (!servicio.cotcomponentes) servicio.cotcomponentes = [];
            servicio.cotcomponentes.push(nuevoComponente);
            abrirNivel('componente', nuevoComponente);
        }
    };

    const eliminarComponente = (servicioId: string, componenteId: string): void => {
        if (!cotizacion.value || !cotizacion.value.cotservicios) return;
        const servicio = cotizacion.value.cotservicios.find((s: any) => s.id === servicioId);
        if (servicio && servicio.cotcomponentes) {
            servicio.cotcomponentes = servicio.cotcomponentes.filter((c: any) => c.id !== componenteId);
            if (dataActiva.value?.id === componenteId) retrocederNivel();
        }
    };

    const agregarSnapshotItem = (componenteId: string): void => {
        if (dataActiva.value && dataActiva.value.id === componenteId) {
            if (!dataActiva.value.snapshotItems) dataActiva.value.snapshotItems = [];
            dataActiva.value.snapshotItems.push({
                id: crypto.randomUUID(),
                nombreSnapshot: [{ language: 'es', content: 'Nueva inclusión' }],
                incluido: true,
                modo: 'incluido',
                modoOriginal: 'incluido',
                tieneUpsell: false,
                idComponenteInyectado: null,
                isInjecting: false,
                sobreescribirTraduccion: false
            });
        }
    };

    const eliminarSnapshotItem = (componenteId: string, itemId: string): void => {
        if (dataActiva.value && dataActiva.value.id === componenteId) {
            const item = dataActiva.value.snapshotItems.find((i: any) => i.id === itemId);
            if (item && item.idComponenteInyectado) {
                removerComponenteInyectado(item, componenteId);
            }
            dataActiva.value.snapshotItems = dataActiva.value.snapshotItems.filter((i: any) => i.id !== itemId);
        }
    };

    const removerComponenteInyectado = (item: any, idPadre: string) => {
        const servicio = findServicioByComponenteId(idPadre);
        if (servicio && servicio.cotcomponentes) {
            const idx = servicio.cotcomponentes.findIndex((c: any) => c.id === item.idComponenteInyectado);
            if (idx !== -1) {
                servicio.cotcomponentes.splice(idx, 1);
            }
        }
        item.idComponenteInyectado = null;
    };

    const toggleUpsellComponent = async (item: any, componentePadre: any) => {
        if (item.incluido) {
            item.modo = 'incluido';

            if (item.tieneUpsell && !item.idComponenteInyectado && !item.isInjecting) {
                item.isInjecting = true;

                try {
                    const targetIriOrId = typeof item.componenteAdicionalVinculado === 'string'
                        ? item.componenteAdicionalVinculado
                        : (item.componenteAdicionalVinculado['@id'] || item.componenteAdicionalVinculado.id);

                    const res = await apiClient.get(targetIriOrId);
                    const compMaestro = res.data;

                    const targetId = extractIdStr(compMaestro.id || compMaestro['@id']);
                    const exists = catalogos.value.allComponentes.some(c => extractIdStr(c.id) === targetId);
                    if (!exists) catalogos.value.allComponentes.push(compMaestro);

                    const nuevoId = crypto.randomUUID();
                    item.idComponenteInyectado = nuevoId;

                    const nuevoComp = {
                        id: nuevoId,
                        componenteMaestroId: compMaestro.id || compMaestro['@id'],
                        nombreSnapshot: JSON.parse(JSON.stringify(getTituloSafe(compMaestro))),
                        cantidad: componentePadre.cantidad,
                        estado: 'Pendiente',
                        modo: 'incluido',
                        fechaHoraInicio: componentePadre.fechaHoraInicio,
                        fechaHoraFin: componentePadre.fechaHoraFin,
                        cotsegmentoId: componentePadre.cotsegmentoId,
                        upsellSourceItemId: item.id,
                        sobreescribirTraduccion: false,
                        snapshotItems: [],
                        cottarifas: []
                    };

                    const servicio = findServicioByComponenteId(componentePadre.id);
                    if (servicio) {
                        if (!servicio.cotcomponentes) servicio.cotcomponentes = [];
                        servicio.cotcomponentes.push(nuevoComp);
                    }

                } catch(err) {
                    console.error("Error al inyectar logística upsell", err);
                } finally {
                    item.isInjecting = false;
                    if (!item.incluido && item.idComponenteInyectado) {
                        removerComponenteInyectado(item, componentePadre.id);
                    }
                }
            }
        } else {
            item.modo = (item.tieneUpsell || item.modoOriginal === 'opcional') ? 'opcional' : 'no_incluido';

            if (item.idComponenteInyectado && !item.isInjecting) {
                removerComponenteInyectado(item, componentePadre.id);
            }
        }
    };

    const agregarTarifa = (componenteId: string): void => {
        const numPaxGlobal = parseInt(cotizacion.value.numPax) || 1;
        const servicio = findServicioByComponenteId(componenteId);
        if (servicio && servicio.cotcomponentes) {
            const componente = servicio.cotcomponentes.find((c: any) => c.id === componenteId);
            if (componente) {
                let paxAsignados = 0;
                const tarifas = componente.cottarifas || [];
                tarifas.forEach((t: any) => {
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
                    esGrupal: false,
                    sobreescribirTraduccion: false
                };
                if (!componente.cottarifas) componente.cottarifas = [];
                componente.cottarifas.push(nuevaTarifa);
                abrirNivel('tarifa', nuevaTarifa);
            }
        }
    };

    const eliminarTarifa = (componenteId: string, tarifaId: string): void => {
        const servicio = findServicioByComponenteId(componenteId);
        if (servicio && servicio.cotcomponentes) {
            const componente = servicio.cotcomponentes.find((c: any) => c.id === componenteId);
            if (componente && componente.cottarifas) {
                componente.cottarifas = componente.cottarifas.filter((t: any) => t.id !== tarifaId);
                if (dataActiva.value?.id === tarifaId) retrocederNivel();
            }
        }
    };

    const abrirEditorSegmentos = () => { isSegmentEditorOpen.value = true; };
    const cerrarEditorSegmentos = () => { isSegmentEditorOpen.value = false; };

    const inyectarComponentesDeSegmento = (segmentoMaestro: any, diaDelSegmento: number = 1, idSegmentoGenerado: string) => {
        if (!dataActiva.value) return;

        if (segmentoMaestro.segmentoComponentes && Array.isArray(segmentoMaestro.segmentoComponentes)) {
            segmentoMaestro.segmentoComponentes.forEach((segComp: any) => {
                const compMaestro = segComp.componente;
                if (!compMaestro || typeof compMaestro === 'string') return;

                const targetId = extractIdStr(compMaestro.id || compMaestro['@id']);
                const exists = catalogos.value.allComponentes.some(c => extractIdStr(c.id) === targetId);
                if (!exists) catalogos.value.allComponentes.push(compMaestro);

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
                    modo: segComp.modo || 'incluido',
                    fechaHoraInicio: fechaHoraInicioFormateada,
                    fechaHoraFin: fechaHoraFinFormateada,
                    cotsegmentoId: idSegmentoGenerado,
                    sobreescribirTraduccion: false,
                    snapshotItems: [], cottarifas: []
                };

                if (!dataActiva.value.cotcomponentes) dataActiva.value.cotcomponentes = [];
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
            let ordenMaximo = dataActiva.value.cotsegmentos ? dataActiva.value.cotsegmentos.length : 0;

            const arrayRelaciones = plantillaProfunda.segmentos || plantillaProfunda.itinerarioSegmentos || [];

            if (arrayRelaciones && Array.isArray(arrayRelaciones)) {
                const segmentosRaw = arrayRelaciones.map((rel: any) => rel.segmento ? rel.segmento : rel);
                const segmentosReales = await hydrateRelations(segmentosRaw);

                segmentosReales.forEach((seg: any, index: number) => {
                    ordenMaximo++;
                    const relacionOriginal = arrayRelaciones[index];
                    const diaDelSegmento = relacionOriginal.dia || 1;
                    const nuevoIdSeg = crypto.randomUUID();

                    let fechaCalculada = dataActiva.value.fechaInicioAbsoluta || new Date().toISOString().split('T')[0];
                    if (diaDelSegmento > 1) {
                        const dateObj = new Date(`${fechaCalculada}T12:00:00Z`);
                        dateObj.setUTCDate(dateObj.getUTCDate() + (diaDelSegmento - 1));
                        fechaCalculada = dateObj.toISOString().split('T')[0];
                    }

                    if (!dataActiva.value.cotsegmentos) dataActiva.value.cotsegmentos = [];
                    dataActiva.value.cotsegmentos.push({
                        id: nuevoIdSeg,
                        dia: diaDelSegmento,
                        orden: ordenMaximo,
                        fechaAbsoluta: fechaCalculada,
                        nombreSnapshot: JSON.parse(JSON.stringify(getTituloSafe(seg))),
                        contenidoSnapshot: JSON.parse(JSON.stringify(seg.contenido || [])),
                        sobreescribirTraduccion: false
                    });
                    inyectarComponentesDeSegmento(seg, diaDelSegmento, nuevoIdSeg);
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
        const ordenNuevo = dataActiva.value.cotsegmentos ? dataActiva.value.cotsegmentos.length + 1 : 1;
        const nuevoIdSeg = crypto.randomUUID();

        const fechaCalculada = dataActiva.value.fechaInicioAbsoluta || new Date().toISOString().split('T')[0];

        if (!dataActiva.value.cotsegmentos) dataActiva.value.cotsegmentos = [];
        dataActiva.value.cotsegmentos.push({
            id: nuevoIdSeg,
            dia: 1,
            orden: ordenNuevo,
            fechaAbsoluta: fechaCalculada,
            nombreSnapshot: JSON.parse(JSON.stringify(getTituloSafe(segmentoMaestro))),
            contenidoSnapshot: JSON.parse(JSON.stringify(segmentoMaestro.contenido || [])),
            sobreescribirTraduccion: false
        });

        inyectarComponentesDeSegmento(segmentoMaestro, 1, nuevoIdSeg);
    };

    const removerCotSegmento = (id: string): void => {
        if (!dataActiva.value) return;
        if (dataActiva.value.cotsegmentos) {
            dataActiva.value.cotsegmentos = dataActiva.value.cotsegmentos.filter((s: any) => s.id !== id);
        }
        if (dataActiva.value.cotcomponentes) {
            dataActiva.value.cotcomponentes = dataActiva.value.cotcomponentes.filter((c: any) => c.cotsegmentoId !== id && c.cotsegmento !== id);
        }
    };

    const onServicioMaestroChange = async (val: string | null): Promise<void> => {
        if (!val || val === 'null') {
            catalogos.value.componentes = catalogos.value.allComponentes;
            catalogos.value.plantillasItinerario = [];
            catalogos.value.poolSegmentos = [];
            return;
        }
        const targetId = extractIdStr(val);
        const maestro = catalogos.value.servicios.find((s: any) => extractIdStr(s.id) === targetId || extractIdStr(s['@id']) === targetId);
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
                    const horaActual = comp.fechaHoraInicio.split('T')[1] || '08:00';
                    comp.fechaHoraInicio = `${nuevaFechaBase}T${horaActual}`;
                    const targetId = extractIdStr(comp.componenteMaestroId);
                    const cMaestro = catalogos.value.allComponentes.find(c => extractIdStr(c.id) === targetId || extractIdStr(c['@id']) === targetId);
                    const duracion = cMaestro?.duracion !== undefined ? parseFloat(cMaestro.duracion) : 1;
                    comp.fechaHoraFin = addDurationToDate(comp.fechaHoraInicio, duracion);
                }
            });
        }

        if (dataActiva.value.cotsegmentos && Array.isArray(dataActiva.value.cotsegmentos)) {
            dataActiva.value.cotsegmentos.forEach((seg: any) => {
                let fechaCalculada = nuevaFechaBase;
                const diaDelSegmento = seg.dia || 1;
                if (diaDelSegmento > 1) {
                    const dateObj = new Date(`${nuevaFechaBase}T12:00:00Z`);
                    dateObj.setUTCDate(dateObj.getUTCDate() + (diaDelSegmento - 1));
                    fechaCalculada = dateObj.toISOString().split('T')[0];
                }
                seg.fechaAbsoluta = fechaCalculada;
            });
        }
    };

    const onComponenteMaestroChange = async (val: string | null): Promise<void> => {
        if (!val || val === 'null') { catalogos.value.tarifas = []; return; }
        const targetId = extractIdStr(val);
        const maestro = catalogos.value.allComponentes.find(c => extractIdStr(c.id) === targetId || extractIdStr(c['@id']) === targetId);

        if (maestro && dataActiva.value) {
            dataActiva.value.nombreSnapshot = JSON.parse(JSON.stringify(getTituloSafe(maestro)));
            if (maestro.duracion !== undefined && dataActiva.value.fechaHoraInicio) {
                dataActiva.value.fechaHoraFin = addDurationToDate(dataActiva.value.fechaHoraInicio, maestro.duracion);
            }
            if(dataActiva.value.fechaHoraInicio && dataActiva.value.fechaHoraFin){
                dataActiva.value.cantidad = calcularPernoctes(dataActiva.value.fechaHoraInicio, dataActiva.value.fechaHoraFin);
            }

            dataActiva.value.snapshotItems = [];
            dataActiva.value.cottarifas = [];

            await fetchComponenteDetalles(val);
        }
    };

    const onComponenteFechasChange = (esCambioInicio: boolean = true): void => {
        if (!dataActiva.value) return;

        if (esCambioInicio && dataActiva.value.fechaHoraInicio) {
            const targetId = extractIdStr(dataActiva.value.componenteMaestroId);
            const maestro = catalogos.value.allComponentes.find(c => extractIdStr(c.id) === targetId || extractIdStr(c['@id']) === targetId);
            const duracion = maestro?.duracion !== undefined ? parseFloat(maestro.duracion) : 1;
            dataActiva.value.fechaHoraFin = addDurationToDate(dataActiva.value.fechaHoraInicio, duracion);
        }

        if (dataActiva.value.fechaHoraInicio && dataActiva.value.fechaHoraFin) {
            dataActiva.value.cantidad = calcularPernoctes(dataActiva.value.fechaHoraInicio, dataActiva.value.fechaHoraFin);
        }
    };

    const onTarifaMaestraChange = (val: string): void => {
        const targetId = extractIdStr(val);
        const maestro = catalogos.value.tarifas.find(t => extractIdStr(t.id) === targetId || extractIdStr(t['@id']) === targetId);
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
        catalogos, cotizacion, fileActual, idiomasDisponibles, isLoading, inspectorActivo, dataActiva,
        isMobileOpen, isSegmentEditorOpen, tipoCambioSugerido,
        resumenFinanciero, itinerarioDinamico, totalCostoNeto, ventaSugerida,
        isComponenteConAlerta, isServicioConAlerta, getI18nText, setI18nText, getTarifaLabel,
        inicializarEditor, guardarCotizacion, abrirNivel, retrocederNivel, cerrarInspectorMobile,
        updateNumPaxGlobal, agregarServicio, eliminarServicio, agregarComponente, eliminarComponente,
        agregarSnapshotItem, eliminarSnapshotItem, toggleUpsellComponent,
        agregarTarifa, eliminarTarifa, fetchComponenteMaestroSilencioso,
        abrirEditorSegmentos, cerrarEditorSegmentos, aplicarPlantilla,
        agregarSegmentoIndividual, removerCotSegmento,
        onServicioMaestroChange, onServicioFechaChange, onComponenteMaestroChange,
        onComponenteFechasChange, onTarifaMaestraChange
    };
});