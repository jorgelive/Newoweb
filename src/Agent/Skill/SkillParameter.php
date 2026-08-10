<?php

declare(strict_types=1);

namespace App\Agent\Skill;

/**
 * Un parámetro que la skill necesita.
 *
 * Deliberadamente NO es un JSON Schema: describir el parámetro en términos propios permite
 * que cada proveedor lo traduzca a lo que su API espera. Una skill escrita hoy sigue valiendo
 * si mañana se enchufa otro motor con otro formato.
 */
final readonly class SkillParameter
{
    private function __construct(
        public string $nombre,
        public string $tipo,
        public string $descripcion,
        public bool $requerido,
    ) {}

    public static function texto(string $nombre, string $descripcion, bool $requerido = true): self
    {
        return new self($nombre, 'string', $descripcion, $requerido);
    }

    public static function entero(string $nombre, string $descripcion, bool $requerido = false): self
    {
        return new self($nombre, 'integer', $descripcion, $requerido);
    }

    public static function booleano(string $nombre, string $descripcion, bool $requerido = false): self
    {
        return new self($nombre, 'boolean', $descripcion, $requerido);
    }

    /**
     * Con decimales: importes, tasas, porcentajes.
     *
     * `entero()` no vale para dinero —30.50 llegaría como 30— y `texto()` obliga a la skill a
     * validar lo que el motor ya sabe validar. Los dos adaptadores lo aceptan sin tocarlos:
     * Anthropic pasa el tipo tal cual (`number`, JSON Schema) y Google lo pone en mayúsculas
     * (`NUMBER`, que es tipo válido de OpenAPI).
     */
    public static function numero(string $nombre, string $descripcion, bool $requerido = false): self
    {
        return new self($nombre, 'number', $descripcion, $requerido);
    }
}
