// src/types/operacionModel.ts
// ============================================================================
// Tipos del módulo de Operaciones anclados a api.d.ts.
//
// Endpoints (routePrefix: '/ops'):
//   /platform/ops/operacion_orden_servicios
//   /platform/ops/operacion_servicios      (#[ApiFilter] by ordenServicio, file)
//   /platform/ops/operacion_mensajes       (#[ApiFilter] by ordenServicio)
//
// Notas de diseño:
//  - MaestroMoneda-operacion.write = Record<string,never> en el schema porque
//    la entidad no expone campos en operacion:write; en runtime se pasa IRI string
//    con la forma '/platform/maestro/monedas/{id}'.
//  - `file` SÍ trae nombreGrupo y pasajeroPrincipal: CotizacionFile los publica en
//    el grupo operacion:item:read justamente para que La Biblia sepa de quién es
//    cada fila. Cottarifa/Cotservicio/Cotcomponente siguen siendo stubs (solo
//    @id + timestamps); su información útil está denormalizada en el snapshot
//    (contextoServicio, tipoComponente, modoComponente, estadoComponente).
//  - operacionServicios dentro de OperacionOrdenServicio son stubs vacíos en
//    operacion:read (campos de OperacionServicio usan operacion:item:read);
//    filtrar por ordenServicio para datos completos.
// ============================================================================

import { components } from '@/types/api';
import { EstadoUIConfig } from '@/types/cotizacionEditorModel';

// ============================================================================
// TIPOS ANCLADOS A LOS SCHEMAS
// ============================================================================

export type OperacionOrdenServicio = components['schemas']['OperacionOrdenServicio-operacion.read_timestamp.read'];
export type OperacionServicio      = components['schemas']['OperacionServicio-operacion.item.read_timestamp.read'];
export type OperacionMensaje       = components['schemas']['OperacionMensaje-operacion.mensaje.read_timestamp.read'];

// MaestroMoneda con los campos que expone en contexto de operacion
export type MaestroMonedaOperacion = components['schemas']['Moneda-operacion.read_timestamp.read'];

// ============================================================================
// TIPOS DE ESCRITURA
// Los schemas de write tienen moneda como Record<string,never> porque la entidad
// no expone campos en operacion:write; en la práctica se envía IRI string.
// Se sobreescriben esos campos con string y se normaliza ordenServicio a IRI.
// ============================================================================

export type OperacionOrdenServicioWrite = Omit<
    components['schemas']['OperacionOrdenServicio-operacion.write'],
    'monedaOs'
> & {
    /**
     * IRI, p.ej. '/platform/maestro/maestro_monedas/PEN'. **Opcional**: el importe de la
     * orden dejó de fijarse en la cabecera —vive en cada ítem con su propia moneda—, así que
     * una orden nace sin total ni moneda y esto sólo se usa como apunte de conciliación.
     */
    monedaOs?: string;
};

export type OperacionServicioWrite = Omit<
    components['schemas']['OperacionServicio-operacion.write'],
    'ordenServicio' | 'monedaCotizada' | 'monedaNegociada'
> & {
    ordenServicio?: string | null;  // IRI OperacionOrdenServicio
    monedaCotizada: string;         // IRI MaestroMoneda
    monedaNegociada: string;             // IRI MaestroMoneda — la NEGOCIADA, editable por ítem
};

export type OperacionMensajeWrite = Omit<
    components['schemas']['OperacionMensaje-operacion.write'],
    'ordenServicio'
> & {
    ordenServicio: string;          // IRI OperacionOrdenServicio
};

// ============================================================================
// TIPOS DE ENUM (derivados del schema para mantenerse sincronizados)
// ============================================================================

export type EstadoOsValue       = OperacionOrdenServicio['estadoOs'];
export type EstadoReservaProveedorValue  = OperacionServicio['estadoReservaProveedor'];
export type EstadoOperacionValue = OperacionServicio['estadoOperacion'];

// ============================================================================
// CONFIGS UI
// ============================================================================

