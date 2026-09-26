<?php

declare(strict_types=1);

namespace App\Pms\Dispatch;

/**
 * Estancias que un pago acaba de confirmar, para que el `confirmed` llegue también a Beds24.
 *
 * Lo lanza {@see \App\Pms\Service\Finance\PmsEstadoPagoEventosService::confirmarPorPago()} y lo
 * atiende {@see \App\Pms\DispatchHandler\AnunciarConfirmacionAlCanalDispatchHandler}. El porqué
 * está en ese handler.
 */
final readonly class AnunciarConfirmacionAlCanalDispatch
{
    /**
     * @param list<string> $eventoIds UUID en texto de cada `PmsEventoCalendario` confirmado.
     */
    public function __construct(
        public array $eventoIds,
    ) {}
}
