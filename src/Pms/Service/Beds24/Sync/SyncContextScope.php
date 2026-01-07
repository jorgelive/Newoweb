<?php

namespace App\Pms\Service\Beds24\Sync;

/**
 * SyncContextScope
 *
 * Representa el ALCANCE TEMPORAL de un cambio de SyncContext.
 * Su única responsabilidad es restaurar el contexto anterior
 * cuando finaliza una operación (scope).
 *
 * Este objeto:
 *  - NO es un servicio
 *  - NO se inyecta
 *  - NO contiene lógica de negocio
 *
 * Se utiliza junto con SyncContext::enterSource() para evitar
 * estados "pegajosos" entre ejecuciones.
 */
final class SyncContextScope
{
    // Evita restaurar el contexto más de una vez (idempotencia).
    private bool $restored = false;

    /**
     * Closure que restaura el SyncContext al estado previo.
     * Se ejecuta una sola vez.
     */
    private \Closure $restore;

    /**
     * @param callable $restore Closure que restaura el contexto previo.
     *                          Normalmente es creada por SyncContext::enterSource().
     */
    public function __construct(callable $restore)
    {
        $this->restore = $restore instanceof \Closure
            ? $restore
            : \Closure::fromCallable($restore);
    }

    /**
     * Restaura explícitamente el SyncContext al estado anterior.
     *
     * Es seguro llamar a este método múltiples veces:
     * solo la primera llamada tendrá efecto.
     */
    public function restore(): void
    {
        if ($this->restored) {
            return;
        }

        $this->restored = true;
        ($this->restore)();
    }

    /**
     * Red de seguridad:
     * si el desarrollador olvida llamar restore(),
     * el destructor restaura el contexto automáticamente.
     */
    public function __destruct()
    {
        $this->restore();
    }
}