export const ESTADO_OS_CONFIG: Record<EstadoOsValue, EstadoUIConfig> = {
    borrador:   { label: 'Borrador',   bg: 'bg-slate-100',  text: 'text-slate-500',   border: 'border-slate-200',   icon: 'fa-pencil' },
    emitida:    { label: 'Emitida',    bg: 'bg-amber-50',   text: 'text-amber-700',   border: 'border-amber-200',   icon: 'fa-paper-plane' },
    confirmada: { label: 'Confirmada', bg: 'bg-emerald-50', text: 'text-emerald-700', border: 'border-emerald-200', icon: 'fa-check' },
    completada: { label: 'Completada', bg: 'bg-blue-50',    text: 'text-blue-700',    border: 'border-blue-200',    icon: 'fa-flag-checkered' },
    cancelada:  { label: 'Cancelada',  bg: 'bg-rose-50',    text: 'text-rose-700',    border: 'border-rose-200',    icon: 'fa-times-circle' },
};

export const ESTADO_RESERVA_PROVEEDOR_CONFIG: Record<EstadoReservaProveedorValue, EstadoUIConfig> = {
    'sin-solicitar':  { label: 'Sin Solicitar',  bg: 'bg-slate-100',  text: 'text-slate-500',   border: 'border-slate-200',   icon: 'fa-circle-minus' },
    'solicitado':     { label: 'Solicitado',     bg: 'bg-amber-50',   text: 'text-amber-700',   border: 'border-amber-200',   icon: 'fa-paper-plane' },
    'confirmado':     { label: 'Confirmado',     bg: 'bg-emerald-50', text: 'text-emerald-700', border: 'border-emerald-200', icon: 'fa-check' },
    'reconfirmado':   { label: 'Reconfirmado',   bg: 'bg-teal-50',    text: 'text-teal-700',    border: 'border-teal-200',    icon: 'fa-check-double' },
    'pendiente-pago': { label: 'Pendiente Pago', bg: 'bg-red-50',     text: 'text-red-700',     border: 'border-red-200',     icon: 'fa-money-bill-wave' },
};

export const ESTADO_OPERACION_CONFIG: Record<EstadoOperacionValue, EstadoUIConfig> = {
    'pendiente':  { label: 'Pendiente',  bg: 'bg-amber-50',   text: 'text-amber-700',   border: 'border-amber-200',   icon: 'fa-clock' },
    'en-proceso': { label: 'En Proceso', bg: 'bg-sky-50',     text: 'text-sky-700',     border: 'border-sky-200',     icon: 'fa-gears' },
    'completado': { label: 'Completado', bg: 'bg-emerald-50', text: 'text-emerald-700', border: 'border-emerald-200', icon: 'fa-check' },
    'cancelado':  { label: 'Cancelado',  bg: 'bg-rose-50',    text: 'text-rose-700',    border: 'border-rose-200',    icon: 'fa-times-circle' },
};

// ============================================================================
// TIPO DE COMPONENTE — espejo de App\Travel\Enum\ComponenteTipoEnum
//
// ⚠️ Espejo: si añades un case en ComponenteTipoEnum (PHP) añádelo también aquí.
// `prioridad` NO se replica: viene calculada del backend en `prioridadOperativa`
// (OperacionServicio::getPrioridadOperativa()), que es la única fuente de verdad.
// ============================================================================

export interface TipoComponenteConfig {
    label: string;
    icon: string;
    /** Color del icono en la fila; el badge de estado va aparte. */
    text: string;
}

