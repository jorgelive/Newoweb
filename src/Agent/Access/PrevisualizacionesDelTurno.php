<?php

declare(strict_types=1);

namespace App\Agent\Access;

use Symfony\Contracts\Service\ResetInterface;

/**
 * Qué se ha previsualizado en ESTE turno del agente.
 *
 * Una previsualización hecha en el mismo turno no puede confirmarse en ese turno: entre enseñar y
 * aplicar tiene que haber un mensaje del operador, o nadie vio nada. Sin esto el modelo podía
 * llamar con `confirmado: false` y, en la vuelta siguiente del mismo bucle, con `true` — la huella
 * coincidía y se aplicaba sin que ningún humano lo hubiera aprobado. Ver
 * {@see GuardiaDeSkills::ejecutar()}.
 *
 * El turno es un mensaje de Messenger: el worker llama a `reset()` (kernel.reset) después de cada
 * uno, así que esto se vacía solo entre turnos. En la consola, cada ejecución de
 * `app:agent:skill` es un proceso nuevo y por tanto un turno nuevo.
 */
final class PrevisualizacionesDelTurno implements ResetInterface
{
    /** @var array<string, true> */
    private array $claves = [];

    public function anotar(string $clave): void
    {
        $this->claves[$clave] = true;
    }

    public function hubo(string $clave): bool
    {
        return isset($this->claves[$clave]);
    }

    public function reset(): void
    {
        $this->claves = [];
    }
}
