<?php

declare(strict_types=1);

namespace App\Pms\Dispatch;

/**
 * Representa la orden de procesar el payload crudo de un Webhook de Beds24.
 * Delega el parseo JSON y la lógica de negocio a un Worker asíncrono.
 */
final readonly class ProcessBeds24WebhookDispatch
{
    /**
     * @param int|string $auditId El ID de la auditoría en la base de datos (para actualizar el estado luego).
     * @param string $rawPayload El cuerpo crudo de la petición HTTP.
     * @param string $token El token de seguridad recibido en los headers/query.
     */
    public function __construct(
        public int|string $auditId,
        public string $rawPayload,
        public string $token
    ) {}
}