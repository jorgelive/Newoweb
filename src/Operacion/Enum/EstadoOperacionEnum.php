<?php

declare(strict_types=1);

namespace App\Operacion\Enum;

enum EstadoOperacionEnum: string
{
    case PENDIENTE  = 'pendiente';
    case EN_PROCESO = 'en-proceso';
    case COMPLETADO = 'completado';
    case CANCELADO  = 'cancelado';
}
