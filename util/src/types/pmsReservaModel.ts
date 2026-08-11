// src/types/pmsReservaModel.ts
// ============================================================================
// Tipos del módulo de Reservas PMS (calendario SPA), anclados a api.d.ts.
//
// Endpoints (routePrefix: '/pms'):
//   GET|PATCH /platform/pms/pms_reservas/{id}            (cliente/datos de la reserva)
//   GET|POST|PATCH /platform/pms/pms_evento_calendarios   (estancia/bloqueo individual)
//   GET (collection) /platform/pms/pms_unidads             (selector de unidad)
//   GET (collection) /platform/pms/pms_evento_estados      (selector de estado)
//   GET (collection) /platform/pms/pms_evento_estado_pagos (selector de estado de pago)
//   GET (collection) /platform/pms/pms_channels            (selector de canal)
//
// Endpoints de solo-fetch del calendario (no son ApiResource, son el provider
// "Spa" de Calendar — ver docs/CALENDAR_ARCHITECTURE.md):
//   GET /fullcalendar/load/event/pms_eventos_no_cancelados_spa
//   GET /fullcalendar/load/event/pms_eventos_todos_spa
//   GET /fullcalendar/load/resource/pms_eventos_*_spa
//
// Notas de diseño:
//  - Los campos IRI (pmsUnidad, channel, estado, estadoPago, pais, idioma) ya
//    vienen tipados como `string` en los schemas de escritura porque API
//    Platform los infiere como format:iri-reference — no hace falta Omit/override.
//  - Las relaciones anidadas en lectura (pmsUnidad, estado, estadoPago, channel,
//    pais, idioma) sí exponen id/nombre/color: se agregaron los grupos de
//    serialización correspondientes en el backend para que vengan pobladas.
// ============================================================================

import { components } from '@/types/api';

// ============================================================================
// TIPOS DE LECTURA
// ============================================================================

/**
 * Reglas de borrado (`safeToDelete` / `motivoNoBorrable`): las expone el backend
 * en el grupo de lectura (PmsEventoCalendario::isSafeToDelete/getMotivoNoBorrable),
 * pero todavía no están en el api.d.ts generado — de ahí la extensión manual.
 * Son la misma verdad que aplica el listener de Doctrine en preRemove: si el
 * frontend deshabilita el botón, el backend habría respondido 403.
 */
export interface PmsBorrableInfo {
    safeToDelete?: boolean;
    motivoNoBorrable?: string | null;
}

/**
 * Estado consolidado de la cola de push hacia Beds24.
 * Espejo de `PmsEventoCalendario::getSyncStatus()` y `PmsReserva::getSyncStatusAggregate()`.
 *
 * - `local`   : no tiene links con Beds24, no hay nada que esperar.
 * - `pending` : hay tareas encoladas o ejecutándose.
 * - `synced`  : todo lo encolado terminó bien.
 * - `error`   : alguna tarea quedó en `failed`.
 */
export type PmsSyncStatus = 'local' | 'pending' | 'synced' | 'error';

export interface PmsSyncInfo {
    syncStatus?: PmsSyncStatus;
}

/** Igual que PmsSyncInfo pero para la reserva, que agrega el peor estado de sus estancias. */
export interface PmsSyncAggregateInfo {
    syncStatusAggregate?: PmsSyncStatus;
}

/**
 * ¿Se puede borrar YA, sin dejar basura en Beds24?
 *
 * `safeToDelete` por sí solo no basta y esto no es un detalle: en cuanto la estancia pasa
 * a «cancelada» el backend la considera borrable, aunque el push de esa cancelación siga
 * en cola. Si se borra en esa ventana, el DELETE llega a Beds24 sobre una reserva todavía
 * confirmada —lo único que Beds24 rechaza borrar— y la reserva se queda viva allí
 * bloqueando esas noches. Por eso se exige además que la sincronización haya terminado.
 *
 * Espejo de la regla del backend en `PmsEventoCalendario::getSyncStatus()`, que documenta
 * la carrera desde el otro lado.
 */
export function puedeBorrarseYa(
    borrable: PmsBorrableInfo | null | undefined,
    sync: PmsSyncStatus | undefined,
): boolean {
    if (!borrable?.safeToDelete) return false;
    return sync === 'synced' || sync === 'local' || sync === undefined;
}

