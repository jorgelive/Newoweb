<?php
declare(strict_types=1);

namespace App\Exchange\Service\Context;

/**
 * SyncContext
 *
 * Representa el CONTEXTO DE EJECUCIÓN global de la sincronización.
 * No describe datos, describe DESDE DÓNDE provienen los cambios
 * para que las reglas internas (listeners, colas, resolvers)
 * puedan comportarse de forma distinta según el modo.
 */
final class SyncContext
{
    /** Modos base de operación */
    public const MODE_UI   = 'ui';
    public const MODE_PUSH = 'push';
    public const MODE_PULL = 'pull';

    /**
     * @var string Modo actual de ejecución.
     */
    private string $mode = self::MODE_UI;

    /**
     * @var string|null Proveedor o canal involucrado.
     */
    private ?string $provider = null;

    /**
     * Entra temporalmente a un modo de ejecución y devuelve un scope
     * que RESTAURA el modo anterior al finalizar.
     *
     * @param string $mode Uno de los MODOS constantes (UI, PUSH, PULL).
     * @param string|null $provider El identificador del canal (ej: 'beds24').
     *
     * Uso recomendado:
     * $scope = $syncContext->enter(SyncContext::MODE_PULL, 'beds24');
     * try { ... } finally { $scope->restore(); }
     */
    public function enter(string $mode, ?string $provider = null): SyncContextScope
    {
        $previousMode = $this->mode;
        $previousProvider = $this->provider;

        $this->mode = $mode;
        $this->provider = $provider;

        // La clausura captura el estado previo para restaurarlo fielmente
        return new SyncContextScope(function () use ($previousMode, $previousProvider): void {
            $this->mode = $previousMode;
            $this->provider = $previousProvider;
        });
    }

    /**
     * Devuelve la combinación de modo y proveedor.
     * Se mantiene por compatibilidad legacy y logs.
     */
    public function getSource(): string
    {
        return $this->provider ? "{$this->mode}_{$this->provider}" : $this->mode;
    }

    public function getMode(): string
    {
        return $this->mode;
    }

    public function getProvider(): ?string
    {
        return $this->provider;
    }

    /**
     * Indica si el origen es un proveedor específico.
     * @example $context->isFrom('beds24')
     */
    public function isFrom(string $providerName): bool
    {
        return $this->provider !== null && strcasecmp($this->provider, $providerName) === 0;
    }

    /**
     * Indica que estamos en modo UI (cambios hechos por humanos).
     */
    public function isUi(): bool
    {
        return $this->mode === self::MODE_UI;
    }

    /**
     * Indica que estamos en modo PULL (datos entrantes).
     */
    public function isPull(): bool
    {
        return $this->mode === self::MODE_PULL;
    }

    /**
     * Indica que estamos en modo PUSH (datos salientes).
     */
    public function isPush(): bool
    {
        return $this->mode === self::MODE_PUSH;
    }
}