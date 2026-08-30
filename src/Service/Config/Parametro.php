<?php

declare(strict_types=1);

namespace App\Service\Config;

use LogicException;

/**
 * Lee un parámetro del contenedor **sabiendo que es texto**.
 *
 * `ContainerInterface::getParameter()` declara `array|bool|float|int|string|UnitEnum|null`, que es
 * la verdad: en `services.yaml` cabe cualquier cosa. El problema es lo que se hace con eso —
 * `rtrim($this->getParameter('api_host_url'), '/')`— porque si el parámetro no existe, está mal
 * escrito o alguien lo convirtió en lista, el fallo sale **dentro de `rtrim()`**, sin decir qué
 * parámetro era ni desde dónde se pidió.
 *
 * Ocho sitios lo hacían así. Esta clase no añade comportamiento: mueve el fallo al sitio donde se
 * entiende y le pone nombre.
 *
 * ⚠️ **Lanza en vez de devolver `''`.** Un host vacío no da error: construye URLs como
 * `/chat?id=…` que parecen correctas y llevan al sitio equivocado. Es la clase de fallo que este
 * proyecto persigue —el que no se ve— y por eso aquí se prefiere que reviente.
 */
final class Parametro
{
    /**
     * `mixed` a secas y no la unión que declara Symfony: el `array<...>` de esa unión obligaría a
     * declarar qué lleva dentro un parámetro que precisamente NO nos interesa —lo único que se
     * comprueba es que sea texto—.
     */
    public static function texto(mixed $valor, string $nombre): string
    {
        if (!is_string($valor)) {
            throw new LogicException(sprintf(
                'El parámetro «%s» tenía que ser texto y es %s. Revisa `config/services.yaml`: '
                .'o no existe, o alguien le cambió el tipo.',
                $nombre,
                get_debug_type($valor),
            ));
        }

        return $valor;
    }
}
