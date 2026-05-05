<?php

declare(strict_types=1);

namespace App\Cotizacion\Enum;

/**
 * Define el estado operativo de un componente logístico en la cotización.
 * Reemplaza la antigua tabla de estados en base de datos.
 */
enum ComponenteEstadoEnum: string
{
    case PENDIENTE = 'Pendiente';
    case CONFIRMADO = 'Confirmado';
    case RECONFIRMADO = 'Reconfirmado';
    case CANCELADO = 'Cancelado';

    /**
     * Retorna el color principal heredado del sistema legacy (para badges/UI general).
     */
    public function colorLegacy(): string
    {
        return match($this) {
            self::PENDIENTE => 'red',
            self::CONFIRMADO => 'steelblue',
            self::RECONFIRMADO => 'seagreen',
            self::CANCELADO => 'violet',
        };
    }

    /**
     * Retorna el color heredado para las vistas de calendario operativo.
     */
    public function colorCalendar(): string
    {
        return match($this) {
            self::PENDIENTE => '#01ff28',
            self::CONFIRMADO => '#cccccc',
            self::RECONFIRMADO => 'white',
            self::CANCELADO => 'red',
        };
    }
}