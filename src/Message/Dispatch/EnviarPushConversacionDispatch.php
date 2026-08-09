<?php

declare(strict_types=1);

namespace App\Message\Dispatch;

/**
 * Pide que se envíe la notificación push de una conversación.
 *
 * Va con retraso para salir DESPUÉS del resumen IA: si se enviara al instante, el cuerpo
 * llevaría el resumen del turno anterior. Ver `MessageConversationMercureListener`.
 */
final readonly class EnviarPushConversacionDispatch
{
    public function __construct(public string $conversationId) {}
}
