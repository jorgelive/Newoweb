<?php

declare(strict_types=1);

namespace App\Pms\Service\Cron\Job;

use App\Pms\Entity\Beds24Config;
use App\Pms\Entity\PmsBeds24Endpoint;
use App\Pms\Entity\PmsBookingsPullQueue;
use App\Pms\Service\Cron\CronJobInterface;
use DateInterval;
use DateTimeImmutable;
use DateTimeInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Cron Job: Generador de tareas de Descarga de Reservas (Pull).
 * * ESTRATEGIA: "Ventanas de Tiempo Deslizantes"
 * --------------------------------------------
 * Este job no descarga datos, solo CREA la tarea en la cola PmsBookingsPullQueue.
 * El orquestador le pasa un rango de fechas (ej: 7 días) y este job genera
 * una entrada en la cola para cada cuenta de Beds24 activa.
 */
#[AutoconfigureTag('app.cron_job')]
final class Beds24BookingsPullCronJob implements CronJobInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em
    ) {}

    public function getName(): string
    {
        return 'beds24_bookings_pull_arrival';
    }

    /**
     * Define el tamaño del salto del cursor.
     * 7 Días es el equilibrio ideal entre tamaño de respuesta API y cantidad de jobs.
     */
    public function getStepInterval(): DateInterval
    {
        return new DateInterval('P7D');
    }

    public function execute(DateTimeInterface $from, DateTimeInterface $to, SymfonyStyle $io): void
    {
        // 1. Obtener el Endpoint Maestro para GET (Debe existir en BD)
        $endpoint = $this->em->getRepository(PmsBeds24Endpoint::class)->findOneBy(['accion' => 'GET_BOOKINGS']);

        if (!$endpoint) {
            $io->error("CRÍTICO: No se encontró el endpoint 'GET_BOOKINGS' en la tabla pms_beds24_endpoint.");
            return;
        }

        // 2. Obtener todas las cuentas activas para generarles trabajo
        $configs = $this->em->getRepository(Beds24Config::class)->findBy(['activo' => true]);

        if (empty($configs)) {
            $io->warning("No hay configuraciones de Beds24 activas.");
            return;
        }

        $jobsCreated = 0;

        foreach ($configs as $config) {
            // 3. Crear el Job de Cola (Payload ligero)
            $job = new PmsBookingsPullQueue();

            $job->setBeds24Config($config);
            $job->setEndpoint($endpoint);

            // Convertimos a Immutable para garantizar seguridad en la entidad
            $job->setArrivalFrom(DateTimeImmutable::createFromInterface($from));
            $job->setArrivalTo(DateTimeImmutable::createFromInterface($to));

            // Prioridad: Ejecución inmediata por el Worker
            $job->setRunAt(new DateTimeImmutable());

            // NOTA DE ARQUITECTURA:
            // No asociamos 'Unidades' específicas aquí.
            // Al dejarlo vacío, el Strategy solicitará TODAS las habitaciones de la cuenta.
            // Esto permite "descubrir" nuevas habitaciones automáticamente.

            $this->em->persist($job);
            $jobsCreated++;
        }

        // 4. Commit en bloque (Eficiencia SQL)
        $this->em->flush();

        $io->success(sprintf(
            "Se generaron %d jobs de descarga para el periodo %s al %s.",
            $jobsCreated,
            $from->format('Y-m-d'),
            $to->format('Y-m-d')
        ));
    }
}