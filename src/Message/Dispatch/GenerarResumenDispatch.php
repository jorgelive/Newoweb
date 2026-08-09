<?php

declare(strict_types=1);

namespace App\Message\Dispatch;

/**
 * Pide que se regenere el resumen IA de una conversación.
 *
 * Lleva el id y no la entidad: cuando el worker lo atienda, la conversación puede haber
 * cambiado —justo lo que queremos, que resuma el estado más reciente—.
 */
final readonly class GenerarResumenDispatch
{
    public function __construct(public string $conversationId) {}
}
