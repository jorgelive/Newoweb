<?php

declare(strict_types=1);

namespace App\Agent\Skill;

/**
 * Lo que una skill devuelve.
 *
 * Un error de negocio **no se lanza, se devuelve**: así el modelo puede reformular o pedir
 * la aclaración que falta, en lugar de romper el turno. Una excepción es un fallo de
 * infraestructura; «no encuentro a ese huésped» no lo es.
 */
final readonly class SkillResult
{
    private function __construct(
        public array $datos,
        public ?string $error,
    ) {}

    public static function ok(array $datos): self
    {
        return new self($datos, null);
    }

    public static function error(string $mensaje): self
    {
        return new self([], $mensaje);
    }

    public function esError(): bool
    {
        return $this->error !== null;
    }

    /** Serialización neutral; cada proveedor la envuelve como necesite. */
    public function aJson(): string
    {
        $payload = $this->esError() ? ['error' => $this->error] : $this->datos;

        return json_encode($payload, JSON_UNESCAPED_UNICODE) ?: '{"error":"respuesta no serializable"}';
    }
}
