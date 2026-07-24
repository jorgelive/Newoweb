<?php

declare(strict_types=1);

namespace App\Cotizacion\Enum;

enum CatalogoTipoClienteEnum: string
{
    case ECONOMICO = 'economico';
    case ESTANDAR = 'estandar';
    case SUPERIOR = 'superior';
    case LUJO = 'lujo';

    public function getLabel(): string
    {
        return match($this) {
            self::ECONOMICO => 'Económico',
            self::ESTANDAR => 'Estándar',
            self::SUPERIOR => 'Superior',
            self::LUJO => 'Lujo',
        };
    }
}
