<?php

declare(strict_types=1);

namespace App\Operacion\Enum;

enum EstadoOrdenServicioEnum: string
{
    case BORRADOR   = 'borrador';
    case EMITIDA    = 'emitida';
    case CONFIRMADA = 'confirmada';
    case COMPLETADA = 'completada';
    case CANCELADA  = 'cancelada';
}
