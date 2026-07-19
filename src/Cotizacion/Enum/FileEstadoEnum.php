<?php

declare(strict_types=1);

namespace App\Cotizacion\Enum;

enum FileEstadoEnum: string
{
    case ABIERTO = 'abierto';
    case CERRADO = 'cerrado';
    case ARCHIVADO = 'archivado';

    /**
     * Opcional: Útil si en algún momento necesitas renderizar
     * el label directamente desde el backend.
     */
    public function getLabel(): string
    {
        return match($this) {
            self::ABIERTO => 'Abierto',
            self::CERRADO => 'Cerrado',
            self::ARCHIVADO => 'Archivado (no venta)',
        };
    }
}