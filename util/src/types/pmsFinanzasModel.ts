// src/types/pmsFinanzasModel.ts
// ============================================================================
// Tipos del panel financiero de una reserva (cargos de Beds24 + pagos propios).
//
// Endpoints REST (routePrefix '/pms', prefijo global '/platform'):
//   GET            /platform/pms/pms_informacion_financieras?reserva={id}
//   PATCH          /platform/pms/pms_cargo_financieros/{id}
//   POST           /platform/pms/pms_pago_financieros
//   PATCH|DELETE   /platform/pms/pms_pago_financieros/{id}
//
// Enums (fuente única en PHP, ver src/Api/Controller/Tipo/PmsEnumAjaxController):
//   GET /tipo/user/enum/pms/tipos-cargo
//   GET /tipo/user/enum/pms/medios-pago
//
// 🔴 REGLA IMPORTANTE (espejo de docs/PmsBeds24ReservasSync.md §12):
// las etiquetas de `tipoCargo` y `medioPago` NO se declaran aquí. Viven en los
// enums PHP (PmsTipoCargo / PmsMedioPago) y llegan por AJAX. Duplicarlas en TS
// era justo la desincronización que este patrón viene a eliminar.
// ============================================================================

// ============================================================================
// ENUMS SERVIDOS POR EL BACKEND
// ============================================================================

/** Item de `/tipo/user/enum/pms/tipos-cargo` (PmsTipoCargo::cases()). */
export interface PmsTipoCargoOption {
    id: string;
    label: string;
    /** Color semántico Tailwind ('sky', 'violet', 'amber', 'slate'). */
    color: string;
}

/** Item de `/tipo/user/enum/pms/medios-pago` (PmsMedioPago::cases()). */
export interface PmsMedioPagoOption {
    id: string;
    label: string;
    /** Clase FontAwesome sin prefijo ('fa-credit-card'). */
    icono: string;
    /** % de recargo por defecto ('5.5' en tarjeta, '0' en el resto). */
    comisionPorcentaje: string;
}

// ============================================================================
// LECTURA
// ============================================================================

/** Moneda embebida (grupo maestro:moneda:read). */
export interface PmsFinanzasMonedaRef {
    id?: string;
    nombre?: string | null;
    simbolo?: string | null;
}

/** Cargo de la reserva: importado de Beds24 o creado a mano (grupo pms_cargo:read). */
export interface PmsCargoFinanciero {
    id?: string;
    /**
     * true = lo creó un operador (reserva directa); false = llegó de Beds24.
     * Sólo los manuales se pueden borrar (lo veta el backend).
     */
    manual?: boolean;
    /** IRI de la estancia a la que se imputa (sólo cargos manuales; los de Beds24 usan beds24BookingId). */
    evento?: string | null;
    tipoCargo?: string | null;
    descripcion?: string | null;
    /**
     * Descripción redactada PARA EL HUÉSPED, en español.
     *
     * Espejo del accesor `PmsCargoFinanciero::getDescripcionClienteEs()`: el backend guarda
     * un `I18nContent[]` y traduce solo a los demás idiomas, pero por la API viaja el texto
     * plano. `descripcion` es la INTERNA — en los cargos de Beds24 trae lo que mande el
     * canal y no es presentable.
     */
    descripcionClienteEs?: string | null;
    /** 'charge' | 'payment' — el `type` crudo de Beds24. */
    tipo?: string | null;
    subTipo?: number | null;
    monto?: string | null;
    totalLinea?: string | null;
    cantidad?: string | null;
    tasaIva?: string | null;
    estado?: string | null;
    moneda?: PmsFinanzasMonedaRef | null;
    tipoCambio?: string | null;
    fechaCreacionBeds24?: string | null;
    beds24ItemId?: string | null;
    beds24BookingId?: string | null;
    beds24InvoiceId?: string | null;
}

