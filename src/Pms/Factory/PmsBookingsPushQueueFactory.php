<?php


declare(strict_types=1);

namespace App\Pms\Factory;

use App\Pms\Entity\Beds24Config;
use App\Pms\Entity\Beds24Endpoint;
use App\Pms\Entity\PmsBookingsPushQueue;

/**
 * Factory para la creación estandarizada de tareas de cola (PUSH).
 * Centraliza la inicialización de estado y dependencias obligatorias.
 */
final class PmsBookingsPushQueueFactory
{
    public function create(Beds24Config $config, Beds24Endpoint $endpoint): PmsBookingsPushQueue
    {
        // 1. Instanciación
        // El constructor de la entidad PmsBookingsPushQueue se encarga de:
        // - Generar el UUID v7 (IdTrait)
        // - Establecer runAt = NOW()
        $queue = new PmsBookingsPushQueue();

        // 2. Inyección de dependencias obligatorias
        $queue->setConfig($config);
        $queue->setEndpoint($endpoint);

        // 3. Garantía de estado inicial
        // Aunque la entidad tenga defaults, el Factory los hace explícitos
        // para protegerse contra cambios futuros en la entidad.
        $queue->setStatus(PmsBookingsPushQueue::STATUS_PENDING);
        $queue->setRetryCount(0);

        return $queue;
    }
}