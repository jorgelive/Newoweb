<?php

declare(strict_types=1);

namespace App\Pms\Dispatch;

/**
 * Estancias que un canal acaba de crear o cambiar: hay que mirar si chocan con una noche extra.
 *
 * Lo lanza {@see \App\Pms\EventListener\PmsChoqueNocheExtraListener} y lo atiende
 * {@see \App\Pms\DispatchHandler\RevisarChoqueDeNocheExtraDispatchHandler}. Asíncrono: el aviso
 * consulta la base y escribe un WhatsApp, y nada de eso tiene que alargar el pull.
 */
final readonly class RevisarChoqueDeNocheExtraDispatch
{
    /**
     * @param list<string> $eventoIds UUID en texto de cada `PmsEventoCalendario`.
     */
    public function __construct(
        public array $eventoIds,
    ) {}
}
