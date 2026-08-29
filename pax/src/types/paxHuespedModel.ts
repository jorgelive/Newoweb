// src/types/paxHuespedModel.ts
// ============================================================================
// Tipos del módulo PAX / Huésped.
//
// Todo lo que tiene schema se ancla con components['schemas'][...]. Aquí sólo se
// escriben a mano las estructuras que el backend NO describe: las columnas JSON
// (PmsContenidoTraducible) y el estado de cuenta que arma un provider a mano
// (PmsResumenFinanciero), y en esos casos el comentario cita quién las produce.
//
// 🔥 Antes había tres tipos a mano —PmsUnidad, PmsEventoEstado y
// PmsEventoCalendario— por una causa que resultó ser un ASTERISCO. El docblock de
// `PmsReserva::getEventosActivosGuia()` tenía `* * @return array<int, ...>`: con el
// asterisco extra el tag se lee como texto suelto, así que API Platform no sabía qué
// había dentro del `array` y publicaba `string[]`. Sin schema anidado, el front no
// tenía más remedio que declarar el árbol entero a mano — y esos tipos a mano
// envejecieron hasta apuntar a `PmsUnidad-pax_evento.read`, un grupo de
// serialización que ya no existe en el backend.
//
// Corregido el docblock, el schema es real y los tres tipos se anclan. La lección
// para la próxima: si un tipo del backend "no tiene schema", sospecha del docblock
// antes de escribirlo a mano.
//
// Los tipos del árbol de la guía ya no viven aquí; ver la nota de más abajo.
// ============================================================================

import type { components } from './api';

// --- Tipos de contenido traducible (columnas JSON, sin schema propio en api.d.ts) ---

/** Elemento de contenido multiidioma: { language, content } */
export interface PmsContenidoTraducible {
    language: string;
    content: string;
}

// --- PmsChannel: anclado a api.d.ts ---

export type PmsChannel = components['schemas']['PmsChannel-pax_reserva.read'];

// --- Eventos de la guía: anclados, sin overrides ---
// `slug` es el segundo segmento del enlace a la guía: /huesped/reserva/{loc}/{slug}.

export type PmsUnidad = components['schemas']['PmsUnidad-pax_reserva.read'];

export type PmsEventoEstado = components['schemas']['PmsEventoEstado-pax_reserva.read'];

export type PmsEventoCalendario = components['schemas']['PmsEventoCalendario-pax_reserva.read'];

/**
 * Estado de cuenta del huésped. Espejo del array que arma
 * `PmsReservaPaxProvider` (backend); importes como strings decimales en la
 * moneda de la cabecera financiera. Solo el agregado: el detalle de cargos y
 * pagos nunca viaja al cliente.
 */
/**
 * El RESUMEN, tal como lo decide `PmsSituacionDeCobro` en el backend.
 *
 * Es la parte que se lee de un vistazo —cuánto y por qué medios— y no sustituye a
 * `total`/`porMoneda`/`lineas`, que siguen alimentando el detalle desplegable.
 *
 * ⚠️ **Espejo del método `PmsReservaPaxProvider::comoResumen()`**, no del objeto de dominio:
 * las fichas de cada medio (titular, banco, número, CCI) NO viajan aquí — son del detalle, y
 * volcarlas pondría cuentas bancarias en la primera pantalla de todo el mundo.
 *
 * `motivo` llega como identificador (`SALDADA`, `CRUCE_DE_MONEDAS`…), no como frase: el
 * read-model devuelve hechos y el texto lo pone quien rinde.
 */
