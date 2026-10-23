<?php

declare(strict_types=1);

namespace App\Agent\Conversation;

/**
 * Por qué un motor no va a volver a responder solo: hay que hacer algo fuera del sistema.
 *
 * Lo decide el cliente de CADA proveedor, que es quien conoce su API, y no el núcleo sobre el texto
 * del error. Ver {@see FalloDelMotor} y docs/Agent.md («Qué manda cada motor cuando falla»).
 */
enum MotivoDefinitivo: string
{
    /** Sin saldo o sin crédito: hay que recargar. */
    case CREDITOS = 'creditos';
    /** Clave mala, caducada o sin permiso: hay que revisarla en el servidor. */
    case CLAVE = 'clave';
}
