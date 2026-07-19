import {defineStore} from 'pinia';
import {computed, ref} from 'vue';
import {apiClient} from '@/services/apiClient';

import {
    Catalogos,
    ClasePasajeroInterna,
    CLASIFICACION_SCHEMA_VERSION,
    ClasificacionFinancieraInterna,
    Componente,
    ComponenteCompleto,
    ComponentePlaceholder,
    Cotizacion,
    CotizacionFileExtended,
    CotSegmento,
    CotServicio,
    DeltaUpgradePorPerfil,
    DetalleOperativoBloque,
    DetalleOperativoTipo,
    expurgarParaCliente,
    formatRangoEdad,
    getProcedenciaUI,
    I18nContent,
    ImagenProveedorSnapshot,
    InclusionLinea,
    InclusionServicio,
    InclusionTarifa,
    Item,
    LineaDetalleClaseInterna,
    ModoFinanciero,
    NivelInspector,
    OpcionUpgradeInterna,
    Segmento,
    SegmentoComponenteProcesado,
    Servicio,
    SnapshotItem,
    Tarifa,
    TarifaBase,
    TarifaCategoriaValue,
    TarifaModalidadValue,
    TarifaProcedenciaValue,
    TarifaRolValue,
    TarifaSnapshot,
    TotalesInternos,
    totalesInternosVacios
} from '@/types/cotizacionEditorModel.ts';

import {ApiIdioma} from '@/types/maestroModel';
import {components} from "@/types/api";

export const isComponenteCompleto = (c: any): c is Componente => {
    return c && 'tipo' in c;
};

