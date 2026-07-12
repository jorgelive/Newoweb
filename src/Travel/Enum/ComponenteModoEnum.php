<?php

declare(strict_types=1);

namespace App\Travel\Enum;

/**
 * Define el comportamiento comercial logístico de un Componente en el itinerario.
 * Garantiza lógica estricta en la facturación, cotizaciones y generación de PDFs.
 */
enum ComponenteModoEnum: string
{
    case INCLUIDO = 'incluido';
    case NO_INCLUIDO = 'no_incluido';
    case CORTESIA = 'cortesia';
    case REEMPLAZADO = 'reemplazado';

    /**
     * Determina si el componente debe ser considerado para sumar un costo a la cotización
     * o si debe requerir la selección de una tarifa.
     * Creado para aislar la lógica de costeo de la capa de presentación.
     *
     * @return bool True si suma costo (ej. incluido), False si no suma costo (ej. cortesía, reemplazado).
     */
    public function esComisionable(): bool
    {
        return match($this) {
            self::INCLUIDO => true,
            default => false,
        };
    }

    /**
     * Determina si el componente debe mostrarse en la sección de "No Incluye" del itinerario.
     * Controla la visibilidad en los documentos finales entregados al cliente.
     *
     * @return bool False si va explícitamente en "No Incluye" o no se muestra, True de lo contrario.
     */
    public function afectaCosto(): bool
    {
        return match($this) {
            self::NO_INCLUIDO, self::REEMPLAZADO => false,
            default => true,
        };
    }
}