export interface PmsSituacionDeCobro {
    /** `ADELANTO` · `TOTAL` · `NADA`. */
    queSePide: string;
    /** Sólo con `queSePide: 'NADA'`. Identificador de `PmsMotivoSinCobro`. */
    motivo?: string | null;
    hayAlgoQuePedir: boolean;
    /** Una entrada POR MONEDA y sin convertir: con dos, se enseñan dos totales. */
    importes: {
        moneda: string;
        simbolo?: string | null;
        importe: string;
        /** Equivalencia orientativa. `null` si no consta que pague desde Perú. */
        enSoles?: string | null;
    }[];
    /**
     * Cómo puede pagarlo, agrupado **POR IMPORTE**.
     *
     * Espejo de `PmsSituacionDeCobro::mediosPorImporte()`. No es una lista de medios: es una
     * lista de **precios**, cada uno con los medios que lo comparten. Listar los medios uno a
     * uno daba seis líneas para decir dos cifras —«Yape 164.10 · Plin 164.10 · Efectivo
     * 164.10…»— que es el mismo ruido que el resumen viene a quitar.
     *
     * Lo que el huésped decide no es «¿por dónde pago?» sino «¿me cuesta lo mismo?», y sólo
     * hay dos respuestas: el precio normal y el de tarjeta con su recargo dentro.
     */
    medios: {
        importe: string;
        enSoles?: string | null;
        /** `null` sin recargo. Se dice como matiz, no como un cálculo que hacer. */
        recargoPorcentaje?: string | null;
        /**
         * Los códigos de los medios que cuestan eso (`yape`, `efectivo`…).
         *
         * Se traducen con `res_medio_{codigo}` de `UiI18n`: las `etiquetas` que manda el
         * backend salen de un enum PHP y están en español.
         */
        codigos: string[];
        /** Respaldo en español, por si falta la cadena traducida. */
        etiquetas: string[];
        /**
         * Los datos para ejecutar cada medio, indexados por código.
         *
         * Un medio puede tener VARIAS —«transferencia» son tres cuentas de tres bancos— y por
         * eso es una lista. Se enseñan detrás de una «i», no en la primera pantalla.
         */
        fichas: Record<string, {
            titular?: string;
            titularAlterno?: string;
            banco?: string;
            numero?: string;
            cci?: string;
            /**
             * El código ISO («PEN»), con el que la app elige el rótulo en palabras.
             *
             * La fila se rotula «En soles», no «S/.»: en esa posición el símbolo se lee como
             * el prefijo de un precio que no está. Ver `ETIQUETA_MONEDA` en la vista.
             */
            moneda?: string;
            /** El símbolo («S/.»), respaldo para una moneda sin cadena propia. */
            monedaSimbolo?: string;
            /**
             * El array i18n crudo, que traduce `maestroStore.traducir()`.
             *
             * No llega resuelto del servidor a propósito: el idioma de la tarjeta lo pone el
             * selector del huésped, no el `idioma` que se dedujo al crear la reserva —hay
             * reservas con uno y otro distintos—. Mismo trato que la guía le da a esta nota.
             */
            nota?: PmsContenidoTraducible[];
        }[]>;
    }[];
    /**
     * Clave i18n del aviso de «ya pagué»: `res_aviso_pago` o `res_aviso_pago_sin_imagenes`.
     *
     * La elige el provider según el canal de la reserva —el chat de Booking no transporta
     * imágenes, el resto sí— y la resuelve `maestroStore.t()`. Va aquí y no en la nota de cada
     * medio porque no es del medio: es del canal. Ver `PmsChannel::CHAT_SIN_IMAGENES`.
     */
    avisoPago: string;
}

