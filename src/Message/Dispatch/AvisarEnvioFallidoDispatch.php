<?php

declare(strict_types=1);

namespace App\Message\Dispatch;

/**
 * Pide que se avise de que un mensaje escrito por una persona no llegó a salir.
 *
 * Va por el bus y no en el propio listener porque mandar push es I/O de red y quien detecta
 * el fallo está dentro de un flush de Doctrine. Ver `AvisoEnvioFallidoListener`.
 */
final readonly class AvisarEnvioFallidoDispatch
{
    public function __construct(public string $messageId) {}
}
