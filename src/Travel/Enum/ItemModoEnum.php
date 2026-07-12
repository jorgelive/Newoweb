<?php

declare(strict_types=1);

namespace App\Travel\Enum;

/**
 * Define el comportamiento comercial y visual de un ítem descriptivo o upsell.
 * Separa la narrativa descriptiva de los componentes logísticos mayores.
 */
enum ItemModoEnum: string
{
    case INCLUIDO = 'incluido';
    case OPCIONAL = 'opcional';
    case NO_INCLUIDO = 'no_incluido';

    /**
     * Determina si el ítem debe ser considerado para sumar un costo a la cotización
     * (por ejemplo, al aplicar un upsell OPCIONAL).
     *
     * @return bool True si el ítem genera un impacto económico calculable.
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
            self::NO_INCLUIDO => false,
            default => true,
        };
    }
}