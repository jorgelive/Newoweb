<?php
declare(strict_types=1);

namespace App\Exchange\Service\Contract;

use App\Exchange\Service\Context\SyncContext;
use App\Exchange\Service\Mapping\MappingStrategyInterface;

/**
 * Contrato unificado para Tareas de Exchange (Nueva Arquitectura).
 * * La Tarea actúa como un "Contenedor de Configuración" (Glue Code) que agrupa:
 * 1. Provider: Quién trae los datos.
 * 2. Strategy: Cómo se transforman y envían.
 * 3. Handler:  Cómo se procesa la respuesta.
 */
interface ExchangeTaskInterface
{
    /**
     * Nombre único de la tarea para logs y métricas (ej: 'rates_push').
     */
    public static function getTaskName(): string;

    /**
     * Define el tamaño MÁXIMO de lote soportado por esta tarea específica.
     * Útil para tareas como 'Pull' que requieren procesar de 1 en 1,
     * protegiendo al sistema de límites demasiado altos pasados por consola.
     */
    public function getMaxBatchSize(): int;

    /**
     * Modo para el SyncContext (ej: 'SyncContext::MODE_PULL').
     */
    public function getSyncMode(): string;

    /**
     * Identificador del origen para el SyncContext (ej: 'pbeds24').
     */
    public function getSyncProvider(): string;

    /**
     * Retorna el proveedor encargado de obtener el lote de trabajo homogéneo.
     */
    public function getQueueProvider(): ExchangeQueueProviderInterface;

    /**
     * Retorna la estrategia encargada del mapeo de datos y construcción HTTP.
     */
    public function getMappingStrategy(): MappingStrategyInterface;

    /**
     * Retorna el manejador encargado de persistir los resultados (éxito/error).
     */
    public function getHandler(): ExchangeHandlerInterface;

    /**
     * Obtiene metadatos de agrupación para un set de IDs (Proxy al repositorio).
     * @param string[] $ids UUIDs texto
     * @return array
     */
    public function getGroupingMetadata(array $ids): array;

}