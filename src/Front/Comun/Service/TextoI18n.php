<?php

declare(strict_types=1);

namespace App\Front\Comun\Service;

use App\Dto\Lee;

/**
 * Lee un campo i18n (`[{language, content}, …]`) en el idioma de la página.
 *
 * Caída: idioma pedido → español (el origen de todo lo que traduce `#[AutoTranslate]`) → el
 * primero con texto. Nunca devuelve una cadena vacía cuando hay algo escrito en otro idioma: en
 * una web pública es mejor un título en español que una tarjeta sin título.
 *
 * La columna es JSON, así que llega como `mixed`: aquí es donde se convierte en tipos (ver
 * docs/TiposDeFrontera.md).
 */
final class TextoI18n
{
    public static function en(mixed $i18n, string $idioma): ?string
    {
        $porIdioma = [];
        foreach (Lee::listaDeMapas($i18n) as $fila) {
            $lang = Lee::texto($fila['language'] ?? null);
            $texto = Lee::texto($fila['content'] ?? null);
            if ($lang === null || $texto === null || trim($texto) === '') {
                continue;
            }
            $porIdioma[$lang] ??= trim($texto);
        }

        return $porIdioma[$idioma] ?? $porIdioma['es'] ?? (array_values($porIdioma)[0] ?? null);
    }
}
