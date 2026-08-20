import {defineStore} from 'pinia';
import { extractApiErrorMessage } from '@/services/apiError';
import {computed, ref, type Ref} from 'vue';
import {apiClient} from '@/services/apiClient';

import {
    Catalogos,
    ClasePasajeroInterna,
    CLASIFICACION_SCHEMA_VERSION,
    ClasificacionFinancieraCliente,
    ClasificacionFinancieraInterna,
    Componente,
    ComponenteCatalogo,
    ComponenteCompleto,
    ComponentePlaceholder,
    ComponenteTipo,
    Cotizacion,
    CotizacionFileExtended,
    CotSegmento,
    CotServicio,
    DeltaUpgradePorPerfil,
    DetalleOperativoBloque,
    DetalleOperativoTipo,
    etiquetaGrupoTarifa,
    expurgarParaCliente,
    formatRangoEdad,
    getProcedenciaUI,
    MODALIDAD_CONFIG,
    CATEGORIA_CONFIG,
    I18nContent,
    ImagenSnapshot,
    InclusionLinea,
    InclusionServicio,
    InclusionTarifa,
    Item,
    Itinerario,
    LineaDetalleClaseInterna,
    ModoFinanciero,
    NivelInspector,
    NodoInspector,
    NotaSnapshot,
    OpcionUpgradeInterna,
    PrecioDesdeRango,
    Organizacion,
    RecursoHydra,
    Segmento,
    SegmentoComponenteProcesado,
    Servicio,
    SnapshotItem,
    PapelesDeTarifa,
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

import {
    parseNaiveAsUTC,
    formatNaiveFromUTC,
    getDuracionMs,
    addDurationToDate,
} from '@/utils/naiveDate';

import {ApiIdioma} from '@/types/maestroModel';
import type {ProveedorWrite} from '@/types/organizacionModel';
import {components} from "@/types/api";

/** Distingue el maestro completo del placeholder ("Sincronizando…") por `tipo`. */
export const isComponenteCompleto = (c: ComponenteCatalogo | null | undefined): c is Componente => {
    return !!c && 'tipo' in c;
};

/**
 * Cuántas unidades cuenta una cantidad, distinguiendo «vacío» de «cero».
 *
 * ⚠️ En JavaScript el cero es falso, así que el `|| 1` que había repartido por el cálculo
 * convertía una tarifa puesta a **0** en **1**: poner a cero la tarifa de niños —la forma natural
 * de decir «este grupo no lleva»— sumaba una unidad al total. Y no saltaba ninguna alerta, porque
 * `isComponenteConAlerta` usaba `|| 0`: **el aviso contaba cero y el dinero contaba uno**.
 *
 * El 1 por defecto sigue teniendo sentido cuando el campo está SIN RELLENAR —una tarifa recién
 * creada—; lo que no puede es pisar un cero escrito a conciencia.
 */
const unidadesDe = (valor: unknown, porDefecto = 1): number => {
    if (valor === null || valor === undefined || valor === '') {
        return porDefecto;
    }

    const n = parseInt(String(valor), 10);

    return Number.isNaN(n) ? porDefecto : n;
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
        tiposComponente: [],
        monedas: []
    });

    const todasLasTarifasMaestras = ref<Tarifa[]>([]);
    const cotizacion = ref<Cotizacion | null>(null);
    const fileActual = ref<CotizacionFileExtended | null>(null);

    // Modo catálogo de tours: el padre es un CotizacionCatalogo en vez de un
    // File. Los tours usan fechas base nominales (solo se muestra "Día N")
    // y exponen un precio comercial "Desde X" además del cálculo real.
    const modoCatalogo = ref(false);
    const FECHA_BASE_NOMINAL = '2030-01-05';

    /**
     * Mínimo de letras para lanzar una búsqueda remota en el catálogo.
     *
     * Estaba en 3 y era demasiado: los nombres se buscan por prefijos cortos y el
     * desplegable, mientras tanto, decía «no se encontraron resultados» porque sólo
     * filtraba lo ya precargado. Si se sube, súbelo también en `SearchableSelect`
     * (`minCharsBusqueda`) o el usuario volverá a ver un vacío que miente.
     */
    const MIN_CHARS_BUSQUEDA = 2;

    // ============================================================================
    // 🔥 LÓGICA DE NEGOCIO: ENUMS (Replicado del Backend)
    // ============================================================================

    /**
     * Id "pelado" de cualquier cosa que identifique a un recurso: una IRI, un id
     * suelto o el propio objeto (mira `@id`, `id` y `tarifaId`, en ese orden).
     */
    const extractIdStr = (val: unknown): string => {
        if (!val) return '';
        if (typeof val === 'object') {
            const obj = val as RecursoHydra;
            const raw = obj['@id'] ?? obj.id ?? obj.tarifaId;
            if (raw) return String(raw).split('/').pop() || '';
        }
        return String(val).split('/').pop() || '';
    };

    const getTipoComponente = (compId: string | null): string => {
        if (!compId) return 'extras';
        const cleanId = extractIdStr(compId);

        const maestro = catalogos.value.allComponentes.find((c) => extractIdStr(c) === cleanId);

        return isComponenteCompleto(maestro) ? maestro.tipo : 'extras';
    };

    const tipoComponenteConfig = (tipo: string): ComponenteTipo | undefined =>
        catalogos.value.tiposComponente.find((t) => t.id === tipo.toLowerCase());

    // Compat: firma antigua, ahora derivada del flag negativo del enum.
    const requiereHoraExacta = (tipo?: string): boolean => {
        if(!tipo) return false;
        const config = tipoComponenteConfig(tipo);
        return config ? !config.sinHorario : false;   // ← flag negativo del enum
    };

    // Default del enum para un TIPO: ¿no lleva hora? (solo se consulta al enganchar un maestro).
    const sinHorarioDeTipo = (tipo?: string | null): boolean => {
        if (!tipo) return true; // sin tipo => como 'extras'
        const config = tipoComponenteConfig(tipo);
        return config ? config.sinHorario : true;
    };

    // Runtime: SIEMPRE lee el snapshot del componente, no el maestro.
    const componenteRequiereHora = (comp?: { sinHorario?: boolean | null } | null): boolean => !comp?.sinHorario;

    // ============================================================================
    // 🔥 HELPERS Y LÓGICA DE TIEMPO
    // ============================================================================

    const getFechaLimpia = (val: unknown): string => {
        if (!val) return new Date().toISOString().split('T')[0];
        const str = String(val);
        return str.includes('T') ? str.split('T')[0] : str;
    };

    const getHoraLimpia = (val: unknown): string | null => {
        if (!val) return null;
        const match = String(val).match(/(?:T|\s|^)([01]\d|2[0-3]):([0-5]\d)/);
        return match ? `${match[1]}:${match[2]}` : null;
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

    const calcularDiaRelativo = (fechaBase: string, fechaObjetivo: string): number => {
        const d1 = new Date(fechaBase + 'T12:00:00Z');
        const d2 = new Date(fechaObjetivo + 'T12:00:00Z');
        return Math.round((d2.getTime() - d1.getTime()) / (1000 * 60 * 60 * 24)) + 1;
    };

    /**
     * Relación de API Platform tal como puede llegar: una IRI suelta, un objeto
     * "flaco" (solo `@id`) o el recurso ya completo. `hydrateRelations()` las
     * normaliza a recursos completos.
     */
    type RelacionSinHidratar = string | (RecursoHydra & { nombreInterno?: unknown; titulo?: unknown; nombre?: unknown });

    /**
     * `T` es lo que el llamador sabe que va a recibir (Segmento, Tarifa…). No se
     * valida en runtime: el contrato lo da el endpoint del que cuelga la relación.
     */
    const hydrateRelations = async <T>(items: RelacionSinHidratar[] | null | undefined, endpointBase?: string): Promise<T[]> => {
        if (!items || !Array.isArray(items) || items.length === 0) return [];

        // Rama 1: array de IRIs string
        if (typeof items[0] === 'string') {
            return batchFetchByIds<T>(items as string[], endpointBase);
        }

        const objetos = items.filter((i): i is Exclude<RelacionSinHidratar, string> => typeof i === 'object');
        const primero = objetos[0];

        // Rama 2: array de objetos parciales (solo @id, sin datos completos)
        if (primero && primero['@id'] && !primero.nombreInterno && !primero.titulo && !primero.nombre) {
            // Los `.well-known/genid` son recursos anónimos (sin IRI resoluble): no
            // se pueden pedir al backend, se devuelven tal cual.
            const iris = objetos.map((obj) => obj['@id'] as string).filter((iri) => !iri.includes('.well-known/genid'));
            const genids = objetos.filter((obj) => obj['@id']?.includes('.well-known/genid'));

            if (iris.length === 0) return [...genids] as T[];

            const batched = await batchFetchByIds<T>(iris, endpointBase);
            return [...batched, ...(genids as T[])];
        }

        return items as T[];
    };

// Helper compartido por ambas ramas
    const batchFetchByIds = async <T>(iris: string[], endpointBase?: string): Promise<T[]> => {
        const base = endpointBase || iris[0].substring(0, iris[0].lastIndexOf('/'));
        const ids = iris.map(iri => iri.split('/').pop()!);

        try {
            const idsParam = ids.map(id => `id[]=${id}`).join('&');
            const res = await apiClient.get(`${base}?${idsParam}&pagination=false`);
            const items: T[] = res.data['hydra:member'] || res.data['member'] || [];

            // Mapeo O(n) para indexación rápida
            const porId = new Map(items.map((item) => [extractIdStr(item), item]));

            // Retorna respetando estrictamente el orden de entrada de los IRIs originales
            return ids.map(id => porId.get(id)).filter((item): item is T => !!item);
        } catch {
            // Fallback individual si el batch falla
            const promises = iris.map(iri => apiClient.get(iri).then(r => r.data as T).catch(() => iri as T));
            return Promise.all(promises);
        }
    };

    /** Título i18n de un maestro, o array vacío si no lo trae (nunca undefined). */
    /**
     * Un `nombreInterno` (string simple) puesto en el formato i18n que usa `nombreSnapshot`.
     *
     * `nombreSnapshot` pasó a ser el NOMBRE OPERATIVO (no el título): nace del `nombreInterno`
     * del servicio y, al aplicar plantilla, del de la plantilla. Como es operativo va en un solo
     * idioma —el español—, no traducido: al proveedor se le habla en uno.
     */
    const nombreOperativoComoI18n = (nombre: string | null | undefined): I18nContent[] =>
        nombre ? [{ language: 'es', content: nombre }] : [];

    const getTituloSafe = (entity: { titulo?: unknown } | null | undefined): I18nContent[] => {
        if (entity && Array.isArray(entity.titulo) && entity.titulo.length > 0) return entity.titulo as I18nContent[];
        return [];
    };

    /** Colección de un endpoint Hydra, con las dos formas de clave que devuelve API Platform. */
    const miembrosHydra = <T>(data: { 'hydra:member'?: T[]; member?: T[] } | null | undefined): T[] =>
        data?.['hydra:member'] || data?.member || [];

    /** ¿Ya está este recurso en la colección? Compara por id/IRI, no por referencia. */
    const yaEnColeccion = (coleccion: RecursoHydra[], recurso: RecursoHydra): boolean =>
        coleccion.some((x) => extractIdStr(x) === extractIdStr(recurso));

    /**
     * Suma la tarifa al pool global de maestras si no estaba. El pool es la fuente
     * que consulta el clasificador financiero para resolver `esGrupal` y demás
     * datos que el snapshot de la cotización no guarda.
     */
    const registrarTarifaMaestra = (tarifa: Tarifa): void => {
        if (!yaEnColeccion(todasLasTarifasMaestras.value, tarifa)) {
            todasLasTarifasMaestras.value.push(tarifa);
        }
    };

    const mapearItemASnapshot = async (item: Item): Promise<SnapshotItem> => {
        let tituloData: I18nContent[] = [];
        const dicc = item.diccionario;

        try {
            const res = await apiClient.get(dicc);
            tituloData = res.data.titulo || [];
        } catch (err) {
            console.error('No se pudo cargar el diccionario del item:', dicc, err);
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

    // Los campos i18n (incluidos los de `notas`) ya vienen corregidos en el tipo
    // `Segmento` del modelo; aquí solo se añade lo que el schema no declara.
    type SegmentoMaestro = Segmento & { '@id'?: string };

    /**
     * Relación Itinerario→Segmento del maestro. Según el grupo de serialización
     * llega como la relación (`{ segmento, dia }`) o como el segmento plano, de
     * ahí que ambas ramas se contemplen al aplicar una plantilla.
     */
    interface RelacionItinerarioSegmento {
        segmento?: SegmentoMaestro | string;
        dia?: number;
    }

    type ItinerarioProfundo = Omit<Itinerario, 'titulo'> & {
        '@id'?: string;
        titulo?: I18nContent[];
        segmentos?: RelacionItinerarioSegmento[];
        itinerarioSegmentos?: RelacionItinerarioSegmento[];
    };

    const extraerNotasSnapshot = (segmentoMaestro: SegmentoMaestro): NotaSnapshot[] => {
        const maestro = segmentoMaestro;

        if (!Array.isArray(maestro.notas)) return [];

        return maestro.notas.map((n): NotaSnapshot => ({
            id: crypto.randomUUID(),
            nombreInterno: n.nombreInterno,
            tipo: n.tipo || 'INFO',
            titulo: JSON.parse(JSON.stringify(n.titulo || [])),
            contenido: JSON.parse(JSON.stringify(n.contenido || []))
        }));
    };

    /**
     * Copia las imágenes del maestro al snapshot del segmento. Se mapea campo a
     * campo (y no con un JSON.parse a ciegas) porque en el maestro todos son
     * opcionales, mientras que el snapshot los da por presentes.
     */
    const extraerImagenesSnapshot = (segmentoMaestro: SegmentoMaestro): ImagenSnapshot[] => {
        if (!Array.isArray(segmentoMaestro.imagenes)) return [];

        return segmentoMaestro.imagenes.map((img): ImagenSnapshot => ({
            orden: img.orden ?? 0,
            imageUrl: img.imageUrl ?? '',
            imageName: img.imageName ?? '',
            imageSize: img.imageSize ?? 0,
            isPortada: img.isPortada ?? false,
        }));
    };

    // Sin `lang`: la etiqueta se arma con `nombreInterno`, que no está traducido.
    const getTarifaLabel = (cat: TarifaLike): string => {
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

    /**
     * De qué proveedor es cada tarifa del catálogo, por id de tarifa.
     *
     * ⚠️ La tarifa ya NO tiene proveedor propio: se mudó al COMPONENTE maestro. Así que «el
     * proveedor de esta tarifa» se resuelve a través del componente del que cuelga, y por eso
     * hay que recorrer `allComponentes` en vez de mirar la tarifa. Es el mismo camino que usa
     * el filtro blando del selector.
     */
    /**
     * Los tres papeles de cada tarifa del catálogo, indexados por su id.
     *
     * Un solo mapa para los tres: son el mismo dato leído del mismo sitio, y tenerlos
     * separados garantizaba que un día uno se actualizara y el otro no.
     */
    const papelesPorTarifa = computed<Map<string, PapelesDeTarifa>>(() => {
        const mapa = new Map<string, PapelesDeTarifa>();

        catalogos.value.allComponentes.forEach(c => {
            const tarifas = ('tarifas' in c ? c.tarifas ?? [] : []) as TarifaLike[];

            tarifas.forEach(t => {
                const tid = extractIdStr(t);
                if (!tid) return;

                // Desde la TARIFA, no desde el componente: el componente dejó de fijar
                // prestador el 20/08/2026 porque puede tener tarifas de empresas distintas.
                // Ver `docs/Travel.md` §11.
                //
                // ⚠️ Esto estuvo leyendo `c.proveedor` hasta hoy, y ese campo se llamaba ya
                // `prestador` desde el renombrado del 19/08: la guarda `'proveedor' in c` era
                // SIEMPRE falsa, el mapa quedaba vacío y de él colgaban en silencio el
                // subtítulo del selector y el snapshot entero. Una cadena que no mira ningún
                // compilador.
                mapa.set(tid, papelesDeTarifaMaestra(t));
            });
        });

        return mapa;
    });

    /** Los tres papeles de una tarifa del catálogo. Vacíos si no está definida. */
    const getPapelesDeTarifa = (t: TarifaLike | string | null | undefined): PapelesDeTarifa | null => {
        const id = extractIdStr(t as string);
        return id ? (papelesPorTarifa.value.get(id) ?? null) : null;
    };

    /**
     * El prestador de una tarifa del catálogo, o null si no lo tiene.
     *
     * Se queda como atajo del anterior porque media vista lo llama así, y porque la pregunta
     * «¿de quién es este precio?» merece una respuesta corta.
     */
    const getProveedorDeTarifa = (t: TarifaLike | string | null | undefined): { id: string; nombre: string } | null => {
        const p = getPapelesDeTarifa(t);
        if (!p?.prestadorMaestroId && !p?.prestadorNombreSnapshot) return null;

        return { id: p.prestadorMaestroId ?? '', nombre: p.prestadorNombreSnapshot ?? '' };
    };

    /**
     * La SEGUNDA línea de una opción de tarifa: proveedor · procedencia · edad.
     *
     * Iba todo apelotonado en `getTarifaLabel()` y en el móvil se cortaba a la mitad. El
     * proveedor —que es lo que evita colgar de un componente la tarifa de otro— no llegaba a
     * verse nunca. Separarlo en dos líneas es lo que hace que quepa.
     */
    const getTarifaSublabel = (t: TarifaLike): string => {
        const partes: string[] = [];

        // El proveedor primero: es lo que decide si esta tarifa es la que toca.
        const prov = getProveedorDeTarifa(t);
        if (prov?.nombre) partes.push(prov.nombre);

        // Luego las CONDICIONES, que es lo que hace que dos tarifas del mismo servicio no
        // sean intercambiables. Sin verlas, elegir entre «Base 2 Pax» y «Base 3 Pax» es
        // adivinar: el nombre interno no dice para quién vale ninguna.
        const modalidad = getModalidadTarifa(t);
        if (modalidad && MODALIDAD_CONFIG[modalidad]) {
            partes.push(`${MODALIDAD_CONFIG[modalidad].icon} ${MODALIDAD_CONFIG[modalidad].label}`);
        }

        const categoria = getCategoriaTarifa(t);
        if (categoria && CATEGORIA_CONFIG[categoria]) {
            partes.push(`${CATEGORIA_CONFIG[categoria].icon} ${CATEGORIA_CONFIG[categoria].label}`);
        }

        const proc = getProcedenciaTarifa(t);
        if (proc) {
            const ui = getProcedenciaUI(proc);
            partes.push(`${ui.icon} ${ui.label}`);
        }

        const edad = formatRangoEdad(getEdadMinimaTarifa(t), getEdadMaximaTarifa(t));
        if (edad) partes.push(`🎂 ${edad}`);

        return partes.join(' · ');
    };

    const calcularPernoctes = (inicioStr: string, finStr: string): number => {
        if (!inicioStr || !finStr) return 1;
        const DIA = 24 * 60 * 60 * 1000;
        const s = Math.floor(parseNaiveAsUTC(inicioStr) / DIA);
        const e = Math.floor(parseNaiveAsUTC(finStr) / DIA);
        const diff = e - s;
        return diff > 0 ? diff : 1;
    };
    const getI18nText = (arrayI18n: I18nContent[] | undefined, lang: string): string => {
        if (!arrayI18n || !Array.isArray(arrayI18n)) return '';
        const found = arrayI18n.find(item => item.language === lang);
        return found ? found.content : '';
    };

    const setI18nText = (arrayI18n: I18nContent[] | undefined, lang: string, text: string): void => {
        if (!arrayI18n || !Array.isArray(arrayI18n)) return;
        const found = arrayI18n.find((item) => item.language === lang);
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
                (cat) => extractIdStr(cat.tarifaId || (cat as Record<string, unknown>)['@id']) === extractIdStr(t.tarifaMaestraId)
            );
            return maestro ? getEsGrupalTarifa(maestro) : false;
        };

        const grupos = new Map<number, { pax: number; grupal: boolean }>();
        for (const t of tarifas) {
            if (t.rolSnapshot === 'operativo' || t.grupoTarifa == null) continue;
            const acc = grupos.get(t.grupoTarifa) || { pax: 0, grupal: false };
            if (resolverGrupal(t)) acc.grupal = true;
            else acc.pax += unidadesDe(t.cantidad, 0);
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

            const reqA = componenteRequiereHora(a);
            const reqB = componenteRequiereHora(b);

            if (reqA && !reqB) return -1;
            if (!reqA && reqB) return 1;

            return valA.localeCompare(valB);
        });
    };

    const sincronizarFechaServicio = (servicio: CotServicio | null | undefined): void => {
        if (!servicio || !servicio.cotcomponentes || servicio.cotcomponentes.length === 0) return;

        let fechaMinima = '9999-12-31T23:59:59';

        servicio.cotcomponentes.forEach((c: ComponenteCompleto) => {
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
        if (typeof val === 'object' && val !== null) {
            const obj = val as Record<string, unknown>;
            return normalizarCodigoMoneda(obj.id ?? 'USD');
        }
        return String(val).trim().toUpperCase() || 'USD';
    };

    const isComponenteBloqueado = (comp: ComponenteCompleto | null | undefined): boolean => {
        if (!comp) return false;

        // Bloqueado si pertenece a un segmento del itinerario (storytelling)
        if (comp.cotsegmentoId || comp.cotsegmento) return true;

        // Bloqueado si es un componente inyectado como logística adicional (upsell)
        if (comp.upsellSourceItemId) return true;

        const servicio = findServicioByComponenteId(comp.id);
        if (servicio && servicio.cotcomponentes) {
            return servicio.cotcomponentes.some((cPadre: ComponenteCompleto) =>
                cPadre.snapshotItems?.some((item: SnapshotItem) => item.idComponenteInyectado === comp.id)
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
                (cat) => extractIdStr(cat.tarifaId || (cat as Record<string, unknown>)['@id']) === extractIdStr(t.tarifaMaestraId)
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

        // Nombre INTERNO del componente (siempre presente): sale del componente
        // maestro. El nombreSnapshot es sólo el título público (opcional, para el
        // cliente). Sólo lectura del catálogo; no dispara fetch dentro del computed.
        const nombreInternoDeComponente = (componente: ComponenteCompleto): string => {
            const maestroId = componente.componenteMaestroId ? extractIdStr(componente.componenteMaestroId) : '';
            if (maestroId) {
                const maestro = catalogos.value.allComponentes.find((c) => extractIdStr(c) === maestroId);
                // `nombre` existe tanto en el maestro completo como en el placeholder.
                const nombre = maestro?.nombre ?? '';
                if (nombre && nombre !== 'Sincronizando...') return nombre;
            }
            // Fallbacks internos: título override del snapshot, luego segmento.
            const snap = getI18nText(componente.nombreSnapshot, idiomaEdicion);
            if (snap) return snap;
            const seg = componente.cotsegmento;
            if (seg && typeof seg === 'object' && Array.isArray((seg as CotSegmento).nombreSnapshot)) {
                return getI18nText((seg as CotSegmento).nombreSnapshot as I18nContent[], idiomaEdicion);
            }
            return '';
        };

        // Título PÚBLICO del componente para el cliente (nunca nombre interno ni
        // segmento): si el componente tiene título público (nombreSnapshot) se usa;
        // si no, se arma con los primeros 3 ítems INCLUIDOS unidos por " · ", por
        // idioma. Devuelve I18nContent[] para respetar traducciones.
        const tituloClienteDeComponente = (componente: ComponenteCompleto): I18nContent[] => {
            if (componente.nombreSnapshot?.length) return componente.nombreSnapshot;
            const items = (componente.snapshotItems || [])
                .filter((it) => (it.modo || '').toLowerCase() === 'incluido' || it.incluido)
                .slice(0, 3);
            if (!items.length) return [];
            // Idiomas presentes en cualquiera de los ítems.
            const idiomas = new Set<string>();
            items.forEach((it) => (it.nombreSnapshot || []).forEach((c) => idiomas.add(c.language)));
            if (!idiomas.size) return [];
            return [...idiomas].map((language) => ({
                language,
                content: items
                    .map((it) => getI18nText(it.nombreSnapshot, language))
                    .filter(Boolean)
                    .join(' · ')
            }));
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
                // Nombre propio del componente (sin fallback al segmento): en vistas
                // internas las alternativas prefieren el nombreInterno de la tarifa
                // antes que caer al título del segmento contenedor.
                const compNombrePropio = componente.nombreSnapshot?.length ? componente.nombreSnapshot : [];
                const compNombreInterno = nombreInternoDeComponente(componente);
                // Datos client-safe para el snapshot que consume la vista pax.
                const compTituloCliente = tituloClienteDeComponente(componente);
                // Herencia de atributos de tarifa → ítems, gateada por flags. Criterio
                // permisivo: se muestra en la tarjeta si AL MENOS un ítem lo permite.
                // Sin ítems, se muestra por defecto (no hay a quién ocultarlo).
                const itemsComp = componente.snapshotItems || [];
                const mostrarTituloCliente = !itemsComp.length || itemsComp.some((it) => it.tituloTarifaVisible);
                const mostrarModalidadCliente = !itemsComp.length || itemsComp.some((it) => it.modalidadTarifaVisible);
                const mostrarCategoriaCliente = !itemsComp.length || itemsComp.some((it) => it.categoriaTarifaVisible);
                const compLabel = getI18nText(compNombre, idiomaEdicion) || 'Insumo Logístico';
                const cCant = unidadesDe(componente.cantidad);
                const fecha = getFechaLimpia(componente.fechaHoraInicio);

                const lineas: LineaVoter[] = [];
                let paxEstandar = 0;

                (componente.cottarifas || []).forEach((t: TarifaSnapshot) => {
                    // req 1: el rol sólo aplica bajo modo 'incluido'. En cualquier otro
                    // modo manda el modo del componente y la 'alternativa' se trata como
                    // estándar (así el preview ya refleja lo que se normalizará al guardar).
                    const rolCrudo = t.rolSnapshot || 'estandar';
                    const rol = (modoFin !== 'incluido' && rolCrudo === 'alternativa')
                        ? 'estandar'
                        : rolCrudo;
                    if (rol === 'alternativa') return;   // → opcionesUpgrade

                    const esGrupal = resolverGrupal(t);
                    const tCant = unidadesDe(t.cantidad);
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
                            montoCosto: String(t.montoCosto || '0'),
                            moneda,
                            esGrupal,
                            cantidad: tCant,
                            cantidadComponente: cCant,
                            modo: modoFin,
                            fecha,
                            modalidad: t.modalidadSnapshot || null, // Se extrae pacíficamente, no es estrictamente obligatorio
                            categoria: t.categoriaSnapshot || null, // Se extrae pacíficamente, no es estrictamente obligatorio
                            procedencia: t.procedenciaSnapshot || null,
                            edadMin: t.edadMinimaSnapshot ?? null,
                            edadMax: t.edadMaximaSnapshot ?? null,
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
                    const hayEstandar = estandares.length > 0;

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
                        const pax = resolverGrupal(t) ? numPaxGlobal : unidadesDe(t.cantidad);
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
                                sumaPaxAlt += unidadesDe(t.cantidad);
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

                            const etiquetaGrupo = etiquetaGrupoTarifa(grupo, hayEstandar);

                            opcionesUpgrade.push({
                                componenteId: extractIdStr(componente.id),
                                grupoTarifa: grupo,
                                grupoLabel: etiquetaGrupo.label,
                                esOpcion: etiquetaGrupo.tipo === 'opcion',
                                componenteNombre: compNombrePropio,
                                componenteNombreInterno: compNombreInterno || null,
                                componenteNombreCliente: compTituloCliente,
                                mostrarTituloCliente,
                                mostrarModalidadCliente,
                                mostrarCategoriaCliente,
                                servicioId,
                                servicioNombre,
                                tarifaTitulo: t.tituloSnapshot || [],
                                tarifaNombreInterno: t.nombreInternoSnapshot || null,
                                tieneEstandarEspejo: !!std,
                                estandarTitulo: std?.tituloSnapshot || [],
                                estandarNombreInterno: std?.nombreInternoSnapshot || null,
                                estandarModalidad: std?.modalidadSnapshot || null,
                                estandarCategoria: std?.categoriaSnapshot || null,
                                notaRol: t.notaRol || [],
                                modalidad: t.modalidadSnapshot || null,
                                categoria: t.categoriaSnapshot || null,
                                procedencia: t.procedenciaSnapshot || null,
                                edadMin: t.edadMinimaSnapshot ?? null,
                                edadMax: t.edadMaximaSnapshot ?? null,
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
            // ⚠️ COBRAR DE MENOS TAMBIÉN ES UN CONFLICTO. Si tras repartir un componente queda
            // alguna clase real sin cubrir, hay pax por los que no se cobra nada — típicamente
            // porque se subió `numPax` DESPUÉS de asignar las tarifas y nadie las reescaló.
            //
            // Hasta ahora la asimetría era la peor posible: asignar de MÁS disparaba la clase
            // «⚠️ CONFLICTO» y bloqueaba publicar, mientras que asignar de MENOS se publicaba en
            // silencio con el total de los pax viejos. Cobrar de menos no se descubre nunca: no
            // hay cliente que reclame por pagar poco.
            const sinCubrir = clases.filter((c) => c.isReal && c.cantidadRestante > 0);
            const tuvoLineasPorPax = lineas.some((l) => !l.esGrupal);

            if (tuvoLineasPorPax && sinCubrir.length > 0) {
                const nombreComp = lineas[0]?.base.componenteNombre ?? '';
                const detalle = sinCubrir
                    .map((c) => `${c.cantidadRestante} de ${c.tipoPaxNombre}`)
                    .join(', ');

                advertencias.push(
                    `«${nombreComp}» no cubre a todos los pasajeros: quedan sin tarifa ${detalle}. `
                    + 'Se está cobrando de menos. Suele pasar al subir el número de pax después de '
                    + 'asignar las tarifas: revísalas.'
                );
            }

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
        // Una sola fuente de verdad: la misma resta que hace el backend. La fórmula anterior
        // —sumar las dos ganancias de bucket— daba el mismo número, pero era una segunda forma de
        // calcularlo, y dos formas es como se llega a dos resultados distintos.
        const ganancia = buckets.incluido.ventaDolares
            - (buckets.incluido.costoDolares + buckets.cortesia.costoDolares);

        return {
            schemaVersion: CLASIFICACION_SCHEMA_VERSION,
            generatedAt: new Date().toISOString(),
            numPax: numPaxGlobal,
            tipoCambio: tc,
            precioOculto: !!cotizacion.value.precioOculto,
            comisionGlobal,
            // ⚠️ EL COSTO INCLUYE LAS CORTESÍAS; LA VENTA NO.
            //
            // Una cortesía cuesta —se le paga al proveedor igual— pero no vende. Contando así,
            // `venta − costo` resta la cortesía sola, sin necesidad de una fórmula aparte: es lo
            // que hace `Cotizacion::getGanancia()` en el backend, y por eso los dos números
            // coinciden.
            //
            // Antes el costo era sólo el del bucket incluido, así que el coste de las cortesías
            // desaparecía y el panel mostraba una ganancia optimista: con venta $1.000, costo
            // $800 y una cortesía de $120, el editor decía $80 y el panel $200. Dos verdades
            // para la misma cotización, y el operador decidía precios mirando la buena.
            totalCostoNeto: buckets.incluido.costoDolares + buckets.cortesia.costoDolares,
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
    /**
     * El prestador del componente: enlace y nombre, que es todo lo que guarda.
     *
     * El editor no necesita la ficha —título, fotos— para trabajar: eso lo resuelve el
     * backend contra el catálogo al servir la propuesta. Aquí basta con saber quién es.
     */
    const resolverPrestador = (
        componente: ComponenteCompleto
    ): { maestroId: string | null; nombre: string | null } | null => {
        const tieneMaestro = Boolean(componente.prestadorMaestroId);
        const tieneNombre = (componente.prestadorNombreSnapshot || '').trim() !== '';

        if (!tieneMaestro && !tieneNombre) return null;

        return {
            maestroId: componente.prestadorMaestroId ?? null,
            nombre: componente.prestadorNombreSnapshot ?? null
        };
    };

    const construirInclusiones = (advertencias: string[]): InclusionServicio[] => {
        if (!cotizacion.value?.cotservicios) return [];
        const idiomaEdicion = cotizacion.value.idiomaEdicion || 'es';
        const resultado: InclusionServicio[] = [];

        // `advertencias` es el MISMO array que alimenta `publicable`, así que todo lo
        // que se empuje aquí bloquea guardar en enviado/confirmado/operado. Esta función
        // recorre servicios→componentes directamente, sin pasar por el votante, y por eso
        // ve cosas que el cálculo financiero no puede ver: componentes que se publican
        // distinto de como se cotizaron, o que no se publican en absoluto.
        //
        // El listón para añadir una advertencia aquí es alto y es siempre el mismo:
        // **el cliente vería una propuesta distinta de la que se quiso vender.** No sirve
        // para avisos de estilo ni para recordatorios.
        const avisar = (servicioLabel: string, compLabel: string, texto: string): void => {
            advertencias.push(`"${servicioLabel} ➔ ${compLabel}": ${texto}`);
        };

        // Los cuatro que `destino()` sabe repartir. Cualquier otro cae en "Incluye".
        const MODOS_ITEM_VALIDOS = ['incluido', 'no_incluido', 'cortesia', 'opcional'];

        const serviciosOrden = [...cotizacion.value.cotservicios]
            .sort((a, b) => getFechaLimpia(a.fechaInicioAbsoluta).localeCompare(getFechaLimpia(b.fechaInicioAbsoluta)));

        serviciosOrden.forEach((servicio) => {
            const servicioLabel = getI18nText(
                servicio.nombrePublicoSnapshot?.length ? servicio.nombrePublicoSnapshot : (servicio.nombreSnapshot || []),
                idiomaEdicion
            ) || 'Servicio';

            const bloque: InclusionServicio = {
                servicioId: extractIdStr(servicio.id),
                servicioNombre: servicio.nombrePublicoSnapshot?.length
                    ? servicio.nombrePublicoSnapshot : (servicio.nombreSnapshot || []),
                incluidos: [], noIncluidos: [], cortesias: [], opcionales: []
            };

            // ⚠️ El `else` final es un cajón de sastre: cualquier modo que no reconozca
            // acaba en "Incluye". Por eso existe MODOS_ITEM_VALIDOS — para que un modo
            // inesperado avise en vez de colarse como incluido.
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
                const cCant = unidadesDe(componente.cantidad);
                const tieneNombre = !!componente.nombreSnapshot?.length;
                const items = componente.snapshotItems || [];
                const compLabel = getI18nText(componente.nombreSnapshot, idiomaEdicion) || 'Insumo Logístico';

                // Sin nombre propio y sin ítems no se genera ni una línea: el componente
                // desaparece de la propuesta. Si además lleva tarifa, es costo que el
                // cliente paga sin ver a cambio de qué.
                if (!tieneNombre && items.length === 0) {
                    avisar(servicioLabel, compLabel,
                        'no tiene título público ni ítems, así que no aparece en la propuesta. '
                        + 'Ponle un título o añádele ítems.');
                }

                // Tarifa estándar visible (fuente de herencia para items y línea propia)
                const estandares = (componente.cottarifas || []).filter(
                    (t: TarifaSnapshot) => (t.rolSnapshot || 'estandar') === 'estandar'
                );
                const hayEstandar = estandares.length > 0;
                const tarifaRef = estandares[0] || null;

                const mapearTarifaInclusion = (t: TarifaSnapshot): InclusionTarifa => ({
                    tarifaTitulo: t.tituloSnapshot || [],
                    cantidad: unidadesDe(t.cantidad),
                    esGrupal: t.esGrupal,
                    modalidad: t.modalidadSnapshot || null,
                    categoria: t.categoriaSnapshot || null,
                    procedencia: t.procedenciaSnapshot || null,
                    edadMin: t.edadMinimaSnapshot ?? null,
                    edadMax: t.edadMaximaSnapshot ?? null,
                    rol: (t.rolSnapshot || 'estandar') as TarifaRolValue,
                    notaRol: t.notaRol || [],
                    montoCotizado: String(t.montoCosto || '0'),   // `limpiarMontoInclusion` lo pone a null
                    moneda: String(t.moneda || 'USD')
                });

                // Línea del COMPONENTE (casos 2 y 3): solo si tiene nombre propio
                if (tieneNombre) {
                    if (modo === 'incluido' && !hayEstandar) {
                        // req 3: componente incluido SIN estándar → no aparece en "Incluye".
                        // Sus grupos de tarifas se muestran como "Opcional" etiquetados
                        // "Opción N" (blindaje req 4: grupoTarifa nulo → 0).
                        const opcionables = (componente.cottarifas || []).filter(
                            (t: TarifaSnapshot) => (t.rolSnapshot || 'estandar') !== 'operativo'
                        );
                        // Un componente marcado INCLUIDO sin tarifa estándar es una
                        // contradicción: está en el paquete pero no tiene precio base. El
                        // renderizado la resuelve con elegancia —lo publica como «Opción N»
                        // en Opcional— y ahí está el peligro: el cliente recibe como
                        // elegible algo que se le vendió como incluido, sin que nadie lo note.
                        if (opcionables.length > 0) {
                            avisar(servicioLabel, compLabel,
                                'está marcado como Incluido pero no tiene tarifa estándar, '
                                + 'así que el cliente lo verá como Opcional. Marca una tarifa como estándar '
                                + 'o cambia el modo del componente.');
                        } else {
                            // Ni estándar ni alternativas: no se publica absolutamente nada.
                            // "Publicable" y no "ninguna": `opcionables` descarta las de rol
                            // operativo, que existen pero nunca se le enseñan al cliente.
                            avisar(servicioLabel, compLabel,
                                'está marcado como Incluido y no tiene ninguna tarifa publicable, '
                                + 'así que no aparece en la propuesta.');
                        }

                        const grupos = new Map<number, TarifaSnapshot[]>();
                        opcionables.forEach((t: TarifaSnapshot) => {
                            const g = t.grupoTarifa ?? 0;
                            if (!grupos.has(g)) grupos.set(g, []);
                            grupos.get(g)!.push(t);
                        });
                        [...grupos.entries()]
                            .sort((a, b) => a[0] - b[0])
                            .forEach(([g, tarifasGrupo]) => {
                                const ref = tarifasGrupo[0] || null;
                                bloque.opcionales.push({
                                    origen: 'componente',
                                    modo: 'opcional',
                                    nombre: componente.nombreSnapshot,
                                    grupoOpcion: etiquetaGrupoTarifa(g, false).indice,
                                    fecha,
                                    cantidadComponente: cCant,
                                    modalidad: ref?.modalidadSnapshot || null,
                                    categoria: ref?.categoriaSnapshot || null,
                                    procedencia: ref?.procedenciaSnapshot || null,
                                    edadMin: ref?.edadMinimaSnapshot ?? null,
                                    edadMax: ref?.edadMaximaSnapshot ?? null,
                                    tarifaTitulo: [],
                                    tarifas: tarifasGrupo.map(mapearTarifaInclusion)
                                });
                            });
                    } else {
                        // El prestador viaja si ESTA cotización decidió nombrarlo. Antes
                        // se preguntaba aquí `modo === 'no_incluido'`, y esa re-derivación
                        // era el defecto: reclasificar el componente cambiaba la propuesta
                        // del cliente sin que nadie lo pidiera. La regla sigue viva como
                        // DEFAULT al asignar (ver onPrestadorComponenteChange); aquí sólo
                        // se lee lo decidido. El backend vuelve a filtrar con la misma
                        // bandera en CotizacionCotcomponentePrestadorPublicNormalizer.
                        const prestador = componente.prestadorVisible
                            ? resolverPrestador(componente)
                            : null;


                        destino(modo).push({
                            origen: 'componente',
                            modo: modo as ModoFinanciero,
                            nombre: componente.nombreSnapshot,
                            fecha,
                            cantidadComponente: cCant,
                            modalidad: tarifaRef?.modalidadSnapshot || null,
                            categoria: tarifaRef?.categoriaSnapshot || null,
                            procedencia: tarifaRef?.procedenciaSnapshot || null,
                            edadMin: tarifaRef?.edadMinimaSnapshot ?? null,
                            edadMax: tarifaRef?.edadMaximaSnapshot ?? null,
                            tarifaTitulo: [],
                            tarifas: estandares.map(mapearTarifaInclusion),
                            // Sólo el nombre histórico: la ficha del prestador la hidrata
                            // pax en lote contra el catálogo, no viaja repetida por línea.
                            prestadorNombre: prestador?.nombre,
                            // El proveedor NO se copia aquí: sería una foto. Sólo viaja el
                            // id, y pax lee el proveedor del componente vivo, que el
                            // backend resuelve contra el maestro al servir.
                            componenteId: componente.id
                        });
                    }
                }

                // Líneas de ITEMS aplanadas (casos 1 y 3): cada item con su propio modo
                items.forEach((item: SnapshotItem) => {
                    const modoItem = (item.modo || 'incluido').toLowerCase();

                    // `destino()` manda a "Incluye" todo lo que no reconoce. Un modo con
                    // una errata deja de ser opcional y pasa a estar incluido, en silencio
                    // y a favor del cliente: acabas regalando lo que querías cobrar aparte.
                    if (!MODOS_ITEM_VALIDOS.includes(modoItem)) {
                        avisar(servicioLabel, compLabel,
                            `el ítem "${getI18nText(item.nombreSnapshot, idiomaEdicion) || 'sin nombre'}" `
                            + `tiene el modo desconocido "${modoItem}" y se publicará como Incluido.`);
                    }

                    destino(modoItem).push({
                        origen: 'item',
                        modo: modoItem as InclusionLinea['modo'],
                        nombre: item.nombreSnapshot,
                        fecha,
                        cantidadComponente: 1,
                        // Herencia condicional por flags desde la tarifa estándar del contenedor
                        modalidad: item.modalidadTarifaVisible ? (tarifaRef?.modalidadSnapshot || null) : null,
                        categoria: item.categoriaTarifaVisible ? (tarifaRef?.categoriaSnapshot || null) : null,
                        // Procedencia/edad heredan la misma puerta que la categoría (no tienen flag propio).
                        procedencia: item.categoriaTarifaVisible ? (tarifaRef?.procedenciaSnapshot || null) : null,
                        edadMin: item.categoriaTarifaVisible ? (tarifaRef?.edadMinimaSnapshot ?? null) : null,
                        edadMax: item.categoriaTarifaVisible ? (tarifaRef?.edadMaximaSnapshot ?? null) : null,
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

    /**
     * Opciones de upgrade agrupadas por escenario ("Alternativa 1/2", "Opción N").
     * Fuente única que consumen tanto el Reporte Financiero como los paneles de
     * Desglose (Análisis por Perfil). Todas las opciones del mismo grupoLabel
     * trabajan juntas aunque provengan de componentes distintos.
     */
    const gruposUpgrade = computed<{ label: string; esOpcion: boolean; opciones: OpcionUpgradeInterna[] }[]>(() => {
        const list = resumenFinanciero.value?.opcionesUpgrade || [];
        const mapa = new Map<string, { label: string; esOpcion: boolean; opciones: OpcionUpgradeInterna[] }>();
        list.forEach((o) => {
            const key = o.grupoLabel || (o.esOpcion ? `Opción ${o.grupoTarifa}` : `Alternativa ${o.grupoTarifa}`);
            if (!mapa.has(key)) mapa.set(key, { label: key, esOpcion: o.esOpcion, opciones: [] });
            mapa.get(key)!.opciones.push(o);
        });
        return [...mapa.values()].sort((a, b) => a.label.localeCompare(b.label, 'es', { numeric: true }));
    });

    const itinerarioDinamico = computed(() => {
        if (!cotizacion.value || !cotizacion.value.cotservicios) return [];

        const todosLosServicios = [...cotizacion.value.cotservicios];

        todosLosServicios.sort((a: CotServicio, b: CotServicio) => {
            const dateA = getFechaLimpia(a.fechaInicioAbsoluta) || '9999-12-31';
            const dateB = getFechaLimpia(b.fechaInicioAbsoluta) || '9999-12-31';
            return dateA.localeCompare(dateB);
        });

        const grupos: Record<string, CotServicio[]> = {};
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
                if (componenteRequiereHora(c) && c.fechaHoraInicio) {
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

    const inicializarEditor = async (fileId: string, cotizacionId: string, esCatalogo = false) => {
        if (!fileId) return;

        modoCatalogo.value = esCatalogo;
        isLoading.value = true;
        try {
            try {
                const tcResponse = await apiClient.post('/platform/maestro/tipo-cambio/consultar', { fecha: getFechaLimpia(new Date().toISOString()) });
                const tc = parseFloat(tcResponse.data.promedio);

                // ⚠️ UN TIPO DE CAMBIO DE 1 NO ES UN VALOR POR DEFECTO, ES UN ERROR.
                //
                // Este `catch` estaba VACÍO, así que si el endpoint fallaba la cotización nacía
                // con `tipoCambio: 1` y toda tarifa en soles se valoraba 1:1 — S/350 pasaban a
                // ser $350 en vez de ~$95. Un número perfectamente plausible, 3,7 veces mal, y
                // ni un aviso. Con 72 de las tarifas del catálogo en PEN, es de las cosas que se
                // descubren en la factura.
                //
                // Se avisa en vez de callar: si no hay tipo de cambio, es el operador quien tiene
                // que ponerlo a mano antes de seguir.
                if (!Number.isFinite(tc) || tc <= 0) {
                    throw new Error('el servicio devolvió un valor no utilizable');
                }

                tipoCambioSugerido.value = tc;
            } catch (err) {
                console.error('No se pudo obtener el tipo de cambio del día:', err);
                alert(
                    'No se pudo obtener el tipo de cambio del día.\n\n'
                    + 'Las tarifas en soles se valorarían 1:1 con el dólar, que NO es correcto. '
                    + 'Revisa el tipo de cambio de la cotización y ponlo a mano antes de guardar.'
                );
            }

            await fetchIdiomas();
            await fetchCatalogos();

            if (esCatalogo) {
                const catRes = await apiClient.get(`/platform/sales/cotizacion_catalogos/${fileId}`);
                // El editor lee nombreGrupo para la cabecera; el catálogo usa "nombre"
                fileActual.value = { ...catRes.data, nombreGrupo: catRes.data.nombre };
            } else {
                const fileRes = await apiClient.get(`/platform/sales/cotizacion_files/${fileId}`);
                fileActual.value = fileRes.data;
            }

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
        } catch {
            idiomasDisponibles.value = [{ id: 'es', nombre: 'Español', bandera: '🇪🇸', prioridad: 1 }];
        }
    };

    const fetchCatalogos = async () => {
        try {
            const [resTipos, resMonedas] = await Promise.all([
                apiClient.get('/tipo/user/enum/travel/componente-tipos'),
                apiClient.get('/platform/maestro/monedas'),
            ]);

            catalogos.value.tiposComponente = resTipos.data || [];
            catalogos.value.monedas = resMonedas.data['hydra:member'] || resMonedas.data['member'] || [];

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

            miembrosHydra<Servicio>(res.data).forEach((item) => {
                // Validar que no exista ya en memoria
                if (!yaEnColeccion(catalogos.value.servicios, item)) {
                    catalogos.value.servicios.push(item);
                }
            });
        } catch (e) {
            console.error("Error buscando servicios en catálogo", e);
        }
    };

    const buscarProveedoresAsincrono = async (query: string) => {
        // 2 y no 3: los proveedores se buscan por prefijos cortos («Ga» → Gabrie) y con el
        // umbral en 3 la petición no salía nunca, mientras el desplegable decía «no se
        // encontraron resultados» filtrando sólo la lista precargada. Ver MIN_CHARS_BUSQUEDA.
        if (!query || query.trim().length < MIN_CHARS_BUSQUEDA) return;

        try {
            // Asumiendo que quieres buscar por nombre comercial
            const res = await apiClient.get(`/platform/travel/organizaciones?nombreComercial=${encodeURIComponent(query)}`);

            miembrosHydra<Organizacion>(res.data).forEach((item) => {
                if (!yaEnColeccion(catalogos.value.proveedores, item)) {
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
                const hydratedComps = await hydrateRelations<Componente>(data.componentes);
                catalogos.value.componentes = hydratedComps;

                const idsParaDetalle: string[] = [];
                hydratedComps.forEach((c) => {
                    const targetId = extractIdStr(c);
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
                        miembrosHydra<Componente>(resDetalle.data).forEach((detalle) => {
                            const detalleId = extractIdStr(detalle);
                            const idx = catalogos.value.allComponentes.findIndex(c => extractIdStr(c) === detalleId);
                            if (idx !== -1) {
                                // Reemplazamos el objeto liviano por el completo (con tarifas, componenteItems)
                                catalogos.value.allComponentes.splice(idx, 1, detalle);
                            }

                            // Precargamos también las tarifas maestras en el pool global
                            (detalle.tarifas || []).forEach(registrarTarifaMaestra);
                        });
                    } catch (e) {
                        console.error('No se pudo precargar el detalle de componentes en batch', e);
                    }
                }
            } else {
                catalogos.value.componentes = [];
            }

            const [plantillas, pool] = await Promise.all([
                hydrateRelations<Itinerario>(data.itinerarios || []),
                hydrateRelations<Segmento>(data.segmentos || [])
            ]);
            if (gen !== undefined && gen !== navGen) return;
            catalogos.value.plantillasItinerario = plantillas;
            catalogos.value.poolSegmentos = pool;
        } catch {
            // Catálogo de apoyo para el panel lateral. Si falla, el editor abre igual con la
            // cotización cargada y el pool vacío; lo que no puede pasar es que un maestro
            // caído impida abrir una cotización que ya está en pantalla.
        }
    };

    const fetchComponenteDetalles = async (componenteIriOrId: string, gen?: number) => {
        const componente = componenteActivo.value;
        const id = extractIdStr(componenteIriOrId);
        const existing = catalogos.value.allComponentes.find(c => extractIdStr(c) === id);

        // 🔥 Si el componente ya tiene tarifas cargadas (señal de que ya se completó antes),
        // no volvemos a pedir nada al servidor.
        const yaCompleto = isComponenteCompleto(existing) && Array.isArray(existing.tarifas);

        if (yaCompleto) {
            const detalle = existing as Componente;
            catalogos.value.tarifas = detalle.tarifas || [];

            detalle.tarifas?.forEach(registrarTarifaMaestra);

            if (componente && inspectorActivo.value === 'componente') {
                const itemsRaw: Item[] = detalle.componenteItems ?? [];   // ó fetchedComp.componenteItems
                if (!componente.snapshotItems || componente.snapshotItems.length === 0) {
                    if (gen !== undefined && gen !== navGen) return;
                    componente.snapshotItems = await Promise.all(itemsRaw.map(mapearItemASnapshot));
                }
            }
            return; // 🔥 nunca llega al fetch
        }

        try {
            const response = await apiClient.get(`/platform/travel/componentes/${id}`);
            if (gen !== undefined && gen !== navGen) return;
            const fetchedComp = response.data as Componente;

            const targetId = extractIdStr(fetchedComp.id);
            const exists = catalogos.value.allComponentes.some(c => extractIdStr(c.id) === targetId);

            if (exists) {
                const idx = catalogos.value.allComponentes.findIndex(c => extractIdStr(c.id) === targetId);
                catalogos.value.allComponentes[idx] = fetchedComp;
            } else {
                catalogos.value.allComponentes.push(fetchedComp);
            }

            catalogos.value.tarifas = await hydrateRelations<Tarifa>(fetchedComp.tarifas || []);
            if (gen !== undefined && gen !== navGen) return;

            catalogos.value.tarifas.forEach(registrarTarifaMaestra);

            if (componente && inspectorActivo.value === 'componente') {
                const itemsRaw = fetchedComp.componenteItems || [];
                if (!componente.snapshotItems || componente.snapshotItems.length === 0) {
                    componente.snapshotItems = await Promise.all(itemsRaw.map(mapearItemASnapshot));
                }
            }
        } catch {
            // Refresco del detalle contra el maestro. La cotización ya tiene su snapshot
            // —es una foto congelada, no depende de esta llamada—, así que un fallo aquí
            // deja los datos que ya se estaban mostrando en vez de vaciarlos.
        }
    };

    const fetchCotizacion = async (id: string) => {
        try {
            const response = await apiClient.get(`/platform/sales/cotizacions/${id}`);
            const data = response.data as Cotizacion;

            if (!data.cotservicios) data.cotservicios = [];

            // IDs a hidratar en batch (evita waterfall)
            const componentesToFetch = new Set<string>();
            const proveedoresToFetch = new Set<string>();
            const serviciosToFetch = new Set<string>();

            data.cotservicios.forEach((s: CotServicio) => {
                // Servicio: normaliza fecha base y asegura título público
                s.fechaInicioAbsoluta = getFechaLimpia(s.fechaInicioAbsoluta);
                if (!s.nombrePublicoSnapshot) {
                    s.nombrePublicoSnapshot = JSON.parse(JSON.stringify(s.nombreSnapshot || []));
                }

                if (s.servicioMaestroId) {
                    serviciosToFetch.add(extractIdStr(s.servicioMaestroId));
                }

                // Segmentos: normaliza fecha y resetea flag de traducción
                if (s.cotsegmentos && Array.isArray(s.cotsegmentos)) {
                    s.cotsegmentos.forEach((seg: CotSegmento) => {
                        seg.fechaAbsoluta = getFechaLimpia(seg.fechaAbsoluta);
                        seg.sobreescribirTraduccion = false;
                    });
                }

                // Componentes
                if (s.cotcomponentes && Array.isArray(s.cotcomponentes)) {
                    s.cotcomponentes.forEach((c: ComponenteCompleto) => {
                        // Backfill defensivo del flag de horario (data previa a la migración del backend)
                        if (c.sinHorario === undefined || c.sinHorario === null) {
                            c.sinHorario = sinHorarioDeTipo(c.tipo);
                        }

                        // Maestro del componente (para tipo / requiereHoraExacta)
                        const cmId = extractIdStr(c.componenteMaestroId);
                        if (cmId && cmId.length === 36) componentesToFetch.add(cmId);

                        // Normaliza el segmento a id plano en cotsegmentoId
                        if (c.cotsegmento && !c.cotsegmentoId) {
                            c.cotsegmentoId = typeof c.cotsegmento === 'string'
                                ? extractIdStr(c.cotsegmento)
                                : extractIdStr(c.cotsegmento?.id || c.cotsegmento?.['@id']);
                        }

                        // El proveedor es UNO por componente: se junta aquí y no dentro
                        // del bucle de tarifas, que lo repetía tantas veces como tarifas.
                        const pId = extractIdStr(c.prestadorMaestroId);
                        if (pId && pId.length === 36) proveedoresToFetch.add(pId);

                        // Tarifas del componente
                        if (c.cottarifas && Array.isArray(c.cottarifas)) {
                            c.cottarifas.forEach((t: TarifaSnapshot) => {
                                t.moneda = normalizarCodigoMoneda(t.moneda);
                            });
                        }
                    });
                    ordenarComponentesCronologicamente(s.cotcomponentes);
                }
            });

            const fetchPromises: Promise<unknown>[] = [];

            // BATCH: servicios maestros
            if (serviciosToFetch.size > 0) {
                const idsParam = Array.from(serviciosToFetch).map(id => `id[]=${id}`).join('&');
                fetchPromises.push(
                    apiClient.get(`/platform/travel/servicios?${idsParam}&pagination=false`)
                        .then(res => { catalogos.value.servicios = res.data['hydra:member'] || res.data['member'] || []; })
                        .catch(() => null)
                );
            }

            // BATCH: proveedores maestros
            if (proveedoresToFetch.size > 0) {
                const idsParam = Array.from(proveedoresToFetch).map(id => `id[]=${id}`).join('&');
                fetchPromises.push(
                    apiClient.get(`/platform/travel/organizaciones?${idsParam}&pagination=false`)
                        .then(res => { catalogos.value.proveedores = res.data['hydra:member'] || res.data['member'] || []; })
                        .catch(() => null)
                );
            }

            // BATCH: componentes maestros (trae tipo + tarifas). Resiliente: si falla, no rompe la carga.
            if (componentesToFetch.size > 0) {
                const idsParam = Array.from(componentesToFetch).map(id => `id[]=${id}`).join('&');
                fetchPromises.push(
                    apiClient.get(`/platform/travel/componentes/batch?${idsParam}&pagination=false`)
                        .then(res => {
                            miembrosHydra<Componente>(res.data).forEach((comp) => {
                                const cid = extractIdStr(comp);
                                const idx = catalogos.value.allComponentes.findIndex(c => extractIdStr(c) === cid);
                                if (idx === -1) catalogos.value.allComponentes.push(comp);
                                else catalogos.value.allComponentes.splice(idx, 1, comp);

                                (comp.tarifas || []).forEach(registrarTarifaMaestra);
                            });
                        })
                        .catch((e) => { console.error('No se pudieron precargar los componentes maestros', e); return null; })
                );
            }

            await Promise.all(fetchPromises);

            data.idiomaEdicion = 'es';
            cotizacion.value = data;
            estadoPersistido.value = String(data?.estado ?? '');

        } catch {
            throw new Error("No se encontró la cotización o falló la hidratación.");
        }
    };
    const crearCotizacionVacia = (fileId: string) => {
        const idiomaDefault = fileActual.value?.idiomaCliente
            || (idiomasDisponibles.value.find(i => i.id === 'es') ? 'es' : (idiomasDisponibles.value.length ? idiomasDisponibles.value[0].id : 'es'));

        cotizacion.value = {
            id: crypto.randomUUID(),
            ...(modoCatalogo.value
                ? { catalogo: `/platform/sales/cotizacion_catalogos/${fileId}`, preciosDesde: [], orden: 0 }
                : { file: `/platform/sales/cotizacion_files/${fileId}` }),
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
            // En catálogo nace activo: un tour no es un grupo concreto, así que su
            // "total de viaje" (numPax base × precio) no es vendible — ver docs §6.b.
            totalesOcultos: modoCatalogo.value,
            titulo: [],
            resumen: [],
            sobreescribirTraduccion: false,
            cotservicios: []
        } as Cotizacion;
    };


    /** IRI de una relación que puede llegar como objeto `{'@id', id}`. */
    const extractIriDeRelacion = (rel: { '@id'?: string; id?: string }): string | null =>
        rel['@id'] || rel.id || null;

    // Helper: elimina campos hipermedia (@id/@context/@type) en todo el árbol.
    // Colócalo fuera de guardarCotizacion (a nivel de módulo o composable).
    const stripHypermedia = <T>(obj: T): T => {
        if (Array.isArray(obj)) return obj.map(stripHypermedia) as T;
        if (obj && typeof obj === 'object') {
            const registro = obj as Record<string, unknown>;
            delete registro['@id'];
            delete registro['@context'];
            delete registro['@type'];
            for (const k of Object.keys(registro)) registro[k] = stripHypermedia(registro[k]);
        }
        return obj;
    };

    // ============================================================================
    // PAYLOAD DE ESCRITURA
    //
    // Lo que se manda al backend NO es una `Cotizacion`: el árbol se aplana a IRIs
    // (file, catalogo, cotsegmento, moneda) y los decimales viajan como string,
    // que es lo que espera API Platform. Tiparlo aparte evita tener que mentir
    // sobre la entidad de lectura mientras se construye.
    // ============================================================================
    type TarifaPayload = Omit<TarifaSnapshot, 'montoCosto'> & {
        /** IRI de MaestroMoneda, no el código plano. */
        moneda: string;
        montoCosto: string;
    };

    type ComponentePayload = Omit<ComponenteCompleto, 'cotsegmento' | 'cottarifas'> & {
        /** IRI del CotizacionSegmento, o null si el componente es suelto. */
        cotsegmento: string | null;
        cottarifas?: TarifaPayload[];
    };

    type ServicioPayload = Omit<CotServicio, 'cotcomponentes'> & {
        cotcomponentes?: ComponentePayload[];
    };

    type CotizacionPayload = Omit<Cotizacion,
        'file' | 'catalogo' | 'cotservicios' | 'idiomaEdicion'
        | 'clasificacionFinanciera' | 'clasificacionFinancieraCliente'> & {
        file?: string | null;
        catalogo?: string | null;
        cotservicios?: ServicioPayload[];
        // `null` es intencional: sin resumen financiero se limpia la clasificación
        // guardada, no se deja la anterior colgando.
        clasificacionFinanciera?: ClasificacionFinancieraInterna | null;
        clasificacionFinancieraCliente?: ClasificacionFinancieraCliente | null;
        // Campos que se borran antes de enviar (derivados o de solo edición local).
        idiomaEdicion?: string;
        ganancia?: unknown;
        createdAt?: string;
        updatedAt?: string;
    };

    /**
     * req 5: el rol sólo aplica bajo modo 'incluido'. Si el componente deja de
     * estar 'incluido', cualquier tarifa 'alternativa' pasa a 'estándar' de
     * inmediato (manda el modo). Las 'operativo' se conservan.
     */
    const normalizarRolesDeComponente = (comp: ComponenteCompleto | null | undefined): void => {
        if (!comp || (comp.modo || '').toLowerCase() === 'incluido') return;
        (comp.cottarifas || []).forEach((t: TarifaSnapshot) => {
            if (t.rolSnapshot === 'alternativa') t.rolSnapshot = 'estandar';
        });
    };

    /**
     * req 5: el rol sólo aplica bajo modo 'incluido'. Al guardar, cualquier tarifa
     * 'alternativa' en un componente no-incluido pasa a 'estándar' (manda el modo).
     * Las 'operativo' se conservan. Muta el árbol reactivo para que el recálculo
     * financiero y el payload queden coherentes con lo que se persiste.
     */
    const normalizarRolesSegunModo = (): void => {
        cotizacion.value?.cotservicios?.forEach((servicio: CotServicio) => {
            servicio.cotcomponentes?.forEach((comp: ComponenteCompleto) => normalizarRolesDeComponente(comp));
        });
    };

    /**
     * Si el componente deja de estar 'incluido' (no_incluido, cortesía o
     * reemplazado), sus ítems que estaban marcados 'incluido' dejan de tener
     * sentido como tal: se desmarcan y pasan a 'no_incluido' también. Evita el
     * típico olvido operativo (ej. alojamiento no incluido pero desayuno sí
     * queda marcado como incluido). Si alguno tenía un componente de upsell
     * inyectado por estar incluido, se retira junto con el ítem.
     */
    const cascadeModoItemsSegunComponente = (comp: ComponenteCompleto | null | undefined): void => {
        if (!comp || (comp.modo || '').toLowerCase() === 'incluido') return;
        (comp.snapshotItems || []).forEach((item: SnapshotItem) => {
            if ((item.modo || '').toLowerCase() !== 'incluido') return;
            item.incluido = false;
            item.modo = 'no_incluido';
            if (item.idComponenteInyectado && !item.isInjecting) {
                removerComponenteInyectado(item, comp.id);
            }
        });
    };

    /**
     * req 5: al cambiar el modo de un componente fuera de 'incluido', el rol
     * deja de aplicar y sus tarifas 'alternativa' pasan a 'estándar' de una vez
     * (sin advertencia: se hace y punto).
     */
    const onCambioModoComponente = (comp: ComponenteCompleto | null | undefined): void => {
        cascadeModoItemsSegunComponente(comp);
        normalizarRolesDeComponente(comp);
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
    /**
     * Guarda la cotización. Devuelve si de verdad se guardó.
     *
     * ⚠️ El `boolean` no es cosmético. Antes era `Promise<void>` y quien llamaba no podía
     * distinguir «guardado» de «abortado por la guarda» de «reventó la red». La vista limpiaba
     * `isDirty` en los tres casos, así que tras un guardado fallido el aviso de «tienes cambios
     * sin guardar» quedaba desarmado y el trabajo se perdía al navegar **sin ninguna señal**.
     * Ése era el «guardé y falló silenciosamente».
     */
    /**
     * El estado que la cotización tiene EN LA BASE, no el que se ve en el selector.
     *
     * Hace falta para distinguir «publicar» de «seguir editando algo ya publicado»: sin esto, el
     * único dato disponible era `payload.estado`, que ya lleva lo que el usuario acaba de elegir.
     */
    const estadoPersistido = ref<string>('');

    const guardarCotizacion = async (): Promise<boolean> => {
        if (!cotizacion.value) return false;

        // Otro trabajo tiene el editor ocupado —una carga, una plantilla—. Se avisa en vez de
        // devolver `false` a secas: para el usuario, un botón que no hace nada es un botón roto.
        if (isLoading.value) {
            alert('El editor está ocupado terminando otra operación. Espera un momento y vuelve a guardar.');

            return false;
        }

        isLoading.value = true;
        try {
            // req 5: normaliza roles antes de clonar el payload y de leer el resumen
            // financiero, para que lo que se persiste y lo que se recalcula coincidan.
            normalizarRolesSegunModo();

            const isUpdate = !!cotizacion.value.createdAt;
            const endpoint = isUpdate
                ? `/platform/sales/cotizacions/${cotizacion.value.id}`
                : `/platform/sales/cotizacions`;

            // 🔥 Clonado + limpieza de hipermedia (evita que @id resuelva a
            // referencias de Doctrine sin constructor → colección sin inicializar).
            // El clon arranca como Cotizacion y se va aplanando a payload de escritura.
            const payload = stripHypermedia(JSON.parse(JSON.stringify(cotizacion.value))) as CotizacionPayload;

            // Campos derivados / gestionados por el backend: no deben viajar.
            delete payload.ganancia;
            delete payload.createdAt;
            delete payload.updatedAt;

            // Formateo del padre (expediente o catálogo de tours)
            if (payload.file && typeof payload.file === 'object') {
                payload.file = extractIriDeRelacion(payload.file);
            } else if (payload.file && !payload.file.includes('/platform/')) {
                payload.file = `/platform/sales/cotizacion_files/${payload.file}`;
            }
            if (payload.catalogo && typeof payload.catalogo === 'object') {
                payload.catalogo = extractIriDeRelacion(payload.catalogo);
            } else if (payload.catalogo && !payload.catalogo.includes('/platform/')) {
                payload.catalogo = `/platform/sales/cotizacion_catalogos/${payload.catalogo}`;
            }

            // Rangos "Desde" (catálogo): valor decimal como string, nunca number
            if (Array.isArray(payload.preciosDesde)) {
                payload.preciosDesde = payload.preciosDesde
                    .filter((r) => r && r.valor !== '' && r.valor !== null && r.valor !== undefined)
                    .map((r): PrecioDesdeRango => ({
                        titulo: Array.isArray(r.titulo) ? r.titulo : [],
                        moneda: r.moneda || 'USD',
                        valor: String(r.valor),
                    }));
            }
            if (payload.orden !== undefined) payload.orden = parseInt(String(payload.orden)) || 0;

            // Parseo seguro de métricas base
            payload.comision = String(payload.comision || '0');
            payload.adelanto = String(payload.adelanto || '0');
            payload.totalCosto = String(resumenFinanciero.value?.totalCostoNeto || '0');
            payload.totalVenta = String(resumenFinanciero.value?.totalVentaBruta || '0');
            payload.numPax = parseInt(String(payload.numPax)) || 1;
            payload.tipoCambio = String(payload.tipoCambio || tipoCambioSugerido.value || 1);

            const fin = resumenFinanciero.value;

            // 🔥 VALIDACIÓN DE ESTADOS PROTEGIDOS: bloquea la TRANSICIÓN, no toda edición.
            //
            // ⚠️ Antes bloqueaba cualquier guardado de una cotización que YA estuviera en un
            // estado protegido, y eso creaba un callejón sin salida: `agregarComponente()` crea el
            // componente con `nombreSnapshot: []` y `snapshotItems: []` —lo normal, acabas de
            // añadirlo—, y esa vacuidad dispara la advertencia «no tiene título público ni ítems».
            // Con la cotización en `enviado` —las 10 de producción lo están—, el guardado abortaba.
            // Para rellenar la línea había que guardarla, y para guardarla había que rellenarla.
            //
            // Lo que hay que proteger es PUBLICAR algo con conflictos, no seguir trabajando sobre
            // lo que ya se publicó. Si el estado no cambia en este guardado, se avisa y se deja
            // decidir.
            const estadosProtegidos = ['enviado', 'confirmado', 'operado'];
            const cambiaDeEstado = payload.estado !== estadoPersistido.value;

            if (estadosProtegidos.includes(payload.estado) && cambiaDeEstado && fin && !fin.publicable) {
                const estadoLabel = payload.estado.charAt(0).toUpperCase() + payload.estado.slice(1);
                alert(
                    `No se puede pasar la cotización a "${estadoLabel}" debido a los siguientes conflictos financieros:\n\n` +
                    (fin.advertencias.length
                        ? fin.advertencias.map(a => `• ${a}`).join('\n')
                        : '• Hay perfiles de pasajero en conflicto (revisa el panel de resumen para asignar las tarifas correctamente).')
                );

                return false;
            }

            // Y si NO cambia de estado, se avisa pero se deja guardar: son cosas distintas
            // —publicar con conflictos frente a seguir trabajando sobre algo ya publicado— y
            // tratarlas igual es lo que dejaba la cotizadora en solo-lectura.
            if (estadosProtegidos.includes(payload.estado) && fin && !fin.publicable) {
                const seguir = confirm(
                    'Esta cotización tiene conflictos financieros sin resolver:\n\n'
                    + (fin.advertencias.length
                        ? fin.advertencias.map(a => `• ${a}`).join('\n')
                        : '• Hay perfiles de pasajero en conflicto.')
                    + '\n\nSe guardará igual para que puedas seguir trabajando, pero no podrás '
                    + 'cambiarla de estado hasta resolverlos. ¿Guardar?'
                );

                if (!seguir) {
                    return false;
                }
            }

            // Inyección de la estructura financiera al payload
            payload.totalCosto = String(fin?.totalCostoNeto ?? '0');
            payload.totalVenta = String(fin?.totalVentaBruta ?? '0');
            payload.clasificacionFinanciera = fin ?? null;
            payload.clasificacionFinancieraCliente = fin ? expurgarParaCliente(fin) : null;

            delete payload.idiomaEdicion;

            // Limpieza y formateo del árbol relacional
            if (payload.cotservicios && Array.isArray(payload.cotservicios)) {
                payload.cotservicios.forEach((servicio) => {

                    if (servicio.servicioMaestroId) {
                        servicio.servicioMaestroId = extractIdStr(servicio.servicioMaestroId);
                    }

                    servicio.fechaInicioAbsoluta = getFechaLimpia(servicio.fechaInicioAbsoluta);
                    if (servicio.fechaInicioAbsoluta.length === 10) {
                        servicio.fechaInicioAbsoluta += 'T00:00:00';
                    }

                    if (servicio.cotsegmentos && Array.isArray(servicio.cotsegmentos)) {
                        servicio.cotsegmentos.forEach((seg) => {
                            seg.fechaAbsoluta = getFechaLimpia(seg.fechaAbsoluta || servicio.fechaInicioAbsoluta);
                            if (seg.fechaAbsoluta.length === 10) seg.fechaAbsoluta += 'T00:00:00';
                        });
                    }

                    if (servicio.cotcomponentes && Array.isArray(servicio.cotcomponentes)) {
                        servicio.cotcomponentes.forEach((componente) => {
                            componente.cantidad = unidadesDe(componente.cantidad);

                            if (componente.componenteMaestroId) {
                                componente.componenteMaestroId = extractIdStr(componente.componenteMaestroId);
                            }

                            // Colapso a 00:00 gobernado por el snapshot del propio componente.
                            // Autosuficiente: ya no depende de que el maestro esté cargado.
                            if (componente.sinHorario) {
                                if (componente.fechaHoraInicio) {
                                    componente.fechaHoraInicio = componente.fechaHoraInicio.split('T')[0] + 'T00:00:00';
                                }
                                if (componente.fechaHoraFin) {
                                    componente.fechaHoraFin = componente.fechaHoraFin.split('T')[0] + 'T00:00:00';
                                }
                            }

                            const segId = componente.cotsegmentoId || extractIdStr(componente.cotsegmento);

                            componente.cotsegmento = segId
                                ? `/platform/sales/cotizacion_segmentos/${segId}`
                                : null;

                            delete componente.cotsegmentoId;

                            if (componente.cottarifas && Array.isArray(componente.cottarifas)) {
                                componente.cottarifas.forEach((tarifa) => {
                                    // Aquí es donde más dolía: esto se PERSISTE, así que un 0
                                    // escrito a conciencia se guardaba como 1 y el cero no
                                    // sobrevivía ni al guardado.
                                    tarifa.cantidad = unidadesDe(tarifa.cantidad);
                                    tarifa.montoCosto = String(tarifa.montoCosto || '0');
                                    if (tarifa.tarifaMaestraId) {
                                        tarifa.tarifaMaestraId = extractIdStr(tarifa.tarifaMaestraId);
                                    }
                                    tarifa.comisionOverrideSnapshot = (tarifa.comisionOverrideSnapshot === '' || tarifa.comisionOverrideSnapshot == null)
                                        ? null
                                        : String(tarifa.comisionOverrideSnapshot);
                                    // MaestroMoneda es una relación — enviar IRI, no código plano
                                    const codigoMoneda = normalizarCodigoMoneda(tarifa.moneda);
                                    tarifa.moneda = `/platform/maestro/monedas/${codigoMoneda}`;
                                });
                            }
                        });
                    }
                });
            }

            const response = await (isUpdate ? apiClient.put : apiClient.post)(endpoint, payload);
            const savedData = response.data;


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

                    // Backfill del flag de horario si el backend no lo devolviera
                    if (c.sinHorario === undefined || c.sinHorario === null) {
                        c.sinHorario = sinHorarioDeTipo(c.tipo);
                    }

                    if (c.snapshotItems && !Array.isArray(c.snapshotItems)) {
                        c.snapshotItems = Object.values(c.snapshotItems);
                    }

                    c.snapshotItems?.forEach((i: SnapshotItem) => {
                        i.sobreescribirTraduccion = false;
                    });

                    c.cottarifas?.forEach((t: TarifaSnapshot) => {
                        t.sobreescribirTraduccion = false;
                        t.moneda = normalizarCodigoMoneda(t.moneda);
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

            // Lo guardado pasa a ser la nueva referencia: si ahora está en «confirmado», el
            // siguiente guardado ya no cuenta como transición.
            estadoPersistido.value = String(savedData.estado ?? payload.estado);

            alert('Cotización guardada exitosamente.');

            return true;
        } catch (error) {
            console.error('Error al guardar la cotización:', error);

            // El backend explica QUÉ pasa —«ese servicio pertenece a una Orden de Servicio…»— y
            // ese texto se tiraba para poner un genérico. `fileStore` ya usa este extractor.
            alert(extractApiErrorMessage(error, 'Falló la sincronización con la base de datos.'));

            return false;
        } finally {
            isLoading.value = false;
        }
    };
    // ============================================================================
    // NAVEGACIÓN Y ABMC
    // ============================================================================

    const inspectorActivo = ref<NivelInspector>('resumen');
    const dataActiva = ref<NodoInspector | null>(null) as Ref<NodoInspector | null>;
    const historialNavegacion = ref<{ nivel: NivelInspector, data: NodoInspector | null }[]>([]);
    const isMobileOpen = ref<boolean>(false);
    const isSegmentEditorOpen = ref<boolean>(false);

    // ============================================================================
    // ACCESORES TIPADOS DEL NODO ABIERTO
    //
    // `dataActiva` es el nodo del árbol que el inspector está editando, y cambia
    // de TIPO según el nivel (servicio / componente / tarifa). `abrirNivel()`
    // asigna nivel y nodo siempre juntos, así que `inspectorActivo` es el
    // discriminante fiable: estos accesores lo comprueban y devuelven null si el
    // inspector está en otro nivel.
    //
    // Todo el store y las vistas leen el nodo por aquí. Tocar `dataActiva` a pelo
    // vuelve a abrir la puerta a escribir campos de un tipo sobre un nodo de otro,
    // que es lo que el `any` de antes dejaba pasar sin rechistar.
    // ============================================================================
    const servicioActivo = computed<CotServicio | null>(() =>
        inspectorActivo.value === 'servicio' ? (dataActiva.value as CotServicio) : null);

    const componenteActivo = computed<ComponenteCompleto | null>(() =>
        inspectorActivo.value === 'componente' ? (dataActiva.value as ComponenteCompleto) : null);

    const tarifaActiva = computed<TarifaSnapshot | null>(() =>
        inspectorActivo.value === 'tarifa' ? (dataActiva.value as TarifaSnapshot) : null);

    let navGen = 0;

    const abrirNivel = async (nivel: NivelInspector, data: NodoInspector | null = null): Promise<void> => {
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
        const servicio = servicioActivo.value;
        if (servicio?.servicioMaestroId) {
            await fetchServicioDetalles(servicio.servicioMaestroId, gen);
        }
        const componente = componenteActivo.value;
        if (componente?.componenteMaestroId) {
            await fetchComponenteDetalles(componente.componenteMaestroId, gen);
        }

        // Los servicios del proveedor se cargan para el COMPONENTE en edición —esté el
        // inspector en él o en una de sus tarifas—, porque el proveedor es suyo. Antes
        // colgaba de la tarifa y había que reconsultarlos al saltar entre hermanas.
        const enEdicion = componenteEnEdicion.value;
        if (enEdicion?.prestadorMaestroId) {
            await fetchProveedorServiciosDeProveedor(enEdicion.prestadorMaestroId, gen);
        }
    };

    const limpiarServicioProveedor = () => {
        const componente = componenteEnEdicion.value;
        if (componente) {
            componente.prestadorServicioMaestroId = null;
            componente.prestadorServicioNombreSnapshot = null;
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

    /**
     * Id del segmento al que pertenece un componente. El backend lo manda de dos
     * formas según el grupo de serialización: `cotsegmentoId` plano o la relación
     * `cotsegmento` (IRI u objeto). Devuelve null si el componente es suelto.
     */
    const idSegmentoDeComponente = (comp: ComponenteCompleto): string | null =>
        comp.cotsegmentoId || (comp.cotsegmento ? extractIdStr(comp.cotsegmento) : null);

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
            : (modoCatalogo.value ? FECHA_BASE_NOMINAL : getFechaLimpia(new Date().toISOString()));

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
                const idAbierto = dataActiva.value?.id;
                const perteneceAlServicio = servicioPadre?.cotcomponentes?.some((c: ComponenteCompleto) => {
                    if (c.id === idAbierto) return true;
                    return c.cottarifas?.some((t: TarifaSnapshot) => t.id === idAbierto);
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
        const actual = servicioActivo.value;
        if (!lista.length || !actual) return;
        const idx = lista.findIndex(s => s.id === actual.id);
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
        const componente = componenteActivo.value;
        return componente ? findServicioByComponenteId(componente.id) : null;
    });

    const componentesHermanos = computed<ComponenteCompleto[]>(() => {
        return servicioActualDeComponente.value?.cotcomponentes || [];
    });

    const irAComponenteAdyacente = async (direccion: 1 | -1): Promise<void> => {
        const lista = componentesHermanos.value;
        const actual = componenteActivo.value;
        if (!lista.length || !actual) return;
        const idx = lista.findIndex(c => c.id === actual.id);
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
                sinHorario: true,
                cantidad: 1,
                estado: 'activo',
                modo: 'incluido',
                fechaHoraInicio: fechaHoraInicio,
                fechaHoraFin: fechaHoraInicio,
                cotsegmentoId: null,
                cotsegmento: null,
                // Obligatorio en el contrato. `false`: un extra suelto no representa el
                // horario global del día — esa promoción es única por (plantilla, día).
                horaServicioCompleto: false,
                // Nace sin prestador ni proveedor, así que no hay a quién nombrar. Las
                // banderas se siembran al asignarlos, no al crear el componente.
                prestadorVisible: false,
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
        const componente = componenteActivo.value;
        if (componente && componente.id === componenteId) {
            if (!componente.snapshotItems) componente.snapshotItems = [];
            componente.snapshotItems.push({
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
        const componente = componenteActivo.value;
        if (componente && componente.id === componenteId) {
            const item = componente.snapshotItems.find((i) => i.id === itemId);
            if (item && item.idComponenteInyectado) {
                removerComponenteInyectado(item, componenteId);
            }
            componente.snapshotItems = componente.snapshotItems.filter((i) => i.id !== itemId);
        }
    };


    const agregarDetalleOperativo = (componenteId: string, tipo: DetalleOperativoTipo = DetalleOperativoTipo.CLIENTE): void => {
        const componente = componenteActivo.value;
        if (componente && componente.id === componenteId) {
            if (!componente.detallesOperativos) componente.detallesOperativos = [];
            componente.detallesOperativos.push({
                id: crypto.randomUUID(),
                tipo,
                detalle: [{ language: 'es', content: '' }]
            });
        }
    };

    const eliminarDetalleOperativo = (componenteId: string, bloqueId: string): void => {
        const componente = componenteActivo.value;
        if (componente && componente.id === componenteId && componente.detallesOperativos) {
            componente.detallesOperativos = componente.detallesOperativos.filter(
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
                        sinHorario: sinHorarioDeTipo(compMaestro.tipo),
                        cantidad: componentePadre.cantidad,
                        estado: 'activo',
                        modo: 'incluido',
                        fechaHoraInicio: componentePadre.fechaHoraInicio,
                        fechaHoraFin: componentePadre.fechaHoraFin,
                        cotsegmentoId: componentePadre.cotsegmentoId,
                        cotsegmento: componentePadre.cotsegmento || null,
                        // El upsell no roba la promoción horaria de su padre: nace en false
                        // aunque el padre la tenga. Sólo un componente por día puede llevarla.
                        horaServicioCompleto: false,
                        prestadorVisible: false,
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

                    const tarifasParaInyectar: TarifaLike[] = [];
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
        const tieneGrupal = enGrupoActual.some((t: TarifaSnapshot) => t.esGrupal);
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
            prestadorMaestroId: null,
            prestadorNombreSnapshot: null,
            prestadorTituloSnapshot: [],
            prestadorUrlSnapshot: null,
            prestadorImagenesSnapshot: [],
            prestadorServicioMaestroId: null,
            proveedorServicioNombreSnapshot: null,
            prestadorServicioTituloSnapshot: [],
            prestadorServicioUrlSnapshot: null,
            prestadorServicioImagenesSnapshot: [],
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
     * Cómo llama el PROVEEDOR a este servicio, para el requerimiento que se le manda.
     *
     * Vacío significa «lo llama igual que nosotros», y por eso NO cae al nombre interno:
     * la cascada de `BibliaSnapshotService::resolverDescripcion()` prefiere este nombre y
     * sólo baja al interno si falta. Rellenarlo aquí lo dejaba al 100% e indistinguible, y
     * la Orden de Servicio acababa pidiéndole al proveedor un código que no reconoce.
     */
    const getNombreParaProveedorTarifa = (t: TarifaLike): string | null => {
        return ('nombreParaPrestador' in t ? t.nombreParaPrestador : null) || null;
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
        // El servicio del proveedor (tipo de habitación) ya no cuelga de la tarifa: es del
        // componente, y lo siembra onTarifaMaestraChange(). Aquí sólo se mapea la línea.
        const rol = getRolTarifa(tarifa);

        return {
            id: crypto.randomUUID(),
            tarifaMaestraId: getIdMaestroTarifa(tarifa),
            tituloSnapshot: JSON.parse(JSON.stringify(getTituloSafe(tarifa))),
            nombreInternoSnapshot: 'nombreInterno' in tarifa ? tarifa.nombreInterno || null : null,
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
            nombreParaProveedorSnapshot: getNombreParaProveedorTarifa(tarifa),
            // DE QUIÉN ES ESTE PRECIO, congelado. El prestador vive en el componente maestro
            // desde `Version20260816240000`, pero la línea tiene que recordar de cuál vino: una
            // cotización puede acabar con tarifas de componentes distintos —el editor lo avisa
            // y lo deja pasar— y entonces «el prestador del componente» ya no lo dice.
            ...papelesDeTarifaMaestra(tarifa),
            sobreescribirTraduccion: false
        };
    }

    /**
     * Los TRES papeles de una tarifa del catálogo, en la forma que guarda el snapshot.
     *
     * Salen de la propia tarifa maestra, que desde el 20/08/2026 es donde viven. El nombre
     * viaja plano junto al IRI (`prestadorNombre`, `compradorNombre`…) justo para esto: sin
     * él habría que resolver una petición por tarifa sólo para congelar un texto.
     *
     * ⚠️ **El comprador NO cae al prestador cuando está vacío.** Aquí se congela lo que hay
     * escrito; qué significa el vacío lo decide `CotizacionCotcomponente::resolverComprador()`
     * en PHP. Rellenarlo aquí duplicaría esa regla y las dos copias acabarían diciendo cosas
     * distintas.
     */
    function papelesDeTarifaMaestra(tarifa: TarifaLike): PapelesDeTarifa {
        const iri = (v: unknown): string | null => extractIdStr(v as string) || null;
        const texto = (v: unknown): string | null => (typeof v === 'string' && v.trim() !== '' ? v : null);

        return {
            prestadorMaestroId: iri('prestador' in tarifa ? tarifa.prestador : null),
            prestadorNombreSnapshot: texto('prestadorNombre' in tarifa ? tarifa.prestadorNombre : null),
            prestadorServicioMaestroId: iri('prestadorServicio' in tarifa ? tarifa.prestadorServicio : null),
            prestadorServicioNombreSnapshot: texto('prestadorServicioNombre' in tarifa ? tarifa.prestadorServicioNombre : null),
            compradorMaestroId: iri('comprador' in tarifa ? tarifa.comprador : null),
            compradorNombreSnapshot: texto('compradorNombre' in tarifa ? tarifa.compradorNombre : null),
        };
    }

    /**
     * La primera tarifa que trae un papel se lo pone a la línea. Las siguientes no lo pisan.
     *
     * ## Por qué «el primero manda» y no «el último»
     *
     * Porque el segundo suele ser el despiste. Colgar de una línea una tarifa de otra empresa
     * es legítimo —le compras al consolidador lo que opera otro— pero es también el error más
     * caro de cotizar cuando no es intencionado, y no se nota hasta que hay que pedirle el
     * servicio a alguien que nunca lo cotizó. Si el último ganara, ese despiste reescribiría
     * en silencio lo que ya estaba bien.
     *
     * ## Por qué NO bloquea
     *
     * Un candado dejaría esa tarifa inseleccionable y el caso legítimo existe. Se avisa y se
     * deja pasar: `desajusteDeTarifa` en la vista.
     *
     * ⚠️ **El servicio va atado al prestador, no suelto.** Sólo se siembra si la línea acaba
     * de tomar ese prestador o ya lo tenía: rellenar el servicio desde una tarifa de OTRA
     * empresa dejaría la línea diciendo «Hotel A» con «habitación del Hotel B» — la misma
     * incoherencia que `TravelTarifa::validarConsistenciaLogica()` impide en el catálogo.
     *
     * El comprador sí es independiente: a quién se le encarga la compra no depende de quién
     * preste, que es justo su razón de ser.
     */
    function sembrarPapelesEnLinea(linea: ComponenteCompleto | null, papeles: PapelesDeTarifa): void {
        if (!linea) return;

        const vacio = (v: string | null | undefined): boolean => !extractIdStr(v ?? null);

        if (papeles.prestadorMaestroId && vacio(linea.prestadorMaestroId)) {
            linea.prestadorMaestroId = papeles.prestadorMaestroId;
            linea.prestadorNombreSnapshot = papeles.prestadorNombreSnapshot;
        }

        const mismoPrestador = !papeles.prestadorMaestroId
            || extractIdStr(linea.prestadorMaestroId ?? null) === papeles.prestadorMaestroId;

        if (papeles.prestadorServicioMaestroId && mismoPrestador && vacio(linea.prestadorServicioMaestroId)) {
            linea.prestadorServicioMaestroId = papeles.prestadorServicioMaestroId;
            linea.prestadorServicioNombreSnapshot = papeles.prestadorServicioNombreSnapshot;
        }

        if (papeles.compradorMaestroId && vacio(linea.compradorMaestroId)) {
            linea.compradorMaestroId = papeles.compradorMaestroId;
            linea.compradorNombreSnapshot = papeles.compradorNombreSnapshot;
        }
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
        const tarifa = tarifaActiva.value;
        if (inspectorActivo.value !== 'tarifa' || !tarifa) return null;
        return encontrarComponentePorTarifaId(tarifa.id);
    });

    /**
     * El componente sobre el que recae la edición, esté abierto el inspector en él o en
     * una de sus tarifas.
     *
     * Hace falta porque el selector de proveedor vive en el inspector de TARIFA pero la
     * presentación que escribe es del COMPONENTE. `componenteActivo` devuelve null cuando
     * el inspector está en 'tarifa' —es su contrato—, así que usarlo ahí habría dejado la
     * escritura sin destino y en silencio.
     */
    const componenteEnEdicion = computed<ComponenteCompleto | null>(() =>
        componenteActivo.value ?? componenteActualDeTarifa.value);

    const tarifasHermanas = computed<TarifaSnapshot[]>(() => {
        const componente = componenteActualDeTarifa.value;
        if (!componente?.cottarifas) return [];
        // Grupo primero (nulls —operativas— al final), estable dentro del mismo grupo
        return [...componente.cottarifas].sort((a, b) => (a.grupoTarifa ?? Infinity) - (b.grupoTarifa ?? Infinity));
    });

    const irATarifaAdyacente = async (direccion: 1 | -1): Promise<void> => {
        const tarifa = tarifaActiva.value;
        const lista = tarifasHermanas.value;
        if (!lista.length || !tarifa) return;
        const idx = lista.findIndex(t => t.id === tarifa.id);
        if (idx === -1) return;
        const nuevoIdx = idx + direccion;
        if (nuevoIdx < 0 || nuevoIdx >= lista.length) return;

        const gen = ++navGen;

        const destino = lista[nuevoIdx];
        dataActiva.value = destino;   // mismo nivel, no toca historialNavegacion

        // Las tarifas hermanas comparten componente y, por tanto, proveedor: saltar entre
        // ellas ya no puede cambiarlo, así que no hay nada que recargar. Antes el
        // proveedor era de cada tarifa y esto reconsultaba en cada salto.
        const compDeTarifa = componenteEnEdicion.value;
        if (compDeTarifa?.prestadorMaestroId) {
            await fetchProveedorServiciosDeProveedor(compDeTarifa.prestadorMaestroId, gen);
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
        segmentoMaestro: SegmentoMaestro,
        diaDelSegmento: number = 1,
        idSegmentoGenerado: string,
        itinerarioId: string | null = null
    ): Promise<void> => {
        const servicio = servicioActivo.value;
        if (!servicio) return;

        if (segmentoMaestro.segmentoComponentes && Array.isArray(segmentoMaestro.segmentoComponentes)) {

            const mejoresMatches = new Map<string, SegmentoComponenteProcesado>();

            segmentoMaestro.segmentoComponentes.forEach((rawSegComp) => {
                const segComp = rawSegComp as SegmentoComponenteProcesado;
                let compMaestro: string | components['schemas']['Componente-componente.item.read'] | Componente | undefined = segComp.componente;

                if (!compMaestro) return;

                const cId = String(extractIdStr(compMaestro) || '');
                const found = catalogos.value.allComponentes.find((c) => String(extractIdStr(c.id || c['@id']) || '') === cId);
                if (found) {
                    compMaestro = found as Componente;
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
                let compMaestro = segComp.tempCompObj as Componente;
                if (!compMaestro) continue;

                // Se prefiere la copia YA hidratada del catálogo (la que trae tarifas)
                // sobre el objeto embebido en la relación del segmento, que viene flaco.
                const compHidratado = catalogos.value.allComponentes.find(
                    (c) => extractIdStr(c) === compId && 'tarifas' in c
                );

                if (isComponenteCompleto(compHidratado)) {
                    compMaestro = compHidratado;
                }

                let fechaBase = getFechaLimpia(servicio.fechaInicioAbsoluta);

                if (diaDelSegmento > 1) {
                    const dateObj = new Date(`${fechaBase}T12:00:00Z`);
                    dateObj.setUTCDate(dateObj.getUTCDate() + (diaDelSegmento - 1));
                    fechaBase = dateObj.toISOString().split('T')[0];
                }

                const tipoComp = compMaestro.tipo || 'extras';
                const sinHorario = sinHorarioDeTipo(tipoComp);
                const reqHora = !sinHorario;

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

                const nuevoComp: ComponenteCompleto = {
                    id: crypto.randomUUID(),
                    componenteMaestroId: extractIdStr(compMaestro) || null,
                    nombreSnapshot: JSON.parse(JSON.stringify(getTituloSafe(compMaestro))),
                    tipo: tipoComp,
                    sinHorario,
                    // Propaga la promoción de la plantilla Travel: la hora de este
                    // componente representa el horario de toda la excursión.
                    horaServicioCompleto: !!segComp.horaServicioCompleto,
                    cantidad: calcularPernoctes(fHoraInicio, fHoraFin),
                    // El prestador no viene del catálogo; el PROVEEDOR sí, desde que subió
                    // de la tarifa al componente maestro. Se siembra aquí en vez de al
                    // elegir tarifa, que es donde estaba y donde ya no tiene sentido.
                    ...sembrarPrestadorDesdeMaestro(compMaestro),
                    estado: 'activo',
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

                const tarifasParaInyectar: (components['schemas']['Tarifa-componente.item.read'] | Tarifa)[] = [];

                // La tarifa por defecto llega como id suelto, como objeto embebido o
                // como IRI, segun el grupo de serializacion de la relacion.
                const tarifaDefId = extractIdStr(segComp.tarifaId || segComp.tarifaPredeterminada);

                if (tarifaDefId) {
                    const coincideDef = (t: Tarifa): boolean => extractIdStr(t) === tarifaDefId;
                    const tDef = (compMaestro.tarifas || []).find(coincideDef)
                        || todasLasTarifasMaestras.value.find(coincideDef);
                    if (tDef) tarifasParaInyectar.push(tDef);
                } else if (compMaestro.tarifas && compMaestro.tarifas.length === 1 && !['no_incluido', 'reemplazado'].includes(nuevoComp.modo)) {
                    tarifasParaInyectar.push(compMaestro.tarifas[0]);
                }

                nuevoComp.cottarifas = tarifasParaInyectar.map((t) =>
                    mapearATarifaSnapshot(t, cotizacion.value?.numPax || 1)
                );

                if (!servicio.cotcomponentes) {
                    servicio.cotcomponentes = [];
                }
                servicio.cotcomponentes.push(nuevoComp);
            }

            ordenarComponentesCronologicamente(servicio.cotcomponentes ?? []);
            sincronizarFechaServicio(servicio);
        }
    };
    const aplicarPlantilla = async (plantillaId: string): Promise<void> => {
        const servicio = servicioActivo.value;
        isLoading.value = true;
        try {
            const endpoint = plantillaId.startsWith('/') ? plantillaId : `/platform/travel/itinerarios/${plantillaId}`;
            const response = await apiClient.get(endpoint);
            const plantillaProfunda = response.data as ItinerarioProfundo;
            if (!servicio) return;

            servicio.itinerarioNombreSnapshot = JSON.parse(JSON.stringify(getTituloSafe(plantillaProfunda)));
            // El NOMBRE operativo se transforma en el de la plantilla (más específica que el
            // servicio). El título público sigue su propia vía, más abajo.
            servicio.nombreSnapshot = nombreOperativoComoI18n(
                (plantillaProfunda as { nombreInterno?: string }).nombreInterno
            );
            // Referencia interna a la plantilla: habilita el re-sync exacto de flags
            // (p.ej. "hora de servicio completo") por el botón Actualizar.
            servicio.itinerarioMaestroId = extractIdStr(plantillaId)
                || extractIdStr(plantillaProfunda) || null;
            servicio.nombrePublicoSnapshot = JSON.parse(JSON.stringify(getTituloSafe(plantillaProfunda))); // 👉 NUEVO
            let ordenMaximo = servicio.cotsegmentos ? servicio.cotsegmentos.length : 0;

            const arrayRelaciones: RelacionItinerarioSegmento[] =
                plantillaProfunda.segmentos || plantillaProfunda.itinerarioSegmentos || [];

            if (arrayRelaciones && Array.isArray(arrayRelaciones)) {
                const segmentosRaw = arrayRelaciones.map((rel) => rel.segmento ?? rel) as RelacionSinHidratar[];
                const segmentosReales = await hydrateRelations<SegmentoMaestro>(segmentosRaw);

                const compIdsToFetch = new Set<string>();
                segmentosReales.forEach((seg) => {
                    (seg.segmentoComponentes || []).forEach((sc) => {
                        const cId = extractIdStr(sc.componente);
                        if (cId) compIdsToFetch.add(cId);
                    });
                });
                await Promise.all(Array.from(compIdsToFetch).map(id => fetchComponenteDetalles(id)));

                for (const [index, seg] of segmentosReales.entries()) {
                    ordenMaximo++;
                    const relacionOriginal = arrayRelaciones[index];
                    const diaDelSegmento = relacionOriginal.dia || 1;
                    const nuevoIdSeg = crypto.randomUUID();

                    let fechaCalculada = getFechaLimpia(servicio.fechaInicioAbsoluta);
                    if (diaDelSegmento > 1) {
                        const dateObj = new Date(`${fechaCalculada}T12:00:00Z`);
                        dateObj.setUTCDate(dateObj.getUTCDate() + (diaDelSegmento - 1));
                        fechaCalculada = dateObj.toISOString().split('T')[0];
                    }

                    if (!servicio.cotsegmentos) servicio.cotsegmentos = [];
                    servicio.cotsegmentos.push({
                        id: nuevoIdSeg,
                        segmentoMaestroId: extractIdStr(seg),
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

    const agregarSegmentoIndividual = async (segmentoMaestroRaw: SegmentoMaestro, itinerarioId: string | null = null): Promise<void> => {
        const servicio = servicioActivo.value;
        if (!servicio) return;

        const segmentoMaestro = await profundizarSegmento(segmentoMaestroRaw);

        const compIdsToFetch = new Set<string>();
        (segmentoMaestro.segmentoComponentes || []).forEach((sc) => {
            const cId = extractIdStr(sc.componente);
            if (cId) compIdsToFetch.add(cId);
        });
        await Promise.all(Array.from(compIdsToFetch).map(id => fetchComponenteDetalles(id)));

        const ordenNuevo = servicio.cotsegmentos ? servicio.cotsegmentos.length + 1 : 1;
        const nuevoIdSeg = crypto.randomUUID();
        const fechaCalculada = getFechaLimpia(servicio.fechaInicioAbsoluta);

        if (!servicio.cotsegmentos) servicio.cotsegmentos = [];
        servicio.cotsegmentos.push({
            id: nuevoIdSeg,
            segmentoMaestroId: extractIdStr(segmentoMaestro),
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
     * Trae el segmento maestro COMPLETO (con `segmentoComponentes`): el que llega
     * desde el pool del catálogo suele venir flaco. Si la petición falla se sigue
     * con lo que había — mejor un segmento sin componentes que romper la inserción.
     */
    const profundizarSegmento = async (segmentoMaestroRaw: SegmentoMaestro): Promise<SegmentoMaestro> => {
        try {
            const idStr = extractIdStr(segmentoMaestroRaw);
            if (!idStr) return segmentoMaestroRaw;
            const res = await apiClient.get(`/platform/travel/segmentos/${idStr}`);
            return res.data as SegmentoMaestro;
        } catch (e) {
            console.error("No se pudo profundizar el segmento", e);
            return segmentoMaestroRaw;
        }
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

    const procesarInsercionSegmento = async (segmentoMaestroRaw: SegmentoMaestro, itinerarioId: string | null, accion: 'append' | 'replace' | 'insert', targetId?: string) => {
        const servicio = servicioActivo.value;
        if (!servicio) return;
        if (!servicio.cotsegmentos) servicio.cotsegmentos = [];

        if (accion === 'append' || !targetId) {
            await agregarSegmentoIndividual(segmentoMaestroRaw, itinerarioId);
            return;
        }

        const segmentoMaestro = await profundizarSegmento(segmentoMaestroRaw);

        const compIdsToFetch = new Set<string>();
        (segmentoMaestro.segmentoComponentes || []).forEach((sc) => {
            const cId = extractIdStr(sc.componente);
            if (cId) compIdsToFetch.add(cId);
        });
        await Promise.all(Array.from(compIdsToFetch).map(id => fetchComponenteDetalles(id)));

        let fechaCalculada = getFechaLimpia(servicio.fechaInicioAbsoluta);
        const index = servicio.cotsegmentos.findIndex((s: CotSegmento) => s.id === targetId);

        if (index === -1) {
            await agregarSegmentoIndividual(segmentoMaestro, itinerarioId);
            return;
        }

        if (accion === 'replace') {
            const segAfectado = servicio.cotsegmentos[index];

            if (servicio.cotcomponentes) {
                servicio.cotcomponentes = servicio.cotcomponentes.filter(
                    (c: ComponenteCompleto) => c.cotsegmentoId !== segAfectado.id
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
            const diaDelSegmento = servicio.cotsegmentos[index].dia || 1;

            if (diaDelSegmento > 1) {
                const dateObj = new Date(`${fechaCalculada}T12:00:00Z`);
                dateObj.setUTCDate(dateObj.getUTCDate() + (diaDelSegmento - 1));
                fechaCalculada = dateObj.toISOString().split('T')[0];
            }

            const nuevoSeg: CotSegmento = {
                id: nuevoIdSeg,
                segmentoMaestroId: extractIdStr(segmentoMaestro),
                dia: diaDelSegmento,
                orden: 0,
                fechaAbsoluta: fechaCalculada,
                nombreSnapshot: JSON.parse(JSON.stringify(getTituloSafe(segmentoMaestro))),
                contenidoSnapshot: JSON.parse(JSON.stringify(segmentoMaestro.contenido || [])),
                notasSnapshot: extraerNotasSnapshot(segmentoMaestro),
                imagenesSnapshot: extraerImagenesSnapshot(segmentoMaestro),
                sobreescribirTraduccion: false
            };

            servicio.cotsegmentos.splice(index + 1, 0, nuevoSeg);
            servicio.cotsegmentos.forEach((s: CotSegmento, i: number) => s.orden = i + 1);

            await inyectarComponentesDeSegmento(segmentoMaestro, diaDelSegmento, nuevoIdSeg, itinerarioId);
        }
    };

    const removerCotSegmento = (id: string): void => {
        const servicio = servicioActivo.value;
        if (!servicio) return;
        if (servicio.cotsegmentos) {
            servicio.cotsegmentos = servicio.cotsegmentos.filter((s: CotSegmento) => s.id !== id);
        }
        if (servicio.cotcomponentes) {
            servicio.cotcomponentes = servicio.cotcomponentes.filter((c: ComponenteCompleto) => c.cotsegmentoId !== id && c.cotsegmento !== id);
            sincronizarFechaServicio(servicio);
        }
    };

    const onServicioMaestroChange = async (val: string | null): Promise<void> => {
        const servicio = servicioActivo.value;
        if (!val || val === 'null') {
            catalogos.value.componentes = catalogos.value.allComponentes;
            catalogos.value.plantillasItinerario = [];
            catalogos.value.poolSegmentos = [];
            return;
        }

        const targetId = extractIdStr(val);

        const maestro = catalogos.value.servicios.find((s: Servicio) => extractIdStr(s.id || s['@id']) === targetId);

        if (maestro && servicio) {
            // El NOMBRE (operativo, un idioma) sale del nombreInterno; el TÍTULO (comercial,
            // i18n, cliente) sale del titulo. Son ejes distintos y ya no se copian entre sí.
            servicio.nombreSnapshot = nombreOperativoComoI18n(maestro.nombreInterno);
            servicio.nombrePublicoSnapshot = JSON.parse(JSON.stringify(getTituloSafe(maestro)));
            await fetchServicioDetalles(val);
        }
    };

    const onServicioFechaChange = (): void => {
        const servicio = servicioActivo.value;
        if (!servicio || !servicio.fechaInicioAbsoluta) return;
        const nuevaFechaBase = getFechaLimpia(servicio.fechaInicioAbsoluta);

        let oldFechaBase = '9999-12-31';
        if (servicio.cotcomponentes && servicio.cotcomponentes.length > 0) {
            servicio.cotcomponentes.forEach((c: ComponenteCompleto) => {
                if (c.fechaHoraInicio) {
                    const d = c.fechaHoraInicio.split('T')[0];
                    if (d < oldFechaBase) oldFechaBase = d;
                }
            });
        } else if (servicio.cotsegmentos && servicio.cotsegmentos.length > 0) {
            oldFechaBase = servicio.cotsegmentos[0].fechaAbsoluta;
        }

        if (oldFechaBase === '9999-12-31') oldFechaBase = nuevaFechaBase;

        const diffTime = new Date(`${nuevaFechaBase}T12:00:00Z`).getTime() - new Date(`${oldFechaBase}T12:00:00Z`).getTime();
        const diffDays = Math.round(diffTime / (1000 * 60 * 60 * 24));

        if (servicio.cotcomponentes && Array.isArray(servicio.cotcomponentes)) {
            servicio.cotcomponentes.forEach((comp: ComponenteCompleto) => {
                if (comp.fechaHoraInicio) {
                    const duracionMs = getDuracionMs(comp.fechaHoraInicio, comp.fechaHoraFin);
                    const oldFechaString = comp.fechaHoraInicio.split('T')[0];
                    const horaActual = comp.fechaHoraInicio.split('T')[1] || '08:00';

                    const dateObj = new Date(`${oldFechaString}T12:00:00Z`);
                    dateObj.setUTCDate(dateObj.getUTCDate() + diffDays);
                    const nuevaFechaCompStr = dateObj.toISOString().split('T')[0];

                    comp.fechaHoraInicio = toDateTimeString(nuevaFechaCompStr, horaActual);

                    const nS = parseNaiveAsUTC(comp.fechaHoraInicio);
                    comp.fechaHoraFin = formatNaiveFromUTC(nS + duracionMs);
                }
            });
        }

        if (servicio.cotsegmentos && Array.isArray(servicio.cotsegmentos)) {
            servicio.cotsegmentos.forEach((seg: CotSegmento) => {
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

        if (servicio.cotcomponentes) {
            ordenarComponentesCronologicamente(servicio.cotcomponentes);
        }
    };
    const onComponenteMaestroChange = async (val: string | null): Promise<void> => {
        const componente = componenteActivo.value;
        if (!val || val === 'null') {
            catalogos.value.tarifas = [];
            return;
        }

        const targetId = extractIdStr(val);
        const maestro = catalogos.value.allComponentes.find((c) => extractIdStr(c) === targetId);

        if (isComponenteCompleto(maestro) && componente) {
            componente.tipo = maestro.tipo || 'extras';   // 🔥 snapshot autónomo del tipo
            componente.sinHorario = sinHorarioDeTipo(maestro.tipo);   // 🔥 snapshot del flag de horario
            componente.nombreSnapshot = JSON.parse(JSON.stringify(getTituloSafe(maestro)));

            const reqHora = !componente.sinHorario;
            const fechaDate = componente.fechaHoraInicio.split('T')[0];

            if (reqHora) {
                componente.fechaHoraInicio = toDateTimeString(fechaDate, '08:00');
                componente.fechaHoraFin = addDurationToDate(componente.fechaHoraInicio, maestro.duracion || 0);
            } else {
                componente.fechaHoraInicio = toDateTimeString(fechaDate);
                const endStr = addDurationToDate(componente.fechaHoraInicio, maestro.duracion || 0);
                componente.fechaHoraFin = toDateTimeString(endStr.split('T')[0]);
            }

            if (componente.fechaHoraInicio && componente.fechaHoraFin) {
                componente.cantidad = calcularPernoctes(componente.fechaHoraInicio, componente.fechaHoraFin);
            }

            componente.snapshotItems = [];
            componente.cottarifas = [];

            await fetchComponenteDetalles(val);

        } else if (maestro && componente) {
            await fetchComponenteDetalles(val);
        }
    };

    const onSegmentoDiaChange = (servicioId: string, segmentoId: string, nuevoDiaStr: string | number) => {
        const nuevoDia = parseInt(String(nuevoDiaStr)) || 1;
        if (!cotizacion.value || !cotizacion.value.cotservicios) return;

        const servicio = cotizacion.value.cotservicios.find((s) => s.id === servicioId);
        if (!servicio) return;

        const segmento = servicio.cotsegmentos?.find((s) => s.id === segmentoId);
        if (!segmento) return;

        segmento.dia = nuevoDia;

        const dateObj = new Date(`${getFechaLimpia(servicio.fechaInicioAbsoluta)}T12:00:00Z`);
        dateObj.setUTCDate(dateObj.getUTCDate() + (nuevoDia - 1));
        const nuevaFechaAbs = dateObj.toISOString().split('T')[0];
        segmento.fechaAbsoluta = nuevaFechaAbs;

        if (servicio.cotcomponentes) {
            servicio.cotcomponentes.forEach((comp) => {
                if (idSegmentoDeComponente(comp) === segmentoId) {
                    const duracionMs = getDuracionMs(comp.fechaHoraInicio, comp.fechaHoraFin);

                    if (comp.fechaHoraInicio) comp.fechaHoraInicio = replaceDateKeepTime(comp.fechaHoraInicio, nuevaFechaAbs);

                    if (comp.fechaHoraInicio) {
                        const nS = parseNaiveAsUTC(comp.fechaHoraInicio);
                        comp.fechaHoraFin = formatNaiveFromUTC(nS + duracionMs);
                    }
                }
            });
            ordenarComponentesCronologicamente(servicio.cotcomponentes);
            sincronizarFechaServicio(servicio);
        }
    };
    const actualizarInicioManteniendoRango = (nuevoInicioStr: string): void => {
        const componente = componenteActivo.value;
        if (!componente || !nuevoInicioStr) return;

        const duracionMs = getDuracionMs(componente.fechaHoraInicio, componente.fechaHoraFin);
        componente.fechaHoraInicio = nuevoInicioStr;

        const newStartMs = parseNaiveAsUTC(nuevoInicioStr);
        componente.fechaHoraFin = formatNaiveFromUTC(newStartMs + duracionMs);

        onComponenteFechasChange();
    };


    const onComponenteFechasChange = (): void => {
        const componente = componenteActivo.value;
        if (!componente) return;

        if (!componenteRequiereHora(componente)) {
            if (componente.fechaHoraInicio) componente.fechaHoraInicio = componente.fechaHoraInicio.split('T')[0] + 'T00:00:00';
            if (componente.fechaHoraFin) componente.fechaHoraFin = componente.fechaHoraFin.split('T')[0] + 'T00:00:00';
        }

        if (componente.fechaHoraInicio && componente.fechaHoraFin) {
            componente.cantidad = calcularPernoctes(componente.fechaHoraInicio, componente.fechaHoraFin);
        }

        const servicio = findServicioByComponenteId(componente.id);

        if (servicio && componente.fechaHoraInicio) {
            const nuevaFechaDateStr = componente.fechaHoraInicio.split('T')[0];
            const currentSegId = idSegmentoDeComponente(componente);

            if (currentSegId) {
                const segmentoPadre = servicio.cotsegmentos?.find((s) => s.id === currentSegId);

                if (segmentoPadre && segmentoPadre.fechaAbsoluta !== nuevaFechaDateStr) {
                    segmentoPadre.fechaAbsoluta = nuevaFechaDateStr;
                    segmentoPadre.dia = calcularDiaRelativo(getFechaLimpia(servicio.fechaInicioAbsoluta), nuevaFechaDateStr);

                    servicio.cotcomponentes?.forEach((comp) => {
                        if (idSegmentoDeComponente(comp) === segmentoPadre.id && comp.id !== componente.id) {
                            const duracionMs = getDuracionMs(comp.fechaHoraInicio, comp.fechaHoraFin);
                            comp.fechaHoraInicio = replaceDateKeepTime(comp.fechaHoraInicio, nuevaFechaDateStr);

                            const nS = parseNaiveAsUTC(comp.fechaHoraInicio);
                            comp.fechaHoraFin = formatNaiveFromUTC(nS + duracionMs);
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
     * - Sobreescribe múltiples propiedades de `componente` (montoCosto, moneda, esGrupal, etc.).
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
        const tarifa = tarifaActiva.value;
        const targetId = extractIdStr(val);

        // La tarifa maestra se identifica por `tarifaId` (id del maestro) o por su
        // IRI; `extractIdStr` mira ambas, así que basta con pasarle el objeto.
        const coincide = (t: Tarifa): boolean => extractIdStr(t) === targetId || extractIdStr(t.tarifaId) === targetId;
        const maestro = catalogos.value.tarifas.find(coincide) || todasLasTarifasMaestras.value.find(coincide);

        if (maestro && tarifa) {

            const rol = getRolTarifa(maestro);

            tarifa.tituloSnapshot = JSON.parse(JSON.stringify(getTituloSafe(maestro)));
            tarifa.nombreInternoSnapshot = maestro.nombreInterno || null;

            if (typeof maestro.moneda === 'object' && maestro.moneda !== null) {
                tarifa.moneda = maestro.moneda.id || maestro.moneda.nombre || 'USD';
            } else {
                tarifa.moneda = maestro.moneda || 'USD';
            }

            tarifa.montoCosto = parseFloat(maestro.monto || '0');


            tarifa.rolSnapshot = rol;
            tarifa.comisionOverrideSnapshot = rol === 'operativo' ? '0.00' : getComisionOverrideTarifa(maestro);
            tarifa.grupoTarifa = rol === 'operativo' ? null : (tarifa.grupoTarifa ?? 1);

            tarifa.modalidadSnapshot = maestro.modalidad || null;
            tarifa.categoriaSnapshot = maestro.categoria || null;
            tarifa.procedenciaSnapshot = maestro.procedencia || null;
            tarifa.edadMinimaSnapshot = maestro.edadMinima ?? null;
            tarifa.edadMaximaSnapshot = maestro.edadMaxima ?? null;

            if (maestro.costoPorGrupo) {
                tarifa.cantidad = 1;
                tarifa.esGrupal = true;
            } else {
                tarifa.esGrupal = false;
            }

            tarifa.nombreParaProveedorSnapshot = maestro.nombreParaPrestador || null;

            // 🏷️ Los tres papeles, congelados al elegir la tarifa. No se pintan en el
            // formulario a propósito: son referencia histórica, no campos que se editen.
            const prov = papelesDeTarifaMaestra(maestro);
            tarifa.prestadorMaestroId = prov.prestadorMaestroId;
            tarifa.prestadorNombreSnapshot = prov.prestadorNombreSnapshot;
            tarifa.prestadorServicioMaestroId = prov.prestadorServicioMaestroId;
            tarifa.prestadorServicioNombreSnapshot = prov.prestadorServicioNombreSnapshot;
            tarifa.compradorMaestroId = prov.compradorMaestroId;
            tarifa.compradorNombreSnapshot = prov.compradorNombreSnapshot;

            // Y la LÍNEA toma de la tarifa los papeles que todavía no tenga: el primero que
            // llega manda. Lo que ya estaba NO se pisa —la mezcla la avisa `desajusteDeTarifa`
            // en la vista, que llega antes y deja decidir a la persona—.
            sembrarPapelesEnLinea(componenteActualDeTarifa.value, prov);
        }
    };

    /**
     * Carga los servicios (livianos: id+nombre) del proveedor seleccionado.
     * Se dispara cada vez que cambia el proveedor elegido en la tarifa, para alimentar
     * el dropdown filtrado de OrganizacionServicio.
     */
    const fetchProveedorServiciosDeProveedor = async (proveedorId: string | null, gen?: number) => {
        if (!proveedorId) {
            catalogos.value.proveedorServicios = [];
            return;
        }
        try {
            const res = await apiClient.get(`/platform/travel/organizacion-servicios?organizacion=${proveedorId}&pagination=false`);
            if (gen !== undefined && gen !== navGen) return;
            const raw = miembrosHydra<RecursoHydra & { nombre?: string }>(res.data);
            catalogos.value.proveedorServicios = raw.map((ps) => ({
                id: extractIdStr(ps),
                nombre: ps.nombre ?? '',
                proveedorId
            }));
        } catch (e) {
            console.error('Error cargando servicios del proveedor', e);
            catalogos.value.proveedorServicios = [];
        }
    };

    /**
     * Copia el prestador del componente maestro al instanciarlo en la cotización.
     *
     * Sólo enlace + nombre: lo demás se resuelve contra el catálogo cuando hace falta.
     */
    const sembrarPrestadorDesdeMaestro = (
        compMaestro: unknown
    ): Partial<ComponenteCompleto> & { prestadorVisible: boolean } => {
        const provRaw = (compMaestro as { proveedor?: unknown })?.proveedor;
        const provId = provRaw ? extractIdStr(provRaw) : null;
        const prov = provId ? catalogos.value.proveedores.find((p) => extractIdStr(p) === provId) : null;

        if (!provId) {
            return { prestadorVisible: false };
        }

        const servRaw = (compMaestro as { proveedorServicio?: unknown })?.proveedorServicio;

        return {
            prestadorMaestroId: provId,
            prestadorNombreSnapshot: prov?.nombreComercial ?? null,
            prestadorServicioMaestroId: servRaw ? extractIdStr(servRaw) : null,
            prestadorVisible: Boolean(prov?.visibleParaCliente),
        };
    };

    /**
     * Asigna el prestador. Sólo se guarda el enlace y el nombre: lo demás se resuelve
     * contra el catálogo cuando hace falta, así que copiarlo aquí sería crear una segunda
     * versión del mismo dato que envejece sola.
     *
     * El nombre sí se guarda, y no es una copia por si acaso: es lo que queda si borran la
     * empresa del catálogo.
     */
    const onPrestadorComponenteChange = (val: string | null): void => {
        const componente = componenteEnEdicion.value;
        if (!componente) return;

        // Cambiar de empresa invalida su servicio: son catálogos encadenados.
        componente.prestadorServicioMaestroId = null;
        componente.prestadorServicioNombreSnapshot = null;
        catalogos.value.proveedorServicios = [];

        if (!val || val === 'null') {
            componente.prestadorMaestroId = null;
            componente.prestadorNombreSnapshot = null;
            componente.prestadorVisible = false;

            return;
        }

        const targetId = extractIdStr(val);
        const prov = catalogos.value.proveedores.find((p) => extractIdStr(p) === targetId);
        if (!prov) return;

        componente.prestadorMaestroId = targetId;
        componente.prestadorNombreSnapshot = prov.nombreComercial ?? null;
        // Semilla: el maestro dice si a esta empresa se le puede nombrar siquiera.
        componente.prestadorVisible = Boolean(prov.visibleParaCliente);

        void fetchProveedorServiciosDeProveedor(targetId);
    };

    /**
     * Da de alta un `Organizacion` desde el editor y lo deja asignado como prestador.
     *
     * Existe para que el prestador quede SIEMPRE identificado contra el maestro, que es la
     * regla que declara `Organizacion::POST`. Sin esto, la salida rápida cuando la empresa no
     * está en el catálogo era escribir texto libre — y eso deja `prestadorMaestroId` vacío,
     * lo saca de los filtros y rompe el histórico financiero.
     */
    const crearPrestadorYAsignar = async (datos: ProveedorWrite): Promise<boolean> => {
        const componente = componenteEnEdicion.value;
        if (!componente || !datos.nombreComercial.trim()) return false;

        try {
            const res = await apiClient.post('/platform/travel/organizaciones', datos);
            const creado = res.data;
            const id = extractIdStr(creado?.id || creado?.['@id'] || '');
            if (!id) return false;

            // Entra al catálogo en memoria para que el selector lo encuentre sin recargar.
            catalogos.value.proveedores = [creado, ...catalogos.value.proveedores];
            onPrestadorComponenteChange(id);

            return true;
        } catch (e) {
            alert(extractApiErrorMessage(e, 'No se pudo crear el proveedor.'));

            return false;
        }
    };

    /**
     * A quién se le encarga la compra. Cascada corta: `componente → prestador`. Si nadie
     * la encargó, se le pide a quien presta, que es el caso normal.
     */
    const resolverComprador = (
        componente: ComponenteCompleto
    ): { origen: 'componente' | 'prestador'; nombre: string | null } | null => {
        if (componente.compradorMaestroId || (componente.compradorNombreSnapshot || '').trim()) {
            return { origen: 'componente', nombre: componente.compradorNombreSnapshot ?? null };
        }

        if (componente.prestadorMaestroId || (componente.prestadorNombreSnapshot || '').trim()) {
            return { origen: 'prestador', nombre: componente.prestadorNombreSnapshot ?? null };
        }

        return null;
    };

    /** Asigna el comprador. Sale del mismo catálogo que el prestador: son empresas. */
    const onCompradorChange = (val: string | null): void => {
        const componente = componenteEnEdicion.value;
        if (!componente) return;

        if (!val || val === 'null') {
            componente.compradorMaestroId = null;
            componente.compradorNombreSnapshot = null;

            return;
        }

        const targetId = extractIdStr(val);
        const prov = catalogos.value.proveedores.find((p) => extractIdStr(p) === targetId);

        componente.compradorMaestroId = targetId;
        componente.compradorNombreSnapshot = prov?.nombreComercial ?? null;
    };

    /**
     * Elige el servicio del prestador (ej. el tipo de habitación).
     *
     * Guarda enlace + nombre y nada más: título e imágenes se resuelven contra el catálogo
     * al servir. Antes esto pedía el detalle completo para congelarlo, y era una llamada
     * por cada vez que se cambiaba de servicio.
     */
    const onProveedorServicioChange = (val: string | null): void => {
        const componente = componenteEnEdicion.value;
        if (!componente) return;

        if (!val || val === 'null') {
            componente.prestadorServicioMaestroId = null;
            componente.prestadorServicioNombreSnapshot = null;

            return;
        }

        const targetId = extractIdStr(val);
        const opcion = catalogos.value.proveedorServicios.find((ps) => ps.id === targetId);

        componente.prestadorServicioMaestroId = targetId;
        componente.prestadorServicioNombreSnapshot = opcion?.nombre ?? null;
    };

    /**
     * Prestador que aplica a la tarifa que se está editando, para filtrar el
     * selector de proveedores. Devuelve null si nadie lo fijó — entonces no hay nada
     * que filtrar y se ven todos.
     */
    const prestadorEsperadoDeTarifaActiva = computed<{ maestroId: string | null; nombre: string | null } | null>(() => {
        // Ya no mira el día: ese default heredable se retiró junto con la cascada.
        const componente = componenteActualDeTarifa.value;

        if (componente && (componente.prestadorMaestroId || (componente.prestadorNombreSnapshot || '').trim())) {
            return { maestroId: componente.prestadorMaestroId ?? null, nombre: componente.prestadorNombreSnapshot ?? null };
        }

        return null;
    });

    const actualizarTextosSegmentos = async (): Promise<void> => {
        const servicio = servicioActivo.value;
        if (!servicio || !servicio.cotsegmentos || servicio.cotsegmentos.length === 0) return;

        // Extraer IDs maestros únicos de los segmentos actuales en la vista
        const idsToFetch: string[] = Array.from(new Set(
            servicio.cotsegmentos
                .map((s: CotSegmento) => s.segmentoMaestroId)
                .filter((id): id is string => !!id)
        ));

        if (idsToFetch.length === 0) {
            alert("Los segmentos actuales no tienen vinculación con un maestro. Aplica la plantilla de nuevo para vincularlos.");
            return;
        }

        isLoading.value = true;
        try {
            // Petición al endpoint en formato id[]=...&id[]=...
            const idsParam = idsToFetch.map((id) => `id[]=${id}`).join('&');
            const res = await apiClient.get(`/platform/travel/segmentos?${idsParam}&pagination=false`);
            const segmentosMaestros = miembrosHydra<SegmentoMaestro>(res.data);

            // Crear diccionario de maestros para búsqueda O(1)
            const mapaMaestros = new Map<string, SegmentoMaestro>();
            segmentosMaestros.forEach((seg) => {
                mapaMaestros.set(extractIdStr(seg), seg);
            });

            // Id de la plantilla con la que se armó el servicio (si se conoce): permite
            // el match exacto de la fila TravelSegmentoComponente, igual que la inyección.
            const itinId = extractIdStr(servicio.itinerarioMaestroId);

            // Elige, para un componente ya inyectado, la fila TravelSegmentoComponente
            // del maestro que aporta su configuración.
            const resolverSegCompDeComponente = (
                segComps: SegmentoComponenteProcesado[],
                componenteMaestroId: string | null | undefined,
                dia: number | null | undefined,
            ): SegmentoComponenteProcesado | null => {
                const targetId = extractIdStr(componenteMaestroId);
                if (!targetId) return null;
                const candidatos = segComps.filter((sc) => {
                    const cId = extractIdStr(sc.componente);
                    if (cId !== targetId) return false;
                    return sc.dia === undefined || sc.dia === null || sc.dia === dia;
                });
                if (!candidatos.length) return null;

                if (itinId) {
                    // Match exacto (como en la inyección): se excluyen filas ligadas a
                    // OTRA plantilla; las de esta plantilla mandan sobre las globales.
                    const aplicables = candidatos.filter((sc) =>
                        !sc.itinerarioContexto || extractIdStr(sc.itinerarioContexto) === itinId);
                    if (!aplicables.length) return null;
                    const deLaPlantilla = aplicables.filter((sc) => extractIdStr(sc.itinerarioContexto) === itinId);
                    const grupo = deLaPlantilla.length ? deLaPlantilla : aplicables;
                    return grupo.find((sc) => sc.horaServicioCompleto) || grupo[0];
                }

                // Fallback (servicios previos a itinerarioMaestroId): mejor esfuerzo —
                // prioriza filas ligadas a plantilla y, entre ellas, la promovida.
                const ligadasAPlantilla = candidatos.filter((sc) => sc.itinerarioContexto);
                const grupo = ligadasAPlantilla.length ? ligadasAPlantilla : candidatos;
                return grupo.find((sc) => sc.horaServicioCompleto) || grupo[0];
            };

            // Actualizar estrictamente los textos, imágenes y el flag de "hora de
            // servicio completo". NO se tocan tarifas, fechas/horas ni el modo
            // comercial, y NO se elimina ningún componente que ya no figure en los
            // segmentos maestros (los sin coincidencia quedan intactos).
            const componentesDelServicio: ComponenteCompleto[] = servicio.cotcomponentes || [];
            servicio.cotsegmentos.forEach((cotSeg: CotSegmento) => {
                const maestro = cotSeg.segmentoMaestroId ? mapaMaestros.get(cotSeg.segmentoMaestroId) : undefined;
                if (maestro) {
                    cotSeg.nombreSnapshot = JSON.parse(JSON.stringify(getTituloSafe(maestro)));
                    cotSeg.contenidoSnapshot = JSON.parse(JSON.stringify(maestro.contenido || []));
                    cotSeg.notasSnapshot = extraerNotasSnapshot(maestro);
                    cotSeg.imagenesSnapshot = extraerImagenesSnapshot(maestro);

                    const segComps = Array.isArray(maestro.segmentoComponentes) ? maestro.segmentoComponentes : [];
                    componentesDelServicio
                        .filter((comp) => comp.cotsegmentoId === cotSeg.id)
                        .forEach((comp) => {
                            const segComp = resolverSegCompDeComponente(segComps, comp.componenteMaestroId, cotSeg.dia);
                            if (segComp) {
                                comp.horaServicioCompleto = !!segComp.horaServicioCompleto;
                            }
                        });
                }
            });

        } catch (error) {
            console.error("Error al actualizar textos de los segmentos:", error);
            alert("Ocurrió un error al actualizar los textos del storytelling.");
        } finally {
            isLoading.value = false;
        }
    };


    // ── Servicio mono-segmento sin plantilla ────────────────────────────────────
    // El nombre del servicio es genérico y su público se descarta en la vista del cliente (pax
    // sólo pinta el nombre del servicio si tiene >1 segmento); el segmento es quien carga el
    // significado. Las vistas de OPERADOR lo detectan para mostrar en read-only el título/nombre
    // del segmento en vez del genérico. Reactivo: aplicar plantilla (setea itinerarioMaestroId) o
    // añadir un 2º segmento (sube el length) lo desactiva solo.
    const esMonoSegmentoSinPlantilla = (servicio: CotServicio | null | undefined): boolean =>
        !!servicio && servicio.cotsegmentos?.length === 1 && !servicio.itinerarioMaestroId;

    // Maestro del único segmento, para leer su `nombreInterno` (no vive en el snapshot). Tolera
    // `poolSegmentos` vacío (carga fallida) → null.
    const segmentoUnicoMaestro = (servicio: CotServicio | null | undefined): Segmento | null => {
        if (!esMonoSegmentoSinPlantilla(servicio)) return null;
        const seg = servicio!.cotsegmentos![0];
        const maestroId = extractIdStr(seg.segmentoMaestroId);
        if (!maestroId) return null;
        return catalogos.value.poolSegmentos.find((s: Segmento) => extractIdStr(s) === maestroId) ?? null;
    };

    return {
        catalogos, cotizacion, fileActual, modoCatalogo, idiomasDisponibles, isLoading, inspectorActivo, dataActiva,
        esMonoSegmentoSinPlantilla, segmentoUnicoMaestro,
        // Vistas tipadas del nodo abierto: es por donde deben leerlo las vistas.
        servicioActivo, componenteActivo, tarifaActiva,
        isMobileOpen, isSegmentEditorOpen, tipoCambioSugerido, todasLasTarifasMaestras,
        resumenFinanciero, gruposUpgrade, itinerarioDinamico, totalCostoNeto, ventaSugerida,
        getTipoComponente, requiereHoraExacta, componenteRequiereHora, sinHorarioDeTipo, calcularPernoctes,
        isComponenteConAlerta, isServicioConAlerta, getI18nText, setI18nText, getTarifaLabel, getTarifaSublabel, getProveedorDeTarifa, getPapelesDeTarifa, extractIdStr,
        inicializarEditor, guardarCotizacion, abrirNivel, retrocederNivel, cerrarInspectorMobile,
        updateNumPaxGlobal, agregarServicio, eliminarServicio, agregarComponente, eliminarComponente,
        agregarSnapshotItem, eliminarSnapshotItem, toggleUpsellComponent, isComponenteBloqueado,
        agregarTarifa, eliminarTarifa, fetchComponenteMaestroSilencioso,
        abrirEditorSegmentos, cerrarEditorSegmentos, aplicarPlantilla,
        actualizarTextosSegmentos,
        agregarSegmentoIndividual, reordenarSegmentos, procesarInsercionSegmento, removerCotSegmento,
        onServicioMaestroChange, onServicioFechaChange, onComponenteMaestroChange,
        onComponenteFechasChange, onSegmentoDiaChange, onTarifaMaestraChange, onCambioModoComponente,
        actualizarInicioManteniendoRango, agregarDetalleOperativo, eliminarDetalleOperativo,
        fetchProveedorServiciosDeProveedor, onProveedorServicioChange, limpiarServicioProveedor, marcarTarifaComoEstandar,
        componenteActualDeTarifa, componenteEnEdicion, tarifasHermanas, irATarifaAdyacente,
        servicioActualDeComponente, componentesHermanos, irAComponenteAdyacente, serviciosOrdenados, irAServicioAdyacente, historialNavegacion,
        buscarServiciosAsincrono, buscarProveedoresAsincrono,
        onPrestadorComponenteChange, crearPrestadorYAsignar, prestadorEsperadoDeTarifaActiva, resolverPrestador,
        resolverComprador, onCompradorChange
    };
});