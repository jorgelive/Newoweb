<?php

declare(strict_types=1);

namespace App\Pms\Dto;

use App\Pms\Entity\PmsEventoCalendario;

/**
 * Dos estancias para la misma noche: la noche extra de `$duenio` y una noche de `$otra`.
 *
 * Lo encuentra `ChoquesDeNocheExtra` cuando un canal cambia una reserva. `$movida` es la que
 * acaba de cambiar — el aviso tiene que decir qué movió el canal, porque es lo único que el equipo
 * no ve en el calendario.
 */
final readonly class ChoqueDeNocheExtra
{
    public function __construct(
        public NocheExtra $noche,
        public PmsEventoCalendario $duenio,
        public PmsEventoCalendario $otra,
        public PmsEventoCalendario $movida,
    ) {}

    /** ¿Lo que cambió el canal es la estancia DUEÑA de la noche extra (y su noche cayó encima de otra)? */
    public function seMovioElDuenio(): bool
    {
        return $this->movida === $this->duenio;
    }
}
