<?php

declare(strict_types=1);

namespace App\Exchange\Dispatch;

/**
 * RunExchangeTaskDispatch.
 * * Representa una orden de "despacho" para que el Worker procese tareas
 * específicas de inmediato. Contiene solo los IDs (UUID v7) necesarios.
 */
final readonly class RunExchangeTaskDispatch
{
    /**
     * @param string $taskName Nombre del servicio de tarea en el Locator (ej: 'beds24_bookings_push')
     * @param string[] $ids Lista de UUIDs (texto) que se acaban de insertar y deben procesarse.
     */
    public function __construct(
        public string $taskName,
        public array $ids
    ) {}
}