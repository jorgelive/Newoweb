// ============================================================================
// FEED DE FULLCALENDAR — GET /fullcalendar/load/{event|resource}/{calendario}
//
// Espejo de `src/Calendar/Dto/CalendarEventDto.php` y `CalendarResourceDto.php`:
// esos DTOs deciden qué claves salen (omiten las nulas), así que casi todo es
// opcional aquí. Si se agrega un campo al DTO, se agrega también en este archivo.
//
// El genérico `TProps` es el `extendedProps` concreto de cada calendario
// (`PmsEventoExtendedProps`, `PmsTarifaExtendedProps`…): es lo que permite leer
// `evento.extendedProps.isOta` con tipos en vez de a ciegas.
// ============================================================================

export interface CalendarEventoFeed<TProps = Record<string, unknown>> {
    id: string;
    title: string;
    /** `YYYY-MM-DDTHH:mm:ss` en hora local del establecimiento (sin zona). */
    start: string;
    end: string;
    resourceId?: string;
    textColor?: string;
    backgroundColor?: string;
    borderColor?: string;
    color?: string;
    classNames?: string[];
    tooltip?: string | string[];
    prioridadImportante?: number;
    extendedProps?: TProps;

    // Banderas de FullCalendar que el backend NO manda: las inyecta la vista en
    // `eventDataTransform` para bloquear el arrastre de estancias OTA.
    editable?: boolean;
    startEditable?: boolean;
    durationEditable?: boolean;
}

export interface CalendarRecursoFeed {
    id: string;
    title: string;
    /** Índice de orden que calcula el provider; alimenta `resourceOrder: 'orden'`. */
    orden?: number;
    extendedProps?: Record<string, unknown>;
}

/**
 * Normaliza la respuesta del feed: el controlador devuelve el array pelado, pero
 * se acepta también `{ data: [...] }` — es la forma en que lo envuelven algunos
 * proxies y era la defensa que ya traía el código original de las vistas.
 */
export function coleccionFeed<T>(data: unknown): T[] {
    if (Array.isArray(data)) return data as T[];
    const envuelto = (data as { data?: unknown } | null)?.data;
    return Array.isArray(envuelto) ? (envuelto as T[]) : [];
}

/**
 * FullCalendar exige un `Error` en el callback de fallo de los feeds, pero un
 * `catch` de axios entrega `unknown`. Esto los reconcilia sin perder el mensaje.
 */
export function comoError(err: unknown): Error {
    return err instanceof Error ? err : new Error(String(err));
}
