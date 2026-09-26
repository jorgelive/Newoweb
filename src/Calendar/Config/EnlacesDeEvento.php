<?php

declare(strict_types=1);

namespace App\Calendar\Config;

use App\Dto\Lee;

/**
 * El bloque `url` de un calendario legacy: los enlaces que puede llevar cada evento, por nombre.
 *
 * Los nombres no son fijos —`edit`/`show` en tarifas; `reservaEdit`, `eventoCalendarioShow`… en
 * estancias—, así que se guardan en un mapa y cada provider pide los suyos. Lo que no sea un bloque
 * (un mapa) no es un enlace y no se guarda.
 */
final class EnlacesDeEvento
{
    /**
     * @param string|null $rutaId Ruta de getters del id que va en la URL (`id`, `unidad.id`). Tal
     *        cual: quien la lee decide su valor por defecto, y no es el mismo en todos.
     * @param array<string, Enlace> $enlaces
     */
    public function __construct(
        public readonly ?string $rutaId,
        private readonly array $enlaces,
    ) {}

    /** @param array<mixed> $datos */
    public static function fromArray(array $datos): self
    {
        $enlaces = [];
        foreach ($datos as $nombre => $bloque) {
            if (is_array($bloque)) {
                $enlaces[(string) $nombre] = Enlace::fromArray($bloque);
            }
        }

        return new self(Lee::texto($datos['id'] ?? null), $enlaces);
    }

    public function enlace(string $nombre): ?Enlace
    {
        return $this->enlaces[$nombre] ?? null;
    }
}