/** Pago propio (grupo pms_pago:read). */
export interface PmsPagoFinanciero {
    id?: string;
    /** NETO imputado a la reserva, sin el recargo del medio de pago. Es lo que suma al saldo. */
    monto?: string;
    moneda?: PmsFinanzasMonedaRef | null;
    tipoCambio?: string | null;
    medioPago?: string;
    /** Recargo en PORCENTAJE ('5.50' = 5.5%), no en importe. */
    comisionPorcentaje?: string | null;
    /** Derivados del backend (no se envían): importe del recargo y total cobrado al huésped. */
    montoComision?: string;
    montoTotalCobrado?: string;
    /** ISO de una columna `date`. */
    fechaPago?: string | null;
    referencia?: string | null;
    notas?: string | null;
    /**
     * ¿Se puede eliminar? Falso en el depósito automático del canal, que se regenera solo.
     *
     * Espejo de `PmsPagoFinanciero::isBorrable()`. La UI no debe pintar el basurero cuando
     * es `false`: el backend rechazaría el borrado y el operador se llevaría un error por
     * una acción que el propio sistema le ofreció.
     *
     * Opcional porque los pagos guardados antes de este campo llegan sin él; se trata
     * `undefined` como borrable, que es el caso mayoritario.
     */
    borrable?: boolean;
    /** Motivo legible cuando `borrable` es false, para el tooltip del candado. */
    motivoNoBorrableTexto?: string | null;
}

/**
 * Estancia de la reserva, para poder etiquetar cada cargo con su casita.
 * Imprescindible en RESERVAS AGRUPADAS de Booking.com: un grupo genera UNA cabecera con los
 * cargos de varios bookings mezclados, y Beds24 manda la misma descripción sin resolver
 * (`[ROOMNAME1] [FIRSTNIGHT] - [LEAVINGDAY]`) en todas. Ver §11.6 del doc.
 */
export interface PmsFinanzasEstancia {
    /** Clave de agrupación: la comparten los cargos del canal y los manuales. */
    eventoId: string;
    /** null en reservas directas (no hay booking del canal). */
    beds24BookingId?: string | null;
    unidad?: string | null;
    inicio?: string | null;
    fin?: string | null;
}

/** Cabecera financiera de la reserva (grupo pms_finanzas:read). */
export interface PmsInformacionFinanciera {
    id?: string;
    /**
     * ¿Los cargos de la estancia cuentan para el saldo?
     * `false` (cancelada) → solo suma la PENALIZACIÓN; los demás cargos se conservan
     * y se muestran, pero no computan. El operador puede reactivarla cuando la reserva
     * cancelada en la OTA sigue adelante como directa (§12.7).
     */
    activa?: boolean;
    /** Moneda BASE (contable): en ella se expresan totalCargos/totalPagos/saldo. */
    moneda?: PmsFinanzasMonedaRef | null;
    /**
     * ¿Se puede cambiar la moneda base? Sólo en reservas directas puras: si algún cargo
     * vino del canal, la moneda la manda el canal (§12.4.4). Lo decide el backend.
     */
    monedaBaseEditable?: boolean;
    /** Totales YA convertidos a la moneda de la cabecera por el listener de coherencia. */
    totalCargos?: string;
    totalPagos?: string;
    saldo?: string;
    lastSyncedAt?: string | null;
    cargos?: PmsCargoFinanciero[];
    pagos?: PmsPagoFinanciero[];
    estancias?: PmsFinanzasEstancia[];
}

// ============================================================================
// ESCRITURA
// ============================================================================

/** PATCH de un cargo: sólo lo que el operador puede corregir (grupo pms_cargo:patch). */
export interface PmsCargoFinancieroPatch {
    tipoCargo?: string | null;
    descripcion?: string | null;
    /**
     * Descripción redactada PARA EL HUÉSPED, en español.
     *
     * Espejo del accesor `PmsCargoFinanciero::getDescripcionClienteEs()`: el backend guarda
     * un `I18nContent[]` y traduce solo a los demás idiomas, pero por la API viaja el texto
     * plano. `descripcion` es la INTERNA — en los cargos de Beds24 trae lo que mande el
     * canal y no es presentable.
     */
    descripcionClienteEs?: string | null;

    monto?: string | null;
    totalLinea?: string | null;
    /** IRI de la estancia, o null para dejarlo como cargo general de la reserva. */
    evento?: string | null;
    /**
     * SÓLO para rellenar el que faltaba: un cargo en otra moneda sin tipo de cambio aporta
     * 0 al saldo y hay que poder arreglarlo. Si el cargo ya tenía TC, el backend rechaza el
     * cambio con DomainException (§12.4). `moneda` sigue sin poder parchearse nunca.
     */
    tipoCambio?: string | null;
}