export type PmsEventoCalendario = components['schemas']['PmsEventoCalendario-pms_evento.read_timestamp.read']
    & PmsBorrableInfo
    & PmsSyncInfo;
/**
 * Elección del número de contacto. Todavía no está en el api.d.ts generado
 * (ver PmsReserva::$telefono2EsPrincipal y getTelefonoContacto() en el backend),
 * de ahí la extensión manual. `telefonoContacto` llega YA RESUELTO: no hay que
 * volver a decidir cuál de los dos números es el bueno.
 */
export interface PmsTelefonoContactoInfo {
    telefono2EsPrincipal?: boolean;
    telefonoContacto?: string | null;
}

export type PmsReserva = components['schemas']['PmsReserva-pms_reserva.read_timestamp.read']
    & PmsBorrableInfo
    & PmsTelefonoContactoInfo
    & PmsSyncAggregateInfo;

export type PmsUnidadOption = components['schemas']['PmsUnidad-pms_unidad.read'];
export type PmsEventoEstadoOption = components['schemas']['PmsEventoEstado-pms_evento_estado.read'];
/**
 * `colorOverride` todavía no está en el schema generado (se agregó el grupo
 * de serialización en el backend pero falta regenerar api.d.ts).
 */
export type PmsEventoEstadoPagoOption = components['schemas']['PmsEventoEstadoPago-pms_evento_estado_pago.read'] & { colorOverride?: boolean };
export type PmsChannelOption = components['schemas']['PmsChannel-pms_channel.read'];

// ============================================================================
// COLOR DE ESTADO (espejo de PmsEventosSpaCalendarProvider::resolveColor() en PHP)
// El color final de una estancia combina el estado y el estado de pago: si el
// estado de pago tiene `colorOverride` activo, su color manda sobre el del
// estado. Usado tanto en la barra de cada evento del calendario (backend) como
// en la cabecera de cada acordeón del drawer y los indicadores de los selects.
// ============================================================================

/** Color hexadecimal por defecto cuando el estado/estado de pago no tiene uno configurado. */
export const COLOR_ESTADO_FALLBACK = '#94A3B8';

export function resolveEventoColor(
    estado?: { color?: string | null } | null,
    estadoPago?: { color?: string | null; colorOverride?: boolean } | null,
): string {
    if (estadoPago?.colorOverride && estadoPago.color) return estadoPago.color;
    return estado?.color || COLOR_ESTADO_FALLBACK;
}

/** Texto blanco o gris oscuro, el que mejor contraste dé sobre `hexColor`. */
export function contrastText(hexColor: string): string {
    const hex = hexColor.replace('#', '');
    if (hex.length !== 6) return '#1e293b';
    const r = parseInt(hex.substring(0, 2), 16);
    const g = parseInt(hex.substring(2, 4), 16);
    const b = parseInt(hex.substring(4, 6), 16);
    const luminance = (0.299 * r + 0.587 * g + 0.114 * b) / 255;
    return luminance > 0.6 ? '#1e293b' : '#ffffff';
}

// ============================================================================
// TIPOS DE ESCRITURA
// ============================================================================

/**
 * PATCH /pms_evento_calendarios/{id} — todos los campos opcionales.
 * `Partial<>` porque el schema generado marca monto/comision como requeridos
 * (tienen @default en OpenAPI); en un merge-patch real cualquier subset es válido.
 */
export type PmsEventoCalendarioPatch = Partial<components['schemas']['PmsEventoCalendario-pms_evento.write.jsonMergePatch']>;

/**
 * POST /pms_evento_calendarios — crear un bloqueo manual, o una estancia más
 * (`reserva` IRI) en otra casita para una reserva ya existente. `reserva` sólo
 * es válido en creación (grupo pms_evento:write_create), nunca en el PATCH; por
 * eso el POST usa este schema y no el de escritura genérico.
 */
export type PmsEventoCalendarioCreate = components['schemas']['PmsEventoCalendario-pms_evento.write_pms_evento.write_create'];

/** PATCH /pms_reservas/{id} — todos los campos opcionales. */
export type PmsReservaPatch = Partial<components['schemas']['PmsReserva-pms_reserva.write.jsonMergePatch']>
    & { telefono2EsPrincipal?: boolean };

/**
 * POST /pms_reservas — "Reserva completa" (titular + estancia inicial) creada en un
 * único paso desde el calendario SPA. Ver PmsReservaCrearInput/PmsReservaCrearProcessor
 * en el backend. No incluye `channel`: el processor siempre fuerza canal directo.
 * (Tipado a mano porque este endpoint usa un Input DTO propio, no reflejado aún en
 * la introspección OpenAPI de api.d.ts.)
 */
