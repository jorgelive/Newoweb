import { defineStore } from 'pinia';
import { ref, computed } from 'vue';
import { apiClient } from '@/services/apiClient';

import {
    Catalogos, Cotizacion, ComponenteCompleto, Tarifa,
    TarifaSnapshot, SnapshotItem, NivelInspector, Componente, ComponentePlaceholder, CotServicio, Language
} from '@/types/cotizacion-store';

export const isComponenteCompleto = (c: any): c is Componente => {
    return 'tipo' in c;
};

export interface I18nString { language: string; content: string; }
export interface MaestroIdioma { id: string; nombre: string; bandera: string | null; prioridad: number; }

export const useCotizacionEditorStore = defineStore('cotizacionEditorStore', () => {

    const isLoading = ref<boolean>(false);
    const idiomasDisponibles = ref<MaestroIdioma[]>([]);
    const tipoCambioSugerido = ref<number>(1);

    const catalogos = ref<Catalogos>({
        servicios: [],
        allComponentes: [],
        componentes: [],
        tarifas: [],
        plantillasItinerario: [],
        poolSegmentos: [],
        proveedores: [],
        tiposComponente: []
    });

    const todasLasTarifasMaestras = ref<Tarifa[]>([]);

    const cotizacion = ref<Cotizacion | null>(null);
    const fileActual = ref<any>(null);

    // ============================================================================
    // 🔥 LÓGICA DE NEGOCIO: ENUMS (Replicado del Backend)
    // ============================================================================

    const extractIdStr = (val: any) => val ? String(val).split('/').pop() : '';

    const getTipoComponente = (compId: string | null): string => {
        if (!compId) return 'extras';
        const cleanId = extractIdStr(compId);

        const maestro = catalogos.value.allComponentes.find(
            (c) => extractIdStr(c.id || (c as any)['@id'] || '') === cleanId
        );

        // Si es un Componente, TS ahora sabe que tiene la propiedad 'tipo'
        return (maestro && isComponenteCompleto(maestro)) ? maestro.tipo : 'extras';
    };

    const requiereHoraExacta = (tipo?: string): boolean => {
        if(!tipo) return false;
        const config = catalogos.value.tiposComponente.find((t: any) => t.id === tipo.toLowerCase());
        return config ? config.requiereHoraExacta : false;
    };

    // ============================================================================
    // 🔥 HELPERS Y LÓGICA DE TIEMPO
    // ============================================================================

    const getFechaLimpia = (val: any): string => {
        if (!val) return new Date().toISOString().split('T')[0];
        const str = String(val);
        return str.includes('T') ? str.split('T')[0] : str;
    };

    const getHoraLimpia = (val: any): string | null => {
        if (!val) return null;
        const match = String(val).match(/(?:T|\s|^)([01]\d|2[0-3]):([0-5]\d)/);
        return match ? `${match[1]}:${match[2]}` : null;
    };

    const replaceDateKeepTime = (isoDateTime: string, newDate: string): string => {
        if (!isoDateTime) return `${newDate}T08:00`;
        const timePart = isoDateTime.includes('T') ? isoDateTime.split('T')[1] : '08:00';
        return `${newDate}T${timePart}`;
    };

    const getDuracionMs = (inicioIso: string, finIso: string, defaultHoras = 0): number => {
        if (inicioIso && finIso) {
            const oS = new Date(inicioIso).getTime();
            const oE = new Date(finIso).getTime();
            if (!isNaN(oS) && !isNaN(oE) && oE >= oS) return oE - oS;
        }
        return defaultHoras * 60 * 60 * 1000;
    };

    const calcularDiaRelativo = (fechaBase: string, fechaObjetivo: string): number => {
        const d1 = new Date(fechaBase + 'T12:00:00Z');
        const d2 = new Date(fechaObjetivo + 'T12:00:00Z');
        return Math.round((d2.getTime() - d1.getTime()) / (1000 * 60 * 60 * 24)) + 1;
    };

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

    const extraerNotasSnapshot = (segmentoMaestro: any) => {
        if (!segmentoMaestro.notas || !Array.isArray(segmentoMaestro.notas)) return [];
        return segmentoMaestro.notas.map((n: any) => ({
            id: crypto.randomUUID(),
            nombreInterno: n.nombreInterno,
            tipo: n.tipo || 'INFO',
            titulo: JSON.parse(JSON.stringify(n.titulo || [])),
            contenido: JSON.parse(JSON.stringify(n.contenido || []))
        }));
    };

    const extraerImagenesSnapshot = (segmentoMaestro: any) => {
        if (!segmentoMaestro.imagenes || !Array.isArray(segmentoMaestro.imagenes)) return [];
        return JSON.parse(JSON.stringify(segmentoMaestro.imagenes));
    };

    const getTarifaLabel = (cat: any, lang: string) => {
        const nombre = cat.nombreInterno || cat.nombre || 'Tarifa sin nombre';
        const moneda = cat.moneda?.codigo || cat.moneda?.id || cat.moneda || '';
        const monto = parseFloat(cat.monto || cat.montoCosto || 0).toFixed(2);
        const esGrupal = cat.costoPorGrupo || cat.esGrupal || false;

        const min = (cat.edadMinima !== undefined && cat.edadMinima !== null && cat.edadMinima !== '') ? Number(cat.edadMinima) : 0;
        const max = (cat.edadMaxima !== undefined && cat.edadMaxima !== null && cat.edadMaxima !== '') ? Number(cat.edadMaxima) : 120;

        let edadStr = '';
        if (min > 0 || max < 120) {
            if (min > 0 && max < 120) edadStr = ` [${min}-${max} años]`;
            else if (min > 0) edadStr = ` [${min}+ años]`;
            else if (max < 120) edadStr = ` [Hasta ${max} años]`;
        }

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
        if (componente.modo === 'no_incluido' || componente.modo === 'cortesia') return false;

        if (!componente.cottarifas || componente.cottarifas.length === 0) return true;

        const numPaxGlobal = cotizacion.value.numPax || 1;
        let paxAsignados = 0;
        let tieneGrupal = false;

        componente.cottarifas.forEach((t: any) => {
            const maestro = todasLasTarifasMaestras.value.find((cat: any) => extractIdStr(cat.id || cat['@id']) === extractIdStr(t.tarifaMaestraId));
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

    // 🔥 MODIFICADO: Ordena evaluando primero el día y enviando los sin horario al final del bloque de ese día.
    const ordenarComponentesCronologicamente = (componentes: any[]) => {
        if (!componentes || !Array.isArray(componentes)) return;
        componentes.sort((a, b) => {
            const valA = a.fechaHoraInicio || '9999-12-31T23:59:59';
            const valB = b.fechaHoraInicio || '9999-12-31T23:59:59';

            const dateA = valA.split('T')[0];
            const dateB = valB.split('T')[0];

            if (dateA !== dateB) {
                return dateA.localeCompare(dateB);
            }

            const reqA = requiereHoraExacta(getTipoComponente(a.componenteMaestroId));
            const reqB = requiereHoraExacta(getTipoComponente(b.componenteMaestroId));

            if (reqA && !reqB) return -1;
            if (!reqA && reqB) return 1;

            return valA.localeCompare(valB);
        });
    };

    const sincronizarFechaServicio = (servicio: any) => {
        if (!servicio || !servicio.cotcomponentes || servicio.cotcomponentes.length === 0) return;

        let fechaMinima = '9999-12-31T23:59:59';
        servicio.cotcomponentes.forEach((c: any) => {
            if (c.fechaHoraInicio && c.fechaHoraInicio < fechaMinima) {
                fechaMinima = c.fechaHoraInicio;
            }
        });

        if (fechaMinima !== '9999-12-31T23:59:59') {
            const nuevaFechaAbs = getFechaLimpia(fechaMinima);
            if (servicio.fechaInicioAbsoluta !== nuevaFechaAbs) {
                servicio.fechaInicioAbsoluta = nuevaFechaAbs;
            }
        }
    };

    const isComponenteBloqueado = (comp: any): boolean => {
        if (!comp) return false;
        if (comp.cotsegmentoId || comp.cotsegmento) return true;
        if (comp.upsellSourceItemId) return true;

        const servicio = findServicioByComponenteId(comp.id);
        if (servicio && servicio.cotcomponentes) {
            return servicio.cotcomponentes.some((cPadre: any) =>
                cPadre.snapshotItems?.some((item: any) => item.idComponenteInyectado === comp.id)
            );
        }
        return false;
    };

    // ============================================================================
    // 🔥 CLASIFICADOR FINANCIERO EXACTO CON RASTREADOR DE CONFLICTOS
    // ============================================================================

    const resumenFinanciero = computed(() => {
        if (!cotizacion.value || !cotizacion.value.cotservicios) return null;

        const idiomaEdicion = cotizacion.value.idiomaEdicion;
        const numPaxGlobal = Math.max(cotizacion.value.numPax || 1, 1);

        const markup = (parseFloat(cotizacion.value.comision) || 0) / 100;
        const adelantoPct = (parseFloat(cotizacion.value.adelanto) || 0) / 100;
        const tcPromedio = parseFloat(cotizacion.value.tipoCambio) || tipoCambioSugerido.value || 1;

        const todosLosComponentes: any[] = [];
        let mejorPuntaje = -1;
        let maestroTarifas: any[] = [];
        let globalCostoNetoDolares = 0;

        cotizacion.value.cotservicios.forEach((servicio: any) => {
            servicio.cotcomponentes?.forEach((componente: any) => {
                if (componente.modo !== 'incluido' || componente.estado === 'Cancelado') return;

                let cantPasajerosEnComponente = 0;
                const compTarifas: any[] = [];
                const cCant = parseInt(componente.cantidad) || 1;

                componente.cottarifas?.forEach((t: any) => {
                    const maestroT = todasLasTarifasMaestras.value.find((cat: any) => extractIdStr(cat.id || cat['@id']) === extractIdStr(t.tarifaMaestraId));
                    const esGrupal = t.esGrupal !== undefined ? t.esGrupal : (maestroT?.costoPorGrupo || false);

                    const tCant = parseInt(t.cantidad) || 1;
                    const montoBase = parseFloat(t.montoCosto) || 0;

                    const monedaRaw = typeof t.moneda === 'object' ? (t.moneda?.id || t.moneda?.codigo) : (t.moneda || 'USD');
                    const isPen = String(monedaRaw).includes('2') || String(monedaRaw).toUpperCase() === 'PEN';

                    const costoTotalLinea = montoBase * tCant * cCant;
                    const costoTotalDolares = isPen ? (costoTotalLinea / tcPromedio) : costoTotalLinea;

                    globalCostoNetoDolares += costoTotalDolares;

                    const cantidadParaVoter = esGrupal ? numPaxGlobal : tCant;
                    const montoPorPaxDolares = costoTotalDolares / cantidadParaVoter;

                    if (!esGrupal) cantPasajerosEnComponente += tCant;

                    const procedenciaRaw = maestroT?.procedencia || '0';
                    const tipoPaxId = procedenciaRaw;

                    let tipoPaxNombre = 'Cualquier Nacionalidad';
                    if (procedenciaRaw === 'nacional') {
                        tipoPaxNombre = 'Nacional / Peruano';
                    } else if (procedenciaRaw === 'extranjero') {
                        tipoPaxNombre = 'Extranjero';
                    } else if (procedenciaRaw === 'can') {
                        tipoPaxNombre = 'Comunidad Andina (CAN)';
                    }

                    const edadMin = maestroT?.edadMinima ?? 0;
                    const edadMax = maestroT?.edadMaxima ?? 120;

                    compTarifas.push({
                        esGrupal,
                        cantidad: cantidadParaVoter,
                        montoPorPaxDolares,
                        tipoPaxId,
                        tipoPaxNombre,
                        edadMin,
                        edadMax,
                        tipo: `r${edadMin}-${edadMax}t${tipoPaxId}`,
                        origenServicio: getI18nText(servicio.nombreSnapshot, idiomaEdicion) || 'Servicio',
                        origenComponente: getI18nText(componente.nombreSnapshot, idiomaEdicion) || 'Insumo',
                        origenTarifa: getI18nText(t.nombreSnapshot, idiomaEdicion) || 'Tarifa'
                    });
                });

                todosLosComponentes.push(compTarifas);

                if (cantPasajerosEnComponente === numPaxGlobal) {
                    let score = 0;
                    compTarifas.forEach(t => {
                        if (t.tipoPaxId !== '0') score += 100;
                        score += (120 - (t.edadMax - t.edadMin));
                    });
                    if (score > mejorPuntaje) {
                        mejorPuntaje = score;
                        maestroTarifas = compTarifas;
                    }
                }
            });
        });

        const clases: any[] = [];
        if (maestroTarifas.length === 0) {
            clases.push({
                tipo: 'r0-120t0', tipoPaxNombre: 'Cualquier Nacionalidad',
                cantidad: numPaxGlobal, cantidadRestante: numPaxGlobal,
                edadMin: 0, edadMax: 120, tipoPaxId: '0', acumuladoDolares: 0,
                isReal: true,
                conflictos: []
            });
        } else {
            maestroTarifas.forEach(t => {
                if (t.esGrupal) return;
                let clase = clases.find(c => c.tipo === t.tipo);
                if (!clase) {
                    clase = {
                        tipo: t.tipo, tipoPaxNombre: t.tipoPaxNombre, cantidad: 0, cantidadRestante: 0,
                        edadMin: t.edadMin, edadMax: t.edadMax, tipoPaxId: t.tipoPaxId, acumuladoDolares: 0,
                        isReal: true,
                        conflictos: []
                    };
                    clases.push(clase);
                }
                clase.cantidad += t.cantidad;
                clase.cantidadRestante += t.cantidad;
            });
        }

        const asignarAlVoter = (tarifa: any, cantidadPendiente: number, recursividad = 0) => {
            if (recursividad > 10 || cantidadPendiente <= 0) return;

            let bestIdx = -1;
            let maxScore = 0;

            clases.forEach((c, idx) => {
                if (c.cantidadRestante > 0 &&
                    (tarifa.tipoPaxId === c.tipoPaxId || tarifa.tipoPaxId === '0' || c.tipoPaxId === '0') &&
                    tarifa.edadMin <= c.edadMax && tarifa.edadMax >= c.edadMin) {

                    let s = 0.1;
                    if (tarifa.tipoPaxId === c.tipoPaxId && c.tipoPaxId !== '0') s += 10;
                    if (tarifa.edadMin === c.edadMin) s += 2;
                    if (tarifa.edadMax === c.edadMax) s += 2;
                    if (c.cantidadRestante === cantidadPendiente) s += 5;

                    if (s > maxScore) { maxScore = s; bestIdx = idx; }
                }
            });

            if (bestIdx === -1) {
                let anomalo = clases.find(c => c.tipo === 'anomalo_' + tarifa.tipo);
                if (!anomalo) {
                    anomalo = {
                        tipo: 'anomalo_' + tarifa.tipo,
                        tipoPaxNombre: '⚠️ CONFLICTO: ' + tarifa.tipoPaxNombre,
                        cantidad: 0,
                        cantidadRestante: 0,
                        edadMin: tarifa.edadMin,
                        edadMax: tarifa.edadMax,
                        tipoPaxId: tarifa.tipoPaxId,
                        acumuladoDolares: 0,
                        isReal: false,
                        conflictos: []
                    };
                    clases.push(anomalo);
                }
                anomalo.cantidad += cantidadPendiente;
                anomalo.acumuladoDolares += (tarifa.montoPorPaxDolares * cantidadPendiente);

                const rutaConflicto = `${tarifa.origenServicio} ➔ ${tarifa.origenComponente} (${tarifa.origenTarifa})`;
                if (!anomalo.conflictos.includes(rutaConflicto)) {
                    anomalo.conflictos.push(rutaConflicto);
                }
                return;
            }

            const asignarAhora = Math.min(clases[bestIdx].cantidadRestante, cantidadPendiente);
            clases[bestIdx].cantidadRestante -= asignarAhora;
            clases[bestIdx].acumuladoDolares += (tarifa.montoPorPaxDolares * asignarAhora);

            if (cantidadPendiente > asignarAhora) {
                asignarAlVoter(tarifa, cantidadPendiente - asignarAhora, recursividad + 1);
            }
        };

        todosLosComponentes.forEach(compTarifas => {
            compTarifas.forEach((t: any) => {
                if (t.esGrupal) {
                    clases.forEach(c => {
                        if (c.isReal) {
                            c.acumuladoDolares += (t.montoPorPaxDolares * c.cantidad);
                        }
                    });
                } else {
                    asignarAlVoter(t, t.cantidad);
                }
            });
            clases.forEach(c => c.cantidadRestante = c.cantidad);
        });

        const clasesFinales = clases.map(c => {
            const ventaDolares = c.acumuladoDolares * (1 + markup);
            return {
                tipo: c.tipo,
                tipoPaxNombre: c.tipoPaxNombre,
                cantidad: c.cantidad,
                edadMin: c.edadMin,
                edadMax: c.edadMax,
                conflictos: c.conflictos || [],
                resumen: {
                    montoDolares: c.acumuladoDolares,
                    ventaDolares: ventaDolares,
                    gananciaDolares: ventaDolares - c.acumuladoDolares
                }
            };
        });

        const ventaTotalGlobal = globalCostoNetoDolares * (1 + markup);

        return {
            totalCostoNeto: globalCostoNetoDolares,
            totalVentaBruta: ventaTotalGlobal,
            ganancia: ventaTotalGlobal - globalCostoNetoDolares,
            montoAdelanto: ventaTotalGlobal * adelantoPct,
            clasesPasajeros: clasesFinales.sort((a, b) => b.edadMin - a.edadMin)
        };
    });

    const totalCostoNeto = computed(() => resumenFinanciero.value?.totalCostoNeto || 0);
    const ventaSugerida = computed(() => resumenFinanciero.value?.totalVentaBruta || 0);

    const itinerarioDinamico = computed(() => {
        if (!cotizacion.value || !cotizacion.value.cotservicios) return [];

        const todosLosServicios = [...cotizacion.value.cotservicios];

        todosLosServicios.sort((a: any, b: any) => {
            const dateA = getFechaLimpia(a.fechaInicioAbsoluta) || '9999-12-31';
            const dateB = getFechaLimpia(b.fechaInicioAbsoluta) || '9999-12-31';
            return dateA.localeCompare(dateB);
        });

        const grupos: Record<string, any[]> = {};
        todosLosServicios.forEach((srv: any) => {
            const fecha = getFechaLimpia(srv.fechaInicioAbsoluta);

            if (srv.cotcomponentes && Array.isArray(srv.cotcomponentes)) {
                ordenarComponentesCronologicamente(srv.cotcomponentes);
            }

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
    // INICIALIZACIÓN Y BATCH FETCHING (ANTI-WATERFALL)
    // ============================================================================

    const inicializarEditor = async (fileId: string, cotizacionId: string) => {
        if (!fileId) return;

        isLoading.value = true;
        try {
            try {
                const tcResponse = await apiClient.post('/platform/maestro/tipo-cambio/consultar', { fecha: getFechaLimpia(new Date().toISOString()) });
                tipoCambioSugerido.value = parseFloat(tcResponse.data.promedio) || 1;
            } catch (err) {}

            await fetchIdiomas();
            await fetchCatalogos();

            const fileRes = await apiClient.get(`/platform/sales/cotizacion_files/${fileId}`);
            fileActual.value = fileRes.data;

            if (cotizacionId === 'nueva') {
                const maxVersion = fileActual.value.cotizaciones?.reduce((max: number, c: any) => Math.max(max, c.version), 0) || 0;
                crearCotizacionVacia(fileId);

                if (cotizacion.value) {
                    cotizacion.value.version = maxVersion + 1;
                }
            } else {
                await fetchCotizacion(cotizacionId);
            }

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
            const response = await apiClient.get('/platform/maestro/idiomas?prioridad[gt]=0&order[prioridad]=desc');
            idiomasDisponibles.value = response.data['hydra:member'] || response.data['member'] || [];
        } catch (e) {
            idiomasDisponibles.value = [{ id: 'es', nombre: 'Español', bandera: '🇪🇸', prioridad: 1 }];
        }
    };

    const fetchCatalogos = async () => {
        try {
            const [resServicios, resProveedores, resTipos] = await Promise.all([
                apiClient.get('/platform/travel/servicios?pagination=false'),
                apiClient.get('/platform/travel/proveedores?pagination=false'),
                apiClient.get('/cotizacion/user/maestros-enum/componente-tipos')
            ]);
            catalogos.value.servicios = resServicios.data['hydra:member'] || resServicios.data['member'] || [];
            catalogos.value.proveedores = resProveedores.data['hydra:member'] || resProveedores.data['member'] || [];
            catalogos.value.tiposComponente = resTipos.data || [];

            catalogos.value.allComponentes = [];
            catalogos.value.componentes = [];
        } catch (e) {
            console.error("Error cargando catálogos o enums", e);
        }
    };

    const fetchComponenteMaestroSilencioso = async (id: string) => {
        const cleanId = extractIdStr(id);
        if (!cleanId) return;

        const existsIdx = catalogos.value.allComponentes.findIndex(c => extractIdStr(c.id) === cleanId);

        // Verificación de estado: si ya existe y no está en modo "cargando", salimos
        if (existsIdx !== -1 && (catalogos.value.allComponentes[existsIdx] as ComponentePlaceholder).nombre !== 'Sincronizando...') return;

        if (existsIdx === -1) {
            const placeholder: ComponentePlaceholder = {
                id: cleanId,
                nombre: 'Sincronizando...'
            };
            catalogos.value.allComponentes.push(placeholder);
        }

        try {
            const res = await apiClient.get(`/platform/travel/componentes/${cleanId}`);
            const idx = catalogos.value.allComponentes.findIndex(c => extractIdStr(c.id) === cleanId);

            if (idx !== -1) {
                // Aseguramos que res.data sea tratado como el tipo Componente completo
                const componenteCompleto = res.data as Componente;

                // Garantía de integridad: si el backend no envía el array vacío, lo inicializamos
                if (!componenteCompleto.tarifas) componenteCompleto.tarifas = [];
                if (!componenteCompleto.snapshotItems) componenteCompleto.snapshotItems = [];

                catalogos.value.allComponentes.splice(idx, 1, componenteCompleto);
            }
        } catch (e) {
            console.error("Error hidratando componente:", e);
        }
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

            if (exists) {
                const idx = catalogos.value.allComponentes.findIndex(c => extractIdStr(c.id) === targetId);
                catalogos.value.allComponentes[idx] = fetchedComp;
            } else {
                catalogos.value.allComponentes.push(fetchedComp);
            }

            catalogos.value.tarifas = await hydrateRelations(fetchedComp.tarifas || []);

            catalogos.value.tarifas.forEach((t: any) => {
                const tId = extractIdStr(t.id || t['@id']);
                if (!todasLasTarifasMaestras.value.some((pt: any) => extractIdStr(pt.id || pt['@id']) === tId)) {
                    todasLasTarifasMaestras.value.push(t);
                }
            });

            if (dataActiva.value && inspectorActivo.value === 'componente') {
                const itemsRaw = fetchedComp.componenteItems || [];

                if (!dataActiva.value.snapshotItems || dataActiva.value.snapshotItems.length === 0) {
                    dataActiva.value.snapshotItems = await Promise.all(itemsRaw.map(async (item: any) => {
                        let tituloData = [];

                        if (typeof item.diccionario === 'string') {
                            try {
                                const res = await apiClient.get(item.diccionario);
                                tituloData = res.data.titulo || [];
                            } catch (err) {
                                console.error("No se pudo cargar el diccionario:", item.diccionario);
                            }
                        } else if (item.diccionario && item.diccionario.titulo) {
                            tituloData = item.diccionario.titulo;
                        }

                        const modoBackend = item.modo || 'incluido';
                        return {
                            id: crypto.randomUUID(),
                            nombreSnapshot: JSON.parse(JSON.stringify(tituloData)),
                            modo: modoBackend,
                            modoOriginal: modoBackend,
                            incluido: modoBackend === 'incluido' || modoBackend === 'cortesia',
                            tieneUpsell: !!item.componenteAdicionalVinculado,
                            componenteAdicionalVinculado: item.componenteAdicionalVinculado || null,
                            idComponenteInyectado: null,
                            isInjecting: false,
                            sobreescribirTraduccion: false
                        };
                    }));
                }
            }
        } catch (e) {}
    };

    const fetchCotizacion = async (id: string) => {
        try {
            const response = await apiClient.get(`/platform/sales/cotizacions/${id}`);
            const data = response.data as Cotizacion;

            if (!data.cotservicios) data.cotservicios = [];

            const tarifasToFetch = new Set<string>();
            const componentesToFetch = new Set<string>();

            data.cotservicios.forEach((s: any) => {
                s.fechaInicioAbsoluta = getFechaLimpia(s.fechaInicioAbsoluta);

                if (s.cotsegmentos && Array.isArray(s.cotsegmentos)) {
                    s.cotsegmentos.forEach((seg: any) => {
                        seg.fechaAbsoluta = getFechaLimpia(seg.fechaAbsoluta);
                    });
                }

                if (s.cotcomponentes && Array.isArray(s.cotcomponentes)) {
                    s.cotcomponentes.forEach((c: any) => {
                        if (c.cotsegmento && !c.cotsegmentoId) {
                            c.cotsegmentoId = typeof c.cotsegmento === 'string'
                                ? extractIdStr(c.cotsegmento)
                                : extractIdStr(c.cotsegmento.id || c.cotsegmento['@id']);
                        }

                        const cId = extractIdStr(c.componenteMaestroId);
                        if (cId && cId.length === 36 && !catalogos.value.allComponentes.some((catC: any) => extractIdStr(catC.id || catC['@id']) === cId)) {
                            componentesToFetch.add(cId);
                        }

                        if (c.cottarifas && Array.isArray(c.cottarifas)) {
                            c.cottarifas.forEach((t: any) => {
                                const tId = extractIdStr(t.tarifaMaestraId);
                                if (tId && tId.length === 36 && !todasLasTarifasMaestras.value.some((catT: any) => extractIdStr(catT.id || catT['@id']) === tId)) {
                                    tarifasToFetch.add(tId);
                                }
                                if (t.fechaLimitePago) {
                                    t.fechaLimitePago = getFechaLimpia(t.fechaLimitePago);
                                }
                            });
                        }
                    });
                    ordenarComponentesCronologicamente(s.cotcomponentes);
                }
            });

            const fetchPromises: Promise<any>[] = [];

            if (componentesToFetch.size > 0) {
                Array.from(componentesToFetch).forEach(compId => {
                    fetchPromises.push(
                        apiClient.get(`/platform/travel/componentes/${compId}`).then(res => {
                            if (!catalogos.value.allComponentes.some((exist: any) => extractIdStr(exist.id || exist['@id']) === compId)) {
                                catalogos.value.allComponentes.push(res.data);
                            }
                        }).catch(() => null)
                    );
                });
            }

            if (tarifasToFetch.size > 0) {
                Array.from(tarifasToFetch).forEach(tarifaId => {
                    fetchPromises.push(
                        apiClient.get(`/platform/travel/tarifas/${tarifaId}`).then(res => {
                            if (!todasLasTarifasMaestras.value.some((exist: any) => extractIdStr(exist.id || exist['@id']) === tarifaId)) {
                                catalogos.value.tarifas.push(res.data);
                                todasLasTarifasMaestras.value.push(res.data);
                            }
                        }).catch(() => null)
                    );
                });
            }

            await Promise.all(fetchPromises);

            data.idiomaEdicion = 'es';

            cotizacion.value = data;

        } catch (e) {
            throw new Error("No se encontró la cotización o falló la hidratación.");
        }
    };

    const crearCotizacionVacia = (fileId: string) => {
        const idiomaDefault = idiomasDisponibles.value.find(i => i.id === 'es')
            ? 'es'
            : (idiomasDisponibles.value.length ? idiomasDisponibles.value[0].id : 'es');

        cotizacion.value = {
            id: crypto.randomUUID(),
            file: `/platform/sales/cotizacion_files/${fileId}`,
            version: 1,
            estado: 'Pendiente',
            monedaGlobal: 'USD',
            idiomaCliente: idiomaDefault,
            idiomaEdicion: 'es',
            numPax: 1,

            // 👉 Corregido: Pasar como string tal cual exige el esquema OpenAPI
            comision: '20.00',
            adelanto: '0.00',
            tipoCambio: String(tipoCambioSugerido.value || 1),

            // 👉 Campos obligatorios faltantes añadidos para cumplir el contrato
            totalCosto: '0.00',
            totalVenta: '0.00',

            hotelOculto: true,
            precioOculto: false,
            resumen: [],
            sobreescribirTraduccion: false,
            cotservicios: []
        } as Cotizacion;
    };

    const guardarCotizacion = async (): Promise<void> => {

        if (!cotizacion.value) return;
        if (isLoading.value) return;
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
            payload.tipoCambio = String(payload.tipoCambio || tipoCambioSugerido.value || 1);
            payload.clasificacionFinanciera = resumenFinanciero.value;
            delete payload.idiomaEdicion;

            if (payload.cotservicios && Array.isArray(payload.cotservicios)) {
                payload.cotservicios.forEach((servicio: any) => {

                    if (servicio.servicioMaestroId) {
                        servicio.servicioMaestroId = extractIdStr(servicio.servicioMaestroId);
                    }

                    servicio.fechaInicioAbsoluta = getFechaLimpia(servicio.fechaInicioAbsoluta);
                    if (servicio.fechaInicioAbsoluta.length === 10) {
                        servicio.fechaInicioAbsoluta += 'T00:00:00';
                    }

                    if (servicio.cotsegmentos && Array.isArray(servicio.cotsegmentos)) {
                        servicio.cotsegmentos.forEach((seg: any) => {
                            seg.fechaAbsoluta = getFechaLimpia(seg.fechaAbsoluta || servicio.fechaInicioAbsoluta);
                            if (seg.fechaAbsoluta.length === 10) seg.fechaAbsoluta += 'T00:00:00';
                        });
                    }

                    if (servicio.cotcomponentes && Array.isArray(servicio.cotcomponentes)) {
                        servicio.cotcomponentes.forEach((componente: any) => {
                            componente.cantidad = parseInt(componente.cantidad) || 1;

                            if (componente.componenteMaestroId) {
                                componente.componenteMaestroId = extractIdStr(componente.componenteMaestroId);
                            }

                            const maestroTipo = getTipoComponente(componente.componenteMaestroId);
                            if (!requiereHoraExacta(maestroTipo)) {
                                if(componente.fechaHoraInicio) componente.fechaHoraInicio = componente.fechaHoraInicio.split('T')[0] + 'T00:00:00';
                                if(componente.fechaHoraFin) componente.fechaHoraFin = componente.fechaHoraFin.split('T')[0] + 'T00:00:00';
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
                                    if (tarifa.fechaLimitePago === '') {
                                        tarifa.fechaLimitePago = null;
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
                s.fechaInicioAbsoluta = getFechaLimpia(s.fechaInicioAbsoluta);

                s.cotsegmentos?.forEach((seg: any) => {
                    seg.sobreescribirTraduccion = false;
                    seg.fechaAbsoluta = getFechaLimpia(seg.fechaAbsoluta);
                });

                s.cotcomponentes?.forEach((c: any) => {
                    c.sobreescribirTraduccion = false;
                    c.snapshotItems?.forEach((i: any) => i.sobreescribirTraduccion = false);
                    c.cottarifas?.forEach((t: any) => {
                        t.sobreescribirTraduccion = false;
                        if (t.fechaLimitePago) {
                            t.fechaLimitePago = getFechaLimpia(t.fechaLimitePago);
                        }
                    });

                    if (c.cotsegmento) {
                        c.cotsegmentoId = typeof c.cotsegmento === 'string'
                            ? extractIdStr(c.cotsegmento)
                            : extractIdStr(c.cotsegmento.id || c.cotsegmento['@id']);
                    }
                });

                ordenarComponentesCronologicamente(s.cotcomponentes);
            });

            cotizacion.value = savedData;

            if (inspectorActivo.value !== 'resumen' && dataActiva.value) {
                const oldId = dataActiva.value.id;
                let relinked = null;

                if (inspectorActivo.value === 'servicio') {
                    relinked = savedData.cotservicios.find((s: any) => s.id === oldId);
                } else if (inspectorActivo.value === 'componente') {
                    savedData.cotservicios.forEach((s: any) => {
                        const found = s.cotcomponentes?.find((c: any) => c.id === oldId);
                        if (found) relinked = found;
                    });
                } else if (inspectorActivo.value === 'tarifa') {
                    savedData.cotservicios.forEach((s: any) => {
                        s.cotcomponentes?.forEach((c: any) => {
                            const found = c.cottarifas?.find((t: any) => t.id === oldId);
                            if (found) relinked = found;
                        });
                    });
                }

                if (relinked) {
                    dataActiva.value = relinked;
                } else {
                    retrocederNivel();
                }
            }

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
        if (!cotizacion.value || !cotizacion.value.cotservicios) return null;

        return cotizacion.value.cotservicios.find(
            (s) => s.cotcomponentes?.some((c) => extractIdStr(c.id) === extractIdStr(compId))
        ) || null;
    };

    const updateNumPaxGlobal = (newPaxStr: string | number) => {
        // 👉 Guardián de tipo: detiene la ejecución si cotizacion.value es null
        if (!cotizacion.value || !cotizacion.value.cotservicios) return;

        const oldPax = parseInt(String(cotizacion.value.numPax)) || 1;
        const newPax = parseInt(String(newPaxStr)) || 1;

        if (oldPax === newPax) return;

        for (const servicio of cotizacion.value.cotservicios) {
            if (!servicio.cotcomponentes) continue;
            for (const componente of servicio.cotcomponentes) {
                if (!componente.cottarifas) continue;
                for (const tarifa of componente.cottarifas) {
                    if (!tarifa.esGrupal && parseInt(String(tarifa.cantidad)) === oldPax) {
                        tarifa.cantidad = newPax;
                    }
                }
            }
        }

        cotizacion.value.numPax = newPax;
    };

    const agregarServicio = (): void => {
        if (!cotizacion.value) return;

        const cots = cotizacion.value.cotservicios || [];
        const fechaBase = cots.length > 0
            ? getFechaLimpia(cots[cots.length - 1].fechaInicioAbsoluta)
            : getFechaLimpia(new Date().toISOString());

        const nuevoServicio = {
            id: crypto.randomUUID(),
            servicioMaestroId: null,
            nombreSnapshot: [{ language: 'es', content: 'Nuevo Servicio' }],
            itinerarioNombreSnapshot: [{ language: 'es', content: 'Sin plantilla' }],
            fechaInicioAbsoluta: fechaBase,
            cotsegmentos: [],
            cotcomponentes: [],
            sobreescribirTraduccion: false
        } as CotServicio;

        if (!cotizacion.value.cotservicios) cotizacion.value.cotservicios = [];
        cotizacion.value.cotservicios.push(nuevoServicio);
        abrirNivel('servicio', nuevoServicio);
    };

    const eliminarServicio = (servicioId: string): void => {
        if (!cotizacion.value || !cotizacion.value.cotservicios) return;

        // 👉 Cambiado (s: any) por (s: CotServicio) para mantener el contrato i18n
        cotizacion.value.cotservicios = cotizacion.value.cotservicios.filter(
            (s: CotServicio) => s.id !== servicioId
        );

        if (dataActiva.value?.id === servicioId) retrocederNivel();
    };

    const agregarComponente = (servicioId: string): void => {
        if (!cotizacion.value || !cotizacion.value.cotservicios) return;
        const servicio = cotizacion.value.cotservicios.find((s: any) => s.id === servicioId);
        if (servicio) {
            const fechaBase = getFechaLimpia(servicio.fechaInicioAbsoluta);
            const fechaHoraInicio = `${fechaBase}T00:00`;
            const nuevoComponente: any = {
                id: crypto.randomUUID(), componenteMaestroId: null,
                nombreSnapshot: [],
                cantidad: 1, estado: 'Pendiente', modo: 'incluido',
                fechaHoraInicio: fechaHoraInicio,
                fechaHoraFin: fechaHoraInicio,
                cotsegmentoId: null,
                sobreescribirTraduccion: false,
                snapshotItems: [], cottarifas: []
            };
            if (!servicio.cotcomponentes) servicio.cotcomponentes = [];
            servicio.cotcomponentes.push(nuevoComponente);

            ordenarComponentesCronologicamente(servicio.cotcomponentes);
            sincronizarFechaServicio(servicio);
            abrirNivel('componente', nuevoComponente);
        }
    };

    const eliminarComponente = (servicioId: string, componenteId: string): void => {
        if (!cotizacion.value || !cotizacion.value.cotservicios) return;
        const servicio = cotizacion.value.cotservicios.find((s: any) => s.id === servicioId);
        if (servicio && servicio.cotcomponentes) {
            servicio.cotcomponentes = servicio.cotcomponentes.filter((c: any) => c.id !== componenteId);
            sincronizarFechaServicio(servicio);
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
            item.modo = item.tieneUpsell ? 'upsell_injected' : 'incluido';

            if (item.tieneUpsell && !item.idComponenteInyectado && !item.isInjecting) {
                item.isInjecting = true;

                try {
                    let compMaestro = item.componenteAdicionalVinculado;

                    if (typeof compMaestro === 'string') {
                        const targetIriOrId = compMaestro;
                        const res = await apiClient.get(targetIriOrId);
                        compMaestro = res.data;
                    }

                    const targetId = extractIdStr(compMaestro.id || compMaestro['@id']);
                    if (!catalogos.value.allComponentes.some(c => extractIdStr(c.id) === targetId)) {
                        catalogos.value.allComponentes.push(compMaestro);
                    }

                    const nuevoId = crypto.randomUUID();
                    item.idComponenteInyectado = nuevoId;

                    const nuevoComp: any = {
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

                    if (compMaestro.componenteItems && Array.isArray(compMaestro.componenteItems)) {
                        nuevoComp.snapshotItems = await Promise.all(compMaestro.componenteItems.map(async (subItem: any) => {
                            let tituloData = [];
                            if (typeof subItem.diccionario === 'string') {
                                try {
                                    const resDicc = await apiClient.get(subItem.diccionario);
                                    tituloData = resDicc.data.titulo || [];
                                } catch (err) {
                                    console.error("No se pudo cargar el diccionario inyectado:", subItem.diccionario);
                                }
                            } else if (subItem.diccionario && subItem.diccionario.titulo) {
                                tituloData = subItem.diccionario.titulo;
                            }
                            return {
                                id: crypto.randomUUID(),
                                nombreSnapshot: JSON.parse(JSON.stringify(tituloData)),
                                modo: subItem.modo || 'incluido',
                                modoOriginal: subItem.modo || 'incluido',
                                incluido: subItem.modo === 'incluido' || subItem.modo === 'cortesia',
                                tieneUpsell: !!subItem.componenteAdicionalVinculado,
                                componenteAdicionalVinculado: subItem.componenteAdicionalVinculado || null,
                                idComponenteInyectado: null,
                                isInjecting: false,
                                sobreescribirTraduccion: false
                            };
                        }));
                    }

                    let tarifasParaInyectar = [];
                    if (compMaestro.tarifas && compMaestro.tarifas.length === 1) {
                        tarifasParaInyectar.push(compMaestro.tarifas[0]);
                    }

                    nuevoComp.cottarifas = tarifasParaInyectar.map((tarifa: any) => ({
                        id: crypto.randomUUID(),
                        tarifaMaestraId: tarifa.id || tarifa['@id'],
                        nombreSnapshot: JSON.parse(JSON.stringify(getTituloSafe(tarifa))),
                        cantidad: tarifa.costoPorGrupo ? 1 : (cotizacion.value?.numPax || 1),
                        moneda: tarifa.moneda?.codigo || tarifa.moneda?.id || tarifa.moneda || 'USD',
                        montoCosto: parseFloat(tarifa.montoCosto || tarifa.monto || 0),
                        esGrupal: tarifa.costoPorGrupo || false,
                        proveedorMaestroId: tarifa.provider ? extractIdStr(tarifa.provider.id || tarifa.provider['@id'] || tarifa.provider) : null,
                        proveedorNombreSnapshot: tarifa.provider?.nombreComercial || null,
                        nombreParaProveedorSnapshot: tarifa.nombreParaProveedor || tarifa.nombreInterno || null,
                        estadoOperativoSnapshot: 'Sin Solicitar',
                        fechaLimitePago: null,
                        condicionesPagoSnapshot: null,
                        tipoModalidadSnapshot: tarifa.modalidad || 'Normal',
                        detallesOperativos: [],
                        sobreescribirTraduccion: false
                    }));

                    const servicio = findServicioByComponenteId(componentePadre.id);
                    if (servicio) {
                        if (!servicio.cotcomponentes) servicio.cotcomponentes = [];
                        servicio.cotcomponentes.push(nuevoComp);
                        ordenarComponentesCronologicamente(servicio.cotcomponentes);
                        sincronizarFechaServicio(servicio);
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
        // 1. 👉 Guardián de tipo: elimina el error TS18047 de un solo golpe
        if (!cotizacion.value) return;

        // Buscamos el componente y lo casteamos a ComponenteCompleto para que herede las interfaces extendidas del Frontend
        const componente = cotizacion.value.cotservicios
            ?.flatMap(s => s.cotcomponentes || [])
            // 👉 Pasamos por 'unknown' primero para disolver la validación de solapamiento de OpenAPI
            .find(c => c.id === componenteId) as unknown as ComponenteCompleto;

        if (!componente) return;

        // Lógica para calcular pasajeros asignados...
        let paxAsignados = 0;
        const tarifas = componente.cottarifas || [];
        tarifas.forEach((t: any) => {
            const esGrupal = t.esGrupal !== undefined ? t.esGrupal : false;
            if (!esGrupal) paxAsignados += parseInt(String(t.cantidad)) || 0;
        });

        // Usamos el estado real del store cotizacion.value.numPax
        let pasajerosRestantes = (parseInt(String(cotizacion.value.numPax)) || 1) - paxAsignados;
        if (pasajerosRestantes <= 0) pasajerosRestantes = 1;

        // 2. 👉 Construcción alineada con el contrato estricto
        const nuevaTarifa = {
            id: crypto.randomUUID(),
            tarifaMaestraId: null,
            // Usamos el string 'es' directamente para que calce con la interfaz sin obligarte a importar Enums
            nombreSnapshot: [{ language: 'es', content: 'Nueva Tarifa' }],
            cantidad: pasajerosRestantes,
            moneda: cotizacion.value.monedaGlobal,

            // string como demanda la API de persistencia
            montoCosto: '0.00',

            esGrupal: false,
            tipoModalidadSnapshot: 'Individual',
            proveedorMaestroId: null,
            proveedorNombreSnapshot: null,
            estadoOperativoSnapshot: 'Pendiente',
            fechaLimitePago: null,
            sobreescribirTraduccion: false
        } as TarifaSnapshot; // Casting para asegurar compatibilidad de empuje

        if (!componente.cottarifas) componente.cottarifas = [];
        componente.cottarifas.push(nuevaTarifa); // ¡Limpio! Al ser ComponenteCompleto, TypeScript ya no choca aquí

        abrirNivel('tarifa', nuevaTarifa);
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

    const inyectarComponentesDeSegmento = async (segmentoMaestro: any, diaDelSegmento: number = 1, idSegmentoGenerado: string, itinerarioId: string | null = null) => {
        if (!dataActiva.value) return;

        if (segmentoMaestro.segmentoComponentes && Array.isArray(segmentoMaestro.segmentoComponentes)) {

            // 🔥 Llave = compId únicamente. El objetivo es UN solo ganador por
            // componente, refinado progresivamente por 'dia' y 'itinerarioContexto'.
            const mejoresMatches = new Map<string, any>();

            segmentoMaestro.segmentoComponentes.forEach((segComp: any) => {
                let compMaestro = segComp.componente;
                if (!compMaestro) return;

                if (typeof compMaestro === 'string') {
                    const cId = String(extractIdStr(compMaestro) || '');
                    compMaestro = catalogos.value.allComponentes.find((c: any) => String(extractIdStr(c.id || c['@id']) || '') === cId);
                }

                if (!compMaestro || typeof compMaestro !== 'object') return;

                const compId = String(extractIdStr(compMaestro.id || compMaestro['@id']) || '');
                if (!compId) return;

                // 🔥 1. FILTRO DE REFINAMIENTO POR DÍA
                // Si el backend envía el campo 'dia' y este no hace match exacto
                // con el día relativo de la plantilla, ignoramos el componente.
                if (segComp.dia && parseInt(segComp.dia) !== diaDelSegmento) {
                    return;
                }

                let esPrioritario = false;

                // 🔥 2. FILTRO/REFINAMIENTO POR ITINERARIO CONTEXTO
                if (segComp.itinerarioContexto) {
                    const ctxId = String(extractIdStr(segComp.itinerarioContexto.id || segComp.itinerarioContexto['@id'] || segComp.itinerarioContexto) || '');
                    const currentItinerarioId = String(extractIdStr(itinerarioId) || '');

                    if (itinerarioId && ctxId === currentItinerarioId) {
                        esPrioritario = true;
                    } else {
                        return;
                    }
                }

                // 🔥 3. RESOLUCIÓN DE BEST MATCH POR COMPONENTE
                // Solo reemplaza si no hay match previo, o si el actual es más
                // refinado (prioritario) que el que ya estaba guardado.
                // Esto es independiente del orden en que vengan los registros.
                const matchPrevio = mejoresMatches.get(compId);
                if (!matchPrevio || (esPrioritario && !matchPrevio.esPrioritario)) {
                    segComp.tempCompObj = compMaestro;
                    segComp.esPrioritario = esPrioritario;
                    mejoresMatches.set(compId, segComp);
                }
            });

            for (const [compId, segComp] of mejoresMatches.entries()) {
                let compMaestro = segComp.tempCompObj;

                const targetId = String(extractIdStr(compMaestro.id || compMaestro['@id']) || '');

                const compHidratado = catalogos.value.allComponentes.find(
                    (c): c is Componente => 'tarifas' in c // Si tiene 'tarifas', ES Componente
                );

                if (compHidratado && compHidratado.tarifas) {
                    // Aquí TypeScript ya sabe que compHidratado es de tipo Componente
                    compMaestro = compHidratado;
                }

                let fechaBase = getFechaLimpia(dataActiva.value.fechaInicioAbsoluta);

                // 🔥 ASIGNACIÓN CRONOLÓGICA DIRECTA
                // Toma la fecha raíz del servicio y le suma los días relativos del párrafo
                // en el que está inyectando.
                if (diaDelSegmento > 1) {
                    const dateObj = new Date(`${fechaBase}T12:00:00Z`);
                    dateObj.setUTCDate(dateObj.getUTCDate() + (diaDelSegmento - 1));
                    fechaBase = dateObj.toISOString().split('T')[0];
                }

                const tipoComp = compMaestro.tipo || 'extras';
                const reqHora = requiereHoraExacta(tipoComp);

                const hInicio = reqHora ? (getHoraLimpia(segComp.hora) || '08:00') : '00:00';
                const fHoraInicio = `${fechaBase}T${hInicio}`;

                let fHoraFin = '';
                if (reqHora) {
                    const hFin = getHoraLimpia(segComp.horaFin);
                    if (hFin) {
                        fHoraFin = `${fechaBase}T${hFin}`;
                        if (fHoraFin < fHoraInicio) {
                            const dNext = new Date(`${fechaBase}T12:00:00Z`);
                            dNext.setUTCDate(dNext.getUTCDate() + 1);
                            fHoraFin = `${dNext.toISOString().split('T')[0]}T${hFin}`;
                        }
                    } else {
                        const duracion = parseFloat(compMaestro.duracion || 0);
                        fHoraFin = addDurationToDate(fHoraInicio, duracion);
                    }
                } else {
                    const duracion = parseFloat(compMaestro.duracion || 0);
                    const calcFin = addDurationToDate(fHoraInicio, duracion);
                    fHoraFin = calcFin.split('T')[0] + 'T00:00';
                }

                const snapshotItemsPreparados = await Promise.all((compMaestro.componenteItems || []).map(async (item: any) => {
                    let diccData = item.diccionario;
                    let tituloSnapshot = [];

                    if (typeof diccData === 'string') {
                        try {
                            const res = await apiClient.get(diccData);
                            tituloSnapshot = res.data.titulo || [];
                        } catch (e) {
                            console.error("No se pudo profundizar el diccionario desde el segmento:", diccData, e);
                        }
                    } else if (diccData && diccData.titulo) {
                        tituloSnapshot = diccData.titulo;
                    }

                    const modoBackend = item.modo || 'incluido';
                    return {
                        id: crypto.randomUUID(),
                        nombreSnapshot: JSON.parse(JSON.stringify(tituloSnapshot)),
                        modo: modoBackend,
                        modoOriginal: modoBackend,
                        incluido: modoBackend === 'incluido' || modoBackend === 'cortesia',
                        tieneUpsell: !!item.componenteAdicionalVinculado,
                        componenteAdicionalVinculado: item.componenteAdicionalVinculado || null,
                        idComponenteInyectado: null,
                        isInjecting: false,
                        sobreescribirTraduccion: false
                    };
                }));

                const nuevoComp: any = {
                    id: crypto.randomUUID(),
                    componenteMaestroId: compMaestro.id || compMaestro['@id'],
                    nombreSnapshot: JSON.parse(JSON.stringify(getTituloSafe(compMaestro))),
                    cantidad: calcularPernoctes(fHoraInicio, fHoraFin),
                    estado: 'Pendiente',
                    modo: segComp.modo || 'incluido',
                    fechaHoraInicio: fHoraInicio,
                    fechaHoraFin: fHoraFin,
                    cotsegmentoId: idSegmentoGenerado,
                    sobreescribirTraduccion: false,
                    cottarifas: [],
                    snapshotItems: snapshotItemsPreparados
                };

                let tarifasParaInyectar: any[] = [];
                const tarifaDefId = extractIdStr(segComp.tarifaId || segComp.tarifaPredeterminada?.id || segComp.tarifaPredeterminada);

                if (tarifaDefId) {
                    const tDef = (compMaestro.tarifas || []).find((t: any) => extractIdStr(t.id || t['@id']) === tarifaDefId)
                        || todasLasTarifasMaestras.value.find((t: any) => extractIdStr(t.id || t['@id']) === tarifaDefId);
                    if (tDef) tarifasParaInyectar.push(tDef);
                } else if (compMaestro.tarifas && compMaestro.tarifas.length === 1 && nuevoComp.modo !== 'no_incluido') {
                    tarifasParaInyectar.push(compMaestro.tarifas[0]);
                }

                nuevoComp.cottarifas = tarifasParaInyectar.map((tarifa: any) => ({
                    id: crypto.randomUUID(),
                    tarifaMaestraId: tarifa.id || tarifa['@id'],
                    nombreSnapshot: JSON.parse(JSON.stringify(getTituloSafe(tarifa))),
                    cantidad: tarifa.costoPorGrupo ? 1 : (cotizacion.value?.numPax || 1),
                    moneda: tarifa.moneda?.codigo || tarifa.moneda?.id || tarifa.moneda || 'USD',
                    montoCosto: parseFloat(tarifa.montoCosto || tarifa.monto || 0),
                    esGrupal: tarifa.costoPorGrupo || false,
                    proveedorMaestroId: tarifa.provider ? extractIdStr(tarifa.provider.id || tarifa.provider['@id'] || tarifa.provider) : null,
                    proveedorNombreSnapshot: tarifa.provider?.nombreComercial || null,
                    nombreParaProveedorSnapshot: tarifa.nombreParaProveedor || tarifa.nombreInterno || null,
                    estadoOperativoSnapshot: 'Sin Solicitar',
                    fechaLimitePago: null,
                    condicionesPagoSnapshot: null,
                    tipoModalidadSnapshot: tarifa.modalidad || 'Normal',
                    detallesOperativos: [],
                    sobreescribirTraduccion: false
                }));

                if (!dataActiva.value.cotcomponentes) dataActiva.value.cotcomponentes = [];
                dataActiva.value.cotcomponentes.push(nuevoComp);
            }

            ordenarComponentesCronologicamente(dataActiva.value.cotcomponentes);
            sincronizarFechaServicio(dataActiva.value);
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

                const compIdsToFetch = new Set<string>();
                segmentosReales.forEach((seg: any) => {
                    (seg.segmentoComponentes || []).forEach((sc: any) => {
                        const cId = extractIdStr(sc.componente?.id || sc.componente?.['@id'] || sc.componente);
                        if (cId) compIdsToFetch.add(cId);
                    });
                });
                await Promise.all(Array.from(compIdsToFetch).map(id => fetchComponenteDetalles(id)));

                for (const [index, seg] of segmentosReales.entries()) {
                    ordenMaximo++;
                    const relacionOriginal = arrayRelaciones[index];
                    const diaDelSegmento = relacionOriginal.dia || 1;
                    const nuevoIdSeg = crypto.randomUUID();

                    let fechaCalculada = getFechaLimpia(dataActiva.value.fechaInicioAbsoluta);
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
                        notasSnapshot: extraerNotasSnapshot(seg),
                        imagenesSnapshot: extraerImagenesSnapshot(seg),
                        sobreescribirTraduccion: false
                    });

                    await inyectarComponentesDeSegmento(seg, diaDelSegmento, nuevoIdSeg, plantillaId);
                }
            }
        } catch (error) {
            console.error("Error al aplicar la plantilla profunda", error);
        } finally {
            isLoading.value = false;
        }
    };

    const agregarSegmentoIndividual = async (segmentoMaestroRaw: any, itinerarioId: string | null = null): Promise<void> => {
        if (!dataActiva.value) return;

        let segmentoMaestro = segmentoMaestroRaw;
        try {
            const idStr = extractIdStr(segmentoMaestroRaw.id || segmentoMaestroRaw['@id']);
            if (idStr) {
                const res = await apiClient.get(`/platform/travel/segmentos/${idStr}`);
                segmentoMaestro = res.data;
            }
        } catch (e) {
            console.error("No se pudo profundizar el segmento", e);
        }

        const compIdsToFetch = new Set<string>();
        (segmentoMaestro.segmentoComponentes || []).forEach((sc: any) => {
            const cId = extractIdStr(sc.componente?.id || sc.componente?.['@id'] || sc.componente);
            if (cId) compIdsToFetch.add(cId);
        });
        await Promise.all(Array.from(compIdsToFetch).map(id => fetchComponenteDetalles(id)));

        const ordenNuevo = dataActiva.value.cotsegmentos ? dataActiva.value.cotsegmentos.length + 1 : 1;
        const nuevoIdSeg = crypto.randomUUID();
        const fechaCalculada = getFechaLimpia(dataActiva.value.fechaInicioAbsoluta);

        if (!dataActiva.value.cotsegmentos) dataActiva.value.cotsegmentos = [];
        dataActiva.value.cotsegmentos.push({
            id: nuevoIdSeg,
            dia: 1,
            orden: ordenNuevo,
            fechaAbsoluta: fechaCalculada,
            nombreSnapshot: JSON.parse(JSON.stringify(getTituloSafe(segmentoMaestro))),
            contenidoSnapshot: JSON.parse(JSON.stringify(segmentoMaestro.contenido || [])),
            notasSnapshot: extraerNotasSnapshot(segmentoMaestro),
            imagenesSnapshot: extraerImagenesSnapshot(segmentoMaestro),
            sobreescribirTraduccion: false
        });

        await inyectarComponentesDeSegmento(segmentoMaestro, 1, nuevoIdSeg, itinerarioId);
    };

    const procesarInsercionSegmento = async (segmentoMaestroRaw: any, itinerarioId: string | null, accion: 'append' | 'replace' | 'insert', targetId?: string) => {
        if (!dataActiva.value) return;
        if (!dataActiva.value.cotsegmentos) dataActiva.value.cotsegmentos = [];

        if (accion === 'append' || !targetId) {
            await agregarSegmentoIndividual(segmentoMaestroRaw, itinerarioId);
            return;
        }

        let segmentoMaestro = segmentoMaestroRaw;
        try {
            const idStr = extractIdStr(segmentoMaestroRaw.id || segmentoMaestroRaw['@id']);
            if (idStr) {
                const res = await apiClient.get(`/platform/travel/segmentos/${idStr}`);
                segmentoMaestro = res.data;
            }
        } catch (e) {
            console.error("No se pudo profundizar el segmento", e);
        }

        const compIdsToFetch = new Set<string>();
        (segmentoMaestro.segmentoComponentes || []).forEach((sc: any) => {
            const cId = extractIdStr(sc.componente?.id || sc.componente?.['@id'] || sc.componente);
            if (cId) compIdsToFetch.add(cId);
        });
        await Promise.all(Array.from(compIdsToFetch).map(id => fetchComponenteDetalles(id)));

        let fechaCalculada = getFechaLimpia(dataActiva.value.fechaInicioAbsoluta);
        const index = dataActiva.value.cotsegmentos.findIndex((s: any) => s.id === targetId);

        if (index === -1) {
            await agregarSegmentoIndividual(segmentoMaestro, itinerarioId);
            return;
        }

        if (accion === 'replace') {
            const segAfectado = dataActiva.value.cotsegmentos[index];

            if (dataActiva.value.cotcomponentes) {
                dataActiva.value.cotcomponentes = dataActiva.value.cotcomponentes.filter(
                    (c: any) => c.cotsegmentoId !== segAfectado.id
                );
            }

            segAfectado.nombreSnapshot = JSON.parse(JSON.stringify(getTituloSafe(segmentoMaestro)));
            segAfectado.contenidoSnapshot = JSON.parse(JSON.stringify(segmentoMaestro.contenido || []));
            segAfectado.notasSnapshot = extraerNotasSnapshot(segmentoMaestro);
            segAfectado.imagenesSnapshot = extraerImagenesSnapshot(segmentoMaestro);
            segAfectado.sobreescribirTraduccion = false;

            await inyectarComponentesDeSegmento(segmentoMaestro, segAfectado.dia || 1, segAfectado.id, itinerarioId);

        } else if (accion === 'insert') {
            const nuevoIdSeg = crypto.randomUUID();
            const diaDelSegmento = dataActiva.value.cotsegmentos[index].dia || 1;

            if (diaDelSegmento > 1) {
                const dateObj = new Date(`${fechaCalculada}T12:00:00Z`);
                dateObj.setUTCDate(dateObj.getUTCDate() + (diaDelSegmento - 1));
                fechaCalculada = dateObj.toISOString().split('T')[0];
            }

            const nuevoSeg = {
                id: nuevoIdSeg,
                dia: diaDelSegmento,
                orden: 0,
                fechaAbsoluta: fechaCalculada,
                nombreSnapshot: JSON.parse(JSON.stringify(getTituloSafe(segmentoMaestro))),
                contenidoSnapshot: JSON.parse(JSON.stringify(segmentoMaestro.contenido || [])),
                notasSnapshot: extraerNotasSnapshot(segmentoMaestro),
                imagenesSnapshot: extraerImagenesSnapshot(segmentoMaestro),
                sobreescribirTraduccion: false
            };

            dataActiva.value.cotsegmentos.splice(index + 1, 0, nuevoSeg);
            dataActiva.value.cotsegmentos.forEach((s: any, i: number) => s.orden = i + 1);

            await inyectarComponentesDeSegmento(segmentoMaestro, diaDelSegmento, nuevoIdSeg, itinerarioId);
        }
    };

    const removerCotSegmento = (id: string): void => {
        if (!dataActiva.value) return;
        if (dataActiva.value.cotsegmentos) {
            dataActiva.value.cotsegmentos = dataActiva.value.cotsegmentos.filter((s: any) => s.id !== id);
        }
        if (dataActiva.value.cotcomponentes) {
            dataActiva.value.cotcomponentes = dataActiva.value.cotcomponentes.filter((c: any) => c.cotsegmentoId !== id && c.cotsegmento !== id);
            sincronizarFechaServicio(dataActiva.value);
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
        const nuevaFechaBase = getFechaLimpia(dataActiva.value.fechaInicioAbsoluta);

        // 1. Inferir la fecha base anterior antes de aplicar los cambios
        let oldFechaBase = '9999-12-31';
        if (dataActiva.value.cotcomponentes && dataActiva.value.cotcomponentes.length > 0) {
            dataActiva.value.cotcomponentes.forEach((c: any) => {
                if (c.fechaHoraInicio) {
                    const d = c.fechaHoraInicio.split('T')[0];
                    if (d < oldFechaBase) oldFechaBase = d;
                }
            });
        } else if (dataActiva.value.cotsegmentos && dataActiva.value.cotsegmentos.length > 0) {
            oldFechaBase = dataActiva.value.cotsegmentos[0].fechaAbsoluta;
        }

        if (oldFechaBase === '9999-12-31') oldFechaBase = nuevaFechaBase;

        const diffTime = new Date(`${nuevaFechaBase}T12:00:00Z`).getTime() - new Date(`${oldFechaBase}T12:00:00Z`).getTime();
        const diffDays = Math.round(diffTime / (1000 * 60 * 60 * 24));

        if (dataActiva.value.cotcomponentes && Array.isArray(dataActiva.value.cotcomponentes)) {
            dataActiva.value.cotcomponentes.forEach((comp: any) => {
                if (comp.fechaHoraInicio) {
                    const duracionMs = getDuracionMs(comp.fechaHoraInicio, comp.fechaHoraFin);

                    const oldFechaString = comp.fechaHoraInicio.split('T')[0];
                    const horaActual = comp.fechaHoraInicio.split('T')[1] || '08:00';

                    const dateObj = new Date(`${oldFechaString}T12:00:00Z`);
                    dateObj.setUTCDate(dateObj.getUTCDate() + diffDays);
                    const nuevaFechaCompStr = dateObj.toISOString().split('T')[0];

                    comp.fechaHoraInicio = `${nuevaFechaCompStr}T${horaActual}`;

                    const nS = new Date(comp.fechaHoraInicio).getTime();
                    const nE = new Date(nS + duracionMs);
                    const offH = nE.getTimezoneOffset() * 60000;
                    comp.fechaHoraFin = (new Date(nE.getTime() - offH)).toISOString().slice(0, 16);
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

        if (dataActiva.value.cotcomponentes) {
            ordenarComponentesCronologicamente(dataActiva.value.cotcomponentes);
        }
    };

    /**
     * Maneja el cambio de selección de un Componente Maestro.
     * Hidrata los snapshots y recalcula fechas basándose en el tipo y duración del componente.
     *
     * @param val El ID o IRI del componente seleccionado.
     */
    const onComponenteMaestroChange = async (val: string | null): Promise<void> => {
        if (!val || val === 'null') {
            catalogos.value.tarifas = [];
            return;
        }

        const targetId = extractIdStr(val);

        // Eliminamos el (c: any) y usamos la inferencia tipada de allComponentes
        const maestro = catalogos.value.allComponentes.find(
            (c) => extractIdStr(c.id || (c as any)['@id'] || '') === targetId
        );

        // 1. Aplicamos el Type Guard: isComponenteCompleto
        if (maestro && isComponenteCompleto(maestro) && dataActiva.value) {

            dataActiva.value.nombreSnapshot = JSON.parse(JSON.stringify(getTituloSafe(maestro)));

            // TypeScript ahora sabe con 100% de certeza que maestro.tipo existe
            const reqHora = requiereHoraExacta(maestro.tipo);
            const fechaDate = dataActiva.value.fechaHoraInicio.split('T')[0];

            if (reqHora) {
                dataActiva.value.fechaHoraInicio = `${fechaDate}T08:00`;
                dataActiva.value.fechaHoraFin = addDurationToDate(dataActiva.value.fechaHoraInicio, maestro.duracion || 0);
            } else {
                dataActiva.value.fechaHoraInicio = `${fechaDate}T00:00`;
                const endStr = addDurationToDate(dataActiva.value.fechaHoraInicio, maestro.duracion || 0);
                dataActiva.value.fechaHoraFin = `${endStr.split('T')[0]}T00:00`;
            }

            if (dataActiva.value.fechaHoraInicio && dataActiva.value.fechaHoraFin) {
                dataActiva.value.cantidad = calcularPernoctes(dataActiva.value.fechaHoraInicio, dataActiva.value.fechaHoraFin);
            }

            dataActiva.value.snapshotItems = [];
            dataActiva.value.cottarifas = [];

            await fetchComponenteDetalles(val);

        } else if (maestro && dataActiva.value) {
            // 2. Caso de Respaldo: Si maestro es un ComponentePlaceholder ("Sincronizando...")
            // No podemos acceder a maestro.tipo, pero sí debemos intentar cargar los detalles desde la API.
            await fetchComponenteDetalles(val);
        }
    };

    const onSegmentoDiaChange = (servicioId: string, segmentoId: string, nuevoDiaStr: string | number) => {
        const nuevoDia = parseInt(String(nuevoDiaStr)) || 1;
        if (!cotizacion.value || !cotizacion.value.cotservicios) return;

        const servicio = cotizacion.value.cotservicios.find((s: any) => s.id === servicioId);
        if(!servicio) return;

        const segmento = servicio.cotsegmentos?.find((s: any) => s.id === segmentoId);
        if(!segmento) return;

        segmento.dia = nuevoDia;

        const dateObj = new Date(`${getFechaLimpia(servicio.fechaInicioAbsoluta)}T12:00:00Z`);
        dateObj.setUTCDate(dateObj.getUTCDate() + (nuevoDia - 1));
        const nuevaFechaAbs = dateObj.toISOString().split('T')[0];
        segmento.fechaAbsoluta = nuevaFechaAbs;

        if(servicio.cotcomponentes) {
            servicio.cotcomponentes.forEach((comp: any) => {
                const segId = comp.cotsegmentoId || (comp.cotsegmento ? extractIdStr(comp.cotsegmento.id || comp.cotsegmento['@id'] || comp.cotsegmento) : null);

                if(segId === segmentoId) {
                    const duracionMs = getDuracionMs(comp.fechaHoraInicio, comp.fechaHoraFin);

                    if(comp.fechaHoraInicio) comp.fechaHoraInicio = replaceDateKeepTime(comp.fechaHoraInicio, nuevaFechaAbs);

                    if(comp.fechaHoraInicio) {
                        const nS = new Date(comp.fechaHoraInicio).getTime();
                        const nE = new Date(nS + duracionMs);
                        const off = nE.getTimezoneOffset() * 60000;
                        comp.fechaHoraFin = (new Date(nE.getTime() - off)).toISOString().slice(0, 16);
                    }
                }
            });
            ordenarComponentesCronologicamente(servicio.cotcomponentes);
            sincronizarFechaServicio(servicio);
        }
    };

    const actualizarInicioManteniendoRango = (nuevoInicioStr: string): void => {
        if (!dataActiva.value || !nuevoInicioStr) return;

        const duracionMs = getDuracionMs(dataActiva.value.fechaHoraInicio, dataActiva.value.fechaHoraFin);

        dataActiva.value.fechaHoraInicio = nuevoInicioStr;

        const newStartMs = new Date(nuevoInicioStr).getTime();
        const newEndObj = new Date(newStartMs + duracionMs);
        const offset = newEndObj.getTimezoneOffset() * 60000;
        dataActiva.value.fechaHoraFin = (new Date(newEndObj.getTime() - offset)).toISOString().slice(0, 16);

        onComponenteFechasChange(false);
    };

    const onComponenteFechasChange = (esCambioInicio: boolean = true): void => {
        if (!dataActiva.value) return;

        const maestroTipo = getTipoComponente(dataActiva.value.componenteMaestroId);
        if (!requiereHoraExacta(maestroTipo)) {
            if(dataActiva.value.fechaHoraInicio) dataActiva.value.fechaHoraInicio = dataActiva.value.fechaHoraInicio.split('T')[0] + 'T00:00:00';
            if(dataActiva.value.fechaHoraFin) dataActiva.value.fechaHoraFin = dataActiva.value.fechaHoraFin.split('T')[0] + 'T00:00:00';
        }

        if (dataActiva.value.fechaHoraInicio && dataActiva.value.fechaHoraFin) {
            dataActiva.value.cantidad = calcularPernoctes(dataActiva.value.fechaHoraInicio, dataActiva.value.fechaHoraFin);
        }

        const servicio = findServicioByComponenteId(dataActiva.value.id);

        if (servicio && dataActiva.value.fechaHoraInicio) {
            const nuevaFechaDateStr = dataActiva.value.fechaHoraInicio.split('T')[0];
            const currentSegId = dataActiva.value.cotsegmentoId || (dataActiva.value.cotsegmento ? extractIdStr(dataActiva.value.cotsegmento.id || dataActiva.value.cotsegmento['@id'] || dataActiva.value.cotsegmento) : null);

            if (currentSegId) {
                const segmentoPadre = servicio.cotsegmentos?.find((s: any) => s.id === currentSegId);

                if (segmentoPadre && segmentoPadre.fechaAbsoluta !== nuevaFechaDateStr) {
                    segmentoPadre.fechaAbsoluta = nuevaFechaDateStr;
                    segmentoPadre.dia = calcularDiaRelativo(getFechaLimpia(servicio.fechaInicioAbsoluta), nuevaFechaDateStr);

                    servicio.cotcomponentes?.forEach((comp: any) => {
                        const hermanoSegId = comp.cotsegmentoId || (comp.cotsegmento ? extractIdStr(comp.cotsegmento.id || comp.cotsegmento['@id'] || comp.cotsegmento) : null);

                        if (hermanoSegId === segmentoPadre.id && comp.id !== dataActiva.value.id) {
                            const duracionMs = getDuracionMs(comp.fechaHoraInicio, comp.fechaHoraFin);

                            comp.fechaHoraInicio = replaceDateKeepTime(comp.fechaHoraInicio, nuevaFechaDateStr);

                            const nS = new Date(comp.fechaHoraInicio).getTime();
                            const nE = new Date(nS + duracionMs);
                            const offH = nE.getTimezoneOffset() * 60000;
                            comp.fechaHoraFin = (new Date(nE.getTime() - offH)).toISOString().slice(0, 16);
                        }
                    });
                }
            }
            if (servicio.cotcomponentes) {
                ordenarComponentesCronologicamente(servicio.cotcomponentes);
            }
            sincronizarFechaServicio(servicio);
        }
    };

    const onTarifaMaestraChange = (val: string): void => {
        const targetId = extractIdStr(val);
        const maestro = catalogos.value.tarifas.find((t: any) => extractIdStr(t.id) === targetId || extractIdStr(t['@id']) === targetId);

        if (maestro && dataActiva.value) {
            dataActiva.value.nombreSnapshot = JSON.parse(JSON.stringify(getTituloSafe(maestro)));

            if (typeof maestro.moneda === 'object' && maestro.moneda !== null) dataActiva.value.moneda = maestro.moneda.id || maestro.moneda.codigo || 'USD';
            else dataActiva.value.moneda = maestro.moneda || 'USD';

            dataActiva.value.montoCosto = parseFloat(maestro.monto || '0');
            dataActiva.value.tipoModalidadSnapshot = maestro.modalidad || 'Normal';

            if (maestro.costoPorGrupo) {
                dataActiva.value.cantidad = 1;
                dataActiva.value.esGrupal = true;
            } else {
                dataActiva.value.esGrupal = false;
            }

            if (maestro.proveedor) {
                const provObj = maestro.proveedor;
                const provId = extractIdStr(provObj.id || provObj['@id'] || provObj);
                dataActiva.value.proveedorMaestroId = provId;

                const provCat = catalogos.value.proveedores.find((p: any) => extractIdStr(p.id || p['@id']) === provId);
                if (provCat) {
                    dataActiva.value.proveedorNombreSnapshot = provCat.nombreComercial;
                }
            } else {
                dataActiva.value.proveedorMaestroId = null;
                dataActiva.value.proveedorNombreSnapshot = null;
            }

            dataActiva.value.nombreParaProveedorSnapshot = maestro.nombreParaProveedor || maestro.nombreInterno || null;
            dataActiva.value.estadoOperativoSnapshot = 'Sin Solicitar';

            dataActiva.value.fechaLimitePago = null;
            dataActiva.value.condicionesPagoSnapshot = maestro.condicionesPagoSnapshot || null;
        }
    };

    const onProveedorChange = (val: string | null): void => {
        if (!val || val === 'null') {
            if (dataActiva.value) {
                dataActiva.value.proveedorMaestroId = null;
                dataActiva.value.proveedorNombreSnapshot = null;
            }
            return;
        }

        const targetId = extractIdStr(val);
        const provCat = catalogos.value.proveedores.find((p: any) => extractIdStr(p.id || p['@id']) === targetId);

        if (provCat && dataActiva.value) {
            dataActiva.value.proveedorMaestroId = targetId;
            dataActiva.value.proveedorNombreSnapshot = provCat.nombreComercial;
        }
    };

    return {
        catalogos, cotizacion, fileActual, idiomasDisponibles, isLoading, inspectorActivo, dataActiva,
        isMobileOpen, isSegmentEditorOpen, tipoCambioSugerido, todasLasTarifasMaestras,
        resumenFinanciero, itinerarioDinamico, totalCostoNeto, ventaSugerida,
        getTipoComponente, requiereHoraExacta, calcularPernoctes,
        isComponenteConAlerta, isServicioConAlerta, getI18nText, setI18nText, getTarifaLabel, extractIdStr,
        inicializarEditor, guardarCotizacion, abrirNivel, retrocederNivel, cerrarInspectorMobile,
        updateNumPaxGlobal, agregarServicio, eliminarServicio, agregarComponente, eliminarComponente,
        agregarSnapshotItem, eliminarSnapshotItem, toggleUpsellComponent, isComponenteBloqueado,
        agregarTarifa, eliminarTarifa, fetchComponenteMaestroSilencioso,
        abrirEditorSegmentos, cerrarEditorSegmentos, aplicarPlantilla,
        agregarSegmentoIndividual, procesarInsercionSegmento, removerCotSegmento,
        onServicioMaestroChange, onServicioFechaChange, onComponenteMaestroChange,
        onComponenteFechasChange, onSegmentoDiaChange, onTarifaMaestraChange,
        actualizarInicioManteniendoRango, onProveedorChange
    };
});