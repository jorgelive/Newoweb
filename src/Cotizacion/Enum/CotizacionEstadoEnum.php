<?php

declare(strict_types=1);

namespace App\Cotizacion\Enum;

/**
 * Define el estado comercial e histórico de una versión de cotización.
 * Reemplaza la antigua tabla `cot_estadocotizacion`.
 */
enum CotizacionEstadoEnum: string
{
    case PENDIENTE = 'pendiente';
    case ENVIADO = 'enviado';
    case ARCHIVADO = 'archivado';
    case CONFIRMADO = 'confirmado';
    case OPERADO = 'operado';
    case CANCELADO = 'cancelado';

    /**
     * Reemplaza la antigua columna `nopublico` de la base de datos.
     * Define si el cliente final tiene acceso al enlace web o PDF de esta propuesta.
     * Solo Enviado y Confirmado son visibles para el cliente.
     */
    public function esPublico(): bool
    {
        return match($this) {
            self::ENVIADO, self::CONFIRMADO => true,
            self::PENDIENTE, self::ARCHIVADO, self::OPERADO, self::CANCELADO => false,
        };
    }

    /**
     * Helper visual para los badges en el frontend (Vue).
     */
    public function badgeColor(): string
    {
        return match($this) {
            self::PENDIENTE => 'amber',
            self::ENVIADO => 'sky',        // 🔥 faltaba, causaba UnhandledMatchError
            self::CONFIRMADO => 'emerald',
            self::OPERADO => 'blue',
            self::ARCHIVADO => 'slate',
            self::CANCELADO => 'rose',
        };
    }
}