export interface PmsReservaCrearPayload {
    nombreCliente: string;
    apellidoCliente?: string | null;
    telefono?: string | null;
    telefono2?: string | null;
    /** Ver PmsReserva::$telefono2EsPrincipal: cuál de los dos números se usa. */
    telefono2EsPrincipal?: boolean;
    emailCliente?: string | null;
    /** IRI de MaestroPais. */
    pais?: string | null;
    /** IRI de MaestroIdioma; si se omite, el backend usa el idioma por defecto. */
    idioma?: string | null;
    nota?: string | null;
    /** IRI de PmsUnidad. */
    pmsUnidad: string;
    inicio: string;
    fin: string;
    cantidadAdultos: number;
    cantidadNinos: number;
    descripcion?: string | null;
    monto: string;
    comision: string;
}

// ============================================================================
// CONTEXTO DE EVENTO (extendedProps del feed FullCalendar SPA)
// Ver PmsEventosSpaCalendarProvider::buildContext() en el backend.
// ============================================================================

export interface PmsEventoExtendedProps {
    context: 'reserva' | 'bloqueo';
    eventoId: string;
    reservaId: string | null;
    isOta: boolean;

    // Datos sueltos para pintar la barra y el tooltip sin re-parsear el `title`
    // («A x8 | Nombre | Casita»). Los agrega PmsEventosSpaCalendarProvider::buildContext().
    canalId?: string | null;
    cliente?: string | null;
    unidad?: string | null;
    pax?: number;
    estado?: string | null;
    estadoPago?: string | null;
    referenciaCanal?: string | null;
    noches?: number;
    /** Early check-in pactado: la barra lo marca con una puerta. */
    entradaTemprana?: boolean;
    /** Late check-out pactado: la barra lo marca con un reloj. */
    salidaTardia?: boolean;

    /**
     * Para contactar sin abrir la ficha. En dígitos, listo para `wa.me`.
     * `null` en un bloqueo o si la reserva no tiene ningún número.
     */
    telefono?: string | null;
    /** Conversación de esta reserva, para `/chat/:id`. `null` si no hay ninguna abierta. */
    conversacionId?: string | null;

    /**
     * Cifras de la cabecera financiera, ya en su moneda. Son de la RESERVA: si
     * ocupa dos casitas, sus dos barras muestran el mismo total y el mismo saldo.
     * `null` cuando no hay cabecera o el total es 0 (bloqueos, reservas nuevas).
     */
    simbolo?: string | null;
    /** Decimal en texto («1250.00»): viene tal cual del backend, sin redondear. */
    total?: string | null;
    /** Positivo = queda por cobrar. Cero o negativo = cobrada. */
    saldo?: string | null;
}

// ============================================================================
// BUSCADOR DE RESERVAS (GET /pms/reservas/buscar?q=)
// Una fila por ESTANCIA, no por reserva: la reserva de dos casitas tiene dos
// tramos con fechas y unidad propias, y es el tramo lo que se busca en el
// calendario. Ver PmsReservaBuscarController en el backend.
// ============================================================================

export interface PmsReservaBusquedaItem {
    eventoId: string;
    reservaId: string | null;
    cliente: string | null;
    localizador: string | null;
    unidad: string | null;
    unidadId: string | null;
    /** `YYYY-MM-DD` (sin hora: las horas del evento son check-in/check-out). */
    inicio: string | null;
    fin: string | null;
    noches: number;
    pax: number;
    estado: string | null;
    /** Código natural del estado (ver PMS_ESTADO): `cancelada`, `confirmada`… */
    estadoId: string | null;
    /** Mismo color que pinta la barra en el calendario (pago puede pisar estado). */
    color: string | null;
    estadoPago: string | null;
    canal: string | null;
    isOta: boolean;
}

// ============================================================================
// CÓDIGOS NATURALES (IDs string, deben coincidir con las constantes PHP)
// ============================================================================

export const PMS_ESTADO = {
    PENDIENTE: 'pendiente',
    CONFIRMADA: 'confirmada',
    CANCELADA: 'cancelada',
    ABIERTO: 'abierto',
    REQUERIMIENTO: 'requerimiento',
    BLOQUEO: 'bloqueo',
} as const;

