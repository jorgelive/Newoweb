<?php

declare(strict_types=1);

namespace App\Panel\Helper;

/**
 * El valor de un campo tal como lo recibe el `formatValue()` de EasyAdmin: `mixed`, porque es lo
 * que haya en la propiedad —un `Uuid`, un número, un enum, `null`, una colección—.
 *
 * Los callbacks del panel lo escribían con `(string) $value`, que con un `Uuid` o un número está
 * bien y con cualquier otra cosa es la palabra «Array» o un error. Aquí se escribe lo que tiene forma
 * de texto y lo demás es vacío, que es lo que ya enseñaba el panel para un campo sin valor.
 */
final class ValorDeCampo
{
    public static function texto(mixed $valor): string
    {
        if ($valor instanceof \BackedEnum) {
            return (string) $valor->value;
        }

        return is_scalar($valor) || $valor instanceof \Stringable ? (string) $valor : '';
    }
}
