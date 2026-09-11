<?php

declare(strict_types=1);

namespace App\Panel\Helper;

/**
 * Ayuda larga que arranca plegada, dejando una línea que ya informa.
 *
 * ### Por qué existe, y por qué es `<details>` y no un componente
 *
 * EasyAdmin no tiene nada para esto: `collapsible()` y `renderCollapsed()` son de `FormField` y
 * esconden el **panel entero**, campos incluidos. Pero la ayuda se pinta con `|raw` —en los
 * campos del CRUD por el `form_theme`, y en un `FormType` con `'help_html' => true`—, así que un
 * `<details>` nativo pliega sólo el texto. Sin JS, sin CSS y sin un estado que mantener.
 *
 * ### La regla del resumen, que es lo único delicado
 *
 * ⚠️ **El resumen NO es el título de un desplegable vacío**: es la frase que hay que poder leer
 * **sin abrir nada**. Plegar una ayuda de forma que quien no la abra se quede sin lo esencial es
 * peor que la pared de texto — la pared al menos se lee de refilón.
 *
 * Regla práctica: si el resumen se puede sustituir por «más información» sin perder nada, está
 * mal escrito.
 *
 * ### Dónde vive
 *
 * Aquí y no en `BaseCrudController` porque los `FormType` de `src/Message/Form/Type/` también la
 * usan, y un formulario que importa un controlador para pintar una ayuda es la clase de
 * dependencia que este repo ya pagó una vez. `BaseCrudController::ayudaPlegable()` sigue
 * existiendo y delega aquí: es la firma que llaman los CRUD de guía y de travel.
 */
final class AyudaPlegable
{
    public static function html(string $resumen, string $detalle): string
    {
        return sprintf(
            '<details><summary style="cursor:pointer; list-style:revert;">%s '
            . '<span class="text-muted">— ver detalle</span></summary>'
            . '<div class="mt-1">%s</div></details>',
            $resumen,
            $detalle,
        );
    }
}
