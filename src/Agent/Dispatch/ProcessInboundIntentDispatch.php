<?php

declare(strict_types=1);

namespace App\Agent\Dispatch;

final readonly class ProcessInboundIntentDispatch
{
    /**
     * @param bool $yaEsperado El pre-router ya dijo «espera» una vez y el trabajo se
     *        reencoló con la ventana completa. Corta el bucle: al segundo paso se procesa
     *        sin volver a preguntar. Sin esto, un modelo que respondiera «espera» siempre
     *        reencolaría el mensaje indefinidamente.
     */
    public function __construct(
        public string $messageId,
        public bool $yaEsperado = false,
    ) {}
}