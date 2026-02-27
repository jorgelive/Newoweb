<?php

declare(strict_types=1);

namespace App\Pms\Factory;

use App\Exchange\Entity\Beds24Config;
use App\Exchange\Entity\ExchangeEndpoint;
use App\Pms\Entity\PmsRatesPushQueue;
use App\Pms\Entity\PmsUnidad;
use App\Pms\Entity\PmsUnidadBeds24Map;
use DateTimeImmutable;

/**
 * Factory para la creación estandarizada de tareas de cola de Tarifas (PUSH).
 * Centraliza la inicialización de estado y dependencias obligatorias.
 */
final class PmsRatesPushQueueFactory
{
    public function create(
        PmsUnidad          $unidad,
        ExchangeEndpoint   $endpoint,
        PmsUnidadBeds24Map $map,
        Beds24Config       $config,
    ): PmsRatesPushQueue {
        // 1. Instanciación
        // El constructor de PmsRatesPushQueue genera UUID v7 y runAt = NOW
        $queue = new PmsRatesPushQueue();

        // 2. Inyección de dependencias obligatorias
        $queue->setUnidad($unidad);
        $queue->setConfig($config);
        $queue->setEndpoint($endpoint);
        $queue->setUnidadBeds24Map($map);

        // 3. Garantía de estado inicial
        $queue->setStatus(PmsRatesPushQueue::STATUS_PENDING);
        $queue->setRetryCount(0);

        // Opcional: setear effectiveAt al momento de creación por defecto
        $queue->setEffectiveAt(new DateTimeImmutable());

        return $queue;
    }
}