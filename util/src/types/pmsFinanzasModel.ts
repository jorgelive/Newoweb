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

import type { components } from '@/types/api';

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

/**
 * Item de `/tipo/user/enum/pms/cobradores`: quién puede figurar como quien RECIBIÓ el dinero.
 *
 * No es un enum —son filas de `user` con `ROLE_COBRADOR`— pero llega por el mismo controlador
 * y se consume igual. El `id` es el UUID plano, NO una IRI: `User` no es un recurso de API
 * Platform, así que el pago lo escribe por `cobradorId`
 * (ver `PmsPagoFinanciero::setCobradorId()` y su processor).
 */
export interface PmsCobradorOption {
    id: string;
    label: string;
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
/**
 * Cargo tal como lo devuelve la API (grupo `pms_cargo:read`).
 *
 * **Derivado del esquema generado, no escrito a mano.** Antes era una interfaz manual y
 * pasó lo que tenía que pasar: al añadir `descripcionClienteEs` en el backend, el
 * `openapi.json` y `api.d.ts` se regeneraron con el campo y el typecheck seguía fallando
 * porque estos tres tipos vivían aparte. Ahora un campo nuevo en la entidad PHP aparece
 * aquí solo con regenerar los tipos, y uno eliminado rompe el build en vez de fallar en
 * runtime.
 *
 * Regenerar tras tocar la entidad o sus grupos de serialización:
 * ```
 * php bin/console api:openapi:export -o openapi.json
 * cd util && npx openapi-typescript ../openapi.json -o src/types/api.d.ts
 * ```
 */
export type PmsCargoFinanciero =
    components['schemas']['PmsCargoFinanciero-pms_finanzas.read_pms_cargo.read_pms_pago.read_maestro.moneda.read'];

/** Pago tal como lo devuelve la API. Derivado del esquema generado (ver PmsCargoFinanciero). */
export type PmsPagoFinanciero =
    components['schemas']['PmsPagoFinanciero-pms_finanzas.read_pms_cargo.read_pms_pago.read_maestro.moneda.read'];


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

/**
 * Cabecera financiera de la reserva. Derivada del esquema generado, con UNA corrección.
 *
 * ⚠️ El esquema declara `estancias` como `string[]` —API Platform infiere IRIs para una
 * asociación— pero en runtime llegan OBJETOS con `eventoId`, `unidad`, `inicio` y `fin`.
 * La inferencia se equivoca ahí, así que el campo se reemplaza por su forma real.
 *
 * Se hace con `Omit` y no redeclarando encima: sin quitarlo antes, TS produce la
 * intersección `string[] & PmsFinanzasEstancia[]`, que no es usable y falla lejos del
 * origen (ver CLAUDE.md, §TypeScript).
 */
export type PmsInformacionFinanciera =
    Omit<components['schemas']['PmsInformacionFinanciera-pms_finanzas.read_pms_cargo.read_pms_pago.read_maestro.moneda.read'], 'estancias'>
    & { estancias?: PmsFinanzasEstancia[] };


// ============================================================================
// ESCRITURA
// ============================================================================

/** Cuerpo del PATCH de un cargo (grupo `pms_cargo:patch`). Derivado del esquema generado. */
export type PmsCargoFinancieroPatch =
    components['schemas']['PmsCargoFinanciero-pms_cargo.patch.jsonMergePatch'];


/** Cuerpo del POST de un cargo manual (grupo `pms_cargo:write`). Derivado del esquema generado. */
export type PmsCargoFinancieroCreate =
    components['schemas']['PmsCargoFinanciero-pms_cargo.write'];


/** Cuerpo del POST de un pago (grupo `pms_pago:write`). Derivado del esquema generado. */
export type PmsPagoFinancieroCreate =
    components['schemas']['PmsPagoFinanciero-pms_pago.write'];


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
 * PATCH de un pago (grupo `pms_pago:patch`), derivado de SU PROPIO esquema generado.
 *
 * Antes salía de `Omit<PmsPagoFinancieroCreate, …>`, o sea del grupo de ALTA, y eso lo dejaba
 * ciego a los campos que sólo existen al parchear: `intervenido` —el candado del depósito
 * automático (§12.4.5)— no aparecía por ningún lado y el `patchPago` que lo mandaba no
 * compilaba. El esquema del PATCH ya excluye `informacionFinanciera` y `moneda` por sí mismo
 * (la moneda es inmutable una vez registrado el pago; ver §12.4), así que el `Omit` sobraba.
 *
 * `Partial` porque un merge-patch manda sólo lo que cambia, y el esquema declara obligatorios
 * los campos que tienen valor por defecto en la entidad (`monto`, `medioPago`).
 */
export type PmsPagoFinancieroPatch =
    Partial<components['schemas']['PmsPagoFinanciero-pms_pago.patch.jsonMergePatch']>;

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
