<?php

declare(strict_types=1);

namespace App\Cotizacion\Enum;

/**
 * Define el estado comercial e histórico de una versión de cotización.
 * Reemplaza la antigua tabla `cot_estadocotizacion`.
 */
enum CotizacionEstadoEnum: string
{
    case PENDIENTE = 'Pendiente';
    case ARCHIVADO = 'Archivado';
    case CONFIRMADO = 'Confirmado';
    case OPERADO = 'Operado';
    case CANCELADO = 'Cancelado';

    /**
     * Reemplaza la antigua columna `nopublico` de la base de datos (0 = true, 1 = false).
     * Define si el cliente final tiene acceso al enlace web o PDF de esta propuesta.
     */
    public function esPublico(): bool
    {
        return match($this) {
            self::PENDIENTE, self::CONFIRMADO => true, // nopublico = 0 en el legacy
            self::ARCHIVADO, self::OPERADO, self::CANCELADO => false, // nopublico = 1 en el legacy
        };
    }

    /**
     * Helper visual para los badges en el frontend (Vue).
     */
    public function badgeColor(): string
    {
        return match($this) {
            self::PENDIENTE => 'amber',    // Amarillo/Naranja
            self::CONFIRMADO => 'emerald', // Verde éxito
            self::OPERADO => 'blue',       // Azul operativo
            self::ARCHIVADO => 'slate',    // Gris neutro
            self::CANCELADO => 'rose',     // Rojo alerta
        };
    }
}