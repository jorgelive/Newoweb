<?php

declare(strict_types=1);

namespace App\Agent\Access;

use RuntimeException;
use Throwable;

/**
 * El turno falló, pero antes ya se había aplicado algo: el operador tiene que saberlo para no
 * volver a pedirlo. Ver {@see EscriturasDelTurno}.
 *
 * El mensaje es para el OPERADOR —las escrituras sólo las hace el equipo— y se le enseña tal cual:
 * por WhatsApp en lugar del acuse, en el panel en lugar de «no se pudo consultar».
 */
final class EscrituraAntesDelFallo extends RuntimeException
{
    /** @param non-empty-list<string> $aplicadas */
    public function __construct(public readonly array $aplicadas, Throwable $fallo)
    {
        parent::__construct(sprintf(
            "✅ Ya quedó hecho: %s\n\n⚠️ Después el asistente falló y no terminó de responder. No lo vuelvas a pedir: se duplicaría.",
            implode('; ', $aplicadas)
        ), 0, $fallo);
    }
}