export const PMS_ESTADO_PAGO = {
    SIN_PAGO: 'no-pagado',
    PAGO_ALOJAMIENTO: 'pago-alojamiento',
    PAGO_PARCIAL: 'pago-parcial',
    PAGO_TOTAL: 'pago-total',
} as const;

export const PMS_CHANNEL = {
    DIRECTO: 'directo',
    AIRBNB: 'airbnb',
    VRBO: 'vrbo',
    BOOKING: 'booking',
} as const;

// ============================================================================
// CANALES: icono y etiqueta
// Una sola tabla para los tres sitios que la necesitan — la barra del
// calendario, su tooltip y el enlace a la extranet del menú contextual.
// ============================================================================

export interface CanalInfo {
    texto: string;
    icono: string;
}

const CANAL_INFO: Record<string, CanalInfo> = {
    [PMS_CHANNEL.AIRBNB]:  { texto: 'Airbnb', icono: 'fab fa-airbnb' },
    [PMS_CHANNEL.BOOKING]: { texto: 'Booking', icono: 'fas fa-hotel' },
    [PMS_CHANNEL.VRBO]:    { texto: 'VRBO', icono: 'fas fa-umbrella-beach' },
    [PMS_CHANNEL.DIRECTO]: { texto: 'Directo', icono: 'fas fa-handshake' },
};

/** Canal desconocido: enlace genérico, sin inventarle una marca. */
const CANAL_FALLBACK: CanalInfo = { texto: 'Canal', icono: 'fas fa-external-link-alt' };

export function canalInfo(canalId?: string | null): CanalInfo {
    return (canalId && CANAL_INFO[canalId]) || CANAL_FALLBACK;
}

// ============================================================================
// HELPERS DE IRI
// API Platform espera IRIs completas en los campos de relación al escribir.
// ============================================================================

export const pmsUnidadIri = (id: string): string => `/platform/pms/pms_unidads/${id}`;
export const pmsReservaIri = (id: string): string => `/platform/pms/pms_reservas/${id}`;
export const pmsEventoEstadoIri = (id: string): string => `/platform/pms/pms_evento_estados/${id}`;
export const pmsEventoEstadoPagoIri = (id: string): string => `/platform/pms/pms_evento_estado_pagos/${id}`;
export const pmsChannelIri = (id: string): string => `/platform/pms/pms_channels/${id}`;
export const maestroPaisIri = (id: string): string => `/platform/maestro/pais/${id}`;
export const maestroIdiomaIri = (id: string): string => `/platform/maestro/idiomas/${id}`;

// ============================================================================
// WHATSAPP (GET /pms/reservas/{id}/whatsapp-link/{templateId})
// Reemplaza al viejo flujo de redirect de EasyAdmin: el backend solo resuelve
// el texto (variables reemplazadas) en JSON, y el frontend arma la URL de
// WhatsApp — evita que un mensaje largo genere una cabecera `Location` de
// redirect que colapse en el servidor/proxy.
// ============================================================================
export interface PmsReservaWhatsappLink {
    telefono: string;
    texto: string;
}

/**
 * URL de WhatsApp Web/App con el texto ya resuelto.
 *
 * Devuelve la URL en vez de abrirla: la apertura tiene que colgar de un `<a href>`
 * que el usuario pulse directamente. Un `window.open()` disparado **después** de un
 * `await` ya no está dentro del gesto del usuario y iOS —sobre todo la PWA en modo
 * standalone— lo bloquea **sin avisar**: no hay error, simplemente no pasa nada.
 * Por eso el caller resuelve el enlace ANTES de pintar el botón (ver el submenú de
 * plantillas en ReservasView.vue).
 */
/**
 * EL número al que hay que escribir, según la elección del operador.
 *
 * **Espejo de `PmsReserva::getTelefonoContacto()` (PHP).** Si cambia la regla,
 * se tocan los dos. La API ya expone `telefonoContacto` resuelto, así que esta
 * copia solo hace falta donde hay que reflejar un formulario SIN GUARDAR —el
 * drawer, mientras el operador teclea y marca la casilla—; en cualquier otro
 * sitio, usa el campo que manda el backend.
 *
 * El flag solo decide el ORDEN de preferencia: si el preferido está vacío se cae
 * al otro, para que marcar como principal un número que luego se borra no deje
 * la reserva sin forma de contacto.
 */
