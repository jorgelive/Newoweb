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
    /**
     * @param array<array-key, mixed> $datos Lo que se le devuelve al modelo, ya serializable.
     *
     * ⚠️ **`array-key` y no `string`: una skill PUEDE devolver una lista.** Lo anoté primero como
     * mapa y PHPStan tumbó el `array_is_list()` de `GoogleAISkillAdapter` por «siempre falso» —
     * pero ese guarda existe porque `functionResponse.response` de Google tiene que ser un objeto
     * JSON, y una lista da 400. La anotación estrecha no sólo era falsa: apagaba la defensa que
     * demostraba que era falsa.
     */
    private function __construct(
        public array $datos,
        public ?string $error,
    ) {}

    /** @param array<array-key, mixed> $datos */
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
