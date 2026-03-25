<?php

declare(strict_types=1);

namespace App\Exchange\Service\Contract;

/**
 * Interfaz opcional para entidades de cola.
 * Permite al Orquestador agnóstico liberar memoria de entidades relacionadas
 * sin acoplarse a reglas de dominio específicas.
 */
interface MemoryCleanableInterface
{
    /**
     * @return array<object|null> Lista de entidades relacionadas a desvincular (detach).
     */
    public function getRelatedEntitiesToDetach(): array;
}