export function telefonoContactoDe(
    telefono?: string | null,
    telefono2?: string | null,
    telefono2EsPrincipal?: boolean,
): string | null {
    const preferido = (telefono2EsPrincipal ? telefono2 : telefono)?.trim() || '';
    const alterno = (telefono2EsPrincipal ? telefono : telefono2)?.trim() || '';

    return preferido || alterno || null;
}

export function whatsappUrl(telefono: string, texto: string): string {
    return `https://api.whatsapp.com/send/?phone=${encodeURIComponent(telefono)}&text=${encodeURIComponent(texto)}&type=phone_number&app_absent=0`;
}

// ============================================================================
// HELPERS DE FECHA (ISO backend <-> input datetime-local del navegador)
// ============================================================================

/** ISO ("2025-03-01T14:00:00+00:00") -> valor para <input type="datetime-local"> ("2025-03-01T14:00"). */
// ============================================================================
// FECHAS DE ESTANCIA: SON HORA DE PARED, NO INSTANTES
//
// `inicio` y `fin` viven en columnas `datetime` SIN zona horaria, y el backend las
// escribe con la hora de recepción del establecimiento (check-in 14:00, check-out 10:00
// — ver PmsEventoCalendarioFactory::resolveFechaConHora()). "Las 14:00" significa las
// 14:00 en la recepción, no un instante universal.
//
// Por eso estas conversiones **nunca pasan por `new Date()`**: ese constructor las trata
// como instantes y les aplica el offset del navegador. Con la implementación anterior
// —`new Date(iso)` al leer y `.toISOString()` al escribir— en Lima (UTC-5) pasaba esto:
//
//     BD 14:00  →  API "14:00+00:00"  →  el formulario mostraba 09:00
//     y al guardar 14:00 a mano, la BD acababa con 19:00
//
// El desfase no se acumulaba, pero la hora mostrada era falsa y los eventos editados a
// mano quedaban descuadrados respecto a los que crea la sincronización de Beds24.
// Trabajando con la cadena en crudo, la hora que ve el operador es la que hay en la BD.
// ============================================================================

/** ISO del backend ("2025-03-01T14:00:00+00:00") -> valor de <input type="datetime-local">. */
export const toDatetimeLocal = (iso?: string | null): string => {
    if (!iso) return '';
    // Acepta separador "T" o espacio; ignora deliberadamente segundos y offset.
    const m = String(iso).match(/^(\d{4}-\d{2}-\d{2})[T ](\d{2}:\d{2})/);
    return m ? `${m[1]}T${m[2]}` : '';
};

/**
 * Valor de <input type="datetime-local"> ("2025-03-01T14:00") -> cadena para el backend.
 * Se manda SIN zona ni sufijo `Z`: es la hora de pared tal cual, que es lo que Doctrine
 * guardará literalmente en la columna.
 */
export const fromDatetimeLocal = (local: string): string => {
    if (!local) return '';
    const m = local.match(/^(\d{4}-\d{2}-\d{2})T(\d{2}:\d{2})/);
    return m ? `${m[1]}T${m[2]}:00` : local;
};