/**
 * POST de un cargo MANUAL (grupo pms_cargo:write). El backend lo marca como manual
 * por omisión de `beds24ItemId`, y le pone `tipo='charge'` para que sume al saldo.
 * `informacionFinanciera` y `moneda` viajan como IRI.
 */
export interface PmsCargoFinancieroCreate {
    informacionFinanciera: string;
    moneda: string;
    tipoCargo?: string | null;
    descripcion?: string | null;
    /**
     * Descripción redactada PARA EL HUÉSPED, en español.
     *
     * Espejo del accesor `PmsCargoFinanciero::getDescripcionClienteEs()`: el backend guarda
     * un `I18nContent[]` y traduce solo a los demás idiomas, pero por la API viaja el texto
     * plano. `descripcion` es la INTERNA — en los cargos de Beds24 trae lo que mande el
     * canal y no es presentable.
     */
    descripcionClienteEs?: string | null;
    totalLinea: string;
    tipoCambio?: string | null;
    /** IRI de la estancia a la que se imputa; null = cargo de la reserva en conjunto. */
    evento?: string | null;
}

/** POST de un pago (grupo pms_pago:write). `informacionFinanciera` y `moneda` viajan como IRI. */
export interface PmsPagoFinancieroCreate {
    informacionFinanciera: string;
    moneda: string;
    /** NETO. El total cobrado se deriva con el porcentaje; no se envía. */
    monto: string;
    medioPago: string;
    /** Formato 'YYYY-MM-DD' (columna `date`). */
    fechaPago: string;
    tipoCambio?: string | null;
    /** Porcentaje ('5.5'), no importe. */
    comisionPorcentaje?: string | null;
    referencia?: string | null;
    notas?: string | null;
}

// ============================================================================
// CÁLCULO DEL RECARGO
//
// Espejo de PmsPagoFinanciero::getMontoComision() / getMontoTotalCobrado() en PHP.
// Vive en los dos lados a propósito: el backend es la fuente de verdad al leer, pero el
// formulario necesita recalcular en vivo mientras se teclea. Si cambia la fórmula,
// **hay que tocar los dos archivos**.
// ============================================================================

/** Total que se le cobra al huésped: neto + recargo. */
export function totalConComision(neto: string | number, porcentaje: string | number): string {
    const n = Number(neto) || 0;
    const p = Number(porcentaje) || 0;
    return (n * (1 + p / 100)).toFixed(2);
}

/** Operación inversa: a partir del total cobrado, el neto que se imputa a la reserva. */
export function netoDesdeTotal(total: string | number, porcentaje: string | number): string {
    const t = Number(total) || 0;
    const p = Number(porcentaje) || 0;
    return (t / (1 + p / 100)).toFixed(2);
}

// ============================================================================
// CONVERSIÓN ENTRE MONEDAS PARA LA VISTA DUAL
//
// ⚠️ ESPEJO de PmsInformacionFinancieraRecalculoService::expresionConvertida() (SQL).
// Si cambia la regla de conversión, **hay que tocar los dos archivos**.
//
// Regla: cada registro se convierte con SU PROPIO tipo de cambio, nunca con uno global.
// Convertir el total ya sumado con la cotización de hoy daría una cifra que nadie pactó:
// si al huésped se le cobró S/. 1425 con TC 3.750, en la vista de soles tiene que salir
// 1425 exactos, no el resultado de ir y volver por dólares con otra cotización.
//
// Consecuencia esperada (no es un fallo): con registros a tipos de cambio distintos, el
// total en soles NO es el total en dólares multiplicado por ningún número. Son dos sumas
// de cifras reales, cada una en su moneda.
// ============================================================================

/** Un registro convertible: importe + moneda + su tipo de cambio snapshoteado. */
interface RegistroConvertible {
    moneda?: PmsFinanzasMonedaRef | null;
    tipoCambio?: string | null;
}

