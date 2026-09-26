<?php

declare(strict_types=1);

namespace App\Panel\Helper;

/**
 * El texto que enseña el panel de un campo i18n (`[{language, content}, …]`): el español, y si no
 * lo hay, el primero.
 *
 * Estaba copiado en el `formatValue()` de cuatro CRUD, cada uno con su variante. EasyAdmin le pasa
 * al callback el valor de la columna JSON tal cual —`mixed`—, así que la forma se comprueba aquí:
 * un `content` que no sea texto era, según la copia, un `TypeError` por el tipo de retorno o la
 * palabra «Array» en el listado. Ahora es vacío, que es lo que ya enseñaba una fila sin traducir.
 */
final class VistaI18n
{
    public static function espanol(mixed $valor): string
    {
        if (!is_array($valor) || $valor === []) {
            return '';
        }

        foreach ($valor as $fila) {
            if (is_array($fila) && ($fila['language'] ?? null) === 'es') {
                return self::contenido($fila);
            }
        }

        $primera = reset($valor);

        return is_array($primera) ? self::contenido($primera) : '';
    }

    /** @param array<mixed> $fila */
    private static function contenido(array $fila): string
    {
        return is_string($fila['content'] ?? null) ? $fila['content'] : '';
    }
}
