<?php

declare(strict_types=1);

namespace App\Calendar\Config;

use App\Dto\Lee;

/**
 * El bloque `eventTime` de las tarifas: la hora VISUAL que se le pone al día de la base, sin mover
 * el día (ver `docs/Calendar_architecture.md` §2).
 *
 * Se guarda como texto y no como `[h, m, s]` porque cada familia de providers lo interpreta a su
 * manera: los compactados descartan las horas fuera de rango y los sin compactar no. Unificarlo
 * cambiaría lo que se pinta con un YAML raro; se deja donde estaba.
 *
 * ⚠️ El valor por defecto de `end` es `11:59:59`, pero el YAML real pone `11:59:00`.
 */
final class HorasDeEvento
{
    public const INICIO = '12:00:00';
    public const FIN = '11:59:59';

    public function __construct(
        public readonly string $inicio = self::INICIO,
        public readonly string $fin = self::FIN,
    ) {}

    public static function desde(mixed $valor): self
    {
        if (!is_array($valor)) {
            return new self();
        }

        return new self(
            inicio: Lee::texto($valor['start'] ?? null) ?? self::INICIO,
            fin: Lee::texto($valor['end'] ?? null) ?? self::FIN,
        );
    }
}
