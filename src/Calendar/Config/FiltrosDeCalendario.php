<?php

declare(strict_types=1);

namespace App\Calendar\Config;

use App\Dto\Lee;

/**
 * El bloque `filters` de un calendario. Lo leen dos familias con claves distintas:
 *
 * - **Tarifas**: `activeOnly` (sólo rangos activos; exige `fields.active`) y `showInactive`, que lo
 *   anula en los providers sin compactar.
 * - **Estancias**: `estado` y `estadoPago` (ver {@see FiltroDeIds}).
 *
 * ⚠️ **`establecimientoId` y `unidadIds` están en el YAML de estancias y NO los lee nadie.** Este
 * DTO tampoco: leerlos aquí haría creer que filtran. El que sí filtra por establecimiento es el del
 * catálogo de filas, `resources.establecimientoId` ({@see OpcionesDeCatalogo}).
 */
final class FiltrosDeCalendario
{
    public function __construct(
        public readonly bool $soloActivos = false,
        public readonly bool $mostrarInactivos = false,
        public readonly FiltroDeIds $estado = new FiltroDeIds(),
        public readonly FiltroDeIds $estadoPago = new FiltroDeIds(),
        // `filters: x` en vez de un mapa. Los compactados lo rechazan con su propio mensaje; los
        // demás lo toman por «sin filtros». Se conserva la diferencia, no se decide aquí.
        public readonly bool $noEsMapa = false,
    ) {}

    public static function desde(mixed $valor): self
    {
        if (!is_array($valor)) {
            return new self(noEsMapa: $valor !== null);
        }

        return new self(
            soloActivos: Lee::booleano($valor['activeOnly'] ?? null) ?? false,
            mostrarInactivos: Lee::booleano($valor['showInactive'] ?? null) ?? false,
            estado: FiltroDeIds::desde($valor['estado'] ?? null),
            estadoPago: FiltroDeIds::desde($valor['estadoPago'] ?? null),
        );
    }
}
