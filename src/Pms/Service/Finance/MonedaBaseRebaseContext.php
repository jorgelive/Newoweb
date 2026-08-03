<?php

declare(strict_types=1);

namespace App\Pms\Service\Finance;

/**
 * Marca de "estoy rebasando la moneda base", para que el listener de coherencia levante sus
 * candados sólo durante esa operación.
 *
 * Existe porque los candados de §12.4 son deliberadamente estrictos: la moneda de un cargo no
 * se cambia nunca y su tipo de cambio sólo se rellena si estaba vacío. El cambio de moneda base
 * es la ÚNICA excepción legítima, y necesita reescribir ambos. En lugar de debilitar el candado
 * con una condición difusa (que acabaría permitiendo el cambio por accidente desde cualquier
 * sitio), se exige que quien lo haga lo declare explícitamente.
 *
 * Mismo patrón que SyncContext para el anti-bucle Pull ↔ Push (§9.2).
 */
final class MonedaBaseRebaseContext
{
    private bool $activo = false;

    /** @param callable():mixed $operacion */
    public function durante(callable $operacion): mixed
    {
        $previo = $this->activo;
        $this->activo = true;

        try {
            return $operacion();
        } finally {
            $this->activo = $previo;
        }
    }

    public function estaRebasando(): bool
    {
        return $this->activo;
    }
}
