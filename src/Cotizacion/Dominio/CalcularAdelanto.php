<?php

declare(strict_types=1);

namespace App\Cotizacion\Dominio;

use App\Dominio\Contrato\OperacionDominioInterface;

/**
 * El adelanto para reservar una cotización: `montoDeAdelanto()` de `dominio/cotizacion/adelanto.ts`.
 *
 * La regla vive en TypeScript —la usan el editor y pax— y aquí sólo se nombra su puerta. La pide
 * `CotizacionEnlaceService` al emitir el enlace del adelanto: un 50 % calculado en PHP podría pedir
 * un céntimo distinto del que el cliente ve en «Cómo reservar».
 *
 * Entrada `{total, porcentaje}` (USD y 0–100) → salida `{adelanto, saldo}`.
 */
final class CalcularAdelanto implements OperacionDominioInterface
{
    public function puntoDeEntrada(): string
    {
        return 'cotizacion/adelanto.cli.ts';
    }

    public function contrato(): string
    {
        return 'adelanto@1';
    }
}
