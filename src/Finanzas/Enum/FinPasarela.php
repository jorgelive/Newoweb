<?php

declare(strict_types=1);

namespace App\Finanzas\Enum;

/**
 * Pasarela que procesa el cobro.
 *
 * Hoy sólo Izipay, pero se guarda en la fila desde el primer día: el día que entre una
 * segunda pasarela, los enlaces históricos tienen que seguir sabiendo quién los cobró
 * para poder conciliar. Añadir la columna después obligaría a adivinarlo.
 */
enum FinPasarela: string
{
    /** Izipay Perú, sobre la plataforma Lyra (`api.micuentaweb.pe`). */
    case IZIPAY = 'izipay';

    public function etiqueta(): string
    {
        return match ($this) {
            self::IZIPAY => 'Izipay',
        };
    }
}
