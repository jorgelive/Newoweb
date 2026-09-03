<?php

declare(strict_types=1);

namespace App\Cotizacion\Enum;

/**
 * Define el estado comercial e histórico de una versión de cotización.
 * Reemplaza la antigua tabla `cot_estadocotizacion`.
 */
enum CotizacionEstadoEnum: string
{
    case PENDIENTE = 'pendiente';
    case ENVIADO = 'enviado';
    /**
     * La propuesta que se aparta: no se vendió, y sus filas de operación se cancelan.
     *
     * ⚠️ Se llamaba `ARCHIVADO` hasta el 30/08/2026, y era la misma palabra que en el expediente
     * (`FileEstadoEnum::ARCHIVADO`) significa lo CONTRARIO: allí archivar es la venta ganada. Con
     * las dos vivas en la misma pantalla —el expediente arriba, sus versiones debajo— «archivado»
     * no decía si algo había salido bien sin mirar a qué fila pertenecía.
     *
     * El vocabulario es el del chat, que es el que más se usa: **lo bueno es archivado, lo malo es
     * cerrado.** Ver `docs/Cotizaciones.md` §6.k.
     */
    case CERRADO = 'cerrado';
    case CONFIRMADO = 'confirmado';
    case OPERADO = 'operado';
    case CANCELADO = 'cancelado';

    /**
     * Una foto congelada de cómo estaba esta propuesta antes de tocarla.
     *
     * ⚠️ **No es una versión más, y por eso no consume número**: el histórico de la v1 sigue
     * siendo v1, distinguido por su fecha. Las versiones son *propuestas* —lo que el cliente
     * eligió entre varias—; esto es el rastro de lo que ya se le vendió.
     *
     * Nace de clonar **hacia atrás**: la copia es el pasado y la cotización viva conserva su id,
     * sus componentes, sus filas de La Biblia y sus órdenes. Clonar hacia adelante —lo que hace
     * «Nueva propuesta»— vale antes de vender; después de vender rompe el ancla de las órdenes.
     * Ver `docs/Cotizaciones.md` §6.j.
     */
    case HISTORICO = 'historico';

    /**
     * Lo que de verdad se va a operar, cuando deja de parecerse a lo vendido.
     *
     * ⚠️ **No consume número de propuesta**, igual que el histórico: es otra fila de la MISMA
     * propuesta, distinguida por estado. El histórico mira atrás y ésta mira adelante, y las dos
     * cuelgan de la confirmada por `derivadaDe`.
     *
     * Nace **al confirmar y sin publicar**, y eso es lo que hace que las filas de operación nazcan
     * ya aquí y **nunca haya que traspasarlas**. Crearla a demanda dejaría una ventana con dos
     * filas con operación viva a la vez — el escenario que `CotizacionConfirmadaEventListener`
     * describe como «riesgo de pedirle y pagarle dos veces lo mismo al proveedor».
     *
     * Lo que ve el cliente se compone: el **financiero de la confirmada** —ya se vendió y se
     * cobró— y el **itinerario de ésta**, si está publicada. Ver `docs/PlanPropuestaOperativa.md`.
     */
    case OPERATIVA = 'operativa';

    // ⚠️ **Aquí vivía `esPublico()`, y se borró el 02/09/2026.** La visibilidad dejó de depender
    // del estado: la decide `Cotizacion::$publicado`, un eje propio. Dejar el método habría sido
    // peor que borrarlo — el siguiente que lo leyera creería que sigue mandando, y respondería que
    // una operativa no es pública cuando sí puede serlo. Ver `docs/Cotizaciones.md` §6.j.1.

    /**
     * ¿Es una foto del pasado en vez de una propuesta viva?
     *
     * Lo consultan los listados para no mezclarlas con las versiones: un histórico comparte
     * `version` con la cotización de la que salió, así que sin este filtro dos filas dirían
     * «V1» y no habría forma de saber cuál es la buena.
     */
    public function esHistorico(): bool
    {
        return $this === self::HISTORICO;
    }

    /**
     * Helper visual para los badges en el frontend (Vue).
     */
    public function badgeColor(): string
    {
        return match($this) {
            self::PENDIENTE => 'amber',
            self::ENVIADO => 'sky',        // 🔥 faltaba, causaba UnhandledMatchError
            self::CONFIRMADO => 'emerald',
            self::OPERADO => 'blue',
            self::CERRADO => 'slate',
            self::CANCELADO => 'rose',
            self::HISTORICO => 'violet',
            self::OPERATIVA => 'orange',
        };
    }
}