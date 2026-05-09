<?php

declare(strict_types=1);

namespace App\Travel\Enum;

/**
 * Define el comportamiento comercial y visual de un ítem dentro de un componente.
 * Reemplaza las tablas dinámicas para garantizar lógica estricta en la facturación y generación de PDFs.
 */
enum ComponenteItemModoEnum: string
{
    case INCLUIDO = 'incluido';
    case OPCIONAL = 'opcional';
    case NO_INCLUIDO = 'no_incluido';
    case CORTESIA = 'cortesia';

    /**
     * Determina si el ítem debe ser considerado para sumar un costo a la cotización
     * o si debe requerir la selección de una tarifa.
     *
     * @return bool
     */
    public function esComisionable(): bool
    {
        return match($this) {
            self::INCLUIDO, self::OPCIONAL => true,
            default => false,
        };
    }

    /**
     * Determina si el ítem debe mostrarse en la sección de "No Incluye" del itinerario.
     *
     * @return bool
     */
    public function afectaCosto(): bool
    {
        return match($this) {
            self::NO_INCLUIDO => true,
            default => false,
        };
    }
}