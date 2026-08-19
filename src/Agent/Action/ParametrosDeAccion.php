<?php

declare(strict_types=1);

namespace App\Agent\Action;

/**
 * Lo que una regla del autorresponder le pasa a su acción, ya tipado.
 *
 * Sustituye al `array $parameters` pelado de {@see BotActionHandlerInterface::execute()}, que
 * tenía su propia entrada en `phpstan-baseline.neon` desde el día que el proyecto pasó al nivel
 * 6: una firma sin decir qué lleva dentro es exactamente lo que ese nivel persigue.
 *
 * El origen es un `json` que teclea un operador en EasyAdmin
 * ({@see \App\Agent\Entity\AutoResponderRule::getActionParameters()}), así que **puede venir
 * cualquier cosa**. Por eso no se declara una forma concreta con propiedades: se declara un
 * acceso que siempre devuelve algo con lo que se puede trabajar, y la validación de si esa
 * clave existía la hace quien la necesita.
 */
final readonly class ParametrosDeAccion
{
    /** @param array<string, scalar|null> $valores */
    private function __construct(
        private array $valores,
    ) {}

    /**
     * Desde lo que hay guardado en la regla.
     *
     * Lo que no sea escalar se descarta en la puerta —un objeto o un array anidado en un
     * parámetro de configuración es un error de tecleo, y arrastrarlo dentro sólo cambia el
     * sitio donde revienta—.
     *
     * @param array<array-key, mixed>|null $crudos
     */
    public static function desdeCrudo(?array $crudos): self
    {
        $valores = [];

        foreach ($crudos ?? [] as $clave => $valor) {
            if (is_scalar($valor) || $valor === null) {
                $valores[(string) $clave] = $valor;
            }
        }

        return new self($valores);
    }

    /** El valor como texto, o `null` si no está o vino vacío. */
    public function texto(string $clave): ?string
    {
        $valor = $this->valores[$clave] ?? null;

        if ($valor === null || is_bool($valor)) {
            return null;
        }

        $texto = trim((string) $valor);

        return $texto === '' ? null : $texto;
    }

    public function tiene(string $clave): bool
    {
        return $this->texto($clave) !== null;
    }

    /** @return array<string, scalar|null> Para el log: qué se le pasó de verdad a la acción. */
    public function todos(): array
    {
        return $this->valores;
    }
}
