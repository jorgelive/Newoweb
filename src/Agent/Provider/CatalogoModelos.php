<?php

declare(strict_types=1);

namespace App\Agent\Provider;

/**
 * Convierte la lista de modelos de una variable de entorno en la lista blanca del motor.
 *
 * Vive fuera de los proveedores porque la invariante es la misma para todos y es la que
 * evita una configuración rota: **el modelo por defecto siempre está en la lista**. Sin eso,
 * poner `ANTHROPIC_MODEL` sin acordarse de `ANTHROPIC_MODELS` dejaría un motor cuyo propio
 * modelo por defecto no valida.
 */
final class CatalogoModelos
{
    /**
     * @param string $porDefecto Modelo de `<PROVEEDOR>_MODEL`.
     * @param string $lista Modelos de `<PROVEEDOR>_MODELS`, separados por comas.
     * @return non-empty-list<string> El de por defecto primero, sin repetidos.
     */
    public static function desde(string $porDefecto, string $lista): array
    {
        $modelos = array_filter(array_map(trim(...), explode(',', $lista)), static fn (string $m) => $m !== '');

        return array_values(array_unique([trim($porDefecto), ...$modelos]));
    }
}
