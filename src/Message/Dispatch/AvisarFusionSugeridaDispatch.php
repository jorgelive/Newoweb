<?php

declare(strict_types=1);

namespace App\Message\Dispatch;

/**
 * Pide que se avise al equipo de que un hilo tiene una fusión sugerida nueva.
 *
 * Va por el bus porque quien la detecta está dentro de un flush y avisar es I/O de red. Ver
 * `FusionSugeridaListener`.
 */
final readonly class AvisarFusionSugeridaDispatch
{
    public function __construct(public string $conversacionId) {}
}
