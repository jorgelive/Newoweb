<?php

declare(strict_types=1);

namespace App\Cotizacion\Enum;

enum DetalleOperativoTipoEnum: string
{
    case CLIENTE = 'cliente';
    case OPERATIVA = 'operativa';

    public function label(): string
    {
        return match ($this) {
            self::CLIENTE => 'Detalles',
            self::OPERATIVA => 'Información Operativa',
        };
    }
}