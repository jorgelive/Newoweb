<?php

declare(strict_types=1);

namespace App\Contract;

use DateTimeInterface;
use InvalidArgumentException;

/**
 * El mapa de hitos de un asunto: clave de {@see ConversationMilestoneInterface} → momento.
 *
 * Es el compañero de {@see MomentoDeHito} para lo que viaja en bloque. Existe por lo mismo:
 * mientras esto era un `array` pelado, su tipo de valor **no lo sabía nadie** —ni el que lo
 * escribía ni ningún analizador—, y de ahí salieron los dos incidentes de `docs/Mensajeria.md`
 * §22.16. Un `array` no se puede tipar en una firma de PHP; una clase sí.
 *
 * ── Las claves también se validan aquí ──────────────────────────────────────
 * La comprobación de clave válida vivía suelta en `MessageConversation::addContextMilestone()` y
 * no se aplicaba en los otros dos escritores, así que un hito con clave inventada entraba sin
 * problema por el enlace y luego el motor no lo encontraba nunca. Al pasar por aquí, la
 * validación es de todos.
 */
final readonly class MapaDeHitos
{
    /** @param array<string, MomentoDeHito> $hitos */
    private function __construct(
        private array $hitos,
    ) {
    }

    public static function vacio(): self
    {
        return new self([]);
    }

    /**
     * Construye desde lo que venga: objetos de fecha del contexto vivo, o texto de un JSON.
     *
     * Las claves desconocidas y los valores ilegibles **se descartan**, no revientan. Es la misma
     * regla que en {@see MomentoDeHito::de()}: perder un hito es malo, perder el asunto entero
     * —y con él todos los recordatorios de esa reserva— es mucho peor, y es lo que pasaba.
     *
     * @param array<string, DateTimeInterface|string|array{date?: string}|null> $crudos
     */
    public static function desdeCrudo(array $crudos): self
    {
        $hitos = [];

        foreach ($crudos as $clave => $valor) {
            if (!in_array($clave, ConversationMilestoneInterface::CLAVES, true)) {
                continue;
            }

            $momento = MomentoDeHito::de($valor);

            if (null !== $momento) {
                $hitos[$clave] = $momento;
            }
        }

        return new self($hitos);
    }

    /** @param array<string, MomentoDeHito> $hitos */
    public static function de(array $hitos): self
    {
        foreach ($hitos as $clave => $_) {
            if (!in_array($clave, ConversationMilestoneInterface::CLAVES, true)) {
                throw new InvalidArgumentException(sprintf(
                    'El hito "%s" no es válido. Sólo se permiten las constantes de %s.',
                    $clave,
                    ConversationMilestoneInterface::class,
                ));
            }
        }

        return new self($hitos);
    }

    /** Devuelve una copia con ese hito puesto (o quitado, si el momento es `null`). */
    public function con(string $clave, ?MomentoDeHito $momento): self
    {
        $hitos = $this->hitos;

        if (null === $momento) {
            unset($hitos[$clave]);
        } else {
            $hitos[$clave] = $momento;
        }

        return self::de($hitos);
    }

    public function obtener(string $clave): ?MomentoDeHito
    {
        return $this->hitos[$clave] ?? null;
    }

    public function tiene(string $clave): bool
    {
        return isset($this->hitos[$clave]);
    }

    /**
     * La forma que va a la columna JSON y la que sale por la API.
     *
     * @return array<string, string>
     */
    public function comoTextoPlano(): array
    {
        return array_map(
            static fn (MomentoDeHito $m): string => $m->comoTexto(),
            $this->hitos,
        );
    }

    /** @return array<string, MomentoDeHito> */
    public function todos(): array
    {
        return $this->hitos;
    }
}
