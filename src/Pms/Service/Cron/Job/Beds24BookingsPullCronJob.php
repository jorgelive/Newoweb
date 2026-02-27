<?php

declare(strict_types=1);

namespace App\Pms\Service\Cron\Job;

use App\Exchange\Entity\ExchangeEndpoint;
use App\Exchange\Enum\ConnectivityProvider;
use App\Pms\Entity\PmsEstablecimiento;
use App\Pms\Service\Beds24\Queue\Beds24BookingsPullQueueCreator;
use App\Pms\Service\Cron\CronJobInterface;
use DateInterval;
use DateTimeInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Cron Job: Orquestador de tareas de Descarga de Reservas (Pull).
 * Delega la creación física a Beds24BookingsPullQueueCreator.
 */
#[AutoconfigureTag('app.cron_job')]
final class Beds24BookingsPullCronJob implements CronJobInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Beds24BookingsPullQueueCreator $queueCreator // ✅ Inyectamos el creador
    ) {}

    public function getName(): string
    {
        return 'beds24_bookings_pull_arrival';
    }

    public function getStepInterval(): DateInterval
    {
        return new DateInterval('P7D');
    }

    public function execute(DateTimeInterface $from, DateTimeInterface $to, SymfonyStyle $io): void
    {
        // 1. Obtener el Endpoint Maestro
        $endpoint = $this->em->getRepository(ExchangeEndpoint::class)->findOneBy([
            'provider' => ConnectivityProvider::BEDS24,
            'accion' => 'GET_BOOKINGS',
            'activo' => true
        ]);

        if (!$endpoint) {
            $io->error("CRÍTICO: No se encontró el endpoint 'GET_BOOKINGS' en la tabla pms_beds24_endpoint.");
            return;
        }

        // 2. Buscar TODOS los establecimientos (La lógica de filtrado la hace el Creator)
        // Nota: Para optimizar en BD gigantes, podrías hacer una query que solo traiga establecimientos con config_id IS NOT NULL
        $establecimientos = $this->em->getRepository(PmsEstablecimiento::class)->findAll();

        if (empty($establecimientos)) {
            $io->warning("No hay establecimientos registrados en el sistema.");
            return;
        }

        $jobsCreated = 0;

        // 3. Iterar por Establecimiento, NO por Configuración
        foreach ($establecimientos as $establecimiento) {
            $job = $this->queueCreator->createForEstablecimiento(
                establecimiento: $establecimiento,
                endpoint: $endpoint,
                from: $from,
                to: $to
            );

            if ($job !== null) {
                $jobsCreated++;
            }
        }

        // 4. Commit en bloque (Eficiencia SQL máxima)
        $this->em->flush();

        if ($jobsCreated > 0) {
            $io->success(sprintf(
                "Se generaron %d jobs de descarga para el periodo %s al %s.",
                $jobsCreated,
                $from->format('Y-m-d'),
                $to->format('Y-m-d')
            ));
        } else {
            $io->note("No se generaron jobs. Revisa si los establecimientos tienen configs activas asignadas.");
        }
    }
}