export interface PmsResumenFinanciero {
    moneda: string;
    simbolo?: string | null;
    /**
     * Sin cifras que enseñar: reserva de un canal que cobra por nosotros
     * (Airbnb, VRBO) y sin cargos añadidos a mano. Se pinta la barra al 100 %
     * como acuse de recibo y NADA más — los importes del canal son lo que la
     * OTA nos remite, no lo que el huésped pagó. Ver `PmsReservaPaxProvider::cifras()`.
     *
     * Cuando viene `true`, `total`/`pagado`/`saldo` NO llegan.
     */
    soloProgreso?: boolean;
    /** El resumen ya decidido. No llega con `soloProgreso`. */
    situacion?: PmsSituacionDeCobro | null;
    /**
     * El CUADRE, no la suma de una moneda: los saldos de todas llevados a `monedaCuadre` con el
     * tipo de cambio de la reserva.
     *
     * Alimenta la barra de progreso y el titular «cuánto falta», que son preguntas que sólo
     * admiten una respuesta. El detalle que el huésped lee va **sin convertir** en `porMoneda`.
     * Cuando `mixta` es true la tarjeta lo escribe con `≈`: es aproximado y hay que decirlo.
     */
    total?: string;
    pagado?: string;
    saldo?: string;
    /** Moneda en la que se expresan `total`, `pagado` y `saldo`. */
    monedaCuadre?: string;
    /**
     * ¿La reserva tiene movimiento en más de una moneda?
     *
     * Con `true`, las tres cifras de arriba están convertidas y la tarjeta las marca con `≈`.
     * Y el conmutador de soles NO se ofrece: convertir una de dos deja la tarjeta sin cuadrar
     * consigo misma, y el huésped ya tiene delante lo que pagó en cada una.
     */
    mixta?: boolean;
    /**
     * Cruce de monedas ya saldado: pagó en una moneda una cuenta emitida en otra.
     *
     * Lo decide `PmsTotalesPorMoneda::sugiereImputacion()` —mixta + cruce + cuadre dentro de la
     * tolerancia—, **no la vista**: deducirlo aquí con `saldo <= 0` deja fuera los cruces con
     * residuo de cambio y sería una segunda vara de medir el mismo dinero.
     */
    cruceSaldado?: boolean;
    /**
     * Lo que se debe y lo que se ha pagado **en cada moneda**, sin convertir.
     *
     * Es la verdad de la tarjeta: quien pagó S/ 223.70 por Yape tiene que ver S/ 223.70, no una
     * cifra en dólares que no reconoce de ningún recibo suyo.
     */
    porMoneda?: Array<{
        moneda: string;
        simbolo?: string | null;
        cargos: string;
        pagado: string;
        saldo: string;
    }>;
    /**
     * Detalle línea a línea, con la descripción redactada PARA EL HUÉSPED.
     *
     * Espejo de `PmsInformacionFinanciera::getLineasCliente()`. Agrupado por tipo, un ajuste
     * de cuadre de −0.20 quedaba sumado dentro de «Otros» y era imposible de interpretar;
     * aquí cada cargo puede explicarse.
     *
     * `descripcion` es `I18nContent[]` sin resolver: se traduce en el front con
     * `maestroStore.traducir()`. Llega vacía en los cargos que no necesitan explicación,
     * que son la mayoría.
     */
    lineas?: Array<{
        tipo: string;
        descripcion: Array<{ content: string; language: string }>;
        monto: string;
    }>;
    /**
     * Equivalencia REFERENCIAL en soles, para el conmutador de la tarjeta.
     *
     * Es UN solo tipo de cambio —el del día— para toda la tarjeta, no el congelado de cada
     * cargo: con los históricos las líneas no sumarían el total convertido. No es lo que se
     * cobró ni lo que se va a cobrar, así que **la pantalla tiene que decir que es
     * referencial**. Ver `PmsReservaPaxProvider::referenciaSoles()`.
     *
     * No llega si la cabecera ya está en soles o no hay tipo de cambio del día: entonces el
     * conmutador no se pinta.
     */
    /**
     * Prepago pendiente, o `null` si no procede.
     *
     * Solo llega a quien NO ha pagado nada todavía: si hay algún pago registrado, ese pago
     * ES el prepago —es lo primero que se cobra— y volver a pedírselo sería reclamarle algo
     * que ya hizo. Tampoco llega en Airbnb/VRBO, donde el canal ya cobró.
     *
     * `claveI18n` es la clave del diccionario (`res_prepago_*`) que explica la política; el
     * texto se resuelve con `maestroStore.t()`. Espejo de `PmsPrepagoCalculador::calcular()`.
     */
    prepago?: {
        monto: string;
        claveI18n: string;
        politica: string;
    } | null;
    /**
     * Enlaces de pago VIGENTES de esta reserva: por los que puede pagar ahora mismo.
     *
     * Espejo de `PmsReservaPaxProvider::enlacesPagables()`. Sólo llega lo mínimo para pintar
     * el botón; la URL la arma la app como `/pago/{token}` sobre su propio router, igual que
     * el enlace que le llegó por WhatsApp.
     *
     * ⚠️ **`montoNeto` y `montoTotal` no coinciden, y no es un error**: el neto es lo que
     * abona la reserva y el total lo que se le pasa a la tarjeta (el recargo se lo queda la
     * pasarela). El botón enseña el TOTAL —es lo que verá en su extracto— y dice el recargo.
     *
     * No llega en las reservas `soloProgreso` ni mientras `FINANZAS_ENLACES_PREPAGO` esté
     * apagado; entonces sencillamente no hay botón.
     */
    enlacesPago?: Array<{
        token: string;
        concepto: string;
        montoNeto: string;
        montoTotal: string;
        recargoPorcentaje: string;
        moneda?: string | null;
        simbolo?: string | null;
        expiraEn?: string | null;
    }>;
    tipoCambioReferencial?: string;
    monedaReferencial?: string;
    simboloReferencial?: string;
    /**
     * Cargos agrupados por tipo: `{ alojamiento: '350.00', limpieza: '15.00' }`.
     *
     * La clave es el valor de `PmsTipoCargo` (PHP), **no una descripción**: las
     * descripciones vienen de Beds24 en un solo idioma y no se pueden traducir.
     * Con el tipo, el front resuelve la etiqueta por i18n (`res_cargo_*`).
     *
     * Llega VACÍO si las líneas no suman el total —cargos en varias monedas—:
     * un desglose que no cuadra es peor que ninguno.
     */
    cargos?: Record<string, string>;
    /** Pagos visibles, de más antiguo a más reciente. `medio` es `PmsMedioPago`. */
    pagos?: PmsPagoResumen[];
}

