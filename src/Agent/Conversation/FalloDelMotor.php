<?php

declare(strict_types=1);

namespace App\Agent\Conversation;

use RuntimeException;
use Throwable;

/**
 * Un proveedor de IA que respondió con error, con lo que de verdad dice: su código HTTP y si el
 * fallo se arregla solo o no (23/10/2026).
 *
 * ### Por qué un tipo y no el texto
 *
 * Hasta ese día `DecisionDelSondeo::esDefinitivo()` buscaba `401|402|403|credit|billing|api key`
 * en el mensaje de la excepción. Con lo que de verdad mandan los tres proveedores, fallaba por los
 * dos lados: DeepSeek sin saldo llega como «Insufficient Balance» —sin código, porque nuestro cliente
 * lo descartaba, y sin esas palabras— y no salía de servicio; y Gemini manda sus límites de ritmo
 * (429) remitiendo a «your plan and billing details», así que un pico de tráfico lo habría sacado.
 *
 * Ahora lo decide **el cliente de cada motor**, que tiene el código en la mano y conoce sus casos:
 * Gemini usa `RESOURCE_EXHAUSTED` tanto para el 402 de créditos como para el 429 de ritmo (visto el
 * 23/10/2026), así que su `status` no distingue nada y sólo vale el código; Anthropic, sin crédito,
 * responde 400 y no 402. Ver docs/Agent.md («Qué manda cada motor cuando falla»).
 */
final class FalloDelMotor extends RuntimeException
{
    public function __construct(
        string $mensaje,
        public readonly ?int $estadoHttp = null,
        public readonly ?MotivoDefinitivo $definitivo = null,
        ?Throwable $previo = null,
    ) {
        parent::__construct($mensaje, $estadoHttp ?? 0, $previo);
    }

    /**
     * La regla que comparten los tres proveedores: 402 son créditos; 401 y 403, la clave. El resto
     * —429, 5xx, 400 de formato— se repone solo o es un fallo nuestro, y no saca a nadie de servicio.
     */
    public static function porCodigoHttp(string $mensaje, int $estado, ?Throwable $previo = null): self
    {
        $definitivo = match ($estado) {
            402 => MotivoDefinitivo::CREDITOS,
            401, 403 => MotivoDefinitivo::CLAVE,
            default => null,
        };

        return new self($mensaje, $estado, $definitivo, $previo);
    }

    /** El fallo del proveedor dentro de `$e`, si lo hay: puede venir envuelto por quien lo relanzó. */
    public static function en(Throwable $e): ?self
    {
        for ($actual = $e; $actual !== null; $actual = $actual->getPrevious()) {
            if ($actual instanceof self) {
                return $actual;
            }
        }

        return null;
    }

    /** `$e` como fallo del motor: el suyo si lo trae, y si no uno sin código, que no es definitivo. */
    public static function de(Throwable $e): self
    {
        return self::en($e) ?? new self($e->getMessage(), null, null, $e);
    }

    public function esDefinitivo(): bool
    {
        return $this->definitivo !== null;
    }
}