/**
 * Importe de un registro expresado en `monedaDestino`.
 *
 * Devuelve `null` cuando NO se puede convertir con honestidad — moneda distinta y sin tipo
 * de cambio —, que es el equivalente del "aporta 0" del backend, pero distinguible de un
 * cero real para poder avisar en la interfaz.
 */
export function importeEnMoneda(
    registro: RegistroConvertible,
    importe: string | number | null | undefined,
    monedaDestino?: string | null,
): number | null {
    const valor = Number(importe ?? 0);
    if (!Number.isFinite(valor)) return null;

    const origen = registro.moneda?.id ?? null;
    if (!monedaDestino || !origen || origen === monedaDestino) return valor;

    const tc = Number(registro.tipoCambio ?? 0);
    if (!tc) return null;

    // El TC siempre se guarda como venta USD→PEN, así que la dirección la marcan las monedas.
    if (origen === 'USD' && monedaDestino === 'PEN') return valor * tc;
    if (origen === 'PEN' && monedaDestino === 'USD') return valor / tc;

    // Par no soportado (p. ej. EUR→PEN): no se inventa una cotización cruzada.
    return null;
}

/** Resultado de sumar una colección en una moneda concreta. */
export interface TotalEnMoneda {
    total: number;
    /** Registros que no se pudieron convertir (sin TC o par no soportado). */
    sinConvertir: number;
}

/** Suma registros en `monedaDestino`, contando aparte los que no se pudieron convertir. */
export function sumarEnMoneda<T extends RegistroConvertible>(
    registros: readonly T[],
    importeDe: (r: T) => string | number | null | undefined,
    monedaDestino?: string | null,
): TotalEnMoneda {
    let total = 0;
    let sinConvertir = 0;

    for (const r of registros) {
        const v = importeEnMoneda(r, importeDe(r), monedaDestino);
        if (v === null) sinConvertir++;
        else total += v;
    }

    return { total, sinConvertir };
}

/**
 * PATCH de un pago (grupo pms_pago:patch).
 * `moneda` queda EXCLUIDA a propósito: una vez registrado el pago su moneda es
 * inmutable (el backend la bloquea con DomainException — ver §12.4 del doc).
 */
export type PmsPagoFinancieroPatch = Partial<Omit<PmsPagoFinancieroCreate, 'informacionFinanciera' | 'moneda'>>;

// ============================================================================
// HELPERS DE IRI
// ============================================================================

export const pmsInformacionFinancieraIri = (id: string): string =>
    `/platform/pms/pms_informacion_financieras/${id}`;

export const pmsEventoIri = (id: string): string =>
    `/platform/pms/pms_evento_calendarios/${id}`;

/** Último segmento de una IRI ('/platform/.../{id}' → '{id}'). */
export const idDeIri = (iri?: string | null): string | null =>
    iri ? (iri.split('/').pop() || null) : null;

// ============================================================================
// PRESENTACIÓN
// ============================================================================

/** Clases Tailwind de la insignia de tipo de cargo, a partir del color del enum. */
export const CLASES_COLOR_CARGO: Record<string, string> = {
    sky: 'bg-sky-100 text-sky-700',
    violet: 'bg-violet-100 text-violet-700',
    amber: 'bg-amber-100 text-amber-700',
    rose: 'bg-rose-100 text-rose-700',
    slate: 'bg-slate-100 text-slate-600',
};

export function clasesTipoCargo(color?: string): string {
    return CLASES_COLOR_CARGO[color ?? 'slate'] ?? CLASES_COLOR_CARGO.slate;
}

/** Importe con el símbolo de su moneda ('$ 120.00'). */
export function importeConMoneda(monto?: string | null, moneda?: PmsFinanzasMonedaRef | null): string {
    if (monto === null || monto === undefined || monto === '') return '—';
    const simbolo = moneda?.simbolo ?? moneda?.id ?? '';
    return `${simbolo} ${Number(monto).toFixed(2)}`.trim();
}

/** ISO de la API -> valor para <input type="date">. */
export const toDateInput = (iso?: string | null): string => (iso ? iso.slice(0, 10) : '');

/** Hoy en 'YYYY-MM-DD' (zona local, sin el corrimiento UTC de toISOString). */
export function hoyInput(): string {
    const d = new Date();
    const pad = (n: number): string => String(n).padStart(2, '0');
    return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;
}
