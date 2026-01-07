<?php

namespace App\Pms\Service\Beds24\Sync;

/**
 * SyncContext
 *
 * Representa el CONTEXTO DE EJECUCIÓN de la sincronización.
 * No describe datos, describe DESDE DÓNDE provienen los cambios
 * para que las reglas internas (listeners, colas, resolvers)
 * puedan comportarse de forma distinta según el modo.
 *
 * Ejemplos de uso:
 *  - UI: cambios hechos por humanos desde el PMS.
 *  - PULL: datos que vienen desde Beds24 (evitar loops).
 *
 * Importante:
 *  - Este contexto es global por request/comando.
 *  - Debe setearse SOLO en entry-points (orchestrators, commands).
 */
final class SyncContext
{
    /** Cambios originados por el PMS / UI */
    public const SOURCE_UI     = 'ui';

    /** Enviando datos a beds24 */
    public const SOURCE_PUSH_BEDS24   = 'push_beds24';

    /** Datos que vienen desde Beds24 (modo PULL) */
    public const SOURCE_PULL_BEDS24 = 'pull_beds24';

    /**
     * Fuente actual de los cambios / modo de ejecución.
     * Por defecto: UI (humano / PMS).
     */
    private string $source = self::SOURCE_UI;

    /**
     * Define explícitamente el origen / modo de ejecución actual.
     *
     * Debe llamarse SOLO desde entry points:
     *  - Orchestrators
     *  - Commands
     *  - Controllers
     *
     * Nunca debe llamarse desde:
     *  - Entities
     *  - Listeners
     *  - Services de dominio
     */
    public function setSource(string $source): void
    {
        $this->source = $source;
    }

    /**
     * Entra temporalmente a un modo de ejecución y devuelve un scope
     * que RESTAURA el modo anterior al finalizar.
     *
     * Este patrón garantiza que:
     *  - Los flush() y listeners vean el contexto correcto.
     *  - El estado no quede "pegajoso" entre ejecuciones.
     *
     * Uso recomendado:
     *   $scope = $syncContext->enterSource(SyncContext::SOURCE_BEDS24);
     *   try { ... } finally { $scope->restore(); }
     */
    public function enterSource(string $source): SyncContextScope
    {
        $previous = $this->source;
        $this->source = $source;

        return new SyncContextScope(function () use ($previous): void {
            $this->source = $previous;
        });
    }

    /**
     * Devuelve el modo/origen actual de la sincronización.
     * Útil para logging, debugging o decisiones de bajo nivel.
     */
    public function getSource(): string
    {
        return $this->source;
    }

    /**
     * Indica que estamos en modo UI (cambios hechos por humanos).
     */
    public function isUi(): bool
    {
        return $this->source === self::SOURCE_UI;
    }

    /**
     * Indica que estamos en modo PULL:
     * los datos vienen desde Beds24 y aplican reglas especiales
     * para evitar loops de sincronización.
     */
    public function isPull(): bool
    {
        return $this->source === self::SOURCE_PULL_BEDS24;
    }

    /**
     * Indica que estamos en modo PULL:
     * los datos se estan empujando a Beds24
     * para evitar loops de sincronización.
     */
    public function isPush(): bool
    {
        return $this->source === self::SOURCE_PUSH_BEDS24;
    }
}