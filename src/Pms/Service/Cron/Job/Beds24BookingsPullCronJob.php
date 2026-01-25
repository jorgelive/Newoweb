<?php
declare(strict_types=1);

namespace App\Pms\Service\Cron\Job;

use App\Pms\Entity\Beds24Config;
use App\Pms\Entity\PmsBeds24Endpoint;
use App\Pms\Entity\PmsBookingsPullQueue;
use App\Pms\Entity\PmsUnidad;
use App\Pms\Service\Cron\CronJobInterface;
use DateInterval;
use DateTimeImmutable;
use DateTimeInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Cron que alimenta la cola de Pull (Descarga) basándose en un cursor de fechas.
 * Recorre el calendario descargando reservas modificadas o por fecha de llegada.
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

    public function getStepInterval(): DateInterval
    {
        return new DateInterval('P7D'); // Avanzar de 7 en 7 días
    }

    public function execute(DateTimeInterface $from, DateTimeInterface $to, SymfonyStyle $io): void
    {
        // 1. Obtener configuraciones activas
        $configs = $this->em->getRepository(Beds24Config::class)->findBy(['activo' => true]);

        // 2. Obtener Endpoint GET
        $endpoint = $this->em->getRepository(PmsBeds24Endpoint::class)->findOneBy(['accion' => 'GET_BOOKINGS']);

        if (!$endpoint) {
            $io->error("Endpoint GET_BOOKINGS no configurado.");
            return;
        }

        foreach ($configs as $config) {
            $io->text("Generando job para Config: " . $config->getNombre());

            // 3. Crear el Job de Cola
            $job = new PmsBookingsPullQueue();
            $job->setBeds24Config($config);
            $job->setEndpoint($endpoint);
            $job->setArrivalFrom($from);
            $job->setArrivalTo($to);
            $job->setRunAt(new DateTimeImmutable()); // Ejecutar inmediatamente cuando el worker lo tome

            // Opcional: Agregar todas las unidades vinculadas a esta config para filtrar
            // Esto depende de tu estrategia. Si quieres descargar TODO de la cuenta, déjalo vacío.
            // Si quieres filtrar por roomIds específicos, añádelos.
            /*
            $mapas = $config->getUnidadMaps();
            foreach($mapas as $map) {
                if ($map->isActivo()) $job->addUnidad($map->getPmsUnidad());
            }
            */

            $this->em->persist($job);
        }

        $this->em->flush();
        // Importante: No hacer clear() aquí si el comando padre maneja el cursor,
        // pero como buena práctica de Batch, el comando padre ya hace el clear.
    }
}