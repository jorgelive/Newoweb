<?php

declare(strict_types=1);

namespace App\Operacion\Enum;

enum EstadoReservaEnum: string
{
    case SIN_SOLICITAR  = 'sin-solicitar';
    case SOLICITADO     = 'solicitado';
    case CONFIRMADO     = 'confirmado';
    case RECONFIRMADO   = 'reconfirmado';
    case PENDIENTE_PAGO = 'pendiente-pago';
}
