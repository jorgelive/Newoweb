<?php

declare(strict_types=1);

namespace App\Pms\Dto;

use App\Pms\Entity\PmsEventoBeds24Link;
use DateTimeImmutable;

/**
 * La noche que un horario extra deja sin vender: la víspera de una entrada temprana o la noche
 * del día de salida de una salida tardía.
 *
 * No se guarda en ningún sitio: la calcula `PmsEventoCalendario::nocheExtra()` de la casilla y de
 * las fechas de la estancia cada vez que se pregunta. Por eso sigue a la estancia sola cuando se
 * mueve — que es lo que el evento hermano no sabía hacer. Ver docs/PlanHorarioExtraSinEventos.md.
 *
 * `desde` y `hasta` son días a medianoche, semiabiertos como una estancia: la víspera del 02/02 es
 * `[01/02, 02/02)`.
 */
final readonly class NocheExtra
{
    public function __construct(
        /** `PmsEventoBeds24Link::ROL_EXTRA_ENTRADA` o `ROL_EXTRA_SALIDA`. */
        public string $rol,
        public DateTimeImmutable $desde,
        public DateTimeImmutable $hasta,
    ) {}

    public function esEntrada(): bool
    {
        return $this->rol === PmsEventoBeds24Link::ROL_EXTRA_ENTRADA;
    }

    /** Lo que se lee en Beds24 delante del nombre del huésped. */
    public function etiqueta(): string
    {
        return $this->esEntrada() ? 'Entrada temprana' : 'Salida tardía';
    }
}