export interface PmsPagoResumen {
    /** 'YYYY-MM-DD', o null si el pago no tiene fecha registrada. */
    fecha: string | null;
    /** Valor del enum PmsMedioPago; se traduce en el front con `res_medio_*`. */
    medio: string;
    monto: string;
}

/**
 * `eventosActivosGuia` ya NO se sobreescribe: el schema lo tipa correctamente desde
 * que se arregló el docblock del getter (ver la cabecera). El único override que
 * queda es `resumenFinanciero`, que no sale de una entidad sino de un array que
 * arma `PmsReservaPaxProvider` a mano y del que no hay schema posible.
 */
export type PmsReserva = components['schemas']['PmsReserva-pax_reserva.read'] & {
    resumenFinanciero?: PmsResumenFinanciero | null;
};

// ============================================================================
// Los tipos del ÁRBOL de la guía (PmsGuia, PmsGuiaSeccion, PmsGuiaItem) y
// `GuiaHelperContext` vivían aquí y se han retirado con el contrato viejo.
//
// Aquel contrato eran dos peticiones: el CMS por UUID de unidad más un
// "helper" que traía el diccionario de valores sensibles para que el navegador
// interpolara los `{{ door_code }}`. Ya no existe ninguno de los dos endpoints.
//
// El sustituto para el catálogo público está en `paxCatalogoUnidadModel.ts`.
// El de la guía del huésped se escribirá contra el grupo `pax_guia:read`
// (GET /platform/client/pax/pms/pms_guia/{localizador}), cuyo payload llega
// ya podado por visibilidad y con los placeholders resueltos: no lleva
// diccionario de valores ni banderas de bloqueo que interpretar.
// ============================================================================
