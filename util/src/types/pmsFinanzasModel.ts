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
    /**
     * Id crudo del canal (`booking`, `airbnb`, `directo`), no su etiqueta.
     *
     * Va el id y no el texto porque el icono y el color de cada canal viven en `canalInfo()`
     * (`pmsReservaModel.ts`), que es la fuente única del front: si el backend mandara ya
     * resuelto el nombre, habría dos sitios donde decir cómo se llama Booking.
     */
    canal?: string | null;
    /**
     * Estado del evento (`confirmada`, `cancelada`, `bloqueo`…), crudo.
     *
     * Hace falta porque los grupos de cargos se siembran desde las estancias, tengan cargos o
     * no: sin él, una estancia CANCELADA salía como un cuadro normal a US$ 0.00 —con su unidad y
     * sus fechas— indistinguible de una viva a la que le falta el precio.
     */
    estado?: string | null;
    /**
     * Lo que esta estancia costaría según el tarifario y la ficha de la casita. Referencia
     * para quien vende, NO un importe a cobrar: en una venta directa el precio lo cierra la
     * persona, y por eso el cargo se crea en cero y esto se enseña al lado.
     *
     * Espejo de `PmsCargosAutomaticosService::costoTeorico()`. Sólo llega en las estancias
     * directas y sólo por `por-reserva`, que es la llamada del panel.
     */
    costoTeorico?: PmsFinanzasCostoTeorico | null;
}

/**
 * Desglose del coste teórico de una estancia directa.
 *
 * Espejo de `PmsCargosAutomaticosService::costoTeorico()` — al tocar allí, tocar aquí.
 *
 * ⚠️ `alojamiento` puede venir `null` con el resto relleno: significa que al tarifario le
 * faltaba alguna noche. No es cero, es «no se sabe», y por eso se pinta distinto.
 */
export interface PmsFinanzasCostoTeorico {
    /** Id de la moneda del tarifario (`USD`, `PEN`). null si el tarifario no la declaró. */
    moneda?: string | null;
    alojamiento?: {
        noches: number;
        /** Sólo si TODAS las noches valen lo mismo; con temporada de por medio llega null. */
        porNoche?: string | null;
        importe: string;
    } | null;
    paxAdicional?: {
        personas: number;
        noches: number;
        porPersonaNoche: string;
        importe: string;
    } | null;
    limpieza?: { importe: string; esPorcentaje: boolean } | null;
    total: string;
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
// ⚠️ RETIRADO el 16/08/2026: la conversión para la vista dual
//
// Aquí vivían `importeEnMoneda()`, `sumarEnMoneda()`, `RegistroConvertible` y `TotalEnMoneda`.
// Convertían cada registro a una moneda de vista con su propio tipo de cambio, para que el panel
// pudiera enseñar «todo en soles». Ese modelo se cambió entero: **los importes se suman por
// moneda y no se convierten** (§12.2b de `docs/PmsBeds24ReservasSync.md`). El panel pinta una
// pastilla por moneda con los totales que ya manda el backend en `totalesPorMoneda`.
//
// Con ellos se fueron el contador `sinConvertir` y el saldo pintado como `—`: existían sólo
// porque un registro sin tipo de cambio desaparecía de la suma, y eso ya no puede pasar.
//
// La única conversión que queda vive en el backend, en el cuadre y en la imputación de un cobro
// a otra moneda — los dos sitios donde el dinero de verdad cruzó.
// ============================================================================

// ============================================================================
// CONTABILIDAD POR MONEDA
//
// Espejo de `PmsInformacionFinanciera::getTotalesPorMoneda()` y `::getCuadre()`. Los tipos se
// DERIVAN del esquema generado: si el backend cambia un campo, esto deja de compilar.
// ============================================================================

/** Lo que se debe y lo que se ha cobrado en UNA moneda. Sin convertir. */
export type PmsTotalMoneda = NonNullable<PmsInformacionFinanciera['totalesPorMoneda']>[number];

/**
 * El balance soles↔dólares de la reserva.
 *
 * ⚠️ **No es contabilidad.** La contabilidad son los totales por moneda; esto es la cifra única
 * que hace falta para cerrar. Viaja con su `tolerancia` para poder explicar por qué cuadra o no,
 * en vez de enseñar un booleano sin argumento.
 */
export type PmsCuadre = NonNullable<PmsInformacionFinanciera['cuadre']>;

// ============================================================================
// LO QUE SIGUE VIVO DEL BLOQUE ANTERIOR
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

// ============================================================================
// DESCRIPCIONES QUE BEDS24 NO RESUELVE
// ============================================================================

/**
 * Plantilla de Beds24 que llegó SIN resolver, del tipo `[ROOMNAME1] [FIRSTNIGHT] - [LEAVINGDAY]`.
 *
 * Beds24 manda la descripción del cargo con sus propios marcadores y, en las reservas agrupadas,
 * a veces no los sustituye por nada. Lo que llega no es una descripción incompleta: es la
 * plantilla en crudo, idéntica en todos los cargos del grupo. Enseñarla es peor que no enseñar
 * nada — ocupa la línea donde el operador busca de qué es el cargo y no dice absolutamente nada.
 *
 * La casita y las fechas de ese cargo ya salen en la barra de su estancia, así que no se pierde
 * nada al ocultarlo.
 *
 * ⚠️ El dato NO se toca en la base. Es lo que el canal mandó y el cargo sigue siendo suyo; esto
 * es una decisión de PRESENTACIÓN. Si algún día Beds24 empieza a resolverlos, la descripción
 * aparecerá sola sin migrar nada.
 */
const MARCADOR_BEDS24 = /\[[A-Z][A-Z0-9]*\]/;

/**
 * La descripción que se puede enseñar, o `null` si no hay ninguna que valga.
 *
 * Se pregunta por marcador y no por el texto exacto: los marcadores de Beds24 son muchos
 * (`[GUESTNAME]`, `[BOOKINGID]`, `[ROOMNAME1]`…) y una lista literal se queda corta con el
 * primero que no habíamos visto. Un corchete con MAYÚSCULAS dentro no es texto humano.
 */
export function descripcionVisible(descripcion?: string | null): string | null {
    const texto = (descripcion ?? '').trim();

    if (texto === '' || MARCADOR_BEDS24.test(texto)) {
        return null;
    }

    return texto;
}