export const TIPO_COMPONENTE_CONFIG: Record<string, TipoComponenteConfig> = {
    // El contacto va primero, como en `ComponenteTipoEnum::prioridad()`: es lo que ocurre antes
    // que nada ese día. ⚠️ No es el guiado — el guiado de Machu Picchu ocurre arriba y el contacto
    // en la estación o el hotel, a cuatro horas. Icono distinto a propósito.
    contacto:               { label: 'Contacto',     icon: 'fas fa-handshake-angle',   text: 'text-emerald-600' },
    transporte:             { label: 'Transporte',   icon: 'fas fa-van-shuttle',       text: 'text-sky-600' },
    guiado:                 { label: 'Guiado',       icon: 'fas fa-person-chalkboard', text: 'text-indigo-600' },
    pool:                   { label: 'Pool',         icon: 'fas fa-people-group',      text: 'text-teal-600' },
    privada:                { label: 'Privada',      icon: 'fas fa-user-group',        text: 'text-teal-700' },
    tren:                   { label: 'Tren',         icon: 'fas fa-train',             text: 'text-purple-600' },
    vuelo:                  { label: 'Vuelo',        icon: 'fas fa-plane',             text: 'text-blue-600' },
    alojamiento:            { label: 'Alojamiento',  icon: 'fas fa-bed',               text: 'text-amber-600' },
    alimentacion_fijo:      { label: 'Comida',       icon: 'fas fa-utensils',          text: 'text-orange-600' },
    alimentacion_variable:  { label: 'Comida',       icon: 'fas fa-mug-saucer',        text: 'text-orange-500' },
    ticket_fijo:            { label: 'Ticket',       icon: 'fas fa-ticket',            text: 'text-rose-600' },
    ticket_variable:        { label: 'Ticket',       icon: 'fas fa-ticket-simple',     text: 'text-rose-500' },
    personal_extra:         { label: 'Personal',     icon: 'fas fa-user-plus',         text: 'text-slate-600' },
    extras:                 { label: 'Extra',        icon: 'fas fa-plus',              text: 'text-slate-500' },
};

const TIPO_DESCONOCIDO: TipoComponenteConfig = {
    label: 'Sin tipo', icon: 'fas fa-circle-question', text: 'text-slate-400',
};

export const getTipoComponenteConfig = (v?: string | null): TipoComponenteConfig =>
    TIPO_COMPONENTE_CONFIG[v ?? ''] ?? TIPO_DESCONOCIDO;

/** Opciones para el selector de tipo, en el orden en que se declaran arriba. */
export const TIPOS_COMPONENTE = Object.entries(TIPO_COMPONENTE_CONFIG)
    .map(([value, cfg]) => ({ value, label: cfg.label, icon: cfg.icon }));

// ============================================================================
// MODO Y ESTADO DEL COMPONENTE — espejo de ComponenteModoEnum / ComponenteEstadoEnum
//
// Se muestran en La Biblia porque el listener NO los filtra: entra todo lo que
// tenga tarifa y fecha, incluidos no_incluido, cortesía, reemplazado y cancelado.
// Verlos es el paso previo a decidir qué se deja de generar. Ver docs/Operacion.md §3.
// ============================================================================

export const MODO_COMPONENTE_CONFIG: Record<string, EstadoUIConfig> = {
    incluido:    { label: 'Incluido',    bg: 'bg-emerald-50', text: 'text-emerald-700', border: 'border-emerald-200', icon: 'fa-check' },
    no_incluido: { label: 'No incluido', bg: 'bg-slate-100',  text: 'text-slate-500',   border: 'border-slate-200',   icon: 'fa-ban' },
    cortesia:    { label: 'Cortesía',    bg: 'bg-violet-50',  text: 'text-violet-700',  border: 'border-violet-200',  icon: 'fa-gift' },
    reemplazado: { label: 'Reemplazado', bg: 'bg-rose-50',    text: 'text-rose-700',    border: 'border-rose-200',    icon: 'fa-arrow-right-arrow-left' },
};

/**
 * Espejo de `ComponenteEstadoEnum` — cómo estaba el componente EN LA COTIZACIÓN al
 * confirmarla. Nada que ver con `ESTADO_RESERVA_PROVEEDOR_CONFIG`, que es lo que el proveedor
 * ha confirmado y sí se edita aquí. Ver docs/Cotizaciones.md §3.b.
 */
export const ESTADO_COMPONENTE_CONFIG: Record<string, EstadoUIConfig> = {
    activo:    { label: 'Activo',    bg: 'bg-emerald-50', text: 'text-emerald-700', border: 'border-emerald-200', icon: 'fa-check' },
    cancelado: { label: 'Cancelado', bg: 'bg-rose-50',    text: 'text-rose-700',    border: 'border-rose-200',    icon: 'fa-times-circle' },
};