export const useCotizacionEditorStore = defineStore('cotizacionEditorStore', () => {

    const isLoading = ref<boolean>(false);
    const idiomasDisponibles = ref<ApiIdioma[]>([]);
    const tipoCambioSugerido = ref<number>(1);

    const catalogos = ref<Catalogos>({
        servicios: [],
        allComponentes: [],
        componentes: [],
        tarifas: [],
        plantillasItinerario: [],
        poolSegmentos: [],
        proveedores: [],
        proveedorServicios: [],
        tiposComponente: []
    });

    const todasLasTarifasMaestras = ref<Tarifa[]>([]);
    const cotizacion = ref<Cotizacion | null>(null);
    const fileActual = ref<CotizacionFileExtended | null>(null);

    // ============================================================================
    // 🔥 LÓGICA DE NEGOCIO: ENUMS (Replicado del Backend)
    // ============================================================================

    const extractIdStr = (val: unknown): string => {
        if (!val) return '';
        if (typeof val === 'object') {
            const obj = val as any;
            const raw = obj['@id'] ?? obj.id ?? obj.tarifaId;
            if (raw) return String(raw).split('/').pop() || '';
        }
        return String(val).split('/').pop() || '';
    };

    const getTipoComponente = (compId: string | null): string => {
        if (!compId) return 'extras';
        const cleanId = extractIdStr(compId);

        const maestro = catalogos.value.allComponentes.find(
            (c) => extractIdStr(c.id || (c as any)['@id'] || '') === cleanId
        );

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

    const formatLocalDateTime = (date: Date): string => {
        const pad = (n: number) => String(n).padStart(2, '0');
        return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}T${pad(date.getHours())}:${pad(date.getMinutes())}:${pad(date.getSeconds())}`;
    };

    const toDateTimeString = (fecha: string, hora: string = '00:00'): string => {
        const horaConSegundos = hora.length === 5 ? `${hora}:00` : hora;
        return `${fecha}T${horaConSegundos}`;
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

    const hydrateRelations = async (items: any[], endpointBase?: string) => {
        if (!items || !Array.isArray(items) || items.length === 0) return [];

        // Rama 1: array de IRIs string
        if (typeof items[0] === 'string') {
            return batchFetchByIds(items as string[], endpointBase);
        }

        // Rama 2: array de objetos parciales (solo @id, sin datos completos)
        if (typeof items[0] === 'object' && items[0]['@id'] && !items[0].nombreInterno && !items[0].titulo && !items[0].nombre) {
            const iris = items.map((obj: any) => obj['@id']).filter((iri: string) => !iri.includes('.well-known/genid'));
            const genids = items.filter((obj: any) => obj['@id']?.includes('.well-known/genid'));

            if (iris.length === 0) return [...genids];

            const batched = await batchFetchByIds(iris, endpointBase);
            return [...batched, ...genids];
        }

        return items;
    };

// Helper compartido por ambas ramas
    const batchFetchByIds = async (iris: string[], endpointBase?: string): Promise<any[]> => {
        const base = endpointBase || iris[0].substring(0, iris[0].lastIndexOf('/'));
        const ids = iris.map(iri => iri.split('/').pop()!);

        try {
            const idsParam = ids.map(id => `id[]=${id}`).join('&');
            const res = await apiClient.get(`${base}?${idsParam}&pagination=false`);
            const items = res.data['hydra:member'] || res.data['member'] || [];

            // Mapeo O(n) para indexación rápida
            const porId = new Map(items.map((item: any) => [extractIdStr(item.id || item['@id']), item]));

            // Retorna respetando estrictamente el orden de entrada de los IRIs originales
            return ids.map(id => porId.get(id)).filter(Boolean);
        } catch (e) {
            // Fallback individual si el batch falla
            const promises = iris.map(iri => apiClient.get(iri).then(r => r.data).catch(() => iri));
            return Promise.all(promises);
        }
    };

    const getTituloSafe = (entity: any) => {
        if (entity && entity.titulo && Array.isArray(entity.titulo) && entity.titulo.length > 0) return entity.titulo;
        return [];
    };

    const mapearItemASnapshot = async (item: Item): Promise<SnapshotItem> => {
        let tituloData: I18nContent[] = [];
        const dicc = item.diccionario;

        if (typeof dicc === 'string') {
            try {
                const res = await apiClient.get(dicc);
                tituloData = res.data.titulo || [];
            } catch (err) {
                console.error('No se pudo cargar el diccionario del item:', dicc, err);
            }
        } else if (dicc && typeof dicc === 'object' && Array.isArray((dicc as { titulo?: I18nContent[] }).titulo)) {
            tituloData = (dicc as { titulo: I18nContent[] }).titulo;
        }

        const modoBackend = item.modo || 'incluido';

        return {
            id: crypto.randomUUID(),
            nombreSnapshot: JSON.parse(JSON.stringify(tituloData)),
            modo: modoBackend,
            modoOriginal: modoBackend,
            // ItemModoEnum ya no tiene 'cortesia': solo 'incluido' marca el check
            incluido: modoBackend === 'incluido',
            tieneUpsell: !!item.componenteAdicionalVinculado,
            componenteAdicionalVinculado: item.componenteAdicionalVinculado || null,
            idComponenteInyectado: null,
            isInjecting: false,
            // Flags snapshoteados desde el ComponenteItem maestro (default false)
            tituloTarifaVisible: item.tituloTarifaVisible ?? false,
            categoriaTarifaVisible: item.categoriaTarifaVisible ?? false,
            modalidadTarifaVisible: item.modalidadTarifaVisible ?? false,
            sobreescribirTraduccion: false
        };
    };

    const mapearImagenesSnapshot = (imagenes: ImagenProveedorSnapshot[] | undefined | null): ImagenProveedorSnapshot[] => {
        if (!imagenes || !Array.isArray(imagenes)) return [];
        return imagenes.map(img => ({
            imageUrl: img.imageUrl ?? null,
            orden: img.orden ?? 0,
            isPortada: img.isPortada ?? false
        }));
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

    const getTarifaLabel = (cat: TarifaLike, lang: string): string => {
        const nombre = cat.nombreInterno || 'Tarifa sin nombre';
        const moneda = getMonedaTarifa(cat);
        const monto = parseFloat(String(getMontoCostoTarifa(cat))).toFixed(2);
        const esGrupal = getEsGrupalTarifa(cat);
        const procedencia = getProcedenciaTarifa(cat);

        const rangoEdad = formatRangoEdad(getEdadMinimaTarifa(cat), getEdadMaximaTarifa(cat));

        const edadStr = rangoEdad ? ` [${rangoEdad}]` : '';

        const indicadorMatematica = esGrupal ? ' 👥' : ' 👤';
        const indicadorProcedencia = procedencia ? ` ${getProcedenciaUI(procedencia).icon}` : '';

        return `${nombre}${edadStr}${indicadorMatematica}${indicadorProcedencia} (${moneda} ${monto})`;
    };

    const addDurationToDate = (baseIsoString: string, durationDecimal: number | string): string => {
        if (!baseIsoString) return '';
        const date = new Date(baseIsoString);
        if (isNaN(date.getTime())) return '';
        const hoursToAdd = typeof durationDecimal === 'string' ? parseFloat(durationDecimal) : durationDecimal;
        date.setMinutes(date.getMinutes() + Math.round(hoursToAdd * 60));
        return formatLocalDateTime(date);
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

    const getI18nText = (arrayI18n: I18nContent[] | undefined, lang: string): string => {
        if (!arrayI18n || !Array.isArray(arrayI18n)) return '';
        const found = arrayI18n.find(item => item.language === lang);
        return found ? found.content : '';
    };

    const setI18nText = (arrayI18n: I18nContent[] | undefined, lang: string, text: string): void => {
        if (!arrayI18n || !Array.isArray(arrayI18n)) return;
        let found = arrayI18n.find((item) => item.language === lang);
        if (found) {
            if (found.content !== text) found.content = text;
        } else {
            arrayI18n.push({ language: lang, content: text });
        }
    };

    const isComponenteConAlerta = (componente: ComponenteCompleto): boolean => {
        if (!cotizacion.value) return false;

        const modo = (componente.modo || '').toLowerCase();
        if (modo === 'reemplazado') return false;

        const tarifas = componente.cottarifas || [];

        if (modo === 'no_incluido' && tarifas.length === 0) return false;
        if (tarifas.length === 0) return true;   // incluido / cortesía sin tarifas

        const numPaxGlobal = cotizacion.value.numPax || 1;

        const resolverGrupal = (t: TarifaSnapshot): boolean => {
            if (t.esGrupal !== undefined) return t.esGrupal;
            const maestro = todasLasTarifasMaestras.value.find(
                (cat) => extractIdStr(cat.tarifaId || (cat as Record<string, any>)['@id']) === extractIdStr(t.tarifaMaestraId)
            );
            return maestro ? getEsGrupalTarifa(maestro) : false;
        };

        const grupos = new Map<number, { pax: number; grupal: boolean }>();
        for (const t of tarifas) {
            if (t.rolSnapshot === 'operativo' || t.grupoTarifa == null) continue;
            const acc = grupos.get(t.grupoTarifa) || { pax: 0, grupal: false };
            if (resolverGrupal(t)) acc.grupal = true;
            else acc.pax += parseInt(String(t.cantidad)) || 0;
            grupos.set(t.grupoTarifa, acc);
        }

        // Solo operativas: no hay cuadre de pax exigible
        if (grupos.size === 0) return false;

        for (const g of grupos.values()) {
            if (!g.grupal && g.pax !== numPaxGlobal) return true;
        }
        return false;
    };

    const isServicioConAlerta = (servicio: CotServicio): boolean => {
        if (!servicio.cotcomponentes || servicio.cotcomponentes.length === 0) return true;
        return servicio.cotcomponentes.some((comp) => isComponenteConAlerta(comp));
    };

    const ordenarComponentesCronologicamente = (componentes: ComponenteCompleto[]): void => {
        if (!componentes || !Array.isArray(componentes)) return;

        componentes.sort((a: ComponenteCompleto, b: ComponenteCompleto) => {
            const valA = a.fechaHoraInicio || '9999-12-31T23:59:59';
            const valB = b.fechaHoraInicio || '9999-12-31T23:59:59';

            const dateA = valA.split('T')[0];
            const dateB = valB.split('T')[0];

            if (dateA !== dateB) {
                return dateA.localeCompare(dateB);
            }

            // Forzamos el fallback a null para satisfacer la firma estricta (string | null) de getTipoComponente
            const reqA = requiereHoraExacta(getTipoComponente(a.componenteMaestroId || null));
            const reqB = requiereHoraExacta(getTipoComponente(b.componenteMaestroId || null));

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

    const normalizarCodigoMoneda = (val: unknown): string => {
        if (!val) return 'USD';
        const s = String(val).trim();
        const upper = s.toUpperCase();
        if (upper === 'PEN' || upper === 'USD') return upper;
        return upper;
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

    /**
     * Clasificador financiero y motor de distribución de costos por perfil de pasajero.
     *
     * ¿Por qué existe?: Consolida toda la estructura operativa de la cotización analizando de forma
     * cruzada restricciones de procedencia/nacionalidad y rangos de edad, resolviendo tarifas e
     * inyectando un rastreador de conflictos en caso de perfiles anómalos o faltantes.
     *
     * Relaciones críticas y efectos secundarios:
     * - Retorna un contrato estricto bajo la interfaz `ClasificacionFinanciera`.
     * - Realiza la conversión matemática a USD dinámicamente si detecta transacciones en PEN.
     * - Cruza datos reactivos de `cotizacion.value.cotservicios`, `todasLasTarifasMaestras.value` y `catalogos.value.allComponentes`.
     *
     * @returns Estructura financiera completa procesada o null si no hay un expediente activo cargado.
     */
    const resumenFinanciero = computed<ClasificacionFinancieraInterna | null>(() => {
        if (!cotizacion.value || !cotizacion.value.cotservicios) return null;

        const idiomaEdicion = cotizacion.value.idiomaEdicion || 'es';
        const numPaxGlobal = Math.max(cotizacion.value.numPax || 1, 1);
        const comisionGlobal = parseFloat(cotizacion.value.comision) || 0;
        const globalMarkup = comisionGlobal / 100;
        const adelantoPct = (parseFloat(cotizacion.value.adelanto) || 0) / 100;
        const tc = parseFloat(cotizacion.value.tipoCambio) || tipoCambioSugerido.value || 1;
        const advertencias: string[] = [];

        // ── Helpers de moneda: la moneda ORIGINAL manda, la otra se deriva 1 vez ──
        interface Bimoneda { soles: number; dolares: number; }
        const aBimoneda = (montoNativo: number, moneda: string): Bimoneda =>
            String(moneda).toUpperCase() === 'PEN'
                ? { soles: montoNativo, dolares: montoNativo / tc }
                : { soles: montoNativo * tc, dolares: montoNativo };

        const markupDeLinea = (t: TarifaSnapshot): number => {
            const ov = t.comisionOverrideSnapshot;
            if (ov !== null && ov !== undefined && ov !== '') return (parseFloat(String(ov)) || 0) / 100;
            return globalMarkup;
        };

        const resolverGrupal = (t: TarifaSnapshot): boolean => {
            if (t.esGrupal !== undefined) return t.esGrupal;
            const maestro = todasLasTarifasMaestras.value.find(
                (cat) => extractIdStr(cat.tarifaId || (cat as Record<string, any>)['@id']) === extractIdStr(t.tarifaMaestraId)
            );
            return maestro ? getEsGrupalTarifa(maestro) : false;
        };

        const nombreDeComponente = (componente: ComponenteCompleto): I18nContent[] => {
            if (componente.nombreSnapshot?.length) return componente.nombreSnapshot;
            // Caso 1 (contenedor sin nombre): fallback al segmento
            const seg = componente.cotsegmento;
            if (seg && typeof seg === 'object' && Array.isArray((seg as CotSegmento).nombreSnapshot)) {
                return (seg as CotSegmento).nombreSnapshot as I18nContent[];
            }
            return [];
        };

        // ── Estructuras internas del voter ──────────────────────────────────────
        interface LineaVoter {
            esGrupal: boolean;
            cantidad: number;                       // cupos (grupal => numPax)
            modo: ModoFinanciero;
            costoPP: Bimoneda;                      // por pax
            ventaPP: Bimoneda;                      // por pax
            tipoPaxId: string;
            tipoPaxNombre: string;
            edadMin: number;
            edadMax: number;
            tipo: string;
            rutaOrigen: string;
            base: Omit<LineaDetalleClaseInterna, 'costoSoles' | 'costoDolares' | 'ventaSoles' | 'ventaDolares'>;
        }

        interface PerfilVoter {
            tipo: string; tipoPaxNombre: string;
            cantidad: number; cantidadRestante: number;
            edadMin: number; edadMax: number; tipoPaxId: string;
            acumCostoD: number; acumVentaD: number;         // clase completa, solo incluido
            isReal: boolean;
            conflictos: string[];
            detalle: LineaDetalleClaseInterna[];
            porModo: { normal: TotalesInternos; ctaPax: TotalesInternos; cortesia: TotalesInternos };
        }

        const nombrePax = (p: string): string =>
            p === 'nacional' ? 'Nacional / Peruano'
                : p === 'extranjero' ? 'Extranjero'
                    : p === 'can' ? 'Comunidad Andina (CAN)'
                        : 'Cualquier Nacionalidad';

        // ── PASO 1: recolección (rama principal) + upgrades + candidato maestro ──
        const componentesProcesados: LineaVoter[][] = [];
        const opcionesUpgrade: OpcionUpgradeInterna[] = [];
        let mejorPuntaje = -1;
        let maestroLineas: LineaVoter[] = [];

        const buckets = {
            incluido: totalesInternosVacios(),
            noIncluido: totalesInternosVacios(),
            cortesia: totalesInternosVacios()
        };

        cotizacion.value.cotservicios.forEach((servicio: CotServicio) => {
            const servicioId = extractIdStr(servicio.id);
            const servicioNombre = servicio.nombrePublicoSnapshot?.length
                ? servicio.nombrePublicoSnapshot : (servicio.nombreSnapshot || []);
            const servicioLabel = getI18nText(servicioNombre, idiomaEdicion) || 'Servicio';

            servicio.cotcomponentes?.forEach((componente: ComponenteCompleto) => {
                const modo = (componente.modo || '').toLowerCase();
                const estado = (componente.estado || '').toLowerCase();
                if (estado === 'cancelado' || modo === 'reemplazado') return;
                if (modo !== 'incluido' && modo !== 'no_incluido' && modo !== 'cortesia') return;

                const modoFin = modo as ModoFinanciero;
                const compNombre = nombreDeComponente(componente);
                const compLabel = getI18nText(compNombre, idiomaEdicion) || 'Insumo Logístico';
                const cCant = componente.cantidad || 1;
                const fecha = getFechaLimpia(componente.fechaHoraInicio);

                const lineas: LineaVoter[] = [];
                let paxEstandar = 0;

                (componente.cottarifas || []).forEach((t: TarifaSnapshot) => {
                    const rol = t.rolSnapshot || 'estandar';
                    if (rol === 'alternativa') return;   // → opcionesUpgrade

                    const esGrupal = resolverGrupal(t);
                    const tCant = parseInt(String(t.cantidad)) || 1;
                    const montoBase = parseFloat(String(t.montoCosto)) || 0;
                    const moneda = String(t.moneda || 'USD').toUpperCase();

                    const costoTotal = aBimoneda(montoBase * tCant * cCant, moneda);
                    const markup = modoFin === 'incluido' ? markupDeLinea(t) : 0;
                    // cortesía: venta 0 (el costo lo absorbe el file); no_incluido: venta = costo
                    const ventaTotal: Bimoneda = modoFin === 'cortesia'
                        ? { soles: 0, dolares: 0 }
                        : { soles: costoTotal.soles * (1 + markup), dolares: costoTotal.dolares * (1 + markup) };

                    const b = modoFin === 'incluido' ? buckets.incluido
                        : modoFin === 'no_incluido' ? buckets.noIncluido
                            : buckets.cortesia;
                    b.costoSoles += costoTotal.soles;   b.costoDolares += costoTotal.dolares;
                    b.ventaSoles += ventaTotal.soles;   b.ventaDolares += ventaTotal.dolares;

                    const cupos = esGrupal ? numPaxGlobal : tCant;
                    if (!esGrupal && modoFin === 'incluido' && rol === 'estandar') paxEstandar += tCant;

                    const procedencia = t.procedenciaSnapshot || '0';
                    const edadMin = t.edadMinimaSnapshot ?? 0;
                    const edadMax = t.edadMaximaSnapshot ?? 120;

                    lineas.push({
                        esGrupal,
                        cantidad: cupos,
                        modo: modoFin,
                        costoPP: { soles: costoTotal.soles / cupos, dolares: costoTotal.dolares / cupos },
                        ventaPP: { soles: ventaTotal.soles / cupos, dolares: ventaTotal.dolares / cupos },
                        tipoPaxId: procedencia,
                        tipoPaxNombre: nombrePax(procedencia),
                        edadMin, edadMax,
                        tipo: `r${edadMin}-${edadMax}t${procedencia}`,
                        rutaOrigen: `${servicioLabel} ➔ ${compLabel} (${getI18nText(t.tituloSnapshot, idiomaEdicion) || t.nombreInternoSnapshot || 'Tarifa'})`,
                        base: {
                            montoCotizado: String(t.montoCosto || '0'),
                            moneda,
                            esGrupal,
                            cantidad: tCant,
                            cantidadComponente: cCant,
                            modo: modoFin,
                            fecha,
                            modalidad: t.modalidadSnapshot || null, // Se extrae pacíficamente, no es estrictamente obligatorio
                            categoria: t.categoriaSnapshot || null, // Se extrae pacíficamente, no es estrictamente obligatorio
                            rol,
                            notaRol: t.notaRol || [],
                            tarifaTitulo: t.tituloSnapshot || [],
                            componenteNombre: compNombre,
                            servicioId,
                            servicioNombre,
                            comisionAplicada: modoFin === 'incluido' ? markup * 100 : 0,
                            comisionOverride: (t.comisionOverrideSnapshot === '' || t.comisionOverrideSnapshot == null)
                                ? null : String(t.comisionOverrideSnapshot),
                            tarifaMaestraId: t.tarifaMaestraId ? extractIdStr(t.tarifaMaestraId) : null,
                            nombreInterno: t.nombreInternoSnapshot || null
                        }
                    });
                });

                if (lineas.length > 0) componentesProcesados.push(lineas);

                // Candidato a partición canónica: incluido, Σ estandar no-grupal == numPax
                if (modoFin === 'incluido' && paxEstandar === numPaxGlobal) {
                    let score = 0;
                    lineas.forEach((l) => {
                        if (l.esGrupal || l.base.rol !== 'estandar') return;
                        if (l.tipoPaxId !== '0') score += 100;
                        score += (120 - (l.edadMax - l.edadMin));
                    });
                    if (score > mejorPuntaje) {
                        mejorPuntaje = score;
                        maestroLineas = lineas.filter((l) => !l.esGrupal && l.base.rol === 'estandar');
                    }
                }

                // ── Upgrades: alternativas por componente (solo incluidos) ──
                if (modoFin === 'incluido') {
                    const alternativas = (componente.cottarifas || []).filter((t) => t.rolSnapshot === 'alternativa');
                    if (alternativas.length === 0) return;

                    const estandares = (componente.cottarifas || []).filter((t) => (t.rolSnapshot || 'estandar') === 'estandar');

                    const ventaPPde = (t: TarifaSnapshot): number => {
                        const esGrupal = resolverGrupal(t);
                        const monto = parseFloat(String(t.montoCosto)) || 0;
                        const nativo = monto * cCant * (1 + (markupDeLinea(t)));
                        const usd = String(t.moneda || 'USD').toUpperCase() === 'PEN' ? nativo / tc : nativo;
                        return esGrupal ? usd / numPaxGlobal : usd;   // no-grupal: el monto YA es por pax
                    };
                    const costoPPde = (t: TarifaSnapshot): number => {
                        const esGrupal = resolverGrupal(t);
                        const monto = parseFloat(String(t.montoCosto)) || 0;
                        const usd = String(t.moneda || 'USD').toUpperCase() === 'PEN' ? (monto * cCant) / tc : monto * cCant;
                        return esGrupal ? usd / numPaxGlobal : usd;
                    };

                    // Base ponderada (cifra única) por si la alternativa no tiene un espejo exacto
                    let sumaVenta = 0, sumaPax = 0;
                    estandares.forEach((t) => {
                        const pax = resolverGrupal(t) ? numPaxGlobal : (parseInt(String(t.cantidad)) || 1);
                        sumaVenta += ventaPPde(t) * pax;
                        sumaPax += pax;
                    });
                    const basePP = sumaPax > 0 ? sumaVenta / sumaPax : 0;

                    const firma = (t: TarifaSnapshot) =>
                        `${t.procedenciaSnapshot || '0'}|${t.edadMinimaSnapshot ?? 0}|${t.edadMaximaSnapshot ?? 120}`;
                    const estandarPorFirma = new Map(estandares.map(t => [firma(t), t]));

                    const grupos = new Map<number, TarifaSnapshot[]>();
                    alternativas.forEach((t) => {
                        const g = t.grupoTarifa ?? 0;
                        if (!grupos.has(g)) grupos.set(g, []);
                        grupos.get(g)!.push(t);
                    });

                    grupos.forEach((tarifasGrupo, grupo) => {
                        // Validación matemática de volumen en lugar de simetría estricta.
                        // Si el grupo de upgrades cubre la totalidad de pasajeros esperados, es válido.
                        let sumaPaxAlt = 0;
                        let esGrupalAlt = false;

                        tarifasGrupo.forEach(t => {
                            if (resolverGrupal(t)) {
                                esGrupalAlt = true;
                                sumaPaxAlt = numPaxGlobal; // Si hay una grupal, asume la cobertura total.
                            } else {
                                sumaPaxAlt += parseInt(String(t.cantidad)) || 1;
                            }
                        });

                        // Si no cuadra matemáticamente con el global o la sumatoria del bloque estándar, advertimos.
                        if (!esGrupalAlt && sumaPaxAlt !== numPaxGlobal && sumaPaxAlt !== sumaPax) {
                            advertencias.push(
                                `El grupo alternativo ${grupo} de "${compLabel}" suma ${sumaPaxAlt} pasajeros, pero debe cuadrar con los ${numPaxGlobal} del expediente (o los ${sumaPax} de la base).`
                            );
                        }

                        tarifasGrupo.forEach((t) => {
                            const std = estandarPorFirma.get(firma(t));
                            const altPP = ventaPPde(t);
                            // Si existe un espejo exacto, comparamos 1 a 1, si no, usamos el promedio ponderado base
                            const stdPP = std ? ventaPPde(std) : basePP;

                            const deltasPorPerfil: DeltaUpgradePorPerfil[] = [{
                                procedencia: t.procedenciaSnapshot || null,
                                edadMin: t.edadMinimaSnapshot ?? 0,
                                edadMax: t.edadMaximaSnapshot ?? 120,
                                // Delta financiero confiable incluso si no hubo match de firma
                                deltaVentaPorPax: altPP - stdPP
                            }];

                            opcionesUpgrade.push({
                                componenteId: extractIdStr(componente.id),
                                grupoTarifa: grupo,
                                componenteNombre: compNombre,
                                servicioId,
                                servicioNombre,
                                tarifaTitulo: t.tituloSnapshot || [],
                                notaRol: t.notaRol || [],
                                modalidad: t.modalidadSnapshot || null,
                                categoria: t.categoriaSnapshot || null,
                                deltaVentaPorPax: altPP - basePP, // Diferencia general vs promedio
                                deltasPorPerfil,
                                deltaVentaTotal: (altPP - basePP) * numPaxGlobal,
                                tarifaMaestraId: t.tarifaMaestraId ? extractIdStr(t.tarifaMaestraId) : null,
                                ventaPorPaxEstandar: stdPP,
                                ventaPorPaxAlternativa: altPP,
                                deltaCostoPorPax: costoPPde(t) - (std ? costoPPde(std) : basePP / (1 + globalMarkup)),
                                comisionAplicada: markupDeLinea(t) * 100,
                                comisionOverride: (t.comisionOverrideSnapshot === '' || t.comisionOverrideSnapshot == null)
                                    ? null : String(t.comisionOverrideSnapshot)
                            });
                        });
                    });
                }
            });
        });

        // ── PASO 2: partición de clases (componente maestro) ─────────────────────
        const clases: PerfilVoter[] = [];
        const nuevaClase = (tipo: string, nombre: string, tipoPaxId: string, edadMin: number, edadMax: number, isReal: boolean): PerfilVoter => ({
            tipo, tipoPaxNombre: nombre, cantidad: 0, cantidadRestante: 0,
            edadMin, edadMax, tipoPaxId,
            acumCostoD: 0, acumVentaD: 0, isReal, conflictos: [], detalle: [],
            porModo: { normal: totalesInternosVacios(), ctaPax: totalesInternosVacios(), cortesia: totalesInternosVacios() }
        });

        if (maestroLineas.length === 0) {
            const c = nuevaClase('r0-120t0', 'Cualquier Nacionalidad', '0', 0, 120, true);
            c.cantidad = numPaxGlobal; c.cantidadRestante = numPaxGlobal;
            clases.push(c);
        } else {
            maestroLineas.forEach((l) => {
                let clase = clases.find(c => c.tipo === l.tipo);
                if (!clase) {
                    clase = nuevaClase(l.tipo, l.tipoPaxNombre, l.tipoPaxId, l.edadMin, l.edadMax, true);
                    clases.push(clase);
                }
                clase.cantidad += l.cantidad;
                clase.cantidadRestante += l.cantidad;
            });
        }

        // ── PASO 3: voter + captura del detalle ──────────────────────────────────
        const registrar = (clase: PerfilVoter, l: LineaVoter, asignados: number) => {
            const bucket = l.modo === 'incluido' ? clase.porModo.normal
                : l.modo === 'no_incluido' ? clase.porModo.ctaPax
                    : clase.porModo.cortesia;
            // Detalle y porModo: POR PAX
            bucket.costoSoles += l.costoPP.soles;   bucket.costoDolares += l.costoPP.dolares;
            bucket.ventaSoles += l.ventaPP.soles;   bucket.ventaDolares += l.ventaPP.dolares;
            bucket.gananciaSoles += l.ventaPP.soles - l.costoPP.soles;
            bucket.gananciaDolares += l.ventaPP.dolares - l.costoPP.dolares;

            clase.detalle.push({
                ...l.base,
                costoSoles: l.costoPP.soles,
                costoDolares: l.costoPP.dolares,
                ventaSoles: l.ventaPP.soles,
                ventaDolares: l.ventaPP.dolares
            });

            if (l.modo === 'incluido') {
                clase.acumCostoD += l.costoPP.dolares * asignados;
                clase.acumVentaD += l.ventaPP.dolares * asignados;
            }
        };

        const asignar = (l: LineaVoter, pendiente: number, prof = 0): void => {
            if (prof > 10 || pendiente <= 0) return;
            let bestIdx = -1, maxScore = 0;
            clases.forEach((c, idx) => {
                if (c.cantidadRestante <= 0) return;
                if (!(l.edadMin <= c.edadMax && l.edadMax >= c.edadMin)) return;
                const exacto = l.tipoPaxId === c.tipoPaxId;
                const comodin = l.tipoPaxId === '0' || c.tipoPaxId === '0';
                const canExt = (l.tipoPaxId === 'can' && c.tipoPaxId === 'extranjero')
                    || (l.tipoPaxId === 'extranjero' && c.tipoPaxId === 'can');
                if (!exacto && !comodin && !canExt) return;
                let s = 0.1;
                if (exacto && c.tipoPaxId !== '0') s += 10;
                if (canExt) s += 3;
                if (l.edadMin === c.edadMin) s += 2;
                if (l.edadMax === c.edadMax) s += 2;
                if (c.cantidadRestante === pendiente) s += 5;
                if (s > maxScore) { maxScore = s; bestIdx = idx; }
            });

            if (bestIdx === -1) {
                let anomalo = clases.find(c => c.tipo === 'anomalo_' + l.tipo);
                if (!anomalo) {
                    anomalo = nuevaClase('anomalo_' + l.tipo, '⚠️ CONFLICTO: ' + l.tipoPaxNombre, l.tipoPaxId, l.edadMin, l.edadMax, false);
                    clases.push(anomalo);
                }
                anomalo.cantidad += pendiente;
                registrar(anomalo, l, pendiente);
                if (!anomalo.conflictos.includes(l.rutaOrigen)) anomalo.conflictos.push(l.rutaOrigen);
                return;
            }

            const ahora = Math.min(clases[bestIdx].cantidadRestante, pendiente);
            clases[bestIdx].cantidadRestante -= ahora;
            registrar(clases[bestIdx], l, ahora);
            if (pendiente > ahora) asignar(l, pendiente - ahora, prof + 1);
        };

        componentesProcesados.forEach((lineas) => {
            lineas.forEach((l) => {
                if (l.esGrupal) {
                    clases.forEach((c) => { if (c.isReal) registrar(c, l, c.cantidad); });
                } else {
                    asignar(l, l.cantidad);
                }
            });
            clases.forEach((c) => c.cantidadRestante = c.cantidad);   // reset por componente
        });

        // Detalle ordenado: fecha → servicio (contrato plano pre-ordenado)
        clases.forEach((c) => c.detalle.sort((a, b) =>
            a.fecha.localeCompare(b.fecha) || a.servicioId.localeCompare(b.servicioId)));

        // ── PASO 4: inclusiones aplanadas ────────────────────────────────────────
        const inclusiones = construirInclusiones(advertencias);

        // ── PASO 5: salida ───────────────────────────────────────────────────────
        const gan = (t: TotalesInternos) => { t.gananciaSoles = t.ventaSoles - t.costoSoles; t.gananciaDolares = t.ventaDolares - t.costoDolares; return t; };
        gan(buckets.incluido);
        buckets.noIncluido.gananciaSoles = 0; buckets.noIncluido.gananciaDolares = 0;
        gan(buckets.cortesia);   // negativa: −costo

        const tieneConflictos = clases.some(c => !c.isReal);
        const ganancia = buckets.incluido.gananciaDolares + buckets.cortesia.gananciaDolares;

        return {
            schemaVersion: CLASIFICACION_SCHEMA_VERSION,
            generatedAt: new Date().toISOString(),
            numPax: numPaxGlobal,
            tipoCambio: tc,
            precioOculto: !!cotizacion.value.precioOculto,
            comisionGlobal,
            totalCostoNeto: buckets.incluido.costoDolares,
            totalVentaBruta: buckets.incluido.ventaDolares,
            ganancia,
            montoAdelanto: buckets.incluido.ventaDolares * adelantoPct,
            resumenGeneral: buckets,
            clasesPasajeros: clases
                .sort((a, b) => b.edadMin - a.edadMin)
                .map((c): ClasePasajeroInterna => ({
                    tipo: c.tipo, tipoPaxNombre: c.tipoPaxNombre, cantidad: c.cantidad,
                    edadMin: c.edadMin, edadMax: c.edadMax,
                    conflictos: c.conflictos,
                    detalle: c.detalle,
                    resumenPorModo: c.porModo,
                    resumen: { montoDolares: c.acumCostoD, ventaDolares: c.acumVentaD, gananciaDolares: c.acumVentaD - c.acumCostoD }
                })),
            opcionesUpgrade,
            inclusiones,
            advertencias,
            publicable: !tieneConflictos && advertencias.length === 0
        };
    });


    // ────────────────────────────────────────────────────────────────────────────
    // Builder de inclusiones (agregar al store; lo consume el computed de arriba)
    // Recorre servicios→componentes directamente (no el voter): cubre componentes
    // sin tarifas y aplana los items con herencia condicional por flags.
    // ────────────────────────────────────────────────────────────────────────────
    const construirInclusiones = (advertencias: string[]): InclusionServicio[] => {
        if (!cotizacion.value?.cotservicios) return [];
        const idiomaEdicion = cotizacion.value.idiomaEdicion || 'es';
        const resultado: InclusionServicio[] = [];

        const serviciosOrden = [...cotizacion.value.cotservicios]
            .sort((a, b) => getFechaLimpia(a.fechaInicioAbsoluta).localeCompare(getFechaLimpia(b.fechaInicioAbsoluta)));

        serviciosOrden.forEach((servicio) => {
            const bloque: InclusionServicio = {
                servicioId: extractIdStr(servicio.id),
                servicioNombre: servicio.nombrePublicoSnapshot?.length
                    ? servicio.nombrePublicoSnapshot : (servicio.nombreSnapshot || []),
                incluidos: [], noIncluidos: [], cortesias: [], opcionales: []
            };

            const destino = (modo: string): InclusionLinea[] =>
                modo === 'no_incluido' ? bloque.noIncluidos
                    : modo === 'cortesia' ? bloque.cortesias
                        : modo === 'opcional' ? bloque.opcionales
                            : bloque.incluidos;

            servicio.cotcomponentes?.forEach((componente: ComponenteCompleto) => {
                const modo = (componente.modo || '').toLowerCase();
                const estado = (componente.estado || '').toLowerCase();
                if (estado === 'cancelado' || modo === 'reemplazado') return;
                if (modo !== 'incluido' && modo !== 'no_incluido' && modo !== 'cortesia') return;

                const fecha = getFechaLimpia(componente.fechaHoraInicio);
                const cCant = componente.cantidad || 1;
                const tieneNombre = !!componente.nombreSnapshot?.length;
                const items = componente.snapshotItems || [];

                // Tarifa estándar visible (fuente de herencia para items y línea propia)
                const estandares = (componente.cottarifas || []).filter(
                    (t: TarifaSnapshot) => (t.rolSnapshot || 'estandar') === 'estandar'
                );
                const tarifaRef = estandares[0] || null;

                // Línea del COMPONENTE (casos 2 y 3): solo si tiene nombre propio
                if (tieneNombre) {
                    destino(modo).push({
                        origen: 'componente',
                        modo: modo as ModoFinanciero,
                        nombre: componente.nombreSnapshot,
                        fecha,
                        cantidadComponente: cCant,
                        modalidad: tarifaRef?.modalidadSnapshot || null,
                        categoria: tarifaRef?.categoriaSnapshot || null,
                        tarifaTitulo: [],
                        tarifas: estandares.map((t: TarifaSnapshot): InclusionTarifa => ({
                            tarifaTitulo: t.tituloSnapshot || [],
                            cantidad: parseInt(String(t.cantidad)) || 1,
                            esGrupal: !!t.esGrupal,
                            modalidad: t.modalidadSnapshot || null,
                            categoria: t.categoriaSnapshot || null,
                            rol: (t.rolSnapshot || 'estandar') as TarifaRolValue,
                            notaRol: t.notaRol || [],
                            montoCotizado: String(t.montoCosto || '0'),   // interna con monto; expurgador limpia
                            moneda: String(t.moneda || 'USD')
                        }))
                    });
                }

                // Líneas de ITEMS aplanadas (casos 1 y 3): cada item con su propio modo
                items.forEach((item: SnapshotItem) => {
                    const modoItem = (item.modo || 'incluido').toLowerCase();
                    destino(modoItem).push({
                        origen: 'item',
                        modo: modoItem as InclusionLinea['modo'],
                        nombre: item.nombreSnapshot,
                        fecha,
                        cantidadComponente: 1,
                        // Herencia condicional por flags desde la tarifa estándar del contenedor
                        modalidad: item.modalidadTarifaVisible ? (tarifaRef?.modalidadSnapshot || null) : null,
                        categoria: item.categoriaTarifaVisible ? (tarifaRef?.categoriaSnapshot || null) : null,
                        tarifaTitulo: item.tituloTarifaVisible ? (tarifaRef?.tituloSnapshot || []) : [],
                        tarifas: []   // items: sin dimensión monetaria, nunca "0"
                    });
                });
            });

            if (bloque.incluidos.length || bloque.noIncluidos.length || bloque.cortesias.length || bloque.opcionales.length) {
                resultado.push(bloque);
            }
        });

        return resultado;
    };


    const totalCostoNeto = computed(() => resumenFinanciero.value?.totalCostoNeto || 0);
    const ventaSugerida = computed(() => resumenFinanciero.value?.totalVentaBruta || 0);

    const itinerarioDinamico = computed(() => {
        if (!cotizacion.value || !cotizacion.value.cotservicios) return [];

        const todosLosServicios = [...cotizacion.value.cotservicios];

        todosLosServicios.sort((a: CotServicio, b: CotServicio) => {
            const dateA = getFechaLimpia(a.fechaInicioAbsoluta) || '9999-12-31';
            const dateB = getFechaLimpia(b.fechaInicioAbsoluta) || '9999-12-31';
            return dateA.localeCompare(dateB);
        });

        const grupos: Record<string, any[]> = {};
        todosLosServicios.forEach((srv: CotServicio) => {
            const fecha = getFechaLimpia(srv.fechaInicioAbsoluta);

            if (srv.cotcomponentes && Array.isArray(srv.cotcomponentes)) {
                ordenarComponentesCronologicamente(srv.cotcomponentes);
            }

            if (!grupos[fecha]) grupos[fecha] = [];
            grupos[fecha].push(srv);
        });

        // Obtiene la hora más temprana entre los componentes que requieren hora exacta.
        // Si el servicio no tiene ningún componente con hora exacta, retorna null (va al final).
        const getHoraClaveServicio = (srv: CotServicio): string | null => {
            if (!srv.cotcomponentes || srv.cotcomponentes.length === 0) return null;

            let horaMinima: string | null = null;
            srv.cotcomponentes.forEach((c: ComponenteCompleto) => {
                const tipo = getTipoComponente(c.componenteMaestroId || null);
                if (requiereHoraExacta(tipo) && c.fechaHoraInicio) {
                    if (horaMinima === null || c.fechaHoraInicio < horaMinima) {
                        horaMinima = c.fechaHoraInicio;
                    }
                }
            });

            return horaMinima;
        };

        Object.keys(grupos).forEach((fecha) => {
            grupos[fecha].sort((a: CotServicio, b: CotServicio) => {
                const horaA = getHoraClaveServicio(a);
                const horaB = getHoraClaveServicio(b);

                // Ninguno tiene hora exacta -> empate, conserva orden original (estable)
                if (horaA === null && horaB === null) return 0;
                // Solo A no tiene hora exacta -> A va al final
                if (horaA === null) return 1;
                // Solo B no tiene hora exacta -> B va al final
                if (horaB === null) return -1;
                // Ambos tienen hora exacta -> ordena por la más temprana
                return horaA.localeCompare(horaB);
            });
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
                const maxVersion: number = fileActual.value?.cotizaciones?.reduce((max: number, c) => Math.max(max, c.version), 0) || 0;
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
            // Solo cargamos enums o datos estrictamente necesarios al inicio
            const [resTipos] = await Promise.all([
                apiClient.get('/cotizacion/user/maestros-enum/componente-tipos')
            ]);

            catalogos.value.tiposComponente = resTipos.data || [];

            // Inicializamos los arrays vacíos, se llenarán bajo demanda
            catalogos.value.servicios = [];
            catalogos.value.proveedores = [];
            catalogos.value.allComponentes = [];
            catalogos.value.componentes = [];
        } catch (e) {
            console.error("Error cargando catálogos o enums", e);
        }
    };

    const buscarServiciosAsincrono = async (query: string) => {
        if (!query || query.trim().length < 3) return; // Disparar búsqueda a partir de 3 letras

        try {
            const res = await apiClient.get(`/platform/travel/servicios?nombreInterno=${encodeURIComponent(query)}`);
            const items = res.data['hydra:member'] || res.data['member'] || [];

            items.forEach((item: any) => {
                const id = extractIdStr(item.id || item['@id']);
                // Validar que no exista ya en memoria
                if (!catalogos.value.servicios.some(s => extractIdStr(s.id || (s as any)['@id']) === id)) {
                    catalogos.value.servicios.push(item);
                }
            });
        } catch (e) {
            console.error("Error buscando servicios en catálogo", e);
        }
    };

    const buscarProveedoresAsincrono = async (query: string) => {
        if (!query || query.trim().length < 3) return;

        try {
            // Asumiendo que quieres buscar por nombre comercial
            const res = await apiClient.get(`/platform/travel/proveedores?nombreComercial=${encodeURIComponent(query)}`);
            const items = res.data['hydra:member'] || res.data['member'] || [];

            items.forEach((item: any) => {
                const id = extractIdStr(item.id || item['@id']);
                if (!catalogos.value.proveedores.some(p => extractIdStr(p.id || (p as any)['@id']) === id)) {
                    catalogos.value.proveedores.push(item);
                }
            });
        } catch (e) {
            console.error("Error buscando proveedores en catálogo", e);
        }
    };
    const fetchComponenteMaestroSilencioso = async (id: string) => {
        const cleanId = extractIdStr(id);
        if (!cleanId) return;

        const existsIdx = catalogos.value.allComponentes.findIndex(c => extractIdStr(c.id) === cleanId);

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
                const componenteCompleto = res.data as Componente;

                if (!componenteCompleto.tarifas) componenteCompleto.tarifas = [];
                if (!componenteCompleto.snapshotItems) componenteCompleto.snapshotItems = [];

                catalogos.value.allComponentes.splice(idx, 1, componenteCompleto);
            }
        } catch (e) {
            console.error("Error hidratando componente:", e);
        }
    };

    const fetchServicioDetalles = async (servicioIriOrId: string, gen?: number) => {
        try {
            const id = extractIdStr(servicioIriOrId);
            const response = await apiClient.get(`/platform/travel/servicios/${id}`);
            if (gen !== undefined && gen !== navGen) return;
            const data = response.data as Servicio;

            if (data.componentes && data.componentes.length > 0) {
                if (gen !== undefined && gen !== navGen) return;
                const hydratedComps = await hydrateRelations(data.componentes);
                catalogos.value.componentes = hydratedComps;

                const idsParaDetalle: string[] = [];
                hydratedComps.forEach((c: any) => {
                    const targetId = extractIdStr(c.id || c['@id']);
                    if (!catalogos.value.allComponentes.some(exist => extractIdStr(exist.id) === targetId)) {
                        catalogos.value.allComponentes.push(c);
                    }
                    idsParaDetalle.push(targetId);
                });

                // 🔥 Precarga en batch el detalle completo (con tarifas) de TODOS los componentes del servicio
                if (idsParaDetalle.length > 0) {
                    const idsParam = idsParaDetalle.map(cid => `id[]=${cid}`).join('&');
                    try {
                        const resDetalle = await apiClient.get(`/platform/travel/componentes/batch?${idsParam}&pagination=false`);
                        if (gen !== undefined && gen !== navGen) return;
                        const detalles = resDetalle.data['hydra:member'] || resDetalle.data['member'] || [];

                        detalles.forEach((detalle: any) => {
                            const detalleId = extractIdStr(detalle.id || detalle['@id']);
                            const idx = catalogos.value.allComponentes.findIndex(c => extractIdStr(c.id || c['@id']) === detalleId);
                            if (idx !== -1) {
                                // Reemplazamos el objeto liviano por el completo (con tarifas, componenteItems)
                                catalogos.value.allComponentes.splice(idx, 1, detalle);
                            }

                            // Precargamos también las tarifas maestras en el pool global
                            (detalle.tarifas || []).forEach((t: any) => {
                                const tId = extractIdStr(t.id || t['@id']);
                                if (!todasLasTarifasMaestras.value.some((pt: any) => extractIdStr(pt.id || pt['@id']) === tId)) {
                                    todasLasTarifasMaestras.value.push(t);
                                }
                            });
                        });
                    } catch (e) {
                        console.error('No se pudo precargar el detalle de componentes en batch', e);
                    }
                }
            } else {
                catalogos.value.componentes = [];
            }

            const [plantillas, pool] = await Promise.all([
               hydrateRelations(data.itinerarios || []),
               hydrateRelations(data.segmentos || [])
            ]);
           if (gen !== undefined && gen !== navGen) return;
           catalogos.value.plantillasItinerario = plantillas;
           catalogos.value.poolSegmentos = pool;
        } catch (e) {}
    };

    const fetchComponenteDetalles = async (componenteIriOrId: string, gen?: number) => {
        const id = extractIdStr(componenteIriOrId);
        const existing = catalogos.value.allComponentes.find(c => extractIdStr(c.id || (c as any)['@id']) === id);

        // 🔥 Si el componente ya tiene tarifas cargadas (señal de que ya se completó antes),
        // no volvemos a pedir nada al servidor.
        const yaCompleto = existing && 'tarifas' in existing && Array.isArray((existing as any).tarifas);

        if (yaCompleto) {
            const detalle = existing as any;
            catalogos.value.tarifas = detalle.tarifas || [];

            detalle.tarifas?.forEach((t: any) => {
                const tId = extractIdStr(t.id || t['@id']);
                if (!todasLasTarifasMaestras.value.some((pt: any) => extractIdStr(pt.id || pt['@id']) === tId)) {
                    todasLasTarifasMaestras.value.push(t);
                }
            });

            if (dataActiva.value && inspectorActivo.value === 'componente') {
                const itemsRaw: Item[] = detalle.componenteItems ?? [];   // ó fetchedComp.componenteItems
                if (!dataActiva.value.snapshotItems || dataActiva.value.snapshotItems.length === 0) {
                    if (gen !== undefined && gen !== navGen) return;
                    dataActiva.value.snapshotItems = await Promise.all(itemsRaw.map(mapearItemASnapshot));
                }
            }
            return; // 🔥 nunca llega al fetch
        }

        try {
            const response = await apiClient.get(`/platform/travel/componentes/${id}`);
            if (gen !== undefined && gen !== navGen) return;
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
            if (gen !== undefined && gen !== navGen) return;

            catalogos.value.tarifas.forEach((t: any) => {
                const tId = extractIdStr(t.id || t['@id']);
                if (!todasLasTarifasMaestras.value.some((pt: any) => extractIdStr(pt.id || pt['@id']) === tId)) {
                    todasLasTarifasMaestras.value.push(t);
                }
            });

            if (dataActiva.value && inspectorActivo.value === 'componente') {
                const itemsRaw = fetchedComp.componenteItems || [];
                if (!dataActiva.value.snapshotItems || dataActiva.value.snapshotItems.length === 0) {
                   dataActiva.value.snapshotItems = await Promise.all(itemsRaw.map(mapearItemASnapshot));
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
            // 🔥 NUEVOS SETS PARA BATCHING
            const proveedoresToFetch = new Set<string>();
            const serviciosToFetch = new Set<string>();

            data.cotservicios.forEach((s: CotServicio) => {
                s.fechaInicioAbsoluta = getFechaLimpia(s.fechaInicioAbsoluta);
                if (!s.nombrePublicoSnapshot) s.nombrePublicoSnapshot = JSON.parse(JSON.stringify(s.nombreSnapshot || []));

                // Recolectar Servicio Maestro
                if (s.servicioMaestroId) {
                    serviciosToFetch.add(extractIdStr(s.servicioMaestroId));
                }

                // ... (tu código existente de segmentos) ...

                if (s.cotcomponentes && Array.isArray(s.cotcomponentes)) {
                    s.cotcomponentes?.forEach((c: ComponenteCompleto) => {
                        // ... (tu código existente de componentes) ...

                        if (c.cottarifas && Array.isArray(c.cottarifas)) {
                            c.cottarifas.forEach((t: TarifaSnapshot) => {
                                t.moneda = normalizarCodigoMoneda(t.moneda);
                                const tId = extractIdStr(t.tarifaMaestraId);
                                if (tId && tId.length === 36) {
                                    tarifasToFetch.add(tId);
                                }
                                // 🔥 Recolectar Proveedor Maestro
                                const pId = extractIdStr(t.proveedorMaestroId);
                                if (pId && pId.length === 36) {
                                    proveedoresToFetch.add(pId);
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

            // Promesas existentes para Componentes y Tarifas...
            // ... (mantén tu código actual de fetchPromises.push para componentes y tarifas) ...

            // 🔥 NUEVA PROMESA BATCH: SERVICIOS
            if (serviciosToFetch.size > 0) {
                const idsParam = Array.from(serviciosToFetch).map(id => `id[]=${id}`).join('&');
                fetchPromises.push(
                    apiClient.get(`/platform/travel/servicios?${idsParam}&pagination=false`).then(res => {
                        catalogos.value.servicios = res.data['hydra:member'] || res.data['member'] || [];
                    }).catch(() => null)
                );
            }

            // 🔥 NUEVA PROMESA BATCH: PROVEEDORES
            if (proveedoresToFetch.size > 0) {
                const idsParam = Array.from(proveedoresToFetch).map(id => `id[]=${id}`).join('&');
                fetchPromises.push(
                    apiClient.get(`/platform/travel/proveedores?${idsParam}&pagination=false`).then(res => {
                        catalogos.value.proveedores = res.data['hydra:member'] || res.data['member'] || [];
                    }).catch(() => null)
                );
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
            estado: 'pendiente',
            monedaGlobal: 'USD',
            idiomaCliente: idiomaDefault,
            idiomaEdicion: 'es',
            numPax: 1,
            comision: '20.00',
            adelanto: '0.00',
            tipoCambio: String(tipoCambioSugerido.value || 1),
            totalCosto: '0.00',
            totalVenta: '0.00',
            precioOculto: false,
            proveedorOculto: false,
            resumen: [],
            sobreescribirTraduccion: false,
            cotservicios: []
        } as Cotizacion;
    };


    // Helper: elimina campos hipermedia (@id/@context/@type) en todo el árbol.
    // Colócalo fuera de guardarCotizacion (a nivel de módulo o composable).
    const stripHypermedia = (obj: any): any => {
        if (Array.isArray(obj)) return obj.map(stripHypermedia);
        if (obj && typeof obj === 'object') {
            delete obj['@id'];
            delete obj['@context'];
            delete obj['@type'];
            for (const k of Object.keys(obj)) obj[k] = stripHypermedia(obj[k]);
        }
        return obj;
    };

    /**
     * Sincroniza el estado local de la cotización con el backend (API Platform).
     *
     * ¿Por qué existe?: Se encarga de transformar y persistir todo el árbol relacional de la
     * cotización (servicios, segmentos, componentes y tarifas) hacia la base de datos, validando
     * reglas de negocio (como conflictos financieros) antes del envío.
     *
     * Relaciones críticas y efectos secundarios:
     * - Al recibir el payload de respuesta (`savedData`), realiza un cruce con el estado reactivo actual
     *   para rescatar propiedades relacionales (como `cotsegmentoId`) que la API suele omitir en
     *   el proceso de serialización, evitando que la UI desbloquee componentes accidentalmente.
     * - Muta el `inspectorActivo` y reconecta el foco de edición (`dataActiva`) al nuevo
     *   nodo referenciado si el usuario estaba editando un sub-ítem durante el guardado.
     *
     * @returns Promesa vacía que se resuelve al finalizar el proceso de guardado y reconexión de UI.
     */
    const guardarCotizacion = async (): Promise<void> => {
        if (!cotizacion.value) return;
        if (isLoading.value) return;
        isLoading.value = true;
        try {
            const isUpdate = !!cotizacion.value.createdAt;
            const endpoint = isUpdate
                ? `/platform/sales/cotizacions/${cotizacion.value.id}`
                : `/platform/sales/cotizacions`;

            // 🔥 Clonado + limpieza de hipermedia (evita que @id resuelva a
            // referencias de Doctrine sin constructor → colección sin inicializar).
            const payload = stripHypermedia(JSON.parse(JSON.stringify(cotizacion.value)));

            // Campos derivados / gestionados por el backend: no deben viajar.
            delete payload.ganancia;
            delete payload.createdAt;
            delete payload.updatedAt;

            // Formateo del archivo adjunto
            if (payload.file && typeof payload.file === 'object') {
                payload.file = payload.file['@id'] || payload.file.id;
            } else if (payload.file && !payload.file.includes('/platform/')) {
                payload.file = `/platform/sales/cotizacion_files/${payload.file}`;
            }

            // Parseo seguro de métricas base
            payload.comision = String(payload.comision || '0');
            payload.adelanto = String(payload.adelanto || '0');
            payload.totalCosto = String(resumenFinanciero.value?.totalCostoNeto || '0');
            payload.totalVenta = String(resumenFinanciero.value?.totalVentaBruta || '0');
            payload.numPax = parseInt(payload.numPax) || 1;
            payload.tipoCambio = String(payload.tipoCambio || tipoCambioSugerido.value || 1);

            const fin = resumenFinanciero.value;

            // 🔥 VALIDACIÓN ESTRICTA DE ESTADOS PROTEGIDOS
            // Evita guardar si la cotización está en un estado avanzado y tiene conflictos financieros.
            const estadosProtegidos = ['enviado', 'confirmado', 'operado'];

            if (estadosProtegidos.includes(payload.estado) && fin && !fin.publicable) {
                const estadoLabel = payload.estado.charAt(0).toUpperCase() + payload.estado.slice(1);
                alert(
                    `No se puede guardar la cotización en estado "${estadoLabel}" debido a los siguientes conflictos financieros:\n\n` +
                    (fin.advertencias.length
                        ? fin.advertencias.map(a => `• ${a}`).join('\n')
                        : '• Hay perfiles de pasajero en conflicto (revisa el panel de resumen para asignar las tarifas correctamente).')
                );
                return;
            }

            // Inyección de la estructura financiera al payload
            payload.totalCosto = String(fin?.totalCostoNeto ?? '0');
            payload.totalVenta = String(fin?.totalVentaBruta ?? '0');
            payload.clasificacionFinanciera = fin ?? null;
            payload.clasificacionFinancieraCliente = fin ? expurgarParaCliente(fin) : null;

            delete payload.idiomaEdicion;

            // Limpieza y formateo del árbol relacional
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
                        servicio.cotcomponentes.forEach((componente: ComponenteCompleto) => {
                            componente.cantidad = parseInt(String(componente.cantidad)) || 1;

                            if (componente.componenteMaestroId) {
                                componente.componenteMaestroId = extractIdStr(componente.componenteMaestroId);
                            }

                            const maestroTipo = getTipoComponente(componente.componenteMaestroId || null);
                            if (!requiereHoraExacta(maestroTipo)) {
                                if (componente.fechaHoraInicio) {
                                    componente.fechaHoraInicio = componente.fechaHoraInicio.split('T')[0] + 'T00:00:00';
                                }
                                if (componente.fechaHoraFin) {
                                    componente.fechaHoraFin = componente.fechaHoraFin.split('T')[0] + 'T00:00:00';
                                }
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
                                componente.cottarifas.forEach((tarifa: TarifaSnapshot) => {
                                    tarifa.cantidad = tarifa.cantidad || 1;
                                    tarifa.montoCosto = String(tarifa.montoCosto || '0');
                                    if (tarifa.tarifaMaestraId) {
                                        tarifa.tarifaMaestraId = extractIdStr(tarifa.tarifaMaestraId);
                                    }
                                    if (tarifa.fechaLimitePago === '') {
                                        tarifa.fechaLimitePago = null;
                                    }
                                    tarifa.comisionOverrideSnapshot = (tarifa.comisionOverrideSnapshot === '' || tarifa.comisionOverrideSnapshot == null)
                                        ? null
                                        : String(tarifa.comisionOverrideSnapshot);
                                });
                            }
                        });
                    }
                });
            }

            const response = await (isUpdate ? apiClient.put : apiClient.post)(endpoint, payload);
            let savedData = response.data;


            if (savedData.cotservicios && !Array.isArray(savedData.cotservicios)) {
                savedData.cotservicios = Object.values(savedData.cotservicios);
            } else if (!savedData.cotservicios) {
                savedData.cotservicios = [];
            }

            savedData.idiomaEdicion = 'es';

            // Rehidratación local post-guardado
            savedData.cotservicios.forEach((s: CotServicio) => {
                s.sobreescribirTraduccion = false;
                s.fechaInicioAbsoluta = getFechaLimpia(s.fechaInicioAbsoluta);

                if (s.cotsegmentos && !Array.isArray(s.cotsegmentos)) {
                    s.cotsegmentos = Object.values(s.cotsegmentos);
                }

                s.cotsegmentos?.forEach((seg: CotSegmento) => {
                    seg.sobreescribirTraduccion = false;
                    seg.fechaAbsoluta = getFechaLimpia(seg.fechaAbsoluta);
                });

                if (s.cotcomponentes && !Array.isArray(s.cotcomponentes)) {
                    s.cotcomponentes = Object.values(s.cotcomponentes);
                }

                s.cotcomponentes?.forEach((c: ComponenteCompleto) => {
                    c.sobreescribirTraduccion = false;

                    if (c.snapshotItems && !Array.isArray(c.snapshotItems)) {
                        c.snapshotItems = Object.values(c.snapshotItems);
                    }

                    c.snapshotItems?.forEach((i: SnapshotItem) => {
                        i.sobreescribirTraduccion = false;
                    });

                    c.cottarifas?.forEach((t: TarifaSnapshot) => {
                        t.sobreescribirTraduccion = false;
                        if (t.fechaLimitePago) {
                            t.fechaLimitePago = getFechaLimpia(t.fechaLimitePago);
                        }
                    });

                    // 🔥 MECANISMO DE RESCATE LOCAL
                    // Extraemos el identificador base del segmento en el payload recibido
                    const parsedSegId = c.cotsegmento
                        ? (typeof c.cotsegmento === 'string'
                            ? extractIdStr(c.cotsegmento)
                            : extractIdStr(c.cotsegmento?.id || c.cotsegmento?.['@id'] || null))
                        : null;

                    if (parsedSegId) {
                        c.cotsegmentoId = parsedSegId;
                    } else {
                        // Si la API lo omitió, buscamos su equivalente en el estado histórico (cotizacion.value)
                        const currentServicio = cotizacion.value?.cotservicios?.find((currS: CotServicio) => currS.id === s.id);
                        const currentComp = currentServicio?.cotcomponentes?.find((currC: ComponenteCompleto) => currC.id === c.id);

                        if (currentComp && currentComp.cotsegmentoId) {
                            c.cotsegmentoId = currentComp.cotsegmentoId;
                            // Restauramos el IRI para la futura validación y persistencia
                            c.cotsegmento = `/platform/sales/cotizacion_segmentos/${currentComp.cotsegmentoId}`;
                        }
                    }
                });

                ordenarComponentesCronologicamente(s.cotcomponentes || []);
            });

            // Asignación final con el árbol relacional completo y blindado
            cotizacion.value = savedData;

            // Restauración del foco de inspección si es necesario
            if (inspectorActivo.value !== 'resumen' && dataActiva.value) {
                const oldId = dataActiva.value.id;
                let relinked: CotServicio | ComponenteCompleto | TarifaSnapshot | undefined = undefined;

                if (inspectorActivo.value === 'servicio') {
                    relinked = savedData.cotservicios.find((s: CotServicio) => s.id === oldId);

                } else if (inspectorActivo.value === 'componente') {
                    savedData.cotservicios.forEach((s: CotServicio) => {
                        const found = s.cotcomponentes?.find((c: ComponenteCompleto) => c.id === oldId);
                        if (found) relinked = found;
                    });

                } else if (inspectorActivo.value === 'tarifa') {
                    savedData.cotservicios.forEach((s: CotServicio) => {
                        s.cotcomponentes?.forEach((c: ComponenteCompleto) => {
                            const found = c.cottarifas?.find((t: TarifaSnapshot) => t.id === oldId);
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

    let navGen = 0;

    const abrirNivel = async (nivel: NivelInspector, data: any = null): Promise<void> => {
        const gen = ++navGen;

        if (nivel === 'servicio' || nivel === 'resumen') historialNavegacion.value = [];
        else historialNavegacion.value.push({ nivel: inspectorActivo.value, data: dataActiva.value });

        // inspectorActivo y dataActiva cambian juntos, siempre — nunca debe haber
        // un render con inspectorActivo apuntando a una vista cuyo dataActiva
        // todavía no corresponde (eso generaba el "Cannot read properties of
        // null (reading 'id')" en los findIndex del header).
        inspectorActivo.value = nivel;
        isMobileOpen.value = true;
        dataActiva.value = data;

        // La hidratación del catálogo (tarifas, snapshotItems, etc.) sigue en
        // segundo plano; si para cuando termina el usuario ya navegó a otro
        // lado (gen distinto), esas funciones ya se frenan solas.
        if (nivel === 'servicio' && data?.servicioMaestroId) {
            await fetchServicioDetalles(data.servicioMaestroId, gen);
        }
        if (nivel === 'componente' && data?.componenteMaestroId) {
            await fetchComponenteDetalles(data.componenteMaestroId, gen);
        }
        if (nivel === 'tarifa' && data?.proveedorMaestroId) {
            await fetchProveedorServiciosDeProveedor(data.proveedorMaestroId);
        }
    };

    const limpiarServicioProveedor = () => {
        if (dataActiva.value) {
            dataActiva.value.proveedorServicioMaestroId = null;
            dataActiva.value.proveedorServicioNombreSnapshot = null;
            dataActiva.value.proveedorServicioTituloSnapshot = [];
            dataActiva.value.proveedorServicioUrlSnapshot = null;
        }
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
            nombrePublicoSnapshot: [{ language: 'es', content: 'Nuevo Servicio' }],
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

        let afectaAlActivo = false;
        if (dataActiva.value) {
            if (inspectorActivo.value === 'servicio' && dataActiva.value.id === servicioId) {
                afectaAlActivo = true;
            } else if (inspectorActivo.value === 'componente' || inspectorActivo.value === 'tarifa') {
                const servicioPadre = cotizacion.value.cotservicios.find((s: CotServicio) => s.id === servicioId);
                const perteneceAlServicio = servicioPadre?.cotcomponentes?.some((c: ComponenteCompleto) => {
                    if (c.id === dataActiva.value.id) return true;
                    return c.cottarifas?.some((t: TarifaSnapshot) => t.id === dataActiva.value.id);
                });
                if (perteneceAlServicio) afectaAlActivo = true;
            }
        }

        cotizacion.value.cotservicios = cotizacion.value.cotservicios.filter(
            (s: CotServicio) => s.id !== servicioId
        );

        if (afectaAlActivo) {
            inspectorActivo.value = 'resumen';
            dataActiva.value = null;
            historialNavegacion.value = [];
            isMobileOpen.value = false;
        }
    };

    const serviciosOrdenados = computed<CotServicio[]>(() => {
        return itinerarioDinamico.value.flatMap(dia => dia.cotservicios);
    });

    const irAServicioAdyacente = async (direccion: 1 | -1): Promise<void> => {
        const lista = serviciosOrdenados.value;
        if (!lista.length || !dataActiva.value) return;
        const idx = lista.findIndex(s => s.id === dataActiva.value.id);
        if (idx === -1) return;
        const nuevoIdx = idx + direccion;
        if (nuevoIdx < 0 || nuevoIdx >= lista.length) return;

        const gen = ++navGen;

        const destino = lista[nuevoIdx];
        dataActiva.value = destino;
        if (destino.servicioMaestroId) {
            await fetchServicioDetalles(destino.servicioMaestroId, gen);
        }
    };


    const servicioActualDeComponente = computed<CotServicio | null>(() => {
        if (inspectorActivo.value !== 'componente' || !dataActiva.value) return null;
        return findServicioByComponenteId(dataActiva.value.id);
    });

    const componentesHermanos = computed<ComponenteCompleto[]>(() => {
        return servicioActualDeComponente.value?.cotcomponentes || [];
    });

    const irAComponenteAdyacente = async (direccion: 1 | -1): Promise<void> => {
        const lista = componentesHermanos.value;
        if (!lista.length || !dataActiva.value) return;
        const idx = lista.findIndex(c => c.id === dataActiva.value.id);
        if (idx === -1) return;
        const nuevoIdx = idx + direccion;
        if (nuevoIdx < 0 || nuevoIdx >= lista.length) return;

        const gen = ++navGen;

        const destino = lista[nuevoIdx];
        dataActiva.value = destino;
        if (destino.componenteMaestroId) {
            await fetchComponenteDetalles(destino.componenteMaestroId, gen);
        } else {
            if (gen === navGen) catalogos.value.tarifas = [];   // componente sin maestro: dropdown limpio
        }
    };

    const agregarComponente = (servicioId: string): void => {
        if (!cotizacion.value || !cotizacion.value.cotservicios) return;

        const servicio = cotizacion.value.cotservicios.find((s: CotServicio) => s.id === servicioId);

        if (servicio) {
            const fechaBase = getFechaLimpia(servicio.fechaInicioAbsoluta);
            const fechaHoraInicio = `${fechaBase}T00:00`;

            const nuevoComponente: ComponenteCompleto = {
                id: crypto.randomUUID(),
                componenteMaestroId: null,
                nombreSnapshot: [],
                tipo: 'extras',
                cantidad: 1,
                estado: 'pendiente',
                modo: 'incluido',
                fechaHoraInicio: fechaHoraInicio,
                fechaHoraFin: fechaHoraInicio,
                cotsegmentoId: null,
                cotsegmento: null,
                sobreescribirTraduccion: false,
                snapshotItems: [],
                cottarifas: [],
                detallesOperativos: []
            };

            if (!servicio.cotcomponentes) {
                servicio.cotcomponentes = [];
            }

            servicio.cotcomponentes.push(nuevoComponente);

            ordenarComponentesCronologicamente(servicio.cotcomponentes);
            sincronizarFechaServicio(servicio);
            abrirNivel('componente', nuevoComponente);
        }
    };

    /**
     * Elimina un componente logístico de un servicio específico dentro de la cotización.
     *
     * ¿Por qué existe?: Se encarga de remover el hito de la colección indexada, disparar la
     * recalculación cronológica de las fechas del servicio contenedor y limpiar el foco del
     * inspector de detalles en caliente si el elemento eliminado era el que estaba activo.
     *
     * @param servicioId - Identificador único UUID del servicio contenedor.
     * @param componenteId - Identificador único UUID del componente logístico a remover.
     */
    const eliminarComponente = (servicioId: string, componenteId: string): void => {
        if (!cotizacion.value || !cotizacion.value.cotservicios) return;

        const servicio = cotizacion.value.cotservicios.find((s: CotServicio) => s.id === servicioId);

        if (servicio && servicio.cotcomponentes) {
            servicio.cotcomponentes = servicio.cotcomponentes.filter((c: ComponenteCompleto) => c.id !== componenteId);

            // Sincroniza y recalcula las fronteras temporales del servicio afectado
            sincronizarFechaServicio(servicio);

            // Desmunda de forma segura la vista del inspector si el foco estaba en este componente
            if (dataActiva.value?.id === componenteId) {
                retrocederNivel();
            }
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
                componenteAdicionalVinculado: null,
                idComponenteInyectado: null,
                isInjecting: false,
                tituloTarifaVisible: false,
                categoriaTarifaVisible: false,
                modalidadTarifaVisible: false,
                sobreescribirTraduccion: false
            });
        }
    };

    const eliminarSnapshotItem = (componenteId: string, itemId: string): void => {
        if (dataActiva.value && dataActiva.value.id === componenteId) {
            const item = dataActiva.value.snapshotItems.find((i: SnapshotItem) => i.id === itemId);
            if (item && item.idComponenteInyectado) {
                removerComponenteInyectado(item, componenteId);
            }
            dataActiva.value.snapshotItems = dataActiva.value.snapshotItems.filter((i: SnapshotItem) => i.id !== itemId);
        }
    };


    const agregarDetalleOperativo = (componenteId: string, tipo: DetalleOperativoTipo = DetalleOperativoTipo.CLIENTE): void => {
        if (dataActiva.value && dataActiva.value.id === componenteId) {
            if (!dataActiva.value.detallesOperativos) dataActiva.value.detallesOperativos = [];
            dataActiva.value.detallesOperativos.push({
                id: crypto.randomUUID(),
                tipo,
                detalle: [{ language: 'es', content: '' }]
            });
        }
    };

    const eliminarDetalleOperativo = (componenteId: string, bloqueId: string): void => {
        if (dataActiva.value && dataActiva.value.id === componenteId && dataActiva.value.detallesOperativos) {
            dataActiva.value.detallesOperativos = dataActiva.value.detallesOperativos.filter(
                (b: DetalleOperativoBloque) => b.id !== bloqueId
            );
        }
    };

    const removerComponenteInyectado = (item: SnapshotItem, idPadre: string): void => {
        const servicio = findServicioByComponenteId(idPadre);
        if (servicio && servicio.cotcomponentes) {
            const idx = servicio.cotcomponentes.findIndex((c: ComponenteCompleto) => c.id === item.idComponenteInyectado);
            if (idx !== -1) {
                servicio.cotcomponentes.splice(idx, 1);
            }
        }
        item.idComponenteInyectado = null;
    };

    const toggleUpsellComponent = async (item: SnapshotItem, componentePadre: ComponenteCompleto): Promise<void> => {
        if (item.incluido) {
            item.modo = 'incluido';

            if (item.tieneUpsell && !item.idComponenteInyectado && !item.isInjecting) {
                item.isInjecting = true;

                try {
                    let compMaestro: Componente | undefined;
                    const vinculado = item.componenteAdicionalVinculado;

                    if (typeof vinculado === 'string') {
                        const res = await apiClient.get(vinculado);
                        compMaestro = res.data as Componente;
                    } else if (vinculado) {
                        compMaestro = vinculado;
                    }

                    if (!compMaestro) return;

                    const targetId = extractIdStr(compMaestro.id || compMaestro['@id']);
                    if (!catalogos.value.allComponentes.some((c) => extractIdStr(c.id || c['@id']) === targetId)) {
                        catalogos.value.allComponentes.push(compMaestro);
                    }

                    const nuevoId = crypto.randomUUID();
                    item.idComponenteInyectado = nuevoId;

                    const nuevoComp: ComponenteCompleto = {
                        id: nuevoId,
                        componenteMaestroId: compMaestro.id || compMaestro['@id'],
                        nombreSnapshot: JSON.parse(JSON.stringify(getTituloSafe(compMaestro))),
                        tipo: compMaestro.tipo || 'extras',
                        cantidad: componentePadre.cantidad,
                        estado: 'pendiente',
                        modo: 'incluido',
                        fechaHoraInicio: componentePadre.fechaHoraInicio,
                        fechaHoraFin: componentePadre.fechaHoraFin,
                        cotsegmentoId: componentePadre.cotsegmentoId,
                        cotsegmento: componentePadre.cotsegmento || null,
                        upsellSourceItemId: item.id,
                        sobreescribirTraduccion: false,
                        snapshotItems: [],
                        cottarifas: [],
                        detallesOperativos: []
                    };

                    if (compMaestro.componenteItems && Array.isArray(compMaestro.componenteItems)) {
                        nuevoComp.snapshotItems = await Promise.all(
                            compMaestro.componenteItems.map(mapearItemASnapshot)
                        );
                    }

                    let tarifasParaInyectar: any[] = [];
                    if (compMaestro.tarifas && compMaestro.tarifas.length === 1) {
                        tarifasParaInyectar.push(compMaestro.tarifas[0]);
                    }

                    nuevoComp.cottarifas = tarifasParaInyectar.map((tarifa) =>
                        mapearATarifaSnapshot(tarifa, cotizacion.value?.numPax || 1)
                    );

                    const servicio = findServicioByComponenteId(componentePadre.id);
                    if (servicio) {
                        if (!servicio.cotcomponentes) servicio.cotcomponentes = [];
                        servicio.cotcomponentes.push(nuevoComp);
                        ordenarComponentesCronologicamente(servicio.cotcomponentes);
                        sincronizarFechaServicio(servicio);
                    }

                } catch (err) {
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
        if (!cotizacion.value) return;

        const componente = cotizacion.value.cotservicios
            ?.flatMap(s => s.cotcomponentes || [])
            .find(c => c.id === componenteId) as unknown as ComponenteCompleto;

        if (!componente) return;

        const numPax = parseInt(String(cotizacion.value.numPax)) || 1;
        const tarifas = componente.cottarifas || [];

        const agrupables = tarifas.filter(
            (t: TarifaSnapshot) => t.rolSnapshot !== 'operativo' && t.grupoTarifa != null
        );

        const grupoActual = agrupables.length
            ? Math.max(...agrupables.map((t: TarifaSnapshot) => t.grupoTarifa as number))
            : 1;

        const enGrupoActual = agrupables.filter((t: TarifaSnapshot) => t.grupoTarifa === grupoActual);
        const tieneGrupal = enGrupoActual.some((t: TarifaSnapshot) => !!t.esGrupal);
        const paxAsignados = enGrupoActual
            .filter((t: TarifaSnapshot) => !t.esGrupal)
            .reduce((sum: number, t: TarifaSnapshot) => sum + (parseInt(String(t.cantidad)) || 0), 0);

        const grupoCubierto = enGrupoActual.length > 0 && (tieneGrupal || paxAsignados >= numPax);

        let grupoDestino: number;
        let cantidadInicial: number;

        if (grupoCubierto) {
            // Capacidad completa: la nueva tarifa arranca una alternativa nueva,
            // con el cupo total del file (cada grupo cuadra por sí mismo).
            grupoDestino = grupoActual + 1;
            cantidadInicial = numPax;
        } else {
            grupoDestino = grupoActual;
            const restantes = numPax - paxAsignados;
            cantidadInicial = restantes > 0 ? restantes : numPax;
        }

        const rolInicial: TarifaRolValue = grupoDestino === 1 ? 'estandar' : 'alternativa';

        const nuevaTarifa = {
            id: crypto.randomUUID(),
            tarifaMaestraId: null,
            tituloSnapshot: [{ language: 'es', content: 'Nueva Tarifa' }],
            nombreInternoSnapshot: 'Nueva Tarifa',
            cantidad: cantidadInicial,
            moneda: cotizacion.value.monedaGlobal,
            montoCosto: '0.00',
            rolSnapshot: rolInicial,
            grupoTarifa: grupoDestino,
            comisionOverrideSnapshot: null,
            notaRol: [],
            esGrupal: false,
            modalidadSnapshot: null,
            categoriaSnapshot: null,
            procedenciaSnapshot: null,
            edadMinimaSnapshot: null,
            edadMaximaSnapshot: null,
            proveedorMaestroId: null,
            proveedorNombreSnapshot: null,
            proveedorTituloSnapshot: [],
            proveedorUrlSnapshot: null,
            proveedorImagenesSnapshot: [],
            proveedorServicioMaestroId: null,
            proveedorServicioNombreSnapshot: null,
            proveedorServicioTituloSnapshot: [],
            proveedorServicioUrlSnapshot: null,
            proveedorServicioImagenesSnapshot: [],
            estadoOperativoSnapshot: 'sin-solicitar',   // FIX: 'pendiente' no existe en EstadoOperativoValue
            fechaLimitePago: null,
            proveedorOculto: false,
            sobreescribirTraduccion: false
        } as TarifaSnapshot;

        if (!componente.cottarifas) componente.cottarifas = [];
        componente.cottarifas.push(nuevaTarifa);

        abrirNivel('tarifa', nuevaTarifa);
    };

    /**
     * Elimina una tarifa snapshot de un componente logístico específico.
     *
     * ¿Por qué existe?: Se encarga de remover la tarifa de la colección mutada del componente,
     * y limpia el inspector de detalles en caliente (retrocediendo el nivel de navegación) si
     * la tarifa que se acaba de eliminar era la que se encontraba activa en la vista.
     *
     * @param componenteId - Identificador único UUID del componente logístico padre.
     * @param tarifaId - Identificador único UUID de la tarifa a remover.
     */
    const eliminarTarifa = (componenteId: string, tarifaId: string): void => {
        const servicio = findServicioByComponenteId(componenteId);

        if (servicio && servicio.cotcomponentes) {
            const componente = servicio.cotcomponentes.find((c: ComponenteCompleto) => c.id === componenteId);

            if (componente && componente.cottarifas) {
                componente.cottarifas = componente.cottarifas.filter((t: TarifaSnapshot) => t.id !== tarifaId);

                if (dataActiva.value?.id === tarifaId) {
                    retrocederNivel();
                }
            }
        }
    };

    const abrirEditorSegmentos = () => { isSegmentEditorOpen.value = true; };
    const cerrarEditorSegmentos = () => { isSegmentEditorOpen.value = false; };


    type TarifaLike = TarifaBase | Tarifa;

    /**
     * Predicado de tipo (Type Guard) para determinar si la tarifa corresponde al modelo extendido del frontend.
     *
     * ¿Por qué existe?: Permite a TypeScript refinar la unión de tipos de forma estricta en tiempo de compilación
     * sin recurrir a castings manuales.
     */
    const esTarifaLocal = (t: TarifaLike): t is Tarifa => 'tarifaId' in t;

    /**
     * Extrae de forma segura el identificador maestro de la tarifa, priorizando el estado local o el grafo JSON-LD de la API.
     */
    const getIdMaestroTarifa = (t: TarifaLike): string | null => {
        if (esTarifaLocal(t) && t.tarifaId) return t.tarifaId;
        const apiT = t as TarifaBase & { '@id'?: string };
        return extractIdStr(apiT['@id'] || '') || null;
    };

    /**
     * Determina si la tarifa aplica una modalidad de costo grupal o global.
     */
    const getEsGrupalTarifa = (t: TarifaLike): boolean => {
        return 'costoPorGrupo' in t ? !!t.costoPorGrupo : false;
    };

    /**
     * Resuelve la representación ISO de la moneda de la tarifa, aislando opcionalidades del esquema de la API.
     */
    const getMonedaTarifa = (t: TarifaLike): string => {
        if (!t.moneda) return 'USD';
        return typeof t.moneda === 'object'
            ? (t.moneda.id || t.moneda.nombre || 'USD')
            : String(t.moneda);
    };

    /**
     * Normaliza el monto del costo abstrayendo las diferencias de nombres de propiedades del backend.
     */
    const getMontoCostoTarifa = (t: TarifaLike): number | string => {
        if ('montoCosto' in t && t.montoCosto !== undefined) {
            return parseFloat(String(t.montoCosto));
        }
        if ('monto' in t && t.monto !== undefined) {
            return String(t.monto);
        }
        return 0;
    };


    /**
     * Estructura de forma segura la información del proveedor asociado, parseando referencias cruzadas o IRIs.
     * Resuelve título/url/imágenes contra el catálogo ya cargado en memoria (sin fetch adicional,
     * ya que Proveedor.proveedorImagenes ahora viaja en el grupo 'proveedor:read' del listado).
     */
    const getProveedorTarifa = (t: TarifaLike): { id: string | null; nombre: string | null; titulo?: I18nContent[]; url?: string | null; imagenes?: ImagenProveedorSnapshot[] } => {
        if ('proveedor' in t && t.proveedor) {
            const id = extractIdStr(t.proveedor);
            const encontrado = catalogos.value.proveedores.find(
                (p) => extractIdStr(p.id || p['@id']) === id
            );
            return {
                id,
                nombre: encontrado?.nombreComercial || null,
                titulo: (encontrado as any)?.titulo,
                url: encontrado?.url || null,
                imagenes: (encontrado as any)?.proveedorImagenes || []
            };
        }
        if ('provider' in t && t.provider) {
            const p = t.provider as { id?: string; '@id'?: string; nombreComercial?: string };
            return {
                id: extractIdStr(p.id || p['@id'] || ''),
                nombre: p.nombreComercial || null
            };
        }
        return { id: null, nombre: null };
    };

    /**
     * Resuelve el servicio-proveedor embebido (ej. tipo de habitación) desde el payload plano de tarifa.
     * Solo extrae id + nombre; título/url/imágenes se resuelven aparte por UUID cuando se necesiten.
     */
    const getProveedorServicioTarifa = (t: TarifaLike): { id: string | null; nombre: string | null } => {
        const psRaw = 'proveedorServicio' in t ? (t as any).proveedorServicio : null;
        if (!psRaw) return { id: null, nombre: null };

        if (typeof psRaw === 'string') {
            return { id: extractIdStr(psRaw), nombre: null };
        }

        return {
            id: extractIdStr(psRaw.proveedorServicioId || psRaw.id || psRaw['@id'] || ''),
            nombre: psRaw.nombre || null
        };
    };

    /**
     * Resuelve el identificador de texto o nombre interno destinado a la comunicación o vouchers de proveedores.
     */
    const getNombreParaProveedorTarifa = (t: TarifaLike): string | null => {
        const nombreParaProveedor = 'nombreParaProveedor' in t ? t.nombreParaProveedor : null;
        const nombreInterno = 'nombreInterno' in t ? t.nombreInterno : null;
        return nombreParaProveedor || nombreInterno || null;
    };

    /**
     * Resuelve la modalidad comercial u operativa (ej. Privado, Compartido) de la tarifa.
     */
    const getModalidadTarifa = (t: TarifaLike): TarifaModalidadValue | null => {
        if (!('modalidad' in t) || !t.modalidad) return null;
        return t.modalidad as TarifaModalidadValue;
    };

    const getCategoriaTarifa = (t: TarifaLike): TarifaCategoriaValue | null => {
        if (!('categoria' in t) || !t.categoria) return null;
        return t.categoria as TarifaCategoriaValue;
    };

    const getEdadMinimaTarifa = (t: TarifaLike): number | null => {
        return 'edadMinima' in t && t.edadMinima !== undefined ? Number(t.edadMinima) : null;
    };
    const getEdadMaximaTarifa = (t: TarifaLike): number | null => {
        return 'edadMaxima' in t && t.edadMaxima !== undefined ? Number(t.edadMaxima) : null;
    };
    const getProcedenciaTarifa = (t: TarifaLike): TarifaProcedenciaValue | null => {
        return 'procedencia' in t ? (t.procedencia as TarifaProcedenciaValue) || null : null;
    };
    const getRolTarifa = (t: TarifaLike): TarifaRolValue =>
        ('rol' in t && t.rol ? t.rol as TarifaRolValue : 'estandar');
    const getComisionOverrideTarifa = (t: TarifaLike): number | string | null =>
        'comisionOverride' in t ? (t.comisionOverride ?? null) : null;

    /**
     * Transforma de forma segura cualquier objeto de tarifa (API o Frontend) al contrato estricto TarifaSnapshot.
     *
     * ¿Por qué existe?: Centraliza y aísla las inconsistencies de nombres entre API Platform (JSON-LD)
     * y los modelos extendidos del cliente, garantizando que el timeline nunca maneje datos parciales o corruptos.
     *
     * @example
     * const snapshots = tarifasParaInyectar.map(t => mapearATarifaSnapshot(t, numPax));
     */
    function mapearATarifaSnapshot(tarifa: TarifaLike, numPax: number = 1): TarifaSnapshot {
        const esGrupal = getEsGrupalTarifa(tarifa);
        const proveedor = getProveedorTarifa(tarifa);
        const proveedorServicio = getProveedorServicioTarifa(tarifa);
        const rol = getRolTarifa(tarifa);

        return {
            id: crypto.randomUUID(),
            tarifaMaestraId: getIdMaestroTarifa(tarifa),
            tituloSnapshot: JSON.parse(JSON.stringify(getTituloSafe(tarifa))),
            nombreInternoSnapshot: 'nombreInterno' in tarifa ? (tarifa as any).nombreInterno || null : null,
            cantidad: esGrupal ? 1 : numPax,
            moneda: getMonedaTarifa(tarifa),
            montoCosto: getMontoCostoTarifa(tarifa),
            rolSnapshot: rol,
            grupoTarifa: rol === 'operativo' ? null : 1,
            comisionOverrideSnapshot: rol === 'operativo' ? '0.00' : getComisionOverrideTarifa(tarifa),
            notaRol: [],
            esGrupal,
            modalidadSnapshot: getModalidadTarifa(tarifa),
            categoriaSnapshot: getCategoriaTarifa(tarifa),
            procedenciaSnapshot: getProcedenciaTarifa(tarifa),
            edadMinimaSnapshot: getEdadMinimaTarifa(tarifa),
            edadMaximaSnapshot: getEdadMaximaTarifa(tarifa),
            proveedorMaestroId: proveedor.id,
            proveedorNombreSnapshot: proveedor.nombre,
            proveedorTituloSnapshot: proveedor.titulo ? JSON.parse(JSON.stringify(proveedor.titulo)) : [],
            proveedorUrlSnapshot: proveedor.url || null,
            proveedorImagenesSnapshot: mapearImagenesSnapshot(proveedor.imagenes),
            proveedorServicioMaestroId: proveedorServicio.id,
            proveedorServicioNombreSnapshot: proveedorServicio.nombre,
            proveedorServicioTituloSnapshot: [],
            proveedorServicioUrlSnapshot: null,
            proveedorServicioImagenesSnapshot: [],
            nombreParaProveedorSnapshot: getNombreParaProveedorTarifa(tarifa),
            estadoOperativoSnapshot: 'sin-solicitar',
            fechaLimitePago: null,
            condicionesPagoSnapshot: null,
            proveedorOculto: false,
            sobreescribirTraduccion: false
        };
    }

    const encontrarComponentePorTarifaId = (tarifaId: string): ComponenteCompleto | null => {
        if (!cotizacion.value?.cotservicios) return null;
        for (const servicio of cotizacion.value.cotservicios) {
            const comp = servicio.cotcomponentes?.find(c => c.cottarifas?.some(t => t.id === tarifaId));
            if (comp) return comp;
        }
        return null;
    };


    const componenteActualDeTarifa = computed<ComponenteCompleto | null>(() => {
        if (inspectorActivo.value !== 'tarifa' || !dataActiva.value) return null;
        return encontrarComponentePorTarifaId(dataActiva.value.id);
    });

    const tarifasHermanas = computed<TarifaSnapshot[]>(() => {
        const componente = componenteActualDeTarifa.value;
        if (!componente?.cottarifas) return [];
        // Grupo primero (nulls —operativas— al final), estable dentro del mismo grupo
        return [...componente.cottarifas].sort((a, b) => (a.grupoTarifa ?? Infinity) - (b.grupoTarifa ?? Infinity));
    });

    const irATarifaAdyacente = async (direccion: 1 | -1): Promise<void> => {
        const lista = tarifasHermanas.value;
        if (!lista.length || !dataActiva.value) return;
        const idx = lista.findIndex(t => t.id === dataActiva.value.id);
        if (idx === -1) return;
        const nuevoIdx = idx + direccion;
        if (nuevoIdx < 0 || nuevoIdx >= lista.length) return;

        const gen = ++navGen;

        const destino = lista[nuevoIdx];
        dataActiva.value = destino;   // mismo nivel, no toca historialNavegacion
        if (destino.proveedorMaestroId) {
            await fetchProveedorServiciosDeProveedor(destino.proveedorMaestroId, gen);
        } else {
            if (gen === navGen) catalogos.value.proveedorServicios = [];
        }
    };

    const marcarTarifaComoEstandar = (tarifaId: string): void => {
        const componente = encontrarComponentePorTarifaId(tarifaId);
        if (!componente?.cottarifas) return;

        const tarifa = componente.cottarifas.find(t => t.id === tarifaId);
        if (!tarifa || tarifa.grupoTarifa == null) return;

        const grupoObjetivo = tarifa.grupoTarifa;
        componente.cottarifas.forEach((t: TarifaSnapshot) => {
            if (t.rolSnapshot === 'operativo' || t.grupoTarifa == null) return;
            t.rolSnapshot = (t.grupoTarifa === grupoObjetivo) ? 'estandar' : 'alternativa';
        });
    };

    const inyectarComponentesDeSegmento = async (
        segmentoMaestro: Segmento,
        diaDelSegmento: number = 1,
        idSegmentoGenerado: string,
        itinerarioId: string | null = null
    ): Promise<void> => {
        if (!dataActiva.value) return;

        if (segmentoMaestro.segmentoComponentes && Array.isArray(segmentoMaestro.segmentoComponentes)) {

            const mejoresMatches = new Map<string, SegmentoComponenteProcesado>();

            segmentoMaestro.segmentoComponentes.forEach((rawSegComp) => {
                const segComp = rawSegComp as SegmentoComponenteProcesado;
                let compMaestro: string | components['schemas']['Componente-componente.item.read'] | ComponenteCompleto | undefined = segComp.componente;

                if (!compMaestro) return;

                const cId = String(extractIdStr(compMaestro) || '');
                const found = catalogos.value.allComponentes.find((c) => String(extractIdStr(c.id || c['@id']) || '') === cId);
                if (found) {
                    compMaestro = found as ComponenteCompleto;
                }

                if (!compMaestro || typeof compMaestro !== 'object') return;

                const compObj = compMaestro as Record<string, unknown>;
                const compId: string = String(extractIdStr(String(compObj.id || compObj['@id'] || '')) || '');
                if (!compId) return;

                if (segComp.dia !== undefined && segComp.dia !== null && segComp.dia !== diaDelSegmento) {
                    return;
                }

                let esPrioritario: boolean = false;

                if (segComp.itinerarioContexto) {
                    const ctxId: string = String(extractIdStr(segComp.itinerarioContexto) || '');

                    if (itinerarioId && ctxId === String(extractIdStr(itinerarioId) || '')) {
                        esPrioritario = true;
                    } else {
                        return;
                    }
                }

                const matchPrevio = mejoresMatches.get(compId);
                if (!matchPrevio || (esPrioritario && !matchPrevio.esPrioritario)) {
                    segComp.tempCompObj = compMaestro;
                    segComp.esPrioritario = esPrioritario;
                    mejoresMatches.set(compId, segComp);
                }
            });

            for (const [compId, segComp] of mejoresMatches.entries()) {
                let compMaestro = segComp.tempCompObj as ComponenteCompleto;
                if (!compMaestro) continue;

                const compHidratado = catalogos.value.allComponentes.find((c) => {
                    if (!c || typeof c !== 'object') return false;
                    const currentId = String(extractIdStr((c as any).id || (c as any)['@id'] || ''));
                    return currentId === compId && 'tarifas' in c;
                });

                if (compHidratado) {
                    compMaestro = compHidratado as unknown as ComponenteCompleto;
                }

                let fechaBase = getFechaLimpia(dataActiva.value.fechaInicioAbsoluta);

                if (diaDelSegmento > 1) {
                    const dateObj = new Date(`${fechaBase}T12:00:00Z`);
                    dateObj.setUTCDate(dateObj.getUTCDate() + (diaDelSegmento - 1));
                    fechaBase = dateObj.toISOString().split('T')[0];
                }

                const tipoComp = compMaestro.tipo || 'extras';
                const reqHora = requiereHoraExacta(tipoComp);

                const hInicio = reqHora ? (getHoraLimpia(segComp.hora) || '08:00') : '00:00';
                const fHoraInicio = toDateTimeString(fechaBase, hInicio);

                const duracionComp = parseFloat(String(compMaestro.duracion || 0));

                let fHoraFin = '';
                if (reqHora) {
                    const hFin = getHoraLimpia(segComp.horaFin);
                    if (hFin) {
                        let extraDias = Math.floor(duracionComp / 24);

                        if (hFin <= hInicio) {
                            extraDias = Math.max(extraDias, 1);
                        }

                        let fechaFin = fechaBase;
                        if (extraDias > 0) {
                            const dNext = new Date(`${fechaBase}T12:00:00Z`);
                            dNext.setUTCDate(dNext.getUTCDate() + extraDias);
                            fechaFin = dNext.toISOString().split('T')[0];
                        }

                        fHoraFin = toDateTimeString(fechaFin, hFin);
                    } else {
                        fHoraFin = addDurationToDate(fHoraInicio, duracionComp);
                    }
                } else {
                    const calcFin = addDurationToDate(fHoraInicio, duracionComp);
                    fHoraFin = toDateTimeString(calcFin.split('T')[0]);
                }

                const snapshotItemsPreparados = await Promise.all(
                    (compMaestro.componenteItems || []).map(mapearItemASnapshot)
                );

                const maestroObj = compMaestro as Record<string, unknown>;
                const nuevoComp: ComponenteCompleto = {
                    id: crypto.randomUUID(),
                    componenteMaestroId: compMaestro.id || String(maestroObj['@id'] || '') || null,
                    nombreSnapshot: JSON.parse(JSON.stringify(getTituloSafe(compMaestro))),
                    tipo: tipoComp,
                    cantidad: calcularPernoctes(fHoraInicio, fHoraFin),
                    estado: 'pendiente',
                    modo: segComp.modo || 'incluido',
                    fechaHoraInicio: fHoraInicio,
                    fechaHoraFin: fHoraFin,
                    cotsegmentoId: idSegmentoGenerado,
                    cotsegmento: null,
                    sobreescribirTraduccion: false,
                    cottarifas: [],
                    detallesOperativos: [],
                    snapshotItems: snapshotItemsPreparados
                };

                let tarifasParaInyectar: (components['schemas']['Tarifa-componente.item.read'] | Tarifa)[] = [];

                const tarifaPredObj = segComp.tarifaPredeterminada as Record<string, unknown> | null | undefined;
                const tarifaDefId = extractIdStr(
                    segComp.tarifaId ||
                    String(tarifaPredObj?.id || tarifaPredObj?.['@id'] || '') ||
                    (typeof segComp.tarifaPredeterminada === 'string' ? segComp.tarifaPredeterminada : '')
                );

                if (tarifaDefId) {
                    const tDef = (compMaestro.tarifas || []).find((t: any) => extractIdStr(t.id || t['@id']) === tarifaDefId)
                        || todasLasTarifasMaestras.value.find((t: any) => extractIdStr(t.id || t['@id']) === tarifaDefId);
                    if (tDef) tarifasParaInyectar.push(tDef);
                } else if (compMaestro.tarifas && compMaestro.tarifas.length === 1 && !['no_incluido', 'reemplazado'].includes(nuevoComp.modo)) {
                    tarifasParaInyectar.push(compMaestro.tarifas[0]);
                }

                nuevoComp.cottarifas = tarifasParaInyectar.map((t) =>
                    mapearATarifaSnapshot(t, cotizacion.value?.numPax || 1)
                );

                if (!dataActiva.value.cotcomponentes) {
                    dataActiva.value.cotcomponentes = [];
                }
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
            dataActiva.value.nombrePublicoSnapshot = JSON.parse(JSON.stringify(getTituloSafe(plantillaProfunda))); // 👉 NUEVO
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
                        segmentoMaestroId: extractIdStr(seg.id || seg['@id']),
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
            segmentoMaestroId: extractIdStr(segmentoMaestro.id || segmentoMaestro['@id']),
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

    /**
     * Reordena los segmentos (párrafos) de un servicio, restringiendo el movimiento
     * para que no se crucen segmentos de días diferentes. Recalcula la propiedad 'orden'.
     */
    const reordenarSegmentos = (servicioId: string, fromId: string, toId: string): void => {
        if (!cotizacion.value || !cotizacion.value.cotservicios) return;

        const servicio = cotizacion.value.cotservicios.find((s: CotServicio) => s.id === servicioId);
        if (!servicio || !servicio.cotsegmentos) return;

        const fromIdx = servicio.cotsegmentos.findIndex((s: CotSegmento) => s.id === fromId);
        const toIdx = servicio.cotsegmentos.findIndex((s: CotSegmento) => s.id === toId);

        if (fromIdx === -1 || toIdx === -1 || fromIdx === toIdx) return;

        const fromSeg = servicio.cotsegmentos[fromIdx];
        const toSeg = servicio.cotsegmentos[toIdx];

        // Regla estricta: No se permite arrastrar un segmento a un día distinto
        if (fromSeg.dia !== toSeg.dia) return;

        // Extraer y reubicar
        const [moved] = servicio.cotsegmentos.splice(fromIdx, 1);
        servicio.cotsegmentos.splice(toIdx, 0, moved);

        // Recalcular la propiedad 'orden' solo para el día afectado
        let currentOrden = 1;
        servicio.cotsegmentos.forEach((seg: CotSegmento) => {
            if (seg.dia === fromSeg.dia) {
                seg.orden = currentOrden++;
            }
        });
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

            const nuevoSeg: CotSegmento = {
                id: nuevoIdSeg,
                segmentoMaestroId: extractIdStr(segmentoMaestro.id || segmentoMaestro['@id']),
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

        const maestro = catalogos.value.servicios.find((s: Servicio) => extractIdStr(s.id || s['@id']) === targetId);

        if (maestro && dataActiva.value) {
            const titulo = JSON.parse(JSON.stringify(getTituloSafe(maestro)));
            dataActiva.value.nombreSnapshot = titulo;
            dataActiva.value.nombrePublicoSnapshot = JSON.parse(JSON.stringify(titulo));
            await fetchServicioDetalles(val);
        }
    };

    const onServicioFechaChange = (): void => {
        if (!dataActiva.value || !dataActiva.value.fechaInicioAbsoluta) return;
        const nuevaFechaBase = getFechaLimpia(dataActiva.value.fechaInicioAbsoluta);

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

                    comp.fechaHoraInicio = toDateTimeString(nuevaFechaCompStr, horaActual);

                    const nS = new Date(comp.fechaHoraInicio).getTime();
                    const nE = new Date(nS + duracionMs);
                    comp.fechaHoraFin = formatLocalDateTime(nE);
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
    const onComponenteMaestroChange = async (val: string | null): Promise<void> => {
        if (!val || val === 'null') {
            catalogos.value.tarifas = [];
            return;
        }

        const targetId = extractIdStr(val);
        const maestro = catalogos.value.allComponentes.find(
            (c) => extractIdStr(c.id || (c as any)['@id'] || '') === targetId
        );

        if (maestro && isComponenteCompleto(maestro) && dataActiva.value) {
            dataActiva.value.nombreSnapshot = JSON.parse(JSON.stringify(getTituloSafe(maestro)));

            const reqHora = requiereHoraExacta(maestro.tipo);
            const fechaDate = dataActiva.value.fechaHoraInicio.split('T')[0];

            if (reqHora) {
                dataActiva.value.fechaHoraInicio = toDateTimeString(fechaDate, '08:00');
                dataActiva.value.fechaHoraFin = addDurationToDate(dataActiva.value.fechaHoraInicio, maestro.duracion || 0);
            } else {
                dataActiva.value.fechaHoraInicio = toDateTimeString(fechaDate);
                const endStr = addDurationToDate(dataActiva.value.fechaHoraInicio, maestro.duracion || 0);
                dataActiva.value.fechaHoraFin = toDateTimeString(endStr.split('T')[0]);
            }

            if (dataActiva.value.fechaHoraInicio && dataActiva.value.fechaHoraFin) {
                dataActiva.value.cantidad = calcularPernoctes(dataActiva.value.fechaHoraInicio, dataActiva.value.fechaHoraFin);
            }

            dataActiva.value.snapshotItems = [];
            dataActiva.value.cottarifas = [];

            await fetchComponenteDetalles(val);

        } else if (maestro && dataActiva.value) {
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
                        comp.fechaHoraFin = formatLocalDateTime(nE);
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
        dataActiva.value.fechaHoraFin = formatLocalDateTime(newEndObj);

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
                            comp.fechaHoraFin = formatLocalDateTime(nE);
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

    /**
     * Actualiza los datos de la tarifa activa basándose en la selección del catálogo maestro.
     *
     * ¿Por qué existe?: Este método sincroniza la información operativa (dataActiva) con la
     * estructura de precios, moneda y configuración de negocio del catálogo (maestro) cuando el
     * usuario selecciona una nueva tarifa desde la interfaz (SearchableSelect).
     *
     * Relaciones críticas y efectos secundarios:
     * - Sobreescribe múltiples propiedades de `dataActiva.value` (montoCosto, moneda, esGrupal, estadoOperativoSnapshot, etc.).
     * - Depende de `catalogos.value.tarifas` y `todasLasTarifasMaestras.value` para localizar la entidad completa.
     * - Depende de `catalogos.value.proveedores` para resolver e hidratar el nombre comercial del proveedor vinculado.
     *
     * @example
     * // Se ejecuta automáticamente por el evento @update:model-value del componente hijo:
     * store.onTarifaMaestraChange('/api/tarifas/12345-abcde');
     *
     * @param val - El ID puro o IRI (Internationalized Resource Identifier) de la tarifa maestra.
     * @returns No retorna ningún valor, muta el estado de dataActiva por referencia.
     */
    const onTarifaMaestraChange = (val: string): void => {
        const targetId = extractIdStr(val);

        const maestro = catalogos.value.tarifas.find((t: any) => extractIdStr(t.id) === targetId || extractIdStr(t['@id']) === targetId)
            || todasLasTarifasMaestras.value.find((t: any) => extractIdStr(t.id) === targetId || extractIdStr(t['@id']) === targetId);

        if (maestro && dataActiva.value) {

            const rol = getRolTarifa(maestro);

            dataActiva.value.tituloSnapshot = JSON.parse(JSON.stringify(getTituloSafe(maestro)));
            dataActiva.value.nombreInternoSnapshot = maestro.nombreInterno || null;

            if (typeof maestro.moneda === 'object' && maestro.moneda !== null) {
                dataActiva.value.moneda = maestro.moneda.id || maestro.moneda.nombre || 'USD';
            } else {
                dataActiva.value.moneda = maestro.moneda || 'USD';
            }

            dataActiva.value.montoCosto = parseFloat(maestro.monto || '0');


            dataActiva.value.rolSnapshot = rol;
            dataActiva.value.comisionOverrideSnapshot = rol === 'operativo' ? '0.00' : getComisionOverrideTarifa(maestro);
            dataActiva.value.grupoTarifa = rol === 'operativo' ? null : (dataActiva.value.grupoTarifa ?? 1);

            dataActiva.value.modalidadSnapshot = maestro.modalidad || null;
            dataActiva.value.categoriaSnapshot = maestro.categoria || null;
            dataActiva.value.procedenciaSnapshot = maestro.procedencia || null;
            dataActiva.value.edadMinimaSnapshot = maestro.edadMinima ?? null;
            dataActiva.value.edadMaximaSnapshot = maestro.edadMaxima ?? null;

            if (maestro.costoPorGrupo) {
                dataActiva.value.cantidad = 1;
                dataActiva.value.esGrupal = true;
            } else {
                dataActiva.value.esGrupal = false;
            }

            if (maestro.proveedor) {
                const provId = extractIdStr(maestro.proveedor);
                dataActiva.value.proveedorMaestroId = provId;

                const provCat = catalogos.value.proveedores.find((p: any) => extractIdStr(p.id || p['@id']) === provId);
                if (provCat) {
                    dataActiva.value.proveedorNombreSnapshot = provCat.nombreComercial;
                    dataActiva.value.proveedorTituloSnapshot = JSON.parse(JSON.stringify(getTituloSafe(provCat)));
                    dataActiva.value.proveedorUrlSnapshot = provCat.url || null;
                    dataActiva.value.proveedorImagenesSnapshot = mapearImagenesSnapshot((provCat as any).proveedorImagenes);
                } else {
                    dataActiva.value.proveedorImagenesSnapshot = [];
                }

                fetchProveedorServiciosDeProveedor(provId);
            }

            const psRaw = (maestro as any).proveedorServicio;
            if (psRaw && typeof psRaw === 'object') {
                dataActiva.value.proveedorServicioMaestroId = extractIdStr(psRaw.proveedorServicioId || psRaw.id || psRaw['@id'] || '');
                dataActiva.value.proveedorServicioNombreSnapshot = psRaw.nombre || null;
                dataActiva.value.proveedorServicioTituloSnapshot = [];
                dataActiva.value.proveedorServicioUrlSnapshot = null;
                dataActiva.value.proveedorServicioImagenesSnapshot = [];
            } else {
                dataActiva.value.proveedorServicioMaestroId = null;
                dataActiva.value.proveedorServicioNombreSnapshot = null;
                dataActiva.value.proveedorServicioTituloSnapshot = [];
                dataActiva.value.proveedorServicioUrlSnapshot = null;
                dataActiva.value.proveedorServicioImagenesSnapshot = [];
            }

            dataActiva.value.nombreParaProveedorSnapshot = maestro.nombreParaProveedor || maestro.nombreInterno || null;
            dataActiva.value.estadoOperativoSnapshot = 'sin-solicitar';
            dataActiva.value.fechaLimitePago = null;
            dataActiva.value.condicionesPagoSnapshot = null;
        }
    };

    /**
     * Carga los servicios (livianos: id+nombre) del proveedor seleccionado.
     * Se dispara cada vez que cambia el proveedor elegido en la tarifa, para alimentar
     * el dropdown filtrado de ProveedorServicio.
     */
    const fetchProveedorServiciosDeProveedor = async (proveedorId: string | null, gen?: number) => {
        if (!proveedorId) {
            catalogos.value.proveedorServicios = [];
            return;
        }
        try {
            const res = await apiClient.get(`/platform/travel/proveedor-servicios?proveedor_id=${proveedorId}&pagination=false`);
            if (gen !== undefined && gen !== navGen) return;
            const raw = res.data['hydra:member'] || res.data['member'] || [];
            catalogos.value.proveedorServicios = raw.map((ps: any) => ({
                id: extractIdStr(ps.id || ps['@id']),
                nombre: ps.nombre,
                proveedorId
            }));
        } catch (e) {
            console.error('Error cargando servicios del proveedor', e);
            catalogos.value.proveedorServicios = [];
        }
    };

    /**
     * El usuario eligió un servicio-proveedor del dropdown. Como la colección no trae 'titulo'
     * (solo el Get individual lo expone), hacemos un fetch de detalle para hidratar el snapshot completo.
     */
    /**
     * El usuario eligió un servicio-proveedor del dropdown. Como la colección no trae 'titulo'
     * ni imágenes (solo el Get individual lo expone), hacemos un fetch de detalle para hidratar
     * el snapshot completo, incluyendo la galería.
     */
    const onProveedorServicioChange = async (val: string | null): Promise<void> => {
        if (!val || val === 'null') {
            if (dataActiva.value) {
                dataActiva.value.proveedorServicioMaestroId = null;
                dataActiva.value.proveedorServicioNombreSnapshot = null;
                dataActiva.value.proveedorServicioTituloSnapshot = [];
                dataActiva.value.proveedorServicioUrlSnapshot = null;
                dataActiva.value.proveedorServicioImagenesSnapshot = [];
            }
            return;
        }
        const targetId = extractIdStr(val);
        if (!dataActiva.value) return;

        dataActiva.value.proveedorServicioMaestroId = targetId;

        const opcionLocal = catalogos.value.proveedorServicios.find((ps) => ps.id === targetId);
        if (opcionLocal) dataActiva.value.proveedorServicioNombreSnapshot = opcionLocal.nombre;

        try {
            const res = await apiClient.get(`/platform/travel/proveedor-servicios/${targetId}`);
            dataActiva.value.proveedorServicioNombreSnapshot = res.data.nombre;
            dataActiva.value.proveedorServicioTituloSnapshot = JSON.parse(JSON.stringify(getTituloSafe(res.data)));
            dataActiva.value.proveedorServicioUrlSnapshot = res.data.url || null;
            dataActiva.value.proveedorServicioImagenesSnapshot = mapearImagenesSnapshot(res.data.proveedorServicioImagenes);
        } catch (e) {
            console.error('No se pudo hidratar el servicio-proveedor', e);
        }
    };

    const onProveedorChange = (val: string | null): void => {
        if (dataActiva.value) {
            dataActiva.value.proveedorServicioMaestroId = null;
            dataActiva.value.proveedorServicioNombreSnapshot = null;
            dataActiva.value.proveedorServicioTituloSnapshot = [];
            dataActiva.value.proveedorServicioUrlSnapshot = null;
            dataActiva.value.proveedorServicioImagenesSnapshot = [];
        }

        if (!val || val === 'null') {
            if (dataActiva.value) {
                dataActiva.value.proveedorMaestroId = null;
                dataActiva.value.proveedorNombreSnapshot = null;
                dataActiva.value.proveedorTituloSnapshot = [];
                dataActiva.value.proveedorUrlSnapshot = null;
                dataActiva.value.proveedorImagenesSnapshot = [];

                // 🔥 Sin proveedor, la gestión operativa no aplica — limpiamos los 3
                dataActiva.value.estadoOperativoSnapshot = null;
                dataActiva.value.fechaLimitePago = null;
                dataActiva.value.condicionesPagoSnapshot = null;
            }
            catalogos.value.proveedorServicios = [];
            return;
        }

        const targetId = extractIdStr(val);
        const provCat = catalogos.value.proveedores.find((p: any) => extractIdStr(p.id || p['@id']) === targetId);

        if (provCat && dataActiva.value) {
            dataActiva.value.proveedorMaestroId = targetId;
            dataActiva.value.proveedorNombreSnapshot = provCat.nombreComercial;
            dataActiva.value.proveedorTituloSnapshot = JSON.parse(JSON.stringify(getTituloSafe(provCat)));
            dataActiva.value.proveedorUrlSnapshot = provCat.url || null;
            dataActiva.value.proveedorImagenesSnapshot = mapearImagenesSnapshot((provCat as any).proveedorImagenes);

            // 🔥 Proveedor recién asignado -> arranca en Sin Solicitar
            dataActiva.value.estadoOperativoSnapshot = 'sin-solicitar';
        }

        fetchProveedorServiciosDeProveedor(targetId);
    };

    const actualizarTextosSegmentos = async (): Promise<void> => {
        if (!dataActiva.value || !dataActiva.value.cotsegmentos || dataActiva.value.cotsegmentos.length === 0) return;

        // Extraer IDs maestros únicos de los segmentos actuales en la vista
        const idsToFetch = Array.from(new Set(
            dataActiva.value.cotsegmentos
                .map((s: any) => s.segmentoMaestroId)
                .filter(Boolean)
        ));

        if (idsToFetch.length === 0) {
            alert("Los segmentos actuales no tienen vinculación con un maestro. Aplica la plantilla de nuevo para vincularlos.");
            return;
        }

        isLoading.value = true;
        try {
            // Petición al endpoint en formato id[]=...&id[]=...
            const idsParam = (idsToFetch as string[]).map((id: string) => `id[]=${id}`).join('&');
            const res = await apiClient.get(`/platform/travel/segmentos?${idsParam}&pagination=false`);
            const segmentosMaestros = res.data['hydra:member'] || res.data['member'] || [];

            // Crear diccionario de maestros para búsqueda O(1)
            const mapaMaestros = new Map();
            segmentosMaestros.forEach((seg: any) => {
                mapaMaestros.set(extractIdStr(seg.id || seg['@id']), seg);
            });

            // Actualizar estrictamente solo los textos e imágenes
            dataActiva.value.cotsegmentos.forEach((cotSeg: any) => {
                if (cotSeg.segmentoMaestroId && mapaMaestros.has(cotSeg.segmentoMaestroId)) {
                    const maestro = mapaMaestros.get(cotSeg.segmentoMaestroId);
                    cotSeg.nombreSnapshot = JSON.parse(JSON.stringify(getTituloSafe(maestro)));
                    cotSeg.contenidoSnapshot = JSON.parse(JSON.stringify(maestro.contenido || []));
                    cotSeg.notasSnapshot = extraerNotasSnapshot(maestro);
                    cotSeg.imagenesSnapshot = extraerImagenesSnapshot(maestro);
                }
            });

        } catch (error) {
            console.error("Error al actualizar textos de los segmentos:", error);
            alert("Ocurrió un error al actualizar los textos del storytelling.");
        } finally {
            isLoading.value = false;
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
        actualizarTextosSegmentos,
        agregarSegmentoIndividual, reordenarSegmentos, procesarInsercionSegmento, removerCotSegmento,
        onServicioMaestroChange, onServicioFechaChange, onComponenteMaestroChange,
        onComponenteFechasChange, onSegmentoDiaChange, onTarifaMaestraChange,
        actualizarInicioManteniendoRango, onProveedorChange, agregarDetalleOperativo, eliminarDetalleOperativo,
        fetchProveedorServiciosDeProveedor, onProveedorServicioChange, limpiarServicioProveedor, marcarTarifaComoEstandar,
        componenteActualDeTarifa, tarifasHermanas, irATarifaAdyacente,
        servicioActualDeComponente, componentesHermanos, irAComponenteAdyacente, serviciosOrdenados, irAServicioAdyacente, historialNavegacion,
        buscarServiciosAsincrono, buscarProveedoresAsincrono
    };
});