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
     * Una foto congelada de cómo estaba esta propuesta antes de tocarla.
     *
     * ⚠️ **No es una versión más, y por eso no consume número**: el histórico de la v1 sigue
     * siendo v1, distinguido por su fecha. Las versiones son *propuestas* —lo que el cliente
     * eligió entre varias—; esto es el rastro de lo que ya se le vendió.
     *
     * Nace de clonar **hacia atrás**: la copia es el pasado y la cotización viva conserva su id,
     * sus componentes, sus filas de La Biblia y sus órdenes. Clonar hacia adelante —lo que hace
     * «Nueva propuesta»— vale antes de vender; después de vender rompe el ancla de las órdenes.
     * Ver `docs/Cotizaciones.md` §6.j.
     */
    case HISTORICO = 'historico';

    /**
     * Reemplaza la antigua columna `nopublico` de la base de datos.
     * Define si el cliente final tiene acceso al enlace web o PDF de esta propuesta.
     * Solo Enviado y Confirmado son visibles para el cliente.
     */
    public function esPublico(): bool
    {
        return match($this) {
            self::ENVIADO, self::CONFIRMADO => true,
            self::PENDIENTE, self::ARCHIVADO, self::OPERADO, self::CANCELADO,
            self::HISTORICO => false,
        };
    }

    /**
     * ¿Es una foto del pasado en vez de una propuesta viva?
     *
     * Lo consultan los listados para no mezclarlas con las versiones: un histórico comparte
     * `version` con la cotización de la que salió, así que sin este filtro dos filas dirían
     * «V1» y no habría forma de saber cuál es la buena.
     */
    public function esHistorico(): bool
    {
        return $this === self::HISTORICO;
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
            self::HISTORICO => 'violet',
        };
    }
}