export const getModoComponenteConfig = (v?: string | null): EstadoUIConfig | null =>
    v ? (MODO_COMPONENTE_CONFIG[v] ?? null) : null;

export const getEstadoComponenteConfig = (v?: string | null): EstadoUIConfig | null =>
    v ? (ESTADO_COMPONENTE_CONFIG[v] ?? null) : null;

// ============================================================================
// FILTROS DE LA BIBLIA
//
// Los nombres de los parámetros de query viven aquí y no repartidos por la vista:
// dependen de qué #[ApiFilter] declara OperacionServicio (DateFilter usa
// fechaServicio[after]/[before], SearchFilter usa el nombre pelado) y cambiarlos
// en el backend obliga a tocar un solo sitio.
// ============================================================================

export interface FiltrosBiblia {
    /** 'YYYY-MM-DD' inclusive. */
    desde?: string;
    /** 'YYYY-MM-DD' inclusive. */
    hasta?: string;
    /** UUID del expediente. */
    fileId?: string;
    /** UUID de la cotización. */
    cotizacionId?: string;
    tipos?: string[];
    estadoReservaProveedor?: string;
    estadoOperacion?: string;
    /**
     * UUIDs de `TravelLugar`, o el centinela `SIN_LUGAR`. Varios se combinan en OR.
     * No es un SearchFilter: lo resuelve `OperacionServicioLugarExtension`, que cruza a
     * Travel porque Operaciones no tiene relación con el catálogo.
     */
    lugares?: string[];
}

/**
 * Centinela del chip «Sin etiqueta»: componentes tecleados a mano en la cotización (sin
 * `componenteMaestroId`) o cuyo maestro no está etiquetado. Sin este chip desaparecerían
 * del cuadro al filtrar por lugar, sin ningún aviso — y con ellos, de la orden de servicio.
 * Debe coincidir con `OperacionServicioLugarExtension::SIN_LUGAR`.
 */
export const SIN_LUGAR = '__sin_lugar__';

/** Opción de `/platform/travel/lugares`. */
export interface LugarOpcion {
    id: string;
    nombre: string;
}

export const construirParamsBiblia = (f: FiltrosBiblia): Record<string, string | string[]> => {
    const params: Record<string, string | string[]> = { itemsPerPage: '200' };

    // DateFilter: [after]/[before] son inclusivos (>=, <=); los strictly_* no lo son.
    if (f.desde) params['fechaServicio[after]'] = f.desde;
    if (f.hasta) params['fechaServicio[before]'] = f.hasta;

    if (f.fileId) params['file'] = f.fileId;
    if (f.cotizacionId) params['cotizacionServicio.cotizacion'] = f.cotizacionId;
    if (f.estadoReservaProveedor) params['estadoReservaProveedor'] = f.estadoReservaProveedor;
    if (f.estadoOperacion) params['estadoOperacion'] = f.estadoOperacion;

    // SearchFilter admite multivalor con la forma prop[]=a&prop[]=b
    if (f.tipos?.length) params['tipoComponente[]'] = f.tipos;

    // Misma forma multivalor, pero lo atiende una query extension, no un SearchFilter.
    if (f.lugares?.length) params['lugar[]'] = f.lugares;

    return params;
};

// ============================================================================
// RECONCILIACIÓN — plan → revisión → aplicar
//
// Espejo de los DTO de `src/Operacion/ApiPlatform/Dto/` (grupo `operacion:plan:read`).
// Se escriben a mano y no se anclan a api.d.ts porque los nombres que genera OpenAPI
// para outputs de operaciones custom (`Cotizacion.PlanReconciliacion-operacion.plan.read`)
// cambian en cuanto se toca la operación. Si añades un campo al DTO, añádelo aquí.
// ============================================================================

