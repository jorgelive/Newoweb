<?php

declare(strict_types=1);

namespace App\Pms\Guia;

use App\Pms\Entity\PmsEventoCalendario;
use App\Pms\Entity\PmsGuiaItem;
use App\Pms\Entity\PmsReserva;

/** Un enlace del equipo que ya pasó todas las comprobaciones: `PmsGuiaEnlaceDelEquipo::leer()`. */
final readonly class EnlaceDelEquipoAbierto
{
    public function __construct(
        public PmsReserva $reserva,
        public PmsEventoCalendario $evento,
        public PmsGuiaItem $item,
        public \DateTimeImmutable $expiraEn,
    ) {
    }
}
