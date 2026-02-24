<?php

declare(strict_types=1);

namespace App\Pms\Service\Beds24\Queue;

use App\Pms\Entity\Beds24Endpoint;
use App\Pms\Entity\PmsBookingsPullQueue;
use App\Pms\Entity\PmsEstablecimiento;
use DateTimeImmutable;
use DateTimeInterface;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Servicio encargado de aislar la lógica de creación de colas de descarga (PULL).
 */
final class Beds24BookingsPullQueueCreator
{
    public function __construct(
        private readonly EntityManagerInterface $em
    ) {}

    /**
     * Crea un trabajo de descarga (Pull) basado en la configuración del Establecimiento.
     */
    public function createForEstablecimiento(
        PmsEstablecimiento $establecimiento,
        Beds24Endpoint $endpoint,
        DateTimeInterface $from,
        DateTimeInterface $to
    ): ?PmsBookingsPullQueue {
        // 1. Resolución Jerárquica: Le pedimos la config al Establecimiento
        $config = $establecimiento->getBeds24Config();

        // Si el establecimiento no tiene Beds24 configurado (o no está activo), lo ignoramos
        if (!$config || !$config->isActivo()) {
            return null;
        }

        // 2. Creación de la Cola
        $job = new PmsBookingsPullQueue();

        // Asignamos los parámetros obligatorios
        $job->setConfig($config);
        $job->setEndpoint($endpoint);

        // Convertimos a Immutable para garantizar seguridad en la entidad
        $job->setArrivalFrom(DateTimeImmutable::createFromInterface($from));
        $job->setArrivalTo(DateTimeImmutable::createFromInterface($to));

        // Prioridad: Ejecución inmediata por el Worker
        $job->setRunAt(new DateTimeImmutable());

        // Opcional: Si tu entidad PmsBookingsPullQueue tiene una relación con Establecimiento,
        // este sería el lugar ideal para hacer: $job->setEstablecimiento($establecimiento);

        // 3. Persistimos (El flush se hace en bloque desde el CronJob para mayor eficiencia)
        $this->em->persist($job);

        return $job;
    }
}