/** Date del navegador -> hora de pared "YYYY-MM-DDTHH:mm", sin convertir a UTC. */
export const fechaAInputLocal = (d: Date): string => {
    const pad = (n: number): string => String(n).padStart(2, '0');
    return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}`;
};

// ============================================================================
// REGLAS OTA (espejo de PmsEventoCalendario::OTA_*_SELECCIONABLES en PHP)
// Usadas para restringir el select de estado cuando el evento es OTA.
// ============================================================================

export const OTA_ESTADOS_NO_SELECCIONABLES: readonly string[] = [
    PMS_ESTADO.CANCELADA,
    PMS_ESTADO.ABIERTO,
    PMS_ESTADO.BLOQUEO,
];

export const OTA_ABIERTO_ESTADOS_SELECCIONABLES: readonly string[] = [
    PMS_ESTADO.ABIERTO,
    PMS_ESTADO.CANCELADA,
];

// ============================================================================
// AUTO-CONFIRMACIÓN POR PAGO
// Espejo de PmsEventoCalendario::requiereAutoConfirmacionPorPago() (PHP), que el
// backend aplica en prePersist/preUpdate vía PmsEventoCalendarioIntegrityListener.
// El editor lo anticipa para que el operador no vea "pendiente" en pantalla y
// "confirmada" en el calendario después de guardar.
// ============================================================================

/** Estados de pago que implican dinero recibido (espejo de PmsEventoEstadoPago::ESTADOS_PAGO_CONFIABLES). */
export const ESTADOS_PAGO_CONFIABLES: readonly string[] = [
    PMS_ESTADO_PAGO.PAGO_PARCIAL,
    PMS_ESTADO_PAGO.PAGO_TOTAL,
    PMS_ESTADO_PAGO.PAGO_ALOJAMIENTO,
];

/**
 * Estados a los que un pago NO les cambia nada (espejo de
 * PmsEventoCalendario::ESTADOS_SIN_AUTO_CONFIRMACION): `cancelada` es terminal y
 * `bloqueo` no es la estancia de un huésped.
 */
export const ESTADOS_SIN_AUTO_CONFIRMACION: readonly string[] = [
    PMS_ESTADO.CANCELADA,
    PMS_ESTADO.BLOQUEO,
];

/** ¿Con este pago el backend va a forzar "confirmada" al guardar? */
export function requiereAutoConfirmacionPorPago(estadoId: string, estadoPagoId: string): boolean {
    if (!estadoId || !estadoPagoId) return false;
    if (!ESTADOS_PAGO_CONFIABLES.includes(estadoPagoId)) return false;
    if (ESTADOS_SIN_AUTO_CONFIRMACION.includes(estadoId)) return false;

    return estadoId !== PMS_ESTADO.CONFIRMADA;
}

/**
 * ¿El pago registrado manda sobre el estado de esta estancia? Es decir: el estado
 * ya no es libre, salvo para cancelar (cancelada sigue siendo elegible, un
 * reembolso no resucita ni congela una reserva muerta).
 */
export function pagoMandaSobreEstado(estadoId: string, estadoPagoId: string): boolean {
    return ESTADOS_PAGO_CONFIABLES.includes(estadoPagoId)
        && !ESTADOS_SIN_AUTO_CONFIRMACION.includes(estadoId);
}

/**
 * Filtra las opciones de estado de un evento. **Espejo de
 * `PmsEventoEstado::transicionOtaPermitida()`** (PHP), que es la fuente única de la regla y
 * la que aplica también `PmsEventoCalendarioSecurityListener` al guardar.
 *
 * ```
 *   pendiente ⇄ confirmada ⇄ requerimiento     ✔  los tres, entre sí
 *   abierto   → cancelada                      ✔  unidireccional: limpiar una consulta
 *   cancelada → *                              ✘  terminal
 *   bloqueo   → *                              ✘  terminal
 * ```
 *
 * ⚠️ **Si cambia la regla, hay que tocar los dos lados.** Lo que aquí sobre, el backend lo
 * rechaza con un 403 y el operador ve una opción que no puede elegir; lo que aquí falte, no
 * se puede hacer desde el panel aunque el backend lo permita. Hay una comprobación cruzada
 * de los dos lados en `var/verificar_transiciones_ota.php`.
 *
 * Un evento que NO es de OTA no se filtra: ahí mandamos nosotros.
 */
export function filtrarEstadosDisponibles(
    todos: PmsEventoEstadoOption[],
    isOta: boolean,
    estadoActualId?: string | null,
): PmsEventoEstadoOption[] {
    if (!isOta) return todos;

    if (estadoActualId === PMS_ESTADO.CANCELADA) {
        return todos.filter(e => e.id === PMS_ESTADO.CANCELADA);
    }

    // `bloqueo` es tan terminal como `cancelada` para una OTA: calendario cerrado no se
    // convierte en una reserva vendida. Faltaba, y el desplegable ofrecía los tres estados
    // vivos — lo destapó la matriz de transiciones al comparar esto con
    // `PmsEventoEstado::transicionOtaPermitida()`. El backend ya lo rechaza (REGLA 4 del
    // listener de seguridad), así que sin esto el operador veía una opción que da 403.
    if (estadoActualId === PMS_ESTADO.BLOQUEO) {
        return todos.filter(e => e.id === PMS_ESTADO.BLOQUEO);
    }

    if (estadoActualId === PMS_ESTADO.ABIERTO) {
        return todos.filter(e => e.id && OTA_ABIERTO_ESTADOS_SELECCIONABLES.includes(e.id));
    }

    return todos.filter(e => e.id && !OTA_ESTADOS_NO_SELECCIONABLES.includes(e.id));
}
