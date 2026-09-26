<?php

declare(strict_types=1);

namespace App\Calendar\Config;

/**
 * El bloque `fields` de los calendarios de tarifas: qué getter de la entidad da cada dato.
 *
 * Cada valor es una **ruta de getters** separada por puntos (`unidad.nombre` →
 * `getUnidad()->getNombre()`), y quien la resuelve es el provider, que es el que tiene la entidad.
 * Aquí sólo se lee qué ruta pidió el YAML.
 *
 * Lo sirven dos familias de providers con claves distintas, y el DTO lleva las de las dos:
 *
 * | Familia                        | Recurso                                   | Obligatorias           |
 * |--------------------------------|-------------------------------------------|------------------------|
 * | `tarifa_ranges_*`              | `resourceRoot`, `resourceId`, `resourceTitle` | `start`, `end`, `price` |
 * | `tarifa_compressed_ranges_*`   | `unit`, `unitId`, `unitTitle`             | `unit`, `start`, `end`, `price` |
 *
 * **Una ruta vacía o que no sea texto es una ruta que no está** (`null`). Es lo que ya hacía el
 * `!empty()` de los providers sin compactar; los compactados miraban `isset()`, y con una ruta
 * vacía resolvían a `null` igualmente, porque ninguna entidad tiene un `get()` a secas.
 *
 * Qué es obligatorio lo decide cada provider —y su mensaje de error, que nombra al provider—, no
 * este DTO: por eso aquí todo es anulable.
 */
final class CamposDeTarifa
{
    public function __construct(
        public readonly ?string $start = null,
        public readonly ?string $end = null,
        public readonly ?string $price = null,
        public readonly ?string $id = null,
        public readonly ?string $minStay = null,
        public readonly ?string $currency = null,
        public readonly ?string $active = null,
        public readonly ?string $important = null,
        public readonly ?string $weight = null,
        public readonly ?string $resourceRoot = null,
        public readonly ?string $resourceId = null,
        public readonly ?string $resourceTitle = null,
        public readonly ?string $unit = null,
        public readonly ?string $unitId = null,
        public readonly ?string $unitTitle = null,
    ) {}

    /** @param array<mixed> $datos */
    public static function fromArray(array $datos): self
    {
        return new self(
            start: self::ruta($datos['start'] ?? null),
            end: self::ruta($datos['end'] ?? null),
            price: self::ruta($datos['price'] ?? null),
            id: self::ruta($datos['id'] ?? null),
            minStay: self::ruta($datos['minStay'] ?? null),
            currency: self::ruta($datos['currency'] ?? null),
            active: self::ruta($datos['active'] ?? null),
            important: self::ruta($datos['important'] ?? null),
            weight: self::ruta($datos['weight'] ?? null),
            resourceRoot: self::ruta($datos['resourceRoot'] ?? null),
            resourceId: self::ruta($datos['resourceId'] ?? null),
            resourceTitle: self::ruta($datos['resourceTitle'] ?? null),
            unit: self::ruta($datos['unit'] ?? null),
            unitId: self::ruta($datos['unitId'] ?? null),
            unitTitle: self::ruta($datos['unitTitle'] ?? null),
        );
    }

    /**
     * Sólo texto, y no vacío. Sin `Lee::texto()` a propósito: un número no es una ruta de getters,
     * y convertirlo a «5» pediría un `get5()`.
     */
    private static function ruta(mixed $valor): ?string
    {
        return is_string($valor) && $valor !== '' ? $valor : null;
    }
}
