<?php

declare(strict_types=1);

namespace App\Agent\Access;

use Symfony\Contracts\Service\ResetInterface;

/**
 * Las escrituras que la guarda APLICÓ en este turno, con lo que dijo la skill (23/10/2026).
 *
 * Existe para un caso: el motor falla DESPUÉS de una escritura —se agotan los créditos entre dos
 * llamadas del mismo turno—. La guarda ya impide que un reintento escriba solo (gasta la huella al
 * aplicar, ver {@see GuardiaDeSkills::ejecutar()}), pero nadie le decía al operador que el cambio ya
 * estaba hecho: la suplencia repetía el turno y le volvía a pedir el «sí», el panel enseñaba «no se
 * pudo consultar», y un segundo «sí» lo duplicaba (un cargo, un bloqueo). Con esto la suplencia no
 * repite y el fallo dice qué se aplicó: {@see EscrituraAntesDelFallo}.
 *
 * Quien pregunta guarda la `cuantas()` de antes y pide `desde()` después: no depende de que alguien
 * la vacíe entre turnos. Aun así se vacía sola (kernel.reset) entre mensajes del worker.
 */
final class EscriturasDelTurno implements ResetInterface
{
    /** @var list<string> */
    private array $aplicadas = [];

    public function anotar(string $resumen): void
    {
        $this->aplicadas[] = $resumen;
    }

    public function cuantas(): int
    {
        return \count($this->aplicadas);
    }

    /** @return list<string> Las aplicadas después de la marca `$cuantasAntes`. */
    public function desde(int $cuantasAntes): array
    {
        return \array_slice($this->aplicadas, $cuantasAntes);
    }

    public function reset(): void
    {
        $this->aplicadas = [];
    }
}