/** Un campo que la cotización quiere cambiar. La aprobación es por campo, no por fila. */
export interface CampoPropuesto {
    campo: string;
    etiqueta: string;
    valorActual: string | null;
    valorPropuesto: string | null;
    /** Lo editó alguien en Operaciones: llega desmarcado y decide una persona. */
    enConflicto: boolean;
}

export type TipoCambio = 'crear' | 'actualizar' | 'huerfano';

export interface CambioPropuesto {
    id: string;
    tipo: TipoCambio;
    descripcion: string;
    contexto: string | null;
    fecha: string | null;
    campos: CampoPropuesto[];
    /** Sin casilla: en una OS emitida o con costo real. Ni se ofrece. */
    bloqueado: boolean;
    motivo: string | null;
    aprobadoPorDefecto: boolean;
    /** En una OS emitida: nada se premarca, ni los campos sin conflicto. */
    enOrdenServicio: boolean;
}

export interface PlanReconciliacion {
    /** Hash del estado leído. `aplicar` lo rechaza si la realidad se movió. */
    firma: string;
    cambios: CambioPropuesto[];
    sinCambios: number;
    mensaje: string;
}

export interface ResultadoAplicacion {
    creados: number;
    actualizados: number;
    borrados: number;
    omitidos: number;
    mensaje: string;
}

/** Body de `aplicar`: `idDelCambio → campos aprobados`. Lo que no aparece, no se aplica. */
export interface AplicarPlanPayload {
    firma: string;
    aprobados: Record<string, string[]>;
}

export const getEstadoOsConfig = (v?: string | null): EstadoUIConfig =>
    ESTADO_OS_CONFIG[(v as EstadoOsValue) || 'borrador'] ?? ESTADO_OS_CONFIG.borrador;

export const getEstadoReservaProveedorConfig = (v?: string | null): EstadoUIConfig =>
    ESTADO_RESERVA_PROVEEDOR_CONFIG[(v as EstadoReservaProveedorValue) || 'sin-solicitar'] ?? ESTADO_RESERVA_PROVEEDOR_CONFIG['sin-solicitar'];

export const getEstadoOperacionConfig = (v?: string | null): EstadoUIConfig =>
    ESTADO_OPERACION_CONFIG[(v as EstadoOperacionValue) || 'pendiente'] ?? ESTADO_OPERACION_CONFIG.pendiente;

// ── Dónde recoge y dónde deja un servicio operado ───────────────────────────
//
// Espejo de `GET /operacion/user/puntos?id[]=…`, servido por `OperacionPuntosController`.
// Es lo que dice el CATÁLOGO: el override del operador viaja en la propia fila
// (`OperacionServicio.puntoRecojo` / `puntoEntrega`), así que aquí no se aplica.
//
// ⚠️ No es `ApiResource`, así que no entra en `api.d.ts` y se declara a mano — como
// `PmsLimpiadorOption` y compañía. Si cambia el array del controlador, cambia también esto.
//
// Se usa como MARCADOR DE POSICIÓN del campo editable: enseña qué saldría si el operador lo
// vaciara, que es la única forma de que se entienda que vacío no significa «sin punto» sino
// «lo que diga el catálogo».
export interface PuntosDerivadosServicio {
    /** `false` = este servicio no recoge ni deja a nadie (ticket, comida, alojamiento). */
    aplica: boolean;
    /** `false` en un guiado: se presenta en un punto y ahí acaba su parte. */
    tieneEntrega: boolean;
    recojo: string | null;
    entrega: string | null;
    /** Por qué falta algo, y dónde se arregla. Vacío si está todo resuelto. */
    avisos: string[];
    /**
     * Lo que de verdad se congelará al emitir: override del operador incluido y el hotel ya
     * resuelto. Es lo que hay que poder revisar ANTES de emitir — después el documento ya está
     * en manos del proveedor y corregirlo cuesta anular y reemitir.
     */
    efectivo: {
        recojo: string | null;
        entrega: string | null;
        tieneEntrega: boolean;
        completo: boolean;
    };
}

export type PuntosDerivadosPorServicio = Record<string, PuntosDerivadosServicio>;
