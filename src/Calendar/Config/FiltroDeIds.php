<?php

declare(strict_types=1);

namespace App\Calendar\Config;

use App\Dto\Lee;

/**
 * Un filtro por id de maestro (`estado`, `estadoPago`) del calendario de estancias.
 *
 * Admite las dos formas que ya aceptaba el provider:
 *
 * ```yaml
 * estado: { in: [confirmada], not_in: [cancelada, extension] }   # la estructurada
 * estado: [confirmada, bloqueo]                                   # la plana: equivale a `in`
 * estado: cancelada                                               # un solo id, también `in`
 * ```
 *
 * ⚠️ **`{in: [], not_in: []}` NO filtra**, y es lo que hay en casi todos los calendarios: listas
 * vacías son «sin acotar», no «nada».
 */
final class FiltroDeIds
{
    /**
     * @param list<string> $incluir
     * @param list<string> $excluir
     */
    public function __construct(
        public readonly array $incluir = [],
        public readonly array $excluir = [],
    ) {}

    public static function desde(mixed $valor): self
    {
        if (is_array($valor) && (isset($valor['in']) || isset($valor['not_in']))) {
            return new self(self::ids($valor['in'] ?? null), self::ids($valor['not_in'] ?? null));
        }

        return new self(self::ids($valor));
    }

    /**
     * Una lista de ids, o un id suelto como lista de uno (el `(array)` de antes).
     *
     * @return list<string>
     */
    private static function ids(mixed $valor): array
    {
        if (is_array($valor)) {
            return Lee::listaDeTextos($valor);
        }

        // `empty()` a propósito, como el provider: `null`, `''` y `'0'` no eran un filtro.
        if (empty($valor)) {
            return [];
        }

        $id = Lee::texto($valor);

        return $id === null ? [] : [$id];
    }
}
