<?php

declare(strict_types=1);

namespace App\Api;

/**
 * Una variable de la ruta (`$uriVariables` de un provider o un processor de API Platform) como texto.
 *
 * API Platform las entrega ya convertidas según el tipo del identificador —un `Uuid` para un id,
 * texto o entero para lo demás—, así que llegan como `mixed`. El `(string)` que se hacía valía para
 * todas esas formas y es lo que se conserva aquí; lo único que cambia es lo que no puede venir de
 * una ruta (un array), que ahora es el texto vacío y no la palabra «Array» con un warning.
 */
final class VariableDeRuta
{
    /** @param array<string, mixed> $uriVariables */
    public static function texto(array $uriVariables, string $nombre): string
    {
        $valor = $uriVariables[$nombre] ?? null;

        if (is_string($valor)) {
            return $valor;
        }

        return $valor instanceof \Stringable || is_int($valor) ? (string) $valor : '';
    }
}
