<?php

declare(strict_types=1);

namespace App\Agent\Service;

use RuntimeException;
use Throwable;

/**
 * El motor pedido en el panel se ha quedado sin créditos o sin clave. Lleva su propio tipo para que
 * el controlador conteste con algo que el operador pueda usar —«elige otro o compruébalo»— en vez
 * del «no disponible ahora mismo» genérico, que invita a reintentar lo mismo.
 */
final class MotorFueraDeServicioException extends RuntimeException
{
    public function __construct(public readonly string $etiqueta, Throwable $previa)
    {
        parent::__construct(sprintf('«%s» se ha quedado sin crédito o sin clave.', $etiqueta), 0, $previa);
    }
}
