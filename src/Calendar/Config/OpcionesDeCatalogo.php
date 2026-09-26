<?php

declare(strict_types=1);

namespace App\Calendar\Config;

use App\Dto\Lee;

/**
 * El bloque `resources` de un calendario: cómo arma `CalendarResourceCatalog` las filas.
 *
 * Los valores por defecto son los que ya aplicaba el catálogo, y son los que importan: **sin bloque
 * `resources`, `showAll` es `true`** y el calendario lista TODAS las unidades, tengan o no eventos
 * en el rango. Ver la cabecera de `CalendarResourceCatalog`.
 */
final class OpcionesDeCatalogo
{
    /**
     * @param string|null $entidad Clase del recurso. `null` = la deduce el provider por la asociación.
     * @param list<string> $establecimientoIds Vacía = sin filtrar.
     * @param array<string, string> $camposExtra `extendedProps.<clave>` → ruta de getters.
     */
    public function __construct(
        public readonly bool $mostrarTodos = true,
        public readonly ?string $entidad = null,
        public readonly string $campoTitulo = '',
        public readonly string $campoActivo = 'activo',
        public readonly bool $soloActivos = false,
        public readonly string $campoEstablecimiento = 'establecimiento',
        public readonly array $establecimientoIds = [],
        public readonly array $camposExtra = [],
    ) {}

    public static function desde(mixed $valor): self
    {
        if (!is_array($valor)) {
            return new self();
        }

        $camposExtra = [];
        foreach (Lee::mapa($valor['extraFields'] ?? null) as $clave => $ruta) {
            // Una ruta ilegible deja la clave y la resuelve a `null`, como hacía el `(string)` de
            // antes: el front ve el campo vacío en vez de no verlo.
            $camposExtra[(string) $clave] = Lee::texto($ruta) ?? '';
        }

        return new self(
            mostrarTodos: Lee::booleano($valor['showAll'] ?? null) ?? true,
            entidad: Lee::texto($valor['entity'] ?? null),
            campoTitulo: Lee::texto($valor['titleField'] ?? null) ?? '',
            campoActivo: Lee::texto($valor['activeField'] ?? null) ?? 'activo',
            soloActivos: Lee::booleano($valor['activeOnly'] ?? null) ?? false,
            campoEstablecimiento: Lee::texto($valor['establecimientoField'] ?? null) ?? 'establecimiento',
            establecimientoIds: self::ids($valor['establecimientoId'] ?? null),
            camposExtra: $camposExtra,
        );
    }

    /**
     * Una lista de ids, o uno suelto como lista de uno (el `(array)` de antes).
     *
     * @return list<string>
     */
    private static function ids(mixed $valor): array
    {
        if (is_array($valor)) {
            return Lee::listaDeTextos($valor);
        }

        // `empty()` como el catálogo: `null`, `''` y `'0'` no filtraban.
        if (empty($valor)) {
            return [];
        }

        $id = Lee::texto($valor);

        return $id === null ? [] : [$id